<?php $title = $the_org['name']; $id = (int) $the_org['id']; $active = $the_org['status'] === 'active'; ?>
<a class="crumb" href="<?= e(url('/platform/organisations')) ?>">Organisations</a>
<div class="page-head"><div><h1><?= e($the_org['name']) ?></h1><p><a href="<?= e($address) ?>/account/login" target="_blank" rel="noopener"><?= e($address) ?></a> · created <?= e(fmt_date($the_org['created_at'])) ?></p></div>
  <span class="tag-sm tag-<?= $active ? 'verified' : 'rejected' ?>"><?= e(ucfirst($the_org['status'])) ?></span></div>
<div class="layout-2">
  <div>
    <section class="panel">
      <h2>Subscription</h2>
      <?php if ($sub): ?><p><?= e($sub['plan_name']) ?> · <?= e(SUB_STATUSES[$sub['status']]) ?> · <span class="tag-sm">Manual billing</span><?= ($sub['trial_ends_on'] && $sub['status'] === 'trial') ? ' · trial ends ' . e($sub['trial_ends_on']) : '' ?><?= $sub['current_period_end'] ? ' · period ends ' . e($sub['current_period_end']) : '' ?></p><?php else: ?><p class="muted">No subscription.</p><?php endif ?>
      <form method="post" class="form grid-fields">
        <?= csrf_field() ?><input type="hidden" name="action" value="subscription">
        <div><label for="plan_id">Plan</label><select id="plan_id" name="plan_id"><?= options_html(array_column($plans, 'name', 'id'), $sub['plan_id'] ?? '') ?></select></div>
        <div><label for="status">Status</label><select id="status" name="status"><?= options_html(SUB_STATUSES, $sub['status'] ?? '') ?></select></div>
        <div><label for="current_period_end">Period end</label><input id="current_period_end" name="current_period_end" type="date" value="<?= e($sub['current_period_end'] ?? '') ?>"></div>
        <div><button class="btn btn-dark btn-sm" type="submit">Save subscription</button></div>
      </form>
    </section>
    <section class="panel">
      <h2>Invoices</h2>
      <div class="table-wrap"><table class="data compact"><thead><tr><th>Number</th><th>Period</th><th>Plan</th><th>Usage fee</th><th>Status</th><th></th></tr></thead>
        <tbody><?php foreach ($invoices as $i): ?><tr><td><?= e($i['number']) ?></td><td class="small"><?= e($i['period_start']) ?> – <?= e($i['period_end']) ?></td><td><?= e(money($i['plan_amount_minor'], $i['currency'])) ?></td><td><?= e(money($i['usage_fee_minor'], $i['currency'])) ?></td>
          <td><?= e(ucfirst($i['status'])) ?><?= $i['payment_reference'] ? '<span class="sub">ref ' . e($i['payment_reference']) . '</span>' : '' ?></td>
          <td><?php if ($i['status'] === 'issued'): ?><form method="post" action="<?= e(url("/platform/invoices/{$i['id']}/paid")) ?>" class="mini-form"><?= csrf_field() ?>
            <input name="payment_reference" placeholder="Payment reference" aria-label="Payment reference"><label class="check"><input type="checkbox" name="confirm" value="1"> Money received</label><button class="btn btn-outline btn-sm" type="submit">Record payment</button></form>
            <form method="post" action="<?= e(url("/platform/invoices/{$i['id']}/void")) ?>" class="mini-form"><?= csrf_field() ?><button class="btn btn-danger btn-sm" type="submit">Void</button></form><?php endif ?></td></tr>
        <?php endforeach ?>
        <?php if (!$invoices): ?><tr><td colspan="6" class="muted">No invoices.</td></tr><?php endif ?></tbody></table></div>
      <?php if ($sub): ?><form method="post" action="<?= e(url("/platform/organisations/$id/invoices")) ?>" class="form inline-form spaced"><?= csrf_field() ?>
        <div><label for="period_start">Invoice the period starting</label><input id="period_start" name="period_start" type="date" required></div>
        <div><button class="btn btn-dark btn-sm" type="submit">Issue invoice</button></div></form>
        <p class="hint">Plan price plus any usage fee (<?= e($sub['platform_fee_percent']) ?>% of payments the organisation confirmed to its customers in the period).</p><?php endif ?>
    </section>
  </div>
  <div>
    <section class="panel">
      <h2>Usage</h2>
      <dl class="facts">
        <dt>Active staff</dt><dd><?= $usage['staff'] ?><?= ($sub && $sub['max_staff']) ? ' / ' . (int) $sub['max_staff'] : '' ?></dd>
        <dt>Customers</dt><dd><?= $usage['customers'] ?></dd>
        <dt>Applications this month</dt><dd><?= $usage['apps_month'] ?><?= ($sub && $sub['max_applications_month']) ? ' / ' . (int) $sub['max_applications_month'] : '' ?></dd>
        <dt>Loan products</dt><dd><?= $usage['products'] ?><?= ($sub && $sub['max_products']) ? ' / ' . (int) $sub['max_products'] : '' ?></dd>
      </dl>
    </section>
    <section class="panel">
      <h2>Administrators</h2>
      <ul class="plain-list"><?php foreach ($admins as $a): ?><li><?= e($a['full_name']) ?> · <?= e($a['email']) ?><small class="block muted">Last sign-in <?= e(fmt_date($a['last_login_at']) ?: 'never') ?> · <?= $a['totp_enabled_at'] ? 'two-step on' : 'password only' ?></small></li><?php endforeach ?>
      <?php if (!$admins): ?><li class="muted">None active.</li><?php endif ?></ul>
    </section>
    <section class="panel">
      <h2>Domains</h2>
      <ul class="doc-list"><?php foreach ($domains as $d): ?><li><span><?= e($d['host']) ?><?= $d['is_primary'] ? ' <span class="tag-sm">primary</span>' : '' ?></span>
        <form method="post" class="mini-form"><?= csrf_field() ?><input type="hidden" name="action" value="remove_domain"><input type="hidden" name="host" value="<?= e($d['host']) ?>"><button class="btn btn-danger btn-sm" type="submit">Remove</button></form></li><?php endforeach ?>
      <?php if (!$domains): ?><li class="muted">No custom domain. The portal is at <?= e($address) ?>.</li><?php endif ?></ul>
      <form method="post" class="form inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_domain">
        <div><label for="host">Add a custom domain</label><input id="host" name="host" placeholder="portal.example.com" maxlength="253"></div>
        <div><button class="btn btn-outline btn-sm" type="submit">Add</button></div></form>
    </section>
    <section class="panel">
      <h2>Access</h2>
      <form method="post" class="form"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $active ? 'suspend' : 'activate' ?>">
        <label class="check"><input type="checkbox" name="confirm" value="1"> <?= $active ? 'Suspend: staff and customers lose access until reactivated. Data is kept.' : 'Reactivate this organisation.' ?></label>
        <div><button class="btn <?= $active ? 'btn-danger' : 'btn-primary' ?> btn-sm" type="submit"><?= $active ? 'Suspend' : 'Reactivate' ?></button></div></form>
    </section>
  </div>
</div>
