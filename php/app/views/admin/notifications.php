<?php $title = 'Notifications'; ?>
<div class="page-head"><div><h1>Notifications</h1><p>Work routed to you by your role and assignments.</p></div>
  <form method="post"><?= csrf_field() ?><button class="btn btn-outline btn-sm" type="submit">Mark all as read</button></form></div>
<section class="panel">
  <ul class="note-list">
    <?php foreach ($rows as $n): ?><li class="<?= $n['read_at'] ? '' : 'unread' ?>"><?php if ($n['link']): ?><a href="<?= e(url($n['link'])) ?>"><?= e($n['message']) ?></a><?php else: ?><?= e($n['message']) ?><?php endif ?><small><?= e(fmt_date($n['created_at'])) ?></small></li><?php endforeach ?>
    <?php if (!$rows): ?><li class="muted">Nothing yet.</li><?php endif ?>
  </ul>
</section>
