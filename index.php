<?php
/**
 * index.php  -  public home page: what the salon offers and how to book.
 */
require __DIR__ . '/includes/bootstrap.php';
$title  = 'Welcome';
$active = 'home';

// Show the active services (set by the admin) from the database.
$services = db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY category, name')->fetchAll();
$me = current_user();

require __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <h1>Your next look, booked in a minute.</h1>
  <p>Choose a service, pick your stylist and a time that suits you. We confirm instantly and remind you before your visit.</p>
  <?php if ($me): ?>
    <a class="btn btn-big" href="<?= h(url(home_for($me['role']))) ?>">Go to my account</a>
  <?php else: ?>
    <div class="row">
      <a class="btn btn-big" href="<?= h(url('register.php')) ?>">Create an account</a>
      <a class="btn btn-big btn-ghost" href="<?= h(url('login.php')) ?>">Log in</a>
    </div>
  <?php endif; ?>
</section>

<h2>Our services</h2>
<div class="grid grid-3">
  <?php foreach ($services as $s): ?>
    <div class="card service-card">
      <small><?= h($s['category']) ?></small>
      <h3><?= h($s['name']) ?></h3>
      <div class="price"><?= h(money($s['price'])) ?></div>
      <div class="meta"><?= (int)$s['duration_minutes'] ?> minutes</div>
      <?php if (($me['role'] ?? 'client') === 'client'): ?>
        <a class="btn btn-small btn-light" href="<?= h(url($me ? 'client/book.php?service=' . $s['service_id'] : 'login.php')) ?>">Book this</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$services): ?><p class="muted">No services yet.</p><?php endif; ?>
</div>

<h2 style="margin-top:1rem">How it works</h2>
<div class="grid grid-3">
  <div class="card step"><div class="step-num">1</div><div><h3>Choose</h3><p class="muted">Pick a service and the stylist you like.</p></div></div>
  <div class="card step"><div class="step-num">2</div><div><h3>Book</h3><p class="muted">Select a date and time. We check the stylist is free.</p></div></div>
  <div class="card step"><div class="step-num">3</div><div><h3>Relax</h3><p class="muted">Get notified, pay after your service and leave a review.</p></div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
