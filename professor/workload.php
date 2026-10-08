<?php
session_start();
ob_start();
require '../backend/db.php';
require_once '../backend/professor_workload_view_helper.php';
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
                </dl>
              </div>
            </section>

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
                        <colgroup><col class="workload-col-subject"><col class="workload-col-section"><col class="workload-col-type"><col class="workload-col-schedule"><col class="workload-col-room"><col class="workload-col-enrolled"></colgroup>
                        <thead><tr><th scope="col">Subject</th><th scope="col">Section / year</th><th scope="col">Type</th><th scope="col">Days &amp; time</th><th scope="col">Room</th><th scope="col" class="workload-enrolled-cell">Enrolled</th></tr></thead>
                        <?php foreach ($group['subjects'] as $row):
                            $searchText = implode(' ', array_map('strval', [$row['subject_code'], $row['descriptive_title'], $row['section_display'], $row['program_label'], $row['schedule_text'], $row['room_name'], $row['campus_name']]));
                            foreach ($row['meetings'] as $meeting) { $searchText .= ' ' . $meeting['days'] . ' ' . $meeting['type_label']; }
                            $meetingCount = count($row['meetings']);
                        ?>
                          <tbody data-workload-class data-workload-key="<?php echo (int)$row['semester'] . '-' . (int)$row['offering_id']; ?>" data-search="<?php echo synk_professor_h($searchText); ?>">
                            <?php foreach ($row['meetings'] as $meetingIndex => $meeting): ?>
                              <tr>
                                <?php if ($meetingIndex === 0): ?>
                                  <th scope="rowgroup" rowspan="<?php echo $meetingCount; ?>" class="workload-subject-cell">
                                    <strong class="workload-table-code"><?php echo synk_professor_h($row['subject_code'] ?: 'Subject'); ?></strong>
                                    <span class="workload-table-title"><?php echo synk_professor_h($row['descriptive_title'] ?: 'Subject title unavailable'); ?></span>
                                    <details class="workload-class-details"><summary>Class details<i class="bx bx-chevron-down" aria-hidden="true"></i></summary><dl>
                                      <div><dt>Program</dt><dd><?php echo synk_professor_h($row['program_label'] ?: 'Not assigned'); ?></dd></div>
                                      <div><dt>College</dt><dd><?php echo synk_professor_h($row['college_name'] ?: 'Not assigned'); ?></dd></div>
                                      <div><dt>Campus</dt><dd><?php echo synk_professor_h($row['campus_name'] ?: 'Not assigned'); ?></dd></div>
                                    </dl></details>
                                  </th>
                                  <td rowspan="<?php echo $meetingCount; ?>"><span class="workload-section"><?php echo synk_professor_h($row['section_display'] ?: 'Section pending'); ?></span><span class="workload-table-year"><?php echo (int)$row['year_level'] > 0 ? 'Year ' . (int)$row['year_level'] : 'Year level pending'; ?></span></td>
                                <?php endif; ?>
                                <td><span class="workload-type<?php echo $meeting['type'] === 'LAB' ? ' is-lab' : ''; ?>"><?php echo synk_professor_h($meeting['type_label']); ?></span></td>
                                <td><strong class="workload-table-days"><?php echo synk_professor_h($meeting['days'] ?: 'Days to be arranged'); ?></strong><span class="workload-table-time"><?php echo synk_professor_h($meeting['time'] ?: 'Time to be arranged'); ?></span></td>
                                <td class="workload-table-room"><?php echo synk_professor_h($meeting['room'] ?: 'Room not assigned'); ?></td>
                                <?php if ($meetingIndex === 0): ?><td rowspan="<?php echo $meetingCount; ?>" class="workload-enrolled-cell"><strong><?php echo (int)$row['student_count']; ?></strong></td><?php endif; ?>
                              </tr>
                            <?php endforeach; ?>
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
                          <div class="workload-class-head"><h3><?php echo synk_professor_h($row['subject_code'] ?: 'Subject'); ?></h3><span class="workload-section"><?php echo synk_professor_h($row['section_display'] ?: 'Section pending'); ?></span></div>
                          <p class="workload-course-title"><?php echo synk_professor_h($row['descriptive_title'] ?: 'Subject title unavailable'); ?></p>
                          <div class="workload-class-meta"><span><i class="bx bx-group" aria-hidden="true"></i><?php echo (int)$row['student_count']; ?> enrolled</span><span><i class="bx bx-layer" aria-hidden="true"></i><?php echo (int)$row['year_level'] > 0 ? 'Year ' . (int)$row['year_level'] : 'Year level pending'; ?></span></div>
                          <div class="workload-meetings">
                            <?php foreach ($row['meetings'] as $meeting): ?>
                              <div class="workload-meeting">
                                <div class="workload-meeting-top"><span class="workload-type<?php echo $meeting['type'] === 'LAB' ? ' is-lab' : ''; ?>"><?php echo synk_professor_h($meeting['type_label']); ?></span><strong><?php echo synk_professor_h($meeting['days'] ?: 'Days to be arranged'); ?></strong></div>
                                <div class="workload-meeting-time"><i class="bx bx-time-five" aria-hidden="true"></i><span><?php echo synk_professor_h($meeting['time'] ?: 'Time to be arranged'); ?></span></div>
                                <div class="workload-meeting-room"><i class="bx bx-map" aria-hidden="true"></i><span><?php echo synk_professor_h($meeting['room'] ?: 'Room not assigned'); ?></span></div>
                              </div>
                            <?php endforeach; ?>
                          </div>
                          <details class="workload-class-details"><summary>Class details<i class="bx bx-chevron-down" aria-hidden="true"></i></summary><dl>
                            <div><dt>Program</dt><dd><?php echo synk_professor_h($row['program_label'] ?: 'Not assigned'); ?></dd></div>
                            <div><dt>College</dt><dd><?php echo synk_professor_h($row['college_name'] ?: 'Not assigned'); ?></dd></div>
                            <div><dt>Campus</dt><dd><?php echo synk_professor_h($row['campus_name'] ?: 'Not assigned'); ?></dd></div>
                          </dl></details>
                        </article>
                      <?php endforeach; ?>
                    </div>
                  </section>
                <?php endforeach; ?>
              </div>
              <div class="card workload-empty" id="workload-no-matches" hidden><i class="bx bx-search" aria-hidden="true"></i><h2>No matching classes</h2><p>Try another subject, section, day, or room.</p><button type="button" id="workload-clear-search" class="btn btn-outline-primary">Clear search</button></div>
              <p class="workload-enrollment-note">Student enrollments are counted per class. A student taking more than one class may be counted more than once.</p>
            <?php endif; ?>
          </main>
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
