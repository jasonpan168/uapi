<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/StripeService.php';
require_once __DIR__ . '/../src/Services/User2FAService.php';
I18n::init();

$db = Database::getInstance();
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$page_title = __('merchant.upgrade.title');

// Payment Configuration
$enable_usdt = ($cfg['enable_payment_usdt'] ?? '0') === '1';
$enable_stripe = ($cfg['enable_payment_stripe'] ?? '0') === '1';
$enable_binance_pay = ($cfg['enable_payment_binance'] ?? '0') === '1';
$collection_chain = $cfg['payment_collection_chain'] ?? 'trc20';
$enable_upgrade_usdc = ($cfg['enable_usdc'] ?? '0') === '1' && strtolower((string)$collection_chain) !== 'trc20';

$wallet_address = '';
$chain_label = '';
if ($collection_chain === 'trc20') {
    $wallet_address = $cfg['usdt_admin_wallet'] ?? '';
    $chain_label = 'TRC20';
} else {
    $wallet_address = $cfg['usdt_admin_wallet_evm'] ?? '';
    $chain_label = 'ERC20/BEP20';
}

$plans = $db->fetchAll("SELECT * FROM plans ORDER BY price_monthly ASC");

// Get User's Current Plan ID
$user = $db->fetch("SELECT plan_id, two_factor_enabled, two_factor_secret, two_factor_scenes FROM users WHERE id = ?", [$_SESSION['user_id']]);
$current_plan_id = $user['plan_id'] ?? 1; // Default to 1 (Free) if not set
$balanceOtpRequired = User2FAService::isSceneEnabled((array)$user, 'balance_pay');

// Repair legacy Stripe upgrade orders for current user (created before stripe flow fix).
try {
    $db->query(
        "UPDATE orders
         SET chain = 'stripe',
             currency = 'USD',
             source = 'upgrade',
             updated_at = NOW()
         WHERE user_id = ?
           AND (merchant_order_id LIKE 'PLAN-%' OR order_no LIKE 'UPG%')
           AND (source IS NULL OR source = '' OR source = 'api')
           AND (wallet_id IS NULL OR wallet_id = 0)
           AND LOWER(chain) = 'trc20'",
        [$_SESSION['user_id']]
    );
} catch (Throwable $e) {
    // Non-blocking repair.
}

// Auto-reconcile pending Stripe upgrade orders for current user.
// This fixes cases where user paid in Stripe but did not return via success_url.
try {
    $pendingStripeUpgrades = $db->fetchAll(
        "SELECT id, order_no, merchant_order_id, tx_hash, coupon_code
         FROM orders
         WHERE user_id = ?
           AND status = 'pending'
           AND LOWER(chain) = 'stripe'
           AND (source = 'upgrade' OR merchant_order_id LIKE 'PLAN-%' OR order_no LIKE 'UPG%')
         ORDER BY id DESC
         LIMIT 20",
        [$_SESSION['user_id']]
    );

    // Cache recent checkout sessions once for orders missing tx_hash(session_id).
    $recentSessionMap = null;

    foreach ($pendingStripeUpgrades as $po) {
        $sessionId = trim((string)($po['tx_hash'] ?? ''));
        $session = null;

        if ($sessionId !== '' && str_starts_with($sessionId, 'cs_')) {
            try {
                $session = StripeService::getCheckoutSession($sessionId);
            } catch (Throwable $e) {
                $session = null;
            }
        } else {
            // Fallback for legacy rows that didn't store checkout session id.
            if ($recentSessionMap === null) {
                $recentSessionMap = [];
                try {
                    $recent = StripeService::listCheckoutSessions(100, time() - 14 * 86400);
                    foreach ($recent as $s) {
                        $ref = trim((string)($s['client_reference_id'] ?? ''));
                        if ($ref !== '') {
                            $recentSessionMap[$ref] = $s;
                        }
                    }
                } catch (Throwable $e) {
                    $recentSessionMap = [];
                }
            }
            $session = $recentSessionMap[(string)$po['order_no']] ?? null;
        }

        if (!$session || !is_array($session)) {
            continue;
        }

        $paymentStatus = strtolower((string)($session['payment_status'] ?? ''));
        $status = strtolower((string)($session['status'] ?? ''));
        if ($paymentStatus !== 'paid' || $status !== 'complete') {
            continue;
        }

        $txHash = (string)($session['payment_intent'] ?? $session['id'] ?? '');
        $updated = $db->query(
            "UPDATE orders
             SET status='paid',
                 pay_provider='stripe',
                 chain='stripe',
                 currency='USD',
                 source='upgrade',
                 tx_hash=?,
                 paid_at=NOW(),
                 updated_at=NOW()
             WHERE id=?
               AND status='pending'",
            [$txHash, (int)$po['id']]
        );
        if ($updated->rowCount() <= 0) {
            continue;
        }
        $couponCode = strtoupper(trim((string)($po['coupon_code'] ?? '')));
        if ($couponCode !== '') {
            $db->query("UPDATE admin_coupons SET used_count = used_count + 1 WHERE code = ? AND status = 'active'", [$couponCode]);
        }

        // Fulfill plan upgrade once the order is marked paid.
        $parts = explode('-', (string)$po['merchant_order_id']);
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

                $uRow = $db->fetch("SELECT expire_at FROM users WHERE id = ? LIMIT 1", [$_SESSION['user_id']]);
                $currentExpire = (!empty($uRow['expire_at']) && strtotime((string)$uRow['expire_at']) > time())
                    ? (string)$uRow['expire_at']
                    : date('Y-m-d H:i:s');
                $newExpire = date('Y-m-d H:i:s', strtotime($duration, strtotime($currentExpire)));

                $db->query(
                    "UPDATE users
                     SET plan_id = ?,
                         expire_at = ?,
                         fast_sync_remaining = COALESCE(fast_sync_remaining, 0) + ?
                     WHERE id = ?",
                    [$plan_id, $newExpire, $fastSyncGrant, $_SESSION['user_id']]
                );
            }
        }
    }

    // Refresh current plan after reconciliation.
    $user = $db->fetch("SELECT plan_id FROM users WHERE id = ?", [$_SESSION['user_id']]);
    $current_plan_id = $user['plan_id'] ?? 1;
} catch (Throwable $e) {
    // Keep page usable even if reconciliation fails.
}

function format_price_display($value) {
    $formatted = number_format((float)$value, 2, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    return $formatted === '' ? '0' : $formatted;
}

?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
        :root {
            --upgrade-surface-muted: #f8fafc;
            --upgrade-surface-accent: #eff6ff;
            --upgrade-surface-success: #ecfdf5;
            --upgrade-border-soft: #e2e8f0;
            --upgrade-border-strong: #cbd5e1;
            --upgrade-text-muted: #64748b;
            --upgrade-text-soft: #475569;
            --upgrade-stripe-icon: #334155;
            --upgrade-card-bg: linear-gradient(160deg, #ffffff 0%, #f8fafc 100%);
            --upgrade-crypto-bg: #ffffff;
            --upgrade-success-text: #047857;
            --upgrade-primary-text: #1d4ed8;
            --upgrade-overlay-panel: #ffffff;
            --upgrade-overlay-title: #111827;
        }
        [data-bs-theme="dark"] {
            --upgrade-surface-muted: #111827;
            --upgrade-surface-accent: rgba(59, 130, 246, 0.16);
            --upgrade-surface-success: rgba(16, 185, 129, 0.16);
            --upgrade-border-soft: #374151;
            --upgrade-border-strong: #475569;
            --upgrade-text-muted: #94a3b8;
            --upgrade-text-soft: #cbd5e1;
            --upgrade-stripe-icon: #e5e7eb;
            --upgrade-card-bg: linear-gradient(160deg, #1f2937 0%, #111827 100%);
            --upgrade-crypto-bg: #1f2937;
            --upgrade-success-text: #6ee7b7;
            --upgrade-primary-text: #93c5fd;
            --upgrade-overlay-panel: #1f2937;
            --upgrade-overlay-title: #f9fafb;
        }
        .pricing-card {
            border-radius: 18px;
            transition: all 0.25s ease;
            background: var(--upgrade-card-bg);
            position: relative;
            overflow: hidden;
            z-index: 1;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            border: 1px solid var(--upgrade-border-soft);
        }
        .pricing-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 32px rgba(15, 23, 42, 0.11);
        }
        .pricing-card.popular {
            border: 2px solid #2563eb;
            transform: translateY(-2px);
            z-index: 2;
        }
        .popular-badge {
            background: #2563eb;
            color: white;
            padding: 5px 36px;
            font-size: 12px;
            font-weight: bold;
            box-shadow: 0 2px 6px rgba(37, 99, 235, 0.35);
            position: absolute; top: 20px; right: -35px; transform: rotate(45deg);
        }
        .card-price {
            font-size: 2.35rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        .card-price small {
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 400;
        }
        .feature-list { list-style: none; padding: 0; margin: 14px 0 20px; }
        .feature-list li {
            margin-bottom: 9px;
            color: var(--upgrade-text-soft);
            background: var(--upgrade-surface-muted);
            border: 1px solid var(--upgrade-border-soft);
            border-radius: 10px;
            padding: 8px 10px;
            font-size: 0.92rem;
            line-height: 1.45;
        }
        .feature-list i { color: #16a34a; margin-right: 8px; }
        .plan-cycles {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 14px;
        }
        .cycle-chip {
            background: var(--upgrade-surface-accent);
            color: var(--upgrade-primary-text);
            border: 1px solid var(--upgrade-border-strong);
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
        }
        h4 { color: var(--text-primary) !important; }

        /* Payment Modal Styles */
        .payment-option {
            border: 1px solid var(--upgrade-border-soft);
            border-radius: 12px;
            padding: 13px 14px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }
        .payment-option:hover {
            border-color: var(--upgrade-border-strong);
            background-color: var(--upgrade-surface-muted);
        }
        .payment-option.selected {
            border-color: #3b82f6;
            background-color: var(--upgrade-surface-accent);
            box-shadow: 0 0 0 1px #3b82f6;
        }
        .payment-option.disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background-color: var(--upgrade-surface-muted);
        }
        .payment-option .method-title {
            font-size: 1.02rem;
            line-height: 1.2;
        }
        .payment-option .method-subtitle {
            font-size: 0.9rem;
            color: var(--upgrade-text-muted);
        }
        .stripe-brand-row {
            opacity: 1 !important;
            filter: none !important;
        }
        .stripe-brand-row i {
            opacity: 1;
            color: var(--upgrade-stripe-icon) !important;
        }
        .stripe-brand-row .visa { color: #1d4ed8 !important; }
        .stripe-brand-row .mc { color: #dc2626 !important; }
        .stripe-brand-row .amex { color: #0891b2 !important; }
        .crypto-card {
            border: 1px solid var(--upgrade-border-soft);
            border-radius: 8px;
            padding: 8px 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--upgrade-crypto-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: var(--text-primary);
        }
        .crypto-card svg {
            width: 24px;
            height: 24px;
        }
        .crypto-card .fw-bold {
            font-size: 0.9rem;
        }
        .crypto-card:hover {
            background-color: var(--upgrade-surface-muted);
            transform: translateY(-2px);
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .crypto-card.selected {
            border-color: #10b981;
            background-color: var(--upgrade-surface-success);
            color: var(--upgrade-success-text);
            box-shadow: 0 0 0 1px #10b981;
        }
        .crypto-card.selected.usdc {
            border-color: #2563eb;
            background-color: var(--upgrade-surface-accent);
            color: var(--upgrade-primary-text);
            box-shadow: 0 0 0 1px #2563eb;
        }
        .binance-waiting-card {
            width: min(360px, 100%);
            background: var(--upgrade-overlay-panel);
            border: 1px solid var(--upgrade-border-soft);
            border-radius: 14px;
            padding: 16px 16px 14px;
            box-shadow: 0 10px 30px rgba(2, 6, 23, 0.28);
            text-align: center;
        }
        .binance-waiting-spinner {
            width: 30px;
            height: 30px;
            border: 3px solid var(--border-color);
            border-top-color: #f0b90b;
            border-radius: 50%;
            margin: 0 auto 10px;
            animation: binanceSpin .9s linear infinite;
        }
        .binance-waiting-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--upgrade-overlay-title);
        }
        .binance-waiting-desc {
            font-size: 12px;
            color: var(--upgrade-text-muted);
            margin-top: 4px;
        }
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.upgrade.title'); include __DIR__ . '/includes/user_topbar.php'; ?>

            <?php if (!empty($_GET['payment_success'])): ?>
                <div class="alert alert-success border-0 shadow-sm">
                    <?php echo I18n::getLang() === 'en' ? 'Payment successful. Plan has been updated.' : '支付成功，套餐已更新。'; ?>
                </div>
            <?php elseif (!empty($_GET['payment_pending'])): ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    <?php echo I18n::getLang() === 'en' ? 'Payment is pending confirmation. Please refresh in a moment.' : '支付正在确认中，请稍后刷新查看。'; ?>
                </div>
            <?php elseif (!empty($_GET['payment_cancel'])): ?>
                <div class="alert alert-secondary border-0 shadow-sm">
                    <?php echo I18n::getLang() === 'en' ? 'Payment has been cancelled.' : '已取消支付。'; ?>
                </div>
            <?php endif; ?>

            <div class="text-center mb-5">
                <h2 class="mb-3 fw-bold" style="color: var(--text-primary);"><?php echo __('merchant.upgrade.hero_title'); ?></h2>
                <p class="text-secondary"><?php echo __('merchant.upgrade.hero_subtitle'); ?></p>
            </div>

            <div class="row justify-content-center g-4">
                <?php foreach ($plans as $plan): 
                    $is_popular = ($plan['name'] === 'Pro' || $plan['name'] === 'Business'); 
                    $is_current = ($plan['id'] == $current_plan_id);
                    $monthlyPrice = format_price_display($plan['price_monthly'] ?? 0);
                    $yearlyPrice = format_price_display($plan['price_yearly'] ?? 0);
                    $quarterlyPrice = format_price_display($plan['price_quarterly'] ?? 0);
                    $descRaw = trim((string)($plan['description'] ?? ''));
                    $descLines = [];
                    if ($descRaw !== '') {
                        $descLines = preg_split('/\r\n|\r|\n/', $descRaw);
                        $descLines = array_values(array_filter(array_map('trim', $descLines), function ($line) {
                            return $line !== '';
                        }));
                    }
                    if (empty($descLines)) {
                        $descLines = [
                            __('merchant.upgrade.feature.daily_limit', ['value' => ((int)($plan['api_limit_daily'] ?? 0) > 0 ? (int)$plan['api_limit_daily'] : __('merchant.upgrade.unlimited'))]),
                            __('merchant.upgrade.feature.api_access'),
                            __('merchant.upgrade.feature.tg', ['value' => !empty($plan['allow_tg_bot']) ? __('merchant.common.yes') : __('merchant.common.no')]),
                            __('merchant.upgrade.feature.email', ['value' => !empty($plan['allow_email_notice']) ? __('merchant.common.yes') : __('merchant.common.no')]),
                            __('merchant.upgrade.feature.webhook', ['value' => (!isset($plan['allow_webhook_notice']) || !empty($plan['allow_webhook_notice'])) ? __('merchant.common.yes') : __('merchant.common.no')]),
                            (I18n::getLang() === 'en'
                                ? ('Derived Wallet: ' . (!empty($plan['allow_derived_wallet']) ? 'Supported' : 'Not Supported'))
                                : ('派生钱包功能: ' . (!empty($plan['allow_derived_wallet']) ? '支持' : '不支持'))),
                        ];
                    }
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="pricing-card p-4 h-100 <?php echo $is_popular ? 'popular' : ''; ?>">
                        <?php if ($is_popular): ?>
                        <div class="popular-badge"><?php echo __('merchant.upgrade.popular'); ?></div>
                        <?php endif; ?>
                        
                        <h4 class="text-uppercase fw-bold text-primary mb-3">
                            <?php echo htmlspecialchars($plan['name']); ?>
                            <?php if($is_current): ?>
                                <span class="badge bg-success ms-2 fs-6 align-middle" style="font-size: 0.6em !important;"><?php echo __('merchant.upgrade.current'); ?></span>
                            <?php endif; ?>
                        </h4>
                        <div class="card-price mb-4">
                            $<?php echo $monthlyPrice; ?><small><?php echo __('merchant.upgrade.per_month'); ?></small>
                        </div>

                        <div class="plan-cycles">
                            <?php if ((float)$plan['price_quarterly'] > 0): ?>
                                <span class="cycle-chip"><?php echo __('merchant.upgrade.cycle.monthly'); ?> x3: $<?php echo $quarterlyPrice; ?></span>
                            <?php endif; ?>
                            <?php if ((float)$plan['price_yearly'] > 0): ?>
                                <span class="cycle-chip"><?php echo __('merchant.upgrade.cycle.yearly'); ?>: $<?php echo $yearlyPrice; ?></span>
                            <?php endif; ?>
                        </div>
                        
                        <ul class="feature-list">
                            <?php foreach ($descLines as $line): ?>
                                <li><i class="fas fa-check"></i> <?php echo htmlspecialchars($line); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <div class="d-grid gap-2 mt-auto">
                            <?php if ($is_current): ?>
                                <button class="btn btn-success" disabled>
                                    <i class="fas fa-check-circle me-2"></i><?php echo __('merchant.upgrade.current_in_use'); ?>
                                </button>
                            <?php else: ?>
                                <?php if ($plan['price_monthly'] > 0): ?>
                                <button class="btn btn-outline-primary" onclick="selectPlan(<?php echo (int)$plan['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$plan['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (float)$plan['price_monthly']; ?>, 'monthly')"><?php echo __('merchant.upgrade.subscribe_monthly'); ?></button>
                                <?php if ($plan['price_yearly'] > 0): ?>
                                <button class="btn btn-primary" onclick="selectPlan(<?php echo (int)$plan['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$plan['name'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo (float)$plan['price_yearly']; ?>, 'yearly')">
                                    <?php echo __('merchant.upgrade.subscribe_yearly'); ?> ($<?php echo $yearlyPrice; ?>)
                                </button>
                                <?php endif; ?>
                                <?php else: ?>
                                <button class="btn btn-secondary" disabled><?php echo __('merchant.upgrade.free_plan'); ?></button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo __('merchant.upgrade.modal.confirm_order'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="text-center mb-4">
                    <h5 class="text-secondary mb-1 small text-uppercase ls-1"><?php echo __('merchant.upgrade.modal.plan'); ?></h5>
                    <h3 class="fw-bold text-dark mb-0"><span id="planName"></span> <span class="badge bg-light text-dark fw-normal border fs-6 ms-2" id="planCycle"></span></h3>
                    <div class="display-5 fw-bold text-primary mt-2">$<span id="planPrice"></span></div>
                </div>
                
                <h6 class="fw-bold mb-3 text-secondary text-uppercase small ls-1"><?php echo __('merchant.upgrade.modal.select_method'); ?></h6>
                
                <?php if ($enable_usdt): ?>
                <div class="payment-option" onclick="selectMethod('crypto')" id="option-crypto">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light rounded-circle p-2 me-3 d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                                <img src="https://cdn.jsdelivr.net/gh/atomiclabs/cryptocurrency-icons@master/32/color/usdt.png" alt="USDT" style="width:22px;height:22px;" onerror="this.style.display='none'">
                            </div>
                            <div>
                                <div class="fw-bold text-dark method-title"><?php echo __('merchant.upgrade.method.crypto'); ?></div>
                                <div class="method-subtitle">USDT / USDC</div>
                            </div>
                        </div>
                        <input type="radio" name="paymentMethod" value="crypto" class="form-check-input" style="pointer-events:none">
                    </div>
                    
                    <!-- Crypto Selection (Visible when checked) -->
                    <div id="cryptoSelect" class="mt-2 border-top pt-2" style="display:none;">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="crypto-card selected" onclick="event.stopPropagation(); selectCrypto('USDT', this)" id="card-USDT">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32">
                                        <g fill="none" fill-rule="evenodd">
                                            <circle cx="16" cy="16" r="16" fill="#26A17B"/>
                                            <path fill="#FFF" d="M17.922 17.383v-.002c-.11.008-.677.042-1.942.042-1.01 0-1.721-.03-1.971-.042v.003c-3.888-.171-6.79-.848-6.79-1.658 0-.809 2.902-1.486 6.79-1.66v2.644c.254.018.982.061 1.988.061 1.207 0 1.812-.05 1.925-.06v-2.643c3.88.173 6.775.85 6.775 1.658 0 .81-2.895 1.485-6.775 1.657m0-3.59v-2.366h5.414V7.819H8.595v3.608h5.414v2.365c-4.4.202-7.709 1.074-7.709 2.118 0 1.044 3.309 1.915 7.709 2.118v7.582h3.913v-7.584c4.393-.202 7.694-1.073 7.694-2.116 0-1.043-3.301-1.914-7.694-2.117"/>
                                        </g>
                                    </svg>
                                    <div class="fw-bold small text-dark">USDT</div>
                                </div>
                                <input type="radio" name="cryptoCurrency" value="USDT" checked class="d-none">
                            </div>
                            <?php if($enable_upgrade_usdc): ?>
                            <div class="col-6">
                                <div class="crypto-card" onclick="event.stopPropagation(); selectCrypto('USDC', this)" id="card-USDC">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32">
                                        <g fill="none" fill-rule="evenodd">
                                            <circle cx="16" cy="16" r="16" fill="#2775CA"/>
                                            <path fill="#FFF" d="M22.022 17.978h-2.196c-.289 1.444-1.4 2.378-3.134 2.533v1.867h-1.555v-1.889c-2.378-.2-3.8-1.578-3.8-3.867 0-2.422 1.689-3.6 4.311-4.044 1.578-.267 2.067-.622 2.067-1.356 0-.644-.6-1.133-1.845-1.133-1.244 0-1.955.511-2.2 1.511h-2.11c.244-1.6 1.444-2.667 3.577-2.823V6.889h1.556v1.867c2.089.178 3.533 1.333 3.533 3.422 0 2.2-1.422 3.489-4.222 3.956-1.867.311-2.178.8-2.178 1.466 0 .8.711 1.223 1.955 1.223 1.534 0 2.089-.667 2.245-1.845h-.003z"/>
                                        </g>
                                    </svg>
                                    <div class="fw-bold small text-dark">USDC</div>
                                </div>
                                <input type="radio" name="cryptoCurrency" value="USDC" class="d-none">
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($enable_stripe): ?>
                <div class="payment-option" onclick="selectMethod('stripe')" id="option-stripe">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light rounded-circle p-2 me-3 text-primary d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                                <i class="fas fa-credit-card fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark method-title"><?php echo __('merchant.upgrade.method.card'); ?></div>
                                <div class="method-subtitle"><?php echo __('merchant.upgrade.method.card_hint'); ?></div>
                            </div>
                        </div>
                        <input type="radio" name="paymentMethod" value="stripe" class="form-check-input" style="pointer-events:none">
                    </div>
                    <div class="mt-2 ps-5 ms-2 d-flex align-items-center gap-2 stripe-brand-row">
                        <i class="fab fa-cc-visa fs-4 visa"></i>
                        <i class="fab fa-cc-mastercard fs-4 mc"></i>
                        <i class="fab fa-cc-amex fs-4 amex"></i>
                        <img src="https://upload.wikimedia.org/wikipedia/commons/1/1b/UnionPay_logo.svg" height="20" alt="UnionPay">
                        <i class="fab fa-google-pay fs-4"></i>
                        <i class="fab fa-apple-pay fs-4"></i>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($enable_binance_pay): ?>
                <div class="payment-option" onclick="selectMethod('binance_pay')" id="option-binance_pay">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light rounded-circle p-2 me-3 d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                                <img src="https://www.binance.com/favicon.ico" alt="Binance" style="height:20px;width:20px;border-radius:4px;" onerror="this.onerror=null;this.src='https://public.bnbstatic.com/static/images/common/favicon.ico';">
                            </div>
                            <div>
                                <div class="fw-bold text-dark method-title">Binance Pay</div>
                                <div class="method-subtitle">扫码支付升级套餐</div>
                            </div>
                        </div>
                        <input type="radio" name="paymentMethod" value="binance_pay" class="form-check-input" style="pointer-events:none">
                    </div>
                </div>
                <?php endif; ?>

                <!-- Balance Payment -->
                <?php 
                    $balance = $db->fetch("SELECT balance FROM users WHERE id = ?", [$user_id])['balance'] ?? 0;
                ?>
                <div class="payment-option <?php echo $balance < 1 ? 'disabled' : ''; ?>" onclick="<?php echo $balance >= 1 ? "selectMethod('balance')" : ""; ?>" id="option-balance">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <div class="icon-box bg-light rounded-circle p-2 me-3 text-secondary d-flex align-items-center justify-content-center" style="width:40px;height:40px;">
                                <i class="fas fa-wallet fs-5"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark"><?php echo __('merchant.upgrade.method.balance'); ?></div>
                                <div class="small text-muted"><?php echo __('merchant.upgrade.current_balance'); ?>: $<?php echo number_format($balance, 2); ?></div>
                            </div>
                        </div>
                        <input type="radio" name="paymentMethod" value="balance" class="form-check-input" style="pointer-events:none" <?php echo $balance < 1 ? 'disabled' : ''; ?>>
                    </div>
                </div>

                <?php if ($balanceOtpRequired): ?>
                <div class="mt-3" id="balanceOtpWrap" style="display:none;">
                    <label class="form-label small text-secondary fw-bold">余额支付验证码</label>
                    <input type="text" id="balanceOtpInput" class="form-control" inputmode="numeric" pattern="\\d{6}" maxlength="6" placeholder="输入谷歌验证器 6 位动态码">
                    <div class="form-text">仅在选择“余额支付”且您开启该场景时需要。</div>
                </div>
                <?php endif; ?>
                
                <!-- Coupon Input -->
                <div class="mt-4 pt-3 border-top">
                    <a href="#" class="text-decoration-none small fw-bold text-primary" onclick="toggleCoupon(event)">
                        <i class="fas fa-tag me-1"></i> <?php echo __('merchant.upgrade.use_coupon'); ?>
                    </a>
                    <div id="couponArea" class="mt-2" style="display:none;">
                        <div class="input-group">
                            <input type="text" id="couponCode" class="form-control" placeholder="<?php echo __('merchant.upgrade.coupon_placeholder'); ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="applyCoupon()"><?php echo __('merchant.upgrade.apply'); ?></button>
                        </div>
                        <div id="couponMsg" class="form-text"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary px-3 py-2" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                <button type="button" class="btn btn-primary px-3 py-2" onclick="processPayment()"><?php echo __('merchant.upgrade.pay_now'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/stripe_loading_ui.php'; ?>

<div id="binanceWaitingOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:2000;align-items:center;justify-content:center;padding:16px;">
    <div class="binance-waiting-card">
        <div class="binance-waiting-spinner"></div>
        <div class="binance-waiting-title">正在跳转 Binance 支付</div>
        <div class="binance-waiting-desc">请稍候，若未自动跳转请不要关闭页面。</div>
    </div>
</div>
<style>
@keyframes binanceSpin { to { transform: rotate(360deg); } }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let selectedPlan = {};
let appliedCoupon = null;

function showBinanceWaiting() {
    const el = document.getElementById('binanceWaitingOverlay');
    if (!el) return;
    el.style.display = 'flex';
}
function hideBinanceWaiting() {
    const el = document.getElementById('binanceWaitingOverlay');
    if (!el) return;
    el.style.display = 'none';
}

function isMobileDevice() {
    return /Android|iPhone|iPad|iPod|Mobile|HarmonyOS/i.test(navigator.userAgent || '');
}

function openBinancePaymentPage(data) {
    const checkoutUrl = (data && (data.binance_checkout_url || data.redirect_url)) ? (data.binance_checkout_url || data.redirect_url) : '';
    const deepLink = (data && (data.binance_universal_url || data.binance_deeplink)) ? (data.binance_universal_url || data.binance_deeplink) : '';
    if (!checkoutUrl && !deepLink) return false;
    showBinanceWaiting();
    if (!isMobileDevice()) {
        window.location.href = checkoutUrl || deepLink;
        return true;
    }
    const params = new URLSearchParams();
    if (selectedPlan && selectedPlan.id) params.set('plan_id', String(selectedPlan.id));
    if (data && data.order_no) params.set('order', String(data.order_no));
    if (checkoutUrl) params.set('checkout', checkoutUrl);
    if (deepLink) params.set('deeplink', deepLink);
    window.location.href = '/binance_open.php?' + params.toString();
    return true;
}

function selectMethod(method) {
    // 1. Update Radio
    const radio = document.querySelector(`input[name="paymentMethod"][value="${method}"]`);
    if (radio && !radio.disabled) {
        radio.checked = true;
        
        // 2. Update UI Classes
        document.querySelectorAll('.payment-option').forEach(el => el.classList.remove('selected'));
        const selectedEl = document.getElementById('option-' + method);
        if (selectedEl) selectedEl.classList.add('selected');

        // 3. Toggle Crypto Select Visibility
        const cryptoArea = document.getElementById('cryptoSelect');
        if (method === 'crypto' && cryptoArea) {
            cryptoArea.style.display = 'block';
        } else if (cryptoArea) {
            cryptoArea.style.display = 'none';
        }
        const balanceOtpWrap = document.getElementById('balanceOtpWrap');
        if (balanceOtpWrap) {
            balanceOtpWrap.style.display = method === 'balance' ? 'block' : 'none';
        }
    }
}

function selectCrypto(currency, el) {
    // 1. Update Radio
    const radio = document.querySelector(`input[name="cryptoCurrency"][value="${currency}"]`);
    if (radio) radio.checked = true;

    // 2. Update UI
    document.querySelectorAll('.crypto-card').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
}

function toggleCoupon(e) {
    e.preventDefault();
    const area = document.getElementById('couponArea');
    area.style.display = area.style.display === 'none' ? 'block' : 'none';
}

function selectPlan(id, name, price, cycle) {
    selectedPlan = { id, name, price, cycle, originalPrice: price };
    appliedCoupon = null;
    document.getElementById('planName').innerText = name;
    document.getElementById('planPrice').innerText = price;
    document.getElementById('planCycle').innerText = cycle === 'monthly'
        ? <?php echo json_encode(__('merchant.upgrade.cycle.monthly')); ?>
        : <?php echo json_encode(__('merchant.upgrade.cycle.yearly')); ?>;
    document.getElementById('couponCode').value = '';
    document.getElementById('couponMsg').innerText = '';
    
    // Init Payment Method Selection
    // Default to Crypto if available, else Stripe, else Balance
    if (document.querySelector('input[name="paymentMethod"][value="crypto"]')) {
        selectMethod('crypto');
    } else if (document.querySelector('input[name="paymentMethod"][value="stripe"]')) {
        selectMethod('stripe');
    } else if (document.querySelector('input[name="paymentMethod"][value="binance_pay"]')) {
        selectMethod('binance_pay');
    } else {
        selectMethod('balance');
    }
    
    const modal = new bootstrap.Modal(document.getElementById('payModal'));
    modal.show();
}

function applyCoupon() {
    const code = document.getElementById('couponCode').value.trim();
    if (!code) return;
    
    fetch('/api/v1/user/verify_coupon.php?code=' + code + '&type=admin')
    .then(res => res.json())
    .then(data => {
        const msg = document.getElementById('couponMsg');
        if (data.status === 'success') {
            appliedCoupon = data.coupon;
            let newPrice = selectedPlan.originalPrice;
            if (data.coupon.type === 'fixed') {
                newPrice = Math.max(0, newPrice - parseFloat(data.coupon.value));
            } else {
                newPrice = Math.max(0, newPrice * (1 - parseFloat(data.coupon.value) / 100));
            }
            document.getElementById('planPrice').innerHTML = newPrice.toFixed(2) + ' <small class="text-muted text-decoration-line-through">$' + selectedPlan.originalPrice + '</small>';
            msg.className = 'form-text text-success';
            msg.innerText = '<?php echo jsesc(__('merchant.upgrade.coupon_applied')); ?>: ' + (data.coupon.type==='fixed' ? '-$'+data.coupon.value : '-'+data.coupon.value+'%');
        } else {
            msg.className = 'form-text text-danger';
            msg.innerText = data.message || <?php echo json_encode(__('merchant.upgrade.invalid_coupon')); ?>;
            appliedCoupon = null;
            document.getElementById('planPrice').innerText = selectedPlan.originalPrice;
        }
    });
}

function processPayment() {
    // 1. Get Selected Method
    const methodInput = document.querySelector('input[name="paymentMethod"]:checked');
    if (!methodInput) {
        alert(<?php echo json_encode(__('merchant.upgrade.select_method_first')); ?>);
        return;
    }
    let method = methodInput.value;
    let currency = 'USDT';
    
    // Handle Crypto Selection
    if (method === 'crypto') {
        const currInput = document.querySelector('input[name="cryptoCurrency"]:checked');
        if (currInput) {
            currency = currInput.value;
            // Map back to backend expected values if needed, but 'crypto' + currency param is better
            // For backward compatibility, let's keep method='usdt' if USDT, or handle in backend
            // Ideally backend should accept method='crypto' and currency='USDT'/'USDC'
            // But let's adapt to existing backend logic:
            // Existing backend likely expects 'usdt' or 'stripe' or 'balance'.
            // We should check api/v1/user/upgrade.php
            method = 'usdt'; // Use generic crypto handler
        }
    }
    
    const isStripeMethod = method === 'stripe';
    const isBinanceMethod = methodInput && methodInput.value === 'binance_pay';

    // 2. Disable Button
    const submitBtn = document.querySelector('button[onclick="processPayment()"]');
    let originalText = <?php echo json_encode(__('merchant.upgrade.pay_now')); ?>;
    if (submitBtn) {
        originalText = submitBtn.innerText;
        submitBtn.disabled = true;
        submitBtn.innerText = <?php echo json_encode(__('merchant.upgrade.processing')); ?>;
    }
    if (isStripeMethod) {
        showStripeLoading();
    }
    if (isBinanceMethod) {
        showBinanceWaiting();
    }

    // 3. Send POST Request
    const payload = {
        plan_id: selectedPlan.id,
        payment_method: method,
        currency: currency, // Send currency choice
        cycle: selectedPlan.cycle
    };
    <?php if ($balanceOtpRequired): ?>
    if (method === 'balance') {
        const otpVal = (document.getElementById('balanceOtpInput')?.value || '').trim();
        if (!/^\\d{6}$/.test(otpVal)) {
            alert('余额支付需要输入 6 位谷歌验证码');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = originalText;
            }
            return;
        }
        payload.otp_code = otpVal;
    }
    <?php endif; ?>
    if (appliedCoupon) {
        payload.coupon_code = appliedCoupon.code;
    }

    fetch('/api/v1/user/upgrade.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (methodInput && methodInput.value === 'binance_pay') {
                if (openBinancePaymentPage(data)) {
                    return;
                }
            }
            if (data.redirect_url) {
                window.location.href = data.redirect_url;
            } else if (data.pay_url) {
                 window.location.href = data.pay_url;
            } else {
                 alert(<?php echo json_encode(__('merchant.upgrade.pay_success')); ?>);
                 window.location.reload();
            }
        } else {
            if (isStripeMethod) hideStripeLoading();
            if (isBinanceMethod) hideBinanceWaiting();
            alert(data.message || <?php echo json_encode(__('merchant.upgrade.pay_failed')); ?>);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerText = originalText;
            }
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (isStripeMethod) hideStripeLoading();
        if (isBinanceMethod) hideBinanceWaiting();
        alert(<?php echo json_encode(__('merchant.upgrade.network_error')); ?>);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = originalText;
        }
    });
}
</script>
</body>
</html>
