'use strict';
// Run against an already imported local catalog. No fixture rows are committed.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
const [input, php = 'php'] = process.argv.slice(2);
if (!input) throw Error('Usage: node tests/management-catalog-import.cjs <catalog.json> [php-executable]');
const inputPath = path.resolve(input);
const original = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'synk-management-catalog-'));
const fixturePath = path.join(temporary, 'fixture.json');
let checks = 0;
function run(file, apply) {
  const result = spawnSync(php, ['backend/tools/prepare_management_catalog.php', file, ...(apply ? ['--apply'] : [])], { cwd:root, encoding:'utf8' });
  if (result.error) throw result.error;
  return result;
}
function snapshot() {
  const code = "mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);require 'backend/db.php';echo json_encode($conn->query('CHECKSUM TABLE tbl_management_organizations,tbl_management_contributions,tbl_management_events,tbl_management_event_sessions EXTENDED')->fetch_all(MYSQLI_ASSOC));";
  const result = spawnSync(php, ['-r', code], { cwd:root, encoding:'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}
function reject(change, expectedMessage) {
  const fixture = structuredClone(original); change(fixture);
  fs.writeFileSync(fixturePath, JSON.stringify(fixture));
  const result = run(fixturePath, true);
  assert.notEqual(result.status, 0, 'Invalid source was accepted.');
  assert.match(result.stderr, expectedMessage);
  assert.deepEqual(snapshot(), before, 'Rejected import changed catalog records.'); checks++;
}
let before;
try {
  before = snapshot();
  const preview = run(inputPath, false);
  assert.equal(preview.status, 0, preview.stderr);
  assert.equal(JSON.parse(preview.stdout).mode, 'DRY RUN');
  assert.deepEqual(snapshot(), before); checks++;
  reject(data => { data.contributions[0].title = 'invalid lowercase'; }, /uppercase/);
  reject(data => { data.contributions[0].amount = '0.001'; }, /no rounding/);
  reject(data => { data.contributions[0].contribution_date = '2026-02-30'; }, /Invalid catalog date/);
  reject(data => { data.events.find(event => event.session).semester = 2; }, /unassigned/);
  // A new organization is inserted before this later source conflict. Both roll
  // back, proving atomicity across different tables rather than only validation.
  reject(data => {
    data.contributions[0].organization_code = 'CATALOG_QA_ROLLBACK';
    data.events[0].source_checksum = data.events[0].source_checksum === '0'.repeat(64) ? '1'.repeat(64) : '0'.repeat(64);
  }, /Source changed/);
  const repeated = run(inputPath, true);
  assert.equal(repeated.status, 0, repeated.stderr);
  const report = JSON.parse(repeated.stdout);
  for (const counts of Object.values(report.result)) assert.equal(counts.inserted, 0, 'Repeat import inserted duplicates.');
  assert.equal(report.result.tbl_management_contributions.preserved, original.contributions.length);
  assert.equal(report.result.tbl_management_events.preserved, original.events.length);
  assert.deepEqual(snapshot(), before, 'Repeat import changed existing records.'); checks++;
  console.log('Management catalog import: ' + checks + ' scenarios passed (validation, rollback, and repeat import).');
} finally {
  if (fs.existsSync(fixturePath)) fs.unlinkSync(fixturePath);
  fs.rmdirSync(temporary);
}
