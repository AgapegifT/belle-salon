<?php
/**
 * client/dashboard.php  -  "My appointments": upcoming visits, history, and what to do next
 * (cancel, reschedule, pay, review).
 */
require __DIR__ . '/../includes/bootstrap.php';
$me       = require_role('client');
$clientId = profile_id();
$title    = 'My appointments';
$active   = 'appointments';

// ---- Handle the Cancel button (a POST form) -----------------------------
if (is_post() && post('action') === 'cancel') {
    csrf_check();
    $appt = get_appointment((int)post('id'));
    if (!$appt || (int)$appt['cid'] !== $clientId) {          // never trust the id sent by the browser
        flash('error', 'Appointment not found.');
    } else {
        try {
            change_status($appt, 'cancelled', 'Cancelled by ' . $me['name']);
            flash('success', 'Your appointment was cancelled.');
        } catch (RuntimeException $e) {
            flash('error', $e->getMessage());
        }
    }
    redirect('client/dashboard.php');
}

// ---- Load this client's appointments ------------------------------------
$st = db()->prepare(APPOINTMENT_SQL . ' WHERE a.client_id = ? ORDER BY a.start_at DESC');
$st->execute([$clientId]);
$all = $st->fetchAll();

// Split into "upcoming / active" and "history".
$upcoming = $history = [];
foreach ($all as $a) {
    if (in_array($a['status'], ['confirmed', 'in_progress'], true)) $upcoming[] = $a; else $history[] = $a;
}
$upcoming = array_reverse($upcoming);                         // soonest first

// Visits that are finished but not yet paid need attention.
$toPay = array_filter($all, fn($a) => $a['status'] === 'completed' && $a['pay_status'] === 'pending');

$loy    = loyalty_info($clientId);
$points = $loy['points'];

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div><h1>Hello, <?= h(explode(' ', $me['name'])[0]) ?></h1><p class="muted" style="margin:0">Here is everything about your visits.</p></div>
  <a class="btn btn-big" href="<?= h(url('client/book.php')) ?>">Book an appointment</a>
</div>

<div class="grid grid-3" style="margin-bottom:1.5rem">
  <div class="card stat" style="margin:0"><div class="num"><?= count($upcoming) ?></div><div class="lbl">Upcoming appointments</div></div>
  <div class="card stat" style="margin:0"><div class="num"><?= count($toPay) ?></div><div class="lbl">Waiting for payment</div></div>
  <div class="card stat" style="margin:0"><div class="num"><?= $points ?></div><div class="lbl">Loyalty points · <?= $loy['loyal'] ? '<b>Loyal client ★</b> (code LOYAL20)' : $loy['missing'] . ' more to become loyal' ?></div>
    <div class="bar"><span style="width:<?= min(100, (int)round($points / LOYAL_POINTS * 100)) ?>%"></span></div></div>
</div>

<div class="card">
  <h2>Upcoming</h2>
  <?php if ($upcoming): ?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Service</th><th>Stylist</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($upcoming as $a): ?>
      <tr>
        <td><b><?= h(nice_date($a['start_at'])) ?></b><br><small><?= h(nice_time($a['start_at'])) ?> – <?= h(nice_time($a['end_at'])) ?></small></td>
        <td><?= h($a['service_name']) ?><br><small><?= h(money($a['price'])) ?></small></td>
        <td><?= h($a['staff_name']) ?></td>
        <td><?= status_badge($a['status']) ?><?php if ($a['pay_status'] === 'paid'): ?><br><small>Paid in advance</small><?php endif; ?></td>
        <td class="actions">
          <?php if ($a['pay_status'] !== 'paid'): ?>
            <a class="btn btn-small" href="<?= h(url('client/pay.php?id=' . $a['appointment_id'])) ?>">Pay now</a>
          <?php endif; ?>
          <?php if ($a['status'] === 'confirmed'): ?>
            <a class="btn btn-small btn-ghost" href="<?= h(url('client/reschedule.php?id=' . $a['appointment_id'])) ?>">Reschedule</a>
            <form class="inline" method="post" onsubmit="return confirm('Cancel this appointment?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$a['appointment_id'] ?>">
              <button class="btn btn-small btn-danger">Cancel</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">Nothing booked. <a href="<?= h(url('client/book.php')) ?>">Book your next visit</a>.</p><?php endif; ?>
</div>

<div class="card">
  <h2>History</h2>
  <?php if ($history): ?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Service</th><th>Stylist</th><th>Status</th><th>Payment</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($history as $a): ?>
      <tr>
        <td><b><?= h(nice_date($a['start_at'])) ?></b><br><small><?= h(nice_time($a['start_at'])) ?></small></td>
        <td><?= h($a['service_name']) ?></td>
        <td><?= h($a['staff_name']) ?></td>
        <td><?= status_badge($a['status']) ?><?php if ($a['reason'] !== ''): ?><br><small><?= h($a['reason']) ?></small><?php endif; ?></td>
        <td><?php if ($a['pay_status']): ?><?= status_badge($a['pay_status']) ?> <small><?= h(money($a['pay_amount'])) ?></small><?php else: ?>–<?php endif; ?></td>
        <td class="actions">
          <?php if ($a['status'] === 'completed' && $a['pay_status'] === 'pending'): ?>
            <a class="btn btn-small" href="<?= h(url('client/pay.php?id=' . $a['appointment_id'])) ?>">Pay now</a>
          <?php endif; ?>
          <?php if ($a['status'] === 'completed' && !$a['review_id']): ?>
            <a class="btn btn-small btn-ghost" href="<?= h(url('client/review.php?id=' . $a['appointment_id'])) ?>">Review</a>
          <?php elseif ($a['review_id']): ?>
            <small>★ <?= (int)$a['rating'] ?>/5</small>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">Your past appointments will appear here.</p><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
