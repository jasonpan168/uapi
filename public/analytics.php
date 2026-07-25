<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';

// Date range handling
$range = $_GET['range'] ?? '7d';
$valid_ranges = ['today', '7d', '30d', 'custom'];
if (!in_array($range, $valid_ranges)) $range = '7d';

$date_from = $_GET['date_from'] ?? '';
$date_to   = $_GET['date_to']   ?? '';
if ($range === 'today') {
    $date_from = date('Y-m-d');
    $date_to   = date('Y-m-d');
} elseif ($range === '7d') {
    $date_from = date('Y-m-d', strtotime('-6 days'));
    $date_to   = date('Y-m-d');
} elseif ($range === '30d') {
    $date_from = date('Y-m-d', strtotime('-29 days'));
    $date_to   = date('Y-m-d');
} else {
    // custom
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = date('Y-m-d', strtotime('-6 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = date('Y-m-d');
}
$ts_from = $date_from . ' 00:00:00';
$ts_to   = $date_to   . ' 23:59:59';

// Summary stats
$summary = $db->fetch(
    "SELECT
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_orders,
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending_orders,
        SUM(CASE WHEN status='expired' OR status='cancelled' THEN 1 ELSE 0 END) AS failed_orders,
        SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) AS total_volume
    FROM orders WHERE user_id = ? AND created_at BETWEEN ? AND ?",
    [$user_id, $ts_from, $ts_to]
);

$success_rate = ($summary['total_orders'] > 0)
    ? round(($summary['paid_orders'] / $summary['total_orders']) * 100, 1)
    : 0;

// Chain breakdown
$chain_stats = $db->fetchAll(
    "SELECT chain, COUNT(*) AS cnt, SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) AS vol
     FROM orders WHERE user_id = ? AND created_at BETWEEN ? AND ? AND status='paid'
     GROUP BY chain ORDER BY vol DESC",
    [$user_id, $ts_from, $ts_to]
);

// Currency breakdown
$currency_stats = $db->fetchAll(
    "SELECT currency, COUNT(*) AS cnt, SUM(amount) AS vol
     FROM orders WHERE user_id = ? AND created_at BETWEEN ? AND ? AND status='paid'
     GROUP BY currency ORDER BY vol DESC",
    [$user_id, $ts_from, $ts_to]
);

// Daily trend
$daily_stats = $db->fetchAll(
    "SELECT DATE(created_at) AS d,
            SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) AS vol,
            SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_cnt,
            COUNT(*) AS total_cnt
     FROM orders WHERE user_id = ? AND created_at BETWEEN ? AND ?
     GROUP BY DATE(created_at) ORDER BY d ASC",
    [$user_id, $ts_from, $ts_to]
);

// Build chart data indexed by date
$daily_map = [];
foreach ($daily_stats as $r) { $daily_map[$r['d']] = $r; }
$chart_labels = [];
$chart_vol    = [];
$chart_paid   = [];
$chart_total  = [];
$cur = strtotime($date_from);
$end = strtotime($date_to);
while ($cur <= $end) {
    $d = date('Y-m-d', $cur);
    $chart_labels[] = date('m-d', $cur);
    $chart_vol[]    = (float)($daily_map[$d]['vol']       ?? 0);
    $chart_paid[]   = (int)  ($daily_map[$d]['paid_cnt']  ?? 0);
    $chart_total[]  = (int)  ($daily_map[$d]['total_cnt'] ?? 0);
    $cur = strtotime('+1 day', $cur);
}
?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.analytics.title'); include __DIR__ . '/includes/user_topbar.php'; ?>

            <!-- Date Range Filter -->
            <form method="GET" class="mb-4">
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <?php foreach ([
                        'today' => __('merchant.analytics.range.today'),
                        '7d' => __('merchant.analytics.range.last_7_days'),
                        '30d' => __('merchant.analytics.range.last_30_days'),
                        'custom' => __('merchant.analytics.range.custom'),
                    ] as $k => $v): ?>
                    <a href="?range=<?php echo $k; ?>" class="btn btn-sm <?php echo $range===$k ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $v; ?></a>
                    <?php endforeach; ?>
                    <?php if ($range === 'custom'): ?>
                    <input type="date" name="date_from" class="form-control form-control-sm" style="width:150px" value="<?php echo htmlspecialchars($date_from); ?>">
                    <span class="text-muted"><?php echo __('merchant.analytics.range.to'); ?></span>
                    <input type="date" name="date_to" class="form-control form-control-sm" style="width:150px" value="<?php echo htmlspecialchars($date_to); ?>">
                    <input type="hidden" name="range" value="custom">
                    <button type="submit" class="btn btn-sm btn-primary"><?php echo __('merchant.analytics.range.search'); ?></button>
                    <?php endif; ?>
                    <span class="text-muted small ms-2"><?php echo htmlspecialchars($date_from); ?> <?php echo __('merchant.analytics.range.to'); ?> <?php echo htmlspecialchars($date_to); ?></span>
                </div>
            </form>

            <!-- Summary Cards -->
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="mole-card text-center">
                        <div class="text-muted small mb-1"><?php echo __('merchant.analytics.summary.total_volume'); ?></div>
                        <div class="fw-bold fs-4">$<?php echo number_format((float)($summary['total_volume'] ?? 0), 2); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="mole-card text-center">
                        <div class="text-muted small mb-1"><?php echo __('merchant.analytics.summary.paid_orders'); ?></div>
                        <div class="fw-bold fs-4 text-success"><?php echo number_format((int)$summary['paid_orders']); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="mole-card text-center">
                        <div class="text-muted small mb-1"><?php echo __('merchant.analytics.summary.total_orders'); ?></div>
                        <div class="fw-bold fs-4"><?php echo number_format((int)$summary['total_orders']); ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="mole-card text-center">
                        <div class="text-muted small mb-1"><?php echo __('merchant.analytics.summary.success_rate'); ?></div>
                        <div class="fw-bold fs-4 <?php echo $success_rate >= 80 ? 'text-success' : ($success_rate >= 50 ? 'text-warning' : 'text-danger'); ?>"><?php echo $success_rate; ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row g-4 mb-4">
                <!-- Trend Chart -->
                <div class="col-lg-8">
                    <div class="mole-card">
                        <h6 class="fw-bold mb-3"><?php echo __('merchant.analytics.chart.trend'); ?></h6>
                        <canvas id="trendChart" style="max-height:280px;"></canvas>
                    </div>
                </div>
                <!-- Chain Breakdown -->
                <div class="col-lg-4">
                    <div class="mole-card">
                        <h6 class="fw-bold mb-3"><?php echo __('merchant.analytics.chart.chain_distribution'); ?></h6>
                        <?php if (empty($chain_stats)): ?>
                            <p class="text-muted text-center py-4"><?php echo __('merchant.analytics.common.no_data'); ?></p>
                        <?php else: ?>
                            <canvas id="chainChart" style="max-height:180px;"></canvas>
                            <div class="mt-3">
                            <?php
                            $chain_colors = ['trc20'=>'#ef4444','bsc'=>'#f59e0b','eth'=>'#3b82f6','polygon'=>'#8b5cf6','base'=>'#0ea5e9','arbitrum'=>'#22d3ee','optimism'=>'#f97316','avalanche'=>'#dc2626'];
                            foreach ($chain_stats as $cs):
                                $vol_pct = ($summary['total_volume'] > 0) ? round(($cs['vol'] / $summary['total_volume']) * 100, 1) : 0;
                                $color = $chain_colors[strtolower($cs['chain'])] ?? '#6b7280';
                            ?>
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="rounded-circle d-inline-block" style="width:10px;height:10px;background:<?php echo $color; ?>;"></span>
                                    <span class="small text-uppercase fw-bold"><?php echo htmlspecialchars($cs['chain']); ?></span>
                                </div>
                                <div class="text-end small">
                                    <span class="fw-bold">$<?php echo number_format((float)$cs['vol'], 2); ?></span>
                                    <span class="text-muted ms-1"><?php echo $vol_pct; ?>%</span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Currency & Status Row -->
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="mole-card">
                        <h6 class="fw-bold mb-3"><?php echo __('merchant.analytics.chart.currency_distribution'); ?></h6>
                        <?php if (empty($currency_stats)): ?>
                            <p class="text-muted text-center py-3"><?php echo __('merchant.analytics.common.no_data'); ?></p>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th><?php echo __('merchant.analytics.table.currency'); ?></th><th><?php echo __('merchant.analytics.table.order_count'); ?></th><th><?php echo __('merchant.analytics.table.volume'); ?></th></tr></thead>
                                <tbody>
                                <?php foreach ($currency_stats as $cs): ?>
                                <tr>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($cs['currency']); ?></span></td>
                                    <td><?php echo number_format((int)$cs['cnt']); ?></td>
                                    <td class="fw-bold">$<?php echo number_format((float)$cs['vol'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mole-card">
                        <h6 class="fw-bold mb-3"><?php echo __('merchant.analytics.chart.status_distribution'); ?></h6>
                        <div class="d-flex flex-column gap-3">
                            <?php
                            $statuses = [
                                ['label'=>__('merchant.analytics.status.paid'),'val'=>(int)$summary['paid_orders'],'color'=>'success'],
                                ['label'=>__('merchant.analytics.status.pending'),'val'=>(int)$summary['pending_orders'],'color'=>'warning'],
                                ['label'=>__('merchant.analytics.status.expired_or_cancelled'),'val'=>(int)$summary['failed_orders'],'color'=>'danger'],
                            ];
                            $total = max(1, (int)$summary['total_orders']);
                            foreach ($statuses as $st):
                                $pct = round(($st['val'] / $total) * 100, 1);
                            ?>
                            <div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="small"><?php echo $st['label']; ?></span>
                                    <span class="small fw-bold"><?php echo $st['val']; ?> (<?php echo $pct; ?>%)</span>
                                </div>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar bg-<?php echo $st['color']; ?>" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Trend Chart
(function() {
    const ctx = document.getElementById('trendChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: <?php echo json_encode(__('merchant.analytics.chart.volume_label')); ?>,
                data: <?php echo json_encode($chart_vol); ?>,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59,130,246,0.08)',
                tension: 0.3,
                fill: true,
                yAxisID: 'y'
            }, {
                label: <?php echo json_encode(__('merchant.analytics.chart.paid_orders_label')); ?>,
                data: <?php echo json_encode($chart_paid); ?>,
                borderColor: '#22c55e',
                backgroundColor: 'transparent',
                tension: 0.3,
                fill: false,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            scales: {
                y: { position: 'left', beginAtZero: true, title: { display: true, text: <?php echo json_encode(__('merchant.analytics.chart.amount_usd')); ?> } },
                y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: <?php echo json_encode(__('merchant.analytics.chart.orders_count')); ?> } }
            }
        }
    });
})();

// Chain Chart
(function() {
    const ctx = document.getElementById('chainChart');
    if (!ctx) return;
    const labels = <?php echo json_encode(array_column($chain_stats, 'chain')); ?>;
    const data   = <?php echo json_encode(array_column($chain_stats, 'vol')); ?>;
    const colorMap = {trc20:'#ef4444',bsc:'#f59e0b',eth:'#3b82f6',polygon:'#8b5cf6',base:'#0ea5e9',arbitrum:'#22d3ee',optimism:'#f97316',avalanche:'#dc2626'};
    const colors = labels.map(l => colorMap[l.toLowerCase()] || '#6b7280');
    new Chart(ctx, {
        type: 'doughnut',
        data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2 }] },
        options: { responsive: true, plugins: { legend: { display: false } } }
    });
})();
</script>
</body>
</html>
