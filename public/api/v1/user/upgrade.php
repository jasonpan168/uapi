<?php
// public/api/v1/user/upgrade.php
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Core/Migrator.php';
require_once __DIR__ . '/../../../../src/Services/FeeAddressAllocator.php';
require_once __DIR__ . '/../../../../src/Services/BinancePayService.php';
require_once __DIR__ . '/../../../../src/Services/User2FAService.php';

try {
    $db = Database::getInstance();

    require_once __DIR__ . '/../../../../src/Services/SecurityService.php';
    $sec = new SecurityService($db);
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($reason = $sec->checkBlocked($ip)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'IP Blocked: ' . $reason]);
        exit;
    }
    if (!$sec->checkRateLimit($ip, 'upgrade', 10, 60)) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'message' => 'Too many requests, please try again later']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    
    $plan_id = isset($input['plan_id']) ? (int)$input['plan_id'] : 0;
    $method = isset($input['payment_method']) ? $input['payment_method'] : 'usdt';
    $cycle = isset($input['cycle']) ? $input['cycle'] : 'monthly';
    $supportedMethods = ['usdt', 'stripe', 'balance', 'binance_pay'];
    if (!in_array($method, $supportedMethods, true)) {
        throw new Exception("Unsupported payment method");
    }
    
    if ($plan_id <= 0) {
        throw new Exception("Invalid Plan ID");
    }
    
    // 1. Get Plan Details
    $plan = $db->fetch("SELECT * FROM plans WHERE id = ?", [$plan_id]);
    if (!$plan) {
        throw new Exception("Plan not found");
    }
    
    // Determine price based on cycle
    $price = $cycle === 'yearly' ? $plan['price_yearly'] : $plan['price_monthly'];

    if ($price <= 0) {
        throw new Exception("Cannot upgrade to free plan or invalid price");
    }
    
    // 2. Get User & Wallet
    $user_id = $_SESSION['user_id'];
    $userBefore = $db->fetch("SELECT plan_id, expire_at, two_factor_enabled, two_factor_secret, two_factor_scenes FROM users WHERE id = ? LIMIT 1", [$user_id]);
    $otpCodeInput = trim((string)($input['otp_code'] ?? ''));
    
    $cfg = FeeAddressAllocator::loadSettings($db);
    $coupon_code = strtoupper(trim((string)($input['coupon_code'] ?? '')));
    $discount_amount = 0.0;
    $payable_price = (float)$price;

    if ($coupon_code !== '') {
        $coupon = $db->fetch("SELECT * FROM admin_coupons WHERE code = ? AND status = 'active' LIMIT 1", [$coupon_code]);
        if (!$coupon) {
            throw new Exception("优惠码不存在");
        }
        if (!empty($coupon['expiry_date']) && strtotime((string)$coupon['expiry_date']) < time()) {
            throw new Exception("优惠码已过期");
        }
        if ((int)$coupon['usage_limit'] !== -1 && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) {
            throw new Exception("优惠码已失效");
        }

        if ((string)$coupon['type'] === 'fixed') {
            $discount_amount = min((float)$price, (float)$coupon['value']);
        } else {
            $discount_amount = (float)$price * ((float)$coupon['value'] / 100);
        }
        $discount_amount = round(max(0.0, $discount_amount), 2);
        $payable_price = round(max(0.0, (float)$price - $discount_amount), 2);
    }

    // 3. Create Order
    $order_no = 'UPG' . date('YmdHis') . rand(1000, 9999);
    $merchant_order_id = 'PLAN-' . $plan_id . '-' . $cycle . '-' . time();
    $pay_access_token = bin2hex(random_bytes(16));
    $pay_provider = match ($method) {
        'stripe' => 'stripe',
        'binance_pay' => 'binance',
        'balance' => 'balance',
        default => 'crypto'
    };
    
    // Amount rules:
    // fixed mode: keep micro random for collision resistance
    // derived mode: one-order-one-address, no micro random needed
    if ($payable_price <= 0) {
        $amount = 0;
    } elseif ($method === 'usdt') {
        $base_amount = (float)$payable_price;
        $rand_int = rand(1000, 9999);
        if ($rand_int % 10 == 0) $rand_int += rand(1, 9);
        $random_part = $rand_int / 1000000;
        $amount = number_format($base_amount + $random_part, 6, '.', '');
    } else {
        $amount = $payable_price;
    }
    
    // For Stripe or Balance, we don't need a crypto wallet, but for USDT we do
    $wallet_id = null;
    $chain = 'trc20';
    $currency = isset($input['currency']) ? strtoupper($input['currency']) : 'USDT';
    $cryptoCurrencies = ['USDT', 'USDC'];
    if ($method === 'usdt' && !in_array($currency, $cryptoCurrencies, true)) {
        throw new Exception("Unsupported crypto currency");
    }
    if ($method === 'usdt' && $currency === 'USDC' && (($cfg['enable_usdc'] ?? '0') !== '1')) {
        throw new Exception("USDC is not enabled");
    }
    
    if ($method === 'usdt' && (float)$payable_price > 0) { // Legacy 'usdt' now means 'crypto'
        // Upgrade orders must use admin fixed receiving wallet only.
        // Do not depend on merchant derived settings / derived-chain switches.
        $allocCfg = $cfg;
        $allocCfg['admin_fee_address_mode'] = 'fixed';
        $alloc = FeeAddressAllocator::resolveChargeWallet(
            $db,
            $order_no,
            'plan_upgrade',
            $user_id,
            $allocCfg['payment_collection_chain'] ?? null,
            $allocCfg
        );
        $chain = $alloc['chain'];
        $wallet_id = $alloc['wallet_id'];
        if ($currency === 'USDC' && strtolower((string)$chain) === 'trc20') {
            throw new Exception("USDC is not supported on TRC20");
        }
    } elseif ($method === 'stripe') {
        $chain = 'stripe';
        $currency = 'USD';
    } elseif ($method === 'binance_pay') {
        $chain = 'binance_pay';
        $currency = isset($input['currency']) ? strtoupper((string)$input['currency']) : 'USDT';
        $amount = round((float)$payable_price, 2);
        if ($amount <= 0) {
            throw new Exception("Invalid Binance Pay amount");
        }
    }
    
    // Insert Order
    $db->query("INSERT INTO orders (order_no, merchant_order_id, pay_access_token, user_id, wallet_id, amount, currency, chain, pay_provider, order_origin, status, source, coupon_code, discount_amount, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'merchant_order', 'pending', 'upgrade', ?, ?, NOW())", [
        $order_no,
        $merchant_order_id,
        $pay_access_token,
        $user_id,
        $wallet_id, // Can be null for Stripe
        $amount,
        $currency, // Dynamic currency
        $chain, // 'trc20', 'evm', or 'stripe'
        $pay_provider,
        $coupon_code !== '' ? $coupon_code : null,
        number_format($discount_amount, 2, '.', '')
    ]);
    try {
        $fastGrantSnapshot = max(0, (int)($plan['fast_sync_limit'] ?? 0));
        $db->query(
            "UPDATE orders SET upgrade_prev_plan_id = ?, upgrade_prev_expire_at = ?, upgrade_fast_sync_grant = ? WHERE order_no = ? LIMIT 1",
            [
                (int)($userBefore['plan_id'] ?? 0),
                !empty($userBefore['expire_at']) ? (string)$userBefore['expire_at'] : null,
                $fastGrantSnapshot,
                $order_no
            ]
        );
    } catch (Throwable $e) {
        // non-blocking snapshot
    }

    require_once __DIR__ . '/../../../../src/Services/UpgradeOrderService.php';
    
    // 4. Return Redirect URL
    $redirect_url = '';
    $binanceCheckoutUrl = '';
    $binanceDeepLink = '';
    $binanceUniversalUrl = '';
    
    if ((float)$payable_price <= 0.0) {
        // Fully covered by coupon, no external payment required.
        $db->query("UPDATE orders SET status='paid', pay_provider='coupon', chain='coupon', currency='USD', paid_at=NOW(), updated_at=NOW() WHERE order_no=?", [$order_no]);
        if ($coupon_code !== '') {
            $db->query("UPDATE admin_coupons SET used_count = used_count + 1 WHERE code = ? AND status = 'active'", [$coupon_code]);
        }
        $order = $db->fetch("SELECT * FROM orders WHERE order_no = ?", [$order_no]);
        UpgradeOrderService::fulfillPlanDirect($db, $user_id, $plan_id, $cycle);
        $redirect_url = "/upgrade.php?payment_success=1&order=" . urlencode($order_no);
    } elseif ($method === 'balance') {
        // Balance Payment — atomic with row lock to prevent double-spend
        $db->query("START TRANSACTION");
        try {
            $user = $db->fetch("SELECT balance, two_factor_enabled, two_factor_secret, two_factor_scenes FROM users WHERE id = ? FOR UPDATE", [$user_id]);
            [$otpOk, $otpMsg] = User2FAService::verifyForScene((array)$user, 'balance_pay', $otpCodeInput);
            if (!$otpOk) {
                throw new Exception($otpMsg);
            }
            $balance = $user['balance'] ?? 0;

            if ($balance < $amount) {
                throw new Exception('余额不足');
            }

            $db->query("UPDATE users SET balance = balance - ? WHERE id = ?", [$amount, $user_id]);
            $db->query("UPDATE orders SET status='paid', pay_provider='balance', paid_at=NOW(), updated_at=NOW(), chain='balance' WHERE order_no=?", [$order_no]);
            if ($coupon_code !== '') {
                $db->query("UPDATE admin_coupons SET used_count = used_count + 1 WHERE code = ? AND status = 'active'", [$coupon_code]);
            }
            $db->query("COMMIT");
        } catch (Throwable $e) {
            $db->query("ROLLBACK");
            throw $e;
        }

        $order = $db->fetch("SELECT * FROM orders WHERE order_no = ?", [$order_no]);
        UpgradeOrderService::fulfillPlanDirect($db, $user_id, $plan_id, $cycle);

        require_once __DIR__ . '/../../../../src/Services/WebhookService.php';
        WebhookService::send($order);

        $redirect_url = "/upgrade.php?payment_success=1&order=" . urlencode($order_no);
        
    } elseif ($method === 'stripe') {
        // Use Stripe Checkout API
        require_once __DIR__ . '/../../../../src/Services/StripeService.php';
        $stripe_secret = $cfg['stripe_secret_key'] ?? '';
        if (empty($stripe_secret)) {
            error_log('[upgrade] Stripe secret key not configured');
            throw new Exception("Payment processing failed, please try again.");
        }
        
        $stripe = new StripeService($stripe_secret);
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'];
        $base_url = "$protocol://$host";
        
        // Success/Cancel URLs
        $success_url = $base_url . "/stripe_return.php?order=" . urlencode($order_no) . "&session_id={CHECKOUT_SESSION_ID}";
        $cancel_url = $base_url . "/upgrade.php?payment_cancel=1&order=" . $order_no;
        
        $product_name = $plan['name'] . ' Plan - ' . ($cfg['site_name'] ?? 'UAPI');
        $stripe_locale = isset($_SESSION['lang']) && strtolower((string)$_SESSION['lang']) === 'en' ? 'en' : 'zh';
        
        try {
            // Using StripeService which uses raw CURL, avoiding composer dependency issues
            if (class_exists('StripeService')) {
                // Static call as defined in StripeService.php
                // Parameters: $amount, $currency, $productName, $orderNo, $successUrl, $cancelUrl
                // Note: The method signature in StripeService.php is:
                // createCheckoutSession($amount, $currency, $productName, $orderNo, $successUrl, $cancelUrl)
                // But wait, look at line 10 in StripeService.php:
                // public static function createCheckoutSession($amount, $currency, $productName, $orderNo, $successUrl, $cancelUrl)
                // The order of params is: amount, currency, productName, orderNo, successUrl, cancelUrl
                
                $session = StripeService::createCheckoutSession(
                    $amount,
                    'usd',
                    $product_name,
                    $order_no,
                    $success_url,
                    $cancel_url,
                    $stripe_locale
                );
                if (!empty($session['id'])) {
                    // Store checkout session id for later reconciliation/audit.
                    $db->query("UPDATE orders SET tx_hash = ? WHERE order_no = ? AND status = 'pending'", [(string)$session['id'], $order_no]);
                }
                
                $redirect_url = $session['url'];
            } else {
                 throw new Exception("StripeService class missing");
            }
        } catch (Exception $e) {
            error_log('[upgrade] payment provider error: ' . $e->getMessage());
            throw new Exception("Payment processing failed, please try again.");
        }
    } elseif ($method === 'binance_pay') {
        $bCfg = BinancePayService::loadConfig($db);
        if (empty($bCfg['enabled'])) {
            throw new Exception("Binance Pay is not enabled");
        }

        $protocol = 'http';
        if (
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        ) {
            $protocol = 'https';
        }
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host;

        $returnUrl = $baseUrl . '/binance_return.php?order=' . urlencode($order_no);
        // Binance Pay only allows one query parameter in redirect URL.
        $cancelUrl = $baseUrl . '/upgrade.php?order=' . urlencode($order_no);
        $webhookUrl = $baseUrl . '/api/v1/binance/webhook.php';

        $payload = [
            'merchantTradeNo' => $order_no,
            'orderAmount' => round((float)$amount, 2),
            'currency' => 'USDT',
            'productType' => '01',
            'productName' => (string)($plan['name'] . ' Plan'),
            'description' => (string)($plan['name'] . ' plan upgrade'),
            'productDetail' => 'Plan upgrade',
            'env' => ['terminalType' => 'WEB'],
            'goodsDetails' => [[
                'goodsType' => '01',
                'goodsCategory' => 'D000',
                'referenceGoodsId' => 'plan-' . (int)$plan_id,
                'goodsName' => (string)$plan['name'],
                'goodsDetail' => 'Subscription plan upgrade',
            ]],
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'webhookUrl' => $webhookUrl,
            'passThroughInfo' => 'upgrade:' . $plan_id . ':' . $cycle,
        ];

        $binanceResp = BinancePayService::createOrder($bCfg, $payload);
        $respBody = $binanceResp['data'] ?? [];
        if (!BinancePayService::isSuccess($binanceResp)) {
            $msg = (string)($respBody['errorMessage'] ?? $respBody['msg'] ?? 'Binance Pay create order failed');
            $code = (string)($respBody['code'] ?? '');
            error_log('[upgrade] Binance Pay error: ' . ($code !== '' ? "[$code] " : '') . $msg);
            throw new Exception('Binance Pay order creation failed, please try again.');
        }

        $respData = $respBody['data'] ?? [];
        $prepayId = trim((string)($respData['prepayId'] ?? ''));
        if ($prepayId !== '') {
            $db->query("UPDATE orders SET tx_hash = ? WHERE order_no = ? AND status = 'pending'", [$prepayId, $order_no]);
        }

        $checkoutUrl = trim((string)($respData['checkoutUrl'] ?? ''));
        $deepLink = trim((string)($respData['deeplink'] ?? ''));
        $universalUrl = trim((string)($respData['universalUrl'] ?? $respData['universalLink'] ?? ''));
        if ($checkoutUrl === '' && $universalUrl !== '') {
            $checkoutUrl = $universalUrl;
        }
        if ($checkoutUrl === '' && $deepLink !== '') {
            $checkoutUrl = $deepLink;
        }
        if ($checkoutUrl === '') {
            throw new Exception('Binance Pay checkout URL missing');
        }
        $binanceCheckoutUrl = $checkoutUrl;
        $binanceDeepLink = $deepLink;
        $binanceUniversalUrl = $universalUrl;
        $redirect_url = $checkoutUrl;
    } elseif ($method === 'usdt') {
        // For USDT, redirect to standard checkout
        $redirect_url = "/pay.php?order=" . $order_no . "&token=" . $pay_access_token;
    }
    
    echo json_encode([
        'status' => 'success',
        'redirect_url' => $redirect_url,
        'order_no' => $order_no,
        'binance_checkout_url' => $binanceCheckoutUrl,
        'binance_deeplink' => $binanceDeepLink,
        'binance_universal_url' => $binanceUniversalUrl
    ]);

} catch (Exception $e) {
    error_log('[upgrade] ' . $e->getMessage());
    http_response_code(400);
    $safeMsg = $e->getMessage();
    if (preg_match('/(secret|key|token|password|config|class missing|internal)/i', $safeMsg)) {
        $safeMsg = 'Payment processing failed, please try again.';
    }
    echo json_encode(['status' => 'error', 'message' => $safeMsg]);
}
