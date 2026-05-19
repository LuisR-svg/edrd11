<?php
/**
 * api/transactions.php — Transactions CRUD API
 * ============================================================
 * Handles adding, updating, deleting transactions.
 * Also handles dues payment recording by month.
 * All endpoints require admin authentication.
 * ============================================================
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

// ── ADD TRANSACTION ──────────────────────────────────────
if ($action === 'add') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $type        = in_array($input['type'] ?? '', ['income','expense']) ? $input['type'] : '';
    $amount      = float_val($input['amount'] ?? 0);
    $date        = preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date'] ?? '') ? $input['date'] : date('Y-m-d');
    $description = mb_substr(trim($input['description'] ?? ''), 0, 255);
    $category    = mb_substr(trim($input['category'] ?? 'General'), 0, 80);
    $member_id   = $input['member_id'] ? int_val($input['member_id']) : null;
    $reference   = mb_substr(trim($input['reference'] ?? ''), 0, 120);
    $dues_months = array_map('intval', $input['dues_months'] ?? []);
    $dues_year   = int_val($input['dues_year'] ?? date('Y'));

    if (!$type)   json_error('Transaction type is required');
    if (!$amount) json_error('Amount must be greater than 0');
    if (!$description) json_error('Description is required');

    try {
        $pdo->beginTransaction();

        // Insert main transaction
        $stmt = $pdo->prepare(
            "INSERT INTO transactions (type, amount, date, description, category, member_id, reference, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$type, $amount, $date, $description, $category, $member_id, $reference ?: null, $_SESSION['admin_id']]);
        $tx_id = $pdo->lastInsertId();

        // If dues payment, record each month paid
        if ($category === 'Dues' && $member_id && !empty($dues_months)) {
            $monthly_amount = $amount / count($dues_months);
            foreach ($dues_months as $month) {
                if ($month < 1 || $month > 12) continue;
                // Upsert dues record
                $pdo->prepare(
                    "INSERT INTO dues (member_id, year, month, amount, paid, paid_date, transaction_id)
                     VALUES (?, ?, ?, ?, 1, ?, ?)
                     ON DUPLICATE KEY UPDATE paid=1, paid_date=VALUES(paid_date), transaction_id=VALUES(transaction_id), amount=VALUES(amount)"
                )->execute([$member_id, $dues_year, $month, $monthly_amount, $date, $tx_id]);
            }
        }

        $pdo->commit();
        audit_log('add_transaction', 'transactions', $tx_id, [], ['type'=>$type,'amount'=>$amount,'description'=>$description]);
        json_ok(['id' => $tx_id, 'message' => 'Transaction recorded']);

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Transaction add error: ' . $e->getMessage());
        json_error('Failed to record transaction');
    }
}

// ── UPDATE TRANSACTION ───────────────────────────────────
if ($action === 'update') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id    = int_val($input['id'] ?? 0);
    if (!$id) json_error('Transaction ID required');

    // Fetch original for audit
    $old = $pdo->prepare("SELECT * FROM transactions WHERE id=?")->execute([$id]) ? $pdo->query("SELECT * FROM transactions WHERE id=$id")->fetch() : [];

    $fields = [];
    $vals   = [];
    $allowed = ['type','amount','date','description','category','reference'];
    foreach ($allowed as $f) {
        if (isset($input[$f])) {
            $fields[] = "`$f` = ?";
            $vals[]   = $f === 'amount' ? float_val($input[$f]) : mb_substr(trim($input[$f]),0,255);
        }
    }
    if (empty($fields)) json_error('No fields to update');

    $vals[] = $id;
    $pdo->prepare("UPDATE transactions SET " . implode(', ', $fields) . " WHERE id=?")->execute($vals);
    audit_log('update_transaction', 'transactions', $id, $old, $input);
    json_ok(['message' => 'Transaction updated']);
}

// ── DELETE TRANSACTION ───────────────────────────────────
if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id    = int_val($input['id'] ?? 0);
    if (!$id) json_error('Transaction ID required');

    $old = $pdo->prepare("SELECT * FROM transactions WHERE id=?");
    $old->execute([$id]);
    $oldData = $old->fetch();

    $pdo->prepare("DELETE FROM transactions WHERE id=?")->execute([$id]);
    audit_log('delete_transaction', 'transactions', $id, $oldData, []);
    json_ok(['message' => 'Transaction deleted']);
}

json_error('Invalid action');