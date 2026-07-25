<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/TotpService.php';
require_once __DIR__ . '/../src/Services/User2FAService.php';
require_once __DIR__ . '/../src/Services/NotificationDispatcher.php';
require_once __DIR__ . '/../src/Helper.php';
I18n::init();

$db = Database::getInstance();
$user_id = (int)$_SESSION['user_id'];

$ensureColumn = static function (string $table, string $column, string $definition) use ($db): void {
    try {
        $exists = $db->fetch(
            "SELECT 1 AS ok FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1",
            [$table, $column]
        );
        if (!$exists) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    } catch (Throwable $ignore) {
    }
};

$ensureColumn('users', 'full_name', "VARCHAR(120) DEFAULT NULL");
$ensureColumn('users', 'phone', "VARCHAR(32) DEFAULT NULL");
$ensureColumn('users', 'company_name', "VARCHAR(120) DEFAULT NULL");
$ensureColumn('users', 'country_region', "VARCHAR(80) DEFAULT NULL");
$ensureColumn('users', 'user_timezone', "VARCHAR(64) DEFAULT NULL");
$ensureColumn('users', 'two_factor_enabled', "TINYINT(1) DEFAULT 0");
$ensureColumn('users', 'two_factor_secret', "VARCHAR(64) DEFAULT NULL");
$ensureColumn('users', 'two_factor_enabled_at', "DATETIME DEFAULT NULL");
$ensureColumn('users', 'two_factor_scenes', "TEXT NULL");

$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';
if (!empty($_SESSION['settings_flash']) && is_array($_SESSION['settings_flash'])) {
    $flash = $_SESSION['settings_flash'];
    unset($_SESSION['settings_flash']);
    if (($flash['type'] ?? '') === 'success') {
        $message = (string)($flash['message'] ?? '');
    } elseif (($flash['type'] ?? '') === 'error') {
        $error = (string)($flash['message'] ?? '');
    }
}

$siteCfgRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$siteCfg = [];
foreach ($siteCfgRows as $row) {
    $siteCfg[(string)$row['key_name']] = (string)$row['value'];
}
$issuer = trim((string)($siteCfg['site_name'] ?? 'UAPI'));

$pendingSecret = (string)($_SESSION['settings_2fa_pending_secret'] ?? '');
if ($pendingSecret === '' && empty($user['two_factor_enabled'])) {
    $pendingSecret = TotpService::generateSecret();
    $_SESSION['settings_2fa_pending_secret'] = $pendingSecret;
}

$is2faEnabled = User2FAService::isEnabled($user);
$twoFactorScenes = User2FAService::parseScenes($user);
$settingsNeedOtp = User2FAService::isSceneEnabled($user, 'settings_security');

$require2faForSensitive = static function (array $currentUser, string $otp) {
    return User2FAService::verifyForScene($currentUser, 'settings_security', $otp);
};

$pushNotify = static function (int $uid, string $title, string $content): void {
    try {
        NotificationDispatcher::notifyUser($uid, [
            'type' => 'security',
            'in_app_type' => 'security',
            'title' => $title,
            'content' => $content,
            'subject' => $title,
            'dedupe_like' => $title . '-' . date('Y-m-d'),
        ]);
    } catch (Throwable $ignore) {
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Helper::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        header("Location: settings.php?msg=csrf_invalid");
        exit;
    }
    $action = trim((string)($_POST['action'] ?? ''));

    try {
        if ($action === 'save_profile') {
            [$ok, $msg] = $require2faForSensitive($user, trim((string)($_POST['security_otp'] ?? '')));
            if (!$ok) {
                throw new Exception($msg);
            }

            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $phone = trim((string)($_POST['phone'] ?? ''));
            $companyName = trim((string)($_POST['company_name'] ?? ''));
            $countryRegion = trim((string)($_POST['country_region'] ?? ''));
            $userTimezone = trim((string)($_POST['user_timezone'] ?? 'Asia/Shanghai'));

            if ($phone !== '' && !preg_match('/^[0-9+\-()\s]{6,20}$/', $phone)) {
                throw new Exception(__('merchant.settings.error.invalid_phone'));
            }

            $db->query(
                "UPDATE users SET full_name = ?, phone = ?, company_name = ?, country_region = ?, user_timezone = ? WHERE id = ?",
                [$fullName, $phone, $companyName, $countryRegion, $userTimezone, $user_id]
            );
            $message = __('merchant.settings.success.profile_saved');
            $pushNotify($user_id, __('merchant.settings.notify.profile_title'), __('merchant.settings.notify.profile_body'));
        } elseif ($action === 'save_payout') {
            [$ok, $msg] = $require2faForSensitive($user, trim((string)($_POST['security_otp'] ?? '')));
            if (!$ok) {
                throw new Exception($msg);
            }

            $addr = trim((string)($_POST['withdraw_address'] ?? ''));
            $net = strtoupper(trim((string)($_POST['withdraw_network'] ?? 'TRC20')));
            $binanceUid = trim((string)($_POST['binance_uid'] ?? ''));

            if ($addr === '') {
                throw new Exception(__('merchant.settings.error.withdraw_required'));
            }
            if (!in_array($net, ['TRC20', 'ERC20', 'BEP20', 'SOL'], true)) {
                $net = 'TRC20';
            }
            if ($binanceUid !== '' && !preg_match('/^\d{4,30}$/', $binanceUid)) {
                throw new Exception(__('merchant.settings.error.binance_uid_invalid'));
            }

            $db->query(
                "UPDATE users SET withdraw_address = ?, withdraw_network = ?, binance_uid = ? WHERE id = ?",
                [$addr, $net, $binanceUid !== '' ? $binanceUid : null, $user_id]
            );
            $message = __('merchant.settings.success.payout_saved');
            $pushNotify($user_id, __('merchant.settings.notify.payout_title'), __('merchant.settings.notify.payout_body'));
        } elseif ($action === 'enable_2fa') {
            $secret = trim((string)($_SESSION['settings_2fa_pending_secret'] ?? ''));
            $code = trim((string)($_POST['otp_code'] ?? ''));
            if ($secret === '') {
                throw new Exception(__('merchant.settings.error.pending_secret_missing'));
            }
            if (!TotpService::verifyCode($secret, $code, 1)) {
                throw new Exception(__('merchant.settings.error.invalid_totp_retry'));
            }
            $sceneInput = User2FAService::buildScenesFromInput((array)($_POST['two_factor_scenes'] ?? []));
            $db->query(
                "UPDATE users SET two_factor_enabled = 1, two_factor_secret = ?, two_factor_enabled_at = NOW(), two_factor_scenes = ? WHERE id = ?",
                [$secret, json_encode($sceneInput, JSON_UNESCAPED_UNICODE), $user_id]
            );
            unset($_SESSION['settings_2fa_pending_secret']);
            $message = __('merchant.settings.success.twofa_enabled');
            $pushNotify($user_id, __('merchant.settings.notify.twofa_enabled_title'), __('merchant.settings.notify.twofa_enabled_body'));
        } elseif ($action === 'disable_2fa') {
            $secret = trim((string)($user['two_factor_secret'] ?? ''));
            $code = trim((string)($_POST['disable_otp_code'] ?? ''));
            if ($secret === '' || !TotpService::verifyCode($secret, $code, 1)) {
                throw new Exception(__('merchant.settings.error.disable_2fa_invalid'));
            }
            $db->query(
                "UPDATE users SET two_factor_enabled = 0, two_factor_secret = NULL, two_factor_enabled_at = NULL WHERE id = ?",
                [$user_id]
            );
            $_SESSION['settings_2fa_pending_secret'] = TotpService::generateSecret();
            $message = __('merchant.settings.success.twofa_disabled');
            $pushNotify($user_id, __('merchant.settings.notify.twofa_disabled_title'), __('merchant.settings.notify.twofa_disabled_body'));
        } elseif ($action === 'save_2fa_scenes') {
            if (!$is2faEnabled) {
                throw new Exception(__('merchant.settings.error.enable_2fa_first'));
            }
            $sceneOtp = trim((string)($_POST['scene_otp_code'] ?? ''));
            if (!TotpService::verifyCode(trim((string)($user['two_factor_secret'] ?? '')), $sceneOtp, 1)) {
                throw new Exception(__('merchant.settings.error.scene_otp_invalid'));
            }
            $sceneInput = User2FAService::buildScenesFromInput((array)($_POST['two_factor_scenes'] ?? []));
            $db->query(
                "UPDATE users SET two_factor_scenes = ? WHERE id = ?",
                [json_encode($sceneInput, JSON_UNESCAPED_UNICODE), $user_id]
            );
            $message = __('merchant.settings.success.twofa_scenes_saved');
            $pushNotify($user_id, __('merchant.settings.notify.twofa_scenes_title'), __('merchant.settings.notify.twofa_scenes_body'));
        } elseif ($action === 'save_pay_brand') {
            $brandColor = trim((string)($_POST['brand_color'] ?? ''));
            $brandNote  = trim((string)($_POST['brand_note'] ?? ''));
            // Validate color: must be a valid hex like #rrggbb or #rgb
            if ($brandColor !== '' && !preg_match('/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $brandColor)) {
                $brandColor = '';
            }
            $brandNote = substr($brandNote, 0, 200);
            $colorKey = 'merchant_brand_color_u' . $user_id;
            $noteKey  = 'merchant_brand_note_u'  . $user_id;
            foreach ([[$colorKey, $brandColor], [$noteKey, $brandNote]] as [$k, $v]) {
                $exists = $db->fetch("SELECT 1 FROM system_settings WHERE key_name = ? LIMIT 1", [$k]);
                if ($exists) {
                    $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$v, $k]);
                } else {
                    $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$k, $v]);
                }
            }
            $message = '收款页品牌设置已保存';
        }

        $user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
        $is2faEnabled = User2FAService::isEnabled($user);
        $twoFactorScenes = User2FAService::parseScenes($user);
        $settingsNeedOtp = User2FAService::isSceneEnabled($user, 'settings_security');
        $pendingSecret = (string)($_SESSION['settings_2fa_pending_secret'] ?? '');
        if ($pendingSecret === '' && !$is2faEnabled) {
            $pendingSecret = TotpService::generateSecret();
            $_SESSION['settings_2fa_pending_secret'] = $pendingSecret;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    $_SESSION['settings_flash'] = [
        'type' => $error !== '' ? 'error' : 'success',
        'message' => $error !== '' ? $error : $message,
    ];
    header('Location: settings.php', true, 303);
    exit;
}

$showSecret = $is2faEnabled ? trim((string)$user['two_factor_secret']) : $pendingSecret;
$otpAuthUrl = TotpService::getOtpAuthUrl($issuer, (string)$user['email'], $showSecret);
$qrImage = 'https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' . rawurlencode($otpAuthUrl);

$page_title = __('merchant.settings.title');
?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
      .settings-wrap { width: 100%; max-width: none; margin: 0; padding-left: 10px; padding-right: 10px; }
      .bn-card { border: 1px solid #eceff3; border-radius: 16px; background: #fff; box-shadow: 0 10px 24px rgba(16,24,40,.05); }
      .bn-title { font-size: 18px; font-weight: 700; color: #1e2329; }
      .bn-sub { color: #667085; font-size: 13px; }
      .bn-head { padding: 14px 18px; border-bottom: 1px solid #f1f5f9; background: linear-gradient(180deg,#fffdfa 0%,#ffffff 100%); }
      .bn-body { padding: 20px; }
      .bn-chip { background: #fff7dd; color: #7a5600; border: 1px solid #f0d999; border-radius: 999px; font-size: 12px; padding: 4px 10px; }
      .otp-secret { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; letter-spacing: .6px; font-size: 12px; }
      .sensitive-tip { font-size: 12px; color: #B54708; background: #FFF7ED; border: 1px solid #FED7AA; border-radius: 8px; padding: 8px 10px; }
      .twofa-grid { display: grid; grid-template-columns: 180px 1fr; gap: 16px; align-items: start; }
      .twofa-qr { width: 180px; height: 180px; object-fit: contain; max-width: 100%; }
      .twofa-meta { min-width: 0; }
      .twofa-meta .input-group .form-control { min-width: 0; }
      @media (max-width: 992px) {
        .settings-wrap { padding-left: 0; padding-right: 0; }
      }
      @media (max-width: 768px) {
        .twofa-grid { grid-template-columns: 1fr; }
        .twofa-qr-wrap { text-align: center; }
        .twofa-qr { width: 160px; height: 160px; }
      }
    </style>
</head>
<body>
<div class="container-fluid g-0">
  <div class="row g-0">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="col-md-9 col-lg-10 main-content">
      <?php include __DIR__ . '/includes/user_topbar.php'; ?>

      <div class="settings-wrap p-3 p-lg-4">
        <?php if($message): ?><div class="alert alert-success mb-3"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger mb-3"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="row g-3">
          <div class="col-12 col-xxl-8">
            <div class="bn-card h-100">
              <div class="bn-head d-flex justify-content-between align-items-center">
                <div>
                  <div class="bn-title"><?php echo __('merchant.settings.profile_title'); ?></div>
                  <div class="bn-sub"><?php echo __('merchant.settings.profile_desc'); ?></div>
                </div>
                <span class="bn-chip"><?php echo __('merchant.settings.profile_chip'); ?></span>
              </div>
              <div class="bn-body">
                <form method="post" class="row g-3"><?php echo Helper::csrfField(); ?>
                  <input type="hidden" name="action" value="save_profile">
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.login_email'); ?></label>
                    <input class="form-control" value="<?php echo htmlspecialchars((string)$user['email']); ?>" readonly>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.merchant_id'); ?></label>
                    <input class="form-control" value="<?php echo (int)$user['id']; ?>" readonly>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.full_name'); ?></label>
                    <input class="form-control" name="full_name" maxlength="120" value="<?php echo htmlspecialchars((string)($user['full_name'] ?? '')); ?>" placeholder="<?php echo __('merchant.settings.full_name_placeholder'); ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.phone'); ?></label>
                    <input class="form-control" name="phone" maxlength="32" value="<?php echo htmlspecialchars((string)($user['phone'] ?? '')); ?>" placeholder="<?php echo __('merchant.settings.phone_placeholder'); ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.company_name'); ?></label>
                    <input class="form-control" name="company_name" maxlength="120" value="<?php echo htmlspecialchars((string)($user['company_name'] ?? '')); ?>" placeholder="<?php echo __('merchant.settings.company_name_placeholder'); ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.country_region'); ?></label>
                    <input class="form-control" name="country_region" maxlength="80" value="<?php echo htmlspecialchars((string)($user['country_region'] ?? '')); ?>" placeholder="<?php echo __('merchant.settings.country_region_placeholder'); ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.timezone'); ?></label>
                    <select class="form-select" name="user_timezone">
                      <?php
                        $tzList = ['Asia/Shanghai','Asia/Hong_Kong','Asia/Singapore','UTC','America/New_York','Europe/London'];
                        $tzVal = (string)($user['user_timezone'] ?? 'Asia/Shanghai');
                        foreach ($tzList as $tz) {
                            $selected = $tz === $tzVal ? 'selected' : '';
                            echo '<option value="' . htmlspecialchars($tz) . '" ' . $selected . '>' . htmlspecialchars($tz) . '</option>';
                        }
                      ?>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.register_time'); ?></label>
                    <input class="form-control" value="<?php echo htmlspecialchars((string)($user['created_at'] ?? '-')); ?>" readonly>
                  </div>

                  <?php if ($settingsNeedOtp): ?>
                  <div class="col-12">
                    <div class="sensitive-tip mb-2"><?php echo __('merchant.settings.security_otp_required'); ?></div>
                    <label class="form-label"><?php echo __('merchant.settings.security_otp_label'); ?></label>
                    <input class="form-control" name="security_otp" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="<?php echo __('merchant.settings.security_otp_placeholder'); ?>" required>
                  </div>
                  <?php endif; ?>

                  <div class="col-12 d-grid d-md-flex justify-content-md-end">
                    <button class="btn btn-primary px-4" type="submit"><?php echo __('merchant.settings.save_profile'); ?></button>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <div class="col-12 col-xxl-4">
            <div class="bn-card mb-3">
              <div class="bn-head d-flex justify-content-between align-items-center">
                <div>
                  <div class="bn-title"><?php echo __('merchant.settings.payout_title'); ?></div>
                  <div class="bn-sub"><?php echo __('merchant.settings.payout_desc'); ?></div>
                </div>
                <span class="bn-chip"><?php echo __('merchant.settings.payout_chip'); ?></span>
              </div>
              <div class="bn-body">
                <form method="post" class="row g-3"><?php echo Helper::csrfField(); ?>
                  <input type="hidden" name="action" value="save_payout">
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.withdraw_network'); ?></label>
                    <select name="withdraw_network" class="form-select">
                      <option value="TRC20" <?php echo (($user['withdraw_network'] ?? 'TRC20') === 'TRC20') ? 'selected' : ''; ?>>TRON (TRC20)</option>
                      <option value="ERC20" <?php echo (($user['withdraw_network'] ?? '') === 'ERC20') ? 'selected' : ''; ?>>Ethereum (ERC20)</option>
                      <option value="BEP20" <?php echo (($user['withdraw_network'] ?? '') === 'BEP20') ? 'selected' : ''; ?>>BSC (BEP20)</option>
                      <option value="SOL" <?php echo (($user['withdraw_network'] ?? '') === 'SOL') ? 'selected' : ''; ?>>Solana</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label class="form-label"><?php echo __('merchant.settings.binance_uid'); ?></label>
                    <input type="text" name="binance_uid" class="form-control" value="<?php echo htmlspecialchars((string)($user['binance_uid'] ?? '')); ?>" placeholder="<?php echo __('merchant.settings.binance_uid_placeholder'); ?>">
                  </div>
                  <div class="col-12">
                    <label class="form-label"><?php echo __('merchant.settings.withdraw_address'); ?></label>
                    <input type="text" name="withdraw_address" class="form-control" value="<?php echo htmlspecialchars((string)($user['withdraw_address'] ?? '')); ?>" placeholder="<?php echo __('merchant.settings.withdraw_address_placeholder'); ?>" required>
                  </div>

                  <?php if ($settingsNeedOtp): ?>
                  <div class="col-12">
                    <label class="form-label"><?php echo __('merchant.settings.security_otp_label'); ?></label>
                    <input class="form-control" name="security_otp" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="<?php echo __('merchant.settings.security_otp_placeholder'); ?>" required>
                  </div>
                  <?php endif; ?>

                  <div class="col-12 d-grid">
                    <button class="btn btn-primary" type="submit"><?php echo __('merchant.settings.save_payout'); ?></button>
                  </div>
                </form>
              </div>
            </div>

            <div class="bn-card">
              <div class="bn-head d-flex justify-content-between align-items-center">
                <div>
                  <div class="bn-title"><?php echo __('merchant.settings.twofa_title'); ?></div>
                  <div class="bn-sub"><?php echo __('merchant.settings.twofa_desc'); ?></div>
                </div>
                <span class="bn-chip"><?php echo $is2faEnabled ? __('merchant.settings.twofa_enabled') : __('merchant.settings.twofa_disabled'); ?></span>
              </div>
              <div class="bn-body">
                <?php if (!$is2faEnabled): ?>
                  <div class="twofa-grid">
                    <div class="twofa-qr-wrap">
                      <img src="<?php echo htmlspecialchars($qrImage); ?>" alt="2FA QR" class="rounded border twofa-qr">
                    </div>
                    <div class="twofa-meta">
                      <div class="mb-2"><?php echo __('merchant.settings.twofa_step_scan'); ?></div>
                      <div class="mb-2"><?php echo __('merchant.settings.twofa_step_manual'); ?></div>
                      <div class="input-group mb-3">
                        <input id="totpSecret" class="form-control otp-secret" value="<?php echo htmlspecialchars($showSecret); ?>" readonly>
                        <button type="button" class="btn btn-outline-secondary" onclick="copySecret()"><?php echo __('merchant.settings.copy_secret'); ?></button>
                      </div>
                      <form method="post" class="d-grid gap-2"><?php echo Helper::csrfField(); ?>
                        <input type="hidden" name="action" value="enable_2fa">
                        <div class="mb-1 small text-muted"><?php echo __('merchant.settings.scene_intro_enable'); ?></div>
                        <div class="mb-2">
                          <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="two_factor_scenes[]" value="login" checked> <span class="form-check-label"><?php echo __('merchant.settings.scene.login'); ?></span></label>
                          <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="two_factor_scenes[]" value="balance_pay" checked> <span class="form-check-label"><?php echo __('merchant.settings.scene.balance_pay'); ?></span></label>
                          <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="two_factor_scenes[]" value="settings_security" checked> <span class="form-check-label"><?php echo __('merchant.settings.scene.settings_security'); ?></span></label>
                          <label class="form-check"><input class="form-check-input" type="checkbox" name="two_factor_scenes[]" value="derived_wallet" checked> <span class="form-check-label"><?php echo __('merchant.settings.scene.derived_wallet'); ?></span></label>
                        </div>
                        <input name="otp_code" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="<?php echo __('merchant.settings.otp_code_placeholder'); ?>" required>
                        <button type="submit" class="btn btn-warning"><?php echo __('merchant.settings.enable_2fa'); ?></button>
                      </form>
                    </div>
                  </div>
                <?php else: ?>
                  <div class="alert alert-success py-2 mb-3"><?php echo __('merchant.settings.twofa_enabled_notice'); ?></div>
                  <form method="post" class="row g-2 mb-3"><?php echo Helper::csrfField(); ?>
                    <input type="hidden" name="action" value="save_2fa_scenes">
                    <div class="col-12">
                      <div class="small text-muted mb-2"><?php echo __('merchant.settings.scene_intro_manage'); ?></div>
                      <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="two_factor_scenes[]" value="login" <?php echo !empty($twoFactorScenes['login']) ? 'checked' : ''; ?>> <span class="form-check-label"><?php echo __('merchant.settings.scene.login'); ?></span></label>
                      <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="two_factor_scenes[]" value="balance_pay" <?php echo !empty($twoFactorScenes['balance_pay']) ? 'checked' : ''; ?>> <span class="form-check-label"><?php echo __('merchant.settings.scene.balance_pay'); ?></span></label>
                      <label class="form-check mb-1"><input class="form-check-input" type="checkbox" name="two_factor_scenes[]" value="settings_security" <?php echo !empty($twoFactorScenes['settings_security']) ? 'checked' : ''; ?>> <span class="form-check-label"><?php echo __('merchant.settings.scene.settings_security'); ?></span></label>
                      <label class="form-check"><input class="form-check-input" type="checkbox" name="two_factor_scenes[]" value="derived_wallet" <?php echo !empty($twoFactorScenes['derived_wallet']) ? 'checked' : ''; ?>> <span class="form-check-label"><?php echo __('merchant.settings.scene.derived_wallet'); ?></span></label>
                    </div>
                    <div class="col-12">
                      <input name="scene_otp_code" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="<?php echo __('merchant.settings.scene_otp_placeholder'); ?>">
                    </div>
                    <div class="col-12 d-grid">
                      <button type="submit" class="btn btn-outline-primary"><?php echo __('merchant.settings.save_2fa_scenes'); ?></button>
                    </div>
                  </form>
                  <div class="mb-2"><?php echo __('merchant.settings.current_secret'); ?></div>
                  <div class="input-group mb-3">
                    <input class="form-control otp-secret" value="<?php echo htmlspecialchars($showSecret); ?>" readonly>
                    <button type="button" class="btn btn-outline-secondary" onclick="copySecret(<?php echo json_encode((string)$showSecret); ?>)"><?php echo __('merchant.settings.copy_secret'); ?></button>
                  </div>
                  <form method="post" class="row g-2"><?php echo Helper::csrfField(); ?>
                    <input type="hidden" name="action" value="disable_2fa">
                    <div class="col-12">
                      <label class="form-label"><?php echo __('merchant.settings.disable_2fa_label'); ?></label>
                      <input name="disable_otp_code" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="<?php echo __('merchant.settings.otp_code_placeholder'); ?>" required>
                    </div>
                    <div class="col-12 d-grid">
                      <button type="submit" class="btn btn-outline-danger"><?php echo __('merchant.settings.disable_2fa'); ?></button>
                    </div>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

<?php
// Load current brand settings
$colorKey = 'merchant_brand_color_u' . $user_id;
$noteKey  = 'merchant_brand_note_u'  . $user_id;
$siteCfgAll = $db->fetchAll("SELECT key_name, value FROM system_settings WHERE key_name IN (?, ?)", [$colorKey, $noteKey]);
$brandCfg = [];
foreach ($siteCfgAll as $bcr) { $brandCfg[$bcr['key_name']] = $bcr['value']; }
$currentBrandColor = $brandCfg[$colorKey] ?? '#3b82f6';
$currentBrandNote  = $brandCfg[$noteKey]  ?? '';
?>
<!-- Brand Customization Card -->
<div class="bn-card mb-3 mt-3">
    <div class="bn-head d-flex align-items-center gap-2">
        <i class="fas fa-palette text-primary"></i>
        <div>
            <div class="bn-title">收款页品牌自定义</div>
            <div class="bn-sub">自定义你的收款页外观，让它与你的品牌保持一致。</div>
        </div>
    </div>
    <div class="bn-body">
        <form method="POST"><?php echo Helper::csrfField(); ?>
            <input type="hidden" name="action" value="save_pay_brand">
            <div class="row g-4">
                <div class="col-md-6">
                    <label class="form-label fw-bold">品牌主色</label>
                    <div class="d-flex align-items-center gap-3">
                        <input type="color" name="brand_color" class="form-control form-control-color"
                               value="<?php echo htmlspecialchars($currentBrandColor ?: '#3b82f6'); ?>"
                               style="width:60px;height:38px;padding:2px;">
                        <input type="text" class="form-control font-monospace" id="brandColorText"
                               value="<?php echo htmlspecialchars($currentBrandColor ?: '#3b82f6'); ?>"
                               style="max-width:120px;" placeholder="#3b82f6"
                               oninput="document.querySelector('input[name=brand_color]').value=this.value">
                        <span class="text-muted small">用于按钮、强调色等</span>
                    </div>
                    <div class="mt-3">
                        <label class="form-label small fw-bold text-secondary">预览</label>
                        <div id="brandPreviewBtn" class="btn text-white px-4 py-2 rounded-3" style="background:<?php echo htmlspecialchars($currentBrandColor ?: '#3b82f6'); ?>;">
                            立即支付
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold">收款页说明文字</label>
                    <textarea name="brand_note" class="form-control" rows="3" maxlength="200"
                        placeholder="例：感谢您的支付，如有问题请联系 support@example.com"><?php echo htmlspecialchars($currentBrandNote); ?></textarea>
                    <div class="form-text">最多 200 字，将显示在收款页底部。留空则不显示。</div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>保存品牌设置</button>
            </div>
        </form>
    </div>
</div>
<script>
(function() {
    const colorInput = document.querySelector('input[name="brand_color"]');
    const colorText = document.getElementById('brandColorText');
    const previewBtn = document.getElementById('brandPreviewBtn');
    if (colorInput && colorText && previewBtn) {
        colorInput.addEventListener('input', function() {
            colorText.value = this.value;
            previewBtn.style.background = this.value;
        });
    }
})();
</script>

      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copySecret(secret) {
  var input = document.getElementById('totpSecret');
  var text = secret || (input ? input.value : '');
  if (!text) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text);
  } else if (input) {
    input.select();
    document.execCommand('copy');
  }
}
</script>
</body>
</html>
