<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
$db = Database::getInstance();

$range = $_GET['range'] ?? 'today'; // today, week, month, year

$where = "";
switch ($range) {
    case 'today':
        $where = "WHERE o.created_at >= CURDATE()";
        break;
    case 'week':
        $where = "WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 WEEK)";
        break;
    case 'month':
        $where = "WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        break;
    case 'year':
        $where = "WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        break;
}

// Only count paid API orders (exclude tests and subscriptions)
$sql = "SELECT u.email, u.id as user_id, 
        COUNT(o.id) as order_count, 
        SUM(o.amount) as total_amount 
        FROM orders o 
        JOIN users u ON o.user_id = u.id 
        $where 
        AND o.status = 'paid'
        AND o.merchant_order_id NOT LIKE 'PLAN-%' 
        AND o.order_no NOT LIKE 'UPG%'
        GROUP BY u.id 
        ORDER BY order_count DESC 
        LIMIT 50";

$rankings = $db->fetchAll($sql);

$active_menu = 'leaderboard';
require_once 'includes/header.php';
?>

<div class="btn-group mb-4">
    <a href="?range=today" class="btn btn-outline-primary <?php echo $range=='today'?'active':''; ?>">今日</a>
    <a href="?range=week" class="btn btn-outline-primary <?php echo $range=='week'?'active':''; ?>">本周</a>
    <a href="?range=month" class="btn btn-outline-primary <?php echo $range=='month'?'active':''; ?>">本月</a>
    <a href="?range=year" class="btn btn-outline-primary <?php echo $range=='year'?'active':''; ?>">今年</a>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="bg-light">
                <tr>
                    <th width="80">排名</th>
                    <th>商户 (邮箱)</th>
                    <th>订单量 (笔)</th>
                    <th>交易总额 (USDT)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rankings as $index => $r): ?>
                <tr>
                    <td>
                        <?php if($index < 3): ?>
                            <span class="badge bg-warning text-dark rounded-pill">#<?php echo $index+1; ?></span>
                        <?php else: ?>
                            <span class="badge bg-secondary rounded-pill">#<?php echo $index+1; ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="users.php?search=<?php echo urlencode($r['email']); ?>"><?php echo htmlspecialchars($r['email']); ?></a>
                    </td>
                    <td class="fw-bold"><?php echo number_format($r['order_count']); ?></td>
                    <td class="text-success"><?php echo number_format($r['total_amount'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($rankings)): ?>
                <tr><td colspan="4" class="text-center py-4 text-muted">暂无数据</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
