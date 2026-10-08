<?php
// php tests/student-identity.php — database constraints and shared importer compatibility.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/college_dean_workload_helper.php';
class StudentIdentityQAConnection extends mysqli
{
    #[\ReturnTypeWillChange]
    public function query($query, $result_mode = MYSQLI_STORE_RESULT)
    {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$query)) throw new RuntimeException('Schema changes are forbidden in identity tests.');
        return parent::query($query,$result_mode);
    }
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags=null,$name=null): bool { return $this->query('SAVEPOINT student_identity_test'); }
    public function commit($flags=null,$name=null): bool { return $this->query('RELEASE SAVEPOINT student_identity_test'); }
    public function rollback($flags=null,$name=null): bool { return $this->query('ROLLBACK TO SAVEPOINT student_identity_test'); }
}
$conn->close(); $conn=new StudentIdentityQAConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4');
$checks=0;
$check=static function (bool $passed,string $message) use (&$checks): void { if (!$passed) throw new RuntimeException($message); $checks++; };
$duplicate=static function (callable $operation,string $message) use ($check): void {
    try { $operation(); } catch (mysqli_sql_exception $e) { $check((int)$e->getCode()===1062,$message); return; }
    $check(false,$message);
};
$snapshot=static function () use ($conn): array {
    $result=[];
    foreach (['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_offering_enrollee_counts'] as $table) $result[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    return $result;
};
$before=$snapshot(); $conn->startFixtures(); $workbook=null;
try {
    $check(synk_student_identity_schema_ready($conn),'Student identity constraints are not installed.');
    $check(synk_student_identity_number('000123')==='000123','Student IDs lost leading zeros.');
    $check(synk_student_identity_number('12345678901234567890123456789012')==='12345678901234567890123456789012','A valid 32-digit ID was converted to a number.');
    foreach (['','0','000','123x','123 456',str_repeat('1',33)] as $invalid) {
        try { synk_student_identity_number($invalid); $check(false,'Invalid ID accepted.'); }
        catch (DomainException $e) { $check(true,'Invalid ID rejected.'); }
    }
    $check(synk_student_identity_name_key('Peña Ñúñez')===synk_student_identity_name_key('PEÑA ÑÚÑEZ'),'Unicode names did not match across case differences.');
    $check(synk_student_identity_name_key("Pen\u{0303}a")===synk_student_identity_name_key('Peña'),'Combining and composed ñ did not match.');
    $check(synk_student_identity_name_key('Pena')!==synk_student_identity_name_key('Peña'),'Name matching removed the tilde from ñ.');
    $check(synk_student_identity_utf8('李, 明')==='李, 明','Non-Latin Unicode names were damaged.');
    $term=synk_fetch_current_academic_term($conn);
    $source=synk_professor_workload_assignment_source_sql();
    $target=$conn->query("SELECT fw.faculty_id,p.college_id {$source} JOIN tbl_program p ON p.program_id=po.program_id
        WHERE fw.ay_id=" . (int)$term['ay_id'] . ' AND fw.semester=' . (int)$term['semester'] . ' ORDER BY po.offering_id LIMIT 1')->fetch_assoc();
    $scope=$conn->query('SELECT c.college_id,c.college_code,c.college_name,cp.campus_id,cp.campus_name FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=' . (int)$target['college_id'])->fetch_assoc();
    $scope['college_id']=(int)$scope['college_id']; $scope['campus_id']=(int)$scope['campus_id'];
    $class=synk_dean_workload_classes($conn,$scope,$term,(int)$target['faculty_id'])[0];
    $number='000' . (string)(3700000000+random_int(1,10000000));
    $student=['student_number'=>$number,'last_name'=>'Peña','first_name'=>'José','middle_name'=>'Niño','suffix_name'=>'',
        'student_name_raw'=>'Peña, José Niño','source_row_number'=>8,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],
        'source_sheet_name'=>'IDENTITY QA','source_file_name'=>'identity-qa.csv','year_level'=>$class['year_level'],
        'email_address'=>'jose.identity-qa@sksu.edu.ph','program_id'=>$class['program_id'],'uploaded_by'=>0];
    $identity=synk_student_identity_create_or_reuse($conn,$student);
    $profile=synk_student_identity_find($conn,$number);
    $check($identity['created'] && $profile['student_number']===$number && $profile['last_name']==='Peña','The unique student profile or UTF-8 storage was incorrect.');
    $duplicate(static function () use ($conn,$student) { synk_student_identity_insert_record($conn,$student); },'The database accepted a duplicate student ID.');
    $case=$student; $case['last_name']='PEÑA'; $case['first_name']='JOSÉ'; $case['middle_name']='NIÑO';
    $reused=synk_student_identity_create_or_reuse($conn,$case);
    $check(!$reused['created'] && $reused['student_id']===$identity['student_id'] && synk_student_identity_find($conn,$number)===$profile,'Reusing an ID changed its canonical profile.');
    $otherNumber=$student; $otherNumber['student_number']=substr($number,3);
    $different=synk_student_identity_create_or_reuse($conn,$otherNumber);
    $check($different['created'] && $different['student_id']!==$identity['student_id'],'Two distinct IDs with identical names were merged.');
    $parsed=['subject_section_raw'=>$class['subject_code'] . ' - ' . $class['section_display'],'descriptive_title'=>$class['descriptive_title'],
        'faculty_name'=>$class['first_name'] . ' ' . $class['last_name'],'campus_header'=>$class['campus_name'],'students'=>[$student],'duplicate_rows'=>0];
    $tag='IdentityQA' . bin2hex(random_bytes(8));
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id) VALUES ('{$tag}','{$tag}@sksu.edu.ph','unusable-qa-password','college_dean','active'," . $scope['college_id'] . ')');
    $userId=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$userId,['college_dean'],'college_dean');
    $_SESSION=['user_id'=>$userId,'role'=>'college_dean'];
    $pending=['user_id'=>$userId,'college_id'=>$scope['college_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],
        'faculty_id'=>$class['faculty_id'],'offering_id'=>$class['offering_id'],'expires_at'=>time()+1800,'file_name'=>'identity-qa.csv','parsed'=>$parsed];
    $saved=synk_dean_roster_save($conn,$scope,$term,$pending,$userId);
    $check($saved['added']===1 && $saved['new_profiles']===0,'The roster did not reuse the master profile.');
    $links='tbl_student_management_enrolled_subjects';
    $fields=[];
    foreach ($conn->query('SHOW COLUMNS FROM ' . $links)->fetch_all(MYSQLI_ASSOC) as $field) if ($field['Field']!=='student_enrollment_id' && stripos($field['Extra'],'GENERATED')===false) $fields[]=$field['Field'];
    $fieldList='`' . implode('`,`',$fields) . '`';
    $where=' WHERE student_id=' . $identity['student_id'] . ' AND offering_id=' . $class['offering_id'] . ' AND is_active=1 LIMIT 1';
    $duplicate(static function () use ($conn,$links,$fieldList,$where) { $conn->query('INSERT INTO ' . $links . ' (' . $fieldList . ') SELECT ' . $fieldList . ' FROM ' . $links . $where); },'The database accepted a duplicate active class membership.');
    $legacySelect=implode(',',array_map(static function ($field) { return $field==='offering_id' ? '0' : '`' . $field . '`'; },$fields));
    $duplicate(static function () use ($conn,$links,$fieldList,$legacySelect,$where) { $conn->query('INSERT INTO ' . $links . ' (' . $fieldList . ') SELECT ' . $legacySelect . ' FROM ' . $links . $where); },'The database accepted a duplicate class through an unresolved legacy offering.');
    $archivedSelect=implode(',',array_map(static function ($field) { return $field==='is_active' ? '0' : '`' . $field . '`'; },$fields));
    $conn->query('INSERT INTO ' . $links . ' (' . $fieldList . ') SELECT ' . $archivedSelect . ' FROM ' . $links . $where);
    $archivedId=(int)$conn->insert_id;
    $duplicate(static function () use ($conn,$links,$archivedId) { $conn->query('UPDATE ' . $links . ' SET is_active=1 WHERE student_enrollment_id=' . $archivedId); },'Reactivating history created a duplicate active membership.');
    $check(count(synk_dean_roster_rows($conn,$class))>=1,'Archived import history affected the active student list.');
    $directory=synk_student_management_fetch_record_by_student_number($conn,$number);
    $check($directory['student_number']===$number && $directory['last_name']==='Peña','The shared directory lost leading zeros or accented characters.');
    $catalog=synk_student_management_fetch_master_student_catalog($conn);
    $check($catalog['by_student_number'][$number]['student_id']===$identity['student_id'],'The shared CSV importer lost the exact student ID.');
    $check(synk_student_management_find_existing_master_student($catalog,$case)['student_id']===$identity['student_id'],'Shared import name matching did not preserve accented identity.');
    $check(synk_student_identity_find($conn,$number)===$profile,'Shared directory reads changed the student profile.');
    // The shared workbook importer must reuse profiles instead of deleting a whole term's students and links.
    $programSql=synk_student_management_program_source_sql('p');
    $programName=$conn->query('SELECT ' . $programSql . ' AS program_name FROM tbl_program p WHERE p.program_id=' . $class['program_id'])->fetch_assoc()['program_name'];
    $yearLabels=[1=>'First Year',2=>'Second Year',3=>'Third Year',4=>'Fourth Year',5=>'Fifth Year',6=>'Sixth Year'];
    $sheetRows=[[$scope['college_name']],[$scope['campus_name']],[$term['semester_label']],[$term['ay_label']],
        [$programName],[$yearLabels[$class['year_level']]],[$number,'Peña','José','Niño'],[$number . '7','Muñoz','María','Güell']];
    $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($sheetRows as $index=>$cells) {
        $xml.='<row r="' . ($index+1) . '">';
        foreach ($cells as $column=>$value) $xml.='<c r="' . chr(65+$column) . ($index+1) . '" t="inlineStr"><is><t>' . htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8') . '</t></is></c>';
        $xml.='</row>';
    }
    $xml.='</sheetData></worksheet>';
    $workbook=tempnam(__DIR__ . '/../tmp','student-identity-');
    $zip=new ZipArchive(); $zip->open($workbook,ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="LIST" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml',$xml); $zip->close();
    $enrollmentBefore=$conn->query('SELECT * FROM ' . $links . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    $imported=synk_student_management_import_students($conn,$workbook,'identity-qa.xlsx',$userId);
    $check($imported['inserted_rows']===1 && $imported['matched_rows']===1 && $imported['deleted_rows']===0,'The workbook importer did not reuse the existing unique student.');
    $check(synk_student_identity_find($conn,$number)===$profile && $conn->query('SELECT * FROM ' . $links . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)===$enrollmentBefore,'A workbook import overwrote profiles or removed existing subject links.');
    $workbookStudent=synk_student_identity_find($conn,$number . '7');
    $check($workbookStudent['student_number']===$number . '7' && $workbookStudent['last_name']==='Muñoz' && $workbookStudent['first_name']==='María' && $workbookStudent['middle_name']==='Güell','Workbook IDs or accented names were corrupted.');
    $repeated=synk_student_management_import_students($conn,$workbook,'identity-qa.xlsx',$userId);
    $check($repeated['inserted_rows']===0 && $repeated['matched_rows']===2,'Re-importing a workbook created duplicate student profiles.');
    unlink($workbook); $workbook=null;
    $conn->discardFixtures();
    $check($snapshot()===$before,'Student identity tests changed real records.');
    echo 'Student identity: ', $checks, ' checks passed. All fixture records rolled back.', PHP_EOL;
} catch (Throwable $e) { $conn->discardFixtures(); throw $e; }
finally { if ($workbook && is_file($workbook)) unlink($workbook); }
