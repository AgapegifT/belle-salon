<?php
/**
 * register.php  -  clients create their own account. (Stylists and admins are added by an admin.)
 */
require __DIR__ . '/includes/bootstrap.php';
$title  = 'Create account';
$active = 'register';

if (current_user()) {
    redirect(home_for(current_user()['role']));
}

$name = $email = $phone = '';
if (is_post()) {
    csrf_check();
    $name = post('name'); $email = strtolower(post('email')); $phone = post('phone');
    $pw = $_POST['password'] ?? ''; $pw2 = $_POST['password2'] ?? '';

    // Validate every field and collect the problems.
    $errors = [];
    if ($name === '' || mb_strlen($name) > 100)             $errors[] = 'Please enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))         $errors[] = 'Please enter a valid e-mail address.';
    if (!preg_match('/^[0-9+ ()-]{7,20}$/', $phone))        $errors[] = 'Please enter a valid phone number.';
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw))
                                                            $errors[] = 'Password needs 8+ characters with letters and numbers.';
    if ($pw !== $pw2)                                       $errors[] = 'The two passwords do not match.';

    if (!$errors) {
        $st = db()->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $st->execute([$email]);
        if ((int)$st->fetchColumn() > 0) $errors[] = 'That e-mail is already registered. Try logging in.';
    }

    if (!$errors) {
        // Two inserts that belong together: the user, then the client profile.
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (name, email, phone, password_hash, role, created_at) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$name, $email, $phone, password_hash($pw, PASSWORD_DEFAULT), 'client', date('Y-m-d H:i:s')]);
        $userId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO clients (user_id, loyalty_points) VALUES (?, 0)')->execute([$userId]);
        $pdo->commit();

        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        flash('success', 'Your account is ready. Welcome, ' . $name . '!');
        redirect('client/book.php');
    }
    foreach ($errors as $e) flash('error', $e);
}

require __DIR__ . '/includes/header.php';
?>
<div class="form-narrow">
  <div class="card">
    <h1>Create your account</h1>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label for="name">Full name</label><input type="text" id="name" name="name" value="<?= h($name) ?>" required></div>
      <div class="field"><label for="email">E-mail</label><input type="email" id="email" name="email" value="<?= h($email) ?>" required></div>
      <div class="field"><label for="phone">Phone</label><input type="text" id="phone" name="phone" value="<?= h($phone) ?>" placeholder="07xx xxx xxx" required></div>
      <div class="field"><label for="password">Password</label><input type="password" id="password" name="password" required>
        <div class="hint">At least 8 characters, with letters and numbers.</div></div>
      <div class="field"><label for="password2">Repeat password</label><input type="password" id="password2" name="password2" required></div>
      <button class="btn" style="width:100%">Create account</button>
    </form>
    <p class="muted" style="margin:1rem 0 0">Already registered? <a href="<?= h(url('login.php')) ?>">Log in</a></p>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
