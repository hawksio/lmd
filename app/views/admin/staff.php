<?php $title = 'Staff accounts'; $meId = (int) current_user()['id']; ?>
<div class="page-head"><div><h1>Staff accounts</h1><p>Roles follow least privilege. Changes sign the person out and are recorded in the audit log.</p></div></div>
<div class="layout-2">
  <section>
    <div class="table-wrap"><table class="data">
      <thead><tr><th>Name</th><th>Role</th><th>Security</th><th>Last sign-in</th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $u): $own = (int) $u['id'] === $meId; ?>
        <tr><td><strong><?= e($u['full_name']) ?></strong><span class="sub"><?= e($u['email']) ?><?= !$u['is_active'] ? ' · deactivated' : '' ?></span></td>
          <td><?php if (!$own): ?>
            <form method="post" action="<?= e(url("/admin/staff/{$u['id']}")) ?>" class="mini-form"><?= csrf_field() ?><input type="hidden" name="action" value="role">
              <label class="sr" for="role<?= (int) $u['id'] ?>">Role</label>
              <select id="role<?= (int) $u['id'] ?>" name="role"><?= options_html(STAFF_ROLES, $u['role']) ?></select>
              <button class="btn btn-outline btn-sm" type="submit">Change</button></form>
            <?php else: ?><?= e(STAFF_ROLES[$u['role']]) ?> (you)<?php endif ?></td>
          <td class="small"><?= $u['totp_enabled_at'] ? 'Two-step on' : 'Password only' ?></td>
          <td class="muted small"><?= e(fmt_date($u['last_login_at']) ?: 'Never') ?></td>
          <td><?php if (!$own): ?>
            <form method="post" action="<?= e(url("/admin/staff/{$u['id']}")) ?>" data-confirm="<?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?> <?= e($u['full_name']) ?>?"><?= csrf_field() ?>
              <input type="hidden" name="action" value="<?= $u['is_active'] ? 'deactivate' : 'activate' ?>">
              <button class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : 'btn-outline' ?>" type="submit"><?= $u['is_active'] ? 'Deactivate' : 'Reactivate' ?></button></form><?php endif ?></td></tr>
      <?php endforeach ?></tbody></table></div>
    <div class="panel spaced">
      <h2>What each role can do</h2>
      <dl class="facts">
        <dt>Organisation administrator</dt><dd>Everything in this organisation: staff, products, financial settings, organisation settings, content, marketing, decisions and payments (never confirming their own entries).</dd>
        <dt>Underwriter</dt><dd>Review applications and documents, propose and issue decisions (a different person issues large approvals), prepare letters.</dd>
        <dt>Document reviewer</dt><dd>Open, verify and reject documents and request evidence. Cannot make lending decisions.</dd>
        <dt>Customer support</dt><dd>Message customers, handle enquiries, edit (not publish) website content. Cannot open customer documents.</dd>
        <dt>Finance officer</dt><dd>Schedule, record and confirm payments (not their own), repayment schedules and repayments, fees, reports and exports.</dd>
        <dt>Read-only auditor</dt><dd>View applications, documents, reports and the audit log. Cannot change anything.</dd>
      </dl>
    </div>
  </section>
  <section class="panel">
    <h2>Add a staff member</h2>
    <p class="muted small">They'll get an email link (valid 24 hours) to choose their own password. You never see it.<?= $limit ? " Your plan allows $limit active staff." : '' ?></p>
    <form method="post" class="form" novalidate>
      <?= csrf_field() ?>
      <div><label for="s_name">Full name</label><input id="s_name" name="full_name" maxlength="120" required></div>
      <div><label for="s_email">Work email</label><input id="s_email" name="email" type="email" maxlength="200" required></div>
      <div><label for="s_role">Role</label><select id="s_role" name="role"><?= options_html(STAFF_ROLES, 'underwriter') ?></select></div>
      <div><button class="btn btn-primary btn-sm" type="submit">Create and send invite</button></div>
    </form>
  </section>
</div>
