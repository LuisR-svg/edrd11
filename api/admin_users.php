<?php
/**
 * api/admin_users.php — Admin User Management
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
secure_session_start();
require_admin();
csrf_protect();

$pdo    = DB::get();
$method = $_SERVER['REQUEST_METHOD'];
$action = get_param('action') ?: 'add';

// ── GET: list all admins ─────────────────────────────────
if ($method === 'GET' && $action === 'list') {
    $admins = $pdo->query(
        "SELECT id, username, name, email, active, last_login, created_at FROM admin_users ORDER BY id ASC"
    )->fetchAll();
    json_ok(['admins' => $admins]);
}

// All write actions need POST
if ($method !== 'POST') json_error('Method not allowed', 405);
csrf_protect();

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// ── ADD ──────────────────────────────────────────────────
if ($action === 'add') {
    $username = mb_substr(trim($input['username'] ?? ''), 0, 80);
    $password = $input['password'] ?? '';
    $name     = mb_substr(trim($input['name']     ?? ''), 0, 120);
    $email    = trim($input['email'] ?? '');

    if (!$username)          json_error('Username is required');
    if (strlen($password)<6) json_error('Password must be at least 6 characters');
    if (!valid_email($email))json_error('Valid email is required');

    $chk = $pdo->prepare("SELECT id FROM admin_users WHERE username=?");
    $chk->execute([$username]);
    if ($chk->fetch()) json_error("Username '$username' is already taken");

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    $pdo->prepare(
        "INSERT INTO admin_users (username, password_hash, name, email, active) VALUES (?,?,?,?,1)"
    )->execute([$username, $hash, $name, $email]);
    $id = $pdo->lastInsertId();
    audit_log('add_admin_user', 'admin_users', $id, [], ['username'=>$username]);
    json_ok(['id' => $id, 'message' => "Admin '$username' created"]);
}

// ── UPDATE ───────────────────────────────────────────────
if ($action === 'update') {
    $id = int_val($input['id'] ?? 0);
    if (!$id) json_error('ID required');
    $old = $pdo->prepare("SELECT * FROM admin_users WHERE id=?");
    $old->execute([$id]); $oldData = $old->fetch();
    if (!$oldData) json_error('Admin not found');

    $fields = []; $vals = [];
    foreach (['name','email'] as $f) {
        if (isset($input[$f])) { $fields[] = "`$f`=?"; $vals[] = mb_substr(trim($input[$f]),0,255); }
    }
    if (!empty($input['password'])) {
        if (strlen($input['password'])<6) json_error('Password must be at least 6 characters');
        $fields[] = 'password_hash=?';
        $vals[]   = password_hash($input['password'], PASSWORD_BCRYPT, ['cost'=>BCRYPT_COST]);
    }
    if (empty($fields)) json_error('Nothing to update');
    $vals[] = $id;
    $pdo->prepare("UPDATE admin_users SET ".implode(',',$fields)." WHERE id=?")->execute($vals);
    audit_log('update_admin_user', 'admin_users', $id, $oldData, $input);
    json_ok(['message' => 'Admin updated']);
}

// ── TOGGLE active ────────────────────────────────────────
if ($action === 'toggle') {
    $id = int_val($input['id'] ?? 0);
    if (!$id) json_error('ID required');
    if ($id === (int)$_SESSION['admin_id']) json_error('Cannot deactivate your own account');
    $pdo->prepare("UPDATE admin_users SET active=NOT active WHERE id=?")->execute([$id]);
    audit_log('toggle_admin_user', 'admin_users', $id);
    json_ok(['message' => 'Status updated']);
}

// ── DELETE ───────────────────────────────────────────────
if ($action === 'delete') {
    $id = int_val($input['id'] ?? 0);
    if (!$id) json_error('ID required');
    if ($id === (int)$_SESSION['admin_id']) json_error('Cannot delete your own account');
    $old = $pdo->prepare("SELECT * FROM admin_users WHERE id=?"); $old->execute([$id]);
    $pdo->prepare("DELETE FROM admin_users WHERE id=?")->execute([$id]);
    audit_log('delete_admin_user', 'admin_users', $id);
    json_ok(['message' => 'Admin deleted']);
}

json_error('Invalid action');