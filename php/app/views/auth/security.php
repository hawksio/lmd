<?php
$title = 'Account and security';
$isClient = !is_staff($user) && !is_platform_owner($user);
?>
<div class="page-head"><div><h1>Account and security</h1><p>Your password, two-step verification, signed-in devices<?= $isClient ? ' and email preferences' : '' ?>.</p></div>
  <?php if ($isClient): ?><a class="btn btn-outline" href="<?= e(url('/account/profile')) ?>">Edit my details</a><?php endif ?>
</div>
<?php if ($must_setup && !$user['totp_enabled_at']): ?><p class="notice error" role="alert">Staff accounts must turn on two-step verification before using the staff area.</p><?php endif ?>

<div class="layout-2">
  <div>
    <section class="panel" id="mfa">
      <h2>Two-step verification</h2>
      <?php if ($recovery_codes): ?>
        <div class="notice success">
          <p><strong>Save these recovery codes now.</strong> Each works once if you lose your phone. They won't be shown again.</p>
          <ul class="codes"><?php foreach ($recovery_codes as $c): ?><li><code><?= e($c) ?></code></li><?php endforeach ?></ul>
        </div>
      <?php endif ?>
      <?php if ($user['totp_enabled_at']): ?>
        <p><span class="badge badge-fully_approved">On</span> since <?= e(fmt_date($user['totp_enabled_at'])) ?>. <?= $recovery_left ?> recovery code<?= $recovery_left === 1 ? '' : 's' ?> left.</p>
        <details class="inline-details"><summary>Turn off two-step verification</summary>
          <form method="post" action="<?= e(url('/account/security/mfa/disable')) ?>" class="form">
            <?= csrf_field() ?>
            <div><label for="mfa-password">Password</label><input id="mfa-password" name="password" type="password" autocomplete="current-password" required></div>
            <div><label for="mfa-code">Current code from your app</label><input id="mfa-code" name="code" inputmode="numeric" autocomplete="one-time-code" required></div>
            <div><button class="btn btn-danger btn-sm" type="submit">Turn off</button></div>
          </form>
        </details>
      <?php elseif ($setup_secret): ?>
        <ol class="steps-list">
          <li>Open an authenticator app (for example Microsoft Authenticator, Google Authenticator or 1Password).</li>
          <li>Scan this code, or enter the key by hand.
            <div class="qr"><?= $qr_svg ?></div>
            <p class="small">Key: <code class="key"><?= e($setup_secret) ?></code></p></li>
          <li>Enter the 6-digit code the app shows.</li>
        </ol>
        <form method="post" action="<?= e(url('/account/security/mfa/confirm')) ?>" class="form inline-form">
          <?= csrf_field() ?>
          <div><label for="setup-code">Code</label><input id="setup-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" required></div>
          <div><button class="btn btn-primary btn-sm" type="submit">Turn on</button></div>
        </form>
      <?php else: ?>
        <p>Add a second step to signing in: a code from an authenticator app on your phone. Strongly recommended<?= $isClient ? '' : ', and required for staff in production' ?>.</p>
        <form method="post" action="<?= e(url('/account/security/mfa/start')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-sm" type="submit">Set up two-step verification</button></form>
      <?php endif ?>
    </section>

    <section class="panel" id="sessions">
      <h2>Where you're signed in</h2>
      <ul class="doc-list">
        <?php foreach ($sessions as $s): ?>
          <li><span><strong><?= e(mb_substr($s['user_agent'], 0, 80) ?: 'Unknown browser') ?></strong><small class="block">Signed in <?= e(fmt_date($s['created_at'])) ?> · last active <?= e(fmt_date($s['last_seen_at'])) ?> UTC<?= $s['ip'] ? ' · ' . e($s['ip']) : '' ?></small></span>
            <?php if ((int) $s['id'] === $this_session): ?><span class="tag-sm tag-verified">This device</span><?php else: ?>
            <?= post_form('/account/security/sessions/' . $s['id'] . '/revoke', '<button class="btn btn-outline btn-sm" type="submit">Sign out</button>', '') ?><?php endif ?>
          </li>
        <?php endforeach ?>
      </ul>
      <?php if (count($sessions) > 1): ?>
        <?= post_form('/account/security/sessions/revoke-others', '<button class="btn btn-dark btn-sm" type="submit">Sign out all other devices</button>', '') ?>
      <?php endif ?>
    </section>
  </div>

  <div>
    <section class="panel" id="password">
      <h2>Change password</h2>
      <form method="post" action="<?= e(url('/account/security/password')) ?>" class="form" novalidate>
        <?= csrf_field() ?>
        <div><label for="current">Current password</label><input id="current" name="current" type="password" autocomplete="current-password" required></div>
        <div><label for="new-password">New password</label><input id="new-password" name="password" type="password" autocomplete="new-password" minlength="10" required aria-describedby="np-hint"><p class="hint" id="np-hint">At least 10 characters. Other devices will be signed out.</p></div>
        <div><label for="new-confirm">Confirm new password</label><input id="new-confirm" name="confirm" type="password" autocomplete="new-password" required></div>
        <div><button class="btn btn-primary btn-sm" type="submit">Change password</button></div>
      </form>
    </section>

    <?php if ($isClient): ?>
    <section class="panel" id="marketing">
      <h2>Email preferences</h2>
      <p class="muted small">We always email you about your account and applications. Marketing emails are optional and off unless you turn them on.</p>
      <form method="post" action="<?= e(url('/account/security/marketing')) ?>" class="form">
        <?= csrf_field() ?>
        <label class="check"><input type="checkbox" name="marketing" value="1"<?= checked($opted_in) ?>> Send me occasional emails about business finance services. I can unsubscribe at any time.</label>
        <div><button class="btn btn-outline btn-sm" type="submit">Save preference</button></div>
      </form>
    </section>
    <?php endif ?>
  </div>
</div>
