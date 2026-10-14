<?php

// Reuse Dean views and assets without copying their scheduling or workload code.
function synk_program_chair_render_portal(string $page): void
{
    $pages = ['index', 'programs', 'students', 'reports', 'faculty-workload', 'faculty-profile',
        'manage-faculty-profile', 'faculty-evaluations', 'student-statistics', 'faculty-statistics', 'education-options'];
    if (!in_array($page, $pages, true)) { http_response_code(404); exit; }
    define('SYNK_PROGRAM_CHAIR_VIEW', true);
    $GLOBALS['synk_program_chair_view_buffer_level'] = ob_get_level();
    ob_start(static function (string $html): string {
        $html = preg_replace_callback('/<title>(.*?)<\/title>/s', static function (array $match): string {
            return '<title>' . str_replace('College Dean', 'Program Chair', $match[1]) . '</title>';
        }, $html);
        $html = str_replace(["Dean portal's viewing term", 'this College Dean account', 'COLLEGE DEAN / FACULTY PROFILE'],
            ["Program Chair portal's viewing term", 'this Program Chair account', 'PROGRAM CHAIR / FACULTY PROFILE'], $html);
        // Relative navigation stays inside Program Chair; static assets come from the shared view.
        $html = preg_replace_callback('/((?:src|href)=["\'])([a-z][a-z0-9_-]*\.(?:js|css|svg))([^"\']*)(["\'])/i',
            static function (array $match): string {
                $path = substr($match[2], -3) === '.js'
                    ? 'shared-script.php?file=' . $match[2] . ($match[3] !== '' ? '&' . ltrim($match[3], '?') : '')
                    : '../college-dean/' . $match[2] . $match[3];
                return $match[1] . $path . $match[4];
            }, $html);
        return synk_program_chair_portal_endpoint_urls($html);
    });
    $previousDirectory = getcwd();
    chdir(dirname(__DIR__) . '/college-dean');
    // Shared views intentionally run at file scope: their navbar needs the connection and context.
    $GLOBALS['synk_program_chair_view_file'] = dirname(__DIR__) . '/college-dean/' . $page . '.php';
    if ($previousDirectory !== false) register_shutdown_function(static function () use ($previousDirectory): void { chdir($previousDirectory); });
}

function synk_program_chair_finish_portal(): void
{
    while (ob_get_level() > $GLOBALS['synk_program_chair_view_buffer_level']) ob_end_flush();
}

function synk_program_chair_portal_endpoint_urls(string $source): string
{
    return str_replace([
        '../backend/dashboard_college_dean_schedule_heatmap.php', '../backend/load_college_dean_room_time_matrix.php',
        '../backend/query_college_dean_faculty_workload.php', '../backend/query_college_dean_rosters.php',
        '../backend/query_college_dean_students.php', '../backend/query_college_dean_faculty_students.php',
    ], [
        '../backend/dashboard_program_chair_schedule_heatmap.php', '../backend/load_program_chair_room_time_matrix.php',
        '../backend/query_program_chair_faculty_workload.php', '../backend/query_program_chair_rosters.php',
        '../backend/query_program_chair_students.php', '../backend/query_program_chair_faculty_students.php',
    ], $source);
}
