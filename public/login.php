<?php
session_start();

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/TotpService.php';
require_once __DIR__ . '/../src/Services/User2FAService.php';
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

$error = '';
$success = '';
$show2faModal = false;
$pending2faEmail = '';
$selfUrl = (string)($_SERVER['REQUEST_URI'] ?? '/login.php');
$redirectSelf = static function () use ($selfUrl): void {
    header('Location: ' . $selfUrl, true, 303);
    exit;
};

if (!empty($_SESSION['login_flash']) && is_array($_SESSION['login_flash'])) {
    $flash = $_SESSION['login_flash'];
    unset($_SESSION['login_flash']);
    $error = (string)($flash['error'] ?? '');
    $success = (string)($flash['success'] ?? '');
}

if (isset($_SESSION['user_id'])) {
    $current = $db->fetch("SELECT role FROM users WHERE id = ?", [(int)$_SESSION['user_id']]);
    if (($current['role'] ?? '') === 'admin') {
        header("Location: /admin/index.php");
    } else {
        header("Location: dashboard.php");
    }
    exit;
}

if (isset($_GET['cancel2fa']) && $_GET['cancel2fa'] === '1') {
    unset($_SESSION['login_2fa_pending']);
    header('Location: /login.php', true, 303);
    exit;
}

if (!empty($_SESSION['login_2fa_pending']) && is_array($_SESSION['login_2fa_pending'])) {
    $show2faModal = true;
    $pending2faEmail = (string)($_SESSION['login_2fa_pending']['email'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? 'login'));

    if ($action === 'cancel_2fa') {
        unset($_SESSION['login_2fa_pending']);
        header('Location: /login.php', true, 303);
        exit;
    } elseif ($action === 'verify_2fa') {
        $pending = $_SESSION['login_2fa_pending'] ?? null;
        if (!$pending || !is_array($pending)) {
            $_SESSION['login_flash'] = ['error' => '登录会话已失效，请重新登录。'];
            $redirectSelf();
        } else {
            $createdAt = (int)($pending['ts'] ?? 0);
            if ($createdAt <= 0 || (time() - $createdAt) > 600) {
                unset($_SESSION['login_2fa_pending']);
                $_SESSION['login_flash'] = ['error' => '验证超时，请重新登录。'];
                $redirectSelf();
            } else {
                $uid = (int)($pending['user_id'] ?? 0);
                $otpCodeRaw = trim((string)($_POST['otp_code'] ?? ''));
                $otpCode = preg_replace('/\D+/', '', $otpCodeRaw);
                $user = $db->fetch("SELECT * FROM users WHERE id = ? LIMIT 1", [$uid]);
                if (!$user || !User2FAService::isSceneEnabled($user, 'login')) {
                    unset($_SESSION['login_2fa_pending']);
                    $_SESSION['login_flash'] = ['error' => '该账户未开启登录二次验证，请重新登录。'];
                    $redirectSelf();
                } elseif (!TotpService::verifyCode(trim((string)($user['two_factor_secret'] ?? '')), $otpCode, 1)) {
                    $_SESSION['login_flash'] = ['error' => '谷歌验证码错误，请重试。'];
                    $redirectSelf();
                } else {
                    unset($_SESSION['login_2fa_pending']);
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['email'] = (string)$user['email'];
                    if (($user['role'] ?? '') === 'admin') {
                        header("Location: /admin/index.php");
                    } else {
                        header("Location: dashboard.php");
                    }
                    exit;
                }
            }
        }
    } else {
        unset($_SESSION['login_2fa_pending']);
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $captcha = trim((string)($_POST['captcha'] ?? ''));

        if (empty($_SESSION['captcha']) || $captcha !== $_SESSION['captcha']) {
            $_SESSION['login_flash'] = ['error' => __('auth.login.error.captcha')];
            $redirectSelf();
        } else {
            unset($_SESSION['captcha']); // one-time use: prevent captcha replay
            $user = $db->fetch("SELECT * FROM users WHERE email = ?", [$email]);

            if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
                $_SESSION['login_flash'] = ['error' => __('auth.login.error.locked')];
                $redirectSelf();
            }

            if ($user && password_verify($password, $user['password_hash'])) {
                $db->query("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?", [$user['id']]);
                if ((int)($user['email_verified'] ?? 1) !== 1) {
                    $_SESSION['login_flash'] = ['error' => __('auth.login.error.email_not_verified')];
                    $redirectSelf();
                }
                if (User2FAService::isSceneEnabled($user, 'login')) {
                    $_SESSION['login_2fa_pending'] = [
                        'user_id' => (int)$user['id'],
                        'email' => (string)$user['email'],
                        'role' => (string)($user['role'] ?? 'user'),
                        'ts' => time(),
                    ];
                    $redirectSelf();
                } else {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['email'] = $user['email'];
                    if (($user['role'] ?? '') === 'admin') {
                        header("Location: /admin/index.php");
                    } else {
                        header("Location: dashboard.php");
                    }
                    exit;
                }
            } else {
                if ($user) {
                    $failures = ((int)($user['failed_attempts'] ?? 0)) + 1;
                    $lockedUntil = $failures >= 5 ? date('Y-m-d H:i:s', strtotime('+15 minutes')) : null;
                    $db->query("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?", [$failures, $lockedUntil, $user['id']]);
                }
                $_SESSION['login_flash'] = ['error' => __('auth.login.error.invalid_credentials')];
                $redirectSelf();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($site_name); ?> - <?php echo __('auth.login.title'); ?></title>
    <?php if (!empty($cfg['site_favicon'])): ?>
    <link rel="icon" href="<?php echo htmlspecialchars($cfg['site_favicon']); ?>">
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/lang-switch.css">
    <style>
        body { background-color: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; }
        .login-card { width: 100%; max-width: 400px; padding: 2rem; border-radius: 10px; background: white; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .logo { font-size: 2rem; font-weight: bold; color: #0d6efd; text-align: center; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: center; }
        .logo img { max-height: 80px; width: auto; margin-right: 10px; }
        .login2fa-dialog { max-width: 430px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo">
            <?php if ($site_logo): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="logo">
            <?php else: ?>
                <i class="fas fa-wallet me-2"></i> <?php echo htmlspecialchars($site_name); ?>
            <?php endif; ?>
        </div>
        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="mb-3">
                <label class="form-label"><?php echo __('auth.common.email'); ?></label>
                <input type="email" name="email" class="form-control" required placeholder="admin@example.com">
            </div>
            <div class="mb-3">
                <label class="form-label"><?php echo __('auth.common.password'); ?></label>
                <input type="password" name="password" class="form-control" required placeholder="<?php echo __('auth.login.password_placeholder'); ?>">
            </div>
            <div class="mb-3">
                <label class="form-label"><?php echo __('auth.common.captcha'); ?></label>
                <div class="input-group">
                    <input type="text" name="captcha" class="form-control" required placeholder="<?php echo __('auth.common.captcha_placeholder'); ?>">
                    <img src="captcha.php" onclick="this.src='captcha.php?'+Math.random()" style="cursor:pointer; height: 38px;">
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100"><?php echo __('auth.login.submit'); ?></button>
        </form>
        <div class="d-flex justify-content-center mt-3">
            <?php include __DIR__ . '/includes/lang_switcher.php'; ?>
        </div>
        <div class="text-center mt-3">
            <?php echo __('auth.login.no_account'); ?><a href="register.php"><?php echo __('auth.login.go_register'); ?></a>
        </div>
    </div>
    <link href="/assets/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <div class="modal fade" id="login2faModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered login2fa-dialog">
        <div class="modal-content">
          <form method="POST">
            <div class="modal-header">
              <h5 class="modal-title">谷歌验证器验证</h5>
              <button type="button" class="btn-close" aria-label="Close" onclick="window.location.href='/login.php?cancel2fa=1'"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="action" value="verify_2fa">
              <div class="mb-2 text-muted small">账户：<?php echo htmlspecialchars($pending2faEmail); ?></div>
              <label class="form-label">请输入 6 位动态码</label>
              <input type="text" name="otp_code" class="form-control" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6 位动态码" required autofocus oninput="this.value=this.value.replace(/[^0-9]/g,'').slice(0,6)">
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary flex-fill" onclick="window.location.href='/login.php?cancel2fa=1'">取消</button>
              <button type="submit" class="btn btn-primary flex-fill">验证并登录</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php if ($show2faModal): ?>
    <script>
      document.addEventListener('DOMContentLoaded', function() {
        var m = new bootstrap.Modal(document.getElementById('login2faModal'), {backdrop: true, keyboard: true});
        m.show();
      });
    </script>
    <?php endif; ?>
</body>
</html>
