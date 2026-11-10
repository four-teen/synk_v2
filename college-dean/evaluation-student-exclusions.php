<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/college_dean_evaluations_helper.php';
header('Cache-Control: no-store, private');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','POST'],true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
synk_college_dean_require_login($conn);
if (($_SESSION['role'] ?? '')!=='college_dean') { http_response_code(403); exit('Only the assigned College Dean can manage student exclusions.'); }
$scope=synk_college_dean_dashboard_require_scope($conn,[],true);
$term=synk_dean_view_term($conn);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$error=''; $students=[]; $audit=[];
$ready=synk_evaluation_student_exclusions_ready($conn);
$studentSearch=is_string($_GET['search'] ?? null) ? mb_substr(trim($_GET['search']),0,150,'UTF-8') : '';
$studentFilter=is_string($_GET['rule_filter'] ?? null) && in_array($_GET['rule_filter'],['all','included','excluded'],true) ? $_GET['rule_filter'] : 'all';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        synk_evaluation_save_student_exclusion($conn,$_POST);
        header('Location: evaluation-student-exclusions.php?'.http_build_query(['dean_ay_id'=>(int)$_POST['ay_id'],'dean_semester'=>(int)$_POST['semester'],'student_id'=>(int)$_POST['student_id'],'search'=>$studentSearch,'rule_filter'=>$studentFilter,'saved'=>1]));
        exit;
    }
} catch (DomainException $exception) { http_response_code(422); $error=$exception->getMessage(); }
catch (Throwable $exception) { http_response_code(500); error_log('Evaluation student exclusion: '.$exception->getMessage()); $error='Unable to save this student exclusion. Reload and try again.'; }
$studentFormError=$error;
try {
    if ($ready) {
        $students=synk_evaluation_student_candidates($conn,(int)$scope['college_id'],(int)$term['ay_id'],(int)$term['semester']);
        $states=synk_evaluation_score_states($conn,(int)$term['ay_id'],(int)$term['semester']);
        $source=synk_dean_evaluations_source($conn,$scope,$term,'all');
        foreach (synk_evaluation_query($conn,'SELECT e.evaluation_id,e.student_id,e.submission_status '.$source['sql'],$source['types'],$source['params']) as $header) {
            $id=(int)$header['student_id'];
            if (!isset($students[$id])) continue;
            $students[$id][$header['submission_status']==='submitted'?'submitted_count':'draft_count']++;
            if (($states[(int)$header['evaluation_id']]['state'] ?? 'included')==='excluded') $students[$id]['excluded_count']++;
        }
        $audit=synk_evaluation_query($conn,'SELECT a.student_id,a.new_value,a.changed_at,u.username FROM tbl_college_evaluation_student_exclusion_audit a
            LEFT JOIN tbl_useraccount u ON u.user_id=a.actor_user_id WHERE a.college_id=? AND a.ay_id=? AND a.semester=? ORDER BY a.audit_id DESC LIMIT 10',
            'iii',[(int)$scope['college_id'],(int)$term['ay_id'],(int)$term['semester']]);
    }
} catch (Throwable $exception) { http_response_code(500); error_log('Evaluation student exclusion list: '.$exception->getMessage()); $error='Unable to load student exclusions. Reload and try again.'; }
$selectedId=is_scalar($_POST['student_id'] ?? $_GET['student_id'] ?? null) ? (int)($_POST['student_id'] ?? $_GET['student_id']) : 0;
$selected=$students[$selectedId] ?? null;
$years=synk_dean_academic_years($conn);
$collegeDeanPortalContext=synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName=(string)($collegeDeanPortalContext['account_name'] ?? 'College Dean');
$collegeDeanPortalDisplayEmail=(string)($collegeDeanPortalContext['email'] ?? '');
function student_exclusion_h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$termQuery=['dean_ay_id'=>(int)$term['ay_id'],'dean_semester'=>(int)$term['semester']];
$listQuery=$termQuery+['search'=>$studentSearch,'rule_filter'=>$studentFilter];
$studentDialogData=[];
foreach ($students as $student) $studentDialogData[(int)$student['student_id']]=[
    'label'=>$student['label'],'excluded'=>(int)($student['rule']['excluded'] ?? 0),
    'reason'=>(string)($student['rule']['reason'] ?? ''),'version'=>(int)($student['rule']['version'] ?? 0)];
$studentVisible=static function(array $student)use($studentSearch,$studentFilter):bool {
    if ($studentFilter==='excluded' && empty($student['rule']['excluded'])) return false;
    if ($studentFilter==='included' && !empty($student['rule']['excluded'])) return false;
    foreach (preg_split('/\s+/u',$studentSearch,-1,PREG_SPLIT_NO_EMPTY) as $word) if (mb_stripos($student['label'],$word,0,'UTF-8')===false) return false;
    return true;
};
$visibleCount=count(array_filter($students,$studentVisible));
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Evaluation Student Exclusions | College Dean | Synk</title>
<link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
<link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css"><link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css"><link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css"><link rel="stylesheet" href="custom_css.css">
<link rel="stylesheet" href="evaluation-subject-exclusions.css?v=<?= (int)filemtime(__DIR__.'/evaluation-subject-exclusions.css') ?>">
<script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script></head>
<body><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php include 'sidebar.php'; ?><div class="layout-page"><?php include 'navbar.php'; ?><div class="content-wrapper"><main class="container-xxl flex-grow-1 container-p-y" id="evaluationStudentExclusions">
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h4 class="mb-1">Evaluation Student Exclusions</h4><p class="text-muted mb-0"><?= student_exclusion_h($scope['college_name'].' · '.$term['term_text']) ?></p></div><a class="btn btn-outline-secondary align-self-start" href="faculty-evaluations.php?<?= student_exclusion_h(http_build_query($termQuery)) ?>">Back to Faculty Evaluation</a></div>
<div class="card mb-4"><div class="card-body"><p>Exclude all ratings submitted by a selected student across every teacher and subject in this college and academic term. This covers existing responses and any submitted later in this term, including combined subject responses.</p>
<p class="small text-muted">Students continue evaluating normally. Original answers, comments and completion records are preserved. Restoring student eligibility keeps any subject exclusions in effect. Other colleges and terms are unaffected.</p>
<form method="get" class="row g-3 align-items-end"><div class="col-6 col-lg-4"><label class="form-label" for="exclusionYear">Academic Year</label><select class="form-select" name="dean_ay_id" id="exclusionYear"><?php foreach ($years as $year): ?><option value="<?= (int)$year['ay_id'] ?>"<?= (int)$year['ay_id']===(int)$term['ay_id']?' selected':'' ?>><?= student_exclusion_h($year['ay']) ?></option><?php endforeach; ?></select></div><div class="col-6 col-lg-4"><label class="form-label" for="exclusionSemester">Semester</label><select class="form-select" name="dean_semester" id="exclusionSemester"><?php foreach ([1,2,3] as $semester): ?><option value="<?= $semester ?>"<?= $semester===(int)$term['semester']?' selected':'' ?>><?= student_exclusion_h(synk_semester_label($semester)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-lg-4"><button class="btn btn-primary">Load Students</button></div></form></div></div>
<?php if ($error!=='' && $error!==$studentFormError): ?><div class="alert alert-danger" role="alert" data-student-page-error><?= student_exclusion_h($error) ?></div><?php endif; ?>
<?php if ($studentFormError!=='' && !$selected): ?><div class="alert alert-danger" role="alert" data-student-page-error><?= student_exclusion_h($studentFormError) ?></div><?php endif; ?>
<?php if (isset($_GET['saved']) && !$error): ?><div class="alert alert-success" role="status" data-student-save-success>Student exclusion saved. Faculty scores now use the updated eligibility rule.</div><?php endif; ?>
<?php if (!$ready): ?><div class="alert alert-warning">Student exclusion storage is not prepared. Apply the evaluation student exclusions update before managing this list.</div><?php else: ?>
<script type="application/json" id="studentExclusionData"><?= json_encode($studentDialogData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<div class="card mb-4"><div class="card-body"><h5>Students in this college and term</h5><p class="small text-muted">Counts cover all questionnaire versions. Excluded responses include both student and subject rules. Restored students can still have responses excluded by subject rules.</p>
<form method="get" class="exclusion-search" id="studentExclusionSearchForm" role="search">
<?php foreach ($termQuery as $name=>$value): ?><input type="hidden" name="<?= $name ?>" value="<?= (int)$value ?>"><?php endforeach; ?>
<div><label for="studentExclusionSearch" class="form-label">Search students</label><input type="search" class="form-control" id="studentExclusionSearch" name="search" maxlength="150" placeholder="Student name or number" value="<?= student_exclusion_h($studentSearch) ?>" aria-controls="studentExclusionList"></div>
<div><label for="studentExclusionFilter" class="form-label">Student rule</label><select class="form-select" id="studentExclusionFilter" name="rule_filter" aria-controls="studentExclusionList"><?php foreach (['all'=>'All students','included'=>'Included','excluded'=>'Excluded'] as $value=>$label): ?><option value="<?= $value ?>"<?= $studentFilter===$value?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<button type="button" class="btn btn-outline-secondary" id="studentExclusionClear">Clear</button><noscript><button class="btn btn-primary">Search</button></noscript></form>
<p class="small text-muted mt-3" id="studentExclusionCount" role="status" aria-live="polite"><?= $visibleCount ?> of <?= count($students) ?> students</p>
<div class="exclusion-list-wrap"><table class="table exclusion-table mb-0" id="studentExclusionList"><thead><tr><th scope="col">Student</th><th scope="col">Student Rule</th><th scope="col">Submitted</th><th scope="col">Drafts</th><th scope="col">Excluded Responses</th><th scope="col">Action</th></tr></thead><tbody>
<?php foreach ($students as $student): ?><tr data-student-row data-student-label="<?= student_exclusion_h($student['label']) ?>" data-rule="<?= !empty($student['rule']['excluded'])?'excluded':'included' ?>"<?= !$studentVisible($student)?' hidden':'' ?>><td data-label="Student"><?= student_exclusion_h($student['label']) ?></td><td data-label="Student Rule"><span class="badge <?= !empty($student['rule']['excluded'])?'bg-label-warning':'bg-label-success' ?>"><?= !empty($student['rule']['excluded'])?'Excluded':'Included' ?></span></td><td data-label="Submitted"><?= (int)$student['submitted_count'] ?></td><td data-label="Drafts"><?= (int)$student['draft_count'] ?></td><td data-label="Excluded Responses"><?= (int)$student['excluded_count'] ?></td><td data-label="Action"><a class="btn btn-sm btn-outline-primary" data-manage-student="<?= (int)$student['student_id'] ?>" href="?<?= student_exclusion_h(http_build_query($listQuery+['student_id'=>$student['student_id']])) ?>#studentExclusionForm" aria-label="Manage <?= student_exclusion_h($student['label']) ?>">Manage</a></td></tr><?php endforeach; ?>
</tbody></table></div><p class="exclusion-empty text-muted" id="studentExclusionNoMatches"<?= $visibleCount?' hidden':'' ?>><?= $students?'No students match your search and filter.':'No students are recorded for this college and term.' ?></p></div></div>
<details class="card mb-4"><summary class="card-body">Recent student exclusion changes</summary><div class="card-body pt-0"><?php foreach ($audit as $item): $change=json_decode($item['new_value'],true) ?: []; ?><div class="border-top pt-2 mb-2"><strong><?= student_exclusion_h($students[(int)$item['student_id']]['label'] ?? 'Student #'.$item['student_id']) ?></strong>: <?= !empty($change['excluded'])?'Excluded':'Restored' ?><small class="d-block text-muted"><?= student_exclusion_h(($item['username'] ?? 'Dean').' · '.$item['changed_at']) ?></small><p class="mb-0"><?= student_exclusion_h($change['reason'] ?? '') ?></p></div><?php endforeach; ?><?php if (!$audit): ?><p class="text-muted mb-0">No student exclusion changes recorded for this term.</p><?php endif; ?></div></details>
<?php endif; ?>
</main><?php include '../footer.php'; ?><div class="content-backdrop fade"></div></div></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<?php if ($ready): $rule=$selected['rule'] ?? []; ?>
<div class="modal fade exclusion-modal" id="studentExclusionModal" tabindex="-1" aria-labelledby="studentExclusionDialogTitle" aria-describedby="studentExclusionContext" data-bs-backdrop="static" data-open="<?= $selected && (!isset($_GET['saved']) || $studentFormError!=='')?'1':'0' ?>">
<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="studentExclusionForm" method="post" action="?<?= student_exclusion_h(http_build_query($listQuery+['student_id'=>$selectedId])) ?>">
<div class="modal-header"><div><h2 class="modal-title fs-5" id="studentExclusionDialogTitle">Manage student</h2><p class="mb-0 mt-2 fw-semibold" id="studentExclusionStudent"><?= student_exclusion_h($selected['label'] ?? '') ?></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close student management"></button></div>
<div class="modal-body"><p class="small text-muted" id="studentExclusionContext"><?= student_exclusion_h($scope['college_name'].' · '.$term['term_text']) ?></p>
<div id="studentExclusionFormError" class="alert alert-danger" role="alert" data-student-form-error<?= $studentFormError===''?' hidden':'' ?>><?= student_exclusion_h($studentFormError) ?></div>
<button type="button" class="btn btn-outline-primary mb-3" id="studentExclusionReload" hidden>Reload latest rule</button>
<?php foreach (['csrf_token'=>$_SESSION['csrf_token'],'college_id'=>$scope['college_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'student_id'=>$selectedId,'version'=>(int)($rule['version'] ?? 0)] as $name=>$value): ?><input type="hidden" name="<?= $name ?>" value="<?= student_exclusion_h($value) ?>"><?php endforeach; ?>
<div class="mb-3"><label for="studentExclusionStatus" class="form-label">Score eligibility</label><select name="excluded" id="studentExclusionStatus" class="form-select"><option value="0">Include / restore eligibility</option><option value="1"<?= !empty($rule['excluded'])?' selected':'' ?>>Exclude from faculty scores</option></select></div>
<p class="small text-muted">This rule covers every teacher and subject evaluated by this student in this college and term. Subject exclusions still apply after restoring this student.</p>
<div><label class="form-label" for="studentExclusionReason">Reason for this change (Dean only)</label><textarea class="form-control" id="studentExclusionReason" name="reason" rows="4" maxlength="1000" required><?= student_exclusion_h(is_string($_POST['reason'] ?? null)?$_POST['reason']:($rule['reason'] ?? '')) ?></textarea></div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="studentExclusionSave">Save Student Rule</button></div>
</form></div></div>
<?php if ($selected): ?><noscript><style>#studentExclusionModal{display:block;position:static;opacity:1}#studentExclusionModal .modal-dialog{margin:1rem auto;transform:none}</style></noscript><?php endif; ?>
<?php endif; ?>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<script src="evaluation-student-exclusions.js?v=<?= (int)filemtime(__DIR__.'/evaluation-student-exclusions.js') ?>"></script>
</body></html>
