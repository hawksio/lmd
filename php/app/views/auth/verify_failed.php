<?php $title = 'Link expired'; ?>
<div class="panel auth-card narrow-panel">
  <h1>This link has expired</h1>
  <p class="muted">Confirmation links work once and last 24 hours. Sign in and use "Send a new link" to get another.</p>
  <p><a class="btn btn-primary" href="<?= e(url('/account/login')) ?>">Sign in</a></p>
</div>
