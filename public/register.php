<?php
session_start();
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/EmailNotificationService.php';
I18n::init();

$db = Database::getInstance();
$db->autoMigrate();
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';
$current_lang = I18n::getLang();
$lang_zh_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-cn']));
$lang_en_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'en']));

// Referral Logic: Capture from URL or Cookie
$referrer_code = $_GET['ref'] ?? $_COOKIE['ref_code'] ?? '';

// Update Cookie if ref is present in URL (validate before storing)
if (isset($_GET['ref'])) {
    $ref_val = (string)$_GET['ref'];
    if (preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $ref_val)) {
        setcookie('ref_code', $ref_val, time() + 86400 * 30, '/'); // 30 days
        $referrer_code = $ref_val;
    }
}

$error = '';
$success = '';
$selfParams = [];
if (isset($_GET['lang'])) {
    $selfParams['lang'] = (string)$_GET['lang'];
}
if (isset($_GET['ref'])) {
    $selfParams['ref'] = (string)$_GET['ref'];
}
$selfUrl = '/register.php' . (!empty($selfParams) ? ('?' . http_build_query($selfParams)) : '');
$redirectSelf = static function () use ($selfUrl): void {
    header('Location: ' . $selfUrl, true, 303);
    exit;
};

if (!empty($_SESSION['register_flash']) && is_array($_SESSION['register_flash'])) {
    $flash = $_SESSION['register_flash'];
    unset($_SESSION['register_flash']);
    $error = (string)($flash['error'] ?? '');
    $success = (string)($flash['success'] ?? '');
}

$buildBaseUrl = static function (): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host;
};
$buildActivateLink = static function (string $token) use ($buildBaseUrl): string {
    return $buildBaseUrl() . '/register.php?activate=' . urlencode($token);
};
$sendActivationEmail = static function (array $cfg, string $to, string $link, string $siteName): bool {
    $smtpEnabled = (string)($cfg['smtp_enabled'] ?? '0') === '1';
    if (!$smtpEnabled) {
        return false;
    }
    $smtp = [
        'host' => trim((string)($cfg['smtp_host'] ?? '')),
        'port' => (int)($cfg['smtp_port'] ?? 587),
        'username' => trim((string)($cfg['smtp_username'] ?? '')),
        'password' => (string)($cfg['smtp_password'] ?? ''),
        'encryption' => trim((string)($cfg['smtp_encryption'] ?? 'tls')),
        'from_name' => trim((string)($cfg['smtp_from_name'] ?? ($siteName ?: 'UAPI'))),
        'from_email' => trim((string)($cfg['smtp_from_email'] ?? '')),
    ];
    if ($smtp['host'] === '' || $smtp['port'] <= 0 || !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $subject = __('auth.register.mail.subject');
    $html = '<p>' . __('auth.register.mail.greeting') . '</p>'
        . '<p>' . __('auth.register.mail.desc') . '</p>'
        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:10px 16px;border-radius:8px;background:#2563eb;color:#fff;text-decoration:none;font-weight:600;">'
        . __('auth.register.mail.button')
        . '</a></p>'
        . '<p style="word-break:break-all;color:#64748b;font-size:12px;">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#64748b;font-size:12px;">' . __('auth.register.mail.expire') . '</p>';
    return EmailNotificationService::sendUsingConfig($smtp, $to, $subject, $html);
};

if (isset($_GET['activate'])) {
    $token = trim((string)($_GET['activate'] ?? ''));
    if ($token === '') {
        $_SESSION['register_flash'] = ['error' => __('auth.register.activate.invalid')];
        $redirectSelf();
    }
    $u = $db->fetch(
        "SELECT id, email_verified, email_verify_expires_at FROM users WHERE email_verify_token = ? LIMIT 1",
        [$token]
    );
    if (!$u) {
        $_SESSION['register_flash'] = ['error' => __('auth.register.activate.invalid')];
        $redirectSelf();
    }
    if ((int)($u['email_verified'] ?? 0) === 1) {
        $_SESSION['register_flash'] = ['success' => __('auth.register.activate.already')];
        $redirectSelf();
    }
    $exp = strtotime((string)($u['email_verify_expires_at'] ?? ''));
    if ($exp <= 0 || $exp < time()) {
        $_SESSION['register_flash'] = ['error' => __('auth.register.activate.expired')];
        $redirectSelf();
    }
    $db->query(
        "UPDATE users
         SET email_verified = 1, email_verified_at = NOW(), email_verify_token = NULL, email_verify_expires_at = NULL
         WHERE id = ? LIMIT 1",
        [(int)$u['id']]
    );
    $_SESSION['login_flash'] = ['success' => __('auth.register.activate.success')];
    header('Location: /login.php', true, 303);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // Webhook input removed
    $webhook = '';
    $captcha = trim($_POST['captcha'] ?? '');
    
    // Get referrer code from POST (hidden input) or fallback to cookie
    $referrer_code = $_POST['referrer_code'] ?? $referrer_code;

    if (empty($_SESSION['captcha']) || $captcha !== $_SESSION['captcha']) {
        $_SESSION['register_flash'] = ['error' => __('auth.register.error.captcha')];
        $redirectSelf();
    }
    unset($_SESSION['captcha']); // one-time use: prevent captcha replay

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        $_SESSION['register_flash'] = ['error' => __('auth.register.error.invalid_email_password')];
        $redirectSelf();
    } else {
        $exist = $db->fetch("SELECT id, email_verified, email_verify_expires_at FROM users WHERE email = ? LIMIT 1", [$email]);
        if ($exist) {
            // Allow re-registration if account is unverified and token has expired
            $isUnverified = (int)($exist['email_verified'] ?? 0) === 0;
            $expTs = strtotime((string)($exist['email_verify_expires_at'] ?? ''));
            if ($isUnverified && $expTs > 0 && $expTs < time()) {
                $db->query("DELETE FROM users WHERE id = ? AND email_verified = 0 LIMIT 1", [(int)$exist['id']]);
                $exist = null;
            }
        }
        if ($exist) {
            $_SESSION['register_flash'] = ['error' => __('auth.register.error.email_exists')];
            $redirectSelf();
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $api_key = 'pk_live_' . bin2hex(random_bytes(16));
            
            // Generate NEW User's Referral Code (ensure DB uniqueness)
            do {
                $new_user_ref_code = substr(bin2hex(random_bytes(4)), 0, 8);
                $codeConflict = $db->fetch("SELECT id FROM users WHERE ref_code = ? LIMIT 1", [$new_user_ref_code]);
            } while ($codeConflict);
            
            // Generate Random ID (1000-9999) and ensure uniqueness
            $new_id = 0;
            $max_retries = 20;
            $digits = 4;
            
            for ($i = 0; $i < $max_retries; $i++) {
                $min = pow(10, $digits - 1);
                $max = pow(10, $digits) - 1;
                $try_id = rand($min, $max);
                
                $chk = $db->fetch("SELECT id FROM users WHERE id = ?", [$try_id]);
                if (!$chk) {
                    $new_id = $try_id;
                    break;
                }
                
                if ($i == $max_retries - 1) {
                    $digits++;
                    $i = -1;
                    if ($digits > 8) break;
                }
            }
            
            // Check Referrer
            $ref_by = null;
            if (!empty($referrer_code)) {
                $referrer = $db->fetch("SELECT id FROM users WHERE ref_code = ?", [$referrer_code]);
                if ($referrer) {
                    $ref_by = $referrer['id'];
                }
            }
            
            $verifyToken = bin2hex(random_bytes(32));
            $verifyExpireAt = date('Y-m-d H:i:s', time() + 3600);
            $activateLink = $buildActivateLink($verifyToken);

            // Insert unverified account
            $db->query(
                "INSERT INTO users (id, email, password_hash, api_key, webhook_url, plan_id, ref_code, ref_by, email_verified, email_verify_token, email_verify_expires_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?, 0, ?, ?)",
                [$new_id, $email, $hash, $api_key, $webhook, $new_user_ref_code, $ref_by, $verifyToken, $verifyExpireAt]
            );

            $mailOk = $sendActivationEmail($cfg, $email, $activateLink, (string)$site_name);
            if (!$mailOk) {
                $db->query("DELETE FROM users WHERE id = ? LIMIT 1", [$new_id]);
                $_SESSION['register_flash'] = ['error' => __('auth.register.error.mail_send_failed')];
                $redirectSelf();
            }

            $_SESSION['register_flash'] = ['success' => __('auth.register.success.activation_sent')];
            $redirectSelf();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_name); ?> - <?php echo __('auth.register.title'); ?></title>
    <?php if (!empty($cfg['site_favicon'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($cfg['site_favicon']); ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/lang-switch.css">
    <style>
        body { background-color: #f0f2f5; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px 0; }
        .login-card { width: 100%; max-width: 450px; padding: 2rem; border-radius: 10px; background: white; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .logo { font-size: 2rem; font-weight: bold; color: #0d6efd; text-align: center; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: center; }
        .logo img { max-height: 80px; width: auto; margin-right: 10px; }
        .submit-loading-mask {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.35);
            backdrop-filter: blur(2px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .submit-loading-mask.show { display: flex; }
        .submit-loading-card {
            width: min(92vw, 420px);
            background: #fff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 12px 36px rgba(15, 23, 42, 0.22);
            padding: 18px 16px;
            text-align: center;
        }
        .submit-loading-spinner {
            width: 36px;
            height: 36px;
            border: 3px solid #dbeafe;
            border-top-color: #2563eb;
            border-radius: 999px;
            margin: 0 auto 10px;
            animation: uapi-spin 0.8s linear infinite;
        }
        .submit-loading-title {
            font-size: 16px;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 6px;
        }
        .submit-loading-desc {
            font-size: 13px;
            color: #64748b;
            line-height: 1.65;
        }
        @keyframes uapi-spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div id="registerSubmitLoadingMask" class="submit-loading-mask" aria-hidden="true">
        <div class="submit-loading-card" role="status" aria-live="polite">
            <div class="submit-loading-spinner"></div>
            <div class="submit-loading-title">正在注册并发送激活邮件</div>
            <div class="submit-loading-desc">请勿刷新或关闭页面，系统正在处理您的请求…</div>
        </div>
    </div>
    <div class="login-card">
        <div class="logo">
            <?php if ($site_logo): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="logo">
            <?php else: ?>
                <i class="fas fa-wallet me-2"></i> <?php echo htmlspecialchars($site_name); ?>
            <?php endif; ?>
        </div>
        
        <h5 class="text-center mb-4 text-muted"><?php echo __('auth.register.subtitle'); ?></h5>

        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" id="registerForm">
            <!-- Hidden Referrer Code -->
            <input type="hidden" name="referrer_code" id="referrerCodeInput" value="<?php echo htmlspecialchars($referrer_code); ?>">

            <div class="mb-3">
                <label class="form-label"><?php echo __('auth.common.email'); ?></label>
                <input type="email" name="email" class="form-control" required placeholder="you@example.com">
            </div>
            <div class="mb-3">
                <label class="form-label"><?php echo __('auth.common.password'); ?></label>
                <input type="password" name="password" class="form-control" required placeholder="<?php echo __('auth.register.password_placeholder'); ?>">
            </div>
            <!-- Webhook removed as per request -->
            <div class="mb-3">
                <label class="form-label"><?php echo __('auth.common.captcha'); ?></label>
                <div class="input-group">
                    <input type="text" name="captcha" class="form-control" required placeholder="<?php echo __('auth.common.captcha_placeholder'); ?>">
                    <img src="captcha.php" onclick="this.src='captcha.php?'+Math.random()" style="cursor:pointer; height: 38px;">
                </div>
            </div>
            <button type="submit" id="registerSubmitBtn" class="btn btn-primary w-100"><?php echo __('auth.register.submit'); ?></button>
        </form>
        <div class="d-flex justify-content-center mt-3">
            <?php include __DIR__ . '/includes/lang_switcher.php'; ?>
        </div>
        <div class="text-center mt-3">
            <?php echo __('auth.register.has_account'); ?><a href="login.php"><?php echo __('auth.register.go_login'); ?></a>
        </div>
    </div>
    <link href="/assets/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // 1. Try to get ref from URL
        const urlParams = new URLSearchParams(window.location.search);
        let ref = urlParams.get('ref');

        // 2. If present, save to localStorage and update input
        if (ref) {
            localStorage.setItem('uapi_ref_code', ref);
            document.getElementById('referrerCodeInput').value = ref;
        } else {
            // 3. If not in URL, try to recover from localStorage
            const savedRef = localStorage.getItem('uapi_ref_code');
            if (savedRef) {
                // Only update if input is empty (PHP cookie might have filled it already, but localstorage is safer backup)
                if (!document.getElementById('referrerCodeInput').value) {
                    document.getElementById('referrerCodeInput').value = savedRef;
                }
            }
        }
        
        // Optional: Ensure form submission carries the value
        const registerForm = document.getElementById('registerForm');
        const registerSubmitBtn = document.getElementById('registerSubmitBtn');
        const loadingMask = document.getElementById('registerSubmitLoadingMask');
        let submitting = false;

        registerForm.addEventListener('submit', function(e) {
            if (submitting) {
                e.preventDefault();
                return;
            }
            if (!registerForm.checkValidity()) {
                return;
            }
            submitting = true;
            if (registerSubmitBtn) {
                registerSubmitBtn.disabled = true;
                registerSubmitBtn.classList.add('opacity-75');
            }
            if (loadingMask) {
                loadingMask.classList.add('show');
                loadingMask.setAttribute('aria-hidden', 'false');
            }

            const finalRef = document.getElementById('referrerCodeInput').value;
            if (finalRef && !localStorage.getItem('uapi_ref_code')) {
                 localStorage.setItem('uapi_ref_code', finalRef);
            }
        });
    });
    </script>
</body>
</html>
