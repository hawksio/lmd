<?php $title = 'Email log'; ?>
<div class="page-head"><div><h1>Email log</h1><p>Delivery status of the last 200 messages. "Outbox" means nothing was sent: no SMTP server is configured, or the address was diverted on staging.</p></div></div>
<nav class="chips" aria-label="Filter by status">
  <?php foreach (['', 'sent', 'outbox', 'failed', 'queued', 'captured'] as $s): ?><a class="chip" href="<?= e(url('/admin/emails' . ($s ? "?status=$s" : ''))) ?>"<?= $status === $s ? ' aria-current="true"' : '' ?>><?= $s ? ucfirst($s) : 'All' ?></a><?php endforeach ?>
</nav>
<div class="table-wrap">
  <table class="data">
    <thead><tr><th>When (UTC)</th><th>Type</th><th>To</th><th>Subject</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($rows as $mrow): ?>
      <tr><td class="small"><?= e($mrow['created_at']) ?></td><td class="small"><?= e($mrow['category']) ?><span class="sub"><?= e($mrow['template']) ?></span></td><td class="small"><?= e($mrow['recipient']) ?></td><td><?= e($mrow['subject']) ?></td>
        <td><span class="tag-sm tag-<?= $mrow['status'] === 'sent' ? 'verified' : ($mrow['status'] === 'failed' ? 'rejected' : 'submitted') ?>"><?= e($mrow['status']) ?></span><?= $mrow['error'] ? '<span class="sub">' . e($mrow['error']) . '</span>' : '' ?></td></tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="muted">No emails yet.</td></tr><?php endif ?></tbody>
  </table>
</div>
