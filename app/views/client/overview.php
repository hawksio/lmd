<?php $title = 'Overview'; ?>
<div class="page-head">
  <div><h1>Hello, <?= e(first_name(current_user()['full_name'])) ?></h1><p>Here's where things stand.</p></div>
  <a class="btn btn-primary" href="<?= e(url('/account/applications/new')) ?>">Start a new application</a>
</div>

<?php if ($progress['done'] < $progress['total']): ?>
<section class="panel onboarding" aria-labelledby="ob-h">
  <h2 id="ob-h">Your next steps</h2>
  <div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $progress['total'] ?>" aria-valuenow="<?= $progress['done'] ?>" aria-label="<?= $progress['done'] ?> of <?= $progress['total'] ?> steps done">
    <span style="--w: <?= $progress['percent'] ?>%"></span></div>
  <ol class="step-list">
    <?php foreach ($progress['steps'] as $i => [$label, $ok, $link]): ?>
      <li class="<?= $ok ? 'done' : '' ?>"><span class="tick" aria-hidden="true"><?= $ok ? '✓' : $i + 1 ?></span>
        <?php if (!$ok && $link): ?><a href="<?= e(url($link)) ?>"><?= e($label) ?></a><?php else: ?><?= e($label) ?><?php endif ?><?= $ok ? '<span class="sr"> (done)</span>' : '' ?></li>
    <?php endforeach ?>
  </ol>
</section>
<?php endif ?>

<?php foreach ($funded as [$a, $s]): ?>
<section class="panel">
  <h2><?= e($a['company_name']) ?> · <?= e(ref_str((int) $a['id'])) ?></h2>
  <?= financing_html($s) ?>
  <p class="small"><a href="<?= e(url("/account/applications/{$a['id']}#financing")) ?>">View details</a><?php if (in_array($a['status'], APPROVED, true)): ?> · <a href="<?= e(url("/account/applications/{$a['id']}/offer")) ?>">Review your offer</a><?php endif ?></p>
</section>
<?php endforeach ?>

<div class="layout-2">
  <section>
    <h2 class="section-title">Your applications</h2>
    <?php if ($apps): ?>
    <div class="app-cards">
      <?php foreach (array_slice($apps, 0, 4) as $a): ?>
      <a class="app-card" href="<?= e(url($a['status'] === 'draft' ? "/account/applications/{$a['id']}/edit" : "/account/applications/{$a['id']}")) ?>">
        <h3><?= e($a['company_name'] ?: 'Untitled application') ?></h3>
        <?= badge($a['status']) ?>
        <span class="meta"><?= e(ref_str((int) $a['id'])) ?><?= $a['amount'] ? ' · ' . e(money($a['amount'] * 100, $a['currency'])) . ' requested' : '' ?> · Updated <?= e(fmt_date($a['updated_at'])) ?>
          <?php if ($a['status'] === 'draft'): ?> · <strong>Continue your draft</strong><?php elseif (in_array($a['status'], ['documents_required', 'info_requested'], true)): ?> · <strong>Action needed</strong><?php elseif ($a['status'] === 'agreement_issued'): ?> · <strong>Your agreement is ready to review</strong><?php endif ?></span>
      </a>
      <?php endforeach ?>
    </div>
    <?php else: ?>
    <div class="panel empty">
      <h3>No applications yet</h3>
      <p>Tell us about your business and what you need. You can save a draft and come back any time.</p>
      <a class="btn btn-primary" href="<?= e(url('/account/applications/new')) ?>">Start your application</a>
    </div>
    <?php endif ?>
  </section>
  <section class="panel">
    <h2>Latest updates</h2>
    <ul class="note-list">
      <?php foreach ($notifications as $n): ?>
        <li class="<?= $n['read_at'] ? '' : 'unread' ?>"><?php if ($n['link']): ?><a href="<?= e(url($n['link'])) ?>"><?= e($n['message']) ?></a><?php else: ?><?= e($n['message']) ?><?php endif ?><small><?= e(fmt_date($n['created_at'])) ?></small></li>
      <?php endforeach ?>
      <?php if (!$notifications): ?><li class="muted">Nothing new yet.</li><?php endif ?>
    </ul>
    <p class="small"><a href="<?= e(url('/account/messages')) ?>">All messages and updates</a> · <?= $doc_count ?> document<?= $doc_count === 1 ? '' : 's' ?> uploaded</p>
  </section>
</div>
