<?php $title = 'Loan products'; ?>
<div class="page-head"><div><h1>Loan products</h1><p>What customers can apply for. Use only products, limits and terms approved under your lending policy.</p></div>
  <?php if (can('manage_products')): ?><a class="btn btn-primary" href="<?= e(url('/admin/products/new')) ?>">New product</a><?php endif ?></div>
<?php if ($rows): ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Product</th><th>Amounts</th><th>Terms</th><th>Repayment</th><th>Applications</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($rows as $p): $cur = csv_list($p['currencies'])[0] ?? 'GBP'; ?>
    <tr><td><a href="<?= e(url("/admin/products/{$p['id']}")) ?>"><?= e($p['name']) ?></a><span class="sub"><?= e($p['currencies']) ?></span></td>
      <td><?= e(money($p['min_amount_minor'], $cur)) ?> – <?= e(money($p['max_amount_minor'], $cur)) ?></td>
      <td><?= (int) $p['min_term_months'] ?>–<?= (int) $p['max_term_months'] ?> months</td>
      <td><?= e(FREQUENCIES[$p['repayment_frequency']]) ?><span class="sub"><?= $p['interest_method'] === 'amortising' ? 'Reducing balance' : 'Flat rate' ?></span></td>
      <td><?= (int) $p['apps'] ?></td><td><?= $p['active'] ? 'Active' : 'Inactive' ?></td></tr>
  <?php endforeach ?></tbody></table></div>
<?php else: ?><div class="panel empty"><p>No products yet. Until you add one, customers apply with general limits and staff can't issue approvals.</p></div><?php endif ?>
