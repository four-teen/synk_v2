<?php
// Management-specific period labels and financial presentation, embedded in Student.
if (!isset($financialAccount) || !is_array($financialAccount)) { http_response_code(404);return; }
$totals=$financialAccount['totals'];$attendance=$financialAccount['attendance'];
$h='synk_management_h';$money='synk_management_account_money';
$date=static function($value):string {$parsed=DateTimeImmutable::createFromFormat('!Y-m-d',substr((string)$value,0,10));return $parsed?$parsed->format('M j, Y'):'—';};
$attendanceLabels=['PRESENT'=>'Present','ABSENT'=>'Absent','PENDING'=>'Attendance pending','NOT_IN_ROSTER'=>'Not in event roster'];
?>
<section class="student-finance-heading"><div><p class="student-eyebrow">STUDENT ACCOUNT</p><h1>My Finances</h1><p>Contributions, event attendance, and your recorded charges in one place.</p></div><span class="badge bg-label-primary"><?= $h($financialAccount['academic_period']) ?></span></section>
<section class="student-finance-summary" aria-label="Financial summary">
  <?php foreach ([['due','Total to pay','bx-wallet','Current charges'],['contributions','Contributions','bx-receipt',count($financialAccount['contributions']).' active contributions'],['event_fines','Absence fines','bx-calendar-x',$attendance['absent'].' recorded absences'],['paid','Recorded payments','bx-check-circle','Payments posted to your account']] as $item): ?>
  <article class="card student-finance-stat<?= $item[0]==='due'?' student-finance-due'.($totals['due']==='0.00'?' is-paid':''):'' ?>"><span><i class="bx <?= $item[2] ?>" aria-hidden="true"></i><?= $item[1] ?></span><strong data-finance-total="<?= $item[0] ?>"><?= $money($totals[$item[0]]) ?></strong><small><?= $h($item[3]) ?></small></article>
  <?php endforeach; ?>
</section>
<section class="card student-finance-periods" aria-label="Current organization periods">
  <div><strong>Current organization periods</strong><p>Records follow each organization’s saved academic year, semester, and Management term.</p></div>
  <?php if (!$financialAccount['accounts']): ?><p class="mb-0">No financial records are available for your current enrollment. Contact your college office if you expect charges here.</p><?php endif; ?>
  <div class="student-finance-period-list"><?php foreach ($financialAccount['accounts'] as $account): ?><span title="<?= $h($account['organization']['name']) ?>"><strong><?= $h($account['organization']['code']) ?></strong><?= $h($account['period']['text']) ?></span><?php endforeach; ?></div>
</section>
<nav class="student-finance-links" aria-label="Financial page sections"><a href="#student-contributions">Contributions</a><a href="#student-events">Events & attendance</a><a href="#student-violations">Violations</a><a href="#student-payments">Payments</a><a href="#student-account-total">Account total</a></nav>
<section class="card student-finance-panel" id="student-contributions" aria-labelledby="student-contributions-title">
  <div class="student-finance-panel-heading"><div><i class="bx bx-receipt" aria-hidden="true"></i><h2 id="student-contributions-title">Contributions</h2></div><span><?= count($financialAccount['contributions']) ?> listed</span></div>
  <?php if (!$financialAccount['contributions']): ?><p class="student-finance-empty">No active contributions are listed for your current organization periods.</p><?php else: ?>
  <ul class="student-finance-records"><?php foreach ($financialAccount['contributions'] as $row): ?><li>
    <div class="student-finance-record-main"><h3><?= $h($row['title']) ?></h3><p><span><?= $h($row['organization_code']) ?></span><time datetime="<?= $h($row['contribution_date']) ?>"><?= $date($row['contribution_date']) ?></time></p><small><?= $h($row['period_text']) ?></small></div>
    <div class="student-finance-record-amount"><strong><?= $money($row['amount_due']) ?></strong><small>Remaining of <?= $money($row['amount']) ?></small><span class="student-finance-status student-finance-<?= strtolower($row['payment_status']) ?>"><?= $h(['PAID'=>'Paid','PARTIALLY_PAID'=>'Partially paid','UNPAID'=>'Unpaid'][$row['payment_status']]) ?></span></div>
  </li><?php endforeach; ?></ul>
  <?php endif; ?>
  <div class="student-finance-section-total"><span>Contribution total</span><strong><?= $money($totals['contributions']) ?></strong></div>
</section>
<section class="card student-finance-panel" id="student-events" aria-labelledby="student-events-title">
  <div class="student-finance-panel-heading"><div><i class="bx bx-calendar-event" aria-hidden="true"></i><h2 id="student-events-title">Events & attendance</h2></div><span><?= $attendance['events'] ?> events</span></div>
  <div class="student-finance-attendance-summary" aria-label="Your attendance totals"><?php foreach (['present'=>'Present','absent'=>'Absent','pending'=>'Pending','not_in_roster'=>'Not in roster'] as $key=>$label): ?><div><strong data-attendance-count="<?= $key ?>"><?= $attendance[$key] ?></strong><span><?= $label ?></span></div><?php endforeach; ?></div>
  <p class="student-finance-attendance-note">Absence fines apply only when event attendance closes. Pending attendance does not add a fine.</p>
  <?php if (!$financialAccount['events']): ?><p class="student-finance-empty">No active events are listed for your current organization periods.</p><?php else: ?>
  <ul class="student-finance-records"><?php foreach ($financialAccount['events'] as $row): ?><li>
    <div class="student-finance-record-main"><h3><?= $h($row['title']) ?></h3><p><span><?= $h($row['organization_code']) ?></span><time datetime="<?= $h($row['event_date']) ?>"><?= $date($row['event_date']) ?></time></p><small><?= $h($row['period_text']) ?></small>
      <?php if ($row['recorded_at']): ?><small>Attendance recorded: <?= $h($row['recorded_at']) ?></small><?php endif; ?>
      <small>Absence fine: <?= $money($row['absence_fine'] ?? '0.00') ?></small></div>
    <div class="student-finance-record-amount"><span class="student-finance-status student-finance-<?= strtolower($row['student_attendance']) ?>"><?= $attendanceLabels[$row['student_attendance']] ?></span><strong><?= $money($row['amount_due']) ?></strong><small>Amount to pay</small></div>
  </li><?php endforeach; ?></ul>
  <?php endif; ?>
  <div class="student-finance-section-total"><span>Total absence fines</span><strong><?= $money($totals['event_fines']) ?></strong></div>
</section>
<div class="student-finance-secondary-grid">
<section class="card student-finance-panel" id="student-violations" aria-labelledby="student-violations-title">
  <div class="student-finance-panel-heading"><div><i class="bx bx-shield-quarter" aria-hidden="true"></i><h2 id="student-violations-title">Recorded violations</h2></div></div>
  <?php if (!$financialAccount['violations']): ?><p class="student-finance-empty">No student violation entries are recorded for your current organization periods.</p><?php else: ?><ul class="student-finance-records"><?php foreach ($financialAccount['violations'] as $row): ?><li><div class="student-finance-record-main"><h3><?= $h($row['title']) ?></h3><p><?= $h($row['organization_code']) ?> · <?= $date($row['date']) ?></p><?php if($row['notes']): ?><small><?= $h($row['notes']) ?></small><?php endif; ?></div><div class="student-finance-record-amount"><strong><?= $money($row['amount_due']) ?></strong><small>Remaining of <?= $money($row['amount']) ?></small><span class="student-finance-status student-finance-<?= strtolower($row['payment_status']) ?>"><?= $h(['PAID'=>'Paid','PARTIALLY_PAID'=>'Partially paid','UNPAID'=>'Unpaid'][$row['payment_status']]) ?></span></div></li><?php endforeach; ?></ul><?php endif; ?>
  <div class="student-finance-section-total"><span>Violation charges</span><strong><?= $money($totals['violations']) ?></strong></div>
</section>
<section class="card student-finance-panel" id="student-payments" aria-labelledby="student-payments-title">
  <div class="student-finance-panel-heading"><div><i class="bx bx-credit-card" aria-hidden="true"></i><h2 id="student-payments-title">Payment history</h2></div></div>
  <?php if (!$financialAccount['payments']): ?><p class="student-finance-empty">No posted payments are available. Contact your organization for payment confirmations.</p><?php else: ?><ul class="student-finance-records"><?php foreach ($financialAccount['payments'] as $row): ?><li><div class="student-finance-record-main"><h3><?= $h($row['reference']) ?></h3><p><?= $h($row['organization_code']) ?> · <?= $date($row['date']) ?></p><small><?= $h($row['period_text']) ?></small><a href="payment-receipt.php?payment_id=<?= (int)$row['payment_id'] ?>" target="_blank" rel="noopener">View receipt</a></div><div class="student-finance-record-amount"><strong><?= $money($row['amount']) ?></strong></div></li><?php endforeach; ?></ul><?php endif; ?>
  <div class="student-finance-section-total"><span>Recorded payments · all periods</span><strong><?= $money($financialAccount['payment_history_total']) ?></strong></div>
</section>
</div>
<section class="card student-finance-account-total" id="student-account-total" aria-labelledby="student-account-total-title"><div><p class="student-eyebrow">ACCOUNT SUMMARY</p><h2 id="student-account-total-title">Your total to pay</h2><p>Automatic contributions plus recorded fines, less posted payments and deductions.</p></div><dl>
  <?php foreach (['contributions'=>'Contributions','event_fines'=>'Event absence fines','violations'=>'Violation charges','paid'=>'Less: recorded payments','deductions'=>'Less: recorded deductions'] as $key=>$label): ?><div><dt><?= $label ?></dt><dd><?= $money($totals[$key]) ?></dd></div><?php endforeach; ?>
  <div class="student-finance-total-due<?= $totals['due']==='0.00'?' is-paid':'' ?>"><dt>Total to pay</dt><dd><?= $money($totals['due']) ?></dd></div>
</dl></section>
