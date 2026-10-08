<?php
// CLI-only request harness: execute the real endpoint inside an outer transaction.
// Endpoint transactions become savepoints so fixture records never persist.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/auth_useraccount.php';
require_once __DIR__ . '/../../backend/professor_account_link_helper.php';

class ProfessorLinkRequestConnection extends mysqli
{
    public bool $failCommit = false;
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction(int $flags = 0, ?string $name = null): bool { return $this->query('SAVEPOINT professor_link_request'); }
    public function commit(int $flags = 0, ?string $name = null): bool
    {
        if ($this->failCommit) throw new RuntimeException('QA transaction failure');
        return $this->query('RELEASE SAVEPOINT professor_link_request');
    }
    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->query('ROLLBACK TO SAVEPOINT professor_link_request');
        return $this->query('RELEASE SAVEPOINT professor_link_request');
    }
}
$conn->close();
$conn = new ProfessorLinkRequestConnection($servername, $username, $password, $dbase);
$conn->set_charset('utf8mb4');
if (!synk_useraccount_role_table_exists($conn) || !synk_professor_faculty_link_table_exists($conn)) {
    throw new RuntimeException('Request checks require the existing role and faculty link tables.');
}
$conn->startFixtures();
$scenario = $argv[1] ?? 'assign';
$conn->failCommit = $scenario === 'fail_commit';
$directoryEligibility = $scenario === 'directory' ? synk_professor_fetch_account_link_eligibility($conn) : [];
$tag = 'LinkQA' . bin2hex(random_bytes(8));
$users = [];
$makeUser = static function (string $label, string $name, string $role = 'admin') use ($conn, $tag, &$users): int {
    $email = strtolower($tag . '-' . $label) . '@sksu.edu.ph';
    $stmt = $conn->prepare("INSERT INTO tbl_useraccount (username, email, password, role, status) VALUES (?, ?, 'unusable-test-password', ?, 'active')");
    $legacyRole = 'admin';
    $stmt->bind_param('sss', $name, $email, $legacyRole);
    $stmt->execute(); $stmt->close();
    $userId = $users[$label] = (int)$conn->insert_id;
    if ($role === 'professor') {
        $conn->query("INSERT INTO tbl_useraccount_roles (user_id, role, is_primary, status) VALUES ({$userId}, 'admin', 1, 'active'), ({$userId}, 'professor', 0, 'active')");
    }
    return $userId;
};
$makeFaculty = static function (string $first, string $middle, string $last, string $status = 'active') use ($conn): int {
    $stmt = $conn->prepare('INSERT INTO tbl_faculty (first_name, middle_name, last_name, status) VALUES (?, ?, ?, ?)');
    $stmt->bind_param('ssss', $first, $middle, $last, $status);
    $stmt->execute(); $stmt->close();
    return (int)$conn->insert_id;
};
$makeFaculty('Annie', 'M', $tag . 'Match');
$makeUser('match', 'Annie ' . $tag . 'Match');
$makeUser('missing', $tag . ' Campus');
$makeFaculty('Inactive', 'M', $tag, 'inactive');
$makeUser('inactive', 'Inactive ' . $tag);
$makeFaculty('Ambiguous', 'M', $tag);
$makeFaculty('Ambiguous', 'N', $tag);
$makeUser('ambiguous', 'Ambiguous ' . $tag);
$makeFaculty('Duplicate', 'M', $tag);
$makeUser('duplicate', 'Duplicate ' . $tag);
$makeUser('duplicate_shadow', 'Duplicate M ' . $tag);
$inUseFaculty = $makeFaculty('Owned', 'M', $tag);
$holder = $makeUser('holder', 'Custom Holder ' . $tag);
$conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id, faculty_id) VALUES ({$holder}, {$inUseFaculty})");
$makeUser('in_use', 'Owned ' . $tag);
$makeFaculty('Repair', 'Middle', $tag);
$makeUser('repair', 'Repair ' . $tag, 'professor');
$linkedFaculty = $makeFaculty('Linked', 'Middle', $tag);
$linkedUser = $makeUser('linked', 'Custom Linked ' . $tag, 'professor');
$conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id, faculty_id) VALUES ({$linkedUser}, {$linkedFaculty})");
$makeFaculty('Annalisa', '', $tag);
$makeUser('substring', 'Anna ' . $tag);

session_save_path(sys_get_temp_dir());
session_id('synk-prof-link-' . bin2hex(random_bytes(8)));
session_start();
$_SESSION = ['user_id' => $holder, 'role' => $scenario === 'unauthorized' ? 'scheduler' : 'admin'];
session_write_close();
$_POST = $scenario === 'load' ? ['load_accounts' => 1, 'response_format' => 'json'] :
    ['set_role' => 1, 'response_format' => 'json', 'role' => 'professor', 'assigned' => '1', 'user_ids' => array_values(array_diff_key($users, array_flip(['holder', 'duplicate_shadow'])))];
if ($scenario === 'directory') {
    $_POST['user_ids'] = array_keys(array_filter($directoryEligibility, static fn(array $entry): bool => $entry['eligible']));
}
ob_start();
register_shutdown_function(static function () use ($conn, $users, $scenario, $directoryEligibility): void {
    $output = ob_get_clean();
    try {
        $response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if ($scenario === 'load') {
            $rowsById = array_column($response['accounts'], null, 'id');
            $response['accounts'] = [];
            foreach ($users as $label => $userId) $response['accounts'][$label] = $rowsById[$userId];
        }
        $state = [];
        foreach ($users as $label => $userId) {
            $row = $conn->query('SELECT role FROM tbl_useraccount WHERE user_id = ' . $userId)->fetch_assoc();
            $roles = synk_fetch_useraccount_role_rows($conn, $userId, $row['role']);
            $link = synk_professor_fetch_faculty_link($conn, $userId);
            $state[$label] = ['roles' => array_column($roles, 'role'), 'faculty_id' => (int)($link['faculty_id'] ?? 0)];
        }
        $directory = ['eligible' => 0, 'linked_with_professor' => 0];
        if ($scenario === 'directory') {
            foreach ($directoryEligibility as $userId => $entry) {
                if (!$entry['eligible']) continue;
                $directory['eligible']++;
                $row = $conn->query('SELECT role FROM tbl_useraccount WHERE user_id = ' . (int)$userId)->fetch_assoc();
                $roles = synk_fetch_useraccount_role_rows($conn, (int)$userId, $row['role']);
                $link = synk_professor_fetch_faculty_link($conn, (int)$userId);
                if (in_array('professor', array_column($roles, 'role'), true) && (int)($link['faculty_id'] ?? 0) === $entry['faculty_id']) $directory['linked_with_professor']++;
            }
        }
        $conn->discardFixtures();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        echo json_encode(['response' => $response, 'accounts' => $state, 'directory' => $directory], JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        $conn->discardFixtures();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        fwrite(STDERR, $error->getMessage() . '\nEndpoint output: ' . $output);
        exit(1);
    }
});
require __DIR__ . '/../../backend/query_accounts.php';
