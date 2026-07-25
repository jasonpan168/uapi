<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/CryptoService.php';
require_once __DIR__ . '/../src/Helper.php';
I18n::init();

$db = Database::getInstance();
$db->autoMigrate();
$user_id = $_SESSION['user_id'];

// Site Info
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';
$page_title = __('merchant.api.title');

$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
$plan = $db->fetch("SELECT allow_webhook_notice FROM plans WHERE id = ?", [$user['plan_id'] ?? 0]);
$allow_webhook_notice = (int)($plan['allow_webhook_notice'] ?? 1) === 1;

$max_websites = 1;
$allowed_categories = ['ecommerce','gaming','service','digital','other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Helper::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        header("Location: api_settings.php?msg=csrf_invalid");
        exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_key') {
        $new_key = 'sk_' . bin2hex(random_bytes(24));
        $db->query("UPDATE users SET api_key = ? WHERE id = ?", [$new_key, $user_id]);
        header("Location: api_settings.php?msg=reset_success");
        exit;
    }

    if ($action === 'save_webhook') {
        if (!$allow_webhook_notice) {
            header("Location: api_settings.php?msg=webhook_disabled");
            exit;
        }
        $webhook_url = trim($_POST['webhook_url'] ?? '');
        if ($webhook_url !== '' && !filter_var($webhook_url, FILTER_VALIDATE_URL)) {
            header("Location: api_settings.php?msg=webhook_invalid");
            exit;
        }
        $db->query("UPDATE users SET webhook_url = ? WHERE id = ?", [$webhook_url ?: null, $user_id]);
        header("Location: api_settings.php?msg=webhook_saved");
        exit;
    }

    if ($action === 'add_website') {
        $domain = trim($_POST['domain'] ?? '');
        $category = trim($_POST['category'] ?? 'other');

        $domain = preg_replace('#^https?://#i', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        $domain = rtrim($domain, '/');
        $domain = preg_replace('/^www\./i', '', $domain);
        $domain = strtolower($domain);

        if ($domain === '' || !preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
            header("Location: api_settings.php?msg=website_invalid");
            exit;
        }
        $existsByOther = $db->fetch(
            "SELECT id FROM websites WHERE domain = ? AND user_id <> ? LIMIT 1",
            [$domain, $user_id]
        );
        if ($existsByOther) {
            header("Location: api_settings.php?msg=website_taken");
            exit;
        }

        $website_total_row = $db->fetch("SELECT COUNT(*) AS c FROM websites WHERE user_id = ?", [$user_id]);
        if ((int)($website_total_row['c'] ?? 0) >= $max_websites) {
            header("Location: api_settings.php?msg=website_limit");
            exit;
        }
        if (!in_array($category, $allowed_categories, true)) {
            $category = 'other';
        }

        try {
            $db->query("INSERT INTO websites (user_id, domain, category) VALUES (?, ?, ?)", [$user_id, $domain, $category]);
            header("Location: api_settings.php?msg=website_saved");
        } catch (Exception $e) {
            $err = strtolower($e->getMessage());
            if (strpos($err, 'duplicate') !== false || strpos($err, 'uniq_domain_global') !== false || strpos($err, '23000') !== false) {
                header("Location: api_settings.php?msg=website_taken");
            } else {
                header("Location: api_settings.php?msg=website_failed");
            }
        }
        exit;
    }

    if ($action === 'delete_website') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            header("Location: api_settings.php?msg=website_not_found");
            exit;
        }
        $db->query("DELETE FROM websites WHERE id = ? AND user_id = ?", [$id, $user_id]);
        header("Location: api_settings.php?msg=website_removed");
        exit;
    }

    if ($action === 'add_ip_whitelist') {
        $ip_input = trim($_POST['ip_address'] ?? '');
        $label = substr(trim($_POST['label'] ?? ''), 0, 100);
        // Validate IPv4 or IPv6
        if (!filter_var($ip_input, FILTER_VALIDATE_IP)) {
            header("Location: api_settings.php?clip=security&msg=ip_invalid");
            exit;
        }
        try {
            $db->query(
                "INSERT INTO api_ip_whitelist (user_id, ip_address, label) VALUES (?, ?, ?)",
                [$user_id, $ip_input, $label ?: null]
            );
            header("Location: api_settings.php?clip=security&msg=ip_added");
        } catch (Exception $e) {
            header("Location: api_settings.php?clip=security&msg=ip_exists");
        }
        exit;
    }

    if ($action === 'delete_ip_whitelist') {
        $id = (int)($_POST['id'] ?? 0);
        $db->query("DELETE FROM api_ip_whitelist WHERE id = ? AND user_id = ?", [$id, $user_id]);
        header("Location: api_settings.php?clip=security&msg=ip_removed");
        exit;
    }
}

$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
$ws_page = max(1, (int)($_GET['ws_page'] ?? 1));
$ws_per_page = 10;
$count = (int)($db->fetch("SELECT COUNT(*) AS c FROM websites WHERE user_id = ?", [$user_id])['c'] ?? 0);
$ws_total_pages = max(1, (int)ceil($count / $ws_per_page));
$ws_page = min($ws_page, $ws_total_pages);
$ws_offset = ($ws_page - 1) * $ws_per_page;
$websites = $db->fetchAll("SELECT * FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT $ws_per_page OFFSET $ws_offset", [$user_id]);

try {
    $db->query("CREATE TABLE IF NOT EXISTS api_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        endpoint VARCHAR(255) NOT NULL,
        method VARCHAR(20) NOT NULL,
        chain VARCHAR(20) DEFAULT '',
        ip_address VARCHAR(64) DEFAULT '',
        status_code INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_created (user_id, created_at),
        INDEX idx_method (method)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $ignore) {
}
CryptoService::ensureApiRequestSchema();
$log_total = CryptoService::getMerchantBillableRequestCount((int)$user_id);
$log_today_total = CryptoService::getMerchantBillableRequestCount((int)$user_id, date('Y-m-d'));
$today_external_calls = $log_today_total;
$today_order_call_count = CryptoService::getMerchantBillableOrderCount((int)$user_id, date('Y-m-d'));

$plan_limit_row = $db->fetch(
    "SELECT p.api_limit_daily
     FROM users u
     LEFT JOIN plans p ON p.id = u.plan_id
     WHERE u.id = ?
     LIMIT 1",
    [$user_id]
);
$plan_api_limit_daily = (int)($plan_limit_row['api_limit_daily'] ?? 0);
$remaining_api_calls = $plan_api_limit_daily > 0
    ? max(0, $plan_api_limit_daily - $today_external_calls)
    : -1;
// Load IP whitelist for current user
try {
    $ip_whitelist = $db->fetchAll("SELECT * FROM api_ip_whitelist WHERE user_id = ? ORDER BY id DESC", [$user_id]);
} catch (Throwable $e) {
    $ip_whitelist = [];
}
$current_ip = $_SERVER['REMOTE_ADDR'] ?? '';

$clip = trim((string)($_GET['clip'] ?? 'domain'));
if (!in_array($clip, ['domain', 'docs', 'logs', 'security'], true)) {
    $clip = 'domain';
}

$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api/v1';
$msg = $_GET['msg'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
        .hero-card {
            border: 1px solid #dbe3ee;
            border-radius: 18px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.05);
        }
        .section-card {
            border: 1px solid #e6edf5;
            border-radius: 16px;
            background: #ffffff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
        }
        .section-title {
            font-size: 0.95rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 10px;
        }
        .api-key-box {
            background: #0f172a;
            color: #60a5fa;
            padding: 12px 14px;
            border-radius: 12px;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 0.92rem;
            word-break: break-all;
            border: 1px solid #1e293b;
            line-height: 1.4;
        }
        .code-wrap {
            background: #0b1220;
            color: #e2e8f0;
            border-radius: 14px;
            border: 1px solid #1e293b;
            position: relative;
            overflow: hidden;
        }
        .code-header {
            padding: 10px 14px;
            border-bottom: 1px solid #1e293b;
            background: #111a2b;
            font-size: 12px;
            color: #94a3b8;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .code-body {
            padding: 14px;
            margin: 0;
            white-space: pre;
            overflow-x: auto;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 12.5px;
            line-height: 1.6;
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 6px 12px;
            background: #eff6ff;
            color: #1d4ed8;
            font-size: 12px;
            font-weight: 700;
        }
        .soft-alert {
            border-radius: 12px;
            padding: 12px 14px;
            border: 1px solid #dbeafe;
            background: #f8fbff;
            color: #1e40af;
            font-size: 13px;
        }
        .table td, .table th { vertical-align: middle; }
        .clip-combo {
            border: 1px solid #e6edf5;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .clip-pane {
            padding: 18px;
            height: 100%;
        }
        .clip-pane + .clip-pane {
            border-left: 1px dashed #d7e2f2;
            background: linear-gradient(180deg, #fbfdff 0%, #ffffff 100%);
        }
        .api-clip-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 16px;
            margin-bottom: 12px;
        }
        .api-clip-btn {
            border: 1px solid #dbe3ee;
            background: #fff;
            color: #334155;
            border-radius: 999px;
            padding: 8px 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .api-clip-btn.active {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }
        .api-clip-panel { display: none; }
        .api-clip-panel.active { display: block; }
        .stat-mini {
            border: 1px solid #e5edf7;
            border-radius: 12px;
            background: #f8fbff;
            padding: 12px 14px;
        }
        .stat-mini .label {
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .stat-mini .value {
            color: #0f172a;
            font-size: 22px;
            font-weight: 800;
            line-height: 1;
        }
        @media (max-width: 991.98px) {
            .clip-pane + .clip-pane {
                border-left: 0;
                border-top: 1px dashed #d7e2f2;
            }
        }
        [data-bs-theme="dark"] .hero-card {
            border-color: #374151;
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);
        }
        [data-bs-theme="dark"] .section-card,
        [data-bs-theme="dark"] .clip-combo,
        [data-bs-theme="dark"] .stat-mini {
            border-color: #374151 !important;
            background: #1f2937 !important;
            color: #e5e7eb !important;
            box-shadow: none;
        }
        [data-bs-theme="dark"] .clip-pane + .clip-pane {
            border-left-color: #374151;
            background: linear-gradient(180deg, #111827 0%, #1f2937 100%);
        }
        [data-bs-theme="dark"] .section-title,
        [data-bs-theme="dark"] .label,
        [data-bs-theme="dark"] .text-muted,
        [data-bs-theme="dark"] .text-secondary {
            color: #9ca3af !important;
        }
        [data-bs-theme="dark"] .stat-mini .value {
            color: #f9fafb !important;
        }
        [data-bs-theme="dark"] .soft-alert {
            border-color: #1d4ed8;
            background: rgba(37, 99, 235, 0.12);
            color: #bfdbfe;
        }
        [data-bs-theme="dark"] .api-clip-btn {
            border-color: #374151;
            background: #1f2937;
            color: #e5e7eb;
        }
        [data-bs-theme="dark"] .api-clip-btn.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }
        [data-bs-theme="dark"] .table td,
        [data-bs-theme="dark"] .table th {
            border-color: #374151 !important;
            color: #e5e7eb !important;
        }
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.api.title'); include __DIR__ . '/includes/user_topbar.php'; ?>

            <?php if ($msg === 'reset_success'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.reset_success'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'webhook_saved'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.webhook.saved'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'webhook_invalid'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.webhook.invalid'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'webhook_disabled'): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.webhook.disabled_by_plan'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'website_saved'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.website.saved'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'website_removed'): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.website.removed'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'website_invalid'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.website.invalid'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'website_limit'): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.website.limit_reached'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'website_taken'): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.website.taken'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'website_failed'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.website.failed'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php elseif ($msg === 'website_not_found'): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo __('merchant.api.website.not_found'); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-4">
                <div class="col-12">
                    <div class="hero-card p-4">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <div>
                                <div class="section-title mb-1"><?php echo __('merchant.api.title'); ?></div>
                                <h4 class="mb-1 fw-bold"><?php echo __('merchant.api.secret_key'); ?></h4>
                                <p class="text-muted mb-0 small"><?php echo __('merchant.api.secret_desc'); ?></p>
                            </div>
                            <span class="chip"><i class="fas fa-shield-halved"></i> API Security</span>
                        </div>

                        <div class="d-flex align-items-center gap-2">
                            <div class="api-key-box flex-grow-1" id="apiKeyDisplay"><?php echo htmlspecialchars($user['api_key']); ?></div>
                            <button class="btn btn-outline-primary" type="button" onclick="copyKey()" title="copy">
                                <i class="fas fa-copy me-1"></i><?php echo __('merchant.api.code.copy'); ?>
                            </button>
                            <form method="POST" class="m-0" onsubmit="return confirm(<?php echo json_encode(__('merchant.api.reset_confirm')); ?>);">
                                <input type="hidden" name="action" value="reset_key">
                                <?php echo Helper::csrfField(); ?>
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="fas fa-rotate me-1"></i><?php echo __('merchant.api.reset_key'); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="api-clip-tabs" id="apiClipTabs">
                        <button type="button" class="api-clip-btn <?php echo $clip === 'domain' ? 'active' : ''; ?>" data-clip-target="domain">
                            <i class="fas fa-link me-1"></i> <?php echo __('merchant.api.tab.domain_webhook'); ?>
                        </button>
                        <button type="button" class="api-clip-btn <?php echo $clip === 'docs' ? 'active' : ''; ?>" data-clip-target="docs">
                            <i class="fas fa-code me-1"></i> <?php echo __('merchant.api.tab.docs'); ?>
                        </button>
                        <button type="button" class="api-clip-btn <?php echo $clip === 'logs' ? 'active' : ''; ?>" data-clip-target="logs">
                            <i class="fas fa-chart-line me-1"></i> <?php echo __('merchant.api.tab.logs'); ?>
                        </button>
                        <button type="button" class="api-clip-btn <?php echo $clip === 'security' ? 'active' : ''; ?>" data-clip-target="security">
                            <i class="fas fa-shield-halved me-1"></i> IP 白名单
                        </button>
                    </div>

                    <div class="api-clip-panel <?php echo $clip === 'domain' ? 'active' : ''; ?>" data-clip-panel="domain">
                        <div class="clip-combo" id="domain-binding">
                            <div class="row g-0">
                                <div class="col-lg-8">
                                    <div class="clip-pane">
                                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                            <h5 class="fw-bold mb-0"><?php echo __('merchant.api.website.title'); ?></h5>
                                            <span class="text-muted small"><?php echo __('merchant.api.website.limit', ['count' => $max_websites]); ?></span>
                                        </div>
                                        <p class="text-muted small mb-3"><?php echo __('merchant.api.website.desc'); ?></p>

                                        <div class="table-responsive mb-3">
                                            <table class="table table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th><?php echo __('merchant.websites.table.domain'); ?></th>
                                                        <th><?php echo __('merchant.websites.table.category'); ?></th>
                                                        <th><?php echo __('merchant.websites.table.status'); ?></th>
                                                        <th><?php echo __('merchant.websites.table.bound_at'); ?></th>
                                                        <th class="text-end"><?php echo __('merchant.websites.table.actions'); ?></th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($websites)): ?>
                                                        <tr><td colspan="5" class="text-center text-muted py-4"><?php echo __('merchant.websites.no_sites'); ?></td></tr>
                                                    <?php else: ?>
                                                        <?php foreach ($websites as $w): ?>
                                                            <?php
                                                                $category_key = sprintf('merchant.websites.category.%s', $w['category']);
                                                                $category_label = __($category_key);
                                                                if ($category_label === $category_key) {
                                                                    $category_label = $w['category'];
                                                                }
                                                            ?>
                                                            <tr>
                                                                <td class="fw-bold"><?php echo htmlspecialchars($w['domain']); ?></td>
                                                                <td><span class="badge bg-secondary bg-opacity-10 text-secondary"><?php echo htmlspecialchars($category_label); ?></span></td>
                                                                <td>
                                                                    <?php if (($w['status'] ?? 'active') === 'active'): ?>
                                                                        <span class="badge bg-success"><?php echo __('merchant.websites.status.active'); ?></span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-danger"><?php echo __('merchant.websites.status.blocked'); ?></span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td class="small text-muted"><?php echo htmlspecialchars($w['created_at']); ?></td>
                                                                <td class="text-end">
                                                                    <form method="POST" class="d-inline" onsubmit="return confirm(<?php echo json_encode(__('merchant.websites.confirm_unbind')); ?>);">
                                                                        <input type="hidden" name="action" value="delete_website">
                                                                        <?php echo Helper::csrfField(); ?>
                                                                        <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
                                                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <small class="text-muted"><?php echo __('merchant.common.total_count', ['count' => $count]); ?></small>
                                            <div class="btn-group btn-group-sm">
                                                <a class="btn btn-outline-secondary <?php echo $ws_page <= 1 ? 'disabled' : ''; ?>" href="?clip=domain&ws_page=<?php echo max(1, $ws_page - 1); ?>#domain-binding"><?php echo __('merchant.common.prev_page'); ?></a>
                                                <span class="btn btn-light disabled"><?php echo $ws_page; ?> / <?php echo $ws_total_pages; ?></span>
                                                <a class="btn btn-outline-secondary <?php echo $ws_page >= $ws_total_pages ? 'disabled' : ''; ?>" href="?clip=domain&ws_page=<?php echo min($ws_total_pages, $ws_page + 1); ?>#domain-binding"><?php echo __('merchant.common.next_page'); ?></a>
                                            </div>
                                        </div>

                                        <?php if ($count < $max_websites): ?>
                                        <form method="POST" class="row g-3 align-items-end" onsubmit="disableSubmit(this)">
                                            <input type="hidden" name="action" value="add_website">
                                            <?php echo Helper::csrfField(); ?>
                                            <div class="col-md-5">
                                                <label class="form-label small fw-bold text-secondary"><?php echo __('merchant.websites.form.domain'); ?></label>
                                                <input type="text" class="form-control" name="domain" placeholder="example.com" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label small fw-bold text-secondary"><?php echo __('merchant.websites.form.category'); ?></label>
                                                <select class="form-select" name="category">
                                                    <option value="ecommerce"><?php echo __('merchant.websites.category.ecommerce'); ?></option>
                                                    <option value="gaming"><?php echo __('merchant.websites.category.gaming'); ?></option>
                                                    <option value="service"><?php echo __('merchant.websites.category.service'); ?></option>
                                                    <option value="digital"><?php echo __('merchant.websites.category.digital'); ?></option>
                                                    <option value="other"><?php echo __('merchant.websites.category.other'); ?></option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <button type="submit" class="btn btn-primary w-100"><i class="fas fa-plus me-1"></i> <?php echo __('merchant.websites.form.bind_now'); ?></button>
                                            </div>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-lg-4">
                                    <div class="clip-pane h-100">
                                        <h5 class="fw-bold mb-2"><?php echo __('merchant.api.webhook.title'); ?></h5>
                                        <p class="text-muted small mb-3"><?php echo __('merchant.api.webhook.desc'); ?></p>
                                        <div class="soft-alert mb-3">
                                            <?php echo __('merchant.api.webhook.same_domain_hint'); ?>
                                        </div>
                                        <?php if (!$allow_webhook_notice): ?>
                                            <div class="alert alert-warning small py-2 mb-3"><?php echo __('merchant.api.webhook.disabled_by_plan'); ?></div>
                                        <?php endif; ?>
                                        <form method="POST">
                                            <input type="hidden" name="action" value="save_webhook">
                                            <?php echo Helper::csrfField(); ?>
                                            <label class="form-label small fw-bold text-secondary"><?php echo __('merchant.api.webhook.label'); ?></label>
                                            <input type="url" name="webhook_url" class="form-control mb-2" placeholder="https://example.com/uapi/webhook" value="<?php echo htmlspecialchars($user['webhook_url'] ?? ''); ?>" <?php echo $allow_webhook_notice ? '' : 'disabled'; ?>>
                                            <div class="form-text mb-3"><?php echo __('merchant.api.webhook.hint'); ?></div>
                                            <button type="submit" class="btn btn-primary w-100 mb-2" <?php echo $allow_webhook_notice ? '' : 'disabled'; ?>><?php echo __('merchant.api.webhook.save'); ?></button>
                                            <a href="webhook_logs.php" class="btn btn-outline-secondary w-100 <?php echo $allow_webhook_notice ? '' : 'disabled'; ?>"><?php echo __('merchant.api.webhook.view_logs'); ?></a>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="api-clip-panel <?php echo $clip === 'docs' ? 'active' : ''; ?>" data-clip-panel="docs">
                        <div class="row g-4">
                            <div class="col-lg-8">
                                <div class="section-card p-4 h-100">
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                        <h5 class="fw-bold mb-0"><?php echo __('merchant.api.quick_start'); ?></h5>
                                        <a href="doc.php" class="btn btn-sm btn-outline-primary"><?php echo __('merchant.api.view_full_docs'); ?></a>
                                    </div>
                                    <p class="mb-2 small text-muted"><?php echo __('merchant.api.base_url'); ?>: <code><?php echo htmlspecialchars($base_url); ?></code></p>
                                    <div class="soft-alert mb-3">
                                        <i class="fas fa-circle-info me-1"></i>
                                        <?php echo __('merchant.api.quick_start_note'); ?>
                                    </div>
                                    <div class="code-wrap">
                                        <div class="code-header d-flex align-items-center justify-content-between">
                                            <span><?php echo __('merchant.api.create_order_example'); ?> (cURL)</span>
                                            <button class="btn btn-sm btn-outline-light py-0" type="button" onclick="copyCode('curlSnippet')"><?php echo __('merchant.api.code.copy'); ?></button>
                                        </div>
                                        <pre class="code-body" id="curlSnippet">curl -X POST <?php echo htmlspecialchars($base_url); ?>/order/create.php \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: <?php echo htmlspecialchars($user['api_key']); ?>" \
  -d '{
    "merchant_order_id": "ORDER_123456",
    "amount": 100.00,
    "currency": "USDT",
    "chain": "trc20",
    "domain": "example.com",
    "notify_url": "https://example.com/uapi/webhook"
  }'</pre>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-4">
                                <div class="section-card p-4 h-100">
                                    <h5 class="fw-bold mb-2"><?php echo __('merchant.api.security_tips'); ?></h5>
                                    <p class="text-muted small mb-3"><?php echo __('merchant.api.security_subtitle'); ?></p>
                                    <ul class="mb-0 ps-3 small">
                                        <li class="mb-2"><?php echo __('merchant.api.tip_rotate'); ?></li>
                                        <li class="mb-2"><?php echo __('merchant.api.tip_no_share'); ?></li>
                                        <li class="mb-2"><?php echo __('merchant.api.tip_bind_domain'); ?></li>
                                        <li class="mb-2"><?php echo __('merchant.api.tip_https_webhook'); ?></li>
                                        <li class="mb-2"><?php echo __('merchant.api.tip_verify_signature'); ?></li>
                                        <li><?php echo __('merchant.api.tip_idempotent'); ?></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="api-clip-panel <?php echo $clip === 'logs' ? 'active' : ''; ?>" data-clip-panel="logs">
                        <div class="section-card p-4">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                <h5 class="fw-bold mb-0"><?php echo __('merchant.api.logs.title'); ?></h5>
                                <span class="text-muted small"><?php echo __('merchant.api.logs.subtitle'); ?></span>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-3">
                                    <div class="stat-mini">
                                        <div class="label"><?php echo __('merchant.api.logs.total_billable'); ?></div>
                                        <div class="value"><?php echo (int)$log_total; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-mini">
                                        <div class="label"><?php echo __('merchant.api.logs.today_billable'); ?></div>
                                        <div class="value"><?php echo (int)$log_today_total; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-mini">
                                        <div class="label"><?php echo I18n::getLang() === 'en' ? 'Orders Called Today' : '今日调用订单数'; ?></div>
                                        <div class="value"><?php echo (int)$today_order_call_count; ?></div>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="stat-mini">
                                        <div class="label"><?php echo __('merchant.api.logs.remaining_limit'); ?></div>
                                        <div class="value">
                                            <?php if ($remaining_api_calls < 0): ?>
                                                <?php echo __('merchant.common.unlimited'); ?>
                                            <?php else: ?>
                                                <?php echo (int)$remaining_api_calls; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="soft-alert mb-3">
                                <?php echo __('merchant.api.logs.billing_scope', ['today' => (int)$today_external_calls]); ?>
                                <?php if ($plan_api_limit_daily > 0): ?>
                                    <?php echo __('merchant.api.logs.daily_limit', ['count' => (int)$plan_api_limit_daily]); ?>
                                <?php else: ?>
                                    <?php echo __('merchant.api.logs.unlimited_plan'); ?>
                                <?php endif; ?>
                            </div>
                            <div class="rounded-4 border border-primary-subtle bg-primary-subtle bg-opacity-25 p-4">
                                <h6 class="fw-bold mb-2"><?php echo I18n::getLang() === 'en' ? 'Merchant View' : '商户展示说明'; ?></h6>
                                <p class="text-muted mb-2"><?php echo I18n::getLang() === 'en'
                                    ? 'The merchant panel now shows only billable usage statistics. Raw provider/request details are stored internally for reconciliation and debugging.'
                                    : '商户后台现在只展示可计费的 API 使用统计。底层真实请求明细仍在系统内部保存，用于计费对账和故障排查。'; ?></p>
                                <p class="text-muted mb-0"><?php echo I18n::getLang() === 'en'
                                    ? 'This avoids exposing provider-level request details while keeping billing and quota calculations aligned with real external consumption.'
                                    : '这样可以避免向商户暴露供应商级请求细节，同时保持计费、额度和真实外部消耗完全一致。'; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="api-clip-panel <?php echo $clip === 'security' ? 'active' : ''; ?>" data-clip-panel="security">
                        <div class="section-card p-4">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                <div>
                                    <h5 class="fw-bold mb-1">API IP 白名单</h5>
                                    <p class="text-muted small mb-0">限制只有指定 IP 地址才能调用你的 API Key。留空表示不限制（允许所有 IP）。</p>
                                </div>
                            </div>

                            <?php
                            $ip_msg = $_GET['msg'] ?? $msg;
                            if ($ip_msg === 'ip_added'): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">IP 白名单添加成功。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                            <?php elseif ($ip_msg === 'ip_removed'): ?>
                                <div class="alert alert-success alert-dismissible fade show" role="alert">IP 已从白名单移除。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                            <?php elseif ($ip_msg === 'ip_invalid'): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">IP 地址格式不正确，请输入有效的 IPv4 或 IPv6 地址。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                            <?php elseif ($ip_msg === 'ip_exists'): ?>
                                <div class="alert alert-warning alert-dismissible fade show" role="alert">该 IP 已在白名单中。<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                            <?php endif; ?>

                            <?php if (!empty($ip_whitelist)): ?>
                            <div class="alert alert-warning small py-2 mb-3">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                <strong>白名单已启用：</strong>只有以下 <?php echo count($ip_whitelist); ?> 个 IP 才能调用你的 API。确保你的服务器 IP 在列表中，否则 API 调用将被拒绝。
                            </div>
                            <?php else: ?>
                            <div class="alert alert-info small py-2 mb-3">
                                <i class="fas fa-circle-info me-1"></i>
                                当前未配置 IP 白名单，<strong>所有 IP 均可调用你的 API</strong>（与原来行为一致）。添加 IP 后将开启白名单限制。
                            </div>
                            <?php endif; ?>

                            <div class="table-responsive mb-4">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>IP 地址</th>
                                            <th>备注</th>
                                            <th>添加时间</th>
                                            <th class="text-end">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($ip_whitelist)): ?>
                                            <tr><td colspan="4" class="text-center text-muted py-4">暂无 IP 白名单，所有 IP 均可访问</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($ip_whitelist as $wip): ?>
                                            <tr>
                                                <td class="font-monospace fw-bold"><?php echo htmlspecialchars($wip['ip_address']); ?></td>
                                                <td class="text-muted small"><?php echo htmlspecialchars($wip['label'] ?? ''); ?></td>
                                                <td class="small text-muted"><?php echo htmlspecialchars($wip['created_at']); ?></td>
                                                <td class="text-end">
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('确定移除此 IP？');">
                                                        <input type="hidden" name="action" value="delete_ip_whitelist">
                                                        <?php echo Helper::csrfField(); ?>
                                                        <input type="hidden" name="id" value="<?php echo (int)$wip['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="border-top pt-3">
                                <h6 class="fw-bold mb-3">添加 IP 到白名单</h6>
                                <form method="POST" class="row g-3 align-items-end">
                                    <input type="hidden" name="action" value="add_ip_whitelist">
                                    <?php echo Helper::csrfField(); ?>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-secondary">IP 地址 <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control font-monospace" name="ip_address" placeholder="例：1.2.3.4 或 ::1" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold text-secondary">备注（可选）</label>
                                        <input type="text" class="form-control" name="label" placeholder="例：生产服务器">
                                    </div>
                                    <div class="col-md-4">
                                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-plus me-1"></i>添加到白名单</button>
                                    </div>
                                </form>
                                <div class="mt-2 text-muted small">
                                    <i class="fas fa-lightbulb me-1 text-warning"></i>
                                    你当前的 IP：<code><?php echo htmlspecialchars($current_ip); ?></code>
                                    &nbsp;
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" onclick="fillCurrentIp()">填入当前 IP</button>
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
function copyKey() {
    const text = document.getElementById('apiKeyDisplay').innerText;
    navigator.clipboard.writeText(text).then(() => {
        alert(<?php echo json_encode(__('merchant.api.copy_success')); ?>);
    });
}

function copyCode(id) {
    const text = document.getElementById(id).innerText;
    navigator.clipboard.writeText(text).then(() => {
        alert(<?php echo json_encode(__('merchant.api.code.copied')); ?>);
    });
}

function disableSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (!btn) return;
    btn.disabled = true;
    btn.innerText = <?php echo json_encode(__('merchant.websites.processing')); ?>;
}

(function initApiClipTabs() {
    const tabWrap = document.getElementById('apiClipTabs');
    if (!tabWrap) return;
    const buttons = Array.from(tabWrap.querySelectorAll('[data-clip-target]'));
    const panels = Array.from(document.querySelectorAll('[data-clip-panel]'));
    const key = 'uapi_api_settings_clip_tab';

    function activate(name, save) {
        buttons.forEach((btn) => btn.classList.toggle('active', btn.dataset.clipTarget === name));
        panels.forEach((panel) => panel.classList.toggle('active', panel.dataset.clipPanel === name));
        if (save) {
            try { localStorage.setItem(key, name); } catch (e) {}
        }
    }

    let initial = <?php echo json_encode($clip); ?>;
    try {
        const saved = localStorage.getItem(key);
        if (saved && buttons.some((btn) => btn.dataset.clipTarget === saved)) {
            initial = saved;
        }
    } catch (e) {}
    activate(initial, false);

    buttons.forEach((btn) => {
        btn.addEventListener('click', () => activate(btn.dataset.clipTarget, true));
    });
})();

function fillCurrentIp() {
    const input = document.querySelector('input[name="ip_address"]');
    if (input) {
        input.value = <?php echo json_encode($current_ip); ?>;
    }
}
</script>
</body>
</html>
