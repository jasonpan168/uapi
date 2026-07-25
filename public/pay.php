<?php
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Services/SecurityService.php';
require_once __DIR__ . '/../src/Core/I18n.php';

session_start();
I18n::init();
$db = Database::getInstance();
$sec = new SecurityService($db);
$client_ip = $_SERVER['REMOTE_ADDR'];
$is_en = I18n::getLang() === 'en';
$tt = static function (string $zh, string $en) use ($is_en): string {
    return $is_en ? $en : $zh;
};
$current_lang = I18n::getLang();
$lang_zh_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-cn']));
$lang_en_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'en']));

// 1. Check IP Block
$block_reason = $sec->checkBlocked($client_ip);
if ($block_reason) {
    die("<!DOCTYPE html><html><head><title>Access Denied</title><link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'></head><body class='bg-light'><div class='container mt-5 text-center'><div class='card shadow-sm p-5'><h1 class='text-danger'>Access Denied</h1><p class='lead'>".$tt('您的 IP 已被暂时封禁。', 'Your IP has been temporarily blocked.')."</p><p class='text-muted'>".$tt('原因', 'Reason').": ".htmlspecialchars($block_reason)."</p></div></div></body></html>");
}

$order_no = $_GET['order'] ?? '';
if (!$order_no) {
    die("Invalid Order");
}

// 2. Admin Check
$is_admin = false;
if (isset($_SESSION['user_id'])) {
    $u = $db->fetch("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
    if ($u && $u['role'] === 'admin') $is_admin = true;
}

// 3. Handle Takeover (Force Continue)
if (isset($_GET['action']) && $_GET['action'] === 'takeover') {
    $sec->clearOrderSessions($order_no);
    $takeoverToken = isset($_GET['token']) ? '&token=' . urlencode((string)$_GET['token']) : '';
    header("Location: pay.php?order=" . $order_no . $takeoverToken);
    exit;
}

$session_token = '';

// Fetch Order with Wallet info
$order = $db->fetch("SELECT o.*, w.address as wallet_address 
    FROM orders o 
    LEFT JOIN wallets w ON o.wallet_id = w.id 
    WHERE o.order_no = ?", [$order_no]);

if (!$order) {
    // Show friendly expired/not found page instead of die()
    $site_name = 'UAPI'; // Fallback
    try {
        $settings = $db->fetchAll("SELECT * FROM system_settings");
        foreach ($settings as $s) { if($s['key_name']=='site_name') $site_name = $s['value']; }
    } catch (Exception $e) {}
    ?>
    <!DOCTYPE html>
    <html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo $tt('订单不存在', 'Order Not Found'); ?> - <?php echo htmlspecialchars($site_name); ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="/assets/css/all.min.css">
        <style>body { background: #f8f9fa; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }</style>
    </head>
    <body>
        <div class="container mt-5 pt-5">
            <div class="card shadow-sm border-0 mx-auto" style="max-width: 480px; border-radius: 16px;">
                <div class="card-body text-center p-5">
                    <i class="fas fa-exclamation-circle text-secondary mb-3" style="font-size: 4rem;"></i>
                    <h3 class="mb-3"><?php echo $tt('订单不存在或已过期', 'Order Not Found or Expired'); ?></h3>
                    <p class="text-muted mb-4"><?php echo $tt('该订单可能因超时未支付已被系统自动取消。', 'This order may have been automatically canceled due to timeout.'); ?></p>
                    <a href="/" class="btn btn-primary px-4"><?php echo $tt('返回首页', 'Back to Home'); ?></a>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
	exit;
}

// Resolve a better return path by order source / same-host referrer.
$current_host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$referrer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$return_path = '/';
$return_label = $tt('返回页面', 'Back to Page');

if ($referrer !== '') {
    $parts = parse_url($referrer);
    $refHost = strtolower((string)($parts['host'] ?? ''));
    $refPath = (string)($parts['path'] ?? '');
    $refQuery = (string)($parts['query'] ?? '');
    if ($refHost !== '' && $refHost === $current_host && $refPath !== '' && stripos($refPath, '/pay.php') === false) {
        $candidate = $refPath . ($refQuery !== '' ? ('?' . $refQuery) : '');
        if ($candidate !== '') {
            $return_path = $candidate;
            $return_label = $tt('返回来源页面', 'Back to Previous Page');
        }
    }
}

if (!empty($order['source'])) {
    $source = strtolower((string)$order['source']);
    $source_id = (int)($order['source_id'] ?? 0);
    if ($source === 'store' && $source_id > 0) {
        $return_path = '/shop.php?id=' . $source_id;
        $return_label = $tt('返回店铺', 'Back to Store');
    } elseif ($source === 'qr_code' && $source_id > 0) {
        $return_path = '/qr_pay.php?id=' . $source_id;
        $return_label = $tt('返回收款页', 'Back to QR Checkout');
    } elseif ($source === 'recharge') {
        $return_path = '/balance.php';
        $return_label = $tt('返回余额页', 'Back to Balance');
    } elseif ($source === 'payment_link') {
        $return_path = '/dashboard.php';
        $return_label = $tt('返回控制台', 'Back to Dashboard');
    }
}

// Signed checkout token check (admin can bypass for support)
$request_token = (string)($_GET['token'] ?? '');
if (!$is_admin) {
    $stored_token = (string)($order['pay_access_token'] ?? '');
    if ($stored_token === '' || $request_token === '' || !hash_equals($stored_token, $request_token)) {
        http_response_code(403);
        ?>
        <!DOCTYPE html>
        <html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo $tt('链接无效', 'Invalid Link'); ?> - UAPI</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
            <div class="container mt-5 pt-5">
                <div class="card border-0 shadow-sm mx-auto" style="max-width: 520px; border-radius: 16px;">
                    <div class="card-body text-center p-5">
                        <h3 class="mb-3"><?php echo $tt('支付链接无效或已失效', 'Checkout link is invalid or expired'); ?></h3>
                        <p class="text-muted mb-4"><?php echo $tt('请返回商户页面重新发起订单。', 'Please create a new order from the merchant site.'); ?></p>
                        <a href="<?php echo htmlspecialchars($return_path); ?>" class="btn btn-primary px-4"><?php echo htmlspecialchars($return_label); ?></a>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

// Site settings
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';

// Load merchant brand customization
$payBrandColor = '';
$payBrandNote  = '';
if (!empty($order['user_id'])) {
    $colorKey = 'merchant_brand_color_u' . (int)$order['user_id'];
    $noteKey  = 'merchant_brand_note_u'  . (int)$order['user_id'];
    $payBrandColor = $cfg[$colorKey] ?? '';
    $payBrandNote  = $cfg[$noteKey]  ?? '';
    if ($payBrandColor !== '' && !preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $payBrandColor)) {
        $payBrandColor = '';
    }
}

// Check expiration by DB clock (avoid PHP/DB timezone mismatch)
$expire_seconds = 600; // 10 minutes
$expiry_meta = $db->fetch(
    "SELECT
        UNIX_TIMESTAMP(COALESCE(expire_at, DATE_ADD(created_at, INTERVAL 600 SECOND))) AS exp_ts,
        TIMESTAMPDIFF(SECOND, NOW(), COALESCE(expire_at, DATE_ADD(created_at, INTERVAL 600 SECOND))) AS remaining_seconds
     FROM orders
     WHERE id = ?
     LIMIT 1",
    [$order['id']]
);
$expire_at_ts = isset($expiry_meta['exp_ts']) ? (int)$expiry_meta['exp_ts'] : 0;
$remaining_seconds = isset($expiry_meta['remaining_seconds']) ? (int)$expiry_meta['remaining_seconds'] : 0;
$is_paid = $order['status'] === 'paid';
$is_expired = ($order['status'] === 'expired') || ((!$is_paid) && ($remaining_seconds <= 0));

// Expired pending order should be marked, not deleted
if ($is_expired && $order['status'] === 'pending') {
    $db->query("UPDATE orders SET status='expired', updated_at=NOW() WHERE id = ? AND status='pending'", [$order['id']]);
    $order['status'] = 'expired';
}
$is_deleted = false;

// Track Payment Page Concurrency only while order is still pending.
// After paid/expired, page refresh should not be blocked by stale active sessions.
if (!$is_admin && !$is_paid && !$is_expired) {
    // Per-page token (avoid multi-tab bypass caused by shared PHP session token)
    $session_token = bin2hex(random_bytes(16));
    $_SESSION['pay_token'] = $session_token;

    $allowed = $sec->trackPaymentPage($order_no, $session_token, $client_ip, false);
    if (!$allowed) {
        $token_takeover_qs = isset($_GET['token']) ? '&token=' . urlencode((string)$_GET['token']) : '';
        ?>
        <!DOCTYPE html>
        <html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
        <head>
            <meta charset="UTF-8">
            <title><?php echo $tt('页面冲突', 'Page Conflict'); ?> - UAPI</title>
            <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <style>body { background: #f8f9fa; display: flex; align-items: center; justify-content: center; height: 100vh; }</style>
        </head>
        <body>
            <div class="card shadow text-center p-5" style="max-width: 500px;">
                <div class="mb-4 text-warning" style="font-size: 4rem;"><i class="fas fa-exclamation-triangle"></i></div>
                <h3><?php echo $tt('该订单正在其他页面进行中', 'This order is active in another page'); ?></h3>
                <p class="text-muted my-3"><?php echo $tt('为避免同一订单重复轮询，本订单一次仅允许一个页面保持监控。<br>如果您希望在当前页面继续，请点击下方按钮接管。', 'To avoid duplicate polling for the same order, only one active monitor is allowed at a time.<br>If you want to continue here, click the button below to take over.'); ?></p>
                <div class="d-grid gap-2">
                    <a href="?order=<?php echo htmlspecialchars($order_no); ?>&action=takeover<?php echo htmlspecialchars($token_takeover_qs); ?>" class="btn btn-primary"><?php echo $tt('在当前页面继续', 'Continue on this page'); ?></a>
                </div>
            </div>
            <link rel="stylesheet" href="/assets/css/all.min.css">
        </body>
        </html>
        <?php
        exit;
    }
}

// Special handling for Upgrade Orders
$is_upgrade = strpos($order['merchant_order_id'], 'PLAN-') === 0;
$order_title = $is_upgrade ? $tt('升级套餐', 'Plan Upgrade') : $tt('支付订单', 'Payment Order');
$amount_raw = (string)($order['amount'] ?? '0');
$amount_display = rtrim(rtrim($amount_raw, '0'), '.');
if ($amount_display === '') {
    $amount_display = '0';
}
if ($is_upgrade) {
    $return_path = '/upgrade.php';
    $return_label = $tt('返回套餐升级', 'Back to Upgrade');
}

// If it's a Stripe order but landed here, redirect (edge case)
if ($order['chain'] === 'stripe' && !$is_paid) {
    header("Location: stripe_pay.php?order=" . $order['order_no']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $tt('收银台', 'Checkout'); ?> - <?php echo htmlspecialchars($site_name); ?></title>
    <?php if (!empty($cfg['site_favicon'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($cfg['site_favicon']); ?>">
    <?php endif; ?>
    <!-- Fonts -->
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/lang-switch.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        :root {
            --primary-color: #3b82f6;
            --secondary-color: #64748b;
            --success-color: #10b981;
            --bg-color: #f3f4f6;
            --card-bg: #ffffff;
        }
        
        body {
            background-color: var(--bg-color);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #1e293b;
            min-height: 100vh;
            display: flex;
            align-items: center;
            padding: 20px 0;
        }

        .pay-container {
            max-width: 1000px;
            width: 100%;
            margin: 0 auto;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 24px;
            box-shadow: 0 20px 40px -5px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.5);
            overflow: hidden;
        }

        /* Left Column Styles */
        .info-section {
            padding: 40px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-right: 1px solid #f1f5f9;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .order-meta {
            font-size: 0.875rem;
            color: var(--secondary-color);
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
        }

        .order-meta .badge {
            background: #e2e8f0;
            color: #475569;
            font-weight: 500;
            margin-right: 10px;
        }

        .copy-inline {
            border: 0;
            background: transparent;
            color: #3b82f6;
            padding: 0;
            margin-left: 8px;
            line-height: 1;
            cursor: pointer;
        }

        .amount-wrapper {
            margin-bottom: 2.5rem;
        }

        .amount-label {
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--secondary-color);
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .amount-value {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 3.5rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1;
            letter-spacing: -1px;
        }

        .currency {
            font-size: 1.5rem;
            color: var(--secondary-color);
            font-weight: 600;
            margin-left: 0.5rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .currency img {
            width: 26px;
            height: 26px;
            object-fit: contain;
            vertical-align: middle;
        }

        .network-tag {
            display: inline-flex;
            align-items: center;
            padding: 8px 16px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 50px;
            font-weight: 600;
            color: #334155;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .network-summary {
            width: 100%;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 12px;
        }

        .network-reminder-trigger {
            border: 0;
            background: rgba(245, 158, 11, 0.14);
            color: #b45309;
            border-radius: 999px;
            padding: 9px 14px;
            font-size: 0.8125rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: default;
        }

        .pay-alert-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: #9a3412;
            margin-bottom: 4px;
        }

        .pay-alert-body {
            font-size: 0.84rem;
            line-height: 1.6;
            color: #9a3412;
            margin: 0;
        }

        .pay-alert-body strong {
            color: #7c2d12;
        }

        /* Timer Styles */
        .timer-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: auto;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02);
        }

        .timer-card-mobile { display: none; }

        .timer-label {
            font-size: 0.875rem;
            color: var(--secondary-color);
            display: flex;
            align-items: center;
        }

        .timer-digits {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 1.5rem;
            font-weight: 700;
            color: #ef4444;
            background: #fef2f2;
            padding: 4px 12px;
            border-radius: 8px;
        }

        /* Right Column Styles */
        .action-section {
            padding: 40px;
            background: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        .qr-wrapper {
            padding: 20px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 0 0 1px #f1f5f9;
            margin-bottom: 2rem;
            position: relative;
        }

        .qr-wrapper::after {
            content: '';
            position: absolute;
            top: 20px;
            left: 20px;
            right: 20px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #3b82f6, transparent);
            animation: scan 2s ease-in-out infinite;
            border-radius: 50%;
        }

        @keyframes scan {
            0% { top: 20px; opacity: 0; }
            50% { opacity: 1; }
            100% { top: calc(100% - 20px); opacity: 0; }
        }

        .address-box {
            width: 100%;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            transition: all 0.2s;
            cursor: pointer;
        }

        .address-box:hover {
            border-color: #cbd5e1;
            background: #f1f5f9;
        }

        .wallet-address {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.875rem;
            color: #334155;
            word-break: break-all;
            margin-right: 10px;
        }

        .address-title {
            width: 100%;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 8px;
            text-align: left;
        }

        .notice-points {
            display: grid;
            gap: 10px;
            margin-top: 14px;
        }

        .notice-point {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }

        .notice-point-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
        }

        .notice-point strong {
            display: block;
            font-size: 0.9rem;
            color: #0f172a;
            margin-bottom: 3px;
        }

        .notice-point span {
            display: block;
            font-size: 0.82rem;
            line-height: 1.55;
            color: #475569;
        }

        .notice-copy-btn {
            min-height: 44px;
            border-radius: 999px;
            font-weight: 600;
        }

        /* Colorful Spinner */
        .loading-status {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            background: #f8fafc;
            border-radius: 50px;
            border: 1px solid #e2e8f0;
        }

        .spinner-colorful {
            width: 24px;
            height: 24px;
            border: 3px solid rgba(0,0,0,0.05);
            border-left-color: #3b82f6;
            border-top-color: #ef4444;
            border-right-color: #f59e0b;
            border-bottom-color: #10b981;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin { 100% { transform: rotate(360deg); } }

        /* Mobile Optimization */
        @media (max-width: 991px) {
            body { align-items: flex-start; padding: 8px 0; }
            .pay-container { padding: 0 8px; }
            .glass-card { border-radius: 14px; }
            .info-section { 
                padding: 14px 14px 10px;
                border-right: none; 
                text-align: center;
                align-items: center;
                gap: 8px;
            }
            .order-meta { justify-content: center; margin-bottom: 6px; }
            .order-meta .font-monospace { font-size: 0.82rem; }
            .amount-wrapper { margin-bottom: 8px; }
            .amount-value { font-size: 2rem; }
            .currency { font-size: 1rem; }
            .network-tag { padding: 6px 10px; font-size: 0.82rem; }
            .network-summary { justify-content: center; gap: 10px; }
            .network-reminder-trigger {
                width: 100%;
                justify-content: center;
                min-height: 44px;
                padding: 10px 14px;
                font-size: 0.82rem;
            }
            .info-section .mb-5 { margin-bottom: 0.35rem !important; }
            .action-section { padding: 8px 14px 14px; }
            .address-box { margin-bottom: 0.6rem; padding: 10px 12px; }
            .wallet-address { font-size: 0.79rem; }
            .qr-wrapper { margin-bottom: 0.7rem; padding: 12px; }
            #qrcode canvas, #qrcode img { width: 132px !important; height: 132px !important; }
            .loading-status { width: 100%; justify-content: center; padding: 8px 12px; }
            .timer-card { display: none; }
            .timer-card-mobile { display: flex; width: 100%; margin-top: 10px; }
            .manual-check-wrap { margin-top: 0.65rem !important; }
            .helper-copy { display: none; }
            .notice-point { padding: 11px 12px; }
            .notice-copy-btn { width: 100%; }
        }

<?php if ($payBrandColor): ?>
        :root { --primary-color: <?php echo htmlspecialchars($payBrandColor); ?>; }
        .btn-primary { background-color: <?php echo htmlspecialchars($payBrandColor); ?> !important; border-color: <?php echo htmlspecialchars($payBrandColor); ?> !important; }
<?php endif; ?>
    </style>
</head>
<body>

<div class="container pay-container">
    <div class="card glass-card">
        <div class="row g-0">
            <!-- Left Column: Order Info -->
            <div class="col-lg-6 info-section">
                <!-- Site Brand -->
                <div class="d-flex align-items-center justify-content-center justify-content-lg-start mb-5">
                    <?php if($site_logo): ?>
                        <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="Logo" style="height: 48px; width: auto;" class="me-3 rounded">
                    <?php else: ?>
                        <!-- Fallback Logo if none uploaded -->
                        <div style="width: 48px; height: 48px; background: #3b82f6; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                            <i class="fas fa-wallet text-white fs-4"></i>
                        </div>
                    <?php endif; ?>
                    <div class="ms-auto">
                        <div class="lang-switch" aria-label="<?php echo $tt('语言', 'Language'); ?>">
                            <a class="<?php echo $current_lang === 'zh-cn' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_zh_url); ?>">中</a>
                            <a class="<?php echo $current_lang === 'en' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_en_url); ?>">EN</a>
                        </div>
                    </div>
                </div>

                <!-- Top: Order ID -->
                <div class="order-meta">
                    <span class="badge">ORDER</span>
                    <span class="font-monospace text-muted" id="orderNoText">#<?php echo $order['order_no']; ?></span>
                    <button type="button" class="copy-inline" onclick="copyText(<?php echo json_encode($order['order_no']); ?>, this)" title="<?php echo $tt('复制订单号', 'Copy order number'); ?>">
                        <i class="far fa-copy"></i>
                    </button>
                </div>

                <!-- Middle: Amount & Network -->
                <div class="amount-wrapper">
                    <div class="amount-label"><?php echo $tt('支付金额', 'Amount'); ?></div>
                    <div class="d-flex align-items-baseline justify-content-center justify-content-lg-start">
                        <span class="amount-value" id="amountText"><?php echo htmlspecialchars($amount_display); ?></span>
                        <button type="button" class="copy-inline helper-copy" onclick="copyText(<?php echo json_encode($amount_display); ?>, this)" title="<?php echo $tt('复制金额', 'Copy amount'); ?>">
                            <i class="far fa-copy"></i>
                        </button>
                        <span class="currency">
                            <?php if($order['currency'] == 'USDC'): ?>
                                <img src="https://cryptologos.cc/logos/usd-coin-usdc-logo.png?v=025" onerror="this.onerror=null;this.src='/assets/usdt.svg';"> USDC
                            <?php else: ?>
                                <img src="/assets/usdt.svg" onerror="this.style.display='none'"> USDT
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <div class="mb-5">
                    <div class="network-summary">
                        <div class="network-tag">
                            <img src="https://cryptologos.cc/logos/<?php echo strtolower($order['chain'] == 'trc20' ? 'tron' : ($order['chain'] == 'bsc' ? 'bnb' : ($order['chain'] == 'solana' ? 'solana' : 'ethereum'))); ?>-<?php echo strtolower($order['chain'] == 'trc20' ? 'trx' : ($order['chain'] == 'bsc' ? 'bnb' : ($order['chain'] == 'solana' ? 'sol' : 'eth'))); ?>-logo.png?v=025" 
                                 onerror="this.style.display='none'" height="20" class="me-2">
                            <span><?php echo $tt('网络', 'Network'); ?>: <?php echo strtoupper($order['chain']); ?></span>
                        </div>
                        <?php if (!$is_paid && !$is_expired && !$is_deleted): ?>
                        <div class="network-reminder-trigger" aria-label="<?php echo $tt('请先确认网络与到账金额', 'Confirm network and received amount first'); ?>">
                            <i class="fas fa-shield-alt"></i>
                            <span><?php echo $tt('请先确认网络与到账金额', 'Confirm network and received amount first'); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Bottom: Timer & Calc -->
                <div class="timer-card" id="timerCardDesktop">
                    <div class="timer-label">
                        <i class="far fa-clock me-2"></i> <?php echo $tt('剩余支付时间', 'Time Remaining'); ?>
                    </div>
                    <div id="countdown" class="timer-digits">--:--</div>
                </div>
                
                <div class="mt-3 w-100 text-center text-lg-start">
                    <button class="btn btn-link text-decoration-none text-muted p-0 small" onclick="showCalculator()">
                        <i class="fas fa-calculator me-1"></i> <?php echo $tt('计算手续费', 'Fee Calculator'); ?>
                    </button>
                </div>
            </div>

            <!-- Right Column: Action -->
            <div class="col-lg-6 action-section">
                <?php if ($is_paid): ?>
                    <div class="text-center">
                        <div class="mb-4">
                            <div style="width: 80px; height: 80px; background: #dcfce7; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="fas fa-check text-success" style="font-size: 36px;"></i>
                            </div>
                        </div>
                        <h3 class="fw-bold mb-2"><?php echo $tt('支付成功!', 'Payment Successful!'); ?></h3>
                        <p class="text-muted mb-4"><?php echo $tt('您的订单已确认，页面即将关闭。', 'Your order is confirmed. This page will close shortly.'); ?></p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                            <button type="button" onclick="backToSourcePage()" class="btn btn-outline-primary px-4 rounded-pill"><?php echo htmlspecialchars($return_label); ?></button>
                            <button type="button" onclick="closePayPage()" class="btn btn-primary px-4 rounded-pill"><?php echo $tt('关闭页面', 'Close Page'); ?></button>
                        </div>
                    </div>
                <?php elseif ($is_expired || $is_deleted): ?>
                    <div class="text-center">
                        <div class="mb-4">
                            <div style="width: 80px; height: 80px; background: #fee2e2; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="fas fa-times text-danger" style="font-size: 36px;"></i>
                            </div>
                        </div>
                        <h3 class="fw-bold mb-2"><?php echo $tt('订单已过期', 'Order Expired'); ?></h3>
                        <p class="text-muted mb-4"><?php echo $tt('订单超时未支付已被取消。', 'This order timed out and has been canceled.'); ?></p>
                        <a href="<?php echo htmlspecialchars($return_path); ?>" class="btn btn-outline-primary px-4 rounded-pill"><?php echo htmlspecialchars($return_label); ?></a>
                    </div>
                <?php else: ?>
                    <div class="address-title"><?php echo $tt('收款地址（点击复制）', 'Receiving address (tap to copy)'); ?></div>
                    <div class="address-box" onclick="copyAddress()" title="<?php echo $tt('点击复制', 'Click to copy'); ?>">
                        <div class="wallet-address text-truncate" id="walletAddr"><?php echo $order['wallet_address']; ?></div>
                        <i class="far fa-copy text-primary" id="copyIcon"></i>
                    </div>

                    <!-- QR Code -->
                    <div class="qr-wrapper">
                        <div id="qrcode"></div>
                    </div>

                    <p class="text-muted small mb-2"><?php echo $tt('请使用支持', 'Use a wallet that supports'); ?> <strong><?php echo strtoupper($order['chain']); ?></strong> <?php echo $tt('的钱包扫码', 'to scan and pay'); ?></p>

                    <div class="timer-card timer-card-mobile">
                        <div class="timer-label">
                            <i class="far fa-clock me-2"></i> <?php echo $tt('剩余支付时间', 'Time Remaining'); ?>
                        </div>
                        <div id="countdownMobile" class="timer-digits">--:--</div>
                    </div>

                    <!-- Status -->
                    <div class="loading-status">
                        <div class="spinner-colorful"></div>
                        <span class="small fw-medium text-secondary"><?php echo $tt('正在等待区块链确认...', 'Waiting for blockchain confirmation...'); ?></span>
                    </div>

                    <!-- Manual Check -->
                    <div class="mt-4 w-100 manual-check-wrap">
                        <div class="text-center">
                            <button class="btn btn-sm btn-link text-decoration-none text-muted small" type="button" onclick="toggleManualCheck()">
                                <?php echo $tt('支付遇到问题？', 'Having payment issues?'); ?><span class="text-primary"><?php echo $tt('手动验证', 'Manual Verify'); ?></span>
                            </button>
                        </div>
                        <div class="collapse mt-3" id="manualCheck">
                            <div class="card card-body bg-light border-0 p-3">
                                <label class="form-label small text-muted"><?php echo $tt('输入交易哈希 (TXID)', 'Enter transaction hash (TXID)'); ?></label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" id="txHashInput" placeholder="<?php echo $tt('请输入 Hash...', 'Enter hash...'); ?>">
                                    <button class="btn btn-primary" type="button" onclick="checkHash()"><?php echo $tt('验证', 'Verify'); ?></button>
                                </div>
                                <div id="hashMsg" class="mt-2 small"></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if (!empty($payBrandNote)): ?>
    <div class="text-center text-muted small mt-3 mb-0" style="max-width:460px;margin:0 auto;font-size:12px;opacity:0.8;">
        <?php echo htmlspecialchars($payBrandNote); ?>
    </div>
    <?php endif; ?>
</div>

<!-- Fee Calculator Modal -->
<div class="modal fade" id="calcModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pb-0">
                <h6 class="modal-title fw-bold"><?php echo $tt('手续费计算器', 'Fee Calculator'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-3">
                <div class="alert alert-warning small border-0 rounded-4 py-3 px-3 mb-3" style="background: #fff7ed; color: #9a3412;">
                    <div class="fw-semibold mb-1"><?php echo $tt('此计算器按“手续费额外支付”方式估算', 'This calculator estimates when the fee is charged separately'); ?></div>
                    <div><?php echo $tt('如果您的交易所会从提现金额中直接扣除手续费，请提高提现金额，确保我方实际到账仍为订单金额。', 'If your exchange deducts the fee from the withdrawal amount itself, increase the withdrawal amount so the amount we receive still matches the order amount.'); ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted"><?php echo $tt('您预计额外支付的提现手续费', 'Estimated withdrawal fee paid separately'); ?></label>
                    <div class="input-group">
                        <input type="number" id="feeInput" class="form-control border-light bg-light" placeholder="0.00" step="0.01" oninput="calcTotal()">
                        <span class="input-group-text border-light bg-light text-muted"><?php echo htmlspecialchars($order['currency'] ?? 'USDT'); ?></span>
                    </div>
                </div>
                <div class="bg-primary bg-opacity-10 p-3 rounded-3 text-center">
                    <p class="small text-primary mb-1 fw-bold"><?php echo $tt('若手续费单独扣除，提现金额可填写：', 'If the fee is charged separately, the withdrawal amount can be:'); ?></p>
                    <h4 class="text-primary fw-bold mb-0" id="calcResult">--</h4>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const orderAmount = <?php echo $order['amount']; ?>;
    const orderNumber = <?php echo json_encode((string)$order['order_no']); ?>;
    const orderWalletAddress = <?php echo json_encode((string)$order['wallet_address']); ?>;
    const payReturnPath = <?php echo json_encode((string)$return_path); ?>;
    const payReferrer = <?php echo json_encode((string)($_SERVER['HTTP_REFERER'] ?? '')); ?>;

    function isSafeReturnUrl(url) {
        try {
            if (!url) return false;
            const u = new URL(url, window.location.origin);
            const p = u.pathname || '';
            if (p.includes('/pay.php')) return false;
            return true;
        } catch (e) {
            return false;
        }
    }

    function backToSourcePage() {
        try {
            if (window.opener && !window.opener.closed) {
                try { window.opener.focus(); } catch (e) {}
                window.close();
                return;
            }
        } catch (e) {}

        if (isSafeReturnUrl(payReferrer)) {
            window.location.href = payReferrer;
            return;
        }

        if (window.history.length > 1) {
            window.history.back();
            return;
        }

        window.location.href = payReturnPath || '/';
    }

    function closePayPage() {
        // Most browsers block window.close() on non-script-opened tabs.
        try { window.close(); } catch (e) {}
        setTimeout(() => {
            if (window.history.length > 1) {
                window.history.back();
                return;
            }
            window.location.href = payReturnPath || '/';
        }, 180);
    }

    function showCalculator() {
        const modal = new bootstrap.Modal(document.getElementById('calcModal'));
        modal.show();
        document.getElementById('feeInput').value = '';
        document.getElementById('calcResult').innerText = orderAmount.toFixed(6);
    }

    function calcTotal() {
        const fee = parseFloat(document.getElementById('feeInput').value) || 0;
        const total = orderAmount + fee;
        document.getElementById('calcResult').innerText = total.toFixed(6);
    }

    <?php if (!$is_paid && !$is_expired): ?>
    // QR Code
    new QRCode(document.getElementById("qrcode"), {
        text: orderWalletAddress,
        width: 180,
        height: 180,
        colorDark : "#1e293b",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });

    function performClipboardCopy(text, triggerEl) {
        if (!navigator.clipboard || !navigator.clipboard.writeText) {
            return Promise.reject(new Error('Clipboard API unavailable'));
        }
        return navigator.clipboard.writeText(text).then(() => {
            const icon = triggerEl && triggerEl.tagName === 'I'
                ? triggerEl
                : (triggerEl ? triggerEl.querySelector('i') : null);
            if (!icon) return;
            const originalClass = icon.className;
            icon.className = 'fas fa-check text-success';
            setTimeout(() => { icon.className = originalClass; }, 1500);
        });
    }

    function copyAddress() {
        const text = document.getElementById('walletAddr').innerText;
        performClipboardCopy(text, document.getElementById('copyIcon')).catch(() => {});
    }

    function copyText(text, triggerEl) {
        performClipboardCopy(text, triggerEl).catch(() => {});
    }

    // Countdown
    let remainingSeconds = <?php echo max(0, (int)$remaining_seconds); ?>;
    
    function updateTimer() {
        if (remainingSeconds <= 0) {
            document.getElementById("countdown").innerHTML = "00:00";
            clearInterval(window.timerInterval);
            if (window.statusInterval) clearInterval(window.statusInterval);
            location.reload();
            return;
        }
        
        const minutes = Math.floor(remainingSeconds / 60);
        const seconds = remainingSeconds % 60;
        
        const mStr = minutes < 10 ? "0" + minutes : minutes;
        const sStr = seconds < 10 ? "0" + seconds : seconds;
        
        document.getElementById("countdown").innerHTML = `${mStr}:${sStr}`;
        const mobileCountdown = document.getElementById("countdownMobile");
        if (mobileCountdown) {
            mobileCountdown.innerHTML = `${mStr}:${sStr}`;
        }
        
        if (remainingSeconds < 60) {
            document.getElementById("countdown").classList.add('text-danger');
            document.getElementById("countdown").style.backgroundColor = '#fef2f2';
            if (mobileCountdown) {
                mobileCountdown.classList.add('text-danger');
                mobileCountdown.style.backgroundColor = '#fef2f2';
            }
        }
        
        remainingSeconds--;
    }
    
    window.timerInterval = setInterval(updateTimer, 1000);
    updateTimer();

    function toggleManualCheck() {
        const el = document.getElementById('manualCheck');
        new bootstrap.Collapse(el, { toggle: true });
    }

    const payAccessToken = <?php echo json_encode((string)($_GET['token'] ?? '')); ?>;
    const paySessionToken = <?php echo json_encode($session_token); ?>;
    const payTabId = `tab-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const payPageLockKey = `uapi-pay-lock-${orderNumber}`;
    const payPageLockTtlMs = 12000;
    let payPageLockInterval = null;
    let payPageBlocked = false;

    function getPayConflictUrl() {
        const url = new URL(window.location.href);
        url.searchParams.set('action', 'takeover');
        return url.toString();
    }

    function renderPayConflict() {
        if (payPageBlocked) return;
        payPageBlocked = true;
        clearInterval(window.statusInterval);
        clearInterval(window.timerInterval);
        if (payPageLockInterval) {
            clearInterval(payPageLockInterval);
            payPageLockInterval = null;
        }
        document.body.innerHTML = "<div class='container mt-5'><div class='alert alert-warning text-center'><h3><?php echo jsesc($tt('该订单正在其他页面进行中', 'This order is active in another page')); ?></h3><p><?php echo jsesc($tt('为避免同一订单重复轮询，本订单一次仅允许一个页面保持监控。<br>如果您希望在当前页面继续，请点击下方按钮接管。', 'To avoid duplicate polling for the same order, only one active monitor is allowed at a time.<br>If you want to continue here, click the button below to take over.')); ?></p><a href='" + getPayConflictUrl() + "' class='btn btn-primary'><?php echo jsesc($tt('在当前页面继续', 'Continue on this page')); ?></a></div></div>";
    }

    function readPayPageLock() {
        try {
            const raw = localStorage.getItem(payPageLockKey);
            return raw ? JSON.parse(raw) : null;
        } catch (e) {
            return null;
        }
    }

    function writePayPageLock() {
        if (payPageBlocked) return;
        try {
            localStorage.setItem(payPageLockKey, JSON.stringify({
                tabId: payTabId,
                ts: Date.now()
            }));
        } catch (e) {}
    }

    function claimPayPageLock() {
        const existing = readPayPageLock();
        if (existing && existing.tabId !== payTabId && (Date.now() - Number(existing.ts || 0) < payPageLockTtlMs)) {
            renderPayConflict();
            return false;
        }
        writePayPageLock();
        return true;
    }

    function releasePayPageLock() {
        try {
            const existing = readPayPageLock();
            if (existing && existing.tabId === payTabId) {
                localStorage.removeItem(payPageLockKey);
            }
        } catch (e) {}
    }

    if (!claimPayPageLock()) {
        throw new Error('Duplicate pay page blocked');
    }

    payPageLockInterval = setInterval(() => {
        if (!claimPayPageLock()) return;
        writePayPageLock();
    }, 4000);

    window.addEventListener('storage', (event) => {
        if (event.key !== payPageLockKey) return;
        let incoming = null;
        try {
            incoming = event.newValue ? JSON.parse(event.newValue) : null;
        } catch (e) {
            incoming = null;
        }
        if (incoming && incoming.tabId !== payTabId && (Date.now() - Number(incoming.ts || 0) < payPageLockTtlMs)) {
            renderPayConflict();
        }
    });

    window.addEventListener('beforeunload', () => {
        releasePayPageLock();
    });

    // Status Polling
    function checkStatus() {
        fetch('/api/v1/order/heartbeat.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                order_no: '<?php echo $order['order_no']; ?>',
                session_token: paySessionToken
            })
        })
        .then(res => {
            if (res.status === 429 || res.status === 403) {
                clearInterval(window.statusInterval);
                document.body.innerHTML = "<div class='container mt-5'><div class='alert alert-warning text-center'><h3><?php echo jsesc($tt('订单监控已暂停', 'Order monitoring paused')); ?></h3><p><?php echo jsesc($tt('该订单已在其他页面接管。若需继续，请点击刷新后在当前页接管。', 'This order was taken over in another page. Click refresh to resume and take over on this page.')); ?></p><a href='' class='btn btn-primary'><?php echo jsesc($tt('刷新并继续', 'Refresh & Continue')); ?></a></div></div>";
                throw new Error('Blocked');
            }
            return res.json();
        })
        .then(data => {
            if (data.status === 'success') {
                return fetch('/api/v1/order/status.php?order_no=<?php echo $order['order_no']; ?>&token=' + encodeURIComponent(payAccessToken));
            }
        })
        .then(res => {
            if (!res) return;
            if (res.status === 404) {
                location.reload(); 
                return null;
            }
            return res.json();
        })
        .then(data => {
            if (data && data.status === 'paid') {
                location.reload();
            }
        })
        .catch(e => console.log(e));
    }
    window.statusInterval = setInterval(checkStatus, 3000);

    function checkHash() {
        const hash = document.getElementById('txHashInput').value.trim();
        const msg = document.getElementById('hashMsg');
        
        if (hash.length < 10) {
            msg.innerText = <?php echo json_encode($tt('请输入有效的交易哈希', 'Please enter a valid transaction hash')); ?>;
            msg.className = 'text-danger small';
            return;
        }
        
        msg.className = 'text-info small';
        msg.innerText = <?php echo json_encode($tt('正在链上查询，请稍候...', 'Checking on-chain, please wait...')); ?>;
        
        fetch('/api/v1/order/check_hash.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                order_no: '<?php echo $order['order_no']; ?>',
                hash: hash,
                token: payAccessToken
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                msg.className = 'text-success small';
                msg.innerText = <?php echo json_encode($tt('验证成功！页面即将跳转...', 'Verification successful! Redirecting...')); ?>;
                setTimeout(() => location.reload(), 1000);
            } else {
                msg.className = 'text-danger small';
                msg.innerText = data.message || <?php echo json_encode($tt('验证失败，未找到该笔交易', 'Verification failed. Transaction not found')); ?>;
            }
        })
        .catch(err => {
            msg.innerText = <?php echo json_encode($tt('网络请求失败', 'Network request failed')); ?>;
            msg.className = 'text-danger small';
        });
    }
    <?php endif; ?>
</script>
</body>
</html>
