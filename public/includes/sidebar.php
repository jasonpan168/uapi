<?php
// public/includes/sidebar.php
if (!defined('CURRENT_PAGE')) {
    define('CURRENT_PAGE', basename($_SERVER['PHP_SELF']));
}
if (!class_exists('I18n')) {
    require_once __DIR__ . '/../../src/Core/I18n.php';
    I18n::init();
}

// Ensure settings are loaded
if (!isset($site_name) || !isset($site_logo)) {
    if (!isset($db)) {
        require_once __DIR__ . '/../../src/Core/Database.php';
        $db = Database::getInstance();
    }
    $settings = $db->fetchAll("SELECT * FROM system_settings");
    $cfg = [];
    foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
    $site_name = $cfg['site_name'] ?? 'UAPI';
    $site_logo = $cfg['site_logo'] ?? '';
}

$show_webhook_menu = true;
$show_derived_menu = false;
if (isset($_SESSION['user_id'])) {
    try {
        $planRow = $db->fetch("SELECT p.allow_webhook_notice, p.allow_derived_wallet FROM users u LEFT JOIN plans p ON p.id = u.plan_id WHERE u.id = ? LIMIT 1", [$_SESSION['user_id']]);
        $show_webhook_menu = !isset($planRow['allow_webhook_notice']) || (int)$planRow['allow_webhook_notice'] === 1;
        $show_derived_menu = isset($planRow['allow_derived_wallet']) && (int)$planRow['allow_derived_wallet'] === 1;
    } catch (Exception $e) {
        $show_webhook_menu = true;
        $show_derived_menu = false;
    }
}
?>
<style>
    /* MoleAPI Sidebar Style - Clean & Spacious */
    .sidebar {
        background-color: #ffffff;
        border-right: 1px solid #e5e7eb;
        width: var(--merchant-sidebar-width, 236px);
        height: 100vh;
        position: sticky;
        top: 0;
        display: flex;
        flex-direction: column;
        overflow-y: auto;
        padding: 0; /* Remove default padding, handle inside */
        scrollbar-width: thin;
        flex-shrink: 0;
        transition: width 0.3s ease;
        z-index: 1000;
    }
    
    .sidebar-content {
        padding: 0 10px 24px 10px;
        display: flex;
        flex-direction: column;
        min-height: 100%;
        overflow: visible;
    }

    /* Scrollbar Styling */
    .sidebar::-webkit-scrollbar { width: 4px; }
    .sidebar::-webkit-scrollbar-thumb { background-color: rgba(0,0,0,0.1); border-radius: 4px; }

    /* Brand */
    .sidebar-brand {
        padding: 24px 8px 20px 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
        overflow: hidden;
        background: transparent !important;
        border-bottom: 1px solid var(--border-color);
        margin-bottom: 10px;
        position: static;
    }

    /* Navigation Links */
    .nav-pills .nav-link {
        color: #4b5563; /* Gray-600 */
        font-weight: 500;
        padding: 8px 10px;
        border-radius: 8px;
        margin-bottom: 2px; /* Reduced margin */
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        width: 100%;
        white-space: nowrap;
        overflow: hidden;
        font-size: 1rem;
    }
    
    .nav-pills .nav-link:hover {
        background-color: #f9fafb; /* Very light gray */
        color: #111827; /* Gray-900 */
        transform: translateX(2px);
    }
    
    .nav-pills .nav-link.active {
        background-color: #eff6ff; /* Blue-50 */
        color: #2563eb; /* Blue-600 */
        font-weight: 600;
    }
    
    .nav-link i {
        width: 24px;
        text-align: center;
        margin-right: 12px;
        font-size: 1.1em;
        flex-shrink: 0;
        opacity: 0.8;
    }
    .nav-link.active i {
        opacity: 1;
    }

    /* Section Headings */
    .sidebar-heading {
        font-size: 0.7rem;
        text-transform: uppercase;
        color: #9ca3af;
        font-weight: 700;
        padding: 16px 10px 8px 10px;
        letter-spacing: 0.05em;
        white-space: nowrap;
    }

    /* Toggle Button */
    .sidebar-toggle {
        margin-top: 16px;
        padding: 16px 0;
        text-align: center;
        cursor: pointer;
        color: #9ca3af;
        transition: color 0.2s;
        border-top: 1px solid #f3f4f6;
    }
    .sidebar-toggle:hover { color: #2563eb; }

    /* Collapsed State */
    .sidebar.collapsed { width: 80px !important; min-width: 80px !important; max-width: 80px !important; }
    .sidebar.collapsed .sidebar-brand span,
    .sidebar.collapsed .sidebar-heading,
    .sidebar.collapsed .nav-link span { display: none; }
    .sidebar.collapsed .nav-link { justify-content: center; padding: 12px 0; }
    .sidebar.collapsed .nav-link i { margin-right: 0; font-size: 1.3em; }
    .sidebar.collapsed .sidebar-brand { justify-content: center; padding-left: 0; padding-right: 0; }
    .sidebar.collapsed .sidebar-brand img {
        max-width: 42px;
        max-height: 30px;
        width: auto;
        height: auto;
        object-fit: contain;
    }
    .sidebar-brand img {
        display: block;
        margin: 0 auto;
    }
    .sidebar-mobile-backdrop {
        display: none;
    }
    @media (max-width: 767.98px) {
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: min(280px, 86vw);
            height: 100dvh;
            max-width: 100%;
            transform: translateX(-100%);
            transition: transform 0.25s ease;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.22);
        }
        .sidebar.is-open {
            transform: translateX(0);
        }
        .sidebar.collapsed {
            width: min(280px, 86vw) !important;
            min-width: 0 !important;
            max-width: 100% !important;
        }
        .sidebar.collapsed .sidebar-brand span,
        .sidebar.collapsed .sidebar-heading,
        .sidebar.collapsed .nav-link span {
            display: inline;
        }
        .sidebar.collapsed .nav-link {
            justify-content: flex-start;
            padding: 8px 10px;
        }
        .sidebar.collapsed .nav-link i {
            margin-right: 12px;
            font-size: 1.1em;
        }
        .sidebar.collapsed .sidebar-brand {
            justify-content: center;
            padding-left: 8px;
            padding-right: 8px;
        }
        .sidebar-toggle {
            display: none;
        }
        .sidebar-mobile-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.35);
            backdrop-filter: blur(1px);
            z-index: 999;
        }
        .sidebar-mobile-backdrop.is-visible {
            display: block;
        }
        body.user-sidebar-open {
            overflow: hidden;
        }
    }
</style>

<div class="sidebar d-flex" id="userSidebar">
    <div class="sidebar-content">
        <!-- Brand -->
        <div class="sidebar-brand">
            <?php if (!empty($site_logo)): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="logo" style="max-height:36px; width:auto;">
            <?php else: ?>
                <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white" style="width: 36px; height: 36px; flex-shrink:0;">
                    <i class="fas fa-bolt" style="font-size: 18px;"></i>
                </div>
            <?php endif; ?>
        </div>

        <!-- Menu Items -->
        <div class="nav flex-column nav-pills mt-2">
            
            <!-- Dashboard Section -->
            <div class="sidebar-heading" style="padding-top: 0;"><?php echo __('merchant.sidebar.console'); ?></div>
            
            <a class="nav-link <?php echo CURRENT_PAGE == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                <i class="fas fa-th-large"></i> <span><?php echo __('merchant.nav.dashboard'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'analytics.php' ? 'active' : ''; ?>" href="analytics.php">
                <i class="fas fa-chart-pie"></i> <span><?php echo __('merchant.nav.analytics'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'balance.php' ? 'active' : ''; ?>" href="balance.php">
                <i class="fas fa-wallet"></i> <span><?php echo __('merchant.nav.balance'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'orders.php' ? 'active' : ''; ?>" href="orders.php">
                <i class="fas fa-list-alt"></i> <span><?php echo __('merchant.nav.orders'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'notifications.php' ? 'active' : ''; ?>" href="notifications.php">
                <i class="fas fa-bell"></i> <span><?php echo __('merchant.nav.notifications'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'api_settings.php' ? 'active' : ''; ?>" href="api_settings.php">
                <i class="fas fa-key"></i> <span>API KEY</span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'payment_links.php' ? 'active' : ''; ?>" href="payment_links.php">
                <i class="fas fa-link"></i> <span><?php echo __('merchant.nav.payment_links'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'qr_codes.php' ? 'active' : ''; ?>" href="qr_codes.php">
                <i class="fas fa-qrcode"></i> <span><?php echo __('merchant.nav.qr_codes'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'payment_config.php' ? 'active' : ''; ?>" href="payment_config.php">
                <i class="fas fa-wallet"></i> <span><?php echo __('merchant.nav.payment_config'); ?></span>
            </a>
            <?php if ($show_derived_menu): ?>
                <a class="nav-link <?php echo CURRENT_PAGE == 'derived_wallets.php' ? 'active' : ''; ?>" href="derived_wallets.php">
                    <i class="fas fa-sitemap"></i> <span><?php echo __('merchant.nav.derived_wallets'); ?></span>
                </a>
            <?php endif; ?>

            <!-- Services Section -->
            <div class="sidebar-heading"><?php echo __('merchant.sidebar.services'); ?></div>
            
            <a class="nav-link <?php echo CURRENT_PAGE == 'plugins.php' ? 'active' : ''; ?>" href="plugins.php">
                <i class="fas fa-plug"></i> <span><?php echo __('merchant.nav.plugins'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'store.php' ? 'active' : ''; ?>" href="store.php">
                <i class="fas fa-store"></i> <span><?php echo __('merchant.nav.store'); ?></span>
            </a>

            <!-- Personal Section -->
            <div class="sidebar-heading"><?php echo __('merchant.sidebar.account'); ?></div>

            <a class="nav-link <?php echo CURRENT_PAGE == 'tickets.php' ? 'active' : ''; ?>" href="tickets.php">
                <i class="fas fa-headset"></i> <span><?php echo __('merchant.nav.tickets'); ?></span>
            </a>

            <a class="nav-link <?php echo CURRENT_PAGE == 'referral.php' ? 'active' : ''; ?>" href="referral.php">
                <i class="fas fa-gift"></i> <span><?php echo __('merchant.nav.referral'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'upgrade.php' ? 'active' : ''; ?>" href="upgrade.php">
                <i class="fas fa-gem text-warning"></i> <span><?php echo __('merchant.nav.upgrade'); ?></span>
            </a>
            <a class="nav-link <?php echo CURRENT_PAGE == 'guide.php' ? 'active' : ''; ?>" href="guide.php">
                <i class="fas fa-book"></i> <span><?php echo __('merchant.nav.guide'); ?></span>
            </a>

            <!-- Logout Section (Spacing handled by margin) -->
            <div class="mt-4 pt-2">
                <a class="nav-link text-danger" href="logout.php">
                    <i class="fas fa-sign-out-alt"></i> <span><?php echo __('merchant.nav.logout'); ?></span>
                </a>
            </div>
            
            <!-- Collapse Toggle -->
            <div class="sidebar-toggle" onclick="toggleSidebar()">
                <i class="fas fa-chevron-left" id="sidebarToggleIcon"></i>
            </div>
        </div>
    </div>
</div>
<div class="sidebar-mobile-backdrop" id="userSidebarBackdrop" onclick="closeUserSidebar()"></div>

<script>
function toggleSidebar() {
    const sb = document.getElementById('userSidebar');
    const icon = document.getElementById('sidebarToggleIcon');
    sb.classList.toggle('collapsed');
    
    if (sb.classList.contains('collapsed')) {
        icon.className = 'fas fa-chevron-right';
        localStorage.setItem('sidebar_collapsed', '1');
        document.body.classList.add('merchant-sidebar-collapsed');
    } else {
        icon.className = 'fas fa-chevron-left';
        localStorage.setItem('sidebar_collapsed', '0');
        document.body.classList.remove('merchant-sidebar-collapsed');
    }
}

function toggleUserSidebar() {
    if (window.innerWidth >= 768) return;
    const sidebar = document.getElementById('userSidebar');
    const backdrop = document.getElementById('userSidebarBackdrop');
    const willOpen = !sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', willOpen);
    backdrop.classList.toggle('is-visible', willOpen);
    document.body.classList.toggle('user-sidebar-open', willOpen);
}

function closeUserSidebar() {
    const sidebar = document.getElementById('userSidebar');
    const backdrop = document.getElementById('userSidebarBackdrop');
    sidebar.classList.remove('is-open');
    backdrop.classList.remove('is-visible');
    document.body.classList.remove('user-sidebar-open');
}

// Init State
if (localStorage.getItem('sidebar_collapsed') === '1') {
    document.getElementById('userSidebar').classList.add('collapsed');
    document.getElementById('sidebarToggleIcon').className = 'fas fa-chevron-right';
    document.body.classList.add('merchant-sidebar-collapsed');
} else {
    document.body.classList.remove('merchant-sidebar-collapsed');
}

window.addEventListener('resize', function() {
    if (window.innerWidth >= 768) {
        closeUserSidebar();
    }
});

document.querySelectorAll('#userSidebar .nav-link').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth < 768) {
            closeUserSidebar();
        }
    });
});
</script>
