<?php // Shared by the desktop subject row and its mobile card.
if (!isset($row) || !is_array($row) || ($_SESSION['role'] ?? '') !== 'professor') { http_response_code(404); return; }
require_once __DIR__ . '/../backend/professor_class_link_helper_lms.php'; ?>
<a class="btn btn-primary workload-manage-class-lms" href="<?= synk_professor_h(synk_professor_class_url_lms($row)) ?>" aria-label="<?= synk_professor_h('Manage Class for '.$row['subject_code'].' '.$row['section_display']) ?>"><i class="bx bx-book-open" aria-hidden="true"></i>Manage Class</a>
