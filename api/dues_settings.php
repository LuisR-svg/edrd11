<?php
/**
 * api/dues_settings.php — Set monthly dues rate per year
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');
secure_session_start();
require_admin();
csrf_protect();   // no require_post() — allow both GET and POST

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = DB::get();

// ── GET: return current rate for a year ──────────────────
if ($method === 'GET') {
    $year = int_val($_GET['year'] ?? date('Y'));
    $stmt = $pdo->prepare("SELECT amount FROM dues_settings WHERE year=?");
    $stmt->execute([$year]);
    $row = $stmt->fetch();
    json_ok(['amount' => $row ? (float)$row['amount'] : 0, 'year' => $year]);
}

// ── POST: upsert rate ────────────────────────────────────
$input  = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$year   = int_val($input['year']   ?? date('Y'));
$amount = float_val($input['amount'] ?? 0);

if ($amount <= 0) json_error('Amount must be greater than 0');
if ($year < 2000 || $year > 2100) json_error('Invalid year');

$pdo->prepare(
    "INSERT INTO dues_settings (year, amount)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE amount=VALUES(amount)"
)->execute([$year, $amount]);

audit_log('update_dues_settings', 'dues_settings', $year, [], ['amount'=>$amount,'year'=>$year]);
json_ok(['message' => "Cuota mensual para $year actualizada a \$$amount"]);