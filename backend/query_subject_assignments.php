<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/subject_assignments_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new DomainException('Use a GET request to view subject assignments.', 405);
    $scope = synk_subject_assignments_scope($conn);
    $requestedCollege = synk_subject_assignments_int($_GET, 'college_id', $scope['college_id']);
    if ($requestedCollege !== $scope['college_id']) throw new DomainException('Your college workspace changed. Refresh this page.', 409);
    session_write_close();
    $options = synk_subject_assignments_options($conn, $scope);
    $action = synk_subject_assignments_scalar($_GET, 'action', 'list');
    if ($action === 'options') {
        $response = ['status'=>'ok', 'college_id'=>$scope['college_id'], 'options'=>$options];
    } elseif (in_array($action, ['list','detail'], true)) {
        $filters = synk_subject_assignments_filters($_GET, $options);
        $groups = synk_subject_assignments_data($conn, $scope, $filters);
        if ($action === 'list') {
            $response = array_merge(['status'=>'ok', 'college_id'=>$scope['college_id'], 'term'=>$filters['term']], synk_subject_assignments_list($groups, $filters));
        } else {
            $subjectId = synk_subject_assignments_int($_GET, 'subject_id');
            $programId = synk_subject_assignments_int($_GET, 'program_id');
            $group = $groups[$subjectId . ':' . $programId] ?? null;
            if (!$subjectId || !$programId || !$group) throw new DomainException('This subject is not offered in the selected program and term.', 404);
            $current = synk_fetch_current_academic_term($conn);
            $isCurrent = $current['ay_id'] === $filters['term']['ay_id'] && $current['semester'] === $filters['term']['semester'];
            $faculty = array_values($group['faculty']);
            usort($faculty, static function(array $a, array $b): int { return strnatcasecmp($a['name'], $b['name']); });
            foreach ($faculty as &$person) {
                $person['workload_url'] = $isCurrent && $person['can_open_workload'] ? 'manage-workload.php?' . http_build_query([
                    'faculty_id'=>$person['faculty_id'], 'ay_id'=>$current['ay_id'], 'semester'=>$current['semester'], 'college_id'=>$scope['college_id']]) : '';
                unset($person['can_open_workload']);
            }
            unset($person);
            $response = ['status'=>'ok', 'college_id'=>$scope['college_id'], 'detail'=>[
                'subject_id'=>$subjectId, 'program_id'=>$programId, 'subject_code'=>$group['subject_code'],
                'description'=>$group['description'], 'program'=>$group['program'], 'term'=>$filters['term']['label'],
                'faculty'=>$faculty, 'needs'=>array_values($group['needs']), 'section_count'=>count($group['sections']),
                'unassigned_meetings'=>$group['unassigned_meetings'], 'meetings'=>$group['meetings'], 'is_current'=>$isCurrent]];
        }
    } else throw new DomainException('Choose a valid subject assignment action.', 422);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
} catch (DomainException $error) {
    http_response_code($error->getCode() >= 400 ? $error->getCode() : 422);
    echo json_encode(['status'=>'error', 'message'=>$error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Subject assignments: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['status'=>'error', 'message'=>'Unable to load subject assignments. Please try again.']);
}
