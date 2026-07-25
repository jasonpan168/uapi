<?php
if (!isset($db)) {
    require_once __DIR__ . '/../../../src/Core/Database.php';
    $db = Database::getInstance();
}
if (!class_exists('I18n')) {
    require_once __DIR__ . '/../../../src/Core/I18n.php';
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
I18n::init();
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$admin_csrf_token = $_SESSION['admin_csrf_token'];
// Ensure settings are loaded
if (!isset($site_name) || !isset($site_logo) || !isset($site_favicon)) {
    $settings = $db->fetchAll("SELECT * FROM system_settings");
    $cfg = [];
    foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
    $site_name = $cfg['site_name'] ?? 'UAPI';
    $site_logo = $cfg['site_logo'] ?? '';
    $site_favicon = $cfg['site_favicon'] ?? '';
}
if (!isset($cfg) || !is_array($cfg)) {
    $cfg = [];
    $settings = $db->fetchAll("SELECT * FROM system_settings");
    foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
}
$feeMode = strtolower(trim((string)($cfg['admin_fee_address_mode'] ?? 'fixed')));
if (!in_array($feeMode, ['fixed', 'derived'], true)) {
    $feeMode = 'fixed';
}
$feeChain = strtolower(trim((string)($cfg['payment_collection_chain'] ?? 'trc20')));
$derivedRemainingCurrent = 0;
$derivedRemainingTotal = 0;
if ($feeMode === 'derived') {
    try {
        $rowCurrent = $db->fetch(
            "SELECT COUNT(*) AS c
             FROM admin_derived_wallets w
             LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
             WHERE w.status = 1 AND w.chain_slug = ? AND a.id IS NULL",
            [$feeChain]
        );
        $derivedRemainingCurrent = (int)($rowCurrent['c'] ?? 0);

        $rowTotal = $db->fetch(
            "SELECT COUNT(*) AS c
             FROM admin_derived_wallets w
             LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
             WHERE w.status = 1 AND a.id IS NULL"
        );
        $derivedRemainingTotal = (int)($rowTotal['c'] ?? 0);
    } catch (Throwable $e) {
        $derivedRemainingCurrent = 0;
        $derivedRemainingTotal = 0;
    }
}
$page_title = $page_title ?? ($site_name . ' ' . __('admin.common.backend'));
$current_page = basename($_SERVER['PHP_SELF']);
$current_lang = I18n::getLang();
$lang_zh_url = I18n::langUrl('zh-cn');
$lang_en_url = I18n::langUrl('en');
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::isZh() ? 'zh-CN' : 'en'; ?>" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <?php if ($site_favicon): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($site_favicon); ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-body: #f9fafb;
            --text-primary: #111827;
            --text-secondary: #6b7280;
            --card-bg: #ffffff;
            --accent-blue: #3b82f6;
            --border-color: #e5e7eb;
            --sidebar-bg: #ffffff;
        }
        [data-bs-theme="dark"] {
            --bg-body: #111827;
            --text-primary: #f9fafb;
            --text-secondary: #9ca3af;
            --card-bg: #1f2937;
            --accent-blue: #60a5fa;
            --border-color: #374151;
            --sidebar-bg: #1f2937;
        }
        body {
            background-color: var(--bg-body);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: var(--text-primary);
        }
        .main-content {
            padding: 32px;
            min-height: 100vh;
        }

        /* MoleAPI Card Style */
        .mole-card {
            background: var(--card-bg);
            border-radius: 16px;
            border: 1px solid var(--border-color);
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            padding: 24px;
            height: 100%;
            transition: all 0.2s;
        }
        .mole-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        /* Top Header */
        .top-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .greeting {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .ops-status-line {
            display: flex;
            gap: 8px;
            margin-top: 6px;
            flex-wrap: wrap;
            font-size: 12px;
        }
        .ops-chip {
            border: 1px solid var(--border-color);
            border-radius: 999px;
            padding: 3px 10px;
            background: #fff;
            color: #334155;
            font-weight: 600;
        }
        .ops-chip.ok {
            border-color: #86efac;
            color: #166534;
            background: #f0fdf4;
        }
        .ops-chip.warn {
            border-color: #fdba74;
            color: #9a3412;
            background: #fff7ed;
        }
        
        /* Sidebar Override */
        .sidebar {
            background-color: var(--sidebar-bg) !important;
            border-right: 1px solid var(--border-color) !important;
        }
        .sidebar-brand { color: var(--text-primary) !important; }
        .nav-link { color: var(--text-secondary) !important; }
        .nav-link:hover { background-color: rgba(59, 130, 246, 0.1) !important; color: var(--text-primary) !important; }
        .nav-link.active { background-color: rgba(59, 130, 246, 0.1) !important; color: var(--accent-blue) !important; }

        /* Tables */
        .table { color: var(--text-primary) !important; }
        .table th { border-color: var(--border-color) !important; color: var(--text-secondary) !important; }
        .table td { border-color: var(--border-color) !important; background-color: transparent !important; color: var(--text-primary) !important; }

        /* Badges */
        .badge-mole {
            padding: 4px 8px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 0.75rem;
        }
        .badge-mole.success { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .badge-mole.warning { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
        .badge-mole.info { background: rgba(59, 130, 246, 0.1); color: #3b82f6; }
        .badge-mole.gray { background: rgba(107, 114, 128, 0.1); color: #6b7280; }
        
        /* Dark Mode Toggle */
        .theme-toggle {
            cursor: pointer;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            transition: all 0.2s;
        }
        .theme-toggle:hover {
            color: var(--accent-blue);
            border-color: var(--accent-blue);
        }
        @media (max-width: 767.98px) {
            .main-content {
                padding: 16px;
            }
            .top-header {
                padding: 12px 16px !important;
                margin: -16px -16px 20px -16px !important;
                flex-wrap: wrap;
                align-items: flex-start;
                gap: 12px;
                margin-bottom: 20px;
            }
            .top-header .greeting {
                display: flex;
                align-items: center;
                gap: 10px;
                width: 100%;
                min-width: 0;
                font-size: 1.125rem;
            }
            .top-header .admin-topbar-actions {
                width: 100%;
                justify-content: flex-end;
                flex-wrap: wrap;
                gap: 10px !important;
            }
            .top-header .dropdown-menu {
                width: min(100vw - 24px, 300px) !important;
            }
            .top-header .theme-toggle {
                width: 42px;
                height: 42px;
            }
        }

    </style>
    <script>
        // Theme Init
        const theme = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-bs-theme', theme);
        
        function toggleTheme() {
            const current = document.documentElement.getAttribute('data-bs-theme');
            const next = current === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-bs-theme', next);
            localStorage.setItem('theme', next);
        }

    </script>
    <?php require_once __DIR__ . '/../../includes/notify_ui.php'; ?>
</head>
<body>

<div class="d-flex">
    <!-- Sidebar -->
    <?php include __DIR__ . '/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-grow-1 main-content" style="background-color: #f9fafb;">
        
        <!-- Global Top Header -->
        <div class="top-header" style="background: white; padding: 16px 32px; border-bottom: 1px solid #e5e7eb; margin: -32px -32px 32px -32px;">
            <div class="greeting">
                <button type="button" class="theme-toggle d-inline-flex d-md-none" onclick="toggleAdminSidebar()" aria-label="Open menu">
                    <i class="fas fa-bars"></i>
                </button>
                <?php
                $titles = [
                    'dashboard.php' => __('admin.nav.dashboard'),
                    'users.php' => __('admin.nav.users'),
                    'orders.php' => __('admin.nav.orders'),
                    'plans.php' => __('admin.nav.plans'),
                    'settings.php' => __('admin.nav.settings'),
                    'security.php' => '安全设置',
                    'api_stats.php' => __('admin.nav.api_stats'),
                    'derived_wallets.php' => __('admin.nav.derived_wallets'),
                    'monitor.php' => __('admin.nav.monitor'),
                    'webhook_logs.php' => __('admin.nav.webhook_logs'),
                    'websites.php' => __('admin.nav.websites'),
                    'referrals.php' => __('admin.nav.referrals'),
                    'leaderboard.php' => __('admin.nav.leaderboard'),
                    'plugins.php' => __('admin.nav.plugins'),
                    'notifications.php' => __('admin.nav.notifications'),
                    'tickets.php' => __('admin.nav.tickets'),
                    'marketing.php' => __('admin.nav.marketing')
                ];
                echo $titles[$current_page] ?? __('admin.common.backend');
                ?>
                
            </div>
            <div class="d-flex align-items-center gap-3 admin-topbar-actions">
                <!-- Theme Toggle -->
                <div class="theme-toggle" onclick="toggleTheme()" title="<?php echo __('admin.topbar.theme_toggle'); ?>">
                    <i class="fas fa-adjust"></i>
                </div>

                <!-- Language Switch -->
                <div class="dropdown">
                    <button class="theme-toggle d-flex align-items-center justify-content-center fw-bold" data-bs-toggle="dropdown" aria-expanded="false" title="<?php echo __('merchant.topbar.language'); ?>" style="font-size: 12px;">
                        <?php echo $current_lang === 'zh-cn' ? '中' : 'EN'; ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" style="min-width: 140px;">
                        <li><h6 class="dropdown-header small text-uppercase"><?php echo __('merchant.topbar.language'); ?></h6></li>
                        <li><a class="dropdown-item small py-2 <?php echo $current_lang === 'zh-cn' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_zh_url); ?>"><?php echo __('merchant.lang.zh'); ?></a></li>
                        <li><a class="dropdown-item small py-2 <?php echo $current_lang === 'en' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_en_url); ?>"><?php echo __('merchant.lang.en'); ?></a></li>
                    </ul>
                </div>

                <!-- Notifications -->
                <div class="dropdown">
                    <button class="theme-toggle" data-bs-toggle="dropdown">
                        <i class="far fa-bell"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0" style="width: 300px; max-height: 400px; overflow-y: auto;">
                        <li class="dropdown-header d-flex justify-content-between align-items-center">
                            <span><?php echo __('admin.topbar.notification_center'); ?></span>
                            <a href="announcements.php" class="small text-decoration-none"><?php echo __('admin.topbar.manage_announcements'); ?></a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <!-- Dynamic Notifications Here -->
                        <li class="p-3 text-center text-muted small"><?php echo __('admin.topbar.no_notifications'); ?></li>
                    </ul>
                </div>

                <!-- Profile -->
                <div class="dropdown">
                    <div class="d-flex align-items-center gap-2 px-3 py-1 rounded-pill border shadow-sm bg-white text-dark" style="cursor: pointer;" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;">
                            <i class="fas fa-user-shield" style="font-size: 12px;"></i>
                        </div>
                        <span class="fw-medium small"><?php echo __('admin.topbar.admin'); ?></span>
                        <i class="fas fa-chevron-down small text-secondary"></i>
                    </div>
                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" style="min-width: 180px;">
                        <li><a class="dropdown-item small py-2" href="security.php"><i class="fas fa-shield-halved me-2 text-secondary"></i>安全设置</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item small py-2 text-danger" href="/logout.php"><i class="fas fa-sign-out-alt me-2"></i><?php echo __('merchant.nav.logout'); ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Content Wrapper Starts Here -->
        <!-- Pages will close divs in footer.php -->
