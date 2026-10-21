<?php $title = 'Repayments'; ?>
<div class="page-head"><div><h1>Repayments</h1><p>Active loans, calculated from the repayment schedules and confirmed payments.</p></div></div>
<?php if ($rows): ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Loan</th><th>Outstanding principal</th><th>Repaid</th><th>Next due</th><th>Overdue</th></tr></thead>
  <tbody><?php foreach ($rows as $r): $p = $r['pos']; $cur = $r['currency']; ?>
    <tr><td><a href="<?= e(url("/admin/applications/{$r['application_id']}#repayments")) ?>"><?= e($r['company_name']) ?></a><span class="sub"><?= e(ref_str((int) $r['application_id'])) ?> · <?= e($r['full_name']) ?></span></td>
      <td><?= e(money($p['outstanding_principal'], $cur)) ?></td><td><?= e(money($p['repaid'], $cur)) ?></td>
      <td class="small"><?= $p['next_due'] ? e(money($p['next_due']['unpaid_minor'], $cur)) . ' on ' . e($p['next_due']['due_date']) : 'Fully repaid' ?></td>
      <td><?= $p['overdue_count'] ? '<span class="tag-sm tag-rejected">' . $p['overdue_count'] . ' · ' . e(money($p['overdue_minor'], $cur)) . '</span>' : '–' ?></td></tr>
  <?php endforeach ?></tbody></table></div>
<?php else: ?><div class="panel empty"><p>No active repayment schedules.</p></div><?php endif ?>
<section class="panel spaced">
  <h2>Latest payments received</h2>
  <ul class="doc-list"><?php foreach ($recent as $r): ?><li><span><a href="<?= e(url("/admin/applications/{$r['application_id']}#repayments")) ?>"><?= e($r['company_name']) ?></a><small class="block"><?= e($r['received_on']) ?> · ref <?= e($r['provider_reference']) ?></small></span><span><?= e(money($r['amount_minor'], $r['currency'])) ?> <span class="tag-sm tag-<?= $r['status'] === 'confirmed' ? 'verified' : 'rejected' ?>"><?= e($r['status']) ?></span></span></li><?php endforeach ?>
  <?php if (!$recent): ?><li class="muted">None yet.</li><?php endif ?></ul>
</section>
