<?php $title = 'Review document'; ?>
<a class="crumb" href="<?= e(url('/admin/documents/')) ?>">Document verification</a>
<div class="page-head"><div><h1><?= e(DOC_TYPES[$doc['doc_type']] ?? 'Document') ?></h1>
  <p><?= e($applicant['full_name']) ?> · <a href="<?= e(url("/admin/applications/{$doc['app_id']}#documents")) ?>"><?= e(ref_str((int) $doc['app_id'])) ?></a> · uploaded <?= e(fmt_date($doc['created_at'])) ?> · <?= (int) round($doc['size_bytes'] / 1024) ?> KB</p></div>
  <?= review_badge($doc['review_status']) ?></div>
<div class="layout-2">
  <div>
    <section class="panel">
      <h2>The file</h2>
      <?php if (can('view_documents')): ?>
        <?php if (in_array($doc['mime'], ['image/png', 'image/jpeg'], true)): ?>
          <p class="small muted">Secure preview (shown in an isolated frame; the access is recorded).</p>
          <iframe class="doc-preview" src="<?= e(url("/admin/document-files/{$doc['id']}")) ?>?view=1" title="Document preview" sandbox></iframe>
        <?php else: ?><p>PDFs open as a download for safety.</p><?php endif ?>
        <p><a class="btn btn-outline btn-sm" href="<?= e(url("/admin/document-files/{$doc['id']}")) ?>">Download <?= e($doc['original_name']) ?></a></p>
      <?php else: ?><p class="muted">Your role can see the document's status but not open the file.</p><?php endif ?>
      <p class="small muted">SHA-256: <code><?= e(substr($doc['sha256'], 0, 16)) ?>…</code></p>
    </section>
    <?php if (can('review_documents')): ?>
    <section class="panel">
      <h2>Verification decision</h2>
      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= e(arg('next')) ?>">
        <div><label for="review_status">Result</label>
          <select id="review_status" name="review_status"><?= options_html(array_intersect_key(DOC_STATUSES, array_flip(REVIEW_ACTIONS)), $doc['review_status']) ?></select></div>
        <div><label for="customer_note">Explanation for the customer</label><textarea id="customer_note" name="customer_note" rows="2" maxlength="1000"><?= e($doc['customer_note']) ?></textarea>
          <p class="hint">Required when rejecting or asking for a replacement. The customer sees this.</p></div>
        <div><label for="internal_note">Internal review note</label><textarea id="internal_note" name="internal_note" rows="2" maxlength="1000"><?= e($doc['internal_note']) ?></textarea>
          <p class="hint">Staff only.</p></div>
        <div><label for="expires_on">Document expiry date (optional)</label><input id="expires_on" name="expires_on" type="date" value="<?= e($doc['expires_on'] ?? '') ?>"></div>
        <div><button class="btn btn-primary btn-sm" type="submit">Save decision</button></div>
      </form>
    </section>
    <?php endif ?>
  </div>
  <div>
    <section class="panel">
      <h2>Review history</h2>
      <ol class="timeline"><?php foreach ($history as $h): ?>
        <li class="done"><span class="dot"></span><div><strong><?= e(DOC_STATUSES[$h['status']] ?? $h['status']) ?></strong><small><?= e(fmt_date($h['created_at'])) ?> · <?= e($h['full_name']) ?></small>
          <?php if ($h['customer_note']): ?><small class="block">To customer: <?= e($h['customer_note']) ?></small><?php endif ?><?php if ($h['internal_note']): ?><small class="block muted">Internal: <?= e($h['internal_note']) ?></small><?php endif ?></div></li>
      <?php endforeach ?>
      <?php if (!$history): ?><li><span class="dot"></span><div><strong>Submitted</strong><small>Not reviewed yet</small></div></li><?php endif ?></ol>
    </section>
    <section class="panel">
      <h2>Versions of this document</h2>
      <ul class="doc-list"><?php foreach ($versions as $v): ?><li><span><a href="<?= e(url("/admin/documents/{$v['id']}/review")) ?>"><?= e($v['original_name']) ?></a><small class="block"><?= e(fmt_date($v['created_at'])) ?><?= (int) $v['id'] === (int) $doc['id'] ? ' · this one' : '' ?></small></span><?= review_badge($v['review_status']) ?></li><?php endforeach ?></ul>
    </section>
  </div>
</div>
