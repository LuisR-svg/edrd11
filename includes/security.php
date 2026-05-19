<?php
/**
 * includes/security.php — Security Helpers
 * ============================================================
 * Provides: CSRF tokens, session management, input sanitization,
 * rate limiting, brute-force protection, IP detection.
 * ============================================================
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ─────────────────────────────────────────────────────────
// SESSION INITIALIZATION
// ─────────────────────────────────────────────────────────
function secure_session_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // Harden cookie settings
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => true,          // HTTPS only — change to false if no SSL during dev
            'httponly' => true,          // JavaScript cannot read the cookie
            'samesite' => 'Strict',      // CSRF protection
        ]);
        session_name('LODGE11_SESS');
        session_start();

        // Regenerate session ID periodically to prevent fixation
        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = true;
            $_SESSION['_created']   = time();
        }
        // Expire session after SESSION_LIFETIME
        if (isset($_SESSION['_created']) && (time() - $_SESSION['_created']) > SESSION_LIFETIME) {
            session_destroy();
            secure_session_start();
        }
    }
}

// ─────────────────────────────────────────────────────────
// CSRF PROTECTION
// ─────────────────────────────────────────────────────────

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

/** Verify the submitted CSRF token. Returns false on mismatch. */
function csrf_verify(): bool {
    $submitted = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals(csrf_token(), $submitted);
}

/** Abort if CSRF token invalid */
function csrf_protect(): void {
    if (!csrf_verify()) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid security token. Please refresh and try again.']));
    }
}

// ─────────────────────────────────────────────────────────
// INPUT SANITIZATION
// ─────────────────────────────────────────────────────────

/** Sanitize a string for safe output (prevents XSS) */
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Get a sanitized POST value */
function post(string $key, string $default = ''): string {
    return trim(strip_tags($_POST[$key] ?? $default));
}

/** Get a sanitized GET value */
function get_param(string $key, string $default = ''): string {
    return trim(strip_tags($_GET[$key] ?? $default));
}

/** Validate email format */
function valid_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Cast to positive integer safely */
function int_val(mixed $val): int {
    return max(0, (int) $val);
}

/** Cast to positive decimal */
function float_val(mixed $val): float {
    return max(0.0, (float) $val);
}

// ─────────────────────────────────────────────────────────
// IP ADDRESS DETECTION
// ─────────────────────────────────────────────────────────
function get_ip(): string {
    // Check common proxy headers, fall back to REMOTE_ADDR
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            // Take first IP from comma-separated list
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

// ─────────────────────────────────────────────────────────
// RATE LIMITING / BRUTE FORCE PROTECTION
// ─────────────────────────────────────────────────────────

/** Check if IP is currently locked out. Returns true if locked. */
function is_locked_out(string $ip, string $type): bool {
    $pdo = DB::get();
    $since = date('Y-m-d H:i:s', time() - (LOCKOUT_MINUTES * 60));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = ? AND type = ? AND success = 0 AND attempted_at > ?"
    );
    $stmt->execute([$ip, $type, $since]);
    return (int)$stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

/** Record a login attempt */
function record_attempt(string $ip, string $username, string $type, bool $success): void {
    $pdo = DB::get();
    $pdo->prepare(
        "INSERT INTO login_attempts (ip_address, username, type, success) VALUES (?, ?, ?, ?)"
    )->execute([$ip, $username, $type, $success ? 1 : 0]);
}

/** Clear login attempts for an IP (on successful login) */
function clear_attempts(string $ip, string $type): void {
    $pdo = DB::get();
    $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? AND type = ?")
        ->execute([$ip, $type]);
}

// ─────────────────────────────────────────────────────────
// AUTHENTICATION CHECKS
// ─────────────────────────────────────────────────────────

/** Check if current visitor is logged in as a member */
function is_member(): bool {
    secure_session_start();
    return isset($_SESSION['member_id']) && !empty($_SESSION['member_id']);
}

/** Check if current visitor is logged in as admin */
function is_admin(): bool {
    secure_session_start();
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/** Redirect to login if not member */
function require_member(): void {
    if (!is_member()) {
        header('Location: /index.php?login=member&redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

/** Redirect to admin login if not admin */
function require_admin(): void {
    if (!is_admin()) {
        header('Location: /admin/login.php');
        exit;
    }
}

// ─────────────────────────────────────────────────────────
// AUDIT LOGGING
// ─────────────────────────────────────────────────────────

/** Write an audit log entry for any admin action */
function audit_log(string $action, string $table = '', int $record_id = 0, array $old = [], array $new = []): void {
    secure_session_start();
    $pdo = DB::get();
    $pdo->prepare(
        "INSERT INTO audit_log (admin_id, action, table_affected, record_id, old_values, new_values, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
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

// ─────────────────────────────────────────────────────────
// JSON API HELPERS
// ─────────────────────────────────────────────────────────

/** Send a JSON success response and exit */
function json_ok(array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(['success' => true] + $data);
    exit;
}

/** Send a JSON error response and exit */
function json_error(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/** Require POST method for API endpoints */
function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_error('Method not allowed', 405);
    }
}