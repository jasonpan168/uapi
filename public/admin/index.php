<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();

require_once __DIR__ . '/../../src/Core/Database.php';
$db = Database::getInstance();

require_once __DIR__ . '/../../src/Core/Migrator.php';
$migrator = new Migrator($db->getConnection());
$migrator->run();

// Site config
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';

// Stats
$total_users = $db->fetch("SELECT COUNT(*) as c FROM users")['c'];
$total_orders = $db->fetch("SELECT COUNT(*) as c FROM orders")['c'];
$paid_orders = $db->fetch("SELECT COUNT(*) as c FROM orders WHERE status = 'paid'")['c'];
$total_volume = $db->fetch("SELECT SUM(amount) as s FROM orders WHERE status = 'paid'")['s'] ?? 0;

// Recent Orders
$recent_orders = $db->fetchAll("SELECT o.*, u.email FROM orders o LEFT JOIN users u ON o.user_id = u.id ORDER BY o.id DESC LIMIT 10");

// Chart Data (Last 7 Days)
$chart_labels = [];
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $chart_labels[] = date('m-d', strtotime($date));
    $sum = $db->fetch("SELECT SUM(amount) as s FROM orders WHERE status = 'paid' AND DATE(created_at) = ?", [$date])['s'] ?? 0;
    $chart_data[] = $sum;
}

$active_menu = 'dashboard';
require_once 'includes/header.php';
?>

<!-- Stats Row -->
<div class="row g-4 mb-4">
    <!-- Users -->
    <div class="col-md-3">
        <div class="mole-card d-flex align-items-start">
            <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary">
                <i class="fas fa-users fa-lg"></i>
            </div>
            <div>
                <div class="stat-label">用户总数</div>
                <div class="stat-value"><?php echo number_format($total_users); ?></div>
            </div>
        </div>
    </div>
    <!-- Volume -->
    <div class="col-md-3">
        <div class="mole-card d-flex align-items-start">
            <div class="stat-icon-wrapper bg-success bg-opacity-10 text-success">
                <i class="fas fa-wallet fa-lg"></i>
            </div>
            <div>
                <div class="stat-label">总交易额</div>
                <div class="stat-value">$<?php echo number_format($total_volume, 2); ?></div>
            </div>
        </div>
    </div>
    <!-- Paid Orders -->
    <div class="col-md-3">
        <div class="mole-card d-flex align-items-start">
            <div class="stat-icon-wrapper bg-warning bg-opacity-10 text-warning">
                <i class="fas fa-check-circle fa-lg"></i>
            </div>
            <div>
                <div class="stat-label">已支付订单</div>
                <div class="stat-value"><?php echo number_format($paid_orders); ?></div>
            </div>
        </div>
    </div>
    <!-- Total Orders -->
    <div class="col-md-3">
        <div class="mole-card d-flex align-items-start">
            <div class="stat-icon-wrapper bg-info bg-opacity-10 text-info">
                <i class="fas fa-list fa-lg"></i>
            </div>
            <div>
                <div class="stat-label">订单总数</div>
                <div class="stat-value"><?php echo number_format($total_orders); ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Chart & System Status -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="mole-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h6 class="fw-bold mb-0">近 7 日交易趋势</h6>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary active">7天</button>
                    <button class="btn btn-outline-secondary">30天</button>
                </div>
            </div>
            <canvas id="volumeChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="mole-card">
            <h6 class="fw-bold mb-4">系统状态</h6>
            <ul class="list-group list-group-flush border-0">
                <li class="list-group-item border-0 px-0 d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small"><i class="fab fa-php me-2"></i>PHP 版本</span>
                    <span class="badge-mole gray"><?php echo PHP_VERSION; ?></span>
                </li>
                <li class="list-group-item border-0 px-0 d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small"><i class="fas fa-database me-2"></i>数据库</span>
                    <span class="badge-mole success">MySQL Connected</span>
                </li>
                <li class="list-group-item border-0 px-0 d-flex justify-content-between align-items-center mb-2">
                    <span class="text-secondary small"><i class="fas fa-clock me-2"></i>服务器时间</span>
                    <span class="font-monospace small text-dark"><?php echo date('H:i:s'); ?></span>
                </li>
                <li class="list-group-item border-0 px-0 d-flex justify-content-between align-items-center">
                    <span class="text-secondary small"><i class="fas fa-server me-2"></i>系统负载</span>
                    <span class="badge-mole info">Normal</span>
                </li>
            </ul>
            
            <hr class="text-secondary opacity-10 my-4">
            
            <h6 class="fw-bold mb-3">快速操作</h6>
            <div class="d-grid gap-2">
                <a href="settings.php" class="btn btn-light btn-sm text-start text-secondary"><i class="fas fa-cog me-2"></i>系统设置</a>
                <a href="plans.php" class="btn btn-light btn-sm text-start text-secondary"><i class="fas fa-tags me-2"></i>管理套餐</a>
            </div>
        </div>
    </div>
</div>

<!-- Recent Orders Table -->
<div class="mole-card p-0 overflow-hidden">
    <div class="d-flex justify-content-between align-items-center p-4 border-bottom border-light">
        <h6 class="fw-bold mb-0">最新订单记录</h6>
        <a href="orders.php" class="btn btn-sm btn-light border text-secondary">查看全部</a>
    </div>
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>用户</th>
                    <th>金额</th>
                    <th>网络</th>
                    <th>状态</th>
                    <th>时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($recent_orders as $o): ?>
                <tr>
                    <td class="font-monospace text-secondary small"><?php echo $o['order_no']; ?></td>
                    <td>
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-light text-secondary d-flex align-items-center justify-content-center me-2" style="width: 24px; height: 24px; font-size: 10px;">
                                <i class="fas fa-user"></i>
                            </div>
                            <span class="small"><?php echo htmlspecialchars($o['email'] ?? 'Unknown'); ?></span>
                        </div>
                    </td>
                    <td class="fw-bold">$<?php echo $o['amount']; ?></td>
                    <td><span class="badge-mole gray text-uppercase"><?php echo $o['chain']; ?></span></td>
                    <td>
                        <span class="badge-mole <?php echo $o['status']=='paid'?'success':($o['status']=='pending'?'warning':'gray'); ?>">
                            <?php echo $o['status']=='paid'?'已支付':($o['status']=='pending'?'待支付':$o['status']); ?>
                        </span>
                    </td>
                    <td class="text-secondary small"><?php echo date('m-d H:i', strtotime($o['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4 text-center text-muted small">
    &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?> Admin Panel.
</div>

<script>
const ctx = document.getElementById('volumeChart').getContext('2d');
// Gradient
const gradient = ctx.createLinearGradient(0, 0, 0, 400);
gradient.addColorStop(0, 'rgba(59, 130, 246, 0.2)');
gradient.addColorStop(1, 'rgba(59, 130, 246, 0)');

new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($chart_labels); ?>,
        datasets: [{
            label: '交易额 (USDT)',
            data: <?php echo json_encode($chart_data); ?>,
            borderColor: '#3b82f6',
            backgroundColor: gradient,
            borderWidth: 2,
            pointBackgroundColor: '#ffffff',
            pointBorderColor: '#3b82f6',
            pointRadius: 4,
            pointHoverRadius: 6,
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: '#1f2937',
                titleColor: '#f9fafb',
                bodyColor: '#f9fafb',
                padding: 10,
                cornerRadius: 8,
                displayColors: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: {
                    color: '#f3f4f6',
                    borderDash: [5, 5]
                },
                ticks: { color: '#9ca3af' }
            },
            x: {
                grid: { display: false },
                ticks: { color: '#9ca3af' }
            }
        }
    }
});

document.addEventListener('DOMContentLoaded', function () {
    const profileDropdownMenu = document.querySelector('.admin-topbar-actions .dropdown:last-child .dropdown-menu');
    if (!profileDropdownMenu || profileDropdownMenu.querySelector('.js-switch-merchant-link')) {
        return;
    }

    const switchItem = document.createElement('li');
    switchItem.innerHTML = '<a class="dropdown-item small py-2 js-switch-merchant-link" href="/dashboard.php"><i class="fas fa-store me-2 text-secondary"></i>切换商户后台</a>';
    profileDropdownMenu.insertBefore(switchItem, profileDropdownMenu.firstChild);

    const divider = document.createElement('li');
    divider.innerHTML = '<hr class="dropdown-divider">';
    profileDropdownMenu.insertBefore(divider, switchItem.nextSibling);
});
</script>

<?php require_once 'includes/footer.php'; ?>
