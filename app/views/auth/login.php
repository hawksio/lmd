<?php $title = 'Sign in'; ?>
<div class="auth">
  <div class="auth-card panel">
    <h1>Sign in<?= on_platform() ? ' to the platform console' : '' ?></h1>
    <?php if (!on_platform()): ?><p class="muted">Track your application, upload documents and message the team.</p><?php endif ?>
    <?php if ($error): ?><p class="notice error" role="alert"><?= e($error) ?></p><?php endif ?>
    <form method="post" class="form" novalidate>
      <?= csrf_field() ?>
      <div>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" value="<?= e($email) ?>" maxlength="200" required autofocus>
      </div>
      <div>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      <button class="btn btn-primary" type="submit">Sign in</button>
    </form>
    <?php if (!on_platform()): ?>
    <p class="switch">New to <?= e(current_org()['name']) ?>? <a href="<?= e(url('/account/register')) ?>">Create an account</a> to start an application.</p>
    <?php endif ?>
    <p class="switch"><a href="<?= e(url('/account/forgot')) ?>">Forgotten your password?</a></p>
  </div>
  <picture class="pic" style="--ar:4/5">
    <source type="image/avif" srcset="/images/support/support-rep-sm.avif">
    <source type="image/webp" srcset="/images/support/support-rep-sm.webp">
    <img src="/images/support/support-rep-md.jpg" width="640" height="800" alt="" loading="lazy">
  </picture>
</div>
