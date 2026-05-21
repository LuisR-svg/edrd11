<?php
/**
 * api/dues_adjustment.php — Toggle a single month paid/unpaid
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
$year      = int_val($input['year']      ?? date('Y'));
$month     = int_val($input['month']     ?? 0);
$paid      = isset($input['paid']) ? (bool)$input['paid'] : null;

if (!$member_id)         json_error('member_id required');
if ($month < 1 || $month > 12) json_error('month must be 1–12');

$pdo = DB::get();

// Verify member exists
$mem = $pdo->prepare("SELECT id FROM members WHERE id=?");
$mem->execute([$member_id]);
if (!$mem->fetch()) json_error('Member not found');

// Get current state
$stmt = $pdo->prepare("SELECT * FROM dues WHERE member_id=? AND year=? AND month=?");
$stmt->execute([$member_id, $year, $month]);
$existing = $stmt->fetch();

// Determine new paid state
$newPaid = ($paid !== null) ? (bool)$paid : ($existing ? !$existing['paid'] : true);

if ($existing) {
    $pdo->prepare(
        "UPDATE dues SET paid=?, paid_date=? WHERE member_id=? AND year=? AND month=?"
    )->execute([$newPaid ? 1 : 0, $newPaid ? date('Y-m-d') : null, $member_id, $year, $month]);
} else {
    $pdo->prepare(
        "INSERT INTO dues (member_id, year, month, amount, paid, paid_date)
         VALUES (?, ?, ?, 0, ?, ?)"
    )->execute([$member_id, $year, $month, $newPaid ? 1 : 0, $newPaid ? date('Y-m-d') : null]);
}

audit_log('dues_adjustment', 'dues', 0, $existing ?: [], [
    'member_id' => $member_id, 'year' => $year, 'month' => $month, 'paid' => $newPaid
]);

json_ok(['paid' => $newPaid, 'message' => "Mes $month marcado como " . ($newPaid ? 'pagado' : 'pendiente')]);