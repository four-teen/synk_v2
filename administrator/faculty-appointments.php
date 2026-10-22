<?php
session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/faculty_college_appointment_helper.php';
require_once __DIR__ . '/../backend/college_dean_term_helper.php';
try { $appointmentAdmin = synk_faculty_appointment_require_admin($conn); }
catch (DomainException $exception) { http_response_code(403); exit('Administrator access is required.'); }
header('Cache-Control: no-store, private');
try { synk_faculty_college_appointments_ensure($conn); }
catch (Throwable $exception) { error_log('College appointments: ' . $exception->getMessage()); http_response_code(503); exit('Leadership appointments are temporarily unavailable.'); }
if (empty($_SESSION['faculty_appointment_csrf'])) $_SESSION['faculty_appointment_csrf'] = bin2hex(random_bytes(32));
$h = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
$colleges = $conn->query("SELECT c.college_id,c.college_code,c.college_name,cp.campus_name
    FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id
    WHERE c.status='active' AND cp.status='active' ORDER BY cp.campus_name,c.college_name")->fetch_all(MYSQLI_ASSOC);
$years = synk_dean_academic_years($conn);
if (!$colleges || !$years) { http_response_code(503); exit('An active college and academic year are required.'); }
$current = synk_fetch_current_academic_term($conn);
$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
try {
    $collegeId = synk_faculty_appointment_integer($input['college_id'] ?? (string)($appointmentAdmin['college_dean_college_id'] ?: $colleges[0]['college_id']));
    if (!in_array($collegeId, array_map('intval', array_column($colleges, 'college_id')), true)) throw new DomainException('Choose an active college.', 400);
    $term = synk_dean_term_from_ids($conn, $input['ay_id'] ?? (string)($current['ay_id'] ?: $years[0]['ay_id']), $input['semester'] ?? (string)($current['semester'] ?: 1));
} catch (DomainException $exception) { http_response_code(400); exit($h($exception->getMessage())); }
$query = ['college_id'=>$collegeId, 'ay_id'=>$term['ay_id'], 'semester'=>$term['semester']];
$baseUrl = 'faculty-appointments.php?' . http_build_query($query);
$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        synk_faculty_appointment_save($conn, $_POST);
        header('Location: ' . $baseUrl . '&saved=1', true, 303);
        exit;
    } catch (DomainException $exception) { http_response_code($exception->getCode() ?: 422); $error = $exception->getMessage(); }
    catch (Throwable $exception) { error_log('College appointment save: ' . $exception->getMessage()); http_response_code(500); $error = 'The appointment could not be saved. Please try again.'; }
}
$appointments = synk_faculty_college_appointments($conn, $collegeId, $term);
$edit = null;
try {
    $editId = synk_faculty_appointment_integer($input['edit'] ?? $input['appointment_id'] ?? '0', true);
    if ($editId) {
        foreach ($appointments as $row) if ((int)$row['appointment_id'] === $editId) $edit = $row;
        if (!$edit) throw new DomainException('This appointment is unavailable in the selected college and term.', 404);
    }
} catch (DomainException $exception) { http_response_code($exception->getCode()); exit($h($exception->getMessage())); }
$stmt = $conn->prepare("SELECT program_id,program_code,program_name,COALESCE(major,'') AS major FROM tbl_program WHERE college_id=? AND status='active' ORDER BY program_code,major");
$stmt->bind_param('i', $collegeId); $stmt->execute(); $programs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
$faculties = $conn->query("SELECT f.faculty_id,f.first_name,f.middle_name,f.last_name,f.ext_name,c.college_code,cp.campus_name
    FROM tbl_faculty f LEFT JOIN tbl_college c ON c.college_id=f.college_id LEFT JOIN tbl_campus cp ON cp.campus_id=f.campus_id
    WHERE f.status IN ('active','on_leave') ORDER BY f.last_name,f.first_name,f.faculty_id")->fetch_all(MYSQLI_ASSOC);
$selectedFaculty = $edit ? (int)$edit['faculty_id'] : 0;
$selectedRole = $edit['appointment_role'] ?? 'dean';
$selectedPrograms = $edit && $selectedRole === 'chair' ? [(int)$edit['program_id']] : [];
if ($error !== '' && is_string($_POST['faculty_id'] ?? null) && ctype_digit($_POST['faculty_id'])) $selectedFaculty = (int)$_POST['faculty_id'];
if (!$edit && $error !== '') {
    if (in_array($_POST['appointment_role'] ?? '', ['dean','chair'], true)) $selectedRole = $_POST['appointment_role'];
    $postedPrograms = $_POST['program_ids'] ?? [$_POST['program_id'] ?? '0'];
    if (is_array($postedPrograms)) {
        foreach ($postedPrograms as $programId) {
            if ((is_string($programId) || is_int($programId)) && ctype_digit((string)$programId)) $selectedPrograms[] = (int)$programId;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Leadership appointments | Synk</title>
  <link rel="icon" href="../assets/img/favicon/synk-icon.png" type="image/png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="../program-chair/vendor/select2/select2.min.css">
  <link rel="stylesheet" href="faculty-appointments.css?v=<?= filemtime(__DIR__ . '/faculty-appointments.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php include __DIR__ . '/sidebar.php'; ?><div class="layout-page"><?php include __DIR__ . '/navbar.php'; ?>
<div class="content-wrapper"><main class="container-xxl flex-grow-1 container-p-y faculty-appointments">
  <header class="appointment-heading"><div><p class="text-muted small text-uppercase mb-1">Faculty / College leadership</p><h1 class="h4">Leadership appointments</h1><p class="text-muted">Assign the Dean and Program Chairs for each college and academic term. Their profiles appear under leadership in that college.</p></div><a class="btn btn-outline-secondary" href="manage-faculty.php">Faculty Masterlist</a></header>
  <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php elseif (isset($_GET['saved'])): ?><div class="alert alert-success" role="status">Leadership appointment saved for <?= $h($term['term_text']) ?>.</div><?php endif; ?>
  <section class="card mb-4"><div class="card-body"><form method="get" class="appointment-filters" id="appointment-filters">
    <div><label class="form-label" for="appointment-college">College</label><select class="form-select" id="appointment-college" name="college_id"><?php foreach ($colleges as $college): ?><option value="<?= (int)$college['college_id'] ?>"<?= (int)$college['college_id']===$collegeId?' selected':'' ?>><?= $h($college['college_code'] . ' - ' . $college['college_name'] . ' / ' . $college['campus_name']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="appointment-year">Academic year</label><select class="form-select" id="appointment-year" name="ay_id"><?php foreach ($years as $year): ?><option value="<?= $year['ay_id'] ?>"<?= $year['ay_id']===$term['ay_id']?' selected':'' ?>><?= $h($year['ay']) ?></option><?php endforeach; ?></select></div>
    <div><label class="form-label" for="appointment-semester">Semester</label><select class="form-select" id="appointment-semester" name="semester"><?php foreach ([1,2,3] as $semester): ?><option value="<?= $semester ?>"<?= $semester===$term['semester']?' selected':'' ?>><?= synk_semester_label($semester) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary" type="submit" id="appointment-view">View appointments</button>
  </form></div></section>
  <p class="text-muted small">Viewing <?= $h($term['term_text']) ?><?= $term['is_current'] ? ' (current term)' : '' ?>. Changes apply to this term only. Portal access is managed separately in User Accounts.</p>
  <div class="appointment-layout">
    <section class="card"><div class="card-body"><h2 class="h5"><?= $edit ? 'Replace or restore appointment' : 'Add appointment' ?></h2>
      <p class="text-muted small">Choose any active faculty member, including someone from another home college. A Program Chair can lead multiple programs; each program has one chair per term.</p>
      <form method="post" id="faculty-appointment-form">
        <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['faculty_appointment_csrf']) ?>"><input type="hidden" name="action" value="save">
        <?php foreach ($query as $key=>$value): ?><input type="hidden" name="<?= $key ?>" value="<?= $value ?>"><?php endforeach; ?>
        <input type="hidden" name="appointment_id" value="<?= (int)($edit['appointment_id'] ?? 0) ?>"><input type="hidden" name="revision" value="<?= (int)($edit['revision'] ?? 0) ?>">
        <div class="mb-3"><label class="form-label" for="appointment-role">Leadership post</label><select class="form-select" id="appointment-role" name="appointment_role"<?= $edit?' disabled':'' ?>><option value="dean"<?= $selectedRole==='dean'?' selected':'' ?>>College Dean</option><option value="chair"<?= $selectedRole==='chair'?' selected':'' ?>>Program Chair</option></select></div>
        <div class="mb-3" id="appointment-program-field"><label class="form-label" for="appointment-program">Programs</label><select class="form-select" id="appointment-program" name="program_ids[]" multiple aria-describedby="appointment-program-help"<?= $edit?' disabled':'' ?>><?php foreach ($programs as $program): ?><option value="<?= (int)$program['program_id'] ?>"<?= in_array((int)$program['program_id'], $selectedPrograms, true)?' selected':'' ?>><?= $h($program['program_code'] . ($program['major'] !== '' ? ' - ' . $program['major'] : '') . ' / ' . $program['program_name']) ?></option><?php endforeach; ?></select><div class="form-text" id="appointment-program-help"><?= $edit ? 'This change applies to this program only. Cancel to add appointments for more programs.' : 'Select one or more programs for the same faculty member. Each is recorded separately so you can replace or end its appointment individually.' ?></div></div>
        <div class="mb-3"><label class="form-label" for="appointment-faculty">Faculty member</label><select class="form-select" id="appointment-faculty" name="faculty_id" required><option value="">Choose a faculty member</option><?php foreach ($faculties as $faculty): $name=trim(implode(' ',array_filter([$faculty['last_name'] . ',',$faculty['first_name'],$faculty['middle_name'],$faculty['ext_name']]))); ?><option value="<?= (int)$faculty['faculty_id'] ?>"<?= (int)$faculty['faculty_id']===$selectedFaculty?' selected':'' ?>><?= $h($name . ' [#' . $faculty['faculty_id'] . ']' . ($faculty['college_code'] ? ' / ' . $faculty['college_code'] : '') . ($faculty['campus_name'] ? ' / ' . $faculty['campus_name'] : '')) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-primary" type="submit">Save appointment</button><?php if ($edit): ?> <a class="btn btn-outline-secondary" href="<?= $h($baseUrl) ?>">Cancel</a><?php endif; ?>
      </form>
    </div></section>
    <section class="card"><div class="card-body"><h2 class="h5">Recorded appointments</h2>
      <p class="text-muted small">End for this term withdraws only that post in the selected college and term. The record remains available to restore; faculty records, login access, and other terms are unchanged.</p>
      <?php if (!$appointments): ?><p class="text-muted mb-0">No leadership appointments have been recorded for this college and term.</p><?php endif; ?>
      <div class="appointment-list"><?php foreach ($appointments as $appointment): ?>
        <article class="appointment-record">
          <div><span class="badge bg-label-<?= $appointment['status']==='active'?'primary':'secondary' ?>"><?= $appointment['status']==='active'?'Appointed':'Ended for this term' ?></span>
            <h3 class="h6 mt-2 mb-1"><?= $appointment['appointment_role']==='dean'?'College Dean':'Program Chair - ' . $h(($appointment['program_code'] ?: $appointment['program_name']) . ($appointment['major'] !== '' ? ' / ' . $appointment['major'] : '')) ?></h3>
            <p class="mb-1"><?= $h(trim(implode(' ',array_filter([$appointment['first_name'],$appointment['middle_name'],$appointment['last_name'],$appointment['ext_name']])))) ?></p>
            <small class="text-muted">Last updated <?= $h($appointment['updated_at']) ?></small>
          </div>
          <div class="appointment-actions"><a class="btn btn-sm btn-outline-primary" href="<?= $h($baseUrl . '&edit=' . $appointment['appointment_id']) ?>"><?= $appointment['status']==='active'?'Replace':'Restore' ?></a>
            <?php if ($appointment['status']==='active'): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $h($_SESSION['faculty_appointment_csrf']) ?>"><input type="hidden" name="action" value="end"><?php foreach ($query as $key=>$value): ?><input type="hidden" name="<?= $key ?>" value="<?= $value ?>"><?php endforeach; ?><input type="hidden" name="appointment_id" value="<?= (int)$appointment['appointment_id'] ?>"><input type="hidden" name="revision" value="<?= (int)$appointment['revision'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit">End for this term</button></form><?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?></div>
    </div></section>
  </div>
</main><?php include __DIR__ . '/../footer.php'; ?></div></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<script src="../program-chair/vendor/select2/select2.min.js"></script><script src="faculty-appointments.js?v=<?= filemtime(__DIR__ . '/faculty-appointments.js') ?>"></script>
</body></html>
