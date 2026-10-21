<?php $title = 'Staff overview'; $me = current_user(); ?>
<div class="page-head">
  <div><h1>Overview</h1><p><?= e(current_org()['name']) ?> · signed in as <?= e($me['full_name']) ?> (<?= e(STAFF_ROLES[$me['role']]) ?>).</p></div>
  <a class="btn btn-primary" href="<?= e(url('/admin/applications?attention=1')) ?>">Needs attention</a>
</div>
<?php if ($usage): ?><p class="notice"><?= e($usage) ?></p><?php endif ?>
<?php if ($stats['failed_mail'] && can('view_audit')): ?><p class="notice error"><a href="<?= e(url('/admin/emails?status=failed')) ?>"><?= $stats['failed_mail'] ?> email<?= $stats['failed_mail'] === 1 ? '' : 's' ?> failed to send.</a> Check the email settings.</p><?php endif ?>
<div class="tiles">
  <a class="tile" href="<?= e(url('/admin/applications?status=submitted')) ?>"><strong><?= $counts['submitted'] ?? 0 ?></strong><span>New, waiting for review</span></a>
  <a class="tile" href="<?= e(url('/admin/documents/')) ?>"><strong><?= $stats['docs_waiting'] ?></strong><span>Documents awaiting verification</span></a>
  <a class="tile" href="<?= e(url('/admin/approvals')) ?>"><strong><?= $stats['decisions_waiting'] ?></strong><span>Decisions waiting to be issued</span></a>
  <a class="tile" href="<?= e(url('/admin/disbursements?status=pending')) ?>"><strong><?= $stats['payments_waiting'] ?></strong><span>Payments awaiting confirmation</span></a>
</div>
<div class="layout-2">
  <section>
    <h2 class="section-title">Needs attention</h2>
    <?php if ($attention): ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Application</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody><?php foreach ($attention as $a): ?>
        <tr><td><a href="<?= e(url("/admin/applications/{$a['id']}")) ?>"><?= e($a['company_name'] ?: 'Untitled') ?></a><span class="sub"><?= e(ref_str((int) $a['id'])) ?> · <?= e($a['full_name']) ?></span></td><td><?= badge($a['status']) ?></td><td class="muted small"><?= e(fmt_date($a['updated_at'])) ?></td></tr>
      <?php endforeach ?></tbody></table></div>
    <?php else: ?><div class="panel empty"><p>Nothing waiting. New applications, customer messages, unreviewed documents, proposed decisions and unconfirmed payments appear here.</p></div><?php endif ?>
    <h2 class="section-title">Recent activity</h2>
    <?php if ($recent): ?>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Application</th><th>Requested</th><th>Status</th><th>Updated</th></tr></thead>
      <tbody><?php foreach ($recent as $a): ?>
        <tr><td><a href="<?= e(url("/admin/applications/{$a['id']}")) ?>"><?= e($a['company_name'] ?: 'Untitled') ?></a><span class="sub"><?= e(ref_str((int) $a['id'])) ?> · <?= e($a['full_name']) ?></span></td>
          <td><?= e(money(((int) $a['amount']) * 100, $a['currency'])) ?></td><td><?= badge($a['status']) ?></td><td class="muted small"><?= e(fmt_date($a['updated_at'])) ?></td></tr>
      <?php endforeach ?></tbody></table></div>
    <?php else: ?><div class="panel empty"><p>No submitted applications yet.</p></div><?php endif ?>
  </section>
  <section class="panel">
    <h2>Pipeline</h2>
    <dl class="facts">
      <?php foreach (STATUSES as $key => $label): if ($key === 'draft' || empty($counts[$key])) { continue; } ?>
        <dt><a href="<?= e(url('/admin/applications?status=' . $key)) ?>"><?= e($label) ?></a></dt><dd><?= $counts[$key] ?></dd>
      <?php endforeach ?>
      <dt>Customer drafts (private)</dt><dd><?= $counts['draft'] ?? 0 ?></dd>
      <dt><a href="<?= e(url('/admin/clients')) ?>">Registered customers</a></dt><dd><?= $stats['clients'] ?></dd>
      <dt><a href="<?= e(url('/admin/enquiries')) ?>">Open enquiries</a></dt><dd><?= $stats['open_enquiries'] ?></dd>
    </dl>
  </section>
</div>
