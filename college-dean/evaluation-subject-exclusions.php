<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/college_dean_evaluations_helper.php';
header('Cache-Control: no-store, private');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET',['GET','POST'],true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
synk_college_dean_require_login($conn);
if (($_SESSION['role'] ?? '')!=='college_dean') { http_response_code(403); exit('Only the assigned College Dean can manage subject exclusions.'); }
$scope=synk_college_dean_dashboard_require_scope($conn,[],true);
$term=synk_dean_view_term($conn);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$error=''; $subjects=[]; $review=[]; $audit=[];
$ready=synk_evaluation_subject_exclusions_ready($conn);
$subjectSearch=is_string($_GET['search'] ?? null) ? mb_substr(trim($_GET['search']),0,150,'UTF-8') : '';
$subjectFilter=is_string($_GET['rule_filter'] ?? null) && in_array($_GET['rule_filter'],['all','included','excluded','review'],true) ? $_GET['rule_filter'] : 'all';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
        synk_evaluation_save_subject_exclusion($conn,$_POST);
        header('Location: evaluation-subject-exclusions.php?'.http_build_query(['dean_ay_id'=>(int)$_POST['ay_id'],'dean_semester'=>(int)$_POST['semester'],'subject_id'=>(int)$_POST['subject_id'],'search'=>$subjectSearch,'rule_filter'=>$subjectFilter,'saved'=>1]));
        exit;
    }
} catch (DomainException $exception) { http_response_code(422); $error=$exception->getMessage(); }
catch (Throwable $exception) { http_response_code(500); error_log('Evaluation subject exclusion: '.$exception->getMessage()); $error='Unable to save this subject exclusion. Reload and try again.'; }
$subjectFormError=$error;
try {
    if ($ready) {
        $subjects=synk_evaluation_subject_candidates($conn,(int)$scope['college_id'],(int)$term['ay_id'],(int)$term['semester']);
        $states=synk_evaluation_score_states($conn,(int)$term['ay_id'],(int)$term['semester'],true);
        $source=synk_dean_evaluations_source($conn,$scope,$term,'all');
        $headers=synk_evaluation_query($conn,'SELECT e.evaluation_id,e.faculty_id,e.faculty_name '.$source['sql']." AND e.submission_status='submitted'",$source['types'],$source['params']);
        foreach ($headers as $header) {
            $state=$states[(int)$header['evaluation_id']] ?? ['state'=>'included','subjects'=>[]];
            $seenSubjects=[];
            foreach ($state['subjects'] as $subject) if (isset($subjects[(int)$subject['subject_id']]) && !isset($seenSubjects[(int)$subject['subject_id']])) {
                $id=(int)$subject['subject_id'];
                $seenSubjects[$id]=true;
                $subjects[$id][$state['state'].'_count']=($subjects[$id][$state['state'].'_count'] ?? 0)+1;
            }
            if ($state['state']==='review') {
                $labels=implode('; ',array_column($state['subjects'],'label')) ?: 'Original subject association unavailable';
                $key=$header['faculty_id'].':'.$labels;
                if (!isset($review[$key])) $review[$key]=['faculty'=>$header['faculty_name'],'subjects'=>$labels,'count'=>0];
                $review[$key]['count']++;
            }
        }
        $audit=synk_evaluation_query($conn,'SELECT a.subject_id,a.new_value,a.changed_at,u.username FROM tbl_college_evaluation_subject_exclusion_audit a
            LEFT JOIN tbl_useraccount u ON u.user_id=a.actor_user_id WHERE a.college_id=? AND a.ay_id=? AND a.semester=? ORDER BY a.audit_id DESC LIMIT 10',
            'iii',[(int)$scope['college_id'],(int)$term['ay_id'],(int)$term['semester']]);
    }
} catch (Throwable $exception) { http_response_code(500); error_log('Evaluation exclusion list: '.$exception->getMessage()); $error='Unable to load subject exclusions. Reload and try again.'; }
$selectedId=is_scalar($_POST['subject_id'] ?? $_GET['subject_id'] ?? null) ? (int)($_POST['subject_id'] ?? $_GET['subject_id']) : 0;
$selected=$subjects[$selectedId] ?? null;
$years=synk_dean_academic_years($conn);
$collegeDeanPortalContext=synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName=(string)($collegeDeanPortalContext['account_name'] ?? 'College Dean');
$collegeDeanPortalDisplayEmail=(string)($collegeDeanPortalContext['email'] ?? '');
function exclusion_h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
$termQuery=['dean_ay_id'=>(int)$term['ay_id'],'dean_semester'=>(int)$term['semester']];
$listQuery=$termQuery+['search'=>$subjectSearch,'rule_filter'=>$subjectFilter];
$subjectDialogData=[];
foreach ($subjects as $subject) $subjectDialogData[(int)$subject['subject_id']]=[
    'label'=>$subject['label'],'excluded'=>(int)($subject['rule']['excluded'] ?? 0),
    'reason'=>(string)($subject['rule']['reason'] ?? ''),'version'=>(int)($subject['rule']['version'] ?? 0)];
$subjectVisible=static function(array $subject)use($subjectSearch,$subjectFilter):bool {
    if ($subjectFilter==='review' && empty($subject['review_count'])) return false;
    if ($subjectFilter==='excluded' && empty($subject['rule']['excluded'])) return false;
    if ($subjectFilter==='included' && !empty($subject['rule']['excluded'])) return false;
    foreach (preg_split('/\s+/u',$subjectSearch,-1,PREG_SPLIT_NO_EMPTY) as $word) if (mb_stripos($subject['label'],$word,0,'UTF-8')===false) return false;
    return true;
};
$visibleCount=count(array_filter($subjects,$subjectVisible));
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Evaluation Subject Exclusions | College Dean | Synk</title>
<link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
<link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css"><link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css"><link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css"><link rel="stylesheet" href="custom_css.css">
<link rel="stylesheet" href="evaluation-subject-exclusions.css?v=<?= (int)filemtime(__DIR__.'/evaluation-subject-exclusions.css') ?>">
<script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script></head>
<body><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php include 'sidebar.php'; ?><div class="layout-page"><?php include 'navbar.php'; ?><div class="content-wrapper"><main class="container-xxl flex-grow-1 container-p-y" id="evaluationSubjectExclusions">
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h4 class="mb-1">Evaluation Subject Exclusions</h4><p class="text-muted mb-0"><?= exclusion_h($scope['college_name'].' · '.$term['term_text']) ?></p></div><a class="btn btn-outline-secondary align-self-start" href="faculty-evaluations.php?<?= exclusion_h(http_build_query($termQuery)) ?>">Back to Faculty Evaluation</a></div>
<div class="card mb-4"><div class="card-body"><p>Students continue evaluating every linked teacher normally. Exclusions affect only score computation in this college and selected academic term. They apply to the selected subject across its teachers and sections.</p>
<p class="small text-muted">One student response may cover several subjects taught by the same teacher. By default, a combined response with both excluded and included subjects stays counted and appears below for review. Missing historical subject associations also stay counted for review. Submitted answers, comments and completion records are preserved.</p>
<form method="get" class="row g-3 align-items-end"><div class="col-6 col-lg-4"><label class="form-label" for="exclusionYear">Academic Year</label><select class="form-select" name="dean_ay_id" id="exclusionYear"><?php foreach ($years as $year): ?><option value="<?= (int)$year['ay_id'] ?>"<?= (int)$year['ay_id']===(int)$term['ay_id']?' selected':'' ?>><?= exclusion_h($year['ay']) ?></option><?php endforeach; ?></select></div><div class="col-6 col-lg-4"><label class="form-label" for="exclusionSemester">Semester</label><select class="form-select" name="dean_semester" id="exclusionSemester"><?php foreach ([1,2,3] as $semester): ?><option value="<?= $semester ?>"<?= $semester===(int)$term['semester']?' selected':'' ?>><?= exclusion_h(synk_semester_label($semester)) ?></option><?php endforeach; ?></select></div><div class="col-12 col-lg-4"><button class="btn btn-primary">Load Subjects</button></div></form></div></div>
<?php if ($error!=='' && $error!==$subjectFormError): ?><div class="alert alert-danger" role="alert" data-subject-page-error><?= exclusion_h($error) ?></div><?php endif; ?>
<?php if ($subjectFormError!=='' && !$selected): ?><div class="alert alert-danger" role="alert" data-subject-page-error><?= exclusion_h($subjectFormError) ?></div><?php endif; ?>
<?php if (isset($_GET['saved']) && !$error): ?><div class="alert alert-success" role="status" data-subject-save-success>Subject exclusion saved. Faculty scores now use the updated eligibility rule.</div><?php endif; ?>
<?php if (!$ready): ?><div class="alert alert-warning">Subject exclusion storage is not prepared. Apply the evaluation subject exclusions update before managing this list.</div><?php else: ?>
<script type="application/json" id="subjectExclusionData"><?= json_encode($subjectDialogData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<div class="card mb-4"><div class="card-body"><h5>Subjects in this college and term</h5><p class="small text-muted">Counts are submitted teacher responses linked to each subject. A combined response can appear under more than one subject; do not add these rows together. All questionnaire versions follow the same subject rule and retain separate scores.</p>
<form method="get" class="exclusion-search" id="subjectExclusionSearchForm" role="search">
<?php foreach ($termQuery as $name=>$value): ?><input type="hidden" name="<?= $name ?>" value="<?= (int)$value ?>"><?php endforeach; ?>
<div><label for="subjectExclusionSearch" class="form-label">Search subjects</label><input type="search" class="form-control" id="subjectExclusionSearch" name="search" maxlength="150" placeholder="Subject code or title" value="<?= exclusion_h($subjectSearch) ?>" aria-controls="subjectExclusionList"></div>
<div><label for="subjectExclusionFilter" class="form-label">Show</label><select class="form-select" id="subjectExclusionFilter" name="rule_filter" aria-controls="subjectExclusionList"><?php foreach (['all'=>'All subjects','included'=>'Included','excluded'=>'Excluded','review'=>'Needs review'] as $value=>$label): ?><option value="<?= $value ?>"<?= $subjectFilter===$value?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
<button type="button" class="btn btn-outline-secondary" id="subjectExclusionClear">Clear</button><noscript><button class="btn btn-primary">Search</button></noscript></form>
<p class="small text-muted mt-3" id="subjectExclusionCount" role="status" aria-live="polite"><?= $visibleCount ?> of <?= count($subjects) ?> subjects</p>
<div class="exclusion-list-wrap"><table class="table exclusion-table mb-0" id="subjectExclusionList"><thead><tr><th scope="col">Subject</th><th scope="col">Rule</th><th scope="col">Included in Score</th><th scope="col">Excluded Responses</th><th scope="col">Needs Review (Counted)</th><th scope="col">Action</th></tr></thead><tbody>
<?php foreach ($subjects as $subject): ?><tr data-subject-row data-subject-label="<?= exclusion_h($subject['label']) ?>" data-rule="<?= !empty($subject['rule']['excluded'])?'excluded':'included' ?>" data-review-count="<?= (int)($subject['review_count'] ?? 0) ?>"<?= !$subjectVisible($subject)?' hidden':'' ?>><td data-label="Subject"><?= exclusion_h($subject['label']) ?></td><td data-label="Rule"><span class="badge <?= !empty($subject['rule']['excluded'])?'bg-label-warning':'bg-label-success' ?>"><?= !empty($subject['rule']['excluded'])?'Excluded':'Included' ?></span></td><td data-label="Included in Score"><?= (int)($subject['included_count'] ?? 0)+(int)($subject['review_count'] ?? 0) ?></td><td data-label="Excluded Responses"><?= (int)($subject['excluded_count'] ?? 0) ?></td><td data-label="Needs Review (Counted)"><?= (int)($subject['review_count'] ?? 0) ?></td><td data-label="Action"><a class="btn btn-sm btn-outline-primary" data-manage-subject="<?= (int)$subject['subject_id'] ?>" href="?<?= exclusion_h(http_build_query($listQuery+['subject_id'=>$subject['subject_id']])) ?>#subjectExclusionForm" aria-label="Manage <?= exclusion_h($subject['label']) ?>">Manage</a></td></tr><?php endforeach; ?>
</tbody></table></div><p class="exclusion-empty text-muted" id="subjectExclusionNoMatches"<?= $visibleCount?' hidden':'' ?>><?= $subjects?'No subjects match your search and filter.':'No linked subjects are recorded for this college and term.' ?></p></div></div>
<?php if ($review): ?><section class="card mb-4"><div class="card-body"><h5>Combined or unverified responses needing review</h5><p class="small text-muted">These responses remain counted. Review the subject rules above; do not assume that one combined rating can be divided between subjects. Unknown subject associations cannot be automatically excluded.</p><div class="exclusion-list-wrap"><table class="table exclusion-table"><thead><tr><th scope="col">Faculty</th><th scope="col">Original Subjects</th><th scope="col">Responses</th></tr></thead><tbody><?php foreach ($review as $item): ?><tr><td data-label="Faculty"><?= exclusion_h($item['faculty']) ?></td><td data-label="Original Subjects"><?= exclusion_h($item['subjects']) ?></td><td data-label="Responses"><?= (int)$item['count'] ?></td></tr><?php endforeach; ?></tbody></table></div></div></section><?php endif; ?>
<details class="card mb-4"><summary class="card-body">Recent exclusion changes</summary><div class="card-body pt-0"><?php foreach ($audit as $item): $change=json_decode($item['new_value'],true) ?: []; ?><div class="border-top pt-2 mb-2"><strong><?= exclusion_h($subjects[(int)$item['subject_id']]['label'] ?? 'Subject #'.$item['subject_id']) ?></strong>: <?= !empty($change['excluded'])?'Excluded':'Restored' ?><small class="d-block text-muted"><?= exclusion_h(($item['username'] ?? 'Dean').' · '.$item['changed_at']) ?></small><p class="mb-0"><?= exclusion_h($change['reason'] ?? '') ?></p></div><?php endforeach; ?><?php if (!$audit): ?><p class="text-muted mb-0">No exclusion changes recorded for this term.</p><?php endif; ?></div></details>
<?php endif; ?>
</main><?php include '../footer.php'; ?><div class="content-backdrop fade"></div></div></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<?php if ($ready): $rule=$selected['rule'] ?? []; ?>
<div class="modal fade exclusion-modal" id="subjectExclusionModal" tabindex="-1" aria-labelledby="subjectExclusionDialogTitle" aria-describedby="subjectExclusionContext" data-bs-backdrop="static" data-open="<?= $selected && (!isset($_GET['saved']) || $subjectFormError!=='')?'1':'0' ?>">
<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="subjectExclusionForm" method="post" action="?<?= exclusion_h(http_build_query($listQuery+['subject_id'=>$selectedId])) ?>">
<div class="modal-header"><div><h2 class="modal-title fs-5" id="subjectExclusionDialogTitle">Manage subject</h2><p class="mb-0 mt-2 fw-semibold" id="subjectExclusionSubject"><?= exclusion_h($selected['label'] ?? '') ?></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close subject management"></button></div>
<div class="modal-body"><p class="small text-muted" id="subjectExclusionContext"><?= exclusion_h($scope['college_name'].' · '.$term['term_text']) ?></p>
<div id="subjectExclusionFormError" class="alert alert-danger" role="alert" data-subject-form-error<?= $subjectFormError===''?' hidden':'' ?>><?= exclusion_h($subjectFormError) ?></div>
<button type="button" class="btn btn-outline-primary mb-3" id="subjectExclusionReload" hidden>Reload latest rule</button>
<?php foreach (['csrf_token'=>$_SESSION['csrf_token'],'college_id'=>$scope['college_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'subject_id'=>$selectedId,'version'=>(int)($rule['version'] ?? 0)] as $name=>$value): ?><input type="hidden" name="<?= $name ?>" value="<?= exclusion_h($value) ?>"><?php endforeach; ?>
<div class="mb-3"><label for="subjectExclusionStatus" class="form-label">Score eligibility</label><select name="excluded" id="subjectExclusionStatus" class="form-select"><option value="0">Include / restore eligibility</option><option value="1"<?= !empty($rule['excluded'])?' selected':'' ?>>Exclude from faculty scores</option></select></div>
<p class="small text-muted">Combined evaluations covering both included and excluded subjects remain counted and are flagged for Dean review.</p>
<div><label class="form-label" for="subjectExclusionReason">Reason for this change (Dean only)</label><textarea class="form-control" id="subjectExclusionReason" name="reason" rows="4" maxlength="1000" required><?= exclusion_h(is_string($_POST['reason'] ?? null)?$_POST['reason']:($rule['reason'] ?? '')) ?></textarea></div></div>
<div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="subjectExclusionSave">Save Subject Rule</button></div>
</form></div></div>
<?php if ($selected): ?><noscript><style>#subjectExclusionModal{display:block;position:static;opacity:1}#subjectExclusionModal .modal-dialog{margin:1rem auto;transform:none}</style></noscript><?php endif; ?>
<?php endif; ?>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<script src="evaluation-subject-exclusions.js?v=<?= (int)filemtime(__DIR__.'/evaluation-subject-exclusions.js') ?>"></script>
</body></html>
