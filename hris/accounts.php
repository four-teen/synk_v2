<?php
session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/account-access.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_admin();
$domain = (string)synk_auth_settings()['allowed_domain'];
$roles = synk_hris_role_labels();
$roleLabels = $roles + ['conflict' => 'Role review needed'];
$roleTones = [SYNK_HRIS_ADMIN => 'green', SYNK_HRIS_USER => 'blue', 'conflict' => 'amber'];
$idOptions = ['options' => ['min_range' => 1]];
$ready = synk_hris_employee_accounts_ready($conn);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['role_action'] ?? '';
    $facultyId = filter_var($_POST['faculty_id'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
    $accountId = filter_var($_POST['account_id'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
    $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, $idOptions) ?: 0;
    $email = is_string($_POST['official_email'] ?? null) ? $_POST['official_email'] : '';
    $role = is_string($_POST['role_code'] ?? null) ? $_POST['role_code'] : '';
    $kind = 'error';
    try {
        if (!synk_hris_valid_post()) throw new DomainException('This form expired. Refresh the page and try again.');
        if (!$ready) throw new DomainException('Kawani account setup is required before assigning access.');
        if ($action === 'assign' ? !$facultyId : (!in_array($action, ['edit', 'remove'], true) || !$accountId || !$revision)) {
            throw new DomainException('Open the employee’s account and try again.');
        }
        synk_hris_manage_employee_account($conn, $account, $action, $facultyId, $accountId, $revision, $email, $role, $domain);
        $kind = 'success';
        $message = $action === 'remove' ? 'Kawani access removed.' : ($action === 'assign' ? 'Kawani access assigned.' : 'Kawani account saved.');
    } catch (DomainException $error) {
        $message = $error->getMessage();
    } catch (Throwable $error) {
        error_log('Kawani account update failed. ' . get_class($error) . ' ' . $error->getCode());
        $message = 'The Kawani account could not be saved. Try again.';
    }

    $_SESSION['hris_account_flash'] = ['kind' => $kind, 'message' => $message];
    $redirect = 'accounts.php#assigned-access-title';
    if ($kind === 'error') {
        $redirect = $action === 'assign' && $facultyId ? 'accounts.php?employee=' . $facultyId . '#role-form-title' : 'accounts.php';
        if ($action === 'edit' && $accountId) $redirect = 'accounts.php?account=' . $accountId . '#account-editor-title';
        if (synk_hris_valid_post() && in_array($action, ['assign', 'edit'], true)) {
            $_SESSION['hris_account_flash']['form'] = compact('action', 'facultyId', 'accountId', 'role') + ['email' => substr($email, 0, 254)];
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
        [$available, $assigned, $byFaculty] = synk_hris_account_directory($conn);
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
        <p>Manage Kawani access for teaching and non-teaching employees using their official SKSU Google email.</p>
    </header>

    <?php if ($flash): ?>
        <?php $success = $flash['kind'] === 'success'; ?>
        <div class="hris-account-alert hris-account-alert-<?= $success ? 'success' : 'error' ?>" role="<?= $success ? 'status' : 'alert' ?>">
            <i class="bx <?= $success ? 'bx-check-circle' : 'bx-error-circle' ?>" aria-hidden="true"></i>
            <span><?= synk_hris_h($flash['message']) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!$ready): ?>
        <p class="hris-notice" role="status"><i class="bx bx-info-circle" aria-hidden="true"></i> Kawani account setup is unavailable.</p>
    <?php else: ?>
        <section class="hris-card hris-account-assignment" aria-labelledby="role-form-title">
            <h2 id="role-form-title">Assign a Kawani role</h2>
            <?php if ($available): ?>
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
                    <div class="hris-account-field">
                        <label for="assign-role">Kawani role</label>
                        <select id="assign-role" name="role_code" required>
                            <option value="">Choose a role</option>
                            <?php foreach ($roles as $code => $label): ?>
                                <option value="<?= synk_hris_h($code) ?>"<?= $assignRetry && $retry['role'] === $code ? ' selected' : '' ?>><?= synk_hris_h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="hris-action hris-action-primary" type="submit"><i class="bx bx-user-plus" aria-hidden="true"></i> Assign</button>
                </form>
            <?php else: ?>
                <p class="hris-empty">No employees are available for a new Kawani assignment.</p>
            <?php endif; ?>
        </section>

        <?php if ($removing): ?>
            <dialog open class="hris-confirm-dialog" id="account-removal" aria-labelledby="remove-account-title" aria-describedby="remove-account-description" data-remove-account-dialog>
                <div class="hris-confirm-icon"><i class="bx bx-user-x" aria-hidden="true"></i></div>
                <h2 id="remove-account-title">Remove Kawani access?</h2>
                <p id="remove-account-description">This removes the Kawani role for <strong><?= synk_hris_h($editing['employee_name']) ?></strong>.</p>
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
            <?php $currentRole = $editing['role_code']; ?>
            <section class="hris-card hris-account-assignment" aria-labelledby="account-editor-title">
                <h2 id="account-editor-title">Edit account</h2>
                <div class="hris-account-selected">
                    <span class="hris-account-selected-icon"><i class="bx bx-id-card" aria-hidden="true"></i></span>
                    <div class="hris-account-selected-info">
                        <strong><?= synk_hris_h($editing['employee_name']) ?></strong>
                        <small><?= synk_hris_h($editing['email']) ?></small>
                    </div>
                    <span class="hris-status hris-tone-<?= $roleTones[$currentRole] ?>"><?= synk_hris_h($roleLabels[$currentRole]) ?></span>
                </div>
                <?php if ($selectedAccountId === $signedInId): ?>
                    <p class="hris-account-protection"><i class="bx bx-lock-alt" aria-hidden="true"></i> Another administrator must change your Kawani account.</p>
                <?php else: ?>
                    <form class="hris-role-form" method="post" action="accounts.php">
                        <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                        <input type="hidden" name="account_id" value="<?= $selectedAccountId ?>">
                        <input type="hidden" name="revision" value="<?= (int)$editing['revision'] ?>">
                        <input type="hidden" name="role_action" value="edit">
                        <div class="hris-account-field">
                            <label for="edit-email">Official SKSU Google email</label>
                            <input id="edit-email" name="official_email" type="email" maxlength="254" autocomplete="off" required
                                value="<?= synk_hris_h($editRetry ? $retry['email'] : $editing['email']) ?>">
                        </div>
                        <div class="hris-account-field">
                            <label for="edit-role">Kawani role</label>
                            <select id="edit-role" name="role_code" required>
                                <?php if (!isset($roles[$currentRole])): ?><option value="">Choose a role</option><?php endif; ?>
                                <?php foreach ($roles as $code => $label): ?>
                                    <option value="<?= synk_hris_h($code) ?>"<?= $code === ($editRetry ? $retry['role'] : $currentRole) ? ' selected' : '' ?>><?= synk_hris_h($label) ?></option>
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
                    <thead><tr><th scope="col">Employee</th><th scope="col">Official email</th><th scope="col">Kawani role</th><th scope="col" class="hris-assigned-action-heading">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($assigned as $id => $employee): ?>
                            <?php
                            $currentRole = $employee['role_code'];
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
                                        </div>
                                    </div>
                                </th>
                                <td data-label="Official email">
                                    <?= synk_hris_h($employee['email']) ?>
                                    <?php if (isset($employee['sign_in_status'])): ?><small><?= synk_hris_h($employee['sign_in_status']) ?></small><?php endif; ?>
                                </td>
                                <td data-label="Kawani role"><span class="hris-status hris-tone-<?= $roleTones[$currentRole] ?>"><?= synk_hris_h($roleLabels[$currentRole]) ?></span></td>
                                <td class="hris-assigned-action" data-label="Actions">
                                    <?php if ($id === $signedInId): ?>
                                        <span class="hris-account-protection"><i class="bx bx-lock-alt" aria-hidden="true"></i> Your account</span>
                                    <?php else: ?>
                                        <div class="hris-assigned-actions">
                                            <a class="hris-action" href="<?= synk_hris_h($employeeUrl) ?>#account-editor-title" aria-label="Edit account for <?= synk_hris_h($employee['employee_name']) ?>"><i class="bx bx-edit" aria-hidden="true"></i> Edit account</a>
                                            <a class="hris-action hris-action-danger" href="<?= synk_hris_h($employeeUrl . '&action=remove') ?>#remove-account-title" aria-label="Remove account for <?= synk_hris_h($employee['employee_name']) ?>"><i class="bx bx-user-x" aria-hidden="true"></i> Remove account</a>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="hris-empty">No employees have a Kawani role yet.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php require __DIR__ . '/views/layout-end.php'; ?>
