<?php $title = 'Audit log'; ?>
<div class="page-head"><div><h1>Audit log</h1><p>Append-only record of significant events. It never contains passwords, tokens, codes or document contents.</p></div>
  <?php if (can('export_data')): ?><a class="btn btn-outline" href="?<?= e(pager_args(['export' => 'csv'])) ?>">Export CSV</a><?php endif ?></div>
<p class="notice <?= $chain_ok ? 'success' : 'error' ?>"><?= $chain_ok ? "Integrity check passed: $checked entries, hash chain intact." : "Integrity check FAILED at entry $bad. The log may have been altered outside the application. Escalate immediately." ?></p>
<form class="filters" method="get">
  <div><label for="event">Event starts with</label><input id="event" name="event" value="<?= e($event) ?>" placeholder="e.g. auth. or application.status" maxlength="60"></div>
  <button class="btn btn-dark btn-sm" type="submit">Filter</button>
</form>
<div class="table-wrap">
  <table class="data">
    <thead><tr><th>#</th><th>When (UTC)</th><th>Who</th><th>Event</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($rows as $l): ?>
      <tr><td class="muted small"><?= (int) $l['id'] ?></td><td class="small"><?= e($l['created_at']) ?></td>
        <td><?= e($l['full_name'] ?: '—') ?><span class="sub"><?= e($l['actor_role']) ?></span></td>
        <td><code><?= e($l['event']) ?></code></td>
        <td class="small"><?php if ($l['target_type'] === 'application' && $l['target_id']): ?><a href="<?= e(url("/admin/applications/{$l['target_id']}")) ?>"><?= e(ref_str((int) $l['target_id'])) ?></a><?php else: ?><?= e($l['target_type']) ?> <?= e($l['target_id'] ?? '') ?><?php endif ?></td>
        <td class="small clip"><code><?= e($l['detail']) ?></code></td><td class="muted small"><?= e($l['ip']) ?></td></tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="muted">No entries.</td></tr><?php endif ?></tbody>
  </table>
</div>
<?= pager($page, $pages) ?>
