<?php $title = 'Enquiries'; ?>
<div class="page-head"><div><h1>Website enquiries</h1><p>From the contact form on the website.</p></div></div>
<nav class="chips" aria-label="Filter enquiries">
  <a class="chip" href="<?= e(url('/admin/enquiries?show=open')) ?>"<?= $show !== 'all' ? ' aria-current="true"' : '' ?>>Open</a>
  <a class="chip" href="<?= e(url('/admin/enquiries?show=all')) ?>"<?= $show === 'all' ? ' aria-current="true"' : '' ?>>All</a>
</nav>
<?php if ($enquiries): ?>
<div class="table-wrap">
  <table class="data">
    <thead><tr><th>From</th><th>Interest</th><th>Message</th><th>Received</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($enquiries as $en): ?>
      <tr>
        <td><a href="<?= e(url("/admin/enquiries/{$en['id']}")) ?>"><?= e($en['name']) ?></a><span class="sub"><?= e($en['company']) ?></span><span class="sub"><?= e($en['email']) ?><?= $en['phone'] ? ' · ' . e($en['phone']) : '' ?></span></td>
        <td><?= e(TOPICS[$en['topic']] ?? $en['topic']) ?></td>
        <td class="clip"><?= e(mb_substr($en['message'], 0, 220) ?: '—') ?><?= mb_strlen($en['message']) > 220 ? '…' : '' ?></td>
        <td class="muted small"><?= e(fmt_date($en['created_at'])) ?></td>
        <td>
          <?php if (can('handle_enquiries')): ?>
          <form method="post" action="<?= e(url("/admin/enquiries/{$en['id']}/handled?show=" . rawurlencode($show))) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-sm <?= $en['handled'] ? 'btn-outline' : 'btn-dark' ?>" type="submit"><?= $en['handled'] ? 'Reopen' : 'Mark handled' ?></button>
          </form>
          <?php endif ?>
        </td>
      </tr>
      <?php endforeach ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="panel empty"><p><?= $show !== 'all' ? 'No open enquiries.' : 'No enquiries yet.' ?></p></div>
<?php endif ?>
