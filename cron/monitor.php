<?php
// cron/monitor.php
ini_set('memory_limit', '256M');
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Services/CryptoService.php';
require_once __DIR__ . '/../src/Services/WebhookService.php';
require_once __DIR__ . '/../src/Services/StoreCouponService.php';
require_once __DIR__ . '/../src/Services/CouponService.php';
require_once __DIR__ . '/../src/Services/StoreReceiptService.php';
require_once __DIR__ . '/../src/Services/NotificationDispatcher.php';
require_once __DIR__ . '/../src/Services/ReferralService.php';

// 防止重复运行
$lock_file = sys_get_temp_dir() . '/uapi_monitor.lock';
$fp = fopen($lock_file, "w+");
if (!flock($fp, LOCK_EX | LOCK_NB)) {
    die("Already running...\n");
}

$db = Database::getInstance();

// Record heartbeat for monitor cron
try {
    $db->query(
        "INSERT INTO cron_heartbeats (job_name, last_run_at, run_count, last_status, last_message)
         VALUES ('monitor', NOW(), 1, 'ok', 'started')
         ON DUPLICATE KEY UPDATE
             last_run_at = NOW(),
             run_count = run_count + 1,
             last_status = 'ok',
             last_message = 'started'"
    );
} catch (Throwable $e) {}

// Check cleanup cron heartbeat — alert if not run in 2 hours (throttled to once per hour)
try {
    $cleanupHb  = $db->fetch("SELECT last_run_at FROM cron_heartbeats WHERE job_name = 'cleanup'");
    $alertHb    = $db->fetch("SELECT last_run_at FROM cron_heartbeats WHERE job_name = 'cleanup_alert'");
    $isStale    = !$cleanupHb || strtotime((string)($cleanupHb['last_run_at'] ?? '0')) < time() - 7200;
    $recentSent = $alertHb && strtotime((string)($alertHb['last_run_at'] ?? '0')) > time() - 3600;
    if ($isStale && !$recentSent) {
        require_once __DIR__ . '/../src/Telegram.php';
        Telegram::send("⚠️ <b>[系统告警]</b> cleanup.php Cron 任务超过 2 小时未执行，请检查计划任务配置！");
        // Record alert timestamp to throttle repeats (max once per hour)
        $db->query(
            "INSERT INTO cron_heartbeats (job_name, last_run_at, run_count, last_status, last_message)
             VALUES ('cleanup_alert', NOW(), 1, 'sent', 'alert sent')
             ON DUPLICATE KEY UPDATE last_run_at=NOW(), run_count=run_count+1, last_status='sent', last_message='alert sent'"
        );
    }
} catch (Throwable $e) {}

echo "[".date('Y-m-d H:i:s')."] Starting Monitor...\n";

// 1. 获取所有 Pending 订单，并按 链+地址 分组
// 只取 1 小时内的订单，且跳过 50 秒内已被 status.php 轮询过的（避免重复打外部 API）
$sql = "
    SELECT o.*, w.address, w.chain as wallet_chain, u.webhook_url
    FROM orders o
    JOIN wallets w ON o.wallet_id = w.id
    JOIN users u ON o.user_id = u.id
    WHERE o.status IN ('pending', 'expired')
    AND o.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
    AND (o.last_external_sync IS NULL OR o.last_external_sync < DATE_SUB(NOW(), INTERVAL 50 SECOND))
    ORDER BY o.id DESC
";
$orders = $db->fetchAll($sql);

if (empty($orders)) {
    echo "No pending orders.\n";
    exit;
}

// 2. 按地址分组，避免重复请求 API
$tasks = [];
foreach ($orders as $order) {
    $key = $order['wallet_chain'] . '|' . $order['address'];
    if (!isset($tasks[$key])) {
        $tasks[$key] = [];
    }
    $tasks[$key][] = $order;
}

// 3. 执行检查
foreach ($tasks as $key => $grouped_orders) {
    list($chain, $address) = explode('|', $key);
    
    // 取这一组订单中最早的时间作为查询起点，减少漏单
    $min_time = time();
    foreach ($grouped_orders as $o) {
        $ts = strtotime($o['created_at']);
        if ($ts < $min_time) $min_time = $ts;
    }
    // 稍微提前一点 buffer
    $min_time -= 300; 

    echo "Checking {$chain} -> {$address} (Orders: ".count($grouped_orders).")\n";
    
    $result = null;
    if ($chain === 'trc20') {
        // TRC20 检查逻辑需要遍历所有订单金额
        // 为了简化 API 调用，CryptoService 应该返回最近的交易列表，我们在外部匹配
        // 这里简化演示：针对每个订单单独检查 (生产环境应重构 CryptoService 为批量获取)
        foreach ($grouped_orders as $order) {
            CryptoService::setExternalUsageContext([
                'user_id' => (int)($order['user_id'] ?? 0),
                'order_id' => (int)($order['id'] ?? 0),
                'order_no' => (string)($order['order_no'] ?? ''),
                'chain' => strtolower((string)($order['chain'] ?? 'trc20')),
                'source' => 'cron_monitor',
                'trigger_mode' => !empty($order['is_fast_sync']) ? 'fast_sync' : 'normal',
            ]);
            try {
                $tx = CryptoService::checkTrc20($address, floatval($order['amount']), strtotime($order['created_at']));
            } finally {
                CryptoService::clearExternalUsageContext();
            }
            if ($tx) processPayment($db, $order, $tx);
        }
    } else {
        // EVM 逻辑同上
        foreach ($grouped_orders as $order) {
            CryptoService::setExternalUsageContext([
                'user_id' => (int)($order['user_id'] ?? 0),
                'order_id' => (int)($order['id'] ?? 0),
                'order_no' => (string)($order['order_no'] ?? ''),
                'chain' => strtolower((string)($order['chain'] ?? $chain)),
                'source' => 'cron_monitor',
                'trigger_mode' => !empty($order['is_fast_sync']) ? 'fast_sync' : 'normal',
            ]);
            try {
                $tx = CryptoService::checkEvm($chain, $address, floatval($order['amount']), strtotime($order['created_at']), strtoupper((string)($order['currency'] ?? 'USDT')));
            } finally {
                CryptoService::clearExternalUsageContext();
            }
            if ($tx) processPayment($db, $order, $tx);
        }
    }
    
    // 限速
    usleep(500000); // 0.5s
}

// 4. 支付成功处理函数
function processPayment($db, $order, $tx) {
    echo "  -> PAID: Order #{$order['order_no']} | Hash: {$tx['hash']}\n";

    // Prevent tx_hash reuse across paid orders
    $exist = $db->fetch("SELECT id FROM orders WHERE tx_hash = ? AND status = 'paid' LIMIT 1", [$tx['hash']]);
    if ($exist) {
        echo "  -> Skip: Hash already used by a paid order.\n";
        return;
    }

    // Atomic status guard: only proceed if order is still pending or expired
    $updated = $db->query(
        "UPDATE orders SET status = 'paid', tx_hash = ?, pay_provider = 'crypto', paid_at = NOW(), updated_at = NOW() WHERE id = ? AND status IN ('pending', 'expired')",
        [$tx['hash'], $order['id']]
    );
    if ($updated->rowCount() === 0) {
        echo "  -> Skip: Order already processed or in a final state.\n";
        return;
    }

    // Store coupon fulfillment
    StoreCouponService::applyOnPaid($db, (int)$order['id']);

    // Admin coupon usage count
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
    require_once __DIR__ . '/../src/Services/UpgradeOrderService.php';
    UpgradeOrderService::fulfillPlanFromOrder($db, $order);

    // Referral commission
    try {
        ReferralService::grantForOrder($db, (int)$order['id']);
    } catch (Throwable $ignore) {}

    // User notification
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

    // Send signed webhook via WebhookService
    $order['status'] = 'paid';
    $order['tx_hash'] = $tx['hash'];
    WebhookService::send($order);
    StoreReceiptService::sendForOrder($order['id']);
}

// Update monitor heartbeat status to completed
try {
    $db->query(
        "UPDATE cron_heartbeats SET last_status = 'ok', last_message = 'completed' WHERE job_name = 'monitor'"
    );
} catch (Throwable $e) {}

flock($fp, LOCK_UN);
fclose($fp);
