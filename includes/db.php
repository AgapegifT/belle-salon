<?php
/**
 * includes/db.php  -  one shared database connection (PDO).
 *
 * Every query in this project uses "prepared statements" (the ? marks), which
 * is what protects the site from SQL injection.
 */

function db(): PDO
{
    static $pdo = null;                      // created once, then reused
    if ($pdo !== null) {
        return $pdo;
    }

    // SALON_DSN is only used by automated tests; normally it is not set.
    $dsn  = getenv('SALON_DSN') ?: 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $user = getenv('SALON_DSN') ? null : DB_USER;
    $pass = getenv('SALON_DSN') ? null : DB_PASS;

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // errors become exceptions
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // rows come back as arrays
        ]);
        if (strpos($dsn, 'sqlite') === 0) {
            $pdo->exec('PRAGMA foreign_keys = ON');                   // tests only
        }
    } catch (PDOException $e) {
        // A friendly message instead of a scary error page.
        http_response_code(500);
        exit('<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;max-width:560px;margin:60px auto">'
           . '<h2>Cannot reach the database</h2>'
           . '<ol><li>Open the XAMPP Control Panel and <b>start MySQL</b>.</li>'
           . '<li>Open <code>http://localhost/phpmyadmin</code> and <b>import</b> <code>database/salon.sql</code>.</li>'
           . '<li>Check the settings in <code>includes/config.php</code>.</li></ol></body>');
    }
    return $pdo;
}
