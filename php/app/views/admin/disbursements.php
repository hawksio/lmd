<?php $title = 'Disbursements'; ?>
<div class="page-head"><div><h1>Disbursements</h1><p>Payments to customers. Only <strong>confirmed</strong> payments have been checked against the bank record.</p></div></div>
<nav class="chips" aria-label="Filter"><?php foreach (['', 'scheduled', 'pending', 'confirmed', 'rejected', 'cancelled', 'reversed'] as $s): ?><a class="chip" href="<?= e(url('/admin/disbursements' . ($s ? "?status=$s" : ''))) ?>"<?= $status === $s ? ' aria-current="true"' : '' ?>><?= e(['' => 'All', 'pending' => 'Awaiting confirmation'][$s] ?? ucfirst($s)) ?></a><?php endforeach ?></nav>
<?php if ($rows): ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Application</th><th>Amount</th><th>Planned / paid</th><th>Reference</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($rows as $d): ?>
    <tr><td><a href="<?= e(url("/admin/applications/{$d['application_id']}#payments")) ?>"><?= e($d['company_name']) ?></a><span class="sub"><?= e(ref_str((int) $d['application_id'])) ?> · <?= e($d['full_name']) ?></span></td>
      <td><?= e(money($d['amount_pence'], $d['currency'])) ?><?= $d['fees_deducted_minor'] ? '<span class="sub">' . e(money($d['fees_deducted_minor'], $d['currency'])) . ' fees deducted</span>' : '' ?></td>
      <td class="small"><?= e($d['scheduled_for'] ?? '') ?><?= $d['paid_on'] ? '<span class="sub">paid ' . e($d['paid_on']) . '</span>' : '' ?></td>
      <td class="small"><?= e($d['payment_reference']) ?></td><td><span class="tag-sm tag-<?= e(['confirmed' => 'verified', 'pending' => 'under_review', 'scheduled' => 'submitted'][$d['status']] ?? 'rejected') ?>"><?= e(ucfirst($d['status'])) ?></span></td></tr>
  <?php endforeach ?></tbody></table></div>
<?php else: ?><div class="panel empty"><p>No payments recorded.</p></div><?php endif ?>
