<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Services/CryptoService.php';
$db = Database::getInstance();
$db->autoMigrate();
CryptoService::ensureApiRequestSchema();

// 1. Ensure table structure
$db->query("CREATE TABLE IF NOT EXISTS api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    endpoint VARCHAR(100),
    method VARCHAR(20),
    chain VARCHAR(20) NULL,
    status_code INT DEFAULT 200,
    ip_address VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_user (user_id),
    INDEX idx_method (method),
    INDEX idx_endpoint (endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("UPDATE api_logs SET status_code = 200 WHERE status_code = 0 AND method != 'EXTERNAL'");

$endpoint_label = static function (string $endpoint): string {
    if ($endpoint === '/v1/order/create') return '创建订单 API';
    if ($endpoint === 'status.php') return '支付状态轮询';
    if ($endpoint === 'monitor.sync') return '链上监控查询';
    if ($endpoint === 'status.poll') return '订单轮询请求';
    if ($endpoint === 'check_hash.manual') return '手动哈希校验';
    if ($endpoint === 'heartbeat.ping') return '支付页心跳';
    if (strpos($endpoint, '/v1/user/') === 0) return '用户接口';
    if (strpos($endpoint, '/v1/order/') === 0) return '订单接口';
    if (strpos($endpoint, 'status.order.') === 0) return '订单轮询请求';
    if (strpos($endpoint, 'check_hash.order.') === 0) return '手动哈希校验';
    return $endpoint !== '' ? $endpoint : '未知接口';
};

$db->query("CREATE TABLE IF NOT EXISTS notification_send_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    channel VARCHAR(20) NOT NULL,
    notice_type VARCHAR(50) DEFAULT 'system',
    status VARCHAR(20) DEFAULT 'success',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created_at (created_at),
    INDEX idx_user (user_id),
    INDEX idx_channel (channel),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2. Summary stats
$total_calls = (int)($db->fetch("SELECT COUNT(*) as c FROM api_logs WHERE method NOT IN ('EXTERNAL', 'MONITOR', 'POLL')")['c'] ?? 0);
$success_calls = (int)($db->fetch("SELECT COUNT(*) as c FROM api_logs WHERE status_code >= 200 AND status_code < 300 AND method NOT IN ('EXTERNAL', 'MONITOR', 'POLL')")['c'] ?? 0);
$failed_calls = max(0, $total_calls - $success_calls);
$success_rate = $total_calls > 0 ? round(($success_calls / $total_calls) * 100, 2) : 0;

$external_total = CryptoService::getSystemBillableRequestCount();
$external_today = CryptoService::getSystemBillableRequestCount(date('Y-m-d'));
$external_today_ok = $external_today;
$external_today_fail = (int)($db->fetch(
    "SELECT COUNT(*) as c
     FROM external_request_logs
     WHERE status_code NOT BETWEEN 200 AND 299
       AND created_at >= CURDATE()"
)['c'] ?? 0);

$tg_total = (int)($db->fetch("SELECT COUNT(*) as c FROM notification_send_logs WHERE channel='tg' AND status='success'")['c'] ?? 0);
$tg_today = (int)($db->fetch("SELECT COUNT(*) as c FROM notification_send_logs WHERE channel='tg' AND status='success' AND created_at >= CURDATE()")['c'] ?? 0);
$email_total = (int)($db->fetch("SELECT COUNT(*) as c FROM notification_send_logs WHERE channel='email' AND status='success'")['c'] ?? 0);
$email_today = (int)($db->fetch("SELECT COUNT(*) as c FROM notification_send_logs WHERE channel='email' AND status='success' AND created_at >= CURDATE()")['c'] ?? 0);

// 3. Last 30 days trend
$api_daily_rows = $db->fetchAll("SELECT DATE(created_at) as d,
    SUM(CASE WHEN method NOT IN ('EXTERNAL', 'MONITOR', 'POLL') THEN 1 ELSE 0 END) as internal_calls
    FROM api_logs
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC");

$external_daily_rows = [];
for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $external_daily_rows[] = [
        'd' => $day,
        'external_calls' => CryptoService::getSystemBillableRequestCount($day),
    ];
}

$notice_daily_rows = $db->fetchAll("SELECT DATE(created_at) as d,
    SUM(CASE WHEN channel='tg' AND status='success' THEN 1 ELSE 0 END) as tg_calls,
    SUM(CASE WHEN channel='email' AND status='success' THEN 1 ELSE 0 END) as email_calls
    FROM notification_send_logs
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC");

$api_daily_map = [];
foreach ($api_daily_rows as $r) {
    $api_daily_map[$r['d']] = [
        'internal' => (int)$r['internal_calls'],
        'external' => 0,
    ];
}
foreach ($external_daily_rows as $r) {
    if (!isset($api_daily_map[$r['d']])) {
        $api_daily_map[$r['d']] = [
            'internal' => 0,
            'external' => 0,
        ];
    }
    $api_daily_map[$r['d']]['external'] = (int)$r['external_calls'];
}
$notice_daily_map = [];
foreach ($notice_daily_rows as $r) {
    $notice_daily_map[$r['d']] = [
        'tg' => (int)$r['tg_calls'],
        'email' => (int)$r['email_calls'],
    ];
}

$dates = [];
$chart_internal = [];
$chart_external = [];
$chart_tg = [];
$chart_email = [];

for ($i = 29; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $dates[] = $day;
    $chart_internal[] = $api_daily_map[$day]['internal'] ?? 0;
    $chart_external[] = $api_daily_map[$day]['external'] ?? 0;
    $chart_tg[] = $notice_daily_map[$day]['tg'] ?? 0;
    $chart_email[] = $notice_daily_map[$day]['email'] ?? 0;
}

// Chart smoothing/cap: avoid huge spikes making page hard to read.
$all_points = array_merge($chart_internal, $chart_external, $chart_tg, $chart_email);
$all_points = array_values(array_filter($all_points, static function ($v) { return (int)$v > 0; }));
$chart_cap = 0;
$chart_capped = false;
if (!empty($all_points)) {
    sort($all_points);
    $idx = (int)floor((count($all_points) - 1) * 0.95);
    $p95 = (int)$all_points[$idx];
    $chart_cap = max(50, (int)ceil($p95 * 1.2));
    $peak = (int)max($all_points);
    if ($peak > $chart_cap) {
        $chart_capped = true;
        $capFn = static function ($v) use ($chart_cap) {
            return (int)min((int)$v, $chart_cap);
        };
        $chart_internal = array_map($capFn, $chart_internal);
        $chart_external = array_map($capFn, $chart_external);
        $chart_tg = array_map($capFn, $chart_tg);
        $chart_email = array_map($capFn, $chart_email);
    }
}

// 4. Chain distribution
$chain_stats = $db->fetchAll("SELECT chain, SUM(c) as c
    FROM (
        SELECT chain, COUNT(*) AS c
        FROM external_request_logs
        WHERE status_code BETWEEN 200 AND 299 AND chain IS NOT NULL AND chain != ''
        GROUP BY chain
    ) chain_union
    GROUP BY chain
    ORDER BY c DESC");

$chain_labels = [];
$chain_data = [];
$chain_total = 0;
foreach ($chain_stats as $s) {
    $label = strtoupper((string)$s['chain']);
    $count = (int)$s['c'];
    $chain_labels[] = $label;
    $chain_data[] = $count;
    $chain_total += $count;
}

$chain_palette = [
    '#3b82f6', '#f59e0b', '#10b981', '#8b5cf6', '#ef4444',
    '#06b6d4', '#f97316', '#84cc16', '#6366f1', '#14b8a6'
];
$chain_colors = [];
for ($i = 0; $i < count($chain_data); $i++) {
    $chain_colors[] = $chain_palette[$i % count($chain_palette)];
}

$chain_rows = [];
foreach ($chain_labels as $idx => $name) {
    $count = (int)$chain_data[$idx];
    $pct = $chain_total > 0 ? round($count * 100 / $chain_total, 2) : 0;
    $chain_rows[] = [
        'name' => $name,
        'count' => $count,
        'pct' => $pct,
        'color' => $chain_colors[$idx],
    ];
}

// 5. Merchant ranking + endpoint details
$top_users = $db->fetchAll("SELECT
        l.user_id,
        u.email,
        COUNT(l.id) as total,
        SUM(CASE WHEN l.created_at >= CURDATE() THEN 1 ELSE 0 END) as today_total,
        SUM(CASE WHEN l.status_code >= 200 AND l.status_code < 300 THEN 1 ELSE 0 END) as success,
        SUM(CASE WHEN l.status_code >= 400 THEN 1 ELSE 0 END) as fail
    FROM api_logs l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.user_id IS NOT NULL AND l.method NOT IN ('EXTERNAL', 'MONITOR', 'POLL')
    GROUP BY l.user_id, u.email
    ORDER BY total DESC
    LIMIT 10");

$user_ids = array_values(array_filter(array_map(static function ($u) {
    return (int)($u['user_id'] ?? 0);
}, $top_users)));

$endpoint_map = [];
$notice_map = [];
if (!empty($user_ids)) {
    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $endpoint_rows = $db->fetchAll(
        "SELECT
            user_id,
            CASE
                WHEN endpoint LIKE 'status.order.%' THEN 'status.poll'
                WHEN endpoint LIKE 'check_hash.order.%' THEN 'check_hash.manual'
                WHEN endpoint = 'status.php' THEN 'status.php'
                WHEN endpoint = 'monitor.sync' THEN 'monitor.sync'
                WHEN endpoint = '/v1/order/create' THEN '/v1/order/create'
                ELSE endpoint
            END AS endpoint,
            COUNT(*) as total,
            SUM(CASE WHEN created_at >= CURDATE() THEN 1 ELSE 0 END) as today_count
         FROM api_logs
         WHERE method NOT IN ('EXTERNAL', 'MONITOR', 'POLL') AND user_id IN ($placeholders)
         GROUP BY user_id, endpoint
         ORDER BY user_id ASC, total DESC",
        $user_ids
    );

    foreach ($endpoint_rows as $row) {
        $uid = (int)$row['user_id'];
        if (!isset($endpoint_map[$uid])) {
            $endpoint_map[$uid] = [];
        }
        $endpoint_map[$uid][] = [
            'endpoint' => (string)$row['endpoint'],
            'total' => (int)$row['total'],
            'today' => (int)$row['today_count'],
        ];
    }

    $notice_rows = $db->fetchAll(
        "SELECT
            user_id,
            channel,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as total_success,
            SUM(CASE WHEN status = 'success' AND created_at >= CURDATE() THEN 1 ELSE 0 END) as today_success
         FROM notification_send_logs
         WHERE user_id IN ($placeholders)
         GROUP BY user_id, channel",
        $user_ids
    );
    foreach ($notice_rows as $row) {
        $uid = (int)$row['user_id'];
        if (!isset($notice_map[$uid])) {
            $notice_map[$uid] = [
                'tg' => ['total' => 0, 'today' => 0],
                'email' => ['total' => 0, 'today' => 0],
            ];
        }
        $ch = (string)$row['channel'];
        if ($ch !== 'tg' && $ch !== 'email') {
            continue;
        }
        $notice_map[$uid][$ch] = [
            'total' => (int)$row['total_success'],
            'today' => (int)$row['today_success'],
        ];
    }
}

$active_menu = 'api_stats';
require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div class="small text-muted">
        <i class="far fa-clock me-1"></i>统计窗口：最近 30 天
    </div>
    <div class="small text-muted">
        外部 API 今日统计：成功 <?php echo number_format($external_today_ok); ?> / 失败 <?php echo number_format($external_today_fail); ?>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold">总 API 调用</h6>
                <h2 class="mb-1 text-primary"><?php echo number_format($total_calls); ?></h2>
                <div class="small text-muted">成功率 <?php echo $success_rate; ?>%</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100 border-start border-4 border-warning">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold">外部 API 消耗</h6>
                <h2 class="mb-1 text-warning"><?php echo number_format($external_total); ?></h2>
                <div class="small text-muted">今日 <?php echo number_format($external_today); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold">TG 发送次数</h6>
                <h2 class="mb-1 text-info"><?php echo number_format($tg_total); ?></h2>
                <div class="small text-muted">今日 <?php echo number_format($tg_today); ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small fw-bold">邮件发送次数</h6>
                <h2 class="mb-1 text-success"><?php echo number_format($email_total); ?></h2>
                <div class="small text-muted">今日 <?php echo number_format($email_today); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-chart-line me-2"></i>调用趋势（内部 / 外部 / TG / 邮件）
            </div>
            <div class="card-body">
                <?php if ($chart_capped): ?>
                    <div class="small text-muted mb-2">
                        图表已做峰值封顶展示（上限 <?php echo number_format($chart_cap); ?>），用于提升可读性；精确数据请以下方统计表为准。
                    </div>
                <?php endif; ?>
                <div style="height:320px;">
                    <canvas id="apiTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold">
                <i class="fas fa-chart-pie me-2"></i>网络使用分布（占比）
            </div>
            <div class="card-body">
                <div style="height:240px;">
                    <canvas id="chainDistChart"></canvas>
                </div>
                <?php if (!empty($chain_rows)): ?>
                    <div class="mt-3 small">
                        <?php foreach ($chain_rows as $r): ?>
                            <div class="d-flex justify-content-between align-items-center py-1">
                                <div class="d-flex align-items-center gap-2">
                                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo htmlspecialchars($r['color']); ?>;"></span>
                                    <span><?php echo htmlspecialchars($r['name']); ?></span>
                                </div>
                                <div class="text-muted"><?php echo number_format($r['count']); ?> (<?php echo $r['pct']; ?>%)</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-center text-muted mt-4">暂无链路数据</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold">
        <i class="fas fa-trophy me-2 text-warning"></i>商户 API 调用排行（含接口明细）
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="bg-light">
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>商户邮箱</th>
                        <th>总调用</th>
                        <th>今日调用</th>
                        <th>成功</th>
                        <th>失败</th>
                        <th>接口明细（总/今日）</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($top_users)): ?>
                    <tr><td colspan="7" class="text-center py-4 text-muted">暂无数据</td></tr>
                <?php else: ?>
                    <?php $rank = 1; foreach ($top_users as $u):
                        $uid = (int)$u['user_id'];
                        $details = $endpoint_map[$uid] ?? [];
                        $notice = $notice_map[$uid] ?? [
                            'tg' => ['total' => 0, 'today' => 0],
                            'email' => ['total' => 0, 'today' => 0],
                        ];
                    ?>
                    <tr>
                        <td><?php echo $rank++; ?></td>
                        <td><span class="fw-bold"><?php echo htmlspecialchars((string)($u['email'] ?: ('UID-' . $uid))); ?></span></td>
                        <td><?php echo number_format((int)$u['total']); ?></td>
                        <td><?php echo number_format((int)$u['today_total']); ?></td>
                        <td class="text-success"><?php echo number_format((int)$u['success']); ?></td>
                        <td class="text-danger"><?php echo number_format((int)$u['fail']); ?></td>
                        <td>
                            <?php if (empty($details)): ?>
                                <span class="text-muted small">暂无</span>
                            <?php else: ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach (array_slice($details, 0, 6) as $d): ?>
                                        <span class="badge text-bg-light border">
                                            <?php echo htmlspecialchars($endpoint_label((string)$d['endpoint'])); ?>: <?php echo (int)$d['total']; ?>/<?php echo (int)$d['today']; ?>
                                        </span>
                                    <?php endforeach; ?>
                                    <span class="badge text-bg-info border border-info-subtle">
                                        TG通知: <?php echo (int)$notice['tg']['total']; ?>/<?php echo (int)$notice['tg']['today']; ?>
                                    </span>
                                    <span class="badge text-bg-success border border-success-subtle">
                                        邮箱通知: <?php echo (int)$notice['email']['total']; ?>/<?php echo (int)$notice['email']['today']; ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctxLine = document.getElementById('apiTrendChart').getContext('2d');
new Chart(ctxLine, {
    type: 'line',
    data: {
        labels: <?php echo json_encode($dates); ?>,
        datasets: [
            {
                label: '内部 API',
                data: <?php echo json_encode($chart_internal); ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,0.08)',
                tension: 0.35,
                fill: false,
                borderWidth: 2
            },
            {
                label: '外部 API',
                data: <?php echo json_encode($chart_external); ?>,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245,158,11,0.08)',
                tension: 0.35,
                fill: false,
                borderWidth: 2
            },
            {
                label: 'TG 发送',
                data: <?php echo json_encode($chart_tg); ?>,
                borderColor: '#06b6d4',
                backgroundColor: 'rgba(6,182,212,0.08)',
                tension: 0.35,
                fill: false,
                borderWidth: 2
            },
            {
                label: '邮件发送',
                data: <?php echo json_encode($chart_email); ?>,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16,185,129,0.08)',
                tension: 0.35,
                fill: false,
                borderWidth: 2
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'top' },
            tooltip: { mode: 'index', intersect: false }
        },
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 },
                suggestedMax: <?php echo $chart_cap > 0 ? (int)$chart_cap : 0; ?>
            }
        }
    }
});

<?php if (!empty($chain_labels)): ?>
const ctxPie = document.getElementById('chainDistChart').getContext('2d');
new Chart(ctxPie, {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($chain_labels); ?>,
        datasets: [{
            data: <?php echo json_encode($chain_data); ?>,
            backgroundColor: <?php echo json_encode($chain_colors); ?>,
            borderWidth: 1,
            borderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        cutout: '58%'
    }
});
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
