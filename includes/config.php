<?php
/**
 * ============================================================
 * ESTRELLA DEL REY DAVID NUMERO 11
 * includes/config.php — Database & App Configuration
 * ============================================================
 * SETUP INSTRUCTIONS:
 * 1. Copy this file to your server at /includes/config.php
 * 2. Fill in YOUR hosting database credentials below
 * 3. Change APP_SECRET to a random 32+ character string
 * 4. Set your site URL in APP_URL
 * 5. IMPORTANT: Make sure this file is NOT publicly accessible.
 *    Add to your .htaccess:  Deny from all
 *    OR store it ABOVE your public_html folder if your host allows
 * ============================================================
 */

// ─────────────────────────────────────────────────────────
// DATABASE SETTINGS
// Get these from your hosting control panel (cPanel, Plesk, etc.)
// ─────────────────────────────────────────────────────────
define('DB_HOST',     '127.0.0.1:3306');          // Usually 'localhost'
define('DB_NAME',     'u581562866_lodge11_db');         // Your database name
define('DB_USER',     'u581562866_lodge11_user');       // Your database username
define('DB_PASS',     '96!Thinkfast'); // Your database password
define('DB_CHARSET',  'utf8mb4');

// ─────────────────────────────────────────────────────────
// APPLICATION SETTINGS
// ─────────────────────────────────────────────────────────
define('APP_NAME',    'Estrella Del Rey David Numero 11');
define('APP_URL',     'https://edrd11.creativeworkspace-bz.com');   // No trailing slash
// CHANGE THIS: random 32+ character string for session security
define('APP_SECRET',  'CHANGE_THIS_TO_A_RANDOM_STRING_AT_LEAST_32_CHARS');
define('APP_VERSION', '1.0.0');

// ─────────────────────────────────────────────────────────
// SECURITY SETTINGS
// ─────────────────────────────────────────────────────────
define('MAX_LOGIN_ATTEMPTS', 5);       // Lock after this many failed tries
define('LOCKOUT_MINUTES',    15);      // Minutes to lock out after too many attempts
define('SESSION_LIFETIME',   3600);    // Session expires after 1 hour (seconds)
define('BCRYPT_COST',        12);      // bcrypt work factor (12 is safe default)

// ─────────────────────────────────────────────────────────
// EMAIL SETTINGS (for future notifications — optional)
// ─────────────────────────────────────────────────────────
define('MAIL_FROM',  'noreply@yourdomain.com');
define('MAIL_NAME',  APP_NAME);

// ─────────────────────────────────────────────────────────
// TIMEZONE — Set to your lodge's timezone
// Full list: https://www.php.net/manual/en/timezones.php
// ─────────────────────────────────────────────────────────
date_default_timezone_set('America/Belize');

// ─────────────────────────────────────────────────────────
// ENVIRONMENT — set to 'production' on live server
// In production: errors are logged, not displayed
// ─────────────────────────────────────────────────────────
define('APP_ENV', 'production'); // 'development' or 'production'

if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}