<?php
require_once __DIR__ . '/management_portal_helper.php';

// Call inside the Management transaction. Lock the existing year/semester and
// the organization's separate Management term; shared settings are read-only.
function synk_management_lock_current_term(mysqli $conn, array $input, array $scope): array
{
    $current = synk_management_fetch_current_period($conn,$scope,true);
    if (($input['current_term'] ?? null) !== $current['key']
        || ($input['current_exam_term'] ?? null) !== $current['exam_term']
        || ($input['current_period_version'] ?? null) !== $current['period_version']) {
        throw new SynkManagementConflict('The current Management period changed or the form is outdated. Reload the page and try again.');
    }
    return $current;
}

function synk_management_save_current_exam_term(mysqli $conn, array $scope, array $input): array
{
    $next = synk_management_exam_term($input['exam_term'] ?? null);
    $conn->begin_transaction();
    try {
        synk_management_lock_assignment($conn,$scope);
        synk_management_require_permission($conn,$scope,'setup');
        $current = synk_management_lock_current_term($conn,$input,$scope);
        if ($current['exam_term'] !== $next || $current['period_version'] === '0') {
            synk_management_execute($conn, 'INSERT INTO tbl_management_period_settings
                (organization_id,college_id,ay_id,semester,current_exam_term,updated_by) VALUES (?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE current_exam_term=VALUES(current_exam_term),revision=revision+1,updated_by=VALUES(updated_by)',
                'iiiisi',[$scope['organization_id'],$scope['college_id'],$current['ay_id'],$current['semester'],$next,(int)$_SESSION['user_id']]);
        }
        $saved = synk_management_fetch_current_period($conn,$scope,true);
        $conn->commit();
        return ['message'=>'Current Management term saved.','current_exam_term'=>$saved['exam_term'],'current_period_version'=>$saved['period_version']];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}

// Payment integration contract: invoke after assignment/term locks, in the same
// transaction as the payment insert. Never accept a payment term from the client.
function synk_management_assert_payment_context(array $scope, array $currentTerm, array $charge): void
{
    if ((int)($charge['organization_id'] ?? 0) !== (int)$scope['organization_id'] || (int)($charge['college_id'] ?? 0) !== (int)$scope['college_id']) {
        throw new DomainException('This charge is outside your assigned organization.');
    }
    if ((int)($charge['ay_id'] ?? 0) !== (int)$currentTerm['ay_id'] || (int)($charge['semester'] ?? 0) !== (int)$currentTerm['semester']) {
        throw new SynkManagementConflict('Payments must use the current academic year and semester.');
    }
    $examTerm = synk_management_exam_term($currentTerm['exam_term'] ?? '');
    // Semester-wide charges have no exam term. Term-specific charges must match.
    if (isset($charge['exam_term']) && $charge['exam_term'] !== null && $charge['exam_term'] !== ''
        && synk_management_exam_term($charge['exam_term']) !== $examTerm) {
        throw new SynkManagementConflict('Payments must use the current Midterm or Final term.');
    }
}
