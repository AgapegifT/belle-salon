<?php
/**
 * login.php  -  the log-in form.  After a successful login each role goes to its own home page.
 */
require __DIR__ . '/includes/bootstrap.php';
$title  = 'Log in';
$active = 'login';

if (current_user()) {
    redirect(home_for(current_user()['role']));
}

$email = '';
if (is_post()) {
    csrf_check();
    $email = post('email');
    $error = login_attempt($email, $_POST['password'] ?? '');
    if ($error === null) {
        flash('success', 'Welcome back!');
        unset($_SESSION['csrf']);                 // fresh form token after login
        redirect(home_for(current_user()['role']));
    }
    flash('error', $error);
}

require __DIR__ . '/includes/header.php';
?>
<div class="form-narrow">
  <div class="card">
    <h1>Log in</h1>
    <form method="post" autocomplete="on">
      <?= csrf_field() ?>
      <div class="field"><label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= h($email) ?>" required autofocus></div>
      <div class="field"><label for="password">Password</label>
        <input type="password" id="password" name="password" required></div>
      <button class="btn" style="width:100%">Log in</button>
    </form>
    <p class="muted" style="margin:1rem 0 0">New here? <a href="<?= h(url('register.php')) ?>">Create an account</a></p>
  </div>

  <!-- Demo help for this assignment. Delete this box before putting the site online. -->
  <div class="demo-box">
    <b>Demo accounts</b> (password <code>Salon@123</code>)<br>
    Admin <code>admin@salon.rw</code> · Stylist <code>grace@salon.rw</code> · Client <code>claire@mail.rw</code>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
