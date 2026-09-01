<?php
// public/api/v1/order/check_hash.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/CryptoService.php';
require_once __DIR__ . '/../../../../src/Services/StoreReceiptService.php';
require_once __DIR__ . '/../../../../src/Services/SecurityService.php';
require_once __DIR__ . '/../../../../src/Services/StoreCouponService.php';
require_once __DIR__ . '/../../../../src/Services/CouponService.php';
require_once __DIR__ . '/../../../../src/Services/NotificationDispatcher.php';
require_once __DIR__ . '/../../../../src/Services/ReferralService.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (empty($input['order_no']) || empty($input['hash'])) {
        throw new Exception('缺少参数');
    }

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

    if ($reason = $sec->checkBlocked($ip)) {
        throw new Exception('IP Blocked: ' . $reason);
    }
    if (!$sec->checkRateLimit($ip, 'check_hash.php', 15, 60)) {
        throw new Exception('请求过于频繁，请稍后重试');
    }

    $order = $db->fetch("SELECT o.*, w.address as wallet_address 
        FROM orders o 
        LEFT JOIN wallets w ON o.wallet_id = w.id 
        WHERE o.order_no = ?", [$input['order_no']]);

    if (!$order) {
        throw new Exception('订单不存在');
    }

    // Non-admin must provide valid checkout token
    if (!$is_admin) {
        $request_token = trim((string)($input['token'] ?? ''));
        $stored_token = (string)($order['pay_access_token'] ?? '');
        if ($stored_token === '' || $request_token === '' || !hash_equals($stored_token, $request_token)) {
            throw new Exception('链接无效或已失效');
        }
    }

    // Per-order hash-check burst guard
    $perOrderCount = $db->fetch(
        "SELECT COUNT(*) as c
         FROM api_logs
         WHERE endpoint = ? AND created_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)",
        ['check_hash.order.' . $input['order_no']]
    );
    if ((int)($perOrderCount['c'] ?? 0) >= 8) {
        throw new Exception('查询过于频繁，请稍后重试');
    }
    $sec->logRequest($order['user_id'] ?? null, 'check_hash.order.' . $input['order_no'], 'POST', $order['chain'] ?? '', $ip);

    // Reject expired orders
    if ($order['status'] === 'pending' && !empty($order['expire_at'])) {
        $expTs = strtotime($order['expire_at']);
        if ($expTs !== false && $expTs <= time()) {
            throw new Exception('订单已过期');
        }
    }

    // Security: Check Referer Binding
    // If request comes from an external site, ensure that site is bound to the order's user.
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    if (!empty($referer)) {
        $parsed = parse_url($referer);
        $ref_host = $parsed['host'] ?? '';
        $current_host = $_SERVER['HTTP_HOST'] ?? '';
        
        // Remove ports if any
        $ref_host = explode(':', $ref_host)[0];
        $current_host = explode(':', $current_host)[0];
        
        // If referer is NOT self, check binding
        if ($ref_host && $ref_host !== $current_host && $ref_host !== 'localhost') {
            // Clean www.
            $clean_host = preg_replace('/^www\./', '', $ref_host);
            
            $bound = $db->fetch("SELECT id FROM websites WHERE user_id = ? AND (domain = ? OR domain = ?) AND status = 'active'", [
                $order['user_id'], $clean_host, 'www.'.$clean_host
            ]);
            
            if (!$bound) {
                 throw new Exception("Access Denied: Domain '$ref_host' is not bound to this merchant.");
            }
        }
    }

    if ($order['status'] === 'paid') {
        StoreCouponService::applyOnPaid($db, (int)$order['id']);
        StoreReceiptService::sendForOrder($order['id']);
        echo json_encode(['status' => 'success', 'message' => '订单已支付']);
        exit;
    }

    // Call CryptoService to verify hash
    // We need to implement verifyTransaction method in CryptoService or use existing check logic
    // For now, let's reuse CryptoService
    
    // Config
    $settings = $db->fetchAll("SELECT * FROM system_settings");
    $cfg = [];
    foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
    
    CryptoService::setExternalUsageContext([
        'user_id' => (int)($order['user_id'] ?? 0),
        'order_id' => (int)($order['id'] ?? 0),
        'order_no' => (string)($order['order_no'] ?? ''),
        'chain' => strtolower((string)($order['chain'] ?? '')),
        'source' => 'check_hash',
        'trigger_mode' => !empty($order['is_fast_sync']) ? 'fast_sync' : 'manual',
    ]);
    try {
        // Check specific hash using Service (supports TRC20 and EVM)
        // The service method returns the amount if valid, false otherwise.
        $valid_amount = CryptoService::verifyHash($order['chain'], $input['hash'], $order['wallet_address'], $order['amount'], strtotime($order['created_at']));
    } finally {
        CryptoService::clearExternalUsageContext();
    }
    
    if ($valid_amount !== false) {
        // Prevent tx_hash reuse across orders
        $hashInUse = $db->fetch(
            "SELECT id FROM orders WHERE tx_hash = ? AND status = 'paid' AND id != ? LIMIT 1",
            [$input['hash'], $order['id']]
        );
        if ($hashInUse) {
            throw new Exception('该交易哈希已被其他订单使用');
        }

        // Mark as paid
        $updated = $db->query("UPDATE orders SET status = 'paid', tx_hash = ?, updated_at = NOW() WHERE id = ? AND status IN ('pending', 'expired')", [
            $input['hash'], $order['id']
        ]);
        if ($updated->rowCount() === 0) {
            StoreCouponService::applyOnPaid($db, (int)$order['id']);
            echo json_encode(['status' => 'success', 'message' => '订单已支付']);
            exit;
        }
        StoreCouponService::applyOnPaid($db, (int)$order['id']);

        // Update admin coupon usage count
        CouponService::countAdminRedemption($db, $order);

        // Balance recharge fulfillment — atomic, prevents double-credit
        if (strtolower((string)($order['source'] ?? '')) === 'recharge') {
            $rechargeAmount = (float)($order['amount'] ?? 0);
            if ($rechargeAmount > 0) {
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
                    }
                    $db->query("COMMIT");
                } catch (Throwable $e) {
                    $db->query("ROLLBACK");
                    error_log("[recharge] Atomic balance update failed for order " . $order['order_no'] . ": " . $e->getMessage());
                }
            }
        }

        // Plan upgrade handling
        require_once __DIR__ . '/../../../../src/Services/UpgradeOrderService.php';
        UpgradeOrderService::fulfillPlanFromOrder($db, $order);

        // Referral commission
        try {
            ReferralService::grantForOrder($db, (int)$order['id']);
        } catch (Throwable $ignore) {}

        // Payment success notification
        try {
            NotificationDispatcher::notifyUser((int)$order['user_id'], [
                'type' => 'order',
                'in_app_type' => 'order',
                'title' => '收款成功通知',
                'content' => "订单号：{$order['merchant_order_id']}\n金额：" . number_format((float)$order['amount'], 2) . ' ' . ($order['currency'] ?? 'USDT') . "\n网络：" . strtoupper((string)$order['chain']) . "\n时间：" . date('Y-m-d H:i:s'),
                'subject' => '收款成功通知',
                'dedupe_like' => (string)$order['order_no'],
            ]);
        } catch (Throwable $ignore) {}

        // Trigger Webhook
        require_once __DIR__ . '/../../../../src/Services/WebhookService.php';
        $order['status'] = 'paid';
        $order['tx_hash'] = $input['hash'];
        WebhookService::send($order);
        StoreReceiptService::sendForOrder($order['id']);

        echo json_encode(['status' => 'success']);
    } else {
        throw new Exception('验证失败：交易未找到、未确认或金额/地址不匹配');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
