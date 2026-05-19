<?php
/**
 * api/donations.php — Donations CRUD API
 * Supports donations from both members and non-members.
 * Add requires admin; delete requires admin.
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

if ($action === 'add') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    // member_id is optional (NULL = non-member donor)
    $member_id   = !empty($input['member_id']) ? int_val($input['member_id']) : null;
    $donor_name  = mb_substr(trim($input['donor_name'] ?? ''), 0, 120);
    $donor_email = trim($input['donor_email'] ?? '');
    $amount      = float_val($input['amount'] ?? 0);
    $date        = preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date'] ?? '') ? $input['date'] : date('Y-m-d');
    $category    = mb_substr(trim($input['category'] ?? 'General'), 0, 80);
    $note        = mb_substr(trim($input['note'] ?? ''), 0, 255);
    $anonymous   = !empty($input['anonymous']) ? 1 : 0;

    if (!$amount) json_error('Donation amount must be greater than 0');
    // Non-member requires a name
    if (!$member_id && !$donor_name) json_error('Donor name is required for non-members');
    if ($donor_email && !filter_var($donor_email, FILTER_VALIDATE_EMAIL)) json_error('Invalid donor email');

    // If member_id given, fetch their name for records
    if ($member_id) {
        $mem = $pdo->prepare("SELECT name, email FROM members WHERE id=?");
        $mem->execute([$member_id]);
        $memData = $mem->fetch();
        if (!$memData) json_error('Member not found');
        $donor_name  = $memData['name'];
        $donor_email = $memData['email'];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO donations (member_id, donor_name, donor_email, amount, date, category, note, anonymous)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([$member_id, $donor_name, $donor_email ?: null, $amount, $date, $category, $note ?: null, $anonymous]);
    $id = $pdo->lastInsertId();
    audit_log('add_donation', 'donations', $id, [], ['amount'=>$amount,'donor'=>$donor_name,'category'=>$category]);
    json_ok(['id' => $id, 'message' => 'Donation recorded']);
}

if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    if (!$id) json_error('Donation ID required');
    $pdo->prepare("DELETE FROM donations WHERE id=?")->execute([$id]);
    audit_log('delete_donation', 'donations', $id);
    json_ok(['message' => 'Donation deleted']);
}

json_error('Invalid action');