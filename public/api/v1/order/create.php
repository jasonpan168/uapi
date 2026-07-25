<?php
// public/api/v1/order/create.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/SecurityService.php';
require_once __DIR__ . '/../../../../src/Services/FeeAddressAllocator.php';

// 1. 鉴权
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (empty($api_key)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing API Key']);
    exit;
}

$db = Database::getInstance();
$sec = new SecurityService($db);
$ip = $_SERVER['REMOTE_ADDR'];

// Check Blocked IP
if ($reason = $sec->checkBlocked($ip)) {
    http_response_code(403);
    echo json_encode(['error' => 'IP Blocked: ' . $reason]);
    exit;
}

$user = $db->fetch("SELECT * FROM users WHERE api_key = ?", [$api_key]);

if (!$user) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid API Key']);
    exit;
}

if ($user['status'] !== 'active') {
    http_response_code(403);
    echo json_encode(['error' => 'Account suspended']);
    exit;
}

// Check Expiration
if ($user['plan_id'] > 1 && $user['expire_at'] && strtotime($user['expire_at']) < time()) {
    http_response_code(403);
    echo json_encode(['error' => 'Plan expired. Please upgrade/renew.']);
    exit;
}

// 1.05 API IP Whitelist check
if (!$sec->checkApiIpWhitelist($user['id'], $ip)) {
    http_response_code(403);
    echo json_encode(['error' => 'IP not in whitelist. Please check your API security settings.']);
    exit;
}

// 1.1 Check Website Binding (Strict Enforcement)
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
$input = json_decode(file_get_contents('php://input'), true); // Decode early for domain param

// Extract domain from Origin/Referer
$request_domain = '';
if (!empty($origin)) {
    $parsed = parse_url($origin);
    $request_domain = $parsed['host'] ?? '';
}

// Allow manual domain override for server-to-server calls (e.g. curl)
if (empty($request_domain) && !empty($input['domain'])) {
    $request_domain = $input['domain'];
}

// Clean domain (remove www., ports)
$request_domain = preg_replace('/^www\./', '', $request_domain);
$request_domain = explode(':', $request_domain)[0];

// STRICT ENFORCEMENT: Domain must be present and bound
if (empty($request_domain)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied: Request must include Origin/Referer header or "domain" parameter.']);
    exit;
}

// Check if this domain is bound to the user
$bound = $db->fetch("SELECT id FROM websites WHERE user_id = ? AND (domain = ? OR domain = ?) AND status = 'active'", [
    $user['id'], $request_domain, 'www.'.$request_domain
]);

if (!$bound) {
    http_response_code(403);
    echo json_encode(['error' => "Access Denied: Domain '$request_domain' is not bound to this API Key. Please bind it in Merchant Dashboard."]);
    exit;
}

// Check if chain is allowed for this user's plan
$chain_slug = strtolower($input['chain'] ?? '');
if (!empty($chain_slug)) {
    $plan_chain = $db->fetch("SELECT c.id FROM chains c JOIN plan_chains pc ON c.id = pc.chain_id WHERE pc.plan_id = ? AND c.slug = ? AND c.status = 1", [$user['plan_id'], $chain_slug]);
    
    if (!$plan_chain) {
        http_response_code(403);
        echo json_encode(['error' => "Access Denied: Chain '$chain_slug' is not enabled for your current plan. Please upgrade."]);
        exit;
    }
}

// 2. 验证输入
if (empty($input['amount']) || empty($input['chain']) || empty($input['merchant_order_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}
if (trim((string)$input['merchant_order_id']) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid merchant_order_id']);
    exit;
}

// 2.1 Fast Sync Parameter
// Default behavior: if user has fast-sync quota, enable it automatically.
// API caller can explicitly disable by sending fast_sync=false.
$is_fast_sync = 0;
$fastSyncInputProvided = array_key_exists('fast_sync', (array)$input);
$wantFastSync = $fastSyncInputProvided ? (bool)$input['fast_sync'] : true;

$currency = strtoupper($input['currency'] ?? 'USDT'); // Default to USDT
$usdcEnabledRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'enable_usdc'");
$isPlatformUsdcEnabled = $usdcEnabledRow && (string)($usdcEnabledRow['value'] ?? '0') === '1';

// Validate Currency
if ($currency !== 'USDT' && $currency !== 'USDC') {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported currency. Only USDT and USDC are supported.']);
    exit;
}
if ($currency === 'USDC' && !$isPlatformUsdcEnabled) {
    http_response_code(400);
    echo json_encode(['error' => 'USDC payment is not enabled on this platform.']);
    exit;
}
$platformCurrencies = ['USDT'];
if ($isPlatformUsdcEnabled) {
    $platformCurrencies[] = 'USDC';
}
$merchantCurrencyKey = 'merchant_enabled_currencies_u' . (int)$user['id'];
$merchantCurrencyRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$merchantCurrencyKey]);
$merchantCurrenciesRaw = strtoupper(trim((string)($merchantCurrencyRow['value'] ?? '')));
$merchantCurrencies = [];
if ($merchantCurrenciesRaw !== '') {
    foreach (explode(',', $merchantCurrenciesRaw) as $cc) {
        $cc = strtoupper(trim($cc));
        if ($cc !== '') $merchantCurrencies[] = $cc;
    }
}
if (empty($merchantCurrencies)) {
    $merchantCurrencies = $platformCurrencies;
}
$merchantCurrencies = array_values(array_unique(array_values(array_intersect($merchantCurrencies, $platformCurrencies))));
if (!in_array($currency, $merchantCurrencies, true)) {
    http_response_code(400);
    echo json_encode(['error' => "Currency '$currency' is not enabled for this merchant."]);
    exit;
}
if ($currency === 'USDC' && strtolower((string)$input['chain'] ?? '') === 'trc20') {
    http_response_code(400);
    echo json_encode(['error' => "Currency '$currency' is not supported on chain 'trc20'."]);
    exit;
}

$notify_url = $input['notify_url'] ?? '';
$planNotify = $db->fetch("SELECT allow_webhook_notice FROM plans WHERE id = ?", [$user['plan_id']]);
$allowWebhookNotice = (int)($planNotify['allow_webhook_notice'] ?? 1) === 1;
if ($allowWebhookNotice && empty($notify_url) && !empty($user['webhook_url'])) {
    $notify_url = $user['webhook_url'];
}
if (!$allowWebhookNotice) {
    $notify_url = '';
}
// Validate URL if present
if (!empty($notify_url) && !filter_var($notify_url, FILTER_VALIDATE_URL)) {
    echo json_encode(['error' => 'Invalid notify_url']);
    exit;
}

// 3. 获取商户收款模式
$chain = strtolower($input['chain']); // trc20, bsc
$receiveModeKey = 'merchant_receive_mode_u' . (int)$user['id'];
$receiveModeRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
$receiveMode = strtolower(trim((string)($receiveModeRow['value'] ?? 'wallet')));
if (!in_array($receiveMode, ['wallet', 'derived'], true)) {
    $receiveMode = 'wallet';
}
$chainMeta = $db->fetch("SELECT is_evm, COALESCE(allow_derived, 1) AS allow_derived FROM chains WHERE slug = ? AND status = 1 LIMIT 1", [$chain]);
$canDerivedOnChain = $chainMeta && (int)($chainMeta['is_evm'] ?? 0) === 1 && (int)($chainMeta['allow_derived'] ?? 1) === 1;

// Log API Call
$endpoint = '/v1/order/create';
$method = $_SERVER['REQUEST_METHOD'];
$sec->logRequest($user['id'], $endpoint, $method, $chain, $ip);

// 4. 创建订单
$order_no = 'PAY' . date('YmdHis') . bin2hex(random_bytes(4));
$merchant_order_id = trim((string)$input['merchant_order_id']);
$merchant_order_unique = $user['id'] . ':' . $merchant_order_id;
// Add random decimal to avoid collision
// User request: non-zero, unique, varied last digit, not too many decimals, last digit cannot be 0
$base_amount = (float)$input['amount'];
if ($base_amount <= 0 || $base_amount > 1000000) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid amount. Must be greater than 0 and no more than 1,000,000.']);
    exit;
}
$final_amount = $base_amount;
if (!($receiveMode === 'derived' && $canDerivedOnChain)) {
    // Generate a random integer between 1000 and 9999
    // Divide by 1,000,000 to get 0.001000 to 0.009999 (4 decimal places used at the end of 6)
    $rand_int = rand(1000, 9999);

    // Ensure last digit is NOT 0 (User requirement: "结尾最后一位一定是数字(1-9)")
    if ($rand_int % 10 == 0) {
        $rand_int += rand(1, 9); // Add 1-9 to make last digit non-zero
    }

    $random_part = $rand_int / 1000000;
    $final_amount = $base_amount + $random_part;
}

// Format to 6 decimals
$amount = number_format($final_amount, 6, '.', '');
$pay_access_token = bin2hex(random_bytes(16));
// TTL for order expiration (seconds)
$ttl_seconds = 600; // 10 minutes

// Idempotency by merchant_order_id (per user)
$existing = $db->fetch(
    "SELECT id, order_no, amount, currency, chain, status, expire_at, pay_access_token
     FROM orders
     WHERE merchant_order_unique = ?
     LIMIT 1",
    [$merchant_order_unique]
);
if ($existing) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $existingToken = (string)($existing['pay_access_token'] ?? '');
    $checkout_url = "$protocol://$host/pay.php?order={$existing['order_no']}";
    if ($existingToken !== '') {
        $checkout_url .= "&token={$existingToken}";
    }

    echo json_encode([
        'status' => 'success',
        'data' => [
            'order_no' => $existing['order_no'],
            'amount' => $existing['amount'],
            'currency' => $existing['currency'] ?: 'USDT',
            'chain' => $existing['chain'],
            'expire_in' => (isset($existing['expire_at']) && $existing['expire_at']) ? max(0, strtotime($existing['expire_at']) - time()) : $ttl_seconds,
            'payment_url' => $checkout_url,
            'idempotent' => true,
            'order_status' => $existing['status']
        ]
    ]);
    exit;
}

$wallet = null;
if ($receiveMode === 'derived' && $canDerivedOnChain) {
    $allocCfg = FeeAddressAllocator::loadSettings($db);
    $allocCfg['admin_fee_address_mode'] = 'derived';
    try {
        $alloc = FeeAddressAllocator::resolveChargeWallet($db, $order_no, 'merchant_order', (int)$user['id'], $chain, $allocCfg);
        if (!$alloc || empty($alloc['wallet_id']) || empty($alloc['chain']) || strtolower((string)$alloc['chain']) !== $chain) {
            echo json_encode(['error' => 'Address allocation failed, please try again.']);
            exit;
        }
        $wallet = ['id' => (int)$alloc['wallet_id'], 'address' => (string)($alloc['address'] ?? '')];
    } catch (Exception $e) {
        error_log('[order/create] address allocation error: ' . $e->getMessage());
        echo json_encode(['error' => 'Address allocation failed, please try again.']);
        exit;
    }
}
if ($receiveMode === 'derived' && !$canDerivedOnChain) {
    echo json_encode(['error' => "Current chain does not support derived mode: $chain"]);
    exit;
}
if (!$wallet) {
    $wallet = $db->fetch("SELECT id, address FROM wallets WHERE user_id = ? AND chain = ? AND status = 1 LIMIT 1", [
        $user['id'], $chain
    ]);
}
if (!$wallet) {
    if ($receiveMode === 'derived') {
        echo json_encode(['error' => "Derived address allocation failed on chain: $chain"]);
        exit;
    }
    echo json_encode(['error' => "No active wallet found for chain: $chain"]);
    exit;
}

try {
    $db->query("INSERT INTO orders (order_no, merchant_order_id, merchant_order_unique, pay_access_token, user_id, wallet_id, amount, currency, chain, notify_url, source, created_at, expire_at, is_fast_sync) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'api', NOW(), DATE_ADD(NOW(), INTERVAL 600 SECOND), ?)", [
        $order_no,
        $merchant_order_id,
        $merchant_order_unique,
        $pay_access_token,
        $user['id'],
        $wallet['id'],
        $amount,
        $currency,
        $chain,
        $notify_url,
        $is_fast_sync
    ]);

    if ($wantFastSync) {
        $dec = $db->query("UPDATE users SET fast_sync_remaining = fast_sync_remaining - 1 WHERE id = ? AND fast_sync_remaining > 0", [$user['id']]);
        if ($dec->rowCount() > 0) {
            $is_fast_sync = 1;
            $db->query("UPDATE orders SET is_fast_sync = 1 WHERE order_no = ?", [$order_no]);
        }
    }
    
    // Generate Checkout URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $checkout_url = "$protocol://$host/pay.php?order=$order_no&token=$pay_access_token";
    
    echo json_encode([
        'status' => 'success',
        'data' => [
            'order_no' => $order_no,
            'amount' => $amount,
            'currency' => $currency,
            'chain' => $chain,
            'expire_in' => $ttl_seconds,
            'payment_url' => $checkout_url,
            'fast_sync_enabled' => (bool)$is_fast_sync
        ]
    ]);
} catch (Exception $e) {
    $raceExisting = $db->fetch(
        "SELECT id, order_no, amount, currency, chain, status, expire_at, pay_access_token
         FROM orders
         WHERE merchant_order_unique = ?
         LIMIT 1",
        [$merchant_order_unique]
    );
    if ($raceExisting) {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $existingToken = (string)($raceExisting['pay_access_token'] ?? '');
        $checkout_url = "$protocol://$host/pay.php?order={$raceExisting['order_no']}";
        if ($existingToken !== '') {
            $checkout_url .= "&token={$existingToken}";
        }
        echo json_encode([
            'status' => 'success',
            'data' => [
                'order_no' => $raceExisting['order_no'],
                'amount' => $raceExisting['amount'],
                'currency' => $raceExisting['currency'] ?: 'USDT',
                'chain' => $raceExisting['chain'],
                'expire_in' => (isset($raceExisting['expire_at']) && $raceExisting['expire_at']) ? max(0, strtotime($raceExisting['expire_at']) - time()) : $ttl_seconds,
                'payment_url' => $checkout_url,
                'idempotent' => true,
                'order_status' => $raceExisting['status']
            ]
        ]);
        exit;
    }
    echo json_encode(['error' => 'Database error']);
}
