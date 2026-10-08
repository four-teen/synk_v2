<?php
$isExcelExport = ($_GET['export'] ?? '') === 'excel';
$exportBufferLevel = ob_get_level();
if ($isExcelExport) ob_start();
require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../backend/di_profile_analytics_helper.php';
$directory = synk_faculty_directory_records($conn);
$profiledFaculty = synk_di_profiled_faculty_records($conn, $directory);
$filters = synk_di_faculty_filters($_GET, $directory);
$records = synk_di_filter_faculty_records($profiledFaculty, $filters);
$exportError = '';
if ($isExcelExport) {
    if (!$records) {
        $exportError = 'No faculty profiles at 25% completion or higher match the selected filters. Adjust the filters before exporting.';
    } else {
        $exportPath = null;
        try {
            require_once __DIR__ . '/../backend/faculty_profile_excel_helper.php';
            $exportPath = synk_faculty_profile_excel_file($records, $directory, $moduleTerm, synk_faculty_excel_eligibilities($conn, $records));
            while (ob_get_level() > $exportBufferLevel) ob_end_clean();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="faculty-profiles-' . date('Y-m-d') . '.xlsx"');
            header('Content-Length: ' . filesize($exportPath));
            header('X-Content-Type-Options: nosniff');
            readfile($exportPath);
            unlink($exportPath);
            exit;
        } catch (Throwable $exception) {
            if ($exportPath && is_file($exportPath)) unlink($exportPath);
            error_log('DI faculty profile Excel export: ' . $exception->getMessage());
            $exportError = 'The Excel file could not be generated. Please try again.';
        }
    }
    while (ob_get_level() > $exportBufferLevel) ob_end_clean();
}
$total = count($records);
$pageCount = max(1, (int)ceil($total / 25));
$page = min($pageCount, max(1, synk_faculty_directory_id($_GET['page'] ?? '1') ?? 1));
$shown = array_slice($records, ($page - 1) * 25, 25);
$statuses = synk_faculty_record_statuses();
$activeCount = count(array_filter($profiledFaculty, static function ($row) { return $row['status'] === 'active'; }));
$averageCompletion = $profiledFaculty
    ? (int)round(array_sum(array_column($profiledFaculty, 'profile_completion_percent')) / count($profiledFaculty))
    : 0;
$pageTitle = 'List of Professors';
include __DIR__ . '/page-start.php';
?>
<div class="di-directory-heading"><div><span class="di-eyebrow">DI / Faculty Management</span><h1>List of Professors</h1><p>Browse faculty who have started profiling and completed at least 25% of their profile details.</p></div><a class="btn btn-outline-secondary" href="index.php"><i class="bx bx-home-circle me-1"></i>Dashboard</a></div>
<div class="di-directory-metrics mb-4">
  <?php foreach ([['Profiles at 25% or more', count($profiledFaculty)], ['Active', $activeCount], ['Other statuses', count($profiledFaculty) - $activeCount], ['Average profile completion', $averageCompletion . '%']] as $metric): ?>
    <div class="card"><div class="card-body"><span class="text-muted small"><?= $metric[0] ?></span><strong><?= $metric[1] ?></strong></div></div>
  <?php endforeach; ?>
</div>
<section class="card" aria-labelledby="faculty-list-heading">
  <div class="card-header"><div class="di-list-card-heading di-professor-list-heading"><div><h2 id="faculty-list-heading">Professors List</h2><p>Only saved profiles at 25% completion or higher are listed. Faculty status and remarks remain available for review.</p></div><div class="di-professor-export">
      <?php if ($records): ?><a class="btn btn-success" href="professors.php?<?= synk_leadership_h(http_build_query($filters + ['export' => 'excel'])) ?>"><i class="bx bx-download me-1" aria-hidden="true"></i>Export Profile to Excel</a><?php else: ?><button class="btn btn-success" type="button" disabled><i class="bx bx-download me-1" aria-hidden="true"></i>Export Profile to Excel</button><?php endif; ?>
      <small class="text-muted">Consolidates all filtered faculty profiles using the faculty profile template.</small>
    </div></div>
    <?php if ($exportError !== ''): ?><div class="alert alert-warning" role="alert"><?= synk_leadership_h($exportError) ?></div><?php endif; ?>
    <form method="get" class="di-directory-filters" aria-label="Filter professors">
      <div class="di-search-field"><label for="faculty-search">Search faculty</label><div class="input-group"><span class="input-group-text"><i class="bx bx-search"></i></span><input type="search" id="faculty-search" class="form-control" name="q" value="<?= synk_leadership_h($filters['q']) ?>" placeholder="Name, faculty ID, email, or remarks"></div></div>
      <div><label for="faculty-campus">Campus</label><select class="form-select" id="faculty-campus" name="campus_id" data-campus-select><option value="0">All campuses</option><?php foreach ($directory['campuses'] as $id => $campus): ?><option value="<?= $id ?>"<?= $filters['campus_id'] === $id ? ' selected' : '' ?>><?= synk_leadership_h($campus['campus_name']) ?></option><?php endforeach; ?></select></div>
      <div><label for="faculty-college">College</label><select class="form-select" id="faculty-college" name="college_id" data-college-select><option value="0">All colleges</option><?php foreach ($directory['colleges'] as $id => $college): ?><option value="<?= $id ?>" data-campus="<?= (int)$college['campus_id'] ?>"<?= $filters['college_id'] === $id ? ' selected' : '' ?>><?= synk_leadership_h($college['college_code'] . ' - ' . $college['college_name']) ?></option><?php endforeach; ?></select></div>
      <div><label for="faculty-status">Faculty status</label><select class="form-select" name="status" id="faculty-status"><option value="">All statuses</option><?php foreach ($statuses as $value => $label): ?><option value="<?= $value ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
      <div><label for="faculty-connection">Connections</label><select class="form-select" name="connection" id="faculty-connection"><option value="">All records</option><option value="needs_review"<?= $filters['connection'] === 'needs_review' ? ' selected' : '' ?>>College / campus needs review</option><option value="no_account"<?= $filters['connection'] === 'no_account' ? ' selected' : '' ?>>No linked account</option></select></div>
      <div class="di-filter-actions"><button class="btn btn-primary" type="submit">Apply filters</button><a class="btn btn-outline-secondary" href="professors.php">Clear</a></div>
    </form>
    <p class="di-list-meta mt-3 mb-0" aria-live="polite"><?= $total ?> faculty match this view<?= $total ? ' · Showing ' . (($page - 1) * 25 + 1) . '–' . min($page * 25, $total) : '' ?>.</p>
  </div>
  <div class="card-body pt-0">
    <div class="di-directory-table-wrap d-none d-lg-block"><table class="table table-hover align-middle mb-0 di-directory-table"><thead><tr><th>#</th><th>Faculty</th><th>Linked account</th><th>Profile details</th><th>College / campus</th><th>Status</th><th>Remarks</th><th class="text-end">Actions</th></tr></thead><tbody>
      <?php foreach ($shown as $index => $record): ?><tr>
        <td><?= ($page - 1) * 25 + $index + 1 ?></td><td><strong><?= synk_leadership_h($record['name']) ?></strong><small class="text-muted d-block">Faculty ID: <?= synk_leadership_h($record['faculty_id_number'] ?: 'Not entered') ?></small></td>
        <td><?php if ($record['account']): ?><span class="di-email"><?= synk_leadership_h($record['account']['email']) ?></span><small class="text-muted d-block"><?= ucfirst($record['account']['status']) ?> account</small><?php else: ?><span class="text-muted">No linked account</span><?php endif; ?></td>
        <td class="di-profile-progress-cell"><div class="d-flex justify-content-between gap-2"><strong><?= $record['profile_completion_percent'] ?>%</strong><small class="text-muted">complete</small></div><div class="progress di-profile-progress mt-1" role="progressbar" aria-label="<?= synk_leadership_h($record['name']) ?> profile completion" aria-valuenow="<?= $record['profile_completion_percent'] ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar bg-primary" style="width:<?= $record['profile_completion_percent'] ?>%"></div></div></td>
        <td><?= synk_leadership_h($record['college_name']) ?><small class="text-muted d-block"><?= synk_leadership_h($record['campus_name']) ?></small><?php if (!$record['connection_complete']): ?><span class="badge bg-label-warning mt-1">Needs review</span><?php endif; ?></td>
        <td><span class="badge bg-label-<?= synk_faculty_record_status_color((string)$record['status']) ?>"><?= $statuses[$record['status']] ?? 'Not recorded' ?></span></td><td class="di-remarks-cell"><?= synk_leadership_h($record['remarks'] ?: '—') ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="faculty-profile.php?faculty_id=<?= $record['faculty_id'] ?>" aria-label="Review <?= synk_leadership_h($record['name']) ?>">Review</a></td>
      </tr><?php endforeach; ?>
      <?php if (!$shown): ?><tr><td colspan="8" class="text-center text-muted py-5">No faculty profile at 25% completion or higher matches these filters.</td></tr><?php endif; ?>
    </tbody></table></div>
    <div class="di-faculty-mobile-list d-lg-none">
      <?php foreach ($shown as $record): ?><article class="di-faculty-mobile-card"><div class="d-flex justify-content-between align-items-start gap-2"><strong><?= synk_leadership_h($record['name']) ?></strong><span class="badge bg-label-<?= synk_faculty_record_status_color((string)$record['status']) ?>"><?= $statuses[$record['status']] ?? 'Not recorded' ?></span></div><small class="text-muted">Faculty ID: <?= synk_leadership_h($record['faculty_id_number'] ?: 'Not entered') ?></small><p class="di-email mb-2 mt-2"><?= synk_leadership_h($record['account']['email'] ?? 'No linked account') ?></p><dl><dt>College / campus</dt><dd><?= synk_leadership_h($record['college_name']) ?><small class="d-block text-muted"><?= synk_leadership_h($record['campus_name']) ?></small></dd><dt>Remarks</dt><dd><?= synk_leadership_h($record['remarks'] ?: '—') ?></dd></dl><?php if (!$record['connection_complete']): ?><span class="badge bg-label-warning mb-2">Connection needs review</span><?php endif; ?><a class="btn btn-outline-primary w-100" href="faculty-profile.php?faculty_id=<?= $record['faculty_id'] ?>">Review faculty</a></article><?php endforeach; ?>
      <?php if (!$shown): ?><p class="text-center text-muted py-4">No faculty profile at 25% completion or higher matches these filters.</p><?php endif; ?>
    </div>
  </div>
  <div class="card-footer di-list-footer"><small class="text-muted">Faculty records are retained when their status changes.</small><nav class="di-pagination" aria-label="Faculty list pages"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= synk_leadership_h(http_build_query($filters + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?><span class="small text-muted">Page <?= $page ?> of <?= $pageCount ?></span><?php if ($page < $pageCount): ?><a class="btn btn-sm btn-outline-secondary" href="?<?= synk_leadership_h(http_build_query($filters + ['page' => $page + 1])) ?>">Next</a><?php endif; ?></nav></div>
</section>
<?php include __DIR__ . '/page-end.php'; ?>
