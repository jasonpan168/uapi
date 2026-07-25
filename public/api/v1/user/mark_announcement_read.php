<?php
// public/api/v1/user/mark_announcement_read.php
session_start();
require_once __DIR__ . '/../../../../src/Core/Database.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

// Check if already read
$exists = $db->fetch("SELECT 1 FROM user_read_announcements WHERE user_id = ? AND announcement_id = ?", [$user_id, $id]);

if (!$exists) {
    try {
        $db->query("INSERT INTO user_read_announcements (user_id, announcement_id) VALUES (?, ?)", [$user_id, $id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error']);
    }
} else {
    echo json_encode(['status' => 'success', 'message' => 'Already read']);
}
