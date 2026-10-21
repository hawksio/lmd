<?php $title = 'Your offer · ' . ref_str((int) $app['id']); ?>
<a class="crumb" href="<?= e(url("/account/applications/{$app['id']}")) ?>"><?= e(ref_str((int) $app['id'])) ?></a>
<div class="page-head"><div><h1>Your offer</h1>
  <p><?= e(['full' => 'Fully approved', 'partial' => 'Partially approved', 'conditional' => 'Conditional approval'][$d['decision_type']]) ?> on <?= e(fmt_date($d['issued_at'])) ?>.</p></div></div>
<p class="notice"><strong>This is an offer, not a payment.</strong> No money has been paid. If you accept, we'll prepare a loan agreement for you to review; nothing is binding until that agreement is signed.</p>
<div class="layout-2">
  <section class="panel">
    <h2>The figures</h2>
    <?= breakdown_html($q) ?>
  </section>
  <div>
    <section class="panel">
      <h2>Conditions</h2>
      <?php if ($conditions): ?><ul class="plain-list"><?php foreach ($conditions as $cnd): ?><li><?= e($cnd['text']) ?> <span class="tag-sm"><?= e(ucfirst($cnd['status'])) ?></span></li><?php endforeach ?></ul>
      <?php else: ?><p class="muted">None.</p><?php endif ?>
    </section>
    <section class="panel">
      <h2>Your response</h2>
      <?php if ($accepted): ?>
        <p class="notice success">You accepted this offer on <?= e(fmt_date($accepted['accepted_at'])) ?>. Your agreement will follow.</p>
      <?php elseif (in_array($app['status'], APPROVED, true)): ?>
        <form method="post" class="form">
          <?= csrf_field() ?>
          <label class="check"><input type="checkbox" name="accept" value="1"> I have read the interest rate, fees, total amount repayable, repayment schedule and conditions, and I want to proceed to an agreement.</label>
          <div><button class="btn btn-primary" type="submit">Accept offer</button></div>
        </form>
        <p class="hint spaced">Questions? <a href="<?= e(url("/account/applications/{$app['id']}#messages")) ?>">Message the team</a> before accepting. You don't have to accept.</p>
      <?php else: ?><p class="muted">This offer can no longer be accepted.</p><?php endif ?>
    </section>
  </div>
</div>
