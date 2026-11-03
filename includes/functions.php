<?php
/**
 * includes/functions.php  -  helpers and business rules used by all pages.
 *
 *   1. Small helpers      (escape text, links, flash messages, form safety)
 *   2. Appointment rules  (the STATE MACHINE and the AVAILABILITY check)
 *   3. Notifications      (who is told what, when something happens)
 *   4. Payments           (invoice, promo code, loyalty points)
 */

/* =====================================================================
 * 1. SMALL HELPERS
 * ===================================================================== */

/** Make text safe to print inside HTML. Use it on EVERY value that came from the database or a form. */
function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Build a link inside the project:  url('client/book.php') */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function money($amount): string
{
    return number_format((float)$amount, 0, '.', ',') . ' RWF';
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/** A trimmed value from a submitted form. */
function post(string $key, string $default = ''): string
{
    return trim((string)($_POST[$key] ?? $default));
}

function nice_date(string $datetime): string { return date('D j M Y', strtotime($datetime)); }
function nice_time(string $datetime): string { return date('H:i', strtotime($datetime)); }

/** Flash messages: set one now, it is shown once on the next page. */
function flash(string $type, string $message): void   // $type: success | error | info
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

/** CSRF protection: every form carries a secret token; forged forms from other sites do not have it. */
function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">';
}

function csrf_check(): void
{
    $token = $_SESSION['csrf'] ?? '';
    if ($token === '' || !hash_equals($token, (string)($_POST['csrf'] ?? ''))) {   // an empty token never counts
        http_response_code(400);
        exit('This form has expired. Please go back, refresh the page and try again.');
    }
}

/** The coloured label for a status, e.g. status_badge('in_progress') */
function status_badge(string $status): string
{
    $labels = [
        'requested' => 'Requested', 'confirmed' => 'Confirmed', 'in_progress' => 'In progress',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected',
        'pending'   => 'Unpaid',    'paid'      => 'Paid',
    ];
    return '<span class="badge badge-' . h($status) . '">' . h($labels[$status] ?? $status) . '</span>';
}

/** Time choices for the booking form: 09:00, 09:30 ... up to closing time. */
function time_options(): array
{
    $out = [];
    for ($t = strtotime(OPEN_TIME); $t < strtotime(CLOSE_TIME); $t += SLOT_MINUTES * 60) {
        $out[] = date('H:i', $t);
    }
    return $out;
}

/* =====================================================================
 * 2. APPOINTMENT RULES
 * ===================================================================== */

/**
 * THE STATE MACHINE (from the design's State Machine diagram).
 * Each status lists the statuses it is allowed to move to. Anything else is refused.
 *
 *   requested -> confirmed | rejected
 *   confirmed -> in_progress | cancelled
 *   in_progress -> completed
 *   completed, cancelled, rejected -> (final, nothing more can happen)
 */
const STATUS_FLOW = [
    'requested'   => ['confirmed', 'rejected'],
    'confirmed'   => ['in_progress', 'cancelled'],
    'in_progress' => ['completed'],
    'completed'   => [],
    'cancelled'   => [],
    'rejected'    => [],
];

/** The SQL used everywhere to read an appointment together with names, price and duration. */
const APPOINTMENT_SQL = "
    SELECT a.*, s.name AS service_name, s.price, s.duration_minutes, s.category,
           c.client_id AS cid, cu.user_id AS client_user_id, cu.name AS client_name,
           st.staff_id AS sid, su.user_id AS staff_user_id,  su.name AS staff_name,
           p.status AS pay_status, p.amount AS pay_amount, p.promo_percent AS pay_promo,
           r.review_id, r.rating
    FROM appointments a
    JOIN services s ON s.service_id = a.service_id
    JOIN clients  c ON c.client_id  = a.client_id
    JOIN users   cu ON cu.user_id   = c.user_id
    JOIN staff   st ON st.staff_id  = a.staff_id
    JOIN users   su ON su.user_id   = st.user_id
    LEFT JOIN payments p ON p.appointment_id = a.appointment_id
    LEFT JOIN reviews  r ON r.appointment_id = a.appointment_id ";

function get_appointment(int $id): ?array
{
    $st = db()->prepare(APPOINTMENT_SQL . ' WHERE a.appointment_id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Move an appointment to a new status. This is the ONLY place where status changes,
 * so the rules can never be bypassed. It also sends the notifications.
 */
function change_status(array $appt, string $next, string $reason = ''): void
{
    if (!in_array($next, STATUS_FLOW[$appt['status']] ?? [], true)) {
        throw new RuntimeException('That change is not allowed (' . $appt['status'] . ' to ' . $next . ').');
    }
    // "AND status = ?" makes sure two people clicking at once cannot both succeed.
    $st = db()->prepare('UPDATE appointments SET status = ?, reason = ? WHERE appointment_id = ? AND status = ?');
    $st->execute([$next, $reason, $appt['appointment_id'], $appt['status']]);
    if ($st->rowCount() === 0) {
        throw new RuntimeException('This appointment was just changed by someone else. Please refresh.');
    }
    $appt['status'] = $next;
    notify_status_change($appt, $next, $reason);
}

/** Describe an appointment in one line, used inside messages. */
function appt_label(array $a): string
{
    return $a['service_name'] . ' with ' . $a['staff_name'] . ' on ' . nice_date($a['start_at']) . ' at ' . nice_time($a['start_at']);
}

/**
 * IS THE STYLIST FREE?  (message "checkAvailability" in the sequence diagram)
 *   a) the whole service must fit inside one of the stylist's work shifts that day, and
 *   b) it must not overlap another confirmed / in-progress appointment of that stylist.
 * $ignoreId lets an appointment ignore itself when it is being moved.
 */
function staff_is_free(int $staffId, string $start, string $end, int $ignoreId = 0): bool
{
    if (substr($start, 0, 10) !== substr($end, 0, 10)) {
        return false;                                    // services never run past midnight
    }
    $st = db()->prepare('SELECT COUNT(*) FROM staff_schedule
                         WHERE staff_id = ? AND work_date = ? AND start_time <= ? AND end_time >= ?');
    $st->execute([$staffId, substr($start, 0, 10), substr($start, 11, 8), substr($end, 11, 8)]);
    if ((int)$st->fetchColumn() === 0) {
        return false;                                    // not working at that time
    }
    $st = db()->prepare("SELECT COUNT(*) FROM appointments
                         WHERE staff_id = ? AND status IN ('confirmed', 'in_progress')
                           AND appointment_id <> ? AND start_at < ? AND end_at > ?");
    $st->execute([$staffId, $ignoreId, $end, $start]);   // two time ranges overlap when each starts before the other ends
    return (int)$st->fetchColumn() === 0;
}

/**
 * WHAT DOES ONE STYLIST'S DAY LOOK LIKE?  (used by the booking page so clients can SEE availability)
 * Returns ['shifts' => ['09:00-17:00', ...], 'slots' => ['09:00', '09:30', ...]]
 * 'slots' are the start times where the whole service fits inside a shift, does not overlap a
 * confirmed / in-progress appointment, and is still in the future. Same rules as staff_is_free(),
 * but it needs only two queries for the whole day instead of two per time.
 */
function staff_day(int $staffId, int $durationMinutes, string $date): array
{
    $pdo = db();
    $st = $pdo->prepare('SELECT start_time, end_time FROM staff_schedule WHERE staff_id = ? AND work_date = ? ORDER BY start_time');
    $st->execute([$staffId, $date]);
    $shifts = $st->fetchAll();

    $st = $pdo->prepare("SELECT start_at, end_at FROM appointments
                         WHERE staff_id = ? AND status IN ('confirmed', 'in_progress') AND start_at < ? AND end_at > ?");
    $st->execute([$staffId, date('Y-m-d', strtotime($date . ' +1 day')) . ' 00:00:00', $date . ' 00:00:00']);
    $busy = $st->fetchAll();

    $labels = []; $slots = [];
    foreach ($shifts as $sh) {
        $labels[] = substr($sh['start_time'], 0, 5) . '-' . substr($sh['end_time'], 0, 5);
        $shiftEnd = strtotime($date . ' ' . $sh['end_time']);
        for ($t = strtotime($date . ' ' . $sh['start_time']); $t + $durationMinutes * 60 <= $shiftEnd; $t += SLOT_MINUTES * 60) {
            if ($t <= time()) continue;                               // already past
            $free = true;
            foreach ($busy as $b) {                                   // two ranges overlap when each starts before the other ends
                if (strtotime($b['start_at']) < $t + $durationMinutes * 60 && strtotime($b['end_at']) > $t) { $free = false; break; }
            }
            if ($free) $slots[date('H:i', $t)] = true;
        }
    }
    return ['shifts' => $labels, 'slots' => array_keys($slots)];
}

/** Up to 3 other free start times with the same stylist, looking forward in 30-minute steps. */
function alternative_slots(int $staffId, int $durationMinutes, string $wantedStart, int $ignoreId = 0): array
{
    $slots = [];
    $t     = strtotime($wantedStart);
    $limit = $t + 8 * 3600;
    for ($t += SLOT_MINUTES * 60; $t <= $limit && count($slots) < 3; $t += SLOT_MINUTES * 60) {
        $s = date('Y-m-d H:i:s', $t);
        $e = date('Y-m-d H:i:s', $t + $durationMinutes * 60);
        if (staff_is_free($staffId, $s, $e, $ignoreId)) {
            $slots[] = $s;
        }
    }
    return $slots;
}

/** Hand an appointment to another stylist (admin action "Assign Staff to Appointment"). */
function reassign_appointment(array $appt, int $newStaffId): void
{
    if ($appt['status'] !== 'confirmed') {
        throw new RuntimeException('Only a confirmed appointment can get a different stylist.');
    }
    $st = db()->prepare('SELECT su.user_id, su.name, st.specialization FROM staff st JOIN users su ON su.user_id = st.user_id WHERE st.staff_id = ?');
    $st->execute([$newStaffId]);
    $new = $st->fetch();
    if (!$new || strcasecmp($new['specialization'], $appt['category']) !== 0) {
        throw new RuntimeException('That stylist does not offer ' . $appt['service_name'] . '.');
    }
    if (!staff_is_free($newStaffId, $appt['start_at'], $appt['end_at'], (int)$appt['appointment_id'])) {
        throw new RuntimeException($new['name'] . ' is not free at that time.');
    }
    db()->prepare('UPDATE appointments SET staff_id = ? WHERE appointment_id = ?')->execute([$newStaffId, $appt['appointment_id']]);
    $when = nice_date($appt['start_at']) . ' at ' . nice_time($appt['start_at']);
    notify((int)$new['user_id'], 'You were assigned: ' . $appt['service_name'] . ' for ' . $appt['client_name'] . ' on ' . $when, (int)$appt['appointment_id']);
    notify((int)$appt['staff_user_id'], 'Your appointment was handed to ' . $new['name'] . ': ' . $appt['service_name'] . ' on ' . $when, (int)$appt['appointment_id']);
    notify((int)$appt['client_user_id'], 'Your stylist is now ' . $new['name'] . ' for ' . $appt['service_name'] . ' on ' . $when, (int)$appt['appointment_id']);
}

/* =====================================================================
 * 3. NOTIFICATIONS  (shown on the "Notifications" page and the bell in the menu)
 * ===================================================================== */

function notify(int $userId, string $message, ?int $appointmentId = null): void
{
    db()->prepare('INSERT INTO notifications (user_id, appointment_id, message, is_read, created_at) VALUES (?, ?, ?, 0, ?)')
        ->execute([$userId, $appointmentId, $message, date('Y-m-d H:i:s')]);
}

function unread_count(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/** Called by change_status(): decides who gets which message. */
function notify_status_change(array $a, string $status, string $reason): void
{
    $id = (int)$a['appointment_id'];
    switch ($status) {
        case 'confirmed':
            notify((int)$a['client_user_id'], 'Appointment confirmed: ' . appt_label($a), $id);
            notify((int)$a['staff_user_id'], 'New appointment: ' . $a['service_name'] . ' for ' . $a['client_name']
                . ' on ' . nice_date($a['start_at']) . ' at ' . nice_time($a['start_at']), $id);
            break;
        case 'rejected':
            notify((int)$a['client_user_id'], 'Sorry, ' . appt_label($a) . ' is not possible. ' . $reason, $id);
            break;
        case 'cancelled':
            notify((int)$a['client_user_id'], 'Appointment cancelled (' . $reason . '): ' . appt_label($a), $id);
            notify((int)$a['staff_user_id'], 'Appointment cancelled (' . $reason . '): ' . $a['service_name']
                . ' for ' . $a['client_name'] . ' at ' . nice_time($a['start_at']), $id);
            break;
        case 'completed':
            notify((int)$a['client_user_id'], 'Thank you! Your ' . $a['service_name'] . ' is done. Your invoice is ready to pay.', $id);
            break;
        // 'in_progress' needs no message
    }
}

/* =====================================================================
 * 4. PAYMENTS
 * ===================================================================== */

/** Create the invoice (a pending payment) for a completed appointment. Safe to call twice. */
function create_invoice(array $appt): void
{
    $st = db()->prepare('SELECT COUNT(*) FROM payments WHERE appointment_id = ?');
    $st->execute([$appt['appointment_id']]);
    if ((int)$st->fetchColumn() === 0) {
        db()->prepare("INSERT INTO payments (appointment_id, amount, status, promo_percent) VALUES (?, ?, 'pending', 0)")
            ->execute([$appt['appointment_id'], $appt['price']]);
    }
}

/** Loyalty status of a client: points, "is loyal?" and how many points are still missing. */
function loyalty_info(int $clientId): array
{
    $st = db()->prepare('SELECT loyalty_points FROM clients WHERE client_id = ?');
    $st->execute([$clientId]);
    $points = (int)$st->fetchColumn();
    return ['points' => $points, 'loyal' => $points >= LOYAL_POINTS, 'missing' => max(0, LOYAL_POINTS - $points)];
}

/** Has this client paid for anything yet? (WELCOME10 is for the first payment only.) */
function client_has_paid(int $clientId): bool
{
    $st = db()->prepare("SELECT COUNT(*) FROM payments p JOIN appointments a ON a.appointment_id = p.appointment_id
                         WHERE a.client_id = ? AND p.status = 'paid'");
    $st->execute([$clientId]);
    return (int)$st->fetchColumn() > 0;
}

/** Which promo codes may this client use right now?  code => percent */
function promos_for(int $clientId): array
{
    $out = [];
    if (!client_has_paid($clientId))    $out['WELCOME10'] = PROMO_CODES['WELCOME10'];
    if (loyalty_info($clientId)['loyal']) $out['LOYAL20'] = PROMO_CODES['LOYAL20'];
    return $out;
}

/**
 * Take the payment. A client may pay once the appointment is confirmed (pay in advance),
 * in progress, or completed. Applies a promo code (optional), saves the payment,
 * adds loyalty points (1 point per 1,000 RWF) and sends the receipt.
 */
function pay_appointment(array $appt, string $method, string $promoCode): void
{
    if (!in_array($method, ['cash', 'card', 'mobile_money'], true)) {
        throw new RuntimeException('Please choose a payment method.');
    }
    if (!in_array($appt['status'], ['confirmed', 'in_progress', 'completed'], true)) {
        throw new RuntimeException('This appointment cannot be paid.');
    }
    $clientId = (int)$appt['cid'];
    $st = db()->prepare('SELECT * FROM payments WHERE appointment_id = ?');
    $st->execute([$appt['appointment_id']]);
    $pay = $st->fetch();
    if ($pay && $pay['status'] === 'paid') {
        throw new RuntimeException('This appointment is already paid.');
    }
    if (!$pay) {                                       // paying in advance: the invoice does not exist yet
        create_invoice($appt);
        $st->execute([$appt['appointment_id']]);
        $pay = $st->fetch();
    }

    $percent = 0;
    $code = strtoupper(trim($promoCode));
    if ($code !== '') {
        if (!isset(PROMO_CODES[$code])) {
            throw new RuntimeException('Unknown promo code.');
        }
        if (!isset(promos_for($clientId)[$code])) {
            throw new RuntimeException($code === 'WELCOME10'
                ? 'WELCOME10 is only for your first payment.'
                : 'LOYAL20 is for loyal clients: collect ' . LOYAL_POINTS . ' loyalty points to unlock it.');
        }
        $percent = PROMO_CODES[$code];
    }
    $amount = round((float)$appt['price'] * (100 - $percent) / 100);

    $wasLoyal = loyalty_info($clientId)['loyal'];
    db()->prepare("UPDATE payments SET amount = ?, method = ?, status = 'paid', promo_percent = ?, paid_at = ?
                   WHERE payment_id = ? AND status = 'pending'")
        ->execute([$amount, $method, $percent, date('Y-m-d H:i:s'), $pay['payment_id']]);
    $earned = max(1, (int)floor($amount / 1000));      // at least 1 point for every payment
    db()->prepare('UPDATE clients SET loyalty_points = loyalty_points + ? WHERE client_id = ?')->execute([$earned, $clientId]);
    notify((int)$appt['client_user_id'], 'Receipt: ' . money($amount) . ' paid by ' . str_replace('_', ' ', $method)
        . ($percent ? " ($percent% promo)" : '') . '. You earned ' . $earned . ' loyalty point' . ($earned === 1 ? '' : 's') . '. Thank you!', (int)$appt['appointment_id']);
    if (!$wasLoyal && loyalty_info($clientId)['loyal']) {
        notify((int)$appt['client_user_id'], 'You are now a LOYAL client! Use the code LOYAL20 for 20% off your next payment.', (int)$appt['appointment_id']);
    }
}
