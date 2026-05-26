<?php
/**
 * api/dues_adjustment.php — Toggle a single month paid/unpaid
 * Works for both members (member_id) and admin users (admin_id).
 * Send exactly one of: member_id or admin_id.
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
secure_session_start();
require_admin();
require_post();
csrf_protect();

$input     = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$member_id = int_val($input['member_id'] ?? 0);
$admin_id  = int_val($input['admin_id']  ?? 0);
$year      = int_val($input['year']      ?? date('Y'));
$month     = int_val($input['month']     ?? 0);
$paid      = isset($input['paid']) ? (bool)$input['paid'] : null;

// Must have one subject
if (!$member_id && !$admin_id) json_error('member_id or admin_id required');
if ($member_id && $admin_id)   json_error('Send only one of member_id or admin_id');
if ($month < 1 || $month > 12) json_error('month must be 1–12');

$pdo = DB::get();

// Verify the subject exists
if ($member_id) {
    $chk = $pdo->prepare("SELECT id FROM members WHERE id=?");
    $chk->execute([$member_id]);
    if (!$chk->fetch()) json_error('Member not found');
}
if ($admin_id) {
    $chk = $pdo->prepare("SELECT id FROM admin_users WHERE id=?");
    $chk->execute([$admin_id]);
    if (!$chk->fetch()) json_error('Admin user not found');
}

// Get current dues rate for this year
$rateStmt = $pdo->prepare("SELECT amount FROM dues_settings WHERE year=?");
$rateStmt->execute([$year]);
$monthlyRate = (float)($rateStmt->fetchColumn() ?: 0);

// Fetch existing dues row
if ($member_id) {
    $stmt = $pdo->prepare("SELECT * FROM dues WHERE member_id=? AND admin_id IS NULL AND year=? AND month=?");
    $stmt->execute([$member_id, $year, $month]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM dues WHERE admin_id=? AND member_id IS NULL AND year=? AND month=?");
    $stmt->execute([$admin_id, $year, $month]);
}
$existing = $stmt->fetch();

// Determine new paid state (toggle if $paid not explicitly given)
$newPaid = ($paid !== null) ? (bool)$paid : ($existing ? !$existing['paid'] : true);

if ($existing) {
    $pdo->prepare(
        "UPDATE dues SET paid=?, paid_date=?, amount=? WHERE id=?"
    )->execute([$newPaid ? 1 : 0, $newPaid ? date('Y-m-d') : null, $monthlyRate, $existing['id']]);
} else {
    if ($member_id) {
        $pdo->prepare(
            "INSERT INTO dues (member_id, admin_id, year, month, amount, paid, paid_date)
             VALUES (?, NULL, ?, ?, ?, ?, ?)"
        )->execute([$member_id, $year, $month, $monthlyRate, $newPaid ? 1 : 0, $newPaid ? date('Y-m-d') : null]);
    } else {
        $pdo->prepare(
            "INSERT INTO dues (member_id, admin_id, year, month, amount, paid, paid_date)
             VALUES (NULL, ?, ?, ?, ?, ?, ?)"
        )->execute([$admin_id, $year, $month, $monthlyRate, $newPaid ? 1 : 0, $newPaid ? date('Y-m-d') : null]);
    }
}

$subject = $member_id ? "member_id=$member_id" : "admin_id=$admin_id";
audit_log('dues_adjustment', 'dues', $existing['id'] ?? 0, $existing ?: [], [
    $subject, 'year' => $year, 'month' => $month, 'paid' => $newPaid, 'amount' => $monthlyRate
]);

json_ok(['paid' => $newPaid, 'message' => "Mes $month marcado como " . ($newPaid ? 'pagado' : 'pendiente')]);