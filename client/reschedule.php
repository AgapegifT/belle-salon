<?php
/**
 * client/reschedule.php  -  move a CONFIRMED appointment to another time (same stylist, same service).
 */
require __DIR__ . '/../includes/bootstrap.php';
$me       = require_role('client');
$clientId = profile_id();
$title    = 'Reschedule';
$active   = 'appointments';

$appt = get_appointment((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$appt || (int)$appt['cid'] !== $clientId || $appt['status'] !== 'confirmed') {
    flash('error', 'Only your own confirmed appointments can be moved.');
    redirect('client/dashboard.php');
}

$date = post('date', substr($appt['start_at'], 0, 10));
$time = post('time', substr($appt['start_at'], 11, 5));
$alternatives = [];

if (is_post()) {
    csrf_check();
    $start = $date . ' ' . $time . ':00';
    $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{2}:\d{2}$/', $time) && strtotime($start) !== false;
    $end   = $valid ? date('Y-m-d H:i:s', strtotime($start) + $appt['duration_minutes'] * 60) : '';

    if (!$valid)                         flash('error', 'Please choose a valid date and time.');
    elseif (strtotime($start) <= time()) flash('error', 'Please choose a time in the future.');
    elseif (staff_is_free((int)$appt['sid'], $start, $end, (int)$appt['appointment_id'])) {
        db()->prepare('UPDATE appointments SET start_at = ?, end_at = ? WHERE appointment_id = ?')
            ->execute([$start, $end, $appt['appointment_id']]);
        // Tell both people about the move.
        $msg = $appt['service_name'] . ' moved from ' . nice_date($appt['start_at']) . ' ' . nice_time($appt['start_at'])
             . ' to ' . nice_date($start) . ' ' . nice_time($start);
        notify((int)$appt['client_user_id'], 'Appointment moved: ' . $msg, (int)$appt['appointment_id']);
        notify((int)$appt['staff_user_id'],  'Appointment moved: ' . $msg . ' (' . $appt['client_name'] . ')', (int)$appt['appointment_id']);
        flash('success', 'Your appointment was moved.');
        redirect('client/dashboard.php');
    } else {
        flash('error', $appt['staff_name'] . ' is not free then. Try one of the times below.');
        $alternatives = alternative_slots((int)$appt['sid'], (int)$appt['duration_minutes'], $start, (int)$appt['appointment_id']);
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1>Reschedule</h1></div>
<div class="card" style="max-width:560px">
  <div class="summary"><b><?= h($appt['service_name']) ?></b> with <?= h($appt['staff_name']) ?><br>
    Now: <?= h(nice_date($appt['start_at'])) ?> at <?= h(nice_time($appt['start_at'])) ?></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$appt['appointment_id'] ?>">
    <div class="form-grid">
      <div class="field"><label for="date">New date</label>
        <input type="date" id="date" name="date" value="<?= h($date) ?>" min="<?= h(date('Y-m-d')) ?>" required></div>
      <div class="field"><label for="time">New time</label>
        <select id="time" name="time"><?php foreach (time_options() as $t): ?><option <?= $t === $time ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="row"><button class="btn">Move appointment</button><a class="btn btn-ghost" href="<?= h(url('client/dashboard.php')) ?>">Back</a></div>
  </form>
  <?php if ($alternatives): ?>
    <hr style="border:0;border-top:1px solid var(--line);margin:1.4rem 0">
    <b>Free times that day:</b>
    <div class="slot-list">
      <?php foreach ($alternatives as $alt): ?>
        <form method="post" class="inline"><?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$appt['appointment_id'] ?>">
          <input type="hidden" name="date" value="<?= h(substr($alt, 0, 10)) ?>"><input type="hidden" name="time" value="<?= h(substr($alt, 11, 5)) ?>">
          <button class="btn btn-light"><?= h(nice_time($alt)) ?></button></form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
