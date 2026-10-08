<?php

require_once __DIR__ . '/professor_portal_helper.php';

function synk_professor_account_name_key(string $name): string
{
    $name = function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
    return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?: '');
}

function synk_professor_faculty_name_keys(array $faculty): array
{
    $first = synk_professor_account_name_key((string)($faculty['first_name'] ?? ''));
    $last = synk_professor_account_name_key((string)($faculty['last_name'] ?? ''));
    if ($first === '' || $last === '') {
        return [];
    }

    $middle = synk_professor_account_name_key((string)($faculty['middle_name'] ?? ''));
    $initials = [];
    foreach ($middle === '' ? [] : explode(' ', $middle) as $part) {
        preg_match('/^./u', $part, $initial);
        $initials[] = $initial[0] ?? '';
    }
    $middleForms = array_unique([$middle, implode(' ', $initials), '']);
    $suffixForms = array_unique([synk_professor_account_name_key((string)($faculty['ext_name'] ?? '')), '']);
    $keys = [];
    foreach ($middleForms as $middleForm) {
        foreach ($suffixForms as $suffix) {
            foreach ([[$first, $middleForm, $last, $suffix], [$last, $suffix, $first, $middleForm]] as $parts) {
                $key = implode(' ', array_filter($parts, static function (string $part): bool {
                    return $part !== '';
                }));
                $keys[$key] = true;
            }
        }
    }
    return array_keys($keys);
}

// Require one faculty candidate and one account candidate. Existing explicit links
// take precedence. Never use a substring, approximate spelling, or email guess.
function synk_professor_account_link_eligibility(array $accounts, array $facultyRows, array $links): array
{
    $facultyById = [];
    $facultyByName = [];
    foreach ($facultyRows as $faculty) {
        $facultyId = (int)($faculty['faculty_id'] ?? 0);
        if ($facultyId <= 0) continue;
        $facultyById[$facultyId] = $faculty;
        foreach (synk_professor_faculty_name_keys($faculty) as $key) {
            $facultyByName[$key][$facultyId] = $facultyId;
        }
    }

    $linksByUser = [];
    $usersByFaculty = [];
    foreach ($links as $link) {
        $userId = (int)($link['user_id'] ?? 0);
        $facultyId = (int)($link['faculty_id'] ?? 0);
        $linksByUser[$userId] = $facultyId;
        $usersByFaculty[$facultyId] = $userId;
    }

    $candidatesByUser = [];
    $candidatesByFaculty = [];
    foreach ($accounts as $account) {
        $userId = (int)($account['user_id'] ?? 0);
        if (isset($linksByUser[$userId])) continue;
        $key = synk_professor_account_name_key((string)($account['username'] ?? ''));
        $candidatesByUser[$userId] = array_values($facultyByName[$key] ?? []);
        foreach ($candidatesByUser[$userId] as $facultyId) {
            $candidatesByFaculty[$facultyId][$userId] = $userId;
        }
    }

    $eligibility = [];
    foreach ($accounts as $account) {
        $userId = (int)($account['user_id'] ?? 0);
        $entry = ['eligible' => false, 'reason' => 'no_faculty_match', 'faculty_id' => 0, 'faculty_name' => '', 'auto_link' => false];
        $linked = isset($linksByUser[$userId]);
        $candidates = $candidatesByUser[$userId] ?? [];
        $facultyId = $linked ? $linksByUser[$userId] : (count($candidates) === 1 ? $candidates[0] : 0);
        $faculty = $facultyById[$facultyId] ?? null;

        if ($linked && $faculty === null) {
            $entry['reason'] = 'invalid_faculty';
        } elseif (!$linked && count($candidates) > 1) {
            $entry['reason'] = 'ambiguous_faculty_match';
        } elseif ($faculty !== null) {
            $entry['faculty_id'] = $facultyId;
            $entry['faculty_name'] = synk_professor_full_name_from_row($faculty);
            $status = strtolower(trim((string)($faculty['status'] ?? 'active')));
            if ($status !== 'active') {
                $entry['reason'] = 'invalid_faculty';
            } elseif (!$linked && isset($usersByFaculty[$facultyId])) {
                $entry['reason'] = 'faculty_in_use';
            } elseif (!$linked && count($candidatesByFaculty[$facultyId] ?? []) !== 1) {
                $entry['reason'] = 'ambiguous_account_match';
            } else {
                $entry['eligible'] = true;
                $entry['reason'] = '';
                $entry['auto_link'] = !$linked;
            }
        }
        $eligibility[$userId] = $entry;
    }
    return $eligibility;
}

function synk_professor_fetch_account_link_eligibility(mysqli $conn, ?array $accounts = null): array
{
    if ($accounts === null) {
        $result = $conn->query('SELECT user_id, username FROM tbl_useraccount');
        $accounts = $result->fetch_all(MYSQLI_ASSOC);
        $result->close();
    }
    $result = $conn->query("SELECT faculty_id, first_name, middle_name, last_name, ext_name, COALESCE(status, 'active') AS status FROM tbl_faculty");
    $facultyRows = $result->fetch_all(MYSQLI_ASSOC);
    $result->close();
    $links = [];
    if (synk_professor_faculty_link_table_exists($conn)) {
        $result = $conn->query('SELECT user_id, faculty_id FROM `' . synk_professor_faculty_link_table_name() . '`');
        $links = $result->fetch_all(MYSQLI_ASSOC);
        $result->close();
    }
    return synk_professor_account_link_eligibility($accounts, $facultyRows, $links);
}

// The caller owns the transaction. Check the faculty status and unique links under
// locks, then insert without replacing any link another administrator has saved.
function synk_professor_insert_auto_account_link(mysqli $conn, int $userId, int $facultyId): ?string
{
    $stmt = $conn->prepare("SELECT COALESCE(status, 'active') AS status FROM tbl_faculty WHERE faculty_id = ? FOR UPDATE");
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $faculty = $result->fetch_assoc();
    $result->close();
    $stmt->close();
    if (!$faculty || strtolower(trim((string)$faculty['status'])) !== 'active') return 'invalid_faculty';

    $tableName = synk_professor_faculty_link_table_name();
    $stmt = $conn->prepare("SELECT user_id, faculty_id FROM `{$tableName}` WHERE user_id = ? OR faculty_id = ? FOR UPDATE");
    $stmt->bind_param('ii', $userId, $facultyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $links = $result->fetch_all(MYSQLI_ASSOC);
    $result->close();
    $stmt->close();
    if ($links) {
        return count($links) === 1 && (int)$links[0]['user_id'] === $userId && (int)$links[0]['faculty_id'] === $facultyId
            ? null : 'faculty_in_use';
    }

    $stmt = $conn->prepare("INSERT INTO `{$tableName}` (user_id, faculty_id) VALUES (?, ?)");
    $stmt->bind_param('ii', $userId, $facultyId);
    $saved = $stmt->execute();
    $stmt->close();
    return $saved ? null : 'save_failed';
}
