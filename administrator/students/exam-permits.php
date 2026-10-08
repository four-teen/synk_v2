<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../backend/student_management_exam_permits_helper.php';

function student_management_csv_upload_error_message(int $errorCode): string
{
    $map = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed form size.',
        UPLOAD_ERR_PARTIAL => 'The file upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Select a CSV file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
    ];

    return $map[$errorCode] ?? 'Unable to upload the selected CSV file.';
}

function student_management_csv_import_error_details(Throwable $error): string
{
    $message = trim($error->getMessage());
    $csvHints = [
        'The CSV file does not contain the expected old-system roster layout.',
        'The CSV file does not match the expected old-system student list header.',
        'No student rows were found in the uploaded CSV file.',
    ];

    if (in_array($message, $csvHints, true)) {
        return 'The CSV must match the old-system roster format with Subject/Section, Descriptive Title, Instructor(s), Building/Room, and Student\'s Name rows.';
    }

    return 'Nothing was committed. Review the selected scope and the CSV content, then try again.';
}

function student_management_exam_permits_scope_from_post(): array
{
    return [
        'campus_id' => (int)($_POST['campus_id'] ?? 0),
        'college_id' => (int)($_POST['college_id'] ?? 0),
        'program_id' => (int)($_POST['program_id'] ?? 0),
        'ay_id' => (int)($_POST['ay_id'] ?? 0),
        'semester' => (int)($_POST['semester'] ?? 0),
    ];
}

function student_management_store_exam_permits_scope(array $scope): void
{
    $_SESSION['student_management_exam_permits_scope'] = [
        'campus_id' => (int)($scope['campus_id'] ?? 0),
        'college_id' => (int)($scope['college_id'] ?? 0),
        'program_id' => (int)($scope['program_id'] ?? 0),
        'ay_id' => (int)($scope['ay_id'] ?? 0),
        'semester' => (int)($scope['semester'] ?? 0),
    ];
}

function student_management_get_exam_permits_scope(): array
{
    $scope = $_SESSION['student_management_exam_permits_scope'] ?? null;

    if (!is_array($scope)) {
        return [
            'campus_id' => 0,
            'college_id' => 0,
            'program_id' => 0,
            'ay_id' => 0,
            'semester' => 0,
        ];
    }

    return [
        'campus_id' => (int)($scope['campus_id'] ?? 0),
        'college_id' => (int)($scope['college_id'] ?? 0),
        'program_id' => (int)($scope['program_id'] ?? 0),
        'ay_id' => (int)($scope['ay_id'] ?? 0),
        'semester' => (int)($scope['semester'] ?? 0),
    ];
}

function student_management_send_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function student_management_normalize_exam_permits_uploads(array $uploadedFile): array
{
    $files = [];
    $names = $uploadedFile['name'] ?? null;

    if (is_array($names)) {
        $count = count($names);
        for ($index = 0; $index < $count; $index++) {
            $files[] = [
                'name' => (string)($uploadedFile['name'][$index] ?? ''),
                'tmp_name' => (string)($uploadedFile['tmp_name'][$index] ?? ''),
                'error' => (int)($uploadedFile['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            ];
        }
    } elseif (isset($uploadedFile['name'])) {
        $files[] = [
            'name' => (string)($uploadedFile['name'] ?? ''),
            'tmp_name' => (string)($uploadedFile['tmp_name'] ?? ''),
            'error' => (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE),
        ];
    }

    return $files;
}

function student_management_resolve_exam_permits_file_scope(
    mysqli $conn,
    array $sharedScope,
    array $manualScope,
    string $originalName,
    bool $allowManualFallback
): array {
    try {
        return synk_student_management_resolve_upload_scope_from_filename(
            $conn,
            $sharedScope,
            $originalName
        );
    } catch (Throwable $e) {
        if (!$allowManualFallback) {
            throw $e;
        }

        return [
            'campus_id' => (int)($sharedScope['campus_id'] ?? 0),
            'college_id' => (int)($sharedScope['college_id'] ?? 0),
            'program_id' => (int)($manualScope['program_id'] ?? 0),
            'ay_id' => (int)($sharedScope['ay_id'] ?? 0),
            'semester' => (int)($sharedScope['semester'] ?? 0),
            'year_level' => (int)($manualScope['year_level'] ?? 0),
            'section_suffix' => (string)($manualScope['section_suffix'] ?? ''),
        ];
    }
}

function student_management_empty_exam_permits_latest_import(): array
{
    return [
        'import_batch_key' => '',
        'source_file_name' => '',
        'campus_name' => '',
        'college_name' => '',
        'program_code' => '',
        'program_name' => '',
        'academic_year_label' => '',
        'semester_label' => '',
        'year_level' => 0,
        'section_display' => '',
        'subject_code' => '',
        'descriptive_title' => '',
        'faculty_name' => '',
        'room_name' => '',
        'schedule_text' => '',
        'student_count' => 0,
        'imported_at' => '',
    ];
}

function student_management_fetch_exam_permits_panel_state(mysqli $conn, string $pageError = ''): array
{
    $state = [
        'page_error' => $pageError,
        'latest_import' => student_management_empty_exam_permits_latest_import(),
        'recent_imports' => [],
        'has_latest_import' => false,
    ];

    if ($pageError !== '') {
        return $state;
    }

    try {
        synk_student_management_ensure_exam_permits_schema($conn);
        $state['latest_import'] = synk_student_management_exam_permits_latest_batch($conn);
        $state['recent_imports'] = synk_student_management_exam_permits_recent_batches($conn);
        $state['has_latest_import'] = trim((string)($state['latest_import']['import_batch_key'] ?? '')) !== '';
    } catch (Throwable $e) {
        $state['page_error'] = $e->getMessage();
    }

    return $state;
}

function student_management_render_exam_permits_panel(array $state): string
{
    $pageError = (string)($state['page_error'] ?? '');
    $latestImport = is_array($state['latest_import'] ?? null) ? $state['latest_import'] : student_management_empty_exam_permits_latest_import();
    $recentImports = is_array($state['recent_imports'] ?? null) ? $state['recent_imports'] : [];
    $hasLatestImport = !empty($state['has_latest_import']);

    ob_start();
    ?>
    <div class="latest-import-scroll">
      <?php if ($pageError !== ''): ?>
        <div class="alert alert-danger mb-0" role="alert">
          <div class="fw-semibold">Latest import panel is unavailable.</div>
          <div class="small mt-1"><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
      <?php elseif ($hasLatestImport): ?>
        <div class="latest-import-hero mb-3">
          <div class="latest-import-badges">
            <span class="latest-chip"><?php echo htmlspecialchars((string)$latestImport['campus_name'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="latest-chip"><?php echo htmlspecialchars((string)$latestImport['college_name'], ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="latest-chip"><?php echo htmlspecialchars(trim((string)$latestImport['academic_year_label'] . ' ' . (string)$latestImport['semester_label']), ENT_QUOTES, 'UTF-8'); ?></span>
          </div>
          <div class="latest-import-title mt-2">
            <?php echo htmlspecialchars(trim((string)$latestImport['subject_code'] . ' - ' . (string)$latestImport['descriptive_title']), ENT_QUOTES, 'UTF-8'); ?>
          </div>
          <div class="latest-import-subtitle mt-1">
            <?php
              echo htmlspecialchars(
                  trim(implode(' | ', array_filter([
                      trim((string)$latestImport['program_code'] . ' - ' . (string)$latestImport['program_name']),
                      (string)$latestImport['section_display'],
                      (int)($latestImport['year_level'] ?? 0) > 0 ? 'Year ' . (int)$latestImport['year_level'] : '',
                  ]))),
                  ENT_QUOTES,
                  'UTF-8'
              );
            ?>
          </div>
        </div>

        <div class="latest-import-grid mb-3">
          <div class="latest-stat-card">
            <div class="summary-label">Faculty</div>
            <div class="latest-stat-value"><?php echo htmlspecialchars((string)$latestImport['faculty_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="latest-stat-card">
            <div class="summary-label">Room</div>
            <div class="latest-stat-value"><?php echo htmlspecialchars((string)$latestImport['room_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="latest-stat-card">
            <div class="summary-label">Students</div>
            <div class="latest-stat-value"><?php echo number_format((int)$latestImport['student_count']); ?></div>
          </div>
          <div class="latest-stat-card">
            <div class="summary-label">Imported</div>
            <div class="latest-stat-value"><?php echo htmlspecialchars(student_management_format_datetime((string)$latestImport['imported_at']), ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>

        <div class="latest-detail-stack mb-3">
          <div class="latest-detail-row">
            <span class="summary-label">Schedule</span>
            <div class="latest-detail-value"><?php echo htmlspecialchars((string)$latestImport['schedule_text'], ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
          <div class="latest-detail-row">
            <span class="summary-label">Source File</span>
            <div class="latest-detail-value"><?php echo htmlspecialchars((string)$latestImport['source_file_name'], ENT_QUOTES, 'UTF-8'); ?></div>
          </div>
        </div>

        <div class="mb-3">
          <form method="post" class="d-inline ajax-delete-batch-form" data-confirm="Delete the latest uploaded batch and restore the previous archived batch if one exists?">
            <input type="hidden" name="delete_import_batch" value="1" />
            <input type="hidden" name="import_batch_key" value="<?php echo htmlspecialchars((string)$latestImport['import_batch_key'], ENT_QUOTES, 'UTF-8'); ?>" />
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bx bx-trash me-1"></i> Delete Latest Batch</button>
          </form>
        </div>

        <?php if (!empty($recentImports)): ?>
          <div class="summary-label mb-2">Recent Uploaded Files</div>
          <div class="recent-import-list">
            <?php foreach ($recentImports as $recentIndex => $recentImport): ?>
              <div class="recent-import-row<?php echo $recentIndex < count($recentImports) - 1 ? ' border-bottom' : ''; ?>">
                <div class="recent-import-head">
                  <div class="recent-import-name">
                    <?php echo htmlspecialchars((string)$recentImport['source_file_name'], ENT_QUOTES, 'UTF-8'); ?>
                  </div>
                  <?php if ((int)($recentImport['is_active'] ?? 0) === 1): ?>
                    <span class="badge bg-label-success">Current</span>
                  <?php else: ?>
                    <span class="badge bg-label-secondary">Archived</span>
                  <?php endif; ?>
                </div>
                <div class="recent-import-meta">
                  <?php
                    echo htmlspecialchars(
                        trim(implode(' | ', array_filter([
                            (string)$recentImport['program_code'],
                            (string)$recentImport['section_display'],
                            (string)$recentImport['subject_code'],
                            (string)($recentImport['faculty_name'] ?? ''),
                            trim((string)$recentImport['academic_year_label'] . ' ' . (string)$recentImport['semester_label']),
                        ]))),
                        ENT_QUOTES,
                        'UTF-8'
                    );
                  ?>
                </div>
                <div class="recent-import-foot">
                  <div class="small">
                    <span class="fw-semibold"><?php echo number_format((int)$recentImport['student_count']); ?> rows</span>
                    <span class="text-muted ms-2"><?php echo htmlspecialchars(student_management_format_datetime((string)$recentImport['imported_at']), ENT_QUOTES, 'UTF-8'); ?></span>
                  </div>
                  <form method="post" class="mt-2 ajax-delete-batch-form" data-confirm="Delete this uploaded batch? If it is the current batch, the newest archived batch for the same subject-section will be restored automatically.">
                    <input type="hidden" name="delete_import_batch" value="1" />
                    <input type="hidden" name="import_batch_key" value="<?php echo htmlspecialchars((string)$recentImport['import_batch_key'], ENT_QUOTES, 'UTF-8'); ?>" />
                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete Batch</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="text-muted">No backward-encoding CSV has been uploaded yet.</div>
      <?php endif; ?>
    </div>
    <?php

    return (string)ob_get_clean();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_import_batch'])) {
    $isAjaxDelete = isset($_POST['ajax_latest_import_panel']);
    $deleteStatus = 'info';
    $deleteMessage = '';
    $deleteDetails = '';

    if ($studentManagementPageError === '') {
        try {
            $summary = synk_student_management_delete_import_batch(
                $conn,
                (string)($_POST['import_batch_key'] ?? '')
            );

            $detailParts = [
                'Deleted enrolled-subject rows: ' . number_format((int)$summary['deleted_rows']),
                'Removed orphan student master rows: ' . number_format((int)$summary['deleted_students']),
            ];

            if (!empty($summary['deleted_section'])) {
                $detailParts[] = 'Removed the now-unused section that was tied to this upload.';
            } else {
                $blockedBy = is_array($summary['section_cleanup_blocked_by'] ?? null) ? $summary['section_cleanup_blocked_by'] : [];
                if (!empty($blockedBy)) {
                    $detailParts[] = 'Section was kept because it is still referenced elsewhere.';
                }
            }

            if (trim((string)($summary['restored_batch_key'] ?? '')) !== '') {
                $detailParts[] = 'Restored the previous archived batch for this subject-section automatically.';
            } elseif (!empty($summary['was_active'])) {
                $detailParts[] = 'No archived batch was available to restore.';
            } else {
                $detailParts[] = 'The deleted batch was archived only, so no current batch was changed.';
            }

            $deleteStatus = 'success';
            $deleteMessage = sprintf(
                'Deleted uploaded batch %s.',
                (string)($summary['source_file_name'] ?? '')
            );
            $deleteDetails = implode(' ', $detailParts);
        } catch (Throwable $e) {
            $deleteStatus = 'danger';
            $deleteMessage = $e->getMessage();
            $deleteDetails = 'No batch rows were changed.';
        }
    } else {
        $deleteStatus = 'danger';
        $deleteMessage = $studentManagementPageError;
        $deleteDetails = 'The module schema could not be prepared, so the batch delete was skipped.';
    }

    if ($isAjaxDelete) {
        $panelState = student_management_fetch_exam_permits_panel_state($conn, $studentManagementPageError === '' ? '' : $studentManagementPageError);
        student_management_send_json_response([
            'success' => $deleteStatus === 'success',
            'status' => $deleteStatus,
            'message' => $deleteMessage,
            'details' => $deleteDetails,
            'latest_panel_html' => student_management_render_exam_permits_panel($panelState),
        ]);
    }

    student_management_push_flash(
        $deleteStatus,
        $deleteMessage,
        $deleteDetails
    );

    student_management_redirect('exam-permits.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_exam_permits_csv'])) {
    $stickyScope = student_management_exam_permits_scope_from_post();
    student_management_store_exam_permits_scope($stickyScope);
    $isAjaxUpload = isset($_POST['upload_exam_permits_ajax']);

    if ($studentManagementPageError === '') {
        try {
            if (!isset($_FILES['exam_permits_csv'])) {
                throw new RuntimeException('Select a CSV file to upload.');
            }

            $uploadedFiles = student_management_normalize_exam_permits_uploads($_FILES['exam_permits_csv']);
            if (empty($uploadedFiles)) {
                throw new RuntimeException('Select at least one CSV file to upload.');
            }

            $sharedScope = [
                'campus_id' => (int)$stickyScope['campus_id'],
                'college_id' => (int)$stickyScope['college_id'],
                'ay_id' => (int)$stickyScope['ay_id'],
                'semester' => (int)$stickyScope['semester'],
            ];
            $manualScope = [
                'program_id' => (int)$stickyScope['program_id'],
                'year_level' => (int)($_POST['year_level'] ?? 0),
                'section_suffix' => (string)($_POST['section_suffix'] ?? ''),
            ];
            $allowManualFallback = count($uploadedFiles) === 1;
            $successfulImports = [];
            $failedImports = [];
            $fileResults = [];

            foreach ($uploadedFiles as $index => $uploadedFile) {
                $originalName = trim((string)($uploadedFile['name'] ?? ''));
                $tmpName = (string)($uploadedFile['tmp_name'] ?? '');
                $errorCode = (int)($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);
                $displayName = $originalName !== '' ? $originalName : ('File ' . ($index + 1));

                try {
                    if ($errorCode !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(student_management_csv_upload_error_message($errorCode));
                    }

                    if ($originalName === '' || $tmpName === '' || !is_uploaded_file($tmpName)) {
                        throw new RuntimeException('The uploaded CSV file could not be processed.');
                    }

                    if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'csv') {
                        throw new RuntimeException('Only CSV files from the old student roster format are supported on this page.');
                    }

                    $scope = student_management_resolve_exam_permits_file_scope(
                        $conn,
                        $sharedScope,
                        $manualScope,
                        $originalName,
                        $allowManualFallback
                    );

                    $successfulImports[] = synk_student_management_import_exam_permits_csv(
                        $conn,
                        $tmpName,
                        $originalName,
                        $scope,
                        (int)($_SESSION['user_id'] ?? 0)
                    );
                    $summary = $successfulImports[count($successfulImports) - 1];
                    $fileResults[] = [
                        'name' => $displayName,
                        'success' => true,
                        'message' => sprintf(
                            'Imported %s row(s) for %s.',
                            number_format((int)($summary['student_count'] ?? 0)),
                            (string)($summary['section_display'] ?? '')
                        ),
                        'details' => sprintf(
                            '%s | %s | Faculty ID %s',
                            (string)($summary['subject_code'] ?? ''),
                            (string)($summary['semester_label'] ?? ''),
                            number_format((int)($summary['faculty_id'] ?? 0))
                        ),
                        'summary' => [
                            'section_display' => (string)($summary['section_display'] ?? ''),
                            'subject_code' => (string)($summary['subject_code'] ?? ''),
                            'student_count' => (int)($summary['student_count'] ?? 0),
                            'faculty_id' => (int)($summary['faculty_id'] ?? 0),
                            'room_name' => (string)($summary['room_name'] ?? ''),
                        ],
                    ];
                } catch (Throwable $fileError) {
                    $failedImports[] = $displayName . ': ' . $fileError->getMessage();
                    $fileResults[] = [
                        'name' => $displayName,
                        'success' => false,
                        'message' => $fileError->getMessage(),
                        'details' => student_management_csv_import_error_details($fileError),
                    ];
                }
            }

            $responseStatus = !empty($successfulImports) && empty($failedImports)
                ? 'success'
                : (!empty($successfulImports) ? 'warning' : 'danger');
            $responseMessage = '';
            $responseDetails = '';

            if (!empty($successfulImports)) {
                if (count($successfulImports) === 1 && empty($failedImports)) {
                    $summary = $successfulImports[0];
                    $responseMessage = sprintf(
                        'Imported %s enrolled-subject rows for %s in %s %s.',
                        number_format((int)$summary['student_count']),
                        (string)$summary['section_display'],
                        (string)$summary['academic_year_label'],
                        (string)$summary['semester_label']
                    );
                    $responseDetails = sprintf(
                        'Subject: %s. Matched existing students: %s. Updated student master rows: %s. Added new students: %s. Replaced previous enrollment rows: %s. Faculty ID: %s. Room link: %s.',
                        (string)$summary['subject_code'],
                        number_format((int)$summary['matched_students']),
                        number_format((int)$summary['updated_students']),
                        number_format((int)$summary['inserted_students']),
                        number_format((int)$summary['deleted_rows']),
                        number_format((int)$summary['faculty_id']),
                        !empty($summary['room_linked']) ? 'Linked to tbl_rooms' : 'Stored as imported room text only'
                    );
                } else {
                    $importedFileLabels = array_map(static function (array $summary): string {
                        return (string)($summary['source_file_name'] ?? '');
                    }, $successfulImports);
                    $details = 'Imported files: ' . implode(', ', array_filter($importedFileLabels));
                    if (!empty($failedImports)) {
                        $details .= ' Failed files: ' . implode(' | ', $failedImports);
                    }

                    $responseMessage = sprintf(
                        'Imported %s of %s selected CSV file(s).',
                        number_format(count($successfulImports)),
                        number_format(count($uploadedFiles))
                    );
                    $responseDetails = $details;
                }
            } else {
                $responseMessage = 'No CSV files were imported.';
                $responseDetails = implode(' | ', $failedImports);
            }

            if ($isAjaxUpload) {
                $panelState = student_management_fetch_exam_permits_panel_state($conn);
                student_management_send_json_response([
                    'success' => !empty($successfulImports) && empty($failedImports),
                    'status' => $responseStatus,
                    'message' => $responseMessage,
                    'details' => $responseDetails,
                    'imported_count' => count($successfulImports),
                    'failed_count' => count($failedImports),
                    'files' => $fileResults,
                    'latest_panel_html' => student_management_render_exam_permits_panel($panelState),
                ]);
            }

            student_management_push_flash(
                $responseStatus,
                $responseMessage,
                $responseDetails
            );
        } catch (Throwable $e) {
            if ($isAjaxUpload) {
                $panelState = student_management_fetch_exam_permits_panel_state($conn);
                student_management_send_json_response([
                    'success' => false,
                    'status' => 'danger',
                    'message' => $e->getMessage(),
                    'details' => student_management_csv_import_error_details($e),
                    'imported_count' => 0,
                    'failed_count' => 1,
                    'files' => [],
                    'latest_panel_html' => student_management_render_exam_permits_panel($panelState),
                ], 200);
            }

            student_management_push_flash(
                'danger',
                $e->getMessage(),
                student_management_csv_import_error_details($e)
            );
        }
    } else {
        if ($isAjaxUpload) {
            $panelState = student_management_fetch_exam_permits_panel_state($conn, $studentManagementPageError);
            student_management_send_json_response([
                'success' => false,
                'status' => 'danger',
                'message' => $studentManagementPageError,
                'details' => 'The module schema could not be prepared, so the CSV upload was skipped.',
                'imported_count' => 0,
                'failed_count' => 1,
                'files' => [],
                'latest_panel_html' => student_management_render_exam_permits_panel($panelState),
            ], 200);
        }

        student_management_push_flash(
            'danger',
            $studentManagementPageError,
            'The module schema could not be prepared, so the CSV upload was skipped.'
        );
    }

    student_management_redirect('exam-permits.php');
}

$flash = student_management_pull_flash();
$latestImport = student_management_empty_exam_permits_latest_import();
$campuses = [];
$colleges = [];
$programs = [];
$academicYears = [];
$recentImports = [];
$semesterOptions = synk_student_management_semester_options();
$currentTerm = synk_fetch_current_academic_term($conn);
$defaultAyId = (int)($currentTerm['ay_id'] ?? 0);
$defaultSemester = (int)($currentTerm['semester'] ?? 0);
$stickyScope = student_management_get_exam_permits_scope();
$selectedCampusId = (int)($stickyScope['campus_id'] ?? 0);
$selectedCollegeId = (int)($stickyScope['college_id'] ?? 0);
$selectedProgramId = (int)($stickyScope['program_id'] ?? 0);
$selectedAyId = (int)($stickyScope['ay_id'] ?? 0);
$selectedSemester = (int)($stickyScope['semester'] ?? 0);

if ($selectedAyId <= 0) {
    $selectedAyId = $defaultAyId;
}

if ($selectedSemester <= 0) {
    $selectedSemester = $defaultSemester;
}

if ($studentManagementPageError === '') {
    try {
        synk_student_management_ensure_exam_permits_schema($conn);
        $latestImport = synk_student_management_exam_permits_latest_batch($conn);
        $campuses = synk_student_management_fetch_active_campuses($conn);
        $colleges = synk_student_management_fetch_college_catalog($conn);
        $programs = synk_student_management_fetch_program_catalog($conn);
        $academicYears = synk_student_management_fetch_academic_year_catalog($conn);
        $recentImports = synk_student_management_exam_permits_recent_batches($conn);
    } catch (Throwable $e) {
        $studentManagementPageError = $e->getMessage();
    }
}

$hasLatestImport = trim((string)($latestImport['import_batch_key'] ?? '')) !== '';
$latestImportPanelState = [
    'page_error' => $studentManagementPageError,
    'latest_import' => $latestImport,
    'recent_imports' => $recentImports,
    'has_latest_import' => $hasLatestImport,
];
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Backward Encoding CSV | Synk</title>

    <link rel="icon" type="image/png" href="../../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <script src="../../assets/vendor/js/helpers.js"></script>
    <script src="../../assets/js/config.js"></script>

    <style>
      .upload-hero { border: 0; border-radius: 1.25rem; background: linear-gradient(145deg, #eef6ff 0%, #ffffff 48%, #f8fbff 100%); }
      .section-card { border: 0; border-radius: 1.1rem; box-shadow: 0 16px 35px rgba(15, 23, 42, 0.08); }
      .upload-note { border-radius: 1rem; border: 1px dashed rgba(54, 90, 148, 0.3); background: #f8fbff; }
      .batch-chip { display: inline-flex; padding: 0.25rem 0.6rem; border-radius: 999px; background: #eaf2ff; color: #2f5ca8; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
      .summary-label { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: #8592a3; font-weight: 700; }
      .scope-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; }
      .upload-dashboard { border: 1px solid rgba(47, 92, 168, 0.14); border-radius: 1.1rem; background: linear-gradient(160deg, #f5f9ff 0%, #ffffff 100%); overflow: hidden; }
      .upload-dashboard-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.2rem 0.8rem; }
      .upload-loader-wrap { display: flex; align-items: center; gap: 0.85rem; }
      .upload-loader-orb { width: 2.5rem; height: 2.5rem; border-radius: 999px; background: radial-gradient(circle at 30% 30%, #76a8ff 0%, #2f5ca8 70%, #16355d 100%); position: relative; box-shadow: 0 10px 25px rgba(47, 92, 168, 0.28); }
      .upload-loader-orb::after { content: ""; position: absolute; inset: -0.35rem; border-radius: inherit; border: 2px solid rgba(47, 92, 168, 0.14); animation: uploadPulse 1.8s ease-out infinite; }
      .upload-dashboard.is-uploading .upload-loader-orb { animation: uploadFloat 1.1s ease-in-out infinite; }
      .upload-dashboard-body { padding: 0 1.2rem 1.2rem; }
      .upload-progress-track { height: 0.8rem; border-radius: 999px; background: #eaf1fb; overflow: hidden; }
      .upload-progress-bar { height: 100%; width: 0%; border-radius: inherit; background: linear-gradient(90deg, #2f5ca8 0%, #57a6ff 55%, #8bd6ff 100%); background-size: 200% 100%; transition: width 0.25s ease; }
      .upload-dashboard.is-uploading .upload-progress-bar { animation: progressWave 1.6s linear infinite; }
      .upload-file-list { display: grid; gap: 0.75rem; margin-top: 1rem; }
      .upload-file-item { border: 1px solid rgba(133, 146, 163, 0.22); border-radius: 1rem; background: #fff; padding: 0.9rem 1rem; transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease; }
      .upload-file-item.is-uploading { border-color: rgba(47, 92, 168, 0.45); box-shadow: 0 12px 30px rgba(47, 92, 168, 0.12); transform: translateY(-1px); }
      .upload-file-item.is-success { border-color: rgba(18, 164, 105, 0.28); background: linear-gradient(180deg, #ffffff 0%, #f2fff8 100%); }
      .upload-file-item.is-error { border-color: rgba(215, 74, 74, 0.28); background: linear-gradient(180deg, #ffffff 0%, #fff5f5 100%); }
      .upload-file-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; }
      .upload-file-meta { min-width: 0; }
      .upload-file-name { font-weight: 700; color: #23344e; word-break: break-word; }
      .upload-file-note { font-size: 0.84rem; color: #697a8d; margin-top: 0.15rem; }
      .upload-file-status { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.55rem; border-radius: 999px; font-size: 0.74rem; font-weight: 700; white-space: nowrap; }
      .upload-file-status.is-pending { background: #eef2f7; color: #566a7f; }
      .upload-file-status.is-uploading { background: rgba(47, 92, 168, 0.12); color: #2f5ca8; }
      .upload-file-status.is-success { background: rgba(18, 164, 105, 0.12); color: #128a5a; }
      .upload-file-status.is-error { background: rgba(215, 74, 74, 0.12); color: #c23c3c; }
      .upload-file-progress { margin-top: 0.75rem; }
      .upload-file-progress .upload-progress-track { height: 0.55rem; }
      .upload-file-extra { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-top: 0.4rem; font-size: 0.78rem; color: #8592a3; }
      .upload-dashboard-summary { margin-top: 0.9rem; padding: 0.85rem 1rem; border-radius: 0.9rem; background: rgba(47, 92, 168, 0.06); color: #334a68; font-size: 0.9rem; }
      .upload-dashboard-summary.is-success { background: rgba(18, 164, 105, 0.08); color: #176b4b; }
      .upload-dashboard-summary.is-warning { background: rgba(241, 196, 15, 0.12); color: #886c08; }
      .upload-dashboard-summary.is-danger { background: rgba(215, 74, 74, 0.1); color: #9e3333; }
      .latest-import-panel { padding-top: 1rem; }
      .latest-import-scroll { max-height: 72vh; overflow-y: auto; padding-right: 0.35rem; }
      .latest-import-hero { padding: 1rem 1rem 0.9rem; border: 1px solid rgba(47, 92, 168, 0.12); border-radius: 1rem; background: linear-gradient(165deg, #f4f8ff 0%, #ffffff 100%); }
      .latest-import-badges { display: flex; flex-wrap: wrap; gap: 0.45rem; }
      .latest-chip { display: inline-flex; align-items: center; padding: 0.25rem 0.55rem; border-radius: 999px; background: rgba(47, 92, 168, 0.1); color: #2f5ca8; font-size: 0.72rem; font-weight: 700; }
      .latest-import-title { font-size: 1rem; line-height: 1.35; font-weight: 700; color: #23344e; }
      .latest-import-subtitle { font-size: 0.87rem; color: #62748a; line-height: 1.45; }
      .latest-import-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
      .latest-stat-card { padding: 0.8rem 0.9rem; border-radius: 0.95rem; border: 1px solid rgba(133, 146, 163, 0.18); background: #fff; }
      .latest-stat-value { font-size: 0.92rem; font-weight: 700; color: #33475f; line-height: 1.4; }
      .latest-detail-stack { display: grid; gap: 0.65rem; }
      .latest-detail-row { padding: 0.8rem 0.9rem; border-radius: 0.95rem; border: 1px solid rgba(133, 146, 163, 0.18); background: #fff; }
      .latest-detail-value { margin-top: 0.25rem; font-size: 0.9rem; line-height: 1.45; color: #516579; word-break: break-word; }
      .recent-import-list { border: 1px solid rgba(133, 146, 163, 0.18); border-radius: 1rem; background: #fff; overflow: hidden; }
      .recent-import-row { padding: 0.85rem 0.95rem; }
      .recent-import-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; }
      .recent-import-name { font-weight: 700; color: #34455d; word-break: break-word; }
      .recent-import-meta { margin-top: 0.3rem; font-size: 0.8rem; color: #7a8899; line-height: 1.45; }
      .recent-import-foot { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-top: 0.55rem; flex-wrap: wrap; }
      @media (max-width: 991.98px) {
        .latest-import-scroll { max-height: none; overflow: visible; padding-right: 0; }
      }
      @media (max-width: 575.98px) {
        .latest-import-grid { grid-template-columns: 1fr; }
      }
      @keyframes uploadPulse {
        0% { transform: scale(0.92); opacity: 0.7; }
        70% { transform: scale(1.22); opacity: 0; }
        100% { transform: scale(1.22); opacity: 0; }
      }
      @keyframes uploadFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-4px); }
      }
      @keyframes progressWave {
        0% { background-position: 0% 0; }
        100% { background-position: 200% 0; }
      }
    </style>
  </head>

  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'navbar.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="card upload-hero mb-4">
                <div class="card-body p-4 p-lg-5">
                  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                    <div>
                      <span class="batch-chip mb-3">Single Upload Page</span>
                      <h3 class="fw-bold mb-2 text-primary">Backward-encode old student roster CSV files into the rebuilt student module.</h3>
                      <p class="text-muted mb-0">
                        This page stores one row per student in <code>tbl_student_management</code> and one row per student-subject enrollment in the renamed enrolled-subjects table.
                      </p>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                      <a href="index.php" class="btn btn-outline-primary">Back to Dashboard</a>
                      <a href="directory.php" class="btn btn-primary">Open Student Directory</a>
                    </div>
                  </div>
                </div>
              </div>

              <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars((string)($flash['status'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?> alert-dismissible mb-4" role="alert">
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php if (!empty($flash['details'])): ?>
                    <div class="small mt-1"><?php echo htmlspecialchars((string)$flash['details'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php endif; ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>

              <?php if ($studentManagementPageError !== ''): ?>
                <div class="alert alert-danger mb-4" role="alert">
                  <div class="fw-semibold">Backward encoding is unavailable.</div>
                  <div class="small mt-1"><?php echo htmlspecialchars($studentManagementPageError, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              <?php endif; ?>

              <div id="ajaxPageNotice" class="d-none mb-4"></div>

              <div class="row g-4">
                <div class="col-lg-7">
                  <div class="card section-card h-100">
                    <div class="card-header">
                      <h5 class="mb-1">Upload Old System CSV</h5>
                      <p class="text-muted mb-0">Select the shared scope first, then upload one or more legacy subject-section CSV files.</p>
                    </div>
                    <div class="card-body">
                      <form method="post" enctype="multipart/form-data" id="backwardEncodingForm">
                        <input type="hidden" name="upload_exam_permits_csv" value="1" />

                        <div class="scope-grid mb-3">
                          <div><label class="form-label" for="campus_id">Campus</label><select class="form-select" id="campus_id" name="campus_id" required><option value="">Select campus</option><?php foreach ($campuses as $campus): ?><option value="<?php echo (int)$campus['campus_id']; ?>" <?php echo $selectedCampusId === (int)$campus['campus_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$campus['campus_name'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                          <div><label class="form-label" for="college_id">College</label><select class="form-select" id="college_id" name="college_id" required disabled><option value="">Select college</option></select></div>
                          <div><label class="form-label" for="program_id">Program</label><select class="form-select" id="program_id" name="program_id" disabled><option value="">Auto from filename</option></select></div>
                          <div><label class="form-label" for="ay_id">Academic Year</label><select class="form-select" id="ay_id" name="ay_id" required><option value="">Select academic year</option><?php foreach ($academicYears as $academicYear): ?><option value="<?php echo (int)$academicYear['ay_id']; ?>" <?php echo $selectedAyId === (int)$academicYear['ay_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$academicYear['ay'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                          <div><label class="form-label" for="semester">Semester</label><select class="form-select" id="semester" name="semester" required><?php foreach ($semesterOptions as $semesterValue => $semesterLabel): ?><option value="<?php echo (int)$semesterValue; ?>" <?php echo $selectedSemester === (int)$semesterValue ? 'selected' : ''; ?>><?php echo htmlspecialchars((string)$semesterLabel, ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></div>
                          <div><label class="form-label" for="year_level">Year Level</label><select class="form-select" id="year_level" name="year_level" disabled><option value="">Auto from filename</option></select></div>
                          <div><label class="form-label" for="section_suffix">Section</label><select class="form-select" id="section_suffix" name="section_suffix" disabled><option value="">Auto from filename</option></select></div>
                        </div>

                        <div class="mb-3">
                          <label for="exam_permits_csv" class="form-label">CSV file(s)</label>
                          <input type="file" class="form-control" id="exam_permits_csv" name="exam_permits_csv[]" accept=".csv,text/csv" multiple required />
                          <div class="form-text" id="filename_scope_notice">Pick one file to preview its program, year level, and section, or pick multiple files and the page will resolve each filename during upload.</div>
                        </div>

                        <div class="upload-dashboard d-none mb-3" id="uploadDashboard">
                          <div class="upload-dashboard-header">
                            <div class="upload-loader-wrap">
                              <div class="upload-loader-orb"></div>
                              <div>
                                <div class="fw-semibold text-primary">Upload Activity</div>
                                <div class="small text-muted" id="currentUploadLabel">Waiting for files</div>
                              </div>
                            </div>
                            <div class="text-end">
                              <div class="small text-muted">Overall progress</div>
                              <div class="fw-bold text-primary" id="overallUploadPercent">0%</div>
                            </div>
                          </div>
                          <div class="upload-dashboard-body">
                            <div class="upload-progress-track">
                              <div class="upload-progress-bar" id="overallUploadBar"></div>
                            </div>
                            <div class="upload-file-list" id="uploadFileList"></div>
                            <div class="upload-dashboard-summary d-none" id="uploadDashboardSummary"></div>
                          </div>
                        </div>

                        <div class="upload-note p-3 mb-3">
                          <div class="fw-semibold mb-2">Import rules</div>
                          <ul class="ps-3 mb-0">
                            <li>The CSV must match the old roster layout like the file you attached.</li>
                            <li>Campus, college, academic year, and semester are the shared safety scope for every selected file.</li>
                            <li>Program, year level, and section are resolved per file from the filename, with single-file preview shown above when possible.</li>
                            <li>Section choices now run from <code>A</code> to <code>I</code>; missing scoped sections are created automatically during upload.</li>
                            <li>Students are inserted into <code>tbl_student_management</code> once only, then refreshed on later uploads.</li>
                            <li>Subject rows are stored in the renamed enrolled-subjects table, so one student can have many subject records.</li>
                            <li>Duplicate uploads are blocked when the current active batch already matches the same faculty, room, schedule, and student roster exactly.</li>
                            <li>Subjects resolve through <code>tbl_prospectus_offering</code> first, then fall back to <code>tbl_subject_masterlist</code> if needed.</li>
                            <li>Instructor must map to <code>tbl_faculty</code>; room tries to map to <code>tbl_rooms</code> inside the selected college scope.</li>
                          </ul>
                        </div>

                        <div class="d-flex flex-wrap gap-2">
                          <button type="button" id="uploadQueueButton" class="btn btn-primary" <?php echo $studentManagementPageError !== '' ? 'disabled' : ''; ?>><i class="bx bx-upload me-1"></i> Upload Backward Encoding CSVs</button>
                          <a href="directory.php" class="btn btn-outline-secondary">View Student Master</a>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>

                <div class="col-lg-5">
                  <div class="card section-card h-100">
                    <div class="card-header">
                      <h5 class="mb-1">Latest Import</h5>
                      <p class="text-muted mb-0">Most recent enrolled-subject batch currently stored in the rebuilt student module.</p>
                    </div>
                    <div class="card-body latest-import-panel" id="latestImportPanel">
                      <?php echo student_management_render_exam_permits_panel($latestImportPanelState); ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <?php include '../../footer.php'; ?>
          </div>
        </div>
      </div>
    </div>

    <script src="../../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../../assets/vendor/libs/popper/popper.js"></script>
    <script src="../../assets/vendor/js/bootstrap.js"></script>
    <script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../../assets/vendor/js/menu.js"></script>
    <script src="../../assets/js/main.js"></script>
    <script>
      (function () {
        const colleges = <?php echo json_encode($colleges, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const programs = <?php echo json_encode($programs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const stickyScope = <?php echo json_encode([
            'college_id' => $selectedCollegeId,
            'program_id' => $selectedProgramId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const yearLevels = [1, 2, 3, 4, 5, 6];
        const sectionSuffixes = ["A", "B", "C", "D", "E", "F", "G", "H", "I"];
        const filenameProgramMap = {
          IS: "BSIS",
          BSIS: "BSIS",
          IT: "BSIT",
          BSIT: "BSIT",
          CS: "BSCS",
          BSCS: "BSCS",
        };
        const campusField = document.getElementById("campus_id");
        const collegeField = document.getElementById("college_id");
        const programField = document.getElementById("program_id");
        const ayField = document.getElementById("ay_id");
        const semesterField = document.getElementById("semester");
        const yearLevelField = document.getElementById("year_level");
        const sectionField = document.getElementById("section_suffix");
        const fileField = document.getElementById("exam_permits_csv");
        const filenameScopeNotice = document.getElementById("filename_scope_notice");
        const form = document.getElementById("backwardEncodingForm");
        const submitButton = document.getElementById("uploadQueueButton");
        const ajaxPageNotice = document.getElementById("ajaxPageNotice");
        const uploadDashboard = document.getElementById("uploadDashboard");
        const uploadFileList = document.getElementById("uploadFileList");
        const uploadDashboardSummary = document.getElementById("uploadDashboardSummary");
        const overallUploadBar = document.getElementById("overallUploadBar");
        const overallUploadPercent = document.getElementById("overallUploadPercent");
        const currentUploadLabel = document.getElementById("currentUploadLabel");
        const latestImportPanel = document.getElementById("latestImportPanel");
        const actionUrl = form.getAttribute("action") || window.location.pathname;
        let uploadInProgress = false;

        function resetSelect(select, placeholder) {
          select.innerHTML = '<option value="">' + placeholder + '</option>';
          select.disabled = true;
        }

        function fillSelect(select, items, valueKey, labelBuilder, selectedValue) {
          let hasSelectedValue = false;

          items.forEach(function (item) {
            const option = document.createElement("option");
            option.value = String(item[valueKey] || "");
            option.textContent = labelBuilder(item);
            if (String(selectedValue || "") !== "" && option.value === String(selectedValue)) {
              option.selected = true;
              hasSelectedValue = true;
            }
            select.appendChild(option);
          });
          select.disabled = items.length === 0;

          if (!hasSelectedValue) {
            select.value = "";
          }
        }

        function resetSectionScope() {
          resetSelect(yearLevelField, "Select year level");
          resetSelect(sectionField, "Select section");
        }

        function getSelectedProgram() {
          const programId = Number(programField.value || 0);
          return programs.find(function (program) {
            return Number(program.program_id || 0) === programId;
          });
        }

        function getCollegeById(collegeId) {
          return colleges.find(function (college) {
            return Number(college.college_id || 0) === Number(collegeId || 0);
          }) || null;
        }

        function normalizeProgramCode(programCode) {
          const normalized = String(programCode || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
          return filenameProgramMap[normalized] || normalized;
        }

        function inferProgramCodeFromSubjectPrefix(subjectHint) {
          const normalized = String(subjectHint || "").toUpperCase().replace(/[^A-Z0-9]/g, "");
          const candidates = ["BSIS", "BSIT", "BSCS", "IS", "IT", "CS"];

          for (let index = 0; index < candidates.length; index += 1) {
            if (normalized.startsWith(candidates[index])) {
              return normalizeProgramCode(candidates[index]);
            }
          }

          return "";
        }

        function setScopeNotice(message, tone) {
          filenameScopeNotice.textContent = message;
          filenameScopeNotice.classList.remove("text-muted", "text-success", "text-warning", "text-danger");
          filenameScopeNotice.classList.add(tone || "text-muted");
        }

        function formatFileSize(bytes) {
          const numericBytes = Number(bytes || 0);
          if (numericBytes >= 1024 * 1024) {
            return (numericBytes / (1024 * 1024)).toFixed(1) + " MB";
          }
          if (numericBytes >= 1024) {
            return Math.max(1, Math.round(numericBytes / 1024)) + " KB";
          }
          return numericBytes + " B";
        }

        function setUploadSummary(message, status) {
          if (!message) {
            uploadDashboardSummary.textContent = "";
            uploadDashboardSummary.className = "upload-dashboard-summary d-none";
            return;
          }

          uploadDashboardSummary.textContent = message;
          uploadDashboardSummary.className = "upload-dashboard-summary";
          uploadDashboardSummary.classList.add("is-" + (status || "success"));
        }

        function showAjaxPageNotice(status, message, details) {
          if (!ajaxPageNotice) {
            return;
          }

          if (!message) {
            ajaxPageNotice.className = "d-none mb-4";
            ajaxPageNotice.innerHTML = "";
            return;
          }

          ajaxPageNotice.className = "alert alert-" + (status || "info") + " mb-4";
          ajaxPageNotice.innerHTML =
            '<div class="fw-semibold"></div>' +
            (details ? '<div class="small mt-1"></div>' : '');
          ajaxPageNotice.querySelector(".fw-semibold").textContent = message;
          if (details) {
            ajaxPageNotice.querySelector(".small").textContent = details;
          }
        }

        function setOverallProgress(percent) {
          const safePercent = Math.max(0, Math.min(100, Math.round(percent || 0)));
          overallUploadBar.style.width = safePercent + "%";
          overallUploadPercent.textContent = safePercent + "%";
        }

        function statusMeta(status) {
          const map = {
            pending: { label: "Queued", className: "is-pending", icon: "bx-time-five" },
            uploading: { label: "Uploading", className: "is-uploading", icon: "bx-loader-alt" },
            success: { label: "Uploaded", className: "is-success", icon: "bx-check-circle" },
            error: { label: "Failed", className: "is-error", icon: "bx-x-circle" },
          };

          return map[status] || map.pending;
        }

        function buildPreviewLabel(file) {
          const parsedScope = parseScopeFromFilename(file.name);
          if (!parsedScope) {
            return "Filename scope will be checked during upload";
          }

          return parsedScope.programCode + " | Year " + parsedScope.yearLevel + " | Section " + parsedScope.sectionSuffix;
        }

        function renderSelectedFiles() {
          const selectedFiles = fileField.files ? Array.from(fileField.files) : [];
          uploadFileList.innerHTML = "";

          if (!selectedFiles.length) {
            uploadDashboard.classList.add("d-none");
            uploadDashboard.classList.remove("is-uploading");
            setOverallProgress(0);
            currentUploadLabel.textContent = "Waiting for files";
            setUploadSummary("", "success");
            if (!uploadInProgress) {
              submitButton.disabled = true;
            }
            return;
          }

          uploadDashboard.classList.remove("d-none");
          uploadDashboard.classList.remove("is-uploading");
          setOverallProgress(0);
          currentUploadLabel.textContent = "Ready to upload " + selectedFiles.length + " file(s)";
          setUploadSummary("", "success");
          if (!uploadInProgress) {
            submitButton.disabled = campusField.value === "" || collegeField.value === "" || ayField.value === "" || semesterField.value === "";
          }

          selectedFiles.forEach(function (file, index) {
            const item = document.createElement("div");
            item.className = "upload-file-item";
            item.dataset.uploadIndex = String(index);
            item.innerHTML =
              '<div class="upload-file-row">' +
                '<div class="upload-file-meta">' +
                  '<div class="upload-file-name"></div>' +
                  '<div class="upload-file-note"></div>' +
                '</div>' +
                '<div class="upload-file-status is-pending"></div>' +
              '</div>' +
              '<div class="upload-file-progress">' +
                '<div class="upload-progress-track"><div class="upload-progress-bar"></div></div>' +
                '<div class="upload-file-extra"><span class="upload-file-detail"></span><span class="upload-file-percent">0%</span></div>' +
              '</div>';

            item.querySelector(".upload-file-name").textContent = file.name;
            item.querySelector(".upload-file-note").textContent = buildPreviewLabel(file) + " | " + formatFileSize(file.size);
            uploadFileList.appendChild(item);
            updateFileCard(index, {
              status: "pending",
              detail: "Waiting in queue",
              percent: 0,
            });
          });
        }

        function updateFileCard(index, options) {
          const item = uploadFileList.querySelector('[data-upload-index="' + String(index) + '"]');
          if (!item) {
            return;
          }

          const statusElement = item.querySelector(".upload-file-status");
          const progressBar = item.querySelector(".upload-progress-bar");
          const detailElement = item.querySelector(".upload-file-detail");
          const percentElement = item.querySelector(".upload-file-percent");
          const meta = statusMeta(options.status || "pending");

          item.classList.remove("is-uploading", "is-success", "is-error");
          if (options.status === "uploading") {
            item.classList.add("is-uploading");
          } else if (options.status === "success") {
            item.classList.add("is-success");
          } else if (options.status === "error") {
            item.classList.add("is-error");
          }

          statusElement.className = "upload-file-status " + meta.className;
          statusElement.innerHTML = '<i class="bx ' + meta.icon + '"></i><span>' + meta.label + "</span>";

          if (typeof options.detail === "string") {
            detailElement.textContent = options.detail;
          }

          if (typeof options.percent === "number") {
            const safePercent = Math.max(0, Math.min(100, Math.round(options.percent)));
            progressBar.style.width = safePercent + "%";
            percentElement.textContent = safePercent + "%";
          }
        }

        function setUploadLockState(isLocked) {
          uploadInProgress = isLocked;
          submitButton.disabled =
            isLocked ||
            campusField.value === "" ||
            collegeField.value === "" ||
            ayField.value === "" ||
            semesterField.value === "" ||
            !(fileField.files && fileField.files.length);
          campusField.disabled = isLocked;
          collegeField.disabled = isLocked;
          ayField.disabled = isLocked;
          semesterField.disabled = isLocked;
          fileField.disabled = isLocked;

          if (!isLocked) {
            campusField.disabled = false;
            collegeField.disabled = campusField.value === "" || collegeField.options.length <= 1;
            ayField.disabled = false;
            semesterField.disabled = false;
            fileField.disabled = false;
            programField.disabled = programField.options.length <= 1;
            yearLevelField.disabled = yearLevelField.options.length <= 1;
            sectionField.disabled = sectionField.options.length <= 1;
          }
        }

        function buildAjaxPayload(file) {
          const formData = new FormData();
          formData.append("upload_exam_permits_csv", "1");
          formData.append("upload_exam_permits_ajax", "1");
          formData.append("campus_id", campusField.value || "");
          formData.append("college_id", collegeField.value || "");
          formData.append("program_id", programField.value || "");
          formData.append("ay_id", ayField.value || "");
          formData.append("semester", semesterField.value || "");
          formData.append("year_level", yearLevelField.value || "");
          formData.append("section_suffix", sectionField.value || "");
          formData.append("exam_permits_csv[]", file, file.name);
          return formData;
        }

        function parseJsonResponse(xhr) {
          if (xhr.response && typeof xhr.response === "object") {
            return xhr.response;
          }

          try {
            return JSON.parse(xhr.responseText || "{}");
          } catch (error) {
            return null;
          }
        }

        function buildResultDetail(result) {
          if (!result || !result.summary) {
            return result && result.details ? result.details : "";
          }

          const detailParts = [
            String(result.summary.subject_code || ""),
            String(result.summary.section_display || ""),
            String(result.summary.student_count || 0) + " student(s)",
          ].filter(Boolean);

          if (result.summary.room_name) {
            detailParts.push(String(result.summary.room_name));
          }

          return detailParts.join(" | ");
        }

        function refreshLatestImportPanel(html) {
          if (!latestImportPanel || typeof html !== "string" || html.trim() === "") {
            return;
          }

          latestImportPanel.innerHTML = html;
        }

        async function handleAjaxDelete(formElement) {
          if (!formElement) {
            return;
          }

          const confirmMessage = formElement.dataset.confirm || "Delete this uploaded batch?";
          if (!window.confirm(confirmMessage)) {
            return;
          }

          const formData = new FormData(formElement);
          formData.append("ajax_latest_import_panel", "1");

          try {
            const response = await fetch(actionUrl, {
              method: "POST",
              body: formData,
              credentials: "same-origin",
            });
            const payload = await response.json();
            refreshLatestImportPanel(payload.latest_panel_html || "");
            showAjaxPageNotice(payload.status || "info", payload.message || "", payload.details || "");
          } catch (error) {
            showAjaxPageNotice("danger", "Unable to delete the selected batch right now.", "Please try again.");
          }
        }

        function uploadSingleFile(file, index, uploadedBytesSoFar, totalBytes, totalFiles) {
          return new Promise(function (resolve) {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", actionUrl, true);
            xhr.responseType = "json";

            xhr.upload.addEventListener("progress", function (event) {
              if (!event.lengthComputable) {
                return;
              }

              const filePercent = event.total > 0 ? (event.loaded / event.total) * 100 : 0;
              const overallPercent = totalBytes > 0 ? ((uploadedBytesSoFar + event.loaded) / totalBytes) * 100 : 0;
              updateFileCard(index, {
                status: "uploading",
                detail: "Sending file to server",
                percent: filePercent,
              });
              setOverallProgress(overallPercent);
            });

            xhr.addEventListener("load", function () {
              const payload = parseJsonResponse(xhr);
              resolve({
                ok: xhr.status >= 200 && xhr.status < 300,
                payload: payload,
              });
            });

            xhr.addEventListener("error", function () {
              resolve({
                ok: false,
                payload: {
                  success: false,
                  message: "Network error while uploading " + file.name + ".",
                  details: "The file was not sent completely. Please try again.",
                },
              });
            });

            currentUploadLabel.textContent = "Uploading " + file.name + " (" + (index + 1) + " of " + totalFiles + ")";
            xhr.send(buildAjaxPayload(file));
          });
        }

        async function runQueuedUpload() {
          const files = fileField.files ? Array.from(fileField.files) : [];
          if (!files.length || uploadInProgress) {
            return;
          }

          if (!form.reportValidity()) {
            return;
          }

          const totalBytes = files.reduce(function (sum, file) {
            return sum + Number(file.size || 0);
          }, 0);
          let uploadedBytesSoFar = 0;
          let successCount = 0;
          let failedCount = 0;
          let latestPanelHtml = "";

          setUploadLockState(true);
          uploadDashboard.classList.remove("d-none");
          uploadDashboard.classList.add("is-uploading");
          setUploadSummary("", "success");
          showAjaxPageNotice("", "", "");

          for (let index = 0; index < files.length; index += 1) {
            const file = files[index];
            updateFileCard(index, {
              status: "uploading",
              detail: "Preparing upload",
              percent: 0,
            });

            const result = await uploadSingleFile(file, index, uploadedBytesSoFar, totalBytes, files.length);
            uploadedBytesSoFar += Number(file.size || 0);
            setOverallProgress(totalBytes > 0 ? (uploadedBytesSoFar / totalBytes) * 100 : 100);

            const fileResult = result.payload && Array.isArray(result.payload.files) && result.payload.files.length
              ? result.payload.files[0]
              : null;
            const isSuccess = !!(result.ok && result.payload && (result.payload.success || (fileResult && fileResult.success)));
            if (result.payload && typeof result.payload.latest_panel_html === "string" && result.payload.latest_panel_html.trim() !== "") {
              latestPanelHtml = result.payload.latest_panel_html;
            }

            if (isSuccess) {
              successCount += 1;
              updateFileCard(index, {
                status: "success",
                detail: buildResultDetail(fileResult),
                percent: 100,
              });
            } else {
              failedCount += 1;
              updateFileCard(index, {
                status: "error",
                detail: fileResult && fileResult.message ? fileResult.message : (result.payload && result.payload.message ? result.payload.message : "Upload failed"),
                percent: 100,
              });
            }
          }

          refreshLatestImportPanel(latestPanelHtml);
          showAjaxPageNotice(
            failedCount > 0 ? (successCount > 0 ? "warning" : "danger") : "success",
            successCount + " file(s) uploaded successfully" + (failedCount > 0 ? " | " + failedCount + " file(s) failed" : ""),
            ""
          );
          uploadDashboard.classList.remove("is-uploading");
          currentUploadLabel.textContent = failedCount > 0
            ? "Upload finished with some issues"
            : "All selected files uploaded successfully";
          setUploadSummary(
            successCount + " file(s) uploaded successfully" + (failedCount > 0 ? " | " + failedCount + " file(s) failed" : ""),
            failedCount > 0 ? (successCount > 0 ? "warning" : "danger") : "success"
          );
          setUploadLockState(false);
        }

        function parseScopeFromFilename(filename) {
          const baseName = String(filename || "")
            .replace(/\.[^.]+$/, "")
            .trim()
            .toLowerCase();
          const explicitMatch =
            baseName.match(/(?:^|[-_ ])(bsis|bsit|bscs|is|it|cs)[-_ ]?([1-6i])([a-i])$/i) ||
            baseName.match(/(bsis|bsit|bscs|is|it|cs)([1-6i])([a-i])$/i);

          if (explicitMatch) {
            return {
              programCode: normalizeProgramCode(explicitMatch[1]),
              yearLevel: String(explicitMatch[2]).toLowerCase() === "i" ? 1 : Number(explicitMatch[2]),
              sectionSuffix: String(explicitMatch[3]).toUpperCase(),
            };
          }

          const inferredMatch = baseName.match(/^(.*?)(?:[-_ ])([1-6i])([a-i])$/i);
          if (!inferredMatch) {
            return null;
          }

          const inferredProgramCode = inferProgramCodeFromSubjectPrefix(inferredMatch[1]);
          if (!inferredProgramCode) {
            return null;
          }

          return {
            programCode: inferredProgramCode,
            yearLevel: String(inferredMatch[2]).toLowerCase() === "i" ? 1 : Number(inferredMatch[2]),
            sectionSuffix: String(inferredMatch[3]).toUpperCase(),
          };
        }

        function findProgramsByCode(programCode) {
          return programs.filter(function (program) {
            return Number(program.program_id || 0) > 0 && normalizeProgramCode(program.program_code) === programCode;
          });
        }

        function chooseProgramCandidate(candidates) {
          if (!candidates.length) {
            return null;
          }

          const currentProgramId = Number(programField.value || stickyScope.program_id || 0);
          const currentCollegeId = Number(collegeField.value || stickyScope.college_id || 0);
          const currentCampusId = Number(campusField.value || 0);
          const exactProgramMatch = candidates.find(function (program) {
            return Number(program.program_id || 0) === currentProgramId;
          }) || null;

          if (exactProgramMatch) {
            return exactProgramMatch;
          }

          const collegeMatches = candidates.filter(function (program) {
            return Number(program.college_id || 0) === currentCollegeId;
          });
          if (collegeMatches.length === 1) {
            return collegeMatches[0];
          }

          const campusMatches = candidates.filter(function (program) {
            const parentCollege = getCollegeById(program.college_id);
            return parentCollege && Number(parentCollege.campus_id || 0) === currentCampusId;
          });
          if (campusMatches.length === 1) {
            return campusMatches[0];
          }

          if (candidates.length === 1) {
            return candidates[0];
          }

          return null;
        }

        function applyProgramFromFilename(programRecord) {
          if (!programRecord) {
            return false;
          }

          const parentCollege = getCollegeById(programRecord.college_id);
          if (!parentCollege) {
            return false;
          }

          campusField.value = String(parentCollege.campus_id || "");
          syncColleges(String(programRecord.college_id || ""), String(programRecord.program_id || ""));

          if (collegeField.value !== String(programRecord.college_id || "")) {
            return false;
          }

          if (programField.value !== String(programRecord.program_id || "")) {
            syncPrograms(String(programRecord.program_id || ""));
          }

          return programField.value === String(programRecord.program_id || "");
        }

        function syncPrograms(selectedProgramId) {
          const collegeId = Number(collegeField.value || 0);
          resetSelect(programField, "Select program");
          resetSectionScope();
          if (collegeId <= 0) {
            return;
          }

          const filteredPrograms = programs.filter(function (program) {
            return Number(program.program_id || 0) > 0 && Number(program.college_id || 0) === collegeId;
          });

          fillSelect(programField, filteredPrograms, "program_id", function (program) {
            return String(program.program_code || "") + " - " + String(program.source_program_name || program.program_name || "");
          }, selectedProgramId);
        }

        function syncColleges(selectedCollegeId, selectedProgramId) {
          const campusId = Number(campusField.value || 0);
          resetSelect(collegeField, "Select college");
          resetSelect(programField, "Select program");
          resetSectionScope();
          if (campusId <= 0) {
            return;
          }

          const filteredColleges = colleges.filter(function (college) {
            return Number(college.campus_id || 0) === campusId;
          });

          fillSelect(collegeField, filteredColleges, "college_id", function (college) {
            return String(college.college_name || "");
          }, selectedCollegeId);

          if (String(selectedCollegeId || "") !== "" && collegeField.value !== "") {
            syncPrograms(selectedProgramId);
          }
        }

        function syncYearLevels(selectedYearLevel) {
          const currentSectionValue = sectionField.value;
          resetSelect(yearLevelField, "Select year level");
          resetSelect(sectionField, "Select section");
          if (Number(programField.value || 0) <= 0 || Number(ayField.value || 0) <= 0 || Number(semesterField.value || 0) <= 0) {
            return;
          }

          fillSelect(yearLevelField, yearLevels.map(function (yearLevel) {
            return { year_level: yearLevel };
          }), "year_level", function (item) {
            return "Year " + String(item.year_level || "");
          }, selectedYearLevel);

          if (yearLevelField.value !== "") {
            syncSections(currentSectionValue);
          }
        }

        function syncSections(selectedSectionSuffix) {
          resetSelect(sectionField, "Select section");

          const yearLevel = Number(yearLevelField.value || 0);
          const selectedProgram = getSelectedProgram();
          if (yearLevel <= 0 || !selectedProgram) {
            return;
          }

          fillSelect(sectionField, sectionSuffixes.map(function (suffix) {
            return { section_suffix: suffix };
          }), "section_suffix", function (section) {
            return String(selectedProgram.program_code || "") + " " + String(yearLevel) + String(section.section_suffix || "");
          }, selectedSectionSuffix);
        }

        function applyFilenameScope() {
          const selectedFiles = fileField.files ? Array.from(fileField.files) : [];
          const selectedFile = selectedFiles.length === 1 ? selectedFiles[0] : null;
          const parsedScope = parseScopeFromFilename(selectedFile ? selectedFile.name : "");
          const programMatches = parsedScope ? findProgramsByCode(parsedScope.programCode) : [];

          renderSelectedFiles();

          if (!selectedFiles.length) {
            setScopeNotice("Pick one file to preview its program, year level, and section, or pick multiple files and the page will resolve each filename during upload.", "text-muted");
            return;
          }

          if (selectedFiles.length > 1) {
            setScopeNotice(String(selectedFiles.length) + " files selected. Program, year level, and section will be resolved per file from each filename during upload.", "text-muted");
            return;
          }

          if (!parsedScope) {
            resetSectionScope();
            setScopeNotice("The filename pattern was not recognized. Select year level and section manually for this upload.", "text-warning");
            return;
          }

          const chosenProgram = chooseProgramCandidate(programMatches);
          if (!chosenProgram) {
            resetSectionScope();
            if (!programMatches.length) {
              setScopeNotice("Filename matched " + parsedScope.programCode + " Year " + parsedScope.yearLevel + " Section " + parsedScope.sectionSuffix + ", but no active program with that code was found.", "text-warning");
            } else {
              setScopeNotice("Filename matched " + parsedScope.programCode + " Year " + parsedScope.yearLevel + " Section " + parsedScope.sectionSuffix + ", but more than one active " + parsedScope.programCode + " program exists. Select the correct campus or college first, then pick the file again.", "text-warning");
            }
            return;
          }

          if (!applyProgramFromFilename(chosenProgram)) {
            resetSectionScope();
            setScopeNotice("Filename matched " + parsedScope.programCode + ", but the program scope could not be applied automatically.", "text-warning");
            return;
          }

          const selectedProgram = getSelectedProgram();
          if (!selectedProgram || normalizeProgramCode(selectedProgram.program_code) !== parsedScope.programCode) {
            resetSectionScope();
            setScopeNotice("Filename matched " + parsedScope.programCode + ", but the matching program could not be confirmed.", "text-warning");
            return;
          }

          syncYearLevels(parsedScope.yearLevel);
          yearLevelField.value = String(parsedScope.yearLevel);
          syncSections(parsedScope.sectionSuffix);
          sectionField.value = parsedScope.sectionSuffix;
          setScopeNotice("Filled " + String(selectedProgram.program_code || parsedScope.programCode) + " Year " + parsedScope.yearLevel + " Section " + parsedScope.sectionSuffix + " from the filename.", "text-success");
        }

        campusField.addEventListener("change", function () {
          syncColleges();
          applyFilenameScope();
        });
        collegeField.addEventListener("change", function () {
          syncPrograms();
          applyFilenameScope();
        });
        programField.addEventListener("change", function () {
          syncYearLevels();
          applyFilenameScope();
        });
        ayField.addEventListener("change", function () {
          syncYearLevels();
          applyFilenameScope();
        });
        semesterField.addEventListener("change", function () {
          syncYearLevels();
          applyFilenameScope();
        });
        yearLevelField.addEventListener("change", syncSections);

        if (campusField.value !== "") {
          syncColleges(stickyScope.college_id, stickyScope.program_id);
          if (collegeField.value !== "" && programField.value !== "") {
            syncYearLevels();
          }
        }

        fileField.addEventListener("change", applyFilenameScope);
        submitButton.addEventListener("click", function () {
          runQueuedUpload();
        });
        if (latestImportPanel) {
          latestImportPanel.addEventListener("submit", function (event) {
            const targetForm = event.target;
            if (!(targetForm instanceof HTMLFormElement) || !targetForm.classList.contains("ajax-delete-batch-form")) {
              return;
            }

            event.preventDefault();
            handleAjaxDelete(targetForm);
          });
        }
        form.addEventListener("submit", function (event) {
          event.preventDefault();
          runQueuedUpload();
        });
        renderSelectedFiles();
      })();
    </script>
  </body>
</html>
