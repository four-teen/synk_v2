<?php
if (!isset($visibleRecords, $trackingPds, $pageUrl)) { http_response_code(404); return; }
if (!$trackingPds) {
    require_once __DIR__ . '/../../backend/faculty_profile_helper.php';
    $serviceAsOf = new DateTimeImmutable('today');
}
?>
<div id="directory-results" data-result-count="<?= count($matching) ?>" data-page="<?= $page ?>"<?= $trackingPds ? '' : ' tabindex="-1"' ?>>
    <?php if (!$trackingPds && $personnelDetails !== null): ?>
        <nav class="hris-directory-views" aria-label="Personnel category">
            <?php foreach (['all' => 'All'] + $personnelCategories as $key => $label):
                $categoryUrl = '?' . http_build_query(['view' => 'faculty', 'category' => $key, 'filter' => $filter, 'q' => $query]);
            ?>
                <a href="<?= synk_hris_h($categoryUrl) ?>"<?= $key === $category ? ' aria-current="page"' : '' ?>><?= synk_hris_h($label) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php if ($visibleRecords): ?>
        <table class="hris-record-table<?= $trackingPds ? '' : ' hris-faculty-table' ?>" role="table">
            <thead role="rowgroup"><tr role="row">
                <?php if ($trackingPds): ?>
                    <th scope="col" role="columnheader">Employee name</th>
                    <th scope="col" role="columnheader">Employee ID</th>
                    <th scope="col" role="columnheader">PDS progress</th>
                    <th scope="col" role="columnheader">Last PDS save</th>
                <?php else: ?>
                    <th scope="col" role="columnheader">Employee profile</th>
                    <th scope="col" role="columnheader">Assignment</th>
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
                            $personnel = $personnelDetails[$record['owner']] ?? [];
                            $storedCategory = $personnel['personnel_category'] ?? '';
                            $nonTeaching = $storedCategory === 'non_teaching';
                            $recognizedRank = synk_hris_rank_label($record['rank']);
                            $suggestedTeaching = $storedCategory === '' && $recognizedRank !== null;
                            $categoryLabel = $personnelCategories[$storedCategory]
                                ?? ($suggestedTeaching ? 'Teaching suggested' : 'Set personnel category');
                            $categoryTone = isset($personnelCategories[$storedCategory]) ? ' hris-tone-green' : '';
                            $jobTitle = $nonTeaching ? ($personnel['position_title'] ?? '') : ($recognizedRank ?? $record['rank']);
                            $assignment = $nonTeaching ? ($personnel['office_unit'] ?? '') : $record['college'];
                            $classification = $record['appointment'];
                            $hrStatus = $facultyStatuses[$record['owner']] ?? [];
                            $statusCode = $hrStatus['status'] ?? '';
                            [$statusLabel, $statusTone] = $facultyStatusOptions[$statusCode] ?? ['Not yet reviewed', 'neutral'];
                            $recordQuery = '?record=' . rawurlencode($record['owner']);
                            $reviewQuery = $record['has_profile'] && !$record['saved_sections'] ? '&tab=profile' : '&tab=pds&section=personal';
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
                                        <?php if ($jobTitle !== ''): ?><small class="hris-faculty-rank"><?= synk_hris_h($jobTitle) ?></small><?php endif; ?>
                                        <strong class="hris-faculty-name"><?= synk_hris_h($record['directory_name']) ?></strong>
                                        <small class="hris-faculty-id-line">
                                            <?php if ($record['employee_id'] !== ''): ?>
                                                <b class="hris-employee-id"><?= synk_hris_h($record['employee_id']) ?></b>
                                            <?php else: ?>Employee ID not recorded<?php endif; ?>
                                            <span class="hris-faculty-id-divider" aria-hidden="true">|</span>
                                            <?= synk_hris_h($classification ?: 'Classification not recorded') ?>
                                        </small>
                                        <div class="hris-faculty-status">
                                            <span>HR status</span>
                                            <?php if ($facultyStatuses === null): ?>
                                                <span class="hris-status hris-tone-neutral">Unavailable</span>
                                            <?php elseif (!$canManageEmployees): ?>
                                                <span class="hris-status hris-tone-<?= $statusTone ?>"><?= synk_hris_h($statusLabel) ?></span>
                                            <?php else: ?>
                                                <?php
                                                $reviewDetail = $hrStatus
                                                    ? ($statusCode === 'active' ? 'Verified' : 'Updated') . ' by '
                                                        . (trim((string)$hrStatus['reviewer']) ?: 'HR') . ' on ' . date('M j, Y', strtotime($hrStatus['updated_at']))
                                                    : '';
                                                ?>
                                                <button class="hris-status hris-tone-<?= $statusTone ?>" type="button" aria-haspopup="dialog"
                                                    aria-label="<?= synk_hris_h($statusLabel . '. Change HR status for ' . $record['directory_name']) ?>"
                                                    data-edit-faculty-status data-record-key="<?= synk_hris_h($record['owner']) ?>"
                                                    data-faculty-name="<?= synk_hris_h($record['directory_name']) ?>" data-employee-id="<?= synk_hris_h($record['employee_id']) ?>"
                                                    data-status="<?= synk_hris_h($statusCode) ?>" data-revision="<?= (int)($hrStatus['revision'] ?? 0) ?>"
                                                    data-review-detail="<?= synk_hris_h($reviewDetail) ?>" data-return-query="<?= synk_hris_h($pageUrl($page)) ?>">
                                                    <?php if ($statusCode === 'active'): ?><i class="bx bx-check-shield" aria-hidden="true"></i><?php endif; ?>
                                                    <?= synk_hris_h($statusLabel) ?> <i class="bx bx-chevron-down" aria-hidden="true"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!$record['has_profile']): ?><small>PDS record</small><?php endif; ?>
                                        <?php if ($record['id_mismatch']): ?><small class="hris-record-warning">Profile ID differs (<?= synk_hris_h($record['profile_id_number']) ?>)</small><?php endif; ?>
                                        <?php if ($record['duplicate_id']): ?><small class="hris-record-warning">Also used by another record</small><?php endif; ?>
                                    </div>
                                </div>
                            </th>
                            <td class="hris-faculty-assignment" role="cell" data-label="Assignment">
                                <?php if ($assignment !== '' || $record['campus'] !== ''): ?>
                                    <strong><?= synk_hris_h($assignment ?: $record['campus']) ?></strong>
                                    <?php if ($assignment !== '' && $record['campus'] !== ''): ?><small><?= synk_hris_h($record['campus']) ?></small><?php endif; ?>
                                <?php else: ?>
                                    <span class="hris-muted">Not recorded</span>
                                <?php endif; ?>
                                <?php if ($positionCatalog !== null): ?>
                                    <button type="button" class="hris-personnel-button<?= $categoryTone ?>" data-edit-personnel aria-haspopup="dialog"
                                        aria-label="<?= synk_hris_h($categoryLabel . '. Edit personnel details for ' . $record['directory_name']) ?>"
                                        data-record-key="<?= synk_hris_h($record['owner']) ?>" data-employee-name="<?= synk_hris_h($record['directory_name']) ?>"
                                        data-category="<?= synk_hris_h($storedCategory ?: ($suggestedTeaching ? 'teaching' : '')) ?>"
                                        data-position-id="<?= synk_hris_h((string)($personnel['position_id'] ?? '')) ?>"
                                        data-employment-classification="<?= synk_hris_h($classification) ?>"
                                        data-office="<?= synk_hris_h($personnel['office_unit'] ?? '') ?>"
                                        data-item="<?= synk_hris_h($personnel['plantilla_item_number'] ?? '') ?>"
                                        data-suggested="<?= $suggestedTeaching ? 'true' : 'false' ?>"
                                        data-revision="<?= (int)($personnel['revision'] ?? 0) ?>" data-return-query="<?= synk_hris_h($pageUrl($page)) ?>">
                                        <?= synk_hris_h($categoryLabel) ?> <i class="bx bx-chevron-down" aria-hidden="true"></i>
                                    </button>
                                <?php elseif ($storedCategory !== ''): ?>
                                    <span class="hris-status<?= $categoryTone ?>"><?= synk_hris_h($categoryLabel) ?></span>
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
                                    <a class="hris-action hris-action-pill hris-tone-green" href="preview-faculty.php<?= synk_hris_h($recordQuery . $reviewQuery) ?>" aria-label="Review details for <?= synk_hris_h($record['directory_name']) ?>"><i class="bx bx-show" aria-hidden="true"></i> Review Details</a>
                                    <?php if ($canManageEmployees): ?>
                                        <button class="hris-action hris-action-pill hris-work-action" type="button" data-work-record="<?= synk_hris_h($record['owner']) ?>" data-work-name="<?= synk_hris_h($record['directory_name']) ?>" aria-haspopup="dialog" aria-label="Work experience for <?= synk_hris_h($record['directory_name']) ?>"><i class="bx bx-briefcase-alt" aria-hidden="true"></i> Work Experience</button>
                                    <?php endif; ?>
                                    <?php if ($canDeleteEmployees && $record['photo_faculty_id'] > 0): ?>
                                        <button class="hris-action hris-action-pill hris-action-danger" type="button" data-delete-employee
                                            data-faculty-id="<?= $record['photo_faculty_id'] ?>" data-employee-name="<?= synk_hris_h($record['directory_name']) ?>"
                                            data-employee-id="<?= synk_hris_h($record['employee_id']) ?>" data-return-query="<?= synk_hris_h($pageUrl($page)) ?>"
                                            aria-haspopup="dialog" aria-label="Delete employee <?= synk_hris_h($record['directory_name']) ?>"><i class="bx bx-trash" aria-hidden="true"></i> Delete</button>
                                    <?php endif; ?>
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
        <p class="hris-empty"><?= $query !== '' || $filter !== 'all' || $category !== 'all'
            ? 'No employee records match your search or filters.' : 'Employee records will appear here after a profile or PDS is saved.' ?></p>
    <?php endif; ?>
</div>
