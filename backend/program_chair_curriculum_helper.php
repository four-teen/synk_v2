<?php
require_once __DIR__ . '/program_chair_helper.php';

function synk_chair_curriculum_query(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare curriculum request.');
    try {
        if ($types !== '') $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) throw new RuntimeException('Unable to complete curriculum request.');
        $result = $stmt->get_result();
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } finally { $stmt->close(); }
}

function synk_chair_curriculum_scope(mysqli $conn, bool $lock = false): int
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || ($_SESSION['role'] ?? '') !== 'program_chair') {
        throw new DomainException('Program Chair access is required.', 403);
    }
    if ($lock) {
        synk_chair_curriculum_query($conn,'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE','i',[$userId]);
        synk_chair_curriculum_query($conn,'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE','i',[$userId]);
    }
    $account = synk_find_useraccount_by_id($conn,$userId);
    if (!$account || !synk_program_chair_account_has_access($conn,$account)) {
        throw new DomainException('Your Program Chair role or college assignment is no longer active.',403);
    }
    $_SESSION['college_id'] = (int)$account['college_id'];
    $_SESSION['college_name'] = (string)($account['college_name'] ?? '');
    if ($lock && !synk_chair_curriculum_query($conn,"SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id
        WHERE c.college_id=? AND c.status='active' AND cp.status='active' FOR UPDATE",'i',[(int)$account['college_id']])) {
        throw new DomainException('Your assigned college is no longer active.',403);
    }
    return (int)$account['college_id'];
}

function synk_chair_curriculum_id($value): int
{
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D',(string)$value) || (float)$value > 2147483647) {
        throw new DomainException('Choose a valid curriculum record.',422);
    }
    return (int)$value;
}

function synk_chair_curriculum_target(mysqli $conn, int $collegeId, string $kind, $value, bool $lock = false): array
{
    $id = synk_chair_curriculum_id($value);
    $joins = 'FROM tbl_program p';
    $where = 'p.program_id=?';
    $fields = 'p.program_id';
    if ($kind==='program') $where .= " AND p.status='active'";
    if ($kind !== 'program') {
        $joins .= ' JOIN tbl_prospectus_header h ON h.program_id=p.program_id';
        $fields .= ',h.prospectus_id'; $where = 'h.prospectus_id=?';
    }
    if (in_array($kind,['year_sem','subject'],true)) {
        $joins .= ' JOIN tbl_prospectus_year_sem y ON y.prospectus_id=h.prospectus_id';
        $fields .= ',y.pys_id'; $where = 'y.pys_id=?';
    }
    if ($kind === 'subject') {
        $joins .= ' JOIN tbl_prospectus_subjects ps ON ps.pys_id=y.pys_id';
        $fields .= ',ps.ps_id'; $where = 'ps.ps_id=?';
    }
    $rows = synk_chair_curriculum_query($conn,"SELECT {$fields} {$joins} WHERE {$where} AND p.college_id=?" . ($lock ? ' FOR UPDATE' : ''),'ii',[$id,$collegeId]);
    if (!$rows) throw new DomainException('This curriculum record is outside your assigned college or is unavailable.',403);
    return $rows[0];
}

// Called only by the dedicated Chair routes. The shared handlers retain the real role.
function synk_chair_curriculum_authorize(mysqli $conn, string $route): int
{
    if (!defined('SYNK_PROGRAM_CHAIR_CURRICULUM_API') || SYNK_PROGRAM_CHAIR_CURRICULUM_API !== true) {
        http_response_code(403); exit('ERROR|Use the Program Chair curriculum portal.');
    }
    $actions = [
        'builder'=>['load_subject_for_edit','update_prospectus_subject','delete_year_sem','get_all_subjects',
            'load_prospectus_list','list_headers','load_header','save_header','save_year_sem',
            'save_prospectus_subject','delete_prospectus_subject','load_year_sem'],
        'browser'=>['load_browser_filters','load_browser_prospectuses','load_prospectus_by_program','update_prospectus_header','transfer_prospectus','delete_prospectus'],
        'copy'=>['load_programs','copy_prospectus'],
    ];
    $writes = ['save_header','save_year_sem','save_prospectus_subject','update_prospectus_subject',
        'delete_prospectus_subject','delete_year_sem','update_prospectus_header','transfer_prospectus','delete_prospectus','copy_prospectus'];
    $writing = false; $transaction = false;
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Use a POST request.',405);
        foreach ($_POST as $key=>$value) if (!is_string($value)) throw new DomainException('Invalid curriculum request.',400);
        $selected = array_values(array_intersect(array_keys($_POST),$actions[$route] ?? []));
        if (count($selected)!==1) throw new DomainException('Choose one curriculum action.',400);
        $action = $selected[0]; $writing = in_array($action,$writes,true);
        if ($writing) {
            if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null)
                || !hash_equals((string)$_SESSION['csrf_token'],$_POST['csrf_token'])) {
                throw new DomainException('Reload this page before managing curriculum.',403);
            }
            $conn->begin_transaction(); $transaction = true;
        }
        $collegeId = synk_chair_curriculum_scope($conn,$writing);
        if (isset($_POST['college_id']) && (string)$_POST['college_id'] !== (string)$collegeId) {
            throw new DomainException('You can manage curriculum only in your assigned college.',403);
        }
        $target = null;
        if (in_array($action,['save_header','load_prospectus_by_program'],true)) {
            $target = synk_chair_curriculum_target($conn,$collegeId,'program',$_POST['program_id'] ?? null,$writing);
        }
        if (in_array($action,['load_header','save_year_sem','load_year_sem','update_prospectus_header','transfer_prospectus','delete_prospectus'],true)) {
            $target = synk_chair_curriculum_target($conn,$collegeId,'header',$_POST['prospectus_id'] ?? null,$writing);
        }
        if (in_array($action,['save_prospectus_subject','delete_year_sem'],true)) {
            $target = synk_chair_curriculum_target($conn,$collegeId,'year_sem',$_POST['pys_id'] ?? null,$writing);
        }
        if (in_array($action,['load_subject_for_edit','update_prospectus_subject','delete_prospectus_subject'],true)) {
            $target = synk_chair_curriculum_target($conn,$collegeId,'subject',$_POST['ps_id'] ?? null,$writing);
        }
        if ($action==='get_all_subjects' && !empty($_POST['prospectus_id'])) {
            synk_chair_curriculum_target($conn,$collegeId,'header',$_POST['prospectus_id']);
        }
        if ($action==='copy_prospectus') {
            $target = synk_chair_curriculum_target($conn,$collegeId,'header',$_POST['source_prospectus_id'] ?? null,true);
        }
        if (in_array($action,['copy_prospectus','transfer_prospectus'],true)) {
            synk_chair_curriculum_target($conn,$collegeId,'program',$_POST['target_program_id'] ?? null,true);
        }
        if ($action==='save_year_sem' && (!in_array($_POST['year_level'] ?? '',['1','2','3','4'],true)
            || !in_array($_POST['semester'] ?? '',['1','2','3'],true))) {
            throw new DomainException('Choose a valid year level and semester.',422);
        }
        if (in_array($action,['save_prospectus_subject','update_prospectus_subject'],true)) {
            $subId = synk_chair_curriculum_id($_POST['sub_id'] ?? null);
            if (!synk_chair_curriculum_query($conn,"SELECT sub_id FROM tbl_subject_masterlist WHERE sub_id=? AND status='active' FOR UPDATE",'i',[$subId])) {
                throw new DomainException('Choose an active subject from the subject masterlist.',422);
            }
            foreach (['lec_units','lab_units'] as $field) {
                $number = $_POST[$field] ?? '0';
                if (!is_numeric($number) || !is_finite((float)$number) || (float)$number<0 || (float)$number>255) {
                    throw new DomainException('Enter valid lecture and laboratory hours.',422);
                }
            }
            if (!preg_match('/^[1-9][0-9]{0,6}$/D',$_POST['sort_order'] ?? '1')) throw new DomainException('Enter a valid subject order.',422);
            $duplicate = synk_chair_curriculum_query($conn,'SELECT ps_id FROM tbl_prospectus_subjects WHERE pys_id=? AND sub_id=? AND ps_id<>? FOR UPDATE',
                'iii',[(int)$target['pys_id'],$subId,(int)($target['ps_id'] ?? 0)]);
            if ($duplicate && $action==='update_prospectus_subject') throw new DomainException('This subject already exists in that year and semester.',422);
            $raw = $_POST['prerequisite_sub_ids'] ?? '';
            $ids = $raw==='' || $raw==='null' ? [] : json_decode($raw,true);
            if (!is_array($ids) || array_values($ids)!==$ids || count($ids)>100) throw new DomainException('Choose valid prerequisite subjects.',422);
            $codes = []; $normalized = [];
            foreach ($ids as $id) {
                $id = synk_chair_curriculum_id($id);
                if ($id===$subId) throw new DomainException('A subject cannot be its own prerequisite.',422);
                if (isset($normalized[$id])) continue;
                $subject = synk_chair_curriculum_query($conn,"SELECT sub_code FROM tbl_subject_masterlist WHERE sub_id=? AND status='active'",'i',[$id]);
                if (!$subject) throw new DomainException('Choose active prerequisite subjects.',422);
                $normalized[$id] = (string)$id; $codes[] = $subject[0]['sub_code'];
            }
            $_POST['prerequisite_sub_ids'] = $normalized ? json_encode(array_values($normalized)) : null;
            $_POST['prerequisites'] = implode(', ',$codes);
            if (strlen($_POST['prerequisites'])>255) throw new DomainException('The prerequisite list is too long.',422);
        }
        if ($writing && $target && isset($target['prospectus_id'])) {
            // A malformed cross-college offering must not turn a curriculum edit into cross-college access.
            $external = synk_chair_curriculum_query($conn,'SELECT o.offering_id FROM tbl_prospectus_offering o JOIN tbl_program p ON p.program_id=o.program_id WHERE o.prospectus_id=? AND p.college_id<>? LIMIT 1',
                'ii',[(int)$target['prospectus_id'],$collegeId]);
            if ($external) throw new DomainException('This curriculum has references outside your college. Ask an administrator to review it.',403);
        }
        if ($writing) {
            // Shared handlers exit after emitting their response. Finalize the enclosing write atomically.
            ob_start(static function (string $body) use ($conn): string {
                try {
                    if (http_response_code()<400 && preg_match('/^(?:OK|EXISTS|EXISTING)\|/',ltrim($body))) $conn->commit();
                    else $conn->rollback();
                } catch (Throwable $exception) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    error_log('Program Chair curriculum transaction: '.$exception->getMessage());
                    http_response_code(500); return 'ERROR|Curriculum changes could not be saved. Please try again.';
                }
                return $body;
            });
        }
        return $collegeId;
    } catch (Throwable $exception) {
        if ($transaction) $conn->rollback();
        $status = $exception instanceof DomainException ? $exception->getCode() : 500;
        http_response_code(in_array($status,[400,403,405,409,422],true) ? $status : 500);
        if (!$exception instanceof DomainException) error_log('Program Chair curriculum: '.$exception->getMessage());
        exit('ERROR|' . ($exception instanceof DomainException ? $exception->getMessage() : 'Unable to manage curriculum. Please try again.'));
    }
}

function synk_chair_curriculum_prepare_view(mysqli $conn): ?array
{
    if (defined('SYNK_PROGRAM_CHAIR_CURRICULUM_VIEW')) synk_program_chair_require_login($conn);
    if (($_SESSION['role'] ?? '')!=='program_chair') return null;
    if (!defined('SYNK_PROGRAM_CHAIR_CURRICULUM_VIEW')) {
        http_response_code(403); exit('Use the Program Chair curriculum portal.');
    }
    $collegeId = synk_chair_curriculum_scope($conn);
    if (!empty($_GET['pid'])) {
        try { synk_chair_curriculum_target($conn,$collegeId,'header',$_GET['pid']); }
        catch (DomainException $exception) { http_response_code(403); exit('This curriculum is outside your assigned college or is unavailable.'); }
    }
    ob_start(static function (string $html): string {
        return str_replace([
            '../backend/query_prospectus.php','../backend/query_prospectus_browser.php','../backend/query_prospectus_copy.php',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            'https://cdn.jsdelivr.net/npm/sweetalert2@11','<script src="../assets/js/dashboards-analytics.js"></script>',
            '<title>Prospectus Builder | Synk</title>','href="custom_css.css"',
            '<title>Prospectus Browser | Synk</title>',
        ],[
            '../backend/query_program_chair_prospectus.php','../backend/query_program_chair_prospectus_browser.php','../backend/query_program_chair_prospectus_copy.php',
            'vendor/select2/select2.min.css','vendor/select2/select2.min.js','../assets/js/sweetalert2.all.min.js','',
            '<title>Curriculum | Program Chair | Synk</title>','href="../college-dean/custom_css.css"',
            '<title>Curriculum Browser | Program Chair | Synk</title>',
        ],$html);
    });
    return ['college_id'=>$collegeId,'college'=>synk_program_chair_fetch_college_context($conn,$collegeId)];
}
