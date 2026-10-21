<?php $title = 'Platform limits'; ?>
<div class="page-head"><div><h1>Platform limits</h1><p>The highest percentage any organisation can configure for each kind of setting. Organisations can set lower limits for themselves.</p></div></div>
<p class="notice small">These are technical ceilings, not legal limits. Lawful maximums depend on each organisation's jurisdiction and products, and remain their responsibility.</p>
<form method="post" class="panel form narrow-form">
  <?= csrf_field() ?>
  <?php foreach (RULE_KINDS as $k => $v): ?><div><label for="<?= e($k) ?>"><?= e($v) ?> (max %)</label><input id="<?= e($k) ?>" name="<?= e($k) ?>" inputmode="decimal" value="<?= e($caps[$k]) ?>"></div><?php endforeach ?>
  <label class="check"><input type="checkbox" name="confirm" value="1"> Apply these limits to every organisation</label>
  <div><button class="btn btn-primary" type="submit">Save limits</button></div>
</form>
