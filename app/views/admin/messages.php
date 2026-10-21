<?php $title = 'Messages'; ?>
<div class="page-head"><div><h1>Messages</h1><p>Latest customer-visible message on each application. Conversations waiting for your reply come first.</p></div></div>
<?php if ($rows): ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Application</th><th>Latest message</th><th>When</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?>
    <tr><td><a href="<?= e(url("/admin/applications/{$r['id']}#notes")) ?>"><?= e($r['company_name'] ?: 'Untitled') ?></a><span class="sub"><?= e(ref_str((int) $r['id'])) ?> · <?= e($r['full_name']) ?></span></td>
      <td class="clip"><?= e(mb_substr($r['body'], 0, 160)) ?><?= mb_strlen($r['body']) > 160 ? '…' : '' ?></td><td class="muted small"><?= e(fmt_date($r['created_at'])) ?></td>
      <td><?= $r['last_role'] === 'client' ? '<span class="tag-sm tag-submitted">Awaiting reply</span>' : '<span class="tag-sm">Replied</span>' ?></td></tr>
  <?php endforeach ?></tbody></table></div>
<?php else: ?><div class="panel empty"><p>No messages yet.</p></div><?php endif ?>
