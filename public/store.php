<?php
// public/store.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Helper.php';
I18n::init();

$db = Database::getInstance();
$db->autoMigrate();

$user_id = (int)$_SESSION['user_id'];
$is_en = I18n::getLang() === 'en';
$tt = static function (string $zh, string $en) use ($is_en): string {
    return $is_en ? $en : $zh;
};

$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) {
    $cfg[$s['key_name']] = $s['value'];
}
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';

function store_alert($type, $text)
{
    return '<div class="alert alert-' . $type . '">' . htmlspecialchars($text) . '</div>';
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Helper::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        header("Location: store.php?msg=csrf_invalid");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fix_db') {
    try {
        $db->query("CREATE TABLE IF NOT EXISTS `stores` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) NOT NULL,
          `name` varchar(100) NOT NULL,
          `slug` varchar(100) NOT NULL,
          `description` text,
          `contact_info` varchar(255) DEFAULT NULL,
          `logo_url` varchar(255) DEFAULT NULL,
          `status` enum('active','inactive') DEFAULT 'active',
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `slug` (`slug`),
          KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $db->query("CREATE TABLE IF NOT EXISTS `store_products` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `store_id` int(11) NOT NULL,
          `name` varchar(255) NOT NULL,
          `description` text,
          `price` decimal(20,6) NOT NULL,
          `currency` varchar(10) DEFAULT 'USDT',
          `image_url` varchar(255) DEFAULT NULL,
          `stock` int(11) DEFAULT -1,
          `status` enum('active','inactive') DEFAULT 'active',
          `category` varchar(100) DEFAULT 'General',
          `is_featured` tinyint(1) DEFAULT 0,
          `is_physical` tinyint(1) DEFAULT 0,
          `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `store_id` (`store_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        try { $db->query("ALTER TABLE stores ADD COLUMN logo_url VARCHAR(255) DEFAULT NULL AFTER contact_info"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE store_products ADD COLUMN category VARCHAR(100) DEFAULT 'General' AFTER name"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE store_products ADD COLUMN is_featured TINYINT(1) DEFAULT 0 AFTER image_url"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE store_products ADD COLUMN image_url VARCHAR(255) DEFAULT NULL AFTER currency"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE store_products ADD COLUMN features TEXT DEFAULT NULL AFTER description"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE store_products ADD COLUMN faq TEXT DEFAULT NULL AFTER features"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE store_products ADD COLUMN is_physical TINYINT(1) DEFAULT 0 AFTER is_featured"); } catch (Exception $e) {}

        try { $db->query("ALTER TABLE orders ADD COLUMN shipping_info TEXT DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE orders ADD COLUMN logistics_company VARCHAR(100) DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE orders ADD COLUMN logistics_status ENUM('pending', 'shipped', 'delivered', 'returned') DEFAULT 'pending'"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE orders ADD COLUMN customer_email VARCHAR(191) DEFAULT NULL"); } catch (Exception $e) {}
        try { $db->query("ALTER TABLE orders ADD COLUMN receipt_sent_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}

        $msg = store_alert('success', $tt('数据库修复成功，请刷新页面。', 'Database repaired successfully.'));
    } catch (Exception $e) {
        $msg = store_alert('danger', $tt('数据库修复失败：', 'Database repair failed: ') . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_settings') {
        $name = trim((string)($_POST['name'] ?? ''));
        $slug = trim((string)($_POST['slug'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? ''));
        $contact = trim((string)($_POST['contact_info'] ?? ''));
        $logo_url_text = trim((string)($_POST['logo_url'] ?? ''));

        if ($name === '' || $slug === '') {
            $msg = store_alert('danger', __('merchant.store.error.name_slug_required'));
        } elseif (!preg_match('/^[a-zA-Z0-9-_]+$/', $slug)) {
            $msg = store_alert('danger', $tt('店铺地址后缀格式错误，仅支持字母数字和 - _', 'Invalid slug format. Use letters, numbers, - and _.'));
        } else {
            $exist = $db->fetch("SELECT id FROM stores WHERE slug = ? AND user_id != ?", [$slug, $user_id]);
            if ($exist) {
                $msg = store_alert('danger', __('merchant.store.error.slug_taken'));
            } else {
                $store = $db->fetch("SELECT * FROM stores WHERE user_id = ?", [$user_id]);
                $logo_url = $store['logo_url'] ?? null;

                if ($logo_url_text !== '') {
                    $logo_url = $logo_url_text;
                }

                if (!empty($_POST['remove_logo'])) {
                    $logo_url = null;
                }

                if (isset($_FILES['logo_file']) && (int)$_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
                    $tmp = $_FILES['logo_file']['tmp_name'];
                    $size = (int)$_FILES['logo_file']['size'];
                    $mime = '';
                    if (function_exists('finfo_open')) {
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo) {
                            $mime = (string)finfo_file($finfo, $tmp);
                            finfo_close($finfo);
                        }
                    }

                    $allowed = [
                        'image/png' => 'png',
                        'image/jpeg' => 'jpg',
                        'image/jpg' => 'jpg',
                        'image/webp' => 'webp',
                    ];

                    if ($size <= 0 || $size > 2 * 1024 * 1024) {
                        $msg = store_alert('danger', $tt('Logo 文件大小必须小于 2MB。', 'Logo file must be less than 2MB.'));
                    } elseif (!isset($allowed[$mime])) {
                        $msg = store_alert('danger', $tt('Logo 格式仅支持 PNG/JPG/WebP。', 'Logo format must be PNG/JPG/WebP.'));
                    } else {
                        $ext = $allowed[$mime];
                        $dir = __DIR__ . '/uploads/store_logos';
                        if (!is_dir($dir)) {
                            @mkdir($dir, 0755, true);
                        }
                        $filename = 'store_' . $user_id . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        $target = $dir . '/' . $filename;
                        if (move_uploaded_file($tmp, $target)) {
                            $logo_url = '/uploads/store_logos/' . $filename;
                        } else {
                            $msg = store_alert('danger', $tt('Logo 上传失败，请重试。', 'Logo upload failed. Please try again.'));
                        }
                    }
                }

                if ($msg === '') {
                    if ($store) {
                        $db->query(
                            "UPDATE stores SET name=?, slug=?, description=?, contact_info=?, logo_url=? WHERE id=?",
                            [$name, $slug, $desc, $contact, $logo_url, $store['id']]
                        );
                    } else {
                        $db->query(
                            "INSERT INTO stores (user_id, name, slug, description, contact_info, logo_url) VALUES (?, ?, ?, ?, ?, ?)",
                            [$user_id, $name, $slug, $desc, $contact, $logo_url]
                        );
                    }
                    $msg = store_alert('success', __('merchant.store.success.settings_saved'));
                }
            }
        }
    }

    if ($_POST['action'] === 'save_product') {
        $store = $db->fetch("SELECT id FROM stores WHERE user_id = ?", [$user_id]);
        if (!$store) {
            $msg = store_alert('warning', __('merchant.store.warn.save_settings_first'));
        } else {
            $p_name = trim((string)($_POST['p_name'] ?? ''));
            $p_price = (float)($_POST['p_price'] ?? 0);
            $p_desc = trim((string)($_POST['p_desc'] ?? ''));
            $p_category = trim((string)($_POST['p_category'] ?? 'General'));
            $p_image = trim((string)($_POST['p_image'] ?? ''));
            $p_features = trim((string)($_POST['p_features'] ?? ''));
            $p_featured = isset($_POST['p_featured']) ? 1 : 0;
            $p_physical = isset($_POST['is_physical']) ? 1 : 0;
            $p_id = (int)($_POST['product_id'] ?? 0);

            if ($p_id > 0) {
                $db->query(
                    "UPDATE store_products SET name=?, price=?, description=?, category=?, image_url=?, is_featured=?, features=?, is_physical=? WHERE id=? AND store_id=?",
                    [$p_name, $p_price, $p_desc, $p_category, $p_image, $p_featured, $p_features, $p_physical, $p_id, $store['id']]
                );
            } else {
                $db->query(
                    "INSERT INTO store_products (store_id, name, price, description, category, image_url, is_featured, features, is_physical) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$store['id'], $p_name, $p_price, $p_desc, $p_category, $p_image, $p_featured, $p_features, $p_physical]
                );
            }
            $msg = store_alert('success', __('merchant.store.success.product_saved'));
        }
    }

    if ($_POST['action'] === 'delete_product') {
        $store = $db->fetch("SELECT id FROM stores WHERE user_id = ?", [$user_id]);
        if ($store) {
            $db->query("DELETE FROM store_products WHERE id=? AND store_id=?", [(int)$_POST['product_id'], $store['id']]);
            $msg = store_alert('success', __('merchant.store.success.product_deleted'));
        }
    }

    if ($_POST['action'] === 'delete_products_bulk') {
        $store = $db->fetch("SELECT id FROM stores WHERE user_id = ?", [$user_id]);
        if ($store && !empty($_POST['selected_ids']) && is_array($_POST['selected_ids'])) {
            $ids = array_map('intval', $_POST['selected_ids']);
            $ids = array_filter($ids, static function ($v) { return $v > 0; });
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $params = array_merge(array_values($ids), [$store['id']]);
                $db->query("DELETE FROM store_products WHERE id IN ($placeholders) AND store_id = ?", $params);
                $msg = store_alert('success', __('merchant.store.success.bulk_deleted', ['count' => count($ids)]));
            }
        } else {
            $msg = store_alert('warning', __('merchant.store.warn.none_selected'));
        }
    }

    if ($_POST['action'] === 'generate_demo_content') {
        try {
            $store = $db->fetch("SELECT id FROM stores WHERE user_id = ?", [$user_id]);
            if ($store) {
                $count = $db->fetch("SELECT count(*) as c FROM store_products WHERE store_id = ?", [$store['id']]);
                if ((int)($count['c'] ?? 0) > 0) {
                    $msg = store_alert('warning', __('merchant.store.warn.products_exist'));
                } else {
                    $demos = [
                        ['SaaS Starter Kit', 'SaaS', 'Complete starter kit for your next SaaS project. Includes auth, payments, and dashboard.', 199.00, 'https://images.unsplash.com/photo-1661956602116-aa6865609028?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80', 1, "Instant Delivery\nSecure Payment\nLifetime Updates\nPriority Support"],
                        ['Premium Dashboard UI', 'Templates', 'Modern and clean dashboard UI kit for React and Vue.', 49.00, 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80', 1, "React & Vue Support\nDark Mode Included\nFigma Files\nResponsive Design"],
                        ['API Access Pro', 'API', 'High-performance API access with 99.9% uptime SLA.', 99.00, 'https://images.unsplash.com/photo-1555066931-4365d14bab8c?ixlib=rb-4.0.3&auto=format&fit=crop&w=800&q=80', 1, "99.9% Uptime SLA\n100 Requests/sec\nDedicated Support\nReal-time Analytics"],
                    ];

                    $default_faq = json_encode([
                        ['q' => 'How do I receive the product?', 'a' => 'Instant delivery via email after payment confirmation.'],
                        ['q' => 'Is this payment secure?', 'a' => 'Yes, we use decentralized crypto payments via UAPI.']
                    ]);

                    foreach ($demos as $d) {
                        $db->query(
                            "INSERT INTO store_products (store_id, name, category, description, price, image_url, is_featured, features, faq) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                            [$store['id'], $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $default_faq]
                        );
                    }
                    $msg = store_alert('success', __('merchant.store.success.demo_generated'));
                }
            } else {
                $msg = store_alert('warning', __('merchant.store.warn.save_settings_first'));
            }
        } catch (Exception $e) {
            $msg = store_alert('danger', __('merchant.store.error.demo_failed') . ': ' . $e->getMessage());
        }
    }

    if ($_POST['action'] === 'update_logistics') {
        $store = $db->fetch("SELECT id FROM stores WHERE user_id = ?", [$user_id]);
        if ($store) {
            $order_no = trim((string)($_POST['order_no'] ?? ''));
            $tracking = trim((string)($_POST['tracking_number'] ?? ''));
            $company = trim((string)($_POST['logistics_company'] ?? ''));
            $status = trim((string)($_POST['status'] ?? 'pending'));
            if (!in_array($status, ['pending', 'shipped', 'delivered', 'returned'], true)) {
                $status = 'pending';
            }
            $db->query(
                "UPDATE orders SET tracking_number=?, logistics_company=?, logistics_status=? WHERE order_no=? AND source='store' AND source_id=?",
                [$tracking, $company, $status, $order_no, $store['id']]
            );
            $msg = store_alert('success', $tt('物流信息已更新。', 'Logistics information updated.'));
        }
    }

    if ($_POST['action'] === 'create_coupon') {
        $store = $db->fetch("SELECT id FROM stores WHERE user_id = ?", [$user_id]);
        if (!$store) {
            $msg = store_alert('warning', $tt('请先创建店铺再设置营销活动。', 'Please create store first.'));
        } else {
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $type = trim((string)($_POST['type'] ?? 'fixed'));
            $value = (float)($_POST['value'] ?? 0);
            $limit = (int)($_POST['usage_limit'] ?? -1);
            if ($code === '' || $value <= 0 || !in_array($type, ['fixed', 'percent'], true)) {
                $msg = store_alert('danger', __('merchant.marketing.error.invalid_input'));
            } else {
                try {
                    $db->query("INSERT INTO store_coupons (store_id, code, type, value, usage_limit) VALUES (?, ?, ?, ?, ?)", [$store['id'], $code, $type, $value, $limit]);
                    $msg = store_alert('success', __('merchant.marketing.success.coupon_created'));
                } catch (Exception $e) {
                    $msg = store_alert('danger', __('merchant.marketing.error.coupon_duplicate'));
                }
            }
        }
    }
}

$store = null;
$products = [];
$logistics_orders = [];
$coupons = [];
$coupon_usages = [];
$db_error = false;
$stats = ['products' => 0, 'orders' => 0, 'volume' => 0, 'pending_shipments' => 0];
$products_page = max(1, (int)($_GET['p_products'] ?? 1));
$logistics_page = max(1, (int)($_GET['p_logistics'] ?? 1));
$coupons_page = max(1, (int)($_GET['p_coupons'] ?? 1));
$products_per_page = 15;
$logistics_per_page = 15;
$coupons_per_page = 15;
$products_total = 0;
$logistics_total = 0;
$coupons_total = 0;
$coupon_usage_total = 0;
$products_total_pages = 1;
$logistics_total_pages = 1;
$coupons_total_pages = 1;
$coupon_usage_page = max(1, (int)($_GET['p_coupon_usage'] ?? 1));
$coupon_usage_per_page = 15;
$coupon_usage_total_pages = 1;

try {
    $store = $db->fetch("SELECT * FROM stores WHERE user_id = ?", [$user_id]);
    if ($store) {
        try {
            $stats = $db->fetch(
                "SELECT
                    (SELECT COUNT(*) FROM store_products WHERE store_id = :sid) AS products,
                    (SELECT COUNT(*) FROM orders WHERE source='store' AND source_id = :sid2) AS orders,
                    (SELECT COALESCE(SUM(amount),0) FROM orders WHERE source='store' AND source_id = :sid3 AND status='paid') AS volume,
                    (SELECT COUNT(*) FROM orders WHERE source='store' AND source_id = :sid4 AND status='paid' AND shipping_info IS NOT NULL AND (logistics_status IS NULL OR logistics_status='pending')) AS pending_shipments",
                [':sid' => $store['id'], ':sid2' => $store['id'], ':sid3' => $store['id'], ':sid4' => $store['id']]
            );

            $products_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM store_products WHERE store_id = ?", [$store['id']])['c'] ?? 0);
            $coupons_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM store_coupons WHERE store_id = ?", [$store['id']])['c'] ?? 0);
            $coupon_usage_total = (int)($db->fetch("SELECT COUNT(*) AS c FROM store_coupon_usages WHERE store_id = ?", [$store['id']])['c'] ?? 0);
            $logistics_total = (int)($db->fetch(
                "SELECT COUNT(*) AS c
                 FROM orders o
                 WHERE o.source='store' AND o.source_id = ? AND o.shipping_info IS NOT NULL AND o.status='paid'",
                [$store['id']]
            )['c'] ?? 0);

            $products_total_pages = max(1, (int)ceil($products_total / $products_per_page));
            $coupons_total_pages = max(1, (int)ceil($coupons_total / $coupons_per_page));
            $coupon_usage_total_pages = max(1, (int)ceil($coupon_usage_total / $coupon_usage_per_page));
            $logistics_total_pages = max(1, (int)ceil($logistics_total / $logistics_per_page));
            $products_page = min($products_page, $products_total_pages);
            $coupons_page = min($coupons_page, $coupons_total_pages);
            $coupon_usage_page = min($coupon_usage_page, $coupon_usage_total_pages);
            $logistics_page = min($logistics_page, $logistics_total_pages);
            $products_offset = ($products_page - 1) * $products_per_page;
            $coupons_offset = ($coupons_page - 1) * $coupons_per_page;
            $coupon_usage_offset = ($coupon_usage_page - 1) * $coupon_usage_per_page;
            $logistics_offset = ($logistics_page - 1) * $logistics_per_page;

            $products = $db->fetchAll("SELECT * FROM store_products WHERE store_id = ? ORDER BY id DESC LIMIT $products_per_page OFFSET $products_offset", [$store['id']]);
            $coupons = $db->fetchAll("SELECT * FROM store_coupons WHERE store_id = ? ORDER BY created_at DESC LIMIT $coupons_per_page OFFSET $coupons_offset", [$store['id']]);
            $coupon_usages = $db->fetchAll(
                "SELECT u.*, c.code AS coupon_code
                 FROM store_coupon_usages u
                 LEFT JOIN store_coupons c ON c.id = u.coupon_id
                 WHERE u.store_id = ?
                 ORDER BY u.paid_at DESC, u.id DESC
                 LIMIT $coupon_usage_per_page OFFSET $coupon_usage_offset",
                [$store['id']]
            );
            $logistics_orders = $db->fetchAll(
                "SELECT o.*, p.name AS product_name, p.image_url
                 FROM orders o
                 LEFT JOIN store_products p ON p.id = o.product_id
                 WHERE o.source='store' AND o.source_id = ? AND o.shipping_info IS NOT NULL AND o.status='paid'
                 ORDER BY o.created_at DESC
                 LIMIT $logistics_per_page OFFSET $logistics_offset",
                [$store['id']]
            );
        } catch (Exception $e) {
            $db_error = true;
            $msg = store_alert('danger', __('merchant.store.error.product_schema'));
        }
    }
} catch (Exception $e) {
    $db_error = true;
    $msg = store_alert('danger', __('merchant.store.error.store_schema'));
}

$active_tab = $_GET['tab'] ?? 'settings';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (in_array($_POST['action'] ?? '', ['save_product', 'delete_product', 'delete_products_bulk', 'generate_demo_content'], true)) {
        $active_tab = 'products';
    }
    if (($_POST['action'] ?? '') === 'update_logistics') {
        $active_tab = 'logistics';
    }
    if (($_POST['action'] ?? '') === 'create_coupon') {
        $active_tab = 'marketing';
    }
}

function store_page_url(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}

$page_title = __('merchant.store.title');
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
        :root {
            --store-surface-muted: #f8fafc;
            --store-surface-accent: #eef2ff;
            --store-border-strong: #e5e7eb;
            --store-border-soft: #cbd5e1;
            --store-text-muted: #64748b;
            --store-text-strong: #0f172a;
            --store-badge-text: #3730a3;
            --store-stat-bg: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }
        [data-bs-theme="dark"] {
            --store-surface-muted: #111827;
            --store-surface-accent: rgba(99, 102, 241, 0.16);
            --store-border-strong: #374151;
            --store-border-soft: #475569;
            --store-text-muted: #94a3b8;
            --store-text-strong: #f9fafb;
            --store-badge-text: #c7d2fe;
            --store-stat-bg: linear-gradient(180deg, #1f2937 0%, #111827 100%);
        }
        .store-shell {
            display: grid;
            grid-template-columns: repeat(12, minmax(0, 1fr));
            gap: 16px;
        }
        .store-stat {
            grid-column: span 3;
            border: 1px solid var(--store-border-strong);
            border-radius: 14px;
            padding: 16px;
            background: var(--store-stat-bg);
        }
        .store-stat .label { font-size: 12px; color: var(--store-text-muted); text-transform: uppercase; letter-spacing: .05em; }
        .store-stat .value { font-size: 1.6rem; font-weight: 800; color: var(--store-text-strong); margin-top: 6px; }
        .mole-card.store-card {
            border-radius: 14px;
            border: 1px solid var(--store-border-strong);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.04);
        }
        .brand-preview {
            border: 1px dashed var(--store-border-soft);
            border-radius: 12px;
            padding: 14px;
            background: var(--store-surface-muted);
            min-height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .brand-preview img {
            max-height: 56px;
            max-width: 220px;
            object-fit: contain;
        }
        .tab-pane .section-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 12px;
            color: var(--store-text-strong);
        }
        .table img.product-thumb {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid var(--store-border-strong);
        }
        .table .badge-soft {
            background: var(--store-surface-accent);
            color: var(--store-badge-text);
            border: 1px solid var(--store-border-soft);
        }
        @media (max-width: 992px) {
            .store-stat { grid-column: span 6; }
        }
        @media (max-width: 576px) {
            .store-stat { grid-column: span 12; }
        }
        .uapi-file-input__native {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .uapi-file-input__label {
            display: flex;
            align-items: stretch;
            width: 100%;
            border: 1px solid var(--bs-border-color);
            border-radius: .75rem;
            overflow: hidden;
            background: var(--bs-body-bg);
            cursor: pointer;
        }
        .uapi-file-input__button {
            display: inline-flex;
            align-items: center;
            padding: .75rem 1rem;
            background: var(--bs-tertiary-bg);
            border-right: 1px solid var(--bs-border-color);
            color: var(--bs-body-color);
            white-space: nowrap;
        }
        .uapi-file-input__name {
            display: inline-flex;
            align-items: center;
            min-width: 0;
            flex: 1;
            padding: .75rem 1rem;
            color: var(--bs-secondary-color);
        }
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.store.title'); include __DIR__ . '/includes/user_topbar.php'; ?>

            <div class="container-fluid">
                <?php echo $msg; ?>

                <?php if ($db_error): ?>
                    <div class="card shadow-sm border-danger">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-database fa-3x text-danger mb-3"></i>
                            <h4 class="text-danger"><?php echo __('merchant.store.db_not_initialized'); ?></h4>
                            <p class="text-muted"><?php echo __('merchant.store.db_not_initialized_desc'); ?></p>
                            <form method="POST"><?php echo Helper::csrfField(); ?>
                                <input type="hidden" name="action" value="fix_db">
                                <button type="submit" class="btn btn-danger btn-lg rounded-pill px-5">
                                    <i class="fas fa-tools me-2"></i> <?php echo __('merchant.store.fix_db_now'); ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php else: ?>

                <?php if ($store): ?>
                <div class="store-shell mb-4">
                    <div class="store-stat">
                        <div class="label"><?php echo $tt('商品数量', 'Products'); ?></div>
                        <div class="value"><?php echo (int)($stats['products'] ?? 0); ?></div>
                    </div>
                    <div class="store-stat">
                        <div class="label"><?php echo $tt('订单总数', 'Orders'); ?></div>
                        <div class="value"><?php echo (int)($stats['orders'] ?? 0); ?></div>
                    </div>
                    <div class="store-stat">
                        <div class="label"><?php echo $tt('累计收入 (USDT)', 'Revenue (USDT)'); ?></div>
                        <div class="value"><?php echo number_format((float)($stats['volume'] ?? 0), 2); ?></div>
                    </div>
                    <div class="store-stat">
                        <div class="label"><?php echo $tt('待发货订单', 'Pending Shipment'); ?></div>
                        <div class="value"><?php echo (int)($stats['pending_shipments'] ?? 0); ?></div>
                    </div>
                </div>
                <?php endif; ?>

                <ul class="nav nav-tabs mb-4" id="storeTabs">
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab === 'settings' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#settings" data-tab="settings"><?php echo __('merchant.store.tabs.settings'); ?></button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab === 'products' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#products" data-tab="products"><?php echo __('merchant.store.tabs.products'); ?></button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab === 'logistics' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#logistics" data-tab="logistics"><?php echo $tt('物流管理', 'Logistics'); ?></button>
                    </li>
                    <li class="nav-item">
                        <button class="nav-link <?php echo $active_tab === 'marketing' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#marketing" data-tab="marketing"><?php echo __('merchant.marketing.title'); ?></button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade <?php echo $active_tab === 'settings' ? 'show active' : ''; ?>" id="settings">
                        <div class="mole-card store-card">
                            <form method="POST" enctype="multipart/form-data"><?php echo Helper::csrfField(); ?>
                                <input type="hidden" name="action" value="save_settings">

                                <div class="row g-4">
                                    <div class="col-lg-8">
                                        <div class="section-title"><?php echo $tt('店铺基础信息', 'Store Basics'); ?></div>
                                        <div class="mb-3">
                                            <label class="form-label"><?php echo __('merchant.store.form.name'); ?></label>
                                            <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($store['name'] ?? ''); ?>" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label"><?php echo __('merchant.store.form.slug'); ?></label>
                                            <div class="input-group">
                                                <span class="input-group-text"><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? ''); ?>/shop.php?store=</span>
                                                <input type="text" name="slug" class="form-control" value="<?php echo htmlspecialchars($store['slug'] ?? ''); ?>" required pattern="[a-zA-Z0-9-_]+" title="<?php echo __('merchant.store.form.slug_title'); ?>">
                                            </div>
                                            <?php if (isset($store['slug'])): ?>
                                                <?php $store_link = '/shop.php?store=' . $store['slug']; ?>
                                                <div class="form-text mt-2">
                                                    <?php echo __('merchant.store.form.store_link'); ?>:
                                                    <a href="<?php echo htmlspecialchars($store_link); ?>" target="_blank" class="text-primary"><?php echo htmlspecialchars($store_link); ?></a>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="copyStoreLink('<?php echo htmlspecialchars($store_link); ?>')"><?php echo $tt('复制', 'Copy'); ?></button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label"><?php echo __('merchant.store.form.description'); ?></label>
                                            <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($store['description'] ?? ''); ?></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label"><?php echo __('merchant.store.form.contact'); ?></label>
                                            <input type="text" name="contact_info" class="form-control" value="<?php echo htmlspecialchars($store['contact_info'] ?? ''); ?>" placeholder="<?php echo __('merchant.store.form.contact_placeholder'); ?>">
                                        </div>
                                    </div>

                                    <div class="col-lg-4">
                                        <div class="section-title"><?php echo $tt('品牌 Logo（用于店铺与收据邮件）', 'Brand Logo (used in storefront and receipts)'); ?></div>
                                        <div class="brand-preview mb-3">
                                            <?php if (!empty($store['logo_url'])): ?>
                                                <img src="<?php echo htmlspecialchars($store['logo_url']); ?>" alt="logo">
                                            <?php else: ?>
                                                <span class="text-muted"><?php echo $tt('暂无 Logo', 'No logo yet'); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label"><?php echo $tt('Logo 图片上传', 'Upload Logo'); ?></label>
                                            <div class="uapi-file-input">
                                                <input type="file" id="storeLogoFile" name="logo_file" class="uapi-file-input__native" accept="image/png,image/jpeg,image/webp">
                                                <label for="storeLogoFile" class="uapi-file-input__label">
                                                    <span class="uapi-file-input__button"><?php echo $tt('选择文件', 'Choose File'); ?></span>
                                                    <span class="uapi-file-input__name" data-empty="<?php echo htmlspecialchars($tt('未选择任何文件', 'No file chosen')); ?>"><?php echo $tt('未选择任何文件', 'No file chosen'); ?></span>
                                                </label>
                                            </div>
                                            <div class="form-text"><?php echo $tt('建议透明底 PNG，最大 2MB。', 'Recommended transparent PNG, max 2MB.'); ?></div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label"><?php echo $tt('或填写 Logo 链接', 'Or use Logo URL'); ?></label>
                                            <input type="url" name="logo_url" class="form-control" placeholder="https://..." value="<?php echo htmlspecialchars($store['logo_url'] ?? ''); ?>">
                                        </div>
                                        <div class="form-check mb-3">
                                            <input class="form-check-input" type="checkbox" name="remove_logo" id="removeLogo" value="1">
                                            <label class="form-check-label" for="removeLogo"><?php echo $tt('移除当前 Logo', 'Remove current logo'); ?></label>
                                        </div>
                                        <div class="alert alert-light border small mb-0">
                                            <strong><?php echo $tt('自动收据说明', 'Auto receipt'); ?>:</strong>
                                            <?php echo $tt('网店订单支付成功后会自动发送收据到买家邮箱。', 'A receipt is sent to buyer email after successful store payment.'); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="mt-4">
                                    <button type="submit" class="btn btn-primary"><?php echo __('merchant.store.form.save_settings'); ?></button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'products' ? 'show active' : ''; ?>" id="products">
                        <div class="mole-card store-card">
                            <form method="POST" id="bulkDeleteForm"><?php echo Helper::csrfField(); ?>
                                <input type="hidden" name="action" value="delete_products_bulk">
                                <div class="d-flex justify-content-between mb-3 align-items-center flex-wrap gap-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <h5 class="mb-0"><?php echo __('merchant.store.products.list'); ?></h5>
                                        <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm(<?php echo json_encode(__('merchant.store.products.confirm_bulk_delete')); ?>);" id="bulkDeleteBtn" style="display:none;">
                                            <i class="fas fa-trash-alt me-1"></i> <?php echo __('merchant.store.products.bulk_delete'); ?>
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="submitDemoGen()"><?php echo __('merchant.store.products.generate_demo'); ?></button>
                                        <button type="button" class="btn btn-primary btn-sm" onclick="openProductModal()"><?php echo __('merchant.store.products.add_product'); ?></button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead>
                                            <tr>
                                                <th width="40"><input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleAll(this)"></th>
                                                <th><?php echo __('merchant.store.table.image'); ?></th>
                                                <th><?php echo __('merchant.store.table.name'); ?></th>
                                                <th><?php echo __('merchant.store.table.category'); ?></th>
                                                <th><?php echo __('merchant.store.table.price'); ?></th>
                                                <th><?php echo $tt('类型', 'Type'); ?></th>
                                                <th><?php echo __('merchant.store.table.featured'); ?></th>
                                                <th><?php echo __('merchant.store.table.actions'); ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($products as $p): ?>
                                            <tr>
                                                <td><input type="checkbox" name="selected_ids[]" value="<?php echo $p['id']; ?>" class="form-check-input product-check" onchange="checkBulkBtn()"></td>
                                                <td>
                                                    <?php if (!empty($p['image_url'])): ?>
                                                        <img src="<?php echo htmlspecialchars($p['image_url']); ?>" alt="img" class="product-thumb">
                                                    <?php else: ?>
                                                        <span class="badge bg-light text-dark"><?php echo __('merchant.store.no_image'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                                <td><span class="badge badge-soft"><?php echo htmlspecialchars($p['category'] ?? 'General'); ?></span></td>
                                                <td><?php echo number_format((float)$p['price'], 2); ?></td>
                                                <td>
                                                    <?php if (!empty($p['is_physical'])): ?>
                                                        <span class="badge bg-warning text-dark"><?php echo $tt('实体', 'Physical'); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-info-subtle text-info-emphasis"><?php echo $tt('虚拟', 'Digital'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if (!empty($p['is_featured'])): ?>
                                                        <span class="badge bg-success"><?php echo __('merchant.store.yes'); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-light border" onclick='openProductModal(<?php echo json_encode($p); ?>)'><?php echo __('merchant.store.edit'); ?></button>
                                                    <button type="button" class="btn btn-sm btn-danger text-white" onclick="deleteSingle(<?php echo $p['id']; ?>)"><?php echo __('merchant.store.delete'); ?></button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($products)): ?>
                                                <tr><td colspan="8" class="text-center text-muted py-4"><?php echo $tt('暂无商品，请先添加。', 'No products yet.'); ?></td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <small class="text-muted">共 <?php echo $products_total; ?> 条</small>
                                    <div class="btn-group btn-group-sm">
                                        <a class="btn btn-outline-secondary <?php echo $products_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(store_page_url(['tab' => 'products', 'p_products' => max(1, $products_page - 1)])); ?>">上一页</a>
                                        <span class="btn btn-light disabled"><?php echo $products_page; ?> / <?php echo $products_total_pages; ?></span>
                                        <a class="btn btn-outline-secondary <?php echo $products_page >= $products_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(store_page_url(['tab' => 'products', 'p_products' => min($products_total_pages, $products_page + 1)])); ?>">下一页</a>
                                    </div>
                                </div>
                            </form>

                            <form method="POST" id="singleDeleteForm" style="display:none;"><?php echo Helper::csrfField(); ?>
                                <input type="hidden" name="action" value="delete_product">
                                <input type="hidden" name="product_id" id="deleteId">
                            </form>
                            <form method="POST" id="demoGenForm" style="display:none;"><?php echo Helper::csrfField(); ?>
                                <input type="hidden" name="action" value="generate_demo_content">
                            </form>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'logistics' ? 'show active' : ''; ?>" id="logistics">
                        <div class="mole-card store-card p-0 overflow-hidden">
                            <div class="card-header bg-white py-3 border-bottom">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-truck text-primary me-2"></i><?php echo $tt('待发货 / 已发货订单', 'Pending / Shipped Orders'); ?></h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4"><?php echo $tt('订单号 / 时间', 'Order / Time'); ?></th>
                                            <th><?php echo $tt('商品', 'Product'); ?></th>
                                            <th><?php echo $tt('收货信息', 'Shipping Info'); ?></th>
                                            <th><?php echo $tt('物流状态', 'Status'); ?></th>
                                            <th class="text-end pe-4"><?php echo $tt('操作', 'Actions'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logistics_orders as $o): ?>
                                            <?php $shipping = json_decode((string)$o['shipping_info'], true) ?: []; ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="fw-bold text-dark">#<?php echo htmlspecialchars($o['order_no']); ?></div>
                                                    <div class="small text-muted"><?php echo htmlspecialchars($o['created_at']); ?></div>
                                                </td>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <?php if (!empty($o['image_url'])): ?>
                                                            <img src="<?php echo htmlspecialchars($o['image_url']); ?>" class="product-thumb me-2" alt="thumb">
                                                        <?php endif; ?>
                                                        <span class="fw-bold"><?php echo htmlspecialchars($o['product_name'] ?? '-'); ?></span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="small">
                                                        <div class="fw-bold"><?php echo htmlspecialchars($shipping['name'] ?? '-'); ?> <span class="text-muted"><?php echo htmlspecialchars($shipping['phone'] ?? '-'); ?></span></div>
                                                        <div class="text-secondary"><?php echo htmlspecialchars(($shipping['city'] ?? '') . ' ' . ($shipping['address'] ?? '-')); ?></div>
                                                        <div class="text-secondary"><?php echo $tt('邮箱', 'Email'); ?>: <?php echo htmlspecialchars($o['customer_email'] ?? '-'); ?></div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (($o['logistics_status'] ?? 'pending') === 'pending'): ?>
                                                        <span class="badge bg-warning text-dark"><?php echo $tt('待发货', 'Pending'); ?></span>
                                                    <?php elseif ($o['logistics_status'] === 'shipped'): ?>
                                                        <span class="badge bg-primary"><?php echo $tt('已发货', 'Shipped'); ?></span>
                                                        <div class="small text-muted mt-1"><?php echo htmlspecialchars((string)$o['logistics_company']); ?>: <?php echo htmlspecialchars((string)$o['tracking_number']); ?></div>
                                                    <?php elseif ($o['logistics_status'] === 'returned'): ?>
                                                        <span class="badge bg-danger"><?php echo $tt('已退回', 'Returned'); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success"><?php echo $tt('已送达', 'Delivered'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end pe-4">
                                                    <button class="btn btn-sm btn-outline-primary" onclick="openShipModal('<?php echo htmlspecialchars($o['order_no']); ?>', '<?php echo htmlspecialchars((string)$o['tracking_number']); ?>', '<?php echo htmlspecialchars((string)$o['logistics_company']); ?>', '<?php echo htmlspecialchars((string)($o['logistics_status'] ?? 'pending')); ?>')">
                                                        <i class="fas fa-edit"></i> <?php echo $tt('发货 / 更新', 'Ship / Update'); ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($logistics_orders)): ?>
                                            <tr><td colspan="5" class="text-center py-5 text-muted"><?php echo $tt('暂无需要发货的订单。', 'No shippable orders yet.'); ?></td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-body border-top d-flex justify-content-between align-items-center">
                                <small class="text-muted">共 <?php echo $logistics_total; ?> 条</small>
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-secondary <?php echo $logistics_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(store_page_url(['tab' => 'logistics', 'p_logistics' => max(1, $logistics_page - 1)])); ?>">上一页</a>
                                    <span class="btn btn-light disabled"><?php echo $logistics_page; ?> / <?php echo $logistics_total_pages; ?></span>
                                    <a class="btn btn-outline-secondary <?php echo $logistics_page >= $logistics_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(store_page_url(['tab' => 'logistics', 'p_logistics' => min($logistics_total_pages, $logistics_page + 1)])); ?>">下一页</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade <?php echo $active_tab === 'marketing' ? 'show active' : ''; ?>" id="marketing">
                        <div class="mole-card store-card p-0 overflow-hidden mb-4">
                            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-ticket-alt text-warning me-2"></i><?php echo __('merchant.marketing.coupon_management'); ?></h5>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#couponModal">
                                    <i class="fas fa-plus me-1"></i><?php echo __('merchant.marketing.new_coupon'); ?>
                                </button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4"><?php echo __('merchant.marketing.table.code'); ?></th>
                                            <th><?php echo __('merchant.marketing.table.type'); ?></th>
                                            <th><?php echo __('merchant.marketing.table.value'); ?></th>
                                            <th><?php echo __('merchant.marketing.table.used'); ?></th>
                                            <th><?php echo __('merchant.marketing.table.status'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($coupons)): ?>
                                            <tr><td colspan="5" class="text-center py-4 text-muted"><?php echo __('merchant.marketing.no_coupons'); ?></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($coupons as $c): ?>
                                            <tr>
                                                <td class="ps-4 font-monospace fw-bold text-primary"><?php echo htmlspecialchars($c['code']); ?></td>
                                                <td>
                                                    <?php if($c['type'] == 'fixed'): ?>
                                                        <span class="badge bg-info text-dark"><?php echo __('merchant.marketing.type.fixed'); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark"><?php echo __('merchant.marketing.type.percent'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="fw-bold"><?php echo $c['type'] === 'fixed' ? '$' . number_format((float)$c['value'], 2) : number_format((float)$c['value'], 2) . '% OFF'; ?></td>
                                                <td><span class="badge bg-secondary"><?php echo (int)$c['used_count']; ?> / <?php echo (int)$c['usage_limit'] === -1 ? '∞' : (int)$c['usage_limit']; ?></span></td>
                                                <td>
                                                    <?php if($c['status'] == 'active'): ?>
                                                        <span class="badge bg-success"><?php echo __('merchant.marketing.status.active'); ?></span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger"><?php echo __('merchant.marketing.status.ended'); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-body border-top d-flex justify-content-between align-items-center">
                                <small class="text-muted">共 <?php echo $coupons_total; ?> 条</small>
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-secondary <?php echo $coupons_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(store_page_url(['tab' => 'marketing', 'p_coupons' => max(1, $coupons_page - 1)])); ?>">上一页</a>
                                    <span class="btn btn-light disabled"><?php echo $coupons_page; ?> / <?php echo $coupons_total_pages; ?></span>
                                    <a class="btn btn-outline-secondary <?php echo $coupons_page >= $coupons_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(store_page_url(['tab' => 'marketing', 'p_coupons' => min($coupons_total_pages, $coupons_page + 1)])); ?>">下一页</a>
                                </div>
                            </div>
                        </div>
                        <div class="mole-card store-card p-0 overflow-hidden">
                            <div class="card-header bg-white py-3 border-bottom">
                                <h5 class="mb-0 fw-bold"><i class="fas fa-history text-info me-2"></i><?php echo $tt('优惠码使用记录', 'Coupon Usage History'); ?></h5>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4"><?php echo $tt('优惠码', 'Coupon'); ?></th>
                                            <th><?php echo $tt('订单号', 'Order No.'); ?></th>
                                            <th><?php echo $tt('商品', 'Product'); ?></th>
                                            <th><?php echo $tt('优惠金额', 'Discount'); ?></th>
                                            <th><?php echo $tt('支付时间', 'Paid At'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($coupon_usages)): ?>
                                            <tr><td colspan="5" class="text-center py-4 text-muted"><?php echo $tt('暂无使用记录', 'No usage records yet'); ?></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($coupon_usages as $u): ?>
                                            <tr>
                                                <td class="ps-4 font-monospace fw-bold text-primary"><?php echo htmlspecialchars((string)($u['coupon_code'] ?? '-')); ?></td>
                                                <td class="font-monospace"><?php echo htmlspecialchars((string)($u['order_no'] ?? '-')); ?></td>
                                                <td><?php echo htmlspecialchars((string)($u['product_name'] ?? '-')); ?></td>
                                                <td class="fw-bold">$<?php echo number_format((float)($u['discount_amount'] ?? 0), 2); ?> <small class="text-muted"><?php echo htmlspecialchars((string)($u['currency'] ?? 'USDT')); ?></small></td>
                                                <td><?php echo htmlspecialchars((string)($u['paid_at'] ?? '-')); ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="card-body border-top d-flex justify-content-between align-items-center">
                                <small class="text-muted">共 <?php echo $coupon_usage_total; ?> 条</small>
                                <div class="btn-group btn-group-sm">
                                    <a class="btn btn-outline-secondary <?php echo $coupon_usage_page <= 1 ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(store_page_url(['tab' => 'marketing', 'p_coupon_usage' => max(1, $coupon_usage_page - 1)])); ?>">上一页</a>
                                    <span class="btn btn-light disabled"><?php echo $coupon_usage_page; ?> / <?php echo $coupon_usage_total_pages; ?></span>
                                    <a class="btn btn-outline-secondary <?php echo $coupon_usage_page >= $coupon_usage_total_pages ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars(store_page_url(['tab' => 'marketing', 'p_coupon_usage' => min($coupon_usage_total_pages, $coupon_usage_page + 1)])); ?>">下一页</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content"><?php echo Helper::csrfField(); ?>
            <input type="hidden" name="action" value="save_product">
            <input type="hidden" name="product_id" id="modalProductId">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo __('merchant.store.modal.edit_product'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label"><?php echo __('merchant.store.modal.product_name'); ?></label>
                    <input type="text" name="p_name" id="modalName" class="form-control" required>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                         <label class="form-label"><?php echo __('merchant.store.modal.price'); ?></label>
                         <input type="number" name="p_price" id="modalPrice" class="form-control" step="0.01" required>
                    </div>
                    <div class="col-md-6 mb-3">
                         <label class="form-label"><?php echo __('merchant.store.modal.category'); ?></label>
                         <input type="text" name="p_category" id="modalCategory" class="form-control" placeholder="<?php echo __('merchant.store.modal.category_placeholder'); ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo __('merchant.store.modal.image_url'); ?></label>
                    <input type="url" name="p_image" id="modalImage" class="form-control" placeholder="https://...">
                </div>

                <div class="mb-3">
                    <div class="form-check form-switch border rounded bg-light p-2 ps-5">
                        <input class="form-check-input ms-0 mt-1" type="checkbox" id="isPhysical" name="is_physical" value="1" onchange="toggleShipping(this)" style="margin-left: -2.5em !important;">
                        <label class="form-check-label fw-bold d-block pt-1" for="isPhysical">
                            <?php echo __('merchant.store.modal.physical_product'); ?>
                        </label>
                        <div class="form-text mt-1 small" id="shippingHint" style="display:none;">
                            <?php echo __('merchant.store.modal.shipping_hint'); ?>
                        </div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label"><?php echo __('merchant.store.modal.features'); ?></label>
                    <textarea name="p_features" id="modalFeatures" class="form-control" rows="3" placeholder="<?php echo __('merchant.store.modal.features_placeholder'); ?>"></textarea>
                    <div class="form-text"><?php echo __('merchant.store.modal.features_hint'); ?></div>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" name="p_featured" class="form-check-input" id="modalFeatured">
                    <label class="form-check-label" for="modalFeatured"><?php echo __('merchant.store.modal.featured'); ?></label>
                </div>

                <div class="mb-3">
                    <label class="form-label"><?php echo __('merchant.store.modal.description'); ?></label>
                    <textarea name="p_desc" id="modalDesc" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                <button type="submit" class="btn btn-primary"><?php echo __('merchant.common.save'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="shipModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" onsubmit="disableShipSubmit(this)"><?php echo Helper::csrfField(); ?>
                <input type="hidden" name="action" value="update_logistics">
                <input type="hidden" name="order_no" id="shipOrderNo">
                <input type="hidden" name="tab" value="logistics">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo $tt('发货信息录入', 'Update Logistics'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo $tt('物流公司', 'Carrier'); ?></label>
                        <select name="logistics_company" id="shipCompany" class="form-select">
                            <option value="顺丰速运"><?php echo $tt('顺丰速运', 'SF Express'); ?></option>
                            <option value="圆通速递"><?php echo $tt('圆通速递', 'YTO Express'); ?></option>
                            <option value="中通快递"><?php echo $tt('中通快递', 'ZTO Express'); ?></option>
                            <option value="京东物流"><?php echo $tt('京东物流', 'JD Logistics'); ?></option>
                            <option value="DHL">DHL</option>
                            <option value="FedEx">FedEx</option>
                            <option value="Other"><?php echo $tt('其他', 'Other'); ?></option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $tt('运单号', 'Tracking Number'); ?></label>
                        <input type="text" name="tracking_number" id="shipTracking" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $tt('物流状态', 'Status'); ?></label>
                        <select name="status" id="shipStatus" class="form-select">
                            <option value="pending"><?php echo $tt('待发货', 'Pending'); ?></option>
                            <option value="shipped"><?php echo $tt('已发货', 'Shipped'); ?></option>
                            <option value="delivered"><?php echo $tt('已送达', 'Delivered'); ?></option>
                            <option value="returned"><?php echo $tt('已退回', 'Returned'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo $tt('保存', 'Save'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="couponModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" onsubmit="disableCouponSubmit(this)"><?php echo Helper::csrfField(); ?>
                <input type="hidden" name="action" value="create_coupon">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('merchant.marketing.modal.create_coupon'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('merchant.marketing.modal.code_label'); ?></label>
                        <input type="text" name="code" class="form-control" required style="text-transform: uppercase;">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col">
                            <label class="form-label"><?php echo __('merchant.marketing.modal.type'); ?></label>
                            <select name="type" class="form-select">
                                <option value="fixed"><?php echo __('merchant.marketing.modal.fixed'); ?></option>
                                <option value="percent"><?php echo __('merchant.marketing.modal.percent'); ?></option>
                            </select>
                        </div>
                        <div class="col">
                            <label class="form-label"><?php echo __('merchant.marketing.modal.value'); ?></label>
                            <input type="number" step="0.01" name="value" class="form-control" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('merchant.marketing.modal.usage_limit'); ?></label>
                        <input type="number" name="usage_limit" class="form-control" value="-1">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('merchant.marketing.modal.publish'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyStoreLink(path) {
    const url = window.location.origin + path;
    navigator.clipboard.writeText(url).then(function () {
        alert(<?php echo json_encode($tt('店铺链接已复制', 'Store link copied')); ?>);
    });
}

function openProductModal(product = null) {
    if (product) {
        document.getElementById('modalProductId').value = product.id || '';
        document.getElementById('modalName').value = product.name || '';
        document.getElementById('modalPrice').value = product.price || '';
        document.getElementById('modalDesc').value = product.description || '';
        document.getElementById('modalCategory').value = product.category || '';
        document.getElementById('modalImage').value = product.image_url || '';
        document.getElementById('modalFeatures').value = product.features || '';
        document.getElementById('modalFeatured').checked = Number(product.is_featured) === 1;
        document.getElementById('isPhysical').checked = Number(product.is_physical) === 1;
    } else {
        document.getElementById('modalProductId').value = '';
        document.getElementById('modalName').value = '';
        document.getElementById('modalPrice').value = '';
        document.getElementById('modalDesc').value = '';
        document.getElementById('modalCategory').value = '';
        document.getElementById('modalImage').value = '';
        document.getElementById('modalFeatures').value = '';
        document.getElementById('modalFeatured').checked = false;
        document.getElementById('isPhysical').checked = false;
    }

    toggleShipping(document.getElementById('isPhysical'));
    new bootstrap.Modal(document.getElementById('productModal')).show();
}

function toggleShipping(el) {
    const hint = document.getElementById('shippingHint');
    hint.style.display = el.checked ? 'block' : 'none';
}

function deleteSingle(id) {
    if (confirm(<?php echo json_encode(__('merchant.store.confirm_delete_single')); ?>)) {
        document.getElementById('deleteId').value = id;
        document.getElementById('singleDeleteForm').submit();
    }
}

function submitDemoGen() {
    if (confirm(<?php echo json_encode(__('merchant.store.confirm_generate_demo')); ?>)) {
        document.getElementById('demoGenForm').submit();
    }
}

function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.product-check');
    checkboxes.forEach(function (item) { item.checked = source.checked; });
    checkBulkBtn();
}

function checkBulkBtn() {
    const checked = document.querySelectorAll('.product-check:checked');
    document.getElementById('bulkDeleteBtn').style.display = checked.length > 0 ? 'block' : 'none';
}

function openShipModal(orderNo, tracking, company, status) {
    document.getElementById('shipOrderNo').value = orderNo;
    document.getElementById('shipTracking').value = tracking || '';
    if (company) {
        document.getElementById('shipCompany').value = company;
    }
    document.getElementById('shipStatus').value = status || 'pending';
    new bootstrap.Modal(document.getElementById('shipModal')).show();
}

function disableShipSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerText = <?php echo json_encode($tt('保存中...', 'Saving...')); ?>;
    }
}

function disableCouponSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) {
        btn.disabled = true;
        btn.innerText = <?php echo json_encode(__('merchant.marketing.publishing')); ?>;
    }
}

document.querySelectorAll('#storeTabs [data-bs-toggle="tab"]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function (e) {
        const tab = e.target.getAttribute('data-tab');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    });
});

document.querySelectorAll('.uapi-file-input__native').forEach(function (input) {
    input.addEventListener('change', function () {
        const label = input.closest('.uapi-file-input')?.querySelector('.uapi-file-input__name');
        if (!label) return;
        const fallback = label.getAttribute('data-empty') || '';
        const fileName = input.files && input.files.length > 0 ? input.files[0].name : fallback;
        label.textContent = fileName;
    });
});
</script>
</body>
</html>
