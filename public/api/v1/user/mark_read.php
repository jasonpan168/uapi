<?php
session_start();
if (!isset($_SESSION['user_id'])) exit;

require_once __DIR__ . '/../../../../src/Core/Database.php';
$db = Database::getInstance();

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    $db->query("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?", [$id, $_SESSION['user_id']]);
}
echo json_encode(['status'=>'ok']);
