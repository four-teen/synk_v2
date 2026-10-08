// Run: node tests/professor-account-role-matrix.js
// Exercise the page's actual handlers with server-provided faculty eligibility.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../administrator/manage-accounts.php'), 'utf8');
const script = source.match(/<script>\s*([\s\S]*?)<\/script>/)[1];
new vm.Script(script); // Compile the entire actual page script before stubbing startup.
const handlers = new Map();
const elements = new Map();
const document = {};
const $ = target => {
  const element = typeof target === 'string' ? (elements.get(target) || {}) : target;
  if (typeof target === 'string') elements.set(target, element);
  return {
    on(event, selector, handler) {
      if (target === document && typeof handler === 'function') handlers.set(event + ' ' + selector, handler);
      return this;
    },
    data(key) { return element[key]; },
    is() { return Boolean(element.checked); },
    prop(key, value) { element[key] = value; return this; },
    attr(key, value) { element[key] = value; return this; },
    each() { return this; }
  };
};
$.trim = value => String(value).trim();
const context = vm.createContext({ $, document, window: {}, Swal: { fire: () => Promise.resolve({ isConfirmed: true }) } });
vm.runInContext(script.replace(/\/\/ INITIAL LOAD[\s\S]*?(?=\$\(window\)\.on)/, ''), context);
const state = vm.runInContext('accountState', context);
const normalize = raw => context.normalizeAccountRecord(raw);
const match = normalize({ id: 1, username: 'Annie Kadatuan', role: 'admin', professor_eligible: true, professor_needs_link: true, professor_match_faculty_label: 'Annie M Kadatuan' });
const missing = normalize({ id: 2, username: 'Campus Login', role: 'admin', professor_eligible: false, professor_link_reason: 'no_faculty_match' });
const linked = normalize({ id: 3, username: 'Linked Faculty', role_payload: [{ role: 'admin', is_primary: true }, { role: 'professor' }], faculty_id: 99, faculty_label: 'Linked Faculty', professor_eligible: true });
const inactive = normalize({ id: 4, username: 'Inactive Faculty', role_payload: [{ role: 'admin', is_primary: true }, { role: 'professor' }], faculty_id: 100, faculty_label: 'Inactive Faculty', professor_eligible: false, professor_link_reason: 'invalid_faculty' });
state.allAccounts = [match, missing, linked, inactive];
const professor = { code: 'professor', label: 'Professor' };
let checks = 0;
const check = (condition, message) => { assert.ok(condition, message); checks++; };
check(!context.buildAccountRoleToggle(match, professor, false).includes('disabled'), 'A unique faculty match has an enabled Professor checkbox.');
check(context.buildAccountRoleToggle(missing, professor, false).includes('disabled'), 'An unmatched account cannot check Professor.');
check(!context.buildAccountRoleToggle(inactive, professor, false).includes('disabled'), 'An existing invalid Professor role can still be removed.');
check(match.facultyHtml.includes('NOT LINK') && match.facultyHtml.includes('annie kadatuan') && match.facultyHtml.includes('Will link to Annie M Kadatuan'), 'A pending link shows the compact status and lowercase display name, with the proposed match in its tooltip.');
check(missing.facultyHtml.includes('badge bg-danger') && missing.facultyHtml.includes('NOT LINK') && missing.facultyHtml.includes('campus login'), 'An unmatched account shows a red NOT LINK badge and its lowercase display name.');
context.updateRoleHeaderToggles();
const header = elements.get('.role-all-toggle[data-role="professor"]');
check(header.indeterminate && !header.checked, 'The header reflects the eligible faculty accounts.');
const requests = [];
context.submitRoleMatrixChange = (role, assigned, ids, bulk) => requests.push({ role, assigned, ids: Array.from(ids), bulk });
const bulkChange = handlers.get('change .role-all-toggle');
check(typeof bulkChange === 'function', 'The actual bulk checkbox handler is registered.');
(async () => {
  bulkChange.call({ role: 'professor', checked: true });
  await new Promise(setImmediate);
  assert.deepEqual(requests[0], { role: 'professor', assigned: true, ids: ['1', '3'], bulk: true });
  checks++;
  bulkChange.call({ role: 'professor', checked: false });
  await new Promise(setImmediate);
  assert.deepEqual(requests[1], { role: 'professor', assigned: false, ids: ['3', '4'], bulk: true });
  checks++;
  match.roles.push('professor');
  context.updateRoleHeaderToggles();
  check(header.checked && !header.indeterminate, 'The header becomes checked once all eligible faculty are assigned.');
  state.allAccounts = [missing];
  context.updateRoleHeaderToggles();
  check(header.disabled && !header.checked, 'Professor bulk assignment is disabled when there are no eligible faculty accounts.');
  console.log(`Professor role matrix: ${checks} checks passed.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
