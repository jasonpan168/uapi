<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/CryptoService.php';
require_once __DIR__ . '/../../../../src/Services/SecurityService.php';
require_once __DIR__ . '/../../../../src/Services/StoreReceiptService.php';
require_once __DIR__ . '/../../../../src/Services/StoreCouponService.php';
require_once __DIR__ . '/../../../../src/Services/NotificationDispatcher.php';
require_once __DIR__ . '/../../../../src/Services/ReferralService.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$order_no = $_GET['order_no'] ?? '';
if (!$order_no) {
    http_response_code(400);
    echo json_encode(['status'=>'error','error'=>'缺少订单号']);
    exit;
}

try {
    $db = Database::getInstance();
    $sec = new SecurityService($db);
    $ip = $_SERVER['REMOTE_ADDR'];
    $is_admin = false;
    if (isset($_SESSION['user_id'])) {
        $u = $db->fetch("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
        if ($u && $u['role'] === 'admin') {
            $is_admin = true;
        }
    }
    
    // Security Checks
    if ($reason = $sec->checkBlocked($ip)) {
        http_response_code(403);
        echo json_encode(['status'=>'error', 'error'=>'IP Blocked: ' . $reason]);
        exit;
    }
    
    // Rate Limit: 30 requests per minute per IP
    if (!$sec->checkRateLimit($ip, 'status.php', 30, 60)) {
        http_response_code(429);
        echo json_encode(['status'=>'error', 'error'=>'Too Many Requests']);
        exit;
    }
    
    $order = $db->fetch("SELECT o.*, w.address AS wallet_address, w.chain AS wallet_chain FROM orders o LEFT JOIN wallets w ON o.wallet_id = w.id WHERE o.order_no = ?", [$order_no]);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['status'=>'error','error'=>'订单不存在']);
        exit;
    }

    // Log Request
    $sec->logRequest($order['user_id'], 'status.php', 'GET', $order['chain'] ?? '', $ip);

    // Token guard for checkout page polling (admin support bypass)
    if (!$is_admin) {
        $request_token = (string)($_GET['token'] ?? '');
        $stored_token = (string)($order['pay_access_token'] ?? '');
        if ($stored_token === '' || $request_token === '' || !hash_equals($stored_token, $request_token)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'error' => 'Invalid token']);
            exit;
        }
    }

    // Per-order burst guard (protect single order from aggressive polling, even across IPs)
    $perOrderCount = $db->fetch(
        "SELECT COUNT(*) as c
         FROM api_logs
         WHERE endpoint = ? AND created_at > DATE_SUB(NOW(), INTERVAL 10 SECOND)",
        ['status.order.' . $order_no]
    );
    if ((int)($perOrderCount['c'] ?? 0) >= 40) {
        http_response_code(429);
        echo json_encode(['status' => 'error', 'error' => 'Order polling too frequent']);
        exit;
    }
    $sec->logRequest($order['user_id'] ?? null, 'status.order.' . $order_no, 'POLL', $order['chain'] ?? '', $ip);

    // Handle expiration by DB clock (avoid PHP/DB timezone mismatch)
    if ($order['status'] === 'pending') {
        try {
            $expMeta = $db->fetch(
                "SELECT TIMESTAMPDIFF(SECOND, NOW(), COALESCE(expire_at, DATE_ADD(created_at, INTERVAL 600 SECOND))) AS remaining_seconds
                 FROM orders
                 WHERE id = ?
                 LIMIT 1",
                [$order['id']]
            );
            $remainingSeconds = isset($expMeta['remaining_seconds']) ? (int)$expMeta['remaining_seconds'] : 0;
            if ($remainingSeconds <= 0) {
                $db->query("UPDATE orders SET status='expired', updated_at=NOW() WHERE id=?", [$order['id']]);
                echo json_encode(['status'=>'expired']);
                exit;
            }
        } catch (Throwable $e) {
            // fallthrough; do not block normal flow
        }
    }

    // Security: Check Referer Binding
    // Protect against unauthorized polling from other domains
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!empty($referer)) {
        $parsed = parse_url($referer);
        $ref_host = $parsed['host'] ?? '';
        $current_host = $_SERVER['HTTP_HOST'] ?? '';
        
        $ref_host = explode(':', $ref_host)[0];
        $current_host = explode(':', $current_host)[0];
        
        if ($ref_host && $ref_host !== $current_host && $ref_host !== 'localhost') {
            $clean_host = preg_replace('/^www\./', '', $ref_host);
            
            $bound = $db->fetch("SELECT id FROM websites WHERE user_id = ? AND (domain = ? OR domain = ?) AND status = 'active'", [
                $order['user_id'], $clean_host, 'www.'.$clean_host
            ]);
            
            if (!$bound) {
                 http_response_code(403);
                 echo json_encode(['status'=>'error', 'error'=>"Access Denied: Domain '$ref_host' is not bound to this merchant."]);
                 exit;
            }
        }
    }

    $rawStatus = strtolower((string)($order['status'] ?? 'pending'));
    if ($rawStatus === 'paid') {
        StoreReceiptService::sendForOrder($order['id']);
        echo json_encode(['status'=>'paid','tx_hash'=>$order['tx_hash']]);
        exit;
    }
    if (in_array($rawStatus, ['cancelled', 'disputed', 'failed', 'refunded'], true)) {
        echo json_encode([
            'status' => $rawStatus,
            'tx_hash' => (string)($order['tx_hash'] ?? ''),
        ]);
        exit;
    }
    // Throttling: Check if we recently synced with external blockchain
    $last_sync = isset($order['last_external_sync']) ? strtotime($order['last_external_sync']) : 0;
    
    // Determine base sync interval from plan settings.
    $base_sync_interval = 10; // Default
    $fast_sync_interval = 3; // Fast package interval

    $user_plan = null;
    if (!empty($order['user_id'])) {
        $user_plan = $db->fetch(
            "SELECT p.sync_interval FROM users u JOIN plans p ON p.id = u.plan_id WHERE u.id = ? LIMIT 1",
            [$order['user_id']]
        );
    }
    if ($user_plan && $user_plan['sync_interval'] > 0) {
        $base_sync_interval = (int)$user_plan['sync_interval'];
    }

    // Smart backoff only applies to normal interval.
    $elapsed = time() - strtotime($order['created_at']);
    if ($elapsed > 600) { // > 10 mins (Should expire, but if not)
         $base_sync_interval = max(60, $base_sync_interval * 4); // Very slow
    } elseif ($elapsed > 300) { // > 5 mins
         $base_sync_interval = max(20, $base_sync_interval * 2); // Slower
    } elseif ($elapsed > 120) { // > 2 mins
         $base_sync_interval = max(15, $base_sync_interval * 1.5); // Slightly slower
    }

    $sync_interval = $base_sync_interval;
    if (!empty($order['is_fast_sync'])) {
        $sync_interval = $fast_sync_interval;
    }

    if (time() - $last_sync < $sync_interval) {
        // Too soon, return pending without checking external API
        echo json_encode(['status'=>'pending', 'cached'=>true]);
        exit;
    }

    // Plan limit: external chain monitor API usage per day
    CryptoService::ensureApiRequestSchema();
    $planLimit = $db->fetch(
        "SELECT p.api_limit_daily
         FROM users u
         JOIN plans p ON p.id = u.plan_id
         WHERE u.id = ?
         LIMIT 1",
        [$order['user_id']]
    );
    $dailyMonitorLimit = (int)($planLimit['api_limit_daily'] ?? 0);
    if ($dailyMonitorLimit > 0) {
        $todayUsedCount = CryptoService::getMerchantBillableRequestCount((int)$order['user_id'], date('Y-m-d'));
        if ($todayUsedCount >= $dailyMonitorLimit) {
            NotificationDispatcher::notifyUser((int)$order['user_id'], [
                'type' => 'low_quota',
                'in_app_type' => 'system',
                'title' => '额度提醒',
                'content' => "日期：" . date('Y-m-d') . "\n今日链上监控查询次数已达到套餐上限（{$dailyMonitorLimit} 次），请升级套餐或明日再试。",
                'subject' => '额度提醒',
                'dedupe_like' => date('Y-m-d'),
            ]);
            echo json_encode([
                'status' => 'pending',
                'limited' => true,
                'error' => 'Daily monitor API limit reached'
            ]);
            exit;
        }
    }

    $address = $order['wallet_address'];
    $amount = floatval($order['amount']);
    $created_ts = strtotime($order['created_at']);
    $chain = strtolower($order['chain']);
    $currency = strtoupper((string)($order['currency'] ?? 'USDT'));
    $tx = null;
    $triggerMode = !empty($order['is_fast_sync']) ? 'fast_sync' : 'normal';
    
    // Load config to access chains_config
    global $chains_config;
    if (!isset($chains_config)) {
        require_once __DIR__ . '/../../../../config/config.php';
    }

    CryptoService::setExternalUsageContext([
        'user_id' => (int)($order['user_id'] ?? 0),
        'order_id' => (int)($order['id'] ?? 0),
        'order_no' => (string)($order['order_no'] ?? ''),
        'chain' => $chain,
        'source' => 'order_status',
        'trigger_mode' => $triggerMode,
    ]);
    try {
        if ($chain === 'trc20') {
            if ($currency !== 'USDT') {
                echo json_encode(['status'=>'pending']);
                exit;
            }
            $tx = CryptoService::checkTrc20($address, $amount, $created_ts);
        } elseif ($chain === 'solana') {
            // Solana Check
            $tx = CryptoService::checkSolana($address, $amount, $created_ts, $currency);
        } else {
            $keys = [];
            // If chain is specifically one of the keys, check only that
            if (isset($chains_config[$chain]) && $chain !== 'trc20') {
                 $keys = [$chain];
            } else {
                 // Check all EVM chains if chain is generic or not found
                 foreach ($chains_config as $k => $v) {
                     if ($k !== 'trc20') {
                         $keys[] = $k;
                     }
                 }
            }
            
            foreach ($keys as $k) {
                $tx = CryptoService::checkEvm($k, $address, $amount, $created_ts, $currency);
                if ($tx) break;
            }
        }
    } finally {
        CryptoService::clearExternalUsageContext();
    }
    if ($tx) {
        $payProvider = 'crypto';
        if (strtolower((string)$chain) === 'stripe') {
            $payProvider = 'stripe';
        } elseif (strtolower((string)$chain) === 'binance_pay') {
            $payProvider = 'binance';
        }
        $updated = $db->query("UPDATE orders SET status='paid', pay_provider=?, paid_at=NOW(), tx_hash=?, updated_at=NOW(), last_external_sync=NOW() WHERE id=? AND status IN ('pending','expired')", [$payProvider, $tx['hash'], $order['id']]);
        if ($updated->rowCount() === 0) {
            $latest = $db->fetch("SELECT status, tx_hash FROM orders WHERE id = ? LIMIT 1", [$order['id']]);
            if (($latest['status'] ?? '') === 'paid') {
                StoreCouponService::applyOnPaid($db, (int)$order['id']);
                StoreReceiptService::sendForOrder($order['id']);
            }
            echo json_encode(['status' => ($latest['status'] ?? 'pending'), 'tx_hash' => $latest['tx_hash'] ?? null]);
            exit;
        }
        StoreCouponService::applyOnPaid($db, (int)$order['id']);
        $couponCode = strtoupper(trim((string)($order['coupon_code'] ?? '')));
        if ($couponCode !== '') {
            $db->query("UPDATE admin_coupons SET used_count = used_count + 1 WHERE code = ? AND status = 'active'", [$couponCode]);
        }

        // Balance recharge fulfillment (on-chain recharge) — atomic, prevents double-credit
        if (strtolower((string)($order['source'] ?? '')) === 'recharge') {
            $rechargeAmount = (float)($order['amount'] ?? 0);
            if ($rechargeAmount > 0) {
                $rechargeCredited = false;
                $db->query("START TRANSACTION");
                try {
                    $desc = 'USDT 余额充值 #' . (string)$order['order_no'];
                    $existsTx = $db->fetch(
                        "SELECT id FROM transactions WHERE user_id = ? AND type = 'recharge' AND description = ? LIMIT 1 FOR UPDATE",
                        [(int)$order['user_id'], $desc]
                    );
                    if (!$existsTx) {
                        $db->query("UPDATE users SET balance = balance + ? WHERE id = ?", [$rechargeAmount, (int)$order['user_id']]);
                        $u = $db->fetch("SELECT balance FROM users WHERE id = ? LIMIT 1", [(int)$order['user_id']]);
                        $balanceAfter = isset($u['balance']) ? (float)$u['balance'] : $rechargeAmount;
                        $db->query(
                            "INSERT INTO transactions (user_id, type, amount, balance_after, description, status) VALUES (?, 'recharge', ?, ?, ?, 'completed')",
                            [(int)$order['user_id'], $rechargeAmount, $balanceAfter, $desc]
                        );
                        $rechargeCredited = true;
                    }
                    $db->query("COMMIT");
                } catch (Throwable $e) {
                    $db->query("ROLLBACK");
                    error_log("[recharge] Atomic balance update failed for order " . $order['order_no'] . ": " . $e->getMessage());
                }
                if ($rechargeCredited) {
                    try {
                        $title = '余额充值成功';
                        $content = "您的充值已到账。\n订单号：" . (string)$order['order_no']
                            . "\n充值金额：" . $rechargeAmount . ' ' . (string)($order['currency'] ?? 'USDT')
                            . "\n到账时间：" . date('Y-m-d H:i:s');
                        NotificationDispatcher::notifyUser((int)$order['user_id'], [
                            'type' => 'balance',
                            'in_app_type' => 'balance',
                            'title' => $title,
                            'content' => $content,
                            'subject' => $title,
                            'dedupe_like' => (string)$order['order_no'],
                        ]);
                    } catch (Throwable $ignore) {
                    }
                }
            }
        }

        // Plan upgrade success handling
        require_once __DIR__ . '/../../../../src/Services/UpgradeOrderService.php';
        UpgradeOrderService::fulfillPlanFromOrder($db, $order);
        
        // --- Referral Commission Logic ---
        try {
            ReferralService::grantForOrder($db, (int)$order['id']);
        } catch (Exception $e) {
            // Log error but don't fail the order status update
            error_log("Referral Error: " . $e->getMessage());
        }
        // ---------------------------------
        
        // --- Unified Notification Logic ---
        try {
            $msg = "✅ <b>收款成功</b>\n\n";
            $msg .= "订单号: <code>" . $order['merchant_order_id'] . "</code>\n";
            $msg .= "金额: <b>" . number_format($order['amount'], 2) . " " . ($order['currency']??'USDT') . "</b>\n";
            $msg .= "网络: " . strtoupper($order['chain']) . "\n";
            $msg .= "时间: " . date('Y-m-d H:i:s') . "\n";
            $msg .= "哈希: <a href='" . CryptoService::getExplorerUrl($order['chain'], $tx['hash']) . "'>查看交易</a>";

            NotificationDispatcher::notifyUser((int)$order['user_id'], [
                'type' => 'order',
                'in_app_type' => 'order',
                'title' => '收款成功通知',
                'content' => "订单号：{$order['merchant_order_id']}\n金额：" . number_format($order['amount'], 2) . " " . ($order['currency'] ?? 'USDT') . "\n网络：" . strtoupper((string)$order['chain']) . "\n时间：" . date('Y-m-d H:i:s'),
                'subject' => '收款成功通知',
                'html' => $msg,
                'dedupe_like' => (string)($order['order_no'] ?? ''),
            ]);
        } catch (Exception $e) {
            error_log("Notification Error: " . $e->getMessage());
        }
        // ---------------------------------

        // Trigger Webhook
        require_once __DIR__ . '/../../../../src/Services/WebhookService.php';
        $order['status'] = 'paid';
        $order['tx_hash'] = $tx['hash'];
        WebhookService::send($order);
        StoreReceiptService::sendForOrder($order['id']);

        echo json_encode(['status'=>'paid','tx_hash'=>$tx['hash']]);
    } else {
        // Update sync time even if not found, to enforce throttle
        $db->query("UPDATE orders SET last_external_sync=NOW() WHERE id=?", [$order['id']]);
        echo json_encode(['status'=>'pending']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','error'=>'系统错误']);
}
