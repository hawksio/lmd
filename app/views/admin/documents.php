<?php $title = 'Document verification'; ?>
<div class="page-head"><div><h1>Document verification</h1><p><?= $total ?> document<?= $total === 1 ? '' : 's' ?> uploaded by customers. Verifying a document never approves a loan.</p></div></div>
<form class="filters" method="get">
  <div><label for="status">Status</label><select id="status" name="status">
    <?= options_html(['awaiting' => 'Awaiting review', 'all' => 'All'] + array_diff_key(DOC_STATUSES, ['not_submitted' => 1]), $status) ?></select></div>
  <div><label for="type">Type</label><select id="type" name="type"><?= options_html(DOC_TYPES, $type, true, 'All types') ?></select></div>
  <div><label for="q">Applicant or reference</label><input id="q" name="q" type="search" value="<?= e($search) ?>" maxlength="100"></div>
  <button class="btn btn-dark btn-sm" type="submit">Filter</button>
</form>
<?php if ($rows): ?>
<div class="table-wrap"><table class="data">
  <thead><tr><th>Document</th><th>Applicant</th><th>Uploaded</th><th>Status</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $d): ?>
    <tr><td><?= e(DOC_TYPES[$d['doc_type']] ?? 'Other') ?><span class="sub"><?= e($d['original_name']) ?><?= $d['replaces_id'] ? ' · replacement' : '' ?></span></td>
      <td><?= e($d['full_name']) ?><span class="sub"><a href="<?= e(url("/admin/applications/{$d['application_id']}#documents")) ?>"><?= e(ref_str((int) $d['application_id'])) ?></a> · <?= e($d['company_name']) ?></span></td>
      <td class="muted small"><?= e(fmt_date($d['created_at'])) ?></td><td><?= review_badge($d['review_status']) ?></td>
      <td><a class="btn btn-outline btn-sm" href="<?= e(url("/admin/documents/{$d['id']}/review")) ?>">Review</a></td></tr>
  <?php endforeach ?></tbody></table></div>
<?= pager($page, $pages) ?>
<?php else: ?><div class="panel empty"><p>No documents match.</p></div><?php endif ?>
