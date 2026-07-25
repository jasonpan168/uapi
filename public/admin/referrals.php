<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();

require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Services/ReferralService.php';

$db = Database::getInstance();
ReferralService::ensureSchema($db);

$active_menu = 'referrals';
$message = '';
$views = ['settings', 'pending', 'earnings', 'relations', 'withdrawals'];
$view = trim((string)($_GET['view'] ?? 'pending'));
if (!in_array($view, $views, true)) {
    $view = 'pending';
}

// Update Settings Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        $message = "请求已被拒绝（CSRF 校验失败）。";
    } elseif (isset($_POST['referral_rate'])) {
        $rate = floatval($_POST['referral_rate']);
        $db->query("INSERT INTO system_settings (key_name, value) VALUES ('referral_rate', ?) ON DUPLICATE KEY UPDATE value = ?", [$rate, $rate]);
        $message = "返利比例已更新";
    } elseif (isset($_POST['action']) && $_POST['action'] === 'set_user_rate') {
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);
        $rate = trim((string)($_POST['user_referral_rate'] ?? ''));
        if ($targetUserId <= 0) {
            $message = "请先选择有效商户";
        } else {
            if ($rate === '') {
                $db->query("UPDATE users SET referral_rate_override = NULL WHERE id = ?", [$targetUserId]);
                $message = "商户独立返利比例已清除（回退为全局比例）";
            } else {
                $rateVal = max(0, min(100, (float)$rate));
                $db->query("UPDATE users SET referral_rate_override = ? WHERE id = ?", [$rateVal, $targetUserId]);
                $message = "商户独立返利比例已更新";
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'review_withdrawal') {
        $withdrawalId = (int)($_POST['withdrawal_id'] ?? 0);
        $decision = trim((string)($_POST['decision'] ?? ''));
        $note = trim((string)($_POST['review_note'] ?? ''));
        $txRef = trim((string)($_POST['tx_ref'] ?? ''));
        $adminId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
        try {
            if (!in_array($decision, ['approve', 'reject', 'mark_paid', 'approve_pay_binance'], true)) {
                throw new Exception('审核动作无效');
            }
            ReferralService::reviewWithdrawal($db, $withdrawalId, $adminId, $decision, $note, $txRef);
            $message = '提现审核已处理';
        } catch (Throwable $e) {
            $message = '审核失败：' . $e->getMessage();
        }
    }
}

// Get Current Rate
$rate_row = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'referral_rate'");
$current_rate = $rate_row ? $rate_row['value'] : 10;

// Stats
$stats = $db->fetch("SELECT 
    COUNT(DISTINCT user_id) as total_referrers,
    COUNT(DISTINCT source_order_id) as total_orders,
    SUM(amount) as total_payout
    FROM referral_earnings");

// Pagination
$earnings_page = max(1, (int)($_GET['earnings_page'] ?? 1));
$relations_page = max(1, (int)($_GET['relations_page'] ?? 1));
$earnings_per_page = 10;
$relations_per_page = 10;
$wd_pending_page = max(1, (int)($_GET['wd_pending_page'] ?? 1));
$wd_recent_page = max(1, (int)($_GET['wd_recent_page'] ?? 1));
$wd_per_page = 10;

$earnings_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM referral_earnings")['c'] ?? 0);
$relations_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM users WHERE ref_by IS NOT NULL")['c'] ?? 0);
$earnings_total_pages = max(1, (int)ceil($earnings_total / $earnings_per_page));
$relations_total_pages = max(1, (int)ceil($relations_total / $relations_per_page));
$earnings_page = min($earnings_page, $earnings_total_pages);
$relations_page = min($relations_page, $relations_total_pages);
$earnings_offset = ($earnings_page - 1) * $earnings_per_page;
$relations_offset = ($relations_page - 1) * $relations_per_page;

// Recent Earnings
$earnings = $db->fetchAll("SELECT e.*, u.email as referrer_email FROM referral_earnings e JOIN users u ON e.user_id = u.id ORDER BY e.created_at DESC LIMIT $earnings_per_page OFFSET $earnings_offset");

// All Referral Relationships
$referral_list = $db->fetchAll("
    SELECT 
        r.id as invitee_id, 
        r.email as invitee_email, 
        r.created_at as invite_time,
        u.id as referrer_id,
        u.email as referrer_email,
        u.referral_rate_override as referrer_rate_override,
        (SELECT COUNT(*) FROM orders WHERE user_id = r.id AND status = 'paid') as order_count,
        (SELECT SUM(e2.amount) FROM referral_earnings e2 WHERE e2.source_order_id IN (SELECT o2.id FROM orders o2 WHERE o2.user_id = r.id)) as total_generated_earnings
    FROM users r 
    JOIN users u ON r.ref_by = u.id 
    ORDER BY r.created_at DESC 
    LIMIT $relations_per_page OFFSET $relations_offset
");

// Top Referrers
$top_referrers = $db->fetchAll("SELECT u.email, COUNT(r.id) as invite_count, 
    (SELECT SUM(amount) FROM referral_earnings WHERE user_id = u.id) as total_earnings
    FROM users u 
    JOIN users r ON r.ref_by = u.id 
    GROUP BY u.id 
    ORDER BY total_earnings DESC LIMIT 10");

$pending_total = (int)($db->fetch("SELECT COUNT(*) c FROM referral_withdrawals WHERE status IN ('pending','approved')")['c'] ?? 0);
$pending_total_pages = max(1, (int)ceil($pending_total / $wd_per_page));
$wd_pending_page = min($wd_pending_page, $pending_total_pages);
$pending_offset = ($wd_pending_page - 1) * $wd_per_page;
$withdrawals_pending = $db->fetchAll(
    "SELECT w.*, u.email
     FROM referral_withdrawals w
     JOIN users u ON u.id = w.user_id
     WHERE w.status IN ('pending','approved')
     ORDER BY w.id DESC
     LIMIT $wd_per_page OFFSET $pending_offset"
);
$recent_total = (int)($db->fetch("SELECT COUNT(*) c FROM referral_withdrawals")['c'] ?? 0);
$recent_total_pages = max(1, (int)ceil($recent_total / $wd_per_page));
$wd_recent_page = min($wd_recent_page, $recent_total_pages);
$recent_offset = ($wd_recent_page - 1) * $wd_per_page;
$withdrawals_recent = $db->fetchAll(
    "SELECT w.*, u.email, a.email AS admin_email
     FROM referral_withdrawals w
     JOIN users u ON u.id = w.user_id
     LEFT JOIN users a ON a.id = w.reviewed_by
     ORDER BY w.id DESC
     LIMIT $wd_per_page OFFSET $recent_offset"
);

function admin_ref_page_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}

require_once 'includes/header.php';
?>

<?php if(isset($message)): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<style>
    .clip-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
    .clip-tabs .clip-btn { border:1px solid #dbe3ee; background:#fff; color:#334155; border-radius:999px; padding:8px 14px; text-decoration:none; font-weight:600; cursor:pointer; }
    .clip-tabs .clip-btn.active { background:#0ea5e9; border-color:#0ea5e9; color:#fff; }
</style>

<!-- Stats -->
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center py-4">
            <h3 class="text-primary mb-1"><?php echo number_format($stats['total_referrers']); ?></h3>
            <div class="text-muted">参与商户数</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center py-4">
            <h3 class="text-success mb-1">$<?php echo number_format($stats['total_payout'] ?? 0, 2); ?></h3>
            <div class="text-muted">累计发放奖励</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center py-4">
            <h3 class="text-info mb-1"><?php echo number_format($stats['total_orders']); ?></h3>
            <div class="text-muted">贡献订单数</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm text-center py-4">
            <h3 class="text-warning mb-1"><?php echo number_format($pending_total); ?></h3>
            <div class="text-muted">待审核提现</div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="clip-tabs">
        <button type="button" class="clip-btn js-ref-tab <?php echo $view === 'settings' ? 'active' : ''; ?>" data-tab="settings">返利设置</button>
        <button type="button" class="clip-btn js-ref-tab <?php echo $view === 'pending' ? 'active' : ''; ?>" data-tab="pending">待审核提现</button>
        <button type="button" class="clip-btn js-ref-tab <?php echo $view === 'earnings' ? 'active' : ''; ?>" data-tab="earnings">奖励记录</button>
        <button type="button" class="clip-btn js-ref-tab <?php echo $view === 'relations' ? 'active' : ''; ?>" data-tab="relations">邀请关系</button>
        <button type="button" class="clip-btn js-ref-tab <?php echo $view === 'withdrawals' ? 'active' : ''; ?>" data-tab="withdrawals">提现记录</button>
    </div>
    <div class="btn-group btn-group-sm">
        <button type="button" class="btn btn-outline-secondary" id="refTabPrev">← 左切换</button>
        <button type="button" class="btn btn-outline-secondary" id="refTabNext">右切换 →</button>
    </div>
</div>

<div class="ref-tab-panel" data-tab="settings" style="<?php echo $view === 'settings' ? '' : 'display:none;'; ?>">
    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-3">返利设置</h6>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                        <div class="mb-3">
                            <label class="form-label">返利比例 (%)</label>
                            <div class="input-group">
                                <input type="number" name="referral_rate" class="form-control" value="<?php echo $current_rate; ?>" step="0.1" min="0" max="100">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="form-text">商户邀请用户消费后获得的奖励比例。</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">保存设置</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="card-title text-muted mb-3">商户单独返利比例</h6>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                        <input type="hidden" name="action" value="set_user_rate">
                        <div class="mb-2">
                            <label class="form-label">商户ID</label>
                            <input type="number" min="1" name="target_user_id" class="form-control" placeholder="输入商户ID">
                        </div>
                        <div class="mb-2">
                            <label class="form-label">返利比例(%)</label>
                            <input type="number" step="0.1" min="0" max="100" name="user_referral_rate" class="form-control" placeholder="留空=使用全局比例">
                        </div>
                        <button type="submit" class="btn btn-outline-primary w-100">保存商户比例</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="ref-tab-panel" data-tab="pending" style="<?php echo $view === 'pending' ? '' : 'display:none;'; ?>">
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">邀请奖励提现待审核</h6>
                <span class="badge bg-warning text-dark"><?php echo (int)$pending_total; ?> 条</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>商户</th>
                            <th>申请时间</th>
                            <th>金额</th>
                            <th>方式</th>
                            <th>目标</th>
                            <th>审核</th>
                            <th>提现</th>
                            <th class="text-end">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($withdrawals_pending as $wd): ?>
                        <tr>
                            <td>#<?php echo (int)$wd['id']; ?></td>
                            <td><?php echo htmlspecialchars((string)$wd['email']); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string)$wd['created_at']); ?></td>
                            <td class="fw-bold text-primary"><?php echo number_format((float)$wd['amount'], 6); ?> <?php echo htmlspecialchars((string)$wd['currency']); ?></td>
                            <td><?php echo htmlspecialchars(ReferralService::methodLabel((string)$wd['method'])); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string)($wd['target_account'] ?? '-')); ?></td>
                            <td><span class="badge <?php echo ($wd['audit_status'] ?? '')==='approved' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo ($wd['audit_status'] ?? '')==='approved' ? '已通过' : '待审核'; ?></span></td>
                            <td><span class="badge <?php echo ($wd['payout_status'] ?? '')==='completed' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo ($wd['payout_status'] ?? '')==='completed' ? '已完成' : (($wd['payout_status'] ?? '')==='pending_manual' ? '待打款' : '处理中'); ?></span></td>
                            <td class="text-end">
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#reviewWithdrawalModal"
                                        data-id="<?php echo (int)$wd['id']; ?>"
                                        data-method="<?php echo htmlspecialchars((string)$wd['method']); ?>"
                                        data-amount="<?php echo htmlspecialchars(number_format((float)$wd['amount'], 6, '.', '')); ?>"
                                        data-currency="<?php echo htmlspecialchars((string)$wd['currency']); ?>"
                                        data-status="<?php echo htmlspecialchars((string)$wd['status']); ?>"
                                        data-target="<?php echo htmlspecialchars((string)($wd['target_account'] ?? '')); ?>">
                                    审核
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($withdrawals_pending)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">暂无待审核提现申请</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-3 border-top d-flex justify-content-between align-items-center">
                <small class="text-muted">共 <?php echo $pending_total; ?> 条</small>
                <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary <?php echo $wd_pending_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(admin_ref_page_url(['wd_pending_page' => max(1, $wd_pending_page - 1)])); ?>">上一页</a>
                    <span class="btn btn-light disabled"><?php echo $wd_pending_page; ?> / <?php echo $pending_total_pages; ?></span>
                    <a class="btn btn-outline-secondary <?php echo $wd_pending_page >= $pending_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(admin_ref_page_url(['wd_pending_page' => min($pending_total_pages, $wd_pending_page + 1)])); ?>">下一页</a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="ref-tab-panel" data-tab="earnings" style="<?php echo $view === 'earnings' ? '' : 'display:none;'; ?>">
<div class="row g-4">
    <!-- Top Referrers -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">商户推广排行 (Top 10)</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>商户</th>
                            <th>邀请人数</th>
                            <th>累计收益</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($top_referrers as $r): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['email']); ?></td>
                            <td><?php echo number_format($r['invite_count']); ?></td>
                            <td class="fw-bold text-success">$<?php echo number_format($r['total_earnings'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Earnings -->
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">最新奖励记录</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>商户</th>
                            <th>来源订单</th>
                            <th>奖励金额</th>
                            <th>时间</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($earnings as $e): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($e['referrer_email']); ?></td>
                            <td class="small text-muted">#<?php echo $e['source_order_id']; ?></td>
                            <td class="fw-bold text-success">+$<?php echo number_format($e['amount'], 4); ?></td>
                            <td class="small text-muted"><?php echo date('m-d H:i', strtotime($e['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-3 border-top d-flex justify-content-between align-items-center">
                <small class="text-muted">共 <?php echo $earnings_total; ?> 条</small>
                <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary <?php echo $earnings_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(admin_ref_page_url(['earnings_page' => max(1, $earnings_page - 1)])); ?>">上一页</a>
                    <span class="btn btn-light disabled"><?php echo $earnings_page; ?> / <?php echo $earnings_total_pages; ?></span>
                    <a class="btn btn-outline-secondary <?php echo $earnings_page >= $earnings_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(admin_ref_page_url(['earnings_page' => min($earnings_total_pages, $earnings_page + 1)])); ?>">下一页</a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="ref-tab-panel" data-tab="relations" style="<?php echo $view === 'relations' ? '' : 'display:none;'; ?>">
<div class="row g-4 mt-2">
    <!-- All Referral Relationships -->
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">全平台邀请关系明细</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>注册时间</th>
                            <th>被邀请人 (ID / 邮箱)</th>
                            <th>邀请人 (ID / 邮箱)</th>
                            <th>返利比例</th>
                            <th>有效订单数</th>
                            <th>贡献收益</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($referral_list as $rel): ?>
                        <tr>
                            <td class="small text-muted"><?php echo $rel['invite_time']; ?></td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="fw-bold text-dark"><?php echo htmlspecialchars($rel['invitee_email']); ?></span>
                                    <span class="small text-muted">ID: <?php echo $rel['invitee_id']; ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="fw-bold text-primary"><?php echo htmlspecialchars($rel['referrer_email']); ?></span>
                                    <span class="small text-muted">ID: <?php echo $rel['referrer_id']; ?></span>
                                </div>
                            </td>
                            <td>
                                <?php
                                    $effectiveRate = ($rel['referrer_rate_override'] !== null && $rel['referrer_rate_override'] !== '')
                                        ? (float)$rel['referrer_rate_override']
                                        : (float)$current_rate;
                                ?>
                                <span class="badge bg-info text-dark"><?php echo rtrim(rtrim(number_format($effectiveRate, 2, '.', ''), '0'), '.'); ?>%</span>
                            </td>
                            <td>
                                <?php if($rel['order_count'] > 0): ?>
                                    <span class="badge bg-success bg-opacity-10 text-success"><?php echo $rel['order_count']; ?> 笔</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold text-success">
                                <?php echo $rel['total_generated_earnings'] > 0 ? '+$' . number_format($rel['total_generated_earnings'], 4) : '-'; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($referral_list)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">暂无邀请记录</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-3 border-top d-flex justify-content-between align-items-center">
                <small class="text-muted">共 <?php echo $relations_total; ?> 条</small>
                <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary <?php echo $relations_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(admin_ref_page_url(['relations_page' => max(1, $relations_page - 1)])); ?>">上一页</a>
                    <span class="btn btn-light disabled"><?php echo $relations_page; ?> / <?php echo $relations_total_pages; ?></span>
                    <a class="btn btn-outline-secondary <?php echo $relations_page >= $relations_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(admin_ref_page_url(['relations_page' => min($relations_total_pages, $relations_page + 1)])); ?>">下一页</a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="ref-tab-panel" data-tab="withdrawals" style="<?php echo $view === 'withdrawals' ? '' : 'display:none;'; ?>">
<div class="row g-4 mt-2">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">邀请奖励提现记录</h6>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>ID</th>
                            <th>商户</th>
                            <th>申请时间</th>
                            <th>金额</th>
                            <th>方式</th>
                            <th>审核状态</th>
                            <th>提现状态</th>
                            <th>审核人</th>
                            <th>备注</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($withdrawals_recent as $wd): ?>
                        <tr>
                            <td>#<?php echo (int)$wd['id']; ?></td>
                            <td><?php echo htmlspecialchars((string)$wd['email']); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string)$wd['created_at']); ?></td>
                            <td class="fw-bold text-primary"><?php echo number_format((float)$wd['amount'], 6); ?> <?php echo htmlspecialchars((string)$wd['currency']); ?></td>
                            <td><?php echo htmlspecialchars(ReferralService::methodLabel((string)$wd['method'])); ?></td>
                            <td><?php echo htmlspecialchars((string)($wd['audit_status'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars((string)($wd['payout_status'] ?? '-')); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string)($wd['admin_email'] ?? '-')); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars((string)($wd['review_note'] ?? '-')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($withdrawals_recent)): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">暂无提现记录</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body pt-3 border-top d-flex justify-content-between align-items-center">
                <small class="text-muted">共 <?php echo $recent_total; ?> 条</small>
                <div class="btn-group btn-group-sm">
                    <a class="btn btn-outline-secondary <?php echo $wd_recent_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(admin_ref_page_url(['wd_recent_page' => max(1, $wd_recent_page - 1)])); ?>">上一页</a>
                    <span class="btn btn-light disabled"><?php echo $wd_recent_page; ?> / <?php echo $recent_total_pages; ?></span>
                    <a class="btn btn-outline-secondary <?php echo $wd_recent_page >= $recent_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(admin_ref_page_url(['wd_recent_page' => min($recent_total_pages, $wd_recent_page + 1)])); ?>">下一页</a>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<div class="modal fade" id="reviewWithdrawalModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
      <input type="hidden" name="action" value="review_withdrawal">
      <input type="hidden" name="withdrawal_id" id="wdIdField" value="">
      <div class="modal-header">
        <h5 class="modal-title">邀请提现审核</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="small text-muted mb-2">申请ID：<span id="wdIdTxt">-</span></div>
        <div class="small text-muted mb-2">提现方式：<span id="wdMethodTxt">-</span></div>
        <div class="small text-muted mb-2">提现金额：<span id="wdAmountTxt">-</span></div>
        <div class="small text-muted mb-3">目标：<span id="wdTargetTxt">-</span></div>

        <div class="mb-3">
            <label class="form-label">审核动作</label>
            <select class="form-select" name="decision" id="wdDecisionSelect">
                <option value="approve">审核通过</option>
                <option value="approve_pay_binance">通过并立即打款（Binance）</option>
                <option value="reject">审核拒绝</option>
                <option value="mark_paid">标记已打款</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">备注</label>
            <input type="text" class="form-control" name="review_note" placeholder="可选">
        </div>
        <div class="mb-1">
            <label class="form-label">交易参考号（可选）</label>
            <input type="text" class="form-control" name="tx_ref" placeholder="tx hash / internal ref">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">取消</button>
        <button type="submit" class="btn btn-primary">提交审核</button>
      </div>
    </form>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabOrder = ['settings', 'pending', 'earnings', 'relations', 'withdrawals'];
    const tabButtons = Array.from(document.querySelectorAll('.js-ref-tab'));
    const panels = Array.from(document.querySelectorAll('.ref-tab-panel'));
    const prevBtn = document.getElementById('refTabPrev');
    const nextBtn = document.getElementById('refTabNext');
    let currentTab = <?php echo json_encode($view); ?>;

    function applyTab(tab) {
        if (!tabOrder.includes(tab)) tab = 'pending';
        currentTab = tab;
        tabButtons.forEach(btn => {
            const isActive = btn.getAttribute('data-tab') === tab;
            btn.classList.toggle('active', isActive);
        });
        panels.forEach(panel => {
            panel.style.display = panel.getAttribute('data-tab') === tab ? '' : 'none';
        });
        if (history.replaceState) {
            history.replaceState(null, '', '#tab=' + encodeURIComponent(tab));
        }
    }

    tabButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            applyTab(btn.getAttribute('data-tab') || 'pending');
        });
    });
    if (prevBtn) {
        prevBtn.addEventListener('click', function() {
            let idx = tabOrder.indexOf(currentTab);
            if (idx < 0) idx = 0;
            idx = (idx - 1 + tabOrder.length) % tabOrder.length;
            applyTab(tabOrder[idx]);
        });
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', function() {
            let idx = tabOrder.indexOf(currentTab);
            if (idx < 0) idx = 0;
            idx = (idx + 1) % tabOrder.length;
            applyTab(tabOrder[idx]);
        });
    }

    const hashMatch = window.location.hash.match(/tab=([a-z_]+)/i);
    if (hashMatch && hashMatch[1]) {
        applyTab(hashMatch[1].toLowerCase());
    } else {
        applyTab(currentTab);
    }

    const modal = document.getElementById('reviewWithdrawalModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', function(event) {
        const btn = event.relatedTarget;
        if (!btn) return;
        const id = btn.getAttribute('data-id') || '';
        const method = btn.getAttribute('data-method') || '';
        const amount = btn.getAttribute('data-amount') || '';
        const currency = btn.getAttribute('data-currency') || '';
        const target = btn.getAttribute('data-target') || '';
        const status = btn.getAttribute('data-status') || '';
        const methodHuman = method === 'binance' ? 'Binance 账户' : (method === 'wallet' ? '个人钱包地址' : (method === 'balance' ? '站内余额' : method));
        document.getElementById('wdIdField').value = id;
        document.getElementById('wdIdTxt').textContent = '#' + id;
        document.getElementById('wdMethodTxt').textContent = methodHuman;
        document.getElementById('wdAmountTxt').textContent = amount + ' ' + currency;
        document.getElementById('wdTargetTxt').textContent = target;
        const select = document.getElementById('wdDecisionSelect');
        if (status === 'approved') {
            select.value = 'mark_paid';
        } else if (method === 'binance') {
            select.value = 'approve_pay_binance';
        } else {
            select.value = 'approve';
        }
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
