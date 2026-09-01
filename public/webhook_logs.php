<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Http.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/UrlSafetyService.php';
I18n::init();

$db = Database::getInstance();
$db->autoMigrate();
$user_id = $_SESSION['user_id'];
$current_user = $db->fetch("SELECT plan_id FROM users WHERE id = ?", [$user_id]);
$plan = $db->fetch("SELECT allow_webhook_notice FROM plans WHERE id = ?", [$current_user['plan_id'] ?? 0]);
$allow_webhook_notice = (int)($plan['allow_webhook_notice'] ?? 1) === 1;

if (!$allow_webhook_notice) {
    flash_add('error', __('merchant.api.webhook.disabled_by_plan'));
    redirect_303('api_settings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry') {
    $order_id = (int)($_POST['order_id'] ?? 0);
    $order = $db->fetch("SELECT * FROM orders WHERE id = ? AND user_id = ?", [$order_id, $user_id]);

    if (!$order) {
        flash_add('error', __('merchant.webhook.retry_not_found'));
        redirect_303('webhook_logs.php');
    }

    if (($order['status'] ?? '') !== 'paid') {
        flash_add('error', __('merchant.webhook.retry_only_paid'));
        redirect_303('webhook_logs.php');
    }

    if (empty($order['notify_url'])) {
        $user = $db->fetch("SELECT webhook_url FROM users WHERE id = ?", [$user_id]);
        if (!empty($user['webhook_url']) && UrlSafetyService::isSafeUrl($user['webhook_url'])) {
            $order['notify_url'] = $user['webhook_url'];
            $db->query("UPDATE orders SET notify_url = ? WHERE id = ?", [$order['notify_url'], $order_id]);
        }
    }

    if (empty($order['notify_url'])) {
        flash_add('error', __('merchant.webhook.retry_no_target'));
        redirect_303('webhook_logs.php');
    }

    require_once __DIR__ . '/../src/Services/WebhookService.php';
    $ok = WebhookService::send($order);
    flash_add($ok ? 'success' : 'error', $ok ? __('merchant.webhook.retry_success') : __('merchant.webhook.retry_failed'));
    redirect_303('webhook_logs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_send') {
    $user_wh = $db->fetch("SELECT webhook_url, api_key FROM users WHERE id = ?", [$user_id]);
    $target_url = trim($user_wh['webhook_url'] ?? '');
    if (empty($target_url) || !filter_var($target_url, FILTER_VALIDATE_URL)) {
        flash_add('error', '未配置有效的 Webhook URL，请先在 API 设置中保存 Webhook 地址。');
        redirect_303('webhook_logs.php');
    }
    // Anti-SSRF: the manual test push is a merchant controlled outbound
    // request, so it goes through the same public-host check as the real
    // callback instead of trusting the stored value.
    $target_check = UrlSafetyService::inspect($target_url);
    if (!$target_check['ok']) {
        flash_add('error', __('merchant.api.webhook.blocked') . '（' . $target_check['error'] . '）');
        redirect_303('webhook_logs.php');
    }
    // Build test payload
    $test_order_no = 'TEST_' . strtoupper(bin2hex(random_bytes(4)));
    $payload = [
        'status'            => 'paid',
        'order_no'          => $test_order_no,
        'merchant_order_id' => 'MERCHANT_TEST_001',
        'amount'            => '0.01',
        'chain'             => 'trc20',
        'currency'          => 'USDT',
        'tx_hash'           => 'test_tx_hash_' . bin2hex(random_bytes(8)),
        'paid_at'           => date('c'),
        '_is_test'          => true,
    ];
    $apiKey = $user_wh['api_key'] ?? '';
    $timestamp = time();
    $bodyForSign = $test_order_no . '0.01' . 'MERCHANT_TEST_001' . $timestamp;
    $hmac = $apiKey ? hash_hmac('sha256', $bodyForSign, $apiKey) : '';
    $headers = ['Content-Type: application/json'];
    if ($hmac) {
        $headers[] = 'X-UAPI-Signature: ' . $hmac;
        $headers[] = 'X-UAPI-Timestamp: ' . $timestamp;
        $headers[] = 'X-UAPI-Event: order.paid';
        $headers[] = 'X-UAPI-Event-ID: test_' . bin2hex(random_bytes(4));
    }
    $ch = curl_init($target_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    UrlSafetyService::hardenCurlHandle($ch, $target_check, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'UAPI-Webhook/1.0');
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ok = ($code >= 200 && $code < 300) || (is_string($resp) && trim(strtolower($resp)) === 'success');
    flash_add($ok ? 'success' : 'error', $ok
        ? "测试 Webhook 发送成功（HTTP {$code}），请检查你的服务端是否收到 _is_test=true 的请求。"
        : "测试 Webhook 发送失败（HTTP {$code}），请检查 URL 是否可以正常接收 POST 请求。"
    );
    redirect_303('webhook_logs.php');
}

$flashes = flash_consume_all();

$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';
$page_title = __('merchant.webhook.page_title');
$per_page = 20;
$ro_page = max(1, (int)($_GET['ro_page'] ?? 1));
$log_page = max(1, (int)($_GET['log_page'] ?? 1));

$recent_total_row = $db->fetch(
    "SELECT COUNT(*) AS c
     FROM orders
     WHERE user_id = ? AND status = 'paid' AND notify_url IS NOT NULL AND notify_url <> ''",
    [$user_id]
);
$recent_total = (int)($recent_total_row['c'] ?? 0);
$recent_pages = max(1, (int)ceil($recent_total / $per_page));
if ($ro_page > $recent_pages) $ro_page = $recent_pages;
$recent_offset = ($ro_page - 1) * $per_page;

$recent_orders = $db->fetchAll(
    "SELECT id, order_no, merchant_order_id, notify_url, notify_status, notify_retries, last_notify_at, updated_at
     FROM orders
     WHERE user_id = ? AND status = 'paid' AND notify_url IS NOT NULL AND notify_url <> ''
     ORDER BY updated_at DESC
     LIMIT $per_page OFFSET $recent_offset",
    [$user_id]
);

$log_total_row = $db->fetch(
    "SELECT COUNT(*) AS c
     FROM webhook_logs wl
     INNER JOIN orders o ON o.id = wl.order_id
     WHERE o.user_id = ?",
    [$user_id]
);
$log_total = (int)($log_total_row['c'] ?? 0);
$log_pages = max(1, (int)ceil($log_total / $per_page));
if ($log_page > $log_pages) $log_page = $log_pages;
$log_offset = ($log_page - 1) * $per_page;

$logs = $db->fetchAll(
    "SELECT wl.*, o.order_no, o.merchant_order_id, o.notify_url
     FROM webhook_logs wl
     INNER JOIN orders o ON o.id = wl.order_id
     WHERE o.user_id = ?
     ORDER BY wl.id DESC
     LIMIT $per_page OFFSET $log_offset",
    [$user_id]
);
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
            <?php include __DIR__ . '/includes/user_topbar.php'; ?>

            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?php echo $f['type'] === 'error' ? 'danger' : ($f['type'] === 'success' ? 'success' : 'info'); ?>">
                    <?php echo htmlspecialchars($f['message']); ?>
                </div>
            <?php endforeach; ?>

            <div class="alert alert-info">
                <i class="fas fa-circle-info me-2"></i>
                <?php echo __('merchant.webhook.page_hint'); ?>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span><?php echo __('merchant.webhook.recent_orders'); ?></span>
                    <?php if ($allow_webhook_notice): ?>
                    <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#testWebhookModal">
                        <i class="fas fa-paper-plane me-1"></i>发送测试
                    </button>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4"><?php echo __('merchant.webhook.col.order_no'); ?></th>
                                <th><?php echo __('merchant.webhook.col.merchant_order'); ?></th>
                                <th><?php echo __('merchant.webhook.col.notify_url'); ?></th>
                                <th><?php echo __('merchant.webhook.col.status'); ?></th>
                                <th><?php echo __('merchant.webhook.col.retries'); ?></th>
                                <th><?php echo __('merchant.webhook.col.last_time'); ?></th>
                                <th class="text-end pe-4"><?php echo __('merchant.webhook.col.actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recent_orders)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4"><?php echo __('merchant.webhook.empty_orders'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_orders as $o): ?>
                                <tr>
                                    <td class="ps-4 font-monospace small"><?php echo htmlspecialchars($o['order_no']); ?></td>
                                    <td class="small"><?php echo htmlspecialchars($o['merchant_order_id']); ?></td>
                                    <td class="small text-truncate" style="max-width: 260px;"><?php echo htmlspecialchars($o['notify_url']); ?></td>
                                    <td>
                                        <?php $st = $o['notify_status'] ?: 'pending'; ?>
                                        <span class="badge <?php echo $st === 'success' ? 'bg-success' : ($st === 'failed' ? 'bg-danger' : 'bg-secondary'); ?>">
                                            <?php echo htmlspecialchars($st); ?>
                                        </span>
                                    </td>
                                    <td><?php echo (int)$o['notify_retries']; ?></td>
                                    <td class="small text-muted"><?php echo !empty($o['last_notify_at']) ? htmlspecialchars($o['last_notify_at']) : '-'; ?></td>
                                    <td class="text-end pe-4">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="retry">
                                            <input type="hidden" name="order_id" value="<?php echo (int)$o['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary"><?php echo __('merchant.webhook.retry_btn'); ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-white">
                    <small class="text-muted"><?php echo __('merchant.common.page_status', ['page' => (int)$ro_page, 'pages' => (int)$recent_pages, 'total' => (int)$recent_total]); ?></small>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-secondary <?php echo $ro_page <= 1 ? 'disabled' : ''; ?>" href="?ro_page=<?php echo max(1, $ro_page - 1); ?>&log_page=<?php echo (int)$log_page; ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                        <a class="btn btn-sm btn-outline-secondary <?php echo $ro_page >= $recent_pages ? 'disabled' : ''; ?>" href="?ro_page=<?php echo min($recent_pages, $ro_page + 1); ?>&log_page=<?php echo (int)$log_page; ?>"><?php echo __('merchant.common.next_page'); ?></a>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white fw-bold"><?php echo __('merchant.webhook.logs_title'); ?></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4"><?php echo __('merchant.webhook.col.time'); ?></th>
                                <th><?php echo __('merchant.webhook.col.order_no'); ?></th>
                                <th><?php echo __('merchant.webhook.col.code'); ?></th>
                                <th><?php echo __('merchant.webhook.col.response'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4"><?php echo __('merchant.webhook.empty_logs'); ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr>
                                    <td class="ps-4 small text-muted"><?php echo htmlspecialchars($l['created_at']); ?></td>
                                    <td class="small font-monospace"><?php echo htmlspecialchars($l['order_no']); ?></td>
                                    <td><span class="badge <?php echo ((int)$l['response_code'] >= 200 && (int)$l['response_code'] < 300) ? 'bg-success' : 'bg-danger'; ?>"><?php echo (int)$l['response_code']; ?></span></td>
                                    <td class="small text-truncate" style="max-width: 420px;"><?php echo htmlspecialchars((string)$l['response_body']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-white">
                    <small class="text-muted"><?php echo __('merchant.common.page_status', ['page' => (int)$log_page, 'pages' => (int)$log_pages, 'total' => (int)$log_total]); ?></small>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-secondary <?php echo $log_page <= 1 ? 'disabled' : ''; ?>" href="?ro_page=<?php echo (int)$ro_page; ?>&log_page=<?php echo max(1, $log_page - 1); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                        <a class="btn btn-sm btn-outline-secondary <?php echo $log_page >= $log_pages ? 'disabled' : ''; ?>" href="?ro_page=<?php echo (int)$ro_page; ?>&log_page=<?php echo min($log_pages, $log_page + 1); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- Test Webhook Modal -->
<div class="modal fade" id="testWebhookModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>发送测试 Webhook</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">将向你配置的 Webhook URL 发送一条带有 <code>_is_test: true</code> 标记的虚假支付通知，用于验证你的服务端是否能正常接收和解析回调数据。</p>
                <div class="alert alert-info small py-2">
                    <i class="fas fa-circle-info me-1"></i>
                    测试请求不会写入数据库，也不会影响任何真实订单。
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="action" value="test_send">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-paper-plane me-1"></i>立即发送测试
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
</body>
</html>
