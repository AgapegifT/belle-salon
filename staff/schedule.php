<?php
/**
 * staff/schedule.php  -  the stylist's day: shift, appointments, Check-in and Complete buttons.
 */
require __DIR__ . '/../includes/bootstrap.php';
$me      = require_role('staff');
$staffId = profile_id();
$title   = 'My schedule';
$active  = 'schedule';
$pdo     = db();

// Which day are we looking at? (default: today)
$day = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) $day = date('Y-m-d');

// ---- Check-in / Complete buttons ---------------------------------------
if (is_post()) {
    csrf_check();
    $action = post('action');
    $appt   = get_appointment((int)post('id'));
    try {
        if (!$appt || (int)$appt['sid'] !== $staffId) {
            throw new RuntimeException('Appointment not found.');
        }
        if ($action === 'checkin') {
            // Activity diagram decision "Arrived on time?" : later than the grace period = no-show.
            if (substr($appt['start_at'], 0, 10) !== date('Y-m-d')) {
                throw new RuntimeException('You can only check clients in on the day of the appointment.');
            }
            if (time() > strtotime($appt['start_at']) + GRACE_MINUTES * 60) {
                change_status($appt, 'cancelled', 'No-show: arrived after the ' . GRACE_MINUTES . '-minute grace period');
                flash('error', $appt['client_name'] . ' arrived too late: the appointment was cancelled as a no-show.');
            } else {
                change_status($appt, 'in_progress');
                flash('success', $appt['client_name'] . ' is checked in.');
            }
        } elseif ($action === 'complete') {
            change_status($appt, 'completed');
            create_invoice($appt);                            // the client can now pay
            flash('success', 'Service completed. The invoice was sent to ' . $appt['client_name'] . '.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('staff/schedule.php?date=' . $day);
}

// ---- Load the day -------------------------------------------------------
$st = $pdo->prepare('SELECT start_time, end_time FROM staff_schedule WHERE staff_id = ? AND work_date = ? ORDER BY start_time');
$st->execute([$staffId, $day]);
$shifts = $st->fetchAll();

$st = $pdo->prepare(APPOINTMENT_SQL . " WHERE a.staff_id = ? AND a.start_at >= ? AND a.start_at < ? AND a.status <> 'rejected' ORDER BY a.start_at");
$st->execute([$staffId, $day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00']);
$appts = $st->fetchAll();

$isToday = $day === date('Y-m-d');
$prev = date('Y-m-d', strtotime($day . ' -1 day'));
$next = date('Y-m-d', strtotime($day . ' +1 day'));

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div><h1><?= $isToday ? 'Today' : h(nice_date($day . ' 00:00:00')) ?></h1>
    <p class="muted" style="margin:0"><?= h(nice_date($day . ' 00:00:00')) ?> ·
      <?php if ($shifts): foreach ($shifts as $i => $s): ?><?= $i ? ', ' : '' ?>shift <?= h(substr($s['start_time'], 0, 5)) ?>–<?= h(substr($s['end_time'], 0, 5)) ?><?php endforeach; else: ?>not working<?php endif; ?></p></div>
  <div class="row">
    <a class="btn btn-ghost btn-small" href="?date=<?= h($prev) ?>">← Previous</a>
    <?php if (!$isToday): ?><a class="btn btn-ghost btn-small" href="?date=<?= h(date('Y-m-d')) ?>">Today</a><?php endif; ?>
    <a class="btn btn-ghost btn-small" href="?date=<?= h($next) ?>">Next →</a>
    <form method="get" class="inline"><input type="date" name="date" value="<?= h($day) ?>" onchange="this.form.submit()" style="width:auto"></form>
  </div>
</div>

<div class="card">
  <?php if ($appts): ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Time</th><th>Client</th><th>Service</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($appts as $a): ?>
      <tr>
        <td><b><?= h(nice_time($a['start_at'])) ?></b> – <?= h(nice_time($a['end_at'])) ?></td>
        <td><?= h($a['client_name']) ?></td>
        <td><?= h($a['service_name']) ?></td>
        <td><?= status_badge($a['status']) ?><?php if ($a['reason'] !== ''): ?><br><small><?= h($a['reason']) ?></small><?php endif; ?></td>
        <td class="actions">
          <?php if ($a['status'] === 'confirmed' && $isToday): ?>
            <form class="inline" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="checkin"><input type="hidden" name="id" value="<?= (int)$a['appointment_id'] ?>"><button class="btn btn-small">Check in</button></form>
          <?php elseif ($a['status'] === 'in_progress'): ?>
            <form class="inline" method="post" onsubmit="return confirm('Mark this service as completed?')"><?= csrf_field() ?><input type="hidden" name="action" value="complete"><input type="hidden" name="id" value="<?= (int)$a['appointment_id'] ?>"><button class="btn btn-small">Complete service</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">No appointments on this day.</p><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
