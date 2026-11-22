<?php

require_once __DIR__ . '/../backend/schema_helper.php';

function synk_hris_faculty_statuses(): array
{
    return [
        'active' => ['Active', 'green'],
        'enactive' => ['Enactive', 'amber'],
        'retired' => ['Retired', 'blue'],
    ];
}

function synk_hris_load_faculty_statuses(mysqli $conn): ?array
{
    if (!synk_table_exists($conn, 'tbl_hr_faculty_statuses')) return null;

    $statuses = [];
    $reviewer = 'u.username';
    $joins = 'LEFT JOIN tbl_useraccount u ON u.user_id = s.updated_by';
    if (synk_table_has_column($conn, 'tbl_hr_faculty_statuses', 'updated_by_hr_account')) {
        $reviewer = "COALESCE(u.username, NULLIF(CONCAT_WS(' ', NULLIF(TRIM(f.first_name), ''), NULLIF(TRIM(f.last_name), '')), ''), a.email)";
        $joins .= ' LEFT JOIN tbl_hr_employee_accounts a ON a.account_id = s.updated_by_hr_account
            LEFT JOIN tbl_faculty f ON f.faculty_id = a.faculty_id';
    }
    $result = $conn->query("SELECT s.record_key, s.status, s.revision, s.updated_at, {$reviewer} AS reviewer
        FROM tbl_hr_faculty_statuses s {$joins}");
    while ($row = $result->fetch_assoc()) {
        $statuses[$row['record_key']] = $row;
    }
    $result->close();
    return $statuses;
}

function synk_hris_save_faculty_status(mysqli $conn, string $owner, string $status, int $revision, int $reviewer, bool $confirmed): void
{
    if (!isset(synk_hris_faculty_statuses()[$status])) throw new DomainException('Select an HR status.');
    if ($status === 'active' && !$confirmed) {
        throw new DomainException('Confirm that HR verified the employee’s identity and current employment before marking them Active.');
    }
    if (!synk_table_exists($conn, 'tbl_hr_faculty_statuses')) {
        throw new DomainException('HR status setup is required before statuses can be saved.');
    }
    $hasKawaniReviewer = synk_table_has_column($conn, 'tbl_hr_faculty_statuses', 'updated_by_hr_account');
    $synkReviewer = $reviewer > 0 ? $reviewer : null;
    $kawaniReviewer = $reviewer < 0 ? -$reviewer : null;
    if ($kawaniReviewer && !$hasKawaniReviewer) throw new DomainException('Kawani reviewer setup is required before statuses can be saved.');

    if ($revision === 0) {
        if ($hasKawaniReviewer) {
            $stmt = $conn->prepare('INSERT INTO tbl_hr_faculty_statuses (record_key, status, updated_by, updated_by_hr_account) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssii', $owner, $status, $synkReviewer, $kawaniReviewer);
        } else {
            $stmt = $conn->prepare('INSERT INTO tbl_hr_faculty_statuses (record_key, status, updated_by) VALUES (?, ?, ?)');
            $stmt->bind_param('ssi', $owner, $status, $synkReviewer);
        }
    } else {
        $hrField = $hasKawaniReviewer ? ', updated_by_hr_account = ?' : '';
        $stmt = $conn->prepare("UPDATE tbl_hr_faculty_statuses
            SET status = ?, revision = revision + 1, updated_by = ?{$hrField}, updated_at = CURRENT_TIMESTAMP
            WHERE record_key = ? AND revision = ?");
        if ($hasKawaniReviewer) $stmt->bind_param('siisi', $status, $synkReviewer, $kawaniReviewer, $owner, $revision);
        else $stmt->bind_param('sisi', $status, $synkReviewer, $owner, $revision);
    }

    $changed = false;
    try {
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception $error) {
        if ($revision !== 0 || (int)$error->getCode() !== 1062) throw $error;
    } finally {
        $stmt->close();
    }
    if (!$changed) {
        throw new DomainException('This HR status changed in another window. Review the current status and try again.');
    }
}
