<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/professor_evaluations_helper.php';
header('Cache-Control: no-store, private');
header('Vary: Cookie');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET') { http_response_code(405); header('Allow: GET'); exit; }
synk_professor_require_login($conn);
$evaluationError=''; $evaluationOptions=[]; $evaluationSelected=null; $evaluationGroups=[];
$professorPortalDisplayName='Professor'; $professorPortalDisplayEmail=(string)($_SESSION['email'] ?? '');
try {
    $evaluationPortal=synk_professor_evaluation_identity($conn);
    $professorPortalDisplayName=$evaluationPortal['faculty_name']; $professorPortalDisplayEmail=$evaluationPortal['email'];
    foreach (['college_id','ay_id','semester'] as $field) if (isset($_GET[$field]) && (!is_scalar($_GET[$field]) || filter_var($_GET[$field],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]])===false)) throw new DomainException('Choose a valid college and academic term.');
    $evaluationOptions=synk_professor_evaluation_options($conn,(int)$evaluationPortal['faculty_id']);
    $explicit=isset($_GET['college_id']) || isset($_GET['ay_id']) || isset($_GET['semester']);
    foreach ($evaluationOptions as $option) {
        if (!$explicit || ((int)$option['college_id']===(int)($_GET['college_id'] ?? 0) && (int)$option['ay_id']===(int)($_GET['ay_id'] ?? 0) && (int)$option['semester']===(int)($_GET['semester'] ?? 0))) { $evaluationSelected=$option; break; }
    }
    if ($explicit && !$evaluationSelected) { http_response_code(404); $evaluationError='These evaluation results are unavailable or have not been released by the college.'; }
    if ($evaluationSelected) $evaluationGroups=synk_professor_evaluation_results($conn,$evaluationSelected);
} catch (DomainException $error) { http_response_code(403); $evaluationError=$error->getMessage(); }
catch (Throwable $error) { http_response_code(500); error_log('Professor evaluation results: '.$error->getMessage()); $evaluationError='Unable to load evaluation results. Please try again.'; }
function synk_professor_evaluation_h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Evaluation Results | Professor | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php include 'sidebar.php'; ?>
<div class="layout-page"><?php include 'navbar.php'; ?><div class="content-wrapper">
<main class="container-xxl flex-grow-1 container-p-y" id="professorEvaluationResults">
  <div class="mb-4"><h4 class="mb-2">My evaluation results</h4><p class="text-muted mb-0">View student evaluation results released by each college. Results are kept separately by college, academic term, and questionnaire.</p></div>
  <?php if ($evaluationError!==''): ?><div class="alert alert-warning" role="alert"><?php echo synk_professor_evaluation_h($evaluationError); ?></div><?php endif; ?>
  <?php if (!$evaluationError && !$evaluationOptions): ?>
    <div class="card"><div class="card-body p-4"><h5>No released results yet</h5><p class="text-muted mb-0">Your college will make evaluation results available here when they are released.</p></div></div>
  <?php elseif (!$evaluationError): ?>
    <div class="card mb-4"><div class="card-body p-4"><h5>Released evaluations</h5><nav class="d-flex flex-wrap gap-2" aria-label="Released college and academic term">
      <?php foreach ($evaluationOptions as $option): $active=(int)$option['college_id']===(int)$evaluationSelected['college_id'] && (int)$option['ay_id']===(int)$evaluationSelected['ay_id'] && (int)$option['semester']===(int)$evaluationSelected['semester']; ?>
      <a class="btn btn-sm <?php echo $active?'btn-primary':'btn-outline-secondary'; ?>"<?php echo $active?' aria-current="page"':''; ?> href="faculty-evaluations.php?<?php echo synk_professor_evaluation_h(http_build_query(['college_id'=>$option['college_id'],'ay_id'=>$option['ay_id'],'semester'=>$option['semester']])); ?>"><?php echo synk_professor_evaluation_h($option['college_name'].' · '.$option['ay_label'].' · '.synk_semester_label((int)$option['semester'])); ?></a>
      <?php endforeach; ?>
    </nav></div></div>
    <?php if (!$evaluationGroups): ?><div class="alert alert-info" role="status">No eligible evaluation results are currently available. Reload to check the college's latest release status.</div><?php endif; ?>
    <?php foreach ($evaluationGroups as $group): ?>
    <article class="card mb-4" data-professor-evaluation-instrument="<?php echo synk_professor_evaluation_h($group['instrument_version']); ?>"><div class="card-body p-4">
      <h5><?php echo synk_professor_evaluation_h($group['instrument']['label']); ?></h5>
      <div class="d-flex flex-wrap gap-2 mb-3"><span class="badge bg-label-primary"><?php echo $group['response_count']; ?> evaluations included in score</span><?php if ($group['score']): ?><span class="badge bg-label-success"><?php echo number_format($group['score']['percentage'],2); ?>% normalized score</span><span class="badge bg-label-info">Mean <?php echo number_format($group['score']['mean'],2); ?> / 5.00</span><?php else: ?><span class="text-muted">No eligible evaluations</span><?php endif; ?></div>
      <p class="small text-muted">Normalized score = total rating points ÷ maximum possible points × 100. Questions have equal weight. Only final results are rounded.</p>
      <details class="mb-4"><summary class="text-primary">Rating scale</summary><ul class="mt-2 mb-0"><?php foreach (synk_student_faculty_evaluation_scale($group['instrument_version']) as $rating=>$definition): ?><li><strong><?php echo $rating; ?> · <?php echo synk_professor_evaluation_h($definition['label']); ?></strong>: <?php echo synk_professor_evaluation_h($definition['description']); ?></li><?php endforeach; ?></ul></details>
      <?php foreach ($group['categories'] as $category): ?>
      <section class="mb-4"><h6><?php echo synk_professor_evaluation_h($category['title']); ?></h6><p class="small text-muted">Mean <?php echo number_format($category['score']['mean'],2); ?> / 5.00 · <?php echo number_format($category['score']['percentage'],2); ?>% of maximum category points</p>
        <div class="table-responsive"><table class="table" style="min-width:520px"><thead><tr><th>Statement</th><th>Mean</th><th>Normalized score</th><th>Responses</th></tr></thead><tbody>
        <?php foreach ($category['questions'] as $question): ?><tr><td style="white-space:normal"><?php echo $question['order']; ?>. <?php echo synk_professor_evaluation_h($question['text']); ?></td><td><?php echo number_format($question['score']['mean'],2); ?></td><td><?php echo number_format($question['score']['percentage'],2); ?>%</td><td><?php echo $question['response_count']; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
      </section><?php endforeach; ?>
      <h6>Student comments</h6>
      <?php if ($group['submitted_count']>$group['response_count']): ?><p class="small text-muted">Submitted comments are retained independently of score eligibility.</p><?php endif; ?>
      <?php foreach ($group['comments'] as $comment): ?><p class="border-top pt-3"><?php echo nl2br(synk_professor_evaluation_h($comment)); ?></p><?php endforeach; ?>
      <?php if (!$group['comments']): ?><p class="text-muted mb-0">No comments were submitted.</p><?php endif; ?>
    </div></article><?php endforeach; ?>
  <?php endif; ?>
</main><?php include '../footer.php'; ?><div class="content-backdrop fade"></div>
</div></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
</body></html>
