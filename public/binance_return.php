<?php
session_start();
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/BinancePayService.php';
require_once __DIR__ . '/../src/Services/UpgradeOrderService.php';
require_once __DIR__ . '/../src/Services/NotificationDispatcher.php';
require_once __DIR__ . '/../src/Services/ReferralService.php';

I18n::init();
$isEn = I18n::getLang() === 'en';
$tt = static function (string $zh, string $en) use ($isEn): string {
    return $isEn ? $en : $zh;
};

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$orderNo = trim((string)($_GET['order'] ?? ''));
$db = Database::getInstance();
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) {
    $cfg[$s['key_name']] = $s['value'];
}
$siteName = $cfg['site_name'] ?? 'UAPI';
$siteLogo = $cfg['site_logo'] ?? '';

$ok = false;
$msg = $tt('支付状态确认中，请稍后刷新。', 'Payment status is being confirmed. Please refresh later.');
$redirect = '/upgrade.php?payment_pending=1&order=' . urlencode($orderNo);
$source = '';

try {
    if ($orderNo === '') {
        throw new Exception($tt('缺少订单号。', 'Missing order number.'));
    }

    $order = $db->fetch("SELECT * FROM orders WHERE order_no = ? LIMIT 1", [$orderNo]);
    if (!$order) {
        throw new Exception($tt('订单不存在。', 'Order not found.'));
    }
    if ((int)$order['user_id'] !== (int)$_SESSION['user_id']) {
        throw new Exception($tt('无权访问该订单。', 'No access to this order.'));
    }
    $source = strtolower(trim((string)($order['source'] ?? '')));
    if ($source === 'recharge') {
        $redirect = '/balance.php?payment_pending=1&order=' . urlencode($orderNo);
    }

    $applyRechargeIfNeeded = static function ($dbConn, array $orderRow) use ($tt): void {
        $sourceInner = strtolower(trim((string)($orderRow['source'] ?? '')));
        if ($sourceInner !== 'recharge') {
            return;
        }
        $uid = (int)($orderRow['user_id'] ?? 0);
        if ($uid <= 0) {
            return;
        }
        $amount = (float)($orderRow['amount'] ?? 0);
        if ($amount <= 0) {
            return;
        }
        $orderNoInner = (string)($orderRow['order_no'] ?? '');
        $desc = $tt('Binance 余额充值', 'Binance balance recharge') . ' #' . $orderNoInner;
        $existsTx = $dbConn->fetch(
            "SELECT id FROM transactions WHERE user_id = ? AND type = 'recharge' AND description = ? LIMIT 1",
            [$uid, $desc]
        );
        if ($existsTx) {
            return;
        }

        $dbConn->query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, $uid]);
        $u = $dbConn->fetch("SELECT balance FROM users WHERE id = ? LIMIT 1", [$uid]);
        $balanceAfter = isset($u['balance']) ? (float)$u['balance'] : $amount;
        $dbConn->query(
            "INSERT INTO transactions (user_id, type, amount, balance_after, description, status) VALUES (?, 'recharge', ?, ?, ?, 'completed')",
            [$uid, $amount, $balanceAfter, $desc]
        );

        $title = $tt('余额充值成功', 'Balance Recharge Successful');
        $content = $tt(
            "您的币安充值已到账。\n订单号：{$orderNoInner}\n充值金额：{$amount} USDT\n到账时间：" . date('Y-m-d H:i:s'),
            "Your Binance recharge has been credited.\nOrder: {$orderNoInner}\nAmount: {$amount} USDT\nTime: " . date('Y-m-d H:i:s')
        );
        NotificationDispatcher::notifyUser($uid, [
            'type' => 'balance',
            'in_app_type' => 'balance',
            'title' => $title,
            'content' => $content,
            'subject' => $title,
            'dedupe_like' => $orderNoInner,
        ]);
    };

    if (strtolower((string)($order['status'] ?? '')) === 'paid') {
        $applyRechargeIfNeeded($db, $order);
        $ok = true;
        if ($source === 'recharge') {
            $msg = $tt('支付成功，余额已到账。', 'Payment successful. Balance has been credited.');
            $redirect = '/balance.php?payment_success=1&order=' . urlencode($orderNo);
        } else {
            $msg = $tt('支付成功，套餐已升级。', 'Payment successful, your plan has been upgraded.');
            $redirect = '/upgrade.php?payment_success=1&order=' . urlencode($orderNo);
        }
    } else {
        $bCfg = BinancePayService::loadConfig($db);
        $query = BinancePayService::queryOrder(
            $bCfg,
            $orderNo,
            trim((string)($order['tx_hash'] ?? ''))
        );
        $resp = $query['data'] ?? [];
        if (!BinancePayService::isSuccess($query)) {
            $err = trim((string)($resp['errorMessage'] ?? ''));
            if ($err !== '') {
                throw new Exception($err);
            }
            throw new Exception($tt('币安订单查询失败。', 'Binance order query failed.'));
        }

        $data = $resp['data'] ?? [];
        $orderStatus = strtoupper((string)($data['status'] ?? $data['orderStatus'] ?? ''));
        $transactionId = (string)($data['transactionId'] ?? $data['prepayId'] ?? $order['tx_hash'] ?? '');
        $paymentInfo = isset($data['paymentInfo']) && is_array($data['paymentInfo']) ? $data['paymentInfo'] : [];

        if (in_array($orderStatus, ['PAID', 'SUCCESS', 'COMPLETED'], true)) {
            $binancePayerUid = (string)($paymentInfo['payerId'] ?? $paymentInfo['payerBuid'] ?? $data['payerId'] ?? $data['payerBuid'] ?? '');
            $binanceOpenUserId = (string)($paymentInfo['openUserId'] ?? $paymentInfo['payerOpenId'] ?? $data['openUserId'] ?? $data['payerOpenId'] ?? '');
            $binanceMerchantId = (string)($paymentInfo['payeeId'] ?? $data['merchantId'] ?? '');

            UpgradeOrderService::markPaidAndFulfill(
                $db,
                (int)$order['id'],
                $transactionId,
                'binance_pay',
                (string)($data['currency'] ?? $order['currency'] ?? 'USDT'),
                [
                    'pay_provider' => 'binance',
                    'binance_pay_order_id' => (string)($data['prepayId'] ?? ''),
                    'binance_payer_uid' => $binancePayerUid,
                    'binance_open_user_id' => $binanceOpenUserId,
                    'binance_merchant_id' => $binanceMerchantId,
                ]
            );
            try {
                ReferralService::grantForOrder($db, (int)$order['id']);
            } catch (Throwable $ignore) {
            }
            $order = $db->fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [(int)$order['id']]);
            if ($order) {
                $applyRechargeIfNeeded($db, $order);
            }
            $ok = true;
            if ($source === 'recharge') {
                $msg = $tt('支付成功，余额已到账。', 'Payment successful. Balance has been credited.');
                $redirect = '/balance.php?payment_success=1&order=' . urlencode($orderNo);
            } else {
                $msg = $tt('支付成功，套餐已升级。', 'Payment successful, your plan has been upgraded.');
                $redirect = '/upgrade.php?payment_success=1&order=' . urlencode($orderNo);
            }
        } else {
            $msg = $tt('订单尚未完成支付，请完成扫码后返回。', 'Order is not paid yet. Please complete payment and return.');
            $redirect = $source === 'recharge'
                ? '/balance.php?payment_pending=1&order=' . urlencode($orderNo)
                : '/upgrade.php?payment_pending=1&order=' . urlencode($orderNo);
            throw new Exception($msg);
        }
    }
} catch (Throwable $e) {
    if ($msg === '') {
        $msg = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $isEn ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tt('币安支付结果', 'Binance Pay Result')); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="mx-auto card shadow-sm border-0" style="max-width:560px;border-radius:16px;">
        <div class="card-body p-4 p-md-5 text-center">
            <?php if (!empty($siteLogo)): ?>
                <img src="<?php echo htmlspecialchars($siteLogo); ?>" alt="logo" style="height:34px;width:auto;" class="mb-3">
            <?php endif; ?>
            <h4 class="fw-bold mb-3 <?php echo $ok ? 'text-success' : 'text-warning'; ?>">
                <?php echo htmlspecialchars($ok ? $tt('支付成功', 'Payment Success') : $tt('等待支付确认', 'Waiting for Confirmation')); ?>
            </h4>
            <p class="text-muted mb-4"><?php echo htmlspecialchars($msg); ?></p>
            <a href="<?php echo htmlspecialchars($redirect); ?>" class="btn btn-primary px-4">
                <?php echo htmlspecialchars($source === 'recharge' ? $tt('返回余额页面', 'Back to Balance') : $tt('返回套餐页面', 'Back to Upgrade')); ?>
            </a>
        </div>
    </div>
</div>
<?php if ($ok): ?>
<script>
setTimeout(function () {
    window.location.href = <?php echo json_encode($redirect); ?>;
}, 1400);
</script>
<?php endif; ?>
</body>
</html>
