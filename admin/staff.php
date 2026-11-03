<?php
/**
 * admin/staff.php  -  the stylists: list them and create new stylist accounts.
 */
require __DIR__ . '/../includes/bootstrap.php';
$me     = require_role('admin');
$title  = 'Staff';
$active = 'staff';
$pdo    = db();

$name = $email = $phone = $spec = '';
if (is_post()) {
    csrf_check();
    $name = post('name'); $email = strtolower(post('email')); $phone = post('phone'); $spec = post('specialization');
    $pw = $_POST['password'] ?? '';
    $errors = [];
    if ($name === '')                                   $errors[] = 'Please enter a name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors[] = 'Please enter a valid e-mail address.';
    if (!preg_match('/^[0-9+ ()-]{7,20}$/', $phone))    $errors[] = 'Please enter a valid phone number.';
    if ($spec === '')                                   $errors[] = 'Please enter a specialization (for example Hair or Nails).';
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) $errors[] = 'Password needs 8+ characters with letters and numbers.';
    if (!$errors) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $st->execute([$email]);
        if ((int)$st->fetchColumn() > 0) $errors[] = 'That e-mail is already registered.';
    }
    if ($errors) {
        foreach ($errors as $e) flash('error', $e);
    } else {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (name, email, phone, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $email, $phone, password_hash($pw, PASSWORD_DEFAULT), 'staff', date('Y-m-d H:i:s')]);
        $uid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO staff (user_id, specialization) VALUES (?, ?)')->execute([$uid, $spec]);
        $pdo->commit();
        flash('success', $name . ' was added. Now give them work shifts under "Schedules".');
        redirect('admin/staff.php');
    }
}

$list = $pdo->query('SELECT st.staff_id, st.specialization, u.name, u.email, u.phone FROM staff st JOIN users u ON u.user_id = st.user_id ORDER BY u.name')->fetchAll();
$cats = array_unique(array_merge(['Hair', 'Nails'], array_column($list, 'specialization')));

require __DIR__ . '/../includes/header.php';
?>
<div class="page-head"><h1>Staff</h1></div>
<div class="grid" style="grid-template-columns: 1.6fr 1fr; align-items:start">
  <div class="card">
    <h2>Our stylists</h2>
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Specialization</th><th>Contact</th></tr></thead>
      <tbody>
      <?php foreach ($list as $s): ?>
        <tr><td><b><?= h($s['name']) ?></b></td><td><?= h($s['specialization']) ?></td><td><?= h($s['email']) ?><br><small><?= h($s['phone']) ?></small></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
  <div class="card">
    <h2>Add a stylist</h2>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label for="name">Full name</label><input type="text" id="name" name="name" value="<?= h($name) ?>" required></div>
      <div class="field"><label for="email">E-mail</label><input type="email" id="email" name="email" value="<?= h($email) ?>" required></div>
      <div class="field"><label for="phone">Phone</label><input type="text" id="phone" name="phone" value="<?= h($phone) ?>" required></div>
      <div class="field"><label for="specialization">Specialization</label><input type="text" id="specialization" name="specialization" value="<?= h($spec) ?>" list="cats" required>
        <datalist id="cats"><?php foreach ($cats as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>
        <div class="hint">Must match a service category.</div></div>
      <div class="field"><label for="password">Temporary password</label><input type="password" id="password" name="password" required></div>
      <button class="btn" style="width:100%">Add stylist</button>
    </form>
  </div>
</div>
<style>@media (max-width: 860px) { .grid[style*="1.6fr"] { grid-template-columns: 1fr !important; } }</style>
<?php require __DIR__ . '/../includes/footer.php'; ?>
