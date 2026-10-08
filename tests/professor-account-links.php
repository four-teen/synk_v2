<?php
// Run: php tests/professor-account-links.php [--database]
// Database request fixtures use savepoints and are always rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/professor_account_link_helper.php';
$checks = 0;
function professor_link_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$faculty = static fn(int $id, string $first, string $middle, string $last, string $status = 'active', string $suffix = ''): array =>
    ['faculty_id' => $id, 'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last, 'ext_name' => $suffix, 'status' => $status];
$account = static fn(int $id, string $name): array => ['user_id' => $id, 'username' => $name];
$resolve = static fn(array $accounts, array $faculties, array $links = []): array => synk_professor_account_link_eligibility($accounts, $faculties, $links);
$annie = $faculty(1, 'ANNIE', 'M', 'KADATUAN');
$match = $resolve([$account(1, 'annie kadatuan')], [$annie])[1];
professor_link_check($match['eligible'] && $match['auto_link'] && $match['faculty_id'] === 1, 'Omitted middle names can match one unique faculty record.');
professor_link_check($resolve([$account(1, 'Annie M. Kadatuan')], [$annie])[1]['eligible'], 'Case and middle-initial punctuation do not prevent matching.');
professor_link_check($resolve([$account(1, 'Kadatuan, Annie M')], [$annie])[1]['eligible'], 'An explicit surname-first display name matches.');
professor_link_check($resolve([$account(1, 'Annie May Kadatuan')], [$faculty(1, 'Annie', 'May', 'Kadatuan')])[1]['eligible'], 'A recorded full middle name matches.');
professor_link_check($resolve([$account(1, 'Annie N Kadatuan')], [$annie])[1]['reason'] === 'no_faculty_match', 'Conflicting middle initials never match.');
professor_link_check($resolve([$account(1, 'An Kadatuan')], [$annie])[1]['reason'] === 'no_faculty_match', 'A first-name substring is not a match.');
professor_link_check($resolve([$account(1, 'Annie Kadatuam')], [$annie])[1]['reason'] === 'no_faculty_match', 'Approximate spelling is not a match.');
professor_link_check($resolve([$account(1, 'Palimbang Campus')], [$annie])[1]['reason'] === 'no_faculty_match', 'An organizational account has no faculty match.');
professor_link_check($resolve([$account(1, 'Annie Kadatuan')], [$faculty(1, 'Annie', 'M', 'Kadatuan', 'inactive')])[1]['reason'] === 'invalid_faculty', 'Inactive faculty cannot receive Professor access.');
professor_link_check($resolve([$account(1, 'Annie Kadatuan')], [$annie, $faculty(2, 'Annie', 'N', 'Kadatuan')])[1]['reason'] === 'ambiguous_faculty_match', 'Faculty with duplicate first and last names require manual selection.');
professor_link_check($resolve([$account(1, 'Annie M Kadatuan')], [$annie, $faculty(2, 'Annie', 'N', 'Kadatuan')])[1]['eligible'], 'An explicit distinguishing middle initial can resolve faculty names.');
$duplicateAccounts = $resolve([$account(1, 'Annie Kadatuan'), $account(2, 'Annie M Kadatuan')], [$annie]);
professor_link_check($duplicateAccounts[1]['reason'] === 'ambiguous_account_match' && $duplicateAccounts[2]['reason'] === 'ambiguous_account_match', 'Two accounts for one faculty cannot auto-link in arbitrary order.');
$linked = $resolve([$account(1, 'Custom Login Name'), $account(2, 'Annie Kadatuan')], [$annie], [['user_id' => 1, 'faculty_id' => 1]]);
professor_link_check($linked[1]['eligible'] && !$linked[1]['auto_link'] && $linked[2]['reason'] === 'faculty_in_use', 'An explicit link is preserved and cannot be taken by another account.');
professor_link_check($resolve([$account(1, 'Annie Kadatuan')], [], [['user_id' => 1, 'faculty_id' => 1]])[1]['reason'] === 'invalid_faculty', 'A missing linked faculty record is rejected.');
professor_link_check($resolve([$account(1, 'Jose Cruz')], [$faculty(1, 'Jose', 'A', 'Cruz'), $faculty(2, 'Jose', 'B', 'Cruz', 'active', 'JR')])[1]['reason'] === 'ambiguous_faculty_match', 'Omitting a suffix cannot choose arbitrarily between faculty.');

if (in_array('--database', $argv, true)) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . '/../backend/db.php';
    $snapshot = static function () use ($conn): array {
        $records = [];
        foreach (['tbl_faculty', 'tbl_useraccount', 'tbl_useraccount_roles', 'tbl_useraccount_faculty_links'] as $table) {
            $records[$table] = $conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
        }
        return $records;
    };
    $before = $snapshot();
    $request = static function (string $scenario): array {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/professor-account-link-request.php', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
        if (!is_resource($process)) throw new RuntimeException('Could not start the account request fixture.');
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $errors !== '') throw new RuntimeException('Account request fixture failed: ' . $errors . $output);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    };

    $load = $request('load');
    professor_link_check($load['response']['status'] === 'ok', 'The real load_accounts handler succeeds.');
    $rows = $load['response']['accounts'];
    professor_link_check($rows['match']['professor_eligible'] && $rows['match']['professor_needs_link'], 'The page receives eligible auto-link candidates before assigning.');
    professor_link_check(!$rows['missing']['professor_eligible'] && !$rows['inactive']['professor_eligible'] && !$rows['duplicate']['professor_eligible'] && !$rows['in_use']['professor_eligible'], 'The page receives blocked accounts for disabled Professor checkboxes.');
    professor_link_check($load['accounts']['match']['faculty_id'] === 0, 'Loading the account list does not create faculty links.');

    $save = $request('assign');
    professor_link_check($save['response']['status'] === 'ok' && $save['response']['changed'] === 2 && $save['response']['auto_linked'] === 2, 'The real set_role handler saves the eligible match and repairs an already-professor account.');
    professor_link_check($save['accounts']['match']['faculty_id'] > 0 && in_array('professor', $save['accounts']['match']['roles'], true), 'Successful Professor assignment persists both the role and faculty link.');
    professor_link_check($save['accounts']['repair']['faculty_id'] > 0 && in_array('professor', $save['accounts']['repair']['roles'], true), 'An existing Professor role with no link is repaired instead of reported unchanged.');
    professor_link_check($save['response']['unchanged'] === 1, 'A valid already-linked Professor account remains unchanged.');
    $reasons = array_column($save['response']['skipped'], 'reason');
    foreach (['no_faculty_match', 'invalid_faculty', 'ambiguous_faculty_match', 'ambiguous_account_match', 'faculty_in_use'] as $reason) {
        professor_link_check(in_array($reason, $reasons, true), 'Server rejects forbidden Professor assignment: ' . $reason);
    }
    foreach (['missing', 'inactive', 'ambiguous', 'duplicate', 'in_use', 'substring'] as $name) {
        professor_link_check(!in_array('professor', $save['accounts'][$name]['roles'], true) && $save['accounts'][$name]['faculty_id'] === 0, 'A blocked account cannot save Professor access: ' . $name);
    }
    professor_link_check($save['accounts']['holder']['faculty_id'] > 0, 'The faculty link owned by another account is retained.');
    $failed = $request('fail_commit');
    professor_link_check($failed['response']['changed'] === 0 && $failed['accounts']['match']['faculty_id'] === 0 && !in_array('professor', $failed['accounts']['match']['roles'], true), 'A failed role transaction rolls back the auto-link and role together.');
    $unauthorized = $request('unauthorized');
    professor_link_check($unauthorized['response']['status'] === 'unauthorized' && $unauthorized['accounts']['match']['faculty_id'] === 0, 'An unauthorized request cannot assign or link accounts.');
    $directory = $request('directory');
    professor_link_check($directory['response']['skipped'] === [], 'The real directory eligible accounts all pass the actual Professor save handler.');
    professor_link_check($directory['directory']['eligible'] > 0 && $directory['directory']['linked_with_professor'] === $directory['directory']['eligible'], 'Every eligible real account saves Professor access with its matched faculty link.');
    professor_link_check($snapshot() === $before, 'All request fixtures were rolled back and real directory records are unchanged.');
}
echo 'Professor account links: ', $checks, ' checks passed.', PHP_EOL;
