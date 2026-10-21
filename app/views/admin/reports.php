<?php $title = 'Reports'; ?>
<div class="page-head"><div><h1>Reports</h1><p><?= e($start) ?> to <?= e($end) ?>. Each currency is reported separately; approvals are not money, and only confirmed payments count as disbursed or collected.</p></div>
  <?php if (can('export_data')): ?><a class="btn btn-outline" href="<?= e(url('/admin/reports/?' . http_build_query(['from' => $start, 'to' => $end, 'export' => 'csv']))) ?>">Export CSV</a><?php endif ?></div>
<form class="filters" method="get"><?= daterange($start, $end) ?><button class="btn btn-dark btn-sm" type="submit">Update</button></form>

<div class="tiles">
  <div class="tile"><strong><?= $r['applications'] ?></strong><span>Applications submitted</span></div>
  <div class="tile"><strong><?= $r['approved'] ?></strong><span>Approved (<?= $r['full'] ?> full · <?= $r['partial'] ?> partial · <?= $r['conditional'] ?> conditional)</span></div>
  <div class="tile"><strong><?= $r['declined'] ?></strong><span>Declined</span></div>
  <div class="tile"><strong><?= $r['approval_rate'] !== null ? $r['approval_rate'] . '%' : '–' ?></strong><span>Approval rate (of decided)</span></div>
</div>
<div class="tiles">
  <div class="tile"><strong><?= $r['avg_review_days'] ?? '–' ?></strong><span>Average days, submission to decision</span></div>
  <div class="tile"><strong><?= $r['verification_backlog'] ?></strong><span>Documents awaiting verification</span></div>
  <div class="tile"><strong><?= $r['instalments_paid'] ?>/<?= $r['instalments_due'] ?></strong><span>Instalments due in period and paid</span></div>
  <div class="tile"><strong><?= count($r['staff']) ?></strong><span>Active staff</span></div>
</div>

<?php foreach ($r['by_currency'] as $cur => $v): $mm = fn($k) => e(money($v[$k] ?? 0, $cur)); ?>
<section class="panel">
  <h2><?= e($cur) ?>: lending and revenue</h2>
  <div class="layout-2 even">
    <dl class="facts">
      <dt>Financing requested</dt><dd><?= $mm('requested') ?></dd>
      <dt>Financing approved</dt><dd><?= $mm('approved') ?><?php if (!empty($v['approved_n'])): ?><small class="block muted">average <?= e(money(intdiv($v['approved'], $v['approved_n']), $cur)) ?></small><?php endif ?></dd>
      <dt>Actually disbursed (confirmed)</dt><dd><?= $mm('disbursed') ?></dd>
      <dt>Repayments received</dt><dd><?= $mm('repaid') ?></dd>
    </dl>
    <dl class="facts">
      <dt>Fees invoiced</dt><dd><?= $mm('fees_invoiced') ?></dd>
      <dt>Fees collected</dt><dd><?= $mm('fees_collected') ?></dd>
      <dt>Fees waived</dt><dd><?= $mm('fees_waived') ?></dd>
      <dt>Fees refunded</dt><dd><?= $mm('fees_refunded') ?></dd>
      <dt>Fees outstanding</dt><dd><?= $mm('fees_pending') ?></dd>
      <dt>Interest income (cash basis)</dt><dd><?= $mm('interest_income') ?></dd>
    </dl>
  </div>
</section>
<?php endforeach ?>
<?php if (!$r['by_currency']): ?><div class="panel empty"><p>No activity in this period.</p></div><?php endif ?>

<?php foreach ($r['book'] as $cur => $b): ?>
<section class="panel">
  <h2><?= e($cur) ?>: loan book now</h2>
  <dl class="facts">
    <dt>Active loans</dt><dd><?= $b['loans'] ?? 0 ?></dd>
    <dt>Outstanding principal</dt><dd><?= e(money($b['outstanding_principal'] ?? 0, $cur)) ?></dd>
    <dt>Scheduled receivables (principal and interest)</dt><dd><?= e(money($b['receivable'] ?? 0, $cur)) ?></dd>
    <dt>Overdue</dt><dd><?= e(money($b['overdue'] ?? 0, $cur)) ?> across <?= $b['overdue_loans'] ?? 0 ?> loan(s)</dd>
  </dl>
</section>
<?php endforeach ?>

<div class="layout-2 even">
  <section class="panel">
    <h2>Products</h2>
    <div class="table-wrap"><table class="data compact"><thead><tr><th>Product</th><th>Applications</th><th>Requested</th></tr></thead>
      <tbody><?php foreach ($r['products'] as $p): ?><tr><td><?= e($p['name'] ?: 'No product') ?></td><td><?= (int) $p['n'] ?></td><td><?= e(money(((int) $p['requested']) * 100, $p['currency'])) ?></td></tr><?php endforeach ?>
      <?php if (!$r['products']): ?><tr><td colspan="3" class="muted">None.</td></tr><?php endif ?></tbody></table></div>
  </section>
  <section class="panel">
    <h2>Staff workload</h2>
    <div class="table-wrap"><table class="data compact"><thead><tr><th>Staff</th><th>Open assigned</th><th>Decisions proposed</th><th>Document reviews</th></tr></thead>
      <tbody><?php foreach ($r['staff'] as $s): ?><tr><td><?= e($s['full_name']) ?><span class="sub"><?= e(STAFF_ROLES[$s['role']] ?? $s['role']) ?></span></td><td><?= (int) $s['open_assigned'] ?></td><td><?= (int) $s['proposed'] ?></td><td><?= (int) $s['reviews'] ?></td></tr><?php endforeach ?></tbody></table></div>
  </section>
</div>
<section class="panel">
  <h2>Applications by month</h2>
  <?php $mx = max([1, ...array_values($r['months'])]); ?>
  <ul class="bar-list"><?php foreach ($r['months'] as $mo => $n): ?><li><span><?= e($mo) ?></span><span class="bar" style="--w: <?= (int) round(100 * $n / $mx) ?>%" aria-hidden="true"></span><strong><?= $n ?></strong></li><?php endforeach ?>
  <?php if (!$r['months']): ?><li class="muted">No applications in this period.</li><?php endif ?></ul>
</section>
