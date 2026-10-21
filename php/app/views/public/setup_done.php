<?php $title = 'Set up complete'; ?>
<div class="panel auth-card narrow-panel">
  <h1>Accounts created</h1>
  <p class="notice success">The platform owner signs in on the console; the administrator signs in on the <?= e($org['name']) ?> portal.</p>
  <ul class="plain-list">
    <li>Console: <a href="<?= e(tenant_base_url(null)) ?>/account/login"><?= e(tenant_base_url(null)) ?>/account/login</a></li>
    <li>Staff and customer portal: <a href="<?= e(tenant_base_url($org)) ?>/account/login"><?= e(tenant_base_url($org)) ?>/account/login</a></li>
  </ul>
  <p class="hint">Now remove <code>setup_token</code> from the configuration file.</p>
</div>
