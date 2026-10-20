<?php
// Validates the complete manual SQL migration in a disposable local database.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
$migration=file_get_contents(__DIR__.'/../backend/sql/phase26_college_evaluation_access.sql');
$testDatabase='synk_eval_migration_qa_'.bin2hex(random_bytes(8));
$created=false; $qa=null; $checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$run=static function(mysqli $db,string $sql):void {
    $db->multi_query($sql);
    do {
        $result=$db->store_result();
        if ($result instanceof mysqli_result) $result->free();
        if (!$db->more_results()) break;
        $db->next_result();
    } while (true);
};
$snapshot=static function(mysqli $db):array {
    $rows=$db->query('SELECT * FROM tbl_student_faculty_evaluations ORDER BY evaluation_id')->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) { unset($row['college_id'],$row['instrument_version']); }
    unset($row);
    return $rows;
};
try {
    $conn->query('CREATE DATABASE `'.$testDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    $created=true;
    $qa=new mysqli($servername,$username,$password,$testDatabase);
    $qa->set_charset('utf8mb4');
    $qa->query('CREATE TABLE tbl_college (college_id INT UNSIGNED PRIMARY KEY,campus_id INT UNSIGNED NOT NULL) ENGINE=InnoDB');
    $qa->query('CREATE TABLE tbl_student_management_enrolled_subjects (
        student_id INT UNSIGNED,faculty_id INT UNSIGNED,ay_id INT UNSIGNED,semester TINYINT UNSIGNED,
        college_id INT UNSIGNED,campus_id INT UNSIGNED,is_active TINYINT UNSIGNED DEFAULT 1
    ) ENGINE=InnoDB');
    $qa->query("CREATE TABLE tbl_student_faculty_evaluations (
        evaluation_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        student_id INT UNSIGNED,faculty_id INT UNSIGNED,ay_id INT UNSIGNED,semester TINYINT UNSIGNED,
        comment_text TEXT,question_count SMALLINT UNSIGNED,total_score SMALLINT UNSIGNED,
        submission_status VARCHAR(16),updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_student_faculty_term (student_id,faculty_id,ay_id,semester)
    ) ENGINE=InnoDB");
    $qa->query('CREATE TABLE tbl_student_faculty_evaluation_answers (answer_id INT PRIMARY KEY,evaluation_id INT,question_key VARCHAR(32),rating TINYINT) ENGINE=InnoDB');
    $qa->query('INSERT INTO tbl_college VALUES (1,10),(2,20)');
    $qa->query('INSERT INTO tbl_student_management_enrolled_subjects VALUES
        (11,31,1,1,1,10,1),(11,31,1,1,1,10,0),
        (12,31,1,1,1,10,1),(12,31,1,1,2,20,1),
        (14,31,1,1,2,20,0)');
    $qa->query("INSERT INTO tbl_student_faculty_evaluations
        (student_id,faculty_id,ay_id,semester,comment_text,question_count,total_score,submission_status,updated_at) VALUES
        (11,31,1,1,'Historical submitted response',20,80,'submitted','2025-01-02 03:04:05'),
        (12,31,1,1,'Ambiguous college draft',20,60,'draft','2025-01-03 03:04:05'),
        (13,31,1,1,'No enrollment response',20,100,'submitted','2025-01-04 03:04:05'),
        (14,31,1,1,'Inactive original membership',20,40,'submitted','2025-01-05 03:04:05')");
    $qa->query("INSERT INTO tbl_student_faculty_evaluation_answers VALUES (1,1,'ORIGINAL1',4),(2,2,'ORIGINAL1',3)");
    $before=$snapshot($qa);
    $answers=$qa->query('SELECT * FROM tbl_student_faculty_evaluation_answers ORDER BY answer_id')->fetch_all(MYSQLI_ASSOC);
    $run($qa,$migration);
    $check($before===$snapshot($qa),'The SQL must preserve comments, scores, draft/submission status and timestamps.');
    $check($answers===$qa->query('SELECT * FROM tbl_student_faculty_evaluation_answers ORDER BY answer_id')->fetch_all(MYSQLI_ASSOC),'Original answers remain unchanged.');
    $owners=array_map('intval',array_column($qa->query('SELECT college_id FROM tbl_student_faculty_evaluations ORDER BY evaluation_id')->fetch_all(MYSQLI_ASSOC),'college_id'));
    $check($owners===[1,0,0,2],'Only unambiguous original college membership is assigned, including inactive imports.');
    $check((int)$qa->query('SELECT COUNT(*) AS n FROM tbl_college_evaluation_settings')->fetch_assoc()['n']===0,'The migration must not create or enable access settings.');
    $check((int)$qa->query('SELECT COUNT(*) AS n FROM tbl_college_evaluation_audit')->fetch_assoc()['n']===0,'Schema preparation must not fabricate control changes.');
    $check((int)$qa->query("SELECT COUNT(*) AS n FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tbl_student_faculty_evaluations' AND index_name='uniq_student_faculty_term'")->fetch_assoc()['n']===0,'The previous college-independent unique key is removed.');
    $key=$qa->query("SHOW INDEX FROM tbl_student_faculty_evaluations WHERE Key_name='uniq_student_faculty_college_term'")->fetch_all(MYSQLI_ASSOC);
    $check(array_column($key,'Column_name')===['student_id','faculty_id','college_id','ay_id','semester'],'The replacement key matches the application.');
    $qa->query("INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,comment_text) VALUES (11,31,2,1,1,'Independent second college')");
    $check((int)$qa->query('SELECT COUNT(*) AS n FROM tbl_student_faculty_evaluations WHERE student_id=11')->fetch_assoc()['n']===2,'Separate colleges can evaluate the same professor for the same student and term.');
    $qa->query('INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,student_open,faculty_released,version,updated_by) VALUES (1,1,1,1,1,7,42)');
    $qa->query("INSERT INTO tbl_college_evaluation_audit (college_id,ay_id,semester,user_id,control_name,previous_value,new_value) VALUES (1,1,1,42,'student_open',0,1)");
    $qa->query("UPDATE tbl_student_faculty_evaluations SET instrument_version='sksu-set-2026-rev01' WHERE evaluation_id=5");
    $qa->query("INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,comment_text) VALUES (11,31,0,1,1,'Legacy ownership requiring collision review')");
    $existing=[];
    foreach (['tbl_student_faculty_evaluations','tbl_college_evaluation_settings','tbl_college_evaluation_audit'] as $table) $existing[$table]=$qa->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    $run($qa,$migration);
    foreach ($existing as $table=>$rows) $check($rows===$qa->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC),'Running the SQL twice preserves existing data: '.$table);
    $check((int)$qa->query('SELECT college_id FROM tbl_student_faculty_evaluations WHERE evaluation_id=6')->fetch_assoc()['college_id']===0,'A legacy response conflicting with an assigned response is preserved for ownership review.');
    echo 'Evaluation access SQL migration: ',$checks,' checks passed in an isolated database.',PHP_EOL;
} finally {
    if ($qa instanceof mysqli) $qa->close();
    if ($created && preg_match('/^synk_eval_migration_qa_[a-f0-9]{16}$/D',$testDatabase) && $testDatabase!==$dbase) {
        $conn->query('DROP DATABASE `'.$testDatabase.'`');
    }
}
