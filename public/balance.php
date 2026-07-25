<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/FeeAddressAllocator.php';
require_once __DIR__ . '/../src/Services/BinancePayService.php';
require_once __DIR__ . '/../src/Services/User2FAService.php';
require_once __DIR__ . '/../src/Helper.php';
I18n::init();

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
$balanceOtpRequired = User2FAService::isSceneEnabled((array)$user, 'balance_pay');
$settingsRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$sysCfg = [];
foreach ($settingsRows as $sr) {
    $sysCfg[(string)$sr['key_name']] = (string)$sr['value'];
}
$siteName = trim((string)($sysCfg['site_name'] ?? 'UAPI'));
$planUsage = $db->fetch(
    "SELECT p.tg_notice_limit, p.email_notice_limit, p.allow_tg_bot, p.allow_email_notice
     FROM users u
     LEFT JOIN plans p ON p.id = u.plan_id
     WHERE u.id = ?
     LIMIT 1",
    [$user_id]
);

// Get Settings
$bonus_rules = [];
$s = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'recharge_bonus_rules'");
if ($s) {
    $bonus_rules = json_decode($s['value'], true);
}

if (isset($_SESSION['balance_flash']) && is_array($_SESSION['balance_flash'])) {
    $flash = $_SESSION['balance_flash'];
    unset($_SESSION['balance_flash']);
    if (($flash['type'] ?? '') === 'success') {
        $success = (string)($flash['message'] ?? '');
    } elseif (($flash['type'] ?? '') === 'error') {
        $error = (string)($flash['message'] ?? '');
    }
}
$redirectSelf = static function (): void {
    header("Location: balance.php", true, 303);
    exit;
};
$setFlash = static function (string $type, string $message): void {
    $_SESSION['balance_flash'] = ['type' => $type, 'message' => $message];
};
$respondJson = static function (bool $ok, string $message, array $extra = []): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($ok ? 200 : 400);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
};

// Ensure transfer table exists (self-healing for new deployments)
try {
    $db->query("CREATE TABLE IF NOT EXISTS user_balance_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        from_user_id INT NOT NULL,
        to_user_id INT NOT NULL,
        amount DECIMAL(20,6) NOT NULL,
        source_bucket VARCHAR(30) NOT NULL DEFAULT 'withdrawable',
        note VARCHAR(255) DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_from_user (from_user_id),
        INDEX idx_to_user (to_user_id),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // Keep page functional if table creation fails.
}

// Handle Recharge Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Helper::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        header("Location: balance.php?msg=csrf_invalid");
        exit;
    }
    if ($_POST['action'] === 'recharge') {
        $amount = floatval($_POST['amount']);
        $method = $_POST['payment_method'] ?? 'usdt';
        
        if ($amount <= 0) {
            $setFlash('error', __('merchant.balance.error.invalid_amount'));
            $redirectSelf();
        }
        
        // Generate Recharge Order
        $order_no = 'CHG' . date('YmdHis') . rand(1000, 9999);
        $merchant_order_id = 'RECHARGE-' . time();
        $pay_access_token = bin2hex(random_bytes(16));
        
        if ($method === 'usdt') {
            try {
                $cfg = FeeAddressAllocator::loadSettings($db);
                $rand_int = rand(1000, 9999);
                if ($rand_int % 10 == 0) $rand_int += rand(1, 9);
                $final_amount = $amount + ($rand_int / 1000000);
                // Recharge should use admin fixed receiving wallet and must not depend on merchant derived settings.
                $allocCfg = $cfg;
                $allocCfg['admin_fee_address_mode'] = 'fixed';
                $alloc = FeeAddressAllocator::resolveChargeWallet(
                    $db,
                    $order_no,
                    'recharge',
                    $user_id,
                    $allocCfg['payment_collection_chain'] ?? null,
                    $allocCfg
                );
                $db->query("INSERT INTO orders (order_no, merchant_order_id, pay_access_token, user_id, wallet_id, amount, currency, chain, status, created_at, source) VALUES (?, ?, ?, ?, ?, ?, 'USDT', ?, 'pending', NOW(), 'recharge')", [
                    $order_no, $merchant_order_id, $pay_access_token, $user_id, (int)$alloc['wallet_id'], number_format($final_amount, 6, '.', ''), (string)$alloc['chain']
                ]);
                header("Location: pay.php?order=$order_no&token=$pay_access_token");
                exit;
            } catch (Throwable $e) {
                $setFlash('error', __('merchant.balance.error.recharge_channel_unavailable'));
                $redirectSelf();
            }
        } elseif ($method === 'stripe') {
            // Stripe Payment
            require_once __DIR__ . '/../src/Services/StripeService.php';
            try {
                $baseUrl = (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
                $stripeLocale = I18n::getLang() === 'en' ? 'en' : 'zh';
                $productName = __('merchant.balance.stripe_product_name', [
                    'site' => $siteName,
                    'amount' => number_format($amount, 2)
                ]);
                $session = StripeService::createCheckoutSession(
                    $amount, 
                    'USD', 
                    $productName,
                    $order_no,
                    $baseUrl . "/stripe_return.php?order=" . urlencode($order_no) . "&session_id={CHECKOUT_SESSION_ID}",
                    $baseUrl . "/balance.php?payment_cancel=1",
                    $stripeLocale
                );
                
                // Create Order (No wallet needed for Stripe)
                $db->query("INSERT INTO orders (order_no, merchant_order_id, user_id, amount, currency, chain, status, created_at, source) VALUES (?, ?, ?, ?, 'USD', 'stripe', 'pending', NOW(), 'recharge')", [
                    $order_no, $merchant_order_id, $user_id, number_format($amount, 2, '.', '')
                ]);
                
                if (empty($session['url'])) {
                    throw new Exception('Stripe session url missing');
                }
                header("Location: " . $session['url']);
                exit;
            } catch (Exception $e) {
                $setFlash('error', __('merchant.balance.error.stripe') . ': ' . $e->getMessage());
                $redirectSelf();
            }
        } elseif ($method === 'binance_pay') {
            try {
                $bCfg = BinancePayService::loadConfig($db);
                if (empty($bCfg['enabled'])) {
                    throw new Exception('Binance Pay not enabled');
                }

                $amount = round((float)$amount, 2);
                if ($amount <= 0) {
                    throw new Exception('Invalid amount');
                }

                $db->query(
                    "INSERT INTO orders (order_no, merchant_order_id, user_id, amount, currency, chain, pay_provider, order_origin, status, source, created_at)
                     VALUES (?, ?, ?, ?, 'USDT', 'binance_pay', 'binance', 'merchant_order', 'pending', 'recharge', NOW())",
                    [$order_no, $merchant_order_id, $user_id, number_format($amount, 2, '.', '')]
                );

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

                $payload = [
                    'merchantTradeNo' => $order_no,
                    'orderAmount' => $amount,
                    'currency' => 'USDT',
                    'productType' => '01',
                    'productName' => 'Balance Recharge',
                    'description' => 'Balance recharge',
                    'env' => ['terminalType' => 'WEB'],
                    'goodsDetails' => [[
                        'goodsType' => '01',
                        'goodsCategory' => 'D000',
                        'referenceGoodsId' => 'recharge-' . $user_id,
                        'goodsName' => 'Balance Recharge',
                        'goodsDetail' => 'Merchant balance recharge',
                    ]],
                    'returnUrl' => $baseUrl . '/binance_return.php?order=' . urlencode($order_no),
                    'cancelUrl' => $baseUrl . '/balance.php?payment_cancel=1&order=' . urlencode($order_no),
                    'webhookUrl' => $baseUrl . '/api/v1/binance/webhook.php',
                    'passThroughInfo' => 'recharge:' . $user_id,
                ];

                $binanceResp = BinancePayService::createOrder($bCfg, $payload);
                if (!BinancePayService::isSuccess($binanceResp)) {
                    $respBody = $binanceResp['data'] ?? [];
                    $msg = (string)($respBody['errorMessage'] ?? $respBody['msg'] ?? 'Binance Pay create order failed');
                    throw new Exception($msg);
                }
                $respData = $binanceResp['data']['data'] ?? [];
                $prepayId = trim((string)($respData['prepayId'] ?? ''));
                if ($prepayId !== '') {
                    $db->query(
                        "UPDATE orders SET tx_hash = ?, binance_pay_order_id = ? WHERE order_no = ? AND status = 'pending'",
                        [$prepayId, $prepayId, $order_no]
                    );
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

                $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
                $isMobile = preg_match('/Android|iPhone|iPad|iPod|Mobile|HarmonyOS/i', $ua) === 1;
                if ($isMobile) {
                    $query = http_build_query([
                        'order' => $order_no,
                        'checkout' => $checkoutUrl,
                        'deeplink' => ($universalUrl !== '' ? $universalUrl : $deepLink),
                    ]);
                    header("Location: /binance_open.php?" . $query);
                } else {
                    header("Location: " . $checkoutUrl);
                }
                exit;
            } catch (Throwable $e) {
                $setFlash('error', 'Binance Pay: ' . $e->getMessage());
                $redirectSelf();
            }
        }
    }
    
    // Handle Purchase Service
    if ($_POST['action'] === 'buy_service') {
        [$otpOk, $otpMsg] = User2FAService::verifyForScene((array)$user, 'balance_pay', trim((string)($_POST['balance_otp'] ?? '')));
        if (!$otpOk) {
            $setFlash('error', $otpMsg);
            $redirectSelf();
        }
        $service_id = (int)$_POST['service_id'];
        $service = $db->fetch("SELECT * FROM services WHERE id = ? AND status = 1", [$service_id]);
        
        if (!$service) {
            $setFlash('error', __('merchant.balance.error.service_not_available'));
            $redirectSelf();
        } elseif ($user['balance'] < $service['price']) {
            $setFlash('error', __('merchant.balance.error.insufficient_balance'));
            $redirectSelf();
        } else {
            // Deduct Balance
            $db->query("UPDATE users SET balance = balance - ? WHERE id = ?", [$service['price'], $user_id]);
            
            // Add Quota
            $serviceType = strtolower(trim((string)($service['type'] ?? '')));
            if ($serviceType === 'fast_sync') {
                $db->query("UPDATE users SET fast_sync_remaining = fast_sync_remaining + ? WHERE id = ?", [(int)$service['amount'], $user_id]);
                $desc = __('merchant.balance.tx.buy_prefix') . ' ' . $service['name'] . ' +' . (int)$service['amount'];
            } elseif ($serviceType === 'tg_notice') {
                // Existing notification quota logic uses "used count"; reducing used count effectively grants extra sends.
                $db->query(
                    "UPDATE users
                     SET tg_notice_used_month = CASE WHEN tg_notice_used_month > ? THEN tg_notice_used_month - ? ELSE 0 END
                     WHERE id = ?",
                    [(int)$service['amount'], (int)$service['amount'], $user_id]
                );
                $desc = __('merchant.balance.tx.buy_prefix') . ' ' . $service['name'] . ' +' . (int)$service['amount'];
            } elseif ($serviceType === 'email_notice') {
                $db->query(
                    "UPDATE users
                     SET email_notice_used_month = CASE WHEN email_notice_used_month > ? THEN email_notice_used_month - ? ELSE 0 END
                     WHERE id = ?",
                    [(int)$service['amount'], (int)$service['amount'], $user_id]
                );
                $desc = __('merchant.balance.tx.buy_prefix') . ' ' . $service['name'] . ' +' . (int)$service['amount'];
            } else {
                $error = __('merchant.balance.error.service_not_available');
                $desc = '';
            }

            if (!empty($error)) {
                // Rollback balance deduction for unsupported legacy service types
                $db->query("UPDATE users SET balance = balance + ? WHERE id = ?", [$service['price'], $user_id]);
                $setFlash('error', $error);
                $redirectSelf();
            } else {
                // Record Transaction
                $db->query("INSERT INTO transactions (user_id, type, amount, balance_after, description, status) VALUES (?, 'spend', ?, ?, ?, 'completed')", [
                    $user_id, -$service['price'], $user['balance'] - $service['price'], $desc
                ]);
                
                // TG Notification
                require_once __DIR__ . '/../src/Services/NotificationDispatcher.php';
                NotificationDispatcher::sendToUser(
                    $user_id,
                    __('merchant.notifications.toggle.balance_title'),
                    __('merchant.balance.tg.consume_notice', [
                        'price' => number_format($service['price'], 2),
                        'service' => $service['name']
                    ]),
                    'balance'
                );
    
                $setFlash('success', __('merchant.balance.success.service_purchased'));
                $redirectSelf();
            }
        }
    }

    // Handle internal transfer between site accounts
    if ($_POST['action'] === 'transfer_balance') {
        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || stripos($accept, 'application/json') !== false;

        $fail = static function (string $message) use ($isAjax, $setFlash, $redirectSelf, $respondJson): void {
            if ($isAjax) {
                $respondJson(false, $message);
            }
            $setFlash('error', $message);
            $redirectSelf();
        };

        [$otpOk, $otpMsg] = User2FAService::verifyForScene((array)$user, 'balance_pay', trim((string)($_POST['balance_otp'] ?? '')));
        if (!$otpOk) {
            $fail($otpMsg);
        }

        $targetInput = trim((string)($_POST['target_user'] ?? ''));
        $amount = round((float)($_POST['amount'] ?? 0), 6);
        $note = trim((string)($_POST['note'] ?? ''));
        if ($targetInput === '' || $amount <= 0) {
            $fail('请填写正确的收款账号与转账金额。');
        }
        if ($note !== '') {
            $note = function_exists('mb_substr') ? mb_substr($note, 0, 120) : substr($note, 0, 120);
        }

        $conn = $db->getConnection();
        try {
            $conn->beginTransaction();

            $sender = $db->fetch("SELECT id, email, balance, withdrawable_balance FROM users WHERE id = ? LIMIT 1 FOR UPDATE", [$user_id]);
            if (!$sender) {
                throw new Exception('发起账号不存在。');
            }

            $targetUser = null;
            if (ctype_digit($targetInput)) {
                $targetUser = $db->fetch("SELECT id, email, balance, withdrawable_balance FROM users WHERE id = ? LIMIT 1 FOR UPDATE", [(int)$targetInput]);
            }
            if (!$targetUser) {
                $targetUser = $db->fetch("SELECT id, email, balance, withdrawable_balance FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1 FOR UPDATE", [$targetInput]);
            }
            if (!$targetUser) {
                throw new Exception('收款账号不存在，请检查用户ID或邮箱。');
            }
            if ((int)$targetUser['id'] === (int)$sender['id']) {
                throw new Exception('不能给自己转账。');
            }

            $senderBalance = (float)($sender['balance'] ?? 0);
            $senderWithdrawable = (float)($sender['withdrawable_balance'] ?? 0);
            $senderNonWithdrawable = max(0.0, $senderBalance - $senderWithdrawable);

            $sourceBucket = '';
            if ($amount <= $senderWithdrawable + 0.0000001) {
                $sourceBucket = 'withdrawable';
            } elseif ($amount <= $senderNonWithdrawable + 0.0000001) {
                $sourceBucket = 'non_withdrawable';
            } else {
                throw new Exception('可提现与不可提现余额均不足以完成此次转账。');
            }

            if ($sourceBucket === 'withdrawable') {
                $db->query(
                    "UPDATE users
                     SET balance = balance - ?,
                         withdrawable_balance = withdrawable_balance - ?
                     WHERE id = ?",
                    [$amount, $amount, $user_id]
                );
                $db->query(
                    "UPDATE users
                     SET balance = balance + ?,
                         withdrawable_balance = withdrawable_balance + ?
                     WHERE id = ?",
                    [$amount, $amount, (int)$targetUser['id']]
                );
            } else {
                $db->query("UPDATE users SET balance = balance - ? WHERE id = ?", [$amount, $user_id]);
                $db->query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, (int)$targetUser['id']]);
            }

            $senderAfter = $db->fetch("SELECT balance, withdrawable_balance FROM users WHERE id = ? LIMIT 1", [$user_id]);
            $receiverAfter = $db->fetch("SELECT balance, withdrawable_balance FROM users WHERE id = ? LIMIT 1", [(int)$targetUser['id']]);
            $senderAfterBalance = (float)($senderAfter['balance'] ?? 0);
            $receiverAfterBalance = (float)($receiverAfter['balance'] ?? 0);

            $sourceLabel = $sourceBucket === 'withdrawable' ? '可提现余额' : '不可提现余额';
            $toEmail = (string)($targetUser['email'] ?? ('UID:' . (int)$targetUser['id']));
            $fromEmail = (string)($sender['email'] ?? ('UID:' . (int)$sender['id']));
            $noteSuffix = $note !== '' ? ('；备注：' . $note) : '';
            $senderTxDesc = "站内转账给 {$toEmail}（{$sourceLabel}）#";

            $db->query(
                "INSERT INTO user_balance_transfers (from_user_id, to_user_id, amount, source_bucket, note, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [$user_id, (int)$targetUser['id'], $amount, $sourceBucket, $note !== '' ? $note : null]
            );
            $transferId = (int)$db->lastInsertId();
            $senderTxDesc .= $transferId . $noteSuffix;
            $receiverTxDesc = "收到 {$fromEmail} 站内转账（{$sourceLabel}）#{$transferId}{$noteSuffix}";

            $db->query(
                "INSERT INTO transactions (user_id, type, amount, balance_after, description, status)
                 VALUES (?, 'transfer_out', ?, ?, ?, 'completed')",
                [$user_id, -$amount, $senderAfterBalance, $senderTxDesc]
            );
            $db->query(
                "INSERT INTO transactions (user_id, type, amount, balance_after, description, status)
                 VALUES (?, 'transfer_in', ?, ?, ?, 'completed')",
                [(int)$targetUser['id'], $amount, $receiverAfterBalance, $receiverTxDesc]
            );

            $conn->commit();
            $okMessage = "转账成功：{$amount} USD，收款人 {$toEmail}，来源 {$sourceLabel}。";
            if ($isAjax) {
                $senderAfterWithdrawable = (float)($senderAfter['withdrawable_balance'] ?? 0);
                $respondJson(true, $okMessage, [
                    'amount' => $amount,
                    'source_label' => $sourceLabel,
                    'to_email' => $toEmail,
                    'description' => $senderTxDesc,
                    'status' => 'completed',
                    'created_at' => date('Y-m-d H:i:s'),
                    'sender_balance' => round($senderAfterBalance, 6),
                    'sender_withdrawable' => round($senderAfterWithdrawable, 6),
                    'sender_non_withdrawable' => round(max(0.0, $senderAfterBalance - $senderAfterWithdrawable), 6)
                ]);
            }
            $setFlash('success', $okMessage);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $fail('转账失败：' . $e->getMessage());
        }
        if (!$isAjax) {
            $redirectSelf();
        }
    }
}

if (isset($_GET['payment_success']) && $_GET['payment_success'] == '1') {
    $success = __('merchant.balance.success.recharge_paid');
} elseif (isset($_GET['payment_cancel']) && $_GET['payment_cancel'] == '1') {
    $error = __('merchant.balance.error.recharge_cancelled');
}

// Fetch Services
$services = $db->fetchAll("SELECT * FROM services WHERE status = 1 ORDER BY price ASC");

// Fetch Transactions
$transactions = $db->fetchAll("SELECT * FROM transactions WHERE user_id = ? ORDER BY created_at DESC LIMIT 20", [$user_id]);

$page_title = __('merchant.balance.title');
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Content -->
        <div class="col-md-9 col-lg-10 main-content">
            <?php include __DIR__ . '/includes/user_topbar.php'; ?>
            
            <?php if(isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if(isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <!-- Balance Card -->
                <div class="col-md-6">
                    <div class="mole-card h-100 bg-primary text-white" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); border:none;">
                        <h6 class="opacity-75"><?php echo __('merchant.balance.total_assets_usd'); ?></h6>
                        <div class="display-4 fw-bold mb-3" id="balanceTotalValue">$<?php echo number_format($user['balance'], 2); ?></div>
                        <div class="d-flex gap-4 mb-4">
                            <div>
                                <div class="small opacity-75"><?php echo __('merchant.balance.withdrawable'); ?></div>
                                <div class="fw-bold fs-5" id="balanceWithdrawableValue">$<?php echo number_format($user['withdrawable_balance'], 2); ?></div>
                            </div>
                            <div>
                                <div class="small opacity-75">
                                    <?php echo __('merchant.balance.non_withdrawable'); ?>
                                    <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline text-white text-decoration-none opacity-75" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo htmlspecialchars(__('merchant.balance.non_withdrawable_tip')); ?>">
                                        <i class="fas fa-circle-question"></i>
                                    </button>
                                </div>
                                <div class="fw-bold fs-5" id="balanceNonWithdrawableValue">$<?php echo number_format($user['balance'] - $user['withdrawable_balance'], 2); ?></div>
                            </div>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-light text-primary fw-bold px-4" data-bs-toggle="modal" data-bs-target="#rechargeModal"><?php echo __('merchant.balance.recharge'); ?></button>
                            <button class="btn btn-outline-light fw-bold px-4" data-bs-toggle="modal" data-bs-target="#withdrawModal"><?php echo __('merchant.balance.withdraw'); ?></button>
                            <button class="btn btn-outline-light fw-bold px-4" data-bs-toggle="modal" data-bs-target="#transferModal">转账</button>
                        </div>
                    </div>
                </div>

                <!-- Purchase Services Card -->
                <div class="col-md-6">
                    <div class="mole-card h-100">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0" style="color: var(--text-primary);"><?php echo __('merchant.balance.value_added_services'); ?></h6>
                            <div class="btn-group btn-group-sm" role="group" aria-label="service tabs">
                                <button type="button" class="btn btn-primary" id="serviceTabBuy" onclick="switchServiceTab('buy')"><?php echo __('merchant.balance.tab.buy'); ?></button>
                                <button type="button" class="btn btn-outline-primary" id="serviceTabUsage" onclick="switchServiceTab('usage')"><?php echo __('merchant.balance.tab.usage'); ?></button>
                            </div>
                        </div>

                        <div id="servicePaneBuy" class="list-group list-group-flush" style="max-height: 220px; overflow-y: auto;">
                            <?php if(empty($services)): ?>
                            <div class="text-center text-muted py-3 small"><?php echo __('merchant.balance.no_services'); ?></div>
                            <?php else: ?>
                            <?php foreach($services as $s): ?>
                            <?php $st = strtolower((string)($s['type'] ?? '')); ?>
                            <div class="list-group-item bg-transparent d-flex justify-content-between align-items-center px-0">
                                <div>
                                    <div class="fw-medium small"><?php echo htmlspecialchars($s['name']); ?></div>
                                    <div class="text-secondary" style="font-size: 11px;">
                                        <?php
                                            if ($st === 'fast_sync') echo __('merchant.balance.service.fast_sync');
                                            elseif ($st === 'tg_notice') echo __('merchant.balance.service.tg_notice');
                                            elseif ($st === 'email_notice') echo __('merchant.balance.service.email_notice');
                                            else echo htmlspecialchars($s['type']);
                                        ?>
                                    </div>
                                </div>
                                <form method="POST" onsubmit="return handleBuyServiceSubmit(this, <?php echo json_encode(__('merchant.balance.confirm_buy', ['price' => $s['price']])); ?>);"><?php echo Helper::csrfField(); ?>
                                    <input type="hidden" name="action" value="buy_service">
                                    <input type="hidden" name="service_id" value="<?php echo $s['id']; ?>">
                                    <input type="hidden" name="balance_otp" value="">
                                    <button class="btn btn-sm btn-outline-primary rounded-pill">
                                        $<?php echo number_format($s['price'], 2); ?> <?php echo __('merchant.balance.buy'); ?>
                                    </button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <?php
                            $tgLimit = (int)($planUsage['tg_notice_limit'] ?? 0);
                            $tgUsed = (int)($user['tg_notice_used_month'] ?? 0);
                            $tgRemaining = max(0, $tgLimit - $tgUsed);
                            $emailLimit = (int)($planUsage['email_notice_limit'] ?? 0);
                            $emailUsed = (int)($user['email_notice_used_month'] ?? 0);
                            $emailRemaining = max(0, $emailLimit - $emailUsed);
                        ?>
                        <div id="servicePaneUsage" class="d-none">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item bg-transparent px-0 d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-medium small"><?php echo __('merchant.balance.usage.fast_sync'); ?></div>
                                        <div class="text-secondary" style="font-size: 11px;"><?php echo __('merchant.balance.usage.fast_sync_desc'); ?></div>
                                    </div>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?php echo (int)($user['fast_sync_remaining'] ?? 0); ?></span>
                                </div>
                                <div class="list-group-item bg-transparent px-0 d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-medium small"><?php echo __('merchant.balance.usage.tg_notice'); ?></div>
                                        <div class="text-secondary" style="font-size: 11px;"><?php echo __('merchant.balance.usage.tg_notice_desc'); ?></div>
                                    </div>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle"><?php echo $tgRemaining; ?></span>
                                </div>
                                <div class="list-group-item bg-transparent px-0 d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-medium small"><?php echo __('merchant.balance.usage.email_notice'); ?></div>
                                        <div class="text-secondary" style="font-size: 11px;"><?php echo __('merchant.balance.usage.email_notice_desc'); ?></div>
                                    </div>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle"><?php echo $emailRemaining; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transactions -->
            <div class="mole-card">
                <h6 class="fw-bold mb-3" style="color: var(--text-primary);"><?php echo __('merchant.balance.transactions'); ?></h6>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th><?php echo __('merchant.balance.table.time'); ?></th>
                                <th><?php echo __('merchant.balance.table.type'); ?></th>
                                <th><?php echo __('merchant.balance.table.amount'); ?></th>
                                <th><?php echo __('merchant.balance.table.balance_after'); ?></th>
                                <th><?php echo __('merchant.balance.table.description'); ?></th>
                                <th><?php echo __('merchant.balance.table.status'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="transactionsTableBody">
                            <?php foreach($transactions as $t): ?>
                            <tr>
                                <td class="small text-muted"><?php echo $t['created_at']; ?></td>
                                <td>
                                    <?php 
                                        $badges = [
                                            'recharge' => ['bg'=>'success', 'text'=>__('merchant.balance.type.recharge')],
                                            'spend' => ['bg'=>'secondary', 'text'=>__('merchant.balance.type.spend')],
                                            'earning' => ['bg'=>'warning', 'text'=>__('merchant.balance.type.earning')],
                                            'withdraw' => ['bg'=>'danger', 'text'=>__('merchant.balance.type.withdraw')],
                                            'transfer_out' => ['bg'=>'dark', 'text'=>'转出'],
                                            'transfer_in' => ['bg'=>'primary', 'text'=>'转入'],
                                        ];
                                        $b = $badges[$t['type']] ?? ['bg'=>'secondary', 'text'=>$t['type']];
                                    ?>
                                    <span class="badge bg-<?php echo $b['bg']; ?> bg-opacity-10 text-<?php echo $b['bg']; ?>"><?php echo $b['text']; ?></span>
                                </td>
                                <td class="fw-bold <?php echo $t['amount']>0?'text-success':'text-dark'; ?>">
                                    <?php echo $t['amount']>0?'+':''; ?><?php echo number_format($t['amount'], 2); ?>
                                </td>
                                <td>$<?php echo number_format($t['balance_after'], 2); ?></td>
                                <td class="small text-secondary"><?php echo htmlspecialchars($t['description']); ?></td>
                                <td>
                                    <?php
                                        $status_map = [
                                            'completed' => __('merchant.balance.status.completed'),
                                            'pending' => __('merchant.balance.status.pending'),
                                            'failed' => __('merchant.balance.status.failed'),
                                        ];
                                    ?>
                                    <span class="small text-muted"><?php echo htmlspecialchars($status_map[$t['status']] ?? $t['status']); ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recharge Modal -->
<div class="modal fade" id="rechargeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 16px;"><?php echo Helper::csrfField(); ?>
            <input type="hidden" name="action" value="recharge">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold"><?php echo __('merchant.balance.recharge_modal.title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold"><?php echo __('merchant.balance.recharge_modal.amount_usd'); ?></label>
                    <input type="number" name="amount" id="rechargeAmount" class="form-control form-control-lg" placeholder="100" min="10" step="1" required>
                    
                    <!-- Quick Recharge Buttons -->
                    <div class="mt-2 d-grid recharge-quick-grid gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="document.getElementById('rechargeAmount').value=10">$10</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="document.getElementById('rechargeAmount').value=50">$50</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="document.getElementById('rechargeAmount').value=100">$100</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="document.getElementById('rechargeAmount').value=500">$500</button>
                    </div>
                </div>
                
                <h6 class="fw-bold mb-3 small text-secondary"><?php echo __('merchant.balance.recharge_modal.select_method'); ?></h6>
                <div class="mb-3 recharge-payment-grid">
                    <label class="payment-option selected" id="recharge-option-stripe" onclick="selectRechargeMethod('stripe')">
                        <input class="d-none" type="radio" name="payment_method" value="stripe" checked>
                        <div class="d-flex align-items-center">
                            <div>
                                <div class="fw-bold method-title"><?php echo __('merchant.balance.recharge_modal.card'); ?></div>
                                <div class="method-subtitle"><?php echo __('merchant.balance.recharge_modal.card_hint'); ?></div>
                            </div>
                            <i class="fab fa-cc-stripe ms-auto text-primary fs-4"></i>
                        </div>
                    </label>

                    <label class="payment-option" id="recharge-option-usdt" onclick="selectRechargeMethod('usdt')">
                        <input class="d-none" type="radio" name="payment_method" value="usdt">
                        <div class="d-flex align-items-center">
                            <div>
                                <div class="fw-bold method-title"><?php echo __('merchant.balance.recharge_modal.usdt'); ?></div>
                                <div class="method-subtitle"><?php echo __('merchant.balance.recharge_modal.usdt_hint'); ?></div>
                            </div>
                            <img src="https://cdn.jsdelivr.net/gh/atomiclabs/cryptocurrency-icons@master/32/color/usdt.png" alt="USDT" class="ms-auto" style="height:22px;width:22px;" onerror="this.style.display='none'">
                        </div>
                    </label>

                    <label class="payment-option" id="recharge-option-binance" onclick="selectRechargeMethod('binance_pay')">
                        <input class="d-none" type="radio" name="payment_method" value="binance_pay">
                        <div class="d-flex align-items-center">
                            <div>
                                <div class="fw-bold method-title"><?php echo __('merchant.balance.recharge_modal.binance_hint'); ?></div>
                                <div class="method-subtitle"><?php echo __('merchant.balance.recharge_modal.binance'); ?></div>
                            </div>
                            <img src="https://www.binance.com/favicon.ico" alt="Binance" class="ms-auto" style="height:22px;width:22px;border-radius:4px;" onerror="this.onerror=null;this.src='https://public.bnbstatic.com/static/images/common/favicon.ico';">
                        </div>
                    </label>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="submit" id="rechargeSubmitBtn" class="btn btn-primary w-100 py-2 rounded-3"><?php echo __('merchant.balance.recharge_modal.submit'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/stripe_loading_ui.php'; ?>
<div id="binanceWaitingOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:2000;align-items:center;justify-content:center;padding:16px;">
    <div style="width:min(360px,100%);background:#fff;border-radius:14px;padding:16px 16px 14px;box-shadow:0 10px 30px rgba(2,6,23,.28);text-align:center;">
        <div style="width:30px;height:30px;border:3px solid #e5e7eb;border-top-color:#f0b90b;border-radius:50%;margin:0 auto 10px;animation:binanceSpin .9s linear infinite;"></div>
        <div style="font-size:15px;font-weight:700;color:#111827;"><?php echo __('merchant.balance.binance_waiting_title'); ?></div>
        <div style="font-size:12px;color:#6b7280;margin-top:4px;"><?php echo __('merchant.balance.binance_waiting_desc'); ?></div>
    </div>
</div>

<!-- Withdraw Modal -->
<div class="modal fade" id="withdrawModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 16px;"><?php echo Helper::csrfField(); ?>
            <input type="hidden" name="action" value="withdraw">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold"><?php echo __('merchant.balance.withdraw_modal.title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-secondary"><?php echo __('merchant.balance.withdraw_modal.withdrawable_balance'); ?></span>
                    <span class="fw-bold text-success">$<?php echo number_format($user['withdrawable_balance'], 2); ?></span>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold"><?php echo __('merchant.balance.withdraw_modal.amount'); ?></label>
                    <input type="number" name="amount" class="form-control" max="<?php echo $user['withdrawable_balance']; ?>" min="10" step="0.01" required>
                </div>
                <?php if(empty($user['withdraw_address'])): ?>
                <div class="alert alert-warning small">
                    <i class="fas fa-exclamation-triangle me-1"></i> <?php echo __('merchant.balance.withdraw_modal.no_address_prefix'); ?> <a href="settings.php"><?php echo __('merchant.balance.withdraw_modal.settings_link'); ?></a> <?php echo __('merchant.balance.withdraw_modal.no_address_suffix'); ?>
                </div>
                <?php else: ?>
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold"><?php echo __('merchant.balance.withdraw_modal.to'); ?></label>
                    <div class="form-control bg-light text-muted small text-truncate">
                        <?php echo $user['withdraw_address']; ?> (<?php echo $user['withdraw_network']; ?>)
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill" <?php echo empty($user['withdraw_address'])?'disabled':''; ?>><?php echo __('merchant.balance.withdraw_modal.submit'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Transfer Modal -->
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 16px;" id="transferForm"><?php echo Helper::csrfField(); ?>
            <input type="hidden" name="action" value="transfer_balance">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold">站内账户转账</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <div class="alert d-none small py-2" id="transferFeedback"></div>
                <div class="alert alert-info small py-2">
                    系统将自动识别转出类型：优先使用可提现余额；若可提现不足且不可提现余额单独充足，则转为不可提现余额。
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold">收款用户（用户ID或邮箱）</label>
                    <input type="text" name="target_user" class="form-control" placeholder="例如：10001 或 user@example.com" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold">转账金额（USD）</label>
                    <input type="number" name="amount" class="form-control" min="0.000001" step="0.000001" required>
                </div>
                <div class="mb-0">
                    <label class="form-label small text-secondary fw-bold">备注（可选）</label>
                    <input type="text" name="note" class="form-control" maxlength="120" placeholder="最多120字">
                </div>
                <?php if ($balanceOtpRequired): ?>
                <div class="mb-0 mt-3">
                    <label class="form-label small text-secondary fw-bold">资金验证码（6位）</label>
                    <input type="text" name="balance_otp" class="form-control" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" placeholder="请输入6位验证码" required>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="submit" class="btn btn-primary w-100 py-2 rounded-pill" id="transferSubmitBtn">确认转账</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes binanceSpin { to { transform: rotate(360deg); } }
.payment-option {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
    margin-bottom: 0;
    cursor: pointer;
    transition: all 0.2s ease;
    background: #fff;
    min-height: 96px;
}
.payment-option:hover {
    border-color: #cbd5e1;
    background: #f8fafc;
}
.payment-option.selected {
    border-color: #3b82f6;
    background: #eff6ff;
    box-shadow: 0 0 0 1px #3b82f6;
}
.payment-option .method-title {
    font-size: 1.02rem;
    line-height: 1.2;
}
.payment-option .method-subtitle {
    font-size: 0.86rem;
    color: #64748b;
    line-height: 1.2;
}
.recharge-quick-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
}
.recharge-payment-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    column-gap: 12px;
    row-gap: 12px;
    padding: 0 2px;
}
@media (max-width: 576px) {
    .recharge-quick-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .recharge-payment-grid {
        grid-template-columns: 1fr;
    }
}
</style>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const BALANCE_OTP_REQUIRED = <?php echo $balanceOtpRequired ? 'true' : 'false'; ?>;

function showBinanceWaiting() {
    const el = document.getElementById('binanceWaitingOverlay');
    if (!el) return;
    el.style.display = 'flex';
}

function handleBuyServiceSubmit(form, confirmText) {
    if (!confirm(confirmText)) return false;
    if (!BALANCE_OTP_REQUIRED) return true;
    const otp = (prompt(<?php echo json_encode(__('merchant.balance.otp_prompt')); ?>) || '').trim();
    if (!/^\\d{6}$/.test(otp)) {
        alert(<?php echo json_encode(__('merchant.balance.otp_invalid')); ?>);
        return false;
    }
    const hidden = form.querySelector('input[name=\"balance_otp\"]');
    if (hidden) hidden.value = otp;
    return true;
}

function selectRechargeMethod(method) {
    const stripeOpt = document.getElementById('recharge-option-stripe');
    const usdtOpt = document.getElementById('recharge-option-usdt');
    const binanceOpt = document.getElementById('recharge-option-binance');
    const stripeInput = document.querySelector('input[name="payment_method"][value="stripe"]');
    const usdtInput = document.querySelector('input[name="payment_method"][value="usdt"]');
    const binanceInput = document.querySelector('input[name="payment_method"][value="binance_pay"]');
    if (!stripeOpt || !usdtOpt || !binanceOpt || !stripeInput || !usdtInput || !binanceInput) return;

    if (method === 'stripe') {
        stripeInput.checked = true;
        usdtInput.checked = false;
        binanceInput.checked = false;
        stripeOpt.classList.add('selected');
        usdtOpt.classList.remove('selected');
        binanceOpt.classList.remove('selected');
    } else if (method === 'binance_pay') {
        stripeInput.checked = false;
        usdtInput.checked = false;
        binanceInput.checked = true;
        stripeOpt.classList.remove('selected');
        usdtOpt.classList.remove('selected');
        binanceOpt.classList.add('selected');
    } else {
        usdtInput.checked = true;
        stripeInput.checked = false;
        binanceInput.checked = false;
        usdtOpt.classList.add('selected');
        stripeOpt.classList.remove('selected');
        binanceOpt.classList.remove('selected');
    }
}

function switchServiceTab(tab) {
    const buyBtn = document.getElementById('serviceTabBuy');
    const usageBtn = document.getElementById('serviceTabUsage');
    const buyPane = document.getElementById('servicePaneBuy');
    const usagePane = document.getElementById('servicePaneUsage');
    if (!buyBtn || !usageBtn || !buyPane || !usagePane) return;

    if (tab === 'usage') {
        usageBtn.className = 'btn btn-primary';
        buyBtn.className = 'btn btn-outline-primary';
        usagePane.classList.remove('d-none');
        buyPane.classList.add('d-none');
    } else {
        buyBtn.className = 'btn btn-primary';
        usageBtn.className = 'btn btn-outline-primary';
        buyPane.classList.remove('d-none');
        usagePane.classList.add('d-none');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function (el) {
        new bootstrap.Tooltip(el);
    });

    bindStripeLoadingOnForm({
        formSelector: '#rechargeModal form',
        submitButtonSelector: '#rechargeSubmitBtn',
        processingText: <?php echo json_encode(__('merchant.balance.recharge_modal.processing_short')); ?>,
        shouldShow: function () {
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            return !!selectedMethod && selectedMethod.value === 'stripe';
        }
    });

    const rechargeForm = document.querySelector('#rechargeModal form');
    if (rechargeForm) {
        rechargeForm.addEventListener('submit', function () {
            const selectedMethod = document.querySelector('input[name="payment_method"]:checked');
            if (selectedMethod && selectedMethod.value === 'binance_pay') {
                showBinanceWaiting();
            }
        });
    }

    const transferForm = document.getElementById('transferForm');
    const transferFeedback = document.getElementById('transferFeedback');
    const transferSubmitBtn = document.getElementById('transferSubmitBtn');
    const transactionsBody = document.getElementById('transactionsTableBody');
    const balanceTotalEl = document.getElementById('balanceTotalValue');
    const balanceWithdrawableEl = document.getElementById('balanceWithdrawableValue');
    const balanceNonWithdrawableEl = document.getElementById('balanceNonWithdrawableValue');

    const esc = (v) => String(v == null ? '' : v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const fmtMoney = (v) => `$${Number(v || 0).toFixed(2)}`;
    const showTransferFeedback = (type, message) => {
        if (!transferFeedback) return;
        transferFeedback.classList.remove('d-none', 'alert-danger', 'alert-success');
        transferFeedback.classList.add(type === 'success' ? 'alert-success' : 'alert-danger');
        transferFeedback.textContent = message;
    };

    if (transferForm) {
        transferForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            showTransferFeedback('error', '');
            if (transferFeedback) transferFeedback.classList.add('d-none');

            if (transferSubmitBtn) {
                transferSubmitBtn.disabled = true;
                transferSubmitBtn.textContent = '提交中...';
            }

            try {
                const resp = await fetch('balance.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json'
                    },
                    body: new FormData(transferForm)
                });
                const data = await resp.json();
                if (!resp.ok || !data.ok) {
                    throw new Error(data.message || '转账失败，请稍后重试。');
                }

                showTransferFeedback('success', data.message || '转账成功');
                transferForm.reset();

                if (balanceTotalEl) balanceTotalEl.textContent = fmtMoney(data.sender_balance);
                if (balanceWithdrawableEl) balanceWithdrawableEl.textContent = fmtMoney(data.sender_withdrawable);
                if (balanceNonWithdrawableEl) balanceNonWithdrawableEl.textContent = fmtMoney(data.sender_non_withdrawable);

                if (transactionsBody) {
                    const amount = Number(data.amount || 0);
                    const rowHtml = `
                        <tr>
                            <td class="small text-muted">${esc(data.created_at || '')}</td>
                            <td><span class="badge bg-dark bg-opacity-10 text-dark">转出</span></td>
                            <td class="fw-bold text-dark">-${amount.toFixed(2)}</td>
                            <td>${fmtMoney(data.sender_balance || 0)}</td>
                            <td class="small text-secondary">${esc(data.description || '')}</td>
                            <td><span class="small text-muted">completed</span></td>
                        </tr>
                    `;
                    transactionsBody.insertAdjacentHTML('afterbegin', rowHtml);
                }
            } catch (err) {
                showTransferFeedback('error', err.message || '转账失败');
            } finally {
                if (transferSubmitBtn) {
                    transferSubmitBtn.disabled = false;
                    transferSubmitBtn.textContent = '确认转账';
                }
            }
        });
    }
});
</script>
</body>
</html>
