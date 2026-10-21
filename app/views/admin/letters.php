<?php $title = 'Agreements and letters'; ?>
<div class="page-head"><div><h1>Agreements and letters</h1><p>Every generated document, its version and who generated and issued it. Drafts are never visible to customers.</p></div></div>
<nav class="chips" aria-label="Filter">
  <a class="chip" href="<?= e(url('/admin/letters')) ?>"<?= !$kind ? ' aria-current="true"' : '' ?>>All</a>
  <?php foreach (LETTER_KINDS as $k => $v): ?><a class="chip" href="<?= e(url('/admin/letters?kind=' . $k)) ?>"<?= $kind === $k ? ' aria-current="true"' : '' ?>><?= e($v) ?></a><?php endforeach ?>
</nav>
<?php if ($rows): ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Document</th><th>Application</th><th>Generated</th><th>Status</th></tr></thead>
  <tbody><?php foreach ($rows as $g): ?>
    <tr><td><?php if (can('view_documents')): ?><a href="<?= e(url("/admin/letters/{$g['id']}")) ?>"><?= e($g['title']) ?></a><?php else: ?><?= e($g['title']) ?><?php endif ?></td>
      <td><a href="<?= e(url("/admin/applications/{$g['application_id']}#letters")) ?>"><?= e($g['company_name']) ?></a><span class="sub"><?= e(ref_str((int) $g['application_id'])) ?></span></td>
      <td class="small"><?= e(fmt_date($g['generated_at'])) ?><span class="sub"><?= e($g['generated_by_name']) ?></span></td>
      <td><span class="tag-sm tag-<?= $g['status'] === 'issued' ? 'verified' : ($g['status'] === 'draft' ? 'submitted' : 'rejected') ?>"><?= e(ucfirst($g['status'])) ?></span></td></tr>
  <?php endforeach ?></tbody></table></div>
<?php else: ?><div class="panel empty"><p>No documents yet.</p></div><?php endif ?>
