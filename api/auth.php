<?php
/**
 * api/auth.php — Login & Logout Handler
 * ============================================================
 * Handles POST requests for member login, admin login, logout.
 * All form submissions go here. Uses brute-force protection.
 * ============================================================
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

secure_session_start();

// ── LOGOUT ──────────────────────────────────────────────
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: /');
    exit;
}

// Only allow POST
require_post();
csrf_protect();

$type = post('type'); // 'member' or 'admin'
$ip   = get_ip();
// ── MEMBER LOGIN ─────────────────────────────────────────
if ($type === 'member') {
    $email = post('email');
    $pin   = post('pin');

    // Brute-force check
    if (is_locked_out($ip, 'member')) {
        $_SESSION['login_error_member'] = 'Too many failed attempts. Please wait ' . LOCKOUT_MINUTES . ' minutes.';
        header('Location: /?login=member');
        exit;
    }

    if (!valid_email($email) || empty($pin)) {
        record_attempt($ip, $email, 'member', false);
        $_SESSION['login_error_member'] = 'Please enter a valid email and PIN.';
        header('Location: /?login=member');
        exit;
    }

    $pdo  = DB::get();
    $stmt = $pdo->prepare("SELECT * FROM members WHERE email = ? AND active = 1 LIMIT 1");
    $stmt->execute([$email]);
    $member = $stmt->fetch();

    if ($member && password_verify($pin, $member['pin_hash'])) {
        record_attempt($ip, $email, 'member', true);
        clear_attempts($ip, 'member');
        // Regenerate session to prevent fixation
        session_regenerate_id(true);
        $_SESSION['member_id']   = $member['id'];
        $_SESSION['member_name'] = $member['name'];
        $_SESSION['member_role'] = $member['role'];
        $_SESSION['_created']    = time();
        header('Location: /member/dashboard.php');
        exit;
    } else {
        record_attempt($ip, $email, 'member', false);
        // Use generic error message (don't reveal if email exists)
        $_SESSION['login_error_member'] = 'Email or PIN not found. Please try again.';
        header('Location: /?login=member');
        exit;
    }
}

// ── ADMIN LOGIN ──────────────────────────────────────────
if ($type === 'admin') {
    $username = post('username');
    $password = post('password');

    if (is_locked_out($ip, 'admin')) {
        $_SESSION['login_error_admin'] = 'Too many failed attempts. Please wait ' . LOCKOUT_MINUTES . ' minutes.';
        header('Location: /?login=admin');
        exit;
    }

    $pdo  = DB::get();
    var_dump($username);
exit;
    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE username = ? AND active = 1 LIMIT 1");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        record_attempt($ip, $username, 'admin', true);
        clear_attempts($ip, 'admin');
        session_regenerate_id(true);
        $_SESSION['admin_id']   = $admin['id'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['_created']   = time();
        // Update last login
        $pdo->prepare("UPDATE admin_users SET last_login=NOW() WHERE id=?")->execute([$admin['id']]);
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        record_attempt($ip, $username, 'admin', false);
        $_SESSION['login_error_admin'] = 'Invalid credentials.';
        header('Location: /?login=admin');
        exit;
    }
}

// Invalid type
header('Location: /');
exit;

