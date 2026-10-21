<?php $title = 'Approval queue'; ?>
<div class="page-head"><div><h1>Approval queue</h1><p>Decisions waiting to be issued, applications in review, and accepted offers waiting for an agreement.</p></div></div>
<section class="panel">
  <h2>Proposed decisions</h2>
  <?php if ($proposed): ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Application</th><th>Decision</th><th>Proposed by</th><th>Second person</th></tr></thead>
    <tbody><?php foreach ($proposed as $d): ?>
      <tr><td><a href="<?= e(url("/admin/applications/{$d['application_id']}#decision")) ?>"><?= e($d['company_name']) ?></a><span class="sub"><?= e(ref_str((int) $d['application_id'])) ?> · revision <?= (int) $d['revision'] ?></span></td>
        <td><?= e(ucfirst($d['decision_type'])) ?>: <?= e(money($d['approved_minor'], $d['currency'])) ?><span class="sub">of <?= e(money($d['requested_minor'], $d['currency'])) ?> requested</span></td>
        <td><?= e($d['proposer']) ?><span class="sub"><?= e(fmt_date($d['proposed_at'])) ?></span></td>
        <td><?php if ($d['needs_second_approval']): ?><?= (int) $d['proposed_by'] === $me ? '<span class="tag-sm tag-submitted">Someone else must issue</span>' : '<span class="tag-sm tag-under_review">You can review and issue</span>' ?><?php else: ?>Not required<?php endif ?></td></tr>
    <?php endforeach ?></tbody></table></div>
  <?php else: ?><p class="muted">None.</p><?php endif ?>
</section>
<section class="panel">
  <h2>In review</h2>
  <?php if ($underwriting): ?>
  <div class="table-wrap"><table class="data">
    <thead><tr><th>Application</th><th>Requested</th><th>Status</th><th>Submitted</th></tr></thead>
    <tbody><?php foreach ($underwriting as $a): ?>
      <tr><td><a href="<?= e(url("/admin/applications/{$a['id']}")) ?>"><?= e($a['company_name']) ?></a><span class="sub"><?= e(ref_str((int) $a['id'])) ?> · <?= e($a['full_name']) ?></span></td>
        <td><?= e(money(((int) $a['amount']) * 100, $a['currency'])) ?></td><td><?= badge($a['status']) ?></td><td class="muted small"><?= e(fmt_date($a['submitted_at'])) ?></td></tr>
    <?php endforeach ?></tbody></table></div>
  <?php else: ?><p class="muted">None.</p><?php endif ?>
</section>
<section class="panel">
  <h2>Offers accepted, agreement to prepare</h2>
  <?php if ($accepted): ?>
  <ul class="doc-list"><?php foreach ($accepted as $a): ?><li><span><a href="<?= e(url("/admin/applications/{$a['id']}#letters")) ?>"><?= e($a['company_name']) ?></a><small class="block"><?= e(ref_str((int) $a['id'])) ?> · <?= e($a['full_name']) ?> · accepted <?= e(fmt_date($a['accepted_at'])) ?></small></span><?= badge($a['status']) ?></li><?php endforeach ?></ul>
  <?php else: ?><p class="muted">None.</p><?php endif ?>
</section>
