<?php
// Included only by the authenticated professor controller, after resolving its profile owner.
if (!isset($conn, $userId, $profileOwner, $trainingRecords)) {http_response_code(404); return;}
$trainingNotice = (string)($_SESSION['faculty_profile_training_notice'] ?? '');
unset($_SESSION['faculty_profile_training_notice']);
$trainingState = ['records'=>$trainingRecords, 'record'=>synk_faculty_profile_training_defaults(),
    'errors'=>[], 'error'=>'', 'notice'=>$trainingNotice, 'show_editor'=>false];
$isTrainingPost = $_SERVER['REQUEST_METHOD'] === 'POST' && is_string($profileAction) && strpos($profileAction,'training_') === 0;

if ($isTrainingPost) {
    $activeProfileTab = 'trainings';
    $trainingState['notice'] = '';
    $trainingState['show_editor'] = $profileAction !== 'training_delete';
    [$trainingState['record'], $trainingState['errors']] = synk_faculty_profile_training_validate($_POST);
    $trainingId = $_POST['training_id'] ?? null;
    $trainingRevision = $_POST['training_revision'] ?? null;
    $validVersion = is_string($trainingId) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $trainingId) && (float)$trainingId <= 2147483647
        && is_string($trainingRevision) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $trainingRevision) && (float)$trainingRevision <= 2147483647
        && (($trainingId === '0') === ($trainingRevision === '0'));
    if ($validVersion) {
        $trainingState['record']['training_id'] = (int)$trainingId;
        $trainingState['record']['revision'] = (int)$trainingRevision;
    }
    if ($profileAction === 'training_delete') $trainingState['errors'] = [];
    $token = $_POST['csrf_token'] ?? null;
    $postedOwner = $_POST['profile_owner'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['faculty_profile_csrf'], $token)) {
        http_response_code(403);
        $trainingState['error'] = 'Your form session expired. Reload this page before saving again.';
    } elseif (!is_string($postedOwner) || !hash_equals($profileOwner, $postedOwner)) {
        http_response_code(409);
        $trainingState['error'] = 'Your faculty account connection changed. Reload the page before saving.';
    } elseif (!in_array($profileAction, ['training_save','training_delete'], true) || !$validVersion || ($profileAction === 'training_delete' && $trainingId === '0')) {
        http_response_code(422);
        $trainingState['error'] = 'The training record or version is invalid. Reload the page before saving.';
    } elseif ($trainingState['errors']) {
        http_response_code(422);
    } else {
        try {
            $conn->begin_transaction();
            $freshLink = null;
            if (synk_professor_faculty_link_table_exists($conn)) {
                $stmt = $conn->prepare('SELECT faculty_id FROM tbl_useraccount_faculty_links WHERE user_id=? FOR UPDATE');
                $stmt->bind_param('i', $userId);
                $stmt->execute();
                $freshLink = $stmt->get_result()->fetch_assoc();
                $stmt->close();
            }
            $freshProfile = synk_faculty_profile_fetch($conn, $userId);
            if (!hash_equals($profileOwner, synk_faculty_profile_owner($userId, (int)($freshLink['faculty_id'] ?? 0), $freshProfile))) {
                throw new DomainException('Your faculty account connection changed. Reload the page before saving.');
            }
            $changed = $profileAction === 'training_delete'
                ? synk_faculty_profile_training_delete($conn, $profileOwner, (int)$trainingId, (int)$trainingRevision)
                : synk_faculty_profile_training_save($conn, $profileOwner, $trainingState['record'], (int)$trainingId, (int)$trainingRevision);
            if (!$changed) throw new DomainException('This training was changed or removed in another window, or is no longer available. Your entries are still shown; reload the latest record before saving.');
            $conn->commit();
            $_SESSION['faculty_profile_training_notice'] = $profileAction === 'training_delete' ? 'Training record removed.' : 'Training record saved.';
            header('Location: manage-profile.php?tab=trainings', true, 303);
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            http_response_code($exception instanceof DomainException ? 409 : 500);
            if ($exception instanceof DomainException) $trainingState['error'] = $exception->getMessage();
            else {
                error_log('Faculty training save failed: ' . $exception->getMessage());
                $trainingState['error'] = 'The training record could not be saved. Your entries are still shown; please try again.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['training'])) {
    $activeProfileTab = 'trainings';
    $requestedTraining = $_GET['training'];
    if ($requestedTraining === 'new') $trainingState['show_editor'] = true;
    else {
        $match = null;
        if (is_string($requestedTraining) && preg_match('/^[1-9][0-9]{0,9}$/D', $requestedTraining) && (float)$requestedTraining <= 2147483647) {
            foreach ($trainingRecords as $record) if ((int)$record['training_id'] === (int)$requestedTraining) {$match = $record; break;}
        }
        if ($match) {
            $trainingState['record'] = $match;
            $trainingState['show_editor'] = true;
        } else {
            http_response_code(404);
            $trainingState['error'] = 'This training record is unavailable.';
        }
    }
}
