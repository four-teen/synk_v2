<?php
if (!isset($paymentRegister,$term,$currentTerm)) { http_response_code(404); exit; }
$h = 'synk_management_h';
$money = 'synk_management_account_money';
$today = $paymentRegister['today'];
$todayTotals = $paymentRegister['today_totals'];
$filters = $paymentRegister['filters'];
$pagination = $paymentRegister['pagination'];
$activeFilters = array_filter($filters,static function ($value): bool { return $value !== null && $value !== ''; });
$registerUrl = $pageUrl('financial.php').'#management-payment-register';
$receiptPage = static function (int $page) use ($pageUrl,$activeFilters): string {
    return $pageUrl('financial.php',$activeFilters+['page'=>$page]).'#management-payment-register';
};
$utc = new DateTimeZone('UTC');
?>
<section class="management-register-today" aria-labelledby="management-register-today-title">
  <div class="management-register-toolbar">
    <div>
      <h2 id="management-register-today-title">Today <time datetime="<?= $today->format('Y-m-d') ?>"><?= $today->format('M j, Y') ?></time></h2>
      <?php if ($term['period_key'] !== $currentTerm['period_key']): ?>
        <p>Current Management period · <?= $h($currentTerm['period_text']) ?></p>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <?php if ($permissions['payment_corrections']): ?>
        <a class="btn btn-outline-primary" id="management-financial-payment-corrections" href="<?= $h($pageUrl('payment-corrections.php')) ?>"><i class="bx bx-undo me-1" aria-hidden="true"></i>Payment Corrections</a>
      <?php endif; ?>
      <?php if (synk_management_operation_allowed($permissions,'student_records')): ?>
        <a class="btn btn-primary" id="management-register-find-student" href="<?= $h($pageUrl('students.php',[
            'term'=>$currentTerm['key'],'exam_term'=>$currentTerm['exam_term'],
        ])) ?>"><i class="bx bx-user me-1" aria-hidden="true"></i>Find student</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="management-stats management-register-stats">
    <article class="card management-stat">
      <span class="management-stat-icon bg-label-success"><i class="bx bx-wallet" aria-hidden="true"></i></span>
      <div>
        <span>Today's net collections</span><strong data-register-today-collected><?= $todayTotals === null ? '—' : $money((string)$todayTotals['collected']) ?></strong>
        <?php if ($todayTotals && ($todayTotals['voided'] > 0 || $todayTotals['refunded'] > 0)): ?>
          <small class="text-muted d-block">Posted <?= $money($todayTotals['posted']) ?> · Voided <?= $money($todayTotals['voided']) ?> · Refunded <?= $money($todayTotals['refunded']) ?></small>
        <?php endif; ?>
      </div>
    </article>
    <article class="card management-stat">
      <span class="management-stat-icon bg-label-info"><i class="bx bx-receipt" aria-hidden="true"></i></span>
      <div><span>Receipts issued today</span><strong data-register-today-receipts><?= $todayTotals === null ? '—' : number_format($todayTotals['receipts']) ?></strong></div>
    </article>
  </div>
</section>

<section class="card management-panel" aria-labelledby="management-payment-register-title" id="management-payment-register">
  <div class="management-panel-heading">
    <div>
      <h2 id="management-payment-register-title">Payment register</h2>
      <p><?= $h($term['period_text']) ?> · Philippine time</p>
    </div>
    <?php if ($paymentRegister['available'] && $paymentRegister['filter_error'] === ''): ?>
      <div class="management-register-total">
        <span><?= $activeFilters ? 'Filtered net total' : 'Period net total' ?></span><strong data-register-total><?= $money((string)$paymentRegister['filtered_total']) ?></strong>
        <?php if ($paymentRegister['filtered_reversed'] > 0): ?><span><?= $money($paymentRegister['filtered_reversed']) ?> reversed</span><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if (!$paymentRegister['available']): ?>
    <div class="management-empty"><i class="bx bx-error-circle" aria-hidden="true"></i><h3>Payment records are unavailable</h3></div>
  <?php else: ?>
    <form class="management-filters management-register-filters" id="management-register-filters" action="financial.php#management-payment-register" method="get" aria-label="Filter receipts">
      <input type="hidden" name="term" value="<?= $h($term['key']) ?>">
      <input type="hidden" name="exam_term" value="<?= $h($term['exam_term']) ?>">
      <div class="management-filter-search">
        <label for="management-register-search">Search receipts</label>
        <input class="form-control" id="management-register-search" type="search" name="search" maxlength="150"
          placeholder="Student name, ID, or receipt number" autocomplete="off" value="<?= $h($filters['search']) ?>">
      </div>
      <div>
        <label for="management-register-from">From date</label>
        <input class="form-control" id="management-register-from" type="date" name="date_from" value="<?= $h($filters['date_from']) ?>">
      </div>
      <div>
        <label for="management-register-to">Through date</label>
        <input class="form-control" id="management-register-to" type="date" name="date_to" value="<?= $h($filters['date_to']) ?>">
      </div>
      <div class="management-register-cashier">
        <label for="management-register-cashier">Cashier</label>
        <select class="form-select" id="management-register-cashier" name="cashier_id">
          <option value="">All cashiers</option>
          <?php foreach ($paymentRegister['cashiers'] as $cashier): $cashierId=(int)$cashier['recorded_by']; ?>
            <option value="<?= $cashierId ?>"<?= $filters['cashier_id'] === $cashierId ? ' selected' : '' ?>><?= $h($cashier['recorded_by_name'] ?: 'Cashier #'.$cashierId) ?></option>
          <?php endforeach; ?>
          <?php if ($filters['cashier_id'] !== null && !in_array($filters['cashier_id'],array_map('intval',array_column($paymentRegister['cashiers'],'recorded_by')),true)): ?>
            <option value="<?= $filters['cashier_id'] ?>" selected>Cashier #<?= $filters['cashier_id'] ?></option>
          <?php endif; ?>
        </select>
      </div>
      <div class="management-filter-actions">
        <?php if ($activeFilters): ?><a class="btn btn-outline-secondary" href="<?= $h($registerUrl) ?>">Clear filters</a><?php endif; ?>
        <button type="submit" class="btn btn-primary">Apply filters</button>
      </div>
    </form>

    <?php if ($paymentRegister['filter_error'] !== ''): ?>
      <p class="alert alert-warning management-register-error" role="alert"><?= $h($paymentRegister['filter_error']) ?></p>
    <?php elseif (!$paymentRegister['rows']): ?>
      <div class="management-empty">
        <i class="bx <?= $activeFilters ? 'bx-search' : 'bx-receipt' ?>" aria-hidden="true"></i>
        <h3><?= $activeFilters ? 'No receipts match these filters' : 'No receipts recorded for this period' ?></h3>
      </div>
    <?php else: ?>
      <div class="management-table-scroll" role="region" tabindex="0" aria-label="Posted payment receipts">
        <table class="table dean-students-table management-table management-mobile-records management-register-table" role="table">
          <thead role="rowgroup"><tr role="row">
            <th scope="col" role="columnheader">Posted date / time</th>
            <th scope="col" role="columnheader">Receipt number</th>
            <th scope="col" role="columnheader">Student</th>
            <th scope="col" role="columnheader">Cashier</th>
            <th scope="col" role="columnheader" class="text-end">Net amount</th>
            <th scope="col" role="columnheader" class="text-end">Receipt</th>
          </tr></thead>
          <tbody role="rowgroup">
            <?php foreach ($paymentRegister['rows'] as $receipt):
                $posted = (new DateTimeImmutable($receipt['recorded_at'],$utc))->setTimezone($today->getTimezone());
                $state = synk_management_payment_state($receipt['posted_amount'],$receipt['amount']);
            ?>
              <tr role="row">
                <td role="cell" data-label="Posted date / time">
                  <time datetime="<?= $posted->format(DATE_ATOM) ?>"><?= $posted->format('M j, Y') ?><small><?= $posted->format('g:i A') ?></small></time>
                </td>
                <td role="cell" data-label="Receipt number"><strong><?= $h($receipt['receipt_number']) ?></strong><?php if ($state !== 'Posted'): ?><small class="d-block text-muted"><?= $h($state) ?></small><?php endif; ?></td>
                <td role="cell" data-label="Student" class="management-record-title">
                  <strong><?= $h($receipt['student_name']) ?></strong><small><?= $h($receipt['student_number']) ?></small>
                </td>
                <td role="cell" data-label="Cashier"><?= $h($receipt['recorded_by_name'] ?: '—') ?></td>
                <td role="cell" data-label="Net amount" class="text-end management-register-amount"><strong><?= $money((string)$receipt['amount']) ?></strong><?php if ($state !== 'Posted'): ?><small class="d-block text-muted">Originally <?= $money($receipt['posted_amount']) ?></small><?php endif; ?></td>
                <td role="cell" class="text-end management-record-actions">
                  <a class="btn btn-sm btn-outline-primary management-register-receipt" href="payment-receipt.php?payment_id=<?= (int)$receipt['payment_id'] ?>"
                    target="_blank" rel="noopener" aria-label="View or reprint receipt <?= $h($receipt['receipt_number']) ?>">View / reprint</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <nav class="management-pagination" aria-label="Receipt pages">
        <span>Showing <?= number_format($pagination['from']) ?>–<?= number_format($pagination['to']) ?> of <?= number_format($pagination['total']) ?> receipts</span>
        <?php if ($pagination['pages'] > 1): ?>
          <div>
            <?php foreach (['Previous'=>-1,'Next'=>1] as $label=>$step):
                $next = $pagination['page'] + $step;
            ?>
              <?php if ($next < 1 || $next > $pagination['pages']): ?><span class="btn btn-sm btn-outline-primary disabled" aria-disabled="true"><?= $label ?></span>
              <?php else: ?><a class="btn btn-sm btn-outline-primary" href="<?= $h($receiptPage($next)) ?>"><?= $label ?></a>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
