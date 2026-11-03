<?php
/**
 * client/review.php  -  rate a COMPLETED appointment from 1 to 5 stars (one review per appointment).
 */
require __DIR__ . '/../includes/bootstrap.php';
$me       = require_role('client');
$clientId = profile_id();
$title    = 'Leave a review';
$active   = 'appointments';

$appt = get_appointment((int)($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$appt || (int)$appt['cid'] !== $clientId || $appt['status'] !== 'completed' || $appt['review_id']) {
    flash('error', 'That appointment cannot be reviewed (not completed, or already reviewed).');
    redirect('client/dashboard.php');
}

if (is_post()) {
    csrf_check();
    $rating = (int)post('rating');
    $text   = mb_substr(post('review_text'), 0, 500);
    if ($rating < 1 || $rating > 5) {
        flash('error', 'Please choose a rating from 1 to 5.');
    } else {
        db()->prepare('INSERT INTO reviews (appointment_id, rating, review_text, created_at) VALUES (?, ?, ?, ?)')
            ->execute([$appt['appointment_id'], $rating, $text, date('Y-m-d H:i:s')]);
        flash('success', 'Thank you for your review!');
        redirect('client/dashboard.php');
    }
}

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1>How was your visit?</h1></div>
<div class="card" style="max-width:560px">
  <div class="summary"><b><?= h($appt['service_name']) ?></b> with <?= h($appt['staff_name']) ?> · <?= h(nice_date($appt['start_at'])) ?></div>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$appt['appointment_id'] ?>">
    <div class="field"><label>Rating</label>
      <div class="stars">
        <?php for ($i = 5; $i >= 1; $i--): ?>
          <label><input type="radio" name="rating" value="<?= $i ?>" <?= (int)post('rating') === $i ? 'checked' : '' ?>><?= str_repeat('★', $i) ?></label>
        <?php endfor; ?>
      </div></div>
    <div class="field"><label for="review_text">Comment <small>(optional)</small></label>
      <textarea id="review_text" name="review_text" rows="4" maxlength="500"><?= h(post('review_text')) ?></textarea></div>
    <div class="row"><button class="btn">Send review</button><a class="btn btn-ghost" href="<?= h(url('client/dashboard.php')) ?>">Skip</a></div>
  </form>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
