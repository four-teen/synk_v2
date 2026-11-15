<?php
// Reuse Admin's forms and builder while keeping all navigation and requests inside DI.
function synk_di_curriculum_render(string $page, mysqli $conn, array $moduleAccount, array $moduleTerm): void
{
    define('SYNK_DI_CURRICULUM_VIEW', true);
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $token = json_encode((string)$_SESSION['csrf_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ob_start(static function (string $html) use ($token, $page): string {
        $html = str_replace([
            '../backend/query_program.php', '../backend/query_subject_masterlist.php',
            '../backend/query_prospectus.php', '../backend/query_prospectus_browser.php',
            '../backend/query_prospectus_copy.php', '../backend/query_view_prospectus.php',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            'https://cdn.jsdelivr.net/npm/sweetalert2@11',
            '<script src="../assets/js/dashboards-analytics.js"></script>',
        ], [
            '../backend/query_di_catalog.php?catalog=program', '../backend/query_di_catalog.php?catalog=subject',
            '../backend/query_di_prospectus.php', '../backend/query_di_prospectus_browser.php',
            '../backend/query_di_prospectus_copy.php', '../backend/query_di_view_prospectus.php',
            '../program-chair/vendor/select2/select2.min.css', '../program-chair/vendor/select2/select2.min.js',
            '../assets/js/sweetalert2.all.min.js', '',
        ], $html);
        $html = str_replace('</head>', '<link rel="stylesheet" href="custom_css.css"><meta name="di-curriculum-token" content="' . htmlspecialchars(json_decode($token), ENT_QUOTES, 'UTF-8') . '"></head>', $html);
        $jquery = '<script src="../assets/vendor/libs/jquery/jquery.js"></script>';
        $html = str_replace($jquery, $jquery . '<script src="curriculum.js?v=' . filemtime(__DIR__ . '/curriculum.js') . '"></script>', $html);
        if ($page === 'manage-programs.php') $html = str_replace('Program Management', 'Course / Program Management', $html);
        // DI must never advertise the Administrator's cascading deletion behavior.
        $html = str_replace('Delete Prospectus and Related Data', 'Delete Prospectus', $html);
        $html = str_replace('This permanently removes the CMO, its year/semester groups, subjects, generated offerings, schedules, and related workload records.', 'This permanently removes this unused prospectus and its year/semester groups and subjects. Prospectuses assigned to sections, offerings, or saved schedules cannot be deleted.', $html);
        return $html;
    });
    require __DIR__ . '/../administrator/' . $page;
}
