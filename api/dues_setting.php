<?php
/**
 * api/dues_settings.php — Dues Settings API
 * ============================================================
 * Manage monthly dues rates per year.
 * All endpoints require admin authentication.
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
secure_session_start();
require_admin();
require_post();
csrf_protect();

$action = get_param('action') ?: 'update';
$pdo    = DB::get();

// ── UPDATE DUES RATE FOR A YEAR ──────────────────────────
if ($action === 'update' || $action === '') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $year   = int_val($input['year'] ?? date('Y'));
    $amount = float_val($input['amount'] ?? 0);

    if (!$amount || $amount <= 0) {
        json_error('Dues amount must be greater than 0');
    }

    // Check if exists for this year
    $check = $pdo->prepare("SELECT id FROM dues_settings WHERE year=?");
    $check->execute([$year]);
    
    if ($check->fetch()) {
        // Update existing
        $pdo->prepare("UPDATE dues_settings SET amount=? WHERE year=?")->execute([$amount, $year]);
        audit_log('update_dues_settings', 'dues_settings', $year, [], ['amount'=>$amount,'year'=>$year]);
        json_ok(['message' => "Dues rate for $year updated to \$$amount/month"]);
    } else {
        // Insert new
        $pdo->prepare("INSERT INTO dues_settings (year, amount) VALUES (?,?)")->execute([$year, $amount]);
        audit_log('add_dues_settings', 'dues_settings', $year, [], ['amount'=>$amount,'year'=>$year]);
        json_ok(['message' => "Dues rate for $year set to \$$amount/month"]);
    }
}

// ── GET DUES RATE ────────────────────────────────────────
if ($action === 'get') {
    $year = int_val($_GET['year'] ?? date('Y'));
    $stmt = $pdo->prepare("SELECT amount FROM dues_settings WHERE year=?");
    $stmt->execute([$year]);
    $result = $stmt->fetch();
    json_ok(['amount' => $result ? (float)$result['amount'] : 0]);
}

json_error('Invalid action');