<?php
$title = 'Applications';
$chip = fn(array $p) => e(url('/admin/applications' . ($p ? '?' . http_build_query($p) : '')));
$qp = $search !== '' ? ['q' => $search] : [];
?>
<div class="page-head"><div><h1>Applications</h1><p><?= $total ?> found<?= $status ? ' · ' . e(STATUSES[$status]) : '' ?><?= $attention ? ' · needing attention' : '' ?><?= $search !== '' ? ' · matching “' . e($search) . '”' : '' ?></p></div>
  <?php if (can('export_data')): ?><a class="btn btn-outline" href="?<?= e(pager_args(['export' => 'csv'])) ?>">Export CSV</a><?php endif ?></div>
<nav class="chips" aria-label="Filter by status">
  <a class="chip" href="<?= $chip($qp) ?>"<?= !$status && !$attention ? ' aria-current="true"' : '' ?>>All submitted</a>
  <a class="chip" href="<?= $chip(['attention' => 1] + $qp) ?>"<?= $attention ? ' aria-current="true"' : '' ?>>Needs attention</a>
  <?php foreach (STATUSES as $key => $label): if ($key === 'draft') { continue; } ?>
    <a class="chip" href="<?= $chip(['status' => $key] + $qp) ?>"<?= $status === $key ? ' aria-current="true"' : '' ?>><?= e($label) ?></a>
  <?php endforeach ?>
</nav>
<form class="filters" method="get" role="search">
  <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
  <?php if ($attention): ?><input type="hidden" name="attention" value="1"><?php endif ?>
  <div><label for="q">Search</label><input id="q" name="q" type="search" value="<?= e($search) ?>" placeholder="Business, applicant, email or reference" maxlength="100"></div>
  <div><label for="product">Product</label><select id="product" name="product"><option value="">All</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"<?= arg('product') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach ?></select></div>
  <div><label for="from">Submitted from</label><input id="from" name="from" type="date" value="<?= e(arg('from')) ?>"></div>
  <div><label for="to">to</label><input id="to" name="to" type="date" value="<?= e(arg('to')) ?>"></div>
  <div><label for="sort">Sort by</label><select id="sort" name="sort"><?= options_html(['updated' => 'Last updated', 'submitted' => 'Submitted', 'amount' => 'Amount', 'company' => 'Business name'], $sort) ?></select></div>
  <div><label for="dir">Order</label><select id="dir" name="dir"><?= options_html(['desc' => 'Newest / largest first', 'asc' => 'Oldest / smallest first'], $dir) ?></select></div>
  <button class="btn btn-dark btn-sm" type="submit">Apply</button>
</form>
<?php if ($apps): ?>
<div class="table-wrap">
  <table class="data">
    <thead><tr><th>Application</th><th>Applicant</th><th>Requested</th><th>Status</th><th>Submitted</th><th>Updated</th></tr></thead>
    <tbody>
      <?php foreach ($apps as $a): ?>
      <tr>
        <td><?= $a['needs_attention'] ? '<span class="attn" role="img" aria-label="Needs attention" title="Needs attention"></span>' : '' ?><a href="<?= e(url("/admin/applications/{$a['id']}")) ?>"><?= e($a['company_name'] ?: 'Untitled') ?></a><span class="sub"><?= e(ref_str((int) $a['id'])) ?></span></td>
        <td><?= e($a['full_name']) ?><span class="sub"><?= e($a['email']) ?></span></td>
        <td><?= e(money(((int) $a['amount']) * 100, $a['currency'])) ?><?= $a['term_months'] ? ' / ' . (int) $a['term_months'] . 'm' : '' ?><span class="sub"><?= e(FINANCE_TYPES[$a['finance_type']] ?? '') ?></span></td>
        <td><?= badge($a['status']) ?></td>
        <td class="muted small"><?= e(fmt_date($a['submitted_at'])) ?></td>
        <td class="muted small"><?= e(fmt_date($a['updated_at'])) ?></td>
      </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
<?= pager($page, $pages) ?>
<?php else: ?>
<div class="panel empty"><p>No applications match.</p></div>
<?php endif ?>
