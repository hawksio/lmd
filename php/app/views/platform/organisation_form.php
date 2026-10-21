<?php
$title = 'New organisation';
$where = config('tenant_base_domain') ? '<address>.' . config('tenant_base_domain') : rtrim((string) config('base_url'), '/') . rtrim((string) config('tenant_path_prefix'), '/') . '/<address>';
?>
<a class="crumb" href="<?= e(url('/platform/organisations')) ?>">Organisations</a>
<div class="page-head"><div><h1>New organisation</h1><p>Creates the organisation, its subscription (manual billing) and invites its first administrator.</p></div></div>
<p class="notice small">Onboard only organisations you have checked are entitled to lend or broker finance where they operate. The platform does not verify licences.</p>
<form method="post" class="panel form narrow-form" novalidate>
  <?= csrf_field() ?>
  <?= field_input('name', 'Organisation name', $f, $errors, ['required' => true, 'maxlength' => 120]) ?>
  <?= field_input('slug', 'Address', $f, $errors, ['required' => true, 'maxlength' => 31, 'hint' => "Their portal will be at $where (a custom domain can be added later)."]) ?>
  <div><label for="currency">Default currency</label><select id="currency" name="currency"><?= options_html(array_combine(array_keys(CURRENCIES), array_keys(CURRENCIES)), $f['currency'] ?? 'GBP') ?></select></div>
  <div><label for="plan_id">Plan</label><select id="plan_id" name="plan_id"><option value="">Choose…</option><?php foreach ($plans as $p): ?><option value="<?= (int) $p['id'] ?>"<?= ($f['plan_id'] ?? '') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?> · <?= e(money($p['price_minor'], $p['currency'])) ?>/<?= e($p['billing_period']) ?><?= $p['trial_days'] ? ' · ' . (int) $p['trial_days'] . '-day trial' : '' ?></option><?php endforeach ?></select><?= field_error($errors, 'plan_id') ?></div>
  <?= field_input('admin_name', 'First administrator: name', $f, $errors, ['required' => true, 'maxlength' => 120]) ?>
  <?= field_input('admin_email', 'First administrator: email', $f, $errors, ['type' => 'email', 'required' => true, 'maxlength' => 200]) ?>
  <div><button class="btn btn-primary" type="submit">Create organisation</button></div>
</form>
