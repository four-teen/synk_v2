<?php
session_start();
ob_start();
require_once __DIR__ . '/../backend/db.php';
require_once '../backend/professor_workload_view_helper.php';
require_once '../backend/professor_workload_students_helper.php';
synk_professor_require_login($conn);
header('Cache-Control: no-store, private');

$portal = synk_professor_resolve_portal_context($conn);
$facultyId = (int)$portal['faculty_id'];
$facultyIsLinked = !empty($portal['faculty_is_linked']);
$currentTerm = synk_fetch_current_academic_term($conn);
$availableTerms = synk_professor_workload_available_terms($conn, $facultyId);
$requestedTerm = synk_professor_workload_query_term($_GET['term'] ?? null);
$navigation = synk_professor_workload_navigation(
    $availableTerms, $currentTerm,
    $requestedTerm['ay_id'] ?? synk_professor_workload_query_integer($_GET['ay_id'] ?? null) ?? 0,
    $requestedTerm['semester'] ?? synk_professor_workload_query_integer($_GET['semester'] ?? null)
);
$selectedAyId = $navigation['ay_id'];
$selectedSemester = $navigation['semester'];
$selectedYear = $navigation['selected_year'];
$workloadRows = $selectedAyId > 0 && $selectedSemester > 0
    ? synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyId, $selectedAyId, $selectedSemester)
    : [];
$totalEnrollments = array_sum(array_column($workloadRows, 'student_count'));
$studentActivity = synk_professor_workload_student_summary($conn, $workloadRows, $selectedAyId, $selectedSemester);
foreach ($workloadRows as &$workloadRow) {
    $workloadRow['activity'] = $studentActivity['classes'][$workloadRow['offering_id']] ?? ['active'=>0, 'inactive'=>0, 'students'=>0];
}
unset($workloadRow);
$semesterGroups = synk_professor_group_subject_rows_by_semester($workloadRows);
$selectedTermLabel = $navigation['selected_term']['label'] ?? 'No assigned workload';
$professorPortalDisplayName = $portal['faculty_name'];
$professorPortalDisplayEmail = $portal['email'];
$professorPortalFacultyStatusLabel = !$facultyIsLinked ? 'Needs faculty link'
    : (!empty($portal['faculty_is_active']) ? 'Faculty linked' : 'Inactive faculty record');
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Professor Workload | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?php echo filemtime(__DIR__ . '/custom_css.css'); ?>">
  <link rel="stylesheet" href="workload.css?v=<?php echo filemtime(__DIR__ . '/workload.css'); ?>">
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
</head>
<body>
  <div class="layout-wrapper layout-content-navbar">
    <div class="layout-container">
      <?php include 'sidebar.php'; ?>
      <div class="layout-page">
        <?php include 'navbar.php'; ?>
        <div class="content-wrapper">
          <main class="container-xxl flex-grow-1 container-p-y workload-page">
            <header class="workload-heading">
              <div><span class="workload-eyebrow">PROFESSOR PORTAL</span><h1>My workload</h1><p>Your classes, meeting times, and rooms in one place.</p></div>
              <a href="index.php" class="btn btn-outline-secondary"><i class="bx bx-arrow-back" aria-hidden="true"></i>Dashboard</a>
            </header>
            <?php if (!$facultyIsLinked || empty($portal['faculty_is_active'])): ?>
              <div class="alert alert-warning" role="status"><?php echo !$facultyIsLinked ? 'Ask your administrator to link your faculty record so your teaching assignments can appear.' : 'Your faculty record is inactive. Contact your administrator to review your access.'; ?></div>
            <?php endif; ?>

            <section class="card workload-controls" aria-label="Workload filters and totals">
              <div class="workload-controls-top">
                <div>
                  <div class="workload-year-label"><label for="workload-term">Academic year / semester</label><?php if ($navigation['selected_term'] && $navigation['selected_key'] === $navigation['latest_key']): ?><span class="workload-latest">Latest term</span><?php endif; ?></div>
                  <form method="get" id="workload-term-form" class="workload-year-form">
                    <select id="workload-term" name="term" class="form-select" aria-describedby="workload-year-help"<?php echo !$navigation['terms'] ? ' disabled' : ''; ?>>
                      <?php if (!$navigation['terms']): ?><option value="">No assigned workload yet</option><?php endif; ?>
                      <?php foreach ($navigation['terms'] as $term): ?>
                        <option value="<?php echo synk_professor_h($term['term_key']); ?>"<?php echo $term['term_key'] === $navigation['selected_key'] ? ' selected' : ''; ?>><?php echo synk_professor_h($term['label']); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary"<?php echo !$navigation['terms'] ? ' disabled' : ''; ?>>View workload</button>
                  </form>
                  <p id="workload-year-help" class="workload-help">Only terms with classes assigned to you are listed, newest first.
                    <?php if ($navigation['selected_term'] && $navigation['selected_key'] !== $navigation['latest_key']): ?><a href="workload.php?term=<?php echo synk_professor_h($navigation['latest_key']); ?>">Back to latest</a><?php endif; ?>
                  </p>
                </div>
                <dl class="workload-totals" aria-label="Selected period totals">
                  <div><dt>Assigned classes</dt><dd><?php echo count($workloadRows); ?></dd></div>
                  <div><dt>Student enrollments</dt><dd><?php echo (int)$totalEnrollments; ?></dd></div>
                  <div class="workload-total-inactive"><dt>Inactive students</dt><dd id="workload-total-inactive"><?php echo $studentActivity['available'] ? (int)$studentActivity['totals']['inactive'] : '&mdash;'; ?></dd></div>
                  <div class="workload-total-active"><dt>Active students</dt><dd id="workload-total-active"><?php echo $studentActivity['available'] ? (int)$studentActivity['totals']['active'] : '&mdash;'; ?></dd></div>
                </dl>
              </div>
            </section>
            <p class="workload-activity-note">Active students have signed in at least once; inactive students have no successful sign-in. Activity totals count each listed student once in the selected term.</p>
            <?php if (!$studentActivity['available']): ?><div class="alert alert-info" role="status">Student sign-in history is currently unavailable. Activity counts will appear when tracking is available.</div><?php endif; ?>

            <div class="workload-list-heading">
              <div><h2><?php echo synk_professor_h($selectedTermLabel); ?></h2><p id="workload-result-count" role="status" aria-live="polite"><?php echo count($workloadRows) . (count($workloadRows) === 1 ? ' class' : ' classes'); ?> shown</p></div>
              <?php if ($workloadRows): ?>
                <div class="workload-search" id="workload-search-ui" hidden><label class="visually-hidden" for="workload-search">Search classes</label><i class="bx bx-search" aria-hidden="true"></i><input id="workload-search" type="search" class="form-control" placeholder="Search subject, section, or room" autocomplete="off"></div>
              <?php endif; ?>
            </div>
            <?php if (!$workloadRows): ?>
              <div class="card workload-empty"><i class="bx bx-book-open" aria-hidden="true"></i><h2>No classes to show yet</h2><p><?php echo !$facultyIsLinked ? 'Your assignments will appear after your faculty record is linked.' : 'There are no available teaching assignments for your faculty record. Check again when your workload is assigned.'; ?></p></div>
            <?php else: ?>
              <div id="workload-class-list">
                <?php foreach ($semesterGroups as $group): ?>
                  <section class="workload-semester-group" data-semester-group aria-label="<?php echo synk_professor_h($group['semester_label']); ?> classes">
                    <div class="workload-table-wrap">
                      <table class="workload-table">
                        <caption class="visually-hidden"><?php echo synk_professor_h($selectedYear['label'] . ' / ' . $group['semester_label']); ?> workload</caption>
                        <colgroup><col class="workload-col-subject"><col class="workload-col-enrolled"><col class="workload-col-activity"><col class="workload-col-action"></colgroup>
                        <thead><tr><th scope="col">Subject</th><th scope="col" class="workload-enrolled-cell">Enrolled</th><th scope="col" class="workload-activity-cell">Active / Inactive</th><th scope="col" class="workload-action-cell">Action</th></tr></thead>
                        <?php foreach ($group['subjects'] as $row):
                            $searchText = implode(' ', array_map('strval', [$row['subject_code'], $row['descriptive_title'], $row['section_display'], $row['program_label'], $row['schedule_text'], $row['room_name'], $row['campus_name']]));
                            foreach ($row['meetings'] as $meeting) { $searchText .= ' ' . $meeting['days'] . ' ' . $meeting['type_label']; }
                        ?>
                          <tbody data-workload-class data-workload-key="<?php echo (int)$row['semester'] . '-' . (int)$row['offering_id']; ?>" data-search="<?php echo synk_professor_h($searchText); ?>">
                              <tr>
                                <th scope="row" class="workload-subject-cell"><?php require __DIR__ . '/workload-class-summary.php'; ?></th>
                                <td class="workload-enrolled-cell"><strong><?= (int)$row['student_count'] ?></strong></td>
                                <td class="workload-activity-cell"><span class="workload-activity-ratio" aria-label="<?= $studentActivity['available'] ? (int)$row['activity']['active'] . ' active, ' . (int)$row['activity']['inactive'] . ' inactive' : 'Activity unavailable' ?>"><?= $studentActivity['available'] ? (int)$row['activity']['active'] . ' / ' . (int)$row['activity']['inactive'] : '&mdash;' ?></span><small><?= (int)$row['activity']['students'] ?> listed</small></td>
                                <td class="workload-action-cell"><button type="button" class="btn workload-show-students" data-bs-toggle="modal" data-bs-target="#workload-students-modal" data-offering-id="<?= (int)$row['offering_id'] ?>" data-ay-id="<?= (int)$row['ay_id'] ?>" data-semester="<?= (int)$row['semester'] ?>" data-class-title="<?= synk_professor_h($row['descriptive_title']) ?>" data-class-label="<?= synk_professor_h($row['subject_code'] . ' · ' . $row['section_display']) ?>" aria-label="<?= synk_professor_h('Show Students for ' . $row['subject_code'] . ' ' . $row['section_display']) ?>"><i class="bx bx-group" aria-hidden="true"></i>Show Students</button></td>
                              </tr>
                          </tbody>
                        <?php endforeach; ?>
                      </table>
                    </div>
                    <div class="workload-class-grid">
                      <?php foreach ($group['subjects'] as $row):
                          $searchText = implode(' ', array_map('strval', [$row['subject_code'], $row['descriptive_title'], $row['section_display'], $row['program_label'], $row['schedule_text'], $row['room_name'], $row['campus_name']]));
                          foreach ($row['meetings'] as $meeting) { $searchText .= ' ' . $meeting['days'] . ' ' . $meeting['type_label']; }
                      ?>
                        <article class="card workload-class" data-workload-class data-workload-key="<?php echo (int)$row['semester'] . '-' . (int)$row['offering_id']; ?>" data-search="<?php echo synk_professor_h($searchText); ?>" aria-label="<?php echo synk_professor_h($row['subject_code'] . ' - ' . $row['section_display']); ?>">
                          <?php require __DIR__ . '/workload-class-summary.php'; ?>
                          <div class="workload-card-counts"><span><strong><?= (int)$row['student_count'] ?></strong> Enrolled</span><span><strong><?= $studentActivity['available'] ? (int)$row['activity']['active'] . ' / ' . (int)$row['activity']['inactive'] : '&mdash;' ?></strong> Active / Inactive</span></div>
                          <button type="button" class="btn workload-show-students" data-bs-toggle="modal" data-bs-target="#workload-students-modal" data-offering-id="<?= (int)$row['offering_id'] ?>" data-ay-id="<?= (int)$row['ay_id'] ?>" data-semester="<?= (int)$row['semester'] ?>" data-class-title="<?= synk_professor_h($row['descriptive_title']) ?>" data-class-label="<?= synk_professor_h($row['subject_code'] . ' · ' . $row['section_display']) ?>"><i class="bx bx-group" aria-hidden="true"></i>Show Students</button>
                        </article>
                      <?php endforeach; ?>
                    </div>
                  </section>
                <?php endforeach; ?>
              </div>
              <div class="card workload-empty" id="workload-no-matches" hidden><i class="bx bx-search" aria-hidden="true"></i><h2>No matching classes</h2><p>Try another subject, section, day, or room.</p><button type="button" id="workload-clear-search" class="btn btn-outline-primary">Clear search</button></div>
              <p class="workload-enrollment-note">Enrolled retains the recorded total for each class. Active / Inactive counts use the named student roster, which may differ from that total. A student taking several classes contributes to each class's enrollments.</p>
            <?php endif; ?>
          </main>
          <div class="modal fade workload-students-modal" id="workload-students-modal" tabindex="-1" aria-labelledby="workload-students-title" aria-describedby="workload-students-help" data-roster-url="workload-students.php">
            <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
              <div class="modal-header"><div><span class="workload-eyebrow">CLASS ROSTER</span><h2 class="modal-title" id="workload-students-title">Class students</h2><p id="workload-students-class"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close student list"></button></div>
              <div class="modal-body">
                <p id="workload-students-help">Active students have signed in successfully at least once. Inactive students have no successful sign-in recorded.</p>
                <div id="workload-students-status" role="status" aria-live="polite"></div>
                <button type="button" class="btn btn-outline-primary" id="workload-students-retry" hidden>Try again</button>
                <div id="workload-students-content" hidden>
                  <div class="workload-roster-toolbar"><p id="workload-roster-count"></p><div class="workload-search"><label class="visually-hidden" for="workload-student-search">Search students</label><i class="bx bx-search" aria-hidden="true"></i><input type="search" id="workload-student-search" class="form-control" placeholder="Search name or student number" autocomplete="off"></div></div>
                  <div class="workload-roster-groups">
                    <section class="workload-roster-group is-inactive" aria-labelledby="workload-roster-inactive-title"><h3 id="workload-roster-inactive-title"><span><i class="bx bx-user" aria-hidden="true"></i>Inactive students</span><span id="workload-roster-inactive-count">0</span></h3><p>No successful sign-in recorded</p><ul id="workload-roster-inactive"></ul><p class="workload-roster-empty" id="workload-roster-inactive-empty" hidden></p></section>
                    <section class="workload-roster-group is-active" aria-labelledby="workload-roster-active-title"><h3 id="workload-roster-active-title"><span><i class="bx bx-user-check" aria-hidden="true"></i>Active students</span><span id="workload-roster-active-count">0</span></h3><p>Signed in to the Student portal</p><ul id="workload-roster-active"></ul><p class="workload-roster-empty" id="workload-roster-active-empty" hidden></p></section>
                  </div>
                </div>
              </div>
              <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
            </div></div>
          </div>
          <?php include '../footer.php'; ?>
        </div>
      </div>
    </div>
    <div class="layout-overlay layout-menu-toggle"></div>
  </div>
  <script src="../assets/vendor/libs/jquery/jquery.js"></script>
  <script src="../assets/vendor/libs/popper/popper.js"></script>
  <script src="../assets/vendor/js/bootstrap.js"></script>
  <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
  <script src="../assets/vendor/js/menu.js"></script>
  <script src="../assets/js/main.js"></script>
  <script src="workload.js?v=<?php echo filemtime(__DIR__ . '/workload.js'); ?>"></script>
</body>
</html>
