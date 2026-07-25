<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
$db = Database::getInstance();
require_once __DIR__ . '/../../src/Services/SecurityService.php';

$sec = new SecurityService($db);

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        http_response_code(403);
        $msg = "Request rejected (invalid CSRF token).";
    } elseif (isset($_POST['action']) && $_POST['action'] === 'ban') {
        $ip = trim($_POST['ip']);
        $reason = trim($_POST['reason']);
        $duration = (int)$_POST['duration']; // Minutes
        if ($ip) {
            $sec->banIp($ip, $reason ?: 'Manual Ban', $duration);
            $sec->clearSessions($ip); // Kill active sessions
            $msg = "IP $ip has been blocked.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'unban') {
        $ip = $_POST['ip'];
        $sec->unbanIp($ip);
        $msg = "IP $ip has been unblocked.";
    } elseif (isset($_POST['action']) && $_POST['action'] === 'clear_sessions') {
        $db->query("DELETE FROM active_sessions"); // Reset all
        $msg = "All active sessions cleared.";
    }
}

// Fetch Data
// Filter out old sessions just in case
$sessions = $db->fetchAll("SELECT * FROM active_sessions WHERE status='active' AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 2 MINUTE) ORDER BY last_heartbeat DESC LIMIT 100");
$blocked = $db->fetchAll("SELECT * FROM blocked_ips ORDER BY blocked_at DESC");

// Fetch cron heartbeats
$cron_jobs = ['monitor' => 'monitor.php（订单监控）', 'cleanup' => 'cleanup.php（数据清理）'];
$cron_hbs = $db->fetchAll("SELECT * FROM cron_heartbeats WHERE job_name IN ('monitor', 'cleanup')");
$cron_status = [];
foreach ($cron_hbs as $hb) {
    $cron_status[$hb['job_name']] = $hb;
}

$active_menu = 'monitor';
require_once 'includes/header.php';
?>

<!-- Cron Heartbeat Status -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-bold d-flex align-items-center gap-2">
        <i class="fas fa-clock text-primary"></i> Cron 任务运行状态
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">任务</th>
                    <th>状态</th>
                    <th>最后运行时间</th>
                    <th>运行次数</th>
                    <th>最后消息</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cron_jobs as $job_key => $job_label): ?>
                <?php
                $hb = $cron_status[$job_key] ?? null;
                $age = $hb ? (time() - strtotime($hb['last_run_at'])) : null;
                if (!$hb) {
                    $badge = '<span class="badge bg-secondary">从未运行</span>';
                } elseif ($age < 300) { // < 5 min
                    $badge = '<span class="badge bg-success">正常</span>';
                } elseif ($age < 7200) { // < 2h
                    $badge = '<span class="badge bg-warning text-dark">稍久未运行</span>';
                } else {
                    $badge = '<span class="badge bg-danger">异常（超过2小时）</span>';
                }
                ?>
                <tr>
                    <td class="ps-4 fw-bold"><?php echo htmlspecialchars($job_label); ?></td>
                    <td><?php echo $badge; ?></td>
                    <td class="small text-muted"><?php echo $hb ? htmlspecialchars($hb['last_run_at']) . ' (' . round(($age ?? 0)/60) . ' 分钟前)' : '-'; ?></td>
                    <td><?php echo $hb ? number_format((int)$hb['run_count']) : '-'; ?></td>
                    <td class="small text-muted"><?php echo $hb ? htmlspecialchars($hb['last_message'] ?? '') : '-'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex justify-content-end align-items-center mb-4">
    <div>
        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#banModal"><i class="fas fa-ban me-2"></i>封禁 IP</button>
        <form method="POST" class="d-inline" onsubmit="return confirm('确定要清除所有活跃会话吗？这将导致所有当前支付页面断开连接。');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
            <input type="hidden" name="action" value="clear_sessions">
            <button class="btn btn-outline-danger btn-sm"><i class="fas fa-broom me-2"></i>清除会话</button>
        </form>
    </div>
</div>

<?php if(isset($msg)): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?php echo htmlspecialchars($msg); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Active Sessions -->
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span><i class="fas fa-users me-2"></i>活跃支付页面监控 (<?php echo count($sessions); ?>)</span>
                <span class="badge bg-success">Live</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle table-sm">
                        <thead class="bg-light">
                            <tr>
                                <th>IP 地址</th>
                                <th>当前订单</th>
                                <th>浏览器/设备</th>
                                <th>最后活跃</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($sessions as $s): ?>
                            <tr>
                                <td>
                                    <span class="font-monospace fw-bold text-primary"><?php echo $s['ip_address']; ?></span>
                                    <?php if($s['user_id']): ?>
                                        <br><small class="text-muted">User: <?php echo $s['user_id']; ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="/pay.php?order=<?php echo $s['order_no']; ?>" target="_blank" class="text-decoration-none">
                                        <?php echo $s['order_no']; ?>
                                    </a>
                                </td>
                                <td>
                                    <small class="text-muted" title="<?php echo htmlspecialchars($s['user_agent']); ?>">
                                        <?php 
                                        $ua = $s['user_agent'];
                                        if (strpos($ua, 'Mobile') !== false) echo '<i class="fas fa-mobile-alt me-1"></i>Mobile';
                                        elseif (strpos($ua, 'Windows') !== false) echo '<i class="fab fa-windows me-1"></i>Windows';
                                        elseif (strpos($ua, 'Mac') !== false) echo '<i class="fab fa-apple me-1"></i>Mac';
                                        elseif (strpos($ua, 'Linux') !== false) echo '<i class="fab fa-linux me-1"></i>Linux';
                                        else echo 'Unknown';
                                        
                                        if (strpos($ua, 'Chrome') !== false) echo ' Chrome';
                                        elseif (strpos($ua, 'Firefox') !== false) echo ' Firefox';
                                        elseif (strpos($ua, 'Safari') !== false) echo ' Safari';
                                        ?>
                                    </small>
                                </td>
                                <td>
                                    <?php 
                                    $diff = time() - strtotime($s['last_heartbeat']);
                                    if ($diff < 10) echo '<span class="badge bg-success">Online</span>';
                                    elseif ($diff < 30) echo '<span class="badge bg-warning text-dark">Idle</span>';
                                    else echo '<span class="badge bg-secondary">Offline</span>';
                                    ?>
                                    <br><small class="text-muted"><?php echo $diff; ?>s ago</small>
                                </td>
                                <td>
                                    <button class="btn btn-xs btn-outline-danger" onclick="banIp('<?php echo $s['ip_address']; ?>')">Ban</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($sessions)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted">暂无活跃会话</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Blocked IPs -->
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-bold border-danger text-danger">
                <i class="fas fa-ban me-2"></i>已封禁 IP (<?php echo count($blocked); ?>)
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach($blocked as $b): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="fw-bold font-monospace"><?php echo $b['ip_address']; ?></span>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                                <input type="hidden" name="action" value="unban">
                                <input type="hidden" name="ip" value="<?php echo $b['ip_address']; ?>">
                                <button class="btn btn-xs btn-outline-secondary">解封</button>
                            </form>
                        </div>
                        <div class="small text-muted">
                            原因: <?php echo htmlspecialchars($b['reason']); ?>
                            <br>过期: <?php echo $b['expires_at'] ?? '永久'; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                    <?php if(empty($blocked)): ?>
                    <li class="list-group-item text-center text-muted py-3">暂无封禁记录</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Ban Modal -->
<div class="modal fade" id="banModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
            <input type="hidden" name="action" value="ban">
            <div class="modal-header">
                <h5 class="modal-title">封禁 IP 地址</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">IP 地址</label>
                    <input type="text" name="ip" id="banIpInput" class="form-control" required placeholder="x.x.x.x">
                </div>
                <div class="mb-3">
                    <label class="form-label">封禁时长 (分钟)</label>
                    <select name="duration" class="form-select">
                        <option value="60">1 小时</option>
                        <option value="1440">24 小时</option>
                        <option value="10080">7 天</option>
                        <option value="525600">永久</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">原因</label>
                    <input type="text" name="reason" class="form-control" placeholder="例如：恶意刷接口">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <button type="submit" class="btn btn-danger">执行封禁</button>
            </div>
        </form>
    </div>
</div>

<script>
function banIp(ip) {
    document.getElementById('banIpInput').value = ip;
    new bootstrap.Modal(document.getElementById('banModal')).show();
}
// Auto refresh every 10s to show live stats
setTimeout(() => location.reload(), 10000);
</script>

<?php require_once 'includes/footer.php'; ?>
