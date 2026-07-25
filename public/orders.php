<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Services/CryptoService.php';
require_once __DIR__ . '/../src/Services/WebhookService.php';
require_once __DIR__ . '/../src/Services/StoreReceiptService.php';
require_once __DIR__ . '/../src/Services/StoreCouponService.php';
I18n::init();
$db = Database::getInstance();
// Auto-migrate on access to ensure columns exist
$db->autoMigrate();

$user_id = $_SESSION['user_id'];
$is_en = I18n::getLang() === 'en';
$tt = static function (string $zh, string $en) use ($is_en): string {
    return $is_en ? $en : $zh;
};

if (!function_exists('merchant_orders_set_flash')) {
    function merchant_orders_set_flash(string $type, string $message): void {
        $_SESSION['merchant_orders_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('merchant_orders_check_chain')) {
    function merchant_orders_check_chain(array $order): ?array {
        $address = (string)($order['wallet_address'] ?? '');
        $chain = strtolower((string)($order['chain'] ?? ''));
        $amount = (float)($order['amount'] ?? 0);
        $currency = strtoupper((string)($order['currency'] ?? 'USDT'));
        $createdTs = strtotime((string)($order['created_at'] ?? ''));
        if ($address === '' || $chain === '' || $amount <= 0 || !$createdTs) {
            return null;
        }

        global $chains_config;
        if (!isset($chains_config)) {
            require __DIR__ . '/../config/config.php';
        }

        CryptoService::setExternalUsageContext([
            'user_id' => (int)($order['user_id'] ?? 0),
            'order_id' => (int)($order['id'] ?? 0),
            'order_no' => (string)($order['order_no'] ?? ''),
            'chain' => $chain,
            'source' => 'merchant_orders_manual',
            'trigger_mode' => !empty($order['is_fast_sync']) ? 'fast_sync' : 'manual',
        ]);
        try {
            if ($chain === 'trc20') {
                if ($currency !== 'USDT') {
                    return null;
                }
                return CryptoService::checkTrc20($address, $amount, $createdTs) ?: null;
            }
            if ($chain === 'solana') {
                return CryptoService::checkSolana($address, $amount, $createdTs, $currency) ?: null;
            }

            $keys = [];
            if (isset($chains_config[$chain]) && $chain !== 'trc20') {
                $keys = [$chain];
            } else {
                foreach ($chains_config as $k => $v) {
                    if ($k !== 'trc20') {
                        $keys[] = $k;
                    }
                }
            }

            foreach ($keys as $k) {
                $tx = CryptoService::checkEvm($k, $address, $amount, $createdTs, $currency);
                if (!empty($tx['hash'])) {
                    return $tx;
                }
            }
        } finally {
            CryptoService::clearExternalUsageContext();
        }

        return null;
    }
}

if (!function_exists('merchant_orders_allowed_currencies')) {
    function merchant_orders_allowed_currencies(string $chain): array {
        $chain = strtolower(trim($chain));
        if ($chain === 'trc20') {
            return ['USDT'];
        }
        return ['USDT', 'USDC'];
    }
}

if (!function_exists('merchant_orders_get_nested_value')) {
    function merchant_orders_get_nested_value(array $data, array $path, $default = '')
    {
        $cursor = $data;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return $default;
            }
            $cursor = $cursor[$key];
        }
        return $cursor;
    }
}

if (!function_exists('merchant_orders_first_non_empty_str')) {
    function merchant_orders_first_non_empty_str(...$values): string
    {
        foreach ($values as $v) {
            $s = trim((string)$v);
            if ($s !== '') {
                return $s;
            }
        }
        return '';
    }
}

if (!function_exists('merchant_orders_extract_binance_meta')) {
    function merchant_orders_extract_binance_meta(array $payload): array
    {
        $data = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            $data = $payload['data'];
        } elseif (isset($payload['data']) && is_string($payload['data'])) {
            $decoded = json_decode((string)$payload['data'], true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
        if (empty($data)) {
            $data = $payload;
        }

        $paymentInfo = [];
        if (isset($data['paymentInfo']) && is_array($data['paymentInfo'])) {
            $paymentInfo = $data['paymentInfo'];
        }

        $payerUid = merchant_orders_first_non_empty_str(
            merchant_orders_get_nested_value($paymentInfo, ['payerId']),
            merchant_orders_get_nested_value($paymentInfo, ['payerBuid']),
            merchant_orders_get_nested_value($data, ['payerId']),
            merchant_orders_get_nested_value($data, ['payerBuid'])
        );
        $openUserId = merchant_orders_first_non_empty_str(
            merchant_orders_get_nested_value($paymentInfo, ['openUserId']),
            merchant_orders_get_nested_value($paymentInfo, ['payerOpenId']),
            merchant_orders_get_nested_value($data, ['openUserId']),
            merchant_orders_get_nested_value($data, ['payerOpenId'])
        );
        $merchantId = merchant_orders_first_non_empty_str(
            merchant_orders_get_nested_value($paymentInfo, ['payeeId']),
            merchant_orders_get_nested_value($data, ['merchantId'])
        );

        return [
            'payer_uid' => $payerUid,
            'open_user_id' => $openUserId,
            'merchant_id' => $merchantId,
        ];
    }
}

$merchant_orders_has_notes_column = false;
try {
    $notesCol = $db->fetch(
        "SELECT COUNT(*) AS c
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'orders'
           AND column_name = 'notes'"
    );
    $merchant_orders_has_notes_column = ((int)($notesCol['c'] ?? 0) > 0);
    if (!$merchant_orders_has_notes_column) {
        $db->query("ALTER TABLE orders ADD COLUMN notes TEXT DEFAULT NULL");
        $merchant_orders_has_notes_column = true;
    }
} catch (Throwable $e) {
    $merchant_orders_has_notes_column = false;
}

if (empty($_SESSION['merchant_orders_csrf_token'])) {
    $_SESSION['merchant_orders_csrf_token'] = bin2hex(random_bytes(32));
}
$merchant_orders_csrf_token = (string)$_SESSION['merchant_orders_csrf_token'];

// Site Info
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';
$page_title = __('merchant.orders.title');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals($merchant_orders_csrf_token, $csrf)) {
        merchant_orders_set_flash('danger', $tt('请求校验失败，请刷新后重试。', 'Request validation failed. Please refresh and try again.'));
        header('Location: orders.php?panel=list');
        exit;
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'verify_exception') {
        $orderId = (int)($_POST['exception_order_id'] ?? 0);
        if ($orderId > 0) {
            $order = $db->fetch(
                "SELECT o.*, w.address AS wallet_address
                 FROM orders o
                 LEFT JOIN wallets w ON o.wallet_id = w.id
                 WHERE o.id = ? AND o.user_id = ?
                 LIMIT 1",
                [$orderId, $user_id]
            );
            if (!$order) {
                merchant_orders_set_flash('danger', $tt('订单不存在或无权限。', 'Order not found or access denied.'));
            } elseif ((string)$order['status'] === 'paid') {
                merchant_orders_set_flash('info', $tt('订单已是支付状态。', 'Order is already paid.'));
            } else {
                try {
                    $allowedCurrencies = merchant_orders_allowed_currencies((string)($order['chain'] ?? ''));
                    $verifyCurrency = strtoupper(trim((string)($_POST['verify_currency'] ?? (string)($order['currency'] ?? 'USDT'))));
                    if (!in_array($verifyCurrency, $allowedCurrencies, true)) {
                        $verifyCurrency = (string)($allowedCurrencies[0] ?? 'USDT');
                    }
                    $orderForCheck = $order;
                    $orderForCheck['currency'] = $verifyCurrency;
                    $tx = merchant_orders_check_chain($orderForCheck);
                    if (!empty($tx['hash'])) {
                        $oldStatus = (string)($order['status'] ?? 'pending');
                        $db->query(
                            "UPDATE orders
                             SET status='paid',
                                 pay_provider='crypto',
                                 paid_at=NOW(),
                                 tx_hash=?,
                                 currency=?,
                                 updated_at=NOW(),
                                 last_external_sync=NOW()
                             WHERE id=? AND user_id=?",
                            [(string)$tx['hash'], $verifyCurrency, $orderId, $user_id]
                        );
                        if ($oldStatus !== 'paid') {
                            StoreCouponService::applyOnPaid($db, (int)$orderId);
                        }
                        merchant_orders_set_flash('success', $tt('链上核验成功，订单已标记为已支付。', 'On-chain verification succeeded. The order has been marked as paid.'));
                    } else {
                        merchant_orders_set_flash('warning', $tt('未找到匹配的链上交易。', 'No matching on-chain transaction found.'));
                    }
                } catch (Throwable $e) {
                    merchant_orders_set_flash('danger', $tt('链上核验失败：', 'Verification failed: ') . $e->getMessage());
                }
            }
        }
        header('Location: orders.php?panel=list');
        exit;
    }

    if ($action === 'update_amount') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newAmount = (float)($_POST['new_amount'] ?? 0);
        $newCurrencyRaw = strtoupper(trim((string)($_POST['new_currency'] ?? '')));
        $note = trim((string)($_POST['note'] ?? ''));
        if ($orderId <= 0 || $newAmount <= 0) {
            merchant_orders_set_flash('danger', $tt('金额无效，请重新输入。', 'Invalid amount. Please try again.'));
            header('Location: orders.php?panel=list');
            exit;
        }

        try {
            $order = $db->fetch(
                "SELECT o.*, w.address AS wallet_address
                 FROM orders o
                 LEFT JOIN wallets w ON o.wallet_id = w.id
                 WHERE o.id = ? AND o.user_id = ?
                 LIMIT 1",
                [$orderId, $user_id]
            );
            if (!$order) {
                merchant_orders_set_flash('danger', $tt('订单不存在或无权限。', 'Order not found or access denied.'));
                header('Location: orders.php?panel=list');
                exit;
            }

            $oldStatus = (string)($order['status'] ?? 'pending');
            $allowedCurrencies = merchant_orders_allowed_currencies((string)($order['chain'] ?? ''));
            $newCurrency = $newCurrencyRaw !== '' ? $newCurrencyRaw : strtoupper((string)($order['currency'] ?? 'USDT'));
            if (!in_array($newCurrency, $allowedCurrencies, true)) {
                $newCurrency = (string)($allowedCurrencies[0] ?? 'USDT');
            }
            $orderForCheck = $order;
            $orderForCheck['amount'] = $newAmount;
            $orderForCheck['currency'] = $newCurrency;
            $tx = merchant_orders_check_chain($orderForCheck);
            if (!empty($tx['hash'])) {
                global $merchant_orders_has_notes_column;
                if ($merchant_orders_has_notes_column && $note !== '') {
                    $appendNote = "商户备注（金额/币种核实通过）: {$note} (" . date('Y-m-d H:i:s') . ")\n";
                    $db->query(
                        "UPDATE orders
                         SET amount=?,
                             currency=?,
                             status='paid',
                             pay_provider='crypto',
                             paid_at=NOW(),
                             tx_hash=?,
                             updated_at=NOW(),
                             last_external_sync=NOW(),
                             notes=CONCAT(IFNULL(notes,''), ?)
                         WHERE id=? AND user_id=?",
                        [$newAmount, $newCurrency, (string)$tx['hash'], $appendNote, $orderId, $user_id]
                    );
                } else {
                    $db->query(
                        "UPDATE orders
                         SET amount=?,
                             currency=?,
                             status='paid',
                             pay_provider='crypto',
                             paid_at=NOW(),
                             tx_hash=?,
                             updated_at=NOW(),
                             last_external_sync=NOW()
                         WHERE id=? AND user_id=?",
                        [$newAmount, $newCurrency, (string)$tx['hash'], $orderId, $user_id]
                    );
                }

                $paidOrder = $db->fetch("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1", [$orderId, $user_id]);
                if ($paidOrder && $oldStatus !== 'paid') {
                    try {
                        WebhookService::send($paidOrder);
                    } catch (Throwable $e) {}
                    try {
                        StoreCouponService::applyOnPaid($db, (int)$orderId);
                        StoreReceiptService::sendForOrder($orderId);
                    } catch (Throwable $e) {}
                }
                merchant_orders_set_flash('success', $tt('金额/币种已修正，且已匹配链上交易，订单已标记为已支付。', 'Amount/currency updated and matched on-chain. The order has been marked as paid.'));
            } else {
                merchant_orders_set_flash('danger', $tt('修改失败：金额不匹配或未找到链上交易。', 'Update failed: amount mismatch or no matching on-chain transaction.'));
            }
        } catch (Throwable $e) {
            merchant_orders_set_flash('danger', $tt('修改失败：', 'Update failed: ') . $e->getMessage());
        }
        header('Location: orders.php?panel=list');
        exit;
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max(1, $page);
$limit = 10;
$offset = ($page - 1) * $limit;

// Search & Filter
$where = "o.user_id = ?";
$params = [$user_id];

$query_order_no = trim((string)($_GET['order_no'] ?? ''));
$query_status = strtolower(trim((string)($_GET['status'] ?? '')));
$query_chain = strtolower(trim((string)($_GET['chain'] ?? '')));
$query_source = strtolower(trim((string)($_GET['source'] ?? '')));
$query_fast_sync = trim((string)($_GET['fast_sync'] ?? ''));
$query_panel = strtolower(trim((string)($_GET['panel'] ?? 'list')));
if (!in_array($query_panel, ['search', 'list'], true)) {
    $query_panel = 'list';
}

if ($query_order_no !== '') {
    $searchableColumns = ['o.order_no', 'o.merchant_order_id', 'w.address'];
    try {
        $columns = $db->fetchAll("SHOW COLUMNS FROM orders");
        $columnNames = [];
        foreach ($columns as $col) {
            $columnNames[] = strtolower((string)($col['Field'] ?? ''));
        }
        foreach (['tx_hash', 'pay_address', 'payment_address', 'to_address'] as $maybeCol) {
            if (in_array($maybeCol, $columnNames, true)) {
                $searchableColumns[] = 'o.' . $maybeCol;
            }
        }
    } catch (Exception $e) {
        // Keep base search columns when schema detection fails.
    }
    $likeParts = [];
    foreach ($searchableColumns as $col) {
        $likeParts[] = $col . " LIKE ?";
    }
    $where .= " AND (" . implode(' OR ', $likeParts) . ")";
    $like = '%' . $query_order_no . '%';
    foreach ($searchableColumns as $_) {
        $params[] = $like;
    }
}

$allowed_statuses = ['paid', 'pending', 'expired', 'failed', 'cancelled'];
if ($query_status !== '' && in_array($query_status, $allowed_statuses, true)) {
    $where .= " AND LOWER(o.status) = ?";
    $params[] = $query_status;
}

$allowed_chains = ['trc20', 'eth', 'bsc'];
if ($query_chain !== '' && in_array($query_chain, $allowed_chains, true)) {
    $where .= " AND LOWER(o.chain) = ?";
    $params[] = $query_chain;
}

$allowed_sources = ['api', 'payment_link', 'qr_code', 'shop', 'store', 'upgrade', 'recharge'];
if ($query_source !== '' && in_array($query_source, $allowed_sources, true)) {
    $where .= " AND LOWER(o.source) = ?";
    $params[] = $query_source;
}

if ($query_fast_sync === '1') {
    $where .= " AND o.is_fast_sync = 1";
} elseif ($query_fast_sync === '0') {
    $where .= " AND (o.is_fast_sync = 0 OR o.is_fast_sync IS NULL)";
}

// Get Data
$ordersFrom = "FROM orders o
    LEFT JOIN wallets w ON w.id = o.wallet_id";
$total = $db->fetch("SELECT COUNT(*) as c $ordersFrom WHERE $where", $params)['c'];
$total_pages = max(1, (int)ceil($total / $limit));
$page = min($page, $total_pages);
$offset = ($page - 1) * $limit;
$orders = $db->fetchAll("SELECT o.*, w.address AS wallet_address, w.user_id AS wallet_owner_user_id, EXISTS(SELECT 1 FROM admin_fee_address_allocations afa WHERE afa.order_no = o.order_no) AS derived_alloc_id $ordersFrom WHERE $where ORDER BY o.id DESC LIMIT $limit OFFSET $offset", $params);
foreach ($orders as &$row) {
    $isBinanceOrder = (strpos(strtolower((string)($row['chain'] ?? '')), 'binance') !== false)
        || (strtolower((string)($row['pay_provider'] ?? '')) === 'binance');
    if (!$isBinanceOrder) {
        continue;
    }

    if (
        trim((string)($row['binance_payer_uid'] ?? '')) !== ''
        || trim((string)($row['binance_open_user_id'] ?? '')) !== ''
    ) {
        continue;
    }

    $logRow = $db->fetch(
        "SELECT request_body
         FROM binance_webhook_logs
         WHERE order_no = ?
         ORDER BY id DESC
         LIMIT 1",
        [(string)($row['order_no'] ?? '')]
    );
    $raw = (string)($logRow['request_body'] ?? '');
    if ($raw === '') {
        continue;
    }
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        continue;
    }
    $meta = merchant_orders_extract_binance_meta($payload);
    if (trim((string)($row['binance_payer_uid'] ?? '')) === '' && $meta['payer_uid'] !== '') {
        $row['binance_payer_uid'] = $meta['payer_uid'];
    }
    if (trim((string)($row['binance_open_user_id'] ?? '')) === '' && $meta['open_user_id'] !== '') {
        $row['binance_open_user_id'] = $meta['open_user_id'];
    }
}
unset($row);

// Stats for Header
$stats = $db->fetch("SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) as paid_orders,
    SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) as total_volume
    FROM orders WHERE user_id = ?", [$user_id]);
$flash = $_SESSION['merchant_orders_flash'] ?? null;
unset($_SESSION['merchant_orders_flash']);

?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
        .order-detail-row label { font-weight: bold; color: #6c757d; }
        .orders-table th {
            white-space: nowrap;
            font-size: 0.82rem;
            letter-spacing: .01em;
        }
        .order-no-cell {
            max-width: 220px;
        }
        .order-no-cell .order-main {
            display: block;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: .8rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .order-no-cell .order-sub {
            display: block;
            color: var(--text-secondary);
            font-size: .75rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-top: 2px;
        }
        .orders-filter-card {
            padding: 16px 18px;
        }
        .orders-filter-card .filter-title {
            font-size: 0.95rem;
            margin-bottom: 10px;
        }
        .orders-filter-card .form-control,
        .orders-filter-card .form-select {
            min-height: 40px;
        }
        .order-filter-actions {
            display: flex;
            gap: .5rem;
        }
        .order-filter-actions .btn {
            min-height: 40px;
            white-space: nowrap;
        }
        .order-filter-extra {
            display: flex;
            justify-content: flex-end;
            margin-top: 8px;
        }
        .badge-mole.info {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        .currency-tag {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: .02em;
            margin-left: 6px;
            border: 1px solid transparent;
            vertical-align: middle;
        }
        .currency-tag-usdt {
            color: #047857;
            background: rgba(16, 185, 129, 0.14);
            border-color: rgba(16, 185, 129, 0.26);
        }
        .currency-tag-usdc {
            color: #1d4ed8;
            background: rgba(59, 130, 246, 0.12);
            border-color: rgba(59, 130, 246, 0.24);
        }
        .currency-tag-usd {
            color: #7c3aed;
            background: rgba(139, 92, 246, 0.12);
            border-color: rgba(139, 92, 246, 0.24);
        }
        .currency-tag-default {
            color: #4b5563;
            background: rgba(107, 114, 128, 0.12);
            border-color: rgba(107, 114, 128, 0.24);
        }
        @media (max-width: 768px) {
            .main-content { padding: 20px; }
            .orders-filter-card {
                padding: 14px;
            }
            .order-filter-actions {
                flex-direction: column;
            }
            .order-filter-actions .btn {
                width: 100%;
            }
            .order-filter-extra {
                justify-content: stretch;
            }
            .order-filter-extra .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Content -->
        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.orders.title'); include __DIR__ . '/includes/user_topbar.php'; ?>

            <!-- Stats -->
            <div class="row g-4 mb-4">
                <div class="col-md-4">
                    <div class="mole-card d-flex align-items-start">
                        <div class="stat-icon-wrapper bg-primary bg-opacity-10 text-primary">
                            <i class="fas fa-file-invoice fa-lg"></i>
                        </div>
                        <div>
                            <div class="stat-label"><?php echo __('merchant.orders.total_orders'); ?></div>
                            <div class="stat-value"><?php echo number_format($stats['total_orders']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mole-card d-flex align-items-start">
                        <div class="stat-icon-wrapper bg-success bg-opacity-10 text-success">
                            <i class="fas fa-circle-check fa-lg"></i>
                        </div>
                        <div>
                            <div class="stat-label"><?php echo __('merchant.orders.paid_orders'); ?></div>
                            <div class="stat-value"><?php echo number_format($stats['paid_orders']); ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mole-card d-flex align-items-start">
                        <div class="stat-icon-wrapper bg-info bg-opacity-10 text-info">
                            <i class="fas fa-wallet fa-lg"></i>
                        </div>
                        <div>
                            <div class="stat-label"><?php echo __('merchant.orders.total_volume'); ?></div>
                            <div class="stat-value">
                                <?php echo number_format($stats['total_volume'], 2); ?>
                                <small class="fs-6 text-muted">USDT</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($flash): ?>
            <?php
                $flashClass = match ((string)($flash['type'] ?? 'info')) {
                    'success' => 'alert-success',
                    'danger' => 'alert-danger',
                    'warning' => 'alert-warning',
                    default => 'alert-info',
                };
            ?>
            <div class="alert <?php echo $flashClass; ?> alert-dismissible fade show mb-4" role="alert">
                <?php echo htmlspecialchars((string)($flash['message'] ?? '')); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
            <?php endif; ?>

            <ul class="nav nav-tabs mb-3" id="merchantOrdersTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $query_panel === 'search' ? 'active' : ''; ?>" id="orders-search-tab" data-bs-toggle="tab" data-bs-target="#orders-search-pane" type="button" role="tab" aria-controls="orders-search-pane" aria-selected="<?php echo $query_panel === 'search' ? 'true' : 'false'; ?>"><?php echo __('merchant.orders.search'); ?></button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link <?php echo $query_panel === 'list' ? 'active' : ''; ?>" id="orders-list-tab" data-bs-toggle="tab" data-bs-target="#orders-list-pane" type="button" role="tab" aria-controls="orders-list-pane" aria-selected="<?php echo $query_panel === 'list' ? 'true' : 'false'; ?>"><?php echo __('merchant.orders.title'); ?></button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade <?php echo $query_panel === 'search' ? 'show active' : ''; ?>" id="orders-search-pane" role="tabpanel" aria-labelledby="orders-search-tab" tabindex="0">
                    <div class="mole-card mb-4 orders-filter-card">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h6 class="fw-bold mb-0 filter-title"><?php echo __('merchant.orders.search'); ?></h6>
                        </div>
                        <div>
                            <form method="GET" class="row g-3">
                                <input type="hidden" name="panel" value="list">
                                <div class="col-12 col-lg-4">
                                    <input type="text" name="order_no" class="form-control" placeholder="<?php echo __('merchant.orders.search_placeholder'); ?>" value="<?php echo htmlspecialchars($query_order_no); ?>">
                                </div>
                                <div class="col-6 col-lg-2">
                                    <select name="chain" class="form-select">
                                        <option value=""><?php echo __('merchant.orders.all_networks'); ?></option>
                                        <option value="trc20" <?php echo $query_chain==='trc20'?'selected':''; ?>>TRC20</option>
                                        <option value="eth" <?php echo $query_chain==='eth'?'selected':''; ?>>Ethereum</option>
                                        <option value="bsc" <?php echo $query_chain==='bsc'?'selected':''; ?>>BSC</option>
                                    </select>
                                </div>
                                <div class="col-6 col-lg-2">
                                    <select name="status" class="form-select">
                                        <option value=""><?php echo __('merchant.orders.all_statuses'); ?></option>
                                        <option value="paid" <?php echo $query_status==='paid'?'selected':''; ?>><?php echo __('merchant.status.paid'); ?></option>
                                        <option value="pending" <?php echo $query_status==='pending'?'selected':''; ?>><?php echo __('merchant.status.pending'); ?></option>
                                        <option value="expired" <?php echo $query_status==='expired'?'selected':''; ?>><?php echo __('merchant.status.expired'); ?></option>
                                    </select>
                                </div>
                                <div class="col-12 col-lg-2">
                                    <select name="source" class="form-select">
                                        <option value=""><?php echo __('merchant.orders.all_sources'); ?></option>
                                        <option value="api" <?php echo $query_source==='api'?'selected':''; ?>><?php echo __('merchant.source.api'); ?></option>
                                        <option value="payment_link" <?php echo $query_source==='payment_link'?'selected':''; ?>><?php echo __('merchant.source.payment_link'); ?></option>
                                        <option value="qr_code" <?php echo $query_source==='qr_code'?'selected':''; ?>><?php echo __('merchant.source.qr_code'); ?></option>
                                        <option value="shop" <?php echo $query_source==='shop'?'selected':''; ?>><?php echo I18n::getLang()==='en'?'Store (Legacy)':'店铺(旧)'; ?></option>
                                        <option value="store" <?php echo $query_source==='store'?'selected':''; ?>><?php echo I18n::getLang()==='en'?'Store':'店铺'; ?></option>
                                        <option value="upgrade" <?php echo $query_source==='upgrade'?'selected':''; ?>><?php echo I18n::getLang()==='en'?'Plan Upgrade':'套餐升级'; ?></option>
                                        <option value="recharge" <?php echo $query_source==='recharge'?'selected':''; ?>><?php echo I18n::getLang()==='en'?'Recharge':'余额充值'; ?></option>
                                    </select>
                                </div>
                                <div class="col-6 col-lg-2">
                                    <select name="fast_sync" class="form-select">
                                        <option value=""><?php echo I18n::getLang()==='en' ? 'All Monitor Modes' : '全部监听模式'; ?></option>
                                        <option value="1" <?php echo $query_fast_sync==='1'?'selected':''; ?>><?php echo I18n::getLang()==='en' ? 'FAST Applied' : '已使用极速包'; ?></option>
                                        <option value="0" <?php echo $query_fast_sync==='0'?'selected':''; ?>><?php echo I18n::getLang()==='en' ? 'Normal Monitor' : '普通监听'; ?></option>
                                    </select>
                                </div>
                                <div class="col-6 col-lg-2">
                                    <div class="order-filter-actions">
                                        <button type="submit" class="btn btn-primary flex-fill"><?php echo __('merchant.orders.search'); ?></button>
                                        <a href="orders.php?panel=list" class="btn btn-outline-secondary flex-fill"><?php echo __('merchant.orders.reset'); ?></a>
                                    </div>
                                </div>
                                <div class="col-12 order-filter-extra">
                                    <a href="/api/v1/user/export_orders.php?<?php echo http_build_query($_GET); ?>" class="btn btn-outline-success btn-sm" target="_blank">
                                        <i class="fas fa-file-export me-1"></i><?php echo __('merchant.orders.export'); ?>
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade <?php echo $query_panel === 'list' ? 'show active' : ''; ?>" id="orders-list-pane" role="tabpanel" aria-labelledby="orders-list-tab" tabindex="0">
                    <div class="mole-card p-0 overflow-hidden">
                        <div class="d-flex justify-content-between align-items-center p-4 border-bottom" style="border-color: var(--border-color)!important;">
                            <h6 class="fw-bold mb-0"><?php echo __('merchant.orders.title'); ?></h6>
                            <span class="badge-mole gray"><?php echo number_format((int)$total); ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle orders-table">
                                <thead>
                                    <tr>
                                        <th><?php echo __('merchant.orders.sys_order_no'); ?></th>
                                        <th><?php echo __('merchant.orders.amount'); ?></th>
                                        <th><?php echo __('merchant.orders.network'); ?></th>
                                        <th><?php echo __('merchant.orders.status'); ?></th>
                                        <th><?php echo __('merchant.orders.source'); ?></th>
                                        <th><?php echo __('merchant.orders.created_at'); ?></th>
                                        <th><?php echo __('merchant.orders.actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $o): ?>
                                    <tr>
                                <td class="order-no-cell">
                                    <span class="order-main"><?php echo htmlspecialchars($o['order_no']); ?></span>
                                    <span class="order-sub"><?php echo (I18n::getLang() === 'en' ? 'Merchant: ' : '商户单号: ') . htmlspecialchars($o['merchant_order_id']); ?></span>
                                </td>
                                        <td class="fw-bold">
                                            <?php
                                                $currency = strtoupper((string)($o['currency'] ?? ''));
                                                $currencyTagClass = 'currency-tag-default';
                                                if ($currency === 'USDT') {
                                                    $currencyTagClass = 'currency-tag-usdt';
                                                } elseif ($currency === 'USDC') {
                                                    $currencyTagClass = 'currency-tag-usdc';
                                                } elseif ($currency === 'USD') {
                                                    $currencyTagClass = 'currency-tag-usd';
                                                }
                                            ?>
                                            <?php echo number_format($o['amount'], 2); ?>
                                            <span class="currency-tag <?php echo $currencyTagClass; ?>"><?php echo htmlspecialchars($currency !== '' ? $currency : '-'); ?></span>
                                            <?php if (!empty($o['is_fast_sync'])): ?>
                                                <span class="badge bg-primary-subtle text-primary ms-1"><?php echo I18n::getLang()==='en'?'FAST':'极速'; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge-mole gray text-uppercase"><?php echo strtoupper($o['chain']); ?></span></td>
                                        <td>
                                            <?php if($o['status']=='paid'): ?>
                                                <span class="badge-mole success"><?php echo __('merchant.status.paid'); ?></span>
                                            <?php elseif($o['status']=='refunded'): ?>
                                                <span class="badge bg-danger"><?php echo I18n::getLang()==='en' ? 'Refunded' : '已退款'; ?></span>
                                            <?php elseif($o['status']=='pending'): ?>
                                                <span class="badge-mole warning"><?php echo __('merchant.status.pending'); ?></span>
                                            <?php elseif($o['status']=='expired'): ?>
                                                <span class="badge-mole gray"><?php echo __('merchant.status.expired'); ?></span>
                                            <?php elseif($o['status']=='failed'): ?>
                                                <span class="badge bg-danger"><?php echo I18n::getLang()==='en' ? 'Failed' : '失败'; ?></span>
                                            <?php elseif($o['status']=='cancelled'): ?>
                                                <span class="badge bg-secondary"><?php echo I18n::getLang()==='en' ? 'Cancelled' : '已取消'; ?></span>
                                            <?php else: ?>
                                                <span class="badge-mole gray"><?php echo htmlspecialchars(I18n::getLang()==='en' ? (string)$o['status'] : ((string)$o['status'] === '' ? '-' : (string)$o['status'])); ?></span>
                                            <?php endif; ?>
                                            <?php
                                                $rStatus = strtolower((string)($o['refund_status'] ?? ''));
                                                if ($rStatus === 'full') {
                                                    echo '<div class="mt-1"><span class="badge bg-danger">'.(I18n::getLang()==='en' ? 'Full Refund' : '全额退款').'</span></div>';
                                                } elseif ($rStatus === 'partial') {
                                                    echo '<div class="mt-1"><span class="badge bg-warning text-dark">'.(I18n::getLang()==='en' ? 'Partial Refund' : '部分退款').' '.htmlspecialchars((string)($o['refund_amount'] ?? '0')).' '.htmlspecialchars((string)($o['currency'] ?? '')).'</span></div>';
                                                }
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                                $src = $o['source'] ?? 'api';
                                                if ($src === 'api') echo '<span class="badge-mole gray">'.__('merchant.source.api').'</span>';
                                                elseif ($src === 'payment_link') echo '<span class="badge-mole info">'.__('merchant.source.payment_link').'</span>';
                                                elseif ($src === 'qr_code') echo '<span class="badge-mole success">'.__('merchant.source.qr_code').'</span>';
                                                elseif ($src === 'shop' || $src === 'store') echo '<span class="badge-mole info">'.(I18n::getLang()==='en'?'Store':'店铺').'</span>';
                                                elseif ($src === 'upgrade') echo '<span class="badge-mole warning">'.(I18n::getLang()==='en'?'Plan Upgrade':'套餐升级').'</span>';
                                                elseif ($src === 'recharge') echo '<span class="badge-mole success">'.(I18n::getLang()==='en'?'Recharge':'余额充值').'</span>';
                                                else echo '<span class="badge-mole gray">'.htmlspecialchars($src).'</span>';

                                                if (!empty($o['derived_alloc_id'])) {
                                                    echo '<span class="badge-mole warning ms-1">'.__('merchant.orders.wallet_type.derived').'</span>';
                                                } elseif (!empty($o['wallet_id']) && (int)($o['wallet_owner_user_id'] ?? 0) === (int)$user_id) {
                                                    echo '<span class="badge-mole info ms-1">'.__('merchant.orders.wallet_type.personal').'</span>';
                                                } elseif (!empty($o['wallet_id'])) {
                                                    echo '<span class="badge-mole gray ms-1">'.__('merchant.orders.wallet_type.platform').'</span>';
                                                }
                                            ?>
                                        </td>
                                        <td class="text-muted small"><?php echo date('m-d H:i', strtotime($o['created_at'])); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary" onclick="showOrder(<?php echo htmlspecialchars(json_encode($o)); ?>)">
                                                <?php echo __('merchant.orders.detail'); ?>
                                            </button>
                                            <?php if (in_array((string)($o['status'] ?? ''), ['pending', 'expired'], true)): ?>
                                            <button class="btn btn-sm btn-outline-warning ms-1"
                                                    onclick="openDisputeModal(<?php echo (int)$o['id']; ?>, '<?php echo htmlspecialchars((string)$o['amount'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string)$o['currency'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars((string)$o['chain'], ENT_QUOTES); ?>')">
                                                <?php echo $tt('异议处理', 'Dispute'); ?>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if(empty($orders)): ?>
                                    <tr><td colspan="7" class="text-center py-5 text-muted"><?php echo __('merchant.orders.no_orders'); ?></td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if($total_pages > 1): ?>
                        <div class="p-3 border-top" style="border-color: var(--border-color)!important;">
                            <nav>
                                <ul class="pagination justify-content-center mb-0">
                                    <?php for($i=1; $i<=$total_pages; $i++): ?>
                                    <li class="page-item <?php echo $page==$i?'active':''; ?>">
                                        <a class="page-link" href="?panel=list&page=<?php echo $i; ?>&order_no=<?php echo urlencode($query_order_no); ?>&chain=<?php echo urlencode($query_chain); ?>&status=<?php echo urlencode($query_status); ?>&source=<?php echo urlencode($query_source); ?>&fast_sync=<?php echo urlencode($query_fast_sync); ?>"><?php echo $i; ?></a>
                                    </li>
                                    <?php endfor; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Dispute Modal -->
<div class="modal fade" id="disputeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $tt('异议订单处理', 'Dispute Order Handling'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <?php echo $tt('当系统未确认但你已核实到账时，可在此手动确认。', 'When the system has not confirmed payment but funds are verified, you can confirm manually here.'); ?>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label"><?php echo $tt('订单 ID', 'Order ID'); ?></label>
                        <input type="text" id="disputeOrderIdText" class="form-control" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><?php echo $tt('当前订单金额', 'Current Order Amount'); ?></label>
                        <input type="text" id="disputeCurrentAmountText" class="form-control" readonly>
                    </div>
                </div>
                <hr>
                <form method="POST" id="disputeAmountForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($merchant_orders_csrf_token); ?>">
                    <input type="hidden" name="action" value="update_amount">
                    <input type="hidden" name="order_id" id="disputeOrderIdInput">
                    <div class="mb-3">
                        <label class="form-label"><?php echo $tt('修正金额后链上核验', 'Adjust Amount and Verify On-chain'); ?></label>
                        <input type="number" step="0.000001" min="0.000001" class="form-control" name="new_amount" id="disputeNewAmountInput" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $tt('修正币种', 'Adjusted Currency'); ?></label>
                        <select class="form-select" name="new_currency" id="disputeCurrencySelect">
                            <option value="USDT">USDT</option>
                            <option value="USDC">USDC</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $tt('备注（可选）', 'Note (optional)'); ?></label>
                        <textarea class="form-control" rows="3" name="note" id="disputeNoteInput" placeholder="<?php echo $tt('例如：用户少付 0.1，已人工核对', 'Example: user underpaid by 0.1, manually verified'); ?>"></textarea>
                    </div>
                </form>
                <form method="POST" id="disputeVerifyOriginalForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($merchant_orders_csrf_token); ?>">
                    <input type="hidden" name="action" value="verify_exception">
                    <input type="hidden" name="exception_order_id" id="disputeVerifyOrderIdInput">
                    <input type="hidden" name="verify_currency" id="disputeVerifyCurrencyInput">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('merchant.common.close'); ?></button>
                <button type="button" class="btn btn-outline-primary" onclick="submitVerifyOriginal()"><?php echo $tt('按原金额核验并确认', 'Verify Original Amount'); ?></button>
                <button type="submit" class="btn btn-warning" form="disputeAmountForm"><?php echo $tt('按修正金额核验并确认', 'Verify Adjusted Amount'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Order Details Modal -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo __('merchant.orders.order_details'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6 order-detail-row">
                        <label><?php echo __('merchant.orders.sys_order_no'); ?></label>
                        <div id="modal_order_no" class="font-monospace user-select-all"></div>
                    </div>
                    <div class="col-md-6 order-detail-row">
                        <label><?php echo __('merchant.orders.merchant_order_no'); ?></label>
                        <div id="modal_merchant_id" class="font-monospace user-select-all"></div>
                    </div>
                    
                    <div class="col-md-6 order-detail-row">
                        <label><?php echo __('merchant.orders.pay_amount'); ?></label>
                        <div class="fs-4 fw-bold text-success" id="modal_amount"></div>
                    </div>
                    <div class="col-md-6 order-detail-row">
                        <label><?php echo __('merchant.orders.current_status'); ?></label>
                        <div id="modal_status"></div>
                    </div>
                    <div class="col-md-6 order-detail-row" id="modal_row_fast_sync">
                        <label><?php echo I18n::getLang()==='en' ? 'Monitor Mode' : '监听模式'; ?></label>
                        <div id="modal_fast_sync"></div>
                    </div>

                    <div class="col-md-12"><hr></div>

                    <div class="col-md-6 order-detail-row">
                        <label><?php echo __('merchant.orders.pay_chain'); ?></label>
                        <div id="modal_chain"></div>
                    </div>
                    <div class="col-md-6 order-detail-row">
                        <label><?php echo __('merchant.orders.pay_source_site'); ?></label>
                        <div class="text-muted" id="modal_source"></div>
                    </div>
                    <div class="col-md-6 order-detail-row" id="modal_row_wallet_type">
                        <label><?php echo __('merchant.orders.wallet_type'); ?></label>
                        <div id="modal_wallet_type"></div>
                    </div>

                    <div class="col-md-12 order-detail-row" id="modal_row_pay_address">
                        <label><?php echo __('merchant.orders.receive_address'); ?></label>
                        <div id="modal_pay_address" class="font-monospace text-break user-select-all bg-light p-2 rounded">-</div>
                    </div>

                    <div class="col-md-12 order-detail-row">
                        <label id="modal_tx_label"><?php echo __('merchant.orders.tx_hash'); ?></label>
                        <div id="modal_tx_hash" class="font-monospace text-break user-select-all bg-light p-2 rounded"></div>
                    </div>
                    <div class="col-md-6 order-detail-row" id="modal_row_refund_type">
                        <label><?php echo I18n::getLang()==='en' ? 'Refund Type' : '退款类型'; ?></label>
                        <div id="modal_refund_type"></div>
                    </div>
                    <div class="col-md-6 order-detail-row" id="modal_row_refund_amount">
                        <label><?php echo I18n::getLang()==='en' ? 'Refund Amount' : '退款金额'; ?></label>
                        <div id="modal_refund_amount"></div>
                    </div>
                    <div class="col-md-6 order-detail-row" id="modal_row_binance_uid">
                        <label><?php echo I18n::getLang()==='en' ? 'Binance Payer UID' : '付款 Binance UID'; ?></label>
                        <div id="modal_binance_payer_uid" class="font-monospace text-break user-select-all bg-light p-2 rounded">-</div>
                    </div>

                    <div class="col-md-6 order-detail-row">
                        <label><?php echo __('merchant.orders.created_at'); ?></label>
                        <div id="modal_created_at"></div>
                    </div>
                    <div class="col-md-6 order-detail-row">
                        <label><?php echo __('merchant.orders.updated_at'); ?></label>
                        <div id="modal_updated_at"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('merchant.common.close'); ?></button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;'})[ch] || ch;
    });
}

function explorerUrlByChain(chain, txHash) {
    const c = String(chain || '').toLowerCase();
    if (!txHash) return '';
    if (c.includes('trc')) return `https://tronscan.org/#/transaction/${txHash}`;
    if (c.includes('sol')) return `https://solscan.io/tx/${txHash}`;
    if (c.includes('bsc')) return `https://bscscan.com/tx/${txHash}`;
    if (c.includes('polygon') || c === 'matic') return `https://polygonscan.com/tx/${txHash}`;
    if (c.includes('optimism') || c === 'op') return `https://optimistic.etherscan.io/tx/${txHash}`;
    if (c.includes('arbitrum') || c === 'arb') return `https://arbiscan.io/tx/${txHash}`;
    if (c.includes('base')) return `https://basescan.org/tx/${txHash}`;
    if (c.includes('avax') || c.includes('avalanche')) return `https://snowtrace.io/tx/${txHash}`;
    if (c.includes('eth') || c.includes('erc')) return `https://etherscan.io/tx/${txHash}`;
    return '';
}

function showOrder(o) {
    const toggleRow = (id, show) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.style.display = show ? '' : 'none';
    };
    const hasValue = (v) => {
        const s = String(v == null ? '' : v).trim();
        if (!s) return false;
        const t = s.toLowerCase();
        return t !== '-' && t !== 'null' && t !== 'undefined';
    };

    document.getElementById('modal_order_no').textContent = o.order_no;
    document.getElementById('modal_merchant_id').textContent = o.merchant_order_id;
    document.getElementById('modal_amount').textContent = parseFloat(o.amount).toFixed(2) + ' ' + o.currency;
    
    let statusHtml = '';
    if(o.status === 'paid') statusHtml = '<span class="badge bg-success"><?php echo jsesc(__('merchant.status.paid')); ?></span>';
    else if(o.status === 'pending') statusHtml = '<span class="badge bg-warning text-dark"><?php echo jsesc(__('merchant.status.pending')); ?></span>';
    else if(o.status === 'expired') statusHtml = '<span class="badge bg-secondary"><?php echo jsesc(__('merchant.status.expired')); ?></span>';
    else if(o.status === 'failed') statusHtml = '<span class="badge bg-danger"><?php echo jsesc(I18n::getLang()==='en' ? 'Failed' : '失败'); ?></span>';
    else if(o.status === 'cancelled') statusHtml = '<span class="badge bg-secondary"><?php echo jsesc(I18n::getLang()==='en' ? 'Cancelled' : '已取消'); ?></span>';
    else if(o.status === 'refunded') statusHtml = '<span class="badge bg-danger"><?php echo jsesc(I18n::getLang()==='en' ? 'Refunded' : '已退款'); ?></span>';
    else statusHtml = '<span class="badge bg-secondary">' + (o.status || '-') + '</span>';
    document.getElementById('modal_status').innerHTML = statusHtml;
    const fastSync = (String(o.is_fast_sync || '0') === '1');
    document.getElementById('modal_fast_sync').innerHTML = fastSync
        ? '<span class="badge bg-primary"><?php echo jsesc(I18n::getLang()==='en' ? 'FAST package applied' : '已使用极速监听包'); ?></span>'
        : '<span class="badge bg-light text-dark border"><?php echo jsesc(I18n::getLang()==='en' ? 'Normal monitor' : '普通监听'); ?></span>';

    const chain = String(o.chain || '').toLowerCase();
    const payProvider = String(o.pay_provider || '').toLowerCase();
    const isBinancePay = chain.includes('binance') || payProvider === 'binance';
    document.getElementById('modal_chain').textContent = chain ? chain.toUpperCase() : '-';
    toggleRow('modal_row_fast_sync', !isBinancePay);

    // Source label mapping
    let sourceText = '';
    const src = String(o.source || 'api').toLowerCase();
    const origin = String(o.order_origin || '').toLowerCase();
    if (origin === 'merchant_order') sourceText = <?php echo json_encode(I18n::getLang()==='en' ? 'Merchant Order' : '商户订单'); ?>;
    else if (origin === 'merchant_customer_order') sourceText = <?php echo json_encode(I18n::getLang()==='en' ? 'Merchant Customer Order' : '商户客户订单'); ?>;
    else if (src === 'api') sourceText = <?php echo json_encode(I18n::getLang()==='en' ? 'API Call' : 'API 调用'); ?>;
    else if (src === 'payment_link') sourceText = <?php echo json_encode(I18n::getLang()==='en' ? 'Payment Link' : '收款链接'); ?>;
    else if (src === 'qr_code') sourceText = <?php echo json_encode(I18n::getLang()==='en' ? 'QR Code' : '收款码'); ?>;
    else if (src === 'shop' || src === 'store') sourceText = <?php echo json_encode(I18n::getLang()==='en' ? 'Store Checkout' : '我的店铺收款'); ?>;
    else if (src === 'upgrade') sourceText = '<?php echo jsesc(I18n::getLang()==='en' ? 'Plan Upgrade (On-site)' : '套餐升级（站内发起）'); ?>';
    else if (src === 'recharge') sourceText = '<?php echo jsesc(I18n::getLang()==='en' ? 'Balance Recharge (On-site)' : '余额充值（站内发起）'); ?>';
    else sourceText = src;
    document.getElementById('modal_source').textContent = sourceText;

    let walletTypeHtml = '<span class="text-muted">-</span>';
    let hasWalletType = false;
    if (String(o.derived_alloc_id || '') !== '') {
        walletTypeHtml = '<span class="badge bg-warning text-dark"><?php echo jsesc(__('merchant.orders.wallet_type.derived')); ?></span>';
        hasWalletType = true;
    } else if (String(o.wallet_id || '') !== '' && parseInt(o.wallet_owner_user_id || '0', 10) === <?php echo (int)$user_id; ?>) {
        walletTypeHtml = '<span class="badge bg-info text-dark"><?php echo jsesc(__('merchant.orders.wallet_type.personal')); ?></span>';
        hasWalletType = true;
    } else if (String(o.wallet_id || '') !== '') {
        walletTypeHtml = '<span class="badge bg-secondary"><?php echo jsesc(__('merchant.orders.wallet_type.platform')); ?></span>';
        hasWalletType = true;
    }
    document.getElementById('modal_wallet_type').innerHTML = walletTypeHtml;
    toggleRow('modal_row_wallet_type', !isBinancePay && hasWalletType);

    const payAddress = String(o.wallet_address || o.pay_address || o.payment_address || o.to_address || '').trim();
    document.getElementById('modal_pay_address').textContent = payAddress || '-';
    toggleRow('modal_row_pay_address', !isBinancePay && hasValue(payAddress));
    const refundStatus = String(o.refund_status || '').toLowerCase();
    let refundType = '-';
    if (refundStatus === 'full') refundType = <?php echo json_encode(I18n::getLang()==='en' ? 'Full Refund' : '全额退款'); ?>;
    if (refundStatus === 'partial') refundType = <?php echo json_encode(I18n::getLang()==='en' ? 'Partial Refund' : '部分退款'); ?>;
    document.getElementById('modal_refund_type').textContent = refundType;
    const refundAmountText = refundStatus ? `${o.refund_amount || '0'} ${o.currency || ''}` : '-';
    document.getElementById('modal_refund_amount').textContent = refundAmountText;
    const showRefund = refundStatus === 'full' || refundStatus === 'partial' || parseFloat(o.refund_amount || '0') > 0;
    toggleRow('modal_row_refund_type', showRefund);
    toggleRow('modal_row_refund_amount', showRefund);

    const payerUid = String(o.binance_payer_uid || o.binance_open_user_id || '').trim();
    document.getElementById('modal_binance_payer_uid').textContent = payerUid || '-';
    toggleRow('modal_row_binance_uid', isBinancePay && hasValue(payerUid));
    
    let txHash = o.tx_hash || <?php echo json_encode(__('merchant.orders.unpaid_or_unconfirmed')); ?>;
    let txDiv = document.getElementById('modal_tx_hash');
    let txLabel = document.getElementById('modal_tx_label');
    if (o.tx_hash) {
        if (chain.includes('stripe')) {
            txLabel.textContent = <?php echo json_encode(I18n::getLang()==='en' ? 'Stripe Payment Intent' : 'Stripe 支付流水号'); ?>;
            txDiv.textContent = o.tx_hash;
        } else if (chain.includes('binance')) {
            txLabel.textContent = <?php echo json_encode(I18n::getLang()==='en' ? 'Binance Pay Order ID' : 'Binance Pay 订单编号'); ?>;
            txDiv.textContent = o.binance_pay_order_id || o.tx_hash;
        } else {
            txLabel.textContent = <?php echo json_encode(__('merchant.orders.tx_hash')); ?>;
            const url = explorerUrlByChain(chain, o.tx_hash);
            const safeHash = escapeHtml(o.tx_hash);
            if (url) {
                txDiv.innerHTML = `<a href="${url}" target="_blank" rel="noopener noreferrer">${safeHash}</a><a href="${url}" target="_blank" rel="noopener noreferrer" class="ms-2"><i class="fas fa-external-link-alt"></i></a>`;
            } else {
                txDiv.textContent = o.tx_hash;
            }
        }
    } else {
        txLabel.textContent = <?php echo json_encode(__('merchant.orders.tx_hash')); ?>;
        txDiv.textContent = txHash;
    }

    document.getElementById('modal_created_at').textContent = o.created_at;
    document.getElementById('modal_updated_at').textContent = o.updated_at || '-';

    new bootstrap.Modal(document.getElementById('orderModal')).show();
}

function openDisputeModal(orderId, amount, currency, chain) {
    const ord = String(orderId || '');
    const amt = String(amount || '');
    const curr = String(currency || 'USDT').toUpperCase();
    const chainKey = String(chain || '').toLowerCase();
    const isTrc20 = chainKey === 'trc20';
    const currencySelect = document.getElementById('disputeCurrencySelect');
    if (currencySelect) {
        currencySelect.value = curr === 'USDC' ? 'USDC' : 'USDT';
        const usdcOption = currencySelect.querySelector('option[value="USDC"]');
        if (usdcOption) {
            usdcOption.disabled = isTrc20;
        }
        if (isTrc20 && currencySelect.value === 'USDC') {
            currencySelect.value = 'USDT';
        }
    }
    document.getElementById('disputeOrderIdText').value = ord;
    document.getElementById('disputeCurrentAmountText').value = `${amt} ${curr === 'USDC' ? 'USDC' : 'USDT'}`;
    document.getElementById('disputeOrderIdInput').value = ord;
    document.getElementById('disputeVerifyOrderIdInput').value = ord;
    document.getElementById('disputeVerifyCurrencyInput').value = (currencySelect && currencySelect.value) ? currencySelect.value : (isTrc20 ? 'USDT' : (curr === 'USDC' ? 'USDC' : 'USDT'));
    document.getElementById('disputeNewAmountInput').value = amt;
    document.getElementById('disputeNoteInput').value = '';
    new bootstrap.Modal(document.getElementById('disputeModal')).show();
}

function submitVerifyOriginal() {
    if (!confirm(<?php echo json_encode($tt('确定按订单原金额进行链上核验并手动确认吗？', 'Confirm verification with original amount and mark paid if matched?')); ?>)) {
        return;
    }
    document.getElementById('disputeVerifyOriginalForm').submit();
}

const disputeCurrencySelect = document.getElementById('disputeCurrencySelect');
if (disputeCurrencySelect) {
    disputeCurrencySelect.addEventListener('change', function () {
        const hidden = document.getElementById('disputeVerifyCurrencyInput');
        if (hidden) hidden.value = disputeCurrencySelect.value;
    });
}

document.querySelectorAll('#merchantOrdersTabs [data-bs-toggle="tab"]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function (e) {
        const pane = e.target.getAttribute('data-bs-target') === '#orders-search-pane' ? 'search' : 'list';
        const url = new URL(window.location.href);
        url.searchParams.set('panel', pane);
        if (pane === 'search') {
            url.searchParams.delete('page');
        }
        window.history.replaceState({}, '', url.toString());
    });
});
</script>
</body>
</html>
