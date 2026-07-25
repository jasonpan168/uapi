<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/SecurityService.php';

$apiKey = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
if ($apiKey === '') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'error' => 'Missing API Key']);
    exit;
}

$db = Database::getInstance();
$sec = new SecurityService($db);
$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if ($reason = $sec->checkBlocked($ip)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'IP Blocked: ' . $reason]);
    exit;
}
if (!$sec->checkRateLimit($ip, 'order_dispute.php', 20, 60)) {
    http_response_code(429);
    echo json_encode(['status' => 'error', 'error' => 'Too Many Requests']);
    exit;
}

$user = $db->fetch("SELECT * FROM users WHERE api_key = ? LIMIT 1", [$apiKey]);
if (!$user || (string)($user['status'] ?? '') !== 'active') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Invalid API Key or account disabled']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
$requestDomain = '';
if ($origin !== '') {
    $parsed = parse_url($origin);
    $requestDomain = (string)($parsed['host'] ?? '');
}
if ($requestDomain === '' && !empty($input['domain'])) {
    $requestDomain = (string)$input['domain'];
}
$requestDomain = preg_replace('/^www\./', '', strtolower($requestDomain));
$requestDomain = explode(':', $requestDomain)[0];
if ($requestDomain === '') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => 'Access Denied: missing domain']);
    exit;
}
$bound = $db->fetch(
    "SELECT id FROM websites WHERE user_id = ? AND (domain = ? OR domain = ?) AND status = 'active' LIMIT 1",
    [(int)$user['id'], $requestDomain, 'www.' . $requestDomain]
);
if (!$bound) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'error' => "Access Denied: Domain '{$requestDomain}' is not bound"]);
    exit;
}

$merchantOrderId = trim((string)($input['merchant_order_id'] ?? ''));
$orderNo = trim((string)($input['order_no'] ?? ''));
if ($merchantOrderId === '' && $orderNo === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing merchant_order_id or order_no']);
    exit;
}

$order = null;
if ($merchantOrderId !== '') {
    $order = $db->fetch("SELECT * FROM orders WHERE merchant_order_id = ? AND user_id = ? LIMIT 1", [$merchantOrderId, (int)$user['id']]);
}
if (!$order && $orderNo !== '') {
    $order = $db->fetch("SELECT * FROM orders WHERE order_no = ? AND user_id = ? LIMIT 1", [$orderNo, (int)$user['id']]);
}
if (!$order) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'error' => 'Order not found']);
    exit;
}

$mode = strtolower(trim((string)($input['mode'] ?? 'original')));
$newAmount = (float)($input['new_amount'] ?? 0);
$newCurrency = strtoupper(trim((string)($input['new_currency'] ?? (string)($order['currency'] ?? 'USDT'))));
$note = trim((string)($input['note'] ?? ''));

if (!in_array($newCurrency, ['USDT', 'USDC'], true)) {
    $newCurrency = 'USDT';
}
if ($mode !== 'adjusted') {
    $mode = 'original';
    $newAmount = (float)($order['amount'] ?? 0);
    $newCurrency = strtoupper((string)($order['currency'] ?? 'USDT'));
}
$originalAmount = (float)($order['amount'] ?? 0);
if ($newAmount <= 0) {
    $newAmount = $originalAmount;
}
if ($newAmount > $originalAmount) {
    $newAmount = $originalAmount;
}

$db->query(
    "UPDATE orders SET status='disputed', amount=?, currency=?, updated_at=NOW() WHERE id=? AND user_id=?",
    [number_format($newAmount, 6, '.', ''), $newCurrency, (int)$order['id'], (int)$user['id']]
);

$notesCol = $db->fetch(
    "SELECT COUNT(*) AS c FROM information_schema.columns
     WHERE table_schema = DATABASE() AND table_name = 'orders' AND column_name = 'notes'"
);
if ((int)($notesCol['c'] ?? 0) > 0 && $note !== '') {
    $append = "[WP异议] mode={$mode}; amount=" . number_format($newAmount, 6, '.', '') . " {$newCurrency}; note={$note}; time=" . date('Y-m-d H:i:s') . "\n";
    $db->query("UPDATE orders SET notes = CONCAT(IFNULL(notes,''), ?) WHERE id = ?", [$append, (int)$order['id']]);
}

echo json_encode([
    'status' => 'success',
    'data' => [
        'order_id' => (int)$order['id'],
        'order_no' => (string)$order['order_no'],
        'merchant_order_id' => (string)$order['merchant_order_id'],
        'order_status' => 'disputed',
        'amount' => number_format($newAmount, 6, '.', ''),
        'currency' => $newCurrency,
        'mode' => $mode,
    ]
]);

