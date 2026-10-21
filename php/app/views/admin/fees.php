<?php $title = 'Fees and payments'; ?>
<div class="page-head"><div><h1>Fees and payments</h1><p>Charges recorded under signed agreements. "Paid" only once a payment has actually been confirmed.</p></div></div>
<nav class="chips" aria-label="Filter"><?php foreach (['', 'pending', 'paid', 'waived', 'refunded', 'cancelled'] as $s): ?><a class="chip" href="<?= e(url('/admin/fees' . ($s ? "?status=$s" : ''))) ?>"<?= $status === $s ? ' aria-current="true"' : '' ?>><?= $s ? ucfirst($s) : 'All' ?></a><?php endforeach ?></nav>
<?php if ($rows): ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Fee</th><th>Application</th><th>Amount</th><th>When payable</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($rows as $f): ?>
    <tr><td><?= e($f['name']) ?><span class="sub"><?= e(RULE_KINDS[$f['kind']] ?? $f['kind']) ?></span></td>
      <td><a href="<?= e(url("/admin/applications/{$f['application_id']}#fees")) ?>"><?= e($f['company_name']) ?></a><span class="sub"><?= e(ref_str((int) $f['application_id'])) ?></span></td>
      <td><?= e(money($f['amount_minor'], $f['currency'])) ?><?= $f['percent'] ? '<span class="sub">' . e($f['percent']) . '% of ' . e(money($f['basis_minor'], $f['currency'])) . '</span>' : '' ?></td>
      <td class="small"><?= e($f['payable_when']) ?></td><td><?= fee_badge($f['status']) ?></td></tr>
  <?php endforeach ?></tbody></table></div>
<?php else: ?><div class="panel empty"><p>No fee records.</p></div><?php endif ?>
