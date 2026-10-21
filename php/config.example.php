<?php
/**
 * Copy to public_html/app/config.local.php on the server (or lmd-storage/config.php), fill in, and never commit it.
 * Every setting and its default is listed in app/lib/core.php (CONFIG_DEFAULTS).
 */
return [
    // 'staging' while you try it out; 'production' at launch (then secure_cookies and an https base_url are required).
    'env' => 'staging',
    'base_url' => 'https://lmdglobalfinltd.com',
    'secure_cookies' => true,          // only once the SSL certificate is active in hPanel

    // Private preview: the whole site asks for user "staging" and this password. Remove at launch.
    'staging_password' => 'CHOOSE-A-LONG-RANDOM-PASSWORD',
    'noindex' => true,                 // keep search engines out until launch

    // One-time set-up page: https://<site>/setup?token=<setup_token>. Remove after creating the first accounts.
    'setup_token' => 'GENERATE-64-RANDOM-CHARACTERS',
    // Scheduled jobs over HTTPS (hPanel cron can call: curl -s "https://<site>/cron/backup?token=<cron_token>").
    'cron_token' => 'GENERATE-64-RANDOM-CHARACTERS',

    // Email: a Hostinger mailbox works (hPanel > Emails). Leave smtp_host empty to keep emails in lmd-storage/outbox.
    'smtp_host' => '',                 // e.g. smtp.hostinger.com
    'smtp_port' => 465,
    'smtp_user' => '',                 // e.g. no-reply@lmdglobalfinltd.com
    'smtp_password' => '',
    'mail_from' => 'LMD Finance <no-reply@lmdglobalfinltd.com>',
    'mail_allowed_domains' => '',      // staging safety net, e.g. 'lmdglobalfinltd.com'

    'require_staff_mfa' => false,      // set true before launch

    // Organisations: /o/<slug>/ on this domain (no DNS changes needed); the console at /console.
    'default_org' => 'lmd',
    'static_site_org' => 'lmd',
    'tenant_path_prefix' => '/o',
    'platform_path' => '/console',
];
