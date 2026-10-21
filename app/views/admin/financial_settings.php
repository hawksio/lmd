<?php
$title = 'Financial settings';
$names = array_column($products, 'name', 'id');
$default = current_org()['default_currency'];
?>
<div class="page-head"><div><h1>Financial settings</h1><p>Percentages and charges used to price approvals. Changes apply to new decisions only; offers and agreements keep the settings they were made with.</p></div>
  <?php if (can('manage_financial_settings')): ?><a class="btn btn-primary" href="<?= e(url('/admin/financial-settings/new')) ?>">New setting</a><?php endif ?></div>
<p class="notice small">Only configure rates, fees and charges that your organisation is permitted to charge where it operates, and that are disclosed in your agreements. The software calculates; it does not make a charge lawful.</p>

<?php foreach (RULE_KINDS as $kind => $label): $group = array_filter($rules, fn($r) => $r['kind'] === $kind); ?>
  <section class="panel">
    <div class="panel-head"><h2><?= e($label) ?></h2>
      <span class="muted small">Limit: <?= e($caps[$kind]) ?>%</span>
      <?php if (can('manage_financial_settings')): ?><a class="btn btn-outline btn-sm" href="<?= e(url('/admin/financial-settings/new?kind=' . $kind)) ?>">Add</a><?php endif ?></div>
    <?php if ($group): ?>
    <div class="table-wrap"><table class="data compact">
      <thead><tr><th>Name</th><th>Value</th><th>Calculated on</th><th>Applies to</th><th>Effective</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($group as $r): ?>
        <tr><td><a href="<?= e(url("/admin/financial-settings/{$r['id']}")) ?>"><?= e($r['name']) ?></a><span class="sub">v<?= (int) $r['version'] ?> · <?= e($r['updated_name']) ?></span></td>
          <td><?= $r['method'] === 'percent' ? e($r['percent']) . '%' : e(money($r['fixed_minor'], csv_list($r['currencies'] ?: $default)[0])) ?></td>
          <td><?= e(RULE_BASES[$r['basis']]) ?><span class="sub"><?= e(RULE_TIMINGS[$r['timing']]) ?></span></td>
          <td class="small"><?= $r['product_ids'] ? e(implode(', ', array_map(fn($i) => $names[(int) $i] ?? "#$i", csv_list($r['product_ids'])))) : 'All products' ?><span class="sub"><?= e($r['currencies'] ?: 'All currencies') ?></span></td>
          <td class="small"><?= e($r['effective_from']) ?><?= $r['effective_to'] ? ' to ' . e($r['effective_to']) : '' ?></td>
          <td><span class="tag-sm tag-<?= $r['enabled'] ? 'verified' : 'rejected' ?>"><?= $r['enabled'] ? 'Enabled' : 'Disabled' ?></span></td>
          <td><?php if (can('manage_financial_settings')): ?><form method="post" action="<?= e(url("/admin/financial-settings/{$r['id']}/toggle")) ?>" class="mini-form"><?= csrf_field() ?>
            <label class="check"><input type="checkbox" name="confirm" value="1"> Confirm</label><button class="btn btn-outline btn-sm" type="submit"><?= $r['enabled'] ? 'Disable' : 'Enable' ?></button></form><?php endif ?></td></tr>
      <?php endforeach ?></tbody></table></div>
    <?php else: ?><p class="muted small">None configured.</p><?php endif ?>
  </section>
<?php endforeach ?>

<section class="panel" id="calculator">
  <h2>Calculator</h2>
  <p class="muted small">See the full breakdown the current settings produce. Nothing is saved.</p>
  <form method="get" class="filters" action="#calculator">
    <div><label for="calc_product">Product</label><select id="calc_product" name="calc_product"><?= options_html(array_column($products, 'name', 'id'), arg('calc_product')) ?></select></div>
    <div><label for="calc_currency">Currency</label><select id="calc_currency" name="calc_currency"><?= options_html(array_combine(array_keys(CURRENCIES), array_keys(CURRENCIES)), arg('calc_currency', $default)) ?></select></div>
    <div><label for="calc_amount">Amount</label><input id="calc_amount" name="calc_amount" inputmode="decimal" value="<?= e(arg('calc_amount')) ?>"></div>
    <div><label for="calc_term">Term (months)</label><input id="calc_term" name="calc_term" inputmode="numeric" value="<?= e(arg('calc_term')) ?>"></div>
    <button class="btn btn-dark btn-sm" type="submit">Calculate</button>
  </form>
  <?php if ($calc_error): ?><p class="notice error"><?= e($calc_error) ?></p><?php endif ?>
  <?php if ($calc): ?><?= breakdown_html($calc) ?><?php endif ?>
</section>
