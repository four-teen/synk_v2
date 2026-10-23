<?php
require_once __DIR__ . '/scheduler_term_helper.php';
require_once __DIR__ . '/scheduler_term_list_helper.php';

function synk_scheduler_term_policy_lock(mysqli $conn): void
{
    static $locked = false;
    if ($locked) return;
    $row = $conn->query("SELECT GET_LOCK('synk.scheduler.term-policy', 5) AS acquired")->fetch_assoc();
    if ((int)($row['acquired'] ?? 0) !== 1) throw new DomainException('Another academic-term operation is finishing. Try again shortly.',409);
    $locked = true;
    register_shutdown_function(static function () use ($conn): void {
        try { $conn->query("SELECT RELEASE_LOCK('synk.scheduler.term-policy')"); } catch (Throwable $ignored) {}
    });
}

function synk_scheduler_guard_request(mysqli $conn, string $endpoint): void
{
    // Shared endpoints retain the authorization and current-term behavior of other roles.
    if (($_SESSION['role'] ?? '') !== 'scheduler') return;
    try {
        $input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? $_GET : $_POST;
        foreach (['action','kind','college_id','csrf_token','scheduler_term_key','scheduler_college_id','ay_id','ay','semester','semester_num','faculty_id','faculty_need_id','offering_id','target_offering_id','full_owner_offering_id','lec_owner_offering_id','lab_owner_offering_id','section_id','program_id','prospectus_id','schedule_set_id','schedule_id','workload_id','simulation_id'] as $scalarField) {
            if (isset($input[$scalarField]) && !is_scalar($input[$scalarField])) throw new DomainException('Invalid request. Reload the page before trying again.',400);
        }
        if (isset($input['schedule_ids'])) {
            if (!is_array($input['schedule_ids']) || count($input['schedule_ids'])>5000) throw new DomainException('Select valid schedule rows.',400);
            foreach ($input['schedule_ids'] as $scheduleId) synk_scheduler_term_id($scheduleId);
        }
        if (isset($input['scheduler_college_id']) && synk_scheduler_term_id($input['scheduler_college_id']) !== (int)($_SESSION['college_id'] ?? 0)) {
            throw new DomainException('Your college workspace changed in another tab. Reload this page before continuing.',409);
        }
        // These read handlers check their submitted college themselves: campus
        // reports support college_id=0; subject filters report stale context as 409.
        $handlerChecksCollege = in_array($endpoint,['query_admin_faculty_workload.php','query_subject_assignments.php'],true);
        $collegeId = isset($input['college_id']) && !$handlerChecksCollege ? synk_scheduler_term_id($input['college_id']) : (int)($_SESSION['college_id'] ?? 0);
        synk_scheduler_list_scope($conn,$collegeId);
        $termInput = $input;
        // Curriculum semesters describe the curriculum, independently of the working term.
        if (in_array($endpoint,['query_prospectus.php','query_program.php','query_faculty.php'],true)) {
            unset($termInput['ay_id'],$termInput['ay'],$termInput['semester'],$termInput['semester_num']);
        }
        $term = synk_scheduler_request_term($conn,$termInput);
        $GLOBALS['synk_scheduler_page_term'] = $term;
        $write = in_array($endpoint,['query_apply_workload.php','query_remove_workload.php','query_remove_all_college_workload.php',
            'query_add_workload_simulation.php','query_remove_workload_simulation.php','query_clear_workload_simulation.php'],true);
        foreach (array_keys($input) as $key) {
            if (preg_match('/^(?:save_|update_|delete_|remove_|clear_|apply_|generate_|copy_|add_|create_)/D',$key)
                || $key === 'load_schedule_set_into_live') $write = true;
        }
        if (in_array($input['action'] ?? '',['create','delete','copy','save','remove','update'],true)) $write = true;
        if (!$write) return;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Use a POST request.',405);
        synk_scheduler_require_csrf($input);
        synk_scheduler_term_policy_lock($conn);
        $term = synk_scheduler_require_writable_term($conn,$term);
        if ($endpoint === 'query_add_workload_simulation.php' && isset($input['faculty_id'])) {
            $facultyId = synk_scheduler_term_id($input['faculty_id']);
            $membership = synk_scheduler_term_query($conn,"SELECT 1 FROM tbl_college_faculty cf JOIN tbl_faculty f ON f.faculty_id=cf.faculty_id
                WHERE cf.college_id=? AND cf.faculty_id=? AND cf.status='active' AND f.status='active'
                AND ((cf.ay_id=? AND cf.semester=?) OR (cf.ay_id IS NULL AND cf.semester IS NULL AND " . synk_scheduler_legacy_term_flag($conn) . "=1)) LIMIT 1",
                'iiii',[$collegeId,$facultyId,$term['ay_id'],$term['semester']]);
            if (!$membership) throw new DomainException('This faculty member is not assigned to your college for the selected term.',403);
        }
        $resourceQueries = [
            'program_id'=>['SELECT NULL AS ay_id,NULL AS semester,college_id FROM tbl_program WHERE program_id=?','tbl_program'],
            'prospectus_id'=>['SELECT NULL AS ay_id,NULL AS semester,p.college_id FROM tbl_prospectus_header ph JOIN tbl_program p ON p.program_id=ph.program_id WHERE ph.prospectus_id=?','tbl_prospectus_header'],
            'offering_id'=>['SELECT o.ay_id,o.semester,p.college_id FROM tbl_prospectus_offering o JOIN tbl_program p ON p.program_id=o.program_id WHERE o.offering_id=?','tbl_prospectus_offering'],
            'section_id'=>['SELECT s.ay_id,s.semester,p.college_id FROM tbl_sections s JOIN tbl_program p ON p.program_id=s.program_id WHERE s.section_id=?','tbl_sections'],
            'simulation_id'=>['SELECT ay_id,semester,college_id FROM tbl_faculty_workload_simulation WHERE simulation_id=?','tbl_faculty_workload_simulation'],
            'college_faculty_id'=>['SELECT ay_id,semester,college_id FROM tbl_college_faculty WHERE college_faculty_id=?','tbl_college_faculty'],
            'schedule_set_id'=>['SELECT ay_id,semester,college_id FROM tbl_program_schedule_set WHERE schedule_set_id=?','tbl_program_schedule_set'],
        ];
        foreach (['target_offering_id','full_owner_offering_id','lec_owner_offering_id','lab_owner_offering_id'] as $mergeField) {
            $resourceQueries[$mergeField] = $resourceQueries['offering_id'];
        }
        foreach ($resourceQueries as $field => $query) {
            if (!isset($input[$field]) || (is_scalar($input[$field]) && in_array(trim((string)$input[$field]),['','0'],true))) continue;
            $id = synk_scheduler_term_id($input[$field]);
            if (!synk_table_exists($conn,$query[1])) continue; // The endpoint reports its existing schema error.
            $rows = synk_scheduler_term_query($conn,$query[0],'i',[$id]);
            if (!$rows) continue; // Preserve endpoint-specific missing-record responses.
            $row = $rows[0];
            if ((int)$row['college_id'] !== $collegeId) throw new DomainException('This record is outside the selected college.',403);
            if ($field==='college_faculty_id' && $row['ay_id']===null && !$term['is_current']) throw new DomainException('Copy or add this faculty member to the selected term before changing its list entry.',409);
            if ($row['ay_id'] !== null && ((int)$row['ay_id'] !== $term['ay_id'] || (int)$row['semester'] !== $term['semester'])) {
                throw new DomainException('This record belongs to another academic term. Reload the page before saving.',409);
            }
        }
        if ($endpoint==='query_workload.php' && !empty($input['workload_id']) && synk_table_exists($conn,'tbl_faculty_workload')) {
            $legacyRows=synk_scheduler_term_query($conn,'SELECT w.ay,w.semester,p.college_id FROM tbl_faculty_workload w JOIN tbl_program p ON p.program_id=w.program_id WHERE w.workload_id=?','i',[synk_scheduler_term_id($input['workload_id'])]);
            foreach ($legacyRows as $legacyRow) {
                $legacyTerm=synk_scheduler_request_term($conn,['ay'=>$legacyRow['ay'],'semester'=>$legacyRow['semester']]);
                if ((int)$legacyRow['college_id']!==$collegeId || $legacyTerm['key']!==$term['key']) throw new DomainException('This workload belongs to another college or academic term.',409);
            }
        }
    } catch (DomainException $error) {
        http_response_code(in_array($error->getCode(),[400,401,403,404,405,409,422],true) ? $error->getCode() : 422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','message'=>$error->getMessage()]); exit;
    } catch (Throwable $error) {
        error_log('Scheduler term guard: '.$error->getMessage());
        http_response_code(500); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status'=>'error','message'=>'Unable to validate the scheduler workspace. Reload the page and try again.']); exit;
    }
}
