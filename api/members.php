<?php
/**
 * api/members.php — Members CRUD API
 * All endpoints require admin authentication.
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/degrees.php';

header('Content-Type: application/json');
secure_session_start();
require_admin();
require_post();
csrf_protect();

$action = get_param('action') ?: 'add';
$pdo    = DB::get();

if ($action === 'add') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $name    = mb_substr(trim($input['name'] ?? ''), 0, 120);
    $email   = filter_var(trim($input['email'] ?? ''), FILTER_SANITIZE_EMAIL);
    $role    = mb_substr(trim($input['role'] ?? 'Member'), 0, 80);
    $degree  = min(33, max(1, int_val($input['degree'] ?? 1)));
    $pin     = $input['pin'] ?? '';
    $joined  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['joined_date'] ?? '') ? $input['joined_date'] : date('Y-m-d');
    $phone   = mb_substr(trim($input['phone'] ?? ''), 0, 30);
    $address = mb_substr(trim($input['address'] ?? ''), 0, 255);
    $notes   = mb_substr(trim($input['notes'] ?? ''), 0, 500);

    if (!$name)  json_error('Name is required');
    if (!valid_email($email)) json_error('Valid email is required');
    if (strlen($pin) < 4 || strlen($pin) > 8) json_error('PIN must be 4-8 digits');
    if (!ctype_digit($pin)) json_error('PIN must contain only numbers');

    // Check email not already taken
    $check = $pdo->prepare("SELECT id FROM members WHERE email=?");
    $check->execute([$email]);
    if ($check->fetch()) json_error('A member with this email already exists');

    $degree_name = SCOTTISH_RITE_DEGREES[$degree];
    $pin_hash    = password_hash($pin, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);

    $stmt = $pdo->prepare(
        "INSERT INTO members (name, email, pin_hash, role, degree, degree_name, active, joined_date, phone, address, notes)
         VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)"
    );
    $stmt->execute([$name, $email, $pin_hash, $role, $degree, $degree_name, $joined, $phone, $address, $notes]);
    $id = $pdo->lastInsertId();
    audit_log('add_member', 'members', $id, [], ['name'=>$name,'email'=>$email]);
    json_ok(['id' => $id, 'message' => 'Member added']);
}

if ($action === 'update') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    if (!$id) json_error('Member ID required');

    $old = $pdo->prepare("SELECT * FROM members WHERE id=?");
    $old->execute([$id]);
    $oldData = $old->fetch();

    $fields = []; $vals = [];
    $allowed = ['name','email','role','phone','address','notes'];
    foreach ($allowed as $f) {
        if (isset($input[$f])) { $fields[] = "`$f`=?"; $vals[] = mb_substr(trim($input[$f]),0,255); }
    }
    if (isset($input['degree'])) {
        $d = min(33, max(1, int_val($input['degree'])));
        $fields[] = 'degree=?'; $vals[] = $d;
        $fields[] = 'degree_name=?'; $vals[] = SCOTTISH_RITE_DEGREES[$d];
    }
    // Only update PIN if provided
    if (!empty($input['new_pin'])) {
        if (strlen($input['new_pin']) < 4 || !ctype_digit($input['new_pin'])) json_error('PIN must be 4-8 digits');
        $fields[] = 'pin_hash=?';
        $vals[] = password_hash($input['new_pin'], PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }
    if (empty($fields)) json_error('No fields to update');
    $vals[] = $id;
    $pdo->prepare("UPDATE members SET " . implode(', ', $fields) . " WHERE id=?")->execute($vals);
    audit_log('update_member', 'members', $id, $oldData, $input);
    json_ok(['message' => 'Member updated']);
}

if ($action === 'toggle') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    if (!$id) json_error('Member ID required');
    $pdo->prepare("UPDATE members SET active = NOT active WHERE id=?")->execute([$id]);
    audit_log('toggle_member', 'members', $id);
    json_ok(['message' => 'Member status updated']);
}

if ($action === 'update_dues') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    $year = int_val($input['year'] ?? date('Y'));
    $month = int_val($input['month'] ?? 0);
    $paid = isset($input['paid']) ? (bool)$input['paid'] : false;
    $amount = float_val($input['amount'] ?? 0);

    if (!$id || !$month) json_error('Member ID and month required');
    $pdo->prepare(
        "INSERT INTO dues (member_id, year, month, amount, paid, paid_date)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE paid=VALUES(paid), paid_date=VALUES(paid_date), amount=VALUES(amount)"
    )->execute([$id, $year, $month, $amount, $paid ? 1 : 0, $paid ? date('Y-m-d') : null]);
    json_ok(['message' => 'Dues updated']);
}

json_error('Invalid action');