<?php
require_once __DIR__ . '/inc/bootstrap.php';

$lang = I18n::getLang();
$db = Database::getInstance();

try {
    $settings = $db->fetchAll("SELECT * FROM system_settings");
    $cfg = [];
    foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
} catch (Exception $e) {
    $cfg = [];
}

$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '/assets/logo.png';

$lang_zh_url    = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-cn']));
$lang_zh_tw_url = '?' . http_build_query(array_merge($_GET, ['lang' => 'zh-tw']));
$lang_en_url    = '?' . http_build_query(array_merge($_GET, ['lang' => 'en']));
$lang_ja_url    = '?' . http_build_query(array_merge($_GET, ['lang' => 'ja']));

$is_https = ((!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
$scheme = $is_https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

$is_default_locale = $lang === 'zh-cn';
$default_title = $site_name . ' - ' . __('meta.title_suffix');
$seo_title       = ($is_default_locale && trim((string)($cfg['seo_title']       ?? '')) !== '') ? trim((string)$cfg['seo_title'])       : $default_title;
$seo_description = ($is_default_locale && trim((string)($cfg['seo_description'] ?? '')) !== '') ? trim((string)$cfg['seo_description']) : __('meta.description');
$seo_keywords    = ($is_default_locale && trim((string)($cfg['seo_keywords']    ?? '')) !== '') ? trim((string)$cfg['seo_keywords'])    : __('meta.keywords');
$seo_canonical = trim((string)($cfg['seo_canonical'] ?? '')) ?: ($scheme . '://' . $host . '/');
?>
<!DOCTYPE html>
<html lang="<?php echo match ($lang) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>">
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
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($site_name); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($seo_title); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($seo_description); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($scheme . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/')); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($scheme . '://' . $host . '/assets/logo.png'); ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($seo_title); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($seo_description); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($scheme . '://' . $host . '/assets/logo.png'); ?>">
    <?php if (!empty($cfg['site_favicon'])): ?>
        <link rel="icon" href="<?php echo htmlspecialchars($cfg['site_favicon']); ?>">
    <?php else: ?>
        <link rel="icon" href="/assets/favicon.png">
    <?php endif; ?>
    <link rel="stylesheet" href="/output.css">
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "SoftwareApplication",
        "name": <?php echo json_encode($site_name); ?>,
        "description": "自托管加密支付网关，商家可一键接入 USDT / USDC 多链收款，资金直达个人钱包，非托管、可私有部署。",
        "url": <?php echo json_encode($scheme . '://' . $host . '/'); ?>,
        "applicationCategory": "FinanceApplication",
        "operatingSystem": "Web",
        "offers": {
            "@type": "Offer",
            "price": 0,
            "priceCurrency": "USD"
        }
    }
    </script>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Organization",
        "name": <?php echo json_encode($site_name); ?>,
        "url": <?php echo json_encode($scheme . '://' . $host . '/'); ?>,
        "logo": <?php echo json_encode($scheme . '://' . $host . '/assets/logo.png'); ?>
    }
    </script>
    <style>
        :root {
            --primary: #2563EB;
            --primary-dark: #1D4ED8;
            --primary-light: #3B82F6;
            --accent: #0EA5E9;
            --ink: #0F172A;
            --muted: #64748B;
            --border: #E2E8F0;
            --bg: #F8FAFC;
            --bg-soft: #F1F5F9;
            --success: #10B981;
            --success-bg: #ECFDF5;
        }
        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Helvetica Neue", "PingFang SC", "Microsoft YaHei", Arial, sans-serif;
            color: var(--ink);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
            line-height: 1.55;
        }
        a { color: inherit; text-decoration: none; }
        button { font-family: inherit; }
        .container-x { max-width: 1200px; margin: 0 auto; padding: 0 24px; }

        /* === Header === */
        .site-header {
            position: sticky; top: 0; z-index: 100;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        }
        .site-header .row { display: flex; align-items: center; justify-content: space-between; height: 64px; }
        .brand { display: inline-flex; align-items: center; gap: 8px; color: var(--ink); text-decoration: none; }
        .brand img { height: 32px; width: auto; display: block; }
        .brand-fallback { font-weight: 800; font-size: 18px; letter-spacing: 0.04em; }
        .nav-links { display: none; gap: 28px; font-size: 14px; color: #475569; }
        .nav-links a:hover { color: var(--primary); }
        @media (min-width: 900px) { .nav-links { display: flex; } }
        .header-cta { display: flex; align-items: center; gap: 12px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px 16px; border-radius: 9px; font-size: 14px; font-weight: 500; cursor: pointer; transition: transform .12s ease, box-shadow .12s ease, background .12s ease; border: 1px solid transparent; white-space: nowrap; }
        .btn-primary { background: var(--primary); color: #fff; box-shadow: 0 6px 16px rgba(37, 99, 235, 0.22); }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .btn-ghost { color: var(--ink); }
        .btn-ghost:hover { background: rgba(15, 23, 42, 0.04); }
        .btn-outline { border-color: rgba(15, 23, 42, 0.16); background: #fff; color: var(--ink); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }

        /* === Hero === */
        .hero {
            position: relative;
            padding: 64px 0 96px;
            background-image: url('/assets/hero-bg.png');
            background-size: cover;
            background-position: center top;
            background-repeat: no-repeat;
        }
        .hero::before {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0) 0%, rgba(248, 250, 252, 0.6) 80%, var(--bg) 100%);
            pointer-events: none;
        }
        .hero-grid { position: relative; display: grid; grid-template-columns: 1fr; gap: 56px; align-items: center; }
        @media (min-width: 980px) { .hero-grid { grid-template-columns: 1.05fr 0.95fr; gap: 48px; } }
        .hero h1 { font-size: clamp(32px, 4vw, 48px); line-height: 1.12; font-weight: 800; margin: 0 0 20px; letter-spacing: -0.02em; }
        .hero h1 .accent { color: var(--primary); display: block; }
        .hero p.lede { font-size: 16px; color: #475569; max-width: 540px; margin: 0 0 28px; }
        .hero-ctas { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 28px; }
        .hero-ctas .btn { padding: 12px 22px; font-size: 15px; }
        .hero-chips { display: flex; flex-wrap: wrap; gap: 18px; font-size: 13px; color: #475569; }
        .hero-chip { display: inline-flex; align-items: center; gap: 6px; }
        .hero-chip svg { color: var(--success); }

        /* === Hero cards === */
        .hero-cards { display: grid; grid-template-columns: 1fr; gap: 20px; }
        @media (min-width: 560px) { .hero-cards { grid-template-columns: 1fr 1fr; } }
        .card { background: #fff; border: 1px solid rgba(15, 23, 42, 0.06); border-radius: 16px; padding: 22px; box-shadow: 0 16px 40px -16px rgba(15, 23, 42, 0.12), 0 1px 2px rgba(15, 23, 42, 0.04); }
        .flow-step { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; }
        .flow-step + .flow-step { border-top: 1px dashed rgba(15, 23, 42, 0.06); }
        .flow-ic { width: 36px; height: 36px; border-radius: 10px; background: #EFF4FF; display: inline-flex; align-items: center; justify-content: center; color: var(--primary); flex-shrink: 0; }
        .flow-text .t { font-weight: 600; font-size: 14px; }
        .flow-text .d { font-size: 12px; color: var(--muted); margin-top: 2px; }
        .pay-card { position: relative; overflow: hidden; }
        .pay-badge { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #065F46; background: var(--success-bg); border-radius: 999px; padding: 5px 12px; }
        .pay-amount { display: flex; align-items: baseline; gap: 8px; margin: 18px 0 14px; }
        .pay-amount .num { font-size: 26px; font-weight: 800; color: var(--primary); }
        .pay-amount .label { font-size: 13px; color: var(--muted); }
        .pay-meta { font-size: 13px; }
        .pay-meta .row { display: flex; justify-content: space-between; padding: 6px 0; border-top: 1px solid rgba(15, 23, 42, 0.05); }
        .pay-meta .row:first-child { border-top: none; }
        .pay-meta .k { color: var(--muted); }
        .pay-meta .v { color: var(--ink); font-weight: 500; }
        .pay-meta .v.green { color: var(--success); }
        .pay-meta .v.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
        /* === Generic Section === */
        section.sec { padding: 80px 0; }
        section.sec-tight { padding: 64px 0; }
        .section-head { text-align: center; margin-bottom: 48px; }
        .section-head h2 { font-size: clamp(26px, 3vw, 34px); font-weight: 700; margin: 0 0 8px; letter-spacing: -0.01em; }
        .section-head p { color: var(--muted); margin: 0; font-size: 15px; }

        /* === 5-col grids === */
        .grid-5 { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 20px; }
        .feat-card { background: #fff; border: 1px solid rgba(15, 23, 42, 0.06); border-radius: 14px; padding: 22px; transition: transform .15s ease, box-shadow .15s ease; }
        .feat-card:hover { transform: translateY(-4px); box-shadow: 0 20px 36px -20px rgba(15, 23, 42, 0.14); }
        .feat-ic { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 14px; }
        .feat-ic.green { background: #DCFCE7; color: #15803D; }
        .feat-ic.blue  { background: #DBEAFE; color: #1D4ED8; }
        .feat-ic.cyan  { background: #CFFAFE; color: #0891B2; }
        .feat-ic.orange{ background: #FFEDD5; color: #C2410C; }
        .feat-ic.purple{ background: #EDE9FE; color: #6D28D9; }
        .feat-ic.indigo{ background: #E0E7FF; color: #4338CA; }
        .feat-card h3 { font-size: 16px; font-weight: 600; margin: 0 0 6px; }
        .feat-card p { font-size: 13px; color: var(--muted); margin: 0; line-height: 1.6; }

        /* === Why grid: 3x2 small cards === */
        .why-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .why-card { background: #fff; border: 1px solid rgba(15, 23, 42, 0.06); border-radius: 14px; padding: 22px; display: flex; gap: 14px; }
        .why-ic { width: 36px; height: 36px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; background: #EFF4FF; color: var(--primary); }
        .why-card h4 { margin: 0 0 4px; font-size: 15px; font-weight: 600; }
        .why-card p { margin: 0; font-size: 13px; color: var(--muted); }

        /* === Steps row with arrows === */
        .steps { display: grid; grid-template-columns: 1fr; gap: 16px; align-items: stretch; }
        @media (min-width: 760px) { .steps { grid-template-columns: 1fr auto 1fr auto 1fr auto 1fr auto 1fr; gap: 8px; } }
        .step-card { background: #fff; border: 1px solid rgba(15, 23, 42, 0.06); border-radius: 14px; padding: 22px 18px; text-align: center; position: relative; min-height: 200px; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; }
        .step-num { position: absolute; top: -14px; left: 50%; transform: translateX(-50%); width: 28px; height: 28px; border-radius: 50%; background: var(--primary); color: #fff; font-weight: 700; font-size: 13px; display: inline-flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.28); }
        .step-ic { width: 48px; height: 48px; border-radius: 12px; background: #EFF4FF; color: var(--primary); display: inline-flex; align-items: center; justify-content: center; margin: 18px auto 12px; }
        .step-card h4 { margin: 0 0 8px; font-size: 15px; font-weight: 600; }
        .step-card p { margin: 0; font-size: 12px; color: var(--muted); line-height: 1.6; }
        .step-arrow { color: #CBD5E1; align-self: center; display: none; }
        @media (min-width: 760px) { .step-arrow { display: inline-flex; } }
        .step-card.usdt .step-ic { background: linear-gradient(135deg, #2BBC8A, #1FA67A); color: #fff; }

        /* === Industry block === */
        .ind-tabs { display: flex; gap: 24px; justify-content: center; margin-bottom: 28px; font-size: 14px; color: var(--muted); flex-wrap: wrap; }
        .ind-tab { padding: 6px 0; border-bottom: 2px solid transparent; cursor: pointer; transition: color .15s ease, border-color .15s ease; }
        .ind-tab.active { color: var(--primary); border-bottom-color: var(--primary); font-weight: 600; }
        .ind-grid { display: grid; grid-template-columns: 1fr; gap: 32px; align-items: stretch; }
        @media (min-width: 900px) { .ind-grid { grid-template-columns: 1fr 1.2fr; } }
        .ind-illust { background: #F8FAFC; border: 1px solid rgba(15, 23, 42, 0.05); border-radius: 16px; padding: 24px; display: flex; flex-direction: column; gap: 18px; align-items: center; justify-content: center; min-height: 320px; }
        .ind-illust .badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px; background: #EFF4FF; color: var(--primary); font-size: 13px; font-weight: 600; }
        .ind-illust .cart-mock { width: 100%; max-width: 360px; }
        .ind-content h3 { margin: 0 0 16px; font-size: 20px; font-weight: 700; }
        .ind-checks { list-style: none; padding: 0; margin: 0 0 20px; display: flex; flex-direction: column; gap: 8px; font-size: 14px; }
        .ind-checks li { display: flex; align-items: center; gap: 8px; color: #334155; }
        .ind-checks svg { color: var(--success); flex-shrink: 0; }
        /* === Code window (macOS-style) === */
        .code-window {
            background: #0B1729;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 24px 56px -24px rgba(11, 23, 41, 0.45);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
            color: #E5E9F0;
            border: 1px solid rgba(255,255,255,0.04);
        }
        .code-window .titlebar {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }
        .code-window .traffic { display: inline-flex; gap: 8px; }
        .code-window .traffic span { width: 12px; height: 12px; border-radius: 50%; display: inline-block; }
        .code-window .traffic .r { background: #FF5F57; }
        .code-window .traffic .y { background: #FEBC2E; }
        .code-window .traffic .g { background: #28C840; }
        .code-window .tabs { display: inline-flex; gap: 6px; align-items: center; }
        .code-window .tab {
            font-size: 11px; font-weight: 700; letter-spacing: 0.12em;
            color: #5B6B85; cursor: pointer;
            padding: 6px 14px; border-radius: 8px;
            text-transform: uppercase;
            background: transparent;
            transition: color .15s ease, background .15s ease;
        }
        .code-window .tab:hover { color: #93A4C2; }
        .code-window .tab.active { background: #18345E; color: #FFFFFF; }
        .code-window .copy-btn {
            margin-left: 8px;
            border: 1px solid #233553;
            color: #93A4C2; background: transparent;
            padding: 6px 14px; border-radius: 8px;
            font-size: 11px; font-weight: 700; letter-spacing: 0.12em;
            cursor: pointer; text-transform: uppercase;
            transition: border-color .15s ease, color .15s ease;
        }
        .code-window .copy-btn:hover { border-color: #2F4A75; color: #DCE5F5; }
        .code-window pre {
            margin: 0; padding: 22px 26px;
            font-size: 13.5px; line-height: 1.85;
            overflow-x: auto; white-space: pre;
        }
        .code-window .c-cmd  { color: #FF7B72; font-weight: 700; }
        .code-window .c-flag { color: #FF7B72; }
        .code-window .c-url  { color: #7EE787; }
        .code-window .c-str  { color: #FFA657; }
        .code-window .c-key  { color: #79C0FF; }
        .code-window .c-jstr { color: #A5D6FF; }
        .code-window .c-num  { color: #FFE066; }

        /* === Pricing === */
        .pricing-grid { display: grid; grid-template-columns: 1fr; gap: 22px; }
        @media (min-width: 820px) { .pricing-grid { grid-template-columns: repeat(3, 1fr); } }
        .price-card { position: relative; background: #fff; border: 1px solid rgba(15, 23, 42, 0.08); border-radius: 16px; padding: 32px 26px; display: flex; flex-direction: column; gap: 18px; }
        .price-card.featured { border-color: var(--primary); box-shadow: 0 20px 60px -28px rgba(37, 99, 235, 0.45); transform: translateY(-4px); }
        .price-badge { position: absolute; top: -14px; left: 50%; transform: translateX(-50%); background: var(--primary); color: #fff; font-size: 12px; font-weight: 600; padding: 5px 14px; border-radius: 999px; white-space: nowrap; }
        .price-name { font-size: 13px; font-weight: 600; letter-spacing: 0.08em; color: var(--muted); text-transform: uppercase; }
        .price-amount { font-size: 40px; font-weight: 800; letter-spacing: -0.02em; }
        .price-amount .per { font-size: 14px; font-weight: 500; color: var(--muted); margin-left: 4px; }
        .price-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; font-size: 14px; color: #334155; }
        .price-list li { display: flex; align-items: center; gap: 8px; }
        .price-list svg.ok { color: var(--success); }
        .price-list svg.no { color: #CBD5E1; }
        .price-card .btn { margin-top: auto; padding: 12px 22px; }

        /* === CTA dark band (image background) === */
        .cta-band {
            color: #fff;
            padding: 72px 0;
            position: relative;
            overflow: hidden;
            background-image: url('/assets/cta-bg.png');
            background-size: cover;
            background-position: right center;
            background-repeat: no-repeat;
        }
        .cta-band::before {
            content: '';
            position: absolute; inset: 0;
            background: linear-gradient(90deg, rgba(20, 78, 209, 0.85) 0%, rgba(20, 78, 209, 0.55) 45%, rgba(20, 78, 209, 0) 70%);
            pointer-events: none;
        }
        .cta-grid { display: grid; grid-template-columns: 1fr; gap: 24px; align-items: center; position: relative; z-index: 1; min-height: 200px; }
        @media (min-width: 820px) { .cta-grid { grid-template-columns: 1.5fr 1fr; } }
        .cta-band h2 { font-size: clamp(24px, 3vw, 30px); margin: 0 0 8px; font-weight: 700; text-shadow: 0 1px 2px rgba(0,0,0,0.15); }
        .cta-band p { margin: 0 0 22px; color: rgba(255,255,255,0.92); font-size: 15px; }
        .cta-band .btn-primary { background: #fff; color: var(--primary); box-shadow: 0 12px 24px rgba(0,0,0,0.2); }
        .cta-band .btn-primary:hover { background: #F1F5F9; color: var(--primary-dark); }
        .cta-band .btn-outline { background: transparent; color: #fff; border-color: rgba(255,255,255,0.5); }
        .cta-band .btn-outline:hover { background: rgba(255,255,255,0.1); border-color: #fff; }

        /* === Footer === */
        footer.site-footer { background: #FFFFFF; border-top: 1px solid rgba(15, 23, 42, 0.06); padding: 24px 0; font-size: 13px; color: var(--muted); }
        footer.site-footer .row { display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; }
        footer.site-footer a:hover { color: var(--primary); }
        .footer-links { display: flex; gap: 18px; flex-wrap: wrap; }
    </style>
</head>
<body>

<!-- =========================
     Header
     ========================= -->
<header class="site-header">
    <div class="container-x row">
        <a href="/" class="brand" aria-label="<?php echo htmlspecialchars($site_name); ?>">
            <?php if (!empty($site_logo)): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" decoding="async" fetchpriority="high">
            <?php else: ?>
                <span class="brand-fallback"><?php echo htmlspecialchars(strtoupper($site_name)); ?></span>
            <?php endif; ?>
        </a>
        <nav class="nav-links" aria-label="primary">
            <a href="#features"><?php echo __('nav.features'); ?></a>
            <a href="#cases"><?php echo $lang === 'en' ? 'Use cases' : ($lang === 'ja' ? '活用シーン' : ($lang === 'zh-tw' ? '適用場景' : '适用场景')); ?></a>
            <a href="#flow"><?php echo $lang === 'en' ? 'Integration' : ($lang === 'ja' ? '導入フロー' : ($lang === 'zh-tw' ? '接入流程' : '接入流程')); ?></a>
            <a href="#pricing"><?php echo __('nav.pricing'); ?></a>
            <a href="/doc.php"><?php echo __('nav.docs'); ?></a>
            <a href="#legal"><?php echo $lang === 'en' ? 'Compliance' : ($lang === 'ja' ? 'コンプライアンス' : ($lang === 'zh-tw' ? '法律合規' : '法律合规')); ?></a>
        </nav>
        <div class="header-cta">
            <?php include __DIR__ . '/includes/lang_switcher.php'; ?>
            <a href="/login.php" class="btn btn-ghost"><?php echo __('nav.login'); ?></a>
            <a href="/register.php" class="btn btn-primary"><?php echo __('nav.register'); ?></a>
        </div>
    </div>
</header>

<!-- =========================
     Hero
     ========================= -->
<section class="hero">
    <div class="container-x hero-grid">
        <div class="hero-text">
            <h1>
                <?php echo $lang === 'en' ? 'Global USDT Payment API,' : ($lang === 'ja' ? 'グローバル USDT 入金 API、' : ($lang === 'zh-tw' ? '全球 USDT 收款 API，' : '全球 USDT 收款 API，')); ?>
                <span class="accent"><?php echo $lang === 'en' ? 'funds direct to your wallet.' : ($lang === 'ja' ? '資金は直接ウォレットへ。' : ($lang === 'zh-tw' ? '資金直達你的錢包' : '资金直达你的钱包')); ?></span>
            </h1>
            <p class="lede">
                <?php echo $lang === 'en'
                    ? 'A stable USDT payment endpoint for cross-border merchants, e-commerce, digital services, and global enterprises. Once your customer pays, funds skip every platform escrow and land in your own on-chain wallet directly.'
                    : ($lang === 'ja' ? '越境貿易・越境 EC・デジタルサービス・グローバル企業向けの安定した USDT 入金エンドポイント。お客様の決済完了後、資金はプラットフォームを経由せず、お客様自身のオンチェーンウォレットへ直接届きます。'
                    : ($lang === 'zh-tw' ? '為跨境貿易、跨境電商、數位服務商與全球企業提供穩定的 USDT 收款接口。客戶支付 USDT 後，資金不經過平台託管，直接進入你的鏈上錢包。'
                    : '为跨境贸易、跨境电商、数字服务商和全球企业提供稳定的 USDT 收款接口。客户支付 USDT 后，资金不经过平台托管，直接进入你的链上钱包。')); ?>
            </p>
            <div class="hero-ctas">
                <a class="btn btn-primary" href="/register.php">
                    <?php echo $lang === 'en' ? 'Start integrating API' : ($lang === 'ja' ? '今すぐ API を導入' : ($lang === 'zh-tw' ? '立即接入收款 API' : '立即接入收款 API')); ?>
                </a>
                <a class="btn btn-outline" href="#flow">
                    <?php echo $lang === 'en' ? 'See integration flow' : ($lang === 'ja' ? '導入フローを見る' : ($lang === 'zh-tw' ? '查看接入流程' : '查看接入流程')); ?>
                </a>
            </div>
            <div class="hero-chips">
                <?php
                $chips = $lang === 'en'
                    ? ['Non-custodial', 'Funds direct to wallet', 'Multi-chain support', 'API automation']
                    : ($lang === 'ja' ? ['ノンカストディアル', '資金は直接ウォレットへ', 'マルチチェーン対応', 'API による自動化']
                    : ($lang === 'zh-tw' ? ['非託管模式', '資金直達錢包', '多鏈支援', 'API 自動化']
                    : ['非托管模式', '资金直达钱包', '多链支持', 'API 自动化']));
                foreach ($chips as $c):
                ?>
                    <span class="hero-chip">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php echo htmlspecialchars($c); ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="hero-cards">
            <!-- Flow card -->
            <div class="card">
                <?php
                $flow = $lang === 'en' ? [
                    ['Customer places order', 'Your system creates the order', 'cart'],
                    ['Receivable generated', 'UAPI generates a USDT receive address', 'receipt'],
                    ['Customer pays on-chain', 'Customer sends USDT to the address', 'link'],
                    ['Funds reach your wallet', 'USDT lands in your wallet directly', 'wallet'],
                    ['System webhook callback', 'Your system is notified on success', 'bell'],
                ] : ($lang === 'ja' ? [
                    ['お客様が注文', 'お客様のシステムが注文を作成', 'cart'],
                    ['入金注文を生成', 'UAPI が USDT 受取アドレスを生成', 'receipt'],
                    ['お客様がオンチェーン決済', '受取アドレスへ USDT を送金', 'link'],
                    ['資金がウォレットに着金', 'USDT が直接ウォレットに届きます', 'wallet'],
                    ['Webhook 通知', '決済成功時にシステムへ通知', 'bell'],
                ] : ($lang === 'zh-tw' ? [
                    ['客戶下單', '你的系統建立訂單', 'cart'],
                    ['生成收款訂單', 'UAPI 生成 USDT 收款地址', 'receipt'],
                    ['客戶鏈上支付', '客戶向鏈地址支付 USDT', 'link'],
                    ['資金直達錢包', 'USDT 直接進入你的錢包', 'wallet'],
                    ['系統回呼通知', '支付成功回呼通知你的系統', 'bell'],
                ] : [
                    ['客户下单', '你的系统创建订单', 'cart'],
                    ['生成收款订单', 'UAPI 生成 USDT 收款地址', 'receipt'],
                    ['客户链上支付', '客户向链地址支付 USDT', 'link'],
                    ['资金直达钱包', 'USDT 直接进入你的钱包', 'wallet'],
                    ['系统回调通知', '支付成功回调通知你的系统', 'bell'],
                ]));
                $svg = [
                    'cart'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>',
                    'receipt' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 2v20l3-2 3 2 3-2 3 2 3-2 1 2V2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>',
                    'link'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
                    'wallet'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h16v5"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-6"/><circle cx="17" cy="14" r="1.5"/></svg>',
                    'bell'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
                ];
                foreach ($flow as [$t, $d, $ic]): ?>
                    <div class="flow-step">
                        <span class="flow-ic"><?php echo $svg[$ic]; ?></span>
                        <div class="flow-text">
                            <div class="t"><?php echo htmlspecialchars($t); ?></div>
                            <div class="d"><?php echo htmlspecialchars($d); ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Payment Detected card -->
            <div class="card pay-card">
                <span class="pay-badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Payment Detected
                </span>
                <div class="pay-amount">
                    <span class="num">+ 368.00 USDT</span>
                </div>
                <div class="pay-meta">
                    <div class="row"><span class="k">Network</span><span class="v">TRC20</span></div>
                    <div class="row"><span class="k">Status</span><span class="v green">Confirmed</span></div>
                    <div class="row"><span class="k">To</span><span class="v">Merchant Wallet</span></div>
                    <div class="row"><span class="k">Tx Hash</span><span class="v mono">TRx8d…7a3f</span></div>
                    <div class="row"><span class="k">Time</span><span class="v mono">2026-05-16 15:30:45</span></div>
                    <div class="row"><span class="k">Webhook</span><span class="v">Delivered</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =========================
     谁适合使用 UAPI?
     ========================= -->
<section class="sec" id="cases">
    <div class="container-x">
        <div class="section-head">
            <h2><?php echo $lang === 'en' ? 'Who is UAPI built for?' : ($lang === 'ja' ? 'UAPI が向いている方' : ($lang === 'zh-tw' ? '誰適合使用 UAPI？' : '谁适合使用 UAPI？')); ?></h2>
            <p><?php echo $lang === 'en' ? 'A fit for any cross-border receivable scenario — built to grow your global business.' : ($lang === 'ja' ? 'あらゆる越境入金シーンに対応し、グローバル事業の成長を支えます。' : ($lang === 'zh-tw' ? '適用於各種跨境收款場景，助力全球業務成長' : '适用于各种跨境收款场景，助力全球业务增长')); ?></p>
        </div>
        <div class="grid-5">
            <?php
            $cases = $lang === 'en' ? [
                ['Cross-border trade',   'Fits external trade, B2B trade, overseas client settlement — fast and safe.',           'green',  'bag'],
                ['E-commerce / DTC',     'Plugs into Shopify, WooCommerce; payment success auto-confirms and ships faster.',     'blue',   'cart'],
                ['Digital products / SaaS','Software services, memberships, online courses for users worldwide.',                'cyan',   'clock'],
                ['Agency / wholesale',   'Multi-customer, multi-order management; batch receivables and reconciliation.',         'orange', 'users'],
                ['Web3 / crypto-native', 'Crypto-native businesses; multi-chain payments and on-chain confirmation alerts.',      'indigo', 'globe'],
            ] : ($lang === 'ja' ? [
                ['越境貿易',         '外貿・B2B 取引・海外顧客の決済に最適。迅速かつ安全に入金を完了。', 'green',  'bag'],
                ['越境 EC / 自社サイト', 'Shopify・WooCommerce 等に対応。決済成功で自動回送し、発送をスピードアップ。', 'blue',   'cart'],
                ['デジタル製品 / SaaS', 'ソフトウェア、会員制、オンライン講座等、グローバルユーザー向けのデジタル業務に。', 'cyan',   'clock'],
                ['代理販売 / 卸',     '多顧客・多注文管理に対応。一括入金照合と自動消込が可能。',     'orange', 'users'],
                ['Web3 / 暗号資産系',  '暗号資産ネイティブな事業者向け。マルチチェーン入金とオンチェーン確認通知。', 'indigo', 'globe'],
            ] : ($lang === 'zh-tw' ? [
                ['跨境貿易',         '適合外貿、B2B 貿易、海外客戶結算，快速安全完成收款。', 'green',  'bag'],
                ['跨境電商 / 獨立站', '適配 Shopify、WooCommerce 等平台，支付成功自動回呼，發貨流程更高效。', 'blue',   'cart'],
                ['數位產品 / SaaS',  '適合軟體服務、會員訂閱、線上課程等全球用戶的數位化業務。', 'cyan',   'clock'],
                ['代理分銷 / 批發業務','多客戶、多訂單管理，支援批量收款、訂單追蹤與財務對帳。',  'orange', 'users'],
                ['Web3 / 加密友好業務','適合加密原生業務，支援多鏈收款、鏈上確認與自動化通知。', 'indigo', 'globe'],
            ] : [
                ['跨境贸易',          '适合外贸、B2B 贸易、海外客户结算，快速安全地完成收款。', 'green',  'bag'],
                ['跨境电商 / 独立站',  '适配 Shopify、WooCommerce 等平台，支付成功自动回调，发货流程更高效。', 'blue',   'cart'],
                ['数字产品 / SaaS',   '适合软件服务、会员订阅、在线课程等全球用户的数字化业务。', 'cyan',   'clock'],
                ['代理分销 / 批发业务','多客户、多订单管理，支持批量收款、订单追踪与财务对账。',   'orange', 'users'],
                ['Web3 / 加密友好业务','适合加密原生业务，支持多链收款、链上确认与自动化通知。', 'indigo', 'globe'],
            ]));
            $caseSvg = [
                'bag'   => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>',
                'cart'  => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>',
                'clock' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
                'users' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
                'globe' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>',
            ];
            foreach ($cases as [$t, $d, $color, $ic]): ?>
                <div class="feat-card">
                    <span class="feat-ic <?php echo $color; ?>"><?php echo $caseSvg[$ic]; ?></span>
                    <h3><?php echo htmlspecialchars($t); ?></h3>
                    <p><?php echo htmlspecialchars($d); ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================
     为什么选择 UAPI?
     ========================= -->
<section class="sec" id="features" style="background:#FFFFFF;">
    <div class="container-x">
        <div class="section-head">
            <h2><?php echo $lang === 'en' ? 'Why pick UAPI?' : ($lang === 'ja' ? 'なぜ UAPI なのか？' : ($lang === 'zh-tw' ? '為什麼選擇 UAPI？' : '为什么选择 UAPI？')); ?></h2>
            <p><?php echo $lang === 'en' ? 'Faster, more reliable, and safer global receivables.' : ($lang === 'ja' ? 'より速く、より安定し、より安全なグローバル入金。' : ($lang === 'zh-tw' ? '更快、更穩、更安全的全球收款方式' : '更快、更稳、更安全的全球收款方式')); ?></p>
        </div>
        <div class="why-grid">
            <?php
            $why = $lang === 'en' ? [
                ['Funds direct to merchant wallet','We never custody funds — once paid, USDT arrives at your bound wallet.','shield'],
                ['Automated API receivables',     'Automatically create orders, watch the chain, and call back into your system — no manual reconciliation.','code'],
                ['Major chain coverage',          'TRC20, SOL, EVM and major USDT chains; covers different customer payment habits.','link'],
                ['Real-time notifications',       'Webhook, Telegram, email — pick how you want to be told the moment payment lands.','bell'],
                ['Built for global payers',       'No need for international cards or cross-border banking; fits global and digital businesses.','globe'],
                ['Security & traceability',       'Every on-chain transaction is verifiable; only admins can configure — safe by default.','lock'],
            ] : ($lang === 'ja' ? [
                ['資金は商家ウォレットへ直送','UAPI は資金を預かりません。決済完了後、USDT は連携済みのウォレットへ直接届きます。','shield'],
                ['API による入金自動化',     '注文の自動生成、チェーン監視、業務システムへのコールバックまで自動化。手作業を削減。','code'],
                ['主要チェーン対応',         'TRC20、SOL、EVM 等の主要 USDT チェーンに対応し、多様な決済習慣をカバー。','link'],
                ['リアルタイム通知',         'Webhook、Telegram、メール通知に対応。決済状況をリアルタイムで把握。','bell'],
                ['グローバル決済に最適',     '国際クレジットカードや越境送金が不要。多様な越境決済シーンに対応。','globe'],
                ['セキュリティと追跡性',     'オンチェーン取引はすべて検証可能。管理者のみ設定可能で安全性を担保。','lock'],
            ] : ($lang === 'zh-tw' ? [
                ['資金直達商戶錢包',  '平台不託管資金，客戶支付後 USDT 直接進入你的綁定錢包地址。','shield'],
                ['API 自動化收款',    '自動建立訂單、監聽鏈上支付、回呼業務系統，減少人工對帳。','code'],
                ['支援主流鏈路',      '適合 TRC20、SOL、EVM 等常見 USDT 收款網路，覆蓋不同客戶支付習慣。','link'],
                ['即時通知觸達',      '支援 Webhook、Telegram、郵箱通知，支付動態即時觸達。','bell'],
                ['適合全球客戶付款',  '無需客戶使用本地銀行卡或重複跨境扣款流程，更適合國際訂單和數位業務。','globe'],
                ['安全與可追溯',      '鏈上交易狀態透明可追溯，僅限管理員配置，安全可控。','lock'],
            ] : [
                ['资金直达商户钱包','平台不托管资金，客户支付后 USDT 直接进入你的绑定钱包地址。','shield'],
                ['API 自动化收款',  '自动创建订单、监听链上支付、回调业务系统，减少人工对账。','code'],
                ['支持主流链路',    '适合 TRC20、SOL、EVM 等常见 USDT 收款网络，覆盖不同客户支付习惯。','link'],
                ['实时通知触达',    '支持 Webhook、Telegram、邮箱通知，支付动态实时触达。','bell'],
                ['适合全球客户付款','无需客户使用本地银行卡或重复跨境扣款流程，更适合国际订单和数字业务。','globe'],
                ['安全与可追溯',    '链上交易状态透明可追溯，仅限管理员配置，安全可控。','lock'],
            ]));
            $whySvg = [
                'shield' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></svg>',
                'code'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
                'link'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
                'bell'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>',
                'globe'  => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg>',
                'lock'   => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>',
            ];
            foreach ($why as [$t, $d, $ic]): ?>
                <div class="why-card">
                    <span class="why-ic"><?php echo $whySvg[$ic]; ?></span>
                    <div>
                        <h4><?php echo htmlspecialchars($t); ?></h4>
                        <p><?php echo htmlspecialchars($d); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================
     5 分钟接入
     ========================= -->
<section class="sec" id="flow">
    <div class="container-x">
        <div class="section-head">
            <h2><?php echo $lang === 'en' ? 'Integrate USDT receivables in 5 minutes' : ($lang === 'ja' ? '5 分で USDT 入金を導入' : ($lang === 'zh-tw' ? '5 分鐘接入 USDT 收款' : '5 分钟接入 USDT 收款')); ?></h2>
            <p><?php echo $lang === 'en' ? 'Five simple steps — fast-track to global receivables.' : ($lang === 'ja' ? 'シンプルな 5 ステップでグローバル入金を実現。' : ($lang === 'zh-tw' ? '簡單 5 步，快速接入全球收款能力' : '简单 5 步，快速接入全球收款能力')); ?></p>
        </div>
        <?php
        $steps = $lang === 'en' ? [
            ['Configure your USDT wallet', 'Fill in your USDT receive address — funds always go straight there.', 'wallet'],
            ['Create order via API',       'POST amount, order ID and metadata to create a receivable.',            'code'],
            ['Customer completes payment', 'Customer sends USDT to the address; the on-chain settlement starts.',   'usdt'],
            ['UAPI monitors payment',      'UAPI watches the chain for confirmation.',                              'shield'],
            ['Webhook callback',           'On success, UAPI calls your system to complete the order flow.',         'webhook'],
        ] : ($lang === 'ja' ? [
            ['USDT ウォレットを設定','USDT 受取アドレスを入力。資金は必ずあなたのウォレットへ。', 'wallet'],
            ['API で注文を作成',    '金額・注文 ID 等を送信し、決済注文を作成。',                  'code'],
            ['お客様がオンチェーン決済','受取アドレスに USDT を送金、オンチェーン取引が開始。',  'usdt'],
            ['UAPI が決済状況を監視','UAPI が自動で取引状況を確認します。',                       'shield'],
            ['Webhook で通知',      '決済成功後、Webhook であなたのシステムに通知し、業務を完了。','webhook'],
        ] : ($lang === 'zh-tw' ? [
            ['配置收款錢包',        '填寫你的 USDT 收款地址，資金將直接進入該錢包。',     'wallet'],
            ['透過 API 建立訂單',   '提交金額、訂單號、回呼地址等資訊，建立收款訂單。',    'code'],
            ['客戶完成鏈上支付',    '客戶向地址支付 USDT，鏈上交易開始確認。',           'usdt'],
            ['系統監測支付狀態',    'UAPI 自動監聽鏈上交易狀態，確認支付是否成功。',     'shield'],
            ['Webhook 回呼你的系統','支付成功後自動回呼通知你的系統，完成業務流程。',    'webhook'],
        ] : [
            ['配置收款钱包',        '填写你的 USDT 收款地址，资金将直接进入该钱包。',    'wallet'],
            ['通过 API 创建订单',    '提交金额、订单号、回调地址等信息，创建收款订单。',  'code'],
            ['客户完成链上支付',    '客户向地址支付 USDT，链上交易开始确认。',          'usdt'],
            ['系统监测支付状态',    'UAPI 自动监听链上交易状态，确认支付是否成功。',    'shield'],
            ['Webhook 回调你的系统','支付成功后自动回调通知你的系统，完成业务流程。',   'webhook'],
        ]));
        $stepSvg = [
            'wallet'  => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12V7a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h16v5"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-6"/><circle cx="17" cy="14" r="1.5"/></svg>',
            'code'    => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
            'usdt'    => '<span style="font-weight:800;font-size:22px;line-height:1;">₮</span>',
            'shield'  => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/></svg>',
            'webhook' => '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M8 6h6a4 4 0 0 1 4 4v3"/><path d="m6 9 6 7"/></svg>',
        ];
        $arrow = '<svg class="step-arrow" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
        ?>
        <div class="steps">
            <?php foreach ($steps as $i => [$t, $d, $ic]): ?>
                <div class="step-card<?php echo $ic === 'usdt' ? ' usdt' : ''; ?>">
                    <span class="step-num"><?php echo $i + 1; ?></span>
                    <span class="step-ic"><?php echo $stepSvg[$ic]; ?></span>
                    <h4><?php echo htmlspecialchars($t); ?></h4>
                    <p><?php echo htmlspecialchars($d); ?></p>
                </div>
                <?php if ($i < count($steps) - 1) echo $arrow; ?>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================
     行业解决方案
     ========================= -->
<section class="sec" style="background:#FFFFFF;">
    <div class="container-x">
        <div class="section-head">
            <h2><?php echo $lang === 'en' ? 'Receivable solutions for every vertical' : ($lang === 'ja' ? '業種ごとの入金ソリューション' : ($lang === 'zh-tw' ? '為不同行業提供收款解決方案' : '为不同行业提供收款解决方案')); ?></h2>
            <div class="ind-tabs">
                <?php
                $tabs = $lang === 'en' ? ['Self-operated','Cross-border B2B','Digital / SaaS','DTC store']
                    : ($lang === 'ja' ? ['自社運営','越境 B2B','デジタル / SaaS','DTC ストア']
                    : ($lang === 'zh-tw' ? ['自營商','外貿 / B2B','數位產品 / SaaS','獨立站']
                    : ['自营商','外贸 / B2B','数字产品 / SaaS','独立站']));
                foreach ($tabs as $i => $name): ?>
                    <div class="ind-tab <?php echo $i === 0 ? 'active' : ''; ?>"><?php echo htmlspecialchars($name); ?></div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="ind-grid">
            <div class="ind-illust">
                <span class="badge">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
                    <?php echo $lang === 'en' ? 'Cross-border e-commerce' : ($lang === 'ja' ? '越境 EC' : ($lang === 'zh-tw' ? '跨境電商' : '跨境电商')); ?>
                </span>
                <svg class="cart-mock" viewBox="0 0 360 200" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="0" y="0" width="360" height="200" rx="14" fill="#FFFFFF"/>
                    <rect x="20" y="20" width="200" height="14" rx="4" fill="#E2E8F0"/>
                    <rect x="20" y="46" width="140" height="10" rx="3" fill="#F1F5F9"/>
                    <rect x="20" y="74" width="320" height="46" rx="12" fill="#F8FAFC" stroke="#E2E8F0"/>
                    <text x="34" y="103" font-family="system-ui" font-size="14" font-weight="600" fill="#0F172A">Pay with USDT</text>
                    <text x="34" y="138" font-family="system-ui" font-size="14" fill="#64748B">+ 368.00 USDT</text>
                    <circle cx="318" cy="138" r="20" fill="#2BBC8A"/>
                    <text x="318" y="146" text-anchor="middle" font-family="system-ui" font-size="22" font-weight="800" fill="#FFFFFF">₮</text>
                </svg>
            </div>
            <div class="ind-content">
                <h3><?php echo $lang === 'en' ? 'E-commerce / DTC playbook' : ($lang === 'ja' ? 'EC / DTC 向けプレイブック' : ($lang === 'zh-tw' ? '跨境電商解決方案' : '跨境电商解决方案')); ?></h3>
                <ul class="ind-checks">
                    <?php
                    $checks = $lang === 'en' ? [
                        'Independent store-level receivables',
                        'Automatic USDT order confirmation',
                        'Auto-fulfilment on payment success',
                        'Webhook into your order system',
                        'Lower chargeback & FX risk',
                    ] : ($lang === 'ja' ? [
                        '独自ストアの入金',
                        'USDT 注文の自動確認',
                        '決済成功後の自動出荷',
                        'Webhook で受注システムへ連携',
                        'チャージバックや為替リスクを低減',
                    ] : ($lang === 'zh-tw' ? [
                        '獨立站訂單收款',
                        'USDT 自動確認',
                        '支付成功自動發貨',
                        'Webhook 對接電商系統',
                        '降低拒付與匯損風險',
                    ] : [
                        '独立站订单收款',
                        'USDT 自动确认',
                        '支付成功自动发货',
                        'Webhook 对接电商系统',
                        '降低拒付与汇损风险',
                    ]));
                    foreach ($checks as $c): ?>
                        <li>
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            <?php echo htmlspecialchars($c); ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <div class="code-window">
                    <div class="titlebar">
                        <span class="traffic" aria-hidden="true">
                            <span class="r"></span><span class="y"></span><span class="g"></span>
                        </span>
                        <div class="tabs">
                            <button type="button" class="tab active">cURL</button>
                            <button type="button" class="tab">PHP</button>
                            <button type="button" class="tab">Node.js</button>
                            <button type="button" class="copy-btn"><?php echo $lang === 'en' ? 'Copy' : ($lang === 'ja' ? 'コピー' : ($lang === 'zh-tw' ? '複製' : '复制')); ?></button>
                        </div>
                    </div>
<pre><span class="c-cmd">curl</span> <span class="c-flag">-X</span> <span class="c-cmd">POST</span> <span class="c-url"><?php echo htmlspecialchars($scheme . '://' . $host); ?>/api/v1/order/create.php</span> \
  <span class="c-flag">-H</span> <span class="c-str">"X-API-KEY: sk_live_your_api_key"</span> \
  <span class="c-flag">-H</span> <span class="c-str">"Content-Type: application/json"</span> \
  <span class="c-flag">-d</span> '{
    <span class="c-key">"amount"</span>: <span class="c-num">100.00</span>,
    <span class="c-key">"chain"</span>: <span class="c-jstr">"trc20"</span>,
    <span class="c-key">"merchant_order_id"</span>: <span class="c-jstr">"ORD-001"</span>,
    <span class="c-key">"notify_url"</span>: <span class="c-jstr">"https://yoursite.com/callback"</span>,
    <span class="c-key">"domain"</span>: <span class="c-jstr">"yoursite.com"</span>
  }'</pre>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- =========================
     透明定价
     ========================= -->
<section class="sec" id="pricing">
    <div class="container-x">
        <div class="section-head">
            <h2><?php echo $lang === 'en' ? 'Transparent pricing, pick what fits' : ($lang === 'ja' ? '透明な料金プラン、ニーズに合わせて選択' : ($lang === 'zh-tw' ? '透明定價，按需選擇' : '透明定价，按需选择')); ?></h2>
            <p><?php echo $lang === 'en' ? 'Free to start; upgrade flexibly as your volume grows.' : ($lang === 'ja' ? '無料で開始、業務拡大に応じてフレキシブルにアップグレード。' : ($lang === 'zh-tw' ? '免費開始，隨業務成長靈活升級' : '免费开始，随业务增长灵活升级')); ?></p>
        </div>
        <?php
        $okIcon = '<svg class="ok" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
        $noIcon = '<svg class="no" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg>';
        $plans = $lang === 'en' ? [
            ['FREE',     '$0',    '/mo', false, [
                ['100 API calls / day', true],
                ['API access',          true],
                ['Telegram notify',     true],
                ['Email notify',        false],
                ['Webhook callback',    false],
            ], 'Start free', '/register.php'],
            ['PRO',      '$19.9', '/mo', true,  [
                ['20,000 API calls / day', true],
                ['API access',             true],
                ['Telegram notify',        true],
                ['Email notify',           true],
                ['Webhook callback',       true],
            ], 'Upgrade now', '/upgrade.php'],
            ['BUSINESS', '$29.9', '/mo', false, [
                ['50,000 API calls / day', true],
                ['API access',             true],
                ['Telegram notify',        true],
                ['Email notify',           true],
                ['Webhook callback',       true],
                ['Priority support',       true],
            ], 'Contact sales', '/login.php'],
        ] : ($lang === 'ja' ? [
            ['FREE',     '$0',    '/月', false, [
                ['1 日 100 回 API 呼び出し', true],
                ['API アクセス権限',         true],
                ['Telegram 通知',           true],
                ['メール通知',              false],
                ['Webhook コールバック',     false],
            ], '無料で始める', '/register.php'],
            ['PRO',      '$19.9', '/月', true,  [
                ['1 日 20,000 回 API 呼び出し', true],
                ['API アクセス権限',           true],
                ['Telegram 通知',             true],
                ['メール通知',                true],
                ['Webhook コールバック',       true],
            ], '今すぐアップグレード', '/upgrade.php'],
            ['BUSINESS', '$29.9', '/月', false, [
                ['1 日 50,000 回 API 呼び出し', true],
                ['API アクセス権限',           true],
                ['Telegram 通知',             true],
                ['メール通知',                true],
                ['Webhook コールバック',       true],
                ['優先サポート',              true],
            ], '営業へ連絡', '/login.php'],
        ] : ($lang === 'zh-tw' ? [
            ['FREE',     '$0',    '/月', false, [
                ['100 次 API 呼叫 / 天',     true],
                ['API 存取權限',             true],
                ['Telegram 通知',           true],
                ['郵箱通知',                false],
                ['Webhook 回呼',            false],
            ], '免費開始測試', '/register.php'],
            ['PRO',      '$19.9', '/月', true,  [
                ['20,000 次 API 呼叫 / 天', true],
                ['API 存取權限',           true],
                ['Telegram 通知',         true],
                ['郵箱通知',              true],
                ['Webhook 回呼',          true],
            ], '立即升級', '/upgrade.php'],
            ['BUSINESS', '$29.9', '/月', false, [
                ['50,000 次 API 呼叫 / 天', true],
                ['API 存取權限',           true],
                ['Telegram 通知',         true],
                ['郵箱通知',              true],
                ['Webhook 回呼',          true],
                ['優先支援',              true],
            ], '聯絡商務', '/login.php'],
        ] : [
            ['FREE',     '$0',    '/月', false, [
                ['100 次 API 调用 / 天',     true],
                ['API 访问权限',             true],
                ['Telegram 通知',           true],
                ['邮箱通知',                false],
                ['Webhook 回调',            false],
            ], '免费开始测试', '/register.php'],
            ['PRO',      '$19.9', '/月', true,  [
                ['20,000 次 API 调用 / 天', true],
                ['API 访问权限',           true],
                ['Telegram 通知',         true],
                ['邮箱通知',              true],
                ['Webhook 回调',          true],
            ], '立即升级', '/upgrade.php'],
            ['BUSINESS', '$29.9', '/月', false, [
                ['50,000 次 API 调用 / 天', true],
                ['API 访问权限',           true],
                ['Telegram 通知',         true],
                ['邮箱通知',              true],
                ['Webhook 回调',          true],
                ['优先支持',              true],
            ], '联系商务', '/login.php'],
        ]));
        ?>
        <div class="pricing-grid">
            <?php foreach ($plans as [$name, $amt, $per, $featured, $list, $cta, $href]): ?>
                <div class="price-card<?php echo $featured ? ' featured' : ''; ?>">
                    <?php if ($featured): ?>
                        <span class="price-badge"><?php echo $lang === 'en' ? 'Most popular' : ($lang === 'ja' ? '人気プラン' : ($lang === 'zh-tw' ? '最受歡迎' : '最受欢迎')); ?></span>
                    <?php endif; ?>
                    <div class="price-name"><?php echo htmlspecialchars($name); ?></div>
                    <div class="price-amount"><?php echo htmlspecialchars($amt); ?><span class="per"><?php echo htmlspecialchars($per); ?></span></div>
                    <ul class="price-list">
                        <?php foreach ($list as [$row, $ok]): ?>
                            <li><?php echo $ok ? $okIcon : $noIcon; ?> <?php echo htmlspecialchars($row); ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <a class="btn <?php echo $featured ? 'btn-primary' : 'btn-outline'; ?>" href="<?php echo htmlspecialchars($href); ?>"><?php echo htmlspecialchars($cta); ?></a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- =========================
     CTA dark band
     ========================= -->
<section class="cta-band">
    <div class="container-x cta-grid">
        <div>
            <h2><?php echo $lang === 'en' ? 'Plug into UAPI now — open a new era of global receivables' : ($lang === 'ja' ? '今すぐ UAPI を導入、グローバル入金の新時代へ' : ($lang === 'zh-tw' ? '立即接入 UAPI，開啟全球收款新時代' : '立即接入 UAPI，开启全球收款新时代')); ?></h2>
            <p><?php echo $lang === 'en' ? 'Five minutes to integrate. Let customers worldwide pay you in USDT.' : ($lang === 'ja' ? '5 分で導入完了。世界中のお客様が USDT で気軽に支払えるように。' : ($lang === 'zh-tw' ? '5 分鐘接入，讓全球客戶輕鬆用 USDT 向你付款' : '5 分钟接入，让全球客户轻松用 USDT 向你付款')); ?></p>
            <div style="display:flex;gap:12px;flex-wrap:wrap;">
                <a class="btn btn-primary" href="/register.php"><?php echo $lang === 'en' ? 'Start integrating API' : ($lang === 'ja' ? '今すぐ API を導入' : ($lang === 'zh-tw' ? '立即接入收款 API' : '立即接入收款 API')); ?></a>
                <a class="btn btn-outline" href="/doc.php"><?php echo $lang === 'en' ? 'Read docs' : ($lang === 'ja' ? 'ドキュメントを見る' : ($lang === 'zh-tw' ? '查看開發文件' : '查看开发文档')); ?></a>
            </div>
        </div>
        <div aria-hidden="true"></div>
    </div>
</section>

<!-- =========================
     Footer
     ========================= -->
<footer class="site-footer">
    <div class="container-x row">
        <div class="brand" style="font-size:13px;">
            <?php if (!empty($site_logo)): ?>
                <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" style="height:22px;width:auto;" decoding="async">
            <?php else: ?>
                <span class="brand-fallback" style="font-size:14px;"><?php echo htmlspecialchars(strtoupper($site_name)); ?></span>
            <?php endif; ?>
            <span style="color:var(--muted);">· <?php echo $lang === 'en' ? 'Global USDT Payment API' : ($lang === 'ja' ? 'グローバル USDT 入金 API' : ($lang === 'zh-tw' ? '全球 USDT 收款 API 平台' : '全球 USDT 收款 API 平台')); ?></span>
        </div>
        <div>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?>. <?php echo $lang === 'en' ? 'All rights reserved.' : ($lang === 'ja' ? '無断複製を禁じます。' : ($lang === 'zh-tw' ? '保留所有權利。' : '保留所有权利。')); ?></div>
        <div class="footer-links">
            <a href="/doc.php"><?php echo $lang === 'en' ? 'API docs' : ($lang === 'ja' ? 'API ドキュメント' : ($lang === 'zh-tw' ? 'API 文件' : 'API 文档')); ?></a>
            <a href="/guide.php"><?php echo $lang === 'en' ? 'User guide' : ($lang === 'ja' ? 'ユーザーガイド' : ($lang === 'zh-tw' ? '使用指南' : '用户指南')); ?></a>
        </div>
    </div>
</footer>

</body>
</html>
