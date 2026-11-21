<?php
if (!isset($visibleRecords, $trackingPds, $pageUrl)) { http_response_code(404); return; }
if (!$trackingPds) {
    require_once __DIR__ . '/../../backend/faculty_profile_helper.php';
    $serviceAsOf = new DateTimeImmutable('today');
}
?>
<div id="directory-results" data-result-count="<?= count($matching) ?>">
    <?php if ($visibleRecords): ?>
        <table class="hris-record-table<?= $trackingPds ? '' : ' hris-faculty-table' ?>" role="table">
            <thead role="rowgroup"><tr role="row">
                <?php if ($trackingPds): ?>
                    <th scope="col" role="columnheader">Faculty name</th>
                    <th scope="col" role="columnheader">Employee ID</th>
                    <th scope="col" role="columnheader">PDS progress</th>
                    <th scope="col" role="columnheader">Last PDS save</th>
                <?php else: ?>
                    <th scope="col" role="columnheader">Faculty profile</th>
                    <th scope="col" role="columnheader">Academic assignment</th>
                    <th scope="col" role="columnheader" class="hris-faculty-service">Years of service</th>
                    <th scope="col" role="columnheader" class="hris-faculty-actions-cell">Actions</th>
                <?php endif; ?>
            </tr></thead>
            <tbody role="rowgroup">
                <?php foreach ($visibleRecords as $record): ?>
                    <tr role="row">
                        <?php if ($trackingPds): ?>
                            <th scope="row" role="rowheader"><strong><?= synk_hris_h($record['name']) ?></strong><?php if (!$record['has_profile']): ?><small>PDS record</small><?php endif; ?></th>
                            <td role="cell" data-label="Employee ID">
                                <?php if ($record['employee_id'] !== ''): ?>
                                    <strong class="hris-employee-id"><?= synk_hris_h($record['employee_id']) ?></strong>
                                    <small><?= synk_hris_h($record['id_source']) ?></small>
                                    <?php if ($record['id_mismatch']): ?><small class="hris-record-warning">Profile ID differs (<?= synk_hris_h($record['profile_id_number']) ?>)</small><?php endif; ?>
                                    <?php if ($record['duplicate_id']): ?><small class="hris-record-warning">Also used by another record</small><?php endif; ?>
                                <?php else: ?><span class="hris-muted">Not recorded</span><?php endif; ?>
                            </td>
                            <?php [$label, $tone] = $pdsStatuses[$record['pds_status']]; ?>
                            <td role="cell" data-label="PDS progress">
                                <span class="hris-status hris-tone-<?= $tone ?>"><?= synk_hris_h($label) ?></span>
                                <?php if ($record['link_issue'] !== ''): ?><small class="hris-record-warning"><?= synk_hris_h($record['link_issue']) ?></small><?php endif; ?>
                                <?php if ($record['saved_sections']): ?>
                                    <details class="hris-saved-sections">
                                        <summary><?= count($record['saved_sections']) ?> of <?= $sectionCount ?> sections saved</summary>
                                        <ul>
                                            <?php foreach ($sectionTitles as $key => $title): ?>
                                                <li><span><?= synk_hris_h($title) ?></span><small><?= isset($record['saved_sections'][$key]) ? 'Saved' : 'Not saved' ?></small></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td role="cell" data-label="Last PDS save">
                                <?php if ($record['last_saved']): ?><time datetime="<?= synk_hris_h(str_replace(' ', 'T', $record['last_saved'])) ?>"><?= synk_hris_h(date('M j, Y · g:i A', strtotime($record['last_saved']))) ?></time>
                                <?php else: ?><span class="hris-muted">—</span><?php endif; ?>
                            </td>
                        <?php else: ?>
                            <?php
                            $rank = synk_hris_rank_label($record['rank']) ?? $record['rank'];
                            $employment = $record['appointment'] ?: $record['work_status'];
                            $recordQuery = '?record=' . rawurlencode($record['owner']);
                            $entryDateValue = $record['service_start_date'];
                            $entryDate = preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $entryDateValue)
                                ? synk_faculty_profile_date($entryDateValue) : null;
                            $serviceLength = $entryDate
                                ? synk_faculty_profile_service($entryDateValue, $serviceAsOf)
                                : $record['length_of_service'];
                            ?>
                            <th scope="row" role="rowheader">
                                <div class="hris-faculty-identity">
                                    <?php require __DIR__ . '/faculty-avatar.php'; ?>
                                    <div class="hris-faculty-info">
                                        <small class="hris-faculty-rank"><?= synk_hris_h($rank ?: 'Academic rank not recorded') ?></small>
                                        <strong class="hris-faculty-name"><?= synk_hris_h($record['name']) ?></strong>
                                        <small class="hris-faculty-id-line">
                                            <?php if ($record['employee_id'] !== ''): ?>
                                                <b class="hris-employee-id"><?= synk_hris_h($record['employee_id']) ?></b>
                                            <?php else: ?>Employee ID not recorded<?php endif; ?>
                                            <span class="hris-faculty-id-divider" aria-hidden="true">|</span>
                                            <?= synk_hris_h($employment ?: 'Employment not recorded') ?>
                                        </small>
                                        <?php if ($record['work_status'] !== '' && strcasecmp($record['work_status'], $employment) !== 0): ?>
                                            <small><?= synk_hris_h($record['work_status']) ?></small>
                                        <?php endif; ?>
                                        <?php if (!$record['has_profile']): ?><small>PDS record</small><?php endif; ?>
                                        <?php if ($record['id_mismatch']): ?><small class="hris-record-warning">Profile ID differs (<?= synk_hris_h($record['profile_id_number']) ?>)</small><?php endif; ?>
                                        <?php if ($record['duplicate_id']): ?><small class="hris-record-warning">Also used by another record</small><?php endif; ?>
                                    </div>
                                </div>
                            </th>
                            <td class="hris-faculty-assignment" role="cell" data-label="Academic assignment">
                                <?php if ($record['college'] !== '' || $record['campus'] !== ''): ?>
                                    <strong><?= synk_hris_h($record['college'] ?: $record['campus']) ?></strong>
                                    <?php if ($record['college'] !== '' && $record['campus'] !== ''): ?><small><?= synk_hris_h($record['campus']) ?></small><?php endif; ?>
                                <?php else: ?>
                                    <span class="hris-muted">Not recorded</span>
                                <?php endif; ?>
                            </td>
                            <td class="hris-faculty-service" role="cell" data-label="Years of service">
                                <?php if ($serviceLength !== ''): ?>
                                    <strong><?= synk_hris_h($serviceLength) ?></strong>
                                <?php else: ?>
                                    <span class="hris-muted"><?= $entryDate ? 'Service not started' : 'Not recorded' ?></span>
                                <?php endif; ?>
                                <small><?php if ($entryDate): ?>
                                    Date of entry · <time datetime="<?= $entryDateValue ?>"><?= $entryDate->format('M j, Y') ?></time>
                                <?php else: ?>Entry date not recorded<?php endif; ?></small>
                            </td>
                            <td class="hris-faculty-actions-cell" role="cell" data-label="Actions">
                                <div class="hris-faculty-actions">
                                    <a class="hris-action hris-tone-green" href="manage-faculty.php<?= $recordQuery ?>" aria-label="Manage <?= synk_hris_h($record['name']) ?>"><i class="bx bx-edit" aria-hidden="true"></i> Manage</a>
                                    <a class="hris-action hris-tone-blue" href="preview-faculty.php<?= $recordQuery ?>" aria-label="Preview <?= synk_hris_h($record['name']) ?>"><i class="bx bx-show" aria-hidden="true"></i> Preview</a>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="hris-pagination">
            <span>Showing <?= number_format($pageOffset + 1) ?>–<?= number_format($pageOffset + count($visibleRecords)) ?> of <?= number_format(count($matching)) ?> records</span>
            <?php if ($pageCount > 1): ?>
                <nav aria-label="Results pages">
                    <?php if ($page > 1): ?><a href="<?= synk_hris_h($pageUrl($page - 1)) ?>" aria-label="Previous page"><i class="bx bx-chevron-left" aria-hidden="true"></i></a><?php endif; ?>
                    <span>Page <?= $page ?> of <?= $pageCount ?></span>
                    <?php if ($page < $pageCount): ?><a href="<?= synk_hris_h($pageUrl($page + 1)) ?>" aria-label="Next page"><i class="bx bx-chevron-right" aria-hidden="true"></i></a><?php endif; ?>
                </nav>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <p class="hris-empty"><?= $query !== '' || $filter !== 'all' ? 'No faculty records match your search or filters.' : 'Faculty records will appear here when a Professor Profile or PDS is saved.' ?></p>
    <?php endif; ?>
</div>
