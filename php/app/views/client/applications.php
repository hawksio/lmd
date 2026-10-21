<?php $title = 'My applications'; ?>
<div class="page-head">
  <div><h1>My applications</h1><p>Every application and where it stands.</p></div>
  <a class="btn btn-primary" href="<?= e(url('/account/applications/new')) ?>">Start a new application</a>
</div>
<?php if ($apps): ?>
<div class="app-cards">
  <?php foreach ($apps as $a): ?>
  <a class="app-card" href="<?= e(url($a['status'] === 'draft' ? "/account/applications/{$a['id']}/edit" : "/account/applications/{$a['id']}")) ?>">
    <h2><?= e($a['company_name'] ?: 'Untitled application') ?></h2>
    <?= badge($a['status']) ?>
    <span class="meta">
      <?= e(ref_str((int) $a['id'])) ?> · <?= e($a['product_name'] ?: (FINANCE_TYPES[$a['finance_type']] ?? 'Product not chosen')) ?>
      <?= $a['amount'] ? ' · ' . e(money($a['amount'] * 100, $a['currency'])) . ' requested' : '' ?>
      <?= $a['term_months'] ? ' over ' . e($a['term_months']) . ' months' : '' ?>
      · Updated <?= e(fmt_date($a['updated_at'])) ?>
      <?= $a['status'] === 'draft' ? ' · <strong>Continue your draft</strong>' : '' ?>
    </span>
  </a>
  <?php endforeach ?>
</div>
<?php else: ?>
<div class="panel empty">
  <h2>No applications yet</h2>
  <p>Tell us about your business and what you need. You can save a draft and come back any time.</p>
  <a class="btn btn-primary" href="<?= e(url('/account/applications/new')) ?>">Start your application</a>
</div>
<?php endif ?>
