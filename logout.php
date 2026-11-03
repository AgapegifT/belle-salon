<?php
/** logout.php  -  ends the session and returns to the home page. */
require __DIR__ . '/includes/bootstrap.php';
logout();
session_start();                       // a new, empty session just to carry the message below
flash('success', 'You have been logged out.');
redirect('index.php');
