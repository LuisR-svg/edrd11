<?php
/**
 * api/dues_adjustment.php — Toggle Due Payment Status
 * ============================================================
 * Allows admin to mark a month as paid/unpaid to correct errors.
 * All endpoints require admin authentication.
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
secure_session_start();
require_admin();
require_post();
csrf_protect();

$action = get_param('action') ?: 'toggle';
$pdo    = DB::get();

// ── TOGGLE PAYMENT STATUS ────────────────────────────────
if ($action === 'toggle') {
    $input    = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $member_id = int_val($input['member_id'] ?? 0);
    $year     = int_val($input['year'] ?? date('Y'));
    $month    = int_val($input['month'] ?? 0);
    $paid     = isset($input['paid']) ? (bool)$input['paid'] : null;

    if (!$member_id || !$month || $month < 1 || $month > 12) {
        json_error('Member ID, year, and month (1-12) are required');
    }

    // Check if member exists
    $memCheck = $pdo->prepare("SELECT id FROM members WHERE id=?");
    $memCheck->execute([$member_id]);
    if (!$memCheck->fetch()) {
        json_error('Member not found');
    }

    // Get current state
    $stmt = $pdo->prepare("SELECT * FROM dues WHERE member_id=? AND year=? AND month=?");
    $stmt->execute([$member_id, $year, $month]);
    $old = $stmt->fetch();

    if (!$old) {
        // Create new dues record
        $newPaid = $paid !== null ? $paid : true;
        $pdo->prepare(
            "INSERT INTO dues (member_id, year, month, amount, paid, paid_date) VALUES (?,?,?,0,?,?)"
        )->execute([$member_id, $year, $month, $newPaid ? 1 : 0, $newPaid ? date('Y-m-d') : null]);
        
        audit_log('create_dues', 'dues', 0, [], ['member_id'=>$member_id,'year'=>$year,'month'=>$month,'paid'=>$newPaid]);
        json_ok(['message' => "Due created for month $month and marked as " . ($newPaid ? 'paid' : 'unpaid')]);
    } else {
        // Toggle or set state
        $newPaid = $paid !== null ? $paid : !$old['paid'];
        $pdo->prepare(
            "UPDATE dues SET paid=?, paid_date=? WHERE member_id=? AND year=? AND month=?"
        )->execute([$newPaid ? 1 : 0, $newPaid ? date('Y-m-d') : null, $member_id, $year, $month]);
        
        audit_log('update_dues', 'dues', 0, $old, ['paid'=>$newPaid]);
        json_ok(['message' => "Due for month $month marked as " . ($newPaid ? 'paid' : 'unpaid')]);
    }
}

// ── GET MEMBER DUES FOR A YEAR ───────────────────────────
if ($action === 'get') {
    $member_id = int_val($_GET['member_id'] ?? 0);
    $year      = int_val($_GET['year'] ?? date('Y'));

    if (!$member_id) json_error('Member ID required');

    $stmt = $pdo->prepare("SELECT * FROM dues WHERE member_id=? AND year=? ORDER BY month");
    $stmt->execute([$member_id, $year]);
    $dues = $stmt->fetchAll();
    
    json_ok(['dues' => $dues]);
}

json_error('Invalid action');