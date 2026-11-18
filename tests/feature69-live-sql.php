<?php
// Validate the live SQL only in a uniquely named disposable local database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
$sql=file_get_contents(__DIR__.'/../backend/sql/feature69_live_upgrade.sql');
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$sources=[];
foreach (['hr_faculty_pds.sql','hr_faculty_pds_remaining.sql'] as $file) $sources[]=str_replace("\r\n","\n",trim(file_get_contents(__DIR__.'/../backend/sql/'.$file)));
$check(strpos($sql,implode("\n\n",$sources))!==false,'The live bundle must contain the exact current source definitions in order.');
$ddl=preg_replace('/^--[^\n]*(?:\n|$)/m','',$sql);
preg_match_all('/CREATE TABLE IF NOT EXISTS (tbl_hr_[a-z0-9_]+)/',$ddl,$matches);
$tables=$matches[1];
$check(count($tables)===24&&count(array_unique($tables))===24,'The branch must create exactly 24 distinct HR tables.');
$check(!preg_match('/^\s*(?:ALTER|DROP|TRUNCATE|INSERT|UPDATE|DELETE|REPLACE|USE)\b|\b(?:CREATE DATABASE|FOREIGN_KEY_CHECKS)\b/im',$ddl),'The deployment SQL must contain no destructive changes, data writes, schema switching or disabled constraints.');
$execute=static function(mysqli $db,string $script):void{
    $db->multi_query($script);
    do {
        $result=$db->store_result();if($result instanceof mysqli_result)$result->free();
        if(!$db->more_results())break;
    } while($db->next_result());
};
$database='synk_feature69_sql_qa_'.bin2hex(random_bytes(8));
if(!preg_match('/^synk_feature69_sql_qa_[a-f0-9]{16}$/D',$database))throw new RuntimeException('Unexpected scratch database name.');
$db=new mysqli($servername,$username,$password);$db->set_charset('utf8mb4');$created=false;
try {
    $db->query('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;$db->select_db($database);
    $db->query('CREATE TABLE tbl_faculty (faculty_id INT UNSIGNED NOT NULL PRIMARY KEY, marker VARCHAR(30) NOT NULL) ENGINE=InnoDB');
    $db->query('CREATE TABLE tbl_useraccount (user_id INT NOT NULL PRIMARY KEY, marker VARCHAR(30) NOT NULL) ENGINE=InnoDB');
    $db->query("INSERT INTO tbl_faculty VALUES (1,'preserve faculty')");
    $db->query("INSERT INTO tbl_useraccount VALUES (1,'preserve account')");
    $before=$db->query('CHECKSUM TABLE tbl_faculty,tbl_useraccount')->fetch_all(MYSQLI_ASSOC);
    $execute($db,$sql);
    $all=$db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
    $check(count($all)===26,'Fresh deployment must add only 24 HR tables to the two prerequisite tables.');
    foreach($tables as $table){
        $check((int)$db->query('SELECT COUNT(*) AS total FROM `'.$table.'`')->fetch_assoc()['total']===0,$table.' must start empty without imported records.');
    }
    $constraints=$db->query("SELECT COUNT(*) AS total FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()")->fetch_assoc();
    $check((int)$constraints['total']===25,'All HR section/record and parent ownership foreign keys must be installed.');
    $db->query('INSERT INTO tbl_hr_faculty_profiles (faculty_id,account_user_id) VALUES (1,1)');$profile=(int)$db->insert_id;
    $db->query("INSERT INTO tbl_hr_faculty_pds_personal (hr_profile_id,surname,updated_by) VALUES (".$profile.",'Existing HR draft',1)");
    $snapshot=[];foreach(array_merge(['tbl_faculty','tbl_useraccount'],$tables) as $table)$snapshot[$table]=[$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch_assoc()['Create Table'],$db->query('SELECT * FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC)];
    $execute($db,$sql);
    $after=[];foreach(array_keys($snapshot) as $table)$after[$table]=[$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch_assoc()['Create Table'],$db->query('SELECT * FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC)];
    $check($snapshot===$after,'Rerunning the script must preserve every existing schema and record.');
    $check($db->query('CHECKSUM TABLE tbl_faculty,tbl_useraccount')->fetch_all(MYSQLI_ASSOC)===$before,'Main account and faculty data must remain unchanged.');
    echo 'Feature 69 live SQL: ',$checks,' checks passed on ',$db->server_info,'. 24 tables created; rerun preserves schemas and data.',PHP_EOL;
} finally {
    if($created)$db->query('DROP DATABASE `'.$database.'`');
    $db->close();
}
