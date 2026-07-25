<?php
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();
$db = Database::getInstance();
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';
$is_https = ((!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
$scheme = $is_https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$request_uri = $_SERVER['REQUEST_URI'] ?? '/';
$current_url = $scheme . '://' . $host . $request_uri;
$default_title = __('guide.title') . ' - ' . $site_name;
// DB-stored SEO overrides apply only to the default locale (zh-cn).
$current_lang = I18n::getLang();
$is_default_locale = $current_lang === 'zh-cn';
$seo_title       = ($is_default_locale && trim((string)($cfg['seo_title']       ?? '')) !== '') ? trim((string)$cfg['seo_title'])       : $default_title;
$seo_description = ($is_default_locale && trim((string)($cfg['seo_description'] ?? '')) !== '') ? trim((string)$cfg['seo_description']) : __('guide.meta.description');
$seo_keywords    = ($is_default_locale && trim((string)($cfg['seo_keywords']    ?? '')) !== '') ? trim((string)$cfg['seo_keywords'])    : __('guide.meta.keywords');
$seo_canonical = trim((string)($cfg['seo_canonical'] ?? '')) ?: $current_url;
$seo_og_image = trim((string)($cfg['seo_og_image'] ?? ''));
if ($seo_og_image === '') {
    $seo_og_image = trim((string)($site_logo ?: '/assets/logo.png'));
}
if (strpos($seo_og_image, 'http://') !== 0 && strpos($seo_og_image, 'https://') !== 0) {
    $seo_og_image = $scheme . '://' . $host . '/' . ltrim($seo_og_image, '/');
}
$current_lang = I18n::getLang();
$lang_zh_url    = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-cn']));
$lang_zh_tw_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-tw']));
$lang_en_url    = '?' . http_build_query(array_merge($_GET, ['lang' => 'en']));
$lang_ja_url    = '?' . http_build_query(array_merge($_GET, ['lang' => 'ja']));
$is_en = I18n::getLang() === 'en';
$tt = static function (string $zh, string $en) use ($is_en): string {
    return $is_en ? $en : $zh;
};
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($seo_title); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($seo_description); ?>">
    <meta name="keywords" content="<?php echo htmlspecialchars($seo_keywords); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($seo_canonical); ?>">
    <link rel="alternate" hreflang="zh-CN"   href="<?php echo htmlspecialchars($lang_zh_url); ?>">
    <link rel="alternate" hreflang="zh-TW"   href="<?php echo htmlspecialchars($lang_zh_tw_url); ?>">
    <link rel="alternate" hreflang="en"      href="<?php echo htmlspecialchars($lang_en_url); ?>">
    <link rel="alternate" hreflang="ja"      href="<?php echo htmlspecialchars($lang_ja_url); ?>">
    <link rel="alternate" hreflang="x-default" href="<?php echo htmlspecialchars($lang_en_url); ?>">
    <meta property="og:type" content="article">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($site_name); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($seo_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($seo_description); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($current_url); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($seo_og_image); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($seo_title); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($seo_description); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($seo_og_image); ?>">
    <!-- Tailwind CSS -->
    <link rel="stylesheet" href="/output.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="/assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/lang-switch.css">

    <style>
        /* guide.php uses an Apple-blue accent that differs from the default
           primary baked into output.css; pin those utilities here. */
        .text-primary { color: #0071e3 !important; }
        .bg-primary { background-color: #0071e3 !important; }
        .border-primary { border-color: #0071e3 !important; }
        .rounded-apple { border-radius: 20px; }

        body { background-color: #fff; color: #1d1d1f; -webkit-font-smoothing: antialiased; }
        .glass-nav { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .apple-card { transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1); }
        .content-section { scroll-margin-top: 100px; }
        .sidebar-link.active { background-color: #f5f5f7; color: #0071e3; font-weight: 600; }
        .step-pill { background: #f5f5f7; border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; color: #86868b; }
    </style>
</head>
<body class="bg-white">

    <!-- Navigation -->
    <nav class="fixed top-0 w-full z-50 glass-nav border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-6 h-16 flex items-center justify-between">
            <div class="flex items-center gap-8">
                <a href="index.php" class="flex items-center gap-2">
                    <?php if ($site_logo): ?>
                        <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="logo" class="h-8 w-auto">
                    <?php else: ?>
                        <span class="text-xl font-bold tracking-tight"><?php echo htmlspecialchars($site_name); ?></span>
                    <?php endif; ?>
                </a>
                <div class="hidden md:flex items-center gap-6 text-sm font-medium text-gray-500">
                    <span class="text-gray-300">/</span>
                    <span class="text-black font-bold"><?php echo __('guide.nav.user_guide'); ?></span>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <?php include __DIR__ . '/includes/lang_switcher.php'; ?>
                <a href="login.php" class="text-sm font-bold text-primary hover:underline"><?php echo __('guide.nav.get_started'); ?></a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-6 pt-24 pb-20">
        <div class="flex flex-col md:flex-row gap-16">
            
            <!-- Sidebar Navigation -->
            <aside class="md:w-64 flex-shrink-0">
                <div class="sticky top-24 space-y-8">
                    <div>
                        <h5 class="px-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4"><?php echo __('guide.sidebar.getting_started'); ?></h5>
                        <nav class="space-y-1">
                            <a href="#intro" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors active"><?php echo __('guide.sidebar.what_is', ['site' => $site_name]); ?></a>
                            <a href="#core-value" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('guide.sidebar.core_value'); ?></a>
                            <a href="#financial-record" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('guide.sidebar.financial_record'); ?></a>
                            <a href="#quick-start" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('guide.sidebar.quick_start'); ?></a>
                        </nav>
                    </div>
                    
                    <div>
                        <h5 class="px-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4"><?php echo __('guide.sidebar.core_tools'); ?></h5>
                        <nav class="space-y-1">
                            <a href="#my-store" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('guide.sidebar.my_store'); ?></a>
                            <a href="#payment-tools" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('guide.sidebar.payment_tools'); ?></a>
                            <a href="#scenarios" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('guide.sidebar.scenarios'); ?></a>
                        </nav>
                    </div>

                    <div>
                        <h5 class="px-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4"><?php echo __('guide.sidebar.security'); ?></h5>
                        <nav class="space-y-1">
                            <a href="#security" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('guide.sidebar.security_title'); ?></a>
                            <a href="#faq" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('guide.sidebar.faq'); ?></a>
                        </nav>
                    </div>
                </div>
            </aside>

            <!-- Main Content Area -->
            <main class="flex-grow max-w-3xl">
                
                <!-- Product Intro -->
                <section id="intro" class="content-section mb-20">
                    <h1 class="text-5xl font-extrabold tracking-tight mb-8"><?php echo $tt('开始您的加密收款之旅', 'Start Your Crypto Payment Journey'); ?></h1>
                    <p class="text-2xl text-secondary leading-relaxed mb-10 font-medium">
                        <?php echo $tt(
                            $site_name . ' 是一个统一的加密货币支付基础设施。无论您是想建立一个全自动的网店，还是仅仅需要一个收款链接，我们都为您准备好了。',
                            $site_name . ' is a unified crypto payment infrastructure. Whether you want a fully automated online store or just a payment link, we have you covered.'
                        ); ?>
                    </p>
                    <p class="text-gray-500 leading-relaxed mb-10">
                        <?php echo $tt(
                            '专为独立开发者、自由职业者和中小企业打造的<strong>非托管式加密货币支付网关</strong>。我们提供了一套完整的解决方案，帮助您在不依赖第三方中心化平台的情况下，直接使用个人的链上钱包接收 USDT (TRC20/ERC20/BSC) 支付，并自动完成订单匹配与财务记账。',
                            'A <strong>non-custodial crypto payment gateway</strong> built for indie developers, freelancers, and SMEs. We provide a complete solution that lets you receive USDT (TRC20/ERC20/BSC) directly in your own on-chain wallet without relying on centralized intermediaries, with automatic order matching and bookkeeping.'
                        ); ?>
                    </p>
                </section>

                <!-- Core Value -->
                <section id="core-value" class="content-section mb-20">
                    <h2 class="text-3xl font-bold mb-10"><?php echo $tt('核心价值', 'Core Value'); ?></h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                        <div class="p-8 bg-light rounded-apple border border-gray-100 apple-card text-center">
                            <div class="w-12 h-12 bg-blue-500 text-white rounded-xl flex items-center justify-center mb-6 shadow-lg shadow-blue-200 mx-auto">
                                <i class="fas fa-wallet"></i>
                            </div>
                            <h3 class="text-xl font-bold mb-3"><?php echo $tt('资金直达', 'Direct Settlement'); ?></h3>
                            <p class="text-gray-500 leading-relaxed font-medium text-sm"><?php echo $tt('资金直接进入您的私钥钱包，不经过平台中转，无需提现，零冻结风险。', 'Funds go straight to your private-key wallet with no middleman, no withdrawal step, and no freezing risk.'); ?></p>
                        </div>
                        <div class="p-8 bg-light rounded-apple border border-gray-100 apple-card text-center">
                            <div class="w-12 h-12 bg-purple-500 text-white rounded-xl flex items-center justify-center mb-6 shadow-lg shadow-purple-200 mx-auto">
                                <i class="fas fa-user-secret"></i>
                            </div>
                            <h3 class="text-xl font-bold mb-3"><?php echo $tt('隐私安全', 'Privacy & Security'); ?></h3>
                            <p class="text-gray-500 leading-relaxed font-medium text-sm"><?php echo $tt('无需繁琐的 KYC 认证，保护您的交易隐私。全自动链上监听，安全可靠。', 'No cumbersome KYC process. Protect your transaction privacy with fully automated, reliable on-chain monitoring.'); ?></p>
                        </div>
                        <div class="p-8 bg-light rounded-apple border border-gray-100 apple-card text-center">
                            <div class="w-12 h-12 bg-green-500 text-white rounded-xl flex items-center justify-center mb-6 shadow-lg shadow-green-200 mx-auto">
                                <i class="fas fa-bolt"></i>
                            </div>
                            <h3 class="text-xl font-bold mb-3"><?php echo $tt('财务自动', 'Automated Finance'); ?></h3>
                            <p class="text-gray-500 leading-relaxed font-medium text-sm"><?php echo $tt('告别手动查账，系统自动识别转账并关联订单，生成清晰的财务报表。', 'No more manual reconciliation. The system auto-detects transfers, links orders, and produces clear financial records.'); ?></p>
                        </div>
                    </div>
                </section>

                <!-- Financial Record (Key Feature) -->
                <section id="financial-record" class="content-section mb-20">
                    <h2 class="text-3xl font-bold mb-8"><?php echo $tt('个人链上钱包财务销售全记录', 'Complete Sales & Finance Records in Your On-Chain Wallet'); ?></h2>
                    <p class="text-gray-600 mb-8 leading-relaxed"><?php echo $tt('这是 ' . htmlspecialchars($site_name) . ' 最核心的功能模块，旨在为您解决“收款难、对账难、记账难”的痛点。', 'This is the core module of ' . htmlspecialchars($site_name) . ', designed to solve the pain points of collecting payments, reconciling records, and bookkeeping.'); ?></p>
                    
                    <div class="bg-blue-50 border border-blue-100 rounded-apple p-8 mb-8">
                        <h4 class="font-bold text-blue-900 mb-4 flex items-center gap-2">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo $tt('痛点是什么？', 'What is the pain point?'); ?>
                        </h4>
                        <p class="text-blue-800 font-medium leading-relaxed mb-0">
                            <?php echo $tt('使用个人钱包收款时，面对大量的转账记录，您很难分辨哪笔转账对应哪个客户、哪个商品。手动核对交易哈希（TX Hash）不仅效率低下，而且极易出错。', 'When receiving funds with a personal wallet, it becomes difficult to identify which transfer belongs to which customer or product. Manual TX hash reconciliation is inefficient and error-prone.'); ?>
                        </p>
                    </div>

                    <div class="space-y-8 mb-12">
                        <div class="flex gap-6 items-start">
                            <div class="flex-shrink-0 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center font-bold">1</div>
                            <div>
                                <h4 class="font-bold text-lg mb-2 text-black"><?php echo $tt('唯一金额匹配技术', 'Unique Amount Matching'); ?></h4>
                                <p class="text-gray-600"><?php echo $tt('当您创建订单时，系统会自动在原金额基础上增加微小的随机小数（例如 100.00 → 100.001234）。这使得每笔待支付订单的金额都是<strong>全网唯一</strong>的。', 'When you create an order, the system adds a tiny random decimal to the base amount (e.g. 100.00 -> 100.001234), making each unpaid order amount <strong>globally unique</strong>.'); ?></p>
                            </div>
                        </div>
                        <div class="flex gap-6 items-start">
                            <div class="flex-shrink-0 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center font-bold">2</div>
                            <div>
                                <h4 class="font-bold text-lg mb-2 text-black"><?php echo $tt('24/7 链上实时监听', '24/7 On-Chain Monitoring'); ?></h4>
                                <p class="text-gray-600"><?php echo $tt('我们的高性能节点全天候监控您的收款钱包地址。一旦检测到与订单金额完全一致的链上转账，系统立即锁定该笔交易。', 'Our high-performance nodes monitor your receiving wallet around the clock. Once a transfer that exactly matches order amount is detected, the system locks that transaction immediately.'); ?></p>
                            </div>
                        </div>
                        <div class="flex gap-6 items-start">
                            <div class="flex-shrink-0 w-8 h-8 bg-primary text-white rounded-full flex items-center justify-center font-bold">3</div>
                            <div>
                                <h4 class="font-bold text-lg mb-2 text-black"><?php echo $tt('自动对账与归档', 'Auto Reconciliation & Archiving'); ?></h4>
                                <p class="text-gray-600"><?php echo $tt('系统自动将链上交易哈希（TX Hash）、付款地址、支付时间与您的商户订单绑定，并将状态更新为“已支付”。', 'The system automatically binds TX hash, payer address, and payment timestamp to your merchant order, then updates status to paid.'); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="p-8 bg-gray-50 rounded-apple border border-gray-100">
                        <h4 class="font-bold text-black mb-6"><?php echo $tt('您能获得什么？', 'What do you gain?'); ?></h4>
                        <ul class="space-y-4">
                            <li class="flex items-start gap-3">
                                <i class="fas fa-check-circle text-green-500 mt-1"></i>
                                <div>
                                    <span class="font-bold text-black block"><?php echo $tt('完整的销售流水', 'Complete Sales Ledger'); ?></span>
                                    <span class="text-sm text-gray-500"><?php echo $tt('在后台“订单管理”中，每一笔收入都清晰可查，包含关联的商品/服务信息。', 'In Order Management, every incoming payment is traceable with linked product/service information.'); ?></span>
                                </div>
                            </li>
                            <li class="flex items-start gap-3">
                                <i class="fas fa-check-circle text-green-500 mt-1"></i>
                                <div>
                                    <span class="font-bold text-black block"><?php echo $tt('不可篡改的凭证', 'Tamper-Proof Proof'); ?></span>
                                    <span class="text-sm text-gray-500"><?php echo $tt('每一笔订单都记录了链上 TX Hash，随时可在区块链浏览器中验证，杜绝假支付。', 'Every order records an on-chain TX hash, verifiable at any time in a blockchain explorer to prevent fake payments.'); ?></span>
                                </div>
                            </li>
                            <li class="flex items-start gap-3">
                                <i class="fas fa-check-circle text-green-500 mt-1"></i>
                                <div>
                                    <span class="font-bold text-black block"><?php echo $tt('数据导出与分析', 'Export & Analytics'); ?></span>
                                    <span class="text-sm text-gray-500"><?php echo $tt('支持导出交易记录，方便您进行月度/季度财务核算与税务申报。', 'Export transaction records for monthly/quarterly financial accounting and tax reporting.'); ?></span>
                                </div>
                            </li>
                            <li class="flex items-start gap-3">
                                <i class="fas fa-check-circle text-green-500 mt-1"></i>
                                <div>
                                    <span class="font-bold text-black block"><?php echo $tt('多链统一管理', 'Unified Multi-Chain Management'); ?></span>
                                    <span class="text-sm text-gray-500"><?php echo $tt('无论是 TRC20、ERC20 还是 BSC，所有链的收入都在一个看板中统一展示。', 'TRC20, ERC20, and BSC incomes are all displayed in one unified dashboard.'); ?></span>
                                </div>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Quick Start -->
                <section id="quick-start" class="content-section mb-20">
                    <h2 class="text-3xl font-bold mb-10"><?php echo $tt('快速开始三部曲', 'Quick Start in 3 Steps'); ?></h2>
                    <div class="space-y-12 relative">
                        <!-- Step 1 -->
                        <div class="flex gap-8 items-start relative z-10">
                            <div class="flex-shrink-0 w-10 h-10 bg-black text-white rounded-full flex items-center justify-center font-bold text-lg">1</div>
                            <div>
                                <h4 class="text-xl font-bold mb-2 text-black"><?php echo $tt('注册并配置钱包', 'Sign Up and Configure Wallet'); ?></h4>
                                <p class="text-gray-600 leading-relaxed mb-4"><?php echo $tt('登录商户后台，在“API 设置”或“钱包管理”中添加您的 USDT 收款地址（支持 TRC20, ERC20, BSC, Solana 等）。', 'Log in to the merchant console and add your USDT receiving address in API Settings or Wallet Management (supports TRC20, ERC20, BSC, Solana, etc.).'); ?></p>
                                <img src="https://images.unsplash.com/photo-1621416894569-0f39ed31d247?auto=format&fit=crop&q=80&w=800" class="rounded-apple border border-gray-100 shadow-sm" alt="setup">
                            </div>
                        </div>
                        <!-- Step 2 -->
                        <div class="flex gap-8 items-start relative z-10">
                            <div class="flex-shrink-0 w-10 h-10 bg-black text-white rounded-full flex items-center justify-center font-bold text-lg">2</div>
                            <div>
                                <h4 class="text-xl font-bold mb-2 text-black"><?php echo $tt('选择您的工具', 'Choose Your Tool'); ?></h4>
                                <p class="text-gray-600 leading-relaxed"><?php echo $tt('您可以选择一键生成“我的网店”，或者创建单个“收款链接”进行测试。', 'You can generate My Store in one click, or create a single payment link for testing.'); ?></p>
                            </div>
                        </div>
                        <!-- Step 3 -->
                        <div class="flex gap-8 items-start relative z-10">
                            <div class="flex-shrink-0 w-10 h-10 bg-black text-white rounded-full flex items-center justify-center font-bold text-lg">3</div>
                            <div>
                                <h4 class="text-xl font-bold mb-2 text-black"><?php echo $tt('开始销售', 'Start Selling'); ?></h4>
                                <p class="text-gray-600 leading-relaxed"><?php echo $tt('将您的店铺链接或收款码分享给客户，剩下的交给 ' . $site_name . '。开始收款！', 'Share your store link or QR code with customers, and let ' . $site_name . ' handle the rest. Start collecting payments.'); ?></p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- My Store (Star Feature) -->
                <section id="my-store" class="content-section mb-20">
                    <div class="p-10 bg-gradient-to-br from-gray-900 to-gray-800 rounded-[32px] text-white overflow-hidden relative">
                        <div class="relative z-10">
                            <span class="inline-block px-3 py-1 bg-primary text-white text-[10px] font-bold rounded-full uppercase tracking-widest mb-6">Core Feature</span>
                            <h2 class="text-4xl font-bold mb-6 tracking-tight"><?php echo $tt('一键开启您的数字帝国', 'Launch Your Digital Empire in One Click'); ?></h2>
                            <p class="text-gray-400 text-lg mb-10 leading-relaxed">
                                <?php echo $tt('“我的网店”功能专为销售数字产品、服务和订阅而设计。我们提供了精美的 SaaS 风格模板，您只需要上传商品，即可拥有一个高转化的线上商店。', 'My Store is designed for selling digital products, services, and subscriptions. With polished SaaS-style templates, you can launch a high-conversion online store by simply uploading your products.'); ?>
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-8">
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-check-circle text-primary"></i>
                                    <span class="font-medium"><?php echo $tt('自动生成演示数据', 'Auto-generate Demo Data'); ?></span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-check-circle text-primary"></i>
                                    <span class="font-medium"><?php echo $tt('内置卡密分发系统', 'Built-in License/Key Delivery'); ?></span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-check-circle text-primary"></i>
                                    <span class="font-medium"><?php echo $tt('响应式 Apple 风格 UI', 'Responsive Apple-style UI'); ?></span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <i class="fas fa-check-circle text-primary"></i>
                                    <span class="font-medium"><?php echo $tt('多语言自动适配', 'Automatic Multi-language Support'); ?></span>
                                </div>
                            </div>
                            <a href="store.php" class="inline-block bg-white text-black px-8 py-4 rounded-full font-bold hover:scale-105 transition-transform"><?php echo $tt('立即生成我的网店', 'Create My Store Now'); ?></a>
                        </div>
                        <i class="fas fa-store absolute -bottom-10 -right-10 text-[200px] text-white/5 rotate-12"></i>
                    </div>
                </section>

                <!-- Payment Tools -->
                <section id="payment-tools" class="content-section mb-20">
                    <h2 class="text-3xl font-bold mb-10"><?php echo $tt('多样化的收款工具', 'Diverse Payment Tools'); ?></h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div class="p-6 border border-gray-100 rounded-apple bg-gray-50">
                            <h4 class="font-bold mb-3 flex items-center gap-2 text-black"><i class="fas fa-link text-primary"></i> <?php echo $tt('收款链接', 'Payment Link'); ?></h4>
                            <p class="text-sm text-gray-500 mb-0 leading-relaxed"><?php echo $tt('无需任何开发，在后台一键生成专属收款页面链接。支持固定金额或由用户自定义。适用：社交媒体、群组、个人转账。', 'No development required. Generate a dedicated payment page link in one click from the dashboard. Supports fixed or custom amounts. Best for social media, groups, and personal transfers.'); ?></p>
                        </div>
                        <div class="p-6 border border-gray-100 rounded-apple bg-gray-50">
                            <h4 class="font-bold mb-3 flex items-center gap-2 text-black"><i class="fas fa-qrcode text-primary"></i> <?php echo $tt('收款码牌', 'QR Standee'); ?></h4>
                            <p class="text-sm text-gray-500 mb-0 leading-relaxed"><?php echo $tt('生成美观的收款二维码，支持多种样式模板。顾客扫码即可进入支付页面。适用：线下门店、摊位、面对面收款。', 'Generate polished payment QR codes with multiple style templates. Customers scan to open the payment page. Best for physical stores, booths, and face-to-face collection.'); ?></p>
                        </div>
                        <div class="p-6 border border-gray-100 rounded-apple bg-gray-50 sm:col-span-2">
                            <h4 class="font-bold mb-3 flex items-center gap-2 text-black"><i class="fas fa-code text-primary"></i> <?php echo $tt('API 集成', 'API Integration'); ?></h4>
                            <p class="text-sm text-gray-500 mb-4 leading-relaxed"><?php echo $tt('为开发者提供强大的 RESTful API，支持订单、状态查询及 Webhook。轻松集成到网站或 App。', 'Powerful RESTful APIs for developers, including order creation, status queries, and webhooks. Easy to integrate into websites or apps.'); ?></p>
                            <a href="doc.php" class="text-xs font-bold text-primary hover:underline uppercase tracking-widest"><?php echo $tt('查看开发文档', 'View API Documentation'); ?> <i class="fas fa-arrow-right ml-1"></i></a>
                        </div>
                    </div>
                </section>

                <!-- Scenarios -->
                <section id="scenarios" class="content-section mb-20">
                    <h2 class="text-3xl font-bold mb-8"><?php echo $tt('适用人群与场景', 'Use Cases & Target Users'); ?></h2>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-8 text-sm">
                        <div>
                            <h5 class="font-bold mb-3 text-black"><?php echo $tt('独立开发者 (Indie Hacker)', 'Indie Hacker'); ?></h5>
                            <p class="text-gray-500 leading-relaxed"><?php echo $tt('销售软件授权码、会员订阅、API 配额。无需注册公司，个人即可全球收款。', 'Sell software licenses, subscriptions, and API quotas. Collect payments globally as an individual without forming a company.'); ?></p>
                        </div>
                        <div>
                            <h5 class="font-bold mb-3 text-black"><?php echo $tt('数字内容创作者', 'Digital Content Creator'); ?></h5>
                            <p class="text-gray-500 leading-relaxed"><?php echo $tt('销售电子书、课程、设计素材、摄影作品。保护隐私，收入直达，无提现门槛。', 'Sell e-books, courses, design assets, and photography. Preserve privacy with direct settlement and no withdrawal threshold.'); ?></p>
                        </div>
                        <div>
                            <h5 class="font-bold mb-3 text-black"><?php echo $tt('跨境电商/外贸', 'Cross-border E-commerce / Trade'); ?></h5>
                            <p class="text-gray-500 leading-relaxed"><?php echo $tt('接收海外客户的小额样品费、服务费。规避汇率风险，快速回款。', 'Receive sample fees and service fees from overseas customers. Reduce FX risk and accelerate settlement.'); ?></p>
                        </div>
                    </div>
                </section>

                <!-- FAQ Section -->
                <section id="faq" class="content-section mb-20">
                    <h2 class="text-3xl font-bold mb-10"><?php echo $tt('常见问题解答', 'Frequently Asked Questions'); ?></h2>
                    <div class="space-y-4">
                        <details class="group border border-gray-100 rounded-apple p-6 bg-gray-50" open>
                            <summary class="list-none flex justify-between items-center cursor-pointer">
                                <h4 class="font-bold text-lg text-black"><?php echo $tt('资金真的安全吗？', 'Are funds truly safe?'); ?></h4>
                                <span class="group-open:rotate-180 transition-transform"><i class="fas fa-chevron-down text-gray-400"></i></span>
                            </summary>
                            <p class="mt-4 text-gray-600 leading-relaxed">
                                <?php echo $tt('<strong>绝对安全。</strong> 我们从不询问、不存储、不接触您的私钥。您只需要在后台填写您的公开收款地址（Public Address）。资金由区块链网络直接确认并存入您的地址，' . $site_name . ' 仅作为一个“观察者”来通知您支付结果。', '<strong>Absolutely safe.</strong> We never ask for, store, or touch your private key. You only provide your public receiving address in the dashboard. Funds are confirmed by the blockchain and sent directly to your address. ' . $site_name . ' only acts as an observer to notify payment results.'); ?>
                            </p>
                        </details>
                        
                        <details class="group border border-gray-100 rounded-apple p-6 bg-gray-50">
                            <summary class="list-none flex justify-between items-center cursor-pointer">
                                <h4 class="font-bold text-lg text-black"><?php echo $tt('如何开始使用？', 'How do I get started?'); ?></h4>
                                <span class="group-open:rotate-180 transition-transform"><i class="fas fa-chevron-down text-gray-400"></i></span>
                            </summary>
                            <div class="mt-4 text-gray-600 leading-relaxed">
                                <ol class="list-decimal pl-5 space-y-2 text-sm text-gray-600">
                                    <li><?php echo $tt('注册并登录商户后台。', 'Sign up and log in to the merchant dashboard.'); ?></li>
                                    <li><?php echo $tt('在“API 设置”或“钱包管理”中添加您的 USDT 收款地址。', 'Add your USDT receiving wallet in API Settings or Wallet Management.'); ?></li>
                                    <li><?php echo $tt('创建一个“收款链接”进行测试，或者查阅开发文档接入 API。', 'Create a payment link for testing, or integrate via API documentation.'); ?></li>
                                    <li><?php echo $tt('开始收款！', 'Start collecting payments.'); ?></li>
                                </ol>
                            </div>
                        </details>

                        <details class="group border border-gray-100 rounded-apple p-6 bg-gray-50">
                            <summary class="list-none flex justify-between items-center cursor-pointer">
                                <h4 class="font-bold text-lg text-black"><?php echo $tt('如果用户付错了金额怎么办？', 'What if users pay the wrong amount?'); ?></h4>
                                <span class="group-open:rotate-180 transition-transform"><i class="fas fa-chevron-down text-gray-400"></i></span>
                            </summary>
                            <p class="mt-4 text-gray-600 leading-relaxed text-sm">
                                <?php echo $tt('系统会自动检测到账金额。如果金额不匹配（少付或多付），订单将不会自动标记为已支付。在这种情况下，您可以进入“订单管理”手动核对哈希并处理。', 'The system checks paid amount automatically. If the amount is mismatched (underpaid or overpaid), the order will not be auto-marked as paid. You can handle it manually in Order Management after checking hash details.'); ?>
                            </p>
                        </details>
                    </div>
                </section>

            </main>
        </div>
    </div>

    <!-- Footer -->
    <footer class="py-12 bg-gray-50 border-t border-gray-100">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <p class="text-sm font-bold text-gray-400 uppercase tracking-widest">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?> Platform Guide</p>
        </div>
    </footer>

    <!-- Scripts -->
    <script>
        // Sidebar scroll spy
        window.addEventListener('scroll', () => {
            const sections = document.querySelectorAll('.content-section');
            const navLinks = document.querySelectorAll('.sidebar-link');
            
            let current = '';
            sections.forEach(section => {
                const sectionTop = section.offsetTop;
                if (pageYOffset >= sectionTop - 150) {
                    current = section.getAttribute('id');
                }
            });

            navLinks.forEach(link => {
                link.classList.remove('active');
                if (link.getAttribute('href').includes(current)) {
                    link.classList.add('active');
                }
            });
        });
    </script>
</body>
</html>
