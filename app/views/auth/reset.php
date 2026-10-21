<?php $title = 'Choose a new password'; ?>
<div class="panel auth-card narrow-panel">
  <?php if ($invalid): ?>
    <h1>This link has expired</h1>
    <p class="muted">Password links work once and expire. Please request a new one.</p>
    <p><a class="btn btn-primary" href="<?= e(url('/account/forgot')) ?>">Send a new link</a></p>
  <?php else: ?>
    <h1>Choose a new password</h1>
    <p class="muted">For <?= e($email) ?>. Saving it signs you out on every other device.</p>
    <form method="post" class="form" novalidate>
      <?= csrf_field() ?>
      <div>
        <label for="password">New password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="200" required autofocus
               aria-describedby="password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
        <p class="hint" id="password-hint">At least 10 characters.</p>
        <?= field_error($errors, 'password') ?>
      </div>
      <div>
        <label for="confirm">Confirm new password</label>
        <input id="confirm" name="confirm" type="password" autocomplete="new-password" required<?= isset($errors['confirm']) ? ' aria-invalid="true" aria-describedby="confirm-error"' : '' ?>>
        <?= field_error($errors, 'confirm') ?>
      </div>
      <button class="btn btn-primary" type="submit">Save new password</button>
    </form>
  <?php endif ?>
</div>
