<?php
/** Staff: website content editor (draft, preview, publish) and consent-based marketing campaigns. */
declare(strict_types=1);

route('GET|POST', '/admin/content/', function () {
    if ($r = need_staff('edit_content')) {
        return $r;
    }
    [$data, $draftRow] = content_draft();
    $errors = [];
    if (is_post()) {
        $settings = [];
        foreach (SETTING_FIELDS as $k => $_) {
            $settings[$k] = form($k);
        }
        $qs = form_list('faq_q');
        $as = form_list('faq_a');
        $faqs = [];
        foreach ($qs as $i => $qq) {
            $faqs[] = ['q' => $qq, 'a' => $as[$i] ?? ''];
        }
        [$settings, $faqs, $errors] = content_validate($settings, $faqs);
        $data = ['settings' => $settings, 'faqs' => $faqs];
        if (!$errors) {
            $vid = content_save_draft($data, current_user(), form('note'));
            audit_log('content.draft_saved', 'content_version', $vid);
            flash('Draft saved. Preview it, then publish when it has been checked.', 'success');
            return redirect('/admin/content/');
        }
    }
    $live = content_published();
    $changed = [];
    foreach (SETTING_FIELDS as $k => $f) {
        if (($data['settings'][$k] ?? '') !== ($live['settings'][$k] ?? '')) {
            $changed[] = $f[0];
        }
    }
    if ($data['faqs'] != $live['faqs']) {
        $changed[] = 'FAQs';
    }
    return view('admin/content', ['data' => $data, 'draft_row' => $draftRow, 'errors' => $errors, 'changed' => $changed,
        'missing' => unresolved_placeholders($live),
        'history' => q('SELECT v.id, v.status, v.note, v.created_at, v.published_at, c.full_name AS author, p.full_name AS publisher FROM content_versions v
            LEFT JOIN users c ON c.id = v.created_by LEFT JOIN users p ON p.id = v.published_by WHERE v.org_id = ? ORDER BY v.id DESC LIMIT 30', [org_id()])]);
});
route('GET', '/admin/content', fn() => redirect('/admin/content/'));

route('GET', '/admin/content/preview/{page}', function (string $page) {
    if ($r = need_staff('edit_content')) {
        return $r;
    }
    if (!in_array($page, PREVIEW_PAGES, true) || !site_page_exists($page)) {
        abort(404);
    }
    [$data] = content_draft();
    return new Response(site_render($page, $data, 'PREVIEW of unpublished changes. Not visible to the public.'), 200,
        ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
});

route('POST', '/admin/content/publish', function () {
    if ($r = need_staff('publish_content')) {
        return $r;
    }
    [, $draftRow] = content_draft();
    if ($draftRow === null) {
        flash('There are no unpublished changes.', 'info');
        return redirect('/admin/content/');
    }
    if (!form('confirm')) {
        flash('Tick the box to confirm the changes have been checked and approved.', 'error');
        return redirect('/admin/content/');
    }
    content_publish($draftRow, current_user());
    audit_log('content.published', 'content_version', $draftRow['id']);
    flash('Published. The public pages show the new content now.', 'success');
    return redirect('/admin/content/');
});

route('POST', '/admin/content/discard', function () {
    if ($r = need_staff('edit_content')) {
        return $r;
    }
    [, $draftRow] = content_draft();
    if ($draftRow !== null) {
        x("UPDATE content_versions SET status = 'discarded' WHERE id = ? AND org_id = ?", [$draftRow['id'], org_id()]);
        audit_log('content.draft_discarded', 'content_version', $draftRow['id']);
    }
    flash('Draft discarded.', 'success');
    return redirect('/admin/content/');
});

// ---------------------------------------------------------------- marketing
function campaign_row(int $id): array
{
    $c = q1('SELECT * FROM campaigns WHERE id = ? AND org_id = ?', [$id, org_id()]);
    if ($c === null) {
        abort(404);
    }
    return $c;
}

route('GET', '/admin/marketing', function () {
    if ($r = need_staff('manage_marketing')) {
        return $r;
    }
    $counts = [];
    foreach (SEGMENTS as $k => $_) {
        $counts[$k] = count(segment_members($k));
    }
    return view('admin/marketing', ['counts' => $counts, 'checks' => marketing_readiness(),
        'suppressed' => (int) qv('SELECT COUNT(*) FROM suppressions WHERE org_id = ?', [org_id()]),
        'campaigns' => q("SELECT c.*, (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS sent_n FROM campaigns c
            WHERE c.org_id = ? ORDER BY c.created_at DESC", [org_id()])]);
});

$campaignForm = function (?int $cid = null) {
    if ($r = need_staff('manage_marketing')) {
        return $r;
    }
    $c = $cid ? campaign_row($cid) : null;
    if ($c && $c['status'] !== 'draft') {
        abort(400, 'Only draft campaigns can be edited.');
    }
    $f = $c ?? ['segment' => 'all_opted_in'];
    $errors = [];
    if (is_post()) {
        $f = [];
        foreach (['name' => 120, 'subject' => 150, 'preheader' => 200, 'body' => 8000, 'segment' => 40] as $k => $n) {
            $f[$k] = mb_substr(trim(form($k)), 0, $n);
        }
        foreach (['name', 'subject', 'body'] as $k) {
            if ($f[$k] === '') {
                $errors[$k] = 'Required.';
            }
        }
        if (!isset(SEGMENTS[$f['segment']])) {
            $errors['segment'] = 'Choose an audience.';
        }
        if (!$errors) {
            if ($c) {
                x('UPDATE campaigns SET name = ?, subject = ?, preheader = ?, body = ?, segment = ? WHERE id = ?', [$f['name'], $f['subject'], $f['preheader'], $f['body'], $f['segment'], $c['id']]);
                $cid = (int) $c['id'];
            } else {
                $cid = x('INSERT INTO campaigns (org_id, name, subject, preheader, body, segment, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)',
                    [org_id(), $f['name'], $f['subject'], $f['preheader'], $f['body'], $f['segment'], current_user()['id'], now()]);
            }
            audit_log('marketing.campaign_saved', 'campaign', $cid);
            flash('Campaign saved as a draft.', 'success');
            return redirect("/admin/marketing/$cid");
        }
    }
    return view('admin/campaign_form', ['f' => $f, 'errors' => $errors, 'campaign' => $c]);
};
route('GET|POST', '/admin/marketing/new', $campaignForm);
route('GET|POST', '/admin/marketing/{id}/edit', $campaignForm);

route('GET', '/admin/marketing/{id}', function (int $id) {
    if ($r = need_staff('manage_marketing')) {
        return $r;
    }
    $c = campaign_row($id);
    $stats = [];
    foreach (q('SELECT status, COUNT(*) n FROM campaign_recipients WHERE campaign_id = ? GROUP BY status', [$id]) as $row) {
        $stats[$row['status']] = (int) $row['n'];
    }
    return view('admin/campaign', ['c' => $c, 'stats' => $stats, 'audience' => count(segment_members($c['segment'])), 'checks' => marketing_readiness()]);
});

route('GET', '/admin/marketing/{id}/preview', function (int $id) {
    if ($r = need_staff('manage_marketing')) {
        return $r;
    }
    $c = campaign_row($id);
    [, , $html] = build_email(['preview@example.com'], $c['subject'], 'marketing_campaign',
        ['campaign' => $c, 'first_name' => 'Alex', 'unsubscribe_link' => '#unsubscribe-preview', 'test' => true], 'marketing');
    return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8',
        'Content-Security-Policy' => "default-src 'none'; img-src 'self' https:; style-src 'unsafe-inline'; sandbox"]);
});

route('POST', '/admin/marketing/{id}/test', function (int $id) {
    if ($r = need_staff('manage_marketing')) {
        return $r;
    }
    $c = campaign_row($id);
    $user = current_user();
    send_campaign_email($c, $user['email'], $user['full_name'], true);  // only ever to the signed-in staff member
    audit_log('marketing.test_send', 'campaign', $id);
    flash("Test email sent to {$user['email']}.", 'success');
    return redirect("/admin/marketing/$id");
});

route('POST', '/admin/marketing/{id}/launch', function (int $id) {
    if ($r = need_staff('manage_marketing')) {
        return $r;
    }
    $c = campaign_row($id);
    if ($c['status'] !== 'draft') {
        abort(400, 'This campaign has already been scheduled or sent.');
    }
    if (!config('marketing_enabled')) {
        abort(400, 'Live campaigns are switched off (marketing_enabled is not on). Complete the marketing checklist first.');
    }
    if (!form('confirm')) {
        flash('Tick the confirmation box to schedule the campaign.', 'error');
        return redirect("/admin/marketing/$id");
    }
    $when = trim(form('scheduled_for'));
    if ($when !== '') {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $when, new DateTimeZone('UTC'));
        if (!$dt) {
            abort(400, 'Enter a valid date and time.');
        }
        $when = $dt->format('Y-m-d H:i:s');
    } else {
        $when = now();
    }
    x("UPDATE campaigns SET status = 'scheduled', scheduled_for = ?, launched_by = ? WHERE id = ?", [$when, current_user()['id'], $id]);
    audit_log('marketing.campaign_scheduled', 'campaign', $id, ['scheduled_for' => $when]);
    flash('Campaign scheduled. It is sent by the scheduled send job (send-campaigns).', 'success');
    return redirect("/admin/marketing/$id");
});

route('POST', '/admin/marketing/{id}/cancel', function (int $id) {
    if ($r = need_staff('manage_marketing')) {
        return $r;
    }
    $c = campaign_row($id);
    if (!in_array($c['status'], ['draft', 'scheduled'], true)) {
        abort(400, 'Only draft or scheduled campaigns can be cancelled.');
    }
    x("UPDATE campaigns SET status = 'cancelled' WHERE id = ?", [$id]);
    audit_log('marketing.campaign_cancelled', 'campaign', $id);
    flash('Campaign cancelled.', 'success');
    return redirect('/admin/marketing');
});

route('GET|POST', '/admin/marketing/suppressions', function () {
    if ($r = need_staff('manage_marketing')) {
        return $r;
    }
    if (is_post()) {
        $email = strtolower(mb_substr(trim(form('email')), 0, 200));
        $reason = form('reason', 'manual');
        if (str_contains($email, '@') && in_array($reason, ['bounced', 'complaint', 'manual'], true)) {
            x('INSERT OR IGNORE INTO suppressions (org_id, email, reason, created_at) VALUES (?,?,?,?)', [org_id(), $email, $reason, now()]);
            $user = q1('SELECT * FROM users WHERE org_id = ? AND email = ?', [org_id(), $email]);
            if ($user && $user['marketing_opt_in_at']) {
                record_consent($user, 'opt_out', "staff: $reason");
            }
            audit_log('marketing.suppressed', 'user', $user['id'] ?? null, ['reason' => $reason]);
            flash('Address added to the suppression list.', 'success');
        } else {
            flash('Enter a valid email address and reason.', 'error');
        }
        return redirect('/admin/marketing/suppressions');
    }
    return view('admin/suppressions', ['rows' => q('SELECT * FROM suppressions WHERE org_id = ? ORDER BY created_at DESC LIMIT 500', [org_id()])]);
});
