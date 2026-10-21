<?php
$o = current_org();
$user = current_user();
$staff = is_staff($user);
$owner = is_platform_owner($user);
$nav = function (string $path, string $label, array $prefixes = [], int $badge = 0): string {
    return '<li><a href="' . e(url($path)) . '"' . (is_current($path, $prefixes) ? ' aria-current="page"' : '') . '>' . e($label)
        . ($badge ? ' <span class="count" aria-label="' . $badge . ' unread">' . $badge . '</span>' : '') . '</a></li>';
};
$unread = $user ? unread_notifications() : 0;
?><!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?: 'Portal') ?> | <?= e($o['name'] ?? 'Platform console') ?></title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0C1A1D">
<link rel="icon" href="/brand/lmd-monogram.svg" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600&display=swap">
<link rel="stylesheet" href="/assets/site.css">
<link rel="stylesheet" href="/assets/app.css">
<?php if ($o): ?><style>:root { --accent: <?= e($o['brand_color']) ?>; --on-accent: <?= e(text_on($o['brand_color'])) ?>; }</style><?php endif ?>
<script src="/assets/site.js" defer></script>
<script src="/assets/app.js" defer></script>
</head>
<body class="app <?= $user ? 'has-sidebar' : '' ?> <?= e($body_class) ?>">
<a class="skip" href="#main">Skip to content</a>
<header class="site-header">
  <div class="wrap header-inner">
    <a class="brand" href="<?= e(url($owner ? '/platform/' : ($staff ? '/admin/' : ($user ? '/account/' : '/')))) ?>" aria-label="<?= e($o['name'] ?? 'Platform console') ?>">
      <?php if ($o && $o['logo_file']): ?><img src="<?= e(url('/branding/logo')) ?>?v=<?= e($o['logo_file']) ?>" height="40" alt="" class="org-logo">
      <?php else: ?><img src="/brand/lmd-monogram.svg" width="40" height="40" alt=""><?php endif ?>
      <span class="brand-text"><strong><?= e($o['name'] ?? 'Platform') ?></strong><small><?= ($owner || !$o) ? 'Console' : ($staff ? 'Staff' : 'Client portal') ?></small></span>
    </a>
    <?php if ($user): ?>
      <span class="who"><?= e($user['full_name']) ?><?= $staff ? ' · ' . e(STAFF_ROLES[$user['role']]) : ($owner ? ' · Platform owner' : '') ?></span>
      <button class="nav-toggle" aria-expanded="false" aria-controls="site-nav"><span class="sr">Menu</span><span class="bars" aria-hidden="true"></span></button>
    <?php else: ?>
      <nav class="site-nav public-nav" aria-label="Main">
        <ul><?php if ($o && owns_static_site()): ?><li><a href="/">Home</a></li><li><a href="/faq.html">FAQs</a></li><li><a href="/contact.html">Contact</a></li><?php endif ?></ul>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/account/login')) ?>">Sign in</a>
      </nav>
    <?php endif ?>
  </div>
</header>

<div class="<?= $user ? 'portal wrap' : 'wrap' ?>">
  <?php if ($user): ?>
  <nav id="site-nav" class="sidebar" aria-label="<?= $owner ? 'Platform' : ($staff ? 'Staff' : 'Account') ?>">
    <ul>
    <?php if ($owner): ?>
      <?= $nav('/platform/', 'Overview') ?>
      <?= $nav('/platform/organisations', 'Organisations', ['/platform/organisations/']) ?>
      <?= $nav('/platform/plans', 'Plans') ?>
      <?= $nav('/platform/settings', 'Platform limits') ?>
      <?= $nav('/account/security', 'Account and security') ?>
    <?php elseif ($staff): ?>
      <?= $nav('/admin/', 'Overview') ?>
      <?= $nav('/admin/applications', 'Applications', ['/admin/applications/']) ?>
      <?= $nav('/admin/approvals', 'Approval queue') ?>
      <?= $nav('/admin/documents/', 'Document verification', ['/admin/documents/']) ?>
      <?= $nav('/admin/clients', 'Customers') ?>
      <?= $nav('/admin/products', 'Loan products', ['/admin/products/']) ?>
      <?= $nav('/admin/disbursements', 'Disbursements') ?>
      <?= $nav('/admin/repayments', 'Repayments') ?>
      <?= $nav('/admin/fees', 'Fees and payments') ?>
      <?= $nav('/admin/letters', 'Agreements and letters') ?>
      <?= $nav('/admin/messages', 'Messages') ?>
      <?= $nav('/admin/notifications', 'Notifications', [], $unread) ?>
      <?= $nav('/admin/enquiries', 'Enquiries', ['/admin/enquiries/']) ?>
      <?php if (can('manage_marketing')): ?><?= $nav('/admin/marketing', 'Marketing', ['/admin/marketing/']) ?><?php endif ?>
      <?php if (can('view_reports')): ?><?= $nav('/admin/reports/', 'Reports') ?><?php endif ?>
      <?php if (can('manage_financial_settings') || can('view_reports')): ?><?= $nav('/admin/financial-settings', 'Financial settings', ['/admin/financial-settings/']) ?><?php endif ?>
      <?php if (can('edit_content')): ?><?= $nav('/admin/content/', 'Website content') ?><?php endif ?>
      <?php if (can('manage_staff')): ?><?= $nav('/admin/staff', 'Staff and permissions') ?><?php endif ?>
      <?php if (can('manage_org_settings')): ?><?= $nav('/admin/settings', 'Organisation settings') ?><?php endif ?>
      <?php if (can('view_audit')): ?><?= $nav('/admin/audit', 'Audit log') ?><?= $nav('/admin/emails', 'Email log') ?><?php endif ?>
      <?= $nav('/account/security', 'Account and security') ?>
    <?php else: ?>
      <?= $nav('/account/', 'Overview') ?>
      <?= $nav('/account/applications', 'My applications', ['/account/applications/']) ?>
      <?= $nav('/account/documents', 'My documents') ?>
      <?= $nav('/account/messages', 'Messages', [], $unread) ?>
      <?= $nav('/account/security', 'Account and security', ['/account/profile']) ?>
      <?= $nav('/account/help', 'Help and contact') ?>
    <?php endif ?>
    </ul>
    <form method="post" action="<?= e(url('/account/logout')) ?>" class="nav-form">
      <?= csrf_field() ?>
      <button class="btn btn-ghost btn-sm" type="submit">Sign out</button>
    </form>
  </nav>
  <?php endif ?>

  <main id="main" class="app-main" tabindex="-1">
    <?php foreach (take_flashes() as [$category, $message]): ?>
      <p class="notice <?= e($category) ?> flash" role="<?= $category === 'error' ? 'alert' : 'status' ?>"><?= e($message) ?></p>
    <?php endforeach ?>
    <?php if ($user && !$staff && !$owner && !$user['email_verified_at']): ?>
      <div class="notice verify-banner">
        <p><strong>Please confirm your email address.</strong> We sent a link to <?= e($user['email']) ?>. You can save drafts now, but you'll need to confirm it before submitting an application.</p>
        <form method="post" action="<?= e(url('/account/verify/resend')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="next" value="<?= e(url(path())) ?>">
          <button class="btn btn-outline btn-sm" type="submit">Send a new link</button>
        </form>
      </div>
    <?php endif ?>
    <?= $content ?>
  </main>
</div>

<footer class="app-footer">
  <div class="wrap">
    <?php if ($o): ?>
    <p><?= e(site()->show('legal_name')) ?><?= site()['regulatory_statement'] ? ' · ' . e(site()['regulatory_statement']) : '' ?>
      <?php if (owns_static_site()): ?> · <a href="/">Main website</a> · <a href="/privacy.html">Privacy</a> · <a href="/complaints.html">Complaints</a><?php endif ?></p>
    <?php else: ?><p>Platform console. Organisation data is not shown here.</p><?php endif ?>
  </div>
</footer>
</body>
</html>
