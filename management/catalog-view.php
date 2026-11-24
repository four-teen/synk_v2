<?php
// Included after portal authorization and catalog query.
if (!isset($scope,$catalog,$catalogOptions,$page)) { http_response_code(404); exit; }
$isFlagRaising=$page==='flag_raising';
$isEvents=$page==='events' || $isFlagRaising;
$singular=synk_management_catalog_kind($page)['label'];
$canWrite=$permissions[$isEvents?'manage_events':'manage_contributions']
    && ($isFlagRaising ? $term['key']===$currentTerm['key'] : $term['period_key']===$currentTerm['period_key']);
$canDelete=$isFlagRaising ? $permissions['delete_event_records'] : $canWrite;
$canRecordPastAttendance=$isEvents && $term['key']===$currentTerm['key'] && $permissions['manage_events'] && $permissions['attendance'];
$statusOptions = synk_management_catalog_status_options($page);
$stats = [['total','Records in this view','bx-list-ul','primary'],['active_count','Active','bx-check-circle','success'],['inactive_count','Inactive','bx-minus-circle','secondary']];
$filters = $catalog['filters']; $pagination = $catalog['pagination'];
$catalogUrl = static function (array $changes = []) use ($pageUrl,$page,$filters): string {
    $urlFilters = array_intersect_key($filters,array_flip(['search','status','page','page_size','event_id','saved_event_id']));
    return $pageUrl($page . '.php',array_merge($urlFilters,$changes));
};
?>
<div id="management-page-notice" class="alert alert-success" role="status" hidden></div>
<section class="management-stats management-catalog-stats" aria-label="<?= $pageTitle ?> overview">
  <?php foreach ($stats as $stat): ?>
    <article class="card management-stat"><span class="management-stat-icon bg-label-<?= $stat[3] ?>"><i class="bx <?= $stat[2] ?>" aria-hidden="true"></i></span><div><span><?= $stat[1] ?></span><strong><?= number_format($catalog['summary'][$stat[0]]) ?></strong></div></article>
  <?php endforeach; ?>
</section>
<section class="card dean-page-card management-panel" aria-labelledby="catalog-title" id="management-catalog" data-kind="<?= $page ?>">
  <div class="management-panel-heading"><div>
    <h1 id="catalog-title" class="card-section-title mb-1"><?= $isFlagRaising ? 'FLAG RAISING CEREMONY' : 'ORGANIZATION '.strtoupper($pageTitle) ?></h1>
    <p><?= $isFlagRaising ? 'Record ceremony attendance and review student absences.' : ($isEvents ? 'Manage events, attendance, and absence fines.' : 'Set up contribution amounts and event links for your organization.') ?></p></div>
    <div class="d-flex gap-2 flex-wrap">
      <?php if ($canRecordPastAttendance): ?><button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#management-past-attendance">Import past attendance</button><?php endif; ?>
      <button class="btn btn-primary" type="button" id="management-add"<?= $canWrite ? '' : ' disabled' ?>><i class="bx bx-plus me-1" aria-hidden="true"></i><?= $isFlagRaising ? 'Add attendance' : 'Add '.$singular ?></button>
    </div>
  </div>
  <?php if (!$canWrite): ?><p class="px-4 pb-3 mb-0 text-muted">Regular catalog changes require permission from your faculty manager and the current Management period.<?php if ($isEvents && $permissions['manage_exemptions']): ?> You can update exemptions for this semester's imported events after attendance closes.<?php endif; ?></p><?php endif; ?>
  <form method="get" class="management-filters" id="management-catalog-filters">
    <input type="hidden" name="term" value="<?= synk_management_h($term['key']) ?>"><input type="hidden" name="exam_term" value="<?= synk_management_h($term['exam_term']) ?>">
    <div class="management-filter-search"><label for="catalog-search">Search <?= strtolower($pageTitle) ?></label><input class="form-control" type="search" id="catalog-search" name="search" maxlength="150" value="<?= synk_management_h($filters['search']) ?>" placeholder="<?= $singular ?> title"></div>
    <div><label for="catalog-status">Status</label><select class="form-select" id="catalog-status" name="status"><option value="">All statuses</option><?php foreach ($statusOptions as $status=>$statusLabel): ?><option value="<?= $status ?>"<?= $filters['status']===$status ? ' selected' : '' ?>><?= $statusLabel ?></option><?php endforeach; ?></select></div>
    <div><label for="catalog-page-size">Records per page</label><select class="form-select" id="catalog-page-size" name="page_size"><?php foreach ([25,50,100] as $size): ?><option value="<?= $size ?>"<?= $filters['page_size']===$size ? ' selected' : '' ?>><?= $size ?> per page</option><?php endforeach; ?></select></div>
  </form>
  <?php if ($isEvents && $filters['event_id']): ?>
    <p class="px-4"><a href="<?= synk_management_h($pageUrl($isFlagRaising?'flag-raising.php':'events.php')) ?>"><?= $isFlagRaising?'Show all ceremonies in this semester':'Show all events in this period' ?></a></p>
  <?php endif; ?>
  <?php if (!$catalog['rows']): ?><div class="management-empty"><i class="bx <?= $isEvents ? 'bx-calendar-event' : 'bx-wallet' ?>" aria-hidden="true"></i><h3>No <?= strtolower($pageTitle) ?> in this view</h3><p>Adjust your filters or add a <?= strtolower($singular) ?> for your organization.</p></div>
  <?php else: ?><div class="management-table-scroll" role="region" aria-label="<?= $pageTitle ?> list" tabindex="0"><table class="table dean-students-table management-table management-catalog-table management-mobile-records" role="table"><thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col"><?= $singular ?></th><?php if ($isEvents): ?><th role="columnheader" scope="col">Attendance count</th><th role="columnheader" scope="col">Attendance status</th><th role="columnheader" scope="col">Event status</th><?php else: ?><th role="columnheader" scope="col">Event date</th><th role="columnheader" scope="col">Amount</th><th role="columnheader" scope="col">Status</th><?php endif; ?><th role="columnheader" scope="col" class="text-end">Actions</th></tr></thead><tbody role="rowgroup">
    <?php foreach ($catalog['rows'] as $row): $id=(int)$row[$isEvents ? 'event_id' : 'contribution_id'];
        $attendanceState=$row['attendance_status'] ?? 'NOT_STARTED';
        $importInSemester=$isEvents && synk_management_imported_event_in_current_semester($row,$currentTerm);
        $openImport=$importInSemester && $attendanceState==='OPEN';
        $manageAttendance=$canWrite || ($importInSemester && $permissions['manage_events'] && $attendanceState!=='NOT_STARTED');
        $manageExemptions=$permissions['manage_exemptions'] && ($term['period_key']===$currentTerm['period_key']
            || ($isFlagRaising && $term['key']===$currentTerm['key']) || ($importInSemester && $attendanceState!=='NOT_STARTED'));
        $editImport=$permissions['manage_events'] && ($openImport || ($isFlagRaising && $canWrite && $attendanceState!=='NOT_STARTED')); ?>
      <tr role="row"<?= $isEvents ? ' id="event-'.$id.'" data-event-row="'.$id.'"' : '' ?><?= $isFlagRaising ? ' data-event-exam-term="'.synk_management_h($row['exam_term']).'"' : '' ?>><td role="cell" class="management-record-title"><strong><?= synk_management_h($row['title']) ?></strong><?php if ($isEvents): ?><small class="management-event-date"><time datetime="<?= synk_management_h($row['event_date']) ?>"><?= strtolower(date('F j, Y',strtotime($row['event_date']))) ?></time></small><?php else: ?><small><?= $row['audience_mode']==='SELECTED' ? 'SELECTED STUDENTS' : ($row['audience_mode']==='ALL' ? 'ALL STUDENTS' : 'AUDIENCE UNASSIGNED') ?></small><?php endif; ?></td>
        <?php if ($isEvents): $attendanceLabel=['OPEN'=>'Open','CLOSED'=>'Closed','NOT_STARTED'=>'Not started'][$attendanceState]; ?>
        <td role="cell" data-label="Attendance count">
          <div class="management-event-attendance-count">
            <span><strong class="management-attendance-present" data-event-present><?= number_format($row['attendance_present'] ?? 0) ?></strong> present</span>
            <span><strong class="management-attendance-remaining" data-event-remaining><?= number_format($row['attendance_remaining'] ?? 0) ?></strong> <span data-event-remaining-label><?= $attendanceState==='CLOSED' ? 'absent' : 'pending' ?></span></span>
          </div>
          <small class="management-event-exemption-count" data-event-exempted><?= (int)($row['attendance_exempted'] ?? 0) ?> exempted</small>
        </td>
        <td role="cell" data-label="Attendance status"><?php if ($manageAttendance): ?><button type="button" class="btn btn-sm management-event-attendance-state" data-attendance="<?= $id ?>" data-event-attendance-status="<?= $attendanceState ?>" data-attendance-mode="manage" aria-label="Manage attendance for <?= synk_management_h($row['title']) ?>"<?= $row['status']!=='ACTIVE' || $row['event_date']>date('Y-m-d') ? ' disabled' : '' ?>><span data-event-attendance-label><?= $attendanceLabel ?></span><i class="bx bx-chevron-right" aria-hidden="true"></i></button><?php else: ?><span class="management-event-attendance-state" data-event-attendance-status="<?= $attendanceState ?>"><?= $attendanceLabel ?></span><?php endif; ?></td>
        <?php else: ?><td role="cell" data-label="Event date" class="text-nowrap"><?= synk_management_h(date('M j, Y',strtotime($row['contribution_date']))) ?></td><td role="cell" data-label="Amount" class="text-nowrap">₱<?= number_format((float)$row['amount'],2) ?></td><?php endif; ?>
        <td role="cell" data-label="<?= $isEvents ? 'Event status' : 'Status' ?>"><span class="badge bg-label-<?= $row['status']==='ACTIVE' ? 'success' : 'secondary' ?>"><?= synk_management_h($statusOptions[$row['status']]) ?></span></td>
        <td role="cell" class="management-record-actions">
          <?php if ($isEvents): ?><div class="management-event-actions"><?php endif; ?>
          <?php if(synk_management_operation_allowed($permissions,'exemptions')): ?>
            <div class="management-exemption-row-actions"><a class="btn btn-sm btn-outline-secondary" href="<?= synk_management_h($pageUrl('exemptions.php',[$isEvents?'event_id':'contribution_id'=>$id,'exam_term'=>$row['exam_term']])) ?>">View exemptions</a><?php if($manageExemptions): ?><button class="btn btn-sm btn-outline-primary" type="button" data-exempt-open <?= $isEvents?'data-exempt-event':'data-exempt-contribution' ?>="<?= $id ?>"<?= $row['status']!=='ACTIVE'?' disabled':'' ?>>Exempt students</button><?php endif; ?></div>
          <?php endif; ?>
          <?php if($isEvents && !$canWrite && $attendanceState!=='CLOSED' && $permissions['attendance'] && ($isFlagRaising?$term['key']===$currentTerm['key']:$term['period_key']===$currentTerm['period_key'])): ?><button class="btn btn-sm btn-primary" type="button" data-attendance="<?= $id ?>"<?= $attendanceState!=='OPEN' || $row['status']!=='ACTIVE' ? ' disabled' : '' ?>>Scan attendance</button><?php endif; ?>
          <?php $showAttendanceAction=$isEvents && ($attendanceState==='CLOSED' || ($permissions['attendance'] && $canWrite)); ?>
          <?php if ($canWrite || $editImport || $canDelete || $showAttendanceAction): ?>
            <div class="management-row-actions">
              <?php if ($showAttendanceAction): ?><button type="button" class="btn btn-sm btn-primary" data-attendance="<?= $id ?>"<?= $attendanceState!=='CLOSED' && ($row['status']!=='ACTIVE' || $row['event_date']>date('Y-m-d')) ? ' disabled title="Attendance requires an active event on or after its event date."' : '' ?>><i class="bx <?= $attendanceState==='CLOSED' ? 'bx-list-check' : 'bx-qr-scan' ?>" aria-hidden="true"></i><span data-event-attendance-action-label><?= $attendanceState === 'CLOSED' ? 'View attendance' : 'Take attendance' ?></span></button><?php endif; ?>
              <?php if ($canWrite || $editImport): ?><button type="button" class="btn btn-sm btn-outline-primary" data-edit="<?= $id ?>"<?= $editImport ? ' data-edit-import' : '' ?> aria-label="Edit <?= synk_management_h($row['title']) ?>"><i class="bx bx-edit-alt" aria-hidden="true"></i><span>Edit</span></button><?php endif; ?>
              <?php if ($canDelete): ?><button type="button" class="btn btn-sm btn-outline-danger" data-delete="<?= $id ?>" aria-label="Delete <?= synk_management_h($row['title']) ?>"><i class="bx bx-trash" aria-hidden="true"></i><span>Delete</span></button><?php endif; ?>
            </div>
          <?php elseif (!$manageAttendance && !$manageExemptions): ?><span class="text-muted float-end">View only</span><?php endif; ?>
          <?php if ($isEvents): ?></div><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <nav class="management-pagination" aria-label="<?= $pageTitle ?> pages"><span>Page <?= $pagination['page'] ?> of <?= $pagination['pages'] ?> · <?= number_format($pagination['total']) ?> records</span><div><?php foreach (['Previous'=>-1,'Next'=>1] as $label=>$step): $next=$pagination['page']+$step; ?><a class="btn btn-sm btn-outline-primary<?= $next<1 || $next>$pagination['pages'] ? ' disabled' : '' ?>"<?= $next<1 || $next>$pagination['pages'] ? ' aria-disabled="true" tabindex="-1"' : '' ?> href="<?= synk_management_h($catalogUrl(['page'=>max(1,$next)])) ?>"><?= $label ?></a><?php endforeach; ?></div></nav>
</section>
<div class="modal fade" id="management-editor" tabindex="-1" aria-labelledby="management-editor-title" aria-hidden="true" data-bs-backdrop="static"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title fs-5" id="management-editor-title">Add <?= $singular ?></h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <form id="management-record-form" class="management-modal-form"><div class="modal-body"><div class="alert alert-danger" id="management-editor-error" role="alert" hidden></div><fieldset id="management-editor-fields">
    <input type="hidden" name="record_id" value=""><input type="hidden" name="version" value="">
    <div class="row g-3"><div class="col-12"><label for="record-title" class="form-label"><?= $singular ?> title <span class="text-danger">*</span></label><input id="record-title" class="form-control management-uppercase" name="title" maxlength="255" required></div>
      <input type="hidden" name="organization_id" value="<?= (int)$scope['organization_id'] ?>"><input type="hidden" name="record_term" value="<?= synk_management_h($currentTerm['key']) ?>">
      <div class="col-md-4"><label for="record-date" class="form-label">Event date <span class="text-danger">*</span></label><input id="record-date" type="date" class="form-control" name="<?= $isEvents ? 'event_date' : 'contribution_date' ?>" required></div>
      <div class="col-md-4"><label for="record-status" class="form-label">Status</label><select id="record-status" name="status" class="form-select"><?php foreach($statusOptions as $status=>$statusLabel): ?><option value="<?= $status ?>"><?= $statusLabel ?></option><?php endforeach; ?></select></div>
      <?php if ($isEvents && !$isFlagRaising): ?><div class="col-md-4"><label for="record-absence" class="form-label">Absence fine (PHP)</label><input id="record-absence" class="form-control" type="number" name="absence_fine" min="0" max="9999999999.99" step="0.01"><div class="form-text">Leave blank when no fixed absence fine applies.</div></div><?php endif; ?>
      <input type="hidden" name="audience_mode" value="ALL">
      <?php if (!$isEvents): ?><div class="col-md-4"><label for="record-amount" class="form-label">Amount (PHP) <span class="text-danger">*</span></label><input id="record-amount" class="form-control" type="number" name="amount" min="0" max="9999999999.99" step="0.01" required></div><div class="col-12"><label for="record-event" class="form-label">Related event (optional)</label><select id="record-event" name="event_id" class="form-select"><option value="">No event link</option><?php foreach($catalogOptions['events'] as $event): ?><option value="<?= (int)$event['event_id'] ?>" data-term="<?= $event['ay_id'] && $event['semester'] ? (int)$event['ay_id'].':'.(int)$event['semester'] : '' ?>"><?= synk_management_h($event['title'].' · '.$event['event_date']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="col-12"><label for="record-description" class="form-label">Description</label><textarea class="form-control management-uppercase" name="description" id="record-description" rows="3" maxlength="10000"></textarea></div>
    </div>
    <p class="small text-muted mt-4 mb-0">Text is saved in uppercase.</p>
  </fieldset></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit" id="management-record-save">Save <?= $singular ?></button></div></form>
</div></div></div>
<?php if ($isEvents) require __DIR__.'/attendance-modal.php'; ?>
<?php if ($canRecordPastAttendance) require __DIR__.'/past-attendance-modal.php'; ?>
<div class="modal fade" id="management-delete-modal" tabindex="-1" aria-labelledby="management-delete-title" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="management-delete-title">Delete <?= $singular ?></h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="alert alert-danger" id="management-delete-error" role="alert" hidden></div>
      <p>Delete <strong id="management-delete-name"></strong>?</p>
      <p class="small text-muted mb-0" id="management-delete-explanation"></p>
      <?php if ($isEvents): ?>
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" id="management-delete-event-confirmation">
          <label class="form-check-label" for="management-delete-event-confirmation">I confirm permanent deletion of this <?= $isFlagRaising ? 'ceremony' : 'event' ?> and its connected records.</label>
        </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="button" id="management-delete-confirm" disabled>Delete <?= $singular ?></button></div>
  </div></div>
</div>
