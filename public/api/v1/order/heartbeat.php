<?php
// public/api/v1/order/heartbeat.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/SecurityService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['order_no'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing order_no']);
    exit;
}

try {
    $db = Database::getInstance();
    $sec = new SecurityService($db);

    $ip = $_SERVER['REMOTE_ADDR'];
    $session_token = (string)($input['session_token'] ?? '');
    $order_no = $input['order_no'];

    // Check Admin Exemption
    $is_admin = false;
    if (isset($_SESSION['user_id'])) {
        $user = $db->fetch("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
        if ($user && $user['role'] === 'admin') {
            $is_admin = true;
        }
    }

    // Admins bypass session token requirement entirely
    if ($is_admin) {
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($session_token === '') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing token']);
        exit;
    }

    $allowed = $sec->trackPaymentPage($order_no, $session_token, $ip, false);
    
    if (!$allowed) {
        // Return 429 Too Many Requests (or 403 Forbidden)
        http_response_code(429);
        echo json_encode(['status' => 'blocked', 'message' => 'This order is already monitored in another page.']);
    } else {
        echo json_encode(['status' => 'success']);
    }

} catch (Exception $e) {
    error_log('[order/heartbeat] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
