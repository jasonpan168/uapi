<?php
/**
 * Dynamic sitemap generator (self-hosted deployment).
 *
 * This file lives at /sitemap.xml.php but search engines look for /sitemap.xml.
 * Map the canonical URL to this script using ONE of the following:
 *
 *   nginx:
 *     location = /sitemap.xml {
 *         rewrite ^/sitemap\.xml$ /sitemap.xml.php last;
 *     }
 *
 *   OpenLiteSpeed (vhost rewrite rules, or .htaccess if AllowOverride):
 *     RewriteEngine On
 *     RewriteRule ^sitemap\.xml$ /sitemap.xml.php [L]
 *
 *   Or a symlink at the docroot:
 *     ln -s sitemap.xml.php sitemap.xml
 *
 * No auth, no DB, no i18n — must be reachable by Googlebot and anonymous users.
 */

ini_set('display_errors', '0');
error_reporting(0);

header('Content-Type: application/xml; charset=utf-8');

$lastmod = date('Y-m-d');

// Build the base URL from the actual request host at runtime.
$is_https = ((!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'));
$scheme = $is_https ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$base = $scheme . '://' . $host;

$urls = [
    ['loc' => $base . '/',             'priority' => '1.0', 'changefreq' => 'daily'],
    ['loc' => $base . '/doc.php',      'priority' => '0.9', 'changefreq' => 'weekly'],
    ['loc' => $base . '/guide.php',    'priority' => '0.8', 'changefreq' => 'weekly'],
    ['loc' => $base . '/register.php', 'priority' => '0.6', 'changefreq' => 'monthly'],
    ['loc' => $base . '/login.php',    'priority' => '0.3', 'changefreq' => 'monthly'],
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
    echo '    <lastmod>' . $lastmod . "</lastmod>\n";
    echo '    <changefreq>' . $u['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $u['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo '</urlset>' . "\n";
