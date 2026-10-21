<?php $title = 'Customers'; ?>
<div class="page-head"><div><h1>Customers</h1><p><?= $total ?> registered<?= $search !== '' ? ' matching “' . e($search) . '”' : '' ?>.</p></div>
  <?php if (can('export_data')): ?><a class="btn btn-outline" href="?<?= e(pager_args(['export' => 'csv'])) ?>">Export CSV</a><?php endif ?></div>
<form class="filters" method="get" role="search">
  <div><label for="q">Search</label><input id="q" name="q" type="search" value="<?= e($search) ?>" placeholder="Name or email" maxlength="100"></div>
  <button class="btn btn-dark btn-sm" type="submit">Search</button>
</form>
<?php if ($clients): ?>
<div class="table-wrap">
  <table class="data">
    <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Submitted applications</th><th>Registered</th><th>Security</th></tr></thead>
    <tbody>
      <?php foreach ($clients as $c): ?>
      <tr>
        <td><strong><?= e($c['full_name']) ?></strong><?= !$c['is_active'] ? '<span class="sub">Deactivated</span>' : '' ?></td>
        <td><a href="<?= e(url('/admin/applications?q=' . rawurlencode($c['email']))) ?>"><?= e($c['email']) ?></a><?= !$c['email_verified_at'] ? '<span class="sub">Email not confirmed</span>' : '' ?></td>
        <td><?= e($c['phone']) ?></td>
        <td><?= (int) $c['apps'] ?></td>
        <td class="muted small"><?= e(fmt_date($c['created_at'])) ?></td>
        <td class="small"><?= $c['totp_enabled_at'] ? 'Two-step on' : 'Password only' ?></td>
      </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
<?= pager($page, $pages) ?>
<?php else: ?><div class="panel empty"><p>No customers found.</p></div><?php endif ?>
