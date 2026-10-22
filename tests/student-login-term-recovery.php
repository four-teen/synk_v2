<?php
// Exercise the actual data migration; all settings/history fixtures roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_login_activity_helper.php';
$sql = file_get_contents(__DIR__.'/../backend/sql/phase33_student_login_activity_term_recovery.sql');
$checks = 0;
$check = static function(bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$snapshot = static function() use ($conn): array {
    return $conn->query('CHECKSUM TABLE tbl_academic_settings,tbl_student_login_activity,tbl_student_management')->fetch_all(MYSQLI_ASSOC);
};
$before = $snapshot();
$originalZone = $conn->query('SELECT @@session.time_zone')->fetch_row()[0];
$conn->begin_transaction();
try {
    $settings = $conn->query('SELECT setting_id FROM tbl_academic_settings ORDER BY setting_id LIMIT 1 FOR UPDATE')->fetch_assoc();
    if (!$settings) throw new RuntimeException('Academic settings are required.');
    $term = synk_fetch_current_academic_term($conn);
    $otherSemester = $term['semester'] === 1 ? 2 : 1;
    $tag = 'TermQA'.bin2hex(random_bytes(5));
    $conn->query("INSERT INTO tbl_student_management (student_number,first_name,last_name) VALUES ('{$tag}','Login','Recovery')");
    $studentId = (int)$conn->insert_id;
    foreach (['+08:00','-05:00'] as $zone) {
        $conn->query("SET time_zone='{$zone}'");
        $conn->query('UPDATE tbl_academic_settings SET status=\'active\',date_updated=DATE_SUB(NOW(),INTERVAL 2 DAY) WHERE setting_id='.(int)$settings['setting_id']);
        $events = [];
        foreach (['inside'=>[-1,'success',null,null], 'boundary'=>[-2,'success',null,null],
            'failure'=>[-1,'failure',null,null], 'before'=>[-3,'success',null,null],
            'future'=>[1,'success',null,null], 'tagged'=>[-1,'success',$term['ay_id'],$otherSemester],
            'partial'=>[-1,'success',null,$term['semester']]] as $label=>$record) {
            [$days,$outcome,$ay,$semester] = $record;
            $stmt = $conn->prepare('INSERT INTO tbl_student_login_activity (student_id,outcome,reason_code,ay_id,semester,occurred_at) VALUES (?, ?, \'login_success\', ?, ?, TIMESTAMPADD(DAY,?,UTC_TIMESTAMP()))');
            $stmt->bind_param('isiii',$studentId,$outcome,$ay,$semester,$days); $stmt->execute();
            $events[$label] = (int)$stmt->insert_id; $stmt->close();
        }
        $historySql = 'SELECT activity_id,student_id,outcome,reason_code,occurred_at FROM tbl_student_login_activity WHERE student_id='.$studentId.' ORDER BY activity_id';
        $originalEvents = $conn->query($historySql)->fetch_all(MYSQLI_ASSOC);
        $conn->query($sql);
        $check($conn->query($historySql)->fetch_all(MYSQLI_ASSOC)===$originalEvents, 'Recovery preserves event identity, timestamp and authentication outcome: '.$zone);
        $rows = $conn->query('SELECT activity_id,ay_id,semester,outcome,occurred_at FROM tbl_student_login_activity WHERE activity_id IN ('.implode(',',$events).')')->fetch_all(MYSQLI_ASSOC);
        $byId = array_column($rows,null,'activity_id');
        foreach (['inside','boundary','failure'] as $label) {
            $row = $byId[$events[$label]];
            $check((int)$row['ay_id']===$term['ay_id'] && (int)$row['semester']===$term['semester'], 'Recover the known term including its UTC boundary: '.$zone.' '.$label);
        }
        foreach (['before','future'] as $label) $check($byId[$events[$label]]['ay_id']===null && $byId[$events[$label]]['semester']===null,'Keep uncertain events unclassified: '.$zone.' '.$label);
        $check((int)$byId[$events['tagged']]['semester']===$otherSemester, 'Never overwrite an existing term: '.$zone);
        $check($byId[$events['partial']]['ay_id']===null && (int)$byId[$events['partial']]['semester']===$term['semester'], 'Preserve partially classified events: '.$zone);
        $check($byId[$events['failure']]['outcome']==='failure', 'Recovery does not turn failures into successful logins: '.$zone);
        $conn->query($sql);
        $check($conn->affected_rows===0, 'Repeating the migration does not duplicate or reassign events: '.$zone);
        $counts = synk_student_login_activity_counts($conn,[$studentId],$term)[$studentId];
        $check($counts['login_success_count']===2 && $counts['login_failure_count']===1, 'The dashboard count includes recovered successes and excludes failures and other terms: '.$zone);
        $allCounts = synk_student_login_activity_counts($conn,[$studentId])[$studentId];
        $check($allCounts['login_success_count']===6, 'All-time history keeps every original success: '.$zone);
        $conn->query('DELETE FROM tbl_student_login_activity WHERE student_id='.$studentId);
        $conn->query('UPDATE tbl_academic_settings SET status=\'inactive\' WHERE setting_id='.(int)$settings['setting_id']);
        $conn->query("INSERT INTO tbl_student_login_activity (student_id,outcome,reason_code,occurred_at) VALUES ({$studentId},'success','login_success',UTC_TIMESTAMP())");
        $conn->query($sql);
        $check($conn->affected_rows===0, 'An inactive term cannot be used to classify legacy events: '.$zone);
        $conn->query('DELETE FROM tbl_student_login_activity WHERE student_id='.$studentId);
    }
    $conn->rollback();
    $check($snapshot()===$before, 'All data and settings are restored after verification.');
    echo 'Student login term recovery: ', $checks, ' checks passed. Fixtures rolled back.', PHP_EOL;
} finally {
    $conn->rollback();
    $conn->query("SET time_zone='".$conn->real_escape_string($originalZone)."'");
}
