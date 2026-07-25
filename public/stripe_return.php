<?php
session_start();
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/StripeService.php';
require_once __DIR__ . '/../src/Services/NotificationDispatcher.php';
require_once __DIR__ . '/../src/Services/ReferralService.php';

I18n::init();
$is_en = I18n::getLang() === 'en';
$tt = static function (string $zh, string $en) use ($is_en): string {
    return $is_en ? $en : $zh;
};

if (!isset($_SESSION['user_id'])) {
    header("Location: /login.php");
    exit;
}

$order_no = trim((string)($_GET['order'] ?? ''));
$session_id = trim((string)($_GET['session_id'] ?? ''));

$db = Database::getInstance();
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) {
    $cfg[$s['key_name']] = $s['value'];
}
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';

$ok = false;
$msg = $tt('支付验证失败，请联系管理员处理。', 'Payment verification failed. Please contact support.');
$redirect = '/upgrade.php';

try {
    if ($order_no === '' || $session_id === '') {
        throw new Exception($tt('缺少订单参数。', 'Missing order parameters.'));
    }

    $order = $db->fetch("SELECT * FROM orders WHERE order_no = ? LIMIT 1", [$order_no]);
    if (!$order) {
        throw new Exception($tt('订单不存在。', 'Order not found.'));
    }
    if ((int)$order['user_id'] !== (int)$_SESSION['user_id']) {
        throw new Exception($tt('无权访问该订单。', 'You do not have access to this order.'));
    }

    $source = strtolower(trim((string)($order['source'] ?? '')));
    if ($source === 'recharge') {
        $redirect = '/balance.php';
    }

    $session = StripeService::getCheckoutSession($session_id);
    $ref = (string)($session['client_reference_id'] ?? '');
    if ($ref !== $order_no) {
        throw new Exception($tt('Stripe 回调订单号不匹配。', 'Stripe session order mismatch.'));
    }

    $paymentStatus = strtolower((string)($session['payment_status'] ?? ''));
    $status = strtolower((string)($session['status'] ?? ''));
    if ($paymentStatus !== 'paid' || $status !== 'complete') {
        $msg = $tt('支付尚未完成，请稍后刷新套餐页面查看。', 'Payment is not complete yet. Please refresh the upgrade page later.');
        $redirect = '/upgrade.php?payment_pending=1&order=' . urlencode($order_no);
        throw new Exception($msg);
    }

    $txHash = (string)($session['payment_intent'] ?? $session_id);
    $updated = $db->query(
        "UPDATE orders SET status='paid', pay_provider='stripe', chain='stripe', currency='USD', tx_hash=?, paid_at=NOW(), updated_at=NOW() WHERE id=? AND status='pending'",
        [$txHash, (int)$order['id']]
    );
    try {
        ReferralService::grantForOrder($db, (int)$order['id']);
    } catch (Throwable $ignore) {
    }
    if ($updated->rowCount() > 0) {
        $couponCode = strtoupper(trim((string)($order['coupon_code'] ?? '')));
        if ($couponCode !== '') {
            $db->query("UPDATE admin_coupons SET used_count = used_count + 1 WHERE code = ? AND status = 'active'", [$couponCode]);
        }
    }

    // Balance recharge fulfillment (idempotent)
    if ($source === 'recharge') {
        if ($updated->rowCount() > 0) {
            $amount = (float)($order['amount'] ?? 0);
            if ($amount <= 0) {
                throw new Exception($tt('充值金额无效。', 'Invalid recharge amount.'));
            }

            $db->query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, (int)$order['user_id']]);
            $u = $db->fetch("SELECT balance FROM users WHERE id = ? LIMIT 1", [(int)$order['user_id']]);
            $balanceAfter = isset($u['balance']) ? (float)$u['balance'] : $amount;

            $desc = $tt('Stripe 余额充值', 'Stripe balance recharge') . ' #' . (string)$order_no;
            $db->query(
                "INSERT INTO transactions (user_id, type, amount, balance_after, description, status) VALUES (?, 'recharge', ?, ?, ?, 'completed')",
                [(int)$order['user_id'], $amount, $balanceAfter, $desc]
            );

            $title = $tt('余额充值成功', 'Balance Recharge Successful');
            $content = $tt(
                "您的 Stripe 充值已到账。\n订单号：{$order_no}\n充值金额：{$amount} USD\n到账时间：" . date('Y-m-d H:i:s'),
                "Your Stripe recharge has been credited.\nOrder: {$order_no}\nAmount: {$amount} USD\nTime: " . date('Y-m-d H:i:s')
            );
            NotificationDispatcher::notifyUser((int)$order['user_id'], [
                'type' => 'balance',
                'in_app_type' => 'balance',
                'title' => $title,
                'content' => $content,
                'subject' => $title,
                'dedupe_like' => (string)$order_no,
            ]);
        }

        $ok = true;
        $msg = $tt('支付成功，余额已到账。正在返回资产页面...', 'Payment successful. Balance has been credited. Redirecting...');
        $redirect = '/balance.php?payment_success=1&order=' . urlencode($order_no);
    }

    // Plan upgrade fulfillment (idempotent)
    if ($source !== 'recharge' && strpos((string)($order['merchant_order_id'] ?? ''), 'PLAN-') === 0) {
        $parts = explode('-', (string)$order['merchant_order_id']);
        $plan_id = (int)($parts[1] ?? 0);
        $cycle = strtolower((string)($parts[2] ?? 'monthly'));
        if ($plan_id > 0) {
            $plan = $db->fetch("SELECT * FROM plans WHERE id = ? LIMIT 1", [$plan_id]);
            if ($plan) {
                $duration = '+1 month';
                if ($cycle === 'yearly') {
                    $duration = '+1 year';
                } elseif ($cycle === 'quarterly') {
                    $duration = '+3 months';
                }

                $fastSyncGrant = max(0, (int)($plan['fast_sync_limit'] ?? 0));
                $uRow = $db->fetch("SELECT plan_id, expire_at FROM users WHERE id = ? LIMIT 1", [(int)$order['user_id']]);
                $currentExpire = (!empty($uRow['expire_at']) && strtotime((string)$uRow['expire_at']) > time())
                    ? (string)$uRow['expire_at']
                    : date('Y-m-d H:i:s');
                $newExpire = date('Y-m-d H:i:s', strtotime($duration, strtotime($currentExpire)));

                // If order was already paid before this code existed, still enforce upgrade once here.
                if ((int)($uRow['plan_id'] ?? 0) !== $plan_id || $updated->rowCount() > 0) {
                    $db->query(
                        "UPDATE users SET plan_id=?, expire_at=?, fast_sync_remaining = COALESCE(fast_sync_remaining, 0) + ? WHERE id=?",
                        [$plan_id, $newExpire, $fastSyncGrant, (int)$order['user_id']]
                    );
                }
            }
        }
    }

    if (!$ok) {
        $ok = true;
        $msg = $tt('支付成功，套餐已升级。正在返回套餐页面...', 'Payment successful. Your plan has been upgraded. Redirecting...');
        $redirect = '/upgrade.php?payment_success=1&order=' . urlencode($order_no);
    }
} catch (Throwable $e) {
    if (!$ok) {
        if ($msg === '' || $msg === $tt('支付验证失败，请联系管理员处理。', 'Payment verification failed. Please contact support.')) {
            $msg = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo $is_en ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tt('支付结果', 'Payment Result')); ?> - <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="mx-auto card shadow-sm border-0" style="max-width:560px;border-radius:16px;">
        <div class="card-body p-4 p-md-5 text-center">
            <?php if (!empty($site_logo)): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="logo" style="height:34px;width:auto;" class="mb-3">
            <?php endif; ?>
            <h4 class="fw-bold mb-3 <?php echo $ok ? 'text-success' : 'text-danger'; ?>">
                <?php echo htmlspecialchars($ok ? $tt('支付成功', 'Payment Success') : $tt('支付处理失败', 'Payment Failed')); ?>
            </h4>
            <p class="text-muted mb-4"><?php echo htmlspecialchars($msg); ?></p>
            <a href="<?php echo htmlspecialchars($redirect); ?>" class="btn btn-primary px-4">
                <?php echo htmlspecialchars($tt('返回套餐页面', 'Back to Upgrade')); ?>
            </a>
        </div>
    </div>
</div>
<?php if ($ok): ?>
<script>
setTimeout(function () {
    window.location.href = <?php echo json_encode($redirect); ?>;
}, 1800);
</script>
<?php endif; ?>
</body>
</html>
