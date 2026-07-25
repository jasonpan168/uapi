<?php
session_start();
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Http.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$db = Database::getInstance();
$db->autoMigrate();
$user_id = $_SESSION['user_id'];
$flashes = flash_consume_all();

// Handle Create Ticket
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $subject    = trim($_POST['subject'] ?? '');
    $message    = trim($_POST['message'] ?? '');
    $ticketType = strtolower(trim((string)($_POST['ticket_type'] ?? 'support')));
    if (!in_array($ticketType, ['support','bug','feature','payment','account','api','withdrawal','other'], true)) $ticketType = 'support';
    $priority = 'medium';
    if (in_array($ticketType, ['bug','payment','withdrawal'], true)) $priority = 'high';
    elseif ($ticketType === 'feature') $priority = 'low';

    if (empty($subject) || empty($message)) {
        flash_add('error', __('merchant.tickets.error.empty_subject_message'));
        redirect_303('tickets.php');
    } else {
        try {
            $db->query("INSERT INTO tickets (user_id, subject, priority, ticket_type, status) VALUES (?, ?, ?, ?, 'open')", [$user_id, $subject, $priority, $ticketType]);
            $ticket_id = $db->lastInsertId();
            $db->query("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, 0)", [$ticket_id, $user_id, $message]);
            flash_add('success', __('merchant.tickets.success.created'));
            redirect_303('tickets.php');
        } catch (Exception $e) {
            flash_add('error', __('merchant.tickets.error.submit_failed'));
            redirect_303('tickets.php');
        }
    }
}

// Status filter
$status_filter = $_GET['status'] ?? 'all';
if (!in_array($status_filter, ['all','open','answered','closed'], true)) $status_filter = 'all';

// Stats for this user
$statsRows = $db->fetchAll("SELECT status, COUNT(*) as c FROM tickets WHERE user_id = ? GROUP BY status", [$user_id]);
$stats = ['total' => 0, 'open' => 0, 'answered' => 0, 'closed' => 0];
foreach ($statsRows as $sr) { $stats[$sr['status']] = (int)$sr['c']; $stats['total'] += (int)$sr['c']; }

// Query
$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$where = "WHERE t.user_id = ?";
$params = [$user_id];
if ($status_filter !== 'all') { $where .= " AND t.status = ?"; $params[] = $status_filter; }

$total_row = $db->fetch("SELECT COUNT(*) AS c FROM tickets t $where", $params);
$total = (int)($total_row['c'] ?? 0);
$pages = max(1, (int)ceil($total / $per_page));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per_page;

$tickets = $db->fetchAll(
    "SELECT t.*,
        (SELECT COUNT(*) FROM ticket_replies tr WHERE tr.ticket_id = t.id) as reply_count,
        (SELECT is_admin FROM ticket_replies tr WHERE tr.ticket_id = t.id ORDER BY tr.created_at DESC LIMIT 1) as last_reply_is_admin
     FROM tickets t $where ORDER BY t.updated_at DESC LIMIT $per_page OFFSET $offset",
    $params
);

$ticketTypeMeta = [
    'support'    => ['label' => __('merchant.tickets.type.support'),    'color' => '#3b82f6'],
    'bug'        => ['label' => __('merchant.tickets.type.bug'),        'color' => '#ef4444'],
    'feature'    => ['label' => __('merchant.tickets.type.feature'),    'color' => '#f59e0b'],
    'payment'    => ['label' => __('merchant.tickets.type.payment'),    'color' => '#ef4444'],
    'account'    => ['label' => __('merchant.tickets.type.account'),    'color' => '#06b6d4'],
    'api'        => ['label' => __('merchant.tickets.type.api'),        'color' => '#8b5cf6'],
    'withdrawal' => ['label' => __('merchant.tickets.type.withdrawal'), 'color' => '#ef4444'],
    'other'      => ['label' => __('merchant.tickets.type.other'),      'color' => '#6b7280'],
];
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
    .tk-tabs{display:flex;gap:4px;background:#f3f4f6;border-radius:10px;padding:4px;width:fit-content}
    .tk-tab{padding:7px 18px;border-radius:8px;font-size:.85rem;font-weight:600;color:#6b7280;text-decoration:none;transition:.15s;white-space:nowrap}
    .tk-tab:hover{color:#111;background:#fff}
    .tk-tab.active{background:#fff;color:#111827;box-shadow:0 1px 4px rgba(0,0,0,.1)}
    .tk-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;transition:.15s;text-decoration:none;display:block;color:inherit}
    .tk-card:hover{border-color:#a5b4fc;box-shadow:0 4px 12px rgba(99,102,241,.1);transform:translateY(-1px);color:inherit}
    .tk-card.has-reply{border-left:3px solid #6366f1}
    .tk-subject{font-weight:700;font-size:.95rem;color:#111827;margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .tk-meta{font-size:.78rem;color:#9ca3af;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .tk-status-open{background:#fef3c7;color:#92400e;border-radius:6px;padding:2px 9px;font-size:.75rem;font-weight:700}
    .tk-status-answered{background:#dbeafe;color:#1e40af;border-radius:6px;padding:2px 9px;font-size:.75rem;font-weight:700}
    .tk-status-closed{background:#f3f4f6;color:#6b7280;border-radius:6px;padding:2px 9px;font-size:.75rem;font-weight:700}
    .tk-type-chip{border-radius:999px;padding:2px 9px;font-size:.75rem;font-weight:600}
    .new-reply-dot{width:8px;height:8px;background:#6366f1;border-radius:50%;display:inline-block;margin-right:4px;animation:pulse 1.5s infinite}
    @keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
    .stat-mini{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 18px;text-align:center}
    .stat-mini-num{font-size:1.4rem;font-weight:800;line-height:1}
    .stat-mini-label{font-size:.75rem;color:#9ca3af;margin-top:2px}
    .empty-state{text-align:center;padding:60px 20px;color:#9ca3af}
    .empty-state i{font-size:3rem;margin-bottom:16px;display:block;opacity:.4}
    [data-bs-theme="dark"] .tk-tabs{background:#1f2937}
    [data-bs-theme="dark"] .tk-tab.active{background:#374151;color:#f9fafb}
    [data-bs-theme="dark"] .tk-card{background:#1f2937;border-color:#374151;color:#f9fafb}
    [data-bs-theme="dark"] .tk-card:hover{border-color:#6366f1}
    [data-bs-theme="dark"] .tk-subject{color:#f9fafb}
    [data-bs-theme="dark"] .stat-mini{background:#1f2937;border-color:#374151}
    [data-bs-theme="dark"] .tk-status-closed{background:#374151;color:#9ca3af}
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.tickets.page_title'); include __DIR__ . '/includes/user_topbar.php'; ?>
            <div class="container-fluid">

                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
                    <div>
                        <h1 class="h3 fw-bold mb-1"><?php echo __('merchant.tickets.my_tickets'); ?></h1>
                        <p class="text-muted small mb-0">提交并跟踪您的支持请求</p>
                    </div>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTicketModal">
                        <i class="fas fa-plus me-2"></i><?php echo __('merchant.tickets.create_new'); ?>
                    </button>
                </div>

                <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?php echo $f['type']==='error'?'danger':($f['type']==='success'?'success':'info'); ?> alert-dismissible fade show">
                    <?php echo htmlspecialchars($f['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endforeach; ?>

                <!-- Stats -->
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="stat-mini">
                            <div class="stat-mini-num"><?php echo $stats['total']; ?></div>
                            <div class="stat-mini-label">全部工单</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-mini">
                            <div class="stat-mini-num" style="color:#d97706"><?php echo $stats['open']; ?></div>
                            <div class="stat-mini-label">待处理</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-mini">
                            <div class="stat-mini-num" style="color:#2563eb"><?php echo $stats['answered']; ?></div>
                            <div class="stat-mini-label">已回复</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-mini">
                            <div class="stat-mini-num" style="color:#10b981"><?php echo $stats['closed']; ?></div>
                            <div class="stat-mini-label">已关闭</div>
                        </div>
                    </div>
                </div>

                <!-- Filter Tabs -->
                <div class="tk-tabs mb-4">
                    <?php
                    $tabs = ['all' => '全部', 'open' => '待处理', 'answered' => '已回复', 'closed' => '已关闭'];
                    foreach ($tabs as $k => $v):
                        $isActive = ($status_filter === $k) ? 'active' : '';
                    ?>
                    <a href="?status=<?php echo $k; ?>&page=1" class="tk-tab <?php echo $isActive; ?>"><?php echo $v; ?>
                        <?php if ($k !== 'all' && $stats[$k] > 0): ?>
                            <span style="opacity:.55;font-size:.8em;margin-left:3px"><?php echo $stats[$k]; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Ticket List -->
                <?php if (empty($tickets)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <div class="fw-semibold mb-1" style="font-size:1rem;color:#6b7280"><?php echo __('merchant.tickets.no_records'); ?></div>
                    <p class="small" style="max-width:300px;margin:0 auto 16px">如有任何问题，欢迎随时提交工单，我们将尽快为您处理。</p>
                    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createTicketModal">
                        <i class="fas fa-plus me-1"></i><?php echo __('merchant.tickets.create_new'); ?>
                    </button>
                </div>
                <?php else: ?>
                <div class="d-flex flex-column gap-3 mb-3">
                    <?php foreach ($tickets as $t):
                        $typeKey = strtolower(trim((string)($t['ticket_type'] ?? 'support')));
                        if (!isset($ticketTypeMeta[$typeKey])) $typeKey = 'support';
                        $color = $ticketTypeMeta[$typeKey]['color'];
                        $hasNewReply = ((int)$t['last_reply_is_admin'] === 1);
                        $age = time() - strtotime($t['updated_at']);
                        $ageStr = $age < 3600 ? round($age/60).'分钟前' : ($age < 86400 ? round($age/3600).'小时前' : date('m-d H:i', strtotime($t['updated_at'])));
                    ?>
                    <a href="ticket_view.php?id=<?php echo $t['id']; ?>" class="tk-card <?php echo $hasNewReply ? 'has-reply' : ''; ?>">
                        <div class="d-flex align-items-start justify-content-between gap-2">
                            <div style="min-width:0;flex:1">
                                <div class="tk-subject">
                                    <?php if ($hasNewReply): ?>
                                        <span class="new-reply-dot" title="有新回复"></span>
                                    <?php endif; ?>
                                    #<?php echo $t['id']; ?> <?php echo htmlspecialchars($t['subject']); ?>
                                </div>
                                <div class="tk-meta">
                                    <span class="tk-type-chip" style="background:<?php echo $color; ?>18;color:<?php echo $color; ?>">
                                        <?php echo $ticketTypeMeta[$typeKey]['label']; ?>
                                    </span>
                                    <?php if ($t['status'] === 'open'): ?>
                                        <span class="tk-status-open"><i class="fas fa-circle me-1" style="font-size:.45rem;vertical-align:middle"></i><?php echo __('merchant.tickets.status.open'); ?></span>
                                    <?php elseif ($t['status'] === 'answered'): ?>
                                        <span class="tk-status-answered"><?php echo __('merchant.tickets.status.answered'); ?></span>
                                    <?php else: ?>
                                        <span class="tk-status-closed"><?php echo __('merchant.tickets.status.closed'); ?></span>
                                    <?php endif; ?>
                                    <?php if ((int)$t['reply_count'] > 0): ?>
                                        <span><i class="fas fa-comment-dots me-1"></i><?php echo $t['reply_count']; ?> 条回复</span>
                                    <?php endif; ?>
                                    <span><i class="far fa-clock me-1"></i><?php echo $ageStr; ?></span>
                                </div>
                            </div>
                            <div style="flex-shrink:0;color:#9ca3af;font-size:.85rem;margin-top:2px">
                                <i class="fas fa-chevron-right"></i>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination -->
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted"><?php echo __('merchant.common.page_status', ['page' => (int)$page, 'pages' => (int)$pages, 'total' => (int)$total]); ?></small>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-secondary <?php echo $page<=1?'disabled':''; ?>" href="?status=<?php echo urlencode($status_filter); ?>&page=<?php echo max(1,$page-1); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                        <a class="btn btn-sm btn-outline-secondary <?php echo $page>=$pages?'disabled':''; ?>" href="?status=<?php echo urlencode($status_filter); ?>&page=<?php echo min($pages,$page+1); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<!-- Create Ticket Modal -->
<div class="modal fade" id="createTicketModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" onsubmit="disableSubmit(this)">
                <input type="hidden" name="action" value="create">
                <div class="modal-header border-0 pb-0">
                    <div>
                        <h5 class="modal-title fw-bold"><?php echo __('merchant.tickets.modal.title'); ?></h5>
                        <p class="text-muted small mb-0">请详细描述您的问题，我们将尽快回复。</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body pt-3">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold"><?php echo __('merchant.tickets.modal.subject'); ?></label>
                            <input type="text" name="subject" class="form-control" required maxlength="100"
                                placeholder="<?php echo __('merchant.tickets.modal.subject_placeholder'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo __('merchant.tickets.modal.type'); ?></label>
                            <select name="ticket_type" class="form-select">
                                <option value="support"><?php echo __('merchant.tickets.type.support'); ?></option>
                                <option value="payment"><?php echo __('merchant.tickets.type.payment'); ?></option>
                                <option value="account"><?php echo __('merchant.tickets.type.account'); ?></option>
                                <option value="api"><?php echo __('merchant.tickets.type.api'); ?></option>
                                <option value="withdrawal"><?php echo __('merchant.tickets.type.withdrawal'); ?></option>
                                <option value="bug"><?php echo __('merchant.tickets.type.bug'); ?></option>
                                <option value="feature"><?php echo __('merchant.tickets.type.feature'); ?></option>
                                <option value="other"><?php echo __('merchant.tickets.type.other'); ?></option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold"><?php echo __('merchant.tickets.modal.message'); ?></label>
                            <textarea name="message" id="ticketMessageInput" class="form-control" rows="6" required
                                placeholder="<?php echo __('merchant.tickets.modal.message_placeholder'); ?>"></textarea>
                            <div class="text-end mt-1"><small id="msgCount" class="text-muted">0 字</small></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 pt-0">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary px-4" id="createTicketBtn">
                        <i class="fas fa-paper-plane me-2"></i><?php echo __('merchant.tickets.modal.submit'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function disableSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo jsesc(__('merchant.tickets.submitting')); ?>'; }
}
const msgInput = document.getElementById('ticketMessageInput');
const msgCount = document.getElementById('msgCount');
if (msgInput && msgCount) {
    msgInput.addEventListener('input', function() { msgCount.textContent = this.value.length + ' 字'; });
}
</script>
</body>
</html>
