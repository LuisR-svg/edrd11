<?php
/**
 * api/savings.php — Savings / Reserve Fund CRUD
 * ============================================================
 * Handles deposits and withdrawals from the lodge savings fund.
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

// ── ADD ──────────────────────────────────────────────────
if ($action === 'add') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $type        = in_array($input['type'] ?? '', ['deposit','withdrawal']) ? $input['type'] : '';
    $amount      = float_val($input['amount'] ?? 0);
    $date        = preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date'] ?? '') ? $input['date'] : date('Y-m-d');
    $description = mb_substr(trim($input['description'] ?? ''), 0, 255);
    $reference   = mb_substr(trim($input['reference']   ?? ''), 0, 120);

    if (!$type)        json_error('Type must be deposit or withdrawal');
    if ($amount <= 0)  json_error('Amount must be greater than 0');
    if (!$description) json_error('Description is required');

    $pdo->prepare(
        "INSERT INTO savings (type, amount, date, description, reference, created_by)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$type, $amount, $date, $description, $reference ?: null, $_SESSION['admin_id'] ?? null]);

    $id = $pdo->lastInsertId();
    audit_log('add_savings', 'savings', $id, [], ['type'=>$type,'amount'=>$amount]);
    json_ok(['id' => $id, 'message' => 'Savings record added']);
}

// ── DELETE ───────────────────────────────────────────────
if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    if (!$id) json_error('ID required');
    $pdo->prepare("DELETE FROM savings WHERE id=?")->execute([$id]);
    audit_log('delete_savings', 'savings', $id);
    json_ok(['message' => 'Record deleted']);
}

json_error('Invalid action');