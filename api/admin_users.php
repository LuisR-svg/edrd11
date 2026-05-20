<?php
/**
 * api/admin_users.php — Admin User Management API
 * ============================================================
 * Allows admins to create, update, and manage other admin accounts.
 * All endpoints require admin authentication.
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
secure_session_start();
require_admin();
require_post();
csrf_protect();

$action = get_param('action') ?: 'add';
$pdo    = DB::get();

// ── ADD ADMIN USER ──────────────────────────────────────
if ($action === 'add') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $username = mb_substr(trim($input['username'] ?? ''), 0, 100);
    $password = $input['password'] ?? '';
    $name     = mb_substr(trim($input['name'] ?? ''), 0, 120);
    $email    = trim($input['email'] ?? '');

    if (!$username) json_error('Username is required');
    if (strlen($password) < 6) json_error('Password must be at least 6 characters');
    if (!valid_email($email)) json_error('Valid email is required');

    // Check username not taken
    $check = $pdo->prepare("SELECT id FROM admin_users WHERE username=?");
    $check->execute([$username]);
    if ($check->fetch()) json_error('Username already exists');

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    $stmt = $pdo->prepare(
        "INSERT INTO admin_users (username, password_hash, name, email, active)
         VALUES (?, ?, ?, ?, 1)"
    );
    $stmt->execute([$username, $hash, $name, $email]);
    $id = $pdo->lastInsertId();
    
    audit_log('add_admin', 'admin_users', $id, [], ['username'=>$username,'name'=>$name,'email'=>$email]);
    json_ok(['id' => $id, 'message' => "Admin user '$username' created successfully"]);
}

// ── UPDATE ADMIN USER ───────────────────────────────────
if ($action === 'update') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    
    if (!$id) json_error('Admin ID required');

    $old = $pdo->prepare("SELECT * FROM admin_users WHERE id=?");
    $old->execute([$id]);
    $oldData = $old->fetch();
    if (!$oldData) json_error('Admin user not found');

    $fields = []; $vals = [];
    $allowed = ['name', 'email'];
    
    foreach ($allowed as $f) {
        if (isset($input[$f])) {
            $fields[] = "`$f`=?";
            $vals[] = mb_substr(trim($input[$f]), 0, 255);
        }
    }

    // If password provided, update it
    if (!empty($input['password'])) {
        if (strlen($input['password']) < 6) json_error('Password must be at least 6 characters');
        $fields[] = 'password_hash=?';
        $vals[] = password_hash($input['password'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }

    if (empty($fields)) json_error('No fields to update');
    $vals[] = $id;
    
    $pdo->prepare("UPDATE admin_users SET " . implode(', ', $fields) . " WHERE id=?")->execute($vals);
    audit_log('update_admin', 'admin_users', $id, $oldData, $input);
    json_ok(['message' => 'Admin user updated']);
}

// ── DELETE ADMIN USER ───────────────────────────────────
if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    
    if (!$id) json_error('Admin ID required');
    
    // Prevent deleting self
    if ($id === (int)$_SESSION['admin_id']) {
        json_error('Cannot delete your own admin account');
    }

    $admin = $pdo->prepare("SELECT * FROM admin_users WHERE id=?");
    $admin->execute([$id]);
    $adminData = $admin->fetch();
    
    if (!$adminData) json_error('Admin user not found');

    $pdo->prepare("DELETE FROM admin_users WHERE id=?")->execute([$id]);
    audit_log('delete_admin', 'admin_users', $id, $adminData, []);
    json_ok(['message' => 'Admin user deleted']);
}

// ── TOGGLE ADMIN ACTIVE STATUS ──────────────────────────
if ($action === 'toggle') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    
    if (!$id) json_error('Admin ID required');
    
    // Prevent disabling self
    if ($id === (int)$_SESSION['admin_id']) {
        json_error('Cannot deactivate your own admin account');
    }

    $admin = $pdo->prepare("SELECT active FROM admin_users WHERE id=?");
    $admin->execute([$id]);
    $current = $admin->fetchColumn();
    
    if ($current === false) json_error('Admin user not found');

    $newStatus = !$current;
    $pdo->prepare("UPDATE admin_users SET active=? WHERE id=?")->execute([$newStatus ? 1 : 0, $id]);
    audit_log('toggle_admin_status', 'admin_users', $id, [], ['active'=>$newStatus]);
    json_ok(['message' => 'Admin status updated']);
}

// ── LIST ALL ADMINS ────────────────────────────────────
if ($action === 'list') {
    $admins = $pdo->query("SELECT id, username, name, email, active, last_login FROM admin_users ORDER BY created_at DESC")->fetchAll();
    json_ok(['admins' => $admins]);
}

json_error('Invalid action');