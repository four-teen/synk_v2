<?php
require_once __DIR__ . '/management_portal_helper.php';

// Call inside the write transaction. Lock the settings row until commit so a
// concurrent Academic Settings change cannot split a record across terms.
function synk_management_lock_current_term(mysqli $conn, array $input): array
{
    $rows = synk_management_query($conn,'SELECT current_ay_id,current_semester FROM tbl_academic_settings ORDER BY setting_id LIMIT 1 FOR UPDATE');
    $ayId = (int)($rows[0]['current_ay_id'] ?? 0); $semester = (int)($rows[0]['current_semester'] ?? 0);
    if (!$ayId || !in_array($semester,[1,2,3],true) || !synk_management_query($conn,'SELECT ay_id FROM tbl_academic_years WHERE ay_id=?','i',[$ayId])) {
        throw new SynkManagementConflict('An administrator must set the current academic year and semester first.');
    }
    $key = $ayId.':'.$semester;
    if (!is_string($input['current_term'] ?? null) || $input['current_term'] !== $key) {
        throw new SynkManagementConflict('The current academic term changed or the form is outdated. Reload the page and try again.');
    }
    return ['ay_id'=>$ayId,'semester'=>$semester,'key'=>$key];
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
}
