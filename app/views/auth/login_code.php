<?php $title = 'Two-step verification'; ?>
<div class="panel auth-card narrow-panel">
  <h1>Enter your code</h1>
  <p class="muted">Open your authenticator app and enter the 6-digit code. Lost your phone? Enter one of your recovery codes instead.</p>
  <?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <div>
      <label for="code">Code</label>
      <input id="code" name="code" autocomplete="one-time-code" inputmode="numeric" maxlength="20" required autofocus>
    </div>
    <button class="btn btn-primary" type="submit">Continue</button>
  </form>
  <p class="switch"><a href="<?= e(url('/account/login')) ?>">Start again</a></p>
</div>
