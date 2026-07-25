<?php
// public/api/v1/store/create_order.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/FeeAddressAllocator.php';

$input = json_decode(file_get_contents('php://input'), true);
$customerEmailRaw = isset($input['customer_email']) ? (string)$input['customer_email'] : (string)($input['email'] ?? '');
if (empty($input['store_id']) || empty($input['product_id']) || trim($customerEmailRaw) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$db = Database::getInstance();
$db->autoMigrate();

require_once __DIR__ . '/../../../../src/Services/SecurityService.php';
$sec = new SecurityService($db);
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($reason = $sec->checkBlocked($ip)) {
    http_response_code(403);
    echo json_encode(['error' => 'IP Blocked: ' . $reason]);
    exit;
}
if (!$sec->checkRateLimit($ip, 'store_create_order', 20, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests, please try again later']);
    exit;
}

$store_id = (int)$input['store_id'];
$product_id = (int)$input['product_id'];
$chain = strtolower(trim((string)($input['chain'] ?? '')));
$currency = strtoupper(trim((string)($input['currency'] ?? 'USDT')));
$customer_email = trim($customerEmailRaw);

if (!filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid email']);
    exit;
}

// 1. Fetch Store & Product
$store = $db->fetch("SELECT * FROM stores WHERE id = ? AND status = 'active'", [$store_id]);
$product = $db->fetch("SELECT * FROM store_products WHERE id = ? AND store_id = ? AND status = 'active'", [$product_id, $store_id]);

if (!$store || !$product) {
    http_response_code(404);
    echo json_encode(['error' => 'Store or Product not found']);
    exit;
}

// 2. Fetch Merchant Wallet
$user_id = $store['user_id'];
$wallet = null;

// 2.1 Validate currency against platform + merchant settings
$settingsRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$settingsMap = [];
foreach ($settingsRows as $sr) {
    $settingsMap[(string)$sr['key_name']] = (string)$sr['value'];
}
$platformCurrencies = [];
if (($settingsMap['enable_payment_usdt'] ?? '1') === '1') $platformCurrencies[] = 'USDT';
if (($settingsMap['enable_usdc'] ?? '0') === '1') $platformCurrencies[] = 'USDC';
if (empty($platformCurrencies)) $platformCurrencies[] = 'USDT';
$effectiveCurrencies = $platformCurrencies;
if (!in_array($currency, $effectiveCurrencies, true)) {
    http_response_code(400);
    echo json_encode(['error' => "Currency '$currency' is not enabled for this merchant"]);
    exit;
}
if ($currency === 'USDC' && $chain === 'trc20') {
    http_response_code(400);
    echo json_encode(['error' => "Currency '$currency' is not supported on chain '$chain'"]);
    exit;
}

// 2.5 Calculate Amount & Apply Coupon
$base_amount = (float)$product['price'];
$coupon_code = null;
$discount_amount = 0;

if (!empty($input['coupon_code'])) {
    $code = $input['coupon_code'];
    $coupon = $db->fetch("SELECT * FROM store_coupons WHERE store_id = ? AND code = ? AND status = 'active'", [$store_id, $code]);
    
    // Validate coupon
    if ($coupon) {
        $is_valid = true;
        if ($coupon['expiry_date'] && strtotime($coupon['expiry_date']) < time()) $is_valid = false;
        if ($coupon['usage_limit'] != -1 && $coupon['used_count'] >= $coupon['usage_limit']) $is_valid = false;
        
        if ($is_valid) {
            $coupon_code = $code;
            if ($coupon['type'] == 'fixed') {
                $discount_amount = min($base_amount, (float)$coupon['value']);
            } else {
                $discount_amount = $base_amount * ((float)$coupon['value'] / 100);
            }
            $base_amount = max(0, $base_amount - $discount_amount);
        }
    }
}

// 2.6 Handle Shipping
$shipping_info = null;
$logistics_status = 'pending';
if (!empty($input['shipping_info'])) {
    $shipping_info = json_encode($input['shipping_info'], JSON_UNESCAPED_UNICODE);
}

// 3. Create Order
$order_no = 'PAY' . date('YmdHis') . rand(1000, 9999);
$merchant_order_id = 'STORE-' . $store_id . '-' . $product_id . '-' . time();
$pay_access_token = bin2hex(random_bytes(16));
$receiveModeKey = 'merchant_receive_mode_u' . (int)$user_id;
$receiveModeRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
$receiveMode = strtolower(trim((string)($receiveModeRow['value'] ?? 'wallet')));
if (!in_array($receiveMode, ['wallet', 'derived'], true)) {
    $receiveMode = 'wallet';
}
// Add random decimal for fixed-address mode only.
$final_amount = $base_amount;
if ($receiveMode !== 'derived') {
    $rand_int = rand(1000, 9999);
    if ($rand_int % 10 == 0) $rand_int += rand(1, 9);
    $random_part = $rand_int / 1000000;
    $final_amount = $base_amount + $random_part;
}
$amount = number_format($final_amount, 6, '.', '');
if ($receiveMode === 'derived') {
    // Derived mode always defaults to merchant's current derived chain selection.
    $preferredChainKey = 'sweep_last_chain_u' . (int)$user_id;
    $preferredChainRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$preferredChainKey]);
    $preferredDerivedChain = strtolower(trim((string)($preferredChainRow['value'] ?? '')));

    $merchant = $db->fetch("SELECT plan_id FROM users WHERE id = ? LIMIT 1", [(int)$user_id]);
    $planId = (int)($merchant['plan_id'] ?? 0);
    $derivedChainRows = $db->fetchAll(
        "SELECT c.slug
         FROM chains c
         INNER JOIN plan_chains pc ON pc.chain_id = c.id AND pc.plan_id = ?
         LEFT JOIN plan_chain_derived pcd ON pcd.plan_id = pc.plan_id AND pcd.chain_id = pc.chain_id
         WHERE c.status = 1
           AND c.is_evm = 1
           AND COALESCE(c.allow_derived, 1) = 1
           AND COALESCE(pcd.enabled, 1) = 1
         ORDER BY c.name ASC",
        [$planId]
    );
    $derivedAllowed = [];
    foreach ($derivedChainRows as $row) {
        $slug = strtolower(trim((string)($row['slug'] ?? '')));
        if ($slug !== '') $derivedAllowed[] = $slug;
    }
    if (!empty($derivedAllowed)) {
        if ($preferredDerivedChain !== '' && in_array($preferredDerivedChain, $derivedAllowed, true)) {
            $chain = $preferredDerivedChain;
        } elseif ($chain !== '' && in_array($chain, $derivedAllowed, true)) {
            // keep requested chain if it's a valid derived chain
        } else {
            $chain = (string)$derivedAllowed[0];
        }
    }
    if ($chain === '' || !in_array($chain, $derivedAllowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'No derived receiving chain available for current merchant plan']);
        exit;
    }

    $allocCfg = FeeAddressAllocator::loadSettings($db);
    $allocCfg['admin_fee_address_mode'] = 'derived';
    try {
        $alloc = FeeAddressAllocator::resolveChargeWallet($db, $order_no, 'store_checkout', (int)$user_id, $chain, $allocCfg);
        if ($alloc && !empty($alloc['wallet_id']) && strtolower((string)($alloc['chain'] ?? '')) === $chain) {
            $wallet = ['id' => (int)$alloc['wallet_id']];
        }
    } catch (Exception $e) {
        http_response_code(400);
        error_log('[create_order] address allocation error: ' . $e->getMessage());
        echo json_encode(['error' => 'Address allocation failed, please try again.']);
        exit;
    }
    if (!$wallet) {
        http_response_code(400);
        echo json_encode(['error' => "Derived address allocation failed on chain: $chain"]);
        exit;
    }
}
if (!$wallet) {
    $walletDefaultChainKey = 'merchant_wallet_default_chain_u' . (int)$user_id;
    $walletDefaultChainRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$walletDefaultChainKey]);
    $walletDefaultChain = strtolower(trim((string)($walletDefaultChainRow['value'] ?? '')));

    $activeWallets = $db->fetchAll("SELECT id, chain FROM wallets WHERE user_id = ? AND status = 1 ORDER BY id DESC", [$user_id]);
    if (empty($activeWallets)) {
        http_response_code(400);
        echo json_encode(['error' => 'Merchant has no enabled receiving wallet']);
        exit;
    }

    $activeChains = [];
    foreach ($activeWallets as $w) {
        $slug = strtolower(trim((string)($w['chain'] ?? '')));
        if ($slug !== '') $activeChains[] = $slug;
    }

    if ($walletDefaultChain !== '' && in_array($walletDefaultChain, $activeChains, true)) {
        $chain = $walletDefaultChain;
    } elseif ($chain !== '' && in_array($chain, $activeChains, true)) {
        // keep requested chain only if it is enabled
    } else {
        $chain = (string)$activeChains[0];
    }

    if ($chain === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing chain']);
        exit;
    }
    $wallet = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND chain = ? AND status = 1 LIMIT 1", [$user_id, $chain]);
}
if (!$wallet) {
    http_response_code(400);
    echo json_encode(['error' => "Merchant does not accept $chain payments"]);
    exit;
}

try {
    $db->query("INSERT INTO orders (
        order_no, merchant_order_id, pay_access_token, user_id, wallet_id, amount, currency, chain, 
        source, source_id, product_id, coupon_code, discount_amount, shipping_info, logistics_status, customer_email, is_fast_sync,
        created_at, expire_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'store', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 600 SECOND))", [
        $order_no,
        $merchant_order_id,
        $pay_access_token,
        $user_id,
        $wallet['id'],
        $amount,
        $currency,
        $chain,
        $store_id,
        $product_id,
        $coupon_code,
        $discount_amount,
        $shipping_info,
        $shipping_info ? 'pending' : null,
        $customer_email,
        0
    ]);
    $dec = $db->query("UPDATE users SET fast_sync_remaining = fast_sync_remaining - 1 WHERE id = ? AND fast_sync_remaining > 0", [$user_id]);
    if ($dec->rowCount() > 0) {
        $db->query("UPDATE orders SET is_fast_sync = 1 WHERE order_no = ?", [$order_no]);
    }
    
    // Notification Logic (Simple stub, can be expanded to TG)
    // require_once __DIR__ . '/../../../../src/Service/NotificationService.php';
    // NotificationService::sendNewOrder($store_id, $order_no, $amount);
    
    echo json_encode([
        'status' => 'success',
        'pay_url' => "/pay.php?order=$order_no&token=$pay_access_token"
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'System error']);
}
