<?php $title = 'Website content'; $faqs = [...$data['faqs'], ['q' => '', 'a' => ''], ['q' => '', 'a' => '']]; ?>
<div class="page-head"><div><h1>Website content</h1><p>Company details and FAQs used across <?= owns_static_site() ? 'the public site, ' : '' ?>letters and emails. Plain text only.</p></div></div>
<?php if ($missing): ?><p class="notice error">Not yet confirmed (shown to the public as placeholders): <?= e(implode(', ', $missing)) ?>. Enter only verified facts.</p><?php endif ?>
<?php if ($draft_row): ?>
<div class="notice">
  <p><strong>Unpublished draft</strong> saved <?= e(fmt_date($draft_row['created_at'])) ?><?= $changed ? '. Changes: ' . e(implode(', ', $changed)) : '' ?>.</p>
  <?php if (owns_static_site()): ?><p class="small">Preview: <?= implode(' · ', array_map(fn($p) => '<a href="' . e(url("/admin/content/preview/$p")) . '" target="_blank" rel="noopener">' . e($p) . '</a>', PREVIEW_PAGES)) ?></p><?php endif ?>
  <div class="inline-actions">
  <?php if (can('publish_content')): ?>
    <form method="post" action="<?= e(url('/admin/content/publish')) ?>" class="mini-form"><?= csrf_field() ?>
      <label class="check"><input type="checkbox" name="confirm" value="1"> I have previewed these changes and they are verified and approved</label>
      <button class="btn btn-primary btn-sm" type="submit">Publish</button></form>
  <?php else: ?><p class="small muted">An administrator needs to publish this draft.</p><?php endif ?>
    <form method="post" action="<?= e(url('/admin/content/discard')) ?>" data-confirm="Discard the unpublished draft?"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Discard draft</button></form>
  </div>
</div>
<?php endif ?>
<form method="post" class="panel form" novalidate>
  <?= csrf_field() ?>
  <section class="form-section">
    <h2>Company details</h2>
    <p>Leave a field empty until the fact is confirmed; the site then shows a clear placeholder.</p>
    <div class="grid-fields">
      <?php foreach (SETTING_FIELDS as $key => [$label, $ph, $help, $kind]): $long = in_array($key, ['registered_office', 'regulatory_statement', 'complaints_contact'], true); ?>
      <div class="<?= ($long || $key === 'announcement') ? 'full' : '' ?>">
        <label for="<?= e($key) ?>"><?= e($label) ?></label>
        <?php if ($long): ?>
          <textarea id="<?= e($key) ?>" name="<?= e($key) ?>" rows="2" maxlength="600"<?= isset($errors[$key]) ? ' aria-invalid="true"' : '' ?> aria-describedby="<?= e($key) ?>-hint"><?= e($data['settings'][$key] ?? '') ?></textarea>
        <?php else: ?>
          <input id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($data['settings'][$key] ?? '') ?>" maxlength="600"<?= isset($errors[$key]) ? ' aria-invalid="true"' : '' ?> aria-describedby="<?= e($key) ?>-hint">
        <?php endif ?>
        <p class="hint" id="<?= e($key) ?>-hint"><?= e($help) ?></p>
        <?= field_error($errors, $key) ?>
      </div>
      <?php endforeach ?>
    </div>
  </section>
  <section class="form-section">
    <h2>FAQs</h2>
    <p>Empty rows are ignored. Clear both boxes to remove an FAQ.</p>
    <?php foreach ($faqs as $i => $f): $n = $i + 1; ?>
      <div class="faq-edit">
        <label for="faq_q<?= $n ?>">Question <?= $n ?></label>
        <input id="faq_q<?= $n ?>" name="faq_q[]" value="<?= e($f['q']) ?>" maxlength="300">
        <label for="faq_a<?= $n ?>" class="sr">Answer <?= $n ?></label>
        <textarea id="faq_a<?= $n ?>" name="faq_a[]" rows="3" maxlength="3000" placeholder="Answer"><?= e($f['a']) ?></textarea>
        <?= field_error($errors, "faq$i") ?>
      </div>
    <?php endforeach ?>
  </section>
  <div class="form-actions">
    <div class="grow"><label for="note">Change note (optional)</label><input id="note" name="note" maxlength="300"></div>
    <button class="btn btn-primary" type="submit">Save draft</button>
  </div>
</form>
<section class="panel">
  <h2>History</h2>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Version</th><th>Status</th><th>Saved</th><th>Published</th><th>Note</th></tr></thead>
    <tbody><?php foreach ($history as $v): ?>
      <tr><td>#<?= (int) $v['id'] ?></td><td><?= e($v['status']) ?></td><td class="small"><?= e(fmt_date($v['created_at'])) ?> · <?= e($v['author'] ?: '—') ?></td><td class="small"><?= $v['published_at'] ? e(fmt_date($v['published_at'])) . ' · ' . e($v['publisher']) : '' ?></td><td class="small"><?= e($v['note']) ?></td></tr>
    <?php endforeach ?>
    <?php if (!$history): ?><tr><td colspan="5" class="muted">No saved versions yet: the site uses the default content.</td></tr><?php endif ?></tbody>
  </table></div>
</section>
