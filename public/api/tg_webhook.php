<?php
// public/api/tg_webhook.php
require_once __DIR__ . '/../../src/Core/Database.php';

header('Content-Type: application/json');

$db = Database::getInstance();
$token_row = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'tg_bot_token'");
$token = $token_row ? $token_row['value'] : '';

if (empty($token)) {
    echo json_encode(['ok' => false, 'description' => 'Token not configured']);
    exit;
}

// Setup Action (Called by Admin)
$setupAction = ($_POST['action'] ?? '') === 'setup';
if ($setupAction) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'description' => 'Unauthorized']);
        exit;
    }
    $admin = $db->fetch("SELECT id FROM users WHERE id = ? AND role = 'admin' LIMIT 1", [$_SESSION['user_id']]);
    if (!$admin) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'description' => 'Forbidden']);
        exit;
    }
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'description' => 'Invalid CSRF token']);
        exit;
    }

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $url = "$protocol://$_SERVER[HTTP_HOST]/api/tg_webhook.php";
    
    $api = "https://api.telegram.org/bot$token/setWebhook?url=" . urlencode($url);
    echo file_get_contents($api);
    exit;
}

// Webhook Logic
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    echo json_encode(['ok' => true]); // Silence
    exit;
}

if (isset($update['message'])) {
    $msg = $update['message'];
    $chat_id = $msg['chat']['id'];
    $text = $msg['text'] ?? '';
    
    $db->query("DELETE FROM tg_bind_codes WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $code = strtoupper(bin2hex(random_bytes(3)));
    $db->query("INSERT INTO tg_bind_codes (chat_id, code) VALUES (?, ?)", [(string)$chat_id, $code]);
    
    $reply = "👋 欢迎使用 UAPI 通知服务\n\n";
    $reply .= "您的 Telegram ID: `$chat_id`\n";
    $reply .= "验证码: `$code`\n\n";
    $reply .= "请复制 ID 和 验证码 到商户后台进行绑定。";
    
    // Send Reply
    $data = [
        'chat_id' => $chat_id,
        'text' => $reply,
        'parse_mode' => 'Markdown'
    ];
    
    $ch = curl_init("https://api.telegram.org/bot$token/sendMessage");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

echo json_encode(['ok' => true]);
