<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/FeeAddressAllocator.php';
require_once __DIR__ . '/../src/Services/CryptoService.php';
I18n::init();
// PHP 8.1+ compatibility: prevent number_format deprecation on null values.
$nf = static function ($v, int $decimals = 0): string {
    return number_format((float)($v ?? 0), $decimals);
};

$db = Database::getInstance();
// Ensure DB is up to date
$db->autoMigrate();
CryptoService::ensureApiRequestSchema();

$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$user_id = $_SESSION['user_id'];
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';
$platformCurrencies = [];
if (($cfg['enable_payment_usdt'] ?? '1') === '1') $platformCurrencies[] = 'USDT';
if (($cfg['enable_usdc'] ?? '0') === '1') $platformCurrencies[] = 'USDC';
if (empty($platformCurrencies)) $platformCurrencies[] = 'USDT';
$merchantCurrencyKey = 'merchant_enabled_currencies_u' . (int)$user_id;
$merchantCurrenciesRaw = strtoupper(trim((string)($cfg[$merchantCurrencyKey] ?? '')));
$merchantCurrencies = [];
if ($merchantCurrenciesRaw !== '') {
    foreach (explode(',', $merchantCurrenciesRaw) as $cc) {
        $cc = strtoupper(trim((string)$cc));
        if ($cc !== '') $merchantCurrencies[] = $cc;
    }
}
if (empty($merchantCurrencies)) $merchantCurrencies = $platformCurrencies;
$merchantCurrencies = array_values(array_unique(array_values(array_intersect($merchantCurrencies, $platformCurrencies))));
if (empty($merchantCurrencies)) $merchantCurrencies = $platformCurrencies;
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
$can_switch_admin_backend = strtolower((string)($user['role'] ?? '')) === 'admin';
$wallets = $db->fetchAll("SELECT * FROM wallets WHERE user_id = ?", [$user_id]);
$orders = $db->fetchAll("SELECT * FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10", [$user_id]);

// Fetch Available Chains for this user's plan
$allowed_chains = $db->fetchAll("SELECT c.* FROM chains c 
    JOIN plan_chains pc ON c.id = pc.chain_id 
    WHERE pc.plan_id = ? AND c.status = 1", 
    [$user['plan_id']]
);

// Fetch ALL active chains
$all_active_chains = $db->fetchAll("SELECT * FROM chains WHERE status = 1 ORDER BY is_evm DESC, name ASC");

// Chart Data: Today's Hourly Volume
$today_start = date('Y-m-d 00:00:00');
$todayApi = [
    'chargeable' => CryptoService::getMerchantBillableRequestCount((int)$user_id, date('Y-m-d')),
];
$hourly_stats = $db->fetchAll("SELECT HOUR(created_at) as h, SUM(amount) as vol, COUNT(*) as cnt FROM orders WHERE user_id = ? AND created_at >= ? GROUP BY h", [$user_id, $today_start]);
$chart_labels_today = [];
$chart_data_today = [];
for($i=0; $i<24; $i++) {
    $chart_labels_today[] = sprintf("%02d:00", $i);
    $found = false;
    foreach($hourly_stats as $row) {
        if($row['h'] == $i) {
            $chart_data_today[] = (float)$row['vol'];
            $found = true;
            break;
        }
    }
    if(!$found) $chart_data_today[] = 0;
}

// Chart Data: Last 7 Days Trend
$chart_labels_week = [];
$chart_data_week = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart_labels_week[] = date('m-d', strtotime($d));
    $row = $db->fetch("SELECT SUM(amount) as s FROM orders WHERE user_id = ? AND DATE(created_at) = ?", [$user_id, $d]);
    $chart_data_week[] = (float)($row['s'] ?? 0);
}

// Notifications & Announcements
$notifs = $db->fetchAll("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 5", [$user_id]);
$announcements = $db->fetchAll("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");

// Calculate unread count (User Notifs + New Announcements if needed, currently just notifs)
// Logic: Show red dot if unread notifs > 0 OR if there are announcements (assuming user hasn't seen them, but we don't track announcement read state per user yet. Simple approach: just show count of personal notifs for dot, or maybe last 3 days announcements)
$unread_count = count($notifs);

// Add/Edit Wallet Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Test Pay
    if ($_POST['action'] === 'test_pay') {
        $chain = $_POST['chain'];
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USDT')));
        if (!in_array($currency, $merchantCurrencies, true)) {
            header("Location: dashboard.php");
            exit;
        }
        if ($currency === 'USDC' && strtolower((string)$chain) === 'trc20') {
            header("Location: dashboard.php");
            exit;
        }
        $amount = (float)($_POST['amount'] ?? 0.01);
        if ($amount <= 0) $amount = 0.01;
        $order_no = 'TEST' . date('YmdHis') . rand(1000, 9999);
        $merchant_order_id = 'SELF-TEST-' . time();
        $pay_access_token = bin2hex(random_bytes(16));
        $final_amount = $amount;
        
        $wallet = null;
        $receiveModeKey = 'merchant_receive_mode_u' . (int)$user_id;
        $receiveModeRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
        $receiveMode = strtolower(trim((string)($receiveModeRow['value'] ?? 'wallet')));
        if (!in_array($receiveMode, ['wallet', 'derived'], true)) {
            $receiveMode = 'wallet';
        }
        if ($receiveMode !== 'derived') {
            $rand_int = rand(1000, 9999);
            if ($rand_int % 10 == 0) $rand_int += rand(1, 9);
            $final_amount = $amount + ($rand_int / 1000000);
        }
        $chainSlug = strtolower((string)$chain);
        $chainMeta = $db->fetch("SELECT is_evm, COALESCE(allow_derived, 1) AS allow_derived FROM chains WHERE slug = ? AND status = 1 LIMIT 1", [$chainSlug]);
        $canDerivedOnChain = $chainMeta && (int)($chainMeta['is_evm'] ?? 0) === 1 && (int)($chainMeta['allow_derived'] ?? 1) === 1;
        if ($receiveMode === 'derived' && !$canDerivedOnChain) {
            $wallet = null;
        } elseif ($receiveMode === 'derived' && $canDerivedOnChain) {
            $allocCfg = FeeAddressAllocator::loadSettings($db);
            $allocCfg['admin_fee_address_mode'] = 'derived';
            try {
                $alloc = FeeAddressAllocator::resolveChargeWallet($db, $order_no, 'dashboard_test', (int)$user_id, (string)$chain, $allocCfg);
                if ($alloc && !empty($alloc['wallet_id']) && strtolower((string)($alloc['chain'] ?? '')) === strtolower((string)$chain)) {
                    $wallet = ['id' => (int)$alloc['wallet_id']];
                }
            } catch (Exception $e) {}
            if (!$wallet) {
                header("Location: payment_config.php?msg=derived_alloc_failed");
                exit;
            }
        }
        if (!$wallet) {
            $wallet = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND chain = ?", [$user_id, $chain]);
        }
        if ($wallet) {
            $db->query("INSERT INTO orders (order_no, merchant_order_id, pay_access_token, user_id, wallet_id, amount, currency, chain, status, is_fast_sync, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW())", [
                $order_no, $merchant_order_id, $pay_access_token, $user_id, $wallet['id'], number_format($final_amount, 6, '.', ''), $currency, $chain
            ]);
            $dec = $db->query("UPDATE users SET fast_sync_remaining = fast_sync_remaining - 1 WHERE id = ? AND fast_sync_remaining > 0", [$user_id]);
            if ($dec->rowCount() > 0) {
                $db->query("UPDATE orders SET is_fast_sync = 1 WHERE order_no = ?", [$order_no]);
            }
            header("Location: pay.php?order=$order_no&token=$pay_access_token");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>" data-bs-theme="light">
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
            
            <!-- Header -->
            <?php $page_title = __('merchant.dashboard.title'); include __DIR__ . '/includes/user_topbar.php'; ?>
            <?php if ($can_switch_admin_backend): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const profileDropdownMenu = document.querySelector('.user-topbar-actions .dropdown:last-child .dropdown-menu');
                    if (!profileDropdownMenu || profileDropdownMenu.querySelector('.js-switch-admin-link')) {
                        return;
                    }

                    const switchItem = document.createElement('li');
                    switchItem.innerHTML = '<a class="dropdown-item small py-2 js-switch-admin-link" href="/admin/index.php"><i class="fas fa-user-shield me-2 text-secondary"></i>切换管理员后台</a>';
                    profileDropdownMenu.insertBefore(switchItem, profileDropdownMenu.firstChild);

                    const divider = document.createElement('li');
                    divider.innerHTML = '<hr class="dropdown-divider">';
                    profileDropdownMenu.insertBefore(divider, switchItem.nextSibling);
                });
            </script>
            <?php endif; ?>
            
            <!-- Stats -->
            <div class="row g-4 mb-4">
                <!-- 4 Stats Cards (Same logic as before but using updated classes) -->
                <?php 
                $stats_today = $db->fetch("SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid,
                    SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) as volume
                    FROM orders WHERE user_id = ? AND created_at >= ?", [$user_id, $today_start]);
                ?>
                <!-- ... (omitting repetitive card HTML, trusting previous logic, just updating values) ... -->
                <div class="col-md-3">
                    <div class="mole-card d-flex align-items-start">
                        <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary"><i class="fas fa-wallet fa-lg"></i></div>
                        <div>
                            <div class="stat-label"><?php echo __('merchant.dashboard.today_volume'); ?></div>
                            <div class="stat-value">$<?php echo $nf($stats_today['volume'] ?? 0, 2); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mole-card d-flex align-items-start">
                        <div class="stat-icon-wrapper bg-success bg-opacity-10 text-success"><i class="fas fa-check-circle fa-lg"></i></div>
                        <div>
                            <div class="stat-label"><?php echo __('merchant.dashboard.today_paid'); ?></div>
                            <div class="stat-value"><?php echo $nf($stats_today['paid'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mole-card d-flex align-items-start">
                        <div class="stat-icon-wrapper bg-warning bg-opacity-10 text-warning"><i class="fas fa-clock fa-lg"></i></div>
                        <div>
                            <div class="stat-label"><?php echo __('merchant.dashboard.pending'); ?></div>
                            <div class="stat-value"><?php echo $nf($stats_today['pending'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mole-card d-flex align-items-start">
                        <div class="stat-icon-wrapper bg-info bg-opacity-10 text-info"><i class="fas fa-list-alt fa-lg"></i></div>
                        <div>
                            <div class="stat-label"><?php echo __('merchant.dashboard.total_orders'); ?></div>
                            <div class="stat-value"><?php echo $nf($stats_today['total'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Chart Section -->
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <div class="mole-card">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h6 class="fw-bold mb-0" style="color: var(--text-primary);"><?php echo __('merchant.dashboard.data_overview'); ?></h6>
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-secondary active" onclick="updateChart('today')"><?php echo __('merchant.dashboard.today_hourly'); ?></button>
                                <button class="btn btn-outline-secondary" onclick="updateChart('week')"><?php echo __('merchant.dashboard.last_7_days'); ?></button>
                            </div>
                        </div>
                        <div style="height: 300px;">
                            <canvas id="dashboardChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <!-- Left: Orders -->
                <div class="col-lg-8">
                    <div class="mole-card p-0 overflow-hidden h-100">
                        <div class="d-flex justify-content-between align-items-center p-4 border-bottom" style="border-color: var(--border-color)!important;">
                            <h6 class="fw-bold mb-0" style="color: var(--text-primary);"><?php echo __('merchant.dashboard.recent_transactions'); ?></h6>
                            <a href="orders.php" class="btn btn-sm btn-light border text-secondary"><?php echo __('merchant.dashboard.view_all'); ?></a>
                        </div>
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th><?php echo __('merchant.orders.sys_order_no'); ?></th>
                                        <th><?php echo __('merchant.orders.amount'); ?></th>
                                        <th><?php echo __('merchant.orders.network'); ?></th>
                                        <th><?php echo __('merchant.orders.status'); ?></th>
                                        <th><?php echo __('merchant.orders.created_at'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($orders as $o): ?>
                                    <tr>
                                        <td class="font-monospace small"><?php echo $o['order_no']; ?></td>
                                        <td class="fw-bold">
                                            $<?php echo $o['amount']; ?>
                                            <?php if (!empty($o['is_fast_sync'])): ?>
                                                <span class="badge bg-primary-subtle text-primary ms-1">FAST</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge-mole gray text-uppercase"><?php echo $o['chain']; ?></span></td>
                                        <td>
                                            <span class="badge-mole <?php echo $o['status']=='paid'?'success':($o['status']=='pending'?'warning':'gray'); ?>">
                                                <?php echo $o['status']=='paid'?__('merchant.status.paid'):($o['status']=='pending'?__('merchant.status.pending'):$o['status']); ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?php echo date('m-d H:i', strtotime($o['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Right: Plan & API -->
                <div class="col-lg-4">
                    <div class="row g-4 h-100">
                        <!-- Compact Plan Card -->
                        <div class="col-12" style="height: auto;">
                            <div class="mole-card">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="fw-bold mb-0 text-primary"><i class="fas fa-crown me-2"></i><?php echo __('merchant.dashboard.current_plan'); ?></h6>
                                    <a href="upgrade.php" class="badge bg-primary text-decoration-none"><?php echo __('merchant.dashboard.upgrade'); ?></a>
                                </div>
                                <?php 
                                    $plan_names = [];
                                    $all_plans = $db->fetchAll("SELECT * FROM plans");
                                    foreach($all_plans as $p) $plan_names[$p['id']] = $p['name'];
                                    $defaultPlanId = 1;
                                    if (!empty($all_plans)) {
                                        $freePlan = null;
                                        foreach ($all_plans as $p) {
                                            if ((float)($p['price_monthly'] ?? 0) <= 0) {
                                                $freePlan = $p;
                                                break;
                                            }
                                        }
                                        if ($freePlan) {
                                            $defaultPlanId = (int)$freePlan['id'];
                                        } else {
                                            $defaultPlanId = (int)($all_plans[0]['id'] ?? 1);
                                        }
                                    }
                                    $currentPlanId = (int)($user['plan_id'] ?? 0);
                                    if (!isset($plan_names[$currentPlanId])) {
                                        $currentPlanId = $defaultPlanId;
                                        try {
                                            $db->query("UPDATE users SET plan_id = ? WHERE id = ? LIMIT 1", [$currentPlanId, $user_id]);
                                            $user['plan_id'] = $currentPlanId;
                                        } catch (Throwable $ignore) {}
                                    }
                                    $plan_name = $plan_names[$currentPlanId] ?? __('merchant.common.unknown');
                                    $plan_expire_at = trim((string)($user['expire_at'] ?? ''));
                                    $plan_expire_text = '-';
                                    if ($plan_expire_at !== '' && strtotime($plan_expire_at) !== false) {
                                        $plan_expire_text = date('Y-m-d H:i', strtotime($plan_expire_at));
                                    }
                                ?>
                                <h4 class="fw-bold mb-2" style="color: var(--text-primary);"><?php echo $plan_name; ?></h4>
                                <div class="d-flex justify-content-between small text-secondary">
                                    <span><?php echo __('merchant.dashboard.api_limit'); ?>: <?php 
                                        $limit = 100;
                                        foreach($all_plans as $p) { if((int)$p['id'] === (int)$currentPlanId) $limit = $p['api_limit_daily']; }
                                        echo $nf($limit);
                                    ?>/<?php echo __('merchant.dashboard.per_day'); ?></span>
                                </div>
                                <div class="small text-secondary mt-1">
                                    <?php echo __('merchant.dashboard.plan_expire'); ?>: <?php echo htmlspecialchars($plan_expire_text); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Compact API Card -->
                        <div class="col-12" style="height: auto;">
                            <div class="mole-card">
                                <h6 class="fw-bold mb-2" style="color: var(--text-primary);"><?php echo __('merchant.dashboard.account_balance'); ?></h6>
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="fs-2 fw-bold text-success">$<?php echo $nf($user['balance'] ?? 0, 2); ?></div>
                                    <a href="balance.php" class="btn btn-sm btn-outline-success"><?php echo __('merchant.dashboard.recharge'); ?></a>
                                </div>
                                <div class="small text-secondary mb-2">
                                    <?php echo __('merchant.dashboard.withdrawable'); ?>: $<?php echo $nf($user['withdrawable_balance'] ?? 0, 2); ?>
                                </div>
                            </div>
                        </div>

                        <!-- Compact API Card -->
                        <div class="col-12" style="height: auto;">
                            <div class="mole-card">
                                <h6 class="fw-bold mb-2" style="color: var(--text-primary);"><?php echo __('merchant.dashboard.today_deducted'); ?></h6>
                                <div class="d-flex align-items-center justify-content-between mb-2">
                                    <div class="fs-2 fw-bold text-primary"><?php echo $nf($todayApi['chargeable'] ?? 0); ?></div>
                                    <div class="text-secondary small"><?php echo __('merchant.dashboard.times'); ?></div>
                                </div>
                                <div class="small text-secondary mb-2">
                                    <?php echo __('merchant.dashboard.billing_scope_hint'); ?>
                                </div>
                                <div class="progress" style="height: 6px;">
                                    <?php 
                                        $usage_percent = (($todayApi['chargeable'] ?? 0) / max(1, $limit)) * 100;
                                        $usage_percent = min(100, $usage_percent);
                                    ?>
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $usage_percent; ?>%"></div>
                                </div>
                                <div class="mt-2 text-end">
                                    <a href="api_settings.php" class="small text-secondary text-decoration-none"><?php echo __('merchant.dashboard.manage_keys'); ?> <i class="fas fa-arrow-right ms-1"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Wallet Config Removed -->

        </div>
    </div>
</div>

<!-- Add/Edit Wallet Modal (Same as before) -->
<div class="modal fade" id="addWalletModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <input type="hidden" name="action" value="add_wallet">
            <input type="hidden" name="wallet_id" id="walletId" value="0">
            <input type="hidden" name="chain" id="walletChainValue">
            
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="walletModalTitle"><?php echo __('merchant.dashboard.config_wallet'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold"><?php echo __('merchant.orders.network'); ?></label>
                    <input type="text" class="form-control bg-light" id="walletChainDisplay" readonly>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold"><?php echo __('merchant.settings.usdt_address'); ?></label>
                    <input type="text" name="address" id="walletAddress" class="form-control" required placeholder="<?php echo __('merchant.settings.usdt_address_placeholder'); ?>">
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                <button type="submit" class="btn btn-primary px-4 rounded-pill"><?php echo __('merchant.common.save'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Test Pay Modal -->
<div class="modal fade" id="testPayModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg" style="border-radius: 16px;">
            <input type="hidden" name="action" value="test_pay">
            <input type="hidden" name="chain" id="testChain" value="">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold"><?php echo __('merchant.dashboard.test_payment'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold"><?php echo __('merchant.dashboard.test_currency'); ?></label>
                    <select name="currency" class="form-select">
                        <?php foreach ($merchantCurrencies as $cc): ?>
                        <option value="<?php echo htmlspecialchars($cc); ?>"><?php echo htmlspecialchars($cc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-secondary fw-bold"><?php echo __('merchant.dashboard.test_amount'); ?></label>
                    <input type="number" name="amount" class="form-control" value="0.01" step="0.01" min="0.01" required>
                </div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                <button type="submit" class="btn btn-success px-4 rounded-pill"><?php echo __('merchant.dashboard.create_order'); ?></button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Chart
    const ctx = document.getElementById('dashboardChart').getContext('2d');
    let myChart;
    
    const dataToday = {
        labels: <?php echo json_encode($chart_labels_today); ?>,
        data: <?php echo json_encode($chart_data_today); ?>
    };
    const dataWeek = {
        labels: <?php echo json_encode($chart_labels_week); ?>,
        data: <?php echo json_encode($chart_data_week); ?>
    };

    function initChart(labels, data) {
        if(myChart) myChart.destroy();
        
        const gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, 'rgba(59, 130, 246, 0.2)');
        gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');

        myChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: <?php echo json_encode(__('merchant.dashboard.volume_usdt')); ?>,
                    data: data,
                    borderColor: '#3b82f6',
                    backgroundColor: gradient,
                    borderWidth: 2,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: '#3b82f6',
                    pointRadius: 3,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { color: '#9ca3af' } },
                    x: { grid: { display: false }, ticks: { color: '#9ca3af' } }
                }
            }
        });
    }
    
    // Default load today
    initChart(dataToday.labels, dataToday.data);

    function updateChart(type) {
        const btns = document.querySelectorAll('.btn-group button');
        btns.forEach(b => b.classList.remove('active'));
        
        if(type === 'today') {
            initChart(dataToday.labels, dataToday.data);
            btns[0].classList.add('active');
        } else {
            initChart(dataWeek.labels, dataWeek.data);
            btns[1].classList.add('active');
        }
    }

    // Wallet Logic
    function addWallet(chainSlug, chainName) {
        document.getElementById('walletId').value = 0;
        document.getElementById('walletChainValue').value = chainSlug;
        document.getElementById('walletChainDisplay').value = chainName;
        document.getElementById('walletAddress').value = '';
        document.getElementById('walletModalTitle').innerText = <?php echo json_encode(__('merchant.dashboard.config_wallet')); ?>;
        
        let ph = <?php echo json_encode(__('merchant.settings.usdt_address_placeholder')); ?>;
        if(chainSlug === 'trc20') ph = <?php echo json_encode(__('merchant.dashboard.placeholder.trc20')); ?>;
        else if(chainSlug === 'solana') ph = <?php echo json_encode(__('merchant.dashboard.placeholder.solana')); ?>;
        else ph = <?php echo json_encode(__('merchant.dashboard.placeholder.evm')); ?>;
        document.getElementById('walletAddress').placeholder = ph;

        new bootstrap.Modal(document.getElementById('addWalletModal')).show();
    }

    function editWallet(id, chainSlug, address, chainName) {
        document.getElementById('walletId').value = id;
        document.getElementById('walletChainValue').value = chainSlug;
        document.getElementById('walletChainDisplay').value = chainName;
        document.getElementById('walletAddress').value = address;
        document.getElementById('walletModalTitle').innerText = <?php echo json_encode(__('merchant.dashboard.edit_wallet')); ?>;
        new bootstrap.Modal(document.getElementById('addWalletModal')).show();
    }
    
    function testPay(chain) {
        document.getElementById('testChain').value = chain;
        new bootstrap.Modal(document.getElementById('testPayModal')).show();
    }
</script>
</body>
</html>
