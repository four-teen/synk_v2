<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require dirname(__DIR__) . '/docs/oracle-integration/synk-research-api.php';
require dirname(__DIR__) . '/professor/oracle-research.php';

$checks = 0;
function oracleCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function oracleReject(callable $action, string $message, ?int $status = null): void
{
    try { $action(); } catch (OracleSynkError | SynkOracleError $error) {
        oracleCheck(strpos($error->getMessage(), $message) !== false && ($status === null || $error->httpStatus === $status), 'Unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$secret = str_repeat('b', 64); // Disposable test credential, never used by either deployment file.
$config = ['shared_secret' => $secret, 'allowed_domain' => 'sksu.edu.ph', 'max_file_bytes' => 20 * 1024 * 1024];
$now = 1800000000;
$signed = static function (array $input) use ($config, $now): array {
    $body = json_encode($input, JSON_THROW_ON_ERROR);
    return [$body, ['HTTPS' => 'on', 'SERVER_PORT' => '443', 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json; charset=UTF-8',
        'HTTP_X_SYNK_TIMESTAMP' => (string) $now, 'HTTP_X_SYNK_SIGNATURE' => hash_hmac('sha256', $now . "\n" . $body, $config['shared_secret'])]];
};
[$body, $server] = $signed(['email' => '  FACULTY@SKSU.EDU.PH  ']);
$input = OracleSynkApi::request($body, $server, $config, $now);
oracleCheck($input['email'] === 'faculty@sksu.edu.ph', 'Verified request normalizes the account email.');
oracleReject(fn() => OracleSynkApi::request($body . ' ', $server, $config, $now), 'authenticated', 401);
oracleReject(fn() => OracleSynkApi::request($body, $server, $config, $now + 301), 'authenticated', 401);
oracleReject(fn() => OracleSynkApi::request($body, array_replace($server, ['HTTP_X_SYNK_SIGNATURE' => '']), $config, $now), 'authenticated', 401);
oracleReject(fn() => OracleSynkApi::request($body, array_replace($server, ['HTTPS' => 'off', 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https']), $config, $now), 'HTTPS', 403);
oracleReject(fn() => OracleSynkApi::request($body, array_replace($server, ['REQUEST_METHOD' => 'GET']), $config, $now), 'POST', 405);
oracleReject(fn() => OracleSynkApi::request($body, array_replace($server, ['CONTENT_TYPE' => 'text/plain']), $config, $now), 'application/json', 415);
oracleReject(fn() => OracleSynkApi::request($body, $server, array_replace($config, ['shared_secret' => '']), $now), 'configured', 503);
foreach ([['email' => 'faculty@gmail.com'], ['email' => ['faculty@sksu.edu.ph']], ['email' => 'faculty@sksu.edu.ph', 'page' => -1],
    ['email' => 'faculty@sksu.edu.ph', 'per_page' => 51], ['email' => 'faculty@sksu.edu.ph', 'research_id' => '1 OR 1=1'],
    ['email' => 'faculty@sksu.edu.ph', 'action' => 'abstract', 'research_id' => 0]] as $bad) {
    [$raw, $headers] = $signed($bad);
    oracleReject(fn() => OracleSynkApi::request($raw, $headers, $config, $now), '', 422);
}

$native = ($argv[1] ?? '') === '--mysql';
if ($native) {
    $pdo = SynkOracleResearch::connection();
    $testDatabase = 'synk_oracle_related_' . bin2hex(random_bytes(8));
    if (!preg_match('/\Asynk_oracle_related_[a-f0-9]{16}\z/', $testDatabase)) { throw new RuntimeException('Unsafe test database name.'); }
    $pdo->exec('CREATE DATABASE `' . $testDatabase . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    register_shutdown_function(static function () use ($pdo, $testDatabase) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $pdo->exec('DROP DATABASE `' . $testDatabase . '`');
    });
    $pdo->exec('USE `' . $testDatabase . '`');
} else {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('FIND_IN_SET', static function ($id, $csv) {
        $position = array_search((string) $id, explode(',', (string) $csv), true);
        return $position === false ? 0 : $position + 1;
    }, 2);
}
foreach ([
    'tblaccount' => 'accountid INTEGER PRIMARY KEY, acc_name TEXT, email TEXT, is_enabled INTEGER',
    'tblresearches' => 'titleid INTEGER PRIMARY KEY, title TEXT, authors TEXT, status TEXT, sdgs TEXT, other_details TEXT,
        owner_accountid INTEGER, is_published INTEGER, submitted_at TEXT, updated_at TEXT, abstract_file_path TEXT,
        abstract_original_name TEXT, typeid INTEGER, campusid INTEGER, programid INTEGER, ayid INTEGER, adviser_accountid INTEGER, encoder INTEGER,
        statistician_accountid INTEGER, english_critic_accountid INTEGER, panelist_accountids TEXT, adviser_name TEXT, statisticians TEXT,
        english_critic_name TEXT, panelists TEXT',
    'tblmanuscript_coauthors' => 'manuscriptid INTEGER, accountid INTEGER',
    'tblmanuscript_panelists' => 'manuscriptid INTEGER, accountid INTEGER',
    'tblresearchtype' => 'researchtypeid INTEGER, research_type TEXT',
    'tblcampus' => 'campusid INTEGER, campusname TEXT',
    'tblcourse' => 'courseid INTEGER, coursecode TEXT',
    'tblacademic_year' => 'ayid INTEGER, ay_from TEXT, ay_to TEXT',
] as $table => $columns) { $pdo->exec('CREATE TABLE ' . $table . ' (' . $columns . ')'); }
$pdo->exec("INSERT INTO tblaccount VALUES (10,'Faculty',' FACULTY@sksu.edu.ph ',1),(20,'Other','other@sksu.edu.ph',1),(30,'Disabled','disabled@sksu.edu.ph',0),
    (40,'Duplicate one','duplicate@sksu.edu.ph',1),(41,'Duplicate two',' DUPLICATE@sksu.edu.ph ',0),(50,'Empty portfolio','empty@sksu.edu.ph',1)");
$pdo->exec("INSERT INTO tblresearchtype VALUES (1,'Faculty Research'); INSERT INTO tblcampus VALUES (1,'Isulan');
    INSERT INTO tblcourse VALUES (1,'BSIT'); INSERT INTO tblacademic_year VALUES (1,'2025','2026')");
$insert = $pdo->prepare('INSERT INTO tblresearches (titleid,title,authors,status,sdgs,other_details,owner_accountid,is_published,submitted_at,
    updated_at,abstract_file_path,abstract_original_name,typeid,campusid,programid,ayid,adviser_accountid,encoder) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
foreach ([
    [1, 'Owned internal research', 10, 0, 'uploads/manuscript_abstracts/allowed.pdf', 0, 10],
    [2, 'Coauthored research', 20, 1, 'uploads/manuscript_abstracts/allowed.pdf', 0, 20],
    [3, 'FOREIGN_RESEARCH_SECRET', 20, 0, 'uploads/manuscript_abstracts/allowed.pdf', 0, 20],
    [4, 'Advised student research', null, 1, 'uploads/manuscript_abstracts/allowed.pdf', 10, 10],
    [5, 'Owned and coauthored once', 10, 1, 'uploads/manuscript_abstracts/missing.pdf', 0, 10],
    [6, 'Encoded for somebody else', 20, 1, '', 0, 10],
    [7, 'Free text name only', 20, 1, '', 0, 20],
    [8, 'Literal 50%_ <img src=x onerror=alert(1)>', 10, 1, 'uploads/manuscript_abstracts/unsafe.html', 0, 10],
    [9, 'Panel assignment', 20, 1, 'uploads/manuscript_abstracts/allowed.pdf', 0, 20],
    [10, 'Statistician assignment', 20, 0, 'uploads/manuscript_abstracts/allowed.pdf', 0, 20],
    [11, 'English critic assignment', 20, 1, 'uploads/manuscript_abstracts/allowed.pdf', 0, 20],
    [12, 'Multiple faculty roles', 20, 1, 'uploads/manuscript_abstracts/allowed.pdf', 10, 20],
    [13, 'Legacy panel assignment', 20, 1, 'uploads/manuscript_abstracts/../../secret.pdf', 0, 20],
    [14, 'CSV boundary belongs to another faculty', 20, 1, '', 0, 20],
    [15, 'Other faculty assignments', 20, 1, '', 20, 20],
] as [$id, $title, $owner, $published, $file, $adviser, $encoder]) {
    $insert->execute([$id, $title, '["Faculty","José Peña"]', 'Completed', '4,9', '<p>Abstract &amp; details</p><script>alert(1)</script>',
        $owner, $published, '2026-01-01 10:00:00', '2026-10-22 10:00:00', $file, '', 1, 1, 1, 1, $adviser, $encoder]);
}
for ($id = 101; $id <= 118; $id++) {
    $insert->execute([$id, 'Portfolio ' . $id, '["Faculty"]', 'On-going', '4', 'Portfolio abstract', 10, 0, '2026-01-01', '2026-10-01', '', '', 1, 1, 1, 1, 0, 10]);
}
$pdo->exec('INSERT INTO tblmanuscript_coauthors VALUES (2,10),(5,10),(5,10)');
$pdo->exec('INSERT INTO tblmanuscript_coauthors VALUES (12,10)');
$pdo->exec('INSERT INTO tblmanuscript_panelists VALUES (9,10),(9,10),(12,10),(15,20)');
$pdo->exec("UPDATE tblresearches SET statistician_accountid=10 WHERE titleid IN (8,10,12) OR titleid BETWEEN 101 AND 118");
$pdo->exec("UPDATE tblresearches SET english_critic_accountid=10 WHERE titleid IN (11,12)");
$pdo->exec("UPDATE tblresearches SET statistician_accountid=20,english_critic_accountid=20 WHERE titleid=15");
$pdo->exec("UPDATE tblresearches SET panelist_accountids='20, 10, 30' WHERE titleid=13");
$pdo->exec("UPDATE tblresearches SET panelist_accountids='110,210' WHERE titleid=14");
if ($native) { $pdo->exec('SET TRANSACTION READ ONLY'); $pdo->beginTransaction(); }
else { $pdo->exec('PRAGMA query_only = ON'); } // Application reads must never write to Oracle data.
OracleSynkApi::schema($pdo);
$account = OracleSynkApi::account($pdo, $input['email']);
oracleCheck((int) $account['accountid'] === 10, 'The email maps to the correct Oracle account.');
oracleReject(fn() => OracleSynkApi::account($pdo, 'missing@sksu.edu.ph'), 'matching Oracle account', 404);
oracleReject(fn() => OracleSynkApi::account($pdo, 'duplicate@sksu.edu.ph'), 'More than one', 409);
oracleReject(fn() => OracleSynkApi::account($pdo, 'disabled@sksu.edu.ph'), 'disabled', 403);
$all = OracleSynkApi::research($pdo, $account, array_replace($input, ['per_page' => 50]));
oracleCheck($all['pagination']['total'] === 25 && count($all['items']) === 25, 'Only the four academic assignment roles grant access; records are deduplicated.');
$ids = array_column($all['items'], 'id');
oracleCheck(!array_intersect([1,2,3,5,6,7,14,15], $ids) && strpos(json_encode($all), 'FOREIGN_RESEARCH_SECRET') === false, 'Ownership, coauthorship, encoding, free-text names, and partial CSV IDs cannot grant access.');
$mapped = array_column($all['items'], null, 'id');
oracleCheck($mapped[10]['relationship'] === 'Statistician' && !$mapped[10]['is_published'], 'Academic assignments permit internal research regardless of publication status.');
oracleCheck($mapped[4]['roles'] === ['Adviser'] && !isset($mapped[6]), 'Encoding alone is excluded; an encoder with an academic assignment is included only for that assignment.');
oracleCheck($mapped[12]['roles'] === ['Adviser','Panelist','Statistician','English Critic'], 'Every simultaneous academic role is retained without administrative roles.');
oracleCheck($mapped[9]['roles'] === ['Panelist'] && $mapped[13]['roles'] === ['Panelist'], 'Normalized and legacy CSV panel assignments are matched exactly.');
$portfolio = OracleSynkApi::research($pdo, $account, array_replace($input, ['action' => 'portfolio', 'per_page' => 1]));
oracleCheck(count($portfolio['items']) === 25 && $portfolio['pagination']['pages'] === 1, 'The portfolio loads all matching records without manual search or pagination.');
$groups = SynkOracleResearch::groups($portfolio['items']);
oracleCheck(count($groups) === 4 && count($groups['Adviser']) === 2 && count($groups['Panelist']) === 3
    && count($groups['Statistician']) === 21 && count($groups['English Critic']) === 2, 'Only the four academic role groups and their counts appear.');
oracleCheck(array_sum($portfolio['role_counts']) === 28 && $portfolio['pagination']['total'] === 25, 'Unique studies and academic role assignments are counted separately.');
$legacyPortfolio = $portfolio;
foreach ([1 => 'Owner', 2 => 'Co-author', 6 => 'Encoder'] as $id => $role) {
    $legacyRecord = $portfolio['items'][0];
    $legacyRecord['id'] = $id; $legacyRecord['roles'] = [$role]; $legacyRecord['relationship'] = $role;
    $legacyPortfolio['items'][] = $legacyRecord; $legacyPortfolio['role_counts'][$role] = 1;
}
$legacyPortfolio['items'][0]['roles'][] = 'Encoder';
$legacyPortfolio['role_counts']['Encoder']++;
$legacyPortfolio['pagination']['per_page'] = 28; $legacyPortfolio['pagination']['total'] = 28;
SynkOracleResearch::validate($legacyPortfolio, 'faculty@sksu.edu.ph');
$restricted = SynkOracleResearch::academicAssignments($legacyPortfolio);
oracleCheck(count($restricted['items']) === 25 && !array_intersect([1,2,6], array_column($restricted['items'], 'id')),
    'Synk removes owner-only, coauthor-only, and encoder-only records even before the live Oracle API is updated.');
oracleCheck($restricted['role_counts'] === $portfolio['role_counts'] && $restricted['pagination']['total'] === 25
    && !in_array('Encoder', $restricted['items'][0]['roles'], true), 'Legacy administrative roles do not inflate counts, tabs, or mixed-role labels.');
foreach ([1,2,6] as $id) {
    $legacyDetail = $legacyPortfolio; $legacyDetail['items'] = array_values(array_filter($legacyPortfolio['items'], fn($item) => $item['id'] === $id));
    oracleReject(fn() => SynkOracleResearch::academicAssignments($legacyDetail, $id), 'unavailable for your account', 404);
}
$emptyPortfolio = OracleSynkApi::research($pdo, OracleSynkApi::account($pdo, 'empty@sksu.edu.ph'), array_replace($input, ['action' => 'portfolio']));
SynkOracleResearch::validate($emptyPortfolio, 'empty@sksu.edu.ph');
oracleCheck($emptyPortfolio['items'] === [] && $emptyPortfolio['pagination']['total'] === 0 && SynkOracleResearch::groups($emptyPortfolio['items']) === [], 'A matching account with no assignments receives a valid, empty portfolio.');
$badCounts = $portfolio; $badCounts['role_counts']['Adviser']++;
oracleReject(fn() => SynkOracleResearch::validate($badCounts, 'faculty@sksu.edu.ph'), 'inconsistent research role counts');
$unknownRole = $portfolio; $unknownRole['items'][0]['roles'] = ['Administrator']; $unknownRole['items'][0]['relationship'] = 'Administrator';
oracleReject(fn() => SynkOracleResearch::validate($unknownRole, 'faculty@sksu.edu.ph'), 'unrecognized research role');
oracleCheck($mapped[4]['authors'] === 'Faculty, José Peña' && $mapped[4]['academic_year'] === '2025 - 2026', 'Unicode authors and academic years survive serialization.');
$first = OracleSynkApi::research($pdo, $account, $input);
$second = OracleSynkApi::research($pdo, $account, array_replace($input, ['page' => 2]));
$third = OracleSynkApi::research($pdo, $account, array_replace($input, ['page' => 3]));
oracleCheck(count($first['items']) === 12 && count($second['items']) === 12 && count($third['items']) === 1
    && count(array_unique(array_merge(array_column($first['items'],'id'),array_column($second['items'],'id'),array_column($third['items'],'id')))) === 25, 'Paginated API reads still reach all matching research without overlap.');
$last = OracleSynkApi::research($pdo, $account, array_replace($input, ['page' => 999]));
oracleCheck($last['pagination']['page'] === 3, 'Out-of-range pages resolve to the last page.');
$literal = OracleSynkApi::research($pdo, $account, array_replace($input, ['search' => '50%_']));
oracleCheck(count($literal['items']) === 1 && $literal['items'][0]['id'] === 8, 'LIKE wildcard characters are searched literally.');
$none = OracleSynkApi::research($pdo, $account, array_replace($input, ['search' => "' OR 1=1 --"]));
oracleCheck($none['items'] === [] && $none['pagination']['total'] === 0, 'Search cannot alter SQL or widen ownership.');
$detail = OracleSynkApi::research($pdo, $account, array_replace($input, ['research_id' => 9]));
oracleCheck(count($detail['items']) === 1 && $detail['items'][0]['id'] === 9, 'Panel-assigned research details can be opened.');
foreach ([1,2,3,5,6] as $forbiddenId) {
    oracleReject(fn() => OracleSynkApi::research($pdo, $account, array_replace($input, ['research_id' => $forbiddenId])), 'unavailable for your account', 404);
}

$root = dirname(__DIR__) . '/.tmp/oracle-api-' . bin2hex(random_bytes(6));
mkdir($root . '/uploads/manuscript_abstracts', 0777, true);
file_put_contents($root . '/uploads/manuscript_abstracts/allowed.pdf', "%PDF-1.4\nIsolated fixture\n");
file_put_contents($root . '/uploads/manuscript_abstracts/unsafe.html', '<script>alert(1)</script>');
file_put_contents($root . '/secret.pdf', 'PRIVATE_CONFIG_FIXTURE');
try {
    $file = OracleSynkApi::attachment($pdo, $account, 4, $root, 1024);
    oracleCheck($file['extension'] === 'pdf' && is_file($file['path']), 'Academically assigned abstract attachments can be downloaded.');
    foreach ([4,9,10,11,12] as $id) {
        oracleCheck(OracleSynkApi::attachment($pdo, $account, $id, $root, 1024)['extension'] === 'pdf', 'Attachments respect each assigned role: ' . $id);
    }
    foreach ([1,2,3,5,6,13,999] as $id) { oracleReject(fn() => OracleSynkApi::attachment($pdo, $account, $id, $root, 1024), 'not available', 404); }
    oracleReject(fn() => OracleSynkApi::attachment($pdo, $account, 8, $root, 1024), 'not supported', 415);
    oracleReject(fn() => OracleSynkApi::attachment($pdo, $account, 4, $root, 1), 'limit', 413);
} finally {
    foreach (['uploads/manuscript_abstracts/allowed.pdf', 'uploads/manuscript_abstracts/unsafe.html', 'secret.pdf'] as $file) { unlink($root . '/' . $file); }
    rmdir($root . '/uploads/manuscript_abstracts'); rmdir($root . '/uploads'); rmdir($root);
}

$synk = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$synk->exec('CREATE TABLE tbl_useraccount (user_id INTEGER PRIMARY KEY, username TEXT, email TEXT, role TEXT, status TEXT)');
$synk->exec("INSERT INTO tbl_useraccount VALUES (1,'Professor',' FACULTY@SKSU.EDU.PH ','professor','active'),(2,'Multi-role','faculty@sksu.edu.ph','admin','active'),
    (3,'Inactive','faculty@sksu.edu.ph','professor','inactive'),(4,'Revoked','faculty@sksu.edu.ph','professor','active'),
    (5,'Fallback','faculty@sksu.edu.ph','professor','active'),(6,'Admin','faculty@sksu.edu.ph','admin','active'),(7,'External','faculty@gmail.com','professor','active')");
$session = ['user_id' => 1, 'role' => 'professor', 'email' => 'other@sksu.edu.ph'];
$identity = SynkOracleResearch::identity($synk, $session, 'sksu.edu.ph');
oracleCheck($identity['email'] === 'faculty@sksu.edu.ph', 'Identity is read from the current account, with legacy roles supported.');
$synk->exec('CREATE TABLE tbl_useraccount_roles (user_id INTEGER, role TEXT, status TEXT)');
$synk->exec("INSERT INTO tbl_useraccount_roles VALUES (1,'professor','active'),(2,'professor','active'),(4,'professor','inactive'),(4,'admin','active')");
oracleCheck(SynkOracleResearch::identity($synk, array_replace($session, ['user_id' => 2]), 'sksu.edu.ph')['email'] === 'faculty@sksu.edu.ph', 'The selected professor role works for multi-role accounts.');
foreach ([['user_id' => 3], ['user_id' => 4], ['user_id' => 6], ['user_id' => 7], ['user_id' => 999], ['role' => 'admin'], ['user_id' => 0]] as $change) {
    oracleReject(fn() => SynkOracleResearch::identity($synk, array_replace($session, $change), 'sksu.edu.ph'), '', 403);
}
oracleCheck(SynkOracleResearch::identity($synk, array_replace($session, ['user_id' => 5]), 'sksu.edu.ph')['name'] === 'Fallback', 'An account with no explicit role rows uses its primary role.');
foreach ([['email' => 'other@sksu.edu.ph'], ['user_id' => '20'], ['q' => ['bad']], ['page' => '0'], ['id' => '-1'], ['download' => '1 OR 1=1']] as $bad) {
    oracleReject(fn() => SynkOracleResearch::input($bad), '', 422);
}
SynkOracleResearch::validate($all, $identity['email']);
$_SERVER['PHP_SELF'] = '/synk/professor/oracle-research.php';
oracleReject(fn() => SynkOracleResearch::validate($all, 'other@sksu.edu.ph'), 'unexpected account');
ob_start(); synk_oracle_render($identity, SynkOracleResearch::input([]), $portfolio, ''); $html = ob_get_clean();
oracleCheck(strpos($html, '<img src=x onerror=alert(1)>') === false && strpos($html, '&lt;img src=x onerror=alert(1)&gt;') !== false, 'Remote titles are HTML-escaped.');
oracleCheck(strpos($html, 'FOREIGN_RESEARCH_SECRET') === false && strpos($html, $secret) === false && strpos($html, 'synk-research-api.php') === false, 'The browser sees neither foreign records, connection credentials, nor the API address.');
oracleCheck(strpos($html, 'My Researches') !== false && strpos($html, 'data-role="Adviser"') !== false
    && strpos($html, 'role="search"') !== false && strpos($html, 'professor-menu-card active') !== false, 'The professor page includes account-scoped search and role tabs.');
preg_match('~<script type="application/json" id="researchIndex">(.*?)</script>~s', $html, $indexMatch);
$index = json_decode($indexMatch[1] ?? '', true);
oracleCheck(is_array($index) && count($index) === 25 && !array_intersect([1,2,3,5,6,7,14,15], array_column($index,'id')), 'The complete search index excludes encoder-only, owner-only, and coauthor-only records.');
oracleCheck($index[0]['roles'] === $portfolio['items'][0]['roles'] && strpos($indexMatch[1], '<script>') === false, 'Role metadata is retained and the embedded index cannot inject a script tag.');
ob_start(); synk_oracle_render($identity, SynkOracleResearch::input(['id' => '9']), $detail, ''); $detailHtml = ob_get_clean();
oracleCheck(strpos($detailHtml, 'Abstract / research details') !== false && strpos($detailHtml, '<script>') === false && strpos($detailHtml, 'Download abstract') !== false, 'Details render full abstract text and authorized download links without executable HTML.');
ob_start(); synk_oracle_render($identity, SynkOracleResearch::input([]), null, 'Connection failed.'); $errorHtml = ob_get_clean();
oracleCheck(strpos($errorHtml, 'Research could not be loaded') !== false && strpos($errorHtml, 'No related research yet') === false, 'Connection errors are distinct from empty portfolios.');

if (($argv[1] ?? '') === '--render') {
    file_put_contents($argv[2], $html);
    file_put_contents($argv[3], $detailHtml);
}
if (($argv[1] ?? '') === '--transport') {
    $transport = array_replace($synkOracleConfig, ['endpoint' => $argv[2], 'ca_file' => $argv[3], 'shared_secret' => $secret, 'timeout' => 3]);
    $payload = ['action' => 'research', 'email' => $identity['email']];
    oracleCheck(SynkOracleResearch::fetch($transport, ['action' => 'health'])['database'] === 'ready', 'The real cURL client authenticates over verified HTTPS.');
    oracleCheck(SynkOracleResearch::fetch($transport, $payload)['account']['email'] === $identity['email'], 'The cURL client accepts valid research JSON.');
    oracleCheck(count(SynkOracleResearch::fetch($transport, array_replace($payload, ['action' => 'portfolio']))['items']) === 1, 'The full portfolio action is accepted over signed HTTPS.');
    $legacyResponse = SynkOracleResearch::fetch($transport, array_replace($payload, ['action' => 'portfolio', 'search' => 'legacy-portfolio']));
    oracleCheck(count($legacyResponse['items']) === 1 && $legacyResponse['pagination']['total'] === 1
        && $legacyResponse['items'][0]['roles'] === ['Adviser','Panelist'] && count($legacyResponse['role_counts']) === 4,
        'The real HTTPS client removes administrative-only records and roles from a legacy live API response.');
    oracleReject(fn() => SynkOracleResearch::fetch($transport, array_replace($payload, ['action' => 'portfolio', 'search' => 'partial-portfolio'])), 'incomplete research portfolio');
    oracleReject(fn() => SynkOracleResearch::fetch($transport, array_replace($payload, ['action' => 'portfolio', 'search' => 'legacy-action'])), 'updated research connection');
    oracleCheck(SynkOracleResearch::fetch($transport, $payload + ['search' => 'clock-skew'])['account']['email'] === $identity['email'], 'One signed retry recovers a six-minute difference using the verified HTTPS response time.');
    oracleReject(fn() => SynkOracleResearch::fetch($transport, $payload + ['search' => 'clock-far']), 'authenticated');
    oracleReject(fn() => SynkOracleResearch::fetch($transport, $payload + ['search' => 'clock-reject']), 'authenticated');
    $binary = SynkOracleResearch::fetch($transport, array_replace($payload, ['action' => 'abstract', 'research_id' => 1]));
    oracleCheck($binary['extension'] === 'pdf' && strpos($binary['file'], '%PDF') === 0, 'Abstract bytes pass through the signed HTTPS client.');
    foreach ([2,3,4] as $forbiddenId) {
        oracleReject(fn() => SynkOracleResearch::fetch($transport, array_replace($payload, ['action' => 'research', 'research_id' => $forbiddenId])), 'unavailable for your account', 404);
        oracleReject(fn() => SynkOracleResearch::fetch($transport, array_replace($payload, ['action' => 'abstract', 'research_id' => $forbiddenId])), 'unavailable for your account', 404);
    }
    foreach (['redirect' => 'redirected', 'wrong-account' => 'unexpected account', 'bad-json' => 'unexpected response', 'html' => 'unexpected response',
        'disabled' => 'disabled', 'old-version' => 'updated research connection'] as $search => $expected) {
        oracleReject(fn() => SynkOracleResearch::fetch($transport, $payload + ['search' => $search]), $expected);
    }
    oracleReject(fn() => SynkOracleResearch::fetch(array_replace($transport, ['shared_secret' => str_repeat('c', 64)]), $payload), 'authenticated');
    oracleReject(fn() => SynkOracleResearch::fetch(array_replace($transport, ['max_response_bytes' => 64]), $payload), 'more data');
    oracleReject(fn() => SynkOracleResearch::fetch(array_replace($transport, ['timeout' => 1]), $payload + ['search' => 'slow']), 'could not be reached');
    oracleReject(fn() => SynkOracleResearch::fetch(array_replace($transport, ['endpoint' => str_replace('https:', 'http:', $transport['endpoint'])]), $payload), 'HTTPS', 503);
}
echo 'Oracle research: ' . $checks . ' checks passed; ' . ($native ? 'disposable MySQL fixtures only; test database removed on exit.' : 'no application databases used.') . "\n";
