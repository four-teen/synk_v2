<?php

function synk_hris_read_review(mysqli $conn, string $owner): ?array
{
    $stmt = $conn->prepare('SELECT r.review_status, r.review_note, r.data_version, r.revision, r.updated_at, u.username
        FROM tbl_hr_faculty_reviews r JOIN tbl_useraccount u ON u.user_id = r.updated_by
        WHERE r.record_key = ?');
    $stmt->bind_param('s', $owner);
    $stmt->execute();
    $review = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $review ?: null;
}

function synk_hris_write_review(mysqli $conn, string $owner, string $status, string $note,
    string $dataVersion, int $reviewerId, int $revision): bool
{
    if ($revision === 0) {
        $stmt = $conn->prepare('INSERT INTO tbl_hr_faculty_reviews
            (record_key, review_status, review_note, data_version, updated_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssi', $owner, $status, $note, $dataVersion, $reviewerId);
    } else {
        $stmt = $conn->prepare('UPDATE tbl_hr_faculty_reviews
            SET review_status = ?, review_note = ?, data_version = ?, updated_by = ?,
                updated_at = CURRENT_TIMESTAMP, revision = revision + 1
            WHERE record_key = ? AND revision = ?');
        $stmt->bind_param('sssisi', $status, $note, $dataVersion, $reviewerId, $owner, $revision);
    }
    try {
        $stmt->execute();
        return $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception $error) {
        if ($revision === 0 && $error->getCode() === 1062) return false;
        throw $error;
    } finally {
        $stmt->close();
    }
}
