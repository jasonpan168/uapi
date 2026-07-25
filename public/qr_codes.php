<?php
// public/qr_codes.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();
$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$user = $db->fetch("SELECT id, plan_id FROM users WHERE id = ?", [$user_id]);
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$page_title = __('merchant.qr_create.title');
$receiveModeKey = 'merchant_receive_mode_u' . (int)$user_id;
$receiveModeRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
$receive_mode = strtolower(trim((string)($receiveModeRow['value'] ?? 'wallet')));
if (!in_array($receive_mode, ['wallet', 'derived'], true)) {
    $receive_mode = 'wallet';
}
$platformCurrencies = [];
if (($cfg['enable_payment_usdt'] ?? '1') === '1') $platformCurrencies[] = 'USDT';
if (($cfg['enable_usdc'] ?? '0') === '1') $platformCurrencies[] = 'USDC';
if (empty($platformCurrencies)) $platformCurrencies[] = 'USDT';

// Fetch Wallets for dropdown
$wallets = $db->fetchAll("SELECT * FROM wallets WHERE user_id = ? AND status = 1", [$user_id]);
$derived_chains = $db->fetchAll(
    "SELECT c.slug, c.name
     FROM chains c
     INNER JOIN plan_chains pc ON pc.chain_id = c.id AND pc.plan_id = ?
     LEFT JOIN plan_chain_derived pcd ON pcd.plan_id = pc.plan_id AND pcd.chain_id = pc.chain_id
     WHERE c.status = 1 AND c.is_evm = 1 AND COALESCE(c.allow_derived, 1) = 1 AND COALESCE(pcd.enabled, 1) = 1
     ORDER BY c.name ASC",
    [(int)$user['plan_id']]
);
$walletOptions = [];
foreach ($wallets as $w) {
    $walletOptions[] = [
        'value' => (string)$w['address'],
        'chain' => strtolower((string)$w['chain']),
        'label' => strtoupper((string)$w['chain']) . ' (' . substr((string)$w['address'], 0, 8) . '...)'
    ];
}
$derivedOptions = [];
foreach ($derived_chains as $dc) {
    $derivedOptions[] = [
        'value' => strtolower((string)$dc['slug']),
        'chain' => strtolower((string)$dc['slug']),
        'label' => strtoupper((string)$dc['slug']) . ' (' . (string)$dc['name'] . ')'
    ];
}
$canWalletQr = !empty($walletOptions);
$canDerivedQr = !empty($derivedOptions);
$defaultQrMode = $canWalletQr ? 'wallet' : 'derived';
$can_generate_qr = $canWalletQr || $canDerivedQr;

// Templates Config (Identical to style_picker.php)
$templates = [
  "t1" => [
    "name_zh" => "清爽白绿",
    "name_en" => "Fresh Green",
    "qr" => ["left" => 18.0, "top" => 31.0, "size" => 64.0],
    "theme" => ["bg1" => "#e9fff4", "bg2" => "#ffffff", "accent" => "#16a34a", "accent2" => "#0f766e"],
    "style" => "clean"
  ],
  "t2" => [
    "name_zh" => "深色霓虹",
    "name_en" => "Dark Neon",
    "qr" => ["left" => 18.0, "top" => 31.0, "size" => 64.0],
    "theme" => ["bg1" => "#061a16", "bg2" => "#0b3a2c", "accent" => "#22c55e", "accent2" => "#14b8a6"],
    "style" => "neon"
  ],
  "t3" => [
    "name_zh" => "蓝绿科技",
    "name_en" => "Blue Tech",
    "qr" => ["left" => 18.0, "top" => 31.0, "size" => 64.0],
    "theme" => ["bg1" => "#e6f5ff", "bg2" => "#ffffff", "accent" => "#0ea5e9", "accent2" => "#22c55e"],
    "style" => "tech"
  ],
  "t4" => [
    "name_zh" => "极简白",
    "name_en" => "Minimal White",
    "qr" => ["left" => 18.0, "top" => 31.0, "size" => 64.0],
    "theme" => ["bg1" => "#ffffff", "bg2" => "#f6f7fb", "accent" => "#16a34a", "accent2" => "#111827"],
    "style" => "minimal"
  ],
  "t5" => [
    "name_zh" => "卡片光泽",
    "name_en" => "Glossy Card",
    "qr" => ["left" => 18.0, "top" => 31.0, "size" => 64.0],
    "theme" => ["bg1" => "#f0fff7", "bg2" => "#ffffff", "accent" => "#15803d", "accent2" => "#22c55e"],
    "style" => "glossy"
  ],
  "t6" => [
    "name_zh" => "商务灰绿",
    "name_en" => "Business Green",
    "qr" => ["left" => 18.0, "top" => 31.0, "size" => 64.0],
    "theme" => ["bg1" => "#f4f6f8", "bg2" => "#ffffff", "accent" => "#0f766e", "accent2" => "#16a34a"],
    "style" => "business"
  ],
];
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    <style>
        :root{ --radius: 18px; --shadow: 0 14px 35px rgba(0,0,0,.12); --font: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        
        /* Page Styles */
        body { font-family: var(--font); }
        
        /* Card Styles */
        .card { border: none; border-radius: 12px; box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075); }
        .card-header { background-color: var(--card-bg); border-bottom: 1px solid var(--border-color); padding: 1.5rem; border-radius: 12px 12px 0 0 !important; color: var(--text-primary); }
        .card-body { background-color: var(--card-bg); color: var(--text-primary); border-radius: 0 0 12px 12px; }

        /* Poster Preview Styles (Exact Copy) */
        .poster-preview {
            width: 100%; aspect-ratio: 320 / 520; border-radius: var(--radius);
            position: relative; overflow: hidden; box-shadow: var(--shadow);
            background: linear-gradient(160deg, var(--bg1), var(--bg2));
            transition: all 0.3s ease;
            margin: 0 auto;
            max-width: 360px; /* Limit width */
        }
        
        /* Inner Elements */
        .poster-preview .glass {
            position:absolute; inset:-40px -60px auto auto; width:220px; height:220px; border-radius: 999px;
            background: radial-gradient(circle at 30% 30%, rgba(255,255,255,.6), rgba(255,255,255,0) 65%);
            opacity:.55; transform: rotate(12deg); pointer-events: none;
        }
        .poster-preview .top {
            position:absolute; left:18px; right:18px; top:18px; padding:14px 14px 12px; border-radius: 16px;
            background: linear-gradient(135deg, rgba(0,0,0,.06), rgba(0,0,0,.0)); border: 1px solid rgba(0,0,0,.06);
        }
        .logoRow { display:flex; align-items:center; gap:10px; }
        .logo { 
            width:40px; height:40px; border-radius:999px; overflow:hidden; 
            display:grid; place-items:center;
            border: 1px solid rgba(255,255,255,.45);
            box-shadow: 0 10px 22px rgba(0,0,0,.10);
        }
        .logo img { 
            width:100%; height:100%; object-fit:cover; object-position:center; display:block; transform: scale(1.18);
        }
        .usdtText { font-size:28px; font-weight:900; letter-spacing:.5px; color: rgba(10,15,20,.92); }
        .sub { margin-top:8px; font-size:15px; font-weight:800; color: rgba(10,15,20,.65); }
        .line { height:1px; background: rgba(0,0,0,.08); margin-top:10px; }
        
        .qr-slot {
            position:absolute; 
            left: calc(var(--qr-left) * 1%);
            top: calc(var(--qr-top) * 1%);
            width: calc(var(--qr-size) * 1%);
            aspect-ratio: 1/1;
            border-radius: 18px; background: rgba(255,255,255,.92); border: 2px solid rgba(0,0,0,.10);
            box-shadow: 0 18px 30px rgba(0,0,0,.12); display:grid; place-items:center;
        }
        .qr-inner { 
            width: 92%; height: 92%; border-radius: 12px; border: 2px dashed rgba(15,23,42,.22); position: relative; 
            background: linear-gradient(180deg, rgba(255,255,255,.88), rgba(255,255,255,.98));
        }
        #qrCodeWrapper {
             display: flex; align-items: center; justify-content: center;
        }
        #qrCodeContainer {
            width: 100%; height: 100%; display: flex; align-items: center; justify-content: center;
        }
        #qrCodeContainer img, #qrCodeContainer canvas {
            max-width: 100%; max-height: 100%; height: auto; width: auto;
        }

        .corner {
            position:absolute; width:18px; height:18px; border:4px solid var(--accent);
            border-right:none; border-bottom:none; border-radius:6px 0 0 0; opacity:.9;
        }
        .c1{ left:10px; top:10px; }
        .c2{ right:10px; top:10px; transform: rotate(90deg); }
        .c3{ left:10px; bottom:10px; transform: rotate(270deg); }
        .c4{ right:10px; bottom:10px; transform: rotate(180deg); }
        
        .bottom {
            position:absolute; left:18px; right:18px; bottom:18px; border-radius: 16px; padding:12px 14px;
            background: rgba(255,255,255,.72); border: 1px solid rgba(0,0,0,.06); 
        }
        .trust {
            display:flex; align-items:center; justify-content:space-between; gap:10px;
            font-weight:900; color: rgba(10,15,20,.78);
        }
        .pill {
            background: linear-gradient(135deg, var(--accent), var(--accent2)); color:white; font-weight:900;
            border-radius: 999px; padding:10px 12px; margin-top: 10px;
            text-align:center; box-shadow: 0 12px 24px rgba(0,0,0,.16); letter-spacing:.2px;
        }
        .thank { margin-top:10px; text-align:center; font-weight:900; color: rgba(10,15,20,.65); }
        
        /* Style Specific Overrides */
        .poster-preview.neon {
            background: radial-gradient(circle at 30% 20%, rgba(34,197,94,.25), rgba(0,0,0,0) 45%),
                        radial-gradient(circle at 70% 80%, rgba(20,184,166,.22), rgba(0,0,0,0) 45%),
                        linear-gradient(150deg, var(--bg1), var(--bg2));
        }
        .poster-preview.neon .top { background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.10); }
        .poster-preview.neon .usdtText, .poster-preview.neon .sub { color: rgba(255,255,255,.92); }
        .poster-preview.neon .line { background: rgba(255,255,255,.15); }
        .poster-preview.neon .bottom { background: rgba(255,255,255,.10); border-color: rgba(255,255,255,.10); }
        .poster-preview.neon .trust, .poster-preview.neon .thank { color: rgba(255,255,255,.86); }
        .poster-preview.neon .qr-slot { border-color: rgba(34,197,94,.55); box-shadow: 0 0 0 3px rgba(34,197,94,.18), 0 22px 35px rgba(0,0,0,.22); }
        
        .poster-preview.tech {
            background: radial-gradient(circle at 20% 15%, rgba(14,165,233,.22), rgba(0,0,0,0) 52%),
                        radial-gradient(circle at 85% 70%, rgba(34,197,94,.16), rgba(0,0,0,0) 55%),
                        linear-gradient(155deg, var(--bg1), var(--bg2));
        }
        .poster-preview.minimal .top, .poster-preview.minimal .bottom { background: rgba(255,255,255,.85); border-color: rgba(0,0,0,.06); }
        .poster-preview.minimal .glass { display:none; }
        .poster-preview.glossy::before {
            content:""; position:absolute; inset:0;
            background: radial-gradient(circle at 25% 10%, rgba(255,255,255,.55), rgba(255,255,255,0) 45%),
                        radial-gradient(circle at 80% 60%, rgba(34,197,94,.16), rgba(255,255,255,0) 55%);
            pointer-events:none;
        }
        .poster-preview.business {
            background: radial-gradient(circle at 25% 15%, rgba(15,118,110,.16), rgba(0,0,0,0) 50%),
                        linear-gradient(160deg, var(--bg1), var(--bg2));
        }

        /* Carousel Style Switcher */
        .style-switcher {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--card-bg);
            padding: 15px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
        }
        .style-name {
            font-weight: bold;
            font-size: 1.1rem;
            text-align: center;
            flex-grow: 1;
            color: var(--text-primary);
        }
        .btn-arrow {
            width: 40px; height: 40px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            background: var(--bg-body); border: 1px solid var(--border-color);
            color: var(--text-secondary);
            transition: all 0.2s;
        }
        .btn-arrow:hover {
            background: var(--accent-blue); color: white; border-color: var(--accent-blue);
        }

        /* Gold Network Badge */
        .badge-gold {
            background: linear-gradient(135deg, #FFD700, #FDB931);
            color: #000;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 800;
            margin-left: 8px;
            vertical-align: middle;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            text-shadow: none;
        }

        /* Center Store Name */
        .store-name-display {
            text-align: center;
            width: 100%;
            display: block;
            margin-top: 10px;
        }
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.qr_create.title'); include __DIR__ . '/includes/user_topbar.php'; ?>
            
            <?php if (!$canWalletQr && !$canDerivedQr): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo I18n::getLang()==='en' ? 'No available receiving wallet or derived network. Please configure one first.' : '暂无可用收款钱包或派生网络，请先配置。'; ?>
            </div>
            <?php else: ?>
            
            <div class="row g-4 align-items-center" style="min-height: 80vh;">
                <!-- Left: Controls -->
                <div class="col-lg-5">
                    <div class="card shadow-sm h-100">
                        <div class="card-header">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-sliders-h me-2 text-primary"></i><?php echo __('merchant.qr_create.config_title'); ?></h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-4">
                                <label class="form-label fw-bold"><?php echo __('merchant.qr_create.poster_language'); ?></label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="lang" id="lang-zh" value="zh" checked onchange="updateLanguage()">
                                    <label class="btn btn-outline-primary" for="lang-zh"><?php echo __('merchant.qr_create.lang_zh'); ?></label>
                                    
                                    <input type="radio" class="btn-check" name="lang" id="lang-en" value="en" onchange="updateLanguage()">
                                    <label class="btn btn-outline-primary" for="lang-en"><?php echo __('merchant.qr_create.lang_en'); ?></label>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold"><?php echo __('merchant.qr_create.name_label'); ?></label>
                                <input type="text" id="storeName" class="form-control form-control-lg" placeholder="<?php echo __('merchant.qr_create.name_placeholder'); ?>" oninput="updatePreviewText()">
                            </div>
                            
                            <div class="mb-4">
                                <label class="form-label fw-bold"><?php echo I18n::getLang()==='en' ? 'Receive Mode' : '收款模式'; ?></label>
                                <select id="receiveModeSelect" class="form-select form-select-lg" onchange="updateWallet()">
                                    <option value="wallet" <?php echo $defaultQrMode === 'wallet' ? 'selected' : ''; ?> <?php echo $canWalletQr ? '' : 'disabled'; ?>>
                                        <?php echo I18n::getLang()==='en' ? 'Fixed Wallet' : '固定地址'; ?>
                                    </option>
                                    <option value="derived" <?php echo $defaultQrMode === 'derived' ? 'selected' : ''; ?> <?php echo $canDerivedQr ? '' : 'disabled'; ?>>
                                        <?php echo I18n::getLang()==='en' ? 'Derived Address' : '派生地址'; ?>
                                    </option>
                                </select>
                            </div>

                            <div class="mb-4">
                                <label class="form-label fw-bold"><?php echo __('merchant.qr_create.wallet_network'); ?></label>
                                <select id="walletSelect" class="form-select form-select-lg" onchange="updateWallet()">
                                    <?php foreach($walletOptions as $wo): ?>
                                    <option value="<?php echo htmlspecialchars($wo['value']); ?>" data-chain="<?php echo htmlspecialchars($wo['chain']); ?>">
                                        <?php echo htmlspecialchars($wo['label']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-4">
                                <label class="form-label fw-bold"><?php echo I18n::getLang()==='en' ? 'Default Currency' : '默认币种'; ?></label>
                                <select id="currencySelect" class="form-select form-select-lg" onchange="updateWallet()">
                                    <?php foreach ($platformCurrencies as $pc): ?>
                                    <option value="<?php echo htmlspecialchars($pc); ?>"><?php echo htmlspecialchars($pc); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <label class="form-label fw-bold"><?php echo __('merchant.qr_create.style_label'); ?></label>
                            <div class="style-switcher">
                                <button class="btn btn-arrow" onclick="changeStyle(-1)"><i class="fas fa-chevron-left"></i></button>
                                <div class="style-name" id="currentStyleName"><?php echo __('merchant.qr_create.style_default'); ?></div>
                                <button class="btn btn-arrow" onclick="changeStyle(1)"><i class="fas fa-chevron-right"></i></button>
                            </div>
                            
                            <div class="d-grid mt-5">
                                <button onclick="downloadPoster()" class="btn btn-success btn-lg shadow-sm">
                                    <i class="fas fa-download me-2"></i> <?php echo __('merchant.qr_create.download_poster'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right: Preview -->
                <div class="col-lg-7">
                    <div class="card shadow-sm h-100 bg-light d-flex align-items-center justify-content-center p-4">
                        <div id="posterContainer" class="poster-preview tech" style="
                            --bg1: #e6f5ff;
                            --bg2: #ffffff;
                            --accent: #0ea5e9;
                            --accent2: #22c55e;
                            --qr-left: 16.0;
                            --qr-top: 29.5;
                            --qr-size: 68.0;
                        ">
                            <div class="glass"></div>
                            <div class="top">
                                <div class="logoRow">
                                    <div class="logo"><img src="assets/usdt.svg" alt="USDT"></div>
                                    <div>
                                        <div class="usdtText">
                                            USDT <span id="networkBadge" class="badge-gold">TRC20</span>
                                        </div>
                                        <div class="sub" id="text-secure"><?php echo __('merchant.qr_create.preview.secure'); ?></div>
                                    </div>
                                </div>
                                <div class="line"></div>
                                <div class="sub store-name-display" id="text-scan-pay" style="margin-top:10px; font-size:14px; font-weight:900; opacity:.9;">
                                    <?php echo __('merchant.qr_create.preview.scan_pay'); ?>
                                </div>
                            </div>
                            <div class="qr-slot">
                                <div class="qr-inner" id="qrCodeWrapper">
                                    <div id="qrCodeContainer"></div>
                                    <div class="corner c1"></div><div class="corner c2"></div><div class="corner c3"></div><div class="corner c4"></div>
                                </div>
                            </div>
                            <div class="bottom">
                                <div class="trust" id="text-trust">
                                    <span><?php echo __('merchant.qr_create.preview.safe'); ?></span><span>•</span><span><?php echo __('merchant.qr_create.preview.fast'); ?></span><span>•</span><span><?php echo __('merchant.qr_create.preview.reliable'); ?></span>
                                </div>
                                <div class="pill" id="text-pill"><?php echo __('merchant.qr_create.preview.pill'); ?></div>
                                <div class="thank" id="text-thank"><?php echo __('merchant.qr_create.preview.thank'); ?></div>
                            </div>
                        </div>
                        <div class="text-muted small mt-3"><i class="fas fa-search-plus me-1"></i> <?php echo __('merchant.qr_create.realtime_preview'); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer -->
    <div class="text-center py-4 text-muted small">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?>. All rights reserved. 
        <span class="mx-2">|</span> 
        Secure Payment Gateway
    </div>
</div>

<script>
const templates = <?php echo json_encode($templates); ?>;
const templateKeys = Object.keys(templates);
let currentStyleIndex = 2; // Default to 't3' (index 2)
const defaultReceiveMode = <?php echo json_encode($defaultQrMode); ?>;
const merchantId = <?php echo (int)$user_id; ?>;
const walletOptions = <?php echo json_encode($walletOptions); ?>;
const derivedOptions = <?php echo json_encode($derivedOptions); ?>;

const i18n = {
    zh: {
        secure: <?php echo json_encode(__('merchant.qr_create.preview.secure')); ?>,
        scanPay: <?php echo json_encode(__('merchant.qr_create.preview.scan_pay')); ?>,
        trust: '<span><?php echo jsesc(__('merchant.qr_create.preview.safe')); ?></span><span>•</span><span><?php echo jsesc(__('merchant.qr_create.preview.fast')); ?></span><span>•</span><span><?php echo jsesc(__('merchant.qr_create.preview.reliable')); ?></span>',
        pill: <?php echo json_encode(__('merchant.qr_create.preview.pill')); ?>,
        thank: <?php echo json_encode(__('merchant.qr_create.preview.thank')); ?>
    },
    en: {
        secure: 'Secure Payment',
        scanPay: 'Scan to Pay',
        trust: '<span>Safe</span><span>•</span><span>Fast</span><span>•</span><span>Reliable</span>',
        pill: '24/7 Instant Settlement',
        thank: 'Thank you!'
    }
};

function getLang() {
    return document.querySelector('input[name="lang"]:checked').value;
}

function updateLanguage() {
    const lang = getLang();
    const t = i18n[lang];
    
    document.getElementById('text-secure').innerText = t.secure;
    document.getElementById('text-trust').innerHTML = t.trust;
    document.getElementById('text-pill').innerText = t.pill;
    document.getElementById('text-thank').innerText = t.thank;
    const currentKey = templateKeys[currentStyleIndex];
    if (templates[currentKey]) {
        document.getElementById('currentStyleName').innerText = lang === 'en'
            ? (templates[currentKey].name_en || templates[currentKey].name_zh)
            : templates[currentKey].name_zh;
    }
    
    updatePreviewText();
}

function updatePreviewText() {
    const name = document.getElementById('storeName').value;
    const display = document.querySelector('.store-name-display');
    const lang = getLang();
    
    if (name.trim()) {
        display.innerText = name;
    } else {
        display.innerText = i18n[lang].scanPay;
    }
}

function updateWallet() {
    const modeEl = document.getElementById('receiveModeSelect');
    const mode = modeEl && modeEl.value ? String(modeEl.value) : defaultReceiveMode;
    const select = document.getElementById('walletSelect');
    if (!select) return;
    const options = mode === 'derived' ? derivedOptions : walletOptions;
    select.innerHTML = '';
    options.forEach(function (opt) {
        const op = document.createElement('option');
        op.value = String(opt.value || '');
        op.setAttribute('data-chain', String(opt.chain || ''));
        op.textContent = String(opt.label || '');
        select.appendChild(op);
    });
    if (!options.length) return;
    const selectedValue = select.value;
    if (!selectedValue) return;
    
    // Clear previous QR
    const container = document.getElementById('qrCodeContainer');
    container.innerHTML = '';
    
    // Generate QR Code using qrcode.js
    // This should point to the mobile checkout page, NOT the wallet address directly.
    // Point to the dual-mode qr_pay.php
    
    const baseUrl = window.location.origin;
    const chain = (select.options[select.selectedIndex].getAttribute('data-chain') || '').toLowerCase();
    const name = encodeURIComponent(document.getElementById('storeName').value);
    let payUrl = '';
    const currencyEl = document.getElementById('currencySelect');
    const currency = currencyEl && currencyEl.value ? String(currencyEl.value) : 'USDT';
    if (mode === 'derived') {
        payUrl = `${baseUrl}/qr_pay.php?merchant=${merchantId}&chain=${encodeURIComponent(chain)}&name=${name}&rm=derived&currency=${encodeURIComponent(currency)}`;
    } else {
        const address = selectedValue;
        payUrl = `${baseUrl}/qr_pay.php?address=${encodeURIComponent(address)}&chain=${encodeURIComponent(chain)}&name=${name}&rm=wallet&currency=${encodeURIComponent(currency)}`;
    }
    
    new QRCode(container, {
        text: payUrl,
        width: 512,
        height: 512,
        colorDark : "#000000",
        colorLight : "#ffffff",
        correctLevel : QRCode.CorrectLevel.H
    });
    
    // Update Network Badge
    const badge = document.getElementById('networkBadge');
    if (chain) {
        badge.innerText = chain.toUpperCase();
        badge.style.display = 'inline-block';
    } else {
        badge.style.display = 'none';
    }
}

function changeStyle(direction) {
    currentStyleIndex += direction;
    if (currentStyleIndex < 0) currentStyleIndex = templateKeys.length - 1;
    if (currentStyleIndex >= templateKeys.length) currentStyleIndex = 0;
    
    const key = templateKeys[currentStyleIndex];
    applyStyle(key);
}

function applyStyle(key) {
    const tpl = templates[key];
    const poster = document.getElementById('posterContainer');
    
    // Update Name
    const lang = getLang();
    document.getElementById('currentStyleName').innerText = lang === 'en' ? (tpl.name_en || tpl.name_zh) : tpl.name_zh;
    
    // Reset classes
    poster.className = 'poster-preview ' + tpl.style;
    
    // Set Variables
    poster.style.setProperty('--bg1', tpl.theme.bg1);
    poster.style.setProperty('--bg2', tpl.theme.bg2);
    poster.style.setProperty('--accent', tpl.theme.accent);
    poster.style.setProperty('--accent2', tpl.theme.accent2);
    poster.style.setProperty('--qr-left', tpl.qr.left);
    poster.style.setProperty('--qr-top', tpl.qr.top);
    poster.style.setProperty('--qr-size', tpl.qr.size);
}

function downloadPoster() {
    const poster = document.getElementById('posterContainer');
    const name = document.getElementById('storeName').value || <?php echo json_encode(__('merchant.qr_create.default_file_name')); ?>;
    
    // Scale up for high quality
    html2canvas(poster, {
        scale: 3,
        useCORS: true,
        backgroundColor: null
    }).then(canvas => {
        const link = document.createElement('a');
        link.download = name + '.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
}

// Init
window.addEventListener('load', () => {
    updateWallet(); // Load initial QR
    // Apply initial style
    const initialKey = templateKeys[currentStyleIndex];
    const lang = getLang();
    document.getElementById('currentStyleName').innerText = lang === 'en' ? (templates[initialKey].name_en || templates[initialKey].name_zh) : templates[initialKey].name_zh;
});
</script>
</body>
</html>
