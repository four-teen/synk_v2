<?php
require_once __DIR__.'/schema_helper.php';

function synk_student_presence_ready(mysqli $conn): bool
{
    return synk_table_exists($conn, 'tbl_student_portal_sessions');
}

function synk_student_presence_start(mysqli $conn, ?int $initialActivity = null): void
{
    if (!synk_student_presence_ready($conn)) throw new RuntimeException('Prepare student session tracking before enabling the portal.');
    $studentId = (int)($_SESSION['student_id'] ?? 0);
    if (($_SESSION['role'] ?? '') !== 'student' || $studentId <= 0) throw new DomainException('Student sign-in is required.', 401);
    $key = bin2hex(random_bytes(32));
    $initial = gmdate('Y-m-d H:i:s', min(time(), $initialActivity ?? time()));
    $stmt = $conn->prepare('INSERT INTO tbl_student_portal_sessions (presence_key,student_id,signed_in_at,last_activity_at,last_seen_at) VALUES (?,?,UTC_TIMESTAMP(),?,UTC_TIMESTAMP())');
    $stmt->bind_param('sis', $key, $studentId, $initial);
    try { $stmt->execute(); } finally { $stmt->close(); }
    $_SESSION['student_presence_key'] = $key;
    $_SESSION['student_presence_csrf'] = bin2hex(random_bytes(32));
}

function synk_student_presence_end(mysqli $conn, string $reason = 'logout', ?array $identity = null): void
{
    $identity = $identity ?? $_SESSION;
    $key = $identity['student_presence_key'] ?? '';
    $studentId = (int)($identity['student_id'] ?? 0);
    if (!is_string($key) || $key === '' || $studentId <= 0 || !synk_student_presence_ready($conn)) return;
    if (!in_array($reason, ['logout','idle','replaced','identity_changed'], true)) throw new InvalidArgumentException('Invalid session end reason.');
    $stmt = $conn->prepare("UPDATE tbl_student_portal_sessions
        SET ended_at=CASE WHEN ?='idle' THEN DATE_ADD(last_activity_at,INTERVAL 15 MINUTE) ELSE UTC_TIMESTAMP() END,end_reason=?
        WHERE presence_key=? AND student_id=? AND ended_at IS NULL");
    $stmt->bind_param('sssi', $reason, $reason, $key, $studentId);
    try { $stmt->execute(); } finally { $stmt->close(); }
}

// Background status requests never extend last_activity_at. Expiry is checked
// before accepting activity, so an expired session cannot be revived by a ping.
function synk_student_presence_check(mysqli $conn, bool $activity = false): array
{
    if (empty($_SESSION['student_presence_key'])) {
        $initial = strtotime((string)($_SESSION['student_login_at'] ?? '')) ?: time();
        synk_student_presence_start($conn, $initial);
    }
    $key = (string)$_SESSION['student_presence_key'];
    $studentId = (int)($_SESSION['student_id'] ?? 0);
    $stmt = $conn->prepare("SELECT ended_at,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(),DATE_ADD(last_activity_at,INTERVAL 15 MINUTE)) AS seconds_left
        FROM tbl_student_portal_sessions WHERE presence_key=? AND student_id=?");
    $read = static function () use ($stmt, $key, $studentId): ?array {
        $stmt->bind_param('si', $key, $studentId); $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    };
    try {
        $row = $read();
        if (!$row || $row['ended_at'] !== null || (int)$row['seconds_left'] <= 0) {
            if ($row && $row['ended_at'] === null) synk_student_presence_end($conn, 'idle');
            throw new DomainException('student_idle_timeout', 401);
        }
        $sql = "UPDATE tbl_student_portal_sessions SET last_seen_at=UTC_TIMESTAMP()";
        if ($activity) $sql .= ',last_activity_at=UTC_TIMESTAMP()';
        $sql .= ' WHERE presence_key=? AND student_id=? AND ended_at IS NULL AND last_activity_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 15 MINUTE)';
        $update = $conn->prepare($sql);
        $update->bind_param('si', $key, $studentId);
        try { $update->execute(); } finally { $update->close(); }
        $row = $read();
        if (!$row || $row['ended_at'] !== null || (int)$row['seconds_left'] <= 0) throw new DomainException('student_idle_timeout', 401);
        return ['seconds_remaining'=>(int)$row['seconds_left'], 'idle_seconds'=>900];
    } finally { $stmt->close(); }
}
