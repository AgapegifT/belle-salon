<?php
/**
 * client/book.php  -  BOOK AN APPOINTMENT  (the Sequence diagram, step by step)
 *
 *   Step 1  choose a service        (links: book.php?service=ID)
 *   Step 2  the client SEES who is free: pick a day from the day strip, then every stylist
 *           who offers the service is shown with the free times of that day. One click on a
 *           time books it.
 *   On click the server checks availability AGAIN (someone else may have just taken the time):
 *             free     -> the appointment is saved and CONFIRMED, both people are notified
 *             not free -> nothing is saved and the fresh list of free times is shown
 */
require __DIR__ . '/../includes/bootstrap.php';
$me       = require_role('client');
$clientId = profile_id();
$title    = 'Book an appointment';
$active   = 'book';
$pdo      = db();

// Step 1: the services the client can pick from.
$services = $pdo->query('SELECT * FROM services WHERE is_active = 1 ORDER BY category, name')->fetchAll();

// Which service is chosen? (from the link, or from the submitted form)
$serviceId = (int)($_POST['service_id'] ?? $_GET['service'] ?? 0);
$service   = null;
foreach ($services as $s) {
    if ((int)$s['service_id'] === $serviceId) $service = $s;
}

// Stylists who offer this service (their specialization matches the service category),
// with their average rating so the client can choose with confidence.
$stylists = [];
if ($service) {
    $st = $pdo->prepare('SELECT st.staff_id, u.name, st.specialization,
                                (SELECT AVG(r.rating) FROM reviews r JOIN appointments a ON a.appointment_id = r.appointment_id WHERE a.staff_id = st.staff_id) AS avg_rating
                         FROM staff st JOIN users u ON u.user_id = st.user_id
                         WHERE LOWER(st.specialization) = LOWER(?) ORDER BY u.name');
    $st->execute([$service['category']]);
    $stylists = $st->fetchAll();
}

// ---- A time button was clicked: try to book it ---------------------------
$date = $_POST['date'] ?? $_GET['date'] ?? '';
if (is_post() && $service) {
    csrf_check();
    $staffId = (int)post('staff_id'); $time = post('time');
    $start = $date . ' ' . $time . ':00';
    $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && preg_match('/^\d{2}:\d{2}$/', $time) && strtotime($start) !== false;
    $end   = $valid ? date('Y-m-d H:i:s', strtotime($start) + $service['duration_minutes'] * 60) : '';
    $stylistOk = in_array($staffId, array_map('intval', array_column($stylists, 'staff_id')), true);

    if (!$valid)                                         flash('error', 'Please choose a valid date and time.');
    elseif (strtotime($start) <= time())                 flash('error', 'Please choose a time in the future.');
    elseif (strtotime($date) > strtotime('+' . BOOK_DAYS_AHEAD . ' days')) flash('error', 'You can book up to ' . BOOK_DAYS_AHEAD . ' days ahead.');
    elseif (!$stylistOk)                                 flash('error', 'Please choose a stylist who offers this service.');
    elseif (staff_is_free($staffId, $start, $end)) {
        // Free: save as "requested", then confirm it (this sends the notifications).
        try {
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO appointments (client_id, staff_id, service_id, start_at, end_at, status, reason, created_at)
                           VALUES (?, ?, ?, ?, ?, 'requested', '', ?)")
                ->execute([$clientId, $staffId, $service['service_id'], $start, $end, date('Y-m-d H:i:s')]);
            $newId = (int)$pdo->lastInsertId();
            change_status(get_appointment($newId), 'confirmed');
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();       // undo the half-saved booking
            throw $e;
        }
        flash('success', 'You are booked! ' . $service['name'] . ' on ' . nice_date($start) . ' at ' . nice_time($start) . '.');
        redirect('client/dashboard.php');
    } else {
        flash('error', 'Sorry, that time was just taken. Please pick another free time below.');
    }
}

// ---- Work out who is free, and when ---------------------------------------
$avail = [];        // $avail[date][staff_id] = ['shifts' => [...], 'slots' => [...]]
$days  = [];        // the day strip: date => number of free times (all stylists together)
if ($service && $stylists) {
    $dur = (int)$service['duration_minutes'];
    for ($i = 0; $i < 14; $i++) {                            // the next 14 days
        $d = date('Y-m-d', strtotime("+$i day"));
        $days[$d] = 0;
        foreach ($stylists as $sty) {
            $avail[$d][$sty['staff_id']] = staff_day((int)$sty['staff_id'], $dur, $d);
            $days[$d] += count($avail[$d][$sty['staff_id']]['slots']);
        }
    }
    // The chosen day: if none (or an invalid one) was given, jump to the first day that has free times.
    $okDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) !== false
              && $date >= date('Y-m-d') && strtotime($date) <= strtotime('+' . BOOK_DAYS_AHEAD . ' days');
    if (!$okDate) {
        $date = date('Y-m-d');
        foreach ($days as $d => $n) { if ($n > 0) { $date = $d; break; } }
    }
    if (!isset($avail[$date])) {                              // a day beyond the strip, picked with the date box
        foreach ($stylists as $sty) {
            $avail[$date][$sty['staff_id']] = staff_day((int)$sty['staff_id'], $dur, $date);
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1>Book an appointment</h1></div>

<?php if (!$service): ?>
  <!-- STEP 1: choose a service -->
  <p class="muted">Step 1 of 2 · Choose a service</p>
  <div class="grid grid-3">
    <?php foreach ($services as $s): ?>
      <div class="card service-card" style="margin:0">
        <small><?= h($s['category']) ?></small>
        <h3><?= h($s['name']) ?></h3>
        <div class="price"><?= h(money($s['price'])) ?></div>
        <div class="meta"><?= (int)$s['duration_minutes'] ?> minutes</div>
        <a class="btn btn-small" href="<?= h(url('client/book.php?service=' . $s['service_id'])) ?>">Choose</a>
      </div>
    <?php endforeach; ?>
  </div>

<?php else: ?>
  <!-- STEP 2: see who is free, pick a day and a time -->
  <p class="muted">Step 2 of 2 · Pick a day, then a free time with the stylist you like</p>
  <div class="summary row spread" style="margin-bottom:1rem">
    <span><b><?= h($service['name']) ?></b> · <?= (int)$service['duration_minutes'] ?> min · <?= h(money($service['price'])) ?></span>
    <a href="<?= h(url('client/book.php')) ?>">Change service</a>
  </div>

  <?php if (!$stylists): ?>
    <div class="card"><p class="empty">No stylist offers this service yet.</p></div>
  <?php else: ?>

    <!-- The day strip: every day shows how many free times there are -->
    <div class="day-strip">
      <?php foreach ($days as $d => $n): ?>
        <a class="day-chip <?= $d === $date ? 'on' : '' ?> <?= $n === 0 ? 'none' : '' ?>"
           href="<?= h(url('client/book.php?service=' . $service['service_id'] . '&date=' . $d)) ?>">
          <span><?= h(date('D', strtotime($d))) ?></span>
          <b><?= h(date('j M', strtotime($d))) ?></b>
          <small><?= $n > 0 ? $n . ' free' : 'no times' ?></small>
        </a>
      <?php endforeach; ?>
    </div>
    <form method="get" class="row" style="margin:.2rem 0 1.2rem;align-items:center">
      <input type="hidden" name="service" value="<?= (int)$service['service_id'] ?>">
      <label for="date" class="muted" style="margin:0">Need a later day?</label>
      <input type="date" id="date" name="date" value="<?= h($date) ?>" min="<?= h(date('Y-m-d')) ?>"
             max="<?= h(date('Y-m-d', strtotime('+' . BOOK_DAYS_AHEAD . ' days'))) ?>" style="width:auto">
      <button class="btn btn-small btn-light">Show</button>
    </form>

    <h2><?= h(nice_date($date . ' 00:00:00')) ?></h2>
    <?php if (array_sum(array_map(function ($a) { return count($a['slots']); }, $avail[$date])) === 0): ?>
      <div class="alert alert-info">No stylist has a free time on this day. Please pick another day from the strip above.</div>
    <?php endif; ?>

    <!-- One card per stylist, with the free times of the chosen day -->
    <div class="grid grid-2">
    <?php foreach ($stylists as $sty):
        $day = $avail[$date][$sty['staff_id']]; ?>
      <div class="card" style="margin:0">
        <div class="row spread">
          <h3 style="margin:0"><?= h($sty['name']) ?></h3>
          <span class="muted"><?= $sty['avg_rating'] !== null ? '★ ' . number_format((float)$sty['avg_rating'], 1) : 'New' ?></span>
        </div>
        <p class="hint" style="margin:.2rem 0 .8rem"><?= h($sty['specialization']) ?> ·
          <?= $day['shifts'] ? 'Works ' . h(implode(', ', $day['shifts'])) : 'Not working this day' ?></p>

        <?php if ($day['slots']): ?>
          <form method="post" class="slot-list">
            <?= csrf_field() ?>
            <input type="hidden" name="service_id" value="<?= (int)$service['service_id'] ?>">
            <input type="hidden" name="staff_id" value="<?= (int)$sty['staff_id'] ?>">
            <input type="hidden" name="date" value="<?= h($date) ?>">
            <?php foreach ($day['slots'] as $t): ?>
              <button class="btn btn-light btn-small" name="time" value="<?= h($t) ?>"
                      onclick="return confirm('Book <?= h($service['name']) ?> with <?= h($sty['name']) ?> on <?= h(nice_date($date . ' 00:00:00')) ?> at <?= h($t) ?>?')"><?= h($t) ?></button>
            <?php endforeach; ?>
          </form>
        <?php elseif ($day['shifts']): ?>
          <p class="empty" style="margin:0;text-align:left">Fully booked. Try another day.</p>
        <?php else: ?>
          <p class="empty" style="margin:0;text-align:left">Day off.</p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
