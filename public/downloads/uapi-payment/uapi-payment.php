<?php
/**
 * Plugin Name: UAPI Payment Gateway
 * Plugin URI: https://github.com/jasonpan168/uapi
 * Description: UAPI 支付插件，支持付费文章、付费下载、付费商品、收款链接和异步回调。
 * Version: 2.3.8
 * Author: UAPI Team
 * Author URI: https://github.com/jasonpan168/uapi
 * Text Domain: uapi-payment
 */

if (!defined('ABSPATH')) {
    exit;
}

final class UAPIPaymentPlugin
{
    private const VERSION = '2.3.8';
    private const DB_VERSION = '2.3.8';
    private const DB_VERSION_OPTION = 'uapi_payment_db_version';
    private const OPTION_KEY = 'uapi_payment_settings';
    private const NONCE_ACTION = 'uapi_payment_nonce';
    private const ADMIN_ACTION_NONCE = 'uapi_payment_admin_action';

    public static function init(): void
    {
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);

        add_action('init', [__CLASS__, 'maybeUpgradeDatabase'], 1);
        add_action('init', [__CLASS__, 'ensureVisitorCookie']);
        add_action('init', [__CLASS__, 'registerShortcodes']);
        add_action('init', [__CLASS__, 'registerRewrite']);
        add_filter('query_vars', [__CLASS__, 'registerQueryVars']);
        add_action('template_redirect', [__CLASS__, 'handlePayLinkTemplate']);

        add_action('admin_menu', [__CLASS__, 'registerAdminMenu']);
        add_action('admin_init', [__CLASS__, 'registerSettings']);
        add_action('admin_init', [__CLASS__, 'handleAdminActions']);

        add_action('add_meta_boxes', [__CLASS__, 'addPostMetaBox']);
        add_action('save_post', [__CLASS__, 'savePostMeta']);
        add_filter('the_content', [__CLASS__, 'filterPaidPostContent'], 20);

        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueueAssets']);

        add_action('wp_ajax_uapi_payment_create_order', [__CLASS__, 'ajaxCreateOrder']);
        add_action('wp_ajax_nopriv_uapi_payment_create_order', [__CLASS__, 'ajaxCreateOrder']);
        add_action('wp_ajax_uapi_payment_check_order', [__CLASS__, 'ajaxCheckOrder']);
        add_action('wp_ajax_nopriv_uapi_payment_check_order', [__CLASS__, 'ajaxCheckOrder']);
        add_action('wp_ajax_uapi_payment_cancel_order', [__CLASS__, 'ajaxCancelOrder']);
        add_action('wp_ajax_nopriv_uapi_payment_cancel_order', [__CLASS__, 'ajaxCancelOrder']);

        add_action('rest_api_init', [__CLASS__, 'registerRestRoutes']);
    }

    public static function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $orders = self::tableName();
        $ordersSql = "CREATE TABLE {$orders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            merchant_order_id VARCHAR(100) NOT NULL,
            order_no VARCHAR(64) DEFAULT '',
            pay_token VARCHAR(64) DEFAULT '',
            object_type VARCHAR(30) NOT NULL,
            object_key VARCHAR(191) NOT NULL,
            amount DECIMAL(18,6) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'USDT',
            chain VARCHAR(30) NOT NULL DEFAULT 'bsc',
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            payment_url TEXT,
            tx_hash VARCHAR(120) DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            visitor_key VARCHAR(64) DEFAULT '',
            customer_email VARCHAR(190) DEFAULT '',
            merchant_scope VARCHAR(64) NOT NULL DEFAULT '',
            meta LONGTEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            paid_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY merchant_order_id (merchant_order_id),
            KEY merchant_scope (merchant_scope),
            KEY order_no (order_no),
            KEY object_key (object_key),
            KEY object_type (object_type),
            KEY status (status),
            KEY user_id (user_id),
            KEY visitor_key (visitor_key)
        ) {$charset};";

        $links = self::tableLinksName();
        $linksSql = "CREATE TABLE {$links} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(120) NOT NULL,
            description TEXT,
            amount DECIMAL(18,6) NOT NULL DEFAULT 0,
            currency VARCHAR(10) NOT NULL DEFAULT 'USDT',
            chain VARCHAR(30) NOT NULL DEFAULT 'bsc',
            slug VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($ordersSql);
        dbDelta($linksSql);

        $defaults = self::defaultSettings();
        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        update_option(self::OPTION_KEY, array_merge($defaults, $settings));
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);

        flush_rewrite_rules(false);
    }

    private static function tableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'uapi_orders';
    }

    private static function tableLinksName(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'uapi_payment_links';
    }

    private static function defaultSettings(): array
    {
        return [
            'api_base' => '',
            'api_key' => '',
            'default_chain' => 'bsc',
            'default_currency' => 'USDT',
            'strict_webhook_verify' => 1,
            'button_text' => '立即支付解锁',
        ];
    }

    private static function settings(): array
    {
        $settings = get_option(self::OPTION_KEY, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        return array_merge(self::defaultSettings(), $settings);
    }

    public static function maybeUpgradeDatabase(): void
    {
        $installed = (string)get_option(self::DB_VERSION_OPTION, '');
        if ($installed === self::DB_VERSION) {
            return;
        }
        self::activate();
        self::backfillBlankScopeToCurrent();
    }

    private static function merchantScopeFromSettings(array $settings): string
    {
        $base = strtolower(trim((string)($settings['api_base'] ?? '')));
        $key = trim((string)($settings['api_key'] ?? ''));
        if ($base === '' || $key === '') {
            return '';
        }
        return substr(hash('sha256', $base . '|' . $key), 0, 32);
    }

    private static function currentMerchantScope(): string
    {
        return self::merchantScopeFromSettings(self::settings());
    }

    private static function backfillBlankScopeToCurrent(): void
    {
        $scope = self::currentMerchantScope();
        if ($scope === '') {
            return;
        }
        global $wpdb;
        $table = self::tableName();
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET merchant_scope=%s WHERE merchant_scope='' OR merchant_scope IS NULL",
                $scope
            )
        );
    }

    public static function registerRewrite(): void
    {
        add_rewrite_rule('^uapi-pay/([^/]+)/?$', 'index.php?uapi_pay_link=$matches[1]', 'top');
    }

    public static function registerQueryVars(array $vars): array
    {
        $vars[] = 'uapi_pay_link';
        return $vars;
    }

    public static function ensureVisitorCookie(): void
    {
        if (headers_sent()) {
            return;
        }
        if (empty($_COOKIE['uapi_vid'])) {
            $vid = wp_generate_password(32, false, false);
            setcookie('uapi_vid', $vid, time() + 86400 * 365, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
            $_COOKIE['uapi_vid'] = $vid;
        }
    }

    private static function getVisitorKey(): string
    {
        $vid = isset($_COOKIE['uapi_vid']) ? sanitize_text_field((string)$_COOKIE['uapi_vid']) : '';
        if ($vid === '') {
            $vid = wp_generate_password(32, false, false);
            $_COOKIE['uapi_vid'] = $vid;
        }
        return substr($vid, 0, 64);
    }

    private static function endpoint(string $suffix): string
    {
        $base = rtrim((string)(self::settings()['api_base'] ?? ''), '/');
        if ($base === '') {
            return '';
        }
        return $base . '/' . ltrim($suffix, '/');
    }

    private static function checkAjaxNonceSoft(): bool
    {
        $nonce = isset($_REQUEST['nonce']) ? (string)$_REQUEST['nonce'] : '';
        if ($nonce === '') {
            return false;
        }
        return wp_verify_nonce($nonce, self::NONCE_ACTION) === 1 || wp_verify_nonce($nonce, self::NONCE_ACTION) === 2;
    }

    private static function explorerTxUrl(string $chain, string $txHash): string
    {
        $txHash = trim($txHash);
        if ($txHash === '' || !preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
            return '';
        }
        $chain = strtolower(trim($chain));
        if ($chain === 'arb') $chain = 'arbitrum';
        $map = [
            'bsc' => 'https://bscscan.com/tx/',
            'arbitrum' => 'https://arbiscan.io/tx/',
        ];
        $base = $map[$chain] ?? '';
        return $base === '' ? '' : ($base . $txHash);
    }

    private static function cleanHostFromUrl(string $url): string
    {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $host = strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host);
        return $host;
    }

    private static function makeObjectKey(string $type, string $key): string
    {
        $type = preg_replace('/[^a-z0-9_\-]/i', '', strtolower($type));
        $key = sanitize_text_field($key);
        return substr($type . ':' . $key, 0, 191);
    }

    private static function hasAccess(string $objectKey): bool
    {
        global $wpdb;
        $uid = get_current_user_id();
        $vid = self::getVisitorKey();
        $table = self::tableName();
        $scope = self::currentMerchantScope();
        if ($scope === '') {
            return false;
        }

        if ($uid > 0) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE merchant_scope = %s AND object_key = %s AND status = 'paid' AND (user_id = %d OR visitor_key = %s)",
                $scope,
                $objectKey,
                $uid,
                $vid
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE merchant_scope = %s AND object_key = %s AND status = 'paid' AND visitor_key = %s",
                $scope,
                $objectKey,
                $vid
            );
        }
        return ((int)$wpdb->get_var($sql)) > 0;
    }

    private static function createButtonHtml(array $args): string
    {
        $amount = number_format((float)$args['amount'], 2, '.', '');
        $currency = strtoupper((string)$args['currency']);
        $chain = strtolower((string)$args['chain']);
        $button = esc_html((string)$args['button']);
        $title = esc_attr((string)$args['title']);
        $objectType = esc_attr((string)$args['object_type']);
        $objectKey = esc_attr((string)$args['object_key']);
        $successText = esc_attr((string)($args['success_text'] ?? '支付成功，已解锁'));
        $extra = '';
        if (!empty($args['download_id'])) {
            $extra .= ' data-download-id="' . esc_attr((string)$args['download_id']) . '"';
        }

        return '<button type="button" class="uapi-pay-btn" data-amount="' . esc_attr($amount) . '" data-currency="' . esc_attr($currency) . '" data-chain="' . esc_attr($chain) . '" data-object-type="' . $objectType . '" data-object-key="' . $objectKey . '" data-title="' . $title . '" data-success-text="' . $successText . '"' . $extra . '>'
            . $button . '（' . esc_html($amount . ' ' . $currency) . '）</button>';
    }

    public static function registerShortcodes(): void
    {
        add_shortcode('uapi_pay', [__CLASS__, 'shortcodePaywall']);
        add_shortcode('uapi_paywall', [__CLASS__, 'shortcodePaywall']);
        add_shortcode('uapi_download', [__CLASS__, 'shortcodeDownload']);
        add_shortcode('uapi_product', [__CLASS__, 'shortcodeProduct']);
    }

    public static function shortcodePaywall($atts, $content = null, $tag = ''): string
    {
        $settings = self::settings();
        $atts = shortcode_atts([
            'amount' => '1.00',
            'btn_text' => (string)$settings['button_text'],
            'currency' => (string)$settings['default_currency'],
            'chain' => (string)$settings['default_chain'],
            'title' => '内容解锁',
            'key' => '',
            'dual' => '',
            'currencies' => 'USDT,USDC',
        ], $atts, 'uapi_paywall');

        $key = (string)$atts['key'];
        if ($key === '' && get_the_ID()) {
            $key = 'post_' . (int)get_the_ID();
        }
        if ($key === '') {
            $key = md5((string)$content);
        }
        $objectKey = self::makeObjectKey('content', $key);

        if (self::hasAccess($objectKey)) {
            return do_shortcode((string)$content);
        }

        $dualRaw = strtolower(trim((string)$atts['dual']));
        $dualDefault = ((string)$tag === 'uapi_pay');
        $dualEnabled = $dualRaw === '' ? $dualDefault : in_array($dualRaw, ['1', 'true', 'yes', 'on'], true);
        $currency = strtoupper(trim((string)$atts['currency']));
        if ($currency !== 'USDT' && $currency !== 'USDC') {
            $currency = strtoupper((string)$settings['default_currency']);
        }

        $buttonsHtml = '';
        if ($dualEnabled) {
            $currencies = [];
            foreach (explode(',', (string)$atts['currencies']) as $c) {
                $c = strtoupper(trim((string)$c));
                if ($c === 'USDT' || $c === 'USDC') {
                    $currencies[] = $c;
                }
            }
            $currencies = array_values(array_unique($currencies));
            if (empty($currencies)) {
                $currencies = ['USDT', 'USDC'];
            }

            foreach ($currencies as $cur) {
                $buttonsHtml .= self::createButtonHtml([
                    'amount' => (float)$atts['amount'],
                    'currency' => (string)$cur,
                    'chain' => (string)$atts['chain'],
                    'button' => $cur . ' 支付',
                    'title' => (string)$atts['title'],
                    'object_type' => 'content',
                    'object_key' => $objectKey,
                ]);
            }
            $buttonsHtml = '<div class="uapi-dual-buttons">' . $buttonsHtml . '</div>';
        } else {
            $buttonsHtml = self::createButtonHtml([
                'amount' => (float)$atts['amount'],
                'currency' => (string)$currency,
                'chain' => (string)$atts['chain'],
                'button' => (string)$atts['btn_text'],
                'title' => (string)$atts['title'],
                'object_type' => 'content',
                'object_key' => $objectKey,
            ]);
        }

        $lockIcon = '<svg width="11" height="13" viewBox="0 0 11 13" fill="none" aria-hidden="true" focusable="false"><rect x="1.25" y="5.75" width="8.5" height="7" rx="1.75" stroke="currentColor" stroke-width="1.5"/><path d="M3 5.75V3.75a2.5 2.5 0 015 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
        return '<div class="uapi-paywall-box">'
            . '<span class="uapi-lock-badge">' . $lockIcon . '付费内容</span>'
            . '<div class="uapi-paywall-action">' . $buttonsHtml . '</div>'
            . '</div>';
    }

    public static function shortcodeDownload($atts): string
    {
        $settings = self::settings();
        $atts = shortcode_atts([
            'amount' => '1.00',
            'currency' => (string)$settings['default_currency'],
            'chain' => (string)$settings['default_chain'],
            'file_url' => '',
            'file_name' => '下载文件',
            'btn_text' => '支付后下载',
            'title' => '下载内容付费',
        ], $atts, 'uapi_download');

        $fileUrl = esc_url_raw((string)$atts['file_url']);
        if ($fileUrl === '') {
            return '<div class="uapi-pay-error">uapi_download 缺少 file_url 参数</div>';
        }

        $objectKey = self::makeObjectKey('download', md5($fileUrl));
        $downloadId = 'uapi-download-' . substr(md5($objectKey), 0, 12);

        if (self::hasAccess($objectKey)) {
            return '<a class="uapi-download-link" href="' . esc_url($fileUrl) . '" download>' . esc_html((string)$atts['file_name']) . '</a>';
        }

        $button = self::createButtonHtml([
            'amount' => (float)$atts['amount'],
            'currency' => (string)$atts['currency'],
            'chain' => (string)$atts['chain'],
            'button' => (string)$atts['btn_text'],
            'title' => (string)$atts['title'],
            'object_type' => 'download',
            'object_key' => $objectKey,
            'download_id' => $downloadId,
            'success_text' => '支付成功，下载按钮已解锁',
        ]);

        $dlIcon = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true" focusable="false"><path d="M6 1.5v6M3.5 5.5L6 8l2.5-2.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><path d="M1 10.5h10" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
        return '<div class="uapi-download-box">'
            . '<span class="uapi-lock-badge">' . $dlIcon . '付费下载</span>'
            . '<div class="uapi-paywall-action">' . $button . '</div>'
            . '<a id="' . esc_attr($downloadId) . '" class="uapi-download-link uapi-hidden" href="' . esc_url($fileUrl) . '" download>' . esc_html((string)$atts['file_name']) . '</a>'
            . '</div>';
    }

    public static function shortcodeProduct($atts): string
    {
        $settings = self::settings();
        $atts = shortcode_atts([
            'id' => '',
            'title' => '商品支付',
            'desc' => '',
            'amount' => '9.90',
            'currency' => (string)$settings['default_currency'],
            'chain' => (string)$settings['default_chain'],
            'btn_text' => '立即购买',
        ], $atts, 'uapi_product');

        $pid = (string)$atts['id'];
        if ($pid === '') {
            $pid = md5((string)$atts['title'] . '|' . (string)$atts['amount']);
        }
        $objectKey = self::makeObjectKey('product', $pid);

        $paidTag = '';
        if (self::hasAccess($objectKey)) {
            $paidTag = '<span class="uapi-paid-tag">已支付</span>';
        }

        $button = self::createButtonHtml([
            'amount' => (float)$atts['amount'],
            'currency' => (string)$atts['currency'],
            'chain' => (string)$atts['chain'],
            'button' => (string)$atts['btn_text'],
            'title' => (string)$atts['title'],
            'object_type' => 'product',
            'object_key' => $objectKey,
            'success_text' => '支付成功，商品已完成购买',
        ]);

        $shopIcon = '<svg width="13" height="12" viewBox="0 0 13 12" fill="none" aria-hidden="true" focusable="false"><path d="M1 1.5h2l1.5 7h6l1.5-5H4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="5.5" cy="10.5" r=".9" fill="currentColor"/><circle cx="10" cy="10.5" r=".9" fill="currentColor"/></svg>';
        return '<div class="uapi-product-card">'
            . '<span class="uapi-lock-badge">' . $shopIcon . '商品购买</span>'
            . '<div class="uapi-product-head"><strong>' . esc_html((string)$atts['title']) . '</strong>' . $paidTag . '</div>'
            . (!empty($atts['desc']) ? '<p class="uapi-product-desc">' . esc_html((string)$atts['desc']) . '</p>' : '')
            . '<div class="uapi-product-price">' . esc_html(number_format((float)$atts['amount'], 2, '.', '') . ' ' . strtoupper((string)$atts['currency'])) . '</div>'
            . '<div class="uapi-paywall-action">' . $button . '</div>'
            . '</div>';
    }

    public static function addPostMetaBox(): void
    {
        add_meta_box('uapi_post_paywall', 'UAPI 付费文章', [__CLASS__, 'renderPostMetaBox'], ['post', 'page'], 'side');
    }

    public static function renderPostMetaBox($post): void
    {
        wp_nonce_field('uapi_post_meta', 'uapi_post_meta_nonce');
        $enabled = get_post_meta($post->ID, '_uapi_lock_enabled', true);
        $amount = get_post_meta($post->ID, '_uapi_lock_amount', true);
        $button = get_post_meta($post->ID, '_uapi_lock_btn', true);
        ?>
        <p><label><input type="checkbox" name="uapi_lock_enabled" value="1" <?php checked($enabled, '1'); ?>> 启用整篇付费阅读</label></p>
        <p><label>金额（USDT）<br><input type="text" name="uapi_lock_amount" value="<?php echo esc_attr($amount ?: '1.00'); ?>" style="width:100%"></label></p>
        <p><label>按钮文案<br><input type="text" name="uapi_lock_btn" value="<?php echo esc_attr($button ?: '支付后阅读全文'); ?>" style="width:100%"></label></p>
        <?php
    }

    public static function savePostMeta(int $postId): void
    {
        if (!isset($_POST['uapi_post_meta_nonce']) || !wp_verify_nonce((string)$_POST['uapi_post_meta_nonce'], 'uapi_post_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        update_post_meta($postId, '_uapi_lock_enabled', isset($_POST['uapi_lock_enabled']) ? '1' : '0');
        update_post_meta($postId, '_uapi_lock_amount', sanitize_text_field((string)($_POST['uapi_lock_amount'] ?? '1.00')));
        update_post_meta($postId, '_uapi_lock_btn', sanitize_text_field((string)($_POST['uapi_lock_btn'] ?? '支付后阅读全文')));
    }

    public static function filterPaidPostContent(string $content): string
    {
        if (!is_singular() || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $postId = get_the_ID();
        if (!$postId) {
            return $content;
        }

        $enabled = get_post_meta($postId, '_uapi_lock_enabled', true) === '1';
        if (!$enabled) {
            return $content;
        }

        $objectKey = self::makeObjectKey('post', (string)$postId);
        if (self::hasAccess($objectKey)) {
            return $content;
        }

        $settings = self::settings();
        $amount = (string)get_post_meta($postId, '_uapi_lock_amount', true);
        $button = (string)get_post_meta($postId, '_uapi_lock_btn', true);
        if ($amount === '') {
            $amount = '1.00';
        }
        if ($button === '') {
            $button = '支付后阅读全文';
        }

        $btn = self::createButtonHtml([
            'amount' => (float)$amount,
            'currency' => (string)$settings['default_currency'],
            'chain' => (string)$settings['default_chain'],
            'button' => $button,
            'title' => get_the_title($postId),
            'object_type' => 'post',
            'object_key' => $objectKey,
        ]);

        $lockIcon = '<svg width="11" height="13" viewBox="0 0 11 13" fill="none" aria-hidden="true" focusable="false"><rect x="1.25" y="5.75" width="8.5" height="7" rx="1.75" stroke="currentColor" stroke-width="1.5"/><path d="M3 5.75V3.75a2.5 2.5 0 015 0v2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
        return '<div class="uapi-post-lock">'
            . '<span class="uapi-lock-badge">' . $lockIcon . '付费文章</span>'
            . '<p class="uapi-paywall-sub">支付后即可阅读全文</p>'
            . '<div class="uapi-paywall-action">' . $btn . '</div>'
            . '</div>';
    }

    public static function enqueueAssets(): void
    {
        wp_register_style('uapi-payment-style', plugin_dir_url(__FILE__) . 'assets/uapi-payment.css', [], self::VERSION);
        wp_register_script('uapi-payment-js', plugin_dir_url(__FILE__) . 'assets/uapi-payment.js', [], self::VERSION, true);
        wp_enqueue_style('uapi-payment-style');
        wp_enqueue_script('uapi-payment-js');

        wp_localize_script('uapi-payment-js', 'uapiPayment', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restBase' => esc_url_raw(rest_url('uapi-payment/v1/')),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'i18n' => [
                'creating' => '正在创建支付订单...',
                'waiting' => '请在新窗口完成支付，系统正在自动确认订单',
                'paid' => '支付成功，正在刷新页面',
                'expired' => '订单已过期，请重新发起支付',
                'failed' => '支付状态查询失败',
                'popupBlocked' => '浏览器拦截了新窗口，请允许弹窗后重试',
                'popupClosed' => '支付窗口已断开，订单已取消',
                'usdcDisabled' => '当前商户未开通 USDC 收款，请切换 USDT 或在商户后台开启 USDC',
                'openPay' => '打开支付页',
                'copyLink' => '复制链接',
            ],
        ]);
    }

    public static function ajaxCreateOrder(): void
    {
        // Soft-check nonce to avoid cached page nonce expiration causing paid-but-no-success UX.
        self::checkAjaxNonceSoft();

        $ret = self::createOrderInternal($_POST);
        if (!$ret['ok']) {
            wp_send_json_error(['message' => (string)$ret['message']], (int)$ret['code']);
        }
        wp_send_json_success((array)$ret['data']);
    }

    private static function createOrderInternal(array $input): array
    {
        $settings = self::settings();
        $apiKey = trim((string)$settings['api_key']);
        if ($apiKey === '') {
            return ['ok' => false, 'code' => 400, 'message' => '插件未配置 API Key，请先在后台设置。'];
        }

        $amount = isset($input['amount']) ? (float)$input['amount'] : 0;
        if ($amount <= 0) {
            return ['ok' => false, 'code' => 400, 'message' => '金额无效'];
        }

        $objectType = sanitize_text_field((string)($input['object_type'] ?? 'content'));
        $objectKeyRaw = sanitize_text_field((string)($input['object_key'] ?? ''));
        if ($objectKeyRaw === '') {
            return ['ok' => false, 'code' => 400, 'message' => '对象参数缺失'];
        }
        $objectKey = strpos($objectKeyRaw, ':') !== false
            ? substr($objectKeyRaw, 0, 191)
            : self::makeObjectKey($objectType, $objectKeyRaw);

        $currency = strtoupper(sanitize_text_field((string)($input['currency'] ?? $settings['default_currency'])));
        $chain = strtolower(sanitize_text_field((string)($input['chain'] ?? $settings['default_chain'])));
        if (!in_array($currency, ['USDT', 'USDC'], true)) {
            $currency = 'USDT';
        }
        if (!in_array($chain, ['bsc', 'arbitrum', 'arb'], true)) {
            $chain = 'bsc';
        }
        if ($chain === 'arb') {
            $chain = 'arbitrum';
        }

        $uid = get_current_user_id();
        $visitor = self::getVisitorKey();
        $clientKey = wp_generate_password(32, false, false);

        $merchantOrderId = 'WP-' . strtoupper(substr(md5(home_url('/')), 0, 6)) . '-' . time() . '-' . wp_rand(1000, 9999);
        $notifyUrl = rest_url('uapi-payment/v1/webhook');
        $host = self::cleanHostFromUrl(home_url('/'));

        $payload = [
            'amount' => number_format($amount, 2, '.', ''),
            'chain' => $chain,
            'currency' => $currency,
            'merchant_order_id' => $merchantOrderId,
            'notify_url' => $notifyUrl,
            'domain' => $host,
            'fast_sync' => false,
        ];

        $resp = wp_remote_post(self::endpoint('order/create.php'), [
            'timeout' => 20,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $apiKey,
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            return ['ok' => false, 'code' => 500, 'message' => '创建订单失败：' . $resp->get_error_message()];
        }

        $code = (int)wp_remote_retrieve_response_code($resp);
        $rawBody = (string)wp_remote_retrieve_body($resp);
        $body = json_decode($rawBody, true);

        if ($code >= 400 || !is_array($body)) {
            $snippet = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($rawBody)));
            $snippet = substr($snippet, 0, 140);
            $msg = '创建订单失败：接口返回异常';
            if ($snippet !== '') {
                $msg .= '（' . $snippet . '）';
            }
            return ['ok' => false, 'code' => 500, 'message' => $msg];
        }

        if (($body['status'] ?? '') !== 'success' || empty($body['data']['order_no'])) {
            $msg = (string)($body['error'] ?? $body['message'] ?? '创建订单失败');
            return ['ok' => false, 'code' => 400, 'message' => $msg];
        }

        global $wpdb;
        $table = self::tableName();
        $data = (array)$body['data'];
        $scope = self::currentMerchantScope();
        if ($scope === '') {
            return ['ok' => false, 'code' => 400, 'message' => '插件 API 配置不完整，无法创建订单。'];
        }

        $inserted = $wpdb->insert($table, [
            'merchant_order_id' => $merchantOrderId,
            'order_no' => sanitize_text_field((string)($data['order_no'] ?? '')),
            'pay_token' => self::parseTokenFromPaymentUrl((string)($data['payment_url'] ?? '')),
            'object_type' => $objectType,
            'object_key' => $objectKey,
            'amount' => number_format((float)$amount, 6, '.', ''),
            'currency' => $currency,
            'chain' => $chain,
            'status' => sanitize_text_field((string)($data['order_status'] ?? 'pending')),
            'payment_url' => esc_url_raw((string)($data['payment_url'] ?? '')),
            'user_id' => (int)$uid,
            'visitor_key' => $visitor,
            'customer_email' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            'merchant_scope' => $scope,
            'meta' => wp_json_encode([
                'request' => $payload,
                'response' => $data,
                'client_key' => $clientKey,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);

        if (!$inserted) {
            return ['ok' => false, 'code' => 500, 'message' => '本地订单记录失败：' . $wpdb->last_error];
        }

        return [
            'ok' => true,
            'code' => 200,
            'data' => [
                'local_order_id' => (int)$wpdb->insert_id,
                'order_no' => (string)$data['order_no'],
                'payment_url' => (string)$data['payment_url'],
                'client_key' => $clientKey,
            ],
        ];
    }

    private static function parseTokenFromPaymentUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['query'])) {
            return '';
        }
        parse_str((string)$parts['query'], $query);
        return sanitize_text_field((string)($query['token'] ?? ''));
    }

    private static function updateOrderStatus(int $id, string $status, string $txHash = ''): void
    {
        global $wpdb;
        $table = self::tableName();
        $row = [
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ];
        if ($txHash !== '') {
            $row['tx_hash'] = sanitize_text_field($txHash);
        }
        if ($status === 'paid') {
            $row['paid_at'] = current_time('mysql');
        }
        $wpdb->update($table, $row, ['id' => $id]);

        if ($status === 'paid') {
            $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id), ARRAY_A);
            if (is_array($order)) {
                do_action('uapi_payment_order_paid', $order);
            }
        }
    }

    private static function appendOrderMetaNote(int $id, array $payload): void
    {
        global $wpdb;
        $table = self::tableName();
        $row = $wpdb->get_row($wpdb->prepare("SELECT meta FROM {$table} WHERE id = %d", $id), ARRAY_A);
        $meta = [];
        if (is_array($row) && !empty($row['meta'])) {
            $decoded = json_decode((string)$row['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        if (!isset($meta['disputes']) || !is_array($meta['disputes'])) {
            $meta['disputes'] = [];
        }
        $meta['disputes'][] = $payload;
        $wpdb->update(
            $table,
            [
                'meta' => wp_json_encode($meta, JSON_UNESCAPED_UNICODE),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id]
        );
    }

    private static function remoteStatus(array $order): array
    {
        $token = (string)($order['pay_token'] ?? '');
        if ($token === '' && !empty($order['payment_url'])) {
            $token = self::parseTokenFromPaymentUrl((string)$order['payment_url']);
        }

        $url = add_query_arg([
            'order_no' => (string)$order['order_no'],
            'token' => $token,
        ], self::endpoint('order/status.php'));

        $resp = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'error' => $resp->get_error_message()];
        }

        $body = json_decode((string)wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) {
            return ['ok' => false, 'error' => 'invalid response'];
        }

        $status = strtolower((string)($body['status'] ?? 'pending'));
        if ($status === 'success') {
            $status = 'paid';
        }

        return [
            'ok' => true,
            'status' => $status,
            'tx_hash' => (string)($body['tx_hash'] ?? ''),
            'raw' => $body,
        ];
    }

    private static function syncOrdersWithRemote(array $rows): array
    {
        if (empty($rows)) {
            return $rows;
        }
        global $wpdb;
        $table = self::tableName();
        $maxSync = 12;
        $synced = 0;
        foreach ($rows as $idx => $row) {
            if ($synced >= $maxSync) {
                break;
            }
            $status = strtolower((string)($row['status'] ?? ''));
            if (!in_array($status, ['pending', 'expired', 'cancelled', 'disputed', 'failed'], true)) {
                continue;
            }
            if (empty($row['order_no'])) {
                continue;
            }
            $ret = self::remoteStatus($row);
            $synced++;
            if (empty($ret['ok'])) {
                continue;
            }
            $remoteStatus = strtolower((string)($ret['status'] ?? ''));
            $txHash = (string)($ret['tx_hash'] ?? '');
            if (!in_array($remoteStatus, ['pending', 'paid', 'expired', 'cancelled', 'disputed', 'failed'], true)) {
                continue;
            }
            if ($remoteStatus === $status && ($txHash === '' || $txHash === (string)($row['tx_hash'] ?? ''))) {
                continue;
            }
            $update = [
                'status' => $remoteStatus,
                'updated_at' => current_time('mysql'),
            ];
            if ($txHash !== '') {
                $update['tx_hash'] = sanitize_text_field($txHash);
            }
            if ($remoteStatus === 'paid' && empty($row['paid_at'])) {
                $update['paid_at'] = current_time('mysql');
            }
            $wpdb->update($table, $update, ['id' => (int)$row['id']]);
            $rows[$idx] = array_merge($row, $update);
        }
        return $rows;
    }

    public static function ajaxCheckOrder(): void
    {
        // Soft-check nonce to avoid cached page nonce expiration causing polling failures.
        self::checkAjaxNonceSoft();

        $ret = self::checkOrderInternal($_POST);
        if (!$ret['ok']) {
            wp_send_json_error(['message' => (string)$ret['message']], (int)$ret['code']);
        }
        wp_send_json_success((array)$ret['data']);
    }

    private static function checkOrderInternal(array $input): array
    {
        global $wpdb;
        $id = isset($input['local_order_id']) ? (int)$input['local_order_id'] : 0;
        if ($id <= 0) {
            return ['ok' => false, 'code' => 400, 'message' => '订单参数错误'];
        }

        $table = self::tableName();
        $scope = self::currentMerchantScope();
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND merchant_scope = %s", $id, $scope), ARRAY_A);
        if (!$order) {
            return ['ok' => false, 'code' => 404, 'message' => '订单不存在'];
        }

        if ((string)$order['status'] === 'paid') {
            return ['ok' => true, 'code' => 200, 'data' => ['status' => 'paid']];
        }

        $uid = get_current_user_id();
        $visitor = self::getVisitorKey();
        $clientKeyReq = sanitize_text_field((string)($input['client_key'] ?? ''));
        $meta = [];
        if (!empty($order['meta'])) {
            $decoded = json_decode((string)$order['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $clientKeySaved = sanitize_text_field((string)($meta['client_key'] ?? ''));
        $ownerOk = ((int)$order['user_id'] > 0 && (int)$order['user_id'] === $uid)
            || ((string)$order['visitor_key'] !== '' && (string)$order['visitor_key'] === $visitor)
            || ($clientKeySaved !== '' && $clientKeyReq !== '' && hash_equals($clientKeySaved, $clientKeyReq));
        if (!$ownerOk) {
            return ['ok' => false, 'code' => 403, 'message' => '无权限查询该订单'];
        }

        $ret = self::remoteStatus($order);
        if (!$ret['ok']) {
            return ['ok' => false, 'code' => 500, 'message' => '查询失败：' . $ret['error']];
        }

        if ($ret['status'] === 'paid') {
            self::updateOrderStatus((int)$order['id'], 'paid', (string)$ret['tx_hash']);
            return ['ok' => true, 'code' => 200, 'data' => ['status' => 'paid']];
        }
        if ($ret['status'] === 'expired') {
            self::updateOrderStatus((int)$order['id'], 'expired');
            return ['ok' => true, 'code' => 200, 'data' => ['status' => 'expired']];
        }

        return ['ok' => true, 'code' => 200, 'data' => ['status' => 'pending']];
    }

    public static function registerRestRoutes(): void
    {
        register_rest_route('uapi-payment/v1', '/create-order', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'restCreateOrder'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('uapi-payment/v1', '/check-order', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'restCheckOrder'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('uapi-payment/v1', '/cancel-order', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'restCancelOrder'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('uapi-payment/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handleWebhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function restCreateOrder(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = $request->get_body_params();
        }
        if (!is_array($input)) {
            $input = [];
        }
        $ret = self::createOrderInternal($input);
        if (!$ret['ok']) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => (string)$ret['message']]], (int)$ret['code']);
        }
        return new WP_REST_Response(['success' => true, 'data' => (array)$ret['data']], 200);
    }

    public static function restCheckOrder(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = $request->get_body_params();
        }
        if (!is_array($input)) {
            $input = [];
        }
        $ret = self::checkOrderInternal($input);
        if (!$ret['ok']) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => (string)$ret['message']]], (int)$ret['code']);
        }
        return new WP_REST_Response(['success' => true, 'data' => (array)$ret['data']], 200);
    }

    public static function ajaxCancelOrder(): void
    {
        self::checkAjaxNonceSoft();
        $ret = self::cancelOrderInternal($_POST);
        if (!$ret['ok']) {
            wp_send_json_error(['message' => (string)$ret['message']], (int)$ret['code']);
        }
        wp_send_json_success((array)$ret['data']);
    }

    public static function restCancelOrder(WP_REST_Request $request): WP_REST_Response
    {
        $input = $request->get_json_params();
        if (!is_array($input)) {
            $input = $request->get_body_params();
        }
        if (!is_array($input)) {
            $input = [];
        }
        $ret = self::cancelOrderInternal($input);
        if (!$ret['ok']) {
            return new WP_REST_Response(['success' => false, 'data' => ['message' => (string)$ret['message']]], (int)$ret['code']);
        }
        return new WP_REST_Response(['success' => true, 'data' => (array)$ret['data']], 200);
    }

    private static function cancelOrderInternal(array $input): array
    {
        global $wpdb;
        $id = isset($input['local_order_id']) ? (int)$input['local_order_id'] : 0;
        if ($id <= 0) {
            return ['ok' => false, 'code' => 400, 'message' => '订单参数错误'];
        }

        $table = self::tableName();
        $scope = self::currentMerchantScope();
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d AND merchant_scope = %s", $id, $scope), ARRAY_A);
        if (!$order) {
            return ['ok' => false, 'code' => 404, 'message' => '订单不存在'];
        }

        $uid = get_current_user_id();
        $visitor = self::getVisitorKey();
        $clientKeyReq = sanitize_text_field((string)($input['client_key'] ?? ''));
        $meta = [];
        if (!empty($order['meta'])) {
            $decoded = json_decode((string)$order['meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $clientKeySaved = sanitize_text_field((string)($meta['client_key'] ?? ''));
        $ownerOk = ((int)$order['user_id'] > 0 && (int)$order['user_id'] === $uid)
            || ((string)$order['visitor_key'] !== '' && (string)$order['visitor_key'] === $visitor)
            || ($clientKeySaved !== '' && $clientKeyReq !== '' && hash_equals($clientKeySaved, $clientKeyReq));
        if (!$ownerOk) {
            return ['ok' => false, 'code' => 403, 'message' => '无权限操作该订单'];
        }

        $status = (string)($order['status'] ?? 'pending');
        if ($status === 'paid') {
            return ['ok' => true, 'code' => 200, 'data' => ['status' => 'paid']];
        }

        if ($status !== 'expired' && $status !== 'cancelled') {
            self::updateOrderStatus((int)$order['id'], 'cancelled');
        }

        return ['ok' => true, 'code' => 200, 'data' => ['status' => 'cancelled']];
    }

    private static function webhookVerify(array $payload, WP_REST_Request $request): bool
    {
        $settings = self::settings();
        $apiKey = trim((string)$settings['api_key']);
        if ($apiKey === '') {
            return false;
        }

        $headerSig = trim((string)$request->get_header('x-uapi-signature'));
        $headerTs = trim((string)$request->get_header('x-uapi-timestamp'));

        if ($headerSig !== '' && $headerTs !== '') {
            $raw = (string)($payload['order_no'] ?? '') . (string)($payload['amount'] ?? '') . (string)($payload['merchant_order_id'] ?? '') . $headerTs;
            $calc = hash_hmac('sha256', $raw, $apiKey);
            if (hash_equals($calc, $headerSig)) {
                return true;
            }
        }

        $legacy = (string)($payload['signature'] ?? '');
        if ($legacy !== '') {
            $raw = (string)($payload['order_no'] ?? '') . (string)($payload['amount'] ?? '') . (string)($payload['merchant_order_id'] ?? '') . $apiKey;
            $calc = md5($raw);
            if (hash_equals($calc, $legacy)) {
                return true;
            }
        }

        return false;
    }

    public static function handleWebhook(WP_REST_Request $request)
    {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid json'], 400);
        }

        $strict = (int)(self::settings()['strict_webhook_verify'] ?? 1) === 1;
        $okVerify = self::webhookVerify($payload, $request);
        if ($strict && !$okVerify) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid signature'], 403);
        }

        $status = strtolower((string)($payload['status'] ?? ''));
        if ($status !== 'paid') {
            return new WP_REST_Response(['ok' => true, 'ignored' => true], 200);
        }

        global $wpdb;
        $table = self::tableName();
        $merchantOrderId = sanitize_text_field((string)($payload['merchant_order_id'] ?? ''));
        $orderNo = sanitize_text_field((string)($payload['order_no'] ?? ''));

        if ($merchantOrderId === '' && $orderNo === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'missing order id'], 400);
        }

        $where = '';
        $params = [];
        if ($merchantOrderId !== '') {
            $where = 'merchant_order_id = %s AND merchant_scope = %s';
            $params[] = $merchantOrderId;
            $params[] = self::currentMerchantScope();
        } else {
            $where = 'order_no = %s AND merchant_scope = %s';
            $params[] = $orderNo;
            $params[] = self::currentMerchantScope();
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE {$where} LIMIT 1", $params), ARRAY_A);
        if (!$row) {
            return new WP_REST_Response(['ok' => true, 'missing_local_order' => true], 200);
        }

        self::updateOrderStatus((int)$row['id'], 'paid', sanitize_text_field((string)($payload['tx_hash'] ?? '')));
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function registerAdminMenu(): void
    {
        add_menu_page('UAPI 支付', 'UAPI 支付', 'manage_options', 'uapi-payment', [__CLASS__, 'renderAdminPage'], 'dashicons-money-alt', 58);
    }

    public static function registerSettings(): void
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitizeSettings'],
            'default' => self::defaultSettings(),
        ]);
    }

    public static function sanitizeSettings($input): array
    {
        $oldSettings = self::settings();
        $output = self::defaultSettings();
        if (!is_array($input)) {
            return $output;
        }

        $output['api_base'] = esc_url_raw((string)($input['api_base'] ?? $output['api_base']));
        $output['api_key'] = sanitize_text_field((string)($input['api_key'] ?? ''));

        $chain = strtolower(sanitize_text_field((string)($input['default_chain'] ?? 'bsc')));
        $output['default_chain'] = in_array($chain, ['bsc', 'arbitrum', 'arb'], true) ? ($chain === 'arb' ? 'arbitrum' : $chain) : 'bsc';

        $currency = strtoupper(sanitize_text_field((string)($input['default_currency'] ?? 'USDT')));
        $output['default_currency'] = in_array($currency, ['USDT', 'USDC'], true) ? $currency : 'USDT';

        $output['strict_webhook_verify'] = !empty($input['strict_webhook_verify']) ? 1 : 0;
        $output['button_text'] = sanitize_text_field((string)($input['button_text'] ?? '立即支付解锁'));

        $oldScope = self::merchantScopeFromSettings($oldSettings);
        $newScope = self::merchantScopeFromSettings($output);
        if ($oldScope !== '' && $newScope !== '' && !hash_equals($oldScope, $newScope)) {
            global $wpdb;
            $table = self::tableName();
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET merchant_scope=%s WHERE merchant_scope='' OR merchant_scope IS NULL",
                    $oldScope
                )
            );
            // Merchant switched: keep only current merchant-scope orders in plugin center.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE merchant_scope <> %s",
                    $newScope
                )
            );
            update_option('uapi_payment_scope_switched_at', current_time('mysql'), false);
        }

        return $output;
    }

    private static function uniqueSlug(string $seed): string
    {
        global $wpdb;
        $table = self::tableLinksName();
        $normalized = strtolower(remove_accents($seed));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized);
        $base = trim((string)$normalized, '-');
        if ($base === '') {
            $base = 'pay-link-' . date('His');
        }
        $base = substr($base, 0, 60);
        $slug = $base;
        $i = 1;
        while ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE slug=%s", $slug)) > 0) {
            $slug = substr($base, 0, 55) . '-' . $i;
            $i++;
            if ($i > 999) {
                $slug = $base . '-' . wp_rand(1000, 9999);
                break;
            }
        }
        return $slug;
    }

    public static function handleAdminActions(): void
    {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        if (empty($_POST['uapi_admin_action'])) {
            return;
        }
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce((string)$_POST['_wpnonce'], self::ADMIN_ACTION_NONCE)) {
            add_settings_error('uapi-payment', 'uapi_nonce', '安全校验失败');
            return;
        }

        global $wpdb;
        $action = sanitize_text_field((string)$_POST['uapi_admin_action']);
        $orders = self::tableName();
        $links = self::tableLinksName();

        if ($action === 'create_link') {
            $title = sanitize_text_field((string)($_POST['title'] ?? '收款链接'));
            $description = sanitize_textarea_field((string)($_POST['description'] ?? ''));
            $amount = max(0.01, (float)($_POST['amount'] ?? 1));
            $currency = strtoupper(sanitize_text_field((string)($_POST['currency'] ?? 'USDT')));
            $chain = strtolower(sanitize_text_field((string)($_POST['chain'] ?? 'bsc')));
            if (!in_array($currency, ['USDT', 'USDC'], true)) {
                $currency = 'USDT';
            }
            if (!in_array($chain, ['bsc', 'arbitrum', 'arb'], true)) {
                $chain = 'bsc';
            }
            if ($chain === 'arb') {
                $chain = 'arbitrum';
            }
            $slug = self::uniqueSlug($title . '-' . time());

            $ok = $wpdb->insert($links, [
                'title' => $title,
                'description' => $description,
                'amount' => number_format($amount, 6, '.', ''),
                'currency' => $currency,
                'chain' => $chain,
                'slug' => $slug,
                'status' => 'active',
                'created_by' => (int)get_current_user_id(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ]);
            add_settings_error('uapi-payment', $ok ? 'uapi_ok' : 'uapi_fail', $ok ? '收款链接创建成功' : ('收款链接创建失败：' . $wpdb->last_error), $ok ? 'updated' : 'error');
        }

        if ($action === 'toggle_link') {
            $id = (int)($_POST['id'] ?? 0);
            $status = sanitize_text_field((string)($_POST['status'] ?? 'active'));
            if ($id > 0 && in_array($status, ['active', 'disabled'], true)) {
                $wpdb->update($links, ['status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id]);
                add_settings_error('uapi-payment', 'uapi_toggle', '收款链接状态已更新', 'updated');
            }
        }

        if ($action === 'raise_dispute') {
            $id = (int)($_POST['id'] ?? 0);
            $mode = sanitize_text_field((string)($_POST['mode'] ?? 'original'));
            $note = sanitize_textarea_field((string)($_POST['note'] ?? ''));
            $newAmount = (float)($_POST['new_amount'] ?? 0);
            $newCurrency = strtoupper(sanitize_text_field((string)($_POST['new_currency'] ?? 'USDT')));
            if ($id <= 0) {
                add_settings_error('uapi-payment', 'uapi_dispute_err', '异议失败：订单参数错误', 'error');
            } else {
                $scope = self::currentMerchantScope();
                $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%d AND merchant_scope=%s", $id, $scope), ARRAY_A);
                if (!$order) {
                    add_settings_error('uapi-payment', 'uapi_dispute_err', '异议失败：订单不存在', 'error');
                } else {
                    if (!in_array($newCurrency, ['USDT', 'USDC'], true)) {
                        $newCurrency = 'USDT';
                    }
                    if ($newAmount <= 0) {
                        $newAmount = (float)($order['amount'] ?? 0);
                    }
                    $apiKey = trim((string)(self::settings()['api_key'] ?? ''));
                    $host = self::cleanHostFromUrl(home_url('/'));
                    $payload = [
                        'merchant_order_id' => (string)($order['merchant_order_id'] ?? ''),
                        'order_no' => (string)($order['order_no'] ?? ''),
                        'mode' => $mode,
                        'new_amount' => number_format($newAmount, 6, '.', ''),
                        'new_currency' => $newCurrency,
                        'note' => $note,
                        'domain' => $host,
                    ];
                    $remoteOk = false;
                    if ($apiKey !== '') {
                        $remoteResp = wp_remote_post(self::endpoint('order/dispute.php'), [
                            'timeout' => 20,
                            'headers' => [
                                'Content-Type' => 'application/json',
                                'X-API-Key' => $apiKey,
                            ],
                            'body' => wp_json_encode($payload),
                        ]);
                        if (!is_wp_error($remoteResp)) {
                            $remoteBody = json_decode((string)wp_remote_retrieve_body($remoteResp), true);
                            if ((int)wp_remote_retrieve_response_code($remoteResp) < 400 && is_array($remoteBody) && (string)($remoteBody['status'] ?? '') === 'success') {
                                $remoteOk = true;
                                $r = (array)($remoteBody['data'] ?? []);
                                $wpdb->update($orders, [
                                    'amount' => number_format((float)($r['amount'] ?? $newAmount), 6, '.', ''),
                                    'currency' => strtoupper((string)($r['currency'] ?? $newCurrency)),
                                    'status' => (string)($r['order_status'] ?? 'disputed'),
                                    'updated_at' => current_time('mysql'),
                                ], ['id' => $id]);
                            }
                        }
                    }

                    if (!$remoteOk) {
                        if ($mode === 'adjusted') {
                            $wpdb->update($orders, [
                                'amount' => number_format($newAmount, 6, '.', ''),
                                'currency' => $newCurrency,
                                'status' => 'disputed',
                                'updated_at' => current_time('mysql'),
                            ], ['id' => $id]);
                        } else {
                            $wpdb->update($orders, [
                                'status' => 'disputed',
                                'updated_at' => current_time('mysql'),
                            ], ['id' => $id]);
                            $newAmount = (float)($order['amount'] ?? 0);
                            $newCurrency = (string)($order['currency'] ?? 'USDT');
                        }
                    }
                    self::appendOrderMetaNote($id, [
                        'time' => current_time('mysql'),
                        'mode' => $mode,
                        'amount' => number_format($newAmount, 6, '.', ''),
                        'currency' => $newCurrency,
                        'note' => $note,
                        'operator' => (int)get_current_user_id(),
                        'remote_synced' => $remoteOk ? 1 : 0,
                    ]);

                    $latest = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$orders} WHERE id=%d AND merchant_scope=%s", $id, $scope), ARRAY_A);
                    if (is_array($latest)) {
                        $ret = self::remoteStatus($latest);
                        if (!empty($ret['ok']) && (string)($ret['status'] ?? '') === 'paid') {
                            self::updateOrderStatus((int)$id, 'paid', (string)($ret['tx_hash'] ?? ''));
                            add_settings_error('uapi-payment', 'uapi_dispute_ok', '异议已提交，且复核命中已支付，订单已确认', 'updated');
                        } else {
                            add_settings_error('uapi-payment', 'uapi_dispute_pending', $remoteOk ? '异议已提交并同步主站，订单状态为“异议处理中”' : '异议已提交（本地记录），订单状态改为“异议处理中”', 'updated');
                        }
                    } else {
                        add_settings_error('uapi-payment', 'uapi_dispute_pending', '异议已提交，订单状态改为“异议处理中”', 'updated');
                    }
                }
            }
        }

        $redirect = remove_query_arg(['settings-updated'], wp_get_referer() ?: admin_url('admin.php?page=uapi-payment'));
        wp_safe_redirect($redirect);
        exit;
    }

    private static function tabs(): array
    {
        return [
            'settings' => '插件设置',
            'paylinks' => '收款链接',
            'orders' => '订单中心',
        ];
    }

    public static function renderAdminPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        settings_errors('uapi-payment');
        $tab = sanitize_text_field((string)($_GET['tab'] ?? 'settings'));
        $tabs = self::tabs();
        if (!isset($tabs[$tab])) {
            $tab = 'settings';
        }
        ?>
        <style>
        /* ===== UAPI Admin Panel Styles ===== */
        #uapi-admin-wrap { max-width: 1200px; }
        #uapi-admin-wrap h1 {
            display: flex; align-items: center; gap: 10px;
            font-size: 20px; color: #1e293b; margin-bottom: 4px;
        }
        #uapi-admin-wrap h1::before {
            content: '\25C8';
            color: #6366f1; font-size: 22px; line-height: 1;
        }
        /* Tab 导航 */
        #uapi-admin-wrap .nav-tab-wrapper {
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 20px;
        }
        #uapi-admin-wrap .nav-tab {
            color: #64748b; border-color: transparent;
            border-bottom: none; margin-bottom: -2px;
            font-weight: 500; transition: color .15s;
        }
        #uapi-admin-wrap .nav-tab:hover { color: #6366f1; }
        #uapi-admin-wrap .nav-tab-active {
            color: #4f46e5 !important;
            border-bottom: 2px solid #4f46e5 !important;
            background: transparent; border-color: transparent;
        }
        /* 卡片 */
        .uapi-admin-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px 28px;
            margin-bottom: 20px;
            max-width: 980px;
            box-shadow: 0 1px 4px rgba(0,0,0,.05), 0 4px 14px rgba(0,0,0,.04);
        }
        .uapi-admin-card h3 {
            margin: 0 0 18px;
            font-size: 15px; font-weight: 700;
            color: #1e293b;
            padding-bottom: 12px;
            border-bottom: 1px solid #f1f5f9;
            display: flex; align-items: center; gap: 8px;
        }
        .uapi-admin-card h3::before { content: ''; display: inline-block; width: 4px; height: 16px; background: #6366f1; border-radius: 2px; }
        /* 筛选栏 */
        .uapi-filter-bar {
            display: flex; align-items: center; gap: 10px;
            flex-wrap: wrap; margin-bottom: 16px;
            background: #fff; padding: 12px 16px;
            border: 1px solid #e2e8f0; border-radius: 10px;
            max-width: 100%;
        }
        .uapi-filter-bar select {
            border: 1px solid #e2e8f0; border-radius: 6px;
            padding: 6px 32px 6px 10px; color: #374151; font-size: 13px;
            -webkit-appearance: auto; appearance: auto;
        }
        .uapi-filter-bar .button {
            background: #6366f1; border-color: #4f46e5;
            color: #fff; border-radius: 6px; padding: 5px 14px;
        }
        .uapi-filter-bar .button:hover { background: #4f46e5; }
        /* 数据表格 */
        .uapi-admin-table { border-radius: 10px; overflow: hidden; border: 1px solid #e2e8f0; }
        .uapi-admin-table thead th {
            background: #f8fafc; color: #64748b;
            font-size: 12px; font-weight: 600;
            text-transform: uppercase; letter-spacing: .4px;
            padding: 10px 12px; border-bottom: 1px solid #e2e8f0;
        }
        .uapi-admin-table tbody tr:hover td { background: #f8fafc; }
        .uapi-admin-table tbody td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .uapi-admin-table tbody tr:last-child td { border-bottom: none; }
        /* 状态徽章 */
        .uapi-badge {
            display: inline-block; border-radius: 6px;
            padding: 2px 9px; font-size: 11px; font-weight: 600; letter-spacing: .2px;
        }
        .uapi-badge-paid    { background: #dcfce7; color: #166534; }
        .uapi-badge-pending { background: #fef9c3; color: #854d0e; }
        .uapi-badge-expired { background: #f1f5f9; color: #64748b; }
        .uapi-badge-cancelled { background: #fee2e2; color: #991b1b; }
        .uapi-badge-active  { background: #dbeafe; color: #1e40af; }
        .uapi-badge-disabled { background: #f1f5f9; color: #94a3b8; }
        /* 操作按钮组 */
        .uapi-order-actions { display: flex; flex-direction: column; gap: 6px; min-width: 140px; }
        .uapi-order-actions .button,
        .uapi-order-actions input[type="text"] { width: 100%; box-sizing: border-box; border-radius: 6px; font-size: 12px; }
        .uapi-order-actions input[type="text"] { border: 1px solid #d1d5db; padding: 5px 8px; }
        .uapi-link-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
        .uapi-link-actions .button { min-width: 60px; text-align: center; border-radius: 6px; font-size: 12px; }
        .uapi-dispute-input {
            width: 100% !important;
            max-width: 100% !important;
            box-sizing: border-box;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 14px;
            line-height: 1.35;
        }
        .uapi-dispute-dialog label { display:block; margin-bottom:6px; font-weight:600; color:#374151; }
        .uapi-dispute-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        @media (max-width: 760px) {
            .uapi-dispute-dialog { width: calc(100vw - 20px) !important; margin: 16px auto !important; padding: 12px !important; }
            .uapi-dispute-grid { grid-template-columns: 1fr !important; }
        }
        /* Webhook 地址框 */
        .uapi-code-box {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 10px 14px; font-family: monospace; font-size: 13px;
            color: #4f46e5; word-break: break-all;
        }
        /* 短代码参考 */
        .uapi-shortcode-list { list-style: none; margin: 0; padding: 0; }
        .uapi-shortcode-list li {
            padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #374151;
        }
        .uapi-shortcode-list li:last-child { border-bottom: none; }
        .uapi-shortcode-list code { background: #f1f5f9; border-radius: 4px; padding: 2px 6px; color: #4f46e5; font-size: 12px; }
        </style>
        <?php

        echo '<div class="wrap" id="uapi-admin-wrap">';
        echo '<h1>UAPI 支付插件</h1>';

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $k => $label) {
            $cls = $k === $tab ? ' nav-tab-active' : '';
            $url = admin_url('admin.php?page=uapi-payment&tab=' . $k);
            echo '<a class="nav-tab' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'settings') {
            self::renderAdminSettingsTab();
        } elseif ($tab === 'paylinks') {
            self::renderAdminPaylinksTab();
        } else {
            self::renderAdminOrdersTab();
        }

        echo '</div>';
    }

    private static function renderAdminSettingsTab(): void
    {
        $settings = self::settings();
        ?>
        <div class="uapi-admin-card">
            <h3>接口配置</h3>
            <form method="post" action="options.php">
                <?php settings_fields(self::OPTION_KEY); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label>API Base URL</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_base]" value="<?php echo esc_attr((string)$settings['api_base']); ?>" class="regular-text">
                            <p class="description">你的 UAPI 网关接口基础地址，例如 https://pay.example.com/api/v1</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>API Key</label></th>
                        <td>
                            <input name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]" value="<?php echo esc_attr((string)$settings['api_key']); ?>" class="regular-text" placeholder="请填写您的 API Key">
                            <p class="description">在 UAPI 后台 — API 设置 中获取</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>默认链</label></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_chain]">
                                <option value="bsc"      <?php selected($settings['default_chain'], 'bsc'); ?>>BSC（BNB Smart Chain）</option>
                                <option value="arbitrum" <?php selected($settings['default_chain'], 'arbitrum'); ?>>Arbitrum One</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>默认币种</label></th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPTION_KEY); ?>[default_currency]">
                                <option value="USDT" <?php selected($settings['default_currency'], 'USDT'); ?>>USDT</option>
                                <option value="USDC" <?php selected($settings['default_currency'], 'USDC'); ?>>USDC</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>默认按钮文案</label></th>
                        <td><input name="<?php echo esc_attr(self::OPTION_KEY); ?>[button_text]" value="<?php echo esc_attr((string)$settings['button_text']); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row">Webhook 强校验</th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[strict_webhook_verify]" value="1" <?php checked((int)$settings['strict_webhook_verify'], 1); ?>> 开启签名验证（推荐）</label></td>
                    </tr>
                </table>
                <?php submit_button('保存设置', 'primary', 'submit', true, ['style' => 'background:#6366f1;border-color:#4f46e5;border-radius:7px;']); ?>
            </form>
        </div>

        <div class="uapi-admin-card">
            <h3>Webhook 回调地址</h3>
            <p style="color:#64748b;font-size:13px;margin-top:0 0 10px;">将此地址填入 UAPI 后台的 Webhook 设置中：</p>
            <div class="uapi-code-box"><?php echo esc_html(rest_url('uapi-payment/v1/webhook')); ?></div>
        </div>

        <div class="uapi-admin-card">
            <h3>短代码用法</h3>
            <ul class="uapi-shortcode-list">
                <li><code>[uapi_pay amount="1.00"]这里是付费内容[/uapi_pay]</code> — 付费解锁内容（默认显示 USDT / USDC 双按钮）</li>
                <li><code>[uapi_pay amount="1.00" dual="0" currency="USDT"]内容[/uapi_pay]</code> — 单按钮模式</li>
                <li><code>[uapi_download amount="2.00" file_url="https://example.com/file.zip" file_name="下载ZIP"]</code> — 付费下载</li>
                <li><code>[uapi_product id="sku-001" title="会员礼包" amount="9.90" desc="一次性购买"]</code> — 商品购买</li>
            </ul>
        </div>
        <?php
    }

    private static function renderAdminPaylinksTab(): void
    {
        global $wpdb;
        $table = self::tableLinksName();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A);
        ?>
        <div class="uapi-admin-card">
            <h3>创建收款链接</h3>
            <form method="post">
                <?php wp_nonce_field(self::ADMIN_ACTION_NONCE); ?>
                <input type="hidden" name="uapi_admin_action" value="create_link">
                <table class="form-table">
                    <tr>
                        <th><label>标题</label></th>
                        <td><input name="title" class="regular-text" value="快速收款"></td>
                    </tr>
                    <tr>
                        <th><label>说明</label></th>
                        <td><textarea name="description" rows="2" class="large-text" placeholder="可选说明文字"></textarea></td>
                    </tr>
                    <tr>
                        <th><label>金额</label></th>
                        <td><input name="amount" type="number" min="0.01" step="0.01" value="1.00" style="width:120px;"></td>
                    </tr>
                    <tr>
                        <th><label>币种 / 链</label></th>
                        <td>
                            <select name="currency" style="border-radius:6px;">
                                <option>USDT</option><option>USDC</option>
                            </select>
                            &nbsp;
                            <select name="chain" style="border-radius:6px;">
                                <option value="bsc">BSC</option>
                                <option value="arbitrum">Arbitrum</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <button class="button button-primary" style="border-radius:7px;background:#6366f1;border-color:#4f46e5;">创建收款链接</button>
            </form>
        </div>

        <h3 style="font-size:15px;font-weight:700;color:#1e293b;margin:0 0 12px;">收款链接列表</h3>
        <div style="overflow:auto;max-width:100%;">
        <table class="widefat uapi-admin-table">
            <thead><tr>
                <th>ID</th><th>标题</th><th>金额</th><th>链 / 币种</th><th>链接</th><th>状态</th><th>访问量</th><th>创建时间</th><th>操作</th>
            </tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" style="color:#94a3b8;text-align:center;padding:24px;">暂无收款链接</td></tr>
            <?php else: foreach ($rows as $r):
                $url = add_query_arg('uapi_pay_link', (string)$r['slug'], home_url('/'));
                $isActive = (string)$r['status'] === 'active';
                ?>
                <tr>
                    <td style="color:#94a3b8;"><?php echo (int)$r['id']; ?></td>
                    <td>
                        <strong style="color:#1e293b;"><?php echo esc_html((string)$r['title']); ?></strong>
                        <?php if (!empty($r['description'])): ?>
                            <br><span style="color:#94a3b8;font-size:12px;"><?php echo esc_html((string)$r['description']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:600;color:#1e293b;"><?php echo esc_html(number_format((float)$r['amount'], 2, '.', '') . ' ' . strtoupper((string)$r['currency'])); ?></td>
                    <td><span style="font-size:12px;font-weight:600;color:#6366f1;"><?php echo esc_html(strtoupper((string)$r['chain'])); ?></span></td>
                    <td>
                        <div class="uapi-link-actions">
                            <a href="<?php echo esc_url($url); ?>" target="_blank" class="button button-small">打开</a>
                            <button type="button" class="button button-small uapi-copy-btn" data-copy="<?php echo esc_attr($url); ?>">复制</button>
                        </div>
                    </td>
                    <td><span class="uapi-badge <?php echo $isActive ? 'uapi-badge-active' : 'uapi-badge-disabled'; ?>"><?php echo $isActive ? '启用' : '停用'; ?></span></td>
                    <td style="color:#64748b;"><?php echo (int)$r['hit_count']; ?></td>
                    <td style="color:#94a3b8;font-size:12px;"><?php echo esc_html((string)$r['created_at']); ?></td>
                    <td>
                        <form method="post" style="display:inline-block;margin:0;">
                            <?php wp_nonce_field(self::ADMIN_ACTION_NONCE); ?>
                            <input type="hidden" name="uapi_admin_action" value="toggle_link">
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                            <input type="hidden" name="status" value="<?php echo $isActive ? 'disabled' : 'active'; ?>">
                            <button class="button button-small" style="border-radius:6px;"><?php echo $isActive ? '停用' : '启用'; ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <script>
        (function(){
            document.querySelectorAll('.uapi-copy-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var txt = btn.getAttribute('data-copy') || '';
                    if (!txt) return;
                    navigator.clipboard.writeText(txt).then(function(){
                        btn.textContent = '已复制';
                        setTimeout(function(){ btn.textContent = '复制'; }, 1200);
                    });
                });
            });
        })();
        </script>
        <?php
    }

    private static function renderAdminOrdersTab(): void
    {
        global $wpdb;
        $table = self::tableName();

        $filterType = sanitize_text_field((string)($_GET['filter_type'] ?? 'all'));
        $filterStatus = sanitize_text_field((string)($_GET['filter_status'] ?? 'all'));
        $scope = self::currentMerchantScope();
        $scopeSwitchedAt = (string)get_option('uapi_payment_scope_switched_at', '');
        $pageNo = max(1, (int)($_GET['orders_page'] ?? 1));
        $perPage = 10;

        $where = 'merchant_scope = %s';
        $params = [$scope];
        if ($scopeSwitchedAt !== '') {
            $where .= ' AND created_at >= %s';
            $params[] = $scopeSwitchedAt;
        }
        if ($filterType !== 'all') {
            $where .= ' AND object_type = %s';
            $params[] = $filterType;
        }
        if ($filterStatus !== 'all') {
            $where .= ' AND status = %s';
            $params[] = $filterStatus;
        }

        $countSql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $totalRows = (int)$wpdb->get_var($wpdb->prepare($countSql, $params));
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        if ($pageNo > $totalPages) {
            $pageNo = $totalPages;
        }
        $offset = ($pageNo - 1) * $perPage;
        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}";
        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, $params);
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $rows = self::syncOrdersWithRemote($rows);
        $statusMap      = ['pending' => '待支付', 'paid' => '已支付', 'expired' => '已过期', 'cancelled' => '已取消', 'disputed' => '异议处理中'];
        $statusBadgeMap = ['pending' => 'uapi-badge-pending', 'paid' => 'uapi-badge-paid', 'expired' => 'uapi-badge-expired', 'cancelled' => 'uapi-badge-cancelled', 'disputed' => 'uapi-badge-disabled'];
        $typeMap        = ['post' => '文章付费', 'content' => '内容付费', 'download' => '下载付费', 'product' => '商品支付', 'payment_link' => '收款链接'];
        ?>
        <div class="uapi-filter-bar">
            <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:0;">
                <input type="hidden" name="page" value="uapi-payment">
                <input type="hidden" name="tab" value="orders">
                <input type="hidden" name="orders_page" value="1">
                <select name="filter_type">
                    <option value="all" <?php selected($filterType, 'all'); ?>>全部类型</option>
                    <option value="post" <?php selected($filterType, 'post'); ?>>文章付费</option>
                    <option value="content" <?php selected($filterType, 'content'); ?>>内容付费</option>
                    <option value="download" <?php selected($filterType, 'download'); ?>>下载付费</option>
                    <option value="product" <?php selected($filterType, 'product'); ?>>商品支付</option>
                    <option value="payment_link" <?php selected($filterType, 'payment_link'); ?>>收款链接</option>
                </select>
                <select name="filter_status">
                    <option value="all" <?php selected($filterStatus, 'all'); ?>>全部状态</option>
                    <option value="pending" <?php selected($filterStatus, 'pending'); ?>>待支付</option>
                    <option value="paid" <?php selected($filterStatus, 'paid'); ?>>已支付</option>
                    <option value="expired" <?php selected($filterStatus, 'expired'); ?>>已过期</option>
                    <option value="cancelled" <?php selected($filterStatus, 'cancelled'); ?>>已取消</option>
                    <option value="disputed" <?php selected($filterStatus, 'disputed'); ?>>异议处理中</option>
                </select>
                <button class="button">筛选</button>
            </form>
        </div>

        <div style="overflow:auto;max-width:100%;">
        <table class="widefat uapi-admin-table">
            <thead>
                <tr>
                    <th>ID</th><th>商户单号</th><th>平台单号</th><th>类型</th><th>买家</th><th>金额</th><th>状态</th><th>交易哈希</th><th>创建时间</th><th>支付时间</th><th>操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="11" style="color:#94a3b8;text-align:center;padding:24px;">暂无订单</td></tr>
            <?php else: foreach ($rows as $o):
                $buyer = '游客';
                if ((int)($o['user_id'] ?? 0) > 0) {
                    $u = get_user_by('id', (int)$o['user_id']);
                    if ($u) {
                        $buyer = (string)($u->user_email ?: $u->display_name ?: $u->user_login);
                    }
                } elseif (!empty($o['customer_email'])) {
                    $buyer = (string)$o['customer_email'];
                }
                $tx = (string)($o['tx_hash'] ?? '');
                $txShort = $tx;
                if (strlen($tx) > 12) {
                    $txShort = substr($tx, 0, 4) . '...' . substr($tx, -4);
                }
                $txUrl = self::explorerTxUrl((string)($o['chain'] ?? ''), $tx);
                $statusKey = (string)$o['status'];
                $badgeCls  = $statusBadgeMap[$statusKey] ?? 'uapi-badge-expired';
            ?>
                <tr>
                    <td style="color:#94a3b8;"><?php echo (int)$o['id']; ?></td>
                    <td style="font-family:monospace;font-size:11px;color:#64748b;"><?php echo esc_html((string)$o['merchant_order_id']); ?></td>
                    <td style="font-family:monospace;font-size:11px;color:#64748b;"><?php echo esc_html((string)$o['order_no']); ?></td>
                    <td style="font-size:12px;color:#6366f1;font-weight:600;"><?php echo esc_html((string)($typeMap[(string)$o['object_type']] ?? (string)$o['object_type'])); ?></td>
                    <td style="font-size:12px;color:#374151;"><?php echo esc_html($buyer); ?></td>
                    <td style="font-weight:700;color:#1e293b;"><?php echo esc_html(number_format((float)$o['amount'], 2, '.', '') . ' ' . strtoupper((string)$o['currency'])); ?><br><span style="font-size:11px;color:#94a3b8;font-weight:400;"><?php echo esc_html(strtoupper((string)$o['chain'])); ?></span></td>
                    <td><span class="uapi-badge <?php echo esc_attr($badgeCls); ?>"><?php echo esc_html((string)($statusMap[$statusKey] ?? $statusKey)); ?></span></td>
                    <td>
                        <?php if ($tx !== '' && $txUrl !== ''): ?>
                            <a href="<?php echo esc_url($txUrl); ?>" target="_blank" rel="noopener noreferrer" style="font-family:monospace;font-size:11px;color:#6366f1;"><?php echo esc_html($txShort); ?></a>
                        <?php elseif ($txShort !== ''): ?>
                            <span style="font-family:monospace;font-size:11px;color:#94a3b8;"><?php echo esc_html($txShort); ?></span>
                        <?php else: ?>
                            <span style="color:#cbd5e1;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#94a3b8;font-size:12px;"><?php echo esc_html((string)$o['created_at']); ?></td>
                    <td style="color:#94a3b8;font-size:12px;"><?php echo (string)$o['paid_at'] !== '' && (string)$o['paid_at'] !== '0000-00-00 00:00:00' ? esc_html((string)$o['paid_at']) : '—'; ?></td>
                    <td>
                        <div class="uapi-order-actions">
                            <button
                                type="button"
                                class="button button-small button-primary uapi-dispute-btn"
                                style="background:#f59e0b;border-color:#d97706;"
                                data-id="<?php echo (int)$o['id']; ?>"
                                data-amount="<?php echo esc_attr(number_format((float)$o['amount'], 6, '.', '')); ?>"
                                data-currency="<?php echo esc_attr(strtoupper((string)$o['currency'])); ?>"
                                data-chain="<?php echo esc_attr(strtoupper((string)$o['chain'])); ?>"
                            >异议处理</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">
                <span style="color:#94a3b8;font-size:12px;">第 <?php echo (int)$pageNo; ?> / <?php echo (int)$totalPages; ?> 页，共 <?php echo (int)$totalRows; ?> 条</span>
                <div style="display:flex;gap:8px;">
                    <?php
                    $baseArgs = [
                        'page' => 'uapi-payment',
                        'tab' => 'orders',
                        'filter_type' => $filterType,
                        'filter_status' => $filterStatus,
                    ];
                    $prevUrl = add_query_arg(array_merge($baseArgs, ['orders_page' => max(1, $pageNo - 1)]), admin_url('admin.php'));
                    $nextUrl = add_query_arg(array_merge($baseArgs, ['orders_page' => min($totalPages, $pageNo + 1)]), admin_url('admin.php'));
                    ?>
                    <a class="button <?php echo $pageNo <= 1 ? 'disabled' : ''; ?>" href="<?php echo esc_url($prevUrl); ?>">上一页</a>
                    <a class="button <?php echo $pageNo >= $totalPages ? 'disabled' : ''; ?>" href="<?php echo esc_url($nextUrl); ?>">下一页</a>
                </div>
            </div>
        <?php endif; ?>
        <div id="uapi-dispute-modal" class="uapi-dispute-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:99999;">
            <div class="uapi-dispute-dialog" style="max-width:640px;width:calc(100vw - 40px);margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 20px 44px rgba(15,23,42,.25);padding:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h3 style="margin:0;font-size:16px;font-weight:700;">异议订单处理</h3>
                    <button type="button" id="uapi-dispute-close" class="button">关闭</button>
                </div>
                <form method="post" id="uapi-dispute-form" style="display:grid;gap:10px;">
                    <?php wp_nonce_field(self::ADMIN_ACTION_NONCE); ?>
                    <input type="hidden" name="uapi_admin_action" value="raise_dispute">
                    <input type="hidden" name="id" id="uapi-dispute-id" value="">
                    <div class="uapi-dispute-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div><label>订单ID</label><input type="text" id="uapi-dispute-id-view" class="uapi-dispute-input" readonly></div>
                        <div><label>当前金额</label><input type="text" id="uapi-dispute-current" class="uapi-dispute-input" readonly></div>
                    </div>
                    <div class="uapi-dispute-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label>处理模式</label>
                            <select name="mode" id="uapi-dispute-mode" class="uapi-dispute-input">
                                <option value="original">按原金额复核</option>
                                <option value="adjusted">按修正金额复核</option>
                            </select>
                        </div>
                        <div>
                            <label>币种</label>
                            <select name="new_currency" id="uapi-dispute-currency" class="uapi-dispute-input">
                                <option value="USDT">USDT</option>
                                <option value="USDC">USDC</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label>修正金额</label>
                        <input type="number" step="0.000001" min="0.000001" name="new_amount" id="uapi-dispute-amount" class="uapi-dispute-input" required>
                    </div>
                    <div>
                        <label>异议说明</label>
                        <textarea name="note" id="uapi-dispute-note" rows="3" class="uapi-dispute-input" placeholder="例如：用户反馈已支付但未回调，申请复核"></textarea>
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;">
                        <button type="button" id="uapi-dispute-cancel" class="button">取消</button>
                        <button type="submit" class="button button-primary">提交异议</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        (function(){
            var modal = document.getElementById('uapi-dispute-modal');
            var closeBtn = document.getElementById('uapi-dispute-close');
            var cancelBtn = document.getElementById('uapi-dispute-cancel');
            function closeModal(){ if (modal) modal.style.display = 'none'; }
            if (closeBtn) closeBtn.addEventListener('click', closeModal);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
            if (modal) modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

            document.querySelectorAll('.uapi-dispute-btn').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var id = btn.getAttribute('data-id') || '';
                    var amount = btn.getAttribute('data-amount') || '';
                    var currency = btn.getAttribute('data-currency') || 'USDT';
                    document.getElementById('uapi-dispute-id').value = id;
                    document.getElementById('uapi-dispute-id-view').value = id;
                    document.getElementById('uapi-dispute-current').value = amount + ' ' + currency;
                    document.getElementById('uapi-dispute-amount').value = amount;
                    document.getElementById('uapi-dispute-currency').value = currency;
                    document.getElementById('uapi-dispute-note').value = '';
                    modal.style.display = 'block';
                });
            });
        })();
        </script>
        <?php
    }

    public static function handlePayLinkTemplate(): void
    {
        $slugRaw = (string)get_query_var('uapi_pay_link');
        if ($slugRaw === '') {
            return;
        }
        $slugCandidates = [];
        foreach ([
            $slugRaw,
            rawurldecode($slugRaw),
            sanitize_title($slugRaw),
            sanitize_title(rawurldecode($slugRaw)),
        ] as $s) {
            $s = trim((string)$s);
            if ($s !== '') $slugCandidates[] = $s;
        }
        $slugCandidates = array_values(array_unique($slugCandidates));
        if (empty($slugCandidates)) return;

        global $wpdb;
        $table = self::tableLinksName();
        $placeholders = implode(',', array_fill(0, count($slugCandidates), '%s'));
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE slug IN ($placeholders) LIMIT 1", ...$slugCandidates);
        $link = $wpdb->get_row($sql, ARRAY_A);

        if (!$link || (string)$link['status'] !== 'active') {
            status_header(404);
            wp_die('收款链接不存在或已停用');
        }

        $wpdb->query($wpdb->prepare("UPDATE {$table} SET hit_count = hit_count + 1, updated_at=%s WHERE id=%d", current_time('mysql'), (int)$link['id']));

        $objectKey = self::makeObjectKey('payment_link', (string)$link['id']);
        $paid = self::hasAccess($objectKey);

        wp_enqueue_style('uapi-payment-style');
        wp_enqueue_script('uapi-payment-js');

        nocache_headers();
        ?><!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo('charset'); ?>">
            <meta name="viewport" content="width=device-width,initial-scale=1">
            <title><?php echo esc_html((string)$link['title']); ?> - 支付</title>
            <?php wp_head(); ?>
        </head>
        <body class="uapi-paylink-body">
            <div class="uapi-paylink-wrap">
                <div class="uapi-paylink-card">
                    <div class="uapi-paylink-brand">UAPI</div>
                    <h1><?php echo esc_html((string)$link['title']); ?></h1>
                    <?php if (!empty($link['description'])): ?>
                        <p class="uapi-paylink-desc"><?php echo esc_html((string)$link['description']); ?></p>
                    <?php endif; ?>
                    <div class="uapi-paylink-amount"><?php echo esc_html(number_format((float)$link['amount'], 2, '.', '') . ' ' . strtoupper((string)$link['currency'])); ?></div>
                    <div class="uapi-paylink-meta"><?php echo esc_html(strtoupper((string)$link['chain'])); ?> 网络</div>

                    <?php if ($paid): ?>
                        <div class="uapi-paylink-paid">该链接下您已支付成功</div>
                    <?php else:
                        echo self::createButtonHtml([
                            'amount' => (float)$link['amount'],
                            'currency' => (string)$link['currency'],
                            'chain' => (string)$link['chain'],
                            'button' => '立即支付',
                            'title' => (string)$link['title'],
                            'object_type' => 'payment_link',
                            'object_key' => $objectKey,
                            'success_text' => '支付成功',
                        ]);
                    endif; ?>
                </div>
            </div>
            <?php wp_footer(); ?>
        </body>
        </html><?php
        exit;
    }
}

UAPIPaymentPlugin::init();
