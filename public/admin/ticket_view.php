<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();

require_once __DIR__ . '/../../src/Core/Database.php';
$httpHelpers = __DIR__ . '/../../src/Core/Http.php';
if (file_exists($httpHelpers)) require_once $httpHelpers;
$db = Database::getInstance();

$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($ticket_id <= 0) { header("Location: tickets.php"); exit; }

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        if (function_exists('flash_add')) flash_add('error', '请求已被拒绝（CSRF 校验失败）');
    } elseif ($_POST['action'] === 'reply') {
        $message = trim($_POST['message']);
        if (!empty($message)) {
            $db->query("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) VALUES (?, 0, ?, 1)", [$ticket_id, $message]);
            $db->query("UPDATE tickets SET status = 'answered', updated_at = NOW() WHERE id = ?", [$ticket_id]);
            if (function_exists('flash_add')) flash_add('success', '回复已发送');
        }
    } elseif ($_POST['action'] === 'close') {
        $db->query("UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = ?", [$ticket_id]);
        if (function_exists('flash_add')) flash_add('success', '工单已关闭');
    } elseif ($_POST['action'] === 'reopen') {
        $db->query("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?", [$ticket_id]);
        if (function_exists('flash_add')) flash_add('info', '工单已重新开启');
    } elseif ($_POST['action'] === 'set_priority') {
        $p = $_POST['priority'] ?? 'medium';
        if (in_array($p, ['low', 'medium', 'high'], true)) {
            $db->query("UPDATE tickets SET priority = ?, updated_at = NOW() WHERE id = ?", [$p, $ticket_id]);
        }
    }
    if (function_exists('redirect_303')) redirect_303('ticket_view.php?id=' . $ticket_id);
    else { header("Location: ticket_view.php?id=" . $ticket_id, true, 303); exit; }
    exit;
}

$ticket = $db->fetch("SELECT t.*, u.email, u.id as uid, u.created_at as user_created_at FROM tickets t JOIN users u ON t.user_id = u.id WHERE t.id = ?", [$ticket_id]);
if (!$ticket) { header("Location: tickets.php"); exit; }

$replies = $db->fetchAll("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at ASC", [$ticket_id]);

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
$typeKey = strtolower(trim((string)($ticket['ticket_type'] ?? 'support')));
if (!isset($ticketTypeMeta[$typeKey])) $typeKey = 'support';

$active_menu = 'tickets';
require_once 'includes/header.php';
?>
<style>
.tv-chat-wrap{height:520px;overflow-y:auto;padding:20px;background:#f9fafb;border-radius:12px;border:1px solid #e5e7eb}
.tv-bubble-row{display:flex;gap:10px;margin-bottom:18px}
.tv-bubble-row.admin{flex-direction:row-reverse}
.tv-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0}
.tv-avatar.user{background:#dbeafe;color:#1e40af}
.tv-avatar.admin{background:#6366f1;color:#fff}
.tv-bubble{padding:12px 16px;border-radius:14px;max-width:75%;word-break:break-word;font-size:.9rem;line-height:1.55}
.tv-bubble.user{background:#fff;border:1px solid #e5e7eb;border-bottom-left-radius:4px}
.tv-bubble.admin{background:#6366f1;color:#fff;border-bottom-right-radius:4px}
.tv-meta{font-size:.72rem;color:#9ca3af;margin-top:5px}
.tv-bubble-row.admin .tv-meta{text-align:right}
.tv-reply-box{background:#fff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden}
.tv-reply-box textarea{border:none;resize:none;outline:none;box-shadow:none;padding:14px 16px;font-size:.9rem;width:100%}
.tv-reply-box textarea:focus{outline:none;box-shadow:none}
.tv-reply-footer{padding:10px 14px;background:#fafafa;border-top:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;gap:8px}
.tv-info-row{display:flex;justify-content:space-between;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f3f4f6}
.tv-info-row:last-child{border-bottom:none;padding-bottom:0}
.tv-info-label{font-size:.78rem;color:#9ca3af;font-weight:500}
.tv-status-open{background:#fef3c7;color:#92400e;border-radius:6px;padding:3px 10px;font-size:.8rem;font-weight:700}
.tv-status-answered{background:#dbeafe;color:#1e40af;border-radius:6px;padding:3px 10px;font-size:.8rem;font-weight:700}
.tv-status-closed{background:#f3f4f6;color:#6b7280;border-radius:6px;padding:3px 10px;font-size:.8rem;font-weight:700}
.quick-reply-btn{border:1px solid #e5e7eb;background:#fff;border-radius:20px;padding:5px 12px;font-size:.78rem;color:#374151;cursor:pointer;transition:.15s;white-space:nowrap}
.quick-reply-btn:hover{background:#f3f4f6;border-color:#d1d5db}
.breadcrumb-nav a{color:#9ca3af;text-decoration:none;font-size:.85rem}
.breadcrumb-nav a:hover{color:#374151}
.breadcrumb-nav span{color:#d1d5db;margin:0 6px}
[data-bs-theme="dark"] .tv-chat-wrap{background:#111827;border-color:#374151}
[data-bs-theme="dark"] .tv-bubble.user{background:#1f2937;border-color:#374151;color:#f9fafb}
[data-bs-theme="dark"] .tv-reply-box{background:#1f2937;border-color:#374151}
[data-bs-theme="dark"] .tv-reply-box textarea{background:#1f2937;color:#f9fafb}
[data-bs-theme="dark"] .tv-reply-footer{background:#111827;border-color:#374151}
[data-bs-theme="dark"] .tv-info-row{border-color:#374151}
[data-bs-theme="dark"] .quick-reply-btn{background:#1f2937;border-color:#374151;color:#d1d5db}
[data-bs-theme="dark"] .quick-reply-btn:hover{background:#374151}
[data-bs-theme="dark"] .tv-status-closed{background:#374151;color:#9ca3af}
</style>

<!-- Breadcrumb -->
<div class="breadcrumb-nav mb-3">
    <a href="tickets.php"><i class="fas fa-ticket-alt me-1"></i>工单管理</a>
    <span>›</span>
    <span style="color:#374151;font-size:.85rem">工单 #<?php echo $ticket['id']; ?></span>
</div>

<!-- Title Row -->
<div class="d-flex align-items-start justify-content-between gap-3 mb-4 flex-wrap">
    <div>
        <h1 class="h4 fw-bold mb-1"><?php echo htmlspecialchars($ticket['subject']); ?></h1>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if ($ticket['status'] === 'open'): ?>
                <span class="tv-status-open"><i class="fas fa-circle me-1" style="font-size:.45rem;vertical-align:middle"></i>待处理</span>
            <?php elseif ($ticket['status'] === 'answered'): ?>
                <span class="tv-status-answered">已回复</span>
            <?php else: ?>
                <span class="tv-status-closed">已关闭</span>
            <?php endif; ?>
            <?php $tc = $ticketTypeMeta[$typeKey]; ?>
            <span style="background:<?php echo $tc['color']; ?>18;color:<?php echo $tc['color']; ?>;border-radius:999px;padding:3px 10px;font-size:.78rem;font-weight:600"><?php echo $tc['label']; ?></span>
            <span class="text-muted" style="font-size:.8rem">· 创建于 <?php echo date('Y-m-d H:i', strtotime($ticket['created_at'])); ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-shrink-0">
        <?php if ($ticket['status'] !== 'closed'): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('确定关闭此工单？')">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
            <input type="hidden" name="action" value="close">
            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-times-circle me-1"></i>关闭工单</button>
        </form>
        <?php else: ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
            <input type="hidden" name="action" value="reopen">
            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-redo me-1"></i>重新开启</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (function_exists('flash_consume_all')): ?>
<?php foreach (flash_consume_all() as $f): ?>
<div class="alert alert-<?php echo $f['type']==='error'?'danger':($f['type']==='success'?'success':($f['type']==='warning'?'warning':'info')); ?> alert-dismissible fade show">
    <?php echo htmlspecialchars($f['message']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="row g-4">
    <!-- Chat -->
    <div class="col-lg-8">
        <div class="tv-chat-wrap mb-3" id="adminChatBox">
            <?php if (empty($replies)): ?>
                <div class="text-center text-muted py-4" style="font-size:.9rem"><i class="fas fa-comment-slash d-block fa-2x mb-2"></i>暂无消息记录</div>
            <?php endif; ?>
            <?php foreach ($replies as $r): ?>
            <div class="tv-bubble-row <?php echo $r['is_admin'] ? 'admin' : ''; ?>">
                <div class="tv-avatar <?php echo $r['is_admin'] ? 'admin' : 'user'; ?>">
                    <?php echo $r['is_admin'] ? 'A' : strtoupper(substr($ticket['email'],0,1)); ?>
                </div>
                <div>
                    <div class="tv-bubble <?php echo $r['is_admin'] ? 'admin' : 'user'; ?>">
                        <?php echo nl2br(htmlspecialchars($r['message'])); ?>
                    </div>
                    <div class="tv-meta">
                        <?php echo $r['is_admin'] ? '管理员' : htmlspecialchars(explode('@',$ticket['email'])[0]); ?>
                        · <?php echo date('Y-m-d H:i', strtotime($r['created_at'])); ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Quick Reply Templates -->
        <div class="d-flex gap-2 flex-wrap mb-2">
            <span style="font-size:.78rem;color:#9ca3af;align-self:center">快速回复：</span>
            <?php
            $templates = [
                '感谢您的反馈，我们正在处理，请耐心等候。',
                '您好，请问还有什么需要我们协助的吗？',
                '问题已排查完毕，请刷新页面重试。',
                '已记录您的需求，将在下个版本中考虑。',
                '请您提供订单号或截图，方便我们进一步排查。',
            ];
            foreach ($templates as $tpl): ?>
            <button type="button" class="quick-reply-btn" onclick="fillReply(this)"><?php echo htmlspecialchars($tpl); ?></button>
            <?php endforeach; ?>
        </div>

        <?php if ($ticket['status'] !== 'closed'): ?>
        <div class="tv-reply-box">
            <form method="POST" onsubmit="disableSubmit(this)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                <input type="hidden" name="action" value="reply">
                <textarea name="message" id="adminReplyInput" rows="5" placeholder="输入回复内容..." required></textarea>
                <div class="tv-reply-footer">
                    <small class="text-muted" id="charCount" style="font-size:.75rem">0 字</small>
                    <button type="submit" class="btn btn-primary btn-sm px-4"><i class="fas fa-paper-plane me-2"></i>发送回复</button>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="text-center py-3 text-muted" style="font-size:.875rem;background:#f9fafb;border-radius:10px;border:1px dashed #e5e7eb">
            <i class="fas fa-lock me-1"></i> 工单已关闭，如需继续沟通请先重新开启
        </div>
        <?php endif; ?>
    </div>

    <!-- Sidebar Info -->
    <div class="col-lg-4">

        <!-- Ticket Info -->
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin-bottom:16px">
            <div class="fw-bold mb-3" style="font-size:.9rem">工单信息</div>
            <div class="tv-info-row">
                <span class="tv-info-label">工单 ID</span>
                <span class="fw-semibold" style="font-family:monospace">#<?php echo $ticket['id']; ?></span>
            </div>
            <div class="tv-info-row">
                <span class="tv-info-label">类型</span>
                <span style="background:<?php echo $tc['color']; ?>18;color:<?php echo $tc['color']; ?>;border-radius:999px;padding:2px 9px;font-size:.78rem;font-weight:600"><?php echo $tc['label']; ?></span>
            </div>
            <div class="tv-info-row">
                <span class="tv-info-label">优先级</span>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                    <input type="hidden" name="action" value="set_priority">
                    <select name="priority" class="form-select form-select-sm" style="width:auto;display:inline-block" onchange="this.form.submit()">
                        <option value="low" <?php echo $ticket['priority']==='low'?'selected':''; ?>>低</option>
                        <option value="medium" <?php echo $ticket['priority']==='medium'?'selected':''; ?>>中</option>
                        <option value="high" <?php echo $ticket['priority']==='high'?'selected':''; ?>>高</option>
                    </select>
                </form>
            </div>
            <div class="tv-info-row">
                <span class="tv-info-label">回复数</span>
                <span class="fw-semibold"><?php echo count($replies); ?> 条</span>
            </div>
            <div class="tv-info-row">
                <span class="tv-info-label">创建时间</span>
                <span style="font-size:.82rem"><?php echo date('Y-m-d H:i', strtotime($ticket['created_at'])); ?></span>
            </div>
            <div class="tv-info-row">
                <span class="tv-info-label">最后更新</span>
                <span style="font-size:.82rem"><?php echo date('Y-m-d H:i', strtotime($ticket['updated_at'])); ?></span>
            </div>
        </div>

        <!-- User Info -->
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px">
            <div class="fw-bold mb-3" style="font-size:.9rem">提交用户</div>
            <div class="d-flex align-items-center gap-3 mb-3">
                <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0">
                    <?php echo strtoupper(substr($ticket['email'],0,1)); ?>
                </div>
                <div style="min-width:0">
                    <div class="fw-semibold text-truncate" style="font-size:.9rem"><?php echo htmlspecialchars($ticket['email']); ?></div>
                    <div class="text-muted" style="font-size:.75rem">UID: <?php echo $ticket['uid']; ?></div>
                </div>
            </div>
            <div class="tv-info-row">
                <span class="tv-info-label">注册时间</span>
                <span style="font-size:.82rem"><?php echo date('Y-m-d', strtotime($ticket['user_created_at'])); ?></span>
            </div>
            <div class="d-grid mt-3 gap-2">
                <a href="users.php?search=<?php echo urlencode($ticket['email']); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-user me-1"></i>查看用户详情
                </a>
                <a href="orders.php?search=<?php echo urlencode($ticket['email']); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-shopping-bag me-1"></i>查看该用户订单
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
<script>
// Auto scroll chat to bottom
const chatBox = document.getElementById('adminChatBox');
if (chatBox) chatBox.scrollTop = chatBox.scrollHeight;

// Quick reply fill
function fillReply(btn) {
    const input = document.getElementById('adminReplyInput');
    if (input) {
        input.value = btn.textContent.trim();
        input.focus();
        updateCount();
    }
}

// Char counter
const replyInput = document.getElementById('adminReplyInput');
const charCount = document.getElementById('charCount');
function updateCount() {
    if (replyInput && charCount) charCount.textContent = replyInput.value.length + ' 字';
}
if (replyInput) replyInput.addEventListener('input', updateCount);

function disableSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>发送中...'; }
}
</script>
