<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Helper.php';
require_once __DIR__ . '/../../src/Services/TotpService.php';
require_once __DIR__ . '/../../src/Services/User2FAService.php';
$db = Database::getInstance();

// Admin 2FA check for broadcast scene
$_bcAdminId   = (int)($_SESSION['user_id'] ?? 0);
$_bcAdmin     = $db->fetch("SELECT two_factor_enabled, two_factor_secret, two_factor_scenes FROM users WHERE id=? AND role='admin' LIMIT 1", [$_bcAdminId]);
$_bcScene     = $_bcAdmin ? User2FAService::isSceneEnabled((array)$_bcAdmin, 'admin_broadcast') : false;

$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';

$message = '';
$error = '';

// Handle Send
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        $error = 'CSRF 校验失败';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'send_broadcast') {
            // 2FA guard for admin_broadcast scene
            if ($_bcScene) {
                $bcOtp = trim($_POST['bc_otp_code'] ?? '');
                [$ok2fa, $err2fa] = User2FAService::verifyForScene((array)$_bcAdmin, 'admin_broadcast', $bcOtp);
                if (!$ok2fa) {
                    $error = '谷歌验证码错误，邮件群发需要动态码验证：' . $err2fa;
                    goto broadcast_done;
                }
            }

            $subject    = trim($_POST['subject'] ?? '');
            $body_html  = trim($_POST['body_html'] ?? '');
            $target     = $_POST['target'] ?? 'all';
            $plan_id    = (int)($_POST['plan_id'] ?? 0);

            if (empty($subject) || empty($body_html)) {
                $error = '主题和正文不能为空';
            } else {
                // Build recipient query
                $where = "status = 'active' AND email IS NOT NULL AND email <> ''";
                $params = [];
                if ($target === 'plan' && $plan_id > 0) {
                    $where .= " AND plan_id = ?";
                    $params[] = $plan_id;
                } elseif ($target === 'free') {
                    $where .= " AND plan_id <= 1";
                } elseif ($target === 'paid') {
                    $where .= " AND plan_id > 1";
                } elseif ($target === 'expiring') {
                    $where .= " AND plan_id > 1 AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
                }
                $recipients = $db->fetchAll("SELECT id, email FROM users WHERE $where LIMIT 500", $params);
                $total = count($recipients);

                if ($total === 0) {
                    $error = '没有符合条件的收件人';
                } else {
                    // Log the broadcast
                    $db->query(
                        "INSERT INTO broadcast_logs (subject, body, target, recipient_count, status) VALUES (?, ?, ?, ?, 'sending')",
                        [$subject, $body_html, $target . ($plan_id > 0 ? ':' . $plan_id : ''), $total]
                    );
                    $log_id = $db->lastInsertId();

                    // Send emails
                    $sent = 0;
                    // Try to load Mailer
                    $mailerAvailable = false;
                    try {
                        require_once __DIR__ . '/../../src/Helper.php';
                        require_once __DIR__ . '/../../src/Env.php';
                        require_once __DIR__ . '/../../src/Mailer.php';
                        $mailerAvailable = true;
                    } catch (Throwable $e) {}

                    foreach ($recipients as $r) {
                        if (!$mailerAvailable) break;
                        try {
                            Mailer::send($r['email'], $subject, $body_html);
                            $sent++;
                        } catch (Throwable $e) {
                            // Continue to next recipient
                        }
                        usleep(50000); // 50ms delay to avoid SMTP flood
                    }

                    $db->query(
                        "UPDATE broadcast_logs SET sent_count = ?, status = 'completed', completed_at = NOW() WHERE id = ?",
                        [$sent, $log_id]
                    );
                    $message = "发送完成：共 {$total} 位收件人，成功发送 {$sent} 封。";
                }
            }
        }
        broadcast_done:;
    }
}

// Load plans for filter
$plans = $db->fetchAll("SELECT id, name FROM plans ORDER BY id ASC");

// Recipient preview based on current filter
$preview_target  = $_GET['target']  ?? 'all';
$preview_plan_id = (int)($_GET['plan_id'] ?? 0);
$pw = "status = 'active' AND email IS NOT NULL AND email <> ''";
$pp = [];
if ($preview_target === 'plan' && $preview_plan_id > 0) { $pw .= " AND plan_id = ?"; $pp[] = $preview_plan_id; }
elseif ($preview_target === 'free') { $pw .= " AND plan_id <= 1"; }
elseif ($preview_target === 'paid') { $pw .= " AND plan_id > 1"; }
elseif ($preview_target === 'expiring') { $pw .= " AND plan_id > 1 AND expire_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"; }
$preview_count_row = $db->fetch("SELECT COUNT(*) AS c FROM users WHERE $pw", $pp);
$preview_count = (int)($preview_count_row['c'] ?? 0);

// Recent broadcast logs
$broadcast_logs = $db->fetchAll("SELECT * FROM broadcast_logs ORDER BY id DESC LIMIT 20");

$active_menu = 'broadcast';
require_once 'includes/header.php';
?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-times-circle me-2"></i><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Send Form -->
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold"><i class="fas fa-envelope me-2 text-primary"></i>发送邮件通知</div>
            <div class="card-body">
                <form method="POST" onsubmit="return confirmSend()">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                    <input type="hidden" name="action" value="send_broadcast">

                    <!-- Target -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">收件人筛选</label>
                        <div class="row g-2">
                            <div class="col-md-5">
                                <select name="target" class="form-select" id="targetSelect" onchange="updatePreview()">
                                    <option value="all" <?php echo $preview_target==='all'?'selected':''; ?>>全部活跃用户</option>
                                    <option value="free" <?php echo $preview_target==='free'?'selected':''; ?>>免费计划用户</option>
                                    <option value="paid" <?php echo $preview_target==='paid'?'selected':''; ?>>付费计划用户</option>
                                    <option value="expiring" <?php echo $preview_target==='expiring'?'selected':''; ?>>7天内到期用户</option>
                                    <option value="plan" <?php echo $preview_target==='plan'?'selected':''; ?>>指定套餐</option>
                                </select>
                            </div>
                            <div class="col-md-4" id="planSelectWrap" style="display:<?php echo $preview_target==='plan'?'block':'none'; ?>;">
                                <select name="plan_id" class="form-select" onchange="updatePreview()">
                                    <option value="0">-- 选择套餐 --</option>
                                    <?php foreach ($plans as $pl): ?>
                                    <option value="<?php echo (int)$pl['id']; ?>" <?php echo $preview_plan_id==(int)$pl['id']?'selected':''; ?>><?php echo htmlspecialchars($pl['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-center">
                                <span class="badge bg-primary fs-6" id="previewCount"><?php echo $preview_count; ?> 位收件人</span>
                            </div>
                        </div>
                    </div>

                    <!-- Subject -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">邮件主题 <span class="text-danger">*</span></label>
                        <input type="text" name="subject" class="form-control" required placeholder="例：平台功能更新通知" maxlength="200">
                    </div>

                    <!-- Body -->
                    <div class="mb-3">
                        <label class="form-label fw-bold">邮件正文（HTML）<span class="text-danger">*</span></label>
                        <textarea name="body_html" class="form-control font-monospace" rows="10" required
                            placeholder="<p>您好，</p><p>感谢您使用我们的服务...</p>"></textarea>
                        <div class="form-text">支持 HTML 格式。请注意邮件兼容性，避免使用复杂 CSS。</div>
                    </div>

                    <div class="alert alert-warning small py-2">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        发送前请确认收件人筛选条件和邮件内容。一旦发送无法撤回。最多支持 500 位收件人/次。
                    </div>

                    <?php if ($_bcScene): ?>
                    <div class="mb-3 d-flex align-items-center gap-2 p-3 rounded" style="background:#eff6ff;border:1px solid #bfdbfe;">
                        <i class="fas fa-shield-halved text-primary"></i>
                        <label class="fw-semibold small mb-0 flex-shrink-0" style="white-space:nowrap;">谷歌验证码 <span class="text-danger">*</span></label>
                        <input name="bc_otp_code" class="form-control form-control-sm" style="max-width:140px;font-family:monospace;font-size:16px;letter-spacing:.12em;text-align:center;" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
                        <span class="small text-muted">发送群发邮件需要 2FA 验证</span>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-1"></i>发送邮件</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Tips -->
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-bold">使用说明</div>
            <div class="card-body">
                <ul class="mb-0 ps-3 small text-muted">
                    <li class="mb-2">发送前请先在"收件人筛选"处预览收件人数量，确认无误后再发送。</li>
                    <li class="mb-2">每次最多发送 500 封，如需更多请分批次发送。</li>
                    <li class="mb-2">系统使用平台 SMTP 设置发送邮件，请确保 <a href="settings.php" class="text-primary">系统设置</a> 中邮件配置正确。</li>
                    <li class="mb-2">正文支持 HTML，建议测试后再批量发送。</li>
                    <li>发送记录会保存在下方历史列表中。</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- History -->
<div class="card shadow-sm">
    <div class="card-header bg-white fw-bold"><i class="fas fa-history me-2"></i>发送历史（最近20条）</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th class="ps-4">发送时间</th>
                    <th>主题</th>
                    <th>目标</th>
                    <th>收件人</th>
                    <th>成功</th>
                    <th>状态</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($broadcast_logs)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">暂无发送记录</td></tr>
            <?php else: ?>
                <?php foreach ($broadcast_logs as $bl): ?>
                <tr>
                    <td class="ps-4 small text-muted"><?php echo htmlspecialchars($bl['created_at']); ?></td>
                    <td class="small fw-bold"><?php echo htmlspecialchars(mb_substr($bl['subject'], 0, 40)); ?></td>
                    <td class="small text-muted"><?php echo htmlspecialchars($bl['target']); ?></td>
                    <td><?php echo (int)$bl['recipient_count']; ?></td>
                    <td class="<?php echo (int)$bl['sent_count'] === (int)$bl['recipient_count'] ? 'text-success' : 'text-warning'; ?> fw-bold"><?php echo (int)$bl['sent_count']; ?></td>
                    <td><span class="badge <?php echo $bl['status']==='completed'?'bg-success':($bl['status']==='sending'?'bg-warning text-dark':'bg-secondary'); ?>"><?php echo htmlspecialchars($bl['status']); ?></span></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-4 text-center text-muted small">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?> Admin Panel.</div>

<script>
function confirmSend() {
    const cnt = document.getElementById('previewCount').textContent;
    return confirm('确认发送？将向 ' + cnt + ' 发送邮件，此操作不可撤销。');
}

function updatePreview() {
    const target = document.getElementById('targetSelect').value;
    const planWrap = document.getElementById('planSelectWrap');
    planWrap.style.display = target === 'plan' ? 'block' : 'none';
    const planId = document.querySelector('select[name="plan_id"]')?.value || 0;
    const url = '?target=' + encodeURIComponent(target) + '&plan_id=' + planId;
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(() => { window.location.href = url; });
}
</script>
<?php require_once 'includes/footer.php'; ?>
