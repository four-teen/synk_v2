<?php
require_once __DIR__.'/../backend/management_officers_helper.php';
$officers=synk_management_officers_list($conn,$scope);$audit=synk_management_officer_audit($conn,$scope);$ready=synk_management_officers_ready($conn);
$auditSummary=static function(array $row):string{
    $data=json_decode($row['details'],true) ?: [];
    if($row['action']==='PAYMENT')return 'Payment received: ₱'.number_format((float)($data['amount'] ?? 0),2).' · Receipt '.($data['receipt'] ?? '');
    if($row['action']==='ATTENDANCE_SCAN')return 'Student attendance recorded.';
    if($row['action']==='VIOLATION_ADD')return 'Applied '.count($data['student_violation_ids'] ?? []).' violation(s) to student #'.($data['student_id'] ?? '').' · '.($data['period'] ?? '');
    if(strpos($row['action'],'CATALOG_')===0)return ucfirst((string)($data['kind'] ?? 'Record')).': '.($data['title'] ?? '').' (record '.($data['record_id'] ?? '').')';
    if(strpos($row['action'],'ATTENDANCE_')===0)return 'Event: '.($data['title'] ?? '').' ('.($data['present'] ?? 0).' present / '.($data['eligible'] ?? 0).' eligible)';
    if($row['action']==='REVOKE')return 'Management access revoked.';
    return synk_management_operation_summary($data,'can_').' · Expires '.($data['expires_on'] ?? '');
};
?>
<section class="card management-panel management-officers" id="management-officers">
<div class="management-panel-heading"><div><p class="card-section-title mb-1">FACULTY SUPERVISION</p><h1 class="fs-4">Student Officers</h1><p>Authorize students for <?= synk_management_h($scope['organization_name']) ?> under <?= synk_management_h($accountName) ?>.</p></div></div>
<div class="p-4">
<?php if(!$ready): ?><div class="alert alert-warning">Student officer storage has not been prepared. Run the Management officers setup before adding accounts.</div><?php else: ?>
<p>Find an enrolled student, select their permitted operations, then set an expiry date. Access also ends when the academic semester changes. Students use their own school Google account at <a href="login.php">Management sign in</a>.</p>
<div class="alert alert-success" id="officer-notice" role="status" hidden></div><div class="alert alert-danger" id="officer-error" role="alert" hidden></div>
<form id="officer-search-form" class="management-officer-search"><label class="form-label" for="officer-search">Find a student in <?= synk_management_h($currentTerm['term_text']) ?></label><div class="d-flex gap-2"><input id="officer-search" class="form-control" type="search" required minlength="2" maxlength="150" placeholder="Student name, ID, or school email"><button class="btn btn-outline-primary" type="submit">Search</button></div></form>
<div id="officer-search-results" aria-live="polite"></div>
<form id="officer-permission-form" class="management-officer-form" hidden>
<h2 class="fs-5" id="officer-selected-name">Selected student</h2><p id="officer-selected-email"></p><input type="hidden" name="student_id"><input type="hidden" name="revision">
<fieldset><legend class="fs-6">Allowed operations</legend>
<label><input type="checkbox" name="can_view" value="1"><span><strong>View records</strong><small>Read student financial overviews, receipts, and organization records.</small></span></label>
<label><input type="checkbox" name="can_attendance" value="1"><span><strong>Scan attendance</strong><small>Record QR attendance for events opened by the faculty manager or an authorized event manager.</small></span></label>
<label><input type="checkbox" name="can_payments" value="1"><span><strong>Collect payments</strong><small>Record payments and issue receipts for other students. Cannot collect for their own account.</small></span></label>
<label><input type="checkbox" name="can_manage_contributions" value="1"><span><strong>Manage contributions</strong><small>Add, edit, and delete contributions, including amounts and event links, within your organization.</small></span></label>
<label><input type="checkbox" name="can_manage_events" value="1"><span><strong>Manage events</strong><small>Add, edit, and delete events and fines; open, close, and reopen attendance. Enable Scan attendance separately to record QR attendance.</small></span></label>
<label><input type="checkbox" name="can_manage_violations" value="1"><span><strong>Manage violations</strong><small>Manage the standard violation list and apply its violations to individual students within your organization.</small></span></label>
</fieldset><label class="form-label mt-3" for="officer-expiry">Access expires on</label><input type="date" class="form-control" id="officer-expiry" name="expires_on" min="<?= date('Y-m-d') ?>" max="<?= (new DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d') ?>" required>
<p class="small text-muted mt-2">These operations apply to your assigned organization. Students receive only the permissions you enable. Officers cannot delegate access or change Management settings. Existing payment and attendance protections still apply.</p>
<div class="d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit">Save student access</button><button class="btn btn-outline-secondary" type="button" id="officer-cancel">Cancel</button></div>
</form>
<h2 class="fs-5 mt-4">Students you supervise</h2>
<?php if(!$officers): ?><p class="text-muted">No student officers assigned yet. Search above to add one.</p><?php endif; ?>
<div class="management-officer-list"><?php foreach($officers as$officer): ?>
<article><div><h3 class="fs-6"><?= synk_management_h($officer['first_name'].' '.$officer['last_name']) ?></h3><p><?= synk_management_h($officer['student_number'].' · '.$officer['email']) ?></p><p class="small">Status: <strong><?= synk_management_h($officer['effective_status']) ?></strong> · Expires <?= synk_management_h($officer['expires_on']) ?></p><p class="small">Operations: <?= synk_management_h(synk_management_operation_summary($officer,'can_')) ?></p></div>
<div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-primary" type="button" data-officer-edit="<?= (int)$officer['student_id'] ?>">Edit permissions / Renew</button><?php if($officer['status']==='ACTIVE'): ?><button class="btn btn-outline-danger" type="button" data-officer-revoke="<?= (int)$officer['student_id'] ?>">Revoke access</button><?php endif; ?></div></article>
<?php endforeach; ?></div>
<details class="mt-4"><summary class="fw-semibold">Recent officer activity and permission changes</summary><ul class="management-officer-audit"><?php foreach($audit as$row): ?><li><strong><?= synk_management_h($row['first_name'].' '.$row['last_name'].' · '.str_replace('_',' ',$row['action'])) ?></strong><span><?= synk_management_h($row['actor_name']) ?> · <?= synk_management_h((new DateTimeImmutable($row['recorded_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A')) ?></span><small><?= synk_management_h($auditSummary($row)) ?></small></li><?php endforeach; ?><?php if(!$audit): ?><li>No activity recorded yet.</li><?php endif; ?></ul></details>
<script type="application/json" id="management-officer-data"><?= json_encode($officers,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?></div></section>
