<?php
if (($page ?? '') !== 'payment_corrections') { http_response_code(404); exit; }
$h = 'synk_management_h';
$money = 'synk_management_account_money';
$formatCents = static function (int $cents) use ($money): string {
    return $money(synk_management_money_text($cents));
};
$localDate = static function (string $utc): string {
    return (new DateTimeImmutable($utc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y');
};
$filterQuery = ['search'=>$search,'page'=>$correctionPage];
$url = static function (array $query) use ($pageUrl): string {
    return $pageUrl('payment-corrections.php',$query);
};
$paidReceipts = array_filter($receipts,static function (array $receipt): bool {
    return $receipt['remaining'] > 0;
});
$canDelete = (bool)array_filter($paidReceipts,static function (array $receipt): bool {
    return $receipt['balanced'];
});
?>
<div class="payment-corrections">
  <?php if ($errorMessage !== '' && !$receipts): ?><div class="alert alert-danger" role="alert"><?= $h($errorMessage) ?></div><?php endif; ?>
  <?php if (!$ready): ?>
    <div class="alert alert-warning" role="status">Payment correction storage needs setup. Apply the Management payment reversals SQL before using this page.</div>
  <?php endif; ?>

  <section class="card mb-4" aria-labelledby="correction-filter-title">
    <form method="get" action="payment-corrections.php" class="card-body">
      <h2 class="h5 mb-3" id="correction-filter-title"><label for="correction-search">Search student</label></h2>
      <input type="hidden" name="term" value="<?= $h($term['key']) ?>">
      <input type="hidden" name="exam_term" value="<?= $h($term['exam_term']) ?>">
      <div class="d-flex gap-2">
        <input class="form-control correction-search" id="correction-search" type="search" name="search" maxlength="100"
          placeholder="Name, student ID, or receipt number" value="<?= $h($search) ?>" required>
        <button class="btn btn-primary" type="submit"<?= $ready ? '' : ' disabled' ?>>Search</button>
      </div>
    </form>
  </section>

  <?php if ($studentResults !== null): ?>
    <section class="card" aria-labelledby="correction-results-title">
      <h2 class="h5 px-4 pt-4 mb-3" id="correction-results-title">Matching students</h2>
      <?php if (!$studentResults['rows']): ?>
        <p class="text-muted px-4 pb-2">No matching students have receipts in this period.</p>
      <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($studentResults['rows'] as $row): ?>
            <li class="list-group-item p-0">
              <a class="correction-student-result" href="<?= $h($url($filterQuery+['student_id'=>$row['student_id']]).'#correction-account') ?>">
                <span><strong><?= $h($row['student_name']) ?></strong><small class="d-block text-muted"><?= $h($row['student_number']) ?></small></span>
                <strong><?= $money($row['total_paid']) ?></strong><i class="bx bx-chevron-right" aria-hidden="true"></i>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($correctionPage > 1 || $studentResults['has_next']): ?>
        <nav class="card-body d-flex justify-content-end gap-2" aria-label="Student search pages">
          <?php if ($correctionPage > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?= $h($url(array_replace($filterQuery,['page'=>$correctionPage-1]))) ?>">Previous</a><?php endif; ?>
          <?php if ($studentResults['has_next']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= $h($url(array_replace($filterQuery,['page'=>$correctionPage+1]))) ?>">Next</a><?php endif; ?>
        </nav>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if ($receipts): $student = reset($receipts); ?>
    <section id="correction-account" aria-labelledby="correction-account-title">
      <?php if ($errorMessage !== ''): ?><div class="alert alert-danger" role="alert"><?= $h($errorMessage) ?></div><?php endif; ?>
      <?php if ($notice !== ''): ?><div class="alert alert-success" role="status"><?= $h($notice) ?></div><?php endif; ?>
      <form id="correction-editor" class="card mb-4" method="post" action="<?= $h($url($filterQuery+['student_id'=>$studentId]).'#correction-account') ?>">
        <div class="card-body correction-account-heading">
          <div>
            <h2 class="h5 mb-1" id="correction-account-title"><?= $h($student['student_name']) ?></h2>
            <p class="text-muted mb-0"><?= $h($student['student_number'].' · '.$term['period_text']) ?></p>
          </div>
          <div class="correction-account-total"><span class="d-block small text-muted">Total paid</span><strong data-correction-account-credit><?= $formatCents(array_sum(array_column($paidReceipts,'remaining'))) ?></strong></div>
        </div>
        <?php if ($paidReceipts): ?><p class="px-4 mb-3 text-muted">Open a receipt and check the payments to delete.</p>
        <?php else: ?><p class="px-4 mb-4 text-muted">No paid payments in this period.</p><?php endif; ?>

        <?php foreach ($paidReceipts as $id=>$receipt):
            $paidItems = array_filter($receipt['items'],static function (array $item): bool { return $item['remaining'] > 0; });
        ?>
          <details class="correction-receipt" data-correction-receipt<?= array_intersect_key($paidItems,$selected) ? ' open' : '' ?>>
            <summary>
              <span><strong>Receipt <?= $h($receipt['receipt_number']) ?></strong><small class="d-block text-muted"><?= $h($localDate($receipt['recorded_at'])) ?></small></span>
              <strong><?= $formatCents($receipt['remaining']) ?></strong>
              <i class="bx bx-chevron-down" aria-hidden="true"></i>
            </summary>
            <div class="correction-receipt-body">
              <div class="correction-receipt-controls">
                <?php if ($receipt['balanced']): ?>
                  <label class="correction-checkbox">
                    <input class="form-check-input" type="checkbox" data-correction-select-all>
                    <span>Select all payments on this receipt</span>
                  </label>
                <?php endif; ?>
                <a class="small" href="<?= $h('payment-receipt.php?payment_id='.$id) ?>" target="_blank" rel="noopener">View receipt<span class="visually-hidden"> <?= $h($receipt['receipt_number']) ?></span></a>
              </div>
              <?php if (!$receipt['balanced']): ?>
                <p class="alert alert-warning py-2 mb-2">The receipt total and payment amounts differ. Review this receipt before deleting its payments.</p>
              <?php endif; ?>
              <ul class="correction-items">
                <?php foreach ($paidItems as $itemId=>$item): ?>
                  <li>
                    <?php if ($receipt['balanced']): ?>
                      <label class="correction-payment-row">
                        <input class="form-check-input" type="checkbox" value="<?= $itemId ?>" data-correction-item data-cents="<?= $item['remaining'] ?>"<?= isset($selected[$itemId]) ? ' checked' : '' ?>>
                        <span><?= $h($item['title']) ?></span>
                        <strong><?= $formatCents($item['remaining']) ?></strong>
                      </label>
                    <?php else: ?>
                      <div class="correction-payment-row"><span><?= $h($item['title']) ?></span><strong><?= $formatCents($item['remaining']) ?></strong></div>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          </details>
        <?php endforeach; ?>

        <?php if ($canDelete): ?>
          <div class="card-body correction-delete">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
            <input type="hidden" name="request_key" value="<?= $h($requestKey) ?>">
            <input type="hidden" name="receipt_version" value="<?= $h($receiptVersion) ?>">
            <input type="hidden" name="selection">
            <label class="form-label" for="correction-reason">Reason</label>
            <textarea class="form-control" name="reason" id="correction-reason" maxlength="500" rows="2" required><?= $h($reason) ?></textarea>
            <div class="correction-delete-actions mt-3">
              <p class="mb-0" aria-live="polite"><span data-correction-count>0 selected</span> · <strong data-correction-selected-total>₱0.00</strong></p>
              <button class="btn btn-danger" type="submit" data-correction-delete disabled>Delete selected payments</button>
            </div>
          </div>
        <?php endif; ?>
      </form>

      <?php if ($history): ?>
        <details class="card correction-history">
          <summary class="card-body fw-semibold">Correction history</summary>
          <div class="table-responsive" role="region" aria-label="Correction history" tabindex="0">
            <table class="table mb-0">
              <thead><tr><th scope="col">Reference</th><th scope="col">Reason</th><th scope="col">Recorded by</th><th scope="col" class="text-end">Amount</th></tr></thead>
              <tbody>
                <?php foreach ($history as $entry): ?>
                  <tr>
                    <th scope="row"><?= $h(synk_management_payment_reversal_reference($entry['reversal_type'],$entry['reversal_id'])) ?></th>
                    <td class="correction-notes"><?= $h($entry['reason']) ?><?php if ($entry['refund_reference'] !== null): ?><small class="d-block text-muted">Refund reference <?= $h($entry['refund_reference']) ?></small><?php endif; ?></td>
                    <td><?= $h($entry['recorded_by_name']) ?><small class="d-block text-muted"><?= $h($localDate($entry['recorded_at'])) ?></small></td>
                    <td class="text-end"><?= $money($entry['amount']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      <?php endif; ?>
    </section>
  <?php elseif ($notice !== ''): ?>
    <div class="alert alert-success" role="status"><?= $h($notice) ?></div>
  <?php endif; ?>
</div>
