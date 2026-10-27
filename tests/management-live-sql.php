<?php
// Executes the manual migration only against uniquely named disposable databases.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
$migration=file_get_contents(__DIR__.'/../backend/sql/management_live_install.sql');
$catalog=file_get_contents(__DIR__.'/../backend/sql/create_management_catalog.sql');
$checks=0;
$check=static function(bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$execute=static function(mysqli $db,string $sql): void {
    $db->multi_query($sql);
    do {
        $result=$db->store_result(); if ($result instanceof mysqli_result) $result->free();
        if (!$db->more_results()) break;
    } while ($db->next_result());
};
$tables=['tbl_management_organizations','tbl_management_events','tbl_management_event_sessions','tbl_management_contributions','tbl_management_violations','tbl_management_period_settings','tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_student_qr_tokens','tbl_management_attendance_audit'];
foreach (['fresh','legacy','varchar'] as $scenario) {
    $database='synk_management_sql_qa_'.bin2hex(random_bytes(8));
    if (!preg_match('/^synk_management_sql_qa_[a-f0-9]{16}$/D',$database)) throw new RuntimeException('Invalid scratch database name.');
    $db=new mysqli($servername,$username,$password); $db->set_charset('utf8mb4');
    $created=false;
    try {
        $db->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created=true;
        $db->select_db($database);
        $db->query('CREATE TABLE tbl_college (college_id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $db->query('CREATE TABLE tbl_academic_years (ay_id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $db->query('CREATE TABLE tbl_student_management (student_id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        $db->query('CREATE TABLE tbl_academic_settings (setting_id INT UNSIGNED PRIMARY KEY,current_ay_id INT UNSIGNED,current_semester TINYINT UNSIGNED) ENGINE=InnoDB');
        $db->query('INSERT INTO tbl_academic_settings VALUES (1,2,1)');
        $academicSchema=$db->query('SHOW CREATE TABLE tbl_academic_settings')->fetch_assoc()['Create Table'];
        $role=$scenario==='varchar' ? "VARCHAR(8) NOT NULL DEFAULT 'admin'" : "ENUM('admin','scheduler','QA_CUSTOM') NULL DEFAULT 'QA_CUSTOM'";
        $db->query("CREATE TABLE tbl_useraccount (user_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,username VARCHAR(150),password VARCHAR(255),college_id INT UNSIGNED NULL,role $role COMMENT 'KEEP ROLE METADATA') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->query('INSERT INTO tbl_college VALUES (1),(2)'); $db->query('INSERT INTO tbl_academic_years VALUES (1),(2)');
        $db->query("INSERT INTO tbl_useraccount (username,password,college_id,role) VALUES ('EXISTING USER','KEEP PASSWORD',1,'admin')");
        if ($scenario==='legacy') {
            $execute($db,$catalog);
            $db->query("ALTER TABLE tbl_management_events DROP INDEX ix_management_event_period, DROP COLUMN exam_term, MODIFY status ENUM('DRAFT','OPEN','CLOSED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'");
            $db->query("ALTER TABLE tbl_management_event_sessions MODIFY status ENUM('DRAFT','OPEN','CLOSED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'");
            foreach(['DRAFT'=>'INACTIVE','OPEN'=>'ACTIVE','CLOSED'=>'INACTIVE','ARCHIVED'=>'INACTIVE']as$oldStatus=>$newStatus){
                $db->query("INSERT INTO tbl_management_events (college_id,title,event_date,status,source_metadata,updated_at) VALUES (1,'EVENT $oldStatus','2026-10-26','$oldStatus','{\"original_status\":\"$oldStatus\"}','2026-01-01 00:00:00')");
                $oldEvent=(int)$db->insert_id;
                $db->query("INSERT INTO tbl_management_event_sessions (college_id,event_id,title,starts_at,ends_at,status,updated_at) VALUES (1,$oldEvent,'SESSION $oldStatus','2026-10-26 08:00:00','2026-10-26 12:00:00','$oldStatus','2026-01-01 00:00:00')");
            }
            $db->query("ALTER TABLE tbl_management_contributions MODIFY status ENUM('DRAFT','OPEN','CLOSED','ARCHIVED') NOT NULL DEFAULT 'DRAFT'");
            $db->query('ALTER TABLE tbl_management_contributions DROP INDEX ix_management_contribution_period, DROP COLUMN exam_term');
            $db->query("INSERT INTO tbl_management_contributions (college_id,ay_id,semester,title,contribution_date,amount) VALUES (1,1,1,'UNKNOWN TERM CONTRIBUTION','2026-10-26',5.25)");
            foreach(['OPEN','CLOSED','ARCHIVED']as$status)$db->query("INSERT INTO tbl_management_contributions (college_id,ay_id,semester,title,contribution_date,amount,status,source_metadata,updated_at) VALUES (1,1,1,'STATUS $status','2026-10-26',5.25,'$status','{\"original_status\":\"$status\"}','2026-01-01 00:00:00')");
            $db->query("INSERT INTO tbl_management_organizations (college_id,organization_code,organization_name) VALUES (1,'CSSO','CSSO')");
            $db->query("CREATE TABLE tbl_management_violations (violation_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,college_id INT UNSIGNED NOT NULL,violation_type VARCHAR(150) NOT NULL,amount DECIMAL(12,2) NOT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_management_violation_type(college_id,violation_type),UNIQUE KEY uq_management_violation_scope(college_id,violation_id),CONSTRAINT fk_management_violation_college FOREIGN KEY(college_id) REFERENCES tbl_college(college_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->query("INSERT INTO tbl_management_violations (college_id,violation_type,amount) VALUES (1,'EXISTING TYPE',15.25)");
            $db->query('ALTER TABLE tbl_useraccount ADD management_college_id INT UNSIGNED NULL');
            $db->query('UPDATE tbl_useraccount SET management_college_id=1');
        }
        $execute($db,$migration);
        $settings=$db->query('SELECT * FROM tbl_academic_settings')->fetch_assoc();
        $check($settings===['setting_id'=>'1','current_ay_id'=>'2','current_semester'=>'1'] && $db->query('SHOW CREATE TABLE tbl_academic_settings')->fetch_assoc()['Create Table']===$academicSchema,$scenario.': Management migration changed shared Academic Settings schema or data.');
        foreach ($tables as $table) $check($db->query("SHOW TABLES LIKE '$table'")->num_rows===1,$scenario.': missing '.$table);
        $column=$db->query("SHOW COLUMNS FROM tbl_management_contributions LIKE 'exam_term'")->fetch_assoc();
        $check($column && $column['Type']==="enum('MIDTERM','FINAL')" && $column['Null']==='YES',$scenario.': contribution term storage is missing.');
        $check($db->query("SHOW INDEX FROM tbl_management_contributions WHERE Key_name='ix_management_contribution_period'")->num_rows===6,$scenario.': contribution period index is missing.');
        $statusColumn=$db->query("SHOW COLUMNS FROM tbl_management_contributions LIKE 'status'")->fetch_assoc();
        $check($statusColumn['Type']==="enum('ACTIVE','INACTIVE')" && $statusColumn['Default']==='ACTIVE',$scenario.': contribution status storage is incorrect.');
        $eventColumn=$db->query("SHOW COLUMNS FROM tbl_management_events LIKE 'exam_term'")->fetch_assoc();
        $check($eventColumn && $eventColumn['Type']==="enum('MIDTERM','FINAL')" && $eventColumn['Null']==='YES',$scenario.': event term storage is missing.');
        $check($db->query("SHOW INDEX FROM tbl_management_events WHERE Key_name='ix_management_event_period'")->num_rows===6,$scenario.': event period index is missing.');
        foreach(['tbl_management_events','tbl_management_event_sessions']as$eventTable){
            $column=$db->query("SHOW COLUMNS FROM $eventTable LIKE 'status'")->fetch_assoc();
            $check($column['Type']==="enum('ACTIVE','INACTIVE')" && $column['Default']==='ACTIVE',$scenario.': incorrect event/session status storage.');
        }
        $role=$db->query("SHOW FULL COLUMNS FROM tbl_useraccount LIKE 'role'")->fetch_assoc();
        $check($scenario==='varchar' ? $role['Type']==='varchar(50)' : strpos($role['Type'],"'QA_CUSTOM'")!==false && strpos($role['Type'],"'management'")!==false,$scenario.': lost role values or Management support.');
        $check($role['Comment']==='KEEP ROLE METADATA' && $role['Default']===($scenario==='varchar'?'admin':'QA_CUSTOM') && $role['Null']===($scenario==='varchar'?'NO':'YES'),$scenario.': role metadata changed.');
        $account=$db->query('SELECT * FROM tbl_useraccount WHERE user_id=1')->fetch_assoc();
        $check($account['password']==='KEEP PASSWORD' && $account['username']==='EXISTING USER' && $account['role']==='admin' && $account['college_id']==='1',$scenario.': modified existing account data.');
        $check($account['management_organization_id']===null,$scenario.': guessed an organization assignment.');
        $check($db->query("SHOW INDEX FROM tbl_management_violations WHERE Key_name='uq_management_violation_type'")->num_rows===0,$scenario.': old violation uniqueness remains.');
        foreach (['fk_user_management_org','fk_management_violation_org','fk_management_violation_ay','uq_management_violation_org_term'] as $name) {
            $check($db->query("SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$database' AND CONSTRAINT_NAME='$name'")->num_rows===1,$scenario.': missing '.$name);
        }
        if ($scenario==='legacy') {
            $old=$db->query("SELECT * FROM tbl_management_contributions WHERE title='UNKNOWN TERM CONTRIBUTION'")->fetch_assoc();
            foreach(['DRAFT'=>'INACTIVE','OPEN'=>'ACTIVE','CLOSED'=>'INACTIVE','ARCHIVED'=>'INACTIVE']as$oldStatus=>$newStatus){
                $row=$db->query("SELECT * FROM tbl_management_events WHERE title='EVENT $oldStatus'")->fetch_assoc();
                $check($row['exam_term']===null && $row['status']===$newStatus && $row['updated_at']==='2026-01-01 00:00:00' && json_decode($row['source_metadata'],true)===['original_status'=>$oldStatus],'Event migration guessed a period or changed source data/timestamps.');
                $row=$db->query("SELECT * FROM tbl_management_event_sessions WHERE title='SESSION $oldStatus'")->fetch_assoc();
                $check($row['status']===$newStatus && $row['updated_at']==='2026-01-01 00:00:00','Event session status migration changed its timestamp.');
            }
            $check($old['exam_term']===null && $old['contribution_date']==='2026-10-26' && $old['amount']==='5.25','Contribution migration guessed a term or changed original data.');
            $check($old['status']==='INACTIVE','Draft contribution was not converted to Inactive.');
            foreach(['OPEN'=>'ACTIVE','CLOSED'=>'INACTIVE','ARCHIVED'=>'INACTIVE']as$oldStatus=>$newStatus){
                $row=$db->query("SELECT * FROM tbl_management_contributions WHERE title='STATUS $oldStatus'")->fetch_assoc();
                $check($row['status']===$newStatus && $row['updated_at']==='2026-01-01 00:00:00' && json_decode($row['source_metadata'],true)===['original_status'=>$oldStatus],'Status conversion lost its meaning, timestamp, or original source value.');
            }
            $record=$db->query('SELECT * FROM tbl_management_violations WHERE violation_id=1')->fetch_assoc();
            $check($record['violation_type']==='EXISTING TYPE' && $record['amount']==='15.25' && $record['college_id']==='1' && $record['organization_id']===null && $record['ay_id']===null && $record['semester']===null,'Legacy violation was changed or ownership was guessed.');
            $check($account['management_college_id']==='1','Legacy Management college assignment changed.');
        }
        $check($db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE 'tbl_management_financial_%' OR TABLE_NAME='tbl_management_legacy_student_links')")->num_rows===0,$scenario.': installation recreated cancelled legacy Financial tables.');
        // Seed valid records, rerun, then compare both structure and data exactly.
        $db->query("INSERT INTO tbl_management_organizations (college_id,organization_code,organization_name) VALUES (2,'QA ORG','QA ORGANIZATION')");
        $org=(int)$db->insert_id;
        $db->query("INSERT INTO tbl_management_period_settings (college_id,organization_id,ay_id,semester,current_exam_term) VALUES (2,$org,2,1,'FINAL')");
        $db->query("INSERT INTO tbl_management_events (college_id,organization_id,ay_id,semester,title,event_date) VALUES (2,$org,2,1,'EXISTING EVENT','2026-10-26')");
        $event=(int)$db->insert_id;
        $db->query("INSERT INTO tbl_management_contributions (college_id,organization_id,ay_id,semester,event_id,title,contribution_date,amount) VALUES (2,$org,2,1,$event,'EXISTING CONTRIBUTION','2026-10-26',12.35)");
        $db->query("INSERT INTO tbl_management_event_sessions (college_id,event_id,title,starts_at,ends_at) VALUES (2,$event,'EXISTING SESSION','2026-10-26 08:00:00','2026-10-26 12:00:00')");
        $db->query("INSERT INTO tbl_management_violations (college_id,organization_id,ay_id,semester,violation_type,amount) VALUES (2,$org,2,1,'EXISTING SCOPED TYPE',25.50)");
        $db->query("UPDATE tbl_useraccount SET management_college_id=2,management_organization_id=$org,google_sub='EXISTING_GOOGLE_SUBJECT',google_email_verified=1 WHERE user_id=1");
        $snapshot=static function()use($db,$tables):array {
            $state=[];
            foreach (array_merge(['tbl_useraccount','tbl_academic_settings'],$tables) as $table) {
                $state[$table]=[$db->query('SHOW CREATE TABLE '.$table)->fetch_assoc()['Create Table'],$db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)];
            }
            return $state;
        };
        $before=$snapshot(); $execute($db,$migration);
        $execute($db,file_get_contents(__DIR__.'/../backend/sql/management_contribution_periods.sql'));
        $execute($db,file_get_contents(__DIR__.'/../backend/sql/management_contribution_status.sql'));
        $execute($db,file_get_contents(__DIR__.'/../backend/sql/management_event_periods.sql'));
        $check($snapshot()===$before,$scenario.': repeat execution changed existing data or schema.');
    } finally {
        if ($created) {
            if (!preg_match('/^synk_management_sql_qa_[a-f0-9]{16}$/D',$database) || $database===$dbase) throw new RuntimeException('Refusing to remove a non-test database.');
            $db->select_db('information_schema'); $db->query('DROP DATABASE `'.$database.'`');
        }
        $db->close();
    }
}
echo 'Management live SQL: ',$checks," checks passed on ",$conn->server_info,". Scratch databases removed; Synk data untouched.\n";
