<?php
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/auth_useraccount.php';

function synk_evaluation_query(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the evaluation request.');
    try {
        synk_bind_dynamic_params($stmt, $types, $params);
        if (!$stmt->execute()) throw new RuntimeException('Unable to complete the evaluation request.');
        $result = $stmt->get_result();
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } finally { $stmt->close(); }
}

function synk_evaluation_table_exists(mysqli $conn, string $table): bool
{
    return count(synk_evaluation_query($conn, 'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', 's', [$table])) > 0;
}

function synk_evaluation_has_ownership(mysqli $conn): bool
{
    return synk_evaluation_table_exists($conn, 'tbl_student_faculty_evaluations')
        && count(synk_evaluation_query($conn, "SHOW COLUMNS FROM tbl_student_faculty_evaluations LIKE 'college_id'")) > 0;
}

// Run before beginning a write transaction. Completed installations perform no DDL.
function synk_evaluation_ensure_access_schema(mysqli $conn): void
{
    if (!synk_evaluation_table_exists($conn, 'tbl_college_evaluation_settings')) {
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_college_evaluation_settings (
            college_id INT UNSIGNED NOT NULL, ay_id INT UNSIGNED NOT NULL, semester TINYINT UNSIGNED NOT NULL,
            student_open TINYINT UNSIGNED NOT NULL DEFAULT 0, faculty_released TINYINT UNSIGNED NOT NULL DEFAULT 0,
            version INT UNSIGNED NOT NULL DEFAULT 0, updated_by INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NULL DEFAULT NULL, PRIMARY KEY (college_id,ay_id,semester)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
    if (!synk_evaluation_table_exists($conn, 'tbl_college_evaluation_audit')) {
        $conn->query("CREATE TABLE IF NOT EXISTS tbl_college_evaluation_audit (
            audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, college_id INT UNSIGNED NOT NULL,
            ay_id INT UNSIGNED NOT NULL, semester TINYINT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
            control_name VARCHAR(32) NOT NULL, previous_value TINYINT UNSIGNED NOT NULL,
            new_value TINYINT UNSIGNED NOT NULL, changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (audit_id), KEY idx_evaluation_audit_term (college_id,ay_id,semester,audit_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
    if (!synk_evaluation_table_exists($conn, 'tbl_student_faculty_evaluations')) return;
    if (!synk_evaluation_has_ownership($conn)) {
        $conn->query('ALTER TABLE tbl_student_faculty_evaluations ADD COLUMN college_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER faculty_id');
        // Preserve every original response and timestamp. Ambiguous ownership is never guessed.
        $conn->query("UPDATE tbl_student_faculty_evaluations e JOIN (
            SELECT student_id,faculty_id,ay_id,semester,MIN(college_id) AS college_id
            FROM tbl_student_management_enrolled_subjects es
            WHERE college_id>0 AND EXISTS (SELECT 1 FROM tbl_college c WHERE c.college_id=es.college_id AND c.campus_id=es.campus_id)
            GROUP BY student_id,faculty_id,ay_id,semester HAVING COUNT(DISTINCT college_id)=1
        ) owner ON owner.student_id=e.student_id AND owner.faculty_id=e.faculty_id
            AND owner.ay_id=e.ay_id AND owner.semester=e.semester
            SET e.college_id=owner.college_id,e.updated_at=e.updated_at WHERE e.college_id=0");
    }
    if (!synk_table_has_index($conn, 'tbl_student_faculty_evaluations', 'uniq_student_faculty_college_term')) {
        $drop = synk_table_has_index($conn, 'tbl_student_faculty_evaluations', 'uniq_student_faculty_term')
            ? 'DROP INDEX uniq_student_faculty_term, ' : '';
        $conn->query('ALTER TABLE tbl_student_faculty_evaluations ' . $drop
            . 'ADD UNIQUE KEY uniq_student_faculty_college_term (student_id,faculty_id,college_id,ay_id,semester)');
    }
}

function synk_evaluation_settings(mysqli $conn, int $collegeId, int $ayId, int $semester, bool $lock = false): array
{
    $default = ['student_open'=>0,'faculty_released'=>0,'version'=>0,'updated_by'=>0,'updated_at'=>null];
    if (!synk_evaluation_table_exists($conn, 'tbl_college_evaluation_settings')) return $default;
    $rows = synk_evaluation_query($conn, 'SELECT student_open,faculty_released,version,updated_by,updated_at
        FROM tbl_college_evaluation_settings WHERE college_id=? AND ay_id=? AND semester=?'
        . ($lock ? ' LOCK IN SHARE MODE' : ''), 'iii', [$collegeId,$ayId,$semester]);
    return ($rows[0] ?? []) + $default;
}

function synk_evaluation_is_current(array $term, array $current): bool
{
    return (int)($term['ay_id'] ?? 0)>0 && (int)($term['semester'] ?? 0)>0
        && (int)$term['ay_id']===(int)$current['ay_id'] && (int)$term['semester']===(int)$current['semester'];
}

// Legacy rows are visible only when their original enrollment proves one college.
function synk_evaluation_owner_sql(mysqli $conn, string $alias, int $collegeId): string
{
    $legacy = "EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects eo
        WHERE eo.student_id={$alias}.student_id AND eo.faculty_id={$alias}.faculty_id
        AND eo.ay_id={$alias}.ay_id AND eo.semester={$alias}.semester AND eo.college_id={$collegeId}
        AND eo.campus_id=(SELECT campus_id FROM tbl_college WHERE college_id={$collegeId}))
        AND NOT EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects ex
        WHERE ex.student_id={$alias}.student_id AND ex.faculty_id={$alias}.faculty_id
        AND ex.ay_id={$alias}.ay_id AND ex.semester={$alias}.semester AND ex.college_id>0 AND ex.college_id<>{$collegeId})";
    return synk_evaluation_has_ownership($conn)
        ? "({$alias}.college_id={$collegeId} OR ({$alias}.college_id=0 AND {$legacy}))" : "({$legacy})";
}

function synk_evaluation_student_colleges(mysqli $conn, int $studentId, int $ayId, int $semester): array
{
    $preview = function_exists('synk_student_preview_college_scope') ? synk_student_preview_college_scope($conn) : null;
    $scope = $preview ? ' AND c.college_id='.(int)$preview['college_id'].' AND c.campus_id='.(int)$preview['campus_id'] : '';
    $owned = synk_evaluation_has_ownership($conn) ? 'e.college_id=c.college_id' : '0=1';
    $savedSql=synk_evaluation_table_exists($conn,'tbl_student_faculty_evaluations')
        ? "OR EXISTS (SELECT 1 FROM tbl_student_faculty_evaluations e WHERE {$owned} AND e.student_id=? AND e.ay_id=? AND e.semester=?)" : '';
    $params=[$studentId,$ayId,$semester];
    if ($savedSql!=='') $params=array_merge($params,$params);
    return synk_evaluation_query($conn, "SELECT c.college_id,c.college_name,c.campus_id FROM tbl_college c
        WHERE (EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects es
            WHERE es.college_id=c.college_id AND es.campus_id=c.campus_id AND es.student_id=? AND es.ay_id=? AND es.semester=?)
        {$savedSql}) {$scope} ORDER BY c.college_name,c.college_id", str_repeat('i',count($params)), $params);
}

// Called inside the same transaction as the draft or final submission.
function synk_evaluation_require_student_write(mysqli $conn, int $studentId, int $collegeId, int $ayId, int $semester, array $facultyIds): array
{
    $current = synk_evaluation_query($conn, 'SELECT current_ay_id AS ay_id,current_semester AS semester FROM tbl_academic_settings LIMIT 1 LOCK IN SHARE MODE');
    if (!$current || !synk_evaluation_is_current(['ay_id'=>$ayId,'semester'=>$semester], $current[0])) {
        throw new DomainException('Previous academic terms are read only. Evaluations are accepted only for the current academic year and semester.');
    }
    $college = synk_evaluation_query($conn, "SELECT c.college_id,c.campus_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id
        WHERE c.college_id=? AND c.status='active' AND cp.status='active' LOCK IN SHARE MODE", 'i', [$collegeId]);
    if (!$college) throw new DomainException('An active college enrollment is required for evaluation.');
    $settings = synk_evaluation_settings($conn,$collegeId,$ayId,$semester,true);
    if (empty($settings['student_open'])) throw new DomainException('Your college has closed student evaluations for this term. Saved responses are preserved.');
    $rows = synk_evaluation_query($conn, 'SELECT DISTINCT faculty_id FROM tbl_student_management_enrolled_subjects
        WHERE student_id=? AND college_id=? AND ay_id=? AND semester=? AND campus_id=? AND is_active=1 AND faculty_id>0 LOCK IN SHARE MODE',
        'iiiii', [$studentId,$collegeId,$ayId,$semester,(int)$college[0]['campus_id']]);
    $allowed = array_map('intval',array_column($rows,'faculty_id'));
    foreach ($facultyIds as $facultyId) if (!in_array((int)$facultyId,$allowed,true)) throw new DomainException('This faculty member is outside your enrolled college and term.');
    return $allowed;
}

function synk_evaluation_save_control(mysqli $conn, array $input): void
{
    if (($_SESSION['role'] ?? '')!=='college_dean') throw new DomainException('Only the assigned College Dean can change evaluation controls.');
    foreach (['csrf_token','college_id','ay_id','semester','control','value','version'] as $key) {
        if (!isset($input[$key]) || !is_scalar($input[$key])) throw new DomainException('Invalid evaluation control request.');
    }
    if (!is_string($input['csrf_token']) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$input['csrf_token'])) {
        throw new DomainException('Your session token changed. Reload the page and try again.');
    }
    $control = (string)$input['control'];
    if (!in_array($control,['student_open','faculty_released'],true) || !in_array((string)$input['value'],['0','1'],true)) throw new DomainException('Choose a valid evaluation control.');
    foreach (['college_id','ay_id','semester','version'] as $key) {
        if (filter_var($input[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>$key==='version'?0:1,'max_range'=>2147483647]])===false) throw new DomainException('Choose a valid college and academic term.');
    }
    $collegeId=(int)$input['college_id']; $ayId=(int)$input['ay_id']; $semester=(int)$input['semester']; $value=(int)$input['value'];
    if ($semester>3 || !synk_evaluation_query($conn,'SELECT ay_id FROM tbl_academic_years WHERE ay_id=?','i',[$ayId])) throw new DomainException('Choose an available academic term.');
    synk_evaluation_ensure_access_schema($conn);
    $conn->begin_transaction();
    try {
        $userId=(int)$_SESSION['user_id'];
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE','i',[$userId]);
        synk_evaluation_query($conn,'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE','i',[$userId]);
        $account=synk_find_useraccount_by_id($conn,$userId);
        if (!$account || !synk_college_dean_account_has_access($conn,$account) || (int)$account['college_dean_college_id']!==$collegeId) throw new DomainException('Your college assignment changed. Reload the page.');
        $active=synk_evaluation_query($conn,"SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=? AND c.status='active' AND cp.status='active' LOCK IN SHARE MODE",'i',[$collegeId]);
        if (!$active) throw new DomainException('An active college and campus assignment is required.');
        $current=synk_evaluation_query($conn,'SELECT current_ay_id AS ay_id,current_semester AS semester FROM tbl_academic_settings LIMIT 1 LOCK IN SHARE MODE');
        if ($control==='student_open' && $value===1 && (!$current || !synk_evaluation_is_current(['ay_id'=>$ayId,'semester'=>$semester],$current[0]))) throw new DomainException('Student evaluations can open only for the current academic year and semester.');
        synk_evaluation_query($conn,'INSERT IGNORE INTO tbl_college_evaluation_settings (college_id,ay_id,semester) VALUES (?,?,?)','iii',[$collegeId,$ayId,$semester]);
        $setting=synk_evaluation_query($conn,'SELECT student_open,faculty_released,version FROM tbl_college_evaluation_settings WHERE college_id=? AND ay_id=? AND semester=? FOR UPDATE','iii',[$collegeId,$ayId,$semester])[0];
        if ((int)$setting['version']!==(int)$input['version']) throw new DomainException('These controls were changed in another request. Reload the page before saving.');
        if ((int)$setting[$control]!==$value) {
            synk_evaluation_query($conn,"UPDATE tbl_college_evaluation_settings SET {$control}=?,version=version+1,updated_by=?,updated_at=NOW() WHERE college_id=? AND ay_id=? AND semester=?",'iiiii',[$value,$userId,$collegeId,$ayId,$semester]);
            synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_audit (college_id,ay_id,semester,user_id,control_name,previous_value,new_value) VALUES (?,?,?,?,?,?,?)','iiiisii',[$collegeId,$ayId,$semester,$userId,$control,(int)$setting[$control],$value]);
        }
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
