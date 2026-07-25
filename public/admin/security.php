<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();

require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Services/TotpService.php';
require_once __DIR__ . '/../../src/Services/User2FAService.php';

$db = Database::getInstance();
$db->autoMigrate();

$adminId = (int)($_SESSION['user_id'] ?? 0);
$admin = $db->fetch("SELECT * FROM users WHERE id = ? AND role = 'admin' LIMIT 1", [$adminId]);
if (!$admin) {
    header('Location: /login.php', true, 303);
    exit;
}

// Ensure 2FA columns exist
foreach ([
    ['users', 'two_factor_enabled',    "TINYINT(1) DEFAULT 0"],
    ['users', 'two_factor_secret',     "VARCHAR(64) DEFAULT NULL"],
    ['users', 'two_factor_enabled_at', "DATETIME DEFAULT NULL"],
    ['users', 'two_factor_scenes',     "TEXT NULL"],
] as [$t, $c, $d]) {
    try {
        $ex = $db->fetch(
            "SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1",
            [$t, $c]
        );
        if (!$ex) $db->query("ALTER TABLE `{$t}` ADD COLUMN `{$c}` {$d}");
    } catch (Throwable $ignore) {}
}

// Flash messages
$message = $error = '';
if (!empty($_SESSION['admin_security_flash']) && is_array($_SESSION['admin_security_flash'])) {
    $flash = $_SESSION['admin_security_flash'];
    unset($_SESSION['admin_security_flash']);
    if (($flash['type'] ?? '') === 'success') $message = (string)($flash['message'] ?? '');
    else $error = (string)($flash['message'] ?? '');
}

$pendingSecret  = (string)($_SESSION['admin_security_2fa_pending_secret'] ?? '');
$is2faEnabled   = User2FAService::isEnabled((array)$admin);
$twoFactorScenes = User2FAService::parseScenes((array)$admin);
$adminOptScenes = User2FAService::adminOptionalScenes();

if ($pendingSecret === '' && !$is2faEnabled) {
    $pendingSecret = TotpService::generateSecret();
    $_SESSION['admin_security_2fa_pending_secret'] = $pendingSecret;
}

// ── POST handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $csrf = (string)($_POST['csrf_token'] ?? '');
        if (empty($_SESSION['admin_csrf_token']) || !hash_equals((string)$_SESSION['admin_csrf_token'], $csrf)) {
            throw new Exception('请求已过期，请刷新后重试。');
        }

        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'enable_2fa') {
            $secret  = trim((string)($_SESSION['admin_security_2fa_pending_secret'] ?? ''));
            $otpCode = trim((string)($_POST['otp_code'] ?? ''));
            if ($secret === '') throw new Exception('待启用的密钥不存在，请刷新页面后重试。');
            if (!TotpService::verifyCode($secret, $otpCode, 1)) throw new Exception('谷歌验证码错误，请输入 6 位动态码。');

            // Build scenes: mandatory fixed + optional from input
            $sceneInput = User2FAService::buildScenesFromInput((array)($_POST['two_factor_scenes'] ?? []));
            $sceneInput['login']             = 1;
            $sceneInput['settings_security'] = 1;

            $db->query(
                "UPDATE users SET two_factor_enabled=1, two_factor_secret=?, two_factor_enabled_at=NOW(), two_factor_scenes=? WHERE id=? AND role='admin'",
                [$secret, json_encode($sceneInput, JSON_UNESCAPED_UNICODE), $adminId]
            );
            unset($_SESSION['admin_security_2fa_pending_secret']);
            $_SESSION['admin_security_flash'] = ['type' => 'success', 'message' => '管理员谷歌验证器已启用。'];

        } elseif ($action === 'save_2fa_scenes') {
            if (!$is2faEnabled) throw new Exception('请先启用谷歌验证器。');
            $sceneOtp = trim((string)($_POST['scene_otp_code'] ?? ''));
            if (!TotpService::verifyCode(trim((string)($admin['two_factor_secret'] ?? '')), $sceneOtp, 1)) {
                throw new Exception('验证码错误，无法保存验证场景。');
            }
            $sceneInput = User2FAService::buildScenesFromInput((array)($_POST['two_factor_scenes'] ?? []));
            $sceneInput['login']             = 1;
            $sceneInput['settings_security'] = 1;
            $db->query(
                "UPDATE users SET two_factor_scenes=? WHERE id=? AND role='admin'",
                [json_encode($sceneInput, JSON_UNESCAPED_UNICODE), $adminId]
            );
            $_SESSION['admin_security_flash'] = ['type' => 'success', 'message' => '验证场景已更新。'];

        } elseif ($action === 'disable_2fa') {
            $disableOtp = trim((string)($_POST['disable_otp_code'] ?? ''));
            if (!TotpService::verifyCode(trim((string)($admin['two_factor_secret'] ?? '')), $disableOtp, 1)) {
                throw new Exception('验证码错误，无法关闭谷歌验证器。');
            }
            $db->query(
                "UPDATE users SET two_factor_enabled=0, two_factor_secret=NULL, two_factor_enabled_at=NULL WHERE id=? AND role='admin'",
                [$adminId]
            );
            unset($_SESSION['admin_settings_2fa_unlock_at'], $_SESSION['admin_binance_2fa_unlock_at'], $_SESSION['admin_broadcast_2fa_unlock_at']);
            $_SESSION['admin_security_2fa_pending_secret'] = TotpService::generateSecret();
            $_SESSION['admin_security_flash'] = ['type' => 'success', 'message' => '管理员谷歌验证器已关闭，所有操作解锁已清除。'];
        }

    } catch (Throwable $e) {
        $_SESSION['admin_security_flash'] = ['type' => 'error', 'message' => $e->getMessage()];
    }
    header('Location: security.php', true, 303);
    exit;
}

// ── Reload after POST redirect ────────────────────────────────────────────
$admin          = $db->fetch("SELECT * FROM users WHERE id=? AND role='admin' LIMIT 1", [$adminId]);
$is2faEnabled   = User2FAService::isEnabled((array)$admin);
$twoFactorScenes = User2FAService::parseScenes((array)$admin);
$pendingSecret  = (string)($_SESSION['admin_security_2fa_pending_secret'] ?? '');
if ($pendingSecret === '' && !$is2faEnabled) {
    $pendingSecret = TotpService::generateSecret();
    $_SESSION['admin_security_2fa_pending_secret'] = $pendingSecret;
}

$issuer      = 'UAPI Admin';
$showSecret  = $is2faEnabled ? trim((string)($admin['two_factor_secret'] ?? '')) : $pendingSecret;
$otpAuthUrl  = TotpService::getOtpAuthUrl($issuer, (string)$admin['email'], $showSecret);
$qrImage     = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . rawurlencode($otpAuthUrl);

// Session unlock status for protected pages
$settingsUnlock = (int)($_SESSION['admin_settings_2fa_unlock_at']  ?? 0);
$binanceUnlock  = (int)($_SESSION['admin_binance_2fa_unlock_at']   ?? 0);
$broadcastUnlock= (int)($_SESSION['admin_broadcast_2fa_unlock_at'] ?? 0);
$now = time();
$lockTtl = 300; // 5 min

$page_title  = '安全设置';
$active_menu = 'security';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ── Security page custom styles ──────────────────────────────────────── */
.sec-hero {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border-radius: 16px;
    padding: 28px 32px;
    color: #fff;
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
}
.sec-hero::after {
    content: '';
    position: absolute;
    right: -40px; top: -40px;
    width: 220px; height: 220px;
    border-radius: 50%;
    background: rgba(59,130,246,.12);
    pointer-events: none;
}
.sec-hero::before {
    content: '';
    position: absolute;
    right: 40px; top: 60px;
    width: 120px; height: 120px;
    border-radius: 50%;
    background: rgba(99,102,241,.10);
    pointer-events: none;
}
.sec-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 14px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .02em;
}
.sec-chip.enabled  { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
.sec-chip.disabled { background: #fefce8; color: #92400e; border: 1px solid #fcd34d; }
.sec-chip.locked   { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
.sec-chip.unlocked { background: #f0fdf4; color: #15803d; border: 1px solid #6ee7b7; }

.sec-card {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 14px;
    box-shadow: 0 4px 16px rgba(15,23,42,.06);
    overflow: hidden;
    margin-bottom: 20px;
}
.sec-card-header {
    padding: 16px 22px;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 15px;
}
.sec-card-header .icon-wrap {
    width: 34px; height: 34px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 15px;
    flex-shrink: 0;
}
.sec-card-body { padding: 22px; }

.otp-input-group {
    display: flex;
    gap: 8px;
}
.otp-input-group input[type="text"],
.otp-input-group input[inputmode="numeric"] {
    font-family: ui-monospace, monospace;
    font-size: 20px;
    letter-spacing: .15em;
    text-align: center;
    max-width: 160px;
}

.scene-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 12px;
}
.scene-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid var(--border-color, #e5e7eb);
    background: var(--card-bg, #fff);
    transition: border-color .15s;
}
.scene-item:has(input:checked) {
    border-color: #3b82f6;
    background: #eff6ff;
}
.scene-item.mandatory {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.scene-icon { font-size: 18px; margin-top: 1px; }
.scene-label { font-weight: 600; font-size: 13.5px; line-height: 1.3; }
.scene-desc  { font-size: 12px; color: #64748b; margin-top: 2px; }

.twofa-qr-wrap {
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 10px;
    background: #fff;
    display: inline-block;
}
.twofa-qr-wrap img { display: block; border-radius: 6px; }

.secret-box {
    font-family: ui-monospace, monospace;
    font-size: 13px;
    letter-spacing: .06em;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px 14px;
    word-break: break-all;
}
.unlock-timeline {
    display: flex; flex-direction: column; gap: 8px;
}
.unlock-row {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 10px 14px; border-radius: 10px;
    border: 1px solid var(--border-color, #e5e7eb);
    font-size: 13.5px;
}
.unlock-row.active { border-color: #6ee7b7; background: #f0fdf4; }
.unlock-row.expired { border-color: #fed7aa; background: #fff7ed; }
.unlock-row.na { border-color: #e5e7eb; background: #f8fafc; color: #94a3b8; }

@media (max-width: 767.98px) {
    .sec-hero { padding: 20px; }
    .sec-card-body { padding: 16px; }
    .scene-grid { grid-template-columns: 1fr; }
    .otp-input-group input { max-width: 120px; }
}
</style>

<?php if ($message !== ''): ?>
<div class="alert alert-success alert-dismissible fade show mb-4">
    <i class="fas fa-circle-check me-2"></i><?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error !== ''): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4">
    <i class="fas fa-triangle-exclamation me-2"></i><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Hero -->
<div class="sec-hero">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
            <div class="d-flex align-items-center gap-3 mb-2">
                <div style="background:rgba(59,130,246,.2);border-radius:10px;padding:8px 12px;">
                    <i class="fas fa-shield-halved fa-lg text-white"></i>
                </div>
                <h4 class="mb-0 text-white fw-bold">管理员安全中心</h4>
            </div>
            <div style="color:#94a3b8;font-size:13.5px;max-width:480px;">
                为后台账号配置谷歌验证器（TOTP），并精细控制哪些操作需要动态码校验，提升系统整体安全性。
            </div>
            <div class="mt-3 small" style="color:#94a3b8;">
                当前管理员：<span class="text-white fw-semibold"><?php echo htmlspecialchars((string)$admin['email']); ?></span>
                <?php if ($is2faEnabled && !empty($admin['two_factor_enabled_at'])): ?>
                    &nbsp;·&nbsp; 启用时间：<span class="text-white"><?php echo htmlspecialchars($admin['two_factor_enabled_at']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <span class="sec-chip <?php echo $is2faEnabled ? 'enabled' : 'disabled'; ?>" style="align-self:flex-start;margin-top:4px;">
            <i class="fas <?php echo $is2faEnabled ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
            <?php echo $is2faEnabled ? '谷歌验证器已启用' : '未启用'; ?>
        </span>
    </div>
</div>

<div class="row g-4">

    <!-- Left: 2FA setup / status -->
    <div class="col-lg-7">

        <?php if (!$is2faEnabled): ?>
        <!-- ── Enable 2FA ─────────────────────────────────────────────── -->
        <div class="sec-card">
            <div class="sec-card-header">
                <div class="icon-wrap" style="background:#fef9c3;color:#ca8a04;"><i class="fas fa-qrcode"></i></div>
                绑定谷歌验证器
            </div>
            <div class="sec-card-body">
                <div class="row g-4 align-items-start">
                    <div class="col-auto">
                        <div class="twofa-qr-wrap">
                            <img src="<?php echo htmlspecialchars($qrImage); ?>" width="200" height="200" alt="2FA QR Code">
                        </div>
                    </div>
                    <div class="col">
                        <ol class="mb-3 ps-4 small text-muted" style="line-height:2;">
                            <li>手机安装 <strong>Google Authenticator</strong> 或 Authy</li>
                            <li>扫描左侧二维码，或手动输入密钥</li>
                            <li>输入 App 中显示的 6 位动态码完成绑定</li>
                        </ol>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small text-muted mb-1">手动录入密钥</label>
                            <div class="d-flex gap-2 align-items-center">
                                <div class="secret-box flex-grow-1" id="adminTotpSecret"><?php echo htmlspecialchars($showSecret); ?></div>
                                <button type="button" class="btn btn-outline-secondary btn-sm flex-shrink-0" onclick="copySecret()">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>

                        <form method="post" id="enableForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$admin_csrf_token); ?>">
                            <input type="hidden" name="action" value="enable_2fa">
                            <!-- Mandatory scenes (hidden) -->
                            <input type="hidden" name="two_factor_scenes[]" value="login">
                            <input type="hidden" name="two_factor_scenes[]" value="settings_security">

                            <!-- Optional scenes selection -->
                            <div class="mb-3">
                                <div class="fw-semibold small mb-2">启用时同时开启的验证场景（可选）</div>
                                <div class="scene-grid">
                                    <?php foreach ($adminOptScenes as $sceneKey => $sceneLabel): ?>
                                    <label class="scene-item" style="cursor:pointer;">
                                        <input class="form-check-input mt-0 flex-shrink-0" type="checkbox" name="two_factor_scenes[]" value="<?php echo htmlspecialchars($sceneKey); ?>">
                                        <div>
                                            <div class="scene-label"><?php echo htmlspecialchars($sceneLabel); ?></div>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label fw-semibold">输入 App 中的 6 位动态码</label>
                                <div class="otp-input-group">
                                    <input name="otp_code" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
                                    <button type="submit" class="btn btn-warning px-4 fw-bold">
                                        <i class="fas fa-lock me-1"></i>启用
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ── 2FA Active: Scene management ──────────────────────────────── -->
        <div class="sec-card">
            <div class="sec-card-header">
                <div class="icon-wrap" style="background:#dcfce7;color:#16a34a;"><i class="fas fa-sliders"></i></div>
                验证场景配置
                <span class="sec-chip enabled ms-auto" style="font-size:11px;">已启用</span>
            </div>
            <div class="sec-card-body">
                <div class="mb-4">
                    <div class="small text-muted mb-3">下列场景<strong>固定开启</strong>，不可关闭：</div>
                    <div class="scene-grid mb-4">
                        <div class="scene-item mandatory">
                            <i class="scene-icon fas fa-right-to-bracket text-primary"></i>
                            <div>
                                <div class="scene-label">登录验证</div>
                                <div class="scene-desc">每次登录后台必须验证动态码</div>
                            </div>
                        </div>
                        <div class="scene-item mandatory">
                            <i class="scene-icon fas fa-shield-halved text-purple-600" style="color:#7c3aed;"></i>
                            <div>
                                <div class="scene-label">安全设置变更</div>
                                <div class="scene-desc">修改/关闭谷歌验证器前需验证</div>
                            </div>
                        </div>
                    </div>

                    <div class="small text-muted mb-3">下列场景<strong>可自由开关</strong>（需输入动态码保存）：</div>
                    <form method="post" id="scenesForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$admin_csrf_token); ?>">
                        <input type="hidden" name="action" value="save_2fa_scenes">
                        <input type="hidden" name="two_factor_scenes[]" value="login">
                        <input type="hidden" name="two_factor_scenes[]" value="settings_security">

                        <div class="scene-grid mb-4">
                            <?php
                            $sceneIcons = [
                                'admin_settings'    => ['fas fa-cog',        '#3b82f6', '修改系统设置（站点名称、支付配置等）'],
                                'admin_binance'     => ['fas fa-b',           '#f59e0b', '发起退款或关闭币安支付订单'],
                                'admin_broadcast'   => ['fas fa-envelope',   '#8b5cf6', '向用户发送群发邮件'],
                                'admin_user_delete' => ['fas fa-user-slash',  '#ef4444', '封禁或删除用户账号'],
                                'admin_plan_edit'   => ['fas fa-cube',        '#10b981', '新增、编辑或删除订阅套餐'],
                            ];
                            foreach ($adminOptScenes as $sceneKey => $sceneLabel):
                                $checked = !empty($twoFactorScenes[$sceneKey]);
                                [$icon, $color, $desc] = $sceneIcons[$sceneKey] ?? ['fas fa-shield', '#64748b', ''];
                            ?>
                            <label class="scene-item" style="cursor:pointer;">
                                <input class="form-check-input mt-0 flex-shrink-0" type="checkbox"
                                    name="two_factor_scenes[]" value="<?php echo htmlspecialchars($sceneKey); ?>"
                                    <?php echo $checked ? 'checked' : ''; ?>>
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <i class="<?php echo $icon; ?>" style="color:<?php echo $color; ?>;font-size:14px;"></i>
                                        <span class="scene-label"><?php echo htmlspecialchars($sceneLabel); ?></span>
                                    </div>
                                    <div class="scene-desc"><?php echo htmlspecialchars($desc); ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small">动态码确认</label>
                                <input name="scene_otp_code" class="form-control" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="输入 6 位动态码" required autocomplete="one-time-code">
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>保存验证场景
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Current Secret -->
        <div class="sec-card">
            <div class="sec-card-header">
                <div class="icon-wrap" style="background:#f1f5f9;color:#475569;"><i class="fas fa-key"></i></div>
                密钥 / 重新绑定
            </div>
            <div class="sec-card-body">
                <div class="mb-3 small text-muted">如需在新设备上重新绑定，可以扫描以下二维码或手动录入密钥（密钥不变）。</div>
                <div class="row g-3 align-items-center mb-4">
                    <div class="col-auto">
                        <div class="twofa-qr-wrap">
                            <img src="<?php echo htmlspecialchars($qrImage); ?>" width="140" height="140" alt="QR Code">
                        </div>
                    </div>
                    <div class="col">
                        <label class="form-label fw-semibold small text-muted mb-1">当前密钥</label>
                        <div class="d-flex gap-2 align-items-center">
                            <div class="secret-box flex-grow-1" id="adminTotpSecret"><?php echo htmlspecialchars($showSecret); ?></div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="copySecret()">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Disable 2FA -->
                <div class="border-top pt-3">
                    <div class="small text-muted mb-3">
                        <i class="fas fa-triangle-exclamation text-warning me-1"></i>
                        关闭验证器后，所有 2FA 场景保护将立即失效。如需关闭请输入当前动态码确认。
                    </div>
                    <form method="post" id="disableForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$admin_csrf_token); ?>">
                        <input type="hidden" name="action" value="disable_2fa">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small text-danger">输入动态码以关闭</label>
                                <input name="disable_otp_code" class="form-control border-danger" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="000000" required autocomplete="one-time-code">
                            </div>
                            <div class="col-md-auto">
                                <button type="submit" class="btn btn-outline-danger"
                                    onclick="return confirm('确认关闭谷歌验证器？所有 2FA 保护将立即解除。')">
                                    <i class="fas fa-lock-open me-1"></i>关闭验证器
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /col-lg-7 -->

    <!-- Right: Operation unlock status + guide -->
    <div class="col-lg-5">

        <!-- Unlock status card -->
        <?php if ($is2faEnabled): ?>
        <div class="sec-card">
            <div class="sec-card-header">
                <div class="icon-wrap" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-unlock-keyhole"></i></div>
                操作解锁状态
            </div>
            <div class="sec-card-body p-3">
                <div class="small text-muted mb-3">
                    受保护页面首次操作时需输入动态码解锁（有效期 5 分钟）。当前状态：
                </div>
                <div class="unlock-timeline">
                    <?php
                    $unlockItems = [
                        ['label' => '系统设置', 'icon' => 'fa-cog', 'scene' => 'admin_settings', 'ts' => $settingsUnlock],
                        ['label' => '币安商户', 'icon' => 'fa-b',   'scene' => 'admin_binance',  'ts' => $binanceUnlock],
                        ['label' => '邮件群发', 'icon' => 'fa-envelope', 'scene' => 'admin_broadcast', 'ts' => $broadcastUnlock],
                    ];
                    foreach ($unlockItems as $ui):
                        $sceneOn = !empty($twoFactorScenes[$ui['scene']]);
                        $remaining = $sceneOn ? max(0, $lockTtl - ($now - $ui['ts'])) : 0;
                        $rowClass = $sceneOn ? ($remaining > 0 ? 'active' : 'expired') : 'na';
                    ?>
                    <div class="unlock-row <?php echo $rowClass; ?>">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fas <?php echo $ui['icon']; ?>" style="width:16px;text-align:center;color:#64748b;"></i>
                            <span class="fw-semibold"><?php echo htmlspecialchars($ui['label']); ?></span>
                        </div>
                        <div>
                            <?php if (!$sceneOn): ?>
                                <span class="sec-chip" style="background:#f1f5f9;color:#94a3b8;border:1px solid #e5e7eb;font-size:11px;">未开启</span>
                            <?php elseif ($remaining > 0): ?>
                                <span class="sec-chip unlocked" style="font-size:11px;">
                                    <i class="fas fa-circle-check"></i> 已解锁 <?php echo $remaining; ?>s
                                </span>
                            <?php else: ?>
                                <span class="sec-chip locked" style="font-size:11px;background:#fef2f2;color:#b91c1c;border-color:#fca5a5;">
                                    <i class="fas fa-lock"></i> 已锁定
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3 small text-muted">
                    <i class="fas fa-circle-info me-1"></i>
                    进入对应页面操作时会自动提示输入动态码。
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Guide card -->
        <div class="sec-card">
            <div class="sec-card-header">
                <div class="icon-wrap" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-info"></i></div>
                配置说明
            </div>
            <div class="sec-card-body">
                <ul class="mb-0 ps-3 small text-muted" style="line-height:2;">
                    <li><strong>登录验证</strong>与<strong>安全设置变更</strong>为强制场景，始终开启。</li>
                    <li>其余场景均为可选，建议按实际安全需求开启。</li>
                    <li>受保护场景在对应页面操作时会弹出动态码输入框，验证通过后 <strong>5 分钟内</strong>免重复验证。</li>
                    <li>关闭验证器将立即清除所有页面的解锁状态。</li>
                    <li>推荐配合登录 IP 白名单一起使用以最大化安全性。</li>
                </ul>
            </div>
        </div>

        <!-- Supported apps -->
        <div class="sec-card">
            <div class="sec-card-header">
                <div class="icon-wrap" style="background:#fdf4ff;color:#9333ea;"><i class="fas fa-mobile-screen-button"></i></div>
                支持的验证器 App
            </div>
            <div class="sec-card-body">
                <div class="d-flex flex-column gap-2">
                    <?php foreach ([
                        ['Google Authenticator', 'ios / Android', 'text-danger'],
                        ['Microsoft Authenticator', 'ios / Android', 'text-primary'],
                        ['Authy', 'ios / Android / 桌面', 'text-warning'],
                        ['1Password (内置 TOTP)', '跨平台', 'text-success'],
                    ] as [$name, $platform, $cls]): ?>
                    <div class="d-flex align-items-center gap-3 small">
                        <i class="fas fa-check-circle <?php echo $cls; ?>"></i>
                        <div>
                            <span class="fw-semibold"><?php echo $name; ?></span>
                            <span class="text-muted ms-1"><?php echo $platform; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div><!-- /col-lg-5 -->
</div><!-- /row -->

<script>
function copySecret() {
    const el = document.getElementById('adminTotpSecret');
    const text = el ? el.textContent.trim() : '';
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
            const btn = event.currentTarget;
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check text-success"></i>';
            setTimeout(() => btn.innerHTML = orig, 1500);
        });
        return;
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
