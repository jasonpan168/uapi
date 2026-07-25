<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/ReferralService.php';
I18n::init();

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
ReferralService::ensureSchema($db);

$flash = $_SESSION['referral_flash'] ?? null;
unset($_SESSION['referral_flash']);
$flashMsg = is_array($flash) ? (string)($flash['message'] ?? '') : '';
$flashType = is_array($flash) ? (string)($flash['type'] ?? 'success') : 'success';

if (empty($_SESSION['referral_csrf'])) {
    $_SESSION['referral_csrf'] = bin2hex(random_bytes(16));
}
$referralCsrf = (string)$_SESSION['referral_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $csrf = trim((string)($_POST['csrf_token'] ?? ''));
    if ($csrf === '' || !hash_equals($referralCsrf, $csrf)) {
        $_SESSION['referral_flash'] = ['type' => 'danger', 'message' => __('merchant.referral.flash.expired')];
        header('Location: referral.php', true, 303);
        exit;
    }
    if ($action === 'submit_referral_withdraw') {
        try {
            ReferralService::submitWithdrawal($db, (int)$user_id, [
                'amount' => $_POST['amount'] ?? 0,
                'method' => $_POST['method'] ?? 'balance',
                'currency' => $_POST['currency'] ?? 'USDT',
            ]);
            $_SESSION['referral_flash'] = ['type' => 'success', 'message' => __('merchant.referral.flash.submitted')];
        } catch (Throwable $e) {
            $_SESSION['referral_flash'] = ['type' => 'danger', 'message' => $e->getMessage()];
        }
        header('Location: referral.php', true, 303);
        exit;
    }
}

// Ensure Ref Code Exists
if (empty($user['ref_code'])) {
    $new_ref_code = substr(md5(uniqid(rand(), true)), 0, 8);
    $db->query("UPDATE users SET ref_code = ? WHERE id = ?", [$new_ref_code, $user_id]);
    $user['ref_code'] = $new_ref_code;
}

// Get Referral Stats
$stats = $db->fetch("SELECT
    (SELECT COUNT(*) FROM users WHERE ref_by = ? AND DATE(created_at) = CURRENT_DATE()) as today_invites,
    (SELECT COUNT(*) FROM users WHERE ref_by = ? AND DATE(created_at) = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)) as yesterday_invites,
    (SELECT COUNT(*) FROM users WHERE ref_by = ? AND YEAR(created_at) = YEAR(CURRENT_DATE()) AND MONTH(created_at) = MONTH(CURRENT_DATE())) as month_invites,
    (SELECT COUNT(*) FROM users WHERE ref_by = ?) as total_invites,
    (SELECT COUNT(*) FROM orders WHERE user_id IN (SELECT id FROM users WHERE ref_by = ?) AND status='paid' AND DATE(created_at)=CURRENT_DATE()) as today_conversions,
    (SELECT COUNT(*) FROM orders WHERE user_id IN (SELECT id FROM users WHERE ref_by = ?) AND status='paid' AND DATE(created_at)=DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)) as yesterday_conversions,
    (SELECT COUNT(*) FROM orders WHERE user_id IN (SELECT id FROM users WHERE ref_by = ?) AND status='paid' AND YEAR(created_at)=YEAR(CURRENT_DATE()) AND MONTH(created_at)=MONTH(CURRENT_DATE())) as month_conversions,
    (SELECT COUNT(*) FROM orders WHERE user_id IN (SELECT id FROM users WHERE ref_by = ?) AND status='paid') as total_conversions,
    (SELECT COALESCE(SUM(amount), 0) FROM referral_earnings WHERE user_id = ? AND DATE(created_at) = CURRENT_DATE()) as today_earnings,
    (SELECT COALESCE(SUM(amount), 0) FROM referral_earnings WHERE user_id = ? AND DATE(created_at) = DATE_SUB(CURRENT_DATE(), INTERVAL 1 DAY)) as yesterday_earnings,
    (SELECT COALESCE(SUM(amount), 0) FROM referral_earnings WHERE user_id = ? AND YEAR(created_at) = YEAR(CURRENT_DATE()) AND MONTH(created_at) = MONTH(CURRENT_DATE())) as month_earnings,
    (SELECT COALESCE(SUM(amount), 0) FROM referral_earnings WHERE user_id = ?) as total_earnings",
    [$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id]
);

$refAvailable = ReferralService::availableAmount($db, (int)$user_id);
$wdStats = $db->fetch(
    "SELECT
        COALESCE(SUM(CASE WHEN status='pending' THEN amount ELSE 0 END),0) AS pending_amount,
        COALESCE(SUM(CASE WHEN status IN ('approved','paid') THEN amount ELSE 0 END),0) AS approved_amount
     FROM referral_withdrawals WHERE user_id = ?",
    [$user_id]
);
$pendingWithdraw = (float)($wdStats['pending_amount'] ?? 0);
$approvedWithdraw = (float)($wdStats['approved_amount'] ?? 0);

$earnings_page = max(1, (int)($_GET['earnings_page'] ?? 1));
$invites_page = max(1, (int)($_GET['invites_page'] ?? 1));
$withdrawals_page = max(1, (int)($_GET['withdrawals_page'] ?? 1));
$wdFilter = trim((string)($_GET['wd_status'] ?? 'all'));
$earnings_per_page = 10;
$invites_per_page = 10;
$withdrawals_per_page = 10;
$earnings_offset = ($earnings_page - 1) * $earnings_per_page;
$invites_offset = ($invites_page - 1) * $invites_per_page;
$withdrawals_offset = ($withdrawals_page - 1) * $withdrawals_per_page;

$wdWhere = '';
$wdParams = [$user_id];
if ($wdFilter === 'pending') {
    $wdWhere = " AND status = 'pending'";
} elseif ($wdFilter === 'approved') {
    $wdWhere = " AND status = 'approved'";
} elseif ($wdFilter === 'completed') {
    $wdWhere = " AND status = 'paid'";
} elseif ($wdFilter === 'rejected') {
    $wdWhere = " AND status = 'rejected'";
} else {
    $wdFilter = 'all';
}

$earnings_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM referral_earnings WHERE user_id = ?", [$user_id])['c'] ?? 0);
$invites_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM users WHERE ref_by = ?", [$user_id])['c'] ?? 0);
$withdrawals_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM referral_withdrawals WHERE user_id = ? {$wdWhere}", $wdParams)['c'] ?? 0);
$earnings_total_pages = max(1, (int)ceil($earnings_total / $earnings_per_page));
$invites_total_pages = max(1, (int)ceil($invites_total / $invites_per_page));
$withdrawals_total_pages = max(1, (int)ceil($withdrawals_total / $withdrawals_per_page));
$earnings_page = min($earnings_page, $earnings_total_pages);
$invites_page = min($invites_page, $invites_total_pages);
$withdrawals_page = min($withdrawals_page, $withdrawals_total_pages);
$earnings_offset = ($earnings_page - 1) * $earnings_per_page;
$invites_offset = ($invites_page - 1) * $invites_per_page;
$withdrawals_offset = ($withdrawals_page - 1) * $withdrawals_per_page;

$earnings_list = $db->fetchAll("
    SELECT e.*,
           u.email AS invitee_email
    FROM referral_earnings e
    LEFT JOIN orders o ON o.id = e.source_order_id
    LEFT JOIN users u ON u.id = o.user_id
    WHERE e.user_id = ?
    ORDER BY e.created_at DESC
    LIMIT $earnings_per_page OFFSET $earnings_offset
", [$user_id]);
$withdrawals = $db->fetchAll(
    "SELECT * FROM referral_withdrawals WHERE user_id = ? {$wdWhere} ORDER BY id DESC LIMIT $withdrawals_per_page OFFSET $withdrawals_offset",
    $wdParams
);

// Get Referral Rate (user override > global)
$referral_rate = ReferralService::rateForUser($db, (int)$user_id);

// Get My Invites List
$my_invites = $db->fetchAll("SELECT id, email, created_at FROM users WHERE ref_by = ? ORDER BY created_at DESC LIMIT $invites_per_page OFFSET $invites_offset", [$user_id]);

function ref_page_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}

// Helper to mask email
function maskEmail($email) {
    $parts = explode('@', $email);
    if (count($parts) < 2) return $email;
    $name = $parts[0];
    $domain = $parts[1];
    $len = strlen($name);
    
    if ($len <= 2) {
        $maskedName = $name . '***';
    } else {
        $start = substr($name, 0, 2);
        $end = substr($name, -2);
        $maskedName = $start . '***' . $end;
    }
    
    return $maskedName . '@' . $domain;
}

function maskEmailStrong($email) {
    $email = trim((string)$email);
    if ($email === '' || strpos($email, '@') === false) {
        return '***';
    }
    [$name, $domain] = explode('@', $email, 2);
    $name = trim($name);
    $domain = trim($domain);
    $nameMasked = strlen($name) <= 2 ? substr($name, 0, 1) . '***' : substr($name, 0, 1) . '***' . substr($name, -1);
    $domainParts = explode('.', $domain);
    $main = $domainParts[0] ?? '';
    $suffix = $domainParts[1] ?? '';
    $mainMasked = strlen($main) <= 2 ? substr($main, 0, 1) . '**' : substr($main, 0, 1) . '***' . substr($main, -1);
    $suffixMasked = $suffix === '' ? '' : '.' . (strlen($suffix) <= 2 ? str_repeat('*', strlen($suffix)) : (substr($suffix, 0, 1) . '**' . substr($suffix, -1)));
    return $nameMasked . '@' . $mainMasked . $suffixMasked;
}

// Handle Withdrawal (Transfer to Balance - actually it is already in balance, so maybe just logs?)
// The requirement says "Earnings are automatically deposited to merchant balance".
// So here we just show the stats.

$page_title = __('merchant.referral.title');
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
        .invite-card {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
            border-radius: 16px;
            padding: 30px;
            position: relative;
            overflow: hidden;
        }
        .invite-card::after {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }
        .step-circle {
            width: 40px; height: 40px;
            border-radius: 50%;
            background: var(--bg-body);
            color: var(--text-primary);
            display: flex; align-items: center; justify-content: center;
            font-weight: bold;
            margin-bottom: 10px;
            border: 1px solid var(--border-color);
        }
        .compact-stat-card {
            padding-top: 10px !important;
            padding-bottom: 10px !important;
            min-height: 104px;
        }
        .compact-stat-card .stat-icon-wrapper {
            width: 48px;
            height: 48px;
        }
        .invite-balance-summary {
            font-size: .98rem;
            color: var(--text-secondary);
            margin-top: 6px;
        }
        .invite-balance-amount-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 4px;
        }
        .ref-help-btn {
            width: 22px;
            height: 22px;
            border-radius: 999px;
            border: 1px solid rgba(255,255,255,.55);
            background: rgba(255,255,255,.2);
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            cursor: pointer;
            vertical-align: middle;
            padding: 0;
        }
        .ref-clip-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .ref-clip-btn {
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            color: var(--text-secondary);
            border-radius: 999px;
            padding: 8px 14px;
            font-weight: 600;
            line-height: 1.2;
            cursor: pointer;
        }
        .ref-clip-btn.active {
            background: #3b82f6;
            border-color: #3b82f6;
            color: #fff;
        }
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Content -->
        <div class="col-md-9 col-lg-10 main-content">
            
            <!-- Header -->
            <?php include __DIR__ . '/includes/user_topbar.php'; ?>
            <?php if ($flashMsg !== ''): ?>
            <div class="alert alert-<?php echo $flashType === 'danger' ? 'danger' : 'success'; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($flashMsg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Invite Banner -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="invite-card shadow-sm">
                        <div class="row align-items-center position-relative" style="z-index: 1;">
                            <div class="col-lg-8">
                                <h2 class="fw-bold mb-3">
                                    <?php echo __('merchant.referral.hero_title', ['rate' => $referral_rate]); ?>
                                    <button type="button" class="ref-help-btn ms-1" data-bs-toggle="modal" data-bs-target="#referralHelpModal" aria-label="<?php echo __('merchant.referral.help_label'); ?>">?</button>
                                </h2>
                                <p class="lead mb-4 opacity-75"><?php echo __('merchant.referral.help_desc'); ?></p>
                                <div class="bg-white bg-opacity-10 p-3 rounded-3 d-inline-flex align-items-center">
                                    <span class="me-3 opacity-75"><?php echo __('merchant.referral.your_link'); ?></span>
                                    <code class="text-white fw-bold me-3 user-select-all" id="refLink">
                                        <?php 
                                            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
                                            $host = $_SERVER['HTTP_HOST'];
                                            echo "$protocol://$host/register.php?ref=" . ($user['ref_code'] ?? 'GENERATE');
                                        ?>
                                    </code>
                                    <button class="btn btn-sm btn-light text-primary fw-bold" onclick="copyRef()"><?php echo __('merchant.referral.copy_link'); ?></button>
                                </div>
                            </div>
                            <div class="col-lg-4 d-none d-lg-block text-center">
                                <i class="fas fa-gift" style="font-size: 8rem; opacity: 0.8;"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="mole-card compact-stat-card">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <h6 class="mb-0 text-secondary"><?php echo __('merchant.referral.stats.today_invites'); ?></h6>
                        </div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.today_invites'); ?> <span class="float-end fw-semibold"><?php echo number_format((int)($stats['today_invites'] ?? 0)); ?></span></div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.yesterday_invites'); ?> <span class="float-end fw-semibold"><?php echo number_format((int)($stats['yesterday_invites'] ?? 0)); ?></span></div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.month_invites'); ?> <span class="float-end fw-semibold"><?php echo number_format((int)($stats['month_invites'] ?? 0)); ?></span></div>
                        <div class="small text-secondary mb-0"><?php echo __('merchant.referral.stats.total_invites_short'); ?> <span class="float-end fw-semibold"><?php echo number_format((int)($stats['total_invites'] ?? 0)); ?></span></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mole-card compact-stat-card">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stat-icon-wrapper bg-success bg-opacity-10 text-success">
                                <i class="fas fa-coins"></i>
                            </div>
                            <h6 class="mb-0 text-secondary"><?php echo __('merchant.referral.stats.earnings_overview'); ?></h6>
                        </div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.today_earnings'); ?> <span class="float-end fw-semibold text-success">$<?php echo number_format((float)($stats['today_earnings'] ?? 0), 2); ?></span></div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.yesterday_earnings'); ?> <span class="float-end fw-semibold text-success">$<?php echo number_format((float)($stats['yesterday_earnings'] ?? 0), 2); ?></span></div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.month_earnings'); ?> <span class="float-end fw-semibold text-success">$<?php echo number_format((float)($stats['month_earnings'] ?? 0), 2); ?></span></div>
                        <div class="small text-secondary mb-0"><?php echo __('merchant.referral.stats.total_earnings_short'); ?> <span class="float-end fw-semibold text-success">$<?php echo number_format((float)($stats['total_earnings'] ?? 0), 2); ?></span></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mole-card compact-stat-card">
                        <div class="d-flex align-items-center mb-3">
                            <div class="stat-icon-wrapper bg-warning bg-opacity-10 text-warning">
                                <i class="fas fa-shopping-cart"></i>
                            </div>
                            <h6 class="mb-0 text-secondary"><?php echo __('merchant.referral.stats.friend_orders'); ?></h6>
                        </div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.today_orders'); ?> <span class="float-end fw-semibold"><?php echo number_format((int)($stats['today_conversions'] ?? 0)); ?></span></div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.yesterday_orders'); ?> <span class="float-end fw-semibold"><?php echo number_format((int)($stats['yesterday_conversions'] ?? 0)); ?></span></div>
                        <div class="small text-secondary"><?php echo __('merchant.referral.stats.month_orders'); ?> <span class="float-end fw-semibold"><?php echo number_format((int)($stats['month_conversions'] ?? 0)); ?></span></div>
                        <div class="small text-secondary mb-0"><?php echo __('merchant.referral.stats.total_orders_short'); ?> <span class="float-end fw-semibold"><?php echo number_format((int)($stats['total_conversions'] ?? 0)); ?></span></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="mole-card compact-stat-card">
                        <div class="d-flex align-items-center mb-3">
                            <div class="d-flex align-items-center">
                                <div class="stat-icon-wrapper bg-info bg-opacity-10 text-info">
                                    <i class="fas fa-wallet"></i>
                                </div>
                                <h6 class="mb-0 text-secondary"><?php echo __('merchant.referral.balance_title'); ?></h6>
                            </div>
                        </div>
                        <div class="invite-balance-amount-row">
                            <h2 class="fw-bold mb-0 text-info">$<?php echo number_format($refAvailable, 2); ?></h2>
                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#withdrawReferralModal"><?php echo __('merchant.referral.withdraw'); ?></button>
                        </div>
                        <div class="invite-balance-summary"><?php echo __('merchant.referral.pending_withdraw', ['amount' => number_format($pendingWithdraw, 2)]); ?></div>
                    </div>
                </div>
            </div>
            <div class="ref-clip-tabs">
                <button type="button" class="ref-clip-btn active" data-ref-tab="invites"><?php echo __('merchant.referral.tab.invites'); ?></button>
                <button type="button" class="ref-clip-btn" data-ref-tab="earnings"><?php echo __('merchant.referral.tab.earnings'); ?></button>
                <button type="button" class="ref-clip-btn" data-ref-tab="withdrawals"><?php echo __('merchant.referral.tab.withdrawals'); ?></button>
            </div>

            <!-- Earnings History -->
            <div class="row g-4 ref-tab-pane d-none" data-ref-pane="earnings">
                <div class="col-12">
                    <div class="mole-card h-100">
                        <h6 class="fw-bold mb-3"><?php echo __('merchant.referral.earnings_details'); ?></h6>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th><?php echo __('merchant.referral.table.time'); ?></th>
                                        <th><?php echo __('merchant.referral.table.source_order'); ?></th>
                                        <th><?php echo __('merchant.referral.table.invitee_email'); ?></th>
                                        <th><?php echo __('merchant.referral.table.reward_amount'); ?></th>
                                        <th><?php echo __('merchant.referral.table.status'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($earnings_list as $e): ?>
                                    <tr>
                                        <td class="text-secondary small"><?php echo $e['created_at']; ?></td>
                                        <td class="small text-muted">#<?php echo $e['source_order_id']; ?></td>
                                        <td class="small text-secondary"><?php echo htmlspecialchars(maskEmailStrong((string)($e['invitee_email'] ?? ''))); ?></td>
                                        <td class="fw-bold text-success">+$<?php echo number_format($e['amount'], 4); ?></td>
                                        <td>
                                            <?php $es = strtolower((string)($e['status'] ?? 'available')); ?>
                                            <?php if ($es === 'available'): ?>
                                                <span class="badge bg-info bg-opacity-10 text-info"><?php echo __('merchant.referral.withdrawable'); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-success bg-opacity-10 text-success"><?php echo __('merchant.referral.arrived'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($earnings_list)): ?>
                                    <tr><td colspan="5" class="text-center py-4 text-muted"><?php echo __('merchant.referral.no_earnings'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-3">
                            <small class="text-muted"><?php echo __('merchant.common.total_count', ['count' => $earnings_total]); ?></small>
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-secondary <?php echo $earnings_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(ref_page_url(['earnings_page' => max(1, $earnings_page - 1), 'tab' => 'earnings'])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                                <span class="btn btn-light disabled"><?php echo $earnings_page; ?> / <?php echo $earnings_total_pages; ?></span>
                                <a class="btn btn-outline-secondary <?php echo $earnings_page >= $earnings_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(ref_page_url(['earnings_page' => min($earnings_total_pages, $earnings_page + 1), 'tab' => 'earnings'])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <div class="row g-4 ref-tab-pane" data-ref-pane="invites">
                <div class="col-12">
                    <div class="mole-card">
                        <h6 class="fw-bold mb-3"><?php echo __('merchant.referral.tab.invites'); ?></h6>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th><?php echo __('merchant.referral.invites.user_id'); ?></th>
                                        <th><?php echo __('merchant.referral.invites.email'); ?></th>
                                        <th><?php echo __('merchant.referral.invites.registered_at'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($my_invites as $invite): ?>
                                    <tr>
                                        <td class="text-secondary small">#<?php echo $invite['id']; ?></td>
                                        <td class="fw-bold text-primary"><?php echo maskEmail($invite['email']); ?></td>
                                        <td class="text-secondary small"><?php echo $invite['created_at']; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($my_invites)): ?>
                                    <tr><td colspan="3" class="text-center py-4 text-muted"><?php echo __('merchant.referral.no_invites'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-3">
                            <small class="text-muted"><?php echo __('merchant.common.total_count', ['count' => $invites_total]); ?></small>
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-secondary <?php echo $invites_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(ref_page_url(['invites_page' => max(1, $invites_page - 1), 'tab' => 'invites'])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                                <span class="btn btn-light disabled"><?php echo $invites_page; ?> / <?php echo $invites_total_pages; ?></span>
                                <a class="btn btn-outline-secondary <?php echo $invites_page >= $invites_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(ref_page_url(['invites_page' => min($invites_total_pages, $invites_page + 1), 'tab' => 'invites'])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-1 ref-tab-pane d-none" data-ref-pane="withdrawals">
                <div class="col-12">
                    <div class="mole-card">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h6 class="fw-bold mb-0"><?php echo __('merchant.referral.withdrawals.title'); ?></h6>
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted small d-none d-md-inline"><?php echo __('merchant.referral.withdrawals.subtitle'); ?></span>
                                <form method="GET" class="d-flex align-items-center gap-2">
                                    <input type="hidden" name="earnings_page" value="<?php echo (int)$earnings_page; ?>">
                                    <input type="hidden" name="invites_page" value="<?php echo (int)$invites_page; ?>">
                                    <input type="hidden" name="withdrawals_page" value="1">
                                    <input type="hidden" name="tab" value="withdrawals">
                                    <select name="wd_status" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <option value="all" <?php echo $wdFilter === 'all' ? 'selected' : ''; ?>><?php echo __('merchant.referral.withdrawals.filter.all'); ?></option>
                                        <option value="pending" <?php echo $wdFilter === 'pending' ? 'selected' : ''; ?>><?php echo __('merchant.referral.withdrawals.filter.pending'); ?></option>
                                        <option value="approved" <?php echo $wdFilter === 'approved' ? 'selected' : ''; ?>><?php echo __('merchant.referral.withdrawals.filter.approved'); ?></option>
                                        <option value="completed" <?php echo $wdFilter === 'completed' ? 'selected' : ''; ?>><?php echo __('merchant.referral.withdrawals.filter.completed'); ?></option>
                                        <option value="rejected" <?php echo $wdFilter === 'rejected' ? 'selected' : ''; ?>><?php echo __('merchant.referral.withdrawals.filter.rejected'); ?></option>
                                    </select>
                                </form>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead>
                                    <tr>
                                        <th><?php echo __('merchant.referral.withdrawals.table.date'); ?></th>
                                        <th><?php echo __('merchant.referral.withdrawals.table.amount'); ?></th>
                                        <th><?php echo __('merchant.referral.withdrawals.table.currency'); ?></th>
                                        <th><?php echo __('merchant.referral.withdrawals.table.method'); ?></th>
                                        <th><?php echo __('merchant.referral.withdrawals.table.audit_status'); ?></th>
                                        <th><?php echo __('merchant.referral.withdrawals.table.payout_status'); ?></th>
                                        <th><?php echo __('merchant.referral.withdrawals.table.target'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($withdrawals as $wd): ?>
                                    <tr>
                                        <td class="small text-muted"><?php echo htmlspecialchars((string)$wd['created_at']); ?></td>
                                        <td class="fw-bold text-primary"><?php echo number_format((float)$wd['amount'], 6); ?></td>
                                        <td><?php echo htmlspecialchars((string)$wd['currency']); ?></td>
                                        <td><?php echo htmlspecialchars(ReferralService::methodLabel((string)$wd['method'])); ?></td>
                                        <td>
                                            <?php $a = (string)($wd['audit_status'] ?? 'pending'); ?>
                                            <span class="badge <?php echo $a==='approved' ? 'bg-success' : ($a==='rejected' ? 'bg-danger' : 'bg-warning text-dark'); ?>">
                                                <?php echo $a==='approved' ? __('merchant.referral.withdrawals.audit.approved') : ($a==='rejected' ? __('merchant.referral.withdrawals.audit.rejected') : __('merchant.referral.withdrawals.audit.pending')); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php $p = (string)($wd['payout_status'] ?? 'pending'); ?>
                                            <span class="badge <?php echo $p==='completed' ? 'bg-success' : ($p==='pending_manual' ? 'bg-info text-dark' : 'bg-secondary'); ?>">
                                                <?php echo $p==='completed' ? __('merchant.referral.withdrawals.payout.completed') : ($p==='pending_manual' ? __('merchant.referral.withdrawals.payout.pending_manual') : __('merchant.referral.withdrawals.payout.processing')); ?>
                                            </span>
                                        </td>
                                        <td class="small text-muted"><?php echo htmlspecialchars((string)($wd['target_account'] ?? '-')); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($withdrawals)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4"><?php echo __('merchant.referral.withdrawals.empty'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-3">
                            <small class="text-muted"><?php echo __('merchant.common.total_count', ['count' => $withdrawals_total]); ?></small>
                            <div class="btn-group btn-group-sm">
                                <a class="btn btn-outline-secondary <?php echo $withdrawals_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(ref_page_url(['withdrawals_page' => max(1, $withdrawals_page - 1), 'tab' => 'withdrawals'])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                                <span class="btn btn-light disabled"><?php echo $withdrawals_page; ?> / <?php echo $withdrawals_total_pages; ?></span>
                                <a class="btn btn-outline-secondary <?php echo $withdrawals_page >= $withdrawals_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(ref_page_url(['withdrawals_page' => min($withdrawals_total_pages, $withdrawals_page + 1), 'tab' => 'withdrawals'])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="modal fade" id="withdrawReferralModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="action" value="submit_referral_withdraw">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($referralCsrf); ?>">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo __('merchant.referral.withdraw_modal.title'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info small py-2">
            <?php echo __('merchant.referral.withdraw_modal.available'); ?><strong>$<?php echo number_format($refAvailable, 6); ?></strong>
        </div>
        <div class="mb-3">
            <label class="form-label"><?php echo __('merchant.referral.withdraw_modal.amount'); ?></label>
            <input type="number" class="form-control" name="amount" min="0.000001" step="0.000001" max="<?php echo htmlspecialchars((string)$refAvailable); ?>" required>
        </div>
        <div class="mb-3">
            <label class="form-label"><?php echo __('merchant.referral.withdraw_modal.currency'); ?></label>
            <select class="form-select" name="currency">
                <option value="USDT">USDT</option>
                <option value="USDC">USDC</option>
            </select>
        </div>
        <div class="mb-2">
            <label class="form-label"><?php echo __('merchant.referral.withdraw_modal.method'); ?></label>
            <select class="form-select" name="method" id="refWithdrawMethod" onchange="updateWithdrawHint()">
                <option value="balance"><?php echo __('merchant.referral.withdraw_modal.method.balance'); ?></option>
                <option value="binance"><?php echo __('merchant.referral.withdraw_modal.method.binance'); ?></option>
                <option value="wallet"><?php echo __('merchant.referral.withdraw_modal.method.wallet'); ?></option>
            </select>
        </div>
        <div id="refWithdrawHint" class="small text-muted"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
        <button type="submit" class="btn btn-primary"><?php echo __('merchant.referral.withdraw_modal.submit'); ?></button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="referralHelpModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><?php echo __('merchant.referral.help_modal.title'); ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2"><?php echo __('merchant.referral.help_modal.desc_1', ['rate' => number_format((float)$referral_rate, (fmod((float)$referral_rate, 1.0) == 0.0 ? 0 : 2))]); ?></p>
        <p class="mb-2"><?php echo __('merchant.referral.help_modal.desc_2'); ?></p>
        <hr>
        <div class="small text-secondary">
            <div class="mb-1"><strong>1.</strong> <?php echo __('merchant.referral.help_modal.step_1'); ?></div>
            <div class="mb-1"><strong>2.</strong> <?php echo __('merchant.referral.help_modal.step_2'); ?></div>
            <div class="mb-1"><strong>3.</strong> <?php echo __('merchant.referral.help_modal.step_3'); ?></div>
            <div class="mb-0"><strong>4.</strong> <?php echo __('merchant.referral.help_modal.step_4'); ?></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><?php echo __('merchant.referral.help_modal.got_it'); ?></button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyRef() {
    const text = document.getElementById('refLink').innerText.trim();
    navigator.clipboard.writeText(text).then(() => {
        alert(<?php echo json_encode(__('merchant.referral.copy_success')); ?>);
    });
}
function switchReferralTab(tabName) {
    const panes = document.querySelectorAll('.ref-tab-pane');
    const btns = document.querySelectorAll('.ref-clip-btn');
    panes.forEach(p => p.classList.toggle('d-none', p.getAttribute('data-ref-pane') !== tabName));
    btns.forEach(b => b.classList.toggle('active', b.getAttribute('data-ref-tab') === tabName));
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url.toString());
}
function updateWithdrawHint() {
    const m = document.getElementById('refWithdrawMethod').value;
    const hint = document.getElementById('refWithdrawHint');
    if (m === 'balance') {
        hint.innerHTML = <?php echo json_encode(__('merchant.referral.withdraw_hint.balance')); ?>;
        return;
    }
    if (m === 'binance') {
        const hasUid = <?php echo !empty($user['binance_uid']) ? 'true' : 'false'; ?>;
        hint.innerHTML = hasUid ? <?php echo json_encode(__('merchant.referral.withdraw_hint.binance.bound')); ?> : <?php echo json_encode(__('merchant.referral.withdraw_hint.binance.unbound')); ?>;
        return;
    }
    const hasWallet = <?php echo !empty($user['withdraw_address']) ? 'true' : 'false'; ?>;
    hint.innerHTML = hasWallet ? <?php echo json_encode(__('merchant.referral.withdraw_hint.wallet.bound')); ?> : <?php echo json_encode(__('merchant.referral.withdraw_hint.wallet.unbound')); ?>;
}
document.addEventListener('DOMContentLoaded', function() {
    updateWithdrawHint();
    const initialTab = (new URL(window.location.href)).searchParams.get('tab') || 'invites';
    switchReferralTab(['earnings', 'invites', 'withdrawals'].includes(initialTab) ? initialTab : 'invites');
    document.querySelectorAll('.ref-clip-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            switchReferralTab(btn.getAttribute('data-ref-tab') || 'invites');
        });
    });
});
</script>
</body>
</html>
