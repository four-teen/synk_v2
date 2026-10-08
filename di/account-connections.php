<?php
require __DIR__ . '/bootstrap.php';
$directory = synk_faculty_directory_records($conn);
$accounts = synk_di_account_connections($conn, $directory);
$query = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$reviewOnly = ($_GET['connection'] ?? '') === 'needs_review';
$accountStatus = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$activeIssues = count(array_filter($accounts, static function ($row) { return $row['status'] === 'active' && $row['issues']; }));
$allIssues = count(array_filter($accounts, static function ($row) { return (bool)$row['issues']; }));
$rows = array_values(array_filter($accounts, static function ($row) use ($query, $reviewOnly, $accountStatus) {
    return (!$reviewOnly || $row['issues']) && ($accountStatus === '' || $row['status'] === $accountStatus)
        && ($query === '' || stripos(implode(' ', [$row['username'], $row['email'], implode(' ', $row['roles']), $row['scope'], implode(' ', $row['issues'])]), $query) !== false);
}));
$total = count($rows);
$pageCount = max(1, (int)ceil($total / 25));
$page = min($pageCount, max(1, synk_faculty_directory_id($_GET['page'] ?? '1') ?? 1));
$shown = array_slice($rows, ($page - 1) * 25, 25);
$filterQuery = ['q' => $query, 'connection' => $reviewOnly ? 'needs_review' : '', 'status' => $accountStatus];
$pageTitle = 'Account Connections';
include __DIR__ . '/page-start.php';
?>
<div class="di-directory-heading"><div><span class="di-eyebrow">DI / Faculty Management</span><h1>Account Connections</h1><p>Review college, campus, and faculty links for every account.</p></div><a class="btn btn-outline-secondary" href="professors.php">Faculty list</a></div>
<div class="di-directory-metrics mb-4"><?php foreach ([['All accounts', count($accounts)], ['Active accounts needing review', $activeIssues], ['All accounts needing review', $allIssues], ['Connected or institution-wide', count($accounts) - $allIssues]] as $metric): ?><div class="card"><div class="card-body"><span class="text-muted small"><?= $metric[0] ?></span><strong><?= $metric[1] ?></strong></div></div><?php endforeach; ?></div>
<section class="card" aria-labelledby="account-list-heading"><div class="card-header"><div class="di-list-card-heading"><h2 id="account-list-heading">Accounts List</h2><p>Faculty connections are independent of semester. Administrator, VPAA, and DI access may be institution-wide. Access permissions are managed in the administrator module.</p></div>
  <form method="get" class="di-directory-filters di-connections-filter" aria-label="Filter account connections"><div><label for="connection-search">Search accounts</label><input class="form-control" type="search" id="connection-search" name="q" value="<?= synk_leadership_h($query) ?>" placeholder="Name, email, role, or scope"></div><div><label for="connection-state">Connection</label><select class="form-select" id="connection-state" name="connection"><option value="">All connections</option><option value="needs_review"<?= $reviewOnly ? ' selected' : '' ?>>Needs review</option></select></div><div><label for="connection-account-status">Account status</label><select class="form-select" name="status" id="connection-account-status"><option value="">All statuses</option><option value="active"<?= $accountStatus === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= $accountStatus === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></div><div class="di-filter-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="account-connections.php">Clear</a></div></form>
  <p class="di-list-meta mt-3 mb-0"><?= $total ?> accounts match this view.</p>
</div><div class="card-body pt-0"><div class="di-directory-table-wrap"><table class="table table-hover align-middle mb-0 di-directory-table"><thead><tr><th>#</th><th>Account</th><th>Roles</th><th>College / campus connection</th><th>Account status</th><th>Review</th></tr></thead><tbody>
<?php foreach ($shown as $index => $account): ?><tr><td><?= ($page - 1) * 25 + $index + 1 ?></td><td><strong><?= synk_leadership_h($account['username']) ?></strong><span class="di-email text-muted"><?= synk_leadership_h($account['email']) ?></span></td><td><?php foreach ($account['roles'] as $role): ?><span class="badge bg-label-primary mb-1"><?= synk_leadership_h(synk_role_label($role)) ?></span><?php endforeach; ?></td><td><?= synk_leadership_h($account['scope']) ?></td><td><span class="badge bg-label-<?= $account['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($account['status']) ?></span></td><td><?php if ($account['issues']): ?><span class="badge bg-label-warning mb-1">Needs review</span><?php foreach ($account['issues'] as $issue): ?><small class="d-block text-muted"><?= synk_leadership_h($issue) ?></small><?php endforeach; ?><?php else: ?><span class="badge bg-label-success"><?= $account['connection_status'] === 'institution_wide' ? 'Institution-wide' : 'Connected' ?></span><?php endif; ?><?php if ($account['faculty_id']): ?><a class="d-block small mt-1" href="faculty-profile.php?faculty_id=<?= $account['faculty_id'] ?>">Review faculty</a><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$shown): ?><tr><td colspan="6" class="text-center text-muted py-5">No accounts match these filters.</td></tr><?php endif; ?>
</tbody></table></div></div><div class="card-footer di-list-footer"><small class="text-muted">Missing connections need verified affiliation or access details.</small><nav class="di-pagination" aria-label="Account list pages"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= synk_leadership_h(http_build_query($filterQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?><span class="small text-muted">Page <?= $page ?> of <?= $pageCount ?></span><?php if ($page < $pageCount): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= synk_leadership_h(http_build_query($filterQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?></nav></div></section>
<?php include __DIR__ . '/page-end.php'; ?>
