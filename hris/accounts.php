<?php
session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/account-access.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_permission('accounts');
$domain = (string)synk_auth_settings()['allowed_domain'];
$idOptions = ['options' => ['min_range' => 1]];
$ready = synk_hris_account_permissions_ready($conn) && synk_hris_secondary_role_ready($conn);
$canAssignAdministrators = synk_hris_active_role($account) === SYNK_HRIS_ADMIN;
$roles = synk_hris_role_labels();
$roleLabels = $roles + ['conflict' => 'Role review needed'];
if (!$canAssignAdministrators) unset($roles[SYNK_HRIS_ADMIN]);
$roleTones = [SYNK_HRIS_ADMIN => 'green', SYNK_HRIS_USER => 'blue', 'conflict' => 'amber'];
$grantablePermissionOptions = array_intersect_key(synk_hris_permission_options(), array_flip(synk_hris_grantable_permissions($account)));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['role_action'] ?? '';
    $facultyId = filter_var($_POST['faculty_id'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
    $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
    $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
    $email = is_string($_POST['official_email'] ?? null) ? $_POST['official_email'] : '';
    $submittedRoles = $_POST['roles'] ?? [];
    $submittedPermissions = $_POST['permissions'] ?? [];
    $returnToWorkspace = false;
    $kind = 'error';
    try {
        if (!synk_hris_valid_post()) throw new DomainException('This form expired. Refresh the page and try again.');
        if (!$ready) throw new DomainException('Kawani account setup is required before assigning access.');
        if ($action === 'assign' ? !$facultyId : (!in_array($action, ['edit', 'remove'], true) || !$accountId || !$revision)) {
            throw new DomainException('Open the employee’s account and try again.');
        }
        if ($action !== 'remove' && !is_array($submittedRoles)) throw new DomainException('Select at least one Kawani role.');
        if ($action !== 'remove' && in_array(SYNK_HRIS_USER, $submittedRoles, true)
            && (($_POST['permissions_set'] ?? '') !== '1' || !is_array($submittedPermissions))) {
            throw new DomainException('Review the User permissions and save the assignment again.');
        }
        $updatedOwnAccount = synk_hris_manage_employee_account($conn, $account, $action, $facultyId, $accountId, $revision, $email,
            is_array($submittedRoles) ? $submittedRoles : [],
            is_array($submittedPermissions) ? $submittedPermissions : [], $domain);
        if ($updatedOwnAccount) {
            $_SESSION['hris_identity']['revision'] = (int)$updatedOwnAccount['revision'];
            synk_hris_set_session_role($updatedOwnAccount['active_role']);
            $returnToWorkspace = !synk_hris_can($updatedOwnAccount, 'accounts');
        }
        $kind = 'success';
        $message = $action === 'remove' ? 'Kawani access removed.' : ($action === 'assign' ? 'Kawani access assigned.' : 'Kawani account saved.');
    } catch (DomainException $error) {
        if ($error->getCode() === 403) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            exit($error->getMessage());
        }
        $message = $error->getMessage();
    } catch (Throwable $error) {
        error_log('Kawani account update failed. ' . get_class($error) . ' ' . $error->getCode());
        $message = 'The Kawani account could not be saved. Try again.';
    }

    $_SESSION['hris_account_flash'] = ['kind' => $kind, 'message' => $message, 'workspace' => $returnToWorkspace];
    $redirect = $returnToWorkspace ? './' : 'accounts.php#assigned-access-title';
    if ($kind === 'error') {
        $redirect = $action === 'assign' && $facultyId ? 'accounts.php?employee=' . $facultyId . '#role-form-title' : 'accounts.php';
        if ($action === 'edit' && $accountId) $redirect = 'accounts.php?account=' . $accountId . '#account-editor-title';
        if (synk_hris_valid_post() && in_array($action, ['assign', 'edit'], true)) {
            $_SESSION['hris_account_flash']['form'] = compact('action', 'facultyId', 'accountId') + [
                'email' => substr($email, 0, 254),
                'roles' => is_array($submittedRoles) ? synk_hris_known_roles($submittedRoles) : [],
                'permissions' => is_array($submittedPermissions) ? synk_hris_known_permissions($submittedPermissions) : [],
            ];
        }
    }
    header('Location: ' . $redirect, true, 303);
    exit;
}

$flash = $_SESSION['hris_account_flash'] ?? null;
$retry = $flash['form'] ?? [];
unset($_SESSION['hris_account_flash']);
$token = synk_hris_csrf();
session_write_close();

require_once __DIR__ . '/../backend/user_avatar_helper.php';
require __DIR__ . '/views/navigation.php';
$available = $assigned = $byFaculty = [];
if ($ready) {
    try {
        [$available, $assigned, $byFaculty] = synk_hris_account_directory($conn, $account);
    } catch (mysqli_sql_exception $error) {
        error_log('Kawani account directory failed. Database error ' . $error->getCode());
        $ready = false;
    }
}
$selectedFacultyId = filter_var($_GET['employee'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
$selectedAccountId = filter_var($_GET['account'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: ($byFaculty[$selectedFacultyId] ?? 0);
$signedInId = (int)$account['account_id'];
$editing = $assigned[$selectedAccountId] ?? null;
$removing = $editing && $selectedAccountId !== $signedInId && ($_GET['action'] ?? '') === 'remove';
$assignRetry = ($retry['action'] ?? '') === 'assign' && (int)$retry['facultyId'] === $selectedFacultyId;
$editRetry = ($retry['action'] ?? '') === 'edit' && (int)$retry['accountId'] === $selectedAccountId;
$view = 'accounts';
$pageTitle = 'Account Management';
require __DIR__ . '/views/layout-start.php';
?>
<main id="main-content" class="hris-main" tabindex="-1">
    <header class="hris-view-heading">
        <h1>Account Management</h1>
        <p><?= $canAssignAdministrators
            ? 'Manage Kawani access for teaching and non-teaching employees using their official SKSU Google email.'
            : 'Manage User accounts within the permissions assigned to you.' ?></p>
    </header>

    <?php require __DIR__ . '/views/account-alert.php'; ?>

    <?php if (!$ready): ?>
        <p class="hris-notice" role="status"><i class="bx bx-info-circle" aria-hidden="true"></i> Kawani account setup is unavailable.</p>
    <?php else: ?>
        <section class="hris-card hris-account-assignment" aria-labelledby="role-form-title">
            <h2 id="role-form-title">Assign Kawani access</h2>
            <?php if ($available): ?>
                <?php
                $selectedRoles = $assignRetry ? ($retry['roles'] ?? []) : ($canAssignAdministrators ? [] : [SYNK_HRIS_USER]);
                $roleChoices = $roles;
                $fixedRoles = [];
                $permissionOptions = $grantablePermissionOptions;
                $checkedPermissions = $assignRetry ? ($retry['permissions'] ?? []) : [];
                ?>
                <form class="hris-account-select" method="post" action="accounts.php">
                    <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                    <input type="hidden" name="role_action" value="assign">
                    <div class="hris-account-field" data-employee-picker>
                        <label id="employee-picker-label" for="employee-select">Employee</label>
                        <select id="employee-select" name="faculty_id" required>
                            <option value="">Select an employee</option>
                            <?php foreach ($available as $employee): ?>
                                <?php $label = implode(' · ', array_filter([$employee['employee_name'], $employee['employee_id'], $employee['email']], 'strlen')); ?>
                                <option value="<?= $employee['faculty_id'] ?>" data-email="<?= synk_hris_h($employee['email']) ?>"<?= $employee['faculty_id'] === $selectedFacultyId ? ' selected' : '' ?>><?= synk_hris_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hris-account-field">
                        <label for="assign-email">Official SKSU Google email</label>
                        <input id="assign-email" name="official_email" type="email" maxlength="254" autocomplete="off" required data-assignment-email
                            value="<?= synk_hris_h($assignRetry ? $retry['email'] : ($available[$selectedFacultyId]['email'] ?? '')) ?>">
                    </div>
                    <?php require __DIR__ . '/views/account-roles.php'; ?>
                    <?php require __DIR__ . '/views/account-permissions.php'; ?>
                    <button class="hris-action hris-action-primary hris-account-submit" type="submit"><i class="bx bx-user-plus" aria-hidden="true"></i> Assign</button>
                </form>
            <?php else: ?>
                <p class="hris-empty">No employees are available for a new Kawani assignment.</p>
            <?php endif; ?>
        </section>

        <?php if ($removing): ?>
            <dialog open class="hris-confirm-dialog" id="account-removal" aria-labelledby="remove-account-title" aria-describedby="remove-account-description" data-remove-account-dialog>
                <div class="hris-confirm-icon"><i class="bx bx-user-x" aria-hidden="true"></i></div>
                <h2 id="remove-account-title">Remove Kawani access?</h2>
                <p id="remove-account-description"><strong><?= synk_hris_h($editing['employee_name']) ?></strong> will lose access to Kawani.</p>
                <form class="hris-confirm-actions" method="post" action="accounts.php">
                    <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                    <input type="hidden" name="account_id" value="<?= $selectedAccountId ?>">
                    <input type="hidden" name="revision" value="<?= (int)$editing['revision'] ?>">
                    <input type="hidden" name="role_action" value="remove">
                    <a class="hris-action" href="accounts.php#assigned-access-title" data-cancel-remove autofocus>Cancel</a>
                    <button class="hris-action hris-action-danger hris-action-danger-primary" type="submit">Remove account</button>
                </form>
            </dialog>
        <?php elseif ($editing): ?>
            <?php
            $ownAccount = $selectedAccountId === $signedInId;
            $currentRoles = synk_hris_assigned_roles($editing);
            $badgeRoles = $currentRoles ?: [$editing['role_code']];
            $fixedRoles = $ownAccount && !$canAssignAdministrators ? $currentRoles : [];
            $selectedRoles = $fixedRoles ?: ($editRetry ? ($retry['roles'] ?? []) : $currentRoles);
            $roleChoices = $fixedRoles ? array_intersect_key($roleLabels, array_flip($fixedRoles)) : $roles;
            $checkedPermissions = $editRetry ? ($retry['permissions'] ?? []) : synk_hris_user_permissions($editing);
            $permissionOptions = $fixedRoles
                ? array_intersect_key(synk_hris_permission_options(), array_flip(synk_hris_user_permissions($editing)))
                : $grantablePermissionOptions;
            ?>
            <section class="hris-card hris-account-assignment" aria-labelledby="account-editor-title">
                <h2 id="account-editor-title">Edit account</h2>
                <div class="hris-account-selected">
                    <span class="hris-account-selected-icon"><i class="bx bx-id-card" aria-hidden="true"></i></span>
                    <div class="hris-account-selected-info">
                        <strong><?= synk_hris_h($editing['employee_name']) ?></strong>
                        <small><?= synk_hris_h($editing['email']) ?></small>
                    </div>
                    <div class="hris-account-role-tags">
                        <?php foreach ($badgeRoles as $code): ?>
                            <span class="hris-status hris-tone-<?= $roleTones[$code] ?>"><?= synk_hris_h($roleLabels[$code]) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <form class="hris-role-form" method="post" action="accounts.php">
                    <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                    <input type="hidden" name="account_id" value="<?= $selectedAccountId ?>">
                    <input type="hidden" name="revision" value="<?= (int)$editing['revision'] ?>">
                    <input type="hidden" name="role_action" value="edit">
                    <div class="hris-account-field">
                        <label for="edit-email">Official SKSU Google email</label>
                        <input id="edit-email" name="official_email" type="email" maxlength="254" autocomplete="off"
                            <?= $ownAccount ? 'readonly' : 'required' ?>
                            value="<?= synk_hris_h($editRetry && !$ownAccount ? $retry['email'] : $editing['email']) ?>">
                    </div>
                    <?php require __DIR__ . '/views/account-roles.php'; ?>
                    <?php require __DIR__ . '/views/account-permissions.php'; ?>
                    <div class="hris-role-actions">
                        <a class="hris-action" href="accounts.php#assigned-access-title">Cancel</a>
                        <button class="hris-action hris-action-primary" type="submit">Save changes</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="hris-card hris-directory hris-assigned-access" aria-labelledby="assigned-access-title">
            <div class="hris-panel-heading hris-assigned-heading">
                <h2 id="assigned-access-title">Assigned Kawani accounts</h2>
                <span class="hris-assigned-count"><?= number_format(count($assigned)) ?> assigned</span>
            </div>
            <?php if ($assigned): ?>
                <table class="hris-record-table hris-assigned-table">
                    <thead><tr><th scope="col">Employee</th><th scope="col">Official email</th><th scope="col">Kawani access</th><th scope="col" class="hris-assigned-action-heading">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($assigned as $id => $employee): ?>
                            <?php
                            $assignedRoles = synk_hris_assigned_roles($employee);
                            $badgeRoles = $assignedRoles ?: [$employee['role_code']];
                            $employeeUrl = 'accounts.php?account=' . $id;
                            $record = ['photo_user_id' => $employee['photo_user_id'], 'photo_faculty_id' => (int)$employee['faculty_id']];
                            ?>
                            <tr>
                                <th scope="row">
                                    <div class="hris-faculty-identity hris-assigned-identity">
                                        <?php require __DIR__ . '/views/faculty-avatar.php'; ?>
                                        <div class="hris-faculty-info">
                                            <strong class="hris-faculty-name"><?= synk_hris_h($employee['employee_name']) ?></strong>
                                            <small><?= $employee['employee_id'] !== '' ? synk_hris_h($employee['employee_id']) : 'Employee ID not recorded' ?></small>
                                            <?php if ($id === $signedInId): ?><small>Your account</small><?php endif; ?>
                                        </div>
                                    </div>
                                </th>
                                <td data-label="Official email">
                                    <?= synk_hris_h($employee['email']) ?>
                                    <?php if (isset($employee['sign_in_status'])): ?><small><?= synk_hris_h($employee['sign_in_status']) ?></small><?php endif; ?>
                                </td>
                                <td data-label="Kawani access">
                                    <div class="hris-account-role-tags">
                                        <?php foreach ($badgeRoles as $code): ?>
                                            <span class="hris-status hris-tone-<?= $roleTones[$code] ?>"><?= synk_hris_h($roleLabels[$code]) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (in_array(SYNK_HRIS_USER, $assignedRoles, true)): ?>
                                        <?php $permissionCount = count(synk_hris_user_permissions($employee)); ?>
                                        <small><?= $permissionCount ? $permissionCount . ($permissionCount === 1 ? ' User permission' : ' User permissions') : 'No User tools assigned' ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="hris-assigned-action" data-label="Actions">
                                    <div class="hris-assigned-actions">
                                        <a class="hris-action" href="<?= synk_hris_h($employeeUrl) ?>#account-editor-title" aria-label="Edit account for <?= synk_hris_h($employee['employee_name']) ?>"><i class="bx bx-edit" aria-hidden="true"></i> Edit account</a>
                                        <?php if ($id !== $signedInId): ?>
                                            <a class="hris-action hris-action-danger" href="<?= synk_hris_h($employeeUrl . '&action=remove') ?>#remove-account-title" aria-label="Remove account for <?= synk_hris_h($employee['employee_name']) ?>"><i class="bx bx-user-x" aria-hidden="true"></i> Remove account</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="hris-empty"><?= $canAssignAdministrators ? 'No employees have a Kawani role yet.' : 'No User accounts are available to manage.' ?></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php require __DIR__ . '/views/layout-end.php'; ?>
