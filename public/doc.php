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
$default_title = __('docs.title') . ' - ' . $site_name;
// DB-stored SEO overrides apply only to the default locale (zh-cn); other
// locales use the i18n strings so docs is searchable in each language.
$current_lang = I18n::getLang();
$is_default_locale = $current_lang === 'zh-cn';
$seo_title       = ($is_default_locale && trim((string)($cfg['seo_title']       ?? '')) !== '') ? trim((string)$cfg['seo_title'])       : $default_title;
$seo_description = ($is_default_locale && trim((string)($cfg['seo_description'] ?? '')) !== '') ? trim((string)$cfg['seo_description']) : __('docs.meta.description');
$seo_keywords    = ($is_default_locale && trim((string)($cfg['seo_keywords']    ?? '')) !== '') ? trim((string)$cfg['seo_keywords'])    : __('docs.meta.keywords');
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
    <!-- Highlight.js -->
    <link rel="stylesheet" href="/assets/css/github-dark.min.css">

    <style>
        /* doc.php uses an Apple-blue accent that differs from the default
           primary baked into output.css; pin those utilities here. */
        .text-primary { color: #0071e3 !important; }
        .bg-primary { background-color: #0071e3 !important; }
        .border-primary { border-color: #0071e3 !important; }
        .rounded-apple { border-radius: 18px; }

        body { background-color: #fff; color: #1d1d1f; -webkit-font-smoothing: antialiased; }
        .glass-nav { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); }
        .sidebar-link.active { background-color: #f5f5f7; color: #0071e3; font-weight: 600; }
        pre code { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif !important; font-size: 13px !important; border-radius: 12px; }
        .content-section { scroll-margin-top: 100px; }
        .copy-btn { opacity: 0; transition: opacity 0.2s; }
        pre:hover .copy-btn { opacity: 1; }
    </style>
</head>
<body class="bg-white">

    <!-- Navigation -->
    <nav class="fixed top-0 w-full z-50 glass-nav border-b border-gray-100">
        <div class="max-w-[1440px] mx-auto px-6 h-16 flex items-center justify-between">
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
                    <span class="text-black font-bold"><?php echo __('docs.nav.documentation'); ?></span>
                </div>
            </div>
            <div class="flex items-center gap-4">
                <?php include __DIR__ . '/includes/lang_switcher.php'; ?>
                <a href="dashboard.php" class="text-sm font-bold text-primary hover:underline"><?php echo __('docs.nav.console'); ?></a>
            </div>
        </div>
    </nav>

    <div class="max-w-[1440px] mx-auto px-6 pt-24 pb-20">
        <div class="flex flex-col md:flex-row gap-12">
            
            <!-- Sidebar Navigation -->
            <aside class="md:w-64 flex-shrink-0">
                <div class="sticky top-24 space-y-8">
                    <div>
                        <h5 class="px-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4"><?php echo __('docs.sidebar.getting_started'); ?></h5>
                        <nav class="space-y-1">
                            <a href="#intro" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors active"><?php echo __('docs.sidebar.intro'); ?></a>
                            <a href="#auth" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.auth'); ?></a>
                            <a href="#flow" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.flow'); ?></a>
                            <a href="#no-code" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.no_code'); ?></a>
                        </nav>
                    </div>
                    
                    <div>
                        <h5 class="px-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4"><?php echo __('docs.sidebar.core_api'); ?></h5>
                        <nav class="space-y-1">
                            <a href="#api-create" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.create_order'); ?></a>
                            <a href="#api-status" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.check_status'); ?></a>
                            <a href="#api-dispute" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo $tt('提交争议', 'Submit Dispute'); ?></a>
                            <a href="#webhook" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.webhook'); ?></a>
                        </nav>
                    </div>

                    <div>
                        <h5 class="px-3 text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-4"><?php echo __('docs.sidebar.references'); ?></h5>
                        <nav class="space-y-1">
                            <a href="#status-model" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.status_model'); ?></a>
                            <a href="#limits" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.limits'); ?></a>
                            <a href="#chains" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.chains'); ?></a>
                            <a href="#errors" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.errors'); ?></a>
                            <a href="#tools-versions" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.tools'); ?></a>
                            <a href="#examples" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.examples'); ?></a>
                            <a href="#doc-faq" class="sidebar-link block px-3 py-2 text-sm rounded-lg text-gray-600 hover:bg-gray-50 transition-colors"><?php echo __('docs.sidebar.faq'); ?></a>
                        </nav>
                    </div>
                </div>
            </aside>

            <!-- Main Content Area -->
            <main class="flex-grow max-w-4xl">
                
                <!-- Introduction -->
                <section id="intro" class="content-section mb-20">
                    <h1 class="text-4xl font-extrabold tracking-tight mb-6"><?php echo $tt('API 概览', 'API Overview'); ?></h1>
                    <p class="text-xl text-secondary leading-relaxed mb-8">
                        <?php echo $tt(
                            htmlspecialchars($site_name) . ' 为全球商户提供高性能、非托管的加密货币支付接入方案。我们的 API 旨在让复杂的区块链交互变得像传统支付一样简单。',
                            htmlspecialchars($site_name) . ' provides a high-performance, non-custodial crypto payment infrastructure for global merchants. Our API makes complex blockchain interactions as simple as traditional payments.'
                        ); ?>
                    </p>
                    <div class="bg-blue-50 border border-blue-100 rounded-apple p-6 flex gap-4 items-start">
                        <i class="fas fa-info-circle text-primary mt-1"></i>
                        <div>
                            <p class="text-sm font-bold text-primary uppercase tracking-wider mb-1">Base URL</p>
                            <code class="text-blue-700 font-bold"><?php echo (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']; ?>/api/v1</code>
                        </div>
                    </div>
                </section>

                <!-- Authentication -->
                <section id="auth" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-6 flex items-center gap-3">
                        <span class="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center text-sm">1</span>
                        <?php echo $tt('鉴权认证', 'Authentication'); ?>
                    </h2>
                    <p class="text-gray-600 mb-6"><?php echo $tt(
                        '所有请求必须在 HTTP Header 中携带您的 API Key。密钥分为 <span class="font-bold">Test Key</span> (测试环境) 和 <span class="font-bold">Live Key</span> (生产环境)。',
                        'All requests must include your API Key in HTTP headers. Keys are split into <span class="font-bold">Test Key</span> (sandbox) and <span class="font-bold">Live Key</span> (production).'
                    ); ?></p>
                    
                    <div class="bg-gray-50 rounded-apple p-6 border border-gray-100 mb-6">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-xs font-bold text-gray-400 uppercase tracking-widest">HTTP Header</span>
                        </div>
                        <code class="text-sm font-mono block">X-API-KEY: sk_live_xxxxxxxxxxxxxxxxxxxxxxxx</code>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('每次请求服务端会按顺序校验以下 6 项', 'Every request goes through these 6 checks (in order)'); ?></h4>
                    <div class="overflow-hidden border border-gray-100 rounded-apple mb-6">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]">#</th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('检查', 'Check'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('不通过时返回', 'On failure'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('如何排查', 'How to fix'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600 text-sm">
                                <tr><td class="px-6 py-3 font-bold">1</td><td class="px-6 py-3"><?php echo $tt('X-API-KEY 头存在', 'X-API-KEY header present'); ?></td><td class="px-6 py-3"><code class="text-red-600">401 Missing API Key</code></td><td class="px-6 py-3"><?php echo $tt('在请求头加 X-API-KEY: sk_live_xxx', 'Add the X-API-KEY header'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-bold">2</td><td class="px-6 py-3"><?php echo $tt('调用者 IP 不在系统黑名单', 'Caller IP not on system blocklist'); ?></td><td class="px-6 py-3"><code class="text-red-600">403 IP Blocked</code></td><td class="px-6 py-3"><?php echo $tt('多次失败尝试后会被自动屏蔽，联系支持解锁', 'Auto-blocked after repeated failed attempts; contact support'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-bold">3</td><td class="px-6 py-3"><?php echo $tt('API Key 有效且账号 active', 'API Key valid and account active'); ?></td><td class="px-6 py-3"><code class="text-red-600">403 Invalid API Key / Account suspended</code></td><td class="px-6 py-3"><?php echo $tt('后台「API 设置」复制最新 Key；账号停用请联系支持', 'Copy the current key from API Settings; suspended accounts need support'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-bold">4</td><td class="px-6 py-3"><?php echo $tt('Plan 未过期（适用于付费 Plan）', 'Plan not expired (paid plans)'); ?></td><td class="px-6 py-3"><code class="text-red-600">403 Plan expired</code></td><td class="px-6 py-3"><?php echo $tt('后台续费或降级到 Free Plan', 'Renew or downgrade to Free'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-bold">5</td><td class="px-6 py-3"><?php echo $tt('调用者 IP 在你的 API IP 白名单内（如已启用）', 'Caller IP in your API IP whitelist (if enabled)'); ?></td><td class="px-6 py-3"><code class="text-red-600">403 IP not in whitelist</code></td><td class="px-6 py-3"><?php echo $tt('后台 API 安全设置加入调用机器 IP；或关闭 IP 白名单功能', 'Add the caller IP under API Security; or disable the whitelist'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-bold">6</td><td class="px-6 py-3"><?php echo $tt('请求来源域名已绑定到该账户', 'Request origin domain bound to this account'); ?></td><td class="px-6 py-3"><code class="text-red-600">403 Access Denied: ...</code></td><td class="px-6 py-3"><?php echo $tt('两条路：浏览器场景请求需要带 Origin/Referer；服务端到服务端调用必须在 body 里传 <code>domain</code>，值是已绑定的网站域名', 'Two options: browser-side requests must send Origin/Referer; server-to-server calls must include <code>domain</code> (a bound website) in the JSON body'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-6 bg-blue-50 border border-blue-100 rounded-apple mb-6">
                        <h4 class="text-sm font-bold text-blue-800 mb-2"><?php echo $tt('额外的"链"权限校验（仅 /order/create.php）', 'Extra "chain" permission check (only on /order/create.php)'); ?></h4>
                        <p class="text-sm text-blue-700 leading-relaxed">
                            <?php echo $tt(
                                '当你传入 <code>chain</code> 字段时，系统会额外检查该链是否在当前 Plan 开放。例如 Free Plan 默认只开 TRC20，升级到 Pro 才解锁多链。失败返回 <code>403 Access Denied: Chain \'…\' is not enabled for your current plan</code>。可在后台「订阅 / Plan」查看当前可用链列表。',
                                'When you pass the <code>chain</code> field, the API also checks whether that chain is enabled on your current plan. Free typically allows TRC20 only — upgrading to Pro unlocks more chains. Failure returns <code>403 Access Denied: Chain \'…\' is not enabled for your current plan</code>. See Subscription / Plan in the console for your currently enabled chains.'
                            ); ?>
                        </p>
                    </div>

                    <div class="p-6 bg-yellow-50 border border-yellow-100 rounded-apple">
                        <h4 class="text-sm font-bold text-yellow-800 mb-2 flex items-center gap-2">
                            <i class="fas fa-shield-halved"></i> <?php echo $tt('网站绑定 (domain) 速查', 'Domain binding quick reference'); ?>
                        </h4>
                        <p class="text-sm text-yellow-700 leading-relaxed">
                            <?php echo $tt(
                                '前端场景：浏览器自动带 <code>Origin</code> 或 <code>Referer</code>，UAPI 据此识别绑定。<br>后端场景：服务端 curl / SDK 调用不带这些头，必须在 JSON body 里加 <code>"domain": "yoursite.com"</code>。<br>绑定操作在 <span class="font-bold underline">商户后台 → API 设置 → 网站绑定</span>。',
                                'Browser-side: the browser sends <code>Origin</code> or <code>Referer</code>; UAPI matches that to a bound domain.<br>Server-side: curl / backend SDK calls do not send those headers, so include <code>"domain": "yoursite.com"</code> in the JSON body.<br>Bind sites under <span class="font-bold underline">Merchant Console → API Settings → Website Bindings</span>.'
                            ); ?>
                        </p>
                    </div>
                </section>

                <!-- Flow -->
                <section id="flow" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-8"><?php echo $tt('对接流程', 'Integration Flow'); ?></h2>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8 relative">
                        <div class="relative z-10 p-6 bg-white rounded-apple border border-gray-100 shadow-sm">
                            <div class="w-10 h-10 bg-primary text-white rounded-full flex items-center justify-center font-bold mb-4">1</div>
                            <h4 class="font-bold mb-2"><?php echo $tt('创建订单', 'Create Order'); ?></h4>
                            <p class="text-sm text-gray-500"><?php echo $tt('商户调用接口，传入金额和网络，获取支付链接 (payment_url)。', 'Merchant calls the API with amount and network, then receives a payment link (payment_url).'); ?></p>
                        </div>
                        <div class="relative z-10 p-6 bg-white rounded-apple border border-gray-100 shadow-sm">
                            <div class="w-10 h-10 bg-primary text-white rounded-full flex items-center justify-center font-bold mb-4">2</div>
                            <h4 class="font-bold mb-2"><?php echo $tt('用户支付', 'User Pays'); ?></h4>
                            <p class="text-sm text-gray-500"><?php echo $tt('重定向用户至收银台，用户通过扫码或转账完成支付。', 'Redirect users to checkout, where they complete payment via QR scan or transfer.'); ?></p>
                        </div>
                        <div class="relative z-10 p-6 bg-white rounded-apple border border-gray-100 shadow-sm">
                            <div class="w-10 h-10 bg-primary text-white rounded-full flex items-center justify-center font-bold mb-4">3</div>
                            <h4 class="font-bold mb-2"><?php echo $tt('确认与回调', 'Confirm & Callback'); ?></h4>
                            <p class="text-sm text-gray-500"><?php echo $tt('链上确认后，系统自动发送 Webhook 通知并更新订单状态。', 'After on-chain confirmation, the system sends a webhook and updates order status automatically.'); ?></p>
                        </div>
                    </div>
                </section>

                <!-- No-Code Integration -->
                <section id="no-code" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-6"><?php echo $tt('免代码集成 (No-Code)', 'No-Code Integration'); ?></h2>
                    <div class="p-6 bg-light rounded-apple border border-gray-100">
                        <p class="text-gray-600 mb-4 font-medium"><?php echo $tt('如果您不想编写代码，可以使用我们的“收款链接”功能：', 'If you do not want to write code, you can use our Payment Link feature:'); ?></p>
                        <ul class="space-y-3 text-sm text-gray-500 list-disc pl-5">
                            <li><?php echo $tt('在商户后台一键生成专属收款页面。', 'Generate a dedicated payment page in one click from the merchant console.'); ?></li>
                            <li><?php echo $tt('支持设置固定金额或允许用户自定义输入金额。', 'Set fixed amounts or allow customers to enter custom amounts.'); ?></li>
                            <li><?php echo $tt('直接分享链接给客户，通过社交媒体、Telegram 或 WhatsApp 进行收款。', 'Share the link directly through social media, Telegram, or WhatsApp.'); ?></li>
                            <li><?php echo $tt('支付成功后，您依然可以在后台查看到账明细。', 'After successful payment, settlement details remain available in your dashboard.'); ?></li>
                        </ul>
                    </div>
                </section>

                <!-- API: Create Order -->
                <section id="api-create" class="content-section mb-20">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-2xl font-bold"><?php echo $tt('创建订单', 'Create Order'); ?></h2>
                        <span class="px-3 py-1 bg-green-100 text-green-700 text-[10px] font-bold rounded-full uppercase tracking-widest">POST</span>
                    </div>
                    <p class="text-gray-600 mb-8 font-medium"><?php echo $tt('创建一个全新的支付订单并获取收银台链接。', 'Create a brand-new payment order and receive a checkout link.'); ?></p>
                    
                    <div class="mb-8">
                        <p class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Endpoint</p>
                        <code class="bg-gray-100 px-4 py-2 rounded-lg text-primary font-bold">/order/create.php</code>
                    </div>

                    <div class="overflow-hidden border border-gray-100 rounded-apple mb-8">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('参数名', 'Parameter'); ?></th>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('类型', 'Type'); ?></th>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('必填', 'Required'); ?></th>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('描述', 'Description'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">amount</td>
                                    <td class="px-6 py-4">Float</td>
                                    <td class="px-6 py-4 text-red-500 font-bold">YES</td>
                                    <td class="px-6 py-4 text-gray-500"><?php echo $tt('订单金额 (USDT)', 'Order amount (USDT)'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">chain</td>
                                    <td class="px-6 py-4">String</td>
                                    <td class="px-6 py-4 text-red-500 font-bold">YES</td>
                                    <td class="px-6 py-4 text-gray-500"><?php echo $tt('网络: trc20, erc20, bsc, solana, polygon, etc.', 'Network: trc20, erc20, bsc, solana, polygon, etc.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">merchant_order_id</td>
                                    <td class="px-6 py-4">String</td>
                                    <td class="px-6 py-4 text-red-500 font-bold">YES</td>
                                    <td class="px-6 py-4 text-gray-500"><?php echo $tt('您的系统订单号 (唯一)', 'Your merchant order number (unique)'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">notify_url</td>
                                    <td class="px-6 py-4">String</td>
                                    <td class="px-6 py-4 text-gray-400">NO</td>
                                    <td class="px-6 py-4 text-gray-500"><?php echo $tt('异步回调地址，支付成功后系统将向该地址发送 POST 请求', 'Webhook URL. A POST callback will be sent after successful payment.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">domain</td>
                                    <td class="px-6 py-4">String</td>
                                    <td class="px-6 py-4 text-amber-600 font-bold">YES*</td>
                                    <td class="px-6 py-4 text-gray-500"><?php echo $tt('服务端直连调用时必填（如 curl / 后端 SDK）。值为您已绑定的网站域名，例如 yoursite.com。若请求自带 Origin/Referer，则可不传。', 'Required for server-to-server requests (e.g., curl/backend SDK). Use your bound website domain, e.g. yoursite.com. It can be omitted if Origin/Referer is present.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">currency</td>
                                    <td class="px-6 py-4">String</td>
                                    <td class="px-6 py-4 text-gray-400">NO</td>
                                    <td class="px-6 py-4 text-gray-500"><?php echo $tt('结算币种：USDT（默认）或 USDC。注意：后端只接受 currency，不接受 token。', 'Settlement token: USDT (default) or USDC. Note: the backend accepts currency only, not token.'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="relative">
                        <div class="absolute right-4 top-4 z-10">
                            <button onclick="copyCode(this)" class="copy-btn bg-white/10 hover:bg-white/20 text-white text-[10px] font-bold px-3 py-1.5 rounded-lg border border-white/20 backdrop-blur-md">COPY</button>
                        </div>
                        <pre><code class="language-json">{
  "status": "success",
  "data": {
    "order_no": "PAY2024...",          // <?php echo $tt('系统生成的支付订单号（所有后续接口都用这个）', 'UAPI-generated order number (used by every subsequent call)'); ?>
    "amount": 100.001234,              // <?php echo $tt('回显订单金额', 'Order amount (echoed back)'); ?>
    "currency": "USDT",                // <?php echo $tt('回显结算币种', 'Settlement token'); ?>
    "chain": "trc20",                  // <?php echo $tt('回显支付链', 'Settlement chain'); ?>
    "expire_in": 600,                  // <?php echo $tt('订单有效期 (秒)，相对时间', 'Order TTL in seconds (relative)'); ?>
    "payment_url": "https://...",      // <?php echo $tt('收银台链接，可重定向用户到此完成支付', 'Checkout URL — redirect the buyer here'); ?>
    "fast_sync_enabled": true          // <?php echo $tt('是否启用了快速同步（影响链上确认速度）', 'Whether fast on-chain confirmation is enabled for this order'); ?>
  }
}</code></pre>
                        <p class="text-xs text-gray-500 mt-2"><?php echo $tt('⚠️ 旧文档曾使用 order_id / token / payment_address / qr_code / expire_at（时间戳）等字段名，那些字段并不真实返回，请按以上字段对接。', '⚠️ Older docs referenced order_id / token / payment_address / qr_code / expire_at (timestamp). Those fields are NOT returned — integrate against the keys above.'); ?></p>
                    </div>
                </section>

                <!-- API: Check Status -->
                <section id="api-status" class="content-section mb-20">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-2xl font-bold"><?php echo $tt('查询订单状态', 'Check Order Status'); ?></h2>
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 text-[10px] font-bold rounded-full uppercase tracking-widest">GET</span>
                    </div>
                    <div class="p-4 bg-amber-50 border border-amber-200 rounded-apple mb-6 text-sm text-amber-800">
                        <i class="fas fa-circle-info mr-1"></i>
                        <?php echo $tt(
                            '此端点 <b>不使用 X-API-KEY</b>。它用订单创建时返回的 <code>pay_access_token</code>（已嵌入 <code>payment_url</code>）作鉴权——主要是给收银台轮询用的，第三方系统通常通过 Webhook 接收成功通知而不是直接轮询。',
                            'This endpoint does <b>not</b> use X-API-KEY. It authenticates with the per-order <code>pay_access_token</code> returned by create (already embedded in <code>payment_url</code>) — designed mainly for the checkout page to poll. Server integrators typically receive payment success via the Webhook callback rather than polling.'
                        ); ?>
                    </div>

                    <div class="mb-6">
                        <p class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Endpoint</p>
                        <code class="bg-gray-100 px-4 py-2 rounded-lg text-blue-600 font-bold block break-all">GET /api/v1/order/status.php?order_no=PAY...&amp;token=ACCESS_TOKEN</code>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('查询参数', 'Query Parameters'); ?></h4>
                    <div class="overflow-hidden border border-gray-100 rounded-apple mb-6">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('参数', 'Param'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('必填', 'Required'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('说明', 'Meaning'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600 text-sm">
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">order_no</td><td class="px-6 py-3 text-red-500 font-bold">YES</td><td class="px-6 py-3"><?php echo $tt('UAPI 订单号（创建订单时返回）', 'UAPI order number from create response'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">token</td><td class="px-6 py-3 text-red-500 font-bold">YES</td><td class="px-6 py-3"><?php echo $tt('订单访问令牌，从创建订单返回的 <code>payment_url</code> 里的 query 取出', 'Per-order access token; extract it from the <code>payment_url</code> query string returned at create time'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('响应（按状态分支）', 'Responses (by state)'); ?></h4>
                    <div class="relative mb-2">
<pre><code class="language-json">// <?php echo $tt('支付成功', 'Paid'); ?>
{ "status": "paid", "tx_hash": "0x123abc..." }

// <?php echo $tt('等待支付', 'Awaiting payment'); ?>
{ "status": "pending" }

// <?php echo $tt('订单已超时', 'Order expired'); ?>
{ "status": "expired" }

// <?php echo $tt('错误', 'Error'); ?>
{ "status": "error", "error": "Invalid token" }</code></pre>
                    </div>
                    <p class="text-xs text-gray-500 mt-2"><?php echo $tt('频率：同 IP 30 次/分钟；单订单跨所有 IP 40 次/10 秒。第三方系统强烈推荐用 Webhook 而非轮询。', 'Rate limits: 30 / min per IP, 40 / 10s per order across all IPs. Server integrators should rely on Webhook callbacks instead of polling.'); ?></p>
                </section>

                <!-- Dispute -->
                <section id="api-dispute" class="content-section mb-20">
                    <div class="flex items-center justify-between mb-6">
                        <h2 class="text-2xl font-bold"><?php echo $tt('提交订单争议', 'Submit Dispute'); ?></h2>
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 text-[10px] font-bold rounded-full uppercase tracking-widest">POST</span>
                    </div>
                    <p class="text-gray-600 mb-6 font-medium"><?php echo $tt('买家收到错款（少付 / 错币种）或对账有争议时，商户可把订单标记为 disputed，可选地把订单金额/币种调成实际收到的数值，以便后续核对。', 'When a buyer underpaid or paid in the wrong currency, the merchant can flag the order as disputed and optionally rewrite the recorded amount / currency to match what was actually received.'); ?></p>

                    <div class="mb-6">
                        <p class="text-[11px] font-bold text-gray-400 uppercase tracking-widest mb-3">Endpoint</p>
                        <code class="bg-gray-100 px-4 py-2 rounded-lg text-blue-600 font-bold block break-all">POST /api/v1/order/dispute.php</code>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('请求头', 'Headers'); ?></h4>
                    <div class="bg-gray-50 rounded-apple p-6 border border-gray-100 mb-6">
                        <code class="text-sm font-mono block">X-API-KEY: sk_live_xxxxxxxxxxxxxxxxxxxxxxxx</code>
                        <code class="text-sm font-mono block">Content-Type: application/json</code>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('请求体', 'Body'); ?></h4>
                    <div class="overflow-hidden border border-gray-100 rounded-apple mb-6">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('字段', 'Field'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('必填', 'Required'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('说明', 'Meaning'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600 text-sm">
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">merchant_order_id</td><td class="px-6 py-3 text-amber-600 font-bold">YES*</td><td class="px-6 py-3"><?php echo $tt('你系统里的订单号。和 <code>order_no</code> 二选一。', 'Your merchant order ID. Either this or <code>order_no</code> must be provided.'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">order_no</td><td class="px-6 py-3 text-amber-600 font-bold">YES*</td><td class="px-6 py-3"><?php echo $tt('UAPI 订单号', 'UAPI order number'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">domain</td><td class="px-6 py-3 text-amber-600 font-bold">YES*</td><td class="px-6 py-3"><?php echo $tt('服务端调用必填，必须是已绑定域名（同 create.php）', 'Required for server-side calls; must be a bound domain (same rule as create.php)'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">mode</td><td class="px-6 py-3 text-gray-400">NO</td><td class="px-6 py-3"><?php echo $tt('<code>original</code>（默认，保持原金额）或 <code>adjusted</code>（按 <code>new_amount</code> / <code>new_currency</code> 改写）', '<code>original</code> (default, keep original amount) or <code>adjusted</code> (rewrite using new_amount / new_currency)'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">new_amount</td><td class="px-6 py-3 text-gray-400">NO</td><td class="px-6 py-3"><?php echo $tt('mode=adjusted 时生效；必须 ≤ 原订单金额，否则会被截断', 'Used when mode=adjusted; cannot exceed the original amount (clamped if it does)'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">new_currency</td><td class="px-6 py-3 text-gray-400">NO</td><td class="px-6 py-3"><?php echo $tt('mode=adjusted 时生效，<code>USDT</code> 或 <code>USDC</code>', 'Used when mode=adjusted; <code>USDT</code> or <code>USDC</code>'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold text-primary">note</td><td class="px-6 py-3 text-gray-400">NO</td><td class="px-6 py-3"><?php echo $tt('备注，会追加到订单 notes 字段', 'Free-form note appended to the order\'s notes column'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('成功响应', 'Success Response'); ?></h4>
                    <div class="relative mb-2">
<pre><code class="language-json">{
  "status": "success",
  "data": {
    "order_id": 12345,
    "order_no": "UAPI20260408001",
    "merchant_order_id": "ORD-001",
    "order_status": "disputed",
    "amount": "100.000000",
    "currency": "USDT",
    "mode": "original"
  }
}</code></pre>
                    </div>
                    <p class="text-xs text-gray-500 mt-2"><?php echo $tt('频率：20 次/分钟/IP。提交后订单 status 即变为 <code>disputed</code>，不会再触发 Webhook。', 'Rate limit: 20 / min per IP. After submission the order status flips to <code>disputed</code> — no further Webhook will fire.'); ?></p>
                </section>

                <!-- Webhook Section -->
                <section id="webhook" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-6"><?php echo $tt('回调通知 (Webhook)', 'Webhook Callback'); ?></h2>
                    <p class="text-gray-600 mb-4 font-medium"><?php echo $tt('当链上交易确认后，UAPI 会立即向你创建订单时传入的 <code>notify_url</code> 发送一个 POST 回调。', 'Once the on-chain transaction is confirmed, UAPI immediately sends a POST callback to the <code>notify_url</code> you passed when creating the order.'); ?></p>
                    <p class="text-gray-500 text-sm mb-8"><?php echo $tt('若创建订单时未传 <code>notify_url</code>，系统会自动使用商户后台「API 设置」中的默认 Webhook 地址。', 'If <code>notify_url</code> is omitted when creating an order, the default webhook URL configured in Merchant Console &gt; API Settings is used instead.'); ?></p>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('请求头', 'Request Headers'); ?></h4>
                    <div class="overflow-hidden border border-gray-100 rounded-apple mb-8">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]">Header</th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('说明', 'Meaning'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600">
                                <tr><td class="px-6 py-3 font-mono text-primary font-bold">Content-Type</td><td class="px-6 py-3"><?php echo $tt('固定 application/json', 'Always application/json'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono text-primary font-bold">X-UAPI-Signature</td><td class="px-6 py-3"><?php echo $tt('HMAC-SHA256 签名（hex 字符串，<b>不带 <code>sha256=</code> 前缀</b>）', 'HMAC-SHA256 hex digest (no <code>sha256=</code> prefix)'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono text-primary font-bold">X-UAPI-Timestamp</td><td class="px-6 py-3"><?php echo $tt('签名时的 Unix 秒级时间戳，参与签名输入', 'Unix timestamp (seconds) used as part of the signed payload'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono text-primary font-bold">X-UAPI-Event</td><td class="px-6 py-3"><?php echo $tt('当前固定 <code>order.paid</code>', 'Currently always <code>order.paid</code>'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono text-primary font-bold">X-UAPI-Event-ID</td><td class="px-6 py-3"><?php echo $tt('本次事件唯一 ID（16 hex 字符），用于幂等去重', 'Per-event unique ID (16 hex chars); use it to dedupe retries'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('请求体（扁平结构）', 'Request Body (flat)'); ?></h4>
                    <div class="relative mb-8">
                        <div class="absolute right-4 top-4 z-10">
                            <button onclick="copyCode(this)" class="copy-btn bg-white/10 hover:bg-white/20 text-white text-[10px] font-bold px-3 py-1.5 rounded-lg border border-white/20 backdrop-blur-md">COPY</button>
                        </div>
<pre><code class="language-json">{
  "status": "paid",
  "order_no": "UAPI20260408001",
  "merchant_order_id": "ORD-001",
  "amount": "100.00",
  "chain": "trc20",
  "currency": "USDT",
  "tx_hash": "0xabcdef1234567890...",
  "paid_at": "2026-04-08T10:05:00+00:00"
}</code></pre>
                        <p class="text-xs text-gray-500 mt-2"><?php echo $tt('⚠️ 注意字段名：<code>order_no</code>（不是 order_id）、<code>currency</code>（不是 token）、<code>tx_hash</code>（不是 txid）、没有嵌套的 <code>event</code>/<code>data</code> 包装层。', '⚠️ Field names: <code>order_no</code> (not order_id), <code>currency</code> (not token), <code>tx_hash</code> (not txid). The body is flat — there is no wrapping <code>event</code>/<code>data</code> object.'); ?></p>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('签名输入与验证', 'Signature Input &amp; Verification'); ?></h4>
                    <p class="text-gray-600 text-sm mb-4"><?php echo $tt('签名输入是 4 个字段的字符串拼接：<code>order_no + amount + merchant_order_id + timestamp</code>，密钥用 <b>你账户的 API Key</b>（没有独立的 webhook secret）。', 'The signed payload is four fields concatenated: <code>order_no + amount + merchant_order_id + timestamp</code>. The secret is <b>your API Key</b> — there is no separate webhook secret.'); ?></p>
                    <div class="relative mb-8">
                        <div class="absolute right-4 top-4 z-10">
                            <button onclick="copyCode(this)" class="copy-btn bg-white/10 hover:bg-white/20 text-white text-[10px] font-bold px-3 py-1.5 rounded-lg border border-white/20 backdrop-blur-md">COPY</button>
                        </div>
<pre><code class="language-php">&lt;?php
$rawBody   = file_get_contents('php://input');
$payload   = json_decode($rawBody, true);
$signature = $_SERVER['HTTP_X_UAPI_SIGNATURE']  ?? '';
$timestamp = $_SERVER['HTTP_X_UAPI_TIMESTAMP']  ?? '';
$apiKey    = 'sk_live_your_api_key';

// reject signatures older than 5 minutes (anti-replay)
if (abs(time() - (int)$timestamp) &gt; 300) {
    http_response_code(401); exit('Stale timestamp');
}

$signInput = $payload['order_no']
           . $payload['amount']
           . $payload['merchant_order_id']
           . $timestamp;
$expected = hash_hmac('sha256', $signInput, $apiKey);

if (!hash_equals($expected, $signature)) {
    http_response_code(401); exit('Invalid signature');
}

// dedupe on X-UAPI-Event-ID, then process the order
// return HTTP 200 to acknowledge
http_response_code(200); echo 'success';</code></pre>
                    </div>

                    <div class="bg-blue-50 border border-blue-100 rounded-apple p-6">
                        <p class="text-sm text-blue-700 font-medium mb-0">
                            <i class="fas fa-check-circle mr-2"></i> <?php echo $tt('响应要求：接收端必须返回 HTTP 2xx（或返回包含字符串 <code>success</code> 的 body）。否则 UAPI 会重试最多 3 次，超时 10 秒。重试间签名头里的 <code>X-UAPI-Event-ID</code> 不变，建议据此做幂等。', 'Response requirement: return HTTP 2xx (or a body containing <code>success</code>). Otherwise UAPI retries up to 3 times (10s timeout each). The <code>X-UAPI-Event-ID</code> header stays constant across retries — use it to dedupe.'); ?>
                        </p>
                    </div>
                </section>

                <!-- Status Model -->
                <section id="status-model" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-8"><?php echo $tt('订单状态机', 'Order State Machine'); ?></h2>
                    <div class="overflow-hidden border border-gray-100 rounded-apple">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('状态', 'Status'); ?></th>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('描述', 'Description'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600">
                                <tr>
                                    <td class="px-6 py-4 font-bold text-black">pending</td>
                                    <td class="px-6 py-4"><?php echo $tt('已创建，等待用户按指定金额转账。', 'Created and waiting for user payment with the exact amount.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-bold text-black">paid</td>
                                    <td class="px-6 py-4"><?php echo $tt('链上到账金额和地址匹配，已记录交易哈希并回调。', 'On-chain amount and address matched; tx hash recorded and callback sent.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-bold text-black">expired</td>
                                    <td class="px-6 py-4"><?php echo $tt('超过有效期 (通常为 10-20 分钟) 未支付，系统自动标记过期。', 'Not paid within validity period (usually 10-20 minutes); automatically marked as expired.'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Limits & Idempotency -->
                <section id="limits" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-6"><?php echo $tt('限流、幂等与金额精度', 'Rate Limits, Idempotency & Amount Precision'); ?></h2>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('四层限流（按代码实际生效顺序）', 'The Four Rate-Limit Layers (in order)'); ?></h4>
                    <div class="overflow-hidden border border-gray-100 rounded-apple mb-6">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('层级', 'Layer'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('范围', 'Scope'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('额度', 'Quota'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('触发后', 'On exceed'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600 text-sm">
                                <tr><td class="px-6 py-3 font-bold">1. Per-IP 黑名单</td><td class="px-6 py-3"><?php echo $tt('全站，按调用方 IP', 'Site-wide, by caller IP'); ?></td><td class="px-6 py-3"><?php echo $tt('多次失败后自动入', 'Auto-block on repeated failures'); ?></td><td class="px-6 py-3"><code>403 IP Blocked: ...</code></td></tr>
                                <tr><td class="px-6 py-3 font-bold">2. Per-endpoint burst</td><td class="px-6 py-3"><?php echo $tt('每端点 × IP × 60 秒滑窗', 'Per endpoint × IP × 60s window'); ?></td><td class="px-6 py-3"><code>order/create.php</code>: 30 / min<br><code>order/status.php</code>: 30 / min<br><code>order/dispute.php</code>: 20 / min<br><code>store/create_order.php</code>: 20 / min</td><td class="px-6 py-3"><code>429 Too Many Requests</code></td></tr>
                                <tr><td class="px-6 py-3 font-bold">3. Per-order burst</td><td class="px-6 py-3"><?php echo $tt('单订单跨所有 IP × 10 秒', 'Single order across all IPs × 10s'); ?></td><td class="px-6 py-3">40 / 10s</td><td class="px-6 py-3"><code>429 Order polling too frequent</code></td></tr>
                                <tr><td class="px-6 py-3 font-bold">4. Daily quota</td><td class="px-6 py-3"><?php echo $tt('账户级，按 Plan', 'Account-level, by Plan'); ?></td><td class="px-6 py-3">Free 100 / day<br>Pro 20,000 / day<br>Business 50,000 / day</td><td class="px-6 py-3"><code>429 Daily quota exceeded</code></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('幂等保证', 'Idempotency Guarantees'); ?></h4>
                    <ul class="space-y-3 text-gray-600 font-medium list-disc pl-5 mb-6">
                        <li><span class="text-black font-bold"><?php echo $tt('创建订单：', 'Create Order:'); ?></span> <?php echo $tt('用相同 <code>merchant_order_id</code> 重复 POST，会返回已存在订单（同 <code>order_no</code> / <code>payment_url</code>），不会重复创建。', 'POSTing the same <code>merchant_order_id</code> returns the existing order (same <code>order_no</code> and <code>payment_url</code>) — no duplicate is created.'); ?></li>
                        <li><span class="text-black font-bold"><?php echo $tt('Webhook：', 'Webhook:'); ?></span> <?php echo $tt('每次事件有唯一 <code>X-UAPI-Event-ID</code>。重试时同一 event_id 不变，请用它做去重。', 'Each event has a unique <code>X-UAPI-Event-ID</code>; retries reuse the same id — dedupe on it.'); ?></li>
                        <li><span class="text-black font-bold"><?php echo $tt('并发：', 'Concurrency:'); ?></span> <?php echo $tt('Webhook 处理建议加分布式锁（按 order_no），防多线程并发同一订单。', 'Lock by order_no (e.g. Redis) when handling webhooks to serialize per-order work.'); ?></li>
                    </ul>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('金额精度与防冲突随机数', 'Amount Precision & Anti-collision'); ?></h4>
                    <div class="bg-amber-50 border border-amber-100 rounded-apple p-6 mb-2">
                        <p class="text-sm text-amber-900 leading-relaxed mb-2">
                            <i class="fas fa-circle-exclamation mr-1"></i>
                            <?php echo $tt('<b>请求</b>的 <code>amount</code> 是普通 decimal，范围 <code>(0, 1,000,000]</code>。', '<b>Request</b> <code>amount</code> is a plain decimal in the range <code>(0, 1,000,000]</code>.'); ?>
                        </p>
                        <p class="text-sm text-amber-900 leading-relaxed mb-2">
                            <?php echo $tt('<b>响应</b>里的 <code>amount</code> 与链上要求转账的金额可能比你传的<b>多一个 6 位小数尾巴</b>（如你传 100.00 → 客户实付 100.001234）。这是<b>故意的</b>：UAPI 用尾数小数区分同金额并发订单，链上 watcher 据此精确匹配付款。', '<b>Response</b> <code>amount</code> may be your input padded with up to 6 random decimal digits (e.g. 100.00 → 100.001234). This is intentional: the trailing micro-amount lets the on-chain watcher distinguish two simultaneous orders with the same headline price.'); ?>
                        </p>
                        <p class="text-sm text-amber-900 leading-relaxed mb-0">
                            <?php echo $tt('<b>客户必须按响应里的 amount 精确支付</b>（少 1 个 micro 都不会被识别为该订单的付款）。', '<b>Customers must transfer the response amount exactly</b> (a single missing micro-unit causes the watcher to skip the order).'); ?>
                        </p>
                    </div>
                    <p class="text-xs text-gray-500"><?php echo $tt('请勿对响应里的 amount 自行截断到 2 位小数；展示给客户的二维码 / 文案应使用 UAPI 返回的原值。', 'Do NOT truncate the response amount to 2 decimals client-side; show the exact UAPI-returned amount in the QR / instructions.'); ?></p>
                </section>

                <!-- Chains Reference -->
                <section id="chains" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-8"><?php echo $tt('支持网络与精度', 'Supported Networks & Precision'); ?></h2>
                    <div class="overflow-hidden border border-gray-100 rounded-apple">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('网络 (Slug)', 'Network (Slug)'); ?></th>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('精度', 'Precision'); ?></th>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('代币', 'Token'); ?></th>
                                    <th class="px-6 py-4 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('备注', 'Notes'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600">
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">trc20</td>
                                    <td class="px-6 py-4">6</td>
                                    <td class="px-6 py-4">USDT</td>
                                    <td class="px-6 py-4"><?php echo $tt('Tron 网络，到账极速且费用极低。', 'Tron network with fast confirmation and very low fees.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">bsc</td>
                                    <td class="px-6 py-4">18</td>
                                    <td class="px-6 py-4">USDT/BNB</td>
                                    <td class="px-6 py-4"><?php echo $tt('币安智能链。', 'Binance Smart Chain.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">erc20</td>
                                    <td class="px-6 py-4">18</td>
                                    <td class="px-6 py-4">USDT/ETH</td>
                                    <td class="px-6 py-4"><?php echo $tt('以太坊主网 (Gas 较高)。', 'Ethereum mainnet (higher gas fees).'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">polygon</td>
                                    <td class="px-6 py-4">6/18</td>
                                    <td class="px-6 py-4">USDT/MATIC</td>
                                    <td class="px-6 py-4"><?php echo $tt('Layer 2 扩容网络。', 'Layer 2 scaling network.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">solana</td>
                                    <td class="px-6 py-4">6/9</td>
                                    <td class="px-6 py-4">USDT/USDC</td>
                                    <td class="px-6 py-4"><?php echo $tt('高性能非 EVM 链。', 'High-performance non-EVM chain.'); ?></td>
                                </tr>
                                <tr>
                                    <td class="px-6 py-4 font-mono text-primary font-bold">arbitrum</td>
                                    <td class="px-6 py-4">18</td>
                                    <td class="px-6 py-4">USDT</td>
                                    <td class="px-6 py-4"><?php echo $tt('以太坊 L2 卷叠网络。', 'Ethereum L2 rollup network.'); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Errors -->
                <section id="errors" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-6"><?php echo $tt('错误响应与错误码', 'Errors &amp; Error Codes'); ?></h2>
                    <p class="text-gray-600 mb-6 font-medium"><?php echo $tt('当前外部 API（X-API-KEY 入口）以两种形式返回错误：单字段简略错误，或 status/error/code 三字段结构。两种都需兼容处理。', 'External API endpoints (X-API-KEY) currently emit errors in two shapes — a short single-field form and a structured status/error/code form. Your client should handle both.'); ?></p>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('短格式（多见于鉴权/限流错误）', 'Short form (most auth / rate-limit errors)'); ?></h4>
                    <div class="relative mb-8">
<pre><code class="language-json">{ "error": "Invalid API Key" }</code></pre>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('结构化格式（多见于业务错误）', 'Structured form (business errors)'); ?></h4>
                    <div class="relative mb-8">
<pre><code class="language-json">{
  "status": "error",
  "error": "Plan expired. Please upgrade/renew.",
  "code": "PLAN_EXPIRED"
}</code></pre>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('HTTP 状态码', 'HTTP Status Codes'); ?></h4>
                    <div class="overflow-hidden border border-gray-100 rounded-apple mb-8">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('状态', 'Status'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('含义', 'Meaning'); ?></th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('常见触发', 'Common Trigger'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600">
                                <tr><td class="px-6 py-3 font-mono font-bold">200</td><td class="px-6 py-3"><?php echo $tt('成功', 'Success'); ?></td><td class="px-6 py-3"><?php echo $tt('订单创建成功 / 查询正常返回', 'Order created / data returned'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold">400</td><td class="px-6 py-3"><?php echo $tt('请求格式或参数错误', 'Bad request'); ?></td><td class="px-6 py-3"><?php echo $tt('缺少必填字段 / 链不支持 / USDC 与 TRC20 组合', 'Missing field / unsupported chain / USDC on TRC20'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold">401</td><td class="px-6 py-3"><?php echo $tt('未鉴权', 'Unauthorized'); ?></td><td class="px-6 py-3"><?php echo $tt('缺少 X-API-KEY 头', 'Missing X-API-KEY header'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold">403</td><td class="px-6 py-3"><?php echo $tt('权限不足', 'Forbidden'); ?></td><td class="px-6 py-3"><?php echo $tt('API Key 无效 / 账号停用 / Plan 过期 / IP 黑名单 / 不在 IP 白名单 / 链未在当前 Plan 开放 / Origin 与绑定域名不符', 'Invalid API key / suspended account / plan expired / IP blocked / IP not in whitelist / chain not enabled on plan / origin not bound'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold">429</td><td class="px-6 py-3"><?php echo $tt('请求过于频繁', 'Rate limited'); ?></td><td class="px-6 py-3"><?php echo $tt('日额度耗尽 / 同 IP 短时间内突发过多请求 / 单订单查询频率超限', 'Daily quota exhausted / per-IP burst / per-order query burst'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono font-bold">500</td><td class="px-6 py-3"><?php echo $tt('服务端错误', 'Server error'); ?></td><td class="px-6 py-3"><?php echo $tt('钱包未配置 / 链端 RPC 失败 / 数据库异常', 'Wallet not provisioned / RPC failure / DB exception'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h4 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3"><?php echo $tt('常见 error 字符串与解决方法', 'Common error messages &amp; how to fix'); ?></h4>
                    <div class="overflow-hidden border border-gray-100 rounded-apple">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]">error</th>
                                    <th class="px-6 py-3 font-bold text-gray-400 uppercase tracking-widest text-[10px]"><?php echo $tt('如何修复', 'How to fix'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50 text-gray-600">
                                <tr><td class="px-6 py-3 font-mono">Missing API Key</td><td class="px-6 py-3"><?php echo $tt('在请求头加 X-API-KEY: sk_live_xxx', 'Add X-API-KEY: sk_live_xxx header'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono">Invalid API Key</td><td class="px-6 py-3"><?php echo $tt('Key 错或已被重置。后台「API 设置」→ 复制最新 Key', 'Key wrong or rotated. Console &gt; API Settings &gt; copy current key'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono">IP not in whitelist</td><td class="px-6 py-3"><?php echo $tt('后台 API 安全设置里把调用机器 IP 加进白名单', 'Add the caller IP to whitelist in API Security settings'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono">Plan expired</td><td class="px-6 py-3"><?php echo $tt('升级或续费当前 Plan', 'Renew or upgrade your plan'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono">Access Denied: Chain '...' is not enabled for your current plan</td><td class="px-6 py-3"><?php echo $tt('该链需要更高级 Plan，或换支持的链', 'Chain requires a higher plan tier; switch chain or upgrade'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono">Access Denied: domain not bound</td><td class="px-6 py-3"><?php echo $tt('在 API 设置「网站绑定」里加上 Origin/Referer 对应域名；服务端调用请在 body 里带 domain', 'Bind the request origin in Settings &gt; Websites, or pass `domain` in the JSON body for S2S calls'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono">Currency '...' is not supported on chain '...'</td><td class="px-6 py-3"><?php echo $tt('USDC 不支持 TRC20，其他链均可。换链或换币种', 'USDC is not available on TRC20; pick another chain or USDT'); ?></td></tr>
                                <tr><td class="px-6 py-3 font-mono">Too many requests</td><td class="px-6 py-3"><?php echo $tt('指数退避后重试；或升级 Plan 提高额度', 'Back off and retry; or upgrade plan for higher quota'); ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- Tools & Versions -->
                <section id="tools-versions" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-6"><?php echo $tt('工具与版本', 'Tools & Versions'); ?></h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="p-6 bg-gray-50 rounded-apple border border-gray-100">
                            <h5 class="font-bold mb-2"><?php echo $tt('TLS 要求', 'TLS Requirement'); ?></h5>
                            <p class="text-sm text-gray-500 leading-relaxed"><?php echo $tt('出于安全考虑，我们的 API 强制要求使用 TLS 1.2 或更高版本。旧版本的 SSL/TLS 将无法建立连接。', 'For security, our API requires TLS 1.2 or higher. Older SSL/TLS versions cannot establish a connection.'); ?></p>
                        </div>
                        <div class="p-6 bg-gray-50 rounded-apple border border-gray-100">
                            <h5 class="font-bold mb-2"><?php echo $tt('数据编码', 'Data Encoding'); ?></h5>
                            <p class="text-sm text-gray-500 leading-relaxed"><?php echo $tt('所有请求和响应体必须使用 UTF-8 编码。请确保您的 Content-Type 设置为 <code>application/json</code>。', 'All request and response bodies must use UTF-8. Ensure your Content-Type is set to <code>application/json</code>.'); ?></p>
                        </div>
                    </div>
                </section>

                <!-- Developer FAQ -->
                <section id="doc-faq" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-8"><?php echo $tt('开发者 FAQ', 'Developer FAQ'); ?></h2>
                    <div class="space-y-4">
                        <details class="group border border-gray-100 rounded-apple p-6 bg-gray-50">
                            <summary class="list-none flex justify-between items-center cursor-pointer">
                                <h4 class="font-bold text-lg text-black"><?php echo $tt('订单有效期可以自定义吗？', 'Can order expiration be customized?'); ?></h4>
                                <span class="group-open:rotate-180 transition-transform"><i class="fas fa-chevron-down text-gray-400"></i></span>
                            </summary>
                            <p class="mt-4 text-gray-600 leading-relaxed text-sm">
                                <?php echo $tt('默认有效期为 10-20 分钟。如果需要更长的有效期，请联系技术支持或在商户后台的 API 设置中进行配置。过长的有效期可能会增加订单金额冲突的概率。', 'Default validity is 10-20 minutes. For longer expiration, contact support or configure it in Merchant Console > API Settings. Longer windows may increase amount-collision probability.'); ?>
                            </p>
                        </details>
                        <details class="group border border-gray-100 rounded-apple p-6 bg-gray-50">
                            <summary class="list-none flex justify-between items-center cursor-pointer">
                                <h4 class="font-bold text-lg text-black"><?php echo $tt('为什么实际支付金额会有随机小数？', 'Why does the actual payable amount include random decimals?'); ?></h4>
                                <span class="group-open:rotate-180 transition-transform"><i class="fas fa-chevron-down text-gray-400"></i></span>
                            </summary>
                            <p class="mt-4 text-gray-600 leading-relaxed text-sm">
                                <?php echo $tt('这是为了在非托管钱包中实现精确的订单匹配。由于区块链上可能存在多笔相同整数金额的转账，通过增加微小的随机数（例如 100.001234），我们可以确保每笔订单在您的钱包地址下是全网唯一的。', 'This enables precise order matching in non-custodial wallets. Since multiple transfers may share the same integer amount on-chain, adding a tiny random decimal (e.g., 100.001234) keeps each order unique under your wallet address.'); ?>
                            </p>
                        </details>
                    </div>
                </section>

                <!-- SDK Examples -->
                <section id="examples" class="content-section mb-20">
                    <h2 class="text-2xl font-bold mb-8"><?php echo $tt('多语言接入示例', 'Multi-language Integration Examples'); ?></h2>
                    
                    <div class="space-y-12">
                        <!-- PHP -->
                        <div>
                            <div class="flex items-center gap-2 mb-4">
                                <i class="fab fa-php text-2xl text-[#777BB4]"></i>
                                <span class="font-bold">PHP</span>
                            </div>
                            <pre><code class="language-php">$apiKey = 'sk_live_xxx';
$data = [
    'amount' => 100.0,
    'chain' => 'trc20',
    'merchant_order_id' => 'MY_ORDER_001',
    'notify_url' => 'https://yoursite.com/callback',
    'domain' => 'yoursite.com'
];

$ch = curl_init('<?php echo htmlspecialchars($scheme . '://' . $host); ?>/api/v1/order/create.php');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-KEY: ' . $apiKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
$result = json_decode($response, true);
echo $result['data']['payment_url'] ?? 'Create order failed';</code></pre>
                        </div>

                        <!-- Node.js -->
                        <div>
                            <div class="flex items-center gap-2 mb-4">
                                <i class="fab fa-node-js text-2xl text-[#339933]"></i>
                                <span class="font-bold">Node.js (Axios)</span>
                            </div>
                            <pre><code class="language-javascript">const axios = require('axios');

const createOrder = async () => {
  const { data } = await axios.post('<?php echo htmlspecialchars($scheme . '://' . $host); ?>/api/v1/order/create.php', {
    amount: 100.0,
    chain: 'trc20',
    merchant_order_id: 'MY_ORDER_001',
    notify_url: 'https://yoursite.com/callback',
    domain: 'yoursite.com'
  }, {
    headers: {
      'X-API-KEY': 'sk_live_xxx',
      'Content-Type': 'application/json'
    }
  });
  console.log(data.data.payment_url);
};</code></pre>
                        </div>
                    </div>
                </section>

            </main>
        </div>
    </div>

    <!-- Footer -->
    <footer class="py-12 bg-gray-50 border-t border-gray-100">
        <div class="max-w-[1440px] mx-auto px-6 text-center">
            <p class="text-sm font-bold text-gray-400 uppercase tracking-widest">&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?> Documentation</p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.8.0/highlight.min.js"></script>
    <script>
        hljs.highlightAll();

        function copyCode(btn) {
            const pre = btn.closest('.relative').querySelector('pre');
            const code = pre.innerText.replace('COPY', '').trim();
            navigator.clipboard.writeText(code).then(() => {
                const originalText = btn.innerText;
                btn.innerText = 'COPIED!';
                btn.classList.add('bg-green-500', 'border-green-500');
                setTimeout(() => {
                    btn.innerText = originalText;
                    btn.classList.remove('bg-green-500', 'border-green-500');
                }, 2000);
            });
        }

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
