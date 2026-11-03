<?php
/**
 * notifications.php  -  messages for the logged-in user (all roles).
 * Opening the page marks them as read.
 */
require __DIR__ . '/includes/bootstrap.php';
$me     = require_login();
$title  = 'Notifications';
$active = 'notifications';

$st = db()->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY notification_id DESC LIMIT 50');
$st->execute([$me['user_id']]);
$items = $st->fetchAll();

// Remember which ones were new, then mark everything as read.
require __DIR__ . '/includes/header.php';   // (the menu badge shows the count BEFORE we clear it)
db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$me['user_id']]);
?>
<div class="page-head"><h1>Notifications</h1></div>
<div class="card">
  <?php foreach ($items as $n): ?>
    <div class="notice-item <?= $n['is_read'] ? 'read' : '' ?>">
      <span class="dot"></span>
      <div><?= h($n['message']) ?><time><?= h(nice_date($n['created_at'])) ?> · <?= h(nice_time($n['created_at'])) ?></time></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$items): ?><p class="empty">No notifications yet.</p><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
