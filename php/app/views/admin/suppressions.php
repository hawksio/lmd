<?php $title = 'Suppression list'; ?>
<a class="crumb" href="<?= e(url('/admin/marketing')) ?>">Marketing</a>
<div class="page-head"><div><h1>Suppression list</h1><p>These addresses never receive marketing email. Unsubscribes are added automatically.</p></div></div>
<div class="layout-2">
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Email</th><th>Reason</th><th>Added</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr><td><?= e($r['email']) ?></td><td><?= e($r['reason']) ?></td><td class="small muted"><?= e(fmt_date($r['created_at'])) ?></td></tr><?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="3" class="muted">Empty.</td></tr><?php endif ?></tbody>
  </table></div>
  <section class="panel">
    <h2>Add an address</h2>
    <p class="muted small">For bounces and complaints reported by your email provider.</p>
    <form method="post" class="form">
      <?= csrf_field() ?>
      <div><label for="s_email">Email</label><input id="s_email" name="email" type="email" maxlength="200" required></div>
      <div><label for="s_reason">Reason</label><select id="s_reason" name="reason"><?= options_html(['bounced' => 'Bounced', 'complaint' => 'Complaint', 'manual' => 'Other']) ?></select></div>
      <div><button class="btn btn-primary btn-sm" type="submit">Add</button></div>
    </form>
  </section>
</div>
