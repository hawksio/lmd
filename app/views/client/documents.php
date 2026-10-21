<?php $title = 'My documents'; ?>
<div class="page-head"><div><h1>My documents</h1><p>Everything you've uploaded, and letters we've issued to you.</p></div></div>
<section class="panel">
  <h2>Letters and agreements from <?= e(current_org()['name']) ?></h2>
  <ul class="doc-list">
    <?php foreach ($letters as $l): ?>
      <li><span><a href="<?= e(url("/account/letters/{$l['id']}")) ?>"><?= e($l['title']) ?></a><small class="block"><?= e($l['company_name']) ?> · <?= e(ref_str((int) $l['application_id'])) ?> · issued <?= e(fmt_date($l['issued_at'])) ?></small></span><span class="tag-sm">PDF</span></li>
    <?php endforeach ?>
    <?php if (!$letters): ?><li class="muted">None yet.</li><?php endif ?>
  </ul>
</section>
<section class="panel">
  <h2>Uploaded documents</h2>
  <?php if ($docs): ?>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th>Document</th><th>Type</th><th>Application</th><th>Uploaded</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($docs as $d): ?>
        <tr><td><a href="<?= e(url("/account/documents/{$d['id']}")) ?>"><?= e($d['original_name']) ?></a><?php if (in_array($d['mime'], ['image/png', 'image/jpeg'], true)): ?><span class="sub"><a href="<?= e(url("/account/documents/{$d['id']}")) ?>?view=1" target="_blank" rel="noopener">Preview</a></span><?php endif ?></td>
          <td><?= e(DOC_TYPES[$d['doc_type']] ?? 'Other') ?></td>
          <td><a href="<?= e(url("/account/applications/{$d['app_id']}#documents")) ?>"><?= e(ref_str((int) $d['app_id'])) ?></a><span class="sub"><?= e($d['company_name']) ?></span></td>
          <td class="muted small"><?= e(fmt_date($d['created_at'])) ?></td>
          <td><?= review_badge($d['review_status']) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php else: ?><p class="muted">You haven't uploaded anything yet. Upload documents from an application's page.</p><?php endif ?>
</section>
