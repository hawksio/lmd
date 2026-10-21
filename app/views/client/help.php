<?php $title = 'Help and contact'; $s = site(); ?>
<div class="page-head"><div><h1>Help and contact</h1><p>Answers to common questions, and how to reach the team.</p></div></div>
<div class="layout-2">
  <div>
    <section class="panel" id="assistant">
      <h2>Ask a question</h2>
      <?php if ($ai_enabled): ?>
        <p class="muted small">Answers come from an <strong>AI assistant</strong> using only our published FAQs. It can't see your application, can't make decisions and may be wrong. For anything about your application, message the team.</p>
      <?php else: ?>
        <p class="muted small">Search our FAQs.</p>
      <?php endif ?>
      <form class="form inline-form" data-assistant data-url="<?= e(url('/account/help/ask')) ?>">
        <?= csrf_field() ?>
        <div><label for="question">Your question</label><input id="question" name="question" maxlength="500" required></div>
        <div><button class="btn btn-primary btn-sm" type="submit">Ask</button></div>
      </form>
      <div class="assistant-answer" data-answer aria-live="polite"></div>
    </section>
    <section class="panel">
      <h2>Reading your financing summary</h2>
      <p class="tag-sm">Illustrative example only. Not a real customer or transaction.</p>
      <dl class="facts finance-facts example">
        <dt>Financing requested</dt><dd>€50,000</dd>
        <dt>Financing approved</dt><dd>€30,000 <span class="tag-sm">€20,000 not approved</span></dd>
        <dt>Approval decision</dt><dd>Partially approved</dd>
        <dt>Conditions outstanding</dt><dd>Supporting business document pending</dd>
        <dt>Agreement</dt><dd>Awaiting signature</dd>
        <dt>Funds actually disbursed</dt><dd>€0</dd>
      </dl>
      <p class="small">"Approved" is a decision, not money. Funds are paid only after the agreement is signed and conditions are met, and appear as "disbursed" once the payment has actually been made.</p>
    </section>
    <section class="panel">
      <h2>Frequently asked questions</h2>
      <div class="faq-list">
        <?php foreach ($s['faqs'] as $f): ?>
          <details class="faq"><summary><?= e($f['q']) ?></summary><p><?= e($f['a']) ?></p></details>
        <?php endforeach ?>
      </div>
    </section>
  </div>
  <section class="panel">
    <h2>Contact us</h2>
    <dl class="facts">
      <dt>About an application</dt><dd>Message the team from the application's page.</dd>
      <dt>Phone</dt><dd><?= e($s->show('phone')) ?></dd>
      <dt>Email</dt><dd><?= e($s->show('email')) ?></dd>
      <dt>Hours</dt><dd><?= e($s->show('opening_hours')) ?></dd>
    </dl>
    <?php if (owns_static_site()): ?><p class="small spaced"><a href="/complaints.html">Complaints</a> · <a href="/privacy.html">Privacy notice</a></p><?php endif ?>
    <p class="notice small">We will never ask for your password, your two-step verification codes or your full card details.</p>
  </section>
</div>
