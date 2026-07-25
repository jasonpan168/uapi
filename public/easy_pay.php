<?php
// public/easy_pay.php
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/FeeAddressAllocator.php';
I18n::init();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = Database::getInstance();
$link = $db->fetch("SELECT * FROM payment_links WHERE id = ?", [$id]);

if (!$link) {
    die(__('front.easy_pay.error.link_not_found'));
}

$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$platformCurrencies = [];
if (($cfg['enable_payment_usdt'] ?? '1') === '1') $platformCurrencies[] = 'USDT';
if (($cfg['enable_usdc'] ?? '0') === '1') $platformCurrencies[] = 'USDC';
if (empty($platformCurrencies)) $platformCurrencies[] = 'USDT';
$enabledCurrencies = $platformCurrencies;
$linkCurrency = strtoupper(trim((string)($link['currency'] ?? 'USDT')));
if (!in_array($linkCurrency, $enabledCurrencies, true)) {
    $linkCurrency = $enabledCurrencies[0] ?? 'USDT';
}
if ($linkCurrency === 'USDC' && strtolower((string)$link['chain']) === 'trc20') {
    $linkCurrency = 'USDT';
}

// Process Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currency = strtoupper(trim((string)($_POST['currency'] ?? $linkCurrency)));
    if (!in_array($currency, $enabledCurrencies, true)) {
        $error = I18n::getLang() === 'en' ? 'Selected currency is not available' : '所选币种不可用';
    }
    $amount = (float)$_POST['amount'];
    if (empty($error) && $link['amount'] > 0 && abs($amount - $link['amount']) > 0.01) {
        $error = __('front.easy_pay.error.fixed_amount', ['amount' => (string)$link['amount']]);
    } elseif (empty($error) && $amount <= 0) {
        $error = __('front.easy_pay.error.invalid_amount');
    } elseif (empty($error) && $currency === 'USDC' && strtolower((string)$link['chain']) === 'trc20') {
        $error = I18n::getLang() === 'en' ? 'USDC is not supported on TRC20' : 'TRC20 暂不支持 USDC';
    } else {
        // Create Order
        $order_no = 'LINK' . date('YmdHis') . rand(1000, 9999);
        $merchant_order_id = 'LINK-' . $link['id'] . '-' . time();
        $pay_access_token = bin2hex(random_bytes(16));
        
        $wallet = null;
        $linkReceiveModeRaw = strtolower(trim((string)($link['receive_mode'] ?? '')));
        $legacyMode = !in_array($linkReceiveModeRaw, ['wallet', 'derived'], true);
        $linkReceiveMode = $legacyMode ? 'wallet' : $linkReceiveModeRaw;
        // Fixed-address mode must use micro-random tail for amount matching.
        $final_amount = $amount;
        if ($linkReceiveMode !== 'derived') {
            $rand_int = rand(1000, 9999);
            if ($rand_int % 10 == 0) $rand_int += rand(1, 9);
            $final_amount = $amount + ($rand_int / 1000000);
        }
        $amount_fmt = number_format($final_amount, 6, '.', '');

        if ($linkReceiveMode === 'derived') {
            $allocCfg = FeeAddressAllocator::loadSettings($db);
            $allocCfg['admin_fee_address_mode'] = 'derived';
            try {
                $alloc = FeeAddressAllocator::resolveChargeWallet($db, $order_no, 'payment_link', (int)$link['user_id'], (string)$link['chain'], $allocCfg);
                if ($alloc && !empty($alloc['wallet_id']) && strtolower((string)($alloc['chain'] ?? '')) === strtolower((string)$link['chain'])) {
                    $wallet = ['id' => (int)$alloc['wallet_id']];
                }
            } catch (Exception $e) {
                $error = I18n::getLang() === 'en'
                    ? ('Derived address allocation failed: ' . $e->getMessage())
                    : ('派生地址分配失败：' . $e->getMessage());
            }
            if (!$wallet && empty($error)) {
                $error = I18n::getLang() === 'en'
                    ? 'Derived address allocation failed on this chain'
                    : '该网络派生地址分配失败';
            }
        }
        if (!$wallet && $linkReceiveMode !== 'derived' && empty($error)) {
            $wallet = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND LOWER(chain) = ? AND status = 1 LIMIT 1", [$link['user_id'], strtolower((string)$link['chain'])]);
        }
        // Backward compatibility for old links without explicit receive_mode:
        // prefer fixed wallet, then fallback to derived if fixed wallet is unavailable.
        if (!$wallet && $legacyMode && empty($error)) {
            $allocCfg = FeeAddressAllocator::loadSettings($db);
            $allocCfg['admin_fee_address_mode'] = 'derived';
            try {
                $alloc = FeeAddressAllocator::resolveChargeWallet($db, $order_no, 'payment_link', (int)$link['user_id'], (string)$link['chain'], $allocCfg);
                if ($alloc && !empty($alloc['wallet_id']) && strtolower((string)($alloc['chain'] ?? '')) === strtolower((string)$link['chain'])) {
                    $wallet = ['id' => (int)$alloc['wallet_id']];
                }
            } catch (Exception $e) {
                // Keep legacy fallback silent; if both modes fail, use unified wallet_unavailable message.
            }
        }
        
        if ($wallet) {
            $db->query("INSERT INTO orders (order_no, merchant_order_id, pay_access_token, user_id, wallet_id, amount, currency, chain, status, source, is_fast_sync, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'payment_link', 0, NOW())", [
                $order_no, $merchant_order_id, $pay_access_token, $link['user_id'], $wallet['id'], $amount_fmt, $currency, $link['chain']
            ]);
            $dec = $db->query("UPDATE users SET fast_sync_remaining = fast_sync_remaining - 1 WHERE id = ? AND fast_sync_remaining > 0", [$link['user_id']]);
            if ($dec->rowCount() > 0) {
                $db->query("UPDATE orders SET is_fast_sync = 1 WHERE order_no = ?", [$order_no]);
            }
            header("Location: pay.php?order=$order_no&token=$pay_access_token");
            exit;
        } else {
            if (empty($error)) {
                $error = __('front.easy_pay.error.wallet_unavailable');
            }
        }
    }
}

$current_lang = I18n::getLang();
$lang_zh_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-cn']));
$lang_en_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'en']));
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($link['title']); ?> - <?php echo __('front.easy_pay.title_suffix'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/lang-switch.css">
    <style>
        body { background: #f8f9fa; display: flex; align-items: center; min-height: 100vh; }
        .card { border: 0; box-shadow: 0 10px 30px rgba(0,0,0,0.08); border-radius: 16px; }
        .form-control-lg { border-radius: 12px; }
        .btn-lg { border-radius: 12px; padding: 12px; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-5 col-lg-4">
            <div class="card p-4">
                <div class="card-body text-center">
                    <div class="mb-4">
                        <div class="d-flex justify-content-end mb-2">
                            <div class="lang-switch" role="group" aria-label="<?php echo __('merchant.topbar.language'); ?>">
                                <a class="<?php echo $current_lang === 'zh-cn' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_zh_url); ?>">中</a>
                                <a class="<?php echo $current_lang === 'en' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_en_url); ?>">EN</a>
                            </div>
                        </div>
                        <i class="fas fa-shopping-bag text-primary mb-3" style="font-size: 3rem;"></i>
                        <h4 class="fw-bold"><?php echo htmlspecialchars($link['title']); ?></h4>
                        <p class="text-muted small"><?php echo __('front.easy_pay.pay_to_merchant'); ?> (<?php echo strtoupper($link['chain']); ?>)</p>
                    </div>

                    <?php if (isset($error)): ?>
                    <div class="alert alert-danger text-start py-2 small"><?php echo $error; ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="mb-3 text-start">
                            <label class="form-label text-muted small fw-bold"><?php echo I18n::getLang()==='en' ? 'Currency' : '支付币种'; ?></label>
                            <?php if (count($enabledCurrencies) > 1): ?>
                            <select name="currency" class="form-select">
                                <?php foreach ($enabledCurrencies as $ec): ?>
                                <option value="<?php echo htmlspecialchars($ec); ?>" <?php echo (strtoupper((string)($_POST['currency'] ?? $linkCurrency)) === $ec) ? 'selected' : ''; ?>><?php echo htmlspecialchars($ec); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php else: ?>
                            <input type="hidden" name="currency" value="<?php echo htmlspecialchars($linkCurrency); ?>">
                            <div class="form-control"><?php echo htmlspecialchars($linkCurrency); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-4 text-start">
                            <label class="form-label text-muted small fw-bold"><?php echo I18n::getLang()==='en' ? 'Payment Amount' : '支付金额'; ?></label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-white border-end-0 text-muted">$</span>
                                <input type="number" name="amount" class="form-control border-start-0 ps-0" 
                                    value="<?php echo $link['amount'] > 0 ? $link['amount'] : ''; ?>" 
                                    <?php echo $link['amount'] > 0 ? 'readonly' : ''; ?> 
                                    step="0.01" min="0.01" required placeholder="0.00">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 btn-lg shadow-sm"><?php echo __('front.easy_pay.pay_now'); ?></button>
                    </form>
                    
                    <div class="mt-4 pt-3 border-top text-muted small">
                        Powered by <?php echo htmlspecialchars($site_name); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
