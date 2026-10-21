<?php
/**
 * Optional AI help (Anthropic Claude), off unless ai_api_key is set.
 * It may answer a signed-in client's general question using ONLY the published FAQs and status names, and draft a
 * reply to a website enquiry for staff to edit (needs ai_staff_features). It never approves, declines or prices
 * anything, changes a record, or sees application data or documents. Questions are redacted (emails, phone numbers,
 * long digit runs, IBANs) before they leave the server. Only success/failure is logged, never the text.
 * Without a key, or if the service is unavailable, clients get the closest FAQ matches instead.
 */
declare(strict_types=1);

function ai_enabled(): bool
{
    return (string) config('ai_api_key') !== '';
}

function ai_staff_enabled(): bool
{
    return ai_enabled() && config('ai_staff_features');
}

function ai_redact(string $text): string
{
    $text = preg_replace('/[^@\s]+@[^@\s]+\.[^@\s]+/', '[email]', $text);
    $text = preg_replace('/\+?\d[\d\s()-]{7,}\d/', '[number]', $text);
    return preg_replace('/\b[A-Z]{2}\d{2}[A-Z0-9]{10,30}\b/', '[iban]', $text);
}

function ai_guardrails(): string
{
    return 'You are the website help assistant for ' . site()->show('legal_name') . ', a UK business finance company. '
        . "Answer ONLY from the reference information below. If it does not answer the question, say you don't know and suggest "
        . 'contacting the team through the client portal messages or the contact page. Never give financial, legal or tax advice, '
        . 'never say whether an application will be approved, never quote rates, fees or repayments, never ask for passwords, codes or '
        . "bank details, and never claim to be a person. Keep answers under 120 words, in plain British English.\n\n";
}

function ai_reference(): string
{
    $faqs = implode("\n", array_map(fn($f) => "Q: {$f['q']}\nA: {$f['a']}", site()['faqs']));
    $statuses = implode("\n", array_map(fn($l) => "- $l", array_values(array_diff_key(STATUSES, ['draft' => 1]))));
    return "FAQs:\n$faqs\n\nApplication statuses shown in the portal:\n$statuses";
}

/** One Messages API call. Returns text, or null on any failure (callers fall back). */
function ai_ask(string $system, string $prompt, int $maxTokens = 1024): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['content-type: application/json', 'anthropic-version: 2023-06-01', 'x-api-key: ' . config('ai_api_key')],
        CURLOPT_POSTFIELDS => json_encode(['model' => config('ai_model'), 'max_tokens' => $maxTokens, 'system' => $system,
            'messages' => [['role' => 'user', 'content' => $prompt]]]),
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) {
        error_log("AI request failed with status $status");
        return null;
    }
    $data = json_decode($body, true);
    if (($data['stop_reason'] ?? '') === 'refusal') {
        return null;
    }
    $text = trim(implode('', array_map(fn($b) => $b['type'] === 'text' ? $b['text'] : '', $data['content'] ?? [])));
    return $text !== '' ? $text : null;
}

/** Offline fallback: FAQs ranked by shared words. */
function faq_matches(string $question, int $limit = 3): array
{
    preg_match_all('/[a-z]{3,}/', strtolower($question), $m);
    $words = array_unique($m[0]);
    $scored = [];
    foreach (site()['faqs'] as $f) {
        preg_match_all('/[a-z]{3,}/', strtolower($f['q'] . ' ' . $f['a']), $fm);
        $score = count(array_intersect($words, array_unique($fm[0])));
        if ($score) {
            $scored[] = [$score, $f];
        }
    }
    usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(fn($s) => $s[1], array_slice($scored, 0, $limit));
}
