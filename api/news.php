<?php
/**
 * api/news.php — News/Notices CRUD API
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
    $title  = mb_substr(trim($input['title'] ?? ''), 0, 200);
    $body   = mb_substr(trim($input['body'] ?? ''), 0, 5000);
    $author = mb_substr(trim($input['author'] ?? 'Admin'), 0, 100);
    if (!$title || !$body) json_error('Title and body are required');
    $pdo->prepare("INSERT INTO news (title, body, author) VALUES (?,?,?)")->execute([$title, $body, $author]);
    $id = $pdo->lastInsertId();
    audit_log('add_news', 'news', $id);
    json_ok(['id' => $id]);
}

if ($action === 'delete') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    if (!$id) json_error('ID required');
    $pdo->prepare("DELETE FROM news WHERE id=?")->execute([$id]);
    audit_log('delete_news', 'news', $id);
    json_ok();
}

if ($action === 'toggle') {
    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = int_val($input['id'] ?? 0);
    $pdo->prepare("UPDATE news SET published=NOT published WHERE id=?")->execute([$id]);
    json_ok();
}

json_error('Invalid action');