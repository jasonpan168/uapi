<?php
session_start();
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Http.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$ticket_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($ticket_id <= 0) {
    header("Location: tickets.php");
    exit;
}

// Get Ticket
$ticket = $db->fetch("SELECT * FROM tickets WHERE id = ? AND user_id = ?", [$ticket_id, $user_id]);
if (!$ticket) {
    header("Location: tickets.php");
    exit;
}

// Handle Close by User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'close_by_user') {
    $db->query("UPDATE tickets SET status = 'closed', updated_at = NOW() WHERE id = ? AND user_id = ?", [$ticket_id, $user_id]);
    flash_add('success', '工单已关闭');
    redirect_303('ticket_view.php?id=' . $ticket_id);
    exit;
}

// Handle Reply (PRG)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reply') {
    $message = trim($_POST['message']);
    if (!empty($message)) {
        // Add Reply
        $db->query("INSERT INTO ticket_replies (ticket_id, user_id, message, is_admin) VALUES (?, ?, ?, 0)", 
            [$ticket_id, $user_id, $message]);
        
        // Update Ticket Status to Open (User replied, so admin needs to see it)
        $db->query("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?", [$ticket_id]);

        $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'message' => __('merchant.ticket_view.success.reply_sent'),
                'reply' => [
                    'is_admin' => 0,
                    'message' => $message,
                    'created_at' => date('Y-m-d H:i:s'),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        flash_add('success', __('merchant.ticket_view.success.reply_sent'));
        redirect_303('ticket_view.php?id=' . $ticket_id);
    }

    $isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'message' => __('merchant.ticket_view.error.empty_message'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Get Replies
$replies = $db->fetchAll("SELECT * FROM ticket_replies WHERE ticket_id = ? ORDER BY created_at ASC", [$ticket_id]);
$flashes = flash_consume_all();
$ticketTypeMeta = [
    'support' => ['label' => __('merchant.tickets.type.support'), 'class' => 'bg-primary-subtle text-primary'],
    'bug' => ['label' => __('merchant.tickets.type.bug'), 'class' => 'bg-danger-subtle text-danger'],
    'feature' => ['label' => __('merchant.tickets.type.feature'), 'class' => 'bg-warning-subtle text-warning-emphasis'],
    'payment' => ['label' => __('merchant.tickets.type.payment'), 'class' => 'bg-danger-subtle text-danger'],
    'account' => ['label' => __('merchant.tickets.type.account'), 'class' => 'bg-info-subtle text-info-emphasis'],
    'api' => ['label' => __('merchant.tickets.type.api'), 'class' => 'bg-secondary-subtle text-secondary-emphasis'],
    'withdrawal' => ['label' => __('merchant.tickets.type.withdrawal'), 'class' => 'bg-danger-subtle text-danger'],
    'other' => ['label' => __('merchant.tickets.type.other'), 'class' => 'bg-dark-subtle text-dark-emphasis'],
];
$ticketTypeKey = strtolower(trim((string)($ticket['ticket_type'] ?? 'support')));
if (!isset($ticketTypeMeta[$ticketTypeKey])) {
    $ticketTypeKey = 'support';
}
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
        .ticket-hero{border-radius:16px;background:linear-gradient(135deg,#eef2ff 0%,#f8fafc 100%);border:1px solid #e5e7eb;padding:24px}
        .ticket-title{font-weight:800}
        .status-chip{border-radius:999px;padding:6px 12px;font-weight:600}
        .chip-open{background:#ecfdf5;color:#065f46;border:1px solid #10b981}
        .chip-answered{background:#eff6ff;color:#1d4ed8;border:1px solid #3b82f6}
        .chip-closed{background:#fef2f2;color:#991b1b;border:1px solid #ef4444}
        .alert-top{border-radius:12px}
        .chat-box{height:46vh;min-height:300px;max-height:460px;overflow-y:auto;padding:18px;background:#ffffff;border:1px solid #e5e7eb;border-radius:16px}
        .message{display:flex;gap:12px;margin-bottom:16px}
        .avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700}
        .avatar-user{background:#dbeafe;color:#1e3a8a}
        .avatar-admin{background:#dcfce7;color:#14532d}
        .bubble{max-width:80%;padding:14px 16px;border-radius:14px;box-shadow:0 4px 10px rgba(0,0,0,0.04)}
        .bubble-user{background:#eff6ff;border:1px solid #bfdbfe}
        .bubble-admin{background:#f0fdf4;border:1px solid #bbf7d0}
        .meta{font-size:.75rem;color:#6b7280;margin-bottom:6px}
        [data-bs-theme="dark"] .ticket-hero{background:linear-gradient(135deg,#0f172a 0%,#111827 100%);border-color:#374151}
        [data-bs-theme="dark"] .chat-box{background:#111827;border-color:#374151}
        [data-bs-theme="dark"] .bubble-user{background:#1e3a8a33;border-color:#1d4ed8}
        [data-bs-theme="dark"] .bubble-admin{background:#14532d33;border-color:#16a34a}
        [data-bs-theme="dark"] .meta{color:#9ca3af}
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Content -->
        <div class="col-md-9 col-lg-10 main-content">
            
            <!-- Header -->
            <?php $page_title = __('merchant.ticket_view.title'); include __DIR__ . '/includes/user_topbar.php'; ?>
            
            <div class="container-fluid">
                <?php foreach ($flashes as $f): ?>
                    <div class="alert alert-<?php echo $f['type']==='error'?'danger':($f['type']==='success'?'success':($f['type']==='warning'?'warning':'info')); ?> alert-top">
                        <?php echo htmlspecialchars($f['message']); ?>
                    </div>
                <?php endforeach; ?>
                
                <div class="ticket-hero mb-4">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <a href="tickets.php" class="text-decoration-none text-muted d-inline-block mb-2"><i class="fas fa-arrow-left me-1"></i> <?php echo __('merchant.ticket_view.back_to_list'); ?></a>
                            <div class="d-flex align-items-center gap-3">
                                <h2 class="ticket-title mb-0">#<?php echo $ticket['id']; ?> <?php echo htmlspecialchars($ticket['subject']); ?></h2>
                                <?php if($ticket['status'] == 'open'): ?>
                                    <span class="status-chip chip-open"><?php echo __('merchant.tickets.status.open'); ?></span>
                                <?php elseif($ticket['status'] == 'answered'): ?>
                                    <span class="status-chip chip-answered"><?php echo __('merchant.tickets.status.answered'); ?></span>
                                <?php else: ?>
                                    <span class="status-chip chip-closed"><?php echo __('merchant.tickets.status.closed'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small mt-2"><?php echo __('merchant.ticket_view.created_at'); ?> <?php echo date('Y-m-d H:i', strtotime($ticket['created_at'])); ?> • <?php echo __('merchant.ticket_view.updated_at'); ?> <?php echo date('Y-m-d H:i', strtotime($ticket['updated_at'])); ?></div>
                        </div>
                        <?php if($ticket['status'] != 'closed'): ?>
                        <form method="POST" onsubmit="return confirm('确定关闭此工单？关闭后可重新回复以开启。')">
                            <input type="hidden" name="action" value="close_by_user">
                            <button type="submit" class="btn btn-outline-danger"><?php echo __('merchant.ticket_view.close_ticket'); ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php if($ticket['status'] == 'closed'): ?>
                        <div class="alert alert-danger mt-3 alert-top d-flex align-items-center gap-2 mb-0">
                            <i class="fas fa-lock"></i>
                            <div><?php echo __('merchant.ticket_view.closed_hint'); ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="row">
                    <div class="col-lg-8">
                        <div class="chat-box mb-3" id="ticketChatBox">
                            <?php foreach($replies as $r): ?>
                                <div class="message <?php echo $r['is_admin'] ? '' : 'flex-row-reverse'; ?>">
                                    <div class="avatar <?php echo $r['is_admin'] ? 'avatar-admin' : 'avatar-user'; ?>">
                                        <?php echo $r['is_admin'] ? 'A' : 'U'; ?>
                                    </div>
                                    <div style="max-width:calc(100% - 48px);">
                                        <div class="meta"><?php echo $r['is_admin'] ? __('merchant.ticket_view.support') : __('merchant.ticket_view.me'); ?> • <?php echo date('m-d H:i', strtotime($r['created_at'])); ?></div>
                                        <div class="bubble <?php echo $r['is_admin'] ? 'bubble-admin' : 'bubble-user'; ?>">
                                            <?php echo nl2br(htmlspecialchars($r['message'])); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if($ticket['status'] != 'closed'): ?>
                        <div class="card border shadow-sm">
                            <div class="card-body">
                                <form method="POST" id="ticketReplyForm" onsubmit="return submitReplyAjax(this)">
                                    <input type="hidden" name="action" value="reply">
                                    <div class="mb-3">
                                        <textarea name="message" id="ticketReplyInput" class="form-control" rows="3" placeholder="<?php echo __('merchant.ticket_view.reply_placeholder'); ?>" required></textarea>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center gap-2">
                                        <small id="ticketReplyHint" class="text-muted"></small>
                                        <button type="submit" class="btn btn-primary" id="ticketReplyBtn"><i class="fas fa-paper-plane me-2"></i> <?php echo __('merchant.ticket_view.send_reply'); ?></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="col-lg-4">
                        <div class="card border-0 shadow-sm mb-4">
                            <div class="card-header bg-white fw-bold"><?php echo __('merchant.ticket_view.info_title'); ?></div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="text-muted small mb-1"><?php echo __('merchant.ticket_view.type'); ?></div>
                                    <div class="fw-bold">
                                        <span class="badge <?php echo $ticketTypeMeta[$ticketTypeKey]['class']; ?>">
                                            <?php echo $ticketTypeMeta[$ticketTypeKey]['label']; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-muted small mb-1"><?php echo __('merchant.ticket_view.ticket_id'); ?></div>
                                    <div class="fw-bold">#<?php echo $ticket['id']; ?></div>
                                </div>
                                <div class="mb-3">
                                    <div class="text-muted small mb-1"><?php echo __('merchant.ticket_view.created_at'); ?></div>
                                    <div class="fw-bold"><?php echo date('Y-m-d H:i', strtotime($ticket['created_at'])); ?></div>
                                </div>
                                <div class="mb-1">
                                    <div class="text-muted small mb-1"><?php echo __('merchant.ticket_view.updated_at'); ?></div>
                                    <div class="fw-bold"><?php echo date('Y-m-d H:i', strtotime($ticket['updated_at'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function appendUserReply(message, createdAt) {
    const chat = document.getElementById('ticketChatBox');
    if (!chat) return;
    const wrapper = document.createElement('div');
    wrapper.className = 'message flex-row-reverse';
    wrapper.innerHTML = `
        <div class="avatar avatar-user">U</div>
        <div style="max-width:calc(100% - 48px);">
            <div class="meta"><?php echo jsesc(__('merchant.ticket_view.me')); ?> • ${createdAt}</div>
            <div class="bubble bubble-user"></div>
        </div>
    `;
    wrapper.querySelector('.bubble').textContent = message;
    chat.appendChild(wrapper);
    chat.scrollTop = chat.scrollHeight;
}

async function submitReplyAjax(form) {
    const btn = document.getElementById('ticketReplyBtn');
    const input = document.getElementById('ticketReplyInput');
    const hint = document.getElementById('ticketReplyHint');
    if (!input || !btn) return false;
    const msg = input.value.trim();
    if (!msg) {
        if (hint) hint.textContent = <?php echo json_encode(__('merchant.ticket_view.error.empty_message')); ?>;
        return false;
    }
    btn.disabled = true;
    const rawText = btn.innerText;
    btn.innerText = <?php echo json_encode(__('merchant.ticket_view.sending')); ?>;
    if (hint) hint.textContent = '';

    try {
        const formData = new FormData(form);
        const resp = await fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        let data = null;
        const raw = await resp.text();
        try { data = JSON.parse(raw); } catch (_) { data = null; }
        if (!resp.ok || !data || !data.ok) {
            throw new Error((data && data.message) ? data.message : <?php echo json_encode(__('merchant.ticket_view.error.send_failed')); ?>);
        }
        appendUserReply(msg, (data.reply && data.reply.created_at) ? data.reply.created_at.slice(5,16).replace('T',' ') : '');
        input.value = '';
        if (hint) hint.textContent = data.message || <?php echo json_encode(__('merchant.ticket_view.success.sent_short')); ?>;
    } catch (e) {
        if (hint) hint.textContent = e.message || <?php echo json_encode(__('merchant.ticket_view.error.send_failed')); ?>;
    } finally {
        btn.disabled = false;
        btn.innerText = rawText;
    }
    return false;
}
</script>
</body>
</html>
