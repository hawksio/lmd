<?php
$title = $code === 404 ? 'Page not found' : 'Something went wrong';
$home = on_platform() ? '/platform/' : '/account/';
?>
<div class="panel empty narrow-panel">
  <?php if ($code === 404): ?>
    <h1>Page not found</h1><p>The page you were looking for doesn't exist or you don't have access to it.</p>
  <?php elseif ($code === 403): ?>
    <h1>No access</h1><p><?= e($message && !str_contains(strtolower($message), 'permission') ? $message : "Your account doesn't have permission to do that.") ?></p>
  <?php elseif ($code === 413): ?>
    <h1>File too large</h1><p>Uploads can be up to <?= e(config('max_upload_mb')) ?> MB. Please compress the file or split it and try again.</p>
  <?php elseif ($code === 429): ?>
    <h1>Too many requests</h1><p>Please wait a few minutes and try again.</p>
  <?php elseif ($code === 500): ?>
    <h1>Something went wrong</h1><p>We've logged the problem. Please try again, or contact us if it keeps happening.</p>
    <?php if ($message): ?><p class="hint">Development detail: <?= e($message) ?></p><?php endif ?>
  <?php elseif ($code === 503): ?>
    <h1>Not available</h1><p><?= e($message ?: 'This service is not available at the moment.') ?></p>
  <?php else: ?>
    <h1>We couldn't do that</h1><p><?= e($message) ?></p>
  <?php endif ?>
  <p><a class="btn btn-primary" href="<?= e(url($home)) ?>">Go to the portal</a></p>
</div>
