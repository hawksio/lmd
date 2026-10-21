<?php $title = 'Platform overview'; ?>
<div class="page-head"><div><h1>Platform overview</h1><p>Organisation-level figures only. Customer data stays inside each organisation.</p></div>
  <a class="btn btn-primary" href="<?= e(url('/platform/organisations/new')) ?>">New organisation</a></div>
<div class="tiles">
  <a class="tile" href="<?= e(url('/platform/organisations')) ?>"><strong><?= (int) ($orgs['active'] ?? 0) ?></strong><span>Active organisations<?= !empty($orgs['suspended']) ? ' (' . (int) $orgs['suspended'] . ' suspended)' : '' ?></span></a>
  <div class="tile"><strong><?= (int) ($subs['active'] ?? 0) ?></strong><span>Active subscriptions</span></div>
  <div class="tile"><strong><?= (int) ($subs['trial'] ?? 0) ?></strong><span>On trial</span></div>
  <div class="tile"><strong><?= (int) ($subs['past_due'] ?? 0) + (int) ($subs['cancelled'] ?? 0) ?></strong><span>Past due or cancelled</span></div>
</div>
<div class="layout-2 even">
  <section class="panel">
    <h2>Subscription revenue (paid invoices)</h2>
    <form class="filters" method="get"><?= daterange($start, $end) ?><button class="btn btn-dark btn-sm" type="submit">Update</button></form>
    <dl class="facts"><?php foreach ($revenue as $r): ?><dt><?= e($r['currency']) ?> · <?= (int) $r['n'] ?> invoice(s)</dt><dd><?= e(money($r['total'], $r['currency'])) ?></dd><?php endforeach ?>
      <?php if (!$revenue): ?><dt>Paid in period</dt><dd>None</dd><?php endif ?>
      <?php foreach ($outstanding as $r): ?><dt><?= e($r['currency']) ?> outstanding · <?= (int) $r['n'] ?></dt><dd><?= e(money($r['total'], $r['currency'])) ?></dd><?php endforeach ?></dl>
    <p class="hint">Manual billing: revenue counts only invoices recorded as paid with a payment reference. Platform revenue is separate from each organisation's lending revenue.</p>
  </section>
  <section class="panel">
    <h2>Service health</h2>
    <dl class="facts">
      <dt>Database check</dt><dd><?= e($health['database']) ?></dd>
      <dt>Schema version</dt><dd><?= e($health['migrations']) ?></dd>
      <dt>PHP version</dt><dd><?= e($health['php']) ?></dd>
      <dt>Failed emails (24 h)</dt><dd><?= $health['failed_emails_24h'] ?></dd>
      <dt>Last backup</dt><dd><?= e($health['last_backup'] ?? 'None found in lmd-storage/backups') ?></dd>
    </dl>
  </section>
</div>
<section class="panel">
  <h2>Organisation activity</h2>
  <div class="table-wrap"><table class="data"><thead><tr><th>Organisation</th><th>Status</th><th>Applications (30 days)</th><th>Last activity</th></tr></thead>
    <tbody><?php foreach ($activity as $o): ?><tr><td><a href="<?= e(url("/platform/organisations/{$o['id']}")) ?>"><?= e($o['name']) ?></a></td><td><?= e($o['status']) ?></td><td><?= (int) $o['apps_30d'] ?></td><td class="small muted"><?= e(fmt_date($o['last_activity'])) ?></td></tr><?php endforeach ?></tbody></table></div>
</section>
