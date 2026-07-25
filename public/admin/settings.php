<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
$httpHelpers = __DIR__ . '/../../src/Core/Http.php';
if (file_exists($httpHelpers)) { require_once $httpHelpers; }
$db = Database::getInstance();
require_once __DIR__ . '/../../src/Core/Migrator.php';
$migrator = new Migrator($db->getConnection());
$migrator->run();
require_once __DIR__ . '/../../src/Services/EmailNotificationService.php';

// ── Admin 2FA unlock support ──────────────────────────────────────────────
require_once __DIR__ . '/../../src/Services/TotpService.php';
require_once __DIR__ . '/../../src/Services/User2FAService.php';
$_adminId2fa   = (int)($_SESSION['user_id'] ?? 0);
$_adminFor2fa  = $db->fetch("SELECT two_factor_enabled, two_factor_secret, two_factor_scenes FROM users WHERE id=? AND role='admin' LIMIT 1", [$_adminId2fa]);
$_settingsScene = $_adminFor2fa ? User2FAService::isSceneEnabled((array)$_adminFor2fa, 'admin_settings') : false;

// Handle 2FA unlock POST for settings page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_settings_2fa_unlock') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!empty($_SESSION['admin_csrf_token']) && hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        $otp = trim($_POST['unlock_otp'] ?? '');
        [$ok, $msg] = User2FAService::verifyForScene((array)$_adminFor2fa, 'admin_settings', $otp);
        if ($ok) {
            $_SESSION['admin_settings_2fa_unlock_at'] = time();
            if (function_exists('flash_add')) flash_add('success', '身份验证成功，系统设置已解锁（5分钟有效）。');
        } else {
            if (function_exists('flash_add')) flash_add('error', '验证码错误，请重试。');
        }
    }
    $tab = $_POST['current_tab'] ?? 'basic';
    header('Location: settings.php?tab=' . urlencode($tab)); exit;
}

$flashes = function_exists('flash_consume_all') ? flash_consume_all() : [];
$settings = $db->fetchAll("SELECT * FROM system_settings");
$config = [];
foreach ($settings as $s) {
    $config[$s['key_name']] = $s['value'];
}

$allowed_tabs = ['basic', 'api', 'payment', 'notifications', 'test'];
$current_tab = $_GET['tab'] ?? 'basic';
if (!in_array($current_tab, $allowed_tabs, true)) {
    $current_tab = 'basic';
}

$build_settings_url = static function (string $tab) use ($allowed_tabs): string {
    if (!in_array($tab, $allowed_tabs, true)) {
        $tab = 'basic';
    }
    return 'settings.php?tab=' . urlencode($tab);
};

$upsertSystemSetting = static function (Database $db, string $key, string $value): void {
    $exists = $db->fetch("SELECT 1 FROM system_settings WHERE key_name = ?", [$key]);
    if ($exists) {
        $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$value, $key]);
        return;
    }
    $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$key, $value]);
};

// Image processing logic
$generateSmallLogo = static function (string $srcPath, string $destPath): bool {
    if (!function_exists('getimagesize')) return false;
    $info = @getimagesize($srcPath);
    if (!$info || empty($info['mime'])) return false;
    $source = null;
    switch ($info['mime']) {
        case 'image/png': if (function_exists('imagecreatefrompng')) $source = @imagecreatefrompng($srcPath); break;
        case 'image/jpeg': if (function_exists('imagecreatefromjpeg')) $source = @imagecreatefromjpeg($srcPath); break;
        case 'image/gif': if (function_exists('imagecreatefromgif')) $source = @imagecreatefromgif($srcPath); break;
        case 'image/webp': if (function_exists('imagecreatefromwebp')) $source = @imagecreatefromwebp($srcPath); break;
    }
    if (!$source || !function_exists('imagecreatetruecolor')) return false;
    $targetW = 136; $targetH = 64;
    $srcW = imagesx($source); $srcH = imagesy($source);
    if ($srcW <= 0 || $srcH <= 0) { @imagedestroy($source); return false; }
    $ratio = min($targetW / $srcW, $targetH / $srcH);
    $newW = max(1, (int)floor($srcW * $ratio));
    $newH = max(1, (int)floor($srcH * $ratio));
    $dstX = (int)floor(($targetW - $newW) / 2);
    $dstY = (int)floor(($targetH - $newH) / 2);
    $target = imagecreatetruecolor($targetW, $targetH);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetW, $targetH, $transparent);
    imagecopyresampled($target, $source, $dstX, $dstY, 0, 0, $newW, $newH, $srcW, $srcH);
    $saved = false;
    if (function_exists('imagewebp')) $saved = @imagewebp($target, $destPath, 86);
    @imagedestroy($target);
    @imagedestroy($source);
    return (bool)$saved;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_tab = $_POST['current_tab'] ?? $current_tab;
    if (!in_array($post_tab, $allowed_tabs, true)) {
        $post_tab = 'basic';
    }

    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        if (function_exists('flash_add')) { flash_add('error', '请求已被拒绝（CSRF 校验失败）'); }
        $target = $build_settings_url($post_tab);
        header("Location: " . $target); exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'send_smtp_test' || $action === 'send_receipt_test') {
        $post_tab = 'notifications';
        $test_to = trim($_POST['smtp_test_to'] ?? '');
        $rows = $db->fetchAll("SELECT key_name, value FROM system_settings WHERE key_name IN ('smtp_enabled','smtp_host','smtp_port','smtp_username','smtp_password','smtp_encryption','smtp_from_name','smtp_from_email')");
        $smtp = [];
        foreach ($rows as $row) { $smtp[$row['key_name']] = $row['value']; }
        $smtpConfig = [
            'host' => trim((string)($smtp['smtp_host'] ?? '')),
            'port' => (int)($smtp['smtp_port'] ?? 587),
            'username' => trim((string)($smtp['smtp_username'] ?? '')),
            'password' => (string)($smtp['smtp_password'] ?? ''),
            'encryption' => trim((string)($smtp['smtp_encryption'] ?? 'tls')),
            'from_name' => trim((string)($smtp['smtp_from_name'] ?? 'UAPI')),
            'from_email' => trim((string)($smtp['smtp_from_email'] ?? '')),
        ];

        if (($smtp['smtp_enabled'] ?? '0') !== '1') {
            if (function_exists('flash_add')) { flash_add('error', 'SMTP 未启用，请先开启平台邮箱通知。'); }
        } elseif (!filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
            if (function_exists('flash_add')) { flash_add('error', '测试邮箱格式不正确。'); }
        } else {
            if ($action === 'send_receipt_test') {
                $receiptHtml = '... (Receipt Template) ...'; 
                $ok = EmailNotificationService::sendUsingConfig($smtpConfig, $test_to, 'UAPI 收据模板测试', '<h3>Receipt Test</h3>');
                if (function_exists('flash_add')) { flash_add($ok ? 'success' : 'error', $ok ? '收据测试发送成功。' : '收据测试发送失败，请检查 SMTP 配置。'); }
            } else {
                $ok = EmailNotificationService::sendUsingConfig($smtpConfig, $test_to, 'UAPI SMTP 测试邮件', '<h3>UAPI SMTP 测试成功</h3><p>这是一封来自后台设置页的测试邮件。</p>');
                if (function_exists('flash_add')) { flash_add($ok ? 'success' : 'error', $ok ? '测试邮件发送成功。' : '测试邮件发送失败，请检查 SMTP 配置。'); }
            }
        }
        $target = $build_settings_url($post_tab);
        header("Location: " . $target); exit;
    }

    if ($action === 'test_derived_service') {
        $post_tab = 'payment';
        $serviceUrl = trim((string)($_POST['derived_addr_service_url'] ?? ($config['derived_addr_service_url'] ?? '')));
        $serviceToken = trim((string)($_POST['derived_addr_service_token'] ?? ($config['derived_addr_service_token'] ?? '')));
        $timeout = (int)($_POST['derived_addr_service_timeout'] ?? ($config['derived_addr_service_timeout'] ?? 5));
        if ($timeout < 2) $timeout = 2;
        if ($timeout > 15) $timeout = 15;
        if ($serviceUrl === '') {
            if (function_exists('flash_add')) flash_add('error', '测试失败：服务地址不能为空。');
        } else {
            $healthz = rtrim($serviceUrl, '/') . '/healthz';
            $headers = ['Accept: application/json'];
            if ($serviceToken !== '') {
                $headers[] = 'X-Api-Key: ' . $serviceToken;
            }
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $healthz);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            $resp = curl_exec($ch);
            $err = curl_error($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $ok = false;
            $msg = '';
            if ($resp === false) {
                $msg = '连接失败：' . ($err ?: 'unknown error');
            } else {
                $data = json_decode((string)$resp, true);
                if ($http === 200 && is_array($data) && (!empty($data['ok']) || !empty($data['service']))) {
                    $ok = true;
                    $msg = '派生服务连接正常（HTTP 200）';
                } else {
                    $msg = '连接异常：HTTP ' . $http . '，响应 ' . mb_substr((string)$resp, 0, 180);
                }
            }

            if (function_exists('flash_add')) flash_add($ok ? 'success' : 'error', $msg);
        }
        $target = $build_settings_url($post_tab);
        header("Location: " . $target); exit;
    }

    // ── 2FA guard for admin_settings scene ─────────────────────────────
    if ($_settingsScene) {
        $unlockAt = (int)($_SESSION['admin_settings_2fa_unlock_at'] ?? 0);
        if (time() - $unlockAt > 300) {
            if (function_exists('flash_add')) flash_add('error', '系统设置受谷歌验证器保护，请先输入动态码解锁。');
            header("Location: " . $build_settings_url($post_tab)); exit;
        }
    }

    foreach ($_POST as $key => $value) {
        $known_keys = [
            'site_name', 'seo_title', 'seo_description', 'seo_keywords', 'seo_og_image', 'seo_canonical',
            'tron_api_provider', 'tron_api_key', 'eth_api_key', 'sol_api_key', 'tg_bot_token', 'tg_bot_username',
            'payment_method', 'enable_payment_usdt', 'enable_payment_stripe', 'enable_payment_binance', 'enable_usdc',
            'admin_fee_address_mode', 'payment_collection_chain',
            'derived_addr_service_url', 'derived_addr_service_token', 'derived_addr_service_timeout',
            'usdt_admin_wallet', 'usdt_admin_wallet_evm', 'usdt_admin_wallet_sol',
            'stripe_public_key', 'stripe_secret_key', 'stripe_webhook_secret',
            'binance_pay_base_url', 'binance_pay_api_key', 'binance_pay_api_secret',
            'binance_pay_certificate_sn', 'binance_pay_webhook_secret',
            'test_payment_network', 'test_payment_amount',
            'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
            'smtp_encryption', 'smtp_from_name', 'smtp_from_email'
        ];
        
        if ($key === 'enable_payment_usdt' || $key === 'enable_payment_stripe' || $key === 'enable_payment_binance' || $key === 'enable_usdc' || $key === 'smtp_enabled') {
            $value = $value ? '1' : '0';
        }

        if (in_array($key, $known_keys) || array_key_exists($key, $config)) {
             if (array_key_exists($key, $config)) {
                 $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$value, $key]);
             } else {
                 $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$key, $value]);
             }
        }
    }
    
    // Handle unchecked checkboxes
    $checkboxes = ['enable_payment_usdt', 'enable_payment_stripe', 'enable_payment_binance', 'enable_usdc', 'smtp_enabled'];
    foreach ($checkboxes as $chk) {
        if (!isset($_POST[$chk])) {
            $db->query("UPDATE system_settings SET value = '0' WHERE key_name = ?", [$chk]);
        }
    }

    // Real MIME sniffing helper for uploads (do not trust client Content-Type / filename)
    $detectUploadMime = static function (string $tmpPath): string {
        if (!function_exists('finfo_open')) return '';
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if (!$finfo) return '';
        $mime = (string)finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        return $mime;
    };

    // Logo Upload
    if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['site_logo']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION));
        $realMime = $detectUploadMime($tmp);
        if (in_array($ext, ['png','jpg','jpeg','webp','gif'])
            && in_array($realMime, ['image/png','image/jpeg','image/webp','image/gif'], true)) {
            $destDir = __DIR__ . '/../assets';
            if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
            $destAbs = $destDir . '/logo.png';
            if (@move_uploaded_file($tmp, $destAbs)) {
                $val = '/assets/logo.png?v=' . time();
                $upsertSystemSetting($db, 'site_logo', $val);
                $smallAbs = $destDir . '/logo-header.webp';
                if ($generateSmallLogo($destAbs, $smallAbs)) {
                    $upsertSystemSetting($db, 'site_logo_small', '/assets/logo-header.webp?v=' . time());
                }
            }
        }
    }

    // Favicon Upload
    if (isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['site_favicon']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['site_favicon']['name'], PATHINFO_EXTENSION));
        $realMime = $detectUploadMime($tmp);
        if (in_array($ext, ['png','ico','jpg','jpeg','webp'])
            && in_array($realMime, ['image/png','image/x-icon','image/vnd.microsoft.icon','image/jpeg','image/webp'], true)) {
            $destDir = __DIR__ . '/../assets';
            if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
            $destAbs = $destDir . '/favicon.png';
            if (@move_uploaded_file($tmp, $destAbs)) {
                $val = '/assets/favicon.png?v=' . time();
                $upsertSystemSetting($db, 'site_favicon', $val);
            }
        }
    }

    if (function_exists('flash_add')) { flash_add('success', '配置已保存'); }
    $target = $build_settings_url($post_tab);
    header("Location: " . $target); exit;
}

$active_menu = 'settings';
require_once 'includes/header.php';
?>
<?php if ($_settingsScene): ?>
<?php
$_s2faUnlockAt  = (int)($_SESSION['admin_settings_2fa_unlock_at'] ?? 0);
$_s2faRemaining = max(0, 300 - (time() - $_s2faUnlockAt));
?>
<div class="alert <?php echo $_s2faRemaining > 0 ? 'alert-success' : 'alert-warning'; ?> d-flex align-items-center gap-3 mb-4 flex-wrap" id="s2faBanner">
    <i class="fas <?php echo $_s2faRemaining > 0 ? 'fa-unlock' : 'fa-lock'; ?> fa-lg"></i>
    <div class="flex-grow-1">
        <?php if ($_s2faRemaining > 0): ?>
            <strong>系统设置已解锁</strong> — 剩余约 <span id="s2faCountdown"><?php echo $_s2faRemaining; ?></span> 秒
        <?php else: ?>
            <strong>系统设置受 2FA 保护</strong> — 请输入谷歌验证码解锁后方可保存设置
        <?php endif; ?>
    </div>
    <?php if ($_s2faRemaining <= 0): ?>
    <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$admin_csrf_token); ?>">
        <input type="hidden" name="action" value="admin_settings_2fa_unlock">
        <input type="hidden" name="current_tab" value="<?php echo htmlspecialchars($current_tab); ?>">
        <input name="unlock_otp" class="form-control form-control-sm" style="width:120px;font-family:monospace;font-size:16px;letter-spacing:.12em;" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
        <button type="submit" class="btn btn-sm btn-warning fw-bold"><i class="fas fa-key me-1"></i>验证解锁</button>
    </form>
    <?php endif; ?>
</div>
<?php if ($_s2faRemaining > 0): ?>
<script>
(function(){
    var n = <?php echo $_s2faRemaining; ?>, el = document.getElementById('s2faCountdown');
    var t = setInterval(function(){ n--; if(n<=0){clearInterval(t);location.reload();}else if(el){el.textContent=n;} }, 1000);
})();
</script>
<?php endif; ?>
<?php endif; ?>
<!-- Inject Tailwind -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    prefix: 'tw-',
    darkMode: ['class', '[data-bs-theme="dark"]'],
    theme: {
      extend: {
        colors: {
          primary: '#3b82f6',
          secondary: '#64748b',
          success: '#10b981',
          danger: '#ef4444',
          warning: '#f59e0b',
          dark: '#1e293b',
          light: '#f8fafc'
        }
      }
    }
  }
</script>
<style>
  .u-switch { display:inline-flex; align-items:center; cursor:pointer; user-select:none; }
  .u-switch-input { position:absolute; opacity:0; width:0; height:0; }
  .u-switch-track {
    position:relative; width:44px; height:24px; border-radius:9999px;
    background:#e5e7eb; transition:all .2s ease;
    border:1px solid #d1d5db; flex-shrink:0;
  }
  .u-switch-track::after {
    content:''; position:absolute; top:1px; left:1px; width:20px; height:20px;
    border-radius:9999px; background:#fff; border:1px solid #d1d5db; transition:all .2s ease;
  }
  .u-switch-input:checked + .u-switch-track { background:#2563eb; border-color:#2563eb; }
  .u-switch-input:checked + .u-switch-track::after { transform:translateX(20px); }
  .u-input-action-row { display:flex; flex-wrap:wrap; gap:.5rem; align-items:stretch; }
  .u-input-action-row .u-input-main { flex:1 1 320px; min-width:260px; }
  .u-input-action-row .u-action-btn { flex:0 0 auto; white-space:nowrap; }
</style>

<div class="tw-font-sans tw-text-gray-800 tw-antialiased tw-min-h-screen tw-bg-gray-50 dark:tw-bg-gray-900 dark:tw-text-gray-100 tw-p-4 md:tw-p-8">
    
    <!-- Page Header -->
    <div class="tw-flex tw-justify-between tw-items-center tw-mb-8">
        <div>
            <h1 class="tw-text-2xl tw-font-bold tw-tracking-tight tw-text-gray-900 dark:tw-text-white">系统设置</h1>
            <p class="tw-text-sm tw-text-gray-500 dark:tw-text-gray-400">配置全站基础参数、API 密钥及支付通道</p>
        </div>
        <button onclick="document.getElementById('settingsForm').submit()" class="tw-inline-flex tw-items-center tw-justify-center tw-rounded-lg tw-bg-primary tw-px-5 tw-py-2.5 tw-text-sm tw-font-medium tw-text-white hover:tw-bg-blue-600 focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-blue-300 dark:focus:tw-ring-blue-900 tw-transition-all tw-shadow-sm">
            <svg class="tw-mr-2 tw-h-4 tw-w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            保存更改
        </button>
    </div>

    <?php if (!empty($flashes)): ?>
        <div class="tw-mb-6">
            <?php foreach ($flashes as $f): ?>
                <?php
                    $type = strtolower((string)($f['type'] ?? 'info'));
                    $msg = (string)($f['message'] ?? '');
                    $alertClass = 'alert-info';
                    if (in_array($type, ['success', 'ok'], true)) {
                        $alertClass = 'alert-success';
                    } elseif (in_array($type, ['error', 'danger', 'fail'], true)) {
                        $alertClass = 'alert-danger';
                    } elseif (in_array($type, ['warn', 'warning'], true)) {
                        $alertClass = 'alert-warning';
                    }
                ?>
                <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($msg); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Main Form -->
    <form id="settingsForm" method="POST" enctype="multipart/form-data" class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-8">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
        <input type="hidden" name="current_tab" id="current_tab" value="<?php echo htmlspecialchars($current_tab); ?>">

        <!-- Sidebar Navigation (Sticky) -->
        <div class="lg:tw-col-span-3">
            <nav class="tw-flex tw-flex-col tw-space-y-1 tw-sticky tw-top-6">
                <?php
                $tabs = [
                    'basic' => ['icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6', 'label' => '基础设置', 'desc' => '站点名称、Logo、SEO'],
                    'api' => ['icon' => 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'label' => '接口配置', 'desc' => '区块链节点、API Keys'],
                    'payment' => ['icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z', 'label' => '支付配置', 'desc' => '收款地址、Stripe、USDT'],
                    'notifications' => ['icon' => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9', 'label' => '通知服务', 'desc' => 'SMTP 邮件、Telegram Bot'],
                    'test' => ['icon' => 'M19.428 15.428a2 2 0 00-1.022-.547l-2.384-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z', 'label' => '在线测试', 'desc' => '测试支付参数配置'],
                ];
                foreach ($tabs as $key => $t):
                    $isActive = $current_tab === $key;
                ?>
                <button type="button" onclick="switchTab('<?php echo $key; ?>')" id="nav-<?php echo $key; ?>" class="tw-group tw-flex tw-items-center tw-px-3 tw-py-3 tw-text-sm tw-font-medium tw-rounded-xl tw-transition-all <?php echo $isActive ? 'tw-bg-white dark:tw-bg-gray-800 tw-text-primary tw-shadow-sm' : 'tw-text-gray-600 dark:tw-text-gray-400 hover:tw-bg-gray-100 dark:hover:tw-bg-gray-800'; ?>">
                    <span class="tw-flex-shrink-0 tw-mr-3">
                        <svg class="tw-h-6 tw-w-6 <?php echo $isActive ? 'tw-text-primary' : 'tw-text-gray-400 group-hover:tw-text-gray-500'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?php echo $t['icon']; ?>"></path></svg>
                    </span>
                    <div class="tw-text-left">
                        <span class="tw-block"><?php echo $t['label']; ?></span>
                        <span class="tw-block tw-text-xs tw-font-normal tw-text-gray-400 dark:tw-text-gray-500"><?php echo $t['desc']; ?></span>
                    </div>
                </button>
                <?php endforeach; ?>
            </nav>
        </div>

        <!-- Content Area -->
        <div class="lg:tw-col-span-9 tw-space-y-6">
            
            <!-- Basic Settings -->
            <div id="tab-basic" class="settings-tab <?php echo $current_tab === 'basic' ? '' : 'tw-hidden'; ?>">
                <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-overflow-hidden">
                    <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <h3 class="tw-text-lg tw-font-medium tw-text-gray-900 dark:tw-text-white">基础设置</h3>
                    </div>
                    <div class="tw-p-6 tw-space-y-6">
                        <div>
                            <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">网站名称</label>
                            <input type="text" name="site_name" value="<?php echo htmlspecialchars($config['site_name'] ?? 'UAPI'); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-shadow-sm focus:tw-border-primary focus:tw-ring-primary sm:tw-text-sm">
                        </div>
                        
                        <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                            <div>
                                <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">网站 Logo</label>
                                <div class="tw-mt-2 tw-flex tw-items-center tw-gap-4">
                                    <?php if (!empty($config['site_logo'])): ?>
                                    <div class="tw-h-16 tw-w-16 tw-rounded-lg tw-bg-gray-100 tw-border tw-flex tw-items-center tw-justify-center tw-overflow-hidden">
                                        <img src="<?php echo htmlspecialchars($config['site_logo']); ?>" class="tw-max-h-full tw-max-w-full">
                                    </div>
                                    <?php endif; ?>
                                    <input type="file" name="site_logo" accept="image/*" class="tw-block tw-w-full tw-text-sm tw-text-gray-500 file:tw-mr-4 file:tw-py-2 file:tw-px-4 file:tw-rounded-full file:tw-border-0 file:tw-text-sm file:tw-font-semibold file:tw-bg-blue-50 file:tw-text-primary hover:file:tw-bg-blue-100">
                                </div>
                            </div>
                            <div>
                                <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Favicon 图标</label>
                                <div class="tw-mt-2 tw-flex tw-items-center tw-gap-4">
                                    <?php if (!empty($config['site_favicon'])): ?>
                                    <div class="tw-h-16 tw-w-16 tw-rounded-lg tw-bg-gray-100 tw-border tw-flex tw-items-center tw-justify-center tw-overflow-hidden">
                                        <img src="<?php echo htmlspecialchars($config['site_favicon']); ?>" class="tw-w-8 tw-h-8">
                                    </div>
                                    <?php endif; ?>
                                    <input type="file" name="site_favicon" accept="image/*,.ico" class="tw-block tw-w-full tw-text-sm tw-text-gray-500 file:tw-mr-4 file:tw-py-2 file:tw-px-4 file:tw-rounded-full file:tw-border-0 file:tw-text-sm file:tw-font-semibold file:tw-bg-blue-50 file:tw-text-primary hover:file:tw-bg-blue-100">
                                </div>
                            </div>
                        </div>

                        <div class="tw-border-t tw-border-gray-100 dark:tw-border-gray-700 tw-pt-6">
                            <h4 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4">SEO 优化</h4>
                            <div class="tw-space-y-4">
                                <div>
                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-uppercase">SEO Title</label>
                                    <input type="text" name="seo_title" value="<?php echo htmlspecialchars($config['seo_title'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-uppercase">Meta Description</label>
                                    <textarea name="seo_description" rows="3" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm"><?php echo htmlspecialchars($config['seo_description'] ?? ''); ?></textarea>
                                </div>
                                <div>
                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-uppercase">Keywords</label>
                                    <input type="text" name="seo_keywords" value="<?php echo htmlspecialchars($config['seo_keywords'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- API Configuration -->
            <div id="tab-api" class="settings-tab <?php echo $current_tab === 'api' ? '' : 'tw-hidden'; ?>">
                <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700">
                    <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <h3 class="tw-text-lg tw-font-medium tw-text-gray-900 dark:tw-text-white">区块链 API 配置</h3>
                    </div>
                    <div class="tw-p-6 tw-space-y-6">
                        <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                            <div>
                                <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">TRON 提供商</label>
                                <select name="tron_api_provider" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                    <option value="tronscan" <?php echo ($config['tron_api_provider']??'')=='tronscan'?'selected':''; ?>>TronScan (Public)</option>
                                    <option value="trongrid" <?php echo ($config['tron_api_provider']??'')=='trongrid'?'selected':''; ?>>TronGrid (Official)</option>
                                </select>
                            </div>
                            <div>
                                <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">TRON API Key</label>
                                <input type="text" name="tron_api_key" value="<?php echo htmlspecialchars($config['tron_api_key'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                            </div>
                            <div>
                                <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">EVM API Key (Etherscan)</label>
                                <input type="text" name="eth_api_key" value="<?php echo htmlspecialchars($config['eth_api_key'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                            </div>
                            <div>
                                <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Solana API Key</label>
                                <input type="text" name="sol_api_key" value="<?php echo htmlspecialchars($config['sol_api_key'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payment Configuration -->
            <div id="tab-payment" class="settings-tab <?php echo $current_tab === 'payment' ? '' : 'tw-hidden'; ?>">
                <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700">
                    <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <h3 class="tw-text-lg tw-font-medium tw-text-gray-900 dark:tw-text-white">支付通道配置</h3>
                    </div>
                    <div class="tw-p-6 tw-space-y-8">
                        
                        <!-- Toggle Switches -->
                        <div class="tw-flex tw-flex-wrap tw-gap-6 tw-p-4 tw-bg-gray-50 dark:tw-bg-gray-700/50 tw-rounded-xl">
                            <?php 
                                $isUsdt = filter_var($config['enable_payment_usdt'] ?? 0, FILTER_VALIDATE_BOOLEAN);
                                $isUsdc = filter_var($config['enable_usdc'] ?? 0, FILTER_VALIDATE_BOOLEAN);
                                $isStripe = filter_var($config['enable_payment_stripe'] ?? 0, FILTER_VALIDATE_BOOLEAN);
                                $isBinancePay = filter_var($config['enable_payment_binance'] ?? 0, FILTER_VALIDATE_BOOLEAN);
                            ?>
                            <label class="u-switch">
                                <input id="enable_payment_usdt" type="checkbox" name="enable_payment_usdt" value="1" class="u-switch-input" <?php echo $isUsdt ? 'checked' : ''; ?>>
                                <span class="u-switch-track"></span>
                                <span class="tw-ml-3 tw-text-sm tw-font-medium tw-text-gray-900 dark:tw-text-gray-300">启用 USDT</span>
                            </label>
                            <label class="u-switch">
                                <input id="enable_usdc" type="checkbox" name="enable_usdc" value="1" class="u-switch-input" <?php echo $isUsdc ? 'checked' : ''; ?>>
                                <span class="u-switch-track"></span>
                                <span class="tw-ml-3 tw-text-sm tw-font-medium tw-text-gray-900 dark:tw-text-gray-300">启用 USDC</span>
                            </label>
                            <label class="u-switch">
                                <input id="enable_payment_stripe" type="checkbox" name="enable_payment_stripe" value="1" class="u-switch-input" <?php echo $isStripe ? 'checked' : ''; ?>>
                                <span class="u-switch-track"></span>
                                <span class="tw-ml-3 tw-text-sm tw-font-medium tw-text-gray-900 dark:tw-text-gray-300">启用 Stripe</span>
                            </label>
                            <label class="u-switch">
                                <input id="enable_payment_binance" type="checkbox" name="enable_payment_binance" value="1" class="u-switch-input" <?php echo $isBinancePay ? 'checked' : ''; ?>>
                                <span class="u-switch-track"></span>
                                <span class="tw-ml-3 tw-text-sm tw-font-medium tw-text-gray-900 dark:tw-text-gray-300">启用 Binance Pay</span>
                            </label>
                        </div>

                        <!-- Admin Wallets -->
                        <div>
                            <h4 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4">平台收款钱包 (管理员)</h4>
                            <div class="tw-space-y-4">
                                <div>
                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-uppercase">TRC20 地址</label>
                                    <input type="text" name="usdt_admin_wallet" value="<?php echo htmlspecialchars($config['usdt_admin_wallet'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-font-mono tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-uppercase">EVM 地址 (ETH/BSC/Polygon)</label>
                                    <input type="text" name="usdt_admin_wallet_evm" value="<?php echo htmlspecialchars($config['usdt_admin_wallet_evm'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-font-mono tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-uppercase">Solana 地址</label>
                                    <input type="text" name="usdt_admin_wallet_sol" value="<?php echo htmlspecialchars($config['usdt_admin_wallet_sol'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-font-mono tw-text-sm">
                                </div>
                            </div>
                        </div>

                        <!-- Derived Service -->
                        <div class="tw-border-t tw-border-gray-100 dark:tw-border-gray-700 tw-pt-6">
                            <h4 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4">实时派生服务</h4>
                            <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                                <div class="md:tw-col-span-2">
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">服务地址 URL</label>
                                    <div class="tw-mt-1 u-input-action-row">
                                        <input type="text" name="derived_addr_service_url" value="<?php echo htmlspecialchars($config['derived_addr_service_url'] ?? 'http://127.0.0.1:8787'); ?>" class="u-input-main tw-block tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                        <button type="submit" name="action" value="test_derived_service" onclick="document.getElementById('current_tab').value='payment'" class="u-action-btn tw-inline-flex tw-items-center tw-px-4 tw-py-2 tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-bg-gray-50 dark:tw-bg-gray-700 tw-text-gray-500 tw-text-sm tw-rounded-lg hover:tw-bg-gray-100 tw-whitespace-nowrap tw-flex-shrink-0">
                                            测试连接
                                        </button>
                                    </div>
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Auth Token</label>
                                    <input type="password" name="derived_addr_service_token" value="<?php echo htmlspecialchars($config['derived_addr_service_token'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">超时时间 (秒)</label>
                                    <input type="number" name="derived_addr_service_timeout" value="<?php echo htmlspecialchars($config['derived_addr_service_timeout'] ?? '5'); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                            </div>
                        </div>

                        <!-- Stripe -->
                        <div class="tw-border-t tw-border-gray-100 dark:tw-border-gray-700 tw-pt-6">
                            <h4 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4">Stripe 密钥</h4>
                            <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Publishable Key</label>
                                    <input type="text" name="stripe_public_key" value="<?php echo htmlspecialchars($config['stripe_public_key'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Secret Key</label>
                                    <input type="password" name="stripe_secret_key" value="<?php echo htmlspecialchars($config['stripe_secret_key'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div class="md:tw-col-span-2">
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">
                                        Webhook Secret <span class="tw-text-xs tw-text-gray-400 tw-font-normal">（whsec_... 从 Stripe Dashboard → Webhooks 获取）</span>
                                    </label>
                                    <input type="password" name="stripe_webhook_secret" value="<?php echo htmlspecialchars($config['stripe_webhook_secret'] ?? ''); ?>" placeholder="whsec_..." class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                    <p class="tw-mt-1 tw-text-xs tw-text-gray-500">Webhook 接收地址填写：<code class="tw-bg-gray-100 dark:tw-bg-gray-700 tw-px-1 tw-rounded"><?php echo htmlspecialchars(rtrim($config['site_url'] ?? ('https://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain.com')), '/')); ?>/api/v1/stripe/webhook.php</code></p>
                                </div>
                            </div>
                        </div>

                        <!-- Binance Pay -->
                        <div class="tw-border-t tw-border-gray-100 dark:tw-border-gray-700 tw-pt-6">
                            <h4 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4">Binance Pay 商户配置</h4>
                            <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                                <div class="md:tw-col-span-2">
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">API Base URL</label>
                                    <input type="text" name="binance_pay_base_url" value="<?php echo htmlspecialchars($config['binance_pay_base_url'] ?? 'https://bpay.binanceapi.com'); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">API Key</label>
                                    <input type="text" name="binance_pay_api_key" value="<?php echo htmlspecialchars($config['binance_pay_api_key'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Certificate SN</label>
                                    <input type="text" name="binance_pay_certificate_sn" value="<?php echo htmlspecialchars($config['binance_pay_certificate_sn'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div class="md:tw-col-span-2">
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Secret Key</label>
                                    <input type="password" name="binance_pay_api_secret" value="<?php echo htmlspecialchars($config['binance_pay_api_secret'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm tw-font-mono">
                                    <p class="tw-mt-2 tw-text-xs tw-text-gray-500">按 Binance Pay 官方文档，签名使用 SecretKey（HMAC-SHA512），不是 PEM 私钥。</p>
                                </div>
                                <div class="md:tw-col-span-2">
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Webhook Secret (可选)</label>
                                    <input type="password" name="binance_pay_webhook_secret" value="<?php echo htmlspecialchars($config['binance_pay_webhook_secret'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                    <p class="tw-mt-2 tw-text-xs tw-text-gray-500">回调地址：<code>/api/v1/binance/webhook.php</code>。Certificate SN 可为空（默认使用 API Key）。</p>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Notification Settings -->
            <div id="tab-notifications" class="settings-tab <?php echo $current_tab === 'notifications' ? '' : 'tw-hidden'; ?>">
                 <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700">
                    <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <h3 class="tw-text-lg tw-font-medium tw-text-gray-900 dark:tw-text-white">通知服务配置</h3>
                    </div>
                    <div class="tw-p-6 tw-space-y-8">
                        
                        <!-- SMTP -->
                        <div>
                            <div class="tw-flex tw-items-center tw-justify-between tw-mb-4">
                                <h4 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider">SMTP 邮件服务</h4>
                                <?php $isSmtp = filter_var($config['smtp_enabled'] ?? 0, FILTER_VALIDATE_BOOLEAN); ?>
                                <label class="u-switch">
                                    <input id="smtp_enabled" type="checkbox" name="smtp_enabled" value="1" class="u-switch-input" <?php echo $isSmtp ? 'checked' : ''; ?>>
                                    <span class="u-switch-track"></span>
                                </label>
                            </div>
                            <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                                <div class="md:tw-col-span-2">
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">SMTP Host</label>
                                    <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($config['smtp_host'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Port</label>
                                    <input type="number" name="smtp_port" value="<?php echo htmlspecialchars($config['smtp_port'] ?? '587'); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Encryption</label>
                                    <select name="smtp_encryption" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                        <option value="tls" <?php echo ($config['smtp_encryption']??'tls')=='tls'?'selected':''; ?>>TLS</option>
                                        <option value="ssl" <?php echo ($config['smtp_encryption']??'')=='ssl'?'selected':''; ?>>SSL</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Username</label>
                                    <input type="text" name="smtp_username" value="<?php echo htmlspecialchars($config['smtp_username'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                                <div>
                                    <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Password</label>
                                    <input type="password" name="smtp_password" value="<?php echo htmlspecialchars($config['smtp_password'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                </div>
                            </div>
                            
                            <div class="tw-mt-4 tw-p-4 tw-bg-gray-50 dark:tw-bg-gray-700/50 tw-rounded-lg tw-flex tw-gap-4 tw-items-end">
                                <div class="tw-flex-1">
                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">测试收件人</label>
                                    <input type="email" name="smtp_test_to" class="tw-mt-1 tw-block tw-w-full tw-rounded-md tw-border-gray-300 tw-text-sm" placeholder="test@example.com">
                                </div>
                                <button type="submit" name="action" value="send_smtp_test" onclick="document.getElementById('current_tab').value='notifications'" class="tw-px-4 tw-py-2 tw-bg-white tw-border tw-border-gray-300 tw-rounded-md tw-text-sm tw-font-medium tw-text-gray-700 hover:tw-bg-gray-50 tw-whitespace-nowrap tw-flex-shrink-0">
                                    发送测试邮件
                                </button>
                            </div>
                        </div>

                        <!-- Telegram Bot -->
                        <div class="tw-border-t tw-border-gray-100 dark:tw-border-gray-700 tw-pt-6">
                            <h4 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4">Telegram 机器人</h4>
                            <div>
                                <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Bot Token</label>
                                <div class="tw-mt-1 u-input-action-row">
                                    <input type="password" name="tg_bot_token" value="<?php echo htmlspecialchars($config['tg_bot_token'] ?? ''); ?>" class="u-input-main tw-block tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                    <button type="button" onclick="setWebhook(this)" class="u-action-btn tw-inline-flex tw-items-center tw-px-4 tw-py-2 tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-bg-gray-50 dark:tw-bg-gray-700 tw-text-gray-500 tw-text-sm tw-rounded-lg hover:tw-bg-gray-100 tw-whitespace-nowrap tw-flex-shrink-0">
                                        更新 Webhook
                                    </button>
                                </div>
                            </div>
                            <div class="tw-mt-4">
                                <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">Bot 用户名</label>
                                <input type="text" name="tg_bot_username" placeholder="YourBotName（不带 @）" value="<?php echo htmlspecialchars($config['tg_bot_username'] ?? ''); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                <p class="tw-mt-1 tw-text-xs tw-text-gray-400">商户通知页会引导用户关注该机器人</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test Settings -->
            <div id="tab-test" class="settings-tab <?php echo $current_tab === 'test' ? '' : 'tw-hidden'; ?>">
                <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700">
                     <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <h3 class="tw-text-lg tw-font-medium tw-text-gray-900 dark:tw-text-white">在线支付测试</h3>
                    </div>
                    <div class="tw-p-6 tw-space-y-6">
                         <div>
                            <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">测试网络</label>
                            <select name="test_payment_network" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                                <?php 
                                $active_chains = $db->fetchAll("SELECT * FROM chains WHERE status = 1 ORDER BY is_evm DESC, name ASC");
                                foreach($active_chains as $c): 
                                ?>
                                <option value="<?php echo $c['slug']; ?>" <?php echo ($config['test_payment_network']??'')==$c['slug']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($c['name']); ?> (<?php echo htmlspecialchars($c['symbol']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="tw-block tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300">测试金额 (USDT)</label>
                            <input type="number" step="0.01" name="test_payment_amount" value="<?php echo htmlspecialchars($config['test_payment_amount'] ?? '0.1'); ?>" class="tw-mt-1 tw-block tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 sm:tw-text-sm">
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>

<script>
function switchTab(id) {
    // Update Hidden Input
    document.getElementById('current_tab').value = id;
    
    // UI Update - Navigation
    document.querySelectorAll('nav button').forEach(btn => {
        const isTarget = btn.id === 'nav-' + id;
        btn.className = isTarget 
            ? 'tw-group tw-flex tw-items-center tw-px-3 tw-py-3 tw-text-sm tw-font-medium tw-rounded-xl tw-transition-all tw-bg-white dark:tw-bg-gray-800 tw-text-primary tw-shadow-sm'
            : 'tw-group tw-flex tw-items-center tw-px-3 tw-py-3 tw-text-sm tw-font-medium tw-rounded-xl tw-transition-all tw-text-gray-600 dark:tw-text-gray-400 hover:tw-bg-gray-100 dark:hover:tw-bg-gray-800';
        
        const svg = btn.querySelector('svg');
        if(svg) svg.className = isTarget ? 'tw-h-6 tw-w-6 tw-text-primary' : 'tw-h-6 tw-w-6 tw-text-gray-400 group-hover:tw-text-gray-500';
    });

    // UI Update - Content
    document.querySelectorAll('.settings-tab').forEach(div => {
        if (div.id === 'tab-' + id) div.classList.remove('tw-hidden');
        else div.classList.add('tw-hidden');
    });

    // URL Update
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    window.history.replaceState({}, '', url);
}

function setWebhook(btn) {
    if(!confirm('确定要更新 Telegram Webhook 吗？')) return;
    if (btn) {
        btn.disabled = true;
        btn.dataset.oldText = btn.textContent;
        btn.textContent = '更新中...';
    }
    fetch('/api/tg_webhook.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=setup&csrf_token=' + encodeURIComponent('<?php echo $admin_csrf_token; ?>')
    }).then(r => r.json()).then(d => {
        alert(d.ok ? 'Webhook 设置成功！' : '设置失败: ' + d.description);
    }).catch(() => alert('网络错误')).finally(() => {
        if (btn) {
            btn.disabled = false;
            btn.textContent = btn.dataset.oldText || '更新 Webhook';
        }
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
