<?php
require_once __DIR__ . '/auth_useraccount.php';

// DI uses its real, revalidated role. These routes never switch academic or scheduler context.
function synk_di_curriculum_account(mysqli $conn, bool $lock = false): array
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0 || ($_SESSION['role'] ?? '') !== 'DI') throw new DomainException('Director for Instruction access is required.', 403);
    if ($lock) {
        synk_di_curriculum_query($conn, 'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE', 'i', [$id]);
        synk_di_curriculum_query($conn, 'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE', 'i', [$id]);
    }
    $account = synk_find_useraccount_by_id($conn, $id);
    if (!$account || ($account['status'] ?? '') !== 'active' || !synk_useraccount_has_active_role($conn, $account, 'DI')) {
        throw new DomainException('Your DI account or role is no longer active.', 403);
    }
    return $account;
}

function synk_di_curriculum_query(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare curriculum request.');
    try {
        if ($types !== '') $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) throw new RuntimeException('Unable to complete curriculum request.');
        $result = $stmt->get_result();
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } finally { $stmt->close(); }
}

function synk_di_curriculum_id($value): int
{
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) {
        throw new DomainException('Choose a valid curriculum record.', 422);
    }
    return (int)$value;
}

function synk_di_curriculum_target(mysqli $conn, string $kind, $value, bool $lock = false): array
{
    $id = synk_di_curriculum_id($value);
    $sql = [
        'program' => 'SELECT program_id FROM tbl_program WHERE program_id=? AND status=\'active\'',
        'header' => 'SELECT prospectus_id,program_id FROM tbl_prospectus_header WHERE prospectus_id=?',
        'year_sem' => 'SELECT prospectus_id,pys_id FROM tbl_prospectus_year_sem WHERE pys_id=?',
        'subject' => 'SELECT y.prospectus_id,ps.pys_id,ps.ps_id FROM tbl_prospectus_subjects ps JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id WHERE ps.ps_id=?',
    ][$kind];
    $rows = synk_di_curriculum_query($conn, $sql . ($lock ? ' FOR UPDATE' : ''), 'i', [$id]);
    if (!$rows) throw new DomainException('This curriculum record is unavailable.', 422);
    return $rows[0];
}

function synk_di_curriculum_unused(mysqli $conn, int $prospectusId): void
{
    // Offerings include unscheduled classes and all terms. Protect their source curriculum too.
    if (synk_di_curriculum_query($conn, 'SELECT offering_id FROM tbl_prospectus_offering WHERE prospectus_id=? OR ps_id IN (SELECT ps.ps_id FROM tbl_prospectus_subjects ps JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id WHERE y.prospectus_id=?) LIMIT 1 FOR UPDATE', 'ii', [$prospectusId,$prospectusId])) {
        throw new DomainException('This prospectus is already used by class offerings. Copy it to a new version before changing its structure, moving, or deleting it.', 409);
    }
    // Saved schedule rows may outlive a workspace offering; never cascade through those references.
    require_once __DIR__ . '/schema_helper.php';
    if (synk_table_exists($conn, 'tbl_section_curriculum') && synk_di_curriculum_query($conn,
        'SELECT prospectus_id FROM tbl_section_curriculum WHERE prospectus_id=? LIMIT 1 FOR UPDATE', 'i', [$prospectusId])) {
        throw new DomainException('This prospectus is assigned to a section. Copy it to a new version before changing it.', 409);
    }
    if (synk_table_exists($conn, 'tbl_section_prospectus') && synk_di_curriculum_query($conn,
        'SELECT s.p_sub_id FROM tbl_section_prospectus s JOIN tbl_prospectus_subjects ps ON ps.ps_id=s.p_sub_id JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id WHERE y.prospectus_id=? LIMIT 1 FOR UPDATE', 'i', [$prospectusId])) {
        throw new DomainException('This prospectus is assigned to section subjects. Copy it to a new version before changing it.', 409);
    }
    if (synk_table_exists($conn, 'tbl_program_schedule_set_row') && synk_di_curriculum_query($conn,
        'SELECT r.ps_id FROM tbl_program_schedule_set_row r JOIN tbl_prospectus_subjects ps ON ps.ps_id=r.ps_id JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id WHERE y.prospectus_id=? LIMIT 1 FOR UPDATE', 'i', [$prospectusId])) {
        throw new DomainException('This prospectus is used by a saved schedule. Copy it to a new version before changing it.', 409);
    }
}

function synk_di_curriculum_authorize(mysqli $conn, string $route): void
{
    $transaction = false;
    try {
        if (!defined('SYNK_DI_CURRICULUM_API') || SYNK_DI_CURRICULUM_API !== true) throw new DomainException('Use the DI curriculum portal.', 403);
        if ($route === 'view') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') throw new DomainException('Use a GET request.', 405);
            synk_di_curriculum_account($conn);
            synk_di_curriculum_id($_GET['prospectus_id'] ?? null);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Use a POST request.', 405);
        foreach ($_POST as $value) if (!is_string($value)) throw new DomainException('Invalid curriculum request.', 400);
        $actions = [
            'builder' => ['load_subject_for_edit','update_prospectus_subject','delete_year_sem','get_all_subjects','load_prospectus_list','list_headers','load_header','save_header','save_year_sem','save_prospectus_subject','delete_prospectus_subject','load_year_sem'],
            'browser' => ['load_browser_filters','load_browser_prospectuses','load_prospectus_by_program','update_prospectus_header','transfer_prospectus','delete_prospectus'],
            'copy' => ['load_programs','copy_prospectus'],
            'program' => ['load_programs','save_program','update_program','delete_program'],
            'subject' => ['load_subjects','save_subject','update_subject','delete_subject'],
        ];
        $allActions = array_unique(array_merge(...array_values($actions)));
        $selected = array_values(array_intersect(array_keys($_POST), $allActions));
        if (count($selected) !== 1 || !in_array($selected[0], $actions[$route] ?? [], true)) throw new DomainException('Choose one curriculum action.', 400);
        $action = $selected[0];
        $writing = !in_array($action, ['load_subject_for_edit','get_all_subjects','load_prospectus_list','list_headers','load_header','load_year_sem','load_browser_filters','load_browser_prospectuses','load_prospectus_by_program','load_programs','load_subjects'], true);
        if ($writing) {
            if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) throw new DomainException('Reload this page before managing curriculum.', 403);
            $conn->begin_transaction(); $transaction = true;
        }
        synk_di_curriculum_account($conn, $writing);
        $target = null;
        if (in_array($action, ['save_header','load_prospectus_by_program'], true)) $target = synk_di_curriculum_target($conn, 'program', $_POST['program_id'] ?? null, $writing);
        if (in_array($action, ['load_header','save_year_sem','load_year_sem','update_prospectus_header','transfer_prospectus','delete_prospectus'], true)) $target = synk_di_curriculum_target($conn, 'header', $_POST['prospectus_id'] ?? null, $writing);
        if (in_array($action, ['save_prospectus_subject','delete_year_sem'], true)) $target = synk_di_curriculum_target($conn, 'year_sem', $_POST['pys_id'] ?? null, $writing);
        if (in_array($action, ['load_subject_for_edit','update_prospectus_subject','delete_prospectus_subject'], true)) $target = synk_di_curriculum_target($conn, 'subject', $_POST['ps_id'] ?? null, $writing);
        if ($action === 'copy_prospectus') synk_di_curriculum_target($conn, 'header', $_POST['source_prospectus_id'] ?? null, true);
        if (in_array($action, ['copy_prospectus','transfer_prospectus'], true)) synk_di_curriculum_target($conn, 'program', $_POST['target_program_id'] ?? null, true);
        if ($writing && isset($target['prospectus_id'])) {
            synk_di_curriculum_target($conn, 'header', $target['prospectus_id'], true);
            synk_di_curriculum_unused($conn, (int)$target['prospectus_id']);
        }
        if ($action === 'save_year_sem' && (!in_array($_POST['year_level'] ?? '', ['1','2','3','4'], true) || !in_array($_POST['semester'] ?? '', ['1','2','3'], true))) throw new DomainException('Choose a valid year level and semester.', 422);
        if (in_array($action, ['save_prospectus_subject','update_prospectus_subject'], true)) {
            $subId = synk_di_curriculum_id($_POST['sub_id'] ?? null);
            if (!synk_di_curriculum_query($conn, "SELECT sub_id FROM tbl_subject_masterlist WHERE sub_id=? AND status='active' FOR UPDATE", 'i', [$subId])) throw new DomainException('Choose an active subject.', 422);
            foreach (['lec_units','lab_units'] as $field) {
                $number = $_POST[$field] ?? '0';
                if (!is_numeric($number) || !is_finite((float)$number) || (float)$number < 0 || (float)$number > 255) throw new DomainException('Enter valid lecture and laboratory hours.', 422);
            }
            if (!preg_match('/^[1-9][0-9]{0,6}$/D', $_POST['sort_order'] ?? '1')) throw new DomainException('Enter a valid subject order.', 422);
            if (synk_di_curriculum_query($conn, 'SELECT ps_id FROM tbl_prospectus_subjects WHERE pys_id=? AND sub_id=? AND ps_id<>? FOR UPDATE', 'iii', [(int)$target['pys_id'],$subId,(int)($target['ps_id'] ?? 0)])) throw new DomainException('This subject already exists in that year and semester.', 422);
            $raw = $_POST['prerequisite_sub_ids'] ?? '';
            $ids = $raw === '' || $raw === 'null' ? [] : json_decode($raw, true);
            if (!is_array($ids) || array_values($ids) !== $ids || count($ids) > 100) throw new DomainException('Choose valid prerequisites.', 422);
            $codes = []; $normalized = [];
            foreach ($ids as $id) {
                $id = synk_di_curriculum_id($id);
                if ($id === $subId) throw new DomainException('A subject cannot be its own prerequisite.', 422);
                if (isset($normalized[$id])) continue;
                $subject = synk_di_curriculum_query($conn, "SELECT sub_code FROM tbl_subject_masterlist WHERE sub_id=? AND status='active'", 'i', [$id]);
                if (!$subject) throw new DomainException('Choose active prerequisites.', 422);
                $normalized[$id] = (string)$id; $codes[] = $subject[0]['sub_code'];
            }
            $_POST['prerequisite_sub_ids'] = $normalized ? json_encode(array_values($normalized)) : null;
            $_POST['prerequisites'] = implode(', ', $codes);
            if (strlen($_POST['prerequisites']) > 255) throw new DomainException('The prerequisite list is too long.', 422);
        }
        if ($writing) {
            // Shared handlers exit after responding; commit only a successful complete response.
            ob_start(static function (string $body) use ($conn): string {
                try {
                    if (http_response_code() < 400 && preg_match('/^(?:(?:OK|EXISTS|EXISTING)\||success$|updated$|deleted$)/', trim($body))) $conn->commit();
                    else $conn->rollback();
                } catch (Throwable $exception) {
                    try { $conn->rollback(); } catch (Throwable $ignored) {}
                    error_log('DI curriculum transaction: ' . $exception->getMessage());
                    http_response_code(500); return 'ERROR|Curriculum changes could not be saved.';
                }
                return $body;
            });
            if ($action === 'delete_prospectus') {
                // DI deletion touches curriculum tables only; never run Admin's academic-data cascade.
                synk_di_curriculum_query($conn, 'DELETE ps FROM tbl_prospectus_subjects ps JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id WHERE y.prospectus_id=?', 'i', [(int)$target['prospectus_id']]);
                synk_di_curriculum_query($conn, 'DELETE FROM tbl_prospectus_year_sem WHERE prospectus_id=?', 'i', [(int)$target['prospectus_id']]);
                synk_di_curriculum_query($conn, 'DELETE FROM tbl_prospectus_header WHERE prospectus_id=?', 'i', [(int)$target['prospectus_id']]);
                exit('OK|Prospectus removed successfully.');
            }
        }
    } catch (Throwable $exception) {
        if ($transaction) $conn->rollback();
        synk_di_curriculum_error($exception);
    }
}

function synk_di_curriculum_error(Throwable $exception): void
{
    $status = $exception instanceof DomainException ? $exception->getCode() : 500;
    http_response_code(in_array($status, [400,403,405,409,422], true) ? $status : 500);
    if (!$exception instanceof DomainException) error_log('DI curriculum: ' . $exception->getMessage());
    exit('ERROR|' . ($exception instanceof DomainException ? $exception->getMessage() : 'Unable to manage curriculum. Please try again.'));
}
