<?php
/**
 * admin/dashboard.php  -  overview and the REVENUE REPORT for any date range.
 */
require __DIR__ . '/../includes/bootstrap.php';
$me     = require_role('admin');
$title  = 'Dashboard';
$active = 'dashboard';
$pdo    = db();

// Report range. Default: from the 1st of this month up to 60 days ahead, so that appointments
// booked for the coming days are counted too (the old default stopped at today).
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d', strtotime('+' . BOOK_DAYS_AHEAD . ' days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d', strtotime('+' . BOOK_DAYS_AHEAD . ' days'));
if ($to < $from) { [$from, $to] = [$to, $from]; }
$lo = $from . ' 00:00:00';
$hi = date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00';          // end of the "to" day

/** Run a query and return all rows. */
function rows(string $sql, array $params): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

// Appointments per status in the range.
$byStatus = [];
foreach (rows('SELECT status, COUNT(*) AS n FROM appointments WHERE start_at >= ? AND start_at < ? GROUP BY status', [$lo, $hi]) as $r) {
    $byStatus[$r['status']] = (int)$r['n'];
}
$totalAppts = array_sum($byStatus);

// Revenue = money collected (paid payments) whose PAYMENT date is in the range.
$payJoin = " FROM payments p JOIN appointments a ON a.appointment_id = p.appointment_id
             JOIN services s ON s.service_id = a.service_id
             JOIN staff st ON st.staff_id = a.staff_id JOIN users u ON u.user_id = st.user_id
             WHERE p.status = 'paid' AND p.paid_at >= ? AND p.paid_at < ? ";
$byService = rows('SELECT s.name AS label, COUNT(*) AS n, SUM(p.amount) AS total' . $payJoin . 'GROUP BY s.service_id, s.name ORDER BY total DESC', [$lo, $hi]);
$byStylist = rows('SELECT u.name AS label, COUNT(*) AS n, SUM(p.amount) AS total' . $payJoin . 'GROUP BY st.staff_id, u.name ORDER BY total DESC', [$lo, $hi]);
$revenue   = array_sum(array_column($byService, 'total'));

// WHAT SELLS: how often each service was booked (cancelled / rejected ones do not count).
$popular = rows("SELECT s.name AS label, COUNT(*) AS n FROM appointments a JOIN services s ON s.service_id = a.service_id
                 WHERE a.status IN ('confirmed', 'in_progress', 'completed') AND a.start_at >= ? AND a.start_at < ?
                 GROUP BY s.service_id, s.name ORDER BY n DESC", [$lo, $hi]);
$popularMax = $popular ? max(array_column($popular, 'n')) : 1;

$rating = rows('SELECT AVG(r.rating) AS avg_rating, COUNT(*) AS n FROM reviews r WHERE r.created_at >= ? AND r.created_at < ?', [$lo, $hi])[0];

// The newest bookings, whatever the range (so a booking made a minute ago is visible right away).
$latest = $pdo->query(APPOINTMENT_SQL . ' ORDER BY a.appointment_id DESC LIMIT 8')->fetchAll();
$loyalClients = (int)$pdo->query('SELECT COUNT(*) FROM clients WHERE loyalty_points >= ' . (int)LOYAL_POINTS)->fetchColumn();
$clients = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();

// Warn if nobody has any future work shift (clients could not book anything).
$st = $pdo->prepare('SELECT COUNT(*) FROM staff_schedule WHERE work_date >= ?');
$st->execute([date('Y-m-d')]);
$noShifts = (int)$st->fetchColumn() === 0;

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><div><h1>Dashboard</h1><p class="muted" style="margin:0">Welcome, <?= h($me['name']) ?>.</p></div></div>

<?php if ($noShifts): ?>
  <div class="alert alert-info">No work shifts are set for the coming days, so clients cannot book yet.
    <a href="<?= h(url('admin/schedules.php')) ?>"><b>Set working hours →</b></a></div>
<?php endif; ?>

<form class="card row" method="get" style="align-items:flex-end">
  <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= h($from) ?>"></div>
  <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= h($to) ?>"></div>
  <button class="btn">Show report</button>
  <div class="presets">
    <a class="btn btn-small btn-light" href="?from=<?= h(date('Y-m-01')) ?>&to=<?= h(date('Y-m-t')) ?>">This month</a>
    <a class="btn btn-small btn-light" href="?from=<?= h(date('Y-m-d')) ?>&to=<?= h(date('Y-m-d', strtotime('+30 days'))) ?>">Next 30 days</a>
    <a class="btn btn-small btn-light" href="?from=2000-01-01&to=2100-12-31">All time</a>
  </div>
</form>

<div class="grid grid-4" style="margin-bottom:1.5rem">
  <div class="card stat" style="margin:0"><div class="num"><?= $totalAppts ?></div><div class="lbl">Appointments in this period</div></div>
  <div class="card stat" style="margin:0"><div class="num"><?= h(money($revenue)) ?></div><div class="lbl">Revenue collected</div></div>
  <div class="card stat" style="margin:0"><div class="num"><?= $rating['n'] ? h(number_format((float)$rating['avg_rating'], 1)) . ' ★' : '–' ?></div><div class="lbl">Average rating (<?= (int)$rating['n'] ?> reviews)</div></div>
  <div class="card stat" style="margin:0"><div class="num"><?= $clients ?></div><div class="lbl">Registered clients · <?= $loyalClients ?> loyal</div></div>
</div>

<div class="grid grid-2" style="margin-bottom:1.2rem">
  <div class="card">
    <h2>Appointments by status</h2>
    <?php foreach (['requested', 'confirmed', 'in_progress', 'completed', 'cancelled', 'rejected'] as $s): ?>
      <div class="row spread" style="padding:.3rem 0"><?= status_badge($s) ?><b><?= $byStatus[$s] ?? 0 ?></b></div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h2>Most booked services</h2>
    <?php foreach ($popular as $r): ?>
      <div style="padding:.3rem 0"><div class="row spread"><span><?= h($r['label']) ?></span><b><?= (int)$r['n'] ?></b></div>
        <div class="meter"><span style="width:<?= (int)round($r['n'] / $popularMax * 100) ?>%"></span></div></div>
    <?php endforeach; ?>
    <?php if (!$popular): ?><p class="empty">No bookings in this period.</p><?php endif; ?>
  </div>
  <div class="card">
    <h2>Revenue by service</h2>
    <?php foreach ($byService as $r): ?>
      <div class="row spread" style="padding:.3rem 0"><span><?= h($r['label']) ?> <small>×<?= (int)$r['n'] ?></small></span><b><?= h(money($r['total'])) ?></b></div>
    <?php endforeach; ?>
    <?php if (!$byService): ?><p class="empty">No payments in this range.</p><?php endif; ?>
  </div>
  <div class="card">
    <h2>Revenue by stylist</h2>
    <?php foreach ($byStylist as $r): ?>
      <div class="row spread" style="padding:.3rem 0"><span><?= h($r['label']) ?> <small>×<?= (int)$r['n'] ?></small></span><b><?= h(money($r['total'])) ?></b></div>
    <?php endforeach; ?>
    <?php if (!$byStylist): ?><p class="empty">No payments in this range.</p><?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="row spread"><h2 style="margin:0">Latest bookings</h2><a href="<?= h(url('admin/appointments.php')) ?>">All appointments →</a></div>
  <?php if ($latest): ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Booked on</th><th>Client</th><th>Service</th><th>Stylist</th><th>Appointment</th><th>Status</th><th>Payment</th></tr></thead>
    <tbody>
    <?php foreach ($latest as $a): ?>
      <tr><td><?= h(nice_date($a['created_at'])) ?> <small><?= h(nice_time($a['created_at'])) ?></small></td>
        <td><?= h($a['client_name']) ?></td><td><?= h($a['service_name']) ?></td><td><?= h($a['staff_name']) ?></td>
        <td><?= h(nice_date($a['start_at'])) ?> <small><?= h(nice_time($a['start_at'])) ?></small></td>
        <td><?= status_badge($a['status']) ?></td>
        <td><?= $a['pay_status'] ? status_badge($a['pay_status']) . ' <small>' . h(money($a['pay_amount'])) . '</small>' : '–' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">No bookings yet.</p><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
