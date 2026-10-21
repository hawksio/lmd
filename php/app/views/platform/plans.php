<?php $title = 'Plans'; ?>
<div class="page-head"><div><h1>Plans</h1><p>Subscription plans organisations can be put on. Names, prices and limits are yours to set.</p></div></div>
<?php if ($errors): ?><p class="notice error"><?= e(implode(' ', $errors)) ?></p><?php endif ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Plan</th><th>Price</th><th>Limits</th><th>Usage fee</th><th>Subscribers</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($rows as $p): ?>
    <tr><td><strong><?= e($p['name']) ?></strong><span class="sub"><?= e($p['code']) ?><?= $p['trial_days'] ? ' · ' . (int) $p['trial_days'] . '-day trial' : '' ?></span></td>
      <td><?= e(money($p['price_minor'], $p['currency'])) ?> / <?= e($p['billing_period']) ?></td>
      <td class="small">Staff <?= e($p['max_staff'] ?? '∞') ?> · applications/month <?= e($p['max_applications_month'] ?? '∞') ?> · products <?= e($p['max_products'] ?? '∞') ?></td>
      <td><?= e($p['platform_fee_percent']) ?>%</td><td><?= (int) $p['subs'] ?></td><td><?= $p['active'] ? 'Active' : 'Hidden' ?></td></tr>
  <?php endforeach ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="muted">No plans yet. Create e.g. Starter, Professional and Enterprise below.</td></tr><?php endif ?></tbody></table></div>
<section class="panel spaced">
  <h2>Add or update a plan</h2>
  <p class="hint">Choose an existing plan to update it (existing invoices never change).</p>
  <form method="post" class="form grid-fields" novalidate>
    <?= csrf_field() ?>
    <div><label for="id">Plan to update (optional)</label><select id="id" name="id"><option value="">New plan</option><?= options_html(array_column($rows, 'name', 'id')) ?></select></div>
    <div><label for="code">Code</label><input id="code" name="code" maxlength="30" placeholder="starter"></div>
    <div><label for="name">Name</label><input id="name" name="name" maxlength="80"></div>
    <div><label for="price">Price</label><input id="price" name="price" inputmode="decimal" placeholder="0.00"></div>
    <div><label for="currency">Currency</label><select id="currency" name="currency"><?= options_html(array_combine(array_keys(CURRENCIES), array_keys(CURRENCIES))) ?></select></div>
    <div><label for="billing_period">Billing period</label><select id="billing_period" name="billing_period"><?= options_html(['month' => 'Monthly', 'year' => 'Yearly']) ?></select></div>
    <div><label for="trial_days">Trial days</label><input id="trial_days" name="trial_days" inputmode="numeric" value="0"></div>
    <div><label for="max_staff">Max staff (empty = unlimited)</label><input id="max_staff" name="max_staff" inputmode="numeric"></div>
    <div><label for="max_applications_month">Max applications a month</label><input id="max_applications_month" name="max_applications_month" inputmode="numeric"></div>
    <div><label for="max_products">Max loan products</label><input id="max_products" name="max_products" inputmode="numeric"></div>
    <div><label for="platform_fee_percent">Usage fee (% of confirmed disbursements, max 10)</label><input id="platform_fee_percent" name="platform_fee_percent" inputmode="decimal" value="0"></div>
    <div class="full"><label for="description">Description</label><input id="description" name="description" maxlength="500"></div>
    <div class="full"><label for="features">Included features</label><input id="features" name="features" maxlength="1000"></div>
    <label class="check full"><input type="checkbox" name="active" value="1" checked> Available for new subscriptions</label>
    <div><button class="btn btn-primary btn-sm" type="submit">Save plan</button></div>
  </form>
</section>
