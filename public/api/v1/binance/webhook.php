<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/BinancePayService.php';
require_once __DIR__ . '/../../../../src/Services/UpgradeOrderService.php';
require_once __DIR__ . '/../../../../src/Services/NotificationDispatcher.php';
require_once __DIR__ . '/../../../../src/Services/ReferralService.php';

$rawBody = (string)file_get_contents('php://input');
$payload = json_decode($rawBody, true);
$logId = 0;
$orderNoForLog = '';
$verifyStatus = 'pending';
$processStatus = 'pending';
$eventType = '';
$logError = '';
$responseCode = 200;
$responseBody = ['returnCode' => 'SUCCESS', 'returnMessage' => null];

try {
    if (!is_array($payload)) {
        throw new Exception('Invalid webhook payload');
    }

    $db = Database::getInstance();
    $db->query("CREATE TABLE IF NOT EXISTS `binance_webhook_logs` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `order_no` varchar(64) DEFAULT NULL,
        `event_type` varchar(64) DEFAULT NULL,
        `verify_status` varchar(20) DEFAULT 'pending',
        `process_status` varchar(20) DEFAULT 'pending',
        `error_message` text,
        `request_headers` text,
        `request_body` longtext,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_order_no` (`order_no`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->query("CREATE TABLE IF NOT EXISTS `binance_pay_links` (
        `id` INT NOT NULL AUTO_INCREMENT,
        `merchant_trade_no` VARCHAR(64) NOT NULL,
        `title` VARCHAR(255) DEFAULT NULL,
        `description` VARCHAR(500) DEFAULT NULL,
        `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
        `currency` VARCHAR(16) NOT NULL DEFAULT 'USDT',
        `checkout_url` TEXT,
        `qr_url` TEXT,
        `source` VARCHAR(30) NOT NULL DEFAULT 'payment_link',
        `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
        `binance_prepay_id` VARCHAR(120) DEFAULT NULL,
        `paid_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_trade_no` (`merchant_trade_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $cfg = BinancePayService::loadConfig($db);

    $hdr = BinancePayService::extractBinanceHeaders();
    $db->query(
        "INSERT INTO binance_webhook_logs (order_no, event_type, verify_status, process_status, request_headers, request_body, created_at)
         VALUES (?, ?, 'pending', 'pending', ?, ?, NOW())",
        [
            null,
            (string)($payload['bizType'] ?? $payload['bizStatus'] ?? ''),
            json_encode($hdr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $rawBody
        ]
    );
    $logId = (int)$db->lastInsertId();

    // Reject stale or future-dated webhooks (5-minute window)
    $tsMs = (string)($hdr['timestamp'] ?? '');
    if ($tsMs !== '' && is_numeric($tsMs)) {
        $tsAge = abs(time() - intdiv((int)$tsMs, 1000));
        if ($tsAge > 300) {
            throw new Exception('Webhook timestamp out of acceptable window');
        }
    }

    $publicKey = BinancePayService::resolveWebhookPublicKey($db, $cfg, (string)($hdr['serial'] ?? ''));
    if (!BinancePayService::verifyWebhookSignature($hdr, $rawBody, $publicKey)) {
        throw new Exception('Webhook signature verification failed');
    }
    $verifyStatus = 'verified';

    $data = [];
    if (isset($payload['data']) && is_array($payload['data'])) {
        $data = $payload['data'];
    } elseif (isset($payload['data']) && is_string($payload['data'])) {
        $decoded = json_decode((string)$payload['data'], true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    } else {
        $data = $payload;
    }
    $orderNo = trim((string)($data['merchantTradeNo'] ?? $data['merchantOrderNo'] ?? ''));
    $orderNoForLog = $orderNo;
    $bizType = strtoupper(trim((string)($payload['bizType'] ?? '')));
    $bizStatus = strtoupper(trim((string)($payload['bizStatus'] ?? $data['bizStatus'] ?? '')));
    $eventType = $bizStatus !== '' ? $bizStatus : ($bizType !== '' ? $bizType : 'PAY');
    $eventUpper = strtoupper($eventType);
    $isRefundEvent = (strpos($eventUpper, 'REFUND') !== false);
    $isPaySuccessEvent = in_array($eventUpper, ['PAY', 'PAY_SUCCESS', 'TRADE', 'TRADE_SUCCESS', 'SUCCESS', 'COMPLETED'], true);
    if ($orderNo === '') {
        throw new Exception('Missing merchantTradeNo');
    }

    $order = $db->fetch("SELECT * FROM orders WHERE order_no = ? LIMIT 1", [$orderNo]);
    if (!$order) {
        $link = $db->fetch("SELECT * FROM binance_pay_links WHERE merchant_trade_no = ? LIMIT 1", [$orderNo]);
        if ($link) {
            $db->query(
                "INSERT INTO orders (
                    order_no, merchant_order_id, user_id, wallet_id, amount, currency, chain, status, pay_provider, source, order_origin,
                    tx_hash, binance_pay_order_id, created_at, updated_at
                ) VALUES (?, ?, 0, NULL, ?, ?, 'binance_pay', 'pending', 'binance', 'payment_link', 'merchant_order', ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency), updated_at = NOW()",
                [
                    (string)$link['merchant_trade_no'],
                    'PLINK-' . (string)$link['merchant_trade_no'],
                    (float)($link['amount'] ?? 0),
                    (string)($link['currency'] ?? 'USDT'),
                    (string)($link['binance_prepay_id'] ?? ''),
                    (string)($link['binance_prepay_id'] ?? '')
                ]
            );
            $order = $db->fetch("SELECT * FROM orders WHERE order_no = ? LIMIT 1", [$orderNo]);
        }
    }
    if (!$order) {
        $processStatus = 'ignored';
        $responseBody = ['returnCode' => 'SUCCESS', 'returnMessage' => null];
        throw new RuntimeException('__done__');
    }

    $applyRechargeIfNeeded = static function ($dbConn, array $orderRow): bool {
        $sourceInner = strtolower(trim((string)($orderRow['source'] ?? '')));
        if ($sourceInner !== 'recharge') {
            return false;
        }
        $uid = (int)($orderRow['user_id'] ?? 0);
        if ($uid <= 0) {
            return false;
        }
        $amount = (float)($orderRow['amount'] ?? 0);
        if ($amount <= 0) {
            return false;
        }
        $orderNoInner = (string)($orderRow['order_no'] ?? '');
        $desc = 'Binance 余额充值 #' . $orderNoInner;

        // Atomic check-then-insert via transaction + advisory lock to prevent double-credit
        $dbConn->query("START TRANSACTION");
        try {
            $existsTx = $dbConn->fetch(
                "SELECT id FROM transactions WHERE user_id = ? AND type = 'recharge' AND description = ? LIMIT 1 FOR UPDATE",
                [$uid, $desc]
            );
            if ($existsTx) {
                $dbConn->query("COMMIT");
                return false;
            }

            $dbConn->query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, $uid]);
            $u = $dbConn->fetch("SELECT balance FROM users WHERE id = ? LIMIT 1", [$uid]);
            $balanceAfter = isset($u['balance']) ? (float)$u['balance'] : $amount;
            $dbConn->query(
                "INSERT INTO transactions (user_id, type, amount, balance_after, description, status) VALUES (?, 'recharge', ?, ?, ?, 'completed')",
                [$uid, $amount, $balanceAfter, $desc]
            );
            $dbConn->query("COMMIT");
        } catch (Throwable $e) {
            $dbConn->query("ROLLBACK");
            throw $e;
        }

        $title = '余额充值成功';
        $content = "您的币安充值已到账。\n订单号：{$orderNoInner}\n充值金额：{$amount} USDT\n到账时间：" . date('Y-m-d H:i:s');
        NotificationDispatcher::notifyUser($uid, [
            'type' => 'balance',
            'in_app_type' => 'balance',
            'title' => $title,
            'content' => $content,
            'subject' => $title,
            'dedupe_like' => $orderNoInner,
        ]);
        return true;
    };

    if (!$isRefundEvent && strtolower((string)($order['status'] ?? '')) === 'paid') {
        $applyRechargeIfNeeded($db, $order);
        $processStatus = 'already_paid';
        $responseBody = ['returnCode' => 'SUCCESS', 'returnMessage' => null];
        throw new RuntimeException('__done__');
    }

    if ($isRefundEvent) {
        $refundAmount = 0.0;
        $refundInfo = (isset($data['refundInfo']) && is_array($data['refundInfo'])) ? $data['refundInfo'] : [];
        $rawRefundAmount = $data['refundAmount']
            ?? ($refundInfo['refundAmount'] ?? null)
            ?? $data['refundMoney']
            ?? $data['amount']
            ?? null;
        if ($rawRefundAmount !== null && $rawRefundAmount !== '') {
            $refundAmount = (float)$rawRefundAmount;
        }
        if ($refundAmount <= 0) {
            $processStatus = 'ignored';
            $responseBody = ['returnCode' => 'SUCCESS', 'returnMessage' => null];
            throw new RuntimeException('__done__');
        }
        $refundRequestId = (string)($data['refundRequestId'] ?? $data['refundId'] ?? '');
        $refundReason = (string)($data['refundReason'] ?? '');
        UpgradeOrderService::applyRefund(
            $db,
            (int)$order['id'],
            $refundAmount,
            $refundRequestId,
            $refundReason
        );
        $db->query(
            "UPDATE binance_pay_links
             SET status = CASE WHEN ? >= amount THEN 'refunded' ELSE 'partial_refunded' END,
                 updated_at = NOW()
             WHERE merchant_trade_no = ?",
            [$refundAmount, $orderNo]
        );
        $processStatus = 'processed';
        $responseBody = ['returnCode' => 'SUCCESS', 'returnMessage' => null];
        throw new RuntimeException('__done__');
    }

    if (!$isPaySuccessEvent) {
        $processStatus = 'ignored';
        $responseBody = ['returnCode' => 'SUCCESS', 'returnMessage' => null];
        throw new RuntimeException('__done__');
    }

    $query = BinancePayService::queryOrder(
        $cfg,
        $orderNo,
        trim((string)($order['binance_pay_order_id'] ?? ''))
    );

    if (!BinancePayService::isSuccess($query)) {
        $resp = $query['data'] ?? [];
        $msg = (string)($resp['errorMessage'] ?? 'Binance query failed');
        throw new Exception($msg);
    }

    $qData = $query['data']['data'] ?? [];
    $status = strtoupper((string)($qData['status'] ?? $qData['orderStatus'] ?? ''));
    if (!in_array($status, ['PAID', 'SUCCESS', 'COMPLETED'], true)) {
        $processStatus = 'ignored';
        $responseBody = ['returnCode' => 'SUCCESS', 'returnMessage' => null];
        throw new RuntimeException('__done__');
    }

    $txHash = (string)($qData['transactionId'] ?? $qData['prepayId'] ?? $order['tx_hash'] ?? '');
    $paymentInfo = isset($qData['paymentInfo']) && is_array($qData['paymentInfo']) ? $qData['paymentInfo'] : [];
    $binancePayerUid = (string)($paymentInfo['payerId'] ?? $paymentInfo['payerBuid'] ?? $qData['payerId'] ?? $qData['payerBuid'] ?? '');
    $binanceOpenUserId = (string)($paymentInfo['openUserId'] ?? $paymentInfo['payerOpenId'] ?? $qData['openUserId'] ?? $qData['payerOpenId'] ?? '');
    $binanceMerchantId = (string)($paymentInfo['payeeId'] ?? $qData['merchantId'] ?? '');

    $paidResult = UpgradeOrderService::markPaidAndFulfill(
        $db,
        (int)$order['id'],
        $txHash,
        'binance_pay',
        (string)($qData['currency'] ?? $order['currency'] ?? 'USDT'),
        [
            'pay_provider' => 'binance',
            'binance_pay_order_id' => (string)($qData['prepayId'] ?? ''),
            'binance_payer_uid' => $binancePayerUid,
            'binance_open_user_id' => $binanceOpenUserId,
            'binance_merchant_id' => $binanceMerchantId,
        ]
    );
    try {
        ReferralService::grantForOrder($db, (int)$order['id']);
    } catch (Throwable $ignore) {
    }
    $paidOrder = $paidResult['order'] ?? null;
    if (is_array($paidOrder)) {
        $applyRechargeIfNeeded($db, $paidOrder);
    } else {
        $applyRechargeIfNeeded($db, $order);
    }
    $db->query(
        "UPDATE binance_pay_links
         SET status = 'paid',
             paid_at = CASE WHEN paid_at IS NULL THEN NOW() ELSE paid_at END,
             binance_prepay_id = CASE WHEN ? <> '' THEN ? ELSE binance_prepay_id END,
             updated_at = NOW()
         WHERE merchant_trade_no = ?",
        [
            (string)($qData['prepayId'] ?? ''), (string)($qData['prepayId'] ?? ''),
            $orderNo
        ]
    );
    $processStatus = 'processed';
    $responseBody = ['returnCode' => 'SUCCESS', 'returnMessage' => null];
} catch (Throwable $e) {
    if ($e instanceof RuntimeException && $e->getMessage() === '__done__') {
        // Normal early completion path.
    } else {
        $logError = $e->getMessage();
        $responseCode = 400;
        $responseBody = ['returnCode' => 'FAIL', 'returnMessage' => $e->getMessage()];
    }
} finally {
    try {
        if (!isset($db)) {
            $db = Database::getInstance();
        }
        if ($logId > 0) {
            $db->query(
                "UPDATE binance_webhook_logs
                 SET order_no = COALESCE(?, order_no),
                     event_type = COALESCE(?, event_type),
                     verify_status = ?,
                     process_status = ?,
                     error_message = ?
                 WHERE id = ?",
                [
                    $orderNoForLog !== '' ? $orderNoForLog : null,
                    $eventType !== '' ? $eventType : null,
                    $verifyStatus,
                    $processStatus,
                    $logError !== '' ? $logError : null,
                    $logId
                ]
            );
        }
    } catch (Throwable $ignore) {
    }
}
http_response_code($responseCode);
echo json_encode($responseBody);
