<?php
session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/account-access.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_admin();
$domain = (string)synk_auth_settings()['allowed_domain'];
$roleLabels = synk_hris_role_labels();
$assignedLabels = $roleLabels + ['conflict' => 'Role review needed'];
$roleTones = [SYNK_HRIS_ADMIN => 'green', SYNK_HRIS_USER => 'blue', 'conflict' => 'amber'];
$idOptions = ['options' => ['min_range' => 1]];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['role_action'] ?? '';
    $target = $_POST['faculty_account'] ?? null;
    $parts = is_string($target) ? explode(':', $target, 2) : [];
    $facultyId = filter_var($parts[0] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
    $userId = filter_var($parts[1] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
    $previousRole = $_POST['previous_role'] ?? '';
    $role = is_string($_POST['role_code'] ?? null) ? $_POST['role_code'] : '';
    $kind = 'error';

    if (!synk_hris_valid_post()) {
        $message = 'This form expired. Refresh the page and try again.';
    } elseif (!$facultyId || !$userId || !in_array($action, ['assign', 'edit', 'remove'], true)) {
        $message = 'Select a faculty member and try again.';
    } elseif ($action !== 'assign' && (!is_string($previousRole) || !isset($assignedLabels[$previousRole]))) {
        $message = 'Open the current Kawani assignment and try again.';
    } else {
        try {
            $conn->begin_transaction();
            if ($action === 'remove') {
                synk_hris_remove_employee_role($conn, $account, $facultyId, $userId, $previousRole, $domain);
                $message = 'Kawani role removed. The faculty member still has their Synk account.';
            } else {
                $expectedRole = $action === 'assign' ? '' : $previousRole;
                synk_hris_assign_employee_role($conn, $account, $facultyId, $userId, $role, $expectedRole, $domain);
                $message = $action === 'assign'
                    ? 'Kawani ' . $roleLabels[$role] . ' role assigned.'
                    : 'Kawani role saved as ' . $roleLabels[$role] . '.';
            }
            $conn->commit();
            $kind = 'success';
        } catch (Throwable $error) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
                error_log('Kawani could not roll back a role update: ' . get_class($rollbackError));
            }
            if ($error instanceof DomainException) {
                $message = $error->getMessage();
            } else {
                error_log('Kawani account role update failed: ' . get_class($error));
                $message = 'The Kawani role could not be updated. Try again.';
            }
        }
    }

    $_SESSION['hris_account_flash'] = ['kind' => $kind, 'message' => $message];
    $redirect = 'accounts.php';
    if ($kind === 'error' && $facultyId > 0) $redirect .= '?employee=' . $facultyId;
    header('Location: ' . $redirect, true, 303);
    exit;
}

$flash = $_SESSION['hris_account_flash'] ?? null;
unset($_SESSION['hris_account_flash']);
$token = synk_hris_csrf();
session_write_close();

require_once __DIR__ . '/../backend/user_avatar_helper.php';
require __DIR__ . '/views/navigation.php';
$employees = synk_hris_employee_accounts($conn, $domain);
$employeeIds = synk_hris_employee_id_map($conn);
$selectedFacultyId = filter_var($_GET['employee'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
$available = $assigned = [];
foreach ($employees as $employee) {
    $facultyId = $employee['faculty_id'];
    $employee['employee_id'] = $employeeIds[$facultyId] ?? '';
    $employee['faculty_account'] = $facultyId . ':' . $employee['user_id'];
    if ($employee['role_state'] === '') {
        $available[$facultyId] = $employee;
    } else {
        $assigned[$facultyId] = $employee;
    }
}

$signedInId = (int)$account['user_id'];
$editing = $assigned[$selectedFacultyId] ?? null;
$removing = $editing && $editing['user_id'] !== $signedInId && ($_GET['action'] ?? '') === 'remove';
$view = 'accounts';
$pageTitle = 'Account Management';
require __DIR__ . '/views/layout-start.php';
?>
        <main id="main-content" class="hris-main" tabindex="-1">
            <header class="hris-view-heading">
                <h1>Account Management</h1>
                <p>Assign Kawani access using a faculty member’s existing SKSU Google account.</p>
            </header>

            <?php if (is_array($flash) && is_string($flash['message'] ?? null)): ?>
                <?php $success = ($flash['kind'] ?? '') === 'success'; ?>
                <div class="hris-account-alert hris-account-alert-<?= $success ? 'success' : 'error' ?>" role="<?= $success ? 'status' : 'alert' ?>">
                    <i class="bx <?= $success ? 'bx-check-circle' : 'bx-error-circle' ?>" aria-hidden="true"></i>
                    <span><?= synk_hris_h($flash['message']) ?></span>
                </div>
            <?php endif; ?>

            <section class="hris-card hris-account-assignment" aria-labelledby="role-form-title">
                <h2 id="role-form-title">Assign a Kawani role</h2>
                <?php if ($available): ?>
                    <form class="hris-account-select" method="post" action="accounts.php">
                        <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                        <input type="hidden" name="role_action" value="assign">
                        <div class="hris-account-field" data-faculty-picker>
                            <label id="faculty-member-label" for="employee-select">Faculty member</label>
                            <select id="employee-select" name="faculty_account" required>
                                <option value="">Select a faculty member</option>
                                <?php foreach ($available as $employee): ?>
                                    <?php
                                    $employeeLabel = $employee['faculty_name'];
                                    if ($employee['employee_id'] !== '') $employeeLabel .= ' · ' . $employee['employee_id'];
                                    $employeeLabel .= ' · ' . $employee['email'];
                                    ?>
                                    <option value="<?= synk_hris_h($employee['faculty_account']) ?>"<?= $employee['faculty_id'] === $selectedFacultyId ? ' selected' : '' ?>>
                                        <?= synk_hris_h($employeeLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="hris-account-field">
                            <label for="assign-role">Kawani role</label>
                            <select id="assign-role" name="role_code" required>
                                <option value="">Choose a role</option>
                                <?php foreach ($roleLabels as $code => $label): ?>
                                    <option value="<?= synk_hris_h($code) ?>"><?= synk_hris_h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button class="hris-action hris-action-primary" type="submit"><i class="bx bx-user-plus" aria-hidden="true"></i> Assign</button>
                    </form>
                <?php else: ?>
                    <p class="hris-empty">No faculty are available for a new Kawani assignment.</p>
                <?php endif; ?>
                <?php if ($selectedFacultyId > 0 && !$editing && !isset($available[$selectedFacultyId])): ?>
                    <p class="hris-empty">This faculty member’s Kawani account is unavailable. Select an eligible faculty member.</p>
                <?php endif; ?>
            </section>

            <?php if ($removing): ?>
                <dialog open class="hris-confirm-dialog" id="account-removal" aria-labelledby="remove-account-title" aria-describedby="remove-account-description" data-remove-account-dialog>
                    <div class="hris-confirm-icon"><i class="bx bx-user-x" aria-hidden="true"></i></div>
                    <h2 id="remove-account-title">Remove Kawani account?</h2>
                    <p id="remove-account-description">This ends Kawani access for <strong><?= synk_hris_h($editing['faculty_name']) ?></strong> by removing their role assignment. Their Synk account and faculty profile are kept.</p>
                    <form class="hris-confirm-actions" method="post" action="accounts.php">
                        <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                        <input type="hidden" name="faculty_account" value="<?= synk_hris_h($editing['faculty_account']) ?>">
                        <input type="hidden" name="previous_role" value="<?= synk_hris_h($editing['role_state']) ?>">
                        <input type="hidden" name="role_action" value="remove">
                        <a class="hris-action" href="accounts.php#assigned-access-title" data-cancel-remove autofocus>Cancel</a>
                        <button class="hris-action hris-action-danger hris-action-danger-primary" type="submit">Remove account</button>
                    </form>
                </dialog>
            <?php elseif ($editing): ?>
                <?php $currentRole = $editing['role_state']; ?>
                <section class="hris-card hris-account-assignment" aria-labelledby="account-editor-title">
                    <h2 id="account-editor-title">Edit account</h2>
                    <div class="hris-account-selected">
                        <span class="hris-account-selected-icon"><i class="bx bx-id-card" aria-hidden="true"></i></span>
                        <div class="hris-account-selected-info">
                            <strong><?= synk_hris_h($editing['faculty_name']) ?></strong>
                            <small><?= synk_hris_h($editing['email']) ?></small>
                        </div>
                        <span class="hris-status hris-tone-<?= $roleTones[$currentRole] ?>"><?= synk_hris_h($assignedLabels[$currentRole]) ?></span>
                    </div>
                    <?php if ($editing['user_id'] === $signedInId): ?>
                        <p class="hris-account-protection"><i class="bx bx-lock-alt" aria-hidden="true"></i> Another administrator must change your Kawani role.</p>
                    <?php elseif (!$editing['eligible']): ?>
                        <p class="hris-account-protection"><i class="bx bx-info-circle" aria-hidden="true"></i> An active university account is required to change this role.</p>
                    <?php else: ?>
                        <form class="hris-role-form" method="post" action="accounts.php">
                            <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                            <input type="hidden" name="faculty_account" value="<?= synk_hris_h($editing['faculty_account']) ?>">
                            <input type="hidden" name="previous_role" value="<?= synk_hris_h($currentRole) ?>">
                            <input type="hidden" name="role_action" value="edit">
                            <div class="hris-account-field">
                                <label for="edit-role">Kawani role</label>
                                <select id="edit-role" name="role_code" required>
                                    <?php if (!isset($roleLabels[$currentRole])): ?><option value="">Choose a role</option><?php endif; ?>
                                    <?php foreach ($roleLabels as $code => $label): ?>
                                        <option value="<?= synk_hris_h($code) ?>"<?= $code === $currentRole ? ' selected' : '' ?>><?= synk_hris_h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="hris-role-actions">
                                <a class="hris-action" href="accounts.php#assigned-access-title">Cancel</a>
                                <button class="hris-action hris-action-primary" type="submit">Save changes</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <section class="hris-card hris-directory hris-assigned-access" aria-labelledby="assigned-access-title">
                <div class="hris-panel-heading hris-assigned-heading">
                    <h2 id="assigned-access-title">Assigned Kawani accounts</h2>
                    <span class="hris-assigned-count"><?= number_format(count($assigned)) ?> assigned</span>
                </div>
                <?php if ($assigned): ?>
                    <table class="hris-record-table hris-assigned-table">
                        <thead>
                            <tr><th scope="col">Faculty member</th><th scope="col">Synk account</th><th scope="col">Kawani role</th><th scope="col" class="hris-assigned-action-heading">Actions</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assigned as $employee): ?>
                                <?php
                                $currentRole = $employee['role_state'];
                                $employeeUrl = 'accounts.php?employee=' . $employee['faculty_id'];
                                $record = [
                                    'photo_user_id' => $employee['user_id'],
                                    'photo_faculty_id' => $employee['faculty_id'],
                                ];
                                ?>
                                <tr>
                                    <th scope="row">
                                        <div class="hris-faculty-identity hris-assigned-identity">
                                            <?php require __DIR__ . '/views/faculty-avatar.php'; ?>
                                            <div class="hris-faculty-info">
                                                <strong class="hris-faculty-name"><?= synk_hris_h($employee['faculty_name']) ?></strong>
                                                <small><?= $employee['employee_id'] !== '' ? 'Employee ID ' . synk_hris_h($employee['employee_id']) : 'Employee ID not recorded' ?></small>
                                            </div>
                                        </div>
                                    </th>
                                    <td data-label="Synk account">
                                        <?= synk_hris_h($employee['email'] ?: 'Email not recorded') ?>
                                        <?php if (!$employee['eligible']): ?><small>Unavailable for sign-in</small><?php endif; ?>
                                    </td>
                                    <td data-label="Kawani role">
                                        <span class="hris-status hris-tone-<?= $roleTones[$currentRole] ?>"><?= synk_hris_h($assignedLabels[$currentRole]) ?></span>
                                    </td>
                                    <td class="hris-assigned-action" data-label="Actions">
                                        <?php if ($employee['user_id'] === $signedInId): ?>
                                            <span class="hris-account-protection"><i class="bx bx-lock-alt" aria-hidden="true"></i> Your account</span>
                                        <?php else: ?>
                                            <div class="hris-assigned-actions">
                                                <?php if ($employee['eligible']): ?>
                                                    <a class="hris-action" href="<?= synk_hris_h($employeeUrl) ?>#account-editor-title" aria-label="Edit account for <?= synk_hris_h($employee['faculty_name']) ?>">
                                                        <i class="bx bx-edit" aria-hidden="true"></i> Edit account
                                                    </a>
                                                <?php endif; ?>
                                                <a class="hris-action hris-action-danger" href="<?= synk_hris_h($employeeUrl . '&action=remove') ?>#remove-account-title" aria-label="Remove account for <?= synk_hris_h($employee['faculty_name']) ?>">
                                                    <i class="bx bx-user-x" aria-hidden="true"></i> Remove account
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="hris-empty">No faculty members have a Kawani role yet.</p>
                <?php endif; ?>
            </section>
<?php require __DIR__ . '/views/layout-end.php'; ?>
