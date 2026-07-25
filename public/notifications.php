<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Helper.php';
require_once __DIR__ . '/../src/Telegram.php';
require_once __DIR__ . '/../src/Services/EmailNotificationService.php';
require_once __DIR__ . '/../src/Services/NotificationPolicy.php';
I18n::init();

$db = Database::getInstance();
$db->autoMigrate();
$user_id = $_SESSION['user_id'];
$message = '';
$error = '';
$clip = trim((string)($_POST['clip'] ?? $_GET['clip'] ?? 'pref'));
if (!in_array($clip, ['pref', 'tg', 'email', 'webhook'], true)) {
    $clip = 'pref';
}
if (!empty($_SESSION['merchant_notifications_flash']) && is_array($_SESSION['merchant_notifications_flash'])) {
    $flash = $_SESSION['merchant_notifications_flash'];
    unset($_SESSION['merchant_notifications_flash']);
    $message = (string)($flash['message'] ?? '');
    $error = (string)($flash['error'] ?? '');
}

$user = $db->fetch(
    "SELECT u.*, p.allow_tg_bot, p.tg_notice_limit, p.allow_email_notice, p.email_notice_limit, p.allow_webhook_notice
     FROM users u
     LEFT JOIN plans p ON p.id = u.plan_id
     WHERE u.id = ? LIMIT 1",
    [$user_id]
);

if (!$user) {
    header('Location: logout.php');
    exit;
}

$currentYm = date('Y-m');
if (($user['notice_cycle_ym'] ?? '') !== $currentYm) {
    $db->query(
        "UPDATE users SET notice_cycle_ym = ?, tg_notice_used_month = 0, email_notice_used_month = 0 WHERE id = ?",
        [$currentYm, $user_id]
    );
    $user['notice_cycle_ym'] = $currentYm;
    $user['tg_notice_used_month'] = 0;
    $user['email_notice_used_month'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_toggles') {
        $settings = [
            'channel_in_app' => isset($_POST['channel_in_app']),
            'channel_tg' => isset($_POST['channel_tg']),
            'channel_email' => isset($_POST['channel_email']),
            'order' => isset($_POST['notify_order']),
            'withdraw' => isset($_POST['notify_withdraw']),
            'balance' => isset($_POST['notify_balance']),
            'announcement' => isset($_POST['notify_announcement']),
            'low_quota' => isset($_POST['notify_low_quota']),
            'security' => isset($_POST['notify_security']),
            'system' => isset($_POST['notify_system']),
        ];
        $db->query("UPDATE users SET notification_settings = ? WHERE id = ?", [json_encode($settings), $user_id]);
        $message = __('merchant.notifications.success.preferences_saved');
    }

    if ($action === 'bind_tg') {
        if (empty($user['allow_tg_bot'])) {
            $error = __('merchant.notifications.error.plan_no_tg');
        } else {
            $tg = trim($_POST['tg_chat_id'] ?? '');
            $code = strtoupper(trim($_POST['tg_verify_code'] ?? ''));
            if ($tg === '' || $code === '') {
                $error = __('merchant.notifications.error.code_required');
            } else {
                $bindRow = $db->fetch(
                    "SELECT id FROM tg_bind_codes WHERE chat_id = ? AND code = ? AND used = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) ORDER BY id DESC LIMIT 1",
                    [$tg, $code]
                );
                if (!$bindRow) {
                    $error = __('merchant.notifications.error.code_invalid');
                } else {
                    $db->query("UPDATE tg_bind_codes SET used = 1 WHERE id = ?", [$bindRow['id']]);
                    $db->query("UPDATE users SET tg_chat_id = ? WHERE id = ?", [$tg, $user_id]);
                    $message = __('merchant.notifications.success.bound');
                }
            }
        }
    }

    if ($action === 'unbind_tg') {
        $db->query("UPDATE users SET tg_chat_id = NULL WHERE id = ?", [$user_id]);
        $message = __('merchant.notifications.success.unbound');
    }

    if ($action === 'save_email') {
        if (empty($user['allow_email_notice'])) {
            $error = __('merchant.notifications.email.error.plan_no_email');
        } else {
            $email = trim($_POST['email_notice_address'] ?? '');
            $useCustom = isset($_POST['email_notice_use_custom_smtp']) ? 1 : 0;
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = __('merchant.notifications.email.error.invalid_email');
            } else {
                $smtpHost = trim($_POST['smtp_host'] ?? '');
                $smtpPort = (int)($_POST['smtp_port'] ?? 587);
                $smtpUser = trim($_POST['smtp_username'] ?? '');
                $smtpPass = (string)($_POST['smtp_password'] ?? '');
                $smtpEnc = trim($_POST['smtp_encryption'] ?? 'tls');
                $smtpFromName = trim($_POST['smtp_from_name'] ?? '');
                $smtpFromEmail = trim($_POST['smtp_from_email'] ?? '');
                if ($useCustom && $smtpPass === '') {
                    $smtpPass = (string)($user['smtp_password'] ?? '');
                }

                if ($useCustom) {
                    if ($smtpHost === '' || $smtpPort <= 0 || $smtpFromEmail === '' || !filter_var($smtpFromEmail, FILTER_VALIDATE_EMAIL)) {
                        $error = __('merchant.notifications.email.error.smtp_required');
                    }
                }

                if ($error === '') {
                    $db->query(
                        "UPDATE users
                         SET email_notice_address = ?, email_notice_use_custom_smtp = ?, smtp_host = ?, smtp_port = ?,
                             smtp_username = ?, smtp_password = ?, smtp_encryption = ?, smtp_from_name = ?, smtp_from_email = ?
                         WHERE id = ?",
                        [
                            $email,
                            $useCustom,
                            $useCustom ? $smtpHost : null,
                            $useCustom ? $smtpPort : null,
                            $useCustom ? $smtpUser : null,
                            $useCustom ? $smtpPass : null,
                            $useCustom ? $smtpEnc : 'tls',
                            $useCustom ? $smtpFromName : null,
                            $useCustom ? $smtpFromEmail : null,
                            $user_id
                        ]
                    );
                    $message = __('merchant.notifications.email.success.saved');
                }
            }
        }
    }

    if ($action === 'test_tg') {
        if (Telegram::sendTestToUser($user_id, __('merchant.notifications.tg.test_message'))) {
            $message = __('merchant.notifications.success.test_sent');
        } else {
            $error = __('merchant.notifications.error.test_failed');
        }
    }

    if ($action === 'test_email') {
        if (empty($user['allow_email_notice'])) {
            $error = __('merchant.notifications.email.error.plan_no_email');
        } else {
            $testTo = trim($_POST['test_email_to'] ?? '');
            if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
                $error = __('merchant.notifications.email.error.invalid_email');
            } else {
                $ok = EmailNotificationService::sendTestToUser(
                    $user_id,
                    $testTo,
                    __('merchant.notifications.email.test_subject'),
                    __('merchant.notifications.email.test_body'),
                    !empty($user['email_notice_use_custom_smtp'])
                );
                if ($ok) {
                    $message = __('merchant.notifications.email.success.test_sent');
                } else {
                    $error = __('merchant.notifications.email.error.test_failed');
                }
            }
        }
    }

    $user = $db->fetch(
        "SELECT u.*, p.allow_tg_bot, p.tg_notice_limit, p.allow_email_notice, p.email_notice_limit, p.allow_webhook_notice
         FROM users u
         LEFT JOIN plans p ON p.id = u.plan_id
         WHERE u.id = ? LIMIT 1",
        [$user_id]
    );
    $_SESSION['merchant_notifications_flash'] = ['message' => (string)$message, 'error' => (string)$error];
    header('Location: notifications.php?clip=' . urlencode($clip), true, 303);
    exit;
}

$notif_settings = json_decode($user['notification_settings'] ?? '{}', true);
$ns = NotificationPolicy::normalize(is_array($notif_settings) ? $notif_settings : []);

$tgUsed    = (int)($user['tg_notice_used_month'] ?? 0);
$tgLimit   = (int)($user['tg_notice_limit'] ?? 0);
$emailUsed  = (int)($user['email_notice_used_month'] ?? 0);
$emailLimit = (int)($user['email_notice_limit'] ?? 0);

$tgRemaining    = $tgLimit > 0    ? max(0, $tgLimit - $tgUsed)       : -1;
$emailRemaining = $emailLimit > 0 ? max(0, $emailLimit - $emailUsed) : -1;

// Today's counts from notification_send_logs
$today_tg = 0; $today_email = 0; $today_inapp = 0;
try {
    $today_tg = (int)($db->fetch(
        "SELECT COUNT(*) AS c FROM notification_send_logs WHERE user_id = ? AND channel = 'tg' AND status = 'success' AND DATE(created_at) = CURDATE()",
        [$user_id]
    )['c'] ?? 0);
    $today_email = (int)($db->fetch(
        "SELECT COUNT(*) AS c FROM notification_send_logs WHERE user_id = ? AND channel = 'email' AND status = 'success' AND DATE(created_at) = CURDATE()",
        [$user_id]
    )['c'] ?? 0);
    $today_inapp = (int)($db->fetch(
        "SELECT COUNT(*) AS c FROM notification_send_logs WHERE user_id = ? AND channel = 'in_app' AND status = 'success' AND DATE(created_at) = CURDATE()",
        [$user_id]
    )['c'] ?? 0);
} catch (Throwable $ignore) {}
$today_total = $today_tg + $today_email + $today_inapp;

$page_title = __('merchant.notifications.title');
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
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
            font-size: 0.85rem;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 6px;
        }
        .soft-alert {
            border-radius: 12px;
            padding: 12px 14px;
            border: 1px solid #dbeafe;
            background: #f8fbff;
            color: #1e40af;
            font-size: 13px;
        }
        /* Stat Cards */
        .notif-stat-card {
            border: 1px solid #e5edf7;
            border-radius: 16px;
            background: #ffffff;
            padding: 18px 20px;
            box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04);
            height: 100%;
        }
        .notif-stat-card .stat-label {
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 8px;
        }
        .notif-stat-card .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: #0f172a;
            line-height: 1;
            margin-bottom: 8px;
        }
        .notif-stat-card .stat-sub {
            font-size: 12px;
            color: #94a3b8;
        }
        .notif-stat-card .stat-sub strong {
            color: #475569;
        }
        .notif-stat-card .stat-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 15px;
            margin-bottom: 12px;
        }
        .icon-blue   { background: #eff6ff; color: #2563eb; }
        .icon-indigo { background: #eef2ff; color: #4f46e5; }
        .icon-green  { background: #f0fdf4; color: #16a34a; }
        .icon-orange { background: #fff7ed; color: #ea580c; }
        .badge-remaining {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
        }
        .badge-ok  { background: #f0fdf4; color: #16a34a; }
        .badge-inf { background: #f0f9ff; color: #0369a1; }
        .badge-low { background: #fff7ed; color: #c2410c; }
        /* Clip tabs */
        .notif-clip-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
            margin-bottom: 14px;
        }
        .notif-clip-btn {
            border: 1px solid #dbe3ee;
            background: #fff;
            color: #334155;
            border-radius: 999px;
            padding: 8px 16px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .notif-clip-btn:hover { background: #f1f5f9; }
        .notif-clip-btn.active {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
        }
        .notif-clip-panel { display: none; }
        .notif-clip-panel.active { display: block; }
        /* Clip combo */
        .clip-combo {
            border: 1px solid #e6edf5;
            border-radius: 16px;
            background: #fff;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
            overflow: hidden;
        }
        .clip-pane { padding: 22px; height: 100%; }
        .clip-pane + .clip-pane {
            border-left: 1px dashed #d7e2f2;
            background: linear-gradient(180deg, #fbfdff 0%, #ffffff 100%);
        }
        @media (max-width: 991.98px) {
            .clip-pane + .clip-pane {
                border-left: 0;
                border-top: 1px dashed #d7e2f2;
            }
        }
        .channel-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }
        .channel-on  { background: #f0fdf4; color: #16a34a; }
        .channel-off { background: #f8fafc; color: #94a3b8; }
        .list-group-item { border-color: #f1f5f9; }
        .form-check-input:checked { background-color: #2563eb; border-color: #2563eb; }
        [data-bs-theme="dark"] .hero-card {
            border-color: #374151;
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            box-shadow: 0 10px 30px rgba(0,0,0,0.35);
        }
        [data-bs-theme="dark"] .section-card,
        [data-bs-theme="dark"] .notif-stat-card,
        [data-bs-theme="dark"] .clip-combo {
            border-color: #374151 !important;
            background: #1f2937 !important;
            box-shadow: none;
        }
        [data-bs-theme="dark"] .section-title,
        [data-bs-theme="dark"] .notif-stat-card .stat-label,
        [data-bs-theme="dark"] .notif-stat-card .stat-sub,
        [data-bs-theme="dark"] .text-muted,
        [data-bs-theme="dark"] .text-secondary {
            color: #9ca3af !important;
        }
        [data-bs-theme="dark"] .notif-stat-card .stat-value {
            color: #f9fafb !important;
        }
        [data-bs-theme="dark"] .soft-alert {
            border-color: #1d4ed8;
            background: rgba(37, 99, 235, 0.12);
            color: #bfdbfe;
        }
        [data-bs-theme="dark"] .notif-clip-btn {
            border-color: #374151;
            background: #1f2937;
            color: #e5e7eb;
        }
        [data-bs-theme="dark"] .notif-clip-btn:hover {
            background: #111827;
        }
        [data-bs-theme="dark"] .notif-clip-btn.active {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }
        [data-bs-theme="dark"] .clip-pane + .clip-pane {
            border-left-color: #374151;
            background: linear-gradient(180deg, #111827 0%, #1f2937 100%);
        }
        [data-bs-theme="dark"] .channel-off {
            background: #111827;
            color: #9ca3af;
        }
        [data-bs-theme="dark"] .list-group-item {
            background: #111827 !important;
            border-color: #374151 !important;
            color: #e5e7eb !important;
        }
        [data-bs-theme="dark"] .table td,
        [data-bs-theme="dark"] .table th {
            border-color: #374151 !important;
            color: #e5e7eb !important;
        }
        [data-bs-theme="dark"] .form-check-input {
            background-color: #111827;
            border-color: #4b5563;
        }
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 main-content">
            <?php include __DIR__ . '/includes/user_topbar.php'; ?>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Stats Cards -->
            <div class="row g-3 mb-2">
                <!-- 今日总通知 -->
                <div class="col-6 col-md-3">
                    <div class="notif-stat-card">
                        <div class="stat-icon icon-blue"><i class="fas fa-bell"></i></div>
                        <div class="stat-label"><?php echo __('merchant.notifications.stats.today_total'); ?></div>
                        <div class="stat-value"><?php echo $today_total; ?></div>
                        <div class="stat-sub"><?php echo __('merchant.notifications.stats.today_breakdown', ['inapp' => $today_inapp, 'tg' => $today_tg, 'email' => $today_email]); ?></div>
                    </div>
                </div>
                <!-- TG -->
                <div class="col-6 col-md-3">
                    <div class="notif-stat-card">
                        <div class="stat-icon icon-indigo"><i class="fab fa-telegram"></i></div>
                        <div class="stat-label"><?php echo __('merchant.notifications.stats.tg_push'); ?></div>
                        <div class="stat-value"><?php echo $today_tg; ?> <small class="fs-6 fw-normal text-muted"><?php echo __('merchant.notifications.stats.today'); ?></small></div>
                        <div class="stat-sub">
                            <?php echo __('merchant.notifications.stats.used_remaining', ['used' => '<strong>' . $tgUsed . '</strong>']); ?>&nbsp;
                            <?php if ($tgRemaining < 0): ?>
                                <span class="badge-remaining badge-inf"><?php echo __('merchant.notifications.stats.unlimited'); ?></span>
                            <?php elseif ($tgRemaining <= 10): ?>
                                <span class="badge-remaining badge-low"><?php echo $tgRemaining; ?></span>
                            <?php else: ?>
                                <span class="badge-remaining badge-ok"><?php echo $tgRemaining; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- 邮件 -->
                <div class="col-6 col-md-3">
                    <div class="notif-stat-card">
                        <div class="stat-icon icon-green"><i class="fas fa-envelope"></i></div>
                        <div class="stat-label"><?php echo __('merchant.notifications.stats.email_push'); ?></div>
                        <div class="stat-value"><?php echo $today_email; ?> <small class="fs-6 fw-normal text-muted"><?php echo __('merchant.notifications.stats.today'); ?></small></div>
                        <div class="stat-sub">
                            <?php echo __('merchant.notifications.stats.used_remaining', ['used' => '<strong>' . $emailUsed . '</strong>']); ?>&nbsp;
                            <?php if ($emailRemaining < 0): ?>
                                <span class="badge-remaining badge-inf"><?php echo __('merchant.notifications.stats.unlimited'); ?></span>
                            <?php elseif ($emailRemaining <= 10): ?>
                                <span class="badge-remaining badge-low"><?php echo $emailRemaining; ?></span>
                            <?php else: ?>
                                <span class="badge-remaining badge-ok"><?php echo $emailRemaining; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- 站内通知 -->
                <div class="col-6 col-md-3">
                    <div class="notif-stat-card">
                        <div class="stat-icon icon-orange"><i class="fas fa-inbox"></i></div>
                        <div class="stat-label"><?php echo __('merchant.notifications.stats.inapp'); ?></div>
                        <div class="stat-value"><?php echo $today_inapp; ?> <small class="fs-6 fw-normal text-muted"><?php echo __('merchant.notifications.stats.today'); ?></small></div>
                        <div class="stat-sub">
                            <?php echo __('merchant.notifications.stats.channel'); ?>&nbsp;
                            <?php if ($ns['channel_in_app']): ?>
                                <span class="badge-remaining badge-ok"><?php echo __('merchant.notifications.status.enabled'); ?></span>
                            <?php else: ?>
                                <span class="badge-remaining badge-low"><?php echo __('merchant.notifications.status.disabled'); ?></span>
                            <?php endif; ?>
                            &nbsp;·&nbsp;<?php echo __('merchant.notifications.stats.quota_exempt'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Clip Tabs -->
            <div class="notif-clip-tabs" id="notifClipTabs">
                <button type="button" class="notif-clip-btn <?php echo $clip === 'pref' ? 'active' : ''; ?>" data-clip-target="pref">
                    <i class="fas fa-sliders me-1"></i> <?php echo __('merchant.notifications.tab.preferences'); ?>
                </button>
                <button type="button" class="notif-clip-btn <?php echo $clip === 'tg' ? 'active' : ''; ?>" data-clip-target="tg">
                    <i class="fab fa-telegram me-1"></i> Telegram
                    <?php if (!empty($user['tg_chat_id'])): ?>
                        <span class="channel-badge channel-on ms-1"><i class="fas fa-check" style="font-size:9px"></i> <?php echo __('merchant.notifications.tab.bound'); ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" class="notif-clip-btn <?php echo $clip === 'email' ? 'active' : ''; ?>" data-clip-target="email">
                    <i class="fas fa-envelope me-1"></i> <?php echo __('merchant.notifications.channels.email'); ?>
                    <?php if (!empty($user['allow_email_notice']) && !empty($user['email_notice_address'])): ?>
                        <span class="channel-badge channel-on ms-1"><i class="fas fa-check" style="font-size:9px"></i> <?php echo __('merchant.notifications.tab.configured'); ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" class="notif-clip-btn <?php echo $clip === 'webhook' ? 'active' : ''; ?>" data-clip-target="webhook">
                    <i class="fas fa-webhook me-1"></i> Webhook
                    <?php if (!empty($user['allow_webhook_notice'])): ?>
                        <span class="channel-badge channel-on ms-1"><i class="fas fa-check" style="font-size:9px"></i> <?php echo __('merchant.notifications.tab.available'); ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Panel: 通知偏好 -->
            <div class="notif-clip-panel <?php echo $clip === 'pref' ? 'active' : ''; ?>" data-clip-panel="pref">
                <div class="section-card">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_toggles">
                        <div class="clip-combo">
                            <div class="row g-0">
                                <div class="col-lg-5">
                                    <div class="clip-pane">
                                        <h6 class="fw-bold mb-1"><?php echo __('merchant.notifications.channels_title'); ?></h6>
                                        <p class="text-muted small mb-3"><?php echo __('merchant.notifications.channels_desc'); ?></p>
                                        <div class="list-group list-group-flush">
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.channels.inapp'); ?> <i class="fas fa-bell text-muted ms-1" style="font-size:11px"></i></div>
                                                    <div class="text-muted" style="font-size:11.5px"><?php echo __('merchant.notifications.channels.inapp_desc'); ?></div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="channel_in_app" <?php echo $ns['channel_in_app'] ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.channels.tg'); ?> <i class="fab fa-telegram text-primary ms-1" style="font-size:11px"></i></div>
                                                    <div class="text-muted" style="font-size:11.5px">
                                                        <?php if (!empty($user['allow_tg_bot'])): ?>
                                                            <?php echo __('merchant.notifications.channels.remaining', ['count' => $tgRemaining < 0 ? __('merchant.notifications.stats.unlimited') : $tgRemaining]); ?>
                                                        <?php else: ?>
                                                            <?php echo __('merchant.notifications.channels.unsupported'); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="channel_tg" <?php echo $ns['channel_tg'] ? 'checked' : ''; ?> <?php echo empty($user['allow_tg_bot']) ? 'disabled' : ''; ?>>
                                            </label>
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.channels.email'); ?> <i class="fas fa-envelope text-success ms-1" style="font-size:11px"></i></div>
                                                    <div class="text-muted" style="font-size:11.5px">
                                                        <?php if (!empty($user['allow_email_notice'])): ?>
                                                            <?php echo __('merchant.notifications.channels.remaining', ['count' => $emailRemaining < 0 ? __('merchant.notifications.stats.unlimited') : $emailRemaining]); ?>
                                                        <?php else: ?>
                                                            <?php echo __('merchant.notifications.channels.unsupported'); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="channel_email" <?php echo $ns['channel_email'] ? 'checked' : ''; ?> <?php echo empty($user['allow_email_notice']) ? 'disabled' : ''; ?>>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-7">
                                    <div class="clip-pane">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div>
                                                <h6 class="fw-bold mb-0"><?php echo __('merchant.notifications.toggle_title'); ?></h6>
                                                <p class="text-muted small mb-0"><?php echo __('merchant.notifications.events_desc'); ?></p>
                                            </div>
                                            <button type="submit" class="btn btn-primary btn-sm px-3">
                                                <i class="fas fa-save me-1"></i><?php echo __('merchant.notifications.save_settings'); ?>
                                            </button>
                                        </div>
                                        <div class="list-group list-group-flush mt-3">
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.toggle.order_title'); ?></div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="notify_order" <?php echo $ns['order'] ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.toggle.withdraw_title'); ?></div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="notify_withdraw" <?php echo $ns['withdraw'] ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.toggle.balance_title'); ?></div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="notify_balance" <?php echo $ns['balance'] ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.toggle.announcement_title'); ?></div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="notify_announcement" <?php echo $ns['announcement'] ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.toggle.low_quota_title'); ?></div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="notify_low_quota" <?php echo $ns['low_quota'] ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.toggle.security_title'); ?></div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="notify_security" <?php echo $ns['security'] ? 'checked' : ''; ?>>
                                            </label>
                                            <label class="list-group-item d-flex justify-content-between align-items-center px-0">
                                                <div>
                                                    <div class="fw-semibold small"><?php echo __('merchant.notifications.toggle.system_title'); ?></div>
                                                </div>
                                                <input class="form-check-input" type="checkbox" name="notify_system" <?php echo $ns['system'] ? 'checked' : ''; ?>>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Panel: Telegram -->
            <div class="notif-clip-panel <?php echo $clip === 'tg' ? 'active' : ''; ?>" data-clip-panel="tg">
                <div class="clip-combo">
                    <div class="row g-0">
                        <div class="col-lg-7">
                            <div class="clip-pane">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <h5 class="fw-bold mb-0"><?php echo __('merchant.notifications.tg_binding'); ?></h5>
                                    <span class="badge <?php echo !empty($user['allow_tg_bot']) ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo !empty($user['allow_tg_bot']) ? __('merchant.notifications.status.enabled') : __('merchant.notifications.status.disabled'); ?>
                                    </span>
                                </div>
                                <p class="text-muted small mb-3"><?php echo __('merchant.notifications.plan_tip'); ?></p>

                                <?php if (!empty($user['tg_chat_id'])): ?>
                                    <div class="soft-alert mb-3">
                                        <i class="fab fa-telegram me-1"></i>
                                        <?php echo __('merchant.notifications.tg.bound_chat_id'); ?><code><?php echo htmlspecialchars($user['tg_chat_id']); ?></code>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <form method="POST" class="m-0">
                                            <input type="hidden" name="action" value="test_tg">
                                            <button class="btn btn-outline-primary btn-sm">
                                                <i class="fas fa-paper-plane me-1"></i><?php echo __('merchant.notifications.test_notification'); ?>
                                            </button>
                                        </form>
                                        <form method="POST" class="m-0" onsubmit="return confirm(<?php echo json_encode(__('merchant.notifications.confirm_unbind')); ?>);">
                                            <input type="hidden" name="action" value="unbind_tg">
                                            <button class="btn btn-outline-danger btn-sm">
                                                <i class="fas fa-unlink me-1"></i><?php echo __('merchant.notifications.unbind'); ?>
                                            </button>
                                        </form>
                                    </div>
                                <?php elseif (!empty($user['allow_tg_bot'])): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="bind_tg">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-secondary">Chat ID</label>
                                            <input type="text" name="tg_chat_id" class="form-control" placeholder="<?php echo __('merchant.notifications.chat_id_placeholder'); ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-secondary"><?php echo __('merchant.notifications.verify_code_placeholder'); ?></label>
                                            <input type="text" name="tg_verify_code" class="form-control" placeholder="<?php echo __('merchant.notifications.tg.verify_example'); ?>">
                                        </div>
                                        <button class="btn btn-primary w-100">
                                            <i class="fab fa-telegram me-1"></i><?php echo __('merchant.notifications.bind_now'); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="soft-alert">
                                        <i class="fas fa-lock me-1"></i>
                                        <?php echo __('merchant.notifications.error.plan_no_tg'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="clip-pane h-100">
                                <h6 class="fw-bold mb-2"><?php echo __('merchant.notifications.tg.bind_steps_title'); ?></h6>
                                <ol class="ps-3 small mb-0" style="line-height:2">
                                    <li><?php echo __('merchant.notifications.bind_step_1'); ?> <?php $tgBotUsername = ltrim((string)Helper::getSetting('tg_bot_username', ''), '@'); ?><?php if ($tgBotUsername !== ''): ?><a href="https://t.me/<?php echo Helper::e($tgBotUsername); ?>" target="_blank">@<?php echo Helper::e($tgBotUsername); ?></a><?php else: ?><span class="text-muted">(<?php echo __('merchant.notifications.bind_bot_unset'); ?>)</span><?php endif; ?></li>
                                    <li><?php echo __('merchant.notifications.bind_step_2'); ?></li>
                                    <li><?php echo __('merchant.notifications.bind_step_3'); ?></li>
                                </ol>
                                <hr class="my-3">
                                <div class="section-title"><?php echo __('merchant.notifications.monthly_usage'); ?></div>
                                <div class="d-flex align-items-baseline gap-2 mb-1">
                                    <span style="font-size:26px;font-weight:800;color:#0f172a"><?php echo $tgUsed; ?></span>
                                    <span class="text-muted small">/ <?php echo $tgLimit > 0 ? $tgLimit : '∞'; ?> <?php echo __('merchant.notifications.count_suffix'); ?></span>
                                </div>
                                <div class="text-muted small">
                                    <?php echo __('merchant.notifications.remaining_label'); ?>
                                    <?php if ($tgRemaining < 0): ?>
                                        <span class="badge-remaining badge-inf"><?php echo __('merchant.notifications.unlimited_count'); ?></span>
                                    <?php elseif ($tgRemaining <= 10): ?>
                                        <span class="badge-remaining badge-low"><?php echo $tgRemaining; ?> <?php echo __('merchant.notifications.count_suffix'); ?></span>
                                    <?php else: ?>
                                        <span class="badge-remaining badge-ok"><?php echo $tgRemaining; ?> <?php echo __('merchant.notifications.count_suffix'); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel: 邮件 -->
            <div class="notif-clip-panel <?php echo $clip === 'email' ? 'active' : ''; ?>" data-clip-panel="email">
                <div class="clip-combo">
                    <div class="row g-0">
                        <div class="col-lg-7">
                            <div class="clip-pane">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <h5 class="fw-bold mb-0"><?php echo __('merchant.notifications.email.title'); ?></h5>
                                    <span class="badge <?php echo !empty($user['allow_email_notice']) ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo !empty($user['allow_email_notice']) ? __('merchant.notifications.status.enabled') : __('merchant.notifications.status.disabled'); ?>
                                    </span>
                                </div>
                                <p class="text-muted small mb-3"><?php echo __('merchant.notifications.email.config_desc'); ?></p>

                                <?php if (!empty($user['allow_email_notice'])): ?>
                                    <form method="POST" class="mb-3">
                                        <input type="hidden" name="action" value="save_email">
                                        <div class="mb-3">
                                            <label class="form-label small fw-bold text-secondary"><?php echo __('merchant.notifications.email.receiver'); ?></label>
                                            <input type="email" name="email_notice_address" class="form-control" value="<?php echo htmlspecialchars($user['email_notice_address'] ?? ''); ?>" placeholder="notify@example.com">
                                            <div class="form-text"><?php echo __('merchant.notifications.email.default_sender_tip'); ?></div>
                                        </div>
                                        <div class="form-check form-switch mb-3">
                                            <input class="form-check-input" type="checkbox" name="email_notice_use_custom_smtp" id="use_custom_smtp" <?php echo !empty($user['email_notice_use_custom_smtp']) ? 'checked' : ''; ?>>
                                            <label class="form-check-label small fw-semibold" for="use_custom_smtp"><?php echo __('merchant.notifications.email.use_custom_smtp'); ?></label>
                                        </div>
                                        <div class="row g-2 mb-3">
                                            <div class="col-8"><input type="text" name="smtp_host" class="form-control form-control-sm" placeholder="SMTP Host" value="<?php echo htmlspecialchars($user['smtp_host'] ?? ''); ?>"></div>
                                            <div class="col-4"><input type="number" name="smtp_port" class="form-control form-control-sm" placeholder="Port" value="<?php echo htmlspecialchars((string)($user['smtp_port'] ?? '587')); ?>"></div>
                                            <div class="col-6"><input type="text" name="smtp_username" class="form-control form-control-sm" placeholder="Username" value="<?php echo htmlspecialchars($user['smtp_username'] ?? ''); ?>"></div>
                                            <div class="col-6"><input type="password" name="smtp_password" class="form-control form-control-sm" placeholder="<?php echo __('merchant.notifications.email.keep_password_placeholder'); ?>" value=""></div>
                                            <div class="col-6">
                                                <select name="smtp_encryption" class="form-select form-select-sm">
                                                    <option value="tls" <?php echo ($user['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                                    <option value="ssl" <?php echo ($user['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                                    <option value="none" <?php echo ($user['smtp_encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                                                </select>
                                            </div>
                                            <div class="col-6"><input type="email" name="smtp_from_email" class="form-control form-control-sm" placeholder="From Email" value="<?php echo htmlspecialchars($user['smtp_from_email'] ?? ''); ?>"></div>
                                            <div class="col-12"><input type="text" name="smtp_from_name" class="form-control form-control-sm" placeholder="From Name" value="<?php echo htmlspecialchars($user['smtp_from_name'] ?? ''); ?>"></div>
                                        </div>
                                        <button class="btn btn-primary w-100">
                                            <i class="fas fa-save me-1"></i><?php echo __('merchant.notifications.save_settings'); ?>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-flex gap-2 align-items-center">
                                        <input type="hidden" name="action" value="test_email">
                                        <input type="email" name="test_email_to" class="form-control form-control-sm" placeholder="<?php echo __('merchant.notifications.email.test_to_placeholder'); ?>" value="<?php echo htmlspecialchars($user['email_notice_address'] ?? $user['email'] ?? ''); ?>">
                                        <button class="btn btn-outline-primary btn-sm text-nowrap" type="submit">
                                            <i class="fas fa-paper-plane me-1"></i><?php echo __('merchant.notifications.email.test_send'); ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="soft-alert">
                                        <i class="fas fa-lock me-1"></i>
                                        <?php echo __('merchant.notifications.email.error.plan_no_email'); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-lg-5">
                            <div class="clip-pane h-100">
                                <div class="section-title"><?php echo __('merchant.notifications.monthly_usage'); ?></div>
                                <div class="d-flex align-items-baseline gap-2 mb-1">
                                    <span style="font-size:26px;font-weight:800;color:#0f172a"><?php echo $emailUsed; ?></span>
                                    <span class="text-muted small">/ <?php echo $emailLimit > 0 ? $emailLimit : '∞'; ?> <?php echo __('merchant.notifications.count_suffix'); ?></span>
                                </div>
                                <div class="text-muted small mb-3">
                                    <?php echo __('merchant.notifications.remaining_label'); ?>
                                    <?php if ($emailRemaining < 0): ?>
                                        <span class="badge-remaining badge-inf"><?php echo __('merchant.notifications.unlimited_count'); ?></span>
                                    <?php elseif ($emailRemaining <= 10): ?>
                                        <span class="badge-remaining badge-low"><?php echo $emailRemaining; ?> <?php echo __('merchant.notifications.count_suffix'); ?></span>
                                    <?php else: ?>
                                        <span class="badge-remaining badge-ok"><?php echo $emailRemaining; ?> <?php echo __('merchant.notifications.count_suffix'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <hr class="my-3">
                                <div class="section-title"><?php echo __('merchant.notifications.email.current_config'); ?></div>
                                <?php if (!empty($user['email_notice_address'])): ?>
                                    <div class="small mb-1"><span class="text-muted"><?php echo __('merchant.notifications.email.receiver_label'); ?></span><strong><?php echo htmlspecialchars($user['email_notice_address']); ?></strong></div>
                                <?php else: ?>
                                    <div class="small text-muted mb-1"><?php echo __('merchant.notifications.email.receiver_unset'); ?></div>
                                <?php endif; ?>
                                <div class="small mb-1">
                                    <span class="text-muted"><?php echo __('merchant.notifications.email.sender_mode'); ?></span>
                                    <?php if (!empty($user['email_notice_use_custom_smtp'])): ?>
                                        <span class="badge-remaining badge-ok"><?php echo __('merchant.notifications.email.custom_smtp'); ?></span>
                                    <?php else: ?>
                                        <span class="badge-remaining badge-inf"><?php echo __('merchant.notifications.email.system_smtp'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($user['email_notice_use_custom_smtp']) && !empty($user['smtp_host'])): ?>
                                    <div class="small text-muted"><?php echo __('merchant.notifications.email.server'); ?><?php echo htmlspecialchars($user['smtp_host']); ?>:<?php echo (int)($user['smtp_port'] ?? 587); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Panel: Webhook -->
            <div class="notif-clip-panel <?php echo $clip === 'webhook' ? 'active' : ''; ?>" data-clip-panel="webhook">
                <div class="section-card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <h5 class="fw-bold mb-1"><?php echo __('merchant.notifications.webhook.title'); ?></h5>
                            <p class="text-muted small mb-0"><?php echo __('merchant.notifications.webhook.plan_desc'); ?></p>
                        </div>
                        <span class="badge <?php echo !empty($user['allow_webhook_notice']) ? 'bg-success' : 'bg-secondary'; ?> fs-6">
                            <?php echo !empty($user['allow_webhook_notice']) ? __('merchant.notifications.status.enabled') : __('merchant.notifications.status.disabled'); ?>
                        </span>
                    </div>
                    <hr>
                    <?php if (!empty($user['allow_webhook_notice'])): ?>
                        <div class="soft-alert mb-3">
                            <i class="fas fa-circle-info me-1"></i>
                            <?php echo __('merchant.notifications.webhook.hint'); ?>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="api_settings.php" class="btn btn-primary">
                                <i class="fas fa-arrow-right me-1"></i><?php echo __('merchant.notifications.webhook.to_api_settings'); ?>
                            </a>
                            <a href="webhook_logs.php" class="btn btn-outline-secondary"><?php echo __('merchant.notifications.webhook.to_logs'); ?></a>
                        </div>
                    <?php else: ?>
                        <div class="soft-alert">
                            <i class="fas fa-lock me-1"></i>
                            <?php echo __('merchant.notifications.webhook.disabled'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function initNotifClipTabs() {
    const tabWrap = document.getElementById('notifClipTabs');
    if (!tabWrap) return;
    const buttons = Array.from(tabWrap.querySelectorAll('[data-clip-target]'));
    const panels  = Array.from(document.querySelectorAll('[data-clip-panel]'));
    const key = 'uapi_notifications_clip_tab';

    function activate(name, save) {
        buttons.forEach(btn => btn.classList.toggle('active', btn.dataset.clipTarget === name));
        panels.forEach(panel => panel.classList.toggle('active', panel.dataset.clipPanel === name));
        if (save) { try { localStorage.setItem(key, name); } catch (e) {} }
    }

    let initial = <?php echo json_encode($clip); ?>;
    try {
        const saved = localStorage.getItem(key);
        if (saved && buttons.some(btn => btn.dataset.clipTarget === saved)) {
            initial = saved;
        }
    } catch (e) {}
    activate(initial, false);

    buttons.forEach(btn => btn.addEventListener('click', () => activate(btn.dataset.clipTarget, true)));
})();
</script>
</body>
</html>
