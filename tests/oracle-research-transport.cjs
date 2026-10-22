// Real PHP cURL against an isolated HTTPS server. No application DB or live Oracle access.
const fs = require('node:fs');
const path = require('node:path');
const https = require('node:https');
const crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const root = path.resolve(__dirname, '..');
fs.mkdirSync(path.join(root, '.tmp'), { recursive: true });
const temporary = fs.mkdtempSync(path.join(root, '.tmp', 'oracle-transport-'));
const php = process.env.ORACLE_TEST_PHP || 'C:\\xampp\\php\\php.exe';
const openssl = process.env.ORACLE_TEST_OPENSSL || 'C:\\Program Files\\Git\\usr\\bin\\openssl.exe';
const opensslConfig = process.env.ORACLE_TEST_OPENSSL_CONFIG || 'C:\\xampp\\apache\\conf\\openssl.cnf';
let server, validRequests = 0, rejectedRequests = 0, redirectsFollowed = 0, unexpected = [], forbiddenDownloads = 0;
let clockChallenges = 0, clockRecoveries = 0, farClockRequests = 0, persistentClockRequests = 0;
const certificate = path.join(temporary, 'localhost-cert.pem');
const privateKey = path.join(temporary, 'localhost-key.pem');

async function runPhp(executable, args) {
  return new Promise((resolve, reject) => {
    const child = spawn(executable, args, { cwd: root, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
    let stdout = '', stderr = '';
    child.stdout.on('data', data => stdout += data); child.stderr.on('data', data => stderr += data);
    child.on('error', reject); child.on('exit', code => resolve({ code, stdout, stderr }));
  });
}

async function main() {
  const generated = spawnSync(openssl, ['req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-batch', '-days', '1',
    '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost,IP:127.0.0.1',
    '-config', opensslConfig, '-keyout', privateKey, '-out', certificate], { windowsHide: true, encoding: 'utf8' });
  if (generated.status !== 0) throw Error(generated.stderr || generated.error?.message || 'Could not generate the disposable test certificate.');
  const item = { id: 1, title: 'HTTPS transport fixture', authors: 'Faculty', relationship: 'Adviser', roles: ['Adviser','Panelist'], status: 'Completed',
    is_published: false, has_abstract_file: true, research_type: 'Faculty Research', campus: 'Isulan', program: 'BSIT',
    academic_year: '2025 - 2026', sdgs: '4', abstract: 'Fixture abstract', updated_at: '2026-10-22 10:00:00',
    adviser: 'Faculty', panelists: 'Faculty', statistician: '', english_critic: '', owner: '', coauthors: '', encoder: '' };
  server = https.createServer({ key: fs.readFileSync(privateKey), cert: fs.readFileSync(certificate) }, (request, response) => {
    const json = (status, data) => { response.writeHead(status, { 'Content-Type': 'application/json' }); response.end(JSON.stringify(data)); };
    if (request.url === '/unexpected-redirect') { redirectsFollowed++; json(500, {}); return; }
    if (request.method !== 'POST' || request.url !== '/synk-research-api.php' || request.headers.cookie || request.headers.authorization) {
      unexpected.push('Unexpected method, URL, cookie, or authorization forwarding.'); json(400, {}); return;
    }
    let raw = '';
    request.setEncoding('utf8'); request.on('data', chunk => raw += chunk);
    request.on('end', () => {
      const timestamp = request.headers['x-synk-timestamp'];
      const expected = crypto.createHmac('sha256', 'b'.repeat(64)).update(timestamp + '\n' + raw).digest('hex');
      if (request.headers['x-synk-signature'] !== expected) {
        rejectedRequests++; json(401, { ok: false, error: 'authentication_failed' }); return;
      }
      const input = JSON.parse(raw);
      if (['clock-skew', 'clock-far', 'clock-reject'].includes(input.search)) {
        const remoteTime = Date.now() / 1000 - (input.search === 'clock-far' ? 3600 : 360);
        response.setHeader('Date', new Date(remoteTime * 1000).toUTCString());
        if (input.search === 'clock-far') { farClockRequests++; json(401, { ok: false, error: 'authentication_failed' }); return; }
        if (input.search === 'clock-reject') { persistentClockRequests++; json(401, { ok: false, error: 'authentication_failed' }); return; }
        if (Math.abs(remoteTime - Number(timestamp)) > 300) { clockChallenges++; json(401, { ok: false, error: 'authentication_failed' }); return; }
        clockRecoveries++;
      } else if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) {
        rejectedRequests++; json(401, { ok: false, error: 'authentication_failed' }); return;
      }
      validRequests++;
      if (input.action === 'health') { json(200, { ok: true, api_version: 2, service: 'oracle-synk-research', database: 'ready' }); return; }
      if (input.action === 'abstract') {
        if ([2,3,4].includes(input.research_id)) forbiddenDownloads++;
        response.writeHead(200, { 'Content-Type': 'application/octet-stream', 'X-Oracle-File-Extension': 'pdf' });
        response.end('%PDF-1.4\nIsolated transport fixture\n'); return;
      }
      if (input.search === 'redirect') { response.writeHead(302, { Location: '/unexpected-redirect' }); response.end(); return; }
      if (input.search === 'disabled') { json(403, { ok: false, error: 'account_disabled' }); return; }
      if (input.search === 'legacy-action') { json(400, { ok: false, error: 'invalid_action' }); return; }
      if (input.search === 'bad-json') { response.writeHead(200, { 'Content-Type': 'application/json' }); response.end('{bad'); return; }
      if (input.search === 'html') { response.writeHead(200, { 'Content-Type': 'text/html' }); response.end('<h1>Sign in</h1>'); return; }
      const data = { ok: true, api_version: input.search === 'old-version' ? 1 : 2, scope: 'all_related_research',
        account: { name: 'Faculty', email: input.search === 'wrong-account' ? 'other@sksu.edu.ph' : input.email },
        items: [item], pagination: { page: 1, pages: 1, per_page: 12, total: 1 },
        role_counts: { Adviser: 1, Panelist: 1, Statistician: 0, 'English Critic': 0, Owner: 0, 'Co-author': 0, Encoder: 0 } };
      if (input.search === 'partial-portfolio') data.pagination.total = 2;
      if ([2,3,4].includes(input.research_id)) {
        const role = { 2: 'Owner', 3: 'Co-author', 4: 'Encoder' }[input.research_id];
        data.items = [{ ...item, id: input.research_id, relationship: role, roles: [role] }];
        data.role_counts = { Adviser: 0, Panelist: 0, Statistician: 0, 'English Critic': 0, Owner: 0, 'Co-author': 0, Encoder: 0, [role]: 1 };
      }
      if (input.search === 'legacy-portfolio') {
        data.items = [{ ...item, relationship: 'Encoder', roles: ['Encoder', 'Adviser', 'Panelist'] },
          ...['Owner','Co-author','Encoder'].map((role, index) => ({ ...item, id: index + 2, relationship: role, roles: [role] }))];
        data.role_counts = { Adviser: 1, Panelist: 1, Statistician: 0, 'English Critic': 0, Owner: 1, 'Co-author': 1, Encoder: 2 };
        data.pagination.total = 4;
      }
      if (input.search === 'slow') { setTimeout(() => { if (!response.destroyed) json(200, data); }, 1600); return; }
      json(200, data);
    });
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const endpoint = `https://127.0.0.1:${server.address().port}/synk-research-api.php`;
  const result = await runPhp(php, [path.join(root, 'tests', 'oracle-research.php'), '--transport', endpoint, certificate]);
  if (result.code !== 0) throw Error(result.stderr + result.stdout);
  const legacyPhp = process.env.ORACLE_TEST_LEGACY_PHP || 'C:\\VertrigoServ\\Php\\php.exe';
  if (fs.existsSync(legacyPhp)) {
    const code = `require $argv[1]; $config=$synkOracleConfig; $config['endpoint']=$argv[2]; $config['ca_file']=$argv[3]; $config['shared_secret']=str_repeat('b',64);
      $health=SynkOracleResearch::fetch($config,['action'=>'health']);
      $data=SynkOracleResearch::fetch($config,['action'=>'research','email'=>'faculty@sksu.edu.ph']);
      $clock=SynkOracleResearch::fetch($config,['action'=>'research','email'=>'faculty@sksu.edu.ph','search'=>'clock-skew']);
      $file=SynkOracleResearch::fetch($config,['action'=>'abstract','email'=>'faculty@sksu.edu.ph','research_id'=>1]);
      if($health['database']!=='ready'||$data['items'][0]['id']!==1||$clock['items'][0]['id']!==1||substr($file['file'],0,4)!=='%PDF')throw new RuntimeException('Legacy runtime behavior failed.');
      echo 'Legacy PHP '.PHP_VERSION.': signed HTTPS health, research, and attachment requests passed.'.PHP_EOL;`;
    const legacy = await runPhp(legacyPhp, ['-d', 'display_errors=0', '-r', code, path.join(root, 'professor', 'oracle-research.php'), endpoint, certificate]);
    if (legacy.code !== 0) throw Error(legacy.stderr + legacy.stdout);
    process.stdout.write(legacy.stdout);
  }
  if (unexpected.length || validRequests < 11 || rejectedRequests !== 1 || redirectsFollowed !== 0 || forbiddenDownloads !== 0) {
    throw Error(`Transport assertions failed: valid=${validRequests}, rejected=${rejectedRequests}, redirects=${redirectsFollowed}; ${unexpected.join(' ')}`);
  }
  if (clockChallenges < 1 || clockRecoveries !== clockChallenges || farClockRequests !== 1 || persistentClockRequests !== 2) {
    throw Error(`Clock retry assertions failed: challenges=${clockChallenges}, recoveries=${clockRecoveries}, excessive=${farClockRequests}, persistent=${persistentClockRequests}`);
  }
  process.stdout.write(result.stdout);
  console.log('HTTPS transport: signatures, certificate verification, bounded clock recovery, binary downloads, malformed responses, account mismatches, redirects, size limits, and timeouts verified.');
}

main().catch(error => { console.error(error.stack); process.exitCode = 1; }).finally(async () => {
  if (server) await new Promise(resolve => server.close(resolve));
  // Only these known generated files are removed; no recursive deletion.
  for (const file of [privateKey, certificate]) if (fs.existsSync(file)) fs.unlinkSync(file);
  fs.rmdirSync(temporary);
});
