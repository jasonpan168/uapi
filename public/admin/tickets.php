<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();

require_once __DIR__ . '/../../src/Core/Database.php';
$db = Database::getInstance();
$db->autoMigrate();

$status_filter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));

// Stats
$statsRows = $db->fetchAll("SELECT status, COUNT(*) as c FROM tickets GROUP BY status");
$stats = ['total' => 0, 'open' => 0, 'answered' => 0, 'closed' => 0];
foreach ($statsRows as $sr) {
    $stats[$sr['status']] = (int)$sr['c'];
    $stats['total'] += (int)$sr['c'];
}

// Build WHERE
$whereParts = [];
$params = [];
$countParams = [];
if ($status_filter !== 'all') {
    $whereParts[] = "t.status = ?";
    $params[] = $status_filter;
    $countParams[] = $status_filter;
}
if ($search !== '') {
    $whereParts[] = "(u.email LIKE ? OR t.subject LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like;
    $countParams[] = $like; $countParams[] = $like;
}
$whereStr = $whereParts ? ' WHERE ' . implode(' AND ', $whereParts) : '';

$countSql = "SELECT COUNT(*) AS c FROM tickets t JOIN users u ON t.user_id = u.id" . $whereStr;
$total_row = $db->fetch($countSql, $countParams);
$total = (int)($total_row['c'] ?? 0);
$pages = max(1, (int)ceil($total / $per_page));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per_page;

$sql = "SELECT t.*, u.email, u.id as user_id,
            (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id) as reply_count
        FROM tickets t JOIN users u ON t.user_id = u.id"
    . $whereStr
    . " ORDER BY CASE t.status WHEN 'open' THEN 0 WHEN 'answered' THEN 1 ELSE 2 END, t.updated_at DESC"
    . " LIMIT $per_page OFFSET $offset";
$tickets = $db->fetchAll($sql, $params);

$ticketTypeMeta = [
    'support'    => ['label' => '工单咨询',  'color' => '#3b82f6'],
    'bug'        => ['label' => 'BUG 提交',  'color' => '#ef4444'],
    'feature'    => ['label' => '功能建议',  'color' => '#f59e0b'],
    'payment'    => ['label' => '支付问题',  'color' => '#ef4444'],
    'account'    => ['label' => '账户问题',  'color' => '#06b6d4'],
    'api'        => ['label' => 'API 问题',  'color' => '#8b5cf6'],
    'withdrawal' => ['label' => '提现问题',  'color' => '#ef4444'],
    'other'      => ['label' => '其他问题',  'color' => '#6b7280'],
];

$active_menu = 'tickets';
require_once 'includes/header.php';
?>
<style>
.tk-stat-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px 24px;display:flex;align-items:center;gap:16px;transition:.2s}
.tk-stat-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.08)}
.tk-stat-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem}
.tk-stat-num{font-size:1.6rem;font-weight:800;line-height:1}
.tk-stat-label{font-size:.8rem;color:#6b7280;margin-top:2px}
.tk-filter-tabs{display:flex;gap:4px;background:#f3f4f6;border-radius:10px;padding:4px}
.tk-filter-tab{padding:7px 18px;border-radius:8px;font-size:.875rem;font-weight:600;color:#6b7280;text-decoration:none;transition:.15s;white-space:nowrap}
.tk-filter-tab:hover{color:#111;background:#fff}
.tk-filter-tab.active{background:#fff;color:#111;box-shadow:0 1px 4px rgba(0,0,0,.12)}
.tk-table th{font-size:.78rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid #f3f4f6;padding:10px 14px;background:#fafafa}
.tk-table td{padding:14px 14px;border-bottom:1px solid #f9fafb;vertical-align:middle}
.tk-table tbody tr:hover td{background:#f9fafb}
.tk-type-chip{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:.78rem;font-weight:600}
.tk-priority-dot{width:7px;height:7px;border-radius:50%;display:inline-block;margin-right:4px}
.tk-status-badge{padding:4px 10px;border-radius:6px;font-size:.78rem;font-weight:700;display:inline-block}
.tk-status-open{background:#fef3c7;color:#92400e}
.tk-status-answered{background:#dbeafe;color:#1e40af}
.tk-status-closed{background:#f3f4f6;color:#6b7280}
.tk-id{font-family:monospace;font-size:.82rem;color:#9ca3af;font-weight:500}
.subject-link{font-weight:600;color:#111;text-decoration:none;font-size:.92rem}
.subject-link:hover{color:#3b82f6}
.reply-badge{background:#f3f4f6;color:#6b7280;border-radius:999px;padding:2px 7px;font-size:.75rem;margin-left:6px}
.avatar-circle{width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0}
[data-bs-theme="dark"] .tk-stat-card{background:#1f2937;border-color:#374151}
[data-bs-theme="dark"] .tk-filter-tabs{background:#111827}
[data-bs-theme="dark"] .tk-filter-tab.active{background:#1f2937;color:#f9fafb}
[data-bs-theme="dark"] .tk-table th{background:#111827;color:#6b7280;border-color:#1f2937}
[data-bs-theme="dark"] .tk-table td{border-color:#1f2937}
[data-bs-theme="dark"] .tk-table tbody tr:hover td{background:#1f2937}
[data-bs-theme="dark"] .subject-link{color:#f9fafb}
[data-bs-theme="dark"] .reply-badge{background:#374151;color:#9ca3af}
[data-bs-theme="dark"] .tk-status-closed{background:#374151;color:#9ca3af}
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-0">工单管理</h1>
        <p class="text-muted small mb-0 mt-1">查看并处理用户提交的支持工单</p>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="tk-stat-card">
            <div class="tk-stat-icon" style="background:#f3f4f6"><i class="fas fa-ticket-alt" style="color:#6b7280"></i></div>
            <div>
                <div class="tk-stat-num"><?php echo $stats['total']; ?></div>
                <div class="tk-stat-label">全部工单</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tk-stat-card">
            <div class="tk-stat-icon" style="background:#fef3c7"><i class="fas fa-clock" style="color:#d97706"></i></div>
            <div>
                <div class="tk-stat-num" style="color:#d97706"><?php echo $stats['open']; ?></div>
                <div class="tk-stat-label">待处理</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tk-stat-card">
            <div class="tk-stat-icon" style="background:#dbeafe"><i class="fas fa-reply" style="color:#2563eb"></i></div>
            <div>
                <div class="tk-stat-num" style="color:#2563eb"><?php echo $stats['answered']; ?></div>
                <div class="tk-stat-label">已回复</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="tk-stat-card">
            <div class="tk-stat-icon" style="background:#f3f4f6"><i class="fas fa-check-circle" style="color:#10b981"></i></div>
            <div>
                <div class="tk-stat-num" style="color:#10b981"><?php echo $stats['closed']; ?></div>
                <div class="tk-stat-label">已关闭</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter & Search -->
<div class="d-flex flex-wrap gap-3 align-items-center justify-content-between mb-3">
    <div class="tk-filter-tabs">
        <?php
        $tabs = ['all' => '全部', 'open' => '待处理', 'answered' => '已回复', 'closed' => '已关闭'];
        foreach ($tabs as $k => $v):
            $active = ($status_filter === $k) ? 'active' : '';
            $q = http_build_query(['status' => $k, 'search' => $search, 'page' => 1]);
        ?>
        <a href="?<?php echo $q; ?>" class="tk-filter-tab <?php echo $active; ?>"><?php echo $v; ?>
            <?php if ($k !== 'all' && $stats[$k] > 0): ?>
                <span class="ms-1" style="opacity:.6"><?php echo $stats[$k]; ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    <form method="GET" class="d-flex gap-2" style="min-width:260px">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
        <input type="hidden" name="page" value="1">
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" class="form-control border-start-0 ps-0" placeholder="搜索邮箱或标题...">
            <button class="btn btn-primary" type="submit">搜索</button>
        </div>
    </form>
</div>

<!-- Table -->
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:14px;overflow:hidden">
    <div class="table-responsive">
        <table class="table tk-table mb-0">
            <thead>
                <tr>
                    <th class="ps-4" style="width:44px">#</th>
                    <th>用户</th>
                    <th>标题</th>
                    <th>类型</th>
                    <th>优先级</th>
                    <th>状态</th>
                    <th>更新时间</th>
                    <th class="text-end pe-4">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tickets as $t):
                $typeKey = strtolower(trim((string)($t['ticket_type'] ?? 'support')));
                if (!isset($ticketTypeMeta[$typeKey])) $typeKey = 'support';
                $color = $ticketTypeMeta[$typeKey]['color'];
                $age = time() - strtotime($t['updated_at']);
                $ageStr = $age < 3600 ? round($age/60).'分钟前' : ($age < 86400 ? round($age/3600).'小时前' : date('m-d H:i', strtotime($t['updated_at'])));
            ?>
            <tr>
                <td class="ps-4"><span class="tk-id"><?php echo $t['id']; ?></span></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="avatar-circle"><?php echo strtoupper(substr($t['email'], 0, 1)); ?></div>
                        <div>
                            <div class="fw-semibold" style="font-size:.85rem;line-height:1.2"><?php echo htmlspecialchars(explode('@', $t['email'])[0]); ?></div>
                            <div class="text-muted" style="font-size:.75rem">@<?php echo htmlspecialchars(explode('@', $t['email'])[1] ?? ''); ?></div>
                        </div>
                    </div>
                </td>
                <td style="max-width:300px">
                    <a href="ticket_view.php?id=<?php echo $t['id']; ?>" class="subject-link d-block text-truncate">
                        <?php echo htmlspecialchars($t['subject']); ?>
                    </a>
                    <?php if ((int)$t['reply_count'] > 0): ?>
                        <span class="reply-badge"><i class="fas fa-comment-dots me-1"></i><?php echo $t['reply_count']; ?> 条回复</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="tk-type-chip" style="background:<?php echo $color; ?>18;color:<?php echo $color; ?>">
                        <?php echo $ticketTypeMeta[$typeKey]['label']; ?>
                    </span>
                </td>
                <td>
                    <?php if ($t['priority'] === 'high'): ?>
                        <span><span class="tk-priority-dot" style="background:#ef4444"></span>高</span>
                    <?php elseif ($t['priority'] === 'medium'): ?>
                        <span><span class="tk-priority-dot" style="background:#f59e0b"></span>中</span>
                    <?php else: ?>
                        <span><span class="tk-priority-dot" style="background:#10b981"></span>低</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($t['status'] === 'open'): ?>
                        <span class="tk-status-badge tk-status-open"><i class="fas fa-circle me-1" style="font-size:.5rem;vertical-align:middle"></i>待处理</span>
                    <?php elseif ($t['status'] === 'answered'): ?>
                        <span class="tk-status-badge tk-status-answered">已回复</span>
                    <?php else: ?>
                        <span class="tk-status-badge tk-status-closed">已关闭</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.82rem;white-space:nowrap"><?php echo $ageStr; ?></td>
                <td class="text-end pe-4">
                    <a href="ticket_view.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-primary px-3">处理</a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($tickets)): ?>
            <tr>
                <td colspan="8" class="text-center py-5">
                    <i class="fas fa-inbox fa-2x text-muted mb-3 d-block"></i>
                    <div class="text-muted"><?php echo $search ? '未找到匹配的工单' : '暂无工单'; ?></div>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between align-items-center px-4 py-3 border-top" style="background:#fafafa">
        <small class="text-muted">共 <?php echo $total; ?> 条 · 第 <?php echo $page; ?>/<?php echo $pages; ?> 页</small>
        <div class="d-flex gap-2">
            <?php $baseQ = http_build_query(['status' => $status_filter, 'search' => $search]); ?>
            <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?<?php echo $baseQ; ?>&page=<?php echo max(1,$page-1); ?>">← 上一页</a>
            <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?<?php echo $baseQ; ?>&page=<?php echo min($pages,$page+1); ?>">下一页 →</a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
