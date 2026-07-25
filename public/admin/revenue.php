<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
$db = Database::getInstance();
$db->autoMigrate();

// Ensure required columns exist before querying
try { $db->query("ALTER TABLE plans ADD COLUMN price DECIMAL(10,2) DEFAULT 0"); } catch (Throwable $e) {}
try { $db->query("ALTER TABLE orders ADD COLUMN order_origin VARCHAR(30) DEFAULT 'merchant_customer_order'"); } catch (Throwable $e) {}

// Plan distribution (active users per plan)
try {
    $plan_dist = $db->fetchAll(
        "SELECT p.name AS plan_name, p.price, COUNT(u.id) AS user_count
         FROM plans p
         LEFT JOIN users u ON u.plan_id = p.id AND u.status = 'active'
         GROUP BY p.id, p.name, p.price
         ORDER BY p.price DESC"
    );
} catch (Throwable $e) {
    $plan_dist = [];
}

// MRR / ARR estimate
$mrr = 0;
foreach ($plan_dist as $pd) {
    $mrr += (float)($pd['price'] ?? 0) * (int)($pd['user_count'] ?? 0);
}
$arr = $mrr * 12;

// Total users / active
try {
    $user_stats = $db->fetch("SELECT COUNT(*) AS total, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active FROM users");
} catch (Throwable $e) {
    $user_stats = ['total' => 0, 'active' => 0];
}

// Expiring soon (within 7 days, paid plan)
try {
    $expiring_soon = $db->fetchAll(
        "SELECT u.id, u.email, u.expire_at, p.name AS plan_name
         FROM users u LEFT JOIN plans p ON p.id = u.plan_id
         WHERE u.plan_id > 1 AND u.expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
         AND u.status = 'active'
         ORDER BY u.expire_at ASC LIMIT 50"
    );
} catch (Throwable $e) {
    $expiring_soon = [];
}

// Recent upgrade orders (last 30 days)
try {
    $recent_upgrades = $db->fetchAll(
        "SELECT o.order_no, o.amount, o.created_at, u.email
         FROM orders o JOIN users u ON u.id = o.user_id
         WHERE o.order_origin = 'upgrade' AND o.status = 'paid'
           AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
         ORDER BY o.created_at DESC LIMIT 20"
    );
} catch (Throwable $e) {
    $recent_upgrades = [];
}

// Monthly new users (last 6 months)
try {
    $monthly_new = $db->fetchAll(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS cnt
         FROM users
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
         GROUP BY ym ORDER BY ym ASC"
    );
} catch (Throwable $e) {
    $monthly_new = [];
}

$active_menu = 'revenue';
require_once 'includes/header.php';
?>

<!-- Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="mole-card text-center">
            <div class="text-muted small mb-1">月收入估算 (MRR)</div>
            <div class="fw-bold fs-4 text-success">$<?php echo number_format($mrr, 2); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="mole-card text-center">
            <div class="text-muted small mb-1">年收入估算 (ARR)</div>
            <div class="fw-bold fs-4">$<?php echo number_format($arr, 2); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="mole-card text-center">
            <div class="text-muted small mb-1">活跃用户</div>
            <div class="fw-bold fs-4"><?php echo number_format((int)($user_stats['active'] ?? 0)); ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="mole-card text-center">
            <div class="text-muted small mb-1">7天内到期</div>
            <div class="fw-bold fs-4 <?php echo count($expiring_soon) > 0 ? 'text-warning' : ''; ?>">
                <?php echo count($expiring_soon); ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Plan Distribution -->
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold">套餐分布</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">套餐</th>
                            <th>单价</th>
                            <th>用户数</th>
                            <th class="pe-4 text-end">月收入</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($plan_dist)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">暂无套餐数据</td></tr>
                    <?php else: ?>
                        <?php foreach ($plan_dist as $pd): ?>
                        <tr>
                            <td class="ps-4 fw-bold"><?php echo htmlspecialchars($pd['plan_name']); ?></td>
                            <td class="text-muted">$<?php echo number_format((float)($pd['price'] ?? 0), 2); ?></td>
                            <td><span class="badge bg-primary bg-opacity-10 text-primary"><?php echo (int)$pd['user_count']; ?></span></td>
                            <td class="pe-4 text-end fw-bold text-success">$<?php echo number_format((float)($pd['price'] ?? 0) * (int)$pd['user_count'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                    <tfoot class="border-top">
                        <tr>
                            <td class="ps-4 fw-bold" colspan="3">合计 MRR</td>
                            <td class="pe-4 text-end fw-bold text-success fs-5">$<?php echo number_format($mrr, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Expiring Soon -->
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span><i class="fas fa-clock text-warning me-2"></i>7天内到期用户</span>
                <span class="badge bg-warning text-dark"><?php echo count($expiring_soon); ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">邮箱</th>
                            <th>套餐</th>
                            <th>到期时间</th>
                            <th class="pe-4 text-end">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($expiring_soon)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">暂无即将到期用户</td></tr>
                    <?php else: ?>
                        <?php foreach ($expiring_soon as $eu): ?>
                        <tr>
                            <td class="ps-4 small"><?php echo htmlspecialchars($eu['email']); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($eu['plan_name'] ?? '-'); ?></span></td>
                            <td class="small text-danger fw-bold"><?php echo htmlspecialchars($eu['expire_at']); ?></td>
                            <td class="pe-4 text-end">
                                <a href="users.php?q=<?php echo urlencode($eu['email']); ?>" class="btn btn-sm btn-outline-primary">查看</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Recent Upgrades + Monthly Growth -->
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-arrow-up me-2 text-success"></i>近30天升级订单
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">邮箱</th>
                            <th>金额</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($recent_upgrades)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">暂无升级记录</td></tr>
                    <?php else: ?>
                        <?php foreach ($recent_upgrades as $ru): ?>
                        <tr>
                            <td class="ps-4 small"><?php echo htmlspecialchars($ru['email']); ?></td>
                            <td class="fw-bold text-success">$<?php echo number_format((float)$ru['amount'], 2); ?></td>
                            <td class="small text-muted"><?php echo date('m-d H:i', strtotime($ru['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-user-plus me-2 text-primary"></i>近6月新增用户趋势
            </div>
            <div class="card-body">
                <?php if (empty($monthly_new)): ?>
                    <p class="text-muted text-center py-4">暂无数据</p>
                <?php else: ?>
                    <canvas id="growthChart" style="max-height:200px;"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 text-center text-muted small">
    &copy; <?php echo date('Y'); ?> Admin Panel
</div>

<?php if (!empty($monthly_new)): ?>
<script>
(function() {
    const ctx = document.getElementById('growthChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode(array_column($monthly_new, 'ym')); ?>,
            datasets: [{
                label: '新增用户',
                data: <?php echo json_encode(array_column($monthly_new, 'cnt')); ?>,
                backgroundColor: 'rgba(59,130,246,0.7)',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
})();
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
