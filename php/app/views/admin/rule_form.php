<?php
$title = $rule['name'] ?? 'New financial setting';
$editable = can('manage_financial_settings');
$pids = csv_list($f['product_ids'] ?? '');
$curs = csv_list($f['currencies'] ?? '');
?>
<a class="crumb" href="<?= e(url('/admin/financial-settings')) ?>">Financial settings</a>
<div class="page-head"><div><h1><?= e($rule['name'] ?? 'New financial setting') ?></h1>
  <p><?= $rule ? 'Version ' . (int) $rule['version'] . '. ' : '' ?>Saving creates a new version; existing offers and agreements are not changed.</p></div></div>
<div class="layout-2">
<form method="post" class="panel form" novalidate>
  <?= csrf_field() ?>
  <fieldset<?= $editable ? '' : ' disabled' ?> class="plain-fieldset">
  <div class="grid-fields">
    <?= field_select('kind', 'Type', RULE_KINDS, $f, $errors, ['blank' => false]) ?>
    <?= field_input('name', 'Name', $f, $errors, ['required' => true, 'maxlength' => 120]) ?>
    <?= field_textarea('description', 'What it applies to', $f, $errors, ['rows' => 2, 'maxlength' => 1000, 'cls' => 'full']) ?>
    <?= field_select('method', 'Calculation', ['percent' => 'Percentage', 'fixed' => 'Fixed amount'], $f, $errors, ['blank' => false]) ?>
    <?= field_input('percent', 'Percentage (%)', $f, $errors, ['hint' => 'Up to 4 decimal places. Interest is an annual rate. Limit for this type: see right.']) ?>
    <?= field_input('fixed_amount', 'Fixed amount', $f, $errors, ['money' => true]) ?>
    <?= field_select('basis', 'Calculated on', RULE_BASES, $f, $errors, ['blank' => false]) ?>
    <?= field_select('timing', 'When it applies', RULE_TIMINGS, $f, $errors, ['blank' => false, 'cls' => 'full']) ?>
    <div><span class="label">Products</span><div class="checks"><?php foreach ($products as $p): ?><label class="check"><input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>"<?= checked(in_array((string) $p['id'], $pids, true)) ?>> <?= e($p['name']) ?></label><?php endforeach ?></div>
      <p class="hint">None ticked = all products.</p></div>
    <div><span class="label">Currencies</span><div class="checks"><?php foreach (CURRENCIES as $k => $_): ?><label class="check"><input type="checkbox" name="currencies[]" value="<?= e($k) ?>"<?= checked(in_array($k, $curs, true)) ?>> <?= e($k) ?></label><?php endforeach ?></div>
      <p class="hint">None ticked = all currencies.</p></div>
    <?= field_input('effective_from', 'Effective from', $f, $errors, ['type' => 'date']) ?>
    <?= field_input('effective_to', 'Effective until (optional)', $f, $errors, ['type' => 'date']) ?>
    <?= field_textarea('conditions', 'Conditions', $f, $errors, ['rows' => 2, 'maxlength' => 1000, 'cls' => 'full']) ?>
    <?= field_textarea('disclosure', 'Disclosure shown to customers', $f, $errors, ['rows' => 2, 'maxlength' => 2000, 'cls' => 'full']) ?>
    <?= field_input('arrangement_reference', 'Commission arrangement reference', $f, $errors, ['hint' => 'Required for commission: the documented arrangement it comes from.', 'maxlength' => 200, 'cls' => 'full']) ?>
    <label class="check full"><input type="checkbox" name="enabled" value="1"<?= checked(!empty($f['enabled'])) ?>> Enabled</label>
    <div class="full"><label for="reason">Reason for this change (kept in the history)</label><input id="reason" name="reason" maxlength="500"></div>
    <label class="check full"><input type="checkbox" name="confirm" value="1"> I confirm this setting has been approved under our pricing policy and is permitted where we lend</label>
    <?= field_error($errors, 'confirm') ?>
  </div>
  </fieldset>
  <?php if ($editable): ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save setting</button></div><?php endif ?>
</form>
<div>
  <section class="panel">
    <h2>Limits</h2>
    <dl class="facts compact"><?php foreach (RULE_KINDS as $k => $v): ?><dt><?= e($v) ?></dt><dd><?= e($caps[$k]) ?>%</dd><?php endforeach ?></dl>
    <p class="hint">Set by the platform and tightened in Organisation settings.</p>
  </section>
  <section class="panel">
    <h2>Change history</h2>
    <?php foreach ($history as $h): ?>
      <div class="history-item"><p><strong>v<?= (int) $h['version'] ?> · <?= e($h['action']) ?></strong> <span class="muted small"><?= e(fmt_date($h['changed_at'])) ?> · <?= e($h['full_name']) ?></span></p>
        <?php if ($h['reason']): ?><p class="small"><?= e($h['reason']) ?></p><?php endif ?>
        <?php if ($h['action'] === 'updated'): ?><table class="data compact"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>
          <?php foreach ($h['new'] as $k => $v): ?><tr><td><?= e($k) ?></td><td><?= e(is_scalar($h['old'][$k] ?? null) ? $h['old'][$k] : '') ?></td><td><?= e(is_scalar($v) ? $v : '') ?></td></tr><?php endforeach ?></tbody></table><?php endif ?></div>
    <?php endforeach ?>
    <?php if (!$history): ?><p class="muted small">No changes yet.</p><?php endif ?>
  </section>
</div>
</div>
