<?php $title = 'Messages'; $me = (int) current_user()['id']; ?>
<div class="page-head"><div><h1>Messages and updates</h1><p>Messages are kept with each application. Reply from the application's page.</p></div></div>
<div class="layout-2">
  <section class="panel">
    <h2>Messages</h2>
    <div class="thread">
      <?php foreach ($notes as $n): ?>
        <article class="msg <?= (int) $n['author_id'] === $me ? 'mine' : '' ?>">
          <header><strong><?= (int) $n['author_id'] === $me ? 'You' : e(current_org()['name']) ?> · <a href="<?= e(url("/account/applications/{$n['application_id']}#messages")) ?>"><?= e(ref_str((int) $n['application_id'])) ?></a></strong><span><?= e(fmt_date($n['created_at'])) ?></span></header>
          <p><?= e($n['body']) ?></p>
        </article>
      <?php endforeach ?>
      <?php if (!$notes): ?><p class="muted">No messages yet.</p><?php endif ?>
    </div>
  </section>
  <section class="panel">
    <h2>Notifications</h2>
    <ul class="note-list">
      <?php foreach ($notifications as $n): ?>
        <li class="<?= $n['read_at'] ? '' : 'unread' ?>"><?php if ($n['link']): ?><a href="<?= e(url($n['link'])) ?>"><?= e($n['message']) ?></a><?php else: ?><?= e($n['message']) ?><?php endif ?><small><?= e(fmt_date($n['created_at'])) ?></small></li>
      <?php endforeach ?>
      <?php if (!$notifications): ?><li class="muted">Nothing yet.</li><?php endif ?>
    </ul>
  </section>
</div>
