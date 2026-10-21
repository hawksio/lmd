<?php
$title = $app ? 'Edit application' : 'New application';
$fieldErrors = count($errors) - (isset($errors['form']) ? 1 : 0);
$allowed = $product ? csv_list($product['currencies']) : array_keys(CURRENCIES);
$currentCurrency = $values['currency'] ?? current_org()['default_currency'];
$whole = fn($minor, $cur) => substr(money($minor, $cur), 0, -3);
?>
<a class="crumb" href="<?= e(url('/account/applications')) ?>">My applications</a>
<div class="page-head">
  <div>
    <h1><?= $app ? 'Continue your application' : 'Apply for finance' ?></h1>
    <p>Fields marked * are needed to submit. Save a draft at any point.</p>
  </div>
  <?php if ($app): ?><span class="muted"><?= e(ref_str((int) $app['id'])) ?></span><?php endif ?>
</div>

<?php if ($errors): ?>
<div class="notice error" role="alert" tabindex="-1" id="form-errors">
  <?php if (isset($errors['form'])): ?><p><?= e($errors['form']) ?></p><?php endif ?>
  <?php if ($fieldErrors): ?><p>Please check the <?= $fieldErrors ?> highlighted <?= $fieldErrors === 1 ? 'field' : 'fields' ?> below.</p><?php endif ?>
</div>
<?php endif ?>

<form method="post" class="panel" novalidate id="application-form" data-prefill="<?= (!$app && !$values) ? '1' : '' ?>" data-once>
  <?= csrf_field() ?>
  <input type="hidden" name="submission_key" value="<?= e($submission_key) ?>">
  <?php if ($products): ?>
  <section class="form-section">
    <h2>Choose a product</h2>
    <div class="product-choices">
      <?php foreach ($products as $p): $cur = csv_list($p['currencies'])[0] ?? 'GBP'; ?>
        <label class="product-choice"><input type="radio" name="product_id" value="<?= (int) $p['id'] ?>"<?= checked($product && (int) $product['id'] === (int) $p['id']) ?> data-autosubmit>
          <span><strong><?= e($p['name']) ?></strong><small><?= e($whole($p['min_amount_minor'], $cur)) ?> to <?= e($whole($p['max_amount_minor'], $cur)) ?> · <?= (int) $p['min_term_months'] ?>–<?= (int) $p['max_term_months'] ?> months</small>
          <?php if ($p['description']): ?><small><?= e($p['description']) ?></small><?php endif ?></span></label>
      <?php endforeach ?>
    </div>
    <?= field_error($errors, 'product_id') ?>
    <noscript><button class="btn btn-outline btn-sm" type="submit" name="action" value="choose">Show this product's questions</button></noscript>
    <?php if ($product && $product['required_docs']): ?><p class="hint">You'll be asked for: <?= e(implode(', ', array_map(fn($d) => DOC_TYPES[$d] ?? $d, csv_list($product['required_docs'])))) ?>.</p><?php endif ?>
  </section>
  <?php endif ?>

  <section class="form-section">
    <h2>Your business</h2>
    <p>The basics needed to assess a business finance request.</p>
    <div class="grid-fields">
      <?= field_input('company_name', 'Business name', $values, $errors, ['required' => true, 'autocomplete' => 'organization', 'cls' => 'full', 'maxlength' => 200]) ?>
      <?= field_select('business_type', 'Business type', BUSINESS_TYPES, $values, $errors, ['required' => true]) ?>
      <?= field_input('company_number', 'Company registration number', $values, $errors, ['hint' => 'Needed for limited companies and LLPs.', 'maxlength' => 30]) ?>
      <?= field_input('industry', 'Industry', $values, $errors, ['required' => true, 'hint' => 'For example: engineering, hospitality, haulage.', 'maxlength' => 200]) ?>
      <?= field_select('years_trading', 'Time trading', YEARS_TRADING, $values, $errors, ['required' => true]) ?>
      <?= field_input('annual_turnover', 'Annual turnover', $values, $errors, ['type' => 'number', 'required' => true]) ?>
      <?= field_select('role_in_business', 'Your role in the business', ROLES_IN_BUSINESS, $values, $errors) ?>
      <?= field_textarea('address', 'Business address', $values, $errors, ['required' => true, 'rows' => 3, 'maxlength' => 500, 'cls' => 'full']) ?>
    </div>
  </section>

  <section class="form-section">
    <h2>The finance you need</h2>
    <p>Your best estimate is fine.</p>
    <div class="grid-fields">
      <?php if (!$products): ?><?= field_select('finance_type', 'Type of finance', FINANCE_TYPES, $values, $errors, ['required' => true]) ?><?php endif ?>
      <div><label for="currency">Currency</label><select id="currency" name="currency"><?php foreach ($allowed as $k): ?><option value="<?= e($k) ?>"<?= $currentCurrency === $k ? ' selected' : '' ?>><?= e($k) ?></option><?php endforeach ?></select><?= field_error($errors, 'currency') ?></div>
      <?= field_input('amount', 'Amount', $values, $errors, ['type' => 'number', 'required' => true,
          'hint' => $product ? 'Between ' . $whole($product['min_amount_minor'], $allowed[0]) . ' and ' . $whole($product['max_amount_minor'], $allowed[0]) . '.' : 'Between 1,000 and 5,000,000.']) ?>
      <?= field_input('term_months', 'Term (months)', $values, $errors, ['type' => 'number', 'required' => true,
          'hint' => $product ? "{$product['min_term_months']} to {$product['max_term_months']} months." : 'For example 36, 48 or 60.']) ?>
      <?= field_textarea('purpose', 'What is the finance for?', $values, $errors, ['required' => true, 'rows' => 5, 'maxlength' => 2000, 'cls' => 'full']) ?>
    </div>
  </section>

  <?php if ($fields): ?>
  <section class="form-section">
    <h2>About this product</h2>
    <div class="grid-fields">
      <?php foreach ($fields as $f): $name = 'x_' . $f['key']; $val = (string) ($extra[$f['key']] ?? ($values[$name] ?? '')); ?>
        <div class="<?= $f['type'] === 'text' ? 'full' : '' ?>">
          <label for="<?= e($name) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="muted">*</span>' : '' ?></label>
          <?php if ($f['type'] === 'select'): ?><select id="<?= e($name) ?>" name="<?= e($name) ?>"><option value="">Choose…</option><?php foreach ($f['options'] ?? [] as $o): ?><option value="<?= e($o) ?>"<?= $val === $o ? ' selected' : '' ?>><?= e($o) ?></option><?php endforeach ?></select>
          <?php elseif ($f['type'] === 'yesno'): ?><select id="<?= e($name) ?>" name="<?= e($name) ?>"><?= options_html(['yes' => 'Yes', 'no' => 'No'], $val, true) ?></select>
          <?php else: ?><input id="<?= e($name) ?>" name="<?= e($name) ?>" value="<?= e($val) ?>" maxlength="500"<?= $f['type'] === 'number' ? ' inputmode="numeric"' : '' ?>><?php endif ?>
          <?= field_error($errors, $name) ?>
        </div>
      <?php endforeach ?>
    </div>
  </section>
  <?php endif ?>

  <section class="form-section">
    <h2>Documents</h2>
    <p>After you submit, you can upload documents on your application page. Files are checked, stored privately and only seen by you and authorised staff.</p>
  </section>

  <section class="form-section">
    <h2>Consent</h2>
    <label class="check"><input type="checkbox" name="consent" value="1"<?= checked(!empty($values['consent'])) ?>>
      I confirm the information is accurate, and I consent to <?= e(site()->show('legal_name')) ?> using it, and sharing it where needed, to assess this application, including checks which may include a credit search.</label>
    <?= field_error($errors, 'consent') ?>
    <p class="hint">Submitting is not a commitment to take finance, and is not a decision. Any offer is explained in writing before you agree to anything. No fee is ever needed to get a decision or receive funds.</p>
  </section>

  <div class="form-actions">
    <button class="btn btn-outline" type="submit" name="action" value="save">Save draft</button>
    <button class="btn btn-primary" type="submit" name="action" value="submit">Submit application</button>
  </div>
</form>

<?php if ($app && $app['status'] === 'draft'): ?>
<form method="post" action="<?= e(url("/account/applications/{$app['id']}/delete")) ?>" class="small right-actions" data-confirm="Delete this draft? This cannot be undone.">
  <?= csrf_field() ?>
  <button class="btn btn-danger btn-sm" type="submit">Delete draft</button>
</form>
<?php endif ?>
