<?php
$title = $product['name'] ?? 'New product';
$editable = can('manage_products');
$curs = csv_list($f['currencies'] ?? '');
$docs = csv_list($f['required_docs'] ?? '');
?>
<a class="crumb" href="<?= e(url('/admin/products')) ?>">Loan products</a>
<div class="page-head"><div><h1><?= e($product['name'] ?? 'New loan product') ?></h1><p>Interest and fees are set in Financial settings and can be limited to this product.</p></div></div>
<form method="post" class="panel form" novalidate>
  <?= csrf_field() ?>
  <fieldset<?= $editable ? '' : ' disabled' ?> class="plain-fieldset">
  <section class="form-section">
    <h2>Product</h2>
    <div class="grid-fields">
      <?= field_input('name', 'Name', $f, $errors, ['required' => true, 'maxlength' => 120, 'cls' => 'full']) ?>
      <?= field_textarea('description', 'Description (shown to customers)', $f, $errors, ['rows' => 2, 'maxlength' => 2000, 'cls' => 'full']) ?>
      <?= field_select('finance_type', 'Category', FINANCE_TYPES, $f, $errors, ['blank' => false]) ?>
      <div><span class="label">Currencies</span>
        <div class="checks"><?php foreach (CURRENCIES as $k => $_): ?><label class="check"><input type="checkbox" name="currencies[]" value="<?= e($k) ?>"<?= checked(in_array($k, $curs, true)) ?>> <?= e($k) ?></label><?php endforeach ?></div>
        <?= field_error($errors, 'currencies') ?></div>
    </div>
  </section>
  <section class="form-section">
    <h2>Limits</h2>
    <div class="grid-fields">
      <?= field_input('min_amount', 'Minimum amount', $f, $errors, ['money' => true]) ?>
      <?= field_input('max_amount', 'Maximum amount', $f, $errors, ['money' => true]) ?>
      <?= field_input('min_term_months', 'Shortest term (months)', $f, $errors, ['type' => 'number']) ?>
      <?= field_input('max_term_months', 'Longest term (months)', $f, $errors, ['type' => 'number']) ?>
      <label class="check full"><input type="checkbox" name="allow_above_requested" value="1"<?= checked(!empty($f['allow_above_requested'])) ?>> Policy allows approving more than the customer requested</label>
      <?= field_input('second_approval_threshold', 'Second-approval threshold (optional)', $f, $errors, ['money' => true, 'hint' => 'Approvals at or above this need a second authorised person. Empty = organisation setting.']) ?>
    </div>
  </section>
  <section class="form-section">
    <h2>Repayment</h2>
    <div class="grid-fields">
      <?= field_select('interest_method', 'Interest method', INTEREST_METHODS, $f, $errors, ['blank' => false]) ?>
      <?= field_select('repayment_frequency', 'Repayment frequency', FREQUENCIES, $f, $errors, ['blank' => false]) ?>
      <?= field_input('grace_period_months', 'Grace period (months, interest only)', $f, $errors, ['type' => 'number', 'hint' => 'Only if your policy permits. 0 = none.']) ?>
      <?= field_input('repayment_method', 'Repayment method', $f, $errors, ['hint' => 'e.g. Direct debit', 'maxlength' => 120]) ?>
    </div>
  </section>
  <section class="form-section">
    <h2>Eligibility and application</h2>
    <div class="grid-fields">
      <?= field_textarea('eligibility', 'Eligibility requirements (shown to staff; summarise for customers in the description)', $f, $errors, ['rows' => 3, 'maxlength' => 3000, 'cls' => 'full']) ?>
      <div class="full"><span class="label">Required documents</span>
        <div class="checks"><?php foreach (DOC_TYPES as $k => $v): ?><label class="check"><input type="checkbox" name="required_docs[]" value="<?= e($k) ?>"<?= checked(in_array($k, $docs, true)) ?>> <?= e($v) ?></label><?php endforeach ?></div></div>
      <div class="full"><label for="form_fields">Extra application questions (JSON)</label>
        <textarea id="form_fields" name="form_fields" rows="4" class="mono"<?= isset($errors['form_fields']) ? ' aria-invalid="true"' : '' ?>><?= e($f['form_fields'] ?? '[]') ?></textarea>
        <p class="hint">Types: text, number, select (with "options"), yesno. Example: [{"key": "vat_registered", "label": "Are you VAT registered?", "type": "yesno", "required": true}]</p>
        <?= field_error($errors, 'form_fields') ?></div>
      <label class="check full"><input type="checkbox" name="active" value="1"<?= checked(!empty($f['active'])) ?>> Active (customers can choose it)</label>
    </div>
  </section>
  </fieldset>
  <?php if ($editable): ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save product</button></div><?php endif ?>
</form>
