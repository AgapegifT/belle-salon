<?php
/**
 * includes/auth.php  -  login, logout and "who is allowed here?"
 *
 * Passwords are never stored. We store password_hash() output and check it with
 * password_verify(). The logged-in user's id lives in the PHP session.
 */

/** The logged-in user as an array, or null when nobody is logged in. */
function current_user(bool $reload = false): ?array
{
    static $user = false;                    // false = not loaded yet
    if ($reload) $user = false;              // forget the cached value (used right after logging in)
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT user_id, name, email, phone, role FROM users WHERE user_id = ?');
            $st->execute([$_SESSION['uid']]);
            $user = $st->fetch() ?: null;
        }
    }
    return $user;
}

/** Try to log in. Returns an error message, or null on success. */
function login_attempt(string $email, string $password): ?string
{
    // Simple brake against password guessing: 5 wrong tries = wait 5 minutes.
    $fails = $_SESSION['fails'] ?? ['n' => 0, 't' => 0];
    if ($fails['n'] >= 5 && time() - $fails['t'] < 300) {
        return 'Too many wrong attempts. Please wait a few minutes and try again.';
    }

    $st = db()->prepare('SELECT user_id, password_hash FROM users WHERE email = ?');
    $st->execute([strtolower($email)]);
    $row = $st->fetch();

    if (!$row || !password_verify($password, $row['password_hash'])) {
        $_SESSION['fails'] = ['n' => $fails['n'] + 1, 't' => time()];
        return 'Wrong e-mail or password.';
    }

    session_regenerate_id(true);             // new session id after login (stops session fixation)
    unset($_SESSION['fails']);
    $_SESSION['uid'] = (int)$row['user_id'];
    current_user(true);                      // reload the cached user now that someone is logged in
    return null;
}

function logout(): void
{
    $_SESSION = [];
    session_destroy();
}

/** Page guard: send guests to the login page. */
function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please log in first.');
        redirect('login.php');
    }
    return $user;
}

/** Page guard: only this role may open the page (client | staff | admin). */
function require_role(string $role): array
{
    $user = require_login();
    if ($user['role'] !== $role) {
        flash('error', 'You do not have access to that page.');
        redirect(home_for($user['role']));
    }
    return $user;
}

/** Where each role lands after login. */
function home_for(string $role): string
{
    if ($role === 'admin') return 'admin/dashboard.php';
    if ($role === 'staff') return 'staff/schedule.php';
    return 'client/dashboard.php';
}

/** The id inside the role table: clients.client_id, staff.staff_id or admins.admin_id. */
function profile_id(): int
{
    $user  = require_login();
    $table = ['client' => 'clients', 'staff' => 'staff', 'admin' => 'admins'][$user['role']];
    $col   = ['client' => 'client_id', 'staff' => 'staff_id', 'admin' => 'admin_id'][$user['role']];
    $st = db()->prepare("SELECT $col FROM $table WHERE user_id = ?");
    $st->execute([$user['user_id']]);
    return (int)$st->fetchColumn();
}
