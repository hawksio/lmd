<?php $title = 'Set up'; ?>
<div class="panel auth-card narrow-panel">
  <h1>Set up the platform</h1>
  <p class="muted">Create the two first accounts. Choose strong, different passwords and turn on two-step verification after signing in. This page stops working once a platform owner exists.</p>
  <form method="post" class="form" novalidate>
    <?= csrf_field() ?>
    <h2 class="small-h">Platform owner (console)</h2>
    <?= field_input('owner_name', 'Name', $f, $errors, ['required' => true, 'maxlength' => 120]) ?>
    <?= field_input('owner_email', 'Email', $f, $errors, ['type' => 'email', 'required' => true, 'maxlength' => 200]) ?>
    <div><label for="owner_password">Password (12+ characters)</label><input id="owner_password" name="owner_password" type="password" autocomplete="new-password" required><?= field_error($errors, 'owner_password') ?></div>
    <h2 class="small-h">First administrator of <?= e($org['name']) ?></h2>
    <?= field_input('admin_name', 'Name', $f, $errors, ['required' => true, 'maxlength' => 120]) ?>
    <?= field_input('admin_email', 'Email', $f, $errors, ['type' => 'email', 'required' => true, 'maxlength' => 200]) ?>
    <div><label for="admin_password">Password (12+ characters)</label><input id="admin_password" name="admin_password" type="password" autocomplete="new-password" required><?= field_error($errors, 'admin_password') ?></div>
    <button class="btn btn-primary" type="submit">Create accounts</button>
  </form>
</div>
