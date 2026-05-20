<?php
/**
 * api/auth.php — Login & Logout Handler
 * ============================================================
 * FIX APPLIED: Passwords/PINs now read from $_POST directly,
 * NOT through the post() helper which calls strip_tags().
 * strip_tags() can corrupt passwords containing < > characters.
 * ============================================================
 */

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
    $email = post('email');                        // email is safe to sanitize
    $pin   = trim($_POST['pin'] ?? '');            // FIX: read PIN raw — no strip_tags on credentials

    // Brute-force check
    if (is_locked_out($ip, 'member')) {
        $_SESSION['login_error_member'] = 'Demasiados intentos fallidos. Por favor espere ' . LOCKOUT_MINUTES . ' minuto(s).';
        header('Location: /?login=member');
        exit;
    }

    if (!valid_email($email) || empty($pin)) {
        record_attempt($ip, $email, 'member', false);
        $_SESSION['login_error_member'] = 'Por favor ingrese un correo y PIN válidos.';
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
        session_regenerate_id(true);
        $_SESSION['member_id']   = $member['id'];
        $_SESSION['member_name'] = $member['name'];
        $_SESSION['member_role'] = $member['role'];
        $_SESSION['_created']    = time();
        $_SESSION['_initiated']  = true;
        header('Location: /member/dashboard.php');
        exit;
    } else {
        record_attempt($ip, $email, 'member', false);
        $_SESSION['login_error_member'] = 'Correo o PIN no encontrado. Por favor intente de nuevo.';
        header('Location: /?login=member');
        exit;
    }
}

// ── ADMIN LOGIN ──────────────────────────────────────────
if ($type === 'admin') {
    $username = trim($_POST['username'] ?? '');    // FIX: read raw — no strip_tags
    $password = trim($_POST['password'] ?? '');    // FIX: read raw — passwords must not be sanitized

    if (is_locked_out($ip, 'admin')) {
        $_SESSION['login_error_admin'] = 'Demasiados intentos fallidos. Por favor espere ' . LOCKOUT_MINUTES . ' minuto(s).';
        header('Location: /?login=admin');
        exit;
    }

    if (empty($username) || empty($password)) {
        $_SESSION['login_error_admin'] = 'Por favor ingrese usuario y contraseña.';
        header('Location: /?login=admin');
        exit;
    }

    $pdo  = DB::get();
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
        $_SESSION['_initiated'] = true;
        $pdo->prepare("UPDATE admin_users SET last_login=NOW() WHERE id=?")->execute([$admin['id']]);
        header('Location: /admin/dashboard.php');
        exit;
    } else {
        record_attempt($ip, $username, 'admin', false);
        $_SESSION['login_error_admin'] = 'Credenciales inválidas.';
        header('Location: /?login=admin');
        exit;
    }
}

// Invalid type — redirect home
header('Location: /');
exit;