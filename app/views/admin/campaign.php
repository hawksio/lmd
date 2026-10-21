<?php $title = $c['name']; ?>
<a class="crumb" href="<?= e(url('/admin/marketing')) ?>">Marketing</a>
<div class="page-head"><div><h1><?= e($c['name']) ?></h1><p><?= e($c['subject']) ?> · <?= e(SEGMENTS[$c['segment']] ?? $c['segment']) ?> · status: <?= e($c['status']) ?></p></div>
  <?php if ($c['status'] === 'draft'): ?><a class="btn btn-outline" href="<?= e(url("/admin/marketing/{$c['id']}/edit")) ?>">Edit</a><?php endif ?></div>
<div class="layout-2">
  <section class="panel">
    <h2>Preview</h2>
    <iframe class="email-preview" src="<?= e(url("/admin/marketing/{$c['id']}/preview")) ?>" title="Email preview" sandbox></iframe>
  </section>
  <div>
    <section class="panel">
      <h2>Test send</h2>
      <p class="muted small">Sends a copy marked [TEST] to you only (<?= e(current_user()['email']) ?>).</p>
      <form method="post" action="<?= e(url("/admin/marketing/{$c['id']}/test")) ?>"><?= csrf_field() ?><button class="btn btn-outline btn-sm" type="submit">Send test to me</button></form>
    </section>
    <?php if ($c['status'] === 'draft'): ?>
    <section class="panel">
      <h2>Schedule</h2>
      <p class="muted small">Current audience: <?= $audience ?> people. Eligibility is checked again at sending time.</p>
      <ul class="checklist"><?php foreach ($checks as [$label, $ok]): ?><li class="<?= $ok ? 'ok' : 'todo' ?>"><?= $ok ? '✓' : '○' ?> <?= e($label) ?></li><?php endforeach ?></ul>
      <form method="post" action="<?= e(url("/admin/marketing/{$c['id']}/launch")) ?>" class="form">
        <?= csrf_field() ?>
        <div><label for="scheduled_for">Send at (UTC, empty = next run)</label><input id="scheduled_for" name="scheduled_for" type="datetime-local"></div>
        <label class="check"><input type="checkbox" name="confirm" value="1"> I confirm this campaign has been approved and the audience consented to marketing</label>
        <div><button class="btn btn-primary btn-sm" type="submit">Schedule campaign</button></div>
      </form>
    </section>
    <?php endif ?>
    <?php if (in_array($c['status'], ['draft', 'scheduled'], true)): ?>
    <form method="post" action="<?= e(url("/admin/marketing/{$c['id']}/cancel")) ?>" data-confirm="Cancel this campaign?"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Cancel campaign</button></form>
    <?php endif ?>
    <?php if ($stats): ?>
    <section class="panel">
      <h2>Delivery</h2>
      <dl class="facts"><?php foreach ($stats as $k => $n): ?><dt><?= e(ucfirst($k)) ?></dt><dd><?= $n ?></dd><?php endforeach ?></dl>
    </section>
    <?php endif ?>
  </div>
</div>
