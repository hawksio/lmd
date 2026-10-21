<?php $title = 'My details'; ?>
<a class="crumb" href="<?= e(url('/account/security')) ?>">Account and security</a>
<div class="page-head"><div><h1>My details</h1><p>How the team contacts you.</p></div></div>
<form method="post" class="panel form narrow-form" novalidate>
  <?= csrf_field() ?>
  <?= field_input('full_name', 'Full name', $user, $errors, ['required' => true, 'autocomplete' => 'name', 'maxlength' => 120]) ?>
  <div>
    <label for="email">Email</label>
    <input id="email" type="email" value="<?= e($user['email']) ?>" disabled aria-describedby="email-hint">
    <p class="hint" id="email-hint">To change your email, please contact us so we can check it's you.</p>
  </div>
  <?= field_input('phone', 'Phone', $user, $errors, ['type' => 'tel', 'autocomplete' => 'tel', 'maxlength' => 40]) ?>
  <div><label for="address">Your address</label><textarea id="address" name="address" rows="3" maxlength="500" autocomplete="street-address"><?= e($user['address']) ?></textarea></div>
  <div><button class="btn btn-primary" type="submit">Save details</button></div>
</form>
<section class="panel narrow-form">
  <h2>Your data</h2>
  <p class="small">Download a copy of the personal information we hold about you (account, applications, document list, messages, payments and consent records). To ask us to correct or delete information, send us a message.</p>
  <a class="btn btn-outline btn-sm" href="<?= e(url('/account/privacy/export')) ?>">Download my data (JSON)</a>
</section>
