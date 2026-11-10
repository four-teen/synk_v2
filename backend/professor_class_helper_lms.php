<?php
require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/professor_workload_view_helper.php';
require_once __DIR__ . '/professor_class_link_helper_lms.php';

// Only read existing workload ownership; LMS cannot assign or reschedule a class.
function synk_professor_class_context_lms(mysqli $coreConnection, array $input): array
{
    if (($_SESSION['role'] ?? '') !== 'professor' || empty($_SESSION['user_id'])) {
        throw new DomainException('Sign in as a professor to open this class.', 401);
    }
    $account = synk_find_useraccount_by_id($coreConnection, (int)$_SESSION['user_id']);
    if (!$account || $account['status'] !== 'active' || !synk_useraccount_has_active_role($coreConnection, $account, 'professor')) {
        throw new DomainException('Your professor account is no longer available.', 403);
    }
    $portal = synk_professor_resolve_portal_context($coreConnection);
    if (!$portal['faculty_is_linked'] || !$portal['faculty_is_active']) {
        throw new DomainException('An active faculty link is required to open this class.', 403);
    }
    $ids = [];
    foreach (['offering_id', 'ay_id', 'semester'] as $field) {
        $value = $input[$field] ?? null;
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false) {
            throw new DomainException('Choose a valid class from your workload.', 400);
        }
        $ids[$field] = (int)$value;
    }
    if (!in_array($ids['semester'], [1, 2, 3], true)) throw new DomainException('Choose a valid academic semester.', 400);
    $view = $input['view'] ?? 'overview';
    if (!is_string($view) || !in_array($view, ['overview', 'students', 'assessments', 'grading'], true)) throw new DomainException('Choose an available class view.', 400);
    synk_professor_require_class_link_lms($input, $ids, $view, (int)$portal['faculty_id']);

    $classes = synk_professor_fetch_subject_rows_by_academic_year($coreConnection, (int)$portal['faculty_id'], $ids['ay_id'], $ids['semester']);
    foreach ($classes as $class) {
        if ((int)$class['offering_id'] === $ids['offering_id']) return ['portal' => $portal, 'class' => $class, 'view' => $view];
    }
    throw new DomainException('This class is not available in your assigned workload.', 404);
}
