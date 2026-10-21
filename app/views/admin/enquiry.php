<?php $title = 'Enquiry from ' . $e['name']; ?>
<a class="crumb" href="<?= e(url('/admin/enquiries')) ?>">Enquiries</a>
<div class="page-head"><div><h1><?= e($e['name']) ?></h1><p><?= e($e['company']) ?> · <?= e(TOPICS[$e['topic']] ?? $e['topic']) ?> · received <?= e(fmt_date($e['created_at'])) ?><?= $e['handled'] ? ' · handled' : '' ?></p></div></div>
<div class="layout-2">
  <div>
    <section class="panel">
      <h2>Message</h2>
      <p class="pre"><?= e($e['message'] ?: '(No message)') ?></p>
    </section>
    <section class="panel">
      <h2>Replies</h2>
      <div class="thread">
        <?php foreach ($replies as $r): ?><article class="msg mine"><header><strong><?= e($r['full_name']) ?></strong><span><?= e(fmt_date($r['created_at'])) ?></span></header><p><?= e($r['body']) ?></p></article><?php endforeach ?>
        <?php if (!$replies): ?><p class="muted">No replies yet.</p><?php endif ?>
      </div>
      <?php if (can('handle_enquiries')): ?>
      <form method="post" action="<?= e(url("/admin/enquiries/{$e['id']}")) ?>" class="form" data-once>
        <?= csrf_field() ?>
        <div><label for="body">Reply by email to <?= e($e['email']) ?></label>
          <textarea id="body" name="body" rows="8" maxlength="6000" required><?= e($draft) ?></textarea>
          <?php if ($draft): ?><p class="hint warn">AI-generated draft. Check every sentence and remove anything inaccurate before sending.</p><?php endif ?></div>
        <div class="inline-actions"><button class="btn btn-primary btn-sm" type="submit">Send reply</button></div>
      </form>
      <?php if ($ai_drafts): ?>
      <form method="post" action="<?= e(url("/admin/enquiries/{$e['id']}/ai-draft")) ?>" class="spaced"><?= csrf_field() ?><button class="btn btn-outline btn-sm" type="submit">Draft a reply with AI</button>
        <span class="hint">Sends the message text (emails and numbers removed) to the AI provider.</span></form>
      <?php endif ?>
      <?php endif ?>
    </section>
  </div>
  <section class="panel">
    <h2>Contact</h2>
    <dl class="facts"><dt>Email</dt><dd><a href="mailto:<?= e($e['email']) ?>"><?= e($e['email']) ?></a></dd><dt>Phone</dt><dd><?= e($e['phone'] ?: 'Not given') ?></dd><dt>Business</dt><dd><?= e($e['company']) ?></dd></dl>
  </section>
</div>
