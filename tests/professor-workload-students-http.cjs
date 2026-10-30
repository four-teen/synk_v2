// Exercise the real JSON controller with isolated rollback-only CLI request fixtures.
const {spawnSync} = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
let checks = 0;
for (const [scenario, status] of Object.entries({valid:200, forged_identity:200, anonymous:401, wrong_role:401,
  disabled:403, revoked:403, unlinked:404, inactive_faculty:404, reassigned:404, foreign:404, wrong_term:404, malformed:422, bad_semester:422, method:405})) {
  const result = spawnSync('php', [path.join(__dirname,'fixtures/professor-workload-students-request.php'),scenario], {cwd:root,encoding:'utf8',windowsHide:true});
  if (result.status !== 0) throw Error(scenario + ': ' + result.stderr + result.stdout);
  const response = JSON.parse(result.stdout);
  if (response.status !== status) throw Error(scenario + ': expected ' + status + ', got ' + result.stdout);
  if (status === 200) {
    if (!response.body.ok || response.body.students.length !== 3 || response.body.counts.active !== 1 || response.body.counts.inactive !== 2) throw Error(scenario + ': inaccurate roster.');
    if (response.body.students.some(student => 'email_address' in student || 'ip_address' in student || 'user_agent' in student)) throw Error('Private login metadata was exposed.');
  } else if (response.body.ok || 'students' in response.body) throw Error(scenario + ': failed authorization exposed students.');
  checks++;
}
console.log('Professor workload roster controller: ' + checks + ' checks passed. Fixtures rolled back.');
