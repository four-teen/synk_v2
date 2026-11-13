<?php

function synk_professor_class_link_secret_lms(int $facultyId, bool $create = false): ?string
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if (($_SESSION['role'] ?? '') !== 'professor' || $userId <= 0 || $facultyId <= 0) return null;
    $state = $_SESSION['professor_lms_class_links'] ?? null;
    // Session regeneration, account switching, and faculty reassignment invalidate old links.
    if (is_array($state) && ($state['user_id'] ?? null) === $userId
        && ($state['faculty_id'] ?? null) === $facultyId && ($state['session_id'] ?? null) === session_id()
        && is_string($state['secret'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $state['secret'])) {
        return $state['secret'];
    }
    if (!$create) return null;
    $secret = bin2hex(random_bytes(32));
    $_SESSION['professor_lms_class_links'] = ['user_id'=>$userId, 'faculty_id'=>$facultyId,
        'session_id'=>session_id(), 'secret'=>$secret];
    return $secret;
}

function synk_professor_class_link_signature_lms(array $ids, string $view, int $facultyId, string $secret): string
{
    $payload = implode("\n", ['synk-professor-class-lms-v1', (string)(int)($_SESSION['user_id'] ?? 0),
        (string)$facultyId, (string)(int)$ids['offering_id'], (string)(int)$ids['ay_id'], (string)(int)$ids['semester'], $view]);
    return hash_hmac('sha256', $payload, $secret);
}

// Called only for class rows loaded by the server from the professor's own workload.
function synk_professor_class_url_lms(array $class, string $view = 'overview'): string
{
    if (!in_array($view, ['overview', 'students', 'assessments', 'grading', 'gradebook', 'announcements'], true)) throw new DomainException('Choose an available class view.', 400);
    $facultyId = (int)($class['faculty_id'] ?? 0);
    $secret = synk_professor_class_link_secret_lms($facultyId, true);
    if ($secret === null) throw new DomainException('Open this class from your workload.', 403);
    $params = ['offering_id'=>(int)$class['offering_id'], 'ay_id'=>(int)$class['ay_id'], 'semester'=>(int)$class['semester']];
    if ($view !== 'overview') $params['view'] = $view;
    $params['signature'] = synk_professor_class_link_signature_lms($params, $view, $facultyId, $secret);
    return 'class_lms.php?' . http_build_query($params);
}

function synk_professor_require_class_link_lms(array $input, array $ids, string $view, int $facultyId): void
{
    $signature = $input['signature'] ?? null;
    $secret = synk_professor_class_link_secret_lms($facultyId);
    if ($secret === null || !is_string($signature) || !preg_match('/^[a-f0-9]{64}$/D', $signature)
        || !hash_equals(synk_professor_class_link_signature_lms($ids, $view, $facultyId, $secret), $signature)) {
        throw new DomainException('Invalid class link. Please open this class from your workload.', 403);
    }
}
