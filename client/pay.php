<?php
/**
 * client/pay.php  -  pay for an appointment (invoice, optional promo code, payment method).
 * Allowed once the appointment is confirmed (pay in advance), in progress, or completed.
 */
require __DIR__ . '/../includes/bootstrap.php';
$me       = require_role('client');
$clientId = profile_id();
$title    = 'Pay';
$active   = 'appointments';

$appt = get_appointment((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$appt || (int)$appt['cid'] !== $clientId || !in_array($appt['status'], ['confirmed', 'in_progress', 'completed'], true) || $appt['pay_status'] === 'paid') {
    flash('error', 'There is nothing to pay for that appointment.');
    redirect('client/dashboard.php');
}

if (is_post()) {
    csrf_check();
    try {
        pay_appointment($appt, post('method'), post('promo'));
        flash('success', 'Payment received. Thank you!' . ($appt['status'] === 'completed' ? ' Please consider leaving a review.' : ''));
        redirect('client/dashboard.php');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
}

$promos = promos_for($clientId);
$loy    = loyalty_info($clientId);
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1>Pay for your visit</h1></div>
<div class="card invoice">
  <h2>Invoice</h2>
  <div class="line"><span><?= h($appt['service_name']) ?></span><span><?= h(money($appt['price'])) ?></span></div>
  <div class="line"><span>Stylist</span><span><?= h($appt['staff_name']) ?></span></div>
  <div class="line"><span>Date</span><span><?= h(nice_date($appt['start_at'])) ?></span></div>
  <div class="line total"><span>Total</span><span><?= h(money($appt['price'])) ?></span></div>

  <form method="post" style="margin-top:1.2rem">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$appt['appointment_id'] ?>">
    <div class="field"><label for="method">Payment method</label>
      <select id="method" name="method" required>
        <option value="">Choose…</option><option value="cash">Cash</option><option value="card">Card</option><option value="mobile_money">Mobile money</option>
      </select></div>
    <div class="field"><label for="promo">Promo code <small>(optional)</small></label>
      <input type="text" id="promo" name="promo" value="<?= h(post('promo')) ?>" placeholder="e.g. WELCOME10">
      <div class="hint">
        <?php if ($promos): ?>Your codes: <?php foreach ($promos as $c => $pc): ?><b><?= h($c) ?></b> (<?= (int)$pc ?>% off) <?php endforeach; ?>
        <?php else: ?>No promo code available right now.<?php endif; ?>
        <?php if (!$loy['loyal']): ?><br>Collect <?= (int)$loy['missing'] ?> more loyalty point<?= $loy['missing'] === 1 ? '' : 's' ?> to become a loyal client and unlock LOYAL20.<?php endif; ?>
        <br>You earn 1 loyalty point per 1,000 RWF paid.</div></div>
    <?php if ($appt['status'] !== 'completed'): ?><p class="hint">You are paying in advance: your appointment on <?= h(nice_date($appt['start_at'])) ?> stays booked.</p><?php endif; ?>
    <div class="row"><button class="btn">Pay now</button><a class="btn btn-ghost" href="<?= h(url('client/dashboard.php')) ?>">Later</a></div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
