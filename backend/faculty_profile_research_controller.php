<?php
// Included only by the authenticated professor controller, after resolving its profile owner.
if (!isset($conn, $userId, $profileOwner, $researchRecords)) {http_response_code(404); return;}
$researchNotice = (string)($_SESSION['faculty_profile_research_notice'] ?? '');
unset($_SESSION['faculty_profile_research_notice']);
$researchState = ['records'=>$researchRecords, 'record'=>synk_faculty_profile_research_defaults(),
    'errors'=>[], 'error'=>'', 'notice'=>$researchNotice, 'show_editor'=>false];
$requestedTab = $_GET['tab'] ?? 'profile';
$activeProfileTab = in_array($requestedTab, ['profile','researches','trainings'], true) ? $requestedTab : 'profile';
$profileAction = $_POST['profile_action'] ?? 'profile';
$isResearchPost = $_SERVER['REQUEST_METHOD'] === 'POST' && $profileAction !== 'profile';

if ($isResearchPost) {
    $activeProfileTab = 'researches';
    $researchState['notice'] = '';
    $researchState['show_editor'] = $profileAction !== 'research_delete';
    [$researchState['record'], $researchState['errors']] = synk_faculty_profile_research_validate($_POST);
    $researchId = $_POST['research_id'] ?? null;
    $researchRevision = $_POST['research_revision'] ?? null;
    $validVersion = is_string($researchId) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $researchId) && (float)$researchId <= 2147483647
        && is_string($researchRevision) && preg_match('/^(0|[1-9][0-9]{0,9})$/D', $researchRevision) && (float)$researchRevision <= 2147483647
        && (($researchId === '0') === ($researchRevision === '0'));
    if ($validVersion) {
        $researchState['record']['research_id'] = (int)$researchId;
        $researchState['record']['revision'] = (int)$researchRevision;
    }
    if ($profileAction === 'research_delete') $researchState['errors'] = [];
    $token = $_POST['csrf_token'] ?? null;
    $postedOwner = $_POST['profile_owner'] ?? null;
    if (!is_string($token) || !hash_equals($_SESSION['faculty_profile_csrf'], $token)) {
        http_response_code(403);
        $researchState['error'] = 'Your form session expired. Reload this page before saving again.';
    } elseif (!is_string($postedOwner) || !hash_equals($profileOwner, $postedOwner)) {
        http_response_code(409);
        $researchState['error'] = 'Your faculty account connection changed. Reload the page before saving.';
    } elseif (!in_array($profileAction, ['research_save','research_delete'], true) || !$validVersion || ($profileAction === 'research_delete' && $researchId === '0')) {
        http_response_code(422);
        $researchState['error'] = 'The research record or version is invalid. Reload the page before saving.';
    } elseif ($researchState['errors']) {
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
            $changed = $profileAction === 'research_delete'
                ? synk_faculty_profile_research_delete($conn, $profileOwner, (int)$researchId, (int)$researchRevision)
                : synk_faculty_profile_research_save($conn, $profileOwner, $researchState['record'], (int)$researchId, (int)$researchRevision);
            if (!$changed) throw new DomainException('This research was changed or removed in another window, or is no longer available. Your entries are still shown; reload the latest record before saving.');
            $conn->commit();
            $_SESSION['faculty_profile_research_notice'] = $profileAction === 'research_delete' ? 'Research record removed.' : 'Research record saved.';
            header('Location: manage-profile.php?tab=researches', true, 303);
            exit;
        } catch (Throwable $exception) {
            $conn->rollback();
            http_response_code($exception instanceof DomainException ? 409 : 500);
            if ($exception instanceof DomainException) $researchState['error'] = $exception->getMessage();
            else {
                error_log('Faculty research save failed: ' . $exception->getMessage());
                $researchState['error'] = 'The research record could not be saved. Your entries are still shown; please try again.';
            }
        }
    }
} elseif (isset($_GET['research'])) {
    $activeProfileTab = 'researches';
    $requestedResearch = $_GET['research'];
    if ($requestedResearch === 'new') $researchState['show_editor'] = true;
    else {
        $match = null;
        if (is_string($requestedResearch) && preg_match('/^[1-9][0-9]{0,9}$/D', $requestedResearch) && (float)$requestedResearch <= 2147483647) {
            foreach ($researchRecords as $record) if ((int)$record['research_id'] === (int)$requestedResearch) {$match = $record; break;}
        }
        if ($match) {
            $researchState['record'] = $match;
            $researchState['show_editor'] = true;
        } else {
            http_response_code(404);
            $researchState['error'] = 'This research record is unavailable.';
        }
    }
}
