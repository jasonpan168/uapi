<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Services/UrlSafetyService.php';
$db = Database::getInstance();
$db->autoMigrate();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$admin_csrf_token = $_SESSION['admin_csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        header('Location: webhook_logs.php?msg=csrf');
        exit;
    }

    $order_id = (int)($_POST['order_id'] ?? 0);
    $order = $db->fetch("SELECT * FROM orders WHERE id = ?", [$order_id]);
    if (!$order) {
        header('Location: webhook_logs.php?msg=notfound');
        exit;
    }
    if (($order['status'] ?? '') !== 'paid') {
        header('Location: webhook_logs.php?msg=notpaid');
        exit;
    }

    if (empty($order['notify_url'])) {
        $u = $db->fetch("SELECT webhook_url FROM users WHERE id = ?", [$order['user_id']]);
        if (!empty($u['webhook_url']) && UrlSafetyService::isSafeUrl($u['webhook_url'])) {
            $order['notify_url'] = $u['webhook_url'];
            $db->query("UPDATE orders SET notify_url = ? WHERE id = ?", [$order['notify_url'], $order_id]);
        }
    }

    if (empty($order['notify_url'])) {
        header('Location: webhook_logs.php?msg=no_target');
        exit;
    }

    require_once __DIR__ . '/../../src/Services/WebhookService.php';
    $ok = WebhookService::send($order);
    header('Location: webhook_logs.php?msg=' . ($ok ? 'retry_ok' : 'retry_fail'));
    exit;
}

$q = trim($_GET['q'] ?? '');
$code = trim($_GET['code'] ?? '');
$params = [];
$where = [];

if ($q !== '') {
    $where[] = "(o.order_no LIKE ? OR o.merchant_order_id LIKE ? OR u.email LIKE ?)";
    $kw = '%' . $q . '%';
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}
if ($code !== '') {
    $where[] = "wl.response_code = ?";
    $params[] = (int)$code;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$per_page = 30;
$page = max(1, (int)($_GET['page'] ?? 1));

$countRow = $db->fetch(
    "SELECT COUNT(*) AS c
     FROM webhook_logs wl
     INNER JOIN orders o ON o.id = wl.order_id
     LEFT JOIN users u ON u.id = o.user_id
     $whereSql",
    $params
);
$total = (int)($countRow['c'] ?? 0);
$pages = max(1, (int)ceil($total / $per_page));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per_page;

$logs = $db->fetchAll(
    "SELECT wl.*, o.order_no, o.merchant_order_id, o.notify_url, o.notify_status, o.notify_retries, o.user_id, u.email
     FROM webhook_logs wl
     INNER JOIN orders o ON o.id = wl.order_id
     LEFT JOIN users u ON u.id = o.user_id
     $whereSql
     ORDER BY wl.id DESC
     LIMIT $per_page OFFSET $offset",
    $params
);

$active_menu = 'webhook_logs';
$page_title = 'Webhook 日志管理';

// Load Stripe webhook logs for the second tab
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

$activeTab = $_GET['tab'] ?? 'crypto';

$stripe_page = max(1, (int)($_GET['stripe_page'] ?? 1));
$stripe_limit = 30;
$stripe_filter_status = $_GET['stripe_status'] ?? '';
$stripe_filter_order  = trim($_GET['stripe_order'] ?? '');
$sw = []; $sp = [];
if ($stripe_filter_status !== '') { $sw[] = 'status = ?'; $sp[] = $stripe_filter_status; }
if ($stripe_filter_order  !== '') { $sw[] = 'order_no LIKE ?'; $sp[] = "%$stripe_filter_order%"; }
$stripeWhereSQL = $sw ? ('WHERE ' . implode(' AND ', $sw)) : '';
$stripe_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM stripe_webhook_logs $stripeWhereSQL", $sp)['c'] ?? 0);
$stripe_pages = max(1, (int)ceil($stripe_total / $stripe_limit));
$stripe_page  = min($stripe_page, $stripe_pages);
$stripe_logs  = $db->fetchAll(
    "SELECT * FROM stripe_webhook_logs $stripeWhereSQL ORDER BY id DESC LIMIT $stripe_limit OFFSET " . (($stripe_page - 1) * $stripe_limit),
    $sp
);
$stripe_stats = $db->fetch("SELECT COUNT(*) AS total, SUM(status='success') AS ok, SUM(status='duplicate') AS dup, SUM(status='error') AS err FROM stripe_webhook_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");

require_once 'includes/header.php';
?>

<?php if(isset($_GET['msg'])): ?>
<div class="alert <?php
    $m = $_GET['msg'];
    echo in_array($m, ['retry_ok']) ? 'alert-success' : (in_array($m, ['csrf','notfound','notpaid','no_target','retry_fail']) ? 'alert-danger' : 'alert-info');
?>">
    <?php
    $map = [
        'csrf' => '安全校验失败，请重试。',
        'notfound' => '订单不存在。',
        'notpaid' => '仅已支付订单可重发。',
        'no_target' => '该订单没有可用回调地址。',
        'retry_ok' => 'Webhook 重发成功。',
        'retry_fail' => 'Webhook 重发失败。',
    ];
    echo $map[$m] ?? '操作完成';
    ?>
</div>
<?php endif; ?>

<!-- Tab 切换 -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab !== 'stripe' ? 'active' : ''; ?>" href="?tab=crypto">
            <i class="fas fa-paper-plane me-1"></i>Crypto Webhook 日志
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $activeTab === 'stripe' ? 'active' : ''; ?>" href="?tab=stripe">
            <i class="fab fa-stripe-s me-1"></i>Stripe 通知日志
        </a>
    </li>
</ul>

<?php if ($activeTab !== 'stripe'): ?>
<!-- ========== Crypto Webhook 日志 ========== -->
<form method="GET" class="row g-2 mb-3">
    <input type="hidden" name="tab" value="crypto">
    <div class="col-md-4">
        <input type="text" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control" placeholder="搜索订单号 / 商户订单号 / 邮箱">
    </div>
    <div class="col-md-2">
        <input type="number" name="code" value="<?php echo htmlspecialchars($code); ?>" class="form-control" placeholder="HTTP码">
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary w-100">筛选</button>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>时间</th>
                    <th>用户</th>
                    <th>系统订单号</th>
                    <th>商户订单号</th>
                    <th>回调地址</th>
                    <th>HTTP码</th>
                    <th>响应</th>
                    <th>重试</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($logs)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">暂无数据</td></tr>
            <?php else: ?>
                <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="small text-muted"><?php echo htmlspecialchars($l['created_at']); ?></td>
                        <td class="small"><?php echo htmlspecialchars($l['email'] ?: ('UID#' . $l['user_id'])); ?></td>
                        <td class="font-monospace small"><?php echo htmlspecialchars($l['order_no']); ?></td>
                        <td class="small"><?php echo htmlspecialchars($l['merchant_order_id']); ?></td>
                        <td class="small text-truncate" style="max-width:260px"><?php echo htmlspecialchars((string)$l['notify_url']); ?></td>
                        <td>
                            <span class="badge <?php echo ((int)$l['response_code'] >= 200 && (int)$l['response_code'] < 300) ? 'bg-success' : 'bg-danger'; ?>">
                                <?php echo (int)$l['response_code']; ?>
                            </span>
                        </td>
                        <td class="small text-truncate" style="max-width:280px"><?php echo htmlspecialchars((string)$l['response_body']); ?></td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="order_id" value="<?php echo (int)$l['order_id']; ?>">
                                <button class="btn btn-sm btn-outline-primary">重发</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between align-items-center p-3 border-top bg-white">
        <small class="text-muted">第 <?php echo (int)$page; ?> / <?php echo (int)$pages; ?> 页，共 <?php echo (int)$total; ?> 条</small>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?tab=crypto&q=<?php echo urlencode($q); ?>&code=<?php echo urlencode($code); ?>&page=<?php echo max(1, $page - 1); ?>">上一页</a>
            <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?tab=crypto&q=<?php echo urlencode($q); ?>&code=<?php echo urlencode($code); ?>&page=<?php echo min($pages, $page + 1); ?>">下一页</a>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ========== Stripe 通知日志 ========== -->
<div class="row g-3 mb-4">
    <?php foreach ([
        ['近7天总计', $stripe_stats['total'] ?? 0, 'border-primary text-primary'],
        ['成功处理',  $stripe_stats['ok']    ?? 0, 'border-success text-success'],
        ['重复忽略',  $stripe_stats['dup']   ?? 0, 'border-warning text-warning'],
        ['错误',      $stripe_stats['err']   ?? 0, 'border-danger text-danger'],
    ] as [$label, $val, $cls]): ?>
    <div class="col-6 col-md-3">
        <div class="card border-2 <?php echo $cls; ?> h-100">
            <div class="card-body py-2 px-3">
                <div class="small text-muted"><?php echo $label; ?></div>
                <div class="fs-4 fw-bold"><?php echo (int)$val; ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<form method="GET" class="row g-2 mb-3">
    <input type="hidden" name="tab" value="stripe">
    <div class="col-md-2">
        <select name="stripe_status" class="form-select">
            <option value="">全部状态</option>
            <?php foreach (['received', 'success', 'duplicate', 'ignored', 'error'] as $st): ?>
            <option value="<?php echo $st; ?>" <?php echo $stripe_filter_status === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <input type="text" name="stripe_order" value="<?php echo htmlspecialchars($stripe_filter_order); ?>" class="form-control" placeholder="订单号">
    </div>
    <div class="col-md-2">
        <button class="btn btn-primary w-100">筛选</button>
    </div>
    <?php if ($stripe_filter_status || $stripe_filter_order): ?>
    <div class="col-auto">
        <a href="?tab=stripe" class="btn btn-outline-secondary">清除</a>
    </div>
    <?php endif; ?>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0 table-sm">
            <thead class="table-light">
                <tr>
                    <th>时间</th>
                    <th>Event Type</th>
                    <th>Event ID</th>
                    <th>订单号</th>
                    <th>状态</th>
                    <th>详情</th>
                    <th>来源 IP</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($stripe_logs)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">
                    <?php echo (!$stripe_filter_status && !$stripe_filter_order) ? 'Stripe 向 Webhook 地址发送事件后将在此显示' : '没有匹配的记录'; ?>
                </td></tr>
            <?php else: foreach ($stripe_logs as $log):
                $sCls = match((string)($log['status'] ?? '')) {
                    'success'   => 'badge bg-success',
                    'duplicate' => 'badge bg-warning text-dark',
                    'error'     => 'badge bg-danger',
                    'ignored'   => 'badge bg-secondary',
                    default     => 'badge bg-info text-dark',
                };
            ?>
                <tr>
                    <td class="small text-muted text-nowrap"><?php echo htmlspecialchars((string)($log['created_at'] ?? '')); ?></td>
                    <td><code class="small"><?php echo htmlspecialchars((string)($log['event_type'] ?? '')); ?></code></td>
                    <td class="small text-truncate" style="max-width:150px" title="<?php echo htmlspecialchars((string)($log['event_id'] ?? '')); ?>">
                        <?php echo htmlspecialchars((string)($log['event_id'] ?? '-')); ?>
                    </td>
                    <td>
                        <?php if (!empty($log['order_no'])): ?>
                        <a href="orders.php?search=<?php echo urlencode((string)$log['order_no']); ?>" class="small">
                            <?php echo htmlspecialchars((string)$log['order_no']); ?>
                        </a>
                        <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                    </td>
                    <td><span class="<?php echo $sCls; ?>"><?php echo htmlspecialchars((string)($log['status'] ?? '')); ?></span></td>
                    <td class="small text-truncate text-muted" style="max-width:220px"><?php echo htmlspecialchars((string)($log['detail'] ?? '')); ?></td>
                    <td class="small text-muted"><?php echo htmlspecialchars((string)($log['ip'] ?? '')); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between align-items-center p-3 border-top bg-white">
        <small class="text-muted">第 <?php echo (int)$stripe_page; ?> / <?php echo (int)$stripe_pages; ?> 页，共 <?php echo (int)$stripe_total; ?> 条</small>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary <?php echo $stripe_page <= 1 ? 'disabled' : ''; ?>" href="?tab=stripe&stripe_status=<?php echo urlencode($stripe_filter_status); ?>&stripe_order=<?php echo urlencode($stripe_filter_order); ?>&stripe_page=<?php echo max(1, $stripe_page - 1); ?>">上一页</a>
            <a class="btn btn-sm btn-outline-secondary <?php echo $stripe_page >= $stripe_pages ? 'disabled' : ''; ?>" href="?tab=stripe&stripe_status=<?php echo urlencode($stripe_filter_status); ?>&stripe_order=<?php echo urlencode($stripe_filter_order); ?>&stripe_page=<?php echo min($stripe_pages, $stripe_page + 1); ?>">下一页</a>
        </div>
    </div>
</div>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
