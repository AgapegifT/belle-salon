<?php
/**
 * includes/bootstrap.php  -  the first line of every page loads this file.
 * It loads the settings, starts the session (the "remember who is logged in" part)
 * and works out the web address of the project folder.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

session_start();

// BASE_URL = the folder of the project as seen by the browser.
// XAMPP: C:\xampp\htdocs\salon  ->  "/salon".   (empty when the project is the web root)
$root = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$doc  = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
define('BASE_URL', ($doc !== '' && strncasecmp($root, $doc, strlen($doc)) === 0) ? substr($root, strlen($doc)) : '');
