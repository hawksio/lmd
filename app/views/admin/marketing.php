<?php $title = 'Marketing'; ?>
<div class="page-head"><div><h1>Marketing email</h1><p>Only people who opted in, confirmed their email and haven't unsubscribed can be emailed.</p></div>
  <div class="inline-actions"><a class="btn btn-outline" href="<?= e(url('/admin/marketing/suppressions')) ?>">Suppression list (<?= $suppressed ?>)</a><a class="btn btn-primary" href="<?= e(url('/admin/marketing/new')) ?>">New campaign</a></div></div>
<div class="layout-2">
  <section>
    <?php if ($campaigns): ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Campaign</th><th>Audience</th><th>Status</th><th>Sent</th></tr></thead>
      <tbody><?php foreach ($campaigns as $c): ?>
        <tr><td><a href="<?= e(url("/admin/marketing/{$c['id']}")) ?>"><?= e($c['name']) ?></a><span class="sub"><?= e($c['subject']) ?></span></td><td class="small"><?= e(SEGMENTS[$c['segment']] ?? $c['segment']) ?></td>
          <td><?= e($c['status']) ?><?= ($c['scheduled_for'] && $c['status'] === 'scheduled') ? '<span class="sub">' . e(fmt_date($c['scheduled_for'])) . ' UTC</span>' : '' ?></td><td><?= (int) $c['sent_n'] ?></td></tr>
      <?php endforeach ?></tbody></table></div>
    <?php else: ?><div class="panel empty"><p>No campaigns yet.</p></div><?php endif ?>
  </section>
  <div>
    <section class="panel">
      <h2>Before going live</h2>
      <ul class="checklist"><?php foreach ($checks as [$label, $ok]): ?><li class="<?= $ok ? 'ok' : 'todo' ?>"><?= $ok ? '✓' : '○' ?> <?= e($label) ?></li><?php endforeach ?></ul>
    </section>
    <section class="panel">
      <h2>Audiences</h2>
      <dl class="facts"><?php foreach (SEGMENTS as $k => $label): ?><dt><?= e($label) ?></dt><dd><?= $counts[$k] ?></dd><?php endforeach ?></dl>
    </section>
  </div>
</div>
