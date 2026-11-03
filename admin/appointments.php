<?php
/**
 * admin/appointments.php  -  every appointment, with filters.
 * Admin actions: hand a confirmed appointment to another stylist, cancel it, or MARK IT DONE
 * (the service took place: the invoice is created so the client can pay and rate).
 */
require __DIR__ . '/../includes/bootstrap.php';
$me     = require_role('admin');
$title  = 'Appointments';
$active = 'appointments';
$pdo    = db();

// ---- Actions -----------------------------------------------------------
if (is_post()) {
    csrf_check();
    $appt = get_appointment((int)post('id'));
    try {
        if (!$appt) throw new RuntimeException('Appointment not found.');
        if (post('action') === 'assign') {
            reassign_appointment($appt, (int)post('staff_id'));
            flash('success', 'The appointment was handed to another stylist.');
        } elseif (post('action') === 'done') {
            // Same steps as the stylist: check-in (if needed), then complete and create the invoice.
            if ($appt['status'] === 'confirmed') {
                change_status($appt, 'in_progress');
                $appt = get_appointment((int)$appt['appointment_id']);
            }
            change_status($appt, 'completed');
            create_invoice($appt);
            flash('success', 'Marked as done. The client can now pay (if not yet paid) and leave a review.');
        } elseif (post('action') === 'cancel') {
            change_status($appt, 'cancelled', 'Cancelled by the salon');
            flash('success', 'The appointment was cancelled and both people were notified.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin/appointments.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'from', 'to']))));
}

// ---- Filters (default: from today onward) ------------------------------
$fStatus = $_GET['status'] ?? '';
$fFrom   = array_key_exists('from', $_GET) ? $_GET['from'] : date('Y-m-d');
$fTo     = $_GET['to'] ?? '';
$where = []; $params = [];
if (in_array($fStatus, array_keys(STATUS_FLOW), true)) { $where[] = 'a.status = ?';   $params[] = $fStatus; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom))        { $where[] = 'a.start_at >= ?'; $params[] = $fFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))          { $where[] = 'a.start_at < ?';  $params[] = date('Y-m-d', strtotime($fTo . ' +1 day')) . ' 00:00:00'; }

$st = $pdo->prepare(APPOINTMENT_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY a.start_at ' . ($fFrom === '' ? 'DESC' : 'ASC') . ' LIMIT 200');
$st->execute($params);
$appts = $st->fetchAll();

$staffList = $pdo->query('SELECT st.staff_id, st.specialization, u.name FROM staff st JOIN users u ON u.user_id = st.user_id ORDER BY u.name')->fetchAll();

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1>Appointments</h1></div>

<form class="card row" method="get" style="align-items:flex-end">
  <div><label for="status">Status</label>
    <select id="status" name="status" style="width:auto"><option value="">All</option>
      <?php foreach (array_keys(STATUS_FLOW) as $s): ?><option value="<?= h($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= h(ucfirst(str_replace('_', ' ', $s))) ?></option><?php endforeach; ?>
    </select></div>
  <div><label for="from">From</label><input type="date" id="from" name="from" value="<?= h($fFrom) ?>"></div>
  <div><label for="to">To</label><input type="date" id="to" name="to" value="<?= h($fTo) ?>"></div>
  <button class="btn">Filter</button>
  <a class="btn btn-ghost" href="?from=">Show all</a>
</form>

<div class="card">
  <?php if ($appts): ?>
  <div class="table-wrap"><table>
    <thead><tr><th>When</th><th>Client</th><th>Service</th><th>Stylist</th><th>Status</th><th>Payment</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($appts as $a):
        // Stylists who could take this appointment over: same category, not the current one, and free then.
        $others = [];
        if ($a['status'] === 'confirmed') {
            foreach ($staffList as $s) {
                if ((int)$s['staff_id'] !== (int)$a['sid'] && strcasecmp($s['specialization'], $a['category']) === 0
                    && staff_is_free((int)$s['staff_id'], $a['start_at'], $a['end_at'], (int)$a['appointment_id'])) $others[] = $s;
            }
        } ?>
      <tr>
        <td><b><?= h(nice_date($a['start_at'])) ?></b><br><small><?= h(nice_time($a['start_at'])) ?> – <?= h(nice_time($a['end_at'])) ?></small></td>
        <td><?= h($a['client_name']) ?></td>
        <td><?= h($a['service_name']) ?></td>
        <td><?= h($a['staff_name']) ?></td>
        <td><?= status_badge($a['status']) ?><?php if ($a['reason'] !== ''): ?><br><small><?= h($a['reason']) ?></small><?php endif; ?></td>
        <td><?php if ($a['pay_status']): ?><?= status_badge($a['pay_status']) ?><?php else: ?>–<?php endif; ?></td>
        <td class="actions">
          <?php if ($a['status'] === 'confirmed'): ?>
            <?php if ($others): ?>
              <form class="inline row" method="post" style="display:inline-flex"><?= csrf_field() ?>
                <input type="hidden" name="action" value="assign"><input type="hidden" name="id" value="<?= (int)$a['appointment_id'] ?>">
                <select name="staff_id" style="width:auto;padding:.3rem .5rem"><?php foreach ($others as $o): ?><option value="<?= (int)$o['staff_id'] ?>"><?= h($o['name']) ?></option><?php endforeach; ?></select>
                <button class="btn btn-small btn-light">Reassign</button></form>
            <?php endif; ?>
            <form class="inline" method="post" onsubmit="return confirm('Mark this appointment as done?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="done"><input type="hidden" name="id" value="<?= (int)$a['appointment_id'] ?>"><button class="btn btn-small">Mark done</button></form>
            <form class="inline" method="post" onsubmit="return confirm('Cancel this appointment?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$a['appointment_id'] ?>"><button class="btn btn-small btn-danger">Cancel</button></form>
          <?php elseif ($a['status'] === 'in_progress'): ?>
            <form class="inline" method="post" onsubmit="return confirm('Mark this appointment as done?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="done"><input type="hidden" name="id" value="<?= (int)$a['appointment_id'] ?>"><button class="btn btn-small">Mark done</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">No appointments match these filters.</p><?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
