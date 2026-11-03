<?php
/**
 * admin/schedules.php  -  STAFF WORK SHIFTS  (use case "Manage Staff Schedule").
 *
 * A stylist can only be booked inside a shift. The admin does NOT enter day after day:
 *   1. "Set working hours"  : one stylist (or everyone) + a date range + the weekdays + the hours
 *                             e.g. Grace, 1 Nov to 1 Dec, Monday to Saturday, 08:00 to 18:00
 *   2. "Set days off"       : one stylist (or everyone) + a date range, e.g. holiday 24 Dec to 2 Jan
 * Below, the list of upcoming shifts can be filtered and single days can still be removed.
 */
require __DIR__ . '/../includes/bootstrap.php';
$me      = require_role('admin');
$adminId = profile_id();
$title   = 'Schedules';
$active  = 'schedules';
$pdo     = db();

$dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

/** Every date from $from to $to (inclusive) as 'Y-m-d' strings. */
function dates_between(string $from, string $to): array
{
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
        $out[] = date('Y-m-d', $t);
    }
    return $out;
}

/** Is this a real date written as YYYY-MM-DD? */
function valid_date(string $d): bool
{
    return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && date('Y-m-d', strtotime($d)) === $d;
}

/** Which stylists does the form mean? ("all" = everybody) */
function chosen_staff(PDO $pdo, string $value): array
{
    if ($value === 'all') {
        return array_map('intval', array_column($pdo->query('SELECT staff_id FROM staff')->fetchAll(), 'staff_id'));
    }
    $st = $pdo->prepare('SELECT staff_id FROM staff WHERE staff_id = ?');
    $st->execute([(int)$value]);
    return $st->fetch() ? [(int)$value] : [];
}

/**
 * Confirmed / in-progress appointments of this stylist on this day that would NOT fit
 * inside the hours $from-$to (so changing the shift would strand a client).
 */
function stranded_appointments(PDO $pdo, int $staffId, string $day, string $from, string $to): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM appointments
                         WHERE staff_id = ? AND status IN ('confirmed', 'in_progress')
                           AND start_at >= ? AND start_at < ?
                           AND NOT (start_at >= ? AND end_at <= ?)");
    $st->execute([$staffId, $day . ' 00:00:00', date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00', $day . ' ' . $from, $day . ' ' . $to]);
    return (int)$st->fetchColumn();
}

if (is_post()) {
    csrf_check();
    $action = post('action');

    /* ---------- 1. SET WORKING HOURS for a range of dates ---------- */
    if ($action === 'pattern') {
        $who  = chosen_staff($pdo, post('staff_id'));
        $from = post('from'); $to = post('to');
        $days = array_map('intval', (array)($_POST['days'] ?? []));
        $start = post('start_time'); $end = post('end_time');
        $replace = isset($_POST['replace']);

        if (!$who)                                                        flash('error', 'Please choose a stylist.');
        elseif (!valid_date($from) || !valid_date($to))                   flash('error', 'Please choose the first and last date.');
        elseif ($to < date('Y-m-d'))                                      flash('error', 'The last date is already in the past.');
        elseif ($from > $to)                                              flash('error', 'The first date must not be after the last date.');
        elseif (strtotime($to) - strtotime($from) > 366 * 86400)          flash('error', 'Please use a range of at most one year.');
        elseif (!$days)                                                   flash('error', 'Tick at least one weekday.');
        elseif (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end)) flash('error', 'Please fill in the working hours.');
        elseif ($start >= $end)                                           flash('error', 'The working day must start before it ends.');
        else {
            $from = max($from, date('Y-m-d'));                            // nothing is created in the past
            $created = 0; $skippedBusy = 0; $skippedExisting = 0;
            $pdo->beginTransaction();
            foreach (dates_between($from, $to) as $day) {
                if (!in_array((int)date('N', strtotime($day)), $days, true)) continue;
                foreach ($who as $sid) {
                    if ($replace) {
                        // Replace what is there, unless a booked client would be left outside the new hours.
                        if (stranded_appointments($pdo, $sid, $day, $start . ':00', $end . ':00') > 0) { $skippedBusy++; continue; }
                        $pdo->prepare('DELETE FROM staff_schedule WHERE staff_id = ? AND work_date = ?')->execute([$sid, $day]);
                    } else {
                        // Keep what is there: only add where the new hours do not overlap an existing shift.
                        $st = $pdo->prepare('SELECT COUNT(*) FROM staff_schedule WHERE staff_id = ? AND work_date = ? AND start_time < ? AND end_time > ?');
                        $st->execute([$sid, $day, $end . ':00', $start . ':00']);
                        if ((int)$st->fetchColumn() > 0) { $skippedExisting++; continue; }
                    }
                    $pdo->prepare('INSERT INTO staff_schedule (staff_id, admin_id, work_date, start_time, end_time) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$sid, $adminId, $day, $start . ':00', $end . ':00']);
                    $created++;
                }
            }
            $pdo->commit();
            $msg = $created . ' working day' . ($created === 1 ? '' : 's') . ' saved (' . $start . '–' . $end . ').';
            if ($skippedBusy)     $msg .= ' ' . $skippedBusy . ' skipped because clients are already booked outside those hours.';
            if ($skippedExisting) $msg .= ' ' . $skippedExisting . ' skipped because a shift already exists (tick "replace" to overwrite).';
            flash($created ? 'success' : 'error', $created ? $msg : 'Nothing was saved. ' . $msg);
        }

    /* ---------- 2. SET DAYS OFF for a range of dates ---------- */
    } elseif ($action === 'dayoff') {
        $who  = chosen_staff($pdo, post('staff_id'));
        $from = post('from'); $to = post('to');
        if (!$who)                                                        flash('error', 'Please choose a stylist.');
        elseif (!valid_date($from) || !valid_date($to))                   flash('error', 'Please choose the first and last day off.');
        elseif ($to < date('Y-m-d'))                                      flash('error', 'Those days are already in the past.');
        elseif ($from > $to)                                              flash('error', 'The first day must not be after the last day.');
        elseif (strtotime($to) - strtotime($from) > 366 * 86400)          flash('error', 'Please use a range of at most one year.');
        else {
            $from = max($from, date('Y-m-d'));
            $off = 0; $skipped = 0;
            $pdo->beginTransaction();
            foreach (dates_between($from, $to) as $day) {
                foreach ($who as $sid) {
                    // A day off means "no shift". It is refused when the stylist still has a client that day.
                    if (stranded_appointments($pdo, $sid, $day, '00:00:00', '00:00:00') > 0) { $skipped++; continue; }
                    $st = $pdo->prepare('DELETE FROM staff_schedule WHERE staff_id = ? AND work_date = ?');
                    $st->execute([$sid, $day]);
                    $off += $st->rowCount();
                }
            }
            $pdo->commit();
            $msg = $off . ' shift' . ($off === 1 ? '' : 's') . ' removed (days off set).';
            if ($skipped) $msg .= ' ' . $skipped . ' skipped because clients are booked on those days: cancel or reassign them first.';
            flash($skipped && !$off ? 'error' : 'success', $msg);
        }

    /* ---------- 3. REMOVE ONE SHIFT ---------- */
    } elseif ($action === 'delete') {
        $st = $pdo->prepare('SELECT * FROM staff_schedule WHERE schedule_id = ?');
        $st->execute([(int)post('id')]);
        $shift = $st->fetch();
        if ($shift) {
            if (stranded_appointments($pdo, (int)$shift['staff_id'], $shift['work_date'], '00:00:00', '00:00:00') > 0) {
                flash('error', 'This day still has appointments. Cancel or reassign them first.');
            } else {
                $pdo->prepare('DELETE FROM staff_schedule WHERE schedule_id = ?')->execute([$shift['schedule_id']]);
                flash('success', 'Shift removed.');
            }
        }
    }
    redirect('admin/schedules.php');
}

// ---- Data for the page ------------------------------------------------------
$staffList = $pdo->query('SELECT st.staff_id, st.specialization, u.name FROM staff st JOIN users u ON u.user_id = st.user_id ORDER BY u.name')->fetchAll();

// List filter: stylist + date range (default: the next 30 days)
$fStaff = $_GET['staff'] ?? 'all';
$fFrom  = valid_date($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d');
$fTo    = valid_date($_GET['to'] ?? '')   ? $_GET['to']   : date('Y-m-d', strtotime('+30 days'));
$sql = 'SELECT sc.*, u.name FROM staff_schedule sc JOIN staff st ON st.staff_id = sc.staff_id JOIN users u ON u.user_id = st.user_id
        WHERE sc.work_date >= ? AND sc.work_date <= ?';
$args = [$fFrom, $fTo];
if ($fStaff !== 'all') { $sql .= ' AND sc.staff_id = ?'; $args[] = (int)$fStaff; }
$st = $pdo->prepare($sql . ' ORDER BY sc.work_date, u.name, sc.start_time LIMIT 300');
$st->execute($args);
$shifts = $st->fetchAll();

$staffOptions = function (bool $withAll) use ($staffList): string {
    $o = $withAll ? '<option value="all">All stylists</option>' : '';
    foreach ($staffList as $s) $o .= '<option value="' . (int)$s['staff_id'] . '">' . h($s['name']) . ' (' . h($s['specialization']) . ')</option>';
    return $o;
};

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1>Staff schedules</h1></div>

<div class="grid grid-2" style="align-items:start">
  <!-- 1. Working hours for a whole range -->
  <div class="card" style="margin:0">
    <h2>Set working hours</h2>
    <p class="hint" style="margin-top:0">Example: Grace works Monday to Saturday, 08:00 to 18:00, from 1 Nov to 1 Dec.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="pattern">
      <div class="field"><label for="p_staff">Stylist</label><select id="p_staff" name="staff_id"><?= $staffOptions(true) ?></select></div>
      <div class="form-grid">
        <div class="field"><label for="p_from">From date</label><input type="date" id="p_from" name="from" value="<?= h(date('Y-m-d')) ?>" min="<?= h(date('Y-m-d')) ?>" required></div>
        <div class="field"><label for="p_to">To date</label><input type="date" id="p_to" name="to" value="<?= h(date('Y-m-d', strtotime('+30 days'))) ?>" min="<?= h(date('Y-m-d')) ?>" required></div>
      </div>
      <div class="field"><label>Works on</label>
        <div class="weekdays">
          <?php foreach ($dayNames as $n => $label): ?>
            <label class="wd"><input type="checkbox" name="days[]" value="<?= $n ?>" <?= $n <= 6 ? 'checked' : '' ?>><span><?= $label ?></span></label>
          <?php endforeach; ?>
        </div></div>
      <div class="form-grid">
        <div class="field"><label for="p_start">From hour</label><input type="time" id="p_start" name="start_time" value="08:00" required></div>
        <div class="field"><label for="p_end">To hour</label><input type="time" id="p_end" name="end_time" value="18:00" required></div>
      </div>
      <label class="check"><input type="checkbox" name="replace" checked> Replace any shifts already set on those days</label>
      <button class="btn" style="width:100%;margin-top:.8rem">Save working hours</button>
    </form>
  </div>

  <!-- 2. Days off for a whole range -->
  <div class="card" style="margin:0">
    <h2>Set days off</h2>
    <p class="hint" style="margin-top:0">Holiday or leave: the stylist cannot be booked on these days.</p>
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="action" value="dayoff">
      <div class="field"><label for="o_staff">Stylist</label><select id="o_staff" name="staff_id"><?= $staffOptions(true) ?></select></div>
      <div class="form-grid">
        <div class="field"><label for="o_from">First day off</label><input type="date" id="o_from" name="from" min="<?= h(date('Y-m-d')) ?>" required></div>
        <div class="field"><label for="o_to">Last day off</label><input type="date" id="o_to" name="to" min="<?= h(date('Y-m-d')) ?>" required></div>
      </div>
      <button class="btn btn-danger" style="width:100%">Set days off</button>
      <p class="hint">Days where the stylist already has a booked client are skipped, so nobody is left without a stylist.</p>
    </form>
  </div>
</div>

<div class="card" style="margin-top:1.2rem">
  <div class="row spread" style="align-items:flex-end">
    <h2 style="margin:0">Shifts</h2>
    <form method="get" class="row" style="align-items:flex-end">
      <div><label for="f_staff">Stylist</label><select id="f_staff" name="staff" style="width:auto">
        <option value="all">All stylists</option>
        <?php foreach ($staffList as $s): ?><option value="<?= (int)$s['staff_id'] ?>" <?= (string)$fStaff === (string)$s['staff_id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
      </select></div>
      <div><label for="f_from">From</label><input type="date" id="f_from" name="from" value="<?= h($fFrom) ?>"></div>
      <div><label for="f_to">To</label><input type="date" id="f_to" name="to" value="<?= h($fTo) ?>"></div>
      <button class="btn btn-small btn-light">Filter</button>
    </form>
  </div>
  <?php if ($shifts): ?>
  <div class="table-wrap" style="margin-top:.8rem"><table>
    <thead><tr><th>Date</th><th>Stylist</th><th>Hours</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($shifts as $s): ?>
      <tr>
        <td><b><?= h(nice_date($s['work_date'] . ' 00:00:00')) ?></b></td>
        <td><?= h($s['name']) ?></td>
        <td><?= h(substr($s['start_time'], 0, 5)) ?> – <?= h(substr($s['end_time'], 0, 5)) ?></td>
        <td class="actions"><form class="inline" method="post" onsubmit="return confirm('Remove this day?')"><?= csrf_field() ?>
          <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$s['schedule_id'] ?>"><button class="btn btn-small btn-danger">Remove</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php else: ?><p class="empty">No shifts in this period. Use “Set working hours” above.</p><?php endif; ?>
</div>
<style>
  .weekdays { display: flex; gap: .4rem; flex-wrap: wrap; }
  .wd { margin: 0; cursor: pointer; }
  .wd input { position: absolute; opacity: 0; }
  .wd span { display: inline-block; padding: .4rem .75rem; border: 1px solid var(--line); border-radius: 999px; font-weight: 600; font-size: .88rem; background: #fff; }
  .wd input:checked + span { background: var(--accent); border-color: var(--accent); color: #fff; }
  .wd input:focus-visible + span { outline: 2px solid var(--accent-d); outline-offset: 2px; }
  .check { font-weight: 500; display: flex; gap: .5rem; align-items: center; }
</style>
<?php require __DIR__ . '/../includes/footer.php'; ?>
