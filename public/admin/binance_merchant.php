<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Core/Migrator.php';
require_once __DIR__ . '/../../src/Core/I18n.php';
require_once __DIR__ . '/../../src/Services/BinancePayService.php';
require_once __DIR__ . '/../../src/Services/UpgradeOrderService.php';
require_once __DIR__ . '/../../src/Services/TotpService.php';
require_once __DIR__ . '/../../src/Services/User2FAService.php';

I18n::init();
$db = Database::getInstance();
$migrator = new Migrator($db->getConnection());
$migrator->run();

// Admin 2FA for binance scene
$_bnAdminId  = (int)($_SESSION['user_id'] ?? 0);
$_bnAdmin    = $db->fetch("SELECT two_factor_enabled, two_factor_secret, two_factor_scenes FROM users WHERE id=? AND role='admin' LIMIT 1", [$_bnAdminId]);
$_bnScene    = $_bnAdmin ? User2FAService::isSceneEnabled((array)$_bnAdmin, 'admin_binance') : false;

$page_title = '币安商户管理';
$active_menu = 'binance_merchant';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['admin_csrf_token'];

$bCfg = BinancePayService::loadConfig($db);
$configOk = !empty($bCfg['api_key']) && !empty($bCfg['secret_key']) && !empty($bCfg['certificate_sn']) && !empty($bCfg['enabled']);

$error = '';
$success = '';
$result = null;
$showRaw = false;
$rawTitle = '原始响应';
$activeTab = (string)($_GET['tab'] ?? ($_POST['action_tab'] ?? 'refund'));
$generatedPayData = null;
$autoOpenModal = '';
if (!empty($_SESSION['binance_admin_flash']) && is_array($_SESSION['binance_admin_flash'])) {
    $flash = $_SESSION['binance_admin_flash'];
    unset($_SESSION['binance_admin_flash']);
    $error = (string)($flash['error'] ?? '');
    $success = (string)($flash['success'] ?? '');
    $activeTab = (string)($flash['active_tab'] ?? $activeTab);
    $autoOpenModal = (string)($flash['auto_open_modal'] ?? '');
    if (!empty($flash['generated_pay_data'])) {
        $decoded = json_decode((string)$flash['generated_pay_data'], true);
        if (is_array($decoded)) {
            $generatedPayData = $decoded;
        }
    }
    if (!empty($flash['quote_preview'])) {
        $decodedQ = json_decode((string)$flash['quote_preview'], true);
        if (is_array($decodedQ)) {
            $quotePreview = $decodedQ;
        }
    }
}
try {
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
        UNIQUE KEY `uniq_trade_no` (`merchant_trade_no`),
        KEY `idx_status` (`status`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $ignore) {
}

$quoteInput = [
    'from' => strtoupper(trim((string)($_POST['convert_from'] ?? 'USDT'))),
    'to' => strtoupper(trim((string)($_POST['convert_to'] ?? 'BTC'))),
    'amount' => trim((string)($_POST['convert_amount'] ?? '1000')),
];
if (!isset($quotePreview) || !is_array($quotePreview)) {
    $quotePreview = null;
}

$merchantsForSharing = $db->fetchAll(
    "SELECT id, email, binance_uid
     FROM users
     WHERE role = 'user' AND binance_uid IS NOT NULL AND binance_uid <> ''
     ORDER BY id DESC
     LIMIT 500"
);

$statusText = static function (string $st): string {
    return match (strtolower(trim($st))) {
        'paid' => '已支付',
        'pending' => '待支付',
        'expired' => '已过期',
        'refunded' => '已退款',
        'failed' => '失败',
        default => $st,
    };
};

$walletText = static function (string $w): string {
    return match (strtoupper(trim($w))) {
        'FUNDING_WALLET' => '资金钱包',
        'SPOT_WALLET' => '现货钱包',
        default => $w,
    };
};

$providerText = static function (string $p): string {
    return match (strtolower(trim($p))) {
        'binance' => '币安支付',
        'stripe' => 'Stripe',
        'crypto' => '加密支付',
        'balance' => '余额支付',
        'coupon' => '优惠券',
        default => $p,
    };
};

$originText = static function (string $o): string {
    return match (strtolower(trim($o))) {
        'merchant_order' => '商户订单',
        'merchant_customer_order' => '商户客户订单',
        'api' => 'API 调用',
        'payment_link' => '收款链接',
        'qr_code' => '收款码',
        'shop', 'store' => '店铺收款',
        'upgrade' => '套餐升级',
        'recharge' => '余额充值',
        default => $o,
    };
};

$webhookEventText = static function (string $s): string {
    $u = strtoupper(trim($s));
    if (strpos($u, 'REFUND') !== false) return '退款通知';
    if (strpos($u, 'PAY_SUCCESS') !== false || $u === 'PAY') return '支付通知';
    if (strpos($u, 'PAY') !== false) return '支付通知';
    return $s;
};

$webhookVerifyText = static function (string $s): string {
    return match (strtolower(trim($s))) {
        'verified' => '已验签',
        'pending' => '待验签',
        default => $s,
    };
};

$webhookProcessText = static function (string $s): string {
    return match (strtolower(trim($s))) {
        'processed' => '已处理',
        'processed_refund_sync' => '已补偿同步退款',
        'ignored' => '已忽略',
        'already_paid' => '已支付(重复通知)',
        'pending' => '待处理',
        default => $s,
    };
};

$goodsDescText = static function (array $o): string {
    $src = strtolower((string)($o['source'] ?? ''));
    $merchantOrderNo = (string)($o['merchant_order_id'] ?? '');
    if ($src === 'upgrade' || strpos($merchantOrderNo, 'PLAN-') === 0) return '套餐升级';
    if ($src === 'recharge') return '余额充值';
    if ($src === 'payment_link') return '收款链接支付';
    if ($src === 'qr_code') return '扫码支付';
    if ($src === 'api') return 'API 收款订单';
    return $src !== '' ? $src : '-';
};

$doAction = static function (string $action) use (
    $db,
    $bCfg,
    $configOk,
    $csrfToken,
    &$error,
    &$success,
    &$result,
    &$showRaw,
    &$rawTitle,
    &$quotePreview,
    $quoteInput,
    &$generatedPayData,
    &$autoOpenModal,
    $_bnAdmin,
    $_bnScene
) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals($csrfToken, $csrf)) {
        $error = 'CSRF 校验失败';
        return;
    }

    // 2FA guard for sensitive binance actions
    $sensitiveActions = ['refund_submit', 'close_order', 'create_paylink_quick', 'create_paylink_detail'];
    if ($_bnScene && in_array($action, $sensitiveActions, true)) {
        $bnOtp = trim($_POST['bn_otp_code'] ?? '');
        [$ok2fa, $err2fa] = User2FAService::verifyForScene((array)$_bnAdmin, 'admin_binance', $bnOtp);
        if (!$ok2fa) {
            $error = '谷歌验证码错误，此操作需要 2FA 验证：' . $err2fa;
            return;
        }
    }

    try {
        if (!$configOk) {
            throw new Exception('请先在系统设置里启用 Binance Pay 并填写 API Key/Secret。');
        }

        if ($action === 'refund_submit') {
            $autoOpenModal = 'refundModal';
            $orderId = (int)($_POST['refund_order_id'] ?? 0);
            $mode = (string)($_POST['refund_mode'] ?? 'full');
            $refundAmountInput = trim((string)($_POST['refund_partial_amount'] ?? ''));
            $reason = trim((string)($_POST['refundReason'] ?? ''));

            $order = $db->fetch("SELECT * FROM orders WHERE id = ? AND LOWER(chain) = 'binance_pay' LIMIT 1", [$orderId]);
            if (!$order || strtolower((string)$order['status']) !== 'paid') {
                throw new Exception('请选择已支付的 Binance 订单');
            }
            if (strtolower((string)($order['refund_status'] ?? '')) === 'full') {
                throw new Exception('该订单已全额退款，不能重复退款');
            }

            $orderAmount = (float)($order['amount'] ?? 0);
            $alreadyRefunded = (float)($order['refund_amount'] ?? 0);
            $remaining = max(0, $orderAmount - $alreadyRefunded);
            if ($remaining <= 0.000001) {
                throw new Exception('该订单可退款余额为 0');
            }

            $refundAmount = $remaining;
            if ($mode === 'partial') {
                $refundAmount = (float)$refundAmountInput;
                if ($refundAmount <= 0 || $refundAmount > $remaining) {
                    throw new Exception('部分退款金额无效');
                }
            }

            $payload = [
                'merchantTradeNo' => (string)$order['order_no'],
                'refundRequestId' => 'RFD-' . date('YmdHis') . '-' . rand(1000, 9999),
                'refundAmount' => number_format($refundAmount, 2, '.', ''),
            ];
            if ($reason !== '') {
                $payload['refundReason'] = $reason;
            }

            $resp = BinancePayService::refund($bCfg, $payload);
            $result = $resp['data'] ?? $resp;
            $showRaw = true;
            $rawTitle = '退款接口返回';
            if (BinancePayService::isSuccess($resp)) {
                UpgradeOrderService::applyRefund(
                    $db,
                    (int)$order['id'],
                    (float)$refundAmount,
                    (string)$payload['refundRequestId'],
                    (string)$reason
                );
                $success = '退款请求已提交并同步订单状态';
            } else {
                $msg = (string)(($resp['data']['errorMessage'] ?? '') ?: ($resp['data']['msg'] ?? '币安退款失败'));
                throw new Exception($msg);
            }
            return;
        }

        if ($action === 'transfer_submit') {
            $autoOpenModal = 'withdrawModal';
            $to = trim((string)($_POST['transfer_to'] ?? ''));
            $amount = trim((string)($_POST['transfer_amount'] ?? ''));
            $currency = strtoupper(trim((string)($_POST['transfer_currency'] ?? 'USDT')));
            $memo = trim((string)($_POST['transfer_memo'] ?? ''));
            if ($to === '' || $amount === '') {
                throw new Exception('转账参数不完整');
            }
            if (!is_numeric($amount) || (float)$amount <= 0) {
                throw new Exception('金额必须为大于 0 的数字');
            }

            $amountNorm = rtrim(rtrim(number_format((float)$amount, 8, '.', ''), '0'), '.');

            $receiveType = '';
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $receiveType = 'EMAIL';
            } elseif (ctype_digit($to)) {
                $receiveType = 'BINANCE_ID';
            } else {
                throw new Exception('接收方格式不正确：请输入邮箱或数字 UID');
            }

            $requestId = 'TRF-' . date('YmdHis') . '-' . rand(1000, 9999);
            $detailId = 'DTL-' . date('YmdHis') . '-' . rand(1000, 9999);

            $payload = [
                'requestId' => $requestId,
                'batchName' => 'AdminWithdraw-' . date('YmdHis'),
                'currency' => $currency,
                'totalAmount' => $amountNorm,
                'totalNumber' => 1,
                'bizScene' => 'MERCHANT_PAYMENT',
                'transferDetailList' => [[
                    'merchantSendId' => $detailId,
                    'receiveType' => $receiveType,
                    'receiver' => $to,
                    'transferAmount' => $amountNorm,
                    'transferMethod' => 'FUNDING_WALLET',
                    'remark' => $memo,
                ]],
            ];

            $resp = BinancePayService::request($bCfg, '/binancepay/openapi/payout/transfer', $payload, 'POST');
            $result = $resp['data'] ?? $resp;
            $showRaw = true;
            $rawTitle = '转账接口返回';
            if (BinancePayService::isSuccess($resp)) {
                $success = '转账请求已提交';
            } else {
                $msg = (string)(($resp['data']['errorMessage'] ?? '') ?: ($resp['data']['msg'] ?? '币安转账失败'));
                $error = $msg;
            }
            return;
        }

        if ($action === 'convert_quote') {
            $autoOpenModal = 'convertModal';
            $from = strtoupper(trim((string)($_POST['convert_from'] ?? $quoteInput['from'])));
            $to = strtoupper(trim((string)($_POST['convert_to'] ?? $quoteInput['to'])));
            $amount = trim((string)($_POST['convert_amount'] ?? $quoteInput['amount']));
            if ($amount === '') {
                throw new Exception('请输入兑换数量');
            }
            $resp = BinancePayService::convertQuote($bCfg, $from, $to, $amount);
            if (!BinancePayService::isSuccess($resp)) {
                $code = (string)($resp['data']['code'] ?? '');
                $msg = (string)(($resp['data']['errorMessage'] ?? '') ?: ($resp['data']['msg'] ?? '获取报价失败'));
                if ($code !== '') {
                    $msg = '[' . $code . '] ' . $msg;
                }
                if (stripos($msg, 'unknown error') !== false) {
                    $msg .= '（请在币安商户后台确认已开通“兑换加密货币”权限）';
                }
                throw new Exception($msg);
            }
            $d = $resp['data']['data'] ?? [];
            $toAmount = (string)($d['toAmount'] ?? $d['estimateToAmount'] ?? $d['quoteToAmount'] ?? $d['receiveAmount'] ?? $d['convertAmount'] ?? '');
            $rate = (string)($d['ratio'] ?? $d['rate'] ?? $d['exchangeRate'] ?? $d['price'] ?? '');
            if ($toAmount === '' && $rate !== '' && is_numeric($rate) && is_numeric($amount)) {
                $toAmount = rtrim(rtrim(number_format((float)$amount * (float)$rate, 8, '.', ''), '0'), '.');
            }
            $quotePreview = [
                'from' => $from,
                'to' => $to,
                'fromAmount' => $amount,
                'toAmount' => $toAmount !== '' ? $toAmount : '-',
                'rate' => $rate !== '' ? $rate : '-',
            ];
            $success = '已获取兑换估算';
            return;
        }

        if ($action === 'convert_execute') {
            $autoOpenModal = 'convertModal';
            $from = strtoupper(trim((string)($_POST['convert_from'] ?? $quoteInput['from'])));
            $to = strtoupper(trim((string)($_POST['convert_to'] ?? $quoteInput['to'])));
            $amount = trim((string)($_POST['convert_amount'] ?? $quoteInput['amount']));
            if ($amount === '') {
                throw new Exception('请输入兑换数量');
            }
            $resp = BinancePayService::convertExecute($bCfg, $from, $to, $amount);
            $result = $resp['data'] ?? $resp;
            $showRaw = true;
            $rawTitle = '兑换接口返回';
            if (BinancePayService::isSuccess($resp)) {
                $success = '兑换请求已提交';
            }
            return;
        }

        if ($action === 'profit_share') {
            $autoOpenModal = 'profitModal';
            $merchantId = (int)($_POST['share_merchant_id'] ?? 0);
            $amount = trim((string)($_POST['share_amount'] ?? ''));
            $currency = strtoupper(trim((string)($_POST['share_currency'] ?? 'USDT')));
            $merchantTradeNo = trim((string)($_POST['share_merchant_trade_no'] ?? ''));
            if ($merchantId <= 0 || $amount === '' || $merchantTradeNo === '') {
                throw new Exception('分润参数不完整');
            }
            $merchant = $db->fetch("SELECT id, email, binance_uid FROM users WHERE id = ? LIMIT 1", [$merchantId]);
            if (!$merchant || trim((string)$merchant['binance_uid']) === '') {
                throw new Exception('该商户未绑定 Binance UID');
            }
            $payload = [
                'requestId' => 'PFS-' . date('YmdHis') . '-' . rand(1000, 9999),
                'merchantTradeNo' => $merchantTradeNo,
                'receiver' => trim((string)$merchant['binance_uid']),
                'amount' => (string)$amount,
                'currency' => $currency,
            ];
            $resp = BinancePayService::request($bCfg, '/binancepay/openapi/profitsharing/order', $payload, 'POST');
            $result = $resp['data'] ?? $resp;
            $showRaw = true;
            $rawTitle = '分润接口返回';
            if (BinancePayService::isSuccess($resp)) {
                $success = '分润请求已提交';
            }
            return;
        }

        if ($action === 'create_paylink_quick' || $action === 'create_paylink_detail') {
            $autoOpenModal = 'payLinkModal';
            $title = trim((string)($_POST['pay_title'] ?? '快捷支付'));
            $amount = (float)($_POST['pay_amount'] ?? 0);
            $currency = strtoupper(trim((string)($_POST['pay_currency'] ?? 'USDT')));
            $desc = trim((string)($_POST['pay_desc'] ?? ''));
            $qty = max(1, (int)($_POST['pay_qty'] ?? 1));

            if ($amount <= 0) {
                throw new Exception('金额必须大于 0');
            }
            if ($title === '') {
                $title = '快捷支付';
            }

            $protocol = 'http';
            if (
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443) ||
                (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https')
            ) {
                $protocol = 'https';
            }
            $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $baseUrl = $protocol . '://' . $host;

            $tradeNo = 'BPL' . date('YmdHis') . rand(1000, 9999);
            $terminalType = 'WEB';
            $payload = [
                'merchantTradeNo' => $tradeNo,
                'orderAmount' => round($amount, 2),
                'currency' => $currency,
                'productType' => '01',
                'productName' => $title,
                'description' => $desc !== '' ? $desc : $title,
                'env' => ['terminalType' => $terminalType],
                'goodsDetails' => [[
                    'goodsType' => '01',
                    'goodsCategory' => 'D000',
                    'referenceGoodsId' => 'admin-link-' . time(),
                    'goodsName' => $title,
                    'goodsDetail' => $desc !== '' ? $desc : $title,
                    'goodsUnitAmount' => [
                        'currency' => $currency,
                        'amount' => number_format($amount, 2, '.', '')
                    ],
                    'goodsQuantity' => $qty
                ]],
                'returnUrl' => $baseUrl . '/admin/binance_merchant.php',
                'cancelUrl' => $baseUrl . '/admin/binance_merchant.php',
                'webhookUrl' => $baseUrl . '/api/v1/binance/webhook.php',
            ];

            $resp = BinancePayService::createOrder($bCfg, $payload);
            $result = $resp['data'] ?? $resp;
            $showRaw = true;
            $rawTitle = '创建支付链接返回';

            if (!BinancePayService::isSuccess($resp)) {
                $msg = (string)(($resp['data']['errorMessage'] ?? '') ?: ($resp['data']['msg'] ?? '创建失败'));
                throw new Exception($msg);
            }

            $data = $resp['data']['data'] ?? [];
            $checkoutUrl = trim((string)($data['checkoutUrl'] ?? $data['deeplink'] ?? ''));
            $qrUrl = trim((string)($data['qrcodeLink'] ?? ''));
            if ($qrUrl === '' && $checkoutUrl !== '') {
                $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . rawurlencode($checkoutUrl);
            }

            $db->query(
                "INSERT INTO orders (
                    order_no, merchant_order_id, user_id, wallet_id, amount, currency, chain, status, pay_provider, source, order_origin,
                    tx_hash, binance_pay_order_id, created_at, updated_at
                ) VALUES (?, ?, 0, NULL, ?, ?, 'binance_pay', 'pending', 'binance', 'payment_link', 'merchant_order', ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE amount = VALUES(amount), currency = VALUES(currency), updated_at = NOW()",
                [
                    $tradeNo,
                    'PLINK-' . $tradeNo,
                    round($amount, 6),
                    $currency,
                    (string)($data['prepayId'] ?? ''),
                    (string)($data['prepayId'] ?? '')
                ]
            );
            $db->query(
                "INSERT INTO binance_pay_links (
                    merchant_trade_no, title, description, amount, currency, checkout_url, qr_url, source, status, binance_prepay_id, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'payment_link', 'pending', ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    description = VALUES(description),
                    amount = VALUES(amount),
                    currency = VALUES(currency),
                    checkout_url = VALUES(checkout_url),
                    qr_url = VALUES(qr_url),
                    binance_prepay_id = VALUES(binance_prepay_id),
                    updated_at = NOW()",
                [
                    $tradeNo,
                    $title,
                    $desc !== '' ? $desc : $title,
                    round($amount, 6),
                    $currency,
                    $checkoutUrl,
                    $qrUrl,
                    (string)($data['prepayId'] ?? ''),
                ]
            );

            $generatedPayData = [
                'trade_no' => $tradeNo,
                'title' => $title,
                'amount' => number_format($amount, 2, '.', ''),
                'currency' => $currency,
                'checkout_url' => $checkoutUrl,
                'qr_url' => $qrUrl,
            ];
            $success = '支付链接创建成功';
            $showRaw = false;
            return;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? '');
    if ($postAction === 'convert_quote_live') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $csrf = (string)($_POST['csrf_token'] ?? '');
            if ($csrf === '' || !hash_equals($csrfToken, $csrf)) {
                throw new Exception('CSRF 校验失败');
            }
            if (!$configOk) {
                throw new Exception('币安配置不完整');
            }
            $from = strtoupper(trim((string)($_POST['convert_from'] ?? 'USDT')));
            $to = strtoupper(trim((string)($_POST['convert_to'] ?? 'BTC')));
            $amount = trim((string)($_POST['convert_amount'] ?? ''));
            if ($from === '' || $to === '' || $amount === '' || !is_numeric($amount) || (float)$amount <= 0) {
                throw new Exception('请输入有效的兑换参数');
            }
            $resp = BinancePayService::convertQuote($bCfg, $from, $to, $amount);
            if (!BinancePayService::isSuccess($resp)) {
                $code = (string)($resp['data']['code'] ?? '');
                $msg = (string)(($resp['data']['errorMessage'] ?? '') ?: ($resp['data']['msg'] ?? '获取报价失败'));
                if ($code !== '') {
                    $msg = '[' . $code . '] ' . $msg;
                }
                if (stripos($msg, 'unknown error') !== false) {
                    $msg .= '（请在币安商户后台确认已开通“兑换加密货币”权限）';
                }
                throw new Exception($msg);
            }
            $d = $resp['data']['data'] ?? [];
            $toAmount = (string)($d['toAmount'] ?? $d['estimateToAmount'] ?? $d['quoteToAmount'] ?? $d['receiveAmount'] ?? $d['convertAmount'] ?? '');
            $rate = (string)($d['ratio'] ?? $d['rate'] ?? $d['exchangeRate'] ?? $d['price'] ?? '');
            if ($toAmount === '' && $rate !== '' && is_numeric($rate) && is_numeric($amount)) {
                $toAmount = rtrim(rtrim(number_format((float)$amount * (float)$rate, 8, '.', ''), '0'), '.');
            }
            echo json_encode([
                'ok' => true,
                'from' => $from,
                'to' => $to,
                'from_amount' => $amount,
                'to_amount' => $toAmount,
                'rate' => $rate
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
    $doAction($postAction);
    $_SESSION['binance_admin_flash'] = [
        'error' => $error,
        'success' => $success,
        'active_tab' => (string)($_POST['action_tab'] ?? $activeTab),
        'auto_open_modal' => $autoOpenModal,
        'generated_pay_data' => $generatedPayData ? json_encode($generatedPayData, JSON_UNESCAPED_UNICODE) : '',
        'quote_preview' => $quotePreview ? json_encode($quotePreview, JSON_UNESCAPED_UNICODE) : '',
    ];
    header('Location: /admin/binance_merchant.php?tab=' . rawurlencode((string)($_POST['action_tab'] ?? $activeTab)), true, 303);
    exit;
}

$balanceRows = [];
if ($configOk) {
    try {
        $balanceResp = BinancePayService::queryAllBalances($bCfg, '');
        $balanceRows = $balanceResp['data']['balances'] ?? [];
    } catch (Throwable $e) {
        if ($error === '') {
            $error = '余额自动加载失败：' . $e->getMessage();
        }
    }
}

$mainstreamSymbols = ['USDT', 'BTC', 'ETH', 'BNB', 'USDC', 'FDUSD', 'BUSD', 'SOL', 'XRP', 'DOGE', 'TRX'];
$mainstreamBalanceMap = [];
foreach ($mainstreamSymbols as $symbol) {
    $mainstreamBalanceMap[$symbol] = 0.0;
}
foreach ($balanceRows as $r) {
    $asset = strtoupper(trim((string)($r['asset'] ?? '')));
    $available = (float)($r['available'] ?? 0);
    if ($asset !== '' && array_key_exists($asset, $mainstreamBalanceMap)) {
        $mainstreamBalanceMap[$asset] += $available;
    }
}
$usdtBalance = (float)($mainstreamBalanceMap['USDT'] ?? 0);

$binanceOrders = $db->fetchAll(
    "SELECT id, order_no, merchant_order_id, amount, currency, status, tx_hash, created_at, updated_at,
            pay_provider, source, order_origin, refund_status, refund_amount,
            binance_pay_order_id, binance_payer_uid, binance_open_user_id, binance_merchant_id
     FROM orders
     WHERE LOWER(chain) = 'binance_pay'
     ORDER BY id DESC
     LIMIT 80"
);

if ($configOk && !empty($binanceOrders)) {
    $refreshCount = 0;
    foreach ($binanceOrders as $row) {
        if ($refreshCount >= 30) {
            break;
        }
        $refreshCount++;
        try {
            $q = BinancePayService::queryOrder($bCfg, (string)$row['order_no'], (string)($row['binance_pay_order_id'] ?? ''));
            if (!BinancePayService::isSuccess($q)) {
                continue;
            }
            $qData = $q['data']['data'] ?? [];
            $paymentInfo = isset($qData['paymentInfo']) && is_array($qData['paymentInfo']) ? $qData['paymentInfo'] : [];
            $normalized = BinancePayService::normalizeOrderStatus($q);
            $txHash = (string)($qData['transactionId'] ?? $qData['prepayId'] ?? $row['tx_hash'] ?? '');

            $db->query(
                "UPDATE orders
                 SET status = ?,
                     pay_provider = 'binance',
                     paid_at = CASE WHEN ? = 'paid' AND paid_at IS NULL THEN NOW() ELSE paid_at END,
                     tx_hash = CASE WHEN ? <> '' THEN ? ELSE tx_hash END,
                     binance_pay_order_id = CASE WHEN ? <> '' THEN ? ELSE binance_pay_order_id END,
                     binance_payer_uid = CASE WHEN ? <> '' THEN ? ELSE binance_payer_uid END,
                     binance_open_user_id = CASE WHEN ? <> '' THEN ? ELSE binance_open_user_id END,
                     binance_merchant_id = CASE WHEN ? <> '' THEN ? ELSE binance_merchant_id END,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $normalized,
                    $normalized,
                    $txHash, $txHash,
                    (string)($qData['prepayId'] ?? ''), (string)($qData['prepayId'] ?? ''),
                    (string)($paymentInfo['payerId'] ?? ''), (string)($paymentInfo['payerId'] ?? ''),
                    (string)($paymentInfo['openUserId'] ?? ''), (string)($paymentInfo['openUserId'] ?? ''),
                    (string)($paymentInfo['payeeId'] ?? $qData['merchantId'] ?? ''), (string)($paymentInfo['payeeId'] ?? $qData['merchantId'] ?? ''),
                    (int)$row['id']
                ]
            );

            $db->query(
                "UPDATE binance_pay_links
                 SET status = ?,
                     binance_prepay_id = CASE WHEN ? <> '' THEN ? ELSE binance_prepay_id END,
                     paid_at = CASE WHEN ? = 'paid' AND paid_at IS NULL THEN NOW() ELSE paid_at END,
                     updated_at = NOW()
                 WHERE merchant_trade_no = ?",
                [
                    $normalized,
                    (string)($qData['prepayId'] ?? ''), (string)($qData['prepayId'] ?? ''),
                    $normalized,
                    (string)$row['order_no']
                ]
            );

            $refundSync = BinancePayService::extractRefundInfoFromOrderQuery($q, (float)($row['amount'] ?? 0));
            if (!empty($refundSync['has_refund'])) {
                $refundAmount = (float)($refundSync['refund_amount'] ?? 0);
                $refundStatus = (string)($refundSync['refund_status'] ?? '');
                $refundRequestId = trim((string)($refundSync['refund_request_id'] ?? ''));

                if ($refundAmount <= 0 && $refundStatus === 'full') {
                    $refundAmount = (float)($row['amount'] ?? 0);
                }
                if ($refundRequestId === '') {
                    $refundRequestId = 'SYNC-' . (string)$row['order_no'] . '-' . ($refundStatus !== '' ? $refundStatus : 'refund');
                }

                if ($refundAmount > 0) {
                    UpgradeOrderService::applyRefund(
                        $db,
                        (int)$row['id'],
                        $refundAmount,
                        $refundRequestId,
                        '币安订单状态自动同步'
                    );
                }
            }

            UpgradeOrderService::ensureUpgradeRollbackForRefund($db, (int)$row['id']);
            // Note: refund notification is already sent inside applyRefund() above.
            // ensureRefundNotification() is NOT called here to avoid duplicate emails on every page load.
        } catch (Throwable $ignore) {
        }
    }
}

try {
    $refundLogs = $db->fetchAll(
        "SELECT id, order_no
         FROM binance_webhook_logs
         WHERE UPPER(event_type) = 'PAY_REFUND'
           AND (process_status IS NULL OR process_status NOT IN ('processed_refund_sync', 'processed', 'ignored'))
         ORDER BY id DESC
         LIMIT 200"
    );
    foreach ($refundLogs as $rl) {
        $orderNo = trim((string)($rl['order_no'] ?? ''));
        if ($orderNo === '') continue;

        $o = $db->fetch("SELECT * FROM orders WHERE order_no = ? AND LOWER(chain) = 'binance_pay' LIMIT 1", [$orderNo]);
        if (!$o) continue;

        if (strtolower((string)($o['refund_status'] ?? '')) !== 'full' && strtolower((string)($o['status'] ?? '')) !== 'refunded') {
            $amount = (float)($o['amount'] ?? 0);
            if ($amount > 0) {
                UpgradeOrderService::applyRefund(
                    $db,
                    (int)$o['id'],
                    $amount,
                    'WHLOG-' . (string)$rl['id'],
                    '根据 PAY_REFUND 回调日志补偿同步'
                );
            }
        }

        UpgradeOrderService::ensureUpgradeRollbackForRefund($db, (int)$o['id']);
        UpgradeOrderService::ensureRefundNotification($db, (int)$o['id']);

        $db->query(
            "UPDATE binance_webhook_logs
             SET process_status = CASE WHEN process_status IN ('already_paid', 'pending') THEN 'processed_refund_sync' ELSE process_status END
             WHERE id = ?",
            [(int)$rl['id']]
        );
    }
} catch (Throwable $ignore) {
}

$binanceOrders = $db->fetchAll(
    "SELECT id, order_no, merchant_order_id, amount, currency, status, tx_hash, created_at, updated_at,
            pay_provider, source, order_origin, refund_status, refund_amount,
            binance_pay_order_id, binance_payer_uid, binance_open_user_id, binance_merchant_id
     FROM orders
     WHERE LOWER(chain) = 'binance_pay'
     ORDER BY id DESC
     LIMIT 80"
);

$paidBinanceOrders = $db->fetchAll(
    "SELECT id, order_no, merchant_order_id, amount, currency, tx_hash, created_at
     FROM orders
     WHERE LOWER(chain) = 'binance_pay'
       AND status = 'paid'
       AND COALESCE(refund_status, '') <> 'full'
       AND COALESCE(refund_amount, 0) < amount
     ORDER BY id DESC
     LIMIT 200"
);

$refundOrders = $db->fetchAll(
    "SELECT o.id, o.order_no, o.merchant_order_id, o.amount, o.currency, o.status, o.created_at, o.paid_at, o.updated_at,
            o.source, o.order_origin, o.refund_status, o.refund_amount, o.refund_count, o.refund_request_id, o.refund_reason, o.refunded_at,
            o.binance_pay_order_id, o.binance_payer_uid, o.binance_open_user_id, o.binance_merchant_id, o.tx_hash, o.user_id,
            u.email AS merchant_email
     FROM orders o
     LEFT JOIN users u ON u.id = o.user_id
     WHERE LOWER(o.chain) = 'binance_pay'
       AND (o.refund_status IN ('full', 'partial') OR o.status = 'refunded' OR COALESCE(o.refund_amount, 0) > 0)
     ORDER BY o.refunded_at DESC, o.id DESC
     LIMIT 200"
);

$webhookLogs = [];
try {
    $webhookLogs = $db->fetchAll(
        "SELECT l.id, l.order_no, l.event_type, l.verify_status, l.process_status, l.error_message, l.created_at, x.cnt
         FROM binance_webhook_logs l
         INNER JOIN (
             SELECT MAX(id) AS max_id, COUNT(*) AS cnt
             FROM binance_webhook_logs
             GROUP BY COALESCE(order_no, ''), COALESCE(event_type, ''), COALESCE(process_status, '')
         ) x ON x.max_id = l.id
         ORDER BY l.id DESC
         LIMIT 20"
    );
} catch (Throwable $ignore) {
}

$payLinkRows = [];
try {
    $payLinkRows = $db->fetchAll(
        "SELECT *
         FROM (
             SELECT CONCAT('L', l.id) AS row_id, l.merchant_trade_no, l.title, l.amount, l.currency, l.checkout_url, l.qr_url,
                    l.status, l.binance_prepay_id, l.paid_at, l.created_at, COALESCE(o.source, l.source) AS source,
                    COALESCE(o.order_origin, 'merchant_order') AS order_origin, COALESCE(o.status, l.status) AS order_status
             FROM binance_pay_links l
             LEFT JOIN orders o ON o.order_no = l.merchant_trade_no
             UNION ALL
             SELECT CONCAT('O', o.id) AS row_id, o.order_no AS merchant_trade_no, o.merchant_order_id AS title, o.amount, o.currency,
                    NULL AS checkout_url, NULL AS qr_url, o.status, o.binance_pay_order_id AS binance_prepay_id, o.paid_at, o.created_at,
                    o.source, o.order_origin, o.status AS order_status
             FROM orders o
             WHERE LOWER(o.chain) = 'binance_pay'
               AND LOWER(COALESCE(o.source, '')) = 'payment_link'
               AND NOT EXISTS (SELECT 1 FROM binance_pay_links l2 WHERE l2.merchant_trade_no = o.order_no)
         ) t
         ORDER BY t.created_at DESC
         LIMIT 50"
    );
} catch (Throwable $ignore) {
}

require_once 'includes/header.php';
?>
<style>
  .binance-admin { --bn-yellow:#f0b90b; --bn-dark:#1e2329; --bn-mid:#2b3139; --bn-soft:#f8f9fa; }
  .binance-admin .card { border-radius: 14px; border-color: #eceff3; overflow: hidden; }
  .binance-admin .table td, .binance-admin .table th { vertical-align: middle; }
  .binance-admin .card-header { background: #fff8e1; border-bottom-color: #f3e5ab; font-weight: 700; }
  .binance-admin .btn-warning { background: var(--bn-yellow); border-color: var(--bn-yellow); color: var(--bn-dark); font-weight: 700; }
  .binance-admin .btn-warning:hover { background: #d9a608; border-color: #d9a608; color: var(--bn-dark); }
  .binance-admin .btn-outline-light:hover { color: var(--bn-dark); }
  .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
  .chip { border-radius: 999px; padding: 2px 10px; font-size: 12px; border: 1px solid; }
  .action-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; }
  .action-btn { display:flex; align-items:center; justify-content:center; gap:8px; min-height:42px; font-weight:600; }
  .refund-table th, .refund-table td { font-size: 13px; }
  .refund-table td.wrap { white-space: normal; word-break: break-word; }
  .mini-note { font-size: 12px; color: #6b7280; }
</style>

<div class="container-fluid binance-admin py-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h4 class="mb-1 d-flex align-items-center gap-2">
        <img src="https://www.binance.com/favicon.ico" alt="Binance" style="height:20px;width:20px;border-radius:4px;" onerror="this.onerror=null;this.src='https://public.bnbstatic.com/static/images/common/favicon.ico';">
        <span>Binance 商户管理</span>
      </h4>
      <div class="text-muted small">Binance 风格重构：余额优先、弹窗操作、功能拆分。</div>
    </div>
    <div>
      <span class="chip <?php echo $configOk ? 'text-success border-success' : 'text-danger border-danger'; ?>">
        <?php echo $configOk ? '配置正常' : '配置不完整'; ?>
      </span>
    </div>
  </div>

  <?php if ($success !== ''): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div class="card mb-3 border-0" style="background: linear-gradient(135deg,#1e2329 0%,#2b3139 100%); color: #fff;">
    <div class="card-body">
      <div class="row align-items-center g-3">
        <div class="col-lg-6">
          <div class="d-flex align-items-center gap-2 mb-2">
            <img src="https://cdn.jsdelivr.net/gh/atomiclabs/cryptocurrency-icons@master/32/color/usdt.png" width="24" height="24" alt="USDT">
            <div class="fw-semibold">账户余额</div>
          </div>
          <div class="display-6 fw-bold mono"><?php echo number_format($usdtBalance, 2, '.', ''); ?> <span class="fs-5">USDT</span></div>
          <div class="small text-white-50 mt-1">默认展示 USDT，其他币种请点击“查看全部”。</div>
        </div>
        <div class="col-lg-6 text-lg-end">
          <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#withdrawModal">提现</button>
            <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#rechargeModal">充值</button>
            <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#allBalancesModal">查看全部币种</button>
            <button class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#payLinkModal">创建支付链接</button>
          </div>
          <div class="small mt-2 text-white-50">
            套餐退款回滚功能已迁移至「套餐与链」页面。
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">快捷操作</div>
    <div class="card-body">
      <div class="action-grid">
        <button class="btn btn-warning action-btn" data-bs-toggle="modal" data-bs-target="#refundModal">发起退款</button>
        <button class="btn btn-outline-secondary action-btn" data-bs-toggle="modal" data-bs-target="#withdrawModal">提现转账</button>
        <button class="btn btn-outline-secondary action-btn" data-bs-toggle="modal" data-bs-target="#convertModal">币种兑换</button>
        <button class="btn btn-outline-secondary action-btn" data-bs-toggle="modal" data-bs-target="#profitModal">分润</button>
        <button class="btn btn-outline-secondary action-btn" data-bs-toggle="modal" data-bs-target="#payLinkModal">创建支付链接</button>
      </div>
      <div class="mini-note mt-2">页面默认只展示核心数据，所有操作在弹窗中完成，避免页面拥挤。</div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-xl-8">
      <div class="card h-100">
        <div class="card-header fw-semibold">币安订单</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>系统订单号</th><th>来源</th><th>金额</th><th>状态</th><th>退款</th><th>时间</th>
                </tr>
              </thead>
              <tbody>
              <?php if (empty($binanceOrders)): ?>
                <tr><td colspan="6" class="text-muted">暂无币安订单</td></tr>
              <?php else: ?>
                <?php foreach ($binanceOrders as $o): ?>
                  <tr>
                    <td class="mono"><?php echo htmlspecialchars((string)$o['order_no']); ?></td>
                    <td><?php echo htmlspecialchars((string)$originText((string)($o['source'] ?: $o['order_origin'] ?: '-'))); ?></td>
                    <td class="mono"><?php echo htmlspecialchars(number_format((float)$o['amount'], 2) . ' ' . (string)$o['currency']); ?></td>
                    <td><?php echo htmlspecialchars((string)$statusText((string)$o['status'])); ?></td>
                    <td>
                      <?php $rs = strtolower((string)($o['refund_status'] ?? '')); ?>
                      <?php if ($rs === 'full'): ?>
                        <span class="badge bg-danger">全额退款</span>
                      <?php elseif ($rs === 'partial'): ?>
                        <span class="badge bg-warning text-dark">部分退款 <?php echo htmlspecialchars((string)$o['refund_amount']); ?></span>
                      <?php else: ?>
                        -
                      <?php endif; ?>
                    </td>
                    <td class="mono"><?php echo htmlspecialchars((string)($o['created_at'] ?? '-')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="col-xl-4">
      <div class="card mb-3">
        <div class="card-header fw-semibold">Webhook 最近通知</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>事件</th><th>处理</th><th>时间</th></tr></thead>
              <tbody>
              <?php if (empty($webhookLogs)): ?>
                <tr><td colspan="3" class="text-muted">暂无回调记录</td></tr>
              <?php else: ?>
                <?php foreach ($webhookLogs as $log): ?>
                  <tr>
                    <td>
                      <div><?php echo htmlspecialchars((string)$webhookEventText((string)$log['event_type'])); ?></div>
                      <div class="mini-note">验签：<?php echo htmlspecialchars((string)$webhookVerifyText((string)$log['verify_status'])); ?><?php if ((int)($log['cnt'] ?? 1) > 1): ?>，重复 <?php echo (int)$log['cnt']; ?> 次<?php endif; ?></div>
                    </td>
                    <td><?php echo htmlspecialchars((string)$webhookProcessText((string)$log['process_status'])); ?></td>
                    <td class="mono" style="font-size:11px;"><?php echo htmlspecialchars((string)($log['created_at'] ?? '')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header fw-semibold">操作说明</div>
        <div class="card-body mini-note">
          1. 全额退款会触发套餐回滚到升级前套餐并保留原剩余时长。<br>
          2. 部分退款仅更新订单退款金额，不回滚套餐。<br>
          3. 退款完成会向商户发送站内通知（金额、退款单号、退款时间）。
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header fw-semibold">退款记录（Binance + 系统订单绑定）</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0 refund-table">
          <thead>
            <tr>
              <th>系统订单号</th><th>付款订单ID</th><th>支付金额</th><th>退款类型</th><th>退款金额</th>
              <th>退款时间</th><th>来源</th><th>商户</th><th>退款单号</th><th>退款原因</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($refundOrders)): ?>
            <tr><td colspan="10" class="text-muted">暂无退款记录</td></tr>
          <?php else: ?>
            <?php foreach ($refundOrders as $ro): ?>
              <?php
                $currency = (string)($ro['currency'] ?? 'USDT');
                $amount = (float)($ro['amount'] ?? 0);
                $refundAmount = (float)($ro['refund_amount'] ?? 0);
                $rs = strtolower((string)($ro['refund_status'] ?? ''));
                $rsLabel = $rs === 'full' || strtolower((string)($ro['status'] ?? '')) === 'refunded' ? '全额退款' : ($rs === 'partial' ? '部分退款' : '退款');
                if ($rsLabel === '全额退款' && $refundAmount <= 0 && $amount > 0) $refundAmount = $amount;
              ?>
              <tr>
                <td class="mono"><?php echo htmlspecialchars((string)($ro['order_no'] ?? '-')); ?></td>
                <td class="mono"><?php echo htmlspecialchars((string)($ro['binance_pay_order_id'] ?: $ro['tx_hash'] ?: '-')); ?></td>
                <td class="mono"><?php echo htmlspecialchars(number_format($amount, 2, '.', '') . ' ' . $currency); ?></td>
                <td><?php echo htmlspecialchars($rsLabel); ?></td>
                <td class="mono"><?php echo htmlspecialchars(number_format($refundAmount, 2, '.', '') . ' ' . $currency); ?></td>
                <td class="mono"><?php echo htmlspecialchars((string)($ro['refunded_at'] ?: '-')); ?></td>
                <td class="wrap"><?php echo htmlspecialchars((string)$goodsDescText($ro)); ?></td>
                <td class="wrap"><?php echo htmlspecialchars((string)($ro['merchant_email'] ?: ('商户#' . (string)($ro['user_id'] ?: '-')))); ?></td>
                <td class="mono"><?php echo htmlspecialchars((string)($ro['refund_request_id'] ?: '-')); ?></td>
                <td class="wrap"><?php echo htmlspecialchars((string)($ro['refund_reason'] ?: '-')); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($generatedPayData): ?>
    <div class="alert alert-success d-flex flex-wrap align-items-center gap-2 mb-3">
      <span>支付链接已创建：<span class="mono"><?php echo htmlspecialchars((string)$generatedPayData['trade_no']); ?></span></span>
      <a class="btn btn-sm btn-warning" target="_blank" href="<?php echo htmlspecialchars((string)$generatedPayData['checkout_url']); ?>">打开链接</a>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(<?php echo json_encode((string)$generatedPayData['checkout_url']); ?>)">复制链接</button>
    </div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header fw-semibold">支付链接记录</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead>
            <tr>
              <th>商户单号</th><th>标题</th><th>金额</th><th>来源</th><th>状态</th><th>创建时间</th><th>操作</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($payLinkRows)): ?>
              <tr><td colspan="7" class="text-muted">暂无支付链接记录</td></tr>
            <?php else: ?>
              <?php foreach ($payLinkRows as $pl): ?>
                <?php
                  $srcRaw = strtolower((string)($pl['source'] ?: 'payment_link'));
                  $srcText = $srcRaw === 'upgrade' ? '套餐升级' : ($srcRaw === 'payment_link' ? '支付链接' : $srcRaw);
                  $stRaw = strtolower((string)($pl['order_status'] ?: $pl['status'] ?: 'pending'));
                  $stText = $stRaw === 'paid' ? '已支付' : ($stRaw === 'refunded' ? '已退款' : '待支付');
                  $qrViewUrl = (string)($pl['qr_url'] ?? '');
                  if ($qrViewUrl === '' && !empty($pl['checkout_url'])) {
                      $qrViewUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' . rawurlencode((string)$pl['checkout_url']);
                  }
                ?>
                <tr>
                  <td class="mono"><?php echo htmlspecialchars((string)$pl['merchant_trade_no']); ?></td>
                  <td><?php echo htmlspecialchars((string)($pl['title'] ?: '-')); ?></td>
                  <td class="mono"><?php echo htmlspecialchars(number_format((float)$pl['amount'], 2) . ' ' . (string)$pl['currency']); ?></td>
                  <td><?php echo htmlspecialchars($srcText); ?></td>
                  <td><?php echo htmlspecialchars($stText); ?></td>
                  <td class="mono"><?php echo htmlspecialchars((string)($pl['created_at'] ?? '-')); ?></td>
                  <td>
                    <?php if (!empty($pl['checkout_url'])): ?>
                      <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?php echo htmlspecialchars((string)$pl['checkout_url']); ?>">链接</a>
                    <?php endif; ?>
                    <?php if ($qrViewUrl !== ''): ?>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="modal"
                        data-bs-target="#viewQrModal"
                        data-qr-src="<?php echo htmlspecialchars($qrViewUrl); ?>"
                        data-qr-trade="<?php echo htmlspecialchars((string)$pl['merchant_trade_no']); ?>"
                      >二维码</button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="modal fade" id="refundModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">发起退款</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="refund_submit">
            <input type="hidden" name="action_tab" value="refund">
            <div class="col-12">
              <label class="form-label">选择可退款订单</label>
              <select class="form-select" name="refund_order_id" required>
                <option value="">请选择...</option>
                <?php foreach ($paidBinanceOrders as $po): ?>
                  <option value="<?php echo (int)$po['id']; ?>"><?php echo htmlspecialchars((string)$po['order_no'] . ' | ' . number_format((float)$po['amount'], 2) . ' ' . (string)$po['currency']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4">
              <label class="form-label">退款类型</label>
              <select class="form-select" name="refund_mode" id="refundModeSelect">
                <option value="full">全额退款</option>
                <option value="partial">部分退款</option>
              </select>
            </div>
            <div class="col-4" id="partialAmountWrap" style="display:none;">
              <label class="form-label">部分退款金额</label>
              <input class="form-control" name="refund_partial_amount" placeholder="0.00">
            </div>
            <div class="col-4">
              <label class="form-label">退款原因</label>
              <input class="form-control" name="refundReason" placeholder="可选">
            </div>
          </div>
          <div class="modal-footer flex-column align-items-stretch gap-2">
            <?php if ($_bnScene): ?>
            <div class="d-flex align-items-center gap-2 w-100 p-2 rounded" style="background:#eff6ff;border:1px solid #bfdbfe;">
                <i class="fas fa-shield-halved text-primary"></i>
                <label class="small fw-semibold mb-0 flex-shrink-0">谷歌动态码 <span class="text-danger">*</span></label>
                <input name="bn_otp_code" class="form-control form-control-sm" style="max-width:120px;font-family:monospace;font-size:16px;letter-spacing:.12em;text-align:center;" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
                <span class="small text-muted">退款操作需要 2FA 验证</span>
            </div>
            <?php endif; ?>
            <div class="d-flex gap-2 justify-content-end w-100">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button class="btn btn-warning" type="submit">提交退款</button>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="convertModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">币种兑换</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action_tab" value="convert">
            <div class="col-4"><label class="form-label">兑换自</label><input id="convertFromInput" class="form-control" name="convert_from" value="<?php echo htmlspecialchars($quoteInput['from']); ?>"></div>
            <div class="col-4"><label class="form-label">兑换到</label><input id="convertToInput" class="form-control" name="convert_to" value="<?php echo htmlspecialchars($quoteInput['to']); ?>"></div>
            <div class="col-4"><label class="form-label">数量</label><input id="convertAmountInput" class="form-control" name="convert_amount" value="<?php echo htmlspecialchars($quoteInput['amount']); ?>"></div>
            <div class="col-12">
              <div id="convertLiveResult" class="alert <?php echo $quotePreview ? 'alert-info' : 'alert-secondary'; ?> mb-0">
                <?php if ($quotePreview): ?>
                  预计获得 <?php echo htmlspecialchars((string)$quotePreview['toAmount']); ?> <?php echo htmlspecialchars((string)$quotePreview['to']); ?>，汇率 <?php echo htmlspecialchars((string)$quotePreview['rate']); ?>
                <?php else: ?>
                  输入币种与数量后，系统将自动获取实时报价。
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-warning" type="submit" name="action" value="convert_execute">确认兑换</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="profitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">发起分润</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="profit_share">
            <input type="hidden" name="action_tab" value="profit">
            <div class="col-12">
              <label class="form-label">分润商户（已绑定 UID）</label>
              <select class="form-select" name="share_merchant_id" required>
                <option value="">请选择...</option>
                <?php foreach ($merchantsForSharing as $m): ?>
                  <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars('#' . (int)$m['id'] . ' | ' . (string)$m['email'] . ' | UID:' . (string)$m['binance_uid']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-4"><label class="form-label">币种</label><input class="form-control" name="share_currency" value="USDT"></div>
            <div class="col-4"><label class="form-label">金额</label><input class="form-control" name="share_amount" required></div>
            <div class="col-4"><label class="form-label">源订单号</label><input class="form-control" name="share_merchant_trade_no" required></div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-warning" type="submit">提交分润</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="allBalancesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">全部币种余额</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="table-responsive">
            <table class="table table-sm">
              <thead><tr><th>币种</th><th>钱包</th><th class="text-end">可用</th><th class="text-end">冻结</th></tr></thead>
              <tbody>
              <?php if (empty($balanceRows)): ?>
                <tr><td colspan="4" class="text-muted">暂无余额数据</td></tr>
              <?php else: ?>
                <?php foreach ($balanceRows as $r): ?>
                  <?php $asset = trim((string)($r['asset'] ?? '')); ?>
                  <tr>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <?php if ($asset !== ''): ?>
                          <img src="https://cdn.jsdelivr.net/gh/atomiclabs/cryptocurrency-icons@master/32/color/<?php echo htmlspecialchars(strtolower($asset)); ?>.png" width="18" height="18" onerror="this.style.display='none'">
                        <?php endif; ?>
                        <span><?php echo htmlspecialchars($asset !== '' ? $asset : '未返回币种'); ?></span>
                      </div>
                    </td>
                    <td><?php echo htmlspecialchars((string)$walletText((string)($r['wallet'] ?? '-'))); ?></td>
                    <td class="text-end mono"><?php echo htmlspecialchars((string)($r['available'] ?? '0')); ?></td>
                    <td class="text-end mono"><?php echo htmlspecialchars((string)($r['freeze'] ?? '0')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="withdrawModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <form method="post">
          <div class="modal-header">
            <h5 class="modal-title">提现（内部转账）</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body row g-2">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="transfer_submit">
            <input type="hidden" name="action_tab" value="transfer">
            <div class="col-12">
              <label class="form-label">收款人（邮箱或 UID）</label>
              <input class="form-control" name="transfer_to" required>
              <div class="mini-note mt-1">系统自动识别：邮箱按邮箱转账，纯数字按 UID 转账。</div>
            </div>
            <div class="col-6"><label class="form-label">币种</label><input class="form-control" name="transfer_currency" value="USDT"></div>
            <div class="col-6"><label class="form-label">金额</label><input class="form-control" name="transfer_amount" required></div>
            <div class="col-12"><label class="form-label">备注</label><input class="form-control" name="transfer_memo"></div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-warning" type="submit">确认提现</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="modal fade" id="rechargeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">账户充值说明</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mini-note">
            Binance Pay 商户接口不提供“给商户钱包直接充值”的单独 API。<br>
            常用做法是创建支付链接，让付款方完成入账收款。
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-warning" data-bs-target="#payLinkModal" data-bs-toggle="modal">去创建支付链接</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="payLinkModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">创建支付链接</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <ul class="nav nav-tabs mb-3">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#plinkQuick" type="button">快捷支付链接</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#plinkDetail" type="button">详细支付链接</button></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="plinkQuick">
              <form method="post" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="create_paylink_quick">
                <input type="hidden" name="action_tab" value="convert">
                <div class="col-12"><label class="form-label">标题</label><input class="form-control" name="pay_title" placeholder="快捷支付"></div>
                <div class="col-4"><label class="form-label">金额</label><input class="form-control" name="pay_amount" required></div>
                <div class="col-4"><label class="form-label">币种</label><input class="form-control" name="pay_currency" value="USDT"></div>
                <div class="col-4 d-flex align-items-end"><button class="btn btn-warning w-100" type="submit">创建</button></div>
                <?php if ($_bnScene): ?>
                <div class="col-12 d-flex align-items-center gap-2 p-2 rounded mt-1" style="background:#eff6ff;border:1px solid #bfdbfe;">
                    <i class="fas fa-shield-halved text-primary"></i>
                    <label class="small fw-semibold mb-0">动态码 <span class="text-danger">*</span></label>
                    <input name="bn_otp_code" class="form-control form-control-sm" style="max-width:120px;font-family:monospace;font-size:16px;letter-spacing:.12em;text-align:center;" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
                </div>
                <?php endif; ?>
              </form>
            </div>
            <div class="tab-pane fade" id="plinkDetail">
              <form method="post" class="row g-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="create_paylink_detail">
                <input type="hidden" name="action_tab" value="convert">
                <div class="col-12"><label class="form-label">产品名称</label><input class="form-control" name="pay_title" placeholder="产品名称"></div>
                <div class="col-4"><label class="form-label">金额</label><input class="form-control" name="pay_amount" required></div>
                <div class="col-4"><label class="form-label">数量</label><input class="form-control" name="pay_qty" value="1"></div>
                <div class="col-4"><label class="form-label">币种</label><input class="form-control" name="pay_currency" value="USDT"></div>
                <div class="col-12"><label class="form-label">描述</label><input class="form-control" name="pay_desc" placeholder="订单描述"></div>
                <?php if ($_bnScene): ?>
                <div class="col-12 d-flex align-items-center gap-2 p-2 rounded" style="background:#eff6ff;border:1px solid #bfdbfe;">
                    <i class="fas fa-shield-halved text-primary"></i>
                    <label class="small fw-semibold mb-0">动态码 <span class="text-danger">*</span></label>
                    <input name="bn_otp_code" class="form-control form-control-sm" style="max-width:120px;font-family:monospace;font-size:16px;letter-spacing:.12em;text-align:center;" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
                </div>
                <?php endif; ?>
                <div class="col-12"><button class="btn btn-warning" type="submit">创建详细支付链接</button></div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="viewQrModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">支付二维码</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body text-center">
          <div class="mini-note mb-2" id="viewQrTradeNo">-</div>
          <img id="viewQrImage" src="" alt="QR" style="max-width:220px;border:1px solid #eee;border-radius:8px;">
        </div>
      </div>
    </div>
  </div>

  <?php if ($showRaw && $result !== null): ?>
    <details class="card mb-3">
      <summary class="card-header fw-semibold" style="cursor:pointer;"><?php echo htmlspecialchars($rawTitle); ?>（调试数据，点击展开）</summary>
      <div class="card-body"><pre class="mb-0 mono"><?php echo htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></div>
    </details>
  <?php endif; ?>
</div>

<script>
  const modeSelect = document.getElementById('refundModeSelect');
  const partialWrap = document.getElementById('partialAmountWrap');
  if (modeSelect && partialWrap) {
    const sync = () => {
      partialWrap.style.display = modeSelect.value === 'partial' ? '' : 'none';
    };
    modeSelect.addEventListener('change', sync);
    sync();
  }

  <?php if ($autoOpenModal !== ''): ?>
  (function() {
    const el = document.getElementById('<?php echo htmlspecialchars($autoOpenModal); ?>');
    if (el && window.bootstrap) {
      const modal = new bootstrap.Modal(el);
      modal.show();
    }
  })();
  <?php endif; ?>

  (function() {
    const qrModal = document.getElementById('viewQrModal');
    if (!qrModal) return;
    qrModal.addEventListener('show.bs.modal', function(ev) {
      const btn = ev.relatedTarget;
      if (!btn) return;
      const src = btn.getAttribute('data-qr-src') || '';
      const trade = btn.getAttribute('data-qr-trade') || '-';
      const img = document.getElementById('viewQrImage');
      const tradeEl = document.getElementById('viewQrTradeNo');
      if (img) img.src = src;
      if (tradeEl) tradeEl.textContent = '订单：' + trade;
    });
  })();

  (function() {
    const fromEl = document.getElementById('convertFromInput');
    const toEl = document.getElementById('convertToInput');
    const amountEl = document.getElementById('convertAmountInput');
    const resultEl = document.getElementById('convertLiveResult');
    if (!fromEl || !toEl || !amountEl || !resultEl) return;

    let timer = null;
    let inflight = false;

    const run = function() {
      const from = (fromEl.value || '').trim().toUpperCase();
      const to = (toEl.value || '').trim().toUpperCase();
      const amount = (amountEl.value || '').trim();
      if (!from || !to || !amount || Number(amount) <= 0) {
        resultEl.className = 'alert alert-secondary mb-0';
        resultEl.textContent = '输入币种与数量后，系统将自动获取实时报价。';
        return;
      }
      if (inflight) return;
      inflight = true;
      const body = new URLSearchParams();
      body.set('action', 'convert_quote_live');
      body.set('csrf_token', '<?php echo htmlspecialchars($csrfToken); ?>');
      body.set('convert_from', from);
      body.set('convert_to', to);
      body.set('convert_amount', amount);
      fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
        body: body.toString()
      }).then(r => r.json())
        .then(d => {
          if (d && d.ok) {
            resultEl.className = 'alert alert-info mb-0';
            let tip = '预计获得 ' + (d.to_amount || '-') + ' ' + to + '，汇率 ' + (d.rate || '-');
            if (d.rate && !isNaN(Number(d.rate))) {
              tip += '（1 ' + from + ' ≈ ' + Number(d.rate).toFixed(8) + ' ' + to + '）';
            }
            resultEl.textContent = tip;
          } else {
            resultEl.className = 'alert alert-warning mb-0';
            resultEl.textContent = '报价失败：' + ((d && d.message) ? d.message : '未知错误');
          }
        })
        .catch(() => {
          resultEl.className = 'alert alert-warning mb-0';
          resultEl.textContent = '报价失败：网络或接口异常';
        })
        .finally(() => { inflight = false; });
    };

    const schedule = function() {
      if (timer) clearTimeout(timer);
      timer = setTimeout(run, 450);
    };
    fromEl.addEventListener('input', schedule);
    toEl.addEventListener('input', schedule);
    amountEl.addEventListener('input', schedule);
    run();
  })();
</script>

<?php require_once 'includes/footer.php'; ?>
