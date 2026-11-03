<?php
/**
 * includes/config.php  -  the only file you may need to edit.
 *
 * The defaults below match a fresh XAMPP: MySQL user "root" with an empty password.
 */

// ---- Database ---------------------------------------------------------
const DB_HOST = 'localhost';
const DB_NAME = 'salon_db';
const DB_USER = 'root';
const DB_PASS = '';          // XAMPP's default is an empty password

// ---- Salon settings ---------------------------------------------------
const SALON_NAME    = 'Belle Salon';
const OPEN_TIME     = '09:00';   // first bookable time of the day
const CLOSE_TIME    = '18:00';   // last service must end by this time
const SLOT_MINUTES  = 30;        // times offered in 30-minute steps
const GRACE_MINUTES = 15;        // a client later than this is a "no-show"
const BOOK_DAYS_AHEAD = 60;      // how far ahead clients may book

// Promo codes: code => percent off (used on the payment page)
const PROMO_CODES = ['WELCOME10' => 10, 'LOYAL20' => 20];
// WELCOME10 works on a client's FIRST payment only. LOYAL20 works for LOYAL clients only:
// a client becomes loyal after collecting LOYAL_POINTS points (1 point for every 1,000 RWF paid).
const LOYAL_POINTS = 20;

// Time zone used for all dates and times (Kigali). Change if you are elsewhere.
date_default_timezone_set('Africa/Kigali');
