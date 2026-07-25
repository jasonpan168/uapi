<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';

$db = Database::getInstance();

// Ensure table exists
try {
    $db->query(
        "CREATE TABLE IF NOT EXISTS stripe_webhook_logs (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            event_id    VARCHAR(100) DEFAULT '',
            event_type  VARCHAR(80)  DEFAULT '',
            order_no    VARCHAR(64)  DEFAULT '',
            status      VARCHAR(20)  DEFAULT 'received',
            detail      TEXT,
            ip          VARCHAR(64)  DEFAULT '',
            created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id),
            INDEX idx_order_no (order_no),
            INDEX idx_created  (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $ignore) {}

// Filters
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 30;
$offset     = ($page - 1) * $limit;
$filterStatus = $_GET['status'] ?? '';
$filterOrder  = trim($_GET['order_no'] ?? '');

$where  = [];
$params = [];
if ($filterStatus !== '') { $where[] = 'status = ?'; $params[] = $filterStatus; }
if ($filterOrder  !== '') { $where[] = 'order_no LIKE ?'; $params[] = "%$filterOrder%"; }
$whereSQL = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total = (int)($db->fetch("SELECT COUNT(*) AS c FROM stripe_webhook_logs $whereSQL", $params)['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));
$page = min($page, $totalPages);

$logs = $db->fetchAll(
    "SELECT * FROM stripe_webhook_logs $whereSQL ORDER BY id DESC LIMIT $limit OFFSET $offset",
    $params
);

// Delete old logs (keep 90 days)
try { $db->query("DELETE FROM stripe_webhook_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)"); } catch (Throwable $ignore) {}

$active_menu = 'stripe_webhooks';
require_once 'includes/header.php';
?>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={corePlugins:{preflight:false}}</script>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    <div class="flex justify-between items-center mb-5 flex-wrap gap-3">
        <div>
            <h4 class="text-lg font-bold text-gray-800 mb-0.5">Stripe Webhook 接收日志</h4>
            <p class="text-sm text-gray-500">记录 Stripe 发送到本系统的所有事件通知（保留90天）</p>
        </div>
        <a href="https://dashboard.stripe.com/webhooks" target="_blank" rel="noopener"
           class="btn btn-sm btn-outline-primary">在 Stripe 后台查看 →</a>
    </div>

    <!-- Filters -->
    <form method="GET" class="flex gap-2 flex-wrap mb-4">
        <select name="status" class="border border-gray-300 rounded-lg text-sm px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
            <option value="">全部状态</option>
            <option value="received"  <?php echo $filterStatus==='received'  ?'selected':''; ?>>received</option>
            <option value="success"   <?php echo $filterStatus==='success'   ?'selected':''; ?>>success</option>
            <option value="duplicate" <?php echo $filterStatus==='duplicate' ?'selected':''; ?>>duplicate</option>
            <option value="ignored"   <?php echo $filterStatus==='ignored'   ?'selected':''; ?>>ignored</option>
            <option value="error"     <?php echo $filterStatus==='error'     ?'selected':''; ?>>error</option>
        </select>
        <input type="text" name="order_no" value="<?php echo htmlspecialchars($filterOrder); ?>"
               placeholder="订单号" class="border border-gray-300 rounded-lg text-sm px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500 w-44">
        <button type="submit" class="bg-gray-800 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-gray-700">筛选</button>
        <?php if ($filterStatus || $filterOrder): ?>
        <a href="stripe_webhooks.php" class="border border-gray-300 text-gray-600 px-3 py-1.5 rounded-lg text-sm hover:bg-gray-50">清除</a>
        <?php endif; ?>
    </form>

    <!-- Stats bar -->
    <?php
    $stats = $db->fetch("SELECT
        COUNT(*) AS total,
        SUM(status='success')  AS ok,
        SUM(status='duplicate') AS dup,
        SUM(status='error')    AS err,
        SUM(status='received') AS recv
        FROM stripe_webhook_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    ?>
    <div class="grid grid-cols-4 gap-3 mb-5">
        <?php foreach ([
            ['近7天总计', $stats['total'] ?? 0, 'bg-blue-50 text-blue-700'],
            ['成功处理',  $stats['ok']    ?? 0, 'bg-green-50 text-green-700'],
            ['重复（已忽略）', $stats['dup'] ?? 0, 'bg-yellow-50 text-yellow-700'],
            ['错误',      $stats['err']   ?? 0, 'bg-red-50 text-red-700'],
        ] as [$label, $val, $cls]): ?>
        <div class="rounded-xl p-3 <?php echo $cls; ?>">
            <div class="text-xs font-medium opacity-70"><?php echo $label; ?></div>
            <div class="text-xl font-bold mt-0.5"><?php echo (int)$val; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Table -->
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-xs text-gray-500 font-semibold">时间</th>
                    <th class="text-xs text-gray-500 font-semibold">Event Type</th>
                    <th class="text-xs text-gray-500 font-semibold">Event ID</th>
                    <th class="text-xs text-gray-500 font-semibold">订单号</th>
                    <th class="text-xs text-gray-500 font-semibold">状态</th>
                    <th class="text-xs text-gray-500 font-semibold">详情</th>
                    <th class="text-xs text-gray-500 font-semibold">来源 IP</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="7" class="text-center text-muted py-10">
                    <?php if (!$filterStatus && !$filterOrder): ?>
                        暂无日志。Stripe 向 webhook 地址发送事件后将在此显示。
                    <?php else: ?>
                        没有匹配的记录。
                    <?php endif; ?>
                </td></tr>
            <?php else: foreach ($logs as $log):
                $statusClass = match((string)($log['status'] ?? '')) {
                    'success'   => 'badge bg-success',
                    'duplicate' => 'badge bg-warning text-dark',
                    'error'     => 'badge bg-danger',
                    'ignored'   => 'badge bg-secondary',
                    default     => 'badge bg-info text-dark',
                };
            ?>
                <tr>
                    <td class="text-nowrap" style="font-size:.8rem;"><?php echo htmlspecialchars((string)($log['created_at'] ?? '')); ?></td>
                    <td><code style="font-size:.76rem;"><?php echo htmlspecialchars((string)($log['event_type'] ?? '')); ?></code></td>
                    <td style="font-size:.74rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                        title="<?php echo htmlspecialchars((string)($log['event_id'] ?? '')); ?>">
                        <?php echo htmlspecialchars((string)($log['event_id'] ?? '-')); ?>
                    </td>
                    <td>
                        <?php if (!empty($log['order_no'])): ?>
                        <a href="orders.php?search=<?php echo urlencode((string)$log['order_no']); ?>" style="font-size:.8rem;">
                            <?php echo htmlspecialchars((string)$log['order_no']); ?>
                        </a>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="<?php echo $statusClass; ?>"><?php echo htmlspecialchars((string)($log['status'] ?? '')); ?></span></td>
                    <td style="font-size:.78rem;color:#6b7280;max-width:200px;"><?php echo htmlspecialchars((string)($log['detail'] ?? '')); ?></td>
                    <td style="font-size:.75rem;color:#9ca3af;"><?php echo htmlspecialchars((string)($log['ip'] ?? '')); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $i; ?>&status=<?php echo urlencode($filterStatus); ?>&order_no=<?php echo urlencode($filterOrder); ?>">
                    <?php echo $i; ?>
                </a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<?php require_once 'includes/footer.php'; ?>
