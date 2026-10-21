<?php $title = 'Reset your password'; ?>
<div class="panel auth-card narrow-panel">
  <h1>Reset your password</h1>
  <?php if ($sent): ?>
    <p class="notice success" role="status">If an account exists for <strong><?= e($email) ?></strong>, we've emailed a link to reset the password. It expires in 1 hour.</p>
    <p class="muted">No email after a few minutes? Check your spam folder<?= owns_static_site() ? ', or contact us using the details on our <a href="/contact.html">contact page</a>' : '' ?>.</p>
    <p><a href="<?= e(url('/account/login')) ?>">Back to sign in</a></p>
  <?php else: ?>
    <p class="muted">Enter the email you registered with and we'll send you a link to choose a new password.</p>
    <form method="post" class="form" novalidate>
      <?= csrf_field() ?>
      <div>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email" maxlength="200" required autofocus>
      </div>
      <button class="btn btn-primary" type="submit">Send reset link</button>
    </form>
    <p class="switch"><a href="<?= e(url('/account/login')) ?>">Back to sign in</a></p>
  <?php endif ?>
</div>
