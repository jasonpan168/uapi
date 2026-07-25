<?php
$active_menu = $active_menu ?? 'dashboard';
?>
<style>
    /* Admin Sidebar - MoleAPI Style */
    .sidebar {
        background-color: #ffffff;
        border-right: 1px solid #e5e7eb;
        height: 100vh;
        width: 220px !important; /* Reduced from 260px */
        position: sticky;
        top: 0;
        overflow-y: auto;
        z-index: 100;
    }
    .sidebar::-webkit-scrollbar { width: 4px; }
    .sidebar::-webkit-scrollbar-track { background: transparent; }
    .sidebar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
    .sidebar::-webkit-scrollbar-thumb:hover { background: #d1d5db; }
    .sidebar-brand {
        padding: 24px 16px;
        border-bottom: 1px solid transparent;
        white-space: nowrap;
        overflow: hidden;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    .nav-pills .nav-link {
        color: #4b5563;
        font-weight: 500;
        padding: 10px 12px;
        margin: 4px 8px;
        border-radius: 8px;
        transition: all 0.2s;
        white-space: nowrap;
        overflow: hidden;
    }
    .nav-pills .nav-link:hover {
        background-color: #f3f4f6;
        color: #111827;
    }
    .nav-pills .nav-link.active {
        background-color: #eff6ff;
        color: #3b82f6;
    }
    .nav-link i {
        width: 20px;
        text-align: center;
        margin-right: 8px;
        font-size: 1.1em;
        flex-shrink: 0;
    }
    .sidebar hr {
        border-color: #e5e7eb;
        margin: 16px 0;
    }
    .admin-sidebar-backdrop {
        display: none;
    }
    @media (max-width: 767.98px) {
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: min(280px, 86vw) !important;
            height: 100dvh;
            transform: translateX(-100%);
            transition: transform 0.25s ease;
            box-shadow: 0 18px 40px rgba(15, 23, 42, 0.22);
            z-index: 1045;
        }
        .sidebar.is-open {
            transform: translateX(0);
        }
        .admin-sidebar-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.35);
            backdrop-filter: blur(1px);
            z-index: 1040;
        }
        .admin-sidebar-backdrop.is-visible {
            display: block;
        }
        body.admin-sidebar-open {
            overflow: hidden;
        }
    }
</style>

<div class="sidebar d-flex flex-column flex-shrink-0" id="adminSidebar">
    <a href="index.php" class="sidebar-brand text-decoration-none">
        <?php if ($site_logo): ?>
            <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="logo" style="max-height:36px; width:auto;">
        <?php else: ?>
            <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white" style="width: 36px; height: 36px;">
                <i class="fas fa-shield-alt" style="font-size: 18px;"></i>
            </div>
        <?php endif; ?>
    </a>
    
    <ul class="nav nav-pills flex-column mb-auto mt-2">
        <li class="nav-item">
            <a href="index.php" class="nav-link <?php echo $active_menu=='dashboard'?'active':''; ?>">
                <i class="fas fa-th-large"></i> <?php echo __('admin.nav.dashboard'); ?>
            </a>
        </li>
        <li>
            <a href="users.php" class="nav-link <?php echo $active_menu=='users'?'active':''; ?>">
                <i class="fas fa-users"></i> <?php echo __('admin.nav.users'); ?>
            </a>
        </li>
        <li>
            <a href="orders.php" class="nav-link <?php echo $active_menu=='orders'?'active':''; ?>">
                <i class="fas fa-list-alt"></i> <?php echo __('admin.nav.orders'); ?>
            </a>
        </li>
        <li>
            <a href="tickets.php" class="nav-link <?php echo $active_menu=='tickets'?'active':''; ?>">
                <i class="fas fa-headset"></i> <?php echo __('admin.nav.tickets'); ?>
            </a>
        </li>
        <li>
            <a href="leaderboard.php" class="nav-link <?php echo $active_menu=='leaderboard'?'active':''; ?>">
                <i class="fas fa-trophy"></i> <?php echo __('admin.nav.leaderboard'); ?>
            </a>
        </li>
        <li>
            <a href="revenue.php" class="nav-link <?php echo $active_menu=='revenue'?'active':''; ?>">
                <i class="fas fa-chart-line"></i> 收入报表
            </a>
        </li>
        <li>
            <a href="referrals.php" class="nav-link <?php echo $active_menu=='referrals'?'active':''; ?>">
                <i class="fas fa-gift"></i> <?php echo __('admin.nav.referrals'); ?>
            </a>
        </li>
        <li>
            <a href="websites.php" class="nav-link <?php echo $active_menu=='websites'?'active':''; ?>">
                <i class="fas fa-globe"></i> <?php echo __('admin.nav.websites'); ?>
            </a>
        </li>
        <li>
            <a href="api_stats.php" class="nav-link <?php echo $active_menu=='api_stats'?'active':''; ?>">
                <i class="fas fa-chart-bar"></i> <?php echo __('admin.nav.api_stats'); ?>
            </a>
        </li>
        <li>
            <a href="derived_wallets.php" class="nav-link <?php echo $active_menu=='derived_wallets'?'active':''; ?>">
                <i class="fas fa-sitemap"></i> <?php echo __('admin.nav.derived_wallets'); ?>
            </a>
        </li>
        <li>
            <a href="merchant_derived.php" class="nav-link <?php echo $active_menu=='merchant_derived'?'active':''; ?>">
                <i class="fas fa-network-wired"></i> 商户派生管理
            </a>
        </li>
        <li>
            <a href="webhook_logs.php" class="nav-link <?php echo $active_menu=='webhook_logs'?'active':''; ?>">
                <i class="fas fa-paper-plane"></i> <?php echo __('admin.nav.webhook_logs'); ?>
            </a>
        </li>
        <li>
            <a href="monitor.php" class="nav-link <?php echo $active_menu=='monitor'?'active':''; ?>">
                <i class="fas fa-server"></i> <?php echo __('admin.nav.monitor'); ?>
            </a>
        </li>

        <li>
            <a href="marketing.php" class="nav-link <?php echo $active_menu=='marketing'?'active':''; ?>">
                <i class="fas fa-ticket-alt"></i> <?php echo __('admin.nav.marketing'); ?>
            </a>
        </li>
        <li>
            <a href="broadcast.php" class="nav-link <?php echo $active_menu=='broadcast'?'active':''; ?>">
                <i class="fas fa-envelope-open-text"></i> 邮件群发
            </a>
        </li>
        <li>
            <a href="plans.php" class="nav-link <?php echo $active_menu=='plans'?'active':''; ?>">
                <i class="fas fa-tags"></i> <?php echo __('admin.nav.plans'); ?>
            </a>
        </li>
        <li>
            <a href="settings.php" class="nav-link <?php echo $active_menu=='settings'?'active':''; ?>">
                <i class="fas fa-cog"></i> <?php echo __('admin.nav.settings'); ?>
            </a>
        </li>
        <li>
            <a href="security.php" class="nav-link <?php echo $active_menu=='security'?'active':''; ?>">
                <i class="fas fa-shield-halved"></i> 安全设置
            </a>
        </li>
        <li>
            <a href="binance_merchant.php" class="nav-link <?php echo $active_menu=='binance_merchant'?'active':''; ?>">
                <i class="fab fa-bitcoin"></i> <?php echo __('admin.nav.binance_merchant'); ?>
            </a>
        </li>
    </ul>
    
    <div class="p-3 border-top border-light">
        <div class="dropdown">
            <a href="#" class="d-flex align-items-center text-dark text-decoration-none dropdown-toggle" id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="rounded-circle bg-dark text-white d-flex align-items-center justify-content-center me-2" style="width: 32px; height: 32px;">
                    <i class="fas fa-user"></i>
                </div>
                <strong><?php echo __('admin.topbar.admin'); ?></strong>
            </a>
            <ul class="dropdown-menu text-small shadow" aria-labelledby="dropdownUser1">
                <li><a class="dropdown-item" href="/logout.php"><?php echo __('merchant.nav.logout'); ?></a></li>
            </ul>
        </div>
    </div>
</div>
<div class="admin-sidebar-backdrop" id="adminSidebarBackdrop" onclick="closeAdminSidebar()"></div>

<script>
function toggleAdminSidebar() {
    if (window.innerWidth >= 768) return;
    const sidebar = document.getElementById('adminSidebar');
    const backdrop = document.getElementById('adminSidebarBackdrop');
    const willOpen = !sidebar.classList.contains('is-open');
    sidebar.classList.toggle('is-open', willOpen);
    backdrop.classList.toggle('is-visible', willOpen);
    document.body.classList.toggle('admin-sidebar-open', willOpen);
}

function closeAdminSidebar() {
    const sidebar = document.getElementById('adminSidebar');
    const backdrop = document.getElementById('adminSidebarBackdrop');
    sidebar.classList.remove('is-open');
    backdrop.classList.remove('is-visible');
    document.body.classList.remove('admin-sidebar-open');
}

window.addEventListener('resize', function() {
    if (window.innerWidth >= 768) {
        closeAdminSidebar();
    }
});

document.querySelectorAll('#adminSidebar .nav-link').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth < 768) {
            closeAdminSidebar();
        }
    });
});
</script>
