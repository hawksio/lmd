<?php $title = 'Create an account'; ?>
<div class="auth">
  <div class="auth-card panel">
    <h1>Create your account</h1>
    <p class="muted">It takes a minute. We'll email you a link to confirm your address, then you can complete your application at your own pace.</p>
    <?php if (isset($errors['form'])): ?><p class="notice error" role="alert"><?= e($errors['form']) ?></p><?php endif ?>
    <form method="post" class="form" novalidate>
      <?= csrf_field() ?>
      <?= field_input('full_name', 'Full name', $f, $errors, ['required' => true, 'autocomplete' => 'name', 'maxlength' => 120]) ?>
      <?= field_input('email', 'Email', $f, $errors, ['type' => 'email', 'required' => true, 'autocomplete' => 'email', 'maxlength' => 200]) ?>
      <?= field_input('phone', 'Phone (optional)', $f, $errors, ['type' => 'tel', 'autocomplete' => 'tel', 'maxlength' => 40]) ?>
      <div>
        <label for="password">Password <span class="muted">*</span></label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="10" maxlength="200" required
               aria-describedby="password-hint<?= isset($errors['password']) ? ' password-error' : '' ?>"<?= isset($errors['password']) ? ' aria-invalid="true"' : '' ?>>
        <p class="hint" id="password-hint">At least 10 characters. A short phrase of unrelated words works well.</p>
        <?= field_error($errors, 'password') ?>
      </div>
      <div>
        <label for="confirm">Confirm password <span class="muted">*</span></label>
        <input id="confirm" name="confirm" type="password" autocomplete="new-password" required<?= isset($errors['confirm']) ? ' aria-invalid="true" aria-describedby="confirm-error"' : '' ?>>
        <?= field_error($errors, 'confirm') ?>
      </div>
      <div>
        <label class="check"><input type="checkbox" name="terms" value="1"> I have read the <a href="/privacy.html" target="_blank" rel="noopener">privacy notice</a>, which explains how <?= e(site()->show('legal_name')) ?> uses my details to run my account and assess applications.</label>
        <?= field_error($errors, 'terms') ?>
      </div>
      <div>
        <label class="check"><input type="checkbox" name="marketing" value="1"> Optional: send me occasional emails about business finance services. I can unsubscribe at any time.</label>
      </div>
      <button class="btn btn-primary" type="submit">Create account</button>
    </form>
    <p class="switch">Already registered? <a href="<?= e(url('/account/login')) ?>">Sign in</a></p>
  </div>
  <picture class="pic" style="--ar:4/3">
    <source type="image/avif" srcset="/images/consultations/one-to-one-md.avif">
    <source type="image/webp" srcset="/images/consultations/one-to-one-md.webp">
    <img src="/images/consultations/one-to-one-md.jpg" width="960" height="720" alt="" loading="lazy">
  </picture>
</div>
