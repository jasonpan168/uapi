<?php
// public/shop_product.php
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();
$db = Database::getInstance();

$id = $_GET['id'] ?? 0;
$product = $db->fetch("SELECT * FROM store_products WHERE id = ? AND status = 'active'", [$id]);

if (!$product) die("Product not found");

$store = $db->fetch("SELECT * FROM stores WHERE id = ?", [$product['store_id']]);
$merchantUserId = (int)($store['user_id'] ?? 0);
$settingsRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$settingsMap = [];
foreach ($settingsRows as $sr) {
    $settingsMap[(string)$sr['key_name']] = (string)$sr['value'];
}
$receiveModeKey = 'merchant_receive_mode_u' . $merchantUserId;
$receiveModeRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
$receiveMode = strtolower(trim((string)($receiveModeRow['value'] ?? 'wallet')));
if (!in_array($receiveMode, ['wallet', 'derived'], true)) {
    $receiveMode = 'wallet';
}

$chains = [];
if ($receiveMode === 'derived') {
    $merchant = $db->fetch("SELECT plan_id FROM users WHERE id = ? LIMIT 1", [$merchantUserId]);
    $planId = (int)($merchant['plan_id'] ?? 0);
    $derivedRows = $db->fetchAll(
        "SELECT c.slug
         FROM chains c
         INNER JOIN plan_chains pc ON pc.chain_id = c.id AND pc.plan_id = ?
         LEFT JOIN plan_chain_derived pcd ON pcd.plan_id = pc.plan_id AND pcd.chain_id = pc.chain_id
         WHERE c.status = 1
           AND c.is_evm = 1
           AND COALESCE(c.allow_derived, 1) = 1
           AND COALESCE(pcd.enabled, 1) = 1
         ORDER BY c.name ASC",
        [$planId]
    );
    $chains = array_values(array_unique(array_map(function ($row) {
        return strtolower(trim((string)($row['slug'] ?? '')));
    }, $derivedRows)));
    $chains = array_values(array_filter($chains, function ($v) { return $v !== ''; }));
} else {
    $wallets = $db->fetchAll("SELECT DISTINCT chain FROM wallets WHERE user_id = ? AND status = 1", [$merchantUserId]);
    $chains = array_values(array_unique(array_map(function ($row) {
        return strtolower(trim((string)($row['chain'] ?? '')));
    }, $wallets)));
    $chains = array_values(array_filter($chains, function ($v) { return $v !== ''; }));
}

$preferredChainKey = 'sweep_last_chain_u' . $merchantUserId;
$preferredChainRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$preferredChainKey]);
$preferredChain = strtolower(trim((string)($preferredChainRow['value'] ?? '')));
$walletDefaultChainKey = 'merchant_wallet_default_chain_u' . $merchantUserId;
$walletDefaultChainRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$walletDefaultChainKey]);
$walletDefaultChain = strtolower(trim((string)($walletDefaultChainRow['value'] ?? '')));
if ($receiveMode === 'derived') {
    if ($preferredChain !== '' && in_array($preferredChain, $chains, true)) {
        $chains = array_values(array_unique(array_merge([$preferredChain], $chains)));
    }
} else {
    if ($walletDefaultChain !== '' && in_array($walletDefaultChain, $chains, true)) {
        $chains = array_values(array_unique(array_merge([$walletDefaultChain], $chains)));
    }
}
$selectedPayChain = !empty($chains) ? (string)$chains[0] : '';
$chainDisplayNames = [];
if (!empty($chains)) {
    $placeholders = implode(',', array_fill(0, count($chains), '?'));
    $chainRows = $db->fetchAll(
        "SELECT slug, name FROM chains WHERE status = 1 AND LOWER(slug) IN ($placeholders)",
        $chains
    );
    foreach ($chainRows as $cr) {
        $slug = strtolower(trim((string)($cr['slug'] ?? '')));
        if ($slug !== '') {
            $chainDisplayNames[$slug] = trim((string)($cr['name'] ?? ''));
        }
    }
}
$selectedPayChainLabel = '';
if ($selectedPayChain !== '') {
    $selectedPayChainUpper = strtoupper($selectedPayChain);
    $selectedPayChainName = trim((string)($chainDisplayNames[$selectedPayChain] ?? ''));
    $selectedPayChainLabel = $selectedPayChainName !== ''
        ? ($selectedPayChainName . ' (' . $selectedPayChainUpper . ')')
        : $selectedPayChainUpper;
}
$currencySettingKey = 'merchant_enabled_currencies_u' . $merchantUserId;
$platformCurrencies = [];
if (($settingsMap['enable_payment_usdt'] ?? '1') === '1') $platformCurrencies[] = 'USDT';
if (($settingsMap['enable_usdc'] ?? '0') === '1') $platformCurrencies[] = 'USDC';
if (empty($platformCurrencies)) $platformCurrencies[] = 'USDT';
$enabledCurrencies = $platformCurrencies;
$selectedCurrency = (string)$enabledCurrencies[0];
$is_en = I18n::getLang() === 'en';
$tt = static function (string $zh, string $en) use ($is_en): string {
    return $is_en ? $en : $zh;
};
$current_lang = I18n::getLang();
$lang_zh_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-cn']));
$lang_en_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'en']));

// 增强描述逻辑：如果描述太短，自动追加结构化营销文案（仅展示层，不改库）
$desc = nl2br(htmlspecialchars($product['description']));

// 1. Render Features if exists
if (!empty($product['features'])) {
    $desc .= '<div class="mt-5"><h5 class="fw-bold mb-3">' . $tt('产品亮点', 'Product Highlights') . '</h5><ul class="list-unstyled">';
    $feats = explode("\n", $product['features']);
    foreach ($feats as $f) {
        if (trim($f)) $desc .= '<li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> ' . htmlspecialchars(trim($f)) . '</li>';
    }
    $desc .= '</ul></div>';
} elseif (strlen($product['description']) < 100) {
    // Fallback for old demo data without features
    $desc .= '
    <div class="mt-5">
        <h5 class="fw-bold mb-3">' . $tt('产品亮点', 'Product Highlights') . '</h5>
        <ul class="list-unstyled">
            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> ' . $tt('邮件即时交付', 'Instant delivery via email') . '</li>
            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> ' . $tt('支持 7x24 优先客服', '24/7 Priority support included') . '</li>
            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> ' . $tt('安全加密支付流程', 'Secure crypto payment processing') . '</li>
            <li class="mb-2"><i class="fas fa-check-circle text-success me-2"></i> ' . $tt('30 天退款保障', '30-day money-back guarantee') . '</li>
        </ul>
    </div>';
}

// 2. Render FAQ if exists (JSON)
if (!empty($product['faq'])) {
    $faqs = json_decode($product['faq'], true);
    if ($faqs && is_array($faqs)) {
        $desc .= '<div class="mt-5"><h5 class="fw-bold mb-3">' . $tt('常见问题', 'Frequently Asked Questions') . '</h5><div class="accordion" id="faqAccordion">';
        foreach ($faqs as $i => $q) {
            $desc .= '<div class="accordion-item border-0 shadow-sm mb-2 rounded">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#faq' . $i . '">
                        ' . htmlspecialchars($q['q']) . '
                    </button>
                </h2>
                <div id="faq' . $i . '" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body text-secondary small">
                        ' . htmlspecialchars($q['a']) . '
                    </div>
                </div>
            </div>';
        }
        $desc .= '</div></div>';
    }
} elseif (strlen($product['description']) < 100) {
    // Fallback FAQ
    $desc .= '
        <div class="accordion mt-4" id="faqAccordion">
            <div class="accordion-item border-0 shadow-sm mb-2 rounded">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                        ' . $tt('如何收到商品？', 'How do I receive the product?') . '
                    </button>
                </h2>
                <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body text-secondary small">
                        ' . $tt('支付确认后，您会立即收到包含下载链接或访问凭证的邮件。', 'After payment confirmation, you will receive an email with the download link or access credentials immediately.') . '
                    </div>
                </div>
            </div>
            <div class="accordion-item border-0 shadow-sm mb-2 rounded">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                        ' . $tt('支付安全吗？', 'Is this payment secure?') . '
                    </button>
                </h2>
                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body text-secondary small">
                        ' . $tt('是的。我们使用去中心化加密支付，交易可在链上验证，且不会存储您的私钥。', 'Yes, we use decentralized crypto payments. Your transaction is verifiable on the blockchain and we do not store your private keys.') . '
                    </div>
                </div>
            </div>
        </div>';
}
?>
<!DOCTYPE html>
<html lang="<?php echo $current_lang === 'en' ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($product['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/lang-switch.css">
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.6.0/dist/confetti.browser.min.js"></script>
<style>
    :root { --primary-color: #0f172a; --accent-color: #3b82f6; --bg-color: #f8fafc; }
        body { background: var(--bg-color); font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #334155; }
        
        /* Navbar */
        .navbar-brand { font-weight: 700; letter-spacing: -0.5px; }
        
        /* Product Layout */
        .product-container { max-width: 1100px; margin: 0 auto; }
        .product-img-wrapper { 
            position: relative; 
            border-radius: 24px; overflow: hidden; 
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.1); 
            background: #fff;
            aspect-ratio: 16/9;
            width: 100%;
        }
        .product-img { 
            width: 100%; 
            height: 100%; 
            object-fit: cover; 
            object-position: top center;
            display: block; 
        }
        
        /* Typography */
        h1 { letter-spacing: -1px; line-height: 1.1; }
        .badge-category { background: #eff6ff; color: #2563eb; font-weight: 600; padding: 8px 16px; border-radius: 100px; font-size: 0.85rem; }
        
        /* Pricing Card */
        .buy-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05);
            position: sticky; top: 100px; /* Sticky on desktop */
        }
        .price-tag { font-size: 3rem; font-weight: 800; color: #0f172a; letter-spacing: -1px; line-height: 1; }
        .currency-label { font-size: 1rem; color: #64748b; font-weight: 500; margin-left: 4px; vertical-align: middle; }
        
        /* Form Elements */
        .form-select-lg { border-radius: 12px; font-size: 1rem; border-color: #cbd5e1; padding: 12px 16px; font-weight: 500; }
        .form-select-lg:focus { border-color: var(--accent-color); box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1); }
        .buy-card .form-control-lg,
        .buy-card .form-select-lg,
        .buy-card .form-control,
        .buy-card .form-select {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
            font-size: 0.95rem;
            border-radius: 10px;
        }
        .buy-card .input-group .btn {
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        .coupon-toggle-btn {
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }
        
        .btn-buy {
            background: #0f172a; color: white;
            border: none;
            padding: 16px;
            border-radius: 100px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.2s;
            box-shadow: 0 10px 15px -3px rgba(15, 23, 42, 0.3);
        }
        .btn-buy:hover { background: #1e293b; transform: translateY(-2px); box-shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.4); }
        .btn-buy:active { transform: translateY(0); }
        .btn-buy:disabled { background: #94a3b8; transform: none; }

        /* Description Content */
        .description-content { font-size: 1.1rem; line-height: 1.7; color: #475569; }
        .description-content h5 { color: #0f172a; margin-top: 2rem; font-size: 1.25rem; }
        .description-content ul li { margin-bottom: 0.5rem; }
        
        /* Mobile */
        @media (max-width: 768px) {
            .product-img-wrapper, .buy-card { position: static; }
            .price-tag { font-size: 2.5rem; }
        }

    </style>
</head>
<body>

<!-- Minimal Header -->
<nav class="navbar navbar-expand-lg bg-white border-bottom py-3 sticky-top">
    <div class="container product-container">
        <a class="navbar-brand d-flex align-items-center text-dark text-decoration-none" href="shop.php?store=<?php echo $store['slug']; ?>">
            <i class="fas fa-arrow-left me-2 text-secondary" style="font-size: 0.9rem;"></i>
            <span><?php echo htmlspecialchars($store['name']); ?></span>
        </a>
        <div class="lang-switch" aria-label="<?php echo $tt('语言', 'Language'); ?>">
            <a class="<?php echo $current_lang === 'zh-cn' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_zh_url); ?>">中</a>
            <a class="<?php echo $current_lang === 'en' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($lang_en_url); ?>">EN</a>
        </div>
    </div>
</nav>

<div class="container product-container py-5">
    <div class="row g-5">
        <!-- Left: Image & Description -->
        <div class="col-lg-7">
            <div class="product-img-wrapper mb-5">
                <?php if($product['image_url']): ?>
                    <img src="<?php echo htmlspecialchars($product['image_url']); ?>" class="product-img" alt="<?php echo htmlspecialchars($product['name']); ?>">
                <?php else: ?>
                    <div class="bg-light d-flex align-items-center justify-content-center text-muted" style="height:400px; width:100%;">
                        <i class="fas fa-image fa-3x opacity-25"></i>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Mobile Title (Visible only on mobile) -->
            <div class="d-lg-none mb-4">
                <h1 class="fw-bold mb-2"><?php echo htmlspecialchars($product['name']); ?></h1>
                <div class="mb-3"><span class="badge-category"><?php echo htmlspecialchars($product['category'] ?: $tt('数字商品', 'Digital Product')); ?></span></div>
            </div>

            <div class="description-content">
                <h4 class="fw-bold mb-4"><?php echo $tt('商品介绍', 'About this product'); ?></h4>
                <?php echo $desc; ?>
            </div>
        </div>

        <!-- Right: Purchase Card (Sticky) -->
        <div class="col-lg-5">
            <div class="buy-card">
                <!-- Desktop Title -->
                <div class="d-none d-lg-block mb-4">
                    <div class="mb-3"><span class="badge-category"><?php echo htmlspecialchars($product['category'] ?: $tt('数字商品', 'Digital Product')); ?></span></div>
                    <h1 class="fw-bold mb-3"><?php echo htmlspecialchars($product['name']); ?></h1>
                    <p class="text-secondary"><?php echo mb_strimwidth(strip_tags($product['description']), 0, 80, '...'); ?></p>
                </div>

                <div class="d-flex align-items-baseline mb-4 pb-4 border-bottom">
                    <span class="price-tag">$<?php echo number_format($product['price'], 2); ?></span>
                    <span class="currency-label" id="priceCurrencyLabel"><?php echo htmlspecialchars($selectedCurrency); ?></span>
                </div>
                
                <div class="mb-4">
                    <label class="form-label text-uppercase small fw-bold text-secondary mb-2" style="font-size: 0.75rem; letter-spacing: 0.05em;"><?php echo $tt('支付网络', 'Payment Network'); ?></label>
                    <input type="hidden" id="chainSelect" value="<?php echo htmlspecialchars($selectedPayChain); ?>">
                    <div class="form-control form-control-lg d-flex align-items-center justify-content-between">
                        <span>
                            <?php if ($selectedPayChain !== ''): ?>
                                <?php echo htmlspecialchars($selectedPayChainLabel); ?>
                            <?php else: ?>
                                <?php echo $tt('暂无可用收款网络', 'No receiving network available'); ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label text-uppercase small fw-bold text-secondary mb-2" style="font-size: 0.75rem; letter-spacing: 0.05em;"><?php echo $tt('支付币种', 'Payment Currency'); ?></label>
                    <?php if (count($enabledCurrencies) > 1): ?>
                    <select id="currencySelect" class="form-select form-select-lg" onchange="onCurrencyChange()">
                        <?php foreach ($enabledCurrencies as $ec): ?>
                        <option value="<?php echo htmlspecialchars($ec); ?>"><?php echo htmlspecialchars($ec); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="hidden" id="currencySelect" value="<?php echo htmlspecialchars($selectedCurrency); ?>">
                    <div class="form-control form-control-lg"><?php echo htmlspecialchars($selectedCurrency); ?></div>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <div class="mb-2">
                        <button type="button" class="btn btn-link p-0 fw-bold text-primary coupon-toggle-btn" onclick="toggleCouponInput()">
                            <?php echo $tt('有优惠码/邀请码？点击输入', 'Have a coupon/invite code? Click to enter'); ?>
                        </button>
                    </div>
                    <div id="couponWrap" style="display:none;">
                        <div class="input-group">
                            <input type="text" id="couponCode" class="form-control form-control-lg" placeholder="<?php echo $tt('输入优惠码', 'Enter code'); ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="applyStoreCoupon()"><?php echo $tt('应用', 'Apply'); ?></button>
                        </div>
                        <div id="couponMsg" class="form-text mt-1"></div>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-uppercase small fw-bold text-secondary mb-2" style="font-size: 0.75rem; letter-spacing: 0.05em;"><?php echo $tt('收据邮箱', 'Receipt Email'); ?></label>
                    <input type="email" id="customerEmail" class="form-control form-control-lg" placeholder="<?php echo $tt('请输入邮箱（必填）', 'Enter email (required)'); ?>" required>
                    <div class="form-text"><?php echo $tt('支付完成后将自动发送电子收据。', 'An electronic receipt will be sent automatically after payment.'); ?></div>
                </div>
                
                <button id="buyBtn" class="btn-buy w-100 d-flex align-items-center justify-content-center" onclick="initiateCheckout()" <?php echo (empty($chains) || empty($enabledCurrencies)) ? 'disabled' : ''; ?>>
                    <span><?php echo $tt('使用加密货币支付', 'Pay with Crypto'); ?></span>
                    <i class="fas fa-arrow-right ms-2"></i>
                </button>
                
                <div class="text-center mt-3">
                    <small class="text-muted" style="font-size: 0.8rem;">
                        <i class="fas fa-lock me-1"></i> <?php echo $tt('由 UAPI 提供安全支付', 'Secure payment powered by UAPI'); ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Shipping Modal -->
<div class="modal fade" id="shippingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $tt('收货信息', 'Shipping Information'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small"><?php echo $tt('该商品为实物商品，请填写收货信息。', 'This is a physical product. Please provide your shipping details.'); ?></p>
                <div class="mb-3">
                    <label class="form-label"><?php echo $tt('收货人姓名', 'Full Name'); ?></label>
                    <input type="text" id="shipName" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo $tt('联系电话', 'Phone Number'); ?></label>
                    <input type="tel" id="shipPhone" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo $tt('省市', 'State / City'); ?></label>
                    <input type="text" id="shipCity" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo $tt('详细地址', 'Detailed Address'); ?></label>
                    <textarea id="shipAddress" class="form-control" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo $tt('取消', 'Cancel'); ?></button>
                <button type="button" class="btn btn-primary" onclick="confirmShipping()"><?php echo $tt('继续支付', 'Continue to Payment'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const storeId = <?php echo $store['id']; ?>;
const productId = <?php echo $product['id']; ?>;
const isPhysical = <?php echo isset($product['is_physical']) && $product['is_physical'] ? 'true' : 'false'; ?>;
let appliedCouponCode = null;
let couponVisible = false;

function toggleCouponInput() {
    couponVisible = !couponVisible;
    const wrap = document.getElementById('couponWrap');
    if (wrap) wrap.style.display = couponVisible ? '' : 'none';
}

function onCurrencyChange() {
    const el = document.getElementById('currencySelect');
    const curr = (el && el.value) ? el.value : <?php echo json_encode($selectedCurrency); ?>;
    const label = document.getElementById('priceCurrencyLabel');
    if (label) label.innerText = curr;
}

function applyStoreCoupon() {
    const code = document.getElementById('couponCode').value.trim();
    if (!code) return;
    
    // Check coupon via API
    fetch('/api/v1/store/verify_coupon.php?store_id=' + storeId + '&code=' + code)
    .then(res => res.json())
    .then(data => {
        const msg = document.getElementById('couponMsg');
        if (data.status === 'success') {
            appliedCouponCode = code;
            msg.className = 'form-text text-success';
            msg.innerText = <?php echo json_encode($tt('优惠已应用：', 'Coupon Applied: ')); ?> + (data.coupon.type==='fixed' ? '-$'+data.coupon.value : '-'+data.coupon.value+'%');
            
            // Fireworks
            confetti({
                particleCount: 100,
                spread: 70,
                origin: { y: 0.6 }
            });
            
            // Update Price Display
            let originalPrice = <?php echo $product['price']; ?>;
            let newPrice = originalPrice;
            if (data.coupon.type === 'fixed') {
                newPrice = Math.max(0, newPrice - parseFloat(data.coupon.value));
            } else {
                newPrice = Math.max(0, newPrice * (1 - parseFloat(data.coupon.value) / 100));
            }
            document.querySelector('.price-tag').innerText = '$' + newPrice.toFixed(2);
            
        } else {
            msg.className = 'form-text text-danger';
            msg.innerText = data.message || <?php echo json_encode($tt('优惠码无效', 'Invalid Coupon')); ?>;
            appliedCouponCode = null;
        }
    });
}

function initiateCheckout() {
    if (isPhysical) {
        new bootstrap.Modal(document.getElementById('shippingModal')).show();
    } else {
        createOrder();
    }
}

function confirmShipping() {
    const name = document.getElementById('shipName').value.trim();
    const phone = document.getElementById('shipPhone').value.trim();
    const city = document.getElementById('shipCity').value.trim();
    const address = document.getElementById('shipAddress').value.trim();
    
    if (!name || !phone || !city || !address) {
        alert(<?php echo json_encode($tt('请填写完整收货信息。', 'Please fill in all shipping fields.')); ?>);
        return;
    }
    
    const shippingInfo = { name, phone, city, address };
    createOrder(shippingInfo);
}

function createOrder(shippingInfo = null) {
    const btn = document.getElementById('buyBtn');
    const originalContent = btn.innerHTML;
    
    // Loading State
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> <?php echo jsesc($tt('处理中...', 'Processing...')); ?>';
    
    const chain = document.getElementById('chainSelect').value;
    const currencyEl = document.getElementById('currencySelect');
    const currency = currencyEl && currencyEl.value ? currencyEl.value : <?php echo json_encode($selectedCurrency); ?>;
    if (!chain) {
        alert(<?php echo json_encode($tt('当前商户暂无可用收款网络。', 'No receiving network is currently available for this merchant.')); ?>);
        btn.disabled = false;
        btn.innerHTML = originalContent;
        return;
    }
    const customerEmail = document.getElementById('customerEmail').value.trim();
    if (!customerEmail) {
        alert(<?php echo json_encode($tt('请输入收据邮箱。', 'Please enter your receipt email.')); ?>);
        btn.disabled = false;
        btn.innerHTML = originalContent;
        return;
    }
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(customerEmail)) {
        alert(<?php echo json_encode($tt('邮箱格式不正确。', 'Invalid email format.')); ?>);
        btn.disabled = false;
        btn.innerHTML = originalContent;
        return;
    }
    
    const payload = { 
        store_id: storeId, 
        product_id: productId, 
        chain: chain,
        currency: currency,
        coupon_code: appliedCouponCode,
        customer_email: customerEmail
    };
    
    if (shippingInfo) {
        payload.shipping_info = shippingInfo;
    }
    
    fetch('/api/v1/store/create_order.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            window.location.href = data.pay_url;
        } else {
            alert(<?php echo json_encode($tt('错误：', 'Error: ')); ?> + (data.error || <?php echo json_encode($tt('未知错误', 'Unknown error')); ?>));
            btn.disabled = false;
            btn.innerHTML = originalContent;
        }
    })
    .catch(e => {
        console.error(e);
        alert(<?php echo json_encode($tt('网络错误', 'Network Error')); ?>);
        btn.disabled = false;
        btn.innerHTML = originalContent;
    });
}
</script>
</body>
</html>
