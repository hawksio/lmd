<?php $title = $campaign ? 'Edit campaign' : 'New campaign'; ?>
<a class="crumb" href="<?= e(url('/admin/marketing')) ?>">Marketing</a>
<div class="page-head"><div><h1><?= $campaign ? 'Edit campaign' : 'New campaign' ?></h1><p>Write in plain text. It is placed into the branded template with the unsubscribe link and company details.</p></div></div>
<form method="post" class="panel form" novalidate>
  <?= csrf_field() ?>
  <?= field_input('name', 'Internal name', $f, $errors, ['required' => true, 'maxlength' => 120]) ?>
  <?= field_input('subject', 'Subject line', $f, $errors, ['required' => true, 'maxlength' => 150]) ?>
  <?= field_input('preheader', 'Preview text (optional)', $f, $errors, ['maxlength' => 200]) ?>
  <?= field_select('segment', 'Audience', SEGMENTS, $f, $errors, ['required' => true]) ?>
  <?= field_textarea('body', 'Message', $f, $errors, ['required' => true, 'rows' => 12, 'maxlength' => 8000,
      'hint' => "Blank lines start new paragraphs. Don't include rates, repayment examples or offers unless compliance has approved them as a financial promotion."]) ?>
  <div><button class="btn btn-primary" type="submit">Save draft</button></div>
</form>
