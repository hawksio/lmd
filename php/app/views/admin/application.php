<?php
$title = ref_str((int) $app['id']) . ' · Staff';
$c = $app['currency'];
$id = (int) $app['id'];
$m = fn($v, $cur = null) => e(money($v, $cur ?? $c));
$types = ['full' => 'Full approval', 'partial' => 'Partial approval', 'conditional' => 'Conditional approval'];
$df = $decision_form;
?>
<a class="crumb" href="<?= e(url('/admin/applications')) ?>">Applications</a>
<div class="page-head">
  <div>
    <h1><?= e($app['company_name'] ?: 'Untitled application') ?></h1>
    <p><?= e(ref_str($id)) ?> · <?= e($product['name'] ?? 'No product chosen') ?> · Submitted <?= e(fmt_date($app['submitted_at']) ?: 'not yet') ?> · Updated <?= e(fmt_date($app['updated_at'])) ?></p>
  </div>
  <?= badge($app['status']) ?>
</div>

<nav class="tabs-nav" aria-label="Sections">
  <a href="#summary">Summary</a><a href="#documents">Documents</a><a href="#decision">Decision</a><a href="#letters">Letters</a>
  <a href="#payments">Disbursements</a><a href="#repayments">Repayments</a><a href="#fees">Fees</a><a href="#notes">Messages</a>
</nav>

<div class="layout-2">
  <div>
    <section class="panel" id="summary">
      <h2>Application</h2>
      <div class="kpis">
        <div><span>Requested</span><strong><?= $m(((int) $app['amount']) * 100) ?></strong></div>
        <div><span>Term</span><strong><?= e($app['term_months'] ?: '–') ?> months</strong></div>
        <div><span>Approved</span><strong><?= $summary['approved'] ? $m($summary['approved']) : '–' ?></strong></div>
        <div><span>Disbursed</span><strong><?= $m($summary['disbursed']) ?></strong></div>
      </div>
      <dl class="facts">
        <dt>Purpose</dt><dd class="pre"><?= e($app['purpose']) ?></dd>
        <dt>Business</dt><dd><?= e(BUSINESS_TYPES[$app['business_type']] ?? '') ?><?= $app['company_number'] ? ' · ' . e($app['company_number']) : '' ?></dd>
        <dt>Industry</dt><dd><?= e($app['industry']) ?></dd>
        <dt>Time trading</dt><dd><?= e(YEARS_TRADING[$app['years_trading']] ?? '') ?></dd>
        <dt>Annual turnover</dt><dd><?= $m(((int) $app['annual_turnover']) * 100) ?></dd>
        <dt>Address</dt><dd class="pre"><?= e($app['address']) ?></dd>
        <dt>Applicant's role</dt><dd><?= e(ROLES_IN_BUSINESS[$app['role_in_business']] ?? 'Not given') ?></dd>
        <?php foreach ($extra_fields as $f): ?><dt><?= e($f['label']) ?></dt><dd><?= e(($extra[$f['key']] ?? '') ?: '–') ?></dd><?php endforeach ?>
        <dt>Consent to checks</dt><dd><?= $app['consent'] ? 'Yes' : 'No' ?></dd>
      </dl>
      <?php if ($product): $req = ((int) $app['amount']) * 100; ?>
      <h3 class="small-h">Eligibility and risk information</h3>
      <ul class="plain-list">
        <li>Amount within product range (<?= $m($product['min_amount_minor']) ?>–<?= $m($product['max_amount_minor']) ?>): <strong><?= ($product['min_amount_minor'] <= $req && $req <= $product['max_amount_minor']) ? 'yes' : 'no' ?></strong></li>
        <li>Term within range (<?= (int) $product['min_term_months'] ?>–<?= (int) $product['max_term_months'] ?> months): <strong><?= ($product['min_term_months'] <= (int) $app['term_months'] && (int) $app['term_months'] <= $product['max_term_months']) ? 'yes' : 'no' ?></strong></li>
        <?php if ($app['annual_turnover']): ?><li>Requested amount as a share of annual turnover: <strong><?= round(((int) $app['amount']) * 100 / $app['annual_turnover'], 1) ?>%</strong></li><?php endif ?>
        <?php if ($product['eligibility']): ?><li class="pre">Product eligibility: <?= e($product['eligibility']) ?></li><?php endif ?>
      </ul>
      <p class="hint">Information only. The software does not score or decide applications.</p>
      <?php endif ?>
    </section>

    <section class="panel" id="documents">
      <h2>Documents and verification</h2>
      <?php if ($requirements): ?>
      <h3 class="small-h">Requirements <?= $outstanding ? '<span class="tag-sm tag-replacement_required">' . count($outstanding) . ' outstanding</span>' : '<span class="tag-sm tag-verified">all verified</span>' ?></h3>
      <ul class="doc-list">
        <?php foreach ($requirements as $r): ?>
          <li><span><strong><?= e($r['label']) ?></strong><?php if ($r['request_note']): ?><small class="block">Requested: <?= e($r['request_note']) ?></small><?php endif ?></span>
            <span class="doc-actions"><?= review_badge($r['status']) ?><?php if ($r['document']): ?><a class="btn btn-outline btn-sm" href="<?= e(url("/admin/documents/{$r['document']['id']}/review")) ?>">Review</a><?php endif ?></span></li>
        <?php endforeach ?>
      </ul>
      <?php endif ?>
      <h3 class="small-h">All uploads</h3>
      <ul class="doc-list">
        <?php foreach ($docs as $d): ?>
          <li class="doc-row"><span>
            <?php if (can('view_documents')): ?><a href="<?= e(url("/admin/document-files/{$d['id']}")) ?>"><?= e($d['original_name']) ?></a><?php else: ?><?= e($d['original_name']) ?><?php endif ?>
            <small class="block"><?= e(DOC_TYPES[$d['doc_type']] ?? 'Other') ?> · <?= (int) round($d['size_bytes'] / 1024) ?> KB · <?= e(fmt_date($d['created_at'])) ?> · <?= $d['uploader_role'] === 'client' ? 'customer' : 'staff' ?> upload<?= $d['reviewer_name'] ? ' · reviewed by ' . e($d['reviewer_name']) : '' ?></small></span>
            <span class="doc-actions"><?= review_badge($d['review_status']) ?><a class="btn btn-outline btn-sm" href="<?= e(url("/admin/documents/{$d['id']}/review")) ?>">Open</a></span></li>
        <?php endforeach ?>
        <?php if (!$docs): ?><li class="muted">No documents yet.</li><?php endif ?>
      </ul>
      <?php if (can('review_documents')): ?>
      <details class="inline-details"><summary>Request a document or more evidence</summary>
        <form method="post" action="<?= e(url("/admin/documents/request/$id")) ?>" class="form">
          <?= csrf_field() ?>
          <div><label for="rq_type">Document type</label><select id="rq_type" name="doc_type"><?= options_html(DOC_TYPES) ?></select></div>
          <div><label for="rq_note">What is needed (shown to the customer)</label><textarea id="rq_note" name="note" rows="2" maxlength="1000" required></textarea></div>
          <div><button class="btn btn-dark btn-sm" type="submit">Send request</button></div>
        </form>
      </details>
      <?php endif ?>
      <?php $open = array_filter($doc_requests, fn($r) => $r['status'] === 'open'); if ($open): ?>
        <h3 class="small-h">Open requests</h3><ul class="plain-list">
        <?php foreach ($open as $r): ?>
          <li><?= e(DOC_TYPES[$r['doc_type']] ?? $r['doc_type']) ?>: <?= e($r['note']) ?> <small class="muted">(<?= e($r['full_name']) ?>, <?= e(fmt_date($r['created_at'])) ?>)</small>
            <?php if (can('review_documents')): ?><form method="post" action="<?= e(url("/admin/documents/request/{$r['id']}/cancel")) ?>" class="inline"><?= csrf_field() ?><button class="link-btn" type="submit">Cancel</button></form><?php endif ?></li>
        <?php endforeach ?></ul>
      <?php endif ?>
      <?php if (can('upload_documents')): ?>
      <form method="post" action="<?= e(url("/admin/applications/$id/documents")) ?>" enctype="multipart/form-data" class="upload spaced" data-once>
        <?= csrf_field() ?>
        <div><label for="doc_type">Type</label><select id="doc_type" name="doc_type"><?= options_html(DOC_TYPES) ?></select></div>
        <div><label for="document">Add a document (staff)</label><input id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required></div>
        <button class="btn btn-dark btn-sm" type="submit">Upload</button>
      </form>
      <?php endif ?>
    </section>

    <section class="panel" id="decision">
      <h2>Approval decision</h2>
      <?php if ($decision): ?>
        <p><span class="tag-sm tag-verified">Issued</span> Revision <?= (int) $decision['revision'] ?> · <?= e($types[$decision['decision_type']]) ?>
          · issued <?= e(fmt_date($decision['issued_at'])) ?><?= $accepted ? ' · <strong>accepted by the customer ' . e(fmt_date($accepted['accepted_at'])) . '</strong>' : ' · not yet accepted by the customer' ?></p>
        <?php if ($conditions): ?>
          <h3 class="small-h">Conditions</h3>
          <ul class="doc-list"><?php foreach ($conditions as $cd): ?>
            <li><span><?= e($cd['text']) ?></span><span class="doc-actions"><span class="tag-sm tag-<?= $cd['status'] !== 'outstanding' ? 'verified' : 'submitted' ?>"><?= e(ucfirst($cd['status'])) ?></span>
              <?php if (can('propose_decisions')): ?><form method="post" action="<?= e(url("/admin/conditions/{$cd['id']}")) ?>" class="mini-form"><?= csrf_field() ?>
                <select name="status" aria-label="Condition status"><?= options_html(['outstanding' => 'Outstanding', 'satisfied' => 'Satisfied', 'waived' => 'Waived'], $cd['status']) ?></select>
                <button class="btn btn-outline btn-sm" type="submit">Save</button></form><?php endif ?></span></li>
          <?php endforeach ?></ul>
        <?php endif ?>
        <details class="inline-details"><summary>Figures shown to the customer</summary><?= breakdown_html($decision['pricing_data']) ?></details>
      <?php endif ?>

      <?php foreach ($decisions as $d): if ($d['status'] !== 'proposed') { continue; } ?>
        <div class="terms-card pending">
          <p><span class="tag-sm tag-submitted">Proposed</span> Revision <?= (int) $d['revision'] ?> by <?= e($d['proposer']) ?> <?= e(fmt_date($d['proposed_at'])) ?>
            <?= $d['needs_second_approval'] ? ' · <strong>needs a second authorised person to issue</strong>' : '' ?></p>
          <p><?= e($types[$d['decision_type']]) ?>: <strong><?= $m($d['approved_minor'], $d['currency']) ?></strong> of <?= $m($d['requested_minor'], $d['currency']) ?> requested
            <?= $d['previous_approved_minor'] ? '(previously ' . $m($d['previous_approved_minor'], $d['currency']) . ')' : '' ?>, <?= (int) $d['term_months'] ?> months.</p>
          <p class="small pre">Basis: <?= e($d['reason']) ?><?= $d['internal_note'] ? "\nInternal note: " . e($d['internal_note']) : '' ?></p>
          <?= breakdown_html($d['pricing_data']) ?>
          <?php if (can('issue_decisions')): ?>
            <?php if ($d['needs_second_approval'] && (int) $d['proposed_by'] === $me): ?>
              <p class="muted small">Waiting for a different authorised person to review and issue this decision.</p>
            <?php else: ?>
              <form method="post" action="<?= e(url("/admin/decisions/{$d['id']}/issue")) ?>" class="mini-form"><?= csrf_field() ?>
                <label class="check"><input type="checkbox" name="confirm" value="1"> I have reviewed this decision and its figures</label>
                <button class="btn btn-primary btn-sm" type="submit">Issue decision to customer</button></form>
            <?php endif ?>
          <?php endif ?>
          <?php if (can('propose_decisions')): ?><form method="post" action="<?= e(url("/admin/decisions/{$d['id']}/withdraw")) ?>" class="mini-form"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Withdraw proposal</button></form><?php endif ?>
        </div>
      <?php endforeach ?>

      <?php if (can('propose_decisions') && $can_decide): ?>
      <details class="inline-details"<?= ($df || !$decision) ? ' open' : '' ?>><summary><?= $decision ? 'Revise the decision' : 'Propose a decision' ?></summary>
        <?php if (!$products): ?><p class="notice small">Set up a loan product first (Loan products), then its interest and fees in Financial settings.</p><?php endif ?>
        <?php if ($outstanding && $settings['require_verified_documents_for_underwriting']): ?><p class="notice small"><?= count($outstanding) ?> required document(s) not yet verified. Your organisation requires verification before a decision.</p><?php endif ?>
        <?php if (isset($decision_errors['pricing'])): ?><p class="notice error"><?= e($decision_errors['pricing']) ?></p><?php endif ?>
        <form method="post" action="<?= e(url("/admin/applications/$id/decision")) ?>" class="form grid-fields" novalidate>
          <?= csrf_field() ?>
          <div><label for="product_id">Loan product</label>
            <select id="product_id" name="product_id"><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"<?= (int) ($df['product_id'] ?? $app['product_id']) === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach ?></select>
            <?= field_error($decision_errors, 'product_id') ?></div>
          <div><label for="decision_type">Decision</label>
            <select id="decision_type" name="decision_type"><?= options_html($types, $df['decision_type'] ?? '') ?></select>
            <?= field_error($decision_errors, 'decision_type') ?></div>
          <div><label for="approved_amount">Approved amount (<?= e($c) ?>)</label>
            <input id="approved_amount" name="approved_amount" inputmode="decimal" value="<?= e($df['approved_amount'] ?? '') ?>"<?= isset($decision_errors['approved_amount']) ? ' aria-invalid="true"' : '' ?>>
            <p class="hint">Requested: <?= $m(((int) $app['amount']) * 100) ?></p><?= field_error($decision_errors, 'approved_amount') ?></div>
          <div><label for="term_months">Term (months)</label>
            <input id="term_months" name="term_months" inputmode="numeric" value="<?= e($df['term_months'] ?? ($app['term_months'] ?? '')) ?>"><?= field_error($decision_errors, 'term_months') ?></div>
          <div class="full"><label for="conditions">Conditions (one per line; shown to the customer)</label>
            <textarea id="conditions" name="conditions" rows="3" maxlength="3000"><?= e($df['conditions_text'] ?? '') ?></textarea><?= field_error($decision_errors, 'conditions') ?></div>
          <div class="full"><label for="reason">Basis for the decision (internal record)</label>
            <textarea id="reason" name="reason" rows="2" maxlength="2000"><?= e($df['reason'] ?? '') ?></textarea><?= field_error($decision_errors, 'reason') ?></div>
          <div class="full"><label for="internal_note">Internal note (optional)</label><textarea id="internal_note" name="internal_note" rows="2" maxlength="2000"><?= e($df['internal_note'] ?? '') ?></textarea></div>
          <p class="hint full">Approvals of <?= $m($threshold) ?> or more need a second authorised person to issue them. Interest and fees come from Financial settings and are frozen on the decision.</p>
          <div class="full inline-actions"><button class="btn btn-outline btn-sm" type="submit" name="action" value="preview">Preview calculation</button>
            <button class="btn btn-primary btn-sm" type="submit" name="action" value="propose">Propose decision</button></div>
        </form>
        <?php if ($preview): ?><div class="terms-card"><h3 class="small-h">Preview (not saved)</h3><?= breakdown_html($preview) ?></div><?php endif ?>
      </details>
      <?php endif ?>

      <?php $older = array_values(array_filter($decisions, fn($d) => $d['status'] !== 'proposed')); if (count($older) > 1 || ($older && !$decision)): ?>
      <details class="inline-details"><summary>Decision history (<?= count($older) ?>)</summary>
        <ul class="plain-list"><?php foreach ($older as $d): ?><li>Rev <?= (int) $d['revision'] ?> · <?= e($d['status']) ?> · <?= $m($d['approved_minor'], $d['currency']) ?><?= $d['previous_approved_minor'] ? ' (was ' . $m($d['previous_approved_minor'], $d['currency']) . ')' : '' ?> · proposed by <?= e($d['proposer']) ?> <?= e(fmt_date($d['proposed_at'])) ?><?= $d['issuer'] ? ' · issued by ' . e($d['issuer']) . ' ' . e(fmt_date($d['issued_at'])) : '' ?></li><?php endforeach ?></ul>
      </details>
      <?php endif ?>
    </section>

    <section class="panel" id="letters">
      <h2>Agreements and letters</h2>
      <?php if (!$settings['agreement_template_approved']): ?><p class="notice small">Your organisation has not confirmed that its agreement template is legally approved, so agreements can be drafted for review but not issued.</p><?php endif ?>
      <ul class="doc-list">
        <?php foreach ($generated as $gd): ?>
          <li><span><?php if (can('view_documents')): ?><a href="<?= e(url("/admin/letters/{$gd['id']}")) ?>"><?= e($gd['title']) ?></a><?php else: ?><?= e($gd['title']) ?><?php endif ?>
            <small class="block">Generated by <?= e($gd['generated_by_name']) ?> <?= e(fmt_date($gd['generated_at'])) ?><?= $gd['issued_at'] ? ' · issued by ' . e($gd['issued_by_name']) . ' ' . e(fmt_date($gd['issued_at'])) : '' ?></small></span>
            <span class="doc-actions"><span class="tag-sm tag-<?= $gd['status'] === 'issued' ? 'verified' : ($gd['status'] === 'draft' ? 'submitted' : 'rejected') ?>"><?= e(ucfirst($gd['status'])) ?></span>
            <?php if ($gd['status'] === 'draft' && can('issue_documents') && ($gd['kind'] !== 'agreement' || $settings['agreement_template_approved'])): ?>
              <form method="post" action="<?= e(url("/admin/letters/{$gd['id']}/issue")) ?>" class="mini-form"><?= csrf_field() ?>
                <label class="check"><input type="checkbox" name="confirm" value="1"> Checked</label>
                <button class="btn btn-primary btn-sm" type="submit">Issue to customer</button></form>
            <?php endif ?></span></li>
        <?php endforeach ?>
        <?php if (!$generated): ?><li class="muted">None yet.</li><?php endif ?>
      </ul>
      <?php if (can('generate_documents')): ?>
      <form method="post" action="<?= e(url("/admin/applications/$id/letters")) ?>" class="form">
        <?= csrf_field() ?>
        <div><label for="kind">Prepare a draft</label>
          <select id="kind" name="kind"><?= options_html(array_intersect_key(LETTER_KINDS, array_flip(STAFF_WRITTEN))) ?></select></div>
        <div><label for="letter-body">Text (information requests and decline letters)</label><textarea id="letter-body" name="body" rows="3" maxlength="4000"></textarea></div>
        <div><button class="btn btn-dark btn-sm" type="submit">Generate draft PDF</button></div>
      </form>
      <?php endif ?>
    </section>

    <section class="panel" id="payments">
      <h2>Disbursements</h2>
      <p class="muted small">Records of real payments only. Schedule, then mark as paid with the bank reference once it has left the bank, then a different authorised person confirms it. Only confirmed payments count as disbursed.</p>
      <?php foreach ($payments as $p): ?>
        <div class="terms-card <?= e($p['status']) ?>">
          <p><span class="tag-sm tag-<?= e(['confirmed' => 'verified', 'pending' => 'under_review', 'scheduled' => 'submitted'][$p['status']] ?? 'rejected') ?>"><?= e(ucfirst($p['status'])) ?></span>
            <?= $m($p['amount_pence'], $p['currency']) ?><?= $p['fees_deducted_minor'] ? ' (customer receives ' . $m($p['amount_pence'] - $p['fees_deducted_minor'], $p['currency']) . ' after fees)' : '' ?>
            <?= $p['scheduled_for'] ? ' · planned ' . e($p['scheduled_for']) : '' ?><?= $p['paid_on'] ? ' · paid ' . e($p['paid_on']) . ' by ' . e(PAYMENT_METHODS[$p['method']] ?? $p['method']) . ', ref ' . e($p['payment_reference']) : '' ?></p>
          <p class="small muted">Recorded by <?= e($p['recorded_name']) ?><?= $p['confirmed_name'] ? ' · confirmed by ' . e($p['confirmed_name']) . ' ' . e(fmt_date($p['confirmed_at'])) . ' (' . e(CONFIRMATION_SOURCES[$p['confirmation_source']] ?? '') . ')' : '' ?><?= $p['reversal_reason'] ? ' · ' . e($p['reversal_reason']) : '' ?></p>
          <?php if ($p['status'] === 'scheduled' && can('record_disbursement')): ?>
            <form method="post" action="<?= e(url("/admin/disbursements/{$p['id']}/paid")) ?>" class="mini-form"><?= csrf_field() ?>
              <label class="sr" for="po<?= (int) $p['id'] ?>">Date paid</label><input id="po<?= (int) $p['id'] ?>" name="paid_on" type="date" required>
              <label class="sr" for="pm<?= (int) $p['id'] ?>">Method</label><select id="pm<?= (int) $p['id'] ?>" name="method"><?= options_html(PAYMENT_METHODS) ?></select>
              <label class="sr" for="pr<?= (int) $p['id'] ?>">Bank reference</label><input id="pr<?= (int) $p['id'] ?>" name="payment_reference" placeholder="Bank reference" maxlength="120" required>
              <button class="btn btn-dark btn-sm" type="submit">Record as paid</button></form>
            <form method="post" action="<?= e(url("/admin/disbursements/{$p['id']}/cancel")) ?>" class="mini-form"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Cancel</button></form>
          <?php elseif ($p['status'] === 'pending' && can('confirm_disbursement')): ?>
            <?php if ((int) $p['recorded_by'] !== $me): ?>
            <form method="post" action="<?= e(url("/admin/disbursements/{$p['id']}/confirm")) ?>" class="mini-form"><?= csrf_field() ?>
              <select name="confirmation_source" aria-label="How was it checked"><?= options_html(CONFIRMATION_SOURCES) ?></select>
              <label class="check"><input type="checkbox" name="bank_checked" value="1"> I have checked this payment</label>
              <button class="btn btn-primary btn-sm" type="submit">Confirm payment</button></form>
            <form method="post" action="<?= e(url("/admin/disbursements/{$p['id']}/reject")) ?>" class="mini-form"><?= csrf_field() ?><input name="reason" placeholder="Reason" aria-label="Reason"><button class="btn btn-danger btn-sm" type="submit">Reject record</button></form>
            <?php else: ?><p class="muted small">Waiting for a different authorised person to confirm.</p><?php endif ?>
          <?php elseif ($p['status'] === 'confirmed' && can('record_disbursement')): ?>
            <details><summary class="small">Reverse this payment record</summary>
              <form method="post" action="<?= e(url("/admin/disbursements/{$p['id']}/reverse")) ?>" class="mini-form"><?= csrf_field() ?>
                <input name="reason" placeholder="Reason" aria-label="Reason" required><label class="check"><input type="checkbox" name="confirm" value="1"> Confirm reversal</label>
                <button class="btn btn-danger btn-sm" type="submit">Reverse</button></form></details>
          <?php endif ?>
        </div>
      <?php endforeach ?>
      <?php if (can('record_disbursement') && in_array($app['status'], ['agreement_signed', 'funding_scheduled', 'partially_disbursed'], true)): ?>
      <form method="post" action="<?= e(url("/admin/applications/$id/disbursements")) ?>" class="form grid-fields">
        <?= csrf_field() ?>
        <div><label for="d_amount">Amount to release (<?= e($c) ?>)</label><input id="d_amount" name="amount" inputmode="decimal" required>
          <p class="hint">Approved <?= $m($summary['approved'] ?? 0) ?>; already scheduled or paid <?= $m($summary['scheduled'] + $summary['disbursed']) ?>.</p></div>
        <div><label for="d_date">Planned payment date</label><input id="d_date" name="scheduled_for" type="date" required></div>
        <div class="full"><label for="d_note">Note</label><input id="d_note" name="note" maxlength="500"></div>
        <div><button class="btn btn-dark btn-sm" type="submit">Schedule payment</button></div>
      </form>
      <?php elseif (!$payments): ?><p class="muted small">Available once the agreement has been signed.</p><?php endif ?>
    </section>

    <section class="panel" id="repayments">
      <h2>Repayments</h2>
      <?php if ($position): ?>
        <div class="kpis">
          <div><span>Outstanding principal</span><strong><?= $m($position['outstanding_principal']) ?></strong></div>
          <div><span>Repaid</span><strong><?= $m($position['repaid']) ?></strong></div>
          <div><span>Overdue</span><strong><?= $m($position['overdue_minor']) ?></strong></div>
          <div><span>Next due</span><strong><?= e($position['next_due']['due_date'] ?? 'None') ?></strong></div>
        </div>
        <div class="table-wrap"><table class="data compact">
          <thead><tr><th>No.</th><th>Due</th><th>Amount</th><th>Paid</th><th>State</th><th></th></tr></thead>
          <tbody><?php foreach ($position['instalments'] as $i): ?><tr><td><?= (int) $i['number'] ?></td><td><?= e($i['due_date']) ?></td><td><?= $m($i['amount_minor']) ?></td><td><?= $m($i['paid_minor']) ?></td>
            <td><span class="tag-sm tag-inst-<?= e($i['state']) ?>"><?= e(ucfirst(str_replace('_', ' ', $i['state']))) ?></span></td>
            <td><?php if ($i['state'] === 'overdue' && can('manage_fees')): ?><form method="post" action="<?= e(url("/admin/instalments/{$i['id']}/late-charge")) ?>" class="inline" data-confirm="Apply the configured late charge to instalment <?= (int) $i['number'] ?>?"><?= csrf_field() ?><button class="link-btn" type="submit">Apply late charge</button></form><?php endif ?></td></tr><?php endforeach ?></tbody>
        </table></div>
        <?php if (can('manage_repayments')): ?>
        <form method="post" action="<?= e(url("/admin/applications/$id/repayments")) ?>" class="form grid-fields spaced" data-once>
          <?= csrf_field() ?>
          <div><label for="r_amount">Amount received (<?= e($c) ?>)</label><input id="r_amount" name="amount" inputmode="decimal" required></div>
          <div><label for="r_date">Date received</label><input id="r_date" name="received_on" type="date" required></div>
          <div><label for="r_method">Method</label><select id="r_method" name="method"><?= options_html(PAYMENT_METHODS) ?></select></div>
          <div><label for="r_ref">Payment reference (unique)</label><input id="r_ref" name="provider_reference" maxlength="120" required></div>
          <div><label for="r_src">Confirmed by</label><select id="r_src" name="confirmation_source"><?= options_html(CONFIRMATION_SOURCES) ?></select></div>
          <div><label for="r_note">Note</label><input id="r_note" name="note" maxlength="500"></div>
          <div><button class="btn btn-dark btn-sm" type="submit">Record repayment</button></div>
        </form>
        <?php endif ?>
      <?php elseif (can('manage_repayments') && $summary['disbursed']): ?>
        <form method="post" action="<?= e(url("/admin/applications/$id/schedule")) ?>" class="form inline-form">
          <?= csrf_field() ?>
          <div><label for="first_due">First payment due</label><input id="first_due" name="first_due_date" type="date" required></div>
          <div><button class="btn btn-dark btn-sm" type="submit">Create repayment schedule</button></div>
        </form>
        <p class="hint">Uses the frozen terms of the issued decision and the principal actually paid out (<?= $m($summary['disbursed']) ?>).</p>
      <?php else: ?><p class="muted small">A schedule can be created after a payment to the customer is confirmed.</p><?php endif ?>
      <?php if ($repayments): ?>
      <h3 class="small-h">Payments received</h3>
      <ul class="doc-list"><?php foreach ($repayments as $r): ?>
        <li><span><?= $m($r['amount_minor'], $r['currency']) ?> on <?= e($r['received_on']) ?> · <?= e(PAYMENT_METHODS[$r['method']] ?? $r['method']) ?> · ref <?= e($r['provider_reference']) ?><small class="block">Recorded by <?= e($r['full_name']) ?><?= $r['reversal_reason'] ? ' · reversed: ' . e($r['reversal_reason']) : '' ?></small></span>
          <span class="doc-actions"><span class="tag-sm tag-<?= $r['status'] === 'confirmed' ? 'verified' : 'rejected' ?>"><?= e(ucfirst($r['status'])) ?></span>
          <?php if ($r['status'] === 'confirmed' && can('manage_repayments')): ?><details><summary class="small">Reverse</summary><form method="post" action="<?= e(url("/admin/repayments/{$r['id']}/reverse")) ?>" class="mini-form"><?= csrf_field() ?><input name="reason" placeholder="Reason" aria-label="Reason" required><label class="check"><input type="checkbox" name="confirm" value="1"> Confirm</label><button class="btn btn-danger btn-sm" type="submit">Reverse</button></form></details><?php endif ?></span></li>
      <?php endforeach ?></ul>
      <?php endif ?>
    </section>

    <section class="panel" id="fees">
      <h2>Fees</h2>
      <?php if ($fees): ?><ul class="doc-list">
      <?php foreach ($fees as $f): ?>
        <li><span><strong><?= e($f['name']) ?></strong> <?= $m($f['amount_minor'], $f['currency']) ?>
          <small class="block"><?= $f['percent'] ? e($f['percent']) . '% of ' . $m($f['basis_minor'], $f['currency']) : 'Fixed' ?> · <?= e($f['payable_when']) ?><?= $f['status_reason'] ? ' · ' . e($f['status_reason']) : '' ?><?= $f['payment_reference'] ? ' · ref ' . e($f['payment_reference']) : '' ?></small></span>
          <span class="doc-actions"><?= fee_badge($f['status']) ?>
          <?php if (can('manage_fees') && in_array($f['status'], ['pending', 'paid'], true)): ?><details><summary class="small">Update</summary>
            <form method="post" action="<?= e(url("/admin/fees/{$f['id']}/" . ($f['status'] === 'pending' ? 'paid' : 'refund'))) ?>" class="mini-form"><?= csrf_field() ?>
              <?php if ($f['status'] === 'pending' && !in_array($f['timing'], ['deducted_from_disbursement', 'added_to_loan', 'with_first_instalment'], true)): ?>
                <input name="payment_reference" placeholder="Payment reference" aria-label="Payment reference"><select name="confirmation_source" aria-label="Confirmed by"><?= options_html(CONFIRMATION_SOURCES) ?></select><button class="btn btn-outline btn-sm" type="submit">Record payment</button>
              <?php elseif ($f['status'] === 'paid'): ?><input name="payment_reference" placeholder="Refund reference" aria-label="Refund reference"><input name="reason" placeholder="Reason" aria-label="Reason"><button class="btn btn-outline btn-sm" type="submit">Record refund</button><?php endif ?>
            </form>
            <?php if ($f['status'] === 'pending'): ?><form method="post" action="<?= e(url("/admin/fees/{$f['id']}/waive")) ?>" class="mini-form"><?= csrf_field() ?><input name="reason" placeholder="Reason" aria-label="Reason"><button class="btn btn-outline btn-sm" type="submit">Waive</button></form>
            <form method="post" action="<?= e(url("/admin/fees/{$f['id']}/cancel")) ?>" class="mini-form"><?= csrf_field() ?><input name="reason" placeholder="Reason" aria-label="Reason"><button class="btn btn-danger btn-sm" type="submit">Cancel</button></form><?php endif ?>
          </details><?php endif ?></span></li>
      <?php endforeach ?></ul>
      <?php else: ?><p class="muted small">Fee records are created from the issued decision when the agreement is signed.</p><?php endif ?>
    </section>

    <section class="panel" id="notes">
      <h2>Messages and internal notes</h2>
      <div class="thread">
        <?php foreach ($notes as $n): ?>
          <article class="msg <?= $n['role'] !== 'client' ? 'mine' : '' ?> <?= !$n['visible_to_client'] ? 'internal' : '' ?>">
            <header><strong><?= e($n['full_name']) ?><?= !$n['visible_to_client'] ? ' · internal note' : '' ?></strong><span><?= e(fmt_date($n['created_at'])) ?></span></header>
            <p><?= e($n['body']) ?></p>
          </article>
        <?php endforeach ?>
        <?php if (!$notes): ?><p class="muted">No messages yet.</p><?php endif ?>
      </div>
      <?php if (can('message_client') || can('internal_note')): ?>
      <form method="post" action="<?= e(url("/admin/applications/$id/notes")) ?>" class="form" data-once>
        <?= csrf_field() ?>
        <div><label for="body">Write a message or note</label><textarea id="body" name="body" rows="3" required maxlength="4000"></textarea></div>
        <?php if (can('message_client')): ?><label class="check"><input type="checkbox" name="visible_to_client" value="1" checked> Send to the customer (untick for an internal note only staff can see)</label><?php endif ?>
        <div><button class="btn btn-primary btn-sm" type="submit">Save</button></div>
      </form>
      <?php endif ?>
    </section>
  </div>

  <div>
    <section class="panel">
      <h2>Financing summary</h2>
      <?= financing_html($summary) ?>
    </section>

    <?php if (can('change_status')): ?>
    <section class="panel">
      <h2>Update status</h2>
      <?php if ($allowed): ?>
      <form method="post" action="<?= e(url("/admin/applications/$id/status")) ?>" class="form">
        <?= csrf_field() ?>
        <div><label for="status">New status</label>
          <select id="status" name="status">
            <option value="<?= e($app['status']) ?>">No change (<?= e(STATUSES[$app['status']]) ?>)</option>
            <?php foreach ($allowed as $key): ?><option value="<?= e($key) ?>"><?= e(STATUSES[$key]) ?></option><?php endforeach ?>
          </select></div>
        <div><label for="reason2">Reason / decision record (internal)</label>
          <textarea id="reason2" name="reason" rows="2" maxlength="2000" aria-describedby="reason-hint"></textarea>
          <p class="hint" id="reason-hint">Required for: <?= e(implode(', ', array_map(fn($k) => STATUSES[$k], NOTE_REQUIRED))) ?>.</p></div>
        <div><label for="message">Message to the customer (optional)</label>
          <textarea id="message" name="message" rows="3" maxlength="4000"></textarea></div>
        <div><button class="btn btn-primary btn-sm" type="submit">Update</button></div>
      </form>
      <p class="hint spaced">Approvals are made under "Approval decision"; disbursed statuses follow confirmed payments.</p>
      <?php else: ?><p class="muted">No manual status changes are available from <?= e(STATUSES[$app['status']]) ?><?= $app['status'] === 'declined' ? ' (only an administrator can reopen)' : '' ?>.</p><?php endif ?>
    </section>
    <section class="panel">
      <h2>Assigned to</h2>
      <form method="post" action="<?= e(url("/admin/applications/$id/assign")) ?>" class="form inline-form">
        <?= csrf_field() ?>
        <div><label class="sr" for="assigned_to">Staff member</label>
          <select id="assigned_to" name="assigned_to"><option value="">Unassigned</option><?php foreach ($staff_members as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) $app['assigned_to'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach ?></select></div>
        <div><button class="btn btn-outline btn-sm" type="submit">Save</button></div>
      </form>
    </section>
    <?php endif ?>

    <section class="panel">
      <h2>Customer</h2>
      <dl class="facts">
        <dt>Name</dt><dd><?= e($app['full_name']) ?></dd>
        <dt>Email</dt><dd><a href="mailto:<?= e($app['email']) ?>"><?= e($app['email']) ?></a><?= !$app['email_verified_at'] ? ' <span class="tag-sm tag-submitted">unverified</span>' : '' ?></dd>
        <dt>Phone</dt><dd><?= $app['phone'] ? '<a href="tel:' . e($app['phone']) . '">' . e($app['phone']) . '</a>' : 'Not given' ?></dd>
      </dl>
    </section>

    <section class="panel">
      <h2>History</h2>
      <ol class="timeline">
        <?php foreach ($history as $h): ?>
          <li class="<?= in_array($h['status'], ['declined', 'withdrawn', 'cancelled'], true) ? 'bad' : 'done' ?>"><span class="dot"></span><div><strong><?= e(STATUSES[$h['status']] ?? $h['status']) ?></strong>
            <small><?= e(fmt_date($h['created_at'])) ?> · <?= e($h['actor_name'] ?: 'system') ?><?= $h['actor_role'] === 'client' ? ' (customer)' : '' ?></small>
            <?php if ($h['note']): ?><small class="block pre"><?= e($h['note']) ?></small><?php endif ?></div></li>
        <?php endforeach ?>
      </ol>
    </section>

    <?php if ($trail): ?>
    <section class="panel">
      <h2>Audit trail</h2>
      <ul class="audit-mini">
        <?php foreach ($trail as $l): ?><li><code><?= e($l['event']) ?></code> <span class="muted small"><?= e(fmt_date($l['created_at'])) ?> · <?= e($l['full_name'] ?: 'anonymous') ?></span></li><?php endforeach ?>
      </ul>
    </section>
    <?php endif ?>
  </div>
</div>
