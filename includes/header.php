<?php
/**
 * includes/header.php  -  the top of every page: <head>, menu bar and flash messages.
 * Before including it, a page sets:   $title = 'Page name';   $active = 'menu-key';
 */
$me     = current_user();
$title  = $title  ?? SALON_NAME;
$active = $active ?? '';

// Menu entries per role:  key => [label, link]
$menus = [
    'guest'  => ['home' => ['Home', 'index.php'], 'login' => ['Log in', 'login.php'], 'register' => ['Register', 'register.php']],
    'client' => ['appointments' => ['My appointments', 'client/dashboard.php'], 'book' => ['Book', 'client/book.php']],
    'staff'  => ['schedule' => ['My schedule', 'staff/schedule.php']],
    'admin'  => ['dashboard' => ['Dashboard', 'admin/dashboard.php'], 'appointments' => ['Appointments', 'admin/appointments.php'],
                 'services' => ['Services', 'admin/services.php'], 'schedules' => ['Schedules', 'admin/schedules.php'],
                 'staff' => ['Staff', 'admin/staff.php']],
];
$menu = $menus[$me['role'] ?? 'guest'];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> · <?= h(SALON_NAME) ?></title>
  <link rel="stylesheet" href="<?= h(url('assets/css/style.css')) ?>">
</head>
<body>
<header class="topbar">
  <div class="container topbar-inner">
    <a class="brand" href="<?= h(url($me ? home_for($me['role']) : 'index.php')) ?>"><span class="brand-mark">✂</span> <?= h(SALON_NAME) ?></a>
    <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-label="Menu">
    <label for="nav-toggle" class="nav-burger">☰</label>
    <nav class="nav">
      <?php foreach ($menu as $key => [$label, $link]): ?>
        <a href="<?= h(url($link)) ?>" class="<?= $active === $key ? 'active' : '' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
      <?php if ($me): $n = unread_count((int)$me['user_id']); ?>
        <a href="<?= h(url('notifications.php')) ?>" class="<?= $active === 'notifications' ? 'active' : '' ?>">Notifications<?php if ($n): ?> <span class="pill"><?= $n ?></span><?php endif; ?></a>
        <span class="nav-user"><?= h($me['name']) ?> <small><?= h($me['role']) ?></small></span>
        <a class="btn btn-small btn-light" href="<?= h(url('logout.php')) ?>">Log out</a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<main class="container page">
<?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
  <div class="alert alert-<?= h($f['type']) ?>"><?= h($f['msg']) ?></div>
<?php endforeach; unset($_SESSION['flash']); ?>
