<?php
/**
 * Outgoing email: transactional (account, application and security messages) and marketing.
 *
 * Transport per category: smtp_* for transactional, marketing_smtp_* for marketing (never falls back to the
 * transactional server). With no SMTP host for a category, messages are written to lmd-storage/outbox/*.eml and
 * nothing is sent. mail_allowed_domains (staging) diverts messages for other domains to the outbox too.
 * Every message gets an email_log row (queued -> sent / outbox / failed / captured). Failures record the error type
 * only: never passwords, tokens or message bodies.
 *
 * Each email is defined once (email_parts) and rendered as both HTML and plain text, so the two always match.
 */
declare(strict_types=1);

function first_name(?string $name): string
{
    return explode(' ', trim((string) $name))[0] ?: 'there';
}

function clean_header(string $value): string
{
    return mb_substr(preg_replace('/\s+/', ' ', $value), 0, 200);  // no CR/LF: blocks header injection
}

function staff_recipients(): array
{
    $org = current_org();
    if ($org === null) {
        return [];
    }
    $configured = org_settings($org)['staff_notification_emails'] ?: (string) config('staff_emails');
    $emails = csv_list($configured);
    if ($emails) {
        return $emails;
    }
    return array_column(q("SELECT email FROM users WHERE org_id = ? AND role IN ('admin', 'underwriter') AND is_active = 1", [$org['id']]), 'email');
}

/** Plain sentence per status, shared by the HTML and text versions. */
function status_explanation(string $status): string
{
    return match ($status) {
        'submitted' => "We have your application. This is not a decision; we'll be in touch.",
        'initial_review', 'underwriting', 'document_verification' => 'The team is reviewing your application and will contact you if anything else is needed.',
        'documents_required', 'info_requested' => 'We need some more information before we can continue. Please sign in, read the message and upload anything requested.',
        'partially_approved', 'fully_approved', 'conditionally_approved' => "An approval decision has been made. Sign in to see the approved amount, terms and any conditions. This is not an agreement and no money has been paid.",
        'agreement_issued' => 'Your agreement is ready to review in the client portal. Please read it carefully and ask us any questions before signing.',
        'agreement_signed' => 'We have recorded your signed agreement.',
        'funding_scheduled' => "Funding is being arranged. It has not been paid yet; we'll confirm when the payment has been made.",
        'partially_disbursed', 'fully_disbursed' => 'A payment has been made. Sign in to see the details.',
        'declined' => "Unfortunately we're not able to offer finance on this application. You can ask us to explain why and what options remain.",
        'withdrawn' => 'This application has been withdrawn and we have stopped working on it.',
        'cancelled' => 'This application has been cancelled.',
        default => '',
    };
}

const SECURITY_LINE = 'For your security we never ask for passwords, codes or bank details by email.';

/**
 * The content of each email: ['preheader', 'kicker', 'heading', 'paragraphs' => [], 'quote' => [title, text],
 * 'facts' => [[label, value]], 'button' => [href, label], 'after' => [], 'footer'].
 */
function email_parts(string $tpl, array $c): array
{
    $base = $c['base_url'];
    $site = site();
    $org = $site['org_name'];
    $who = first_name($c['client']['full_name'] ?? ($c['user']['full_name'] ?? ''));
    $app = $c['app'] ?? null;
    $ref = $app ? ref_str((int) $app['id']) : '';
    switch ($tpl) {
        case 'account_exists':
            return ['preheader' => 'You already have an account.', 'greeting' => $who,
                'paragraphs' => ["Someone tried to create a new $org account with this email address. You already have one, so nothing changed.",
                    'If this was you, sign in or reset your password. If it wasn\'t, you can ignore this email.'],
                'button' => [$base . '/account/login', 'Sign in'], 'after' => ["Forgot your password? $base/account/forgot", SECURITY_LINE]];
        case 'application_received':
            return ['preheader' => 'We have received your application.', 'greeting' => $who, 'kicker' => "$ref · {$app['company_name']}",
                'heading' => 'Application received',
                'paragraphs' => ["We've received your application $ref ({$app['company_name']}). It is waiting for review. This is not a decision, and nothing has been agreed yet."],
                'button' => ["$base/account/applications/{$app['id']}", 'View your application'], 'after' => [SECURITY_LINE]];
        case 'client_new_message':
            return ['preheader' => 'You have a new message.', 'greeting' => $who,
                'paragraphs' => ["The team has sent you a message about application $ref. Sign in to read it."],
                'button' => ["$base/account/applications/{$app['id']}#messages", 'Read the message'], 'after' => [SECURITY_LINE]];
        case 'client_status':
            return ['preheader' => status_explanation($app['status']), 'greeting' => $who, 'kicker' => "$ref · {$app['company_name']}",
                'heading' => 'Status: ' . $c['status_label'], 'paragraphs' => [status_explanation($app['status'])],
                'quote' => $c['message'] ? ['Message from your adviser', $c['message']] : null,
                'button' => ["$base/account/applications/{$app['id']}", 'View your application'], 'after' => [SECURITY_LINE]];
        case 'disbursement_confirmed':
            $d = $c['d'];
            return ['preheader' => 'A payment has been made.', 'greeting' => $who, 'heading' => 'Payment made',
                'paragraphs' => ["We have made a payment for application $ref."],
                'facts' => [['Principal released', money($d['amount_pence'], $d['currency'])],
                    ['Fees deducted', money($d['fees_deducted_minor'], $d['currency'])],
                    ['Paid to you', money($d['amount_pence'] - $d['fees_deducted_minor'], $d['currency'])],
                    ['Date paid', $d['paid_on']], ['Bank reference', $d['payment_reference']]],
                'button' => ["$base/account/applications/{$app['id']}#financing", 'View details'], 'after' => [SECURITY_LINE]];
        case 'document_issued':
            return ['preheader' => 'A document is ready for you.', 'greeting' => $who,
                'paragraphs' => ["{$c['title']} is ready in your client portal for application $ref.", 'If it is an agreement, it is not binding until it has been signed.'],
                'button' => ["$base/account/applications/{$app['id']}#letters", 'View the document'], 'after' => [SECURITY_LINE]];
        case 'enquiry_reply':
            return ['preheader' => 'A reply to your enquiry.', 'greeting' => first_name($c['enquiry']['name']),
                'paragraphs' => [$c['body'], $c['staff']['full_name']], 'after' => [SECURITY_LINE]];
        case 'password_reset':
            return ['preheader' => 'Reset your password.', 'greeting' => $who,
                'paragraphs' => ["We received a request to reset the password for your $org account. The link works once and expires in 1 hour."],
                'button' => [$c['link'], 'Choose a new password'], 'after' => ["If you didn't ask for this, you can ignore this email. Your password won't change."]];
        case 'security_alert':
            return ['preheader' => 'Security alert.', 'greeting' => $who,
                'paragraphs' => ["We're letting you know that {$c['what']} on your $org account at {$c['now']} UTC.",
                    "If this was you, there's nothing to do. If it wasn't, reset your password now and contact us."],
                'button' => ["$base/account/forgot", 'Reset your password'], 'after' => [SECURITY_LINE]];
        case 'staff_client_message':
            return ['preheader' => 'New client message.', 'heading' => 'New client message',
                'paragraphs' => ["{$c['client']['full_name']} sent a message on application $ref ({$app['company_name']})."],
                'button' => ["$base/admin/applications/{$app['id']}#notes", 'Open the application']];
        case 'staff_enquiry':
            $e = $c['enquiry'];
            return ['preheader' => 'New website enquiry.', 'heading' => 'New website enquiry',
                'paragraphs' => ["New website enquiry from {$e['name']} ({$e['company']}) about " . (TOPICS[$e['topic']] ?? $e['topic']) . '.'],
                'button' => ["$base/admin/enquiries/{$e['id']}", 'Open the enquiry']];
        case 'staff_invite':
            return ['preheader' => 'Your staff account.', 'greeting' => first_name($c['user']['full_name']),
                'paragraphs' => ["A $org staff account has been created for you (role: {$c['role']}). Choose your password with the link below. It works once and expires in 24 hours.",
                    'Then turn on two-step verification under Account and security.'],
                'button' => [$c['link'], 'Choose your password']];
        case 'staff_new_application':
            $cl = $c['client'];
            return ['preheader' => 'A new application is waiting for review.', 'heading' => 'New application: ' . $app['company_name'],
                'paragraphs' => ['A new application has been submitted and is waiting for review.'],
                'facts' => [['Reference', $ref], ['Client', $cl['full_name']], ['Email', $cl['email']], ['Phone', $cl['phone'] ?: 'Not given'],
                    ['Finance', FINANCE_TYPES[$app['finance_type']] ?? ''], ['Amount', money_whole($app['amount'], $app['currency'])],
                    ['Term', $app['term_months'] . ' months'], ['Annual turnover', money_whole($app['annual_turnover'], $app['currency'])],
                    ['Time trading', YEARS_TRADING[$app['years_trading']] ?? '']],
                'button' => ["$base/admin/applications/{$app['id']}", 'Review it'], 'footer' => 'Staff notification.'];
        case 'staff_withdrawn':
            return ['preheader' => 'An application was withdrawn.', 'heading' => 'Application withdrawn: ' . $app['company_name'],
                'paragraphs' => ["{$c['client']['full_name']} ({$c['client']['email']}) has withdrawn application $ref."],
                'button' => ["$base/admin/applications/{$app['id']}", 'Open it'], 'footer' => 'Staff notification.'];
        case 'subscription_reminder':
            $s = $c['sub'];
            $trial = $s['status'] === 'trial';
            return ['preheader' => 'Your subscription renews soon.', 'greeting' => 'there',
                'paragraphs' => ["The {$s['plan_name']} subscription for {$c['org_name']} " . ($trial ? 'trial ends' : 'current period ends') . ' on '
                    . ($trial ? $s['trial_ends_on'] : $s['current_period_end']) . '.', 'An invoice will follow. Payment is recorded only once it has been received.']];
        case 'verify_email':
            return ['preheader' => 'Confirm your email address.', 'greeting' => $who,
                'paragraphs' => ['Please confirm your email address. The link works once and expires in 24 hours.'],
                'button' => [$c['link'], 'Confirm my email address'], 'after' => ["If you didn't create an account, you can ignore this email."]];
        case 'marketing_campaign':
            $camp = $c['campaign'];
            return ['preheader' => $camp['preheader'] ?? '', 'greeting' => $c['first_name'] ?: 'there',
                'kicker' => !empty($c['test']) ? 'TEST EMAIL' : '', 'paragraphs' => preg_split('/\n\s*\n/', trim($camp['body'])),
                'footer' => $site->show('legal_name') . ', ' . $site->show('registered_office')
                    . ". You're receiving this because you opted in to emails from $org.",
                'unsubscribe' => $c['unsubscribe_link']];
    }
    throw new RuntimeException("Unknown email template $tpl");
}

function email_text(array $p, array $c): string
{
    $site = site();
    $out = [];
    if (!empty($p['kicker'])) {
        $out[] = '[' . $p['kicker'] . ']';
    }
    if (!empty($p['greeting'])) {
        $out[] = 'Hello ' . $p['greeting'] . ',';
    }
    if (!empty($p['heading'])) {
        $out[] = $p['heading'];
    }
    foreach ($p['paragraphs'] ?? [] as $para) {
        $out[] = $para;
    }
    if (!empty($p['quote'])) {
        $out[] = $p['quote'][0] . ":\n" . $p['quote'][1];
    }
    if (!empty($p['facts'])) {
        $out[] = implode("\n", array_map(fn($f) => str_pad($f[0] . ':', 20) . $f[1], $p['facts']));
    }
    if (!empty($p['button'])) {
        $out[] = $p['button'][1] . ': ' . $p['button'][0];
    }
    foreach ($p['after'] ?? [] as $para) {
        $out[] = $para;
    }
    $out[] = ($site['legal_name'] ?: $site['org_name']) . ($site['phone'] ? ' · ' . $site['phone'] : '');
    if (!empty($p['footer'])) {
        $out[] = $p['footer'];
    }
    if (!empty($p['unsubscribe'])) {
        $out[] = 'Unsubscribe: ' . $p['unsubscribe'];
    }
    return implode("\n\n", $out) . "\n";
}

function email_html(array $p, array $c): string
{
    $site = site();
    $accent = $site['brand_color'] ?: '#F08A6E';
    $nl = fn($t) => nl2br(e($t), false);
    $h = '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light"><title>' . e($c['subject']) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#EDF2F2;"><span style="display:none;max-height:0;overflow:hidden;opacity:0;">' . e($p['preheader'] ?? '') . '</span>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EDF2F2;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#14272B;">'
        . '<tr><td style="background:#112327;padding:20px 32px;border-bottom:3px solid ' . e($accent) . ';">'
        . '<span style="font-family:Georgia,serif;font-weight:bold;font-size:20px;color:#ffffff;">' . e($site['org_name']) . '</span></td></tr>'
        . '<tr><td style="padding:32px 32px 8px;font-size:16px;line-height:1.6;">';
    if (!empty($p['greeting'])) {
        $h .= '<p style="margin:0 0 16px;">Hello ' . e($p['greeting']) . ',</p>';
    }
    if (!empty($p['kicker'])) {
        $h .= '<p style="margin:0 0 6px;font-size:12px;letter-spacing:2px;text-transform:uppercase;color:#A8452A;font-weight:bold;">' . e($p['kicker']) . '</p>';
    }
    if (!empty($p['heading'])) {
        $h .= '<h1 style="margin:0 0 16px;font-family:Georgia,serif;font-size:26px;line-height:1.25;color:#112327;">' . e($p['heading']) . '</h1>';
    }
    foreach ($p['paragraphs'] ?? [] as $para) {
        $h .= '<p style="margin:0 0 16px;">' . $nl($para) . '</p>';
    }
    if (!empty($p['quote'])) {
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0;"><tr><td style="border-left:3px solid '
            . e($accent) . ';background:#FDEFEA;padding:14px 18px;font-size:15px;line-height:1.6;"><strong style="display:block;margin-bottom:4px;">'
            . e($p['quote'][0]) . '</strong>' . $nl($p['quote'][1]) . '</td></tr></table>';
    }
    if (!empty($p['facts'])) {
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;border-top:1px solid #DEE6E7;font-size:15px;">';
        foreach ($p['facts'] as [$label, $value]) {
            $h .= '<tr><td style="padding:10px 0;border-bottom:1px solid #DEE6E7;color:#56676A;width:42%;vertical-align:top;">' . e($label)
                . '</td><td style="padding:10px 0;border-bottom:1px solid #DEE6E7;font-weight:bold;vertical-align:top;">' . e($value) . '</td></tr>';
        }
        $h .= '</table>';
    }
    if (!empty($p['button'])) {
        $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0;"><tr><td style="background:' . e($accent)
            . ';border-radius:999px;"><a href="' . e($p['button'][0]) . '" style="display:inline-block;padding:14px 28px;color:#112327;font-weight:bold;text-decoration:none;font-size:15px;">'
            . e($p['button'][1]) . '</a></td></tr></table>';
    }
    foreach ($p['after'] ?? [] as $para) {
        $h .= '<p style="margin:0 0 12px;color:#56676A;font-size:14px;">' . $nl($para) . '</p>';
    }
    $h .= '</td></tr><tr><td style="padding:8px 32px 24px;font-size:16px;line-height:1.6;"><p style="margin:0;">' . e($site['legal_name'] ?: $site['org_name'])
        . ($site['phone'] || $site['opening_hours'] ? '<br><span style="color:#56676A;font-size:14px;">' . e(trim($site['phone'] . ($site['phone'] && $site['opening_hours'] ? ' · ' : '') . $site['opening_hours'])) . '</span>' : '')
        . '</p></td></tr><tr><td style="padding:18px 32px;background:#F3F6F6;font-size:12px;line-height:1.6;color:#56676A;">'
        . e($p['footer'] ?? ('You are receiving this because you have an account with ' . ($site['legal_name'] ?: $site['org_name']) . '.'))
        . ($site['company_number'] ? ' Company number ' . e($site['company_number']) . '.' : '')
        . ($site['regulatory_statement'] ? ' ' . e($site['regulatory_statement']) : '')
        . (!empty($p['unsubscribe']) ? ' <a href="' . e($p['unsubscribe']) . '" style="color:#56676A;">Unsubscribe</a>' : '')
        . '</td></tr></table></td></tr></table></body></html>';
    return $h;
}

function sender_address(string $category): string
{
    $configured = (string) config($category === 'marketing' ? 'marketing_from' : 'mail_from');
    $address = preg_match('/<([^>]+)>/', $configured, $m) ? $m[1] : trim($configured);
    $org = current_org();
    $name = $org ? clean_header($org['name']) : (preg_match('/^(.*?)\s*</', $configured, $m2) ? $m2[1] : '');
    return $name !== '' ? '"' . str_replace('"', '', $name) . "\" <$address>" : $address;
}

function encode_header(string $value): string
{
    return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

/** Build a complete MIME message (multipart/alternative: text + HTML). Returns [message, subject]. */
function build_email(array $to, string $subject, string $tpl, array $ctx, string $category = 'transactional', array $headers = []): array
{
    $ctx += ['base_url' => tenant_base_url(), 'subject' => $subject, 'message' => '', 'test' => false];
    $parts = email_parts($tpl, $ctx);
    $from = sender_address($category);
    $domain = substr(strrchr(preg_replace('/.*<|>.*/', '', $from), '@') ?: '@localhost', 1);
    $boundary = 'lmd-' . bin2hex(random_bytes(12));
    $lines = [
        'From: ' . (preg_match('/^"(.*)" <(.*)>$/', $from, $m) ? '"' . encode_header($m[1]) . '" <' . $m[2] . '>' : $from),
        'To: ' . implode(', ', $to),
        'Subject: ' . encode_header(clean_header($subject)),
        'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
    ];
    if (config('mail_reply_to') && $category === 'transactional') {
        $lines[] = 'Reply-To: ' . clean_header((string) config('mail_reply_to'));
    }
    foreach ($headers as $k => $v) {
        $lines[] = $k . ': ' . clean_header((string) $v);
    }
    $lines[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
    $body = "--$boundary\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode(email_text($parts, $ctx)))
        . "--$boundary\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode(email_html($parts, $ctx))) . "--$boundary--\r\n";
    return [implode("\r\n", $lines) . "\r\n\r\n" . $body, clean_header($subject), email_html($parts, $ctx)];
}

function mail_diverted(array $recipients): bool
{
    $allowed = array_map('strtolower', csv_list((string) config('mail_allowed_domains')));
    if (!$allowed) {
        return false;
    }
    foreach ($recipients as $r) {
        if (!in_array(strtolower(substr(strrchr($r, '@') ?: '', 1)), $allowed, true)) {
            return true;
        }
    }
    return false;
}

function email_status(int $logId, string $status, string $error = ''): void
{
    try {
        x('UPDATE email_log SET status = ?, error = ?, updated_at = ? WHERE id = ?', [$status, mb_substr($error, 0, 200), now(), $logId]);
    } catch (Throwable $e) {
        error_log("email_log $logId: " . get_class($e));
    }
}

/** Queue and deliver one message. Returns the email_log id (or null when there is no recipient). Never throws. */
function send_mail($to, string $subject, string $tpl, array $ctx = [], string $category = 'transactional', array $headers = []): ?int
{
    $recipients = array_values(array_filter(is_array($to) ? $to : [$to]));
    if (!$recipients) {
        return null;
    }
    [$message, $cleanSubject] = build_email($recipients, $subject, $tpl, $ctx, $category, $headers);
    $stamp = now();
    $logId = x('INSERT INTO email_log (org_id, created_at, category, template, recipient, subject, status, updated_at) VALUES (?,?,?,?,?,?,?,?)',
        [current_org()['id'] ?? null, $stamp, $category, $tpl, mb_substr(implode(', ', $recipients), 0, 500), $cleanSubject, 'queued', $stamp]);
    if (isset($GLOBALS['__lmd_mail_capture'])) {  // tests
        $GLOBALS['__lmd_mail_capture'][] = ['to' => $recipients, 'subject' => $cleanSubject, 'message' => $message, 'template' => $tpl];
        email_status($logId, 'captured');
        return $logId;
    }
    $prefix = $category === 'marketing' ? 'marketing_smtp_' : 'smtp_';
    $divert = mail_diverted($recipients);
    try {
        if (config($prefix . 'host') && !$divert) {
            smtp_send((string) config($prefix . 'host'), (int) config($prefix . 'port'), (string) config($prefix . 'user'),
                (string) config($prefix . 'password'), preg_replace('/.*<|>.*/', '', sender_address($category)), $recipients, $message);
            email_status($logId, 'sent');
        } else {
            file_put_contents(storage_dir() . '/outbox/' . gmdate('Ymd-His') . '-' . $logId . '.eml', $message);
            email_status($logId, 'outbox', $divert ? 'diverted: recipient outside mail_allowed_domains' : '');
        }
    } catch (Throwable $e) {
        error_log("Email $logId ($cleanSubject) failed: " . get_class($e) . ' ' . $e->getMessage());
        email_status($logId, 'failed', get_class($e) . ': ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 120));
    }
    return $logId;
}

/** Minimal SMTP client: implicit TLS on 465, STARTTLS otherwise, AUTH LOGIN. */
function smtp_send(string $host, int $port, string $user, string $password, string $from, array $to, string $message): void
{
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("SMTP connection failed ($errno)");
    }
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        $reply = $read();
        if (!in_array((int) substr($reply, 0, 3), $ok, true)) {
            throw new RuntimeException('SMTP error ' . substr($reply, 0, 3));
        }
        return $reply;
    };
    $read();
    $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
    $cmd($ehlo, [250]);
    if ($port !== 465) {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('STARTTLS failed');
        }
        $cmd($ehlo, [250]);
    }
    if ($user !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($password), [235]);
    }
    $cmd("MAIL FROM:<$from>", [250]);
    foreach ($to as $r) {
        $cmd('RCPT TO:<' . preg_replace('/[<>\r\n]/', '', $r) . '>', [250, 251]);
    }
    $cmd('DATA', [354]);
    $cmd(preg_replace('/^\./m', '..', str_replace("\n", "\r\n", str_replace("\r\n", "\n", $message))) . "\r\n.", [250]);
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
}

// ---------------------------------------------------------------- the messages
function mail_ref(array $app): string
{
    return ref_str((int) $app['id']);
}

function notify_staff_new_application(array $app, array $client): void
{
    if (org_settings()['notify_staff_new_application']) {
        send_mail(staff_recipients(), "New application: {$app['company_name']} (" . mail_ref($app) . ')', 'staff_new_application', ['app' => $app, 'client' => $client]);
    }
}

function notify_staff_withdrawn(array $app, array $client): void
{
    send_mail(staff_recipients(), "Withdrawn: {$app['company_name']} (" . mail_ref($app) . ')', 'staff_withdrawn', ['app' => $app, 'client' => $client]);
}

function notify_staff_client_message(array $app, array $client): void
{
    if (org_settings()['notify_staff_client_message']) {
        send_mail(staff_recipients(), 'New client message on ' . mail_ref($app), 'staff_client_message', ['app' => $app, 'client' => $client]);
    }
}

function notify_staff_enquiry(array $enquiry): void
{
    send_mail(staff_recipients(), 'New website enquiry', 'staff_enquiry', ['enquiry' => $enquiry]);
}

function notify_client_status(array $app, array $client, string $label, string $message): void
{
    send_mail($client['email'], 'Your application ' . mail_ref($app) . ": $label", 'client_status',
        ['app' => $app, 'client' => $client, 'status_label' => $label, 'message' => $message]);
}

function send_application_received(array $app, array $client): void
{
    send_mail($client['email'], 'We have received your application ' . mail_ref($app), 'application_received', ['app' => $app, 'client' => $client]);
}

function notify_client_message(array $app, array $client): void
{
    // The message itself stays in the portal; the email only says there is one.
    send_mail($client['email'], 'New message about your application ' . mail_ref($app), 'client_new_message', ['app' => $app, 'client' => $client]);
}

function notify_document_issued(array $app, array $client, string $title): void
{
    send_mail($client['email'], "A document is ready for you: $title", 'document_issued', ['app' => $app, 'client' => $client, 'title' => $title]);
}

function notify_disbursement(array $app, array $client, array $d): void
{
    send_mail($client['email'], 'Payment made for application ' . mail_ref($app), 'disbursement_confirmed', ['app' => $app, 'client' => $client, 'd' => $d]);
}

function send_password_reset(array $user, string $link): void
{
    send_mail($user['email'], 'Reset your password', 'password_reset', ['user' => $user, 'link' => $link]);
}

function send_verification(array $user, string $link): void
{
    send_mail($user['email'], 'Confirm your email address', 'verify_email', ['user' => $user, 'link' => $link]);
}

function send_account_exists(array $user): void
{
    send_mail($user['email'], 'Someone tried to register with your email', 'account_exists', ['user' => $user]);
}

function send_security_alert(array $user, string $what): void
{
    send_mail($user['email'], "Security alert: $what", 'security_alert', ['user' => $user, 'what' => $what, 'now' => now()]);
}

function send_enquiry_reply(array $enquiry, string $body, array $staff): ?int
{
    return send_mail($enquiry['email'], 'Re: your enquiry to ' . site()['org_name'], 'enquiry_reply', ['enquiry' => $enquiry, 'body' => $body, 'staff' => $staff]);
}
