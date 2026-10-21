<?php $title = 'Organisations'; ?>
<div class="page-head"><div><h1>Organisations</h1><p>Subscribed finance organisations and their usage.</p></div>
  <a class="btn btn-primary" href="<?= e(url('/platform/organisations/new')) ?>">New organisation</a></div>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Organisation</th><th>Plan</th><th>Subscription</th><th>Staff</th><th>Customers</th><th>Applications this month</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($rows as $o): ?>
    <tr><td><a href="<?= e(url("/platform/organisations/{$o['id']}")) ?>"><?= e($o['name']) ?></a><span class="sub"><?= e($o['slug']) ?></span></td>
      <td><?= e($o['plan_name'] ?? 'None') ?></td><td><?= e(SUB_STATUSES[$o['sub_status'] ?? ''] ?? 'No subscription') ?></td>
      <td><?= $o['usage']['staff'] ?></td><td><?= $o['usage']['customers'] ?></td><td><?= $o['usage']['apps_month'] ?></td>
      <td><span class="tag-sm tag-<?= $o['status'] === 'active' ? 'verified' : 'rejected' ?>"><?= e(ucfirst($o['status'])) ?></span></td></tr>
  <?php endforeach ?></tbody></table></div>
