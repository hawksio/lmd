<?php
$title = ref_str((int) $app['id']);
$c = $app['currency'];
$me = (int) current_user()['id'];
?>
<a class="crumb" href="<?= e(url('/account/applications')) ?>">My applications</a>
<div class="page-head">
  <div>
    <h1><?= e($app['company_name'] ?: 'Your application') ?></h1>
    <p><?= e(ref_str((int) $app['id'])) ?> · <?= e($product['name'] ?? (FINANCE_TYPES[$app['finance_type']] ?? '')) ?><?= $app['amount'] ? ' · ' . e(money($app['amount'] * 100, $c)) . ' requested' : '' ?><?= $app['term_months'] ? ' over ' . e($app['term_months']) . ' months' : '' ?><?= $app['submitted_at'] ? ' · Submitted ' . e(fmt_date($app['submitted_at'])) : '' ?></p>
  </div>
  <?= badge($app['status']) ?>
</div>

<?php if ($app['status'] === 'draft'): ?>
<p class="notice">This is still a draft. <a href="<?= e(url("/account/applications/{$app['id']}/edit")) ?>">Continue and submit it</a> when you're ready.</p>
<?php elseif (in_array($app['status'], ['documents_required', 'info_requested'], true)): ?>
<p class="notice">We need more information. Please check the documents and messages below.</p>
<?php elseif (in_array($app['status'], APPROVED, true)): ?>
<p class="notice">A decision has been made. <a href="<?= e(url("/account/applications/{$app['id']}/offer")) ?>">Review the offer, its costs and conditions</a>. This is not an agreement and no money has been paid.</p>
<?php elseif ($app['status'] === 'agreement_issued'): ?>
<p class="notice">Your agreement is ready under "Letters and agreements". Read it carefully and ask us any questions before signing.</p>
<?php elseif ($app['status'] === 'funding_scheduled'): ?>
<p class="notice">A payment is being arranged. It has not been made yet; this page will show it once it has actually been paid.</p>
<?php endif ?>

<div class="layout-2">
  <div>
    <?php if ($summary['approved']): ?>
    <section class="panel" id="financing">
      <h2>Your financing</h2>
      <?= financing_html($summary) ?>
      <?php if ($summary['decision']): ?><p class="small"><a href="<?= e(url("/account/applications/{$app['id']}/offer")) ?>">See the full breakdown and repayment schedule</a></p><?php endif ?>
    </section>
    <?php endif ?>

    <section class="panel" id="documents">
      <h2>Documents</h2>
      <?php if ($requirements): ?>
      <h3 class="small-h">What we need</h3>
      <ul class="doc-list">
        <?php foreach ($requirements as $r): ?>
          <li><span><strong><?= e($r['label']) ?></strong><?php if ($r['request_note']): ?><small class="block"><?= e($r['request_note']) ?></small><?php endif ?>
            <?php if ($r['document'] && $r['document']['customer_note'] && in_array($r['status'], DOC_REPLACEABLE, true)): ?><small class="block warn"><?= e($r['document']['customer_note']) ?></small><?php endif ?></span>
            <?= review_badge($r['status']) ?></li>
        <?php endforeach ?>
      </ul>
      <?php endif ?>
      <h3 class="small-h">Your uploads</h3>
      <ul class="doc-list">
        <?php foreach ($docs as $d): ?>
          <li><span><a href="<?= e(url("/account/documents/{$d['id']}")) ?>"><?= e($d['original_name']) ?></a>
            <small class="block"><?= e(DOC_TYPES[$d['doc_type']] ?? 'Other') ?> · <?= (int) round($d['size_bytes'] / 1024) ?> KB · <?= e(fmt_date($d['created_at'])) ?><?= $d['uploader_role'] !== 'client' ? ' · added by us' : '' ?></small>
            <?php if ($d['customer_note'] && in_array($d['review_status'], DOC_REPLACEABLE, true)): ?><small class="block warn"><?= e($d['customer_note']) ?></small><?php endif ?></span>
            <span class="doc-actions"><?= review_badge($d['review_status']) ?>
            <?php if (in_array($d['review_status'], DOC_REPLACEABLE, true) && !$final): ?>
              <form method="post" action="<?= e(url("/account/applications/{$app['id']}/documents")) ?>" enctype="multipart/form-data" class="mini-form" data-once>
                <?= csrf_field() ?><input type="hidden" name="replaces" value="<?= (int) $d['id'] ?>">
                <label class="sr" for="rep<?= (int) $d['id'] ?>">Replacement for <?= e($d['original_name']) ?></label>
                <input id="rep<?= (int) $d['id'] ?>" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png" required>
                <button class="btn btn-dark btn-sm" type="submit">Upload replacement</button></form>
            <?php endif ?></span></li>
        <?php endforeach ?>
        <?php if (!$docs): ?><li class="muted">Nothing uploaded yet.</li><?php endif ?>
      </ul>
      <?php if (!$final): ?>
      <form method="post" action="<?= e(url("/account/applications/{$app['id']}/documents")) ?>" enctype="multipart/form-data" class="upload" data-once>
        <?= csrf_field() ?>
        <div><label for="doc_type">Type of document</label><select id="doc_type" name="doc_type"><?= options_html(DOC_TYPES) ?></select></div>
        <div><label for="document">File (PDF, JPG or PNG, up to <?= e(config('max_upload_mb')) ?> MB)</label>
          <input id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required></div>
        <button class="btn btn-dark btn-sm" type="submit">Upload</button>
      </form>
      <p class="hint">Files are checked, stored privately and only seen by you and authorised staff. Every staff view is recorded.</p>
      <?php endif ?>
    </section>

    <?php if ($position): ?>
    <section class="panel" id="repayments">
      <h2>Repayment schedule</h2>
      <div class="table-wrap"><table class="data compact">
        <thead><tr><th>No.</th><th>Due</th><th>Amount</th><th>Paid</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($position['instalments'] as $i): ?><tr><td><?= (int) $i['number'] ?></td><td><?= e($i['due_date']) ?></td><td><?= e(money($i['amount_minor'], $c)) ?></td><td><?= e(money($i['paid_minor'], $c)) ?></td>
          <td><span class="tag-sm tag-inst-<?= e($i['state']) ?>"><?= e(ucfirst(str_replace('_', ' ', $i['state']))) ?></span></td></tr><?php endforeach ?></tbody>
      </table></div>
      <?php if ($repayments): ?><h3 class="small-h">Payments we've received</h3>
      <ul class="doc-list"><?php foreach ($repayments as $r): ?><li><span><?= e(money($r['amount_minor'], $r['currency'])) ?> on <?= e($r['received_on']) ?><small class="block">Reference <?= e($r['provider_reference']) ?><?= $r['status'] === 'reversed' ? ' · reversed: ' . e($r['reversal_reason']) : '' ?></small></span>
        <span class="doc-actions"><span class="tag-sm tag-<?= $r['status'] === 'confirmed' ? 'verified' : 'rejected' ?>"><?= $r['status'] === 'confirmed' ? 'Received' : 'Reversed' ?></span><?php if ($r['status'] === 'confirmed' && isset($receipts[(int) $r['id']])): ?><a class="btn btn-outline btn-sm" href="<?= e(url('/account/letters/' . $receipts[(int) $r['id']])) ?>">Receipt</a><?php endif ?></span></li><?php endforeach ?></ul><?php endif ?>
    </section>
    <?php endif ?>

    <?php if ($fees): ?>
    <section class="panel" id="fees">
      <h2>Fees</h2>
      <ul class="doc-list"><?php foreach ($fees as $f): ?>
        <li><span><strong><?= e($f['name']) ?></strong> <?= e(money($f['amount_minor'], $f['currency'])) ?>
          <small class="block"><?= $f['percent'] ? e($f['percent']) . '% of ' . e(money($f['basis_minor'], $f['currency'])) . '. ' : '' ?><?= e($f['payable_when']) ?>.</small>
          <?php if ($f['disclosure']): ?><small class="block muted"><?= e($f['disclosure']) ?></small><?php endif ?></span><?= fee_badge($f['status']) ?></li>
      <?php endforeach ?></ul>
      <p class="hint">Fees are shown as paid only once a payment has been confirmed. No fee is ever required to receive a decision or funds.</p>
    </section>
    <?php endif ?>

    <section class="panel" id="messages">
      <h2>Messages with the team</h2>
      <div class="thread">
        <?php foreach ($notes as $n): ?>
          <article class="msg <?= (int) $n['author_id'] === $me ? 'mine' : '' ?>">
            <header><strong><?= (int) $n['author_id'] === $me ? 'You' : e(current_org()['name']) ?></strong><span><?= e(fmt_date($n['created_at'])) ?></span></header>
            <p><?= e($n['body']) ?></p>
          </article>
        <?php endforeach ?>
        <?php if (!$notes): ?><p class="muted">No messages yet. The team will message you here, or you can ask a question below.</p><?php endif ?>
      </div>
      <?php if (!$final): ?>
      <form method="post" action="<?= e(url("/account/applications/{$app['id']}/messages")) ?>" class="form" data-once>
        <?= csrf_field() ?>
        <div>
          <label for="body">Send a message</label>
          <textarea id="body" name="body" rows="3" required maxlength="4000" aria-describedby="body-hint"></textarea>
          <p class="hint" id="body-hint">Please don't send passwords or full card details.</p>
        </div>
        <div><button class="btn btn-primary btn-sm" type="submit">Send</button></div>
      </form>
      <?php endif ?>
    </section>

    <?php if ($letters): ?>
    <section class="panel" id="letters">
      <h2>Letters and agreements</h2>
      <ul class="doc-list">
        <?php foreach ($letters as $l): ?>
          <li><span><a href="<?= e(url("/account/letters/{$l['id']}")) ?>"><?= e($l['title']) ?></a><small class="block">Issued <?= e(fmt_date($l['issued_at'])) ?></small></span><span class="tag-sm">PDF</span></li>
        <?php endforeach ?>
      </ul>
      <p class="muted small">An issued agreement is not signed. Nothing is binding until both parties have signed through the process we explain to you.</p>
    </section>
    <?php endif ?>
  </div>

  <div>
    <section class="panel">
      <h2>Progress</h2>
      <?= timeline($app, $history) ?>
    </section>

    <section class="panel">
      <h2>Your answers</h2>
      <dl class="facts">
        <dt>Business type</dt><dd><?= e(BUSINESS_TYPES[$app['business_type']] ?? '') ?></dd>
        <dt>Registration number</dt><dd><?= e($app['company_number'] ?: 'Not given') ?></dd>
        <dt>Industry</dt><dd><?= e($app['industry']) ?></dd>
        <dt>Time trading</dt><dd><?= e(YEARS_TRADING[$app['years_trading']] ?? '') ?></dd>
        <dt>Turnover</dt><dd><?= e(money_whole($app['annual_turnover'] ?? 0, $c)) ?></dd>
        <dt>Purpose</dt><dd class="pre"><?= e($app['purpose']) ?></dd>
      </dl>
      <?php if ($can_withdraw): ?>
      <form method="post" action="<?= e(url("/account/applications/{$app['id']}/withdraw")) ?>" class="spaced" data-confirm="Withdraw this application? The team will stop working on it.">
        <?= csrf_field() ?>
        <button class="btn btn-danger btn-sm" type="submit">Withdraw application</button>
      </form>
      <?php endif ?>
    </section>
  </div>
</div>
