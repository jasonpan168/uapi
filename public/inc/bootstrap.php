<?php
// Prevent double loading
if (defined('BOOTSTRAP_LOADED')) {
    return;
}
define('BOOTSTRAP_LOADED', true);

// Start Session immediately
if (session_status() === PHP_SESSION_NONE) {
    // For SEO: relax session.cache_limiter on public, indexable pages
    // so Googlebot doesn't get hit with "Cache-Control: no-store" + the 1981
    // Expires sentinel. Auth-gated pages keep the default 'nocache'.
    $publicSeoPages = [
        '/index.php',
        '/doc.php',
        '/guide.php',
        '/sitemap.xml.php',
        '/robots.txt',
    ];
    // SCRIPT_NAME is reliable under nginx + PHP-FPM; PHP_SELF is empty here.
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === '/' || in_array($script, $publicSeoPages, true)) {
        session_cache_limiter('public');
        session_cache_expire(60); // minutes
    }
    session_start();
}

// Load Configuration
// Assuming public/inc/bootstrap.php -> ../../config/config.php
require_once __DIR__ . '/../../config/config.php';

// Load Core Libraries
require_once __DIR__ . '/../../src/Core/I18n.php';
require_once __DIR__ . '/../../src/Core/Database.php';

// Initialize I18n (detects lang from GET/Session/Cookie)
I18n::init();

// Initialize Flash Messages (from previous task, just in case needed)
require_once __DIR__ . '/../../src/Core/Http.php';
