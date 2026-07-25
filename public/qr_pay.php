<?php
// public/qr_pay.php
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/FeeAddressAllocator.php';
I18n::init();
$db = Database::getInstance();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$address = $_GET['address'] ?? '';
$chain = strtolower(trim((string)($_GET['chain'] ?? '')));
$merchant = isset($_GET['merchant']) ? (int)$_GET['merchant'] : 0;
$name = $_GET['name'] ?? '';
$requestedMode = strtolower(trim((string)($_GET['rm'] ?? '')));
$requestedCurrency = strtoupper(trim((string)($_GET['currency'] ?? '')));

$code = null;
$wallet = null;
$merchant_id_prefix = 'QR-';
$source_type = 'qr_code';
$source_id = 0;

if ($id > 0) {
    // Mode 1: Legacy (Database ID)
    $code = $db->fetch("SELECT * FROM qr_codes WHERE id = ?", [$id]);
    if (!$code) die(__('front.qr_pay.error.invalid_qr'));
    
    $wallet = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND LOWER(chain) = ? AND status = 1 LIMIT 1", [$code['user_id'], strtolower((string)$code['chain'])]);
    $merchant_id_prefix = 'QR-' . $code['id'] . '-';
    $source_id = $code['id'];
    
} elseif ($address && $chain) {
    // Mode 2: Stateless (Direct Address)
    // Validate Wallet exists
    $wallet_info = $db->fetch("SELECT * FROM wallets WHERE address = ? AND LOWER(chain) = ? AND status = 1 LIMIT 1", [$address, $chain]);
    if (!$wallet_info) die(__('front.qr_pay.error.wallet_not_registered'));
    
    $code = [
        'name' => $name ?: __('front.qr_pay.scan_pay'),
        'user_id' => $wallet_info['user_id'],
        'chain' => strtolower((string)$wallet_info['chain']),
        'id' => 0 // Stateless
    ];
    $wallet = ['id' => $wallet_info['id']];
    $merchant_id_prefix = 'QRG-' . $wallet_info['user_id'] . '-'; // QRG = QR Global
    $source_id = 0;
} elseif ($merchant > 0 && $chain !== '') {
    // Mode 3: Merchant + Chain (Derived-friendly stateless)
    $merchantUser = $db->fetch("SELECT id FROM users WHERE id = ? LIMIT 1", [$merchant]);
    if (!$merchantUser) die(__('front.qr_pay.error.invalid_params'));
    $code = [
        'name' => $name ?: __('front.qr_pay.scan_pay'),
        'user_id' => (int)$merchantUser['id'],
        'chain' => $chain,
        'id' => 0
    ];
    $wallet = null;
    $merchant_id_prefix = 'QRM-' . (int)$merchantUser['id'] . '-';
    $source_id = 0;
} else {
    die(__('front.qr_pay.error.invalid_params'));
}

$code['chain'] = strtolower(trim((string)($code['chain'] ?? '')));

$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$platformCurrencies = [];
if (($cfg['enable_payment_usdt'] ?? '1') === '1') $platformCurrencies[] = 'USDT';
if (($cfg['enable_usdc'] ?? '0') === '1') $platformCurrencies[] = 'USDC';
if (empty($platformCurrencies)) $platformCurrencies[] = 'USDT';
$enabledCurrencies = $platformCurrencies;
$selectedCurrency = $requestedCurrency;
if (!in_array($selectedCurrency, $enabledCurrencies, true)) {
    $selectedCurrency = $enabledCurrencies[0];
}
if ($selectedCurrency === 'USDC' && strtolower((string)$code['chain']) === 'trc20') {
    $selectedCurrency = 'USDT';
}

$defaultReceiveMode = 'wallet';
if ($address !== '') {
    $defaultReceiveMode = 'wallet';
} else {
    $receiveModeKey = 'merchant_receive_mode_u' . (int)$code['user_id'];
    $receiveModeRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
    $defaultReceiveMode = strtolower(trim((string)($receiveModeRow['value'] ?? 'wallet')));
}
if (!in_array($defaultReceiveMode, ['wallet', 'derived'], true)) {
    $defaultReceiveMode = 'wallet';
}
$activeReceiveMode = in_array($requestedMode, ['wallet', 'derived'], true) ? $requestedMode : $defaultReceiveMode;
if ($address !== '') {
    $activeReceiveMode = 'wallet';
}

// Process Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currency = strtoupper(trim((string)($_POST['currency'] ?? $selectedCurrency)));
    if (!in_array($currency, $enabledCurrencies, true)) {
        $error = I18n::getLang() === 'en' ? 'Selected currency is not available' : '所选币种不可用';
    }
    $amount = (float)$_POST['amount'];
    if (empty($error) && $amount <= 0) {
        $error = __('front.qr_pay.error.invalid_amount');
    } elseif (empty($error) && $currency === 'USDC' && strtolower((string)$code['chain']) === 'trc20') {
        $error = I18n::getLang() === 'en' ? 'USDC is not supported on TRC20' : 'TRC20 暂不支持 USDC';
    } else {
        // Create Order
        $order_no = 'QR' . date('YmdHis') . rand(1000, 9999);
        $merchant_order_id = $merchant_id_prefix . time();
        $pay_access_token = bin2hex(random_bytes(16));
        
        $resolvedWalletId = (int)($wallet['id'] ?? 0);
        $receiveMode = $activeReceiveMode;
        $final_amount = $amount;
        if ($receiveMode !== 'derived') {
            $rand_int = rand(1000, 9999);
            if ($rand_int % 10 == 0) $rand_int += rand(1, 9);
            $final_amount = $amount + ($rand_int / 1000000);
        }
        $amount_fmt = number_format($final_amount, 6, '.', '');
        if ($receiveMode === 'derived') {
            $allocCfg = FeeAddressAllocator::loadSettings($db);
            $allocCfg['admin_fee_address_mode'] = 'derived';
            try {
                $alloc = FeeAddressAllocator::resolveChargeWallet($db, $order_no, 'qr_pay', (int)$code['user_id'], (string)$code['chain'], $allocCfg);
                if ($alloc && !empty($alloc['wallet_id']) && strtolower((string)($alloc['chain'] ?? '')) === strtolower((string)$code['chain'])) {
                    $resolvedWalletId = (int)$alloc['wallet_id'];
                }
            } catch (Exception $e) {
                $error = I18n::getLang() === 'en'
                    ? ('Derived address allocation failed: ' . $e->getMessage())
                    : ('派生地址分配失败：' . $e->getMessage());
            }
            if ($resolvedWalletId <= 0 && empty($error)) {
                $error = I18n::getLang() === 'en'
                    ? 'Derived address allocation failed on this chain'
                    : '该网络派生地址分配失败';
            }
        }

        if ($receiveMode !== 'derived' && $resolvedWalletId <= 0) {
            $error = __('front.qr_pay.error.wallet_unavailable');
        }
        if (!empty($error)) {
            goto render_page;
        }

        $db->query("INSERT INTO orders (order_no, merchant_order_id, pay_access_token, user_id, wallet_id, amount, currency, chain, status, is_fast_sync, created_at, source, source_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 0, NOW(), ?, ?)", [
            $order_no, $merchant_order_id, $pay_access_token, $code['user_id'], $resolvedWalletId, $amount_fmt, $currency, $code['chain'], $source_type, $source_id
        ]);
        $dec = $db->query("UPDATE users SET fast_sync_remaining = fast_sync_remaining - 1 WHERE id = ? AND fast_sync_remaining > 0", [$code['user_id']]);
        if ($dec->rowCount() > 0) {
            $db->query("UPDATE orders SET is_fast_sync = 1 WHERE order_no = ?", [$order_no]);
        }
        header("Location: pay.php?order=$order_no&token=$pay_access_token");
        exit;
    }
}

$current_lang = I18n::getLang();
render_page:
$lang_zh_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-cn']));
$lang_en_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'en']));
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo __('front.qr_pay.checkout_title'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="/assets/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/lang-switch.css">
    <style>
        body { background: #f2f2f2; font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
        
        /* Header */
        .header-bar { background: #4CAF50; color: white; padding: 10px 15px; display: flex; justify-content: space-between; align-items: center; height: 50px; }
        .header-title { font-weight: bold; font-size: 18px; }
        
        /* Content */
        .main-content { padding: 20px; flex: 1; overflow-y: auto; }
        
        .merchant-info { display: flex; align-items: center; margin-bottom: 20px; }
        .merchant-avatar { width: 40px; height: 40px; background: #e0e0e0; border-radius: 8px; display: flex; align-items: center; justify-content: center; margin-right: 12px; color: #4CAF50; font-size: 20px; }
        .merchant-name { font-weight: 500; font-size: 16px; color: #333; }
        
        .amount-card { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .amount-label { color: #333; font-size: 14px; margin-bottom: 10px; }
        .amount-input-row { display: flex; align-items: center; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        .currency-symbol { font-size: 28px; font-weight: bold; color: #333; margin-right: 10px; }
        .amount-display { font-size: 36px; font-weight: bold; color: #333; flex: 1; border: none; outline: none; background: transparent; }
        .amount-display::placeholder { color: #ddd; }
        
        .add-note { color: #999; font-size: 14px; margin-top: 15px; display: flex; align-items: center; }
        
        .brand-footer { text-align: center; margin-top: 40px; }
        .brand-logo { color: #2267B2; font-weight: bold; font-size: 20px; display: flex; align-items: center; justify-content: center; }
        .brand-slogan { color: #999; font-size: 12px; margin-top: 5px; }
        
        /* Keypad */
        .keypad-container { background: white; padding-bottom: 20px; box-shadow: 0 -2px 10px rgba(0,0,0,0.05); }
        .keypad-grid { display: grid; grid-template-columns: 3fr 1fr; height: 260px; }
        .num-pad { display: grid; grid-template-columns: 1fr 1fr 1fr; grid-template-rows: 1fr 1fr 1fr 1fr; }
        .key-btn { border: 0.5px solid #f0f0f0; background: white; font-size: 24px; font-weight: 500; display: flex; align-items: center; justify-content: center; cursor: pointer; user-select: none; }
        .key-btn:active { background: #f9f9f9; }
        .action-pad { display: flex; flex-direction: column; }
        .backspace-btn { flex: 1; border: 0.5px solid #f0f0f0; background: white; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .confirm-btn { flex: 3; background: #4CAF50; color: white; border: none; font-size: 18px; font-weight: bold; display: flex; align-items: center; justify-content: center; margin: 5px; border-radius: 8px; cursor: pointer; }
        .confirm-btn:active { background: #43A047; }
        .confirm-btn.disabled { background: #A5D6A7; cursor: not-allowed; }
        .header-bar .lang-switch { background: rgba(255, 255, 255, 0.2); border-color: rgba(255, 255, 255, 0.45); }
        .header-bar .lang-switch a { color: rgba(255, 255, 255, 0.92); }
        .header-bar .lang-switch a.active { color: #2e7d32 !important; }
        
        /* Mobile fixes */
        input[type=number]::-webkit-inner-spin-button, 
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body>

<!-- Header -->
<div class="header-bar">
    <div class="header-icon"><i class="fas fa-home"></i></div>
    <div class="header-title"><?php echo __('front.qr_pay.checkout_title'); ?></div>
    <div class="header-icon">
        <div class="lang-switch" role="group" aria-label="<?php echo __('merchant.topbar.language'); ?>">
            <a class="<?php echo $current_lang === 'zh-cn' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_zh_url); ?>">中</a>
            <a class="<?php echo $current_lang === 'en' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_en_url); ?>">EN</a>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="main-content">
    <?php if (!empty($error)): ?>
    <div class="alert alert-danger py-2 small mb-3"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <div class="merchant-info">
        <div class="merchant-avatar"><i class="fas fa-store"></i></div>
        <div class="merchant-name"><?php echo htmlspecialchars($code['name']); ?></div>
    </div>
    
    <div class="amount-card">
        <div class="mb-2">
            <label class="small text-muted"><?php echo I18n::getLang()==='en' ? 'Currency' : '支付币种'; ?></label>
            <?php if (count($enabledCurrencies) > 1): ?>
            <select id="currencySelect" name="currency" class="form-select form-select-sm">
                <?php foreach ($enabledCurrencies as $ec): ?>
                <option value="<?php echo htmlspecialchars($ec); ?>" <?php echo $selectedCurrency === $ec ? 'selected' : ''; ?>><?php echo htmlspecialchars($ec); ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="hidden" id="currencySelect" name="currency" value="<?php echo htmlspecialchars($selectedCurrency); ?>">
            <div class="form-control form-control-sm"><?php echo htmlspecialchars($selectedCurrency); ?></div>
            <?php endif; ?>
        </div>
        <div class="amount-label"><?php echo __('front.qr_pay.pay_amount'); ?></div>
        <div class="amount-input-row">
            <span class="currency-symbol" id="currencySymbol"><?php echo htmlspecialchars($selectedCurrency); ?></span>
            <input type="text" id="amountDisplay" class="amount-display" placeholder="" readonly>
        </div>
        <div class="add-note">
            <i class="far fa-edit me-1"></i> <?php echo __('front.qr_pay.add_note'); ?>
        </div>
    </div>
    
    <div class="brand-footer">
        <div class="brand-logo">
            <i class="fas fa-wallet me-2"></i> <?php echo htmlspecialchars($site_name); ?>
        </div>
        <div class="brand-slogan"><?php echo __('front.qr_pay.slogan'); ?></div>
    </div>
</div>

<!-- Keypad -->
<form method="POST" id="payForm">
    <input type="hidden" name="amount" id="amountInput">
    <input type="hidden" name="currency" id="currencyInput" value="<?php echo htmlspecialchars($selectedCurrency); ?>">
    <div class="keypad-container">
        <div class="keypad-grid">
            <div class="num-pad">
                <div class="key-btn" onclick="pressKey('1')">1</div>
                <div class="key-btn" onclick="pressKey('2')">2</div>
                <div class="key-btn" onclick="pressKey('3')">3</div>
                <div class="key-btn" onclick="pressKey('4')">4</div>
                <div class="key-btn" onclick="pressKey('5')">5</div>
                <div class="key-btn" onclick="pressKey('6')">6</div>
                <div class="key-btn" onclick="pressKey('7')">7</div>
                <div class="key-btn" onclick="pressKey('8')">8</div>
                <div class="key-btn" onclick="pressKey('9')">9</div>
                <div class="key-btn" style="background: #f9f9f9;"></div>
                <div class="key-btn" onclick="pressKey('0')">0</div>
                <div class="key-btn" onclick="pressKey('.')">.</div>
            </div>
            <div class="action-pad">
                <div class="backspace-btn" onclick="pressBackspace()"><i class="fas fa-backspace"></i></div>
                <button type="submit" id="confirmBtn" class="confirm-btn disabled" disabled><?php echo __('front.qr_pay.confirm_pay'); ?></button>
            </div>
        </div>
    </div>
</form>

<script>
    let currentAmount = '';
    const display = document.getElementById('amountDisplay');
    const input = document.getElementById('amountInput');
    const confirmBtn = document.getElementById('confirmBtn');
    const currencySelect = document.getElementById('currencySelect');
    const currencyInput = document.getElementById('currencyInput');
    const currencySymbol = document.getElementById('currencySymbol');

    function syncCurrency() {
        const curr = currencySelect && currencySelect.value ? currencySelect.value : <?php echo json_encode($selectedCurrency); ?>;
        if (currencyInput) currencyInput.value = curr;
        if (currencySymbol) currencySymbol.textContent = curr;
    }

    function updateDisplay() {
        display.value = currentAmount;
        input.value = currentAmount;
        
        if (parseFloat(currentAmount) > 0) {
            confirmBtn.classList.remove('disabled');
            confirmBtn.removeAttribute('disabled');
        } else {
            confirmBtn.classList.add('disabled');
            confirmBtn.setAttribute('disabled', 'disabled');
        }
    }

    function pressKey(key) {
        if (key === '.' && currentAmount.includes('.')) return;
        if (currentAmount.includes('.') && currentAmount.split('.')[1].length >= 2) return; // Max 2 decimals
        if (currentAmount === '' && key === '.') currentAmount = '0.';
        else if (currentAmount === '0' && key !== '.') currentAmount = key;
        else if (currentAmount.length < 10) currentAmount += key;
        updateDisplay();
    }

    function pressBackspace() {
        currentAmount = currentAmount.slice(0, -1);
        updateDisplay();
    }
    if (currencySelect && currencySelect.tagName === 'SELECT') {
        currencySelect.addEventListener('change', syncCurrency);
    }
    syncCurrency();
</script>
</body>
</html>
