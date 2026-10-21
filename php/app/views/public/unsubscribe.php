<?php $title = 'Unsubscribe'; ?>
<div class="panel auth-card narrow-panel">
  <?php if ($done): ?>
    <h1>You're unsubscribed</h1>
    <p class="notice success" role="status"><?= e($email) ?> won't receive marketing emails from us again. We'll still send emails about your account and any applications.</p>
  <?php else: ?>
    <h1>Unsubscribe from marketing</h1>
    <p>Stop marketing emails to <strong><?= e($email) ?></strong>?</p>
    <form method="post" action="<?= e(url('/marketing/unsubscribe/' . $token)) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Unsubscribe</button></form>
  <?php endif ?>
</div>
