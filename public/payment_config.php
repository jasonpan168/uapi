<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/FeeAddressAllocator.php';
require_once __DIR__ . '/../src/Helper.php';
I18n::init();

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
$wallets = $db->fetchAll("SELECT * FROM wallets WHERE user_id = ? ORDER BY status DESC, id DESC", [$user_id]);
$walletDefaultChainKey = 'merchant_wallet_default_chain_u' . (int)$user_id;
$currencySettingKey = 'merchant_enabled_currencies_u' . (int)$user_id;
$settingsRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$settingsMap = [];
foreach ($settingsRows as $sr) {
    $settingsMap[(string)$sr['key_name']] = (string)$sr['value'];
}
$platformCurrencies = [];
if (($settingsMap['enable_payment_usdt'] ?? '1') === '1') {
    $platformCurrencies[] = 'USDT';
}
if (($settingsMap['enable_usdc'] ?? '0') === '1') {
    $platformCurrencies[] = 'USDC';
}
if (empty($platformCurrencies)) {
    $platformCurrencies[] = 'USDT';
}
$merchantCurrenciesRaw = strtoupper(trim((string)($settingsMap[$currencySettingKey] ?? '')));
$merchantCurrencies = [];
if ($merchantCurrenciesRaw !== '') {
    foreach (explode(',', $merchantCurrenciesRaw) as $cc) {
        $cc = strtoupper(trim($cc));
        if ($cc !== '') $merchantCurrencies[] = $cc;
    }
}
if (empty($merchantCurrencies)) {
    $merchantCurrencies = $platformCurrencies;
}
$merchantCurrencies = array_values(array_unique(array_values(array_intersect($merchantCurrencies, $platformCurrencies))));
if (empty($merchantCurrencies)) {
    $merchantCurrencies = $platformCurrencies;
}
$receiveModeKey = 'merchant_receive_mode_u' . (int)$user_id;
$receiveModeRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
$receive_mode = strtolower(trim((string)($receiveModeRow['value'] ?? 'wallet')));
if (!in_array($receive_mode, ['wallet', 'derived'], true)) {
    $receive_mode = 'wallet';
}

function payment_config_redirect_with_msg($msg)
{
    $_SESSION['payment_config_flash'] = (string)$msg;
    header("Location: payment_config.php");
    exit;
}

// Fetch Available Chains for this user's plan
$allowed_chains = $db->fetchAll("SELECT c.* FROM chains c 
    JOIN plan_chains pc ON c.id = pc.chain_id 
    WHERE pc.plan_id = ? AND c.status = 1", 
    [$user['plan_id']]
);

// Fetch ALL active chains
$all_active_chains = $db->fetchAll("SELECT * FROM chains WHERE status = 1 ORDER BY is_evm DESC, name ASC");

// Add/Edit Wallet Logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!Helper::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        header("Location: payment_config.php?msg=csrf_invalid");
        exit;
    }
    if ($_POST['action'] === 'save_receive_mode') {
        $mode = strtolower(trim((string)($_POST['receive_mode'] ?? 'wallet')));
        if (!in_array($mode, ['wallet', 'derived'], true)) {
            $mode = 'wallet';
        }
        $exists = $db->fetch("SELECT 1 FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
        if ($exists) {
            $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$mode, $receiveModeKey]);
        } else {
            $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$receiveModeKey, $mode]);
        }
        payment_config_redirect_with_msg('receive_mode_saved');
    }
    if ($_POST['action'] === 'save_enabled_currencies') {
        $selected = isset($_POST['currencies']) && is_array($_POST['currencies']) ? $_POST['currencies'] : [];
        $enabled = [];
        foreach ($selected as $cc) {
            $u = strtoupper(trim((string)$cc));
            if (in_array($u, $platformCurrencies, true)) $enabled[] = $u;
        }
        $enabled = array_values(array_unique($enabled));
        if (empty($enabled) && !empty($platformCurrencies)) {
            $enabled[] = $platformCurrencies[0];
        }
        $saveVal = implode(',', $enabled);
        $exists = $db->fetch("SELECT 1 FROM system_settings WHERE key_name = ? LIMIT 1", [$currencySettingKey]);
        if ($exists) {
            $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$saveVal, $currencySettingKey]);
        } else {
            $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$currencySettingKey, $saveVal]);
        }
        payment_config_redirect_with_msg('currencies_saved');
    }
    if ($_POST['action'] === 'add_wallet') {
        $chain = $_POST['chain'];
        $address = $_POST['address'];
        $wallet_id = isset($_POST['wallet_id']) ? (int)$_POST['wallet_id'] : 0;
        
        $slugs = array_map(function($c){return $c['slug'];}, $allowed_chains);
        if (!in_array($chain, $slugs)) { header("Location: payment_config.php"); exit; }
        
        if ($wallet_id > 0) {
            $db->query("UPDATE wallets SET address = ? WHERE id = ? AND user_id = ?", [$address, $wallet_id, $user_id]);
        } else {
            $existing = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND chain = ?", [$user_id, $chain]);
            if ($existing) {
                 $db->query("UPDATE wallets SET address = ? WHERE id = ?", [$address, $existing['id']]);
            } else {
                 $db->query("INSERT INTO wallets (user_id, chain, address) VALUES (?, ?, ?)", [$user_id, $chain, $address]);
            }
        }
        header("Location: payment_config.php");
        exit;
    }
    if ($_POST['action'] === 'toggle_wallet_status') {
        $wallet_id = isset($_POST['wallet_id']) ? (int)$_POST['wallet_id'] : 0;
        $enable = isset($_POST['enable']) ? (int)$_POST['enable'] : 0;
        $row = $db->fetch("SELECT id, chain, status FROM wallets WHERE id = ? AND user_id = ? LIMIT 1", [$wallet_id, $user_id]);
        if ($row) {
            $db->query("UPDATE wallets SET status = ? WHERE id = ? AND user_id = ?", [$enable ? 1 : 0, $wallet_id, $user_id]);

            if ($enable) {
                $exists = $db->fetch("SELECT 1 FROM system_settings WHERE key_name = ? LIMIT 1", [$walletDefaultChainKey]);
                if ($exists) {
                    $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [(string)$row['chain'], $walletDefaultChainKey]);
                } else {
                    $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$walletDefaultChainKey, (string)$row['chain']]);
                }
            } else {
                $defaultRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$walletDefaultChainKey]);
                $defaultChain = strtolower(trim((string)($defaultRow['value'] ?? '')));
                if ($defaultChain === strtolower((string)$row['chain'])) {
                    $next = $db->fetch("SELECT chain FROM wallets WHERE user_id = ? AND status = 1 ORDER BY id DESC LIMIT 1", [$user_id]);
                    $nextChain = (string)($next['chain'] ?? '');
                    $exists = $db->fetch("SELECT 1 FROM system_settings WHERE key_name = ? LIMIT 1", [$walletDefaultChainKey]);
                    if ($exists) {
                        $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$nextChain, $walletDefaultChainKey]);
                    } else {
                        $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$walletDefaultChainKey, $nextChain]);
                    }
                }
            }
        }
        header("Location: payment_config.php");
        exit;
    }
    // Test Pay
    if ($_POST['action'] === 'test_pay') {
        $chain = $_POST['chain'];
        $amount = (float)($_POST['amount'] ?? 0.01);
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USDT')));
        if (!in_array($currency, $merchantCurrencies, true)) {
            payment_config_redirect_with_msg('currency_not_enabled');
        }
        if ($currency === 'USDC' && strtolower((string)$chain) === 'trc20') {
            payment_config_redirect_with_msg('currency_chain_not_supported');
        }
        if ($amount <= 0) $amount = 0.01;
        $order_no = 'TEST' . date('YmdHis') . rand(1000, 9999);
        $merchant_order_id = 'SELF-TEST-' . time();
        $pay_access_token = bin2hex(random_bytes(16));
        $final_amount = $amount;

        $wallet = null;
        $chainSlug = strtolower((string)$chain);
        // Testing from this page must always work with the merchant's configured fixed wallet first.
        $wallet = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND chain = ? AND status = 1 LIMIT 1", [$user_id, $chainSlug]);

        $receiveModeCurrentRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
        $receiveModeCurrent = strtolower(trim((string)($receiveModeCurrentRow['value'] ?? $receive_mode)));
        if (!in_array($receiveModeCurrent, ['wallet', 'derived'], true)) {
            $receiveModeCurrent = 'wallet';
        }
        if ($receiveModeCurrent !== 'derived') {
            $rand_int = rand(1000, 9999);
            if ($rand_int % 10 == 0) $rand_int += rand(1, 9);
            $final_amount = $amount + ($rand_int / 1000000);
        }

        // Only fallback to derived allocation when no active fixed wallet is configured.
        if (!$wallet && $receiveModeCurrent === 'derived') {
            $chainMeta = $db->fetch("SELECT is_evm, COALESCE(allow_derived, 1) AS allow_derived FROM chains WHERE slug = ? AND status = 1 LIMIT 1", [$chainSlug]);
            $canDerivedOnChain = $chainMeta && (int)($chainMeta['is_evm'] ?? 0) === 1 && (int)($chainMeta['allow_derived'] ?? 1) === 1;
            if (!$canDerivedOnChain) {
                payment_config_redirect_with_msg('derived_not_supported');
            }
            $allocCfg = FeeAddressAllocator::loadSettings($db);
            $allocCfg['admin_fee_address_mode'] = 'derived';
            try {
                $alloc = FeeAddressAllocator::resolveChargeWallet($db, $order_no, 'dashboard_test', (int)$user_id, (string)$chain, $allocCfg);
                if ($alloc && !empty($alloc['wallet_id']) && strtolower((string)($alloc['chain'] ?? '')) === strtolower((string)$chain)) {
                    $wallet = ['id' => (int)$alloc['wallet_id']];
                }
            } catch (Exception $e) {
                payment_config_redirect_with_msg('derived_alloc_failed');
            }
            if (!$wallet) {
                payment_config_redirect_with_msg('derived_alloc_failed');
            }
        }
        if ($wallet) {
            $db->query("INSERT INTO orders (order_no, merchant_order_id, pay_access_token, user_id, wallet_id, amount, currency, chain, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())", [
                $order_no, $merchant_order_id, $pay_access_token, $user_id, $wallet['id'], number_format($final_amount, 6, '.', ''), $currency, $chain
            ]);
            header("Location: pay.php?order=$order_no&token=$pay_access_token");
            exit;
        }
        payment_config_redirect_with_msg('wallet_not_configured');
    }
}

$page_title = trim((string)__('merchant.payment_config.title'));
if ($page_title === '' || $page_title === 'merchant.payment_config.title') {
    $page_title = '支付配置';
}
$flashMsgCode = '';
if (!empty($_SESSION['payment_config_flash'])) {
    $flashMsgCode = (string)$_SESSION['payment_config_flash'];
    unset($_SESSION['payment_config_flash']);
}
?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = {
        corePlugins: { preflight: false },
        theme: {
          extend: {
            colors: {
                primary: '#0f172a',
                secondary: '#64748b',
                success: '#10b981',
                danger: '#ef4444',
                warning: '#f59e0b',
                surface: '#ffffff',
                background: '#f8fafc',
            },
            fontFamily: {
                sans: ['Inter', 'system-ui', 'sans-serif'],
                mono: ['Fira Code', 'Menlo', 'Monaco', 'Consolas', 'monospace'],
            }
          }
        }
      }
    </script>
    <style>
        .page-body { font-family: 'Inter', system-ui, sans-serif; background-color: #f8fafc; }
        
        .mole-card { 
            background: #fff; 
            border-radius: 16px; 
            box-shadow: 0 1px 2px rgba(0,0,0,0.05); 
            border: 1px solid #e2e8f0; 
            transition: all 0.2s ease;
        }
        .mole-card:hover {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            border-color: #cbd5e1;
        }

        /* Custom Modal */
        .modal-backdrop {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(4px);
            z-index: 50; display: none; opacity: 0; transition: opacity 0.3s ease;
        }
        .modal-backdrop.show { display: flex; opacity: 1; }
        .modal-content {
            background: white; width: 100%; max-width: 500px; margin: auto;
            border-radius: 16px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            transform: scale(0.95); transition: transform 0.3s ease;
        }
        .modal-backdrop.show .modal-content { transform: scale(1); }

        .chain-icon { width: 32px; height: 32px; object-fit: contain; }
        
        .status-badge {
            @apply px-2.5 py-0.5 rounded-full text-xs font-bold border;
        }
        .status-badge.enabled { @apply bg-green-50 text-green-700 border-green-200; }
        .status-badge.disabled { @apply bg-gray-50 text-gray-600 border-gray-200; }
        .status-badge.locked { @apply bg-yellow-50 text-yellow-700 border-yellow-200; }
    </style>
</head>
<body class="page-body">
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Content -->
        <div class="col-md-9 col-lg-10 main-content min-h-screen">
            <?php include __DIR__ . '/includes/user_topbar.php'; ?>
            
            <div class="w-full max-w-[1600px]">

                <!-- Alerts -->
                <div class="space-y-4 mb-6">
                    <?php if ($flashMsgCode !== ''): ?>
                        <?php 
                        $msgMap = [
                            'receive_mode_saved' => ['success', __('merchant.payment_config.flash.receive_mode_saved')],
                            'currencies_saved' => ['success', __('merchant.payment_config.flash.currencies_saved')],
                            'derived_alloc_failed' => ['error', __('merchant.payment_config.flash.derived_alloc_failed')],
                            'derived_not_supported' => ['warning', __('merchant.payment_config.flash.derived_not_supported')],
                            'wallet_not_configured' => ['warning', __('merchant.payment_config.flash.wallet_not_configured')],
                            'currency_not_enabled' => ['warning', __('merchant.payment_config.flash.currency_not_enabled')],
                            'currency_chain_not_supported' => ['warning', __('merchant.payment_config.flash.currency_chain_not_supported')]
                        ];
                        if (isset($msgMap[$flashMsgCode])): 
                            $m = $msgMap[$flashMsgCode];
                            $color = $m[0] === 'success' ? 'green' : ($m[0] === 'error' ? 'red' : 'amber');
                            $icon = $m[0] === 'success' ? 'check-circle' : ($m[0] === 'error' ? 'triangle-exclamation' : 'circle-exclamation');
                        ?>
                        <div class="p-3 rounded-lg border bg-<?php echo $color; ?>-50 border-<?php echo $color; ?>-200 text-<?php echo $color; ?>-800 flex items-center justify-between shadow-sm">
                            <div class="flex items-center gap-3">
                                <i class="fa-solid fa-<?php echo $icon; ?>"></i>
                                <span class="text-sm font-medium"><?php echo $m[1]; ?></span>
                            </div>
                            <button onclick="this.parentElement.remove()" class="text-<?php echo $color; ?>-600 hover:text-<?php echo $color; ?>-900"><i class="fa-solid fa-xmark"></i></button>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Global Settings -->
                <div class="mb-6">
                    <details class="mole-card" style="overflow: hidden;">
                        <summary class="px-4 py-3 cursor-pointer flex items-center justify-between select-none">
                            <div>
                                <h3 class="text-sm font-bold text-gray-900 mb-1"><?php echo __('merchant.payment_config.collection_settings'); ?></h3>
                                <p class="text-xs text-gray-500"><?php echo __('merchant.payment_config.collection_settings_desc'); ?></p>
                            </div>
                            <i class="fa-solid fa-chevron-down text-gray-400"></i>
                        </summary>
                        <div class="px-4 pb-4 pt-2 border-t border-gray-100">
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                <form method="POST" class="mole-card p-4 flex flex-col"><?php echo Helper::csrfField(); ?>
                                    <input type="hidden" name="action" value="save_receive_mode">
                                    <div class="flex justify-between items-start mb-3">
                                        <div>
                                            <h4 class="text-sm font-bold text-gray-900"><?php echo __('merchant.payment_config.receiving_config'); ?></h4>
                                            <p class="text-xs text-gray-500 mt-1"><?php echo __('merchant.payment_config.choose_receive_mode'); ?></p>
                                        </div>
                                    </div>
                                    <div class="space-y-3">
                                        <label class="flex items-center p-3 rounded-xl border border-gray-200 cursor-pointer hover:bg-gray-50 transition-colors <?php echo $receive_mode==='wallet'?'bg-blue-100 border-blue-300 ring-1 ring-blue-300':''; ?>">
                                            <input type="radio" name="receive_mode" value="wallet" class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500" <?php echo $receive_mode==='wallet'?'checked':''; ?>>
                                            <div class="ml-3">
                                                <span class="block text-sm font-bold text-gray-900"><?php echo __('merchant.payment_config.mode.wallet'); ?></span>
                                                <span class="block text-xs text-gray-500"><?php echo __('merchant.payment_config.mode.wallet_desc'); ?></span>
                                            </div>
                                        </label>
                                        <label class="flex items-center p-3 rounded-xl border border-gray-200 cursor-pointer hover:bg-gray-50 transition-colors <?php echo $receive_mode==='derived'?'bg-blue-100 border-blue-300 ring-1 ring-blue-300':''; ?>">
                                            <input type="radio" name="receive_mode" value="derived" class="w-4 h-4 text-blue-600 border-gray-300 focus:ring-blue-500" <?php echo $receive_mode==='derived'?'checked':''; ?>>
                                            <div class="ml-3">
                                                <span class="block text-sm font-bold text-gray-900"><?php echo __('merchant.payment_config.mode.derived'); ?></span>
                                                <span class="block text-xs text-gray-500"><?php echo __('merchant.payment_config.mode.derived_desc'); ?></span>
                                            </div>
                                        </label>
                                    </div>
                                    <div class="mt-4 pt-2 border-t border-gray-100 flex justify-end">
                                        <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-black text-white text-xs font-bold rounded-lg transition-colors">
                                            <?php echo __('merchant.payment_config.save_changes'); ?>
                                        </button>
                                    </div>
                                </form>

                                <form method="POST" class="mole-card p-4 flex flex-col"><?php echo Helper::csrfField(); ?>
                                    <input type="hidden" name="action" value="save_enabled_currencies">
                                    <div class="mb-3">
                                        <h4 class="text-sm font-bold text-gray-900"><?php echo __('merchant.payment_config.accepted_currencies'); ?></h4>
                                        <p class="text-xs text-gray-500 mt-1"><?php echo __('merchant.payment_config.accepted_currencies_desc'); ?></p>
                                    </div>
                                    <div class="flex flex-wrap gap-2 mb-4">
                                        <?php foreach ($platformCurrencies as $pc): ?>
                                            <label class="cursor-pointer">
                                                <input type="checkbox" name="currencies[]" value="<?php echo htmlspecialchars($pc); ?>" class="peer sr-only" <?php echo in_array($pc, $merchantCurrencies, true) ? 'checked' : ''; ?>>
                                                <div class="px-3 py-1.5 rounded-lg border border-gray-300 text-sm font-bold text-gray-700 peer-checked:bg-emerald-600 peer-checked:text-white peer-checked:border-emerald-700 peer-checked:shadow-md transition-all flex items-center gap-2 hover:bg-gray-50">
                                                    <i class="fa-solid fa-circle-check opacity-0 peer-checked:opacity-100 transition-opacity"></i>
                                                    <?php echo htmlspecialchars($pc); ?>
                                                </div>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="mt-auto pt-2 border-t border-gray-100 flex justify-end">
                                        <button type="submit" class="px-4 py-2 bg-gray-900 hover:bg-black text-white text-xs font-bold rounded-lg transition-colors">
                                            <?php echo __('merchant.payment_config.save_changes'); ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </details>
                </div>

                <!-- Chains Grid -->
                <h3 class="text-lg font-bold text-gray-900 mb-4 px-1">Payment Networks</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6">
                    <?php 
                    $allowed_slugs = array_map(function($c){return $c['slug'];}, $allowed_chains);
                    foreach($all_active_chains as $c): 
                        $slug = $c['slug'];
                        $is_allowed = in_array($slug, $allowed_slugs);
                        if (!$is_allowed) continue;

                        $user_wallet = null;
                        foreach($wallets as $w) { if ($w['chain'] === $slug) { $user_wallet = $w; break; } }
                        
                        $isEnabled = $user_wallet && (int)($user_wallet['status'] ?? 1) === 1;
                        $icon = strtolower($c['symbol']);
                        $map = ['bnb'=>'bnb', 'matic'=>'matic'];
                        $iconUrl = "https://cdn.jsdelivr.net/gh/atomiclabs/cryptocurrency-icons@1a63530be6e374711a8554f31b17e4cb92c25fa5/32/color/" . ($map[$icon] ?? $icon) . ".png";
                    ?>
                    <div class="mole-card p-5 flex flex-col h-full <?php echo !$is_allowed ? 'opacity-60 grayscale' : ''; ?>">
                        <!-- Header -->
                        <div class="flex justify-between items-start mb-4">
                            <div class="flex items-center gap-3">
                                <img src="<?php echo $iconUrl; ?>" class="w-10 h-10 rounded-full shadow-sm bg-gray-50 p-0.5" alt="<?php echo $slug; ?>">
                                <div>
                                    <h4 class="font-bold text-gray-900 text-sm"><?php echo htmlspecialchars($c['name']); ?></h4>
                                    <span class="text-[10px] text-gray-400 font-mono uppercase"><?php echo $slug; ?></span>
                                </div>
                            </div>
                            <?php if($user_wallet): ?>
                                <span class="status-badge <?php echo $isEnabled ? 'enabled' : 'disabled'; ?>">
                                    <?php echo $isEnabled ? __('merchant.payment_config.status.active') : __('merchant.payment_config.status.disabled'); ?>
                                </span>
                            <?php elseif($is_allowed): ?>
                                <span class="status-badge disabled"><?php echo __('merchant.payment_config.not_configured'); ?></span>
                            <?php else: ?>
                                <span class="status-badge locked"><i class="fa-solid fa-lock mr-1"></i> Locked</span>
                            <?php endif; ?>
                        </div>

                        <!-- Body -->
                        <div class="flex-grow">
                            <?php if($user_wallet): ?>
                                <div class="bg-gray-50 p-3 rounded-lg border border-gray-100 mb-4 group relative cursor-pointer" onclick="copyText('<?php echo $user_wallet['address']; ?>')">
                                    <div class="text-[10px] text-gray-400 uppercase font-bold mb-1"><?php echo __('merchant.payment_config.wallet_address'); ?></div>
                                    <div class="font-mono text-xs text-gray-700 break-all leading-relaxed">
                                        <?php echo $user_wallet['address']; ?>
                                    </div>
                                    <div class="absolute inset-0 bg-gray-900/5 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center">
                                        <span class="text-xs font-bold text-gray-700"><i class="fa-regular fa-copy"></i> <?php echo __('merchant.payment_config.copy'); ?></span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 mb-3">
                                    <span class="text-[10px] text-gray-400 uppercase font-bold">余额：</span>
                                    <span id="bal_<?php echo preg_replace('/[^a-zA-Z0-9]/','',$user_wallet['address']); ?>" class="text-xs text-gray-500">-</span>
                                    <button type="button" class="px-1.5 py-0.5 rounded border border-blue-200 text-blue-500 hover:bg-blue-50 text-[10px] transition-colors"
                                        onclick="checkWalletBalance('<?php echo htmlspecialchars($slug); ?>','<?php echo htmlspecialchars($user_wallet['address']); ?>','USDT',this)"
                                        title="查询余额">
                                        <i class="fa-solid fa-rotate-right" style="font-size:10px;"></i>
                                    </button>
                                </div>
                            <?php else: ?>
                                <div class="bg-gray-50 border border-dashed border-gray-200 rounded-lg p-6 text-center mb-4 h-24 flex flex-col justify-center items-center">
                                    <p class="text-xs text-gray-400"><?php echo __('merchant.payment_config.no_wallet'); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Footer Actions -->
                        <div class="pt-4 border-t border-gray-100 flex gap-2">
                            <?php if($user_wallet): ?>
                                <button onclick="editWallet(<?php echo $user_wallet['id']; ?>, '<?php echo $slug; ?>', '<?php echo $user_wallet['address']; ?>', '<?php echo $c['name']; ?>')" 
                                        class="flex-1 py-2 rounded-lg border border-gray-200 text-gray-600 text-xs font-bold hover:bg-gray-50 transition-colors">
                                    <?php echo __('merchant.payment_config.edit'); ?>
                                </button>
                                <button onclick="testPay('<?php echo $slug; ?>')" 
                                        class="flex-1 py-2 rounded-lg border border-blue-100 text-blue-600 bg-blue-50 text-xs font-bold hover:bg-blue-100 transition-colors <?php echo $isEnabled ? '' : 'opacity-50 cursor-not-allowed'; ?>" <?php echo $isEnabled ? '' : 'disabled'; ?>>
                                    <?php echo __('merchant.payment_config.test'); ?>
                                </button>
                                <form method="POST" class="contents"><?php echo Helper::csrfField(); ?>
                                    <input type="hidden" name="action" value="toggle_wallet_status">
                                    <input type="hidden" name="wallet_id" value="<?php echo (int)$user_wallet['id']; ?>">
                                    <input type="hidden" name="enable" value="<?php echo $isEnabled ? '0' : '1'; ?>">
                                    <button type="submit" class="w-8 flex items-center justify-center rounded-lg border <?php echo $isEnabled ? 'border-red-100 text-red-500 hover:bg-red-50' : 'border-green-100 text-green-500 hover:bg-green-50'; ?> transition-colors" title="<?php echo $isEnabled ? __('merchant.payment_config.disable') : __('merchant.payment_config.enable'); ?>">
                                        <i class="fa-solid fa-power-off"></i>
                                    </button>
                                </form>
                            <?php elseif($is_allowed): ?>
                                <button onclick="addWallet('<?php echo $slug; ?>', '<?php echo $c['name']; ?>')" class="w-full py-2.5 bg-gray-900 hover:bg-black text-white text-xs font-bold rounded-lg transition-colors shadow-sm">
                                    <?php echo __('merchant.payment_config.configure_now'); ?>
                                </button>
                            <?php else: ?>
                                <a href="upgrade.php" class="w-full py-2.5 bg-yellow-50 hover:bg-yellow-100 text-yellow-700 border border-yellow-200 text-xs font-bold rounded-lg transition-colors text-center block">
                                    <i class="fa-solid fa-crown mr-1"></i> <?php echo __('merchant.payment_config.upgrade_unlock'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Wallet Modal -->
<div id="addWalletModal" class="modal-backdrop">
    <div class="modal-content p-0">
        <form method="POST" class="flex flex-col h-full"><?php echo Helper::csrfField(); ?>
            <input type="hidden" name="action" value="add_wallet">
            <input type="hidden" name="wallet_id" id="walletId" value="0">
            <input type="hidden" name="chain" id="walletChainValue">
            
            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900" id="walletModalTitle"><?php echo __('merchant.payment_config.modal.configure_wallet'); ?></h3>
                <button type="button" onclick="closeModal('addWalletModal')" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2"><?php echo __('merchant.payment_config.modal.network'); ?></label>
                    <input type="text" id="walletChainDisplay" readonly class="w-full bg-gray-50 border border-gray-200 text-gray-600 rounded-lg p-3 text-sm font-medium focus:outline-none">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2"><?php echo __('merchant.payment_config.modal.wallet_address'); ?></label>
                    <textarea name="address" id="walletAddress" rows="2" required class="w-full border border-gray-200 rounded-lg p-3 text-sm focus:border-gray-900 focus:ring-0 font-mono transition-colors" placeholder="<?php echo __('merchant.payment_config.modal.wallet_placeholder'); ?>"></textarea>
                    <p class="text-[10px] text-gray-400 mt-2">Double check your address. Transactions cannot be reversed.</p>
                </div>
            </div>

            <div class="p-6 border-t border-gray-100 bg-gray-50/50 rounded-b-2xl flex justify-end gap-3">
                <button type="button" onclick="closeModal('addWalletModal')" class="px-5 py-2.5 bg-white border border-gray-200 text-gray-700 font-bold rounded-lg text-sm hover:bg-gray-50 transition-colors">
                    <?php echo __('merchant.common.cancel'); ?>
                </button>
                <button type="submit" class="px-5 py-2.5 bg-gray-900 hover:bg-black text-white font-bold rounded-lg text-sm shadow-lg shadow-gray-900/20 transition-all hover:transform hover:-translate-y-0.5">
                    <?php echo __('merchant.payment_config.modal.save'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Test Pay Modal -->
<div id="testPayModal" class="modal-backdrop">
    <div class="modal-content p-0 max-w-sm">
        <form method="POST" target="_blank"><?php echo Helper::csrfField(); ?>
            <input type="hidden" name="action" value="test_pay">
            <input type="hidden" name="chain" id="testChain" value="">
            
            <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                <h3 class="text-lg font-bold text-gray-900"><?php echo __('merchant.payment_config.test_modal.title'); ?></h3>
                <button type="button" onclick="closeModal('testPayModal')" class="text-gray-400 hover:text-gray-600"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            
            <div class="p-6">
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2"><?php echo __('merchant.payment_config.test_modal.currency'); ?></label>
                <select name="currency" class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm font-bold text-gray-900 focus:border-gray-900 focus:ring-0 mb-3">
                    <?php foreach ($merchantCurrencies as $cc): ?>
                    <option value="<?php echo htmlspecialchars($cc); ?>"><?php echo htmlspecialchars($cc); ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-2"><?php echo __('merchant.payment_config.test_modal.amount'); ?></label>
                <div class="relative">
                    <span class="absolute left-3 top-2.5 text-gray-400">$</span>
                    <input type="number" name="amount" value="0.01" step="0.01" min="0.01" required class="w-full border border-gray-200 rounded-lg pl-7 pr-3 py-2.5 text-sm font-bold text-gray-900 focus:border-gray-900 focus:ring-0">
                </div>
                <p class="text-xs text-blue-600 mt-3 bg-blue-50 p-2 rounded border border-blue-100">
                    <i class="fa-solid fa-circle-info mr-1"></i> <?php echo __('merchant.payment_config.test_modal.tip_real_order'); ?>
                </p>
            </div>

            <div class="p-6 border-t border-gray-100 bg-gray-50/50 rounded-b-2xl flex justify-end gap-3">
                <button type="button" onclick="closeModal('testPayModal')" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 font-bold rounded-lg text-sm hover:bg-gray-50 transition-colors">
                    <?php echo __('merchant.common.cancel'); ?>
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-bold rounded-lg text-sm shadow-lg shadow-green-600/20 transition-all hover:transform hover:-translate-y-0.5">
                    <?php echo __('merchant.payment_config.test_modal.create_order'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    // Modal Functions
    function openModal(id) {
        document.getElementById(id).classList.add('show');
    }
    function closeModal(id) {
        document.getElementById(id).classList.remove('show');
    }

    // Close on backdrop click
    document.querySelectorAll('.modal-backdrop').forEach(el => {
        el.addEventListener('click', (e) => {
            if (e.target === el) closeModal(el.id);
        });
    });

    // Wallet Logic
    function addWallet(chainSlug, chainName) {
        document.getElementById('walletId').value = 0;
        document.getElementById('walletChainValue').value = chainSlug;
        document.getElementById('walletChainDisplay').value = chainName;
        document.getElementById('walletAddress').value = '';
        document.getElementById('walletModalTitle').innerText = <?php echo json_encode(__('merchant.payment_config.modal.configure_wallet')); ?>;
        
        let ph = <?php echo json_encode(__('merchant.payment_config.modal.wallet_placeholder')); ?>;
        if(chainSlug === 'trc20') ph = <?php echo json_encode(__('merchant.dashboard.placeholder.trc20')); ?>;
        else if(chainSlug === 'solana') ph = <?php echo json_encode(__('merchant.dashboard.placeholder.solana')); ?>;
        else ph = <?php echo json_encode(__('merchant.dashboard.placeholder.evm')); ?>;
        document.getElementById('walletAddress').placeholder = ph;

        openModal('addWalletModal');
    }

    function editWallet(id, chainSlug, address, chainName) {
        document.getElementById('walletId').value = id;
        document.getElementById('walletChainValue').value = chainSlug;
        document.getElementById('walletChainDisplay').value = chainName;
        document.getElementById('walletAddress').value = address;
        document.getElementById('walletModalTitle').innerText = <?php echo json_encode(__('merchant.payment_config.modal.edit_wallet')); ?>;
        openModal('addWalletModal');
    }
    
    function testPay(chain) {
        document.getElementById('testChain').value = chain;
        openModal('testPayModal');
    }

    function copyText(text) {
        navigator.clipboard.writeText(text).then(() => {
            const box = document.createElement('div');
            box.className = 'fixed right-6 top-6 z-[80] px-4 py-2 rounded-lg bg-gray-900 text-white text-sm shadow-lg';
            box.textContent = <?php echo json_encode(__('merchant.payment_config.address_copied')); ?>;
            document.body.appendChild(box);
            setTimeout(() => box.remove(), 1600);
        });
    }
</script>
<!-- Balance Query Button & Logic -->
<script>
function checkWalletBalance(chain, address, currency, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:10px;height:10px;border-width:1px;"></span>';
    fetch('/api/v1/chain/balance.php?chain=' + encodeURIComponent(chain) + '&address=' + encodeURIComponent(address) + '&currency=' + encodeURIComponent(currency))
        .then(r => r.json())
        .then(d => {
            const bal = d.balance !== null ? d.balance.toFixed(4) + ' ' + (d.currency || currency) : '查询失败';
            const el = document.getElementById('bal_' + address.replace(/[^a-zA-Z0-9]/g, ''));
            if (el) el.textContent = bal;
            btn.innerHTML = '<i class="fa-solid fa-rotate-right" style="font-size:10px;"></i>';
            btn.disabled = false;
        })
        .catch(() => {
            btn.innerHTML = '失败';
            btn.disabled = false;
        });
}
</script>
</body>
</html>
