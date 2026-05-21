<?php
/**
 * includes/security.php — Security Helpers
 * 
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function secure_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', // FIX: only true on real HTTPS
            'httponly' => true,
            'samesite' => 'Lax', // FIX: changed from Strict to Lax so redirects carry the cookie
        ]);
        session_name('LODGE11_SESS');
        session_start();

        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
            $_SESSION['_created']   = time();
        }
        // Expire old sessions — but don't recurse, just clear
        if (isset($_SESSION['_created']) && (time() - $_SESSION['_created']) > SESSION_LIFETIME) {
            session_unset();
            session_destroy();
            session_start();
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
            $_SESSION['_created']   = time();
        }
    }
}

/** Generate or return existing CSRF token */
function csrf_token(): string {
    secure_session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Return a hidden HTML input with the CSRF token */
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">';
}

/** Verify the submitted CSRF token */
function csrf_verify(): bool {
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($submitted)) return false;
    return hash_equals(csrf_token(), $submitted);
}

/** Abort if CSRF token invalid */
function csrf_protect(): void {
    if (!csrf_verify()) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Invalid security token. Please refresh and try again.']));
    }
}

/** Sanitize string for HTML output (XSS prevention) */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Get sanitized POST string — NOT for passwords */
function post(string $key, string $default = ''): string {
    return trim(strip_tags($_POST[$key] ?? $default));
}

/** Get sanitized GET string */
function get_param(string $key, string $default = ''): string {
    return trim(strip_tags($_GET[$key] ?? $default));
}

/** Validate email */
function valid_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Safe positive int cast */
function int_val(mixed $val): int {
    return max(0, (int) $val);
}

/** Safe positive float cast */
function float_val(mixed $val): float {
    return max(0.0, (float) $val);
}

/** Detect real client IP */
function get_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '0.0.0.0';
}

/** Check if IP is locked out from too many failed logins */
function is_locked_out(string $ip, string $type): bool {
    $pdo   = DB::get();
    $since = date('Y-m-d H:i:s', time() - (LOCKOUT_MINUTES * 60));
    $stmt  = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts WHERE ip_address=? AND type=? AND success=0 AND attempted_at>?"
    );
    $stmt->execute([$ip, $type, $since]);
    return (int)$stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

/** Record a login attempt */
function record_attempt(string $ip, string $username, string $type, bool $success): void {
    DB::get()->prepare(
        "INSERT INTO login_attempts (ip_address, username, type, success) VALUES (?,?,?,?)"
    )->execute([$ip, $username, $type, $success ? 1 : 0]);
}

/** Clear login attempts after successful login */
function clear_attempts(string $ip, string $type): void {
    DB::get()->prepare("DELETE FROM login_attempts WHERE ip_address=? AND type=?")
             ->execute([$ip, $type]);
}

/** Is a member currently logged in? */
function is_member(): bool {
    secure_session_start();
    return !empty($_SESSION['member_id']);
}

/** Is an admin currently logged in? */
function is_admin(): bool {
    secure_session_start();
    return !empty($_SESSION['admin_id']);
}

/**
 * Require member login.
 * FIX: redirects to /?login=member (modal) not a separate page.
 */
function require_member(): void {
    if (!is_member()) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '/member/dashboard.php');
        header('Location: /?login=member&redirect=' . $redirect);
        exit;
    }
}

/**
 * Require admin login.
 * FIX: was redirecting to /admin/login.php which doesn't exist.
 * Now redirects to /?login=admin which opens the admin modal on index.php.
 */
function require_admin(): void {
    if (!is_admin()) {
        header('Location: /?login=admin');
        exit;
    }
}

/** Write an audit log entry */
function audit_log(string $action, string $table = '', int $record_id = 0, array $old = [], array $new = []): void {
    secure_session_start();
    DB::get()->prepare(
        "INSERT INTO audit_log (admin_id, action, table_affected, record_id, old_values, new_values, ip_address)
         VALUES (?,?,?,?,?,?,?)"
    )->execute([
        $_SESSION['admin_id'] ?? null,
        $action,
        $table ?: null,
        $record_id ?: null,
        $old ? json_encode($old) : null,
        $new  ? json_encode($new)  : null,
        get_ip(),
    ]);
}

/** Send JSON success response */
function json_ok(array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => true] + $data);
    exit;
}

/** Send JSON error response */
function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/** Require POST method */
function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }
}