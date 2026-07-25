<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Core/Migrator.php';
require_once __DIR__ . '/../../src/Services/CryptoService.php';
require_once __DIR__ . '/../../src/Services/WebhookService.php';
require_once __DIR__ . '/../../src/Services/StoreReceiptService.php';
require_once __DIR__ . '/../../src/Services/StoreCouponService.php';

$db = Database::getInstance();
$migrator = new Migrator($db->getConnection());
$migrator->run();

// Repair legacy Stripe upgrade orders created before stripe flow fix.
// Old bad rows looked like: PLAN-*, chain=trc20, currency=USDT, wallet_id NULL/no address.
try {
    $db->query(
        "UPDATE orders
         SET chain = 'stripe',
             currency = 'USD',
             source = 'upgrade',
             updated_at = NOW()
         WHERE (merchant_order_id LIKE 'PLAN-%' OR order_no LIKE 'UPG%')
           AND (source IS NULL OR source = '' OR source = 'api')
           AND (wallet_id IS NULL OR wallet_id = 0)
           AND LOWER(chain) = 'trc20'"
    );
} catch (Throwable $e) {
    // Keep admin page usable even if this repair query fails.
}

$admin_orders_has_notes_column = false;
try {
    $notesCol = $db->fetch(
        "SELECT COUNT(*) AS c
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = 'orders'
           AND column_name = 'notes'"
    );
    $admin_orders_has_notes_column = ((int)($notesCol['c'] ?? 0) > 0);
    if (!$admin_orders_has_notes_column) {
        $db->query("ALTER TABLE orders ADD COLUMN notes TEXT DEFAULT NULL");
        $admin_orders_has_notes_column = true;
    }
} catch (Throwable $e) {
    // Keep page usable even if schema patch fails.
    $admin_orders_has_notes_column = false;
}

if (!function_exists('admin_orders_set_flash')) {
    function admin_orders_set_flash(string $type, string $message): void {
        $_SESSION['admin_orders_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('admin_orders_status_label')) {
    function admin_orders_status_label(string $status): string {
        return match (strtolower($status)) {
            'paid' => '已支付',
            'pending' => '待支付',
            'expired' => '已过期',
            default => '未知状态',
        };
    }
}

if (!function_exists('admin_orders_last_note')) {
    function admin_orders_last_note(?string $notes): string {
        $text = trim((string)$notes);
        if ($text === '') {
            return '';
        }
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $lines = array_values(array_filter(array_map('trim', $lines), fn($v) => $v !== ''));
        if (empty($lines)) {
            return '';
        }
        $last = (string)$lines[count($lines) - 1];
        if (strpos($last, '[管理员手动修正]') !== false) {
            if (preg_match('/备注:\s*(.*?)(?:;\s*时间:|$)/u', $last, $m)) {
                $parsed = trim((string)($m[1] ?? ''));
                if ($parsed !== '' && $parsed !== '无') {
                    return '管理员备注：' . $parsed;
                }
            }
            return '';
        }
        return $last;
    }
}

// Chain Check Logic (Preserved)
if (!function_exists('admin_orders_check_chain')) {
    function admin_orders_check_chain(array $order): ?array {
        $address = (string)($order['wallet_address'] ?? '');
        $chain = strtolower((string)($order['chain'] ?? ''));
        $amount = (float)($order['amount'] ?? 0);
        $createdTs = strtotime((string)($order['created_at'] ?? ''));
        if ($address === '' || $chain === '' || $amount <= 0 || !$createdTs) {
            return null;
        }

        global $chains_config;
        if (!isset($chains_config)) {
            require __DIR__ . '/../../config/config.php';
        }

        CryptoService::setExternalUsageContext([
            'user_id' => (int)($order['user_id'] ?? 0),
            'order_id' => (int)($order['id'] ?? 0),
            'order_no' => (string)($order['order_no'] ?? ''),
            'chain' => $chain,
            'source' => 'admin_orders_manual',
            'trigger_mode' => !empty($order['is_fast_sync']) ? 'fast_sync' : 'manual',
        ]);
        try {
            if ($chain === 'trc20') {
                return CryptoService::checkTrc20($address, $amount, $createdTs) ?: null;
            }
            if ($chain === 'solana') {
                return CryptoService::checkSolana($address, $amount, $createdTs) ?: null;
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
                $tx = CryptoService::checkEvm($k, $address, $amount, $createdTs);
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

$type = $_GET['type'] ?? 'all';
$allowedTypes = ['all', 'api', 'test', 'exception', 'subscription'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'all';
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        admin_orders_set_flash('danger', 'CSRF validation failed.');
        header('Location: orders.php?type=' . urlencode($type));
        exit;
    }

    $action = (string)($_POST['action'] ?? '');

    // 0. Delete ALL orders by status (no checkbox needed)
    if ($action === 'delete_all_status') {
        $delStatus = $_POST['del_status'] ?? '';
        $allowed = ['pending', 'expired', 'paid'];
        if (in_array($delStatus, $allowed, true)) {
            $count = (int)($db->fetch("SELECT COUNT(*) as c FROM orders WHERE status = ?", [$delStatus])['c'] ?? 0);
            $db->query("DELETE FROM orders WHERE status = ?", [$delStatus]);
            admin_orders_set_flash('success', "已删除全部 {$count} 条「{$delStatus}」订单。");
        }
        header('Location: orders.php?type=' . urlencode($type));
        exit;
    }

    // 1. Delete / Mark Paid (Bulk)
    if (($action === 'delete' || $action === 'mark_paid') && isset($_POST['order_ids']) && is_array($_POST['order_ids'])) {
        $ids = array_values(array_filter(array_map('intval', $_POST['order_ids']), fn($v) => $v > 0));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            if ($action === 'delete') {
                $db->query("DELETE FROM orders WHERE id IN ($placeholders)", $ids);
                admin_orders_set_flash('success', 'Deleted ' . count($ids) . ' orders.');
            } else {
                $db->query("UPDATE orders SET status = 'paid', updated_at = NOW() WHERE id IN ($placeholders)", $ids);
                foreach ($ids as $oid) {
                    StoreCouponService::applyOnPaid($db, (int)$oid);
                }
                admin_orders_set_flash('success', 'Marked ' . count($ids) . ' orders as paid.');
            }
        }
        header('Location: orders.php?type=' . urlencode($type));
        exit;
    }

    // 2. Verify Exception (Chain Check)
    if ($action === 'verify_exception') {
        $orderId = (int)($_POST['exception_order_id'] ?? 0);
        if ($orderId > 0) {
            $order = $db->fetch("SELECT o.*, w.address AS wallet_address FROM orders o LEFT JOIN wallets w ON o.wallet_id = w.id WHERE o.id = ? LIMIT 1", [$orderId]);
            if ($order) {
                if ((string)$order['status'] === 'paid') {
                    admin_orders_set_flash('info', 'Order is already paid.');
                } else {
                    try {
                        $tx = admin_orders_check_chain($order);
                        if (!empty($tx['hash'])) {
                            $db->query("UPDATE orders SET status='paid', pay_provider='crypto', paid_at=NOW(), tx_hash=?, updated_at=NOW(), last_external_sync=NOW() WHERE id=?", [(string)$tx['hash'], $orderId]);
                            StoreCouponService::applyOnPaid($db, (int)$orderId);
                            admin_orders_set_flash('success', 'Verified on-chain! Order marked as paid. Tx: ' . (string)$tx['hash']);
                        } else {
                            admin_orders_set_flash('warning', 'No matching on-chain transaction found.');
                        }
                    } catch (Throwable $e) {
                        admin_orders_set_flash('danger', 'Verification error: ' . $e->getMessage());
                    }
                }
            }
        }
        header('Location: orders.php?type=exception&focus=' . $orderId);
        exit;
    }

    // 3. Verify New Amount On-chain First, then Update
    if ($action === 'update_amount') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newAmount = (float)($_POST['new_amount'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        
        if ($orderId <= 0 || $newAmount <= 0) {
            admin_orders_set_flash('danger', '金额无效，请重新输入。');
            header('Location: orders.php?type=exception&focus=' . $orderId);
            exit;
        }

        try {
            $order = $db->fetch(
                "SELECT o.*, w.address AS wallet_address
                 FROM orders o
                 LEFT JOIN wallets w ON o.wallet_id = w.id
                 WHERE o.id = ?
                 LIMIT 1",
                [$orderId]
            );
            if (!$order) {
                admin_orders_set_flash('danger', '订单不存在。');
                header('Location: orders.php?type=exception');
                exit;
            }

            $oldStatus = (string)($order['status'] ?? 'pending');
            $orderForCheck = $order;
            $orderForCheck['amount'] = $newAmount;
            $tx = admin_orders_check_chain($orderForCheck);
            if (!empty($tx['hash'])) {
                global $admin_orders_has_notes_column;
                if ($admin_orders_has_notes_column && $note !== '') {
                    $appendNote = "管理员备注（金额核实通过）: {$note} (" . date('Y-m-d H:i:s') . ")\n";
                    $db->query(
                        "UPDATE orders
                         SET amount = ?,
                             status='paid',
                             pay_provider='crypto',
                             paid_at=NOW(),
                             tx_hash=?,
                             updated_at=NOW(),
                             last_external_sync=NOW(),
                             notes = CONCAT(IFNULL(notes, ''), ?)
                         WHERE id=?",
                        [$newAmount, (string)$tx['hash'], $appendNote, $orderId]
                    );
                } else {
                    $db->query(
                        "UPDATE orders
                         SET amount = ?,
                             status='paid',
                             pay_provider='crypto',
                             paid_at=NOW(),
                             tx_hash=?,
                             updated_at=NOW(),
                             last_external_sync=NOW()
                         WHERE id=?",
                        [$newAmount, (string)$tx['hash'], $orderId]
                    );
                }

                $paidOrder = $db->fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
                try {
                    if ($paidOrder && $oldStatus !== 'paid') {
                        WebhookService::send($paidOrder);
                    }
                } catch (Throwable $e) {
                    // Webhook should not block flow.
                }
                try {
                    if ($oldStatus !== 'paid') {
                        StoreCouponService::applyOnPaid($db, (int)$orderId);
                        StoreReceiptService::sendForOrder($orderId);
                    }
                } catch (Throwable $e) {
                    // Receipt sending should not block flow.
                }

                admin_orders_set_flash('success', '金额已修改，且已匹配链上交易，订单自动标记为“已支付”。');
            } else {
                admin_orders_set_flash('danger', '修改失败：金额不对或查无此交易！');
            }
        } catch (Throwable $e) {
            admin_orders_set_flash('danger', '修改失败：' . $e->getMessage());
        }
        header('Location: orders.php?type=exception&focus=' . $orderId);
        exit;
    }
}

// Pagination & Filtering
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 20;
$offset = ($page - 1) * $limit;
$focusId = isset($_GET['focus']) ? (int)$_GET['focus'] : 0;
$search = trim($_GET['search'] ?? '');
$statusFilter = $_GET['status_filter'] ?? '';
if (!in_array($statusFilter, ['', 'pending', 'paid', 'expired'], true)) $statusFilter = '';

$where = [];
$params = [];

if ($search) {
    $where[] = "(o.order_no LIKE ? OR o.merchant_order_id LIKE ? OR w.address LIKE ? OR u.email LIKE ? OR u.full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($statusFilter !== '') {
    $where[] = "o.status = ?";
    $params[] = $statusFilter;
}

if ($type === 'test') {
    $where[] = "o.order_no LIKE 'TEST%'";
} elseif ($type === 'subscription') {
    $where[] = "(o.merchant_order_id LIKE 'PLAN-%' OR o.order_no LIKE 'UPG%')";
} elseif ($type === 'api') {
    $where[] = "o.order_no NOT LIKE 'TEST%'";
} elseif ($type === 'exception') {
    if ($statusFilter === '') {
        $where[] = "o.status IN ('pending', 'expired')";
    }
}

$whereSQL = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

$totalRow = $db->fetch("SELECT COUNT(*) AS c FROM orders o LEFT JOIN wallets w ON o.wallet_id = w.id LEFT JOIN users u ON o.user_id = u.id {$whereSQL}", $params);
$total = (int)($totalRow['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $limit;

$orders = $db->fetchAll(
    "SELECT o.*, u.email, w.address AS wallet_address, w.chain AS wallet_chain
     FROM orders o
     LEFT JOIN users u ON o.user_id = u.id
     LEFT JOIN wallets w ON o.wallet_id = w.id
     {$whereSQL}
     ORDER BY o.id DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);
foreach ($orders as &$orderRow) {
    $orderRow['tx_explorer_url'] = '';
    $txHash = trim((string)($orderRow['tx_hash'] ?? ''));
    if ($txHash !== '') {
        try {
            $orderRow['tx_explorer_url'] = (string)CryptoService::getExplorerUrl((string)($orderRow['chain'] ?? ''), $txHash);
        } catch (Throwable $e) {
            $orderRow['tx_explorer_url'] = '';
        }
    }
}
unset($orderRow);

$flash = $_SESSION['admin_orders_flash'] ?? null;
unset($_SESSION['admin_orders_flash']);

$active_menu = 'orders';
require_once 'includes/header.php';
?>

<!-- Tailwind CSS (Scoped/Preflight Disabled to play nice with Bootstrap) -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    corePlugins: {
      preflight: false,
    },
    theme: {
      extend: {
        colors: {
            primary: '#3b82f6',
            success: '#10b981',
            warning: '#f59e0b',
            danger: '#ef4444',
            dark: '#1f2937',
        }
      }
    }
  }
</script>
<style>
    /* Custom overrides for smooth integration */
    .tw-btn {
        @apply px-3 py-1.5 rounded-md text-sm font-medium transition-colors duration-200 flex items-center gap-2;
    }
    .tw-badge {
        @apply px-2 py-0.5 rounded text-xs font-medium border;
    }
    .tw-input {
        @apply border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm;
    }
</style>

<div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
    
    <!-- Top Stats / Filter Bar -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
        
        <!-- Tabs -->
        <div class="flex space-x-1 bg-gray-100 p-1 rounded-lg">
            <?php 
            $tabs = [
                'all' => '全部',
                'api' => 'API',
                'test' => '测试',
                'exception' => '异常/待处理',
                'subscription' => '订阅'
            ];
            foreach ($tabs as $k => $label): 
                $active = $type === $k;
            ?>
            <a href="?type=<?php echo $k; ?>" 
               class="px-4 py-1.5 rounded-md text-sm font-medium transition-all <?php echo $active ? 'bg-white text-blue-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'; ?>">
               <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Search + Status Filter -->
        <form method="GET" class="flex gap-2 flex-wrap">
            <input type="hidden" name="type" value="<?php echo htmlspecialchars($type); ?>">
            <select name="status_filter" class="border border-gray-300 rounded-lg text-sm px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-blue-500 bg-white">
                <option value="" <?php echo $statusFilter===''?'selected':''; ?>>全部状态</option>
                <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>待支付 Pending</option>
                <option value="paid" <?php echo $statusFilter==='paid'?'selected':''; ?>>已支付 Paid</option>
                <option value="expired" <?php echo $statusFilter==='expired'?'selected':''; ?>>已过期 Expired</option>
            </select>
            <div class="relative">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="订单号 / 地址 / 邮箱 / 姓名"
                       class="pl-9 pr-4 py-1.5 border border-gray-300 rounded-lg text-sm w-56 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <i class="fa-solid fa-search absolute left-3 top-2.5 text-gray-400 text-xs"></i>
            </div>
            <button type="submit" class="bg-gray-800 text-white px-3 py-1.5 rounded-lg text-sm hover:bg-gray-700">筛选</button>
        </form>
    </div>

    <!-- Flash Messages (converted to top-right toast by admin header script) -->
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

    <!-- Bulk Actions Form -->
    <form method="POST" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
        
        <!-- Toolbar -->
        <div class="flex justify-between items-center mb-4 bg-gray-50 p-2 rounded-lg border border-gray-100 gap-2 flex-wrap">
            <div class="flex items-center gap-3">
                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 ml-2">
                <span class="text-xs text-gray-500 font-medium uppercase tracking-wider">Select All</span>
                <span class="text-xs text-gray-400">共 <?php echo $total; ?> 条</span>
            </div>
            <div class="flex gap-2 flex-wrap">
                <button type="submit" name="action" value="mark_paid" onclick="return confirm('确定将选中订单标记为已支付？')"
                        class="bg-green-600 hover:bg-green-700 text-white text-xs px-3 py-1.5 rounded flex items-center gap-1">
                    <i class="fa-solid fa-check-double"></i> 批量已支付
                </button>
                <button type="submit" name="action" value="delete" onclick="return confirm('确定删除选中订单？此操作不可恢复！')"
                        class="bg-white border border-gray-300 text-red-600 hover:bg-red-50 text-xs px-3 py-1.5 rounded flex items-center gap-1">
                    <i class="fa-solid fa-trash"></i> 批量删除
                </button>
                <?php
                // 快捷一键删除按钮（根据当前状态筛选或默认显示pending）
                $quickDelStatus = $statusFilter !== '' ? $statusFilter : 'pending';
                $quickDelCount = (int)($db->fetch("SELECT COUNT(*) as c FROM orders WHERE status = ?", [$quickDelStatus])['c'] ?? 0);
                $quickDelLabel = ['pending' => '待支付', 'expired' => '已过期', 'paid' => '已支付'][$quickDelStatus] ?? $quickDelStatus;
                if ($quickDelCount > 0):
                ?>
                <button type="button"
                        onclick="confirmDeleteAll('<?php echo $quickDelStatus; ?>', <?php echo $quickDelCount; ?>, '<?php echo $quickDelLabel; ?>')"
                        class="bg-red-600 hover:bg-red-700 text-white text-xs px-3 py-1.5 rounded flex items-center gap-1">
                    <i class="fa-solid fa-fire"></i> 删除全部「<?php echo $quickDelLabel; ?>」(<?php echo $quickDelCount; ?> 条)
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Data Table -->
        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-200 text-xs uppercase text-gray-500 font-semibold">
                        <th class="p-3 w-10"></th>
                        <th class="p-3">订单信息</th>
                        <th class="p-3">金额 (预计)</th>
                        <th class="p-3">网络 / 地址</th>
                        <th class="p-3">状态</th>
                        <th class="p-3">用户</th>
                        <th class="p-3">时间</th>
                        <th class="p-3 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 text-sm">
                    <?php foreach ($orders as $o): 
                        $isTest = strpos((string)$o['order_no'], 'TEST') === 0;
                        $isSub = strpos((string)$o['merchant_order_id'], 'PLAN-') === 0 || strpos((string)$o['order_no'], 'UPG') === 0;
                        $statusColor = match((string)$o['status']) {
                            'paid' => 'bg-green-100 text-green-700 border-green-200',
                            'pending' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                            'expired' => 'bg-gray-100 text-gray-600 border-gray-200',
                            default => 'bg-gray-100 text-gray-600'
                        };
                        $isFocused = $focusId > 0 && (int)$o['id'] === $focusId;
                    ?>
                    <tr class="hover:bg-gray-50 transition-colors <?php echo $isFocused ? 'bg-yellow-50' : ''; ?>">
                        <td class="p-3">
                            <input type="checkbox" name="order_ids[]" value="<?php echo (int)$o['id']; ?>" class="order-check rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        </td>
                        <td class="p-3">
                            <div class="flex flex-col">
                                <span class="font-mono font-medium text-gray-900"><?php echo htmlspecialchars((string)$o['order_no']); ?></span>
                                <span class="text-xs text-gray-500 mt-0.5" title="Merchant Order ID">
                                    <?php echo htmlspecialchars((string)$o['merchant_order_id']); ?>
                                </span>
                                <?php
                                    $lastNote = admin_orders_last_note($o['notes'] ?? null);
                                    if ($lastNote !== ''):
                                ?>
                                    <span class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-2 py-1 mt-1.5 inline-block max-w-[320px] truncate" title="<?php echo htmlspecialchars($lastNote); ?>">
                                        备注：<?php echo htmlspecialchars($lastNote); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if($isTest): ?>
                                    <span class="inline-flex mt-1 items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 w-fit">TEST</span>
                                <?php elseif($isSub): ?>
                                    <span class="inline-flex mt-1 items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-800 w-fit">SUB</span>
                                <?php endif; ?>
                                <?php
                                    $origin = strtolower((string)($o['order_origin'] ?? 'merchant_customer_order'));
                                    $originLabel = $origin === 'merchant_order' ? '商户订单' : '商户客户订单';
                                    $originCls = $origin === 'merchant_order'
                                        ? 'bg-blue-100 text-blue-800'
                                        : 'bg-emerald-100 text-emerald-800';
                                ?>
                                <span class="inline-flex mt-1 items-center px-1.5 py-0.5 rounded text-xs font-medium <?php echo $originCls; ?> w-fit"><?php echo $originLabel; ?></span>
                            </div>
                        </td>
                        <td class="p-3">
                            <div class="font-bold text-gray-900">
                                <?php echo htmlspecialchars((string)$o['amount']); ?> 
                                <span class="text-xs font-normal text-gray-500"><?php echo htmlspecialchars((string)$o['currency']); ?></span>
                            </div>
                        </td>
                        <td class="p-3">
                            <div class="flex flex-col gap-1">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700 border border-blue-100 w-fit uppercase">
                                    <?php echo htmlspecialchars((string)$o['chain']); ?>
                                </span>
                                <?php if (!empty($o['wallet_address'])): ?>
                                <div class="flex items-center gap-1 text-gray-500">
                                    <span class="font-mono text-xs truncate max-w-[120px]" title="<?php echo htmlspecialchars($o['wallet_address']); ?>">
                                        <?php echo substr($o['wallet_address'], 0, 6) . '...' . substr($o['wallet_address'], -4); ?>
                                    </span>
                                    <button type="button" class="copy-btn text-gray-400 hover:text-blue-600" data-clipboard-text="<?php echo htmlspecialchars($o['wallet_address']); ?>">
                                        <i class="fa-regular fa-copy text-xs"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="p-3">
                            <span class="tw-badge <?php echo $statusColor; ?>">
                                <?php echo admin_orders_status_label((string)$o['status']); ?>
                            </span>
                            <?php
                                $refundStatus = strtolower((string)($o['refund_status'] ?? ''));
                                if ($refundStatus === 'full'):
                            ?>
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700 border border-red-200">全额退款</span>
                                </div>
                            <?php elseif ($refundStatus === 'partial'): ?>
                                <div class="mt-1">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700 border border-orange-200">
                                        部分退款 <?php echo htmlspecialchars((string)($o['refund_amount'] ?? '0')); ?> <?php echo htmlspecialchars((string)($o['currency'] ?? '')); ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($o['tx_hash'])): ?>
                                <div class="mt-1">
                                    <?php if (!empty($o['tx_explorer_url'])): ?>
                                        <a href="<?php echo htmlspecialchars((string)$o['tx_explorer_url']); ?>" target="_blank" rel="noopener noreferrer" class="text-xs text-gray-400 hover:text-blue-600 underline decoration-dotted" title="<?php echo htmlspecialchars((string)$o['tx_hash']); ?>">TxHash</a>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400" title="<?php echo htmlspecialchars((string)$o['tx_hash']); ?>">TxHash</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="p-3">
                            <?php if (!empty($o['email'])): ?>
                                <a href="users.php?search=<?php echo urlencode((string)$o['email']); ?>" class="text-blue-600 hover:underline text-xs">
                                    <?php echo htmlspecialchars((string)$o['email']); ?>
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-gray-400">Guest</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 text-xs text-gray-500">
                            <?php echo date('m-d H:i', strtotime($o['created_at'])); ?>
                        </td>
                        <td class="p-3 text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button"
                                        class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 p-1.5 rounded transition-colors"
                                        title="查看详情"
                                        onclick='openAdminOrderDetail(<?php echo htmlspecialchars(json_encode($o, JSON_UNESCAPED_UNICODE), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                                <?php if ($type === 'exception' && (string)$o['status'] !== 'paid'): ?>
                                    <button type="button" 
                                            onclick="openEditModal(<?php echo $o['id']; ?>, '<?php echo $o['amount']; ?>')"
                                            class="bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 hover:text-blue-600 p-1.5 rounded transition-colors" title="修改入账金额">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <button type="submit" form="verifyForm-<?php echo $o['id']; ?>" class="bg-blue-600 text-white p-1.5 rounded hover:bg-blue-700 shadow-sm" title="核实并标记已支付">
                                        <i class="fa-solid fa-check"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="8" class="p-8 text-center text-gray-400 bg-gray-50">
                            <div class="flex flex-col items-center">
                                <i class="fa-solid fa-inbox text-3xl mb-2 opacity-50"></i>
                                <span>没有找到相关订单</span>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="mt-6 flex justify-center">
            <nav class="flex items-center gap-1">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?type=<?php echo urlencode($type); ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" 
                   class="px-3 py-1 rounded-md text-sm font-medium <?php echo $page === $i ? 'bg-blue-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50 border border-gray-200'; ?>">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>
            </nav>
        </div>
        <?php endif; ?>
    </form>
    
    <!-- Hidden Forms for Actions -->
    <?php foreach ($orders as $o): ?>
        <?php if ($type === 'exception' && (string)$o['status'] !== 'paid'): ?>
        <form id="verifyForm-<?php echo $o['id']; ?>" method="POST" class="hidden">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
            <input type="hidden" name="action" value="verify_exception">
            <input type="hidden" name="exception_order_id" value="<?php echo $o['id']; ?>">
        </form>
        <?php endif; ?>
    <?php endforeach; ?>

</div>

<!-- Admin Order Detail Modal -->
<div id="adminOrderDetailModal" class="fixed inset-0 z-[9998] hidden" aria-modal="true" role="dialog">
    <div class="fixed inset-0 bg-black/40" onclick="closeAdminOrderDetail()"></div>
    <div class="fixed inset-0 overflow-y-auto p-4">
        <div class="mx-auto max-w-3xl bg-white rounded-xl shadow-xl border border-gray-200">
            <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900">订单详情</h3>
                <button type="button" class="text-gray-500 hover:text-gray-700" onclick="closeAdminOrderDetail()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                <div><div class="text-gray-500">系统订单号</div><div id="adm_d_order_no" class="font-mono"></div></div>
                <div><div class="text-gray-500">商户单号</div><div id="adm_d_merchant_order_id" class="font-mono"></div></div>
                <div><div class="text-gray-500">支付方式</div><div id="adm_d_pay_method"></div></div>
                <div><div class="text-gray-500">支付状态</div><div id="adm_d_status"></div></div>
                <div><div class="text-gray-500">支付时间</div><div id="adm_d_paid_at"></div></div>
                <div><div class="text-gray-500">支付币种/金额</div><div id="adm_d_amount"></div></div>
                <div><div class="text-gray-500">支付网络</div><div id="adm_d_chain"></div></div>
                <div><div class="text-gray-500">来源类型</div><div id="adm_d_origin"></div></div>
                <div class="md:col-span-2"><div class="text-gray-500">付款来源地址</div><div id="adm_d_wallet_addr" class="font-mono break-all"></div></div>
                <div class="md:col-span-2"><div class="text-gray-500" id="adm_d_tx_label">交易哈希</div><div id="adm_d_tx_hash" class="font-mono break-all"></div></div>
                <div><div class="text-gray-500">退款类型</div><div id="adm_d_refund_type"></div></div>
                <div><div class="text-gray-500">退款金额</div><div id="adm_d_refund_amount"></div></div>
                <div><div class="text-gray-500">付款 Binance UID</div><div id="adm_d_binance_uid"></div></div>
                <div><div class="text-gray-500">收款 Binance 商户ID</div><div id="adm_d_binance_mid"></div></div>
                <div><div class="text-gray-500">商户ID（套餐订单）</div><div id="adm_d_upgrade_uid"></div></div>
                <div><div class="text-gray-500">商户邮箱（套餐订单）</div><div id="adm_d_upgrade_email"></div></div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Amount Modal -->
<div id="editAmountModal" class="fixed inset-0 z-[9999] hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" onclick="closeEditModal()"></div>
    <div class="fixed inset-0 z-10 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 text-center">
            <div class="relative transform overflow-hidden rounded-xl bg-white text-left shadow-xl transition-all w-full max-w-2xl">
                <form method="POST" id="editAmountForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                    <input type="hidden" name="action" value="update_amount">
                    <input type="hidden" name="order_id" id="modalOrderId">
                    
                    <div class="bg-white px-6 py-6">
                        <div class="w-full">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-100">
                                <i class="fa-solid fa-pen-to-square text-blue-600"></i>
                            </div>
                            <div class="mt-4 w-full max-w-xl mx-auto text-center">
                                <h3 class="text-base font-semibold leading-6 text-gray-900" id="modal-title">修改入账金额</h3>
                                <p class="text-sm text-gray-500 mt-3 mb-5">
                                    如果用户实际支付金额与订单金额不一致，您可以在此修正。
                                </p>
                                <div class="space-y-4 text-left">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 text-center">新的金额</label>
                                        <input type="number" step="0.000001" name="new_amount" id="modalAmount" required class="mt-1.5 block w-full h-12 rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-base border px-4 text-center">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 text-center">备注原因</label>
                                        <textarea name="note" rows="3" class="mt-1.5 block w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-base border p-3" placeholder="例如：用户少付了 0.1 U"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-6 py-4 flex justify-end gap-3">
                        <button type="button" onclick="closeEditModal()" class="inline-flex items-center justify-center h-11 min-w-[108px] rounded-lg bg-white px-5 text-sm font-semibold text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 hover:bg-gray-50">取消</button>
                        <button type="submit" id="verifyAmountSubmitBtn" class="inline-flex items-center justify-center h-11 min-w-[148px] rounded-lg bg-blue-600 px-5 text-sm font-semibold text-white shadow-sm hover:bg-blue-500">立即核实金额</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="verifyLoadingMask" class="fixed inset-0 z-[10000] hidden bg-black/35 backdrop-blur-[1px]">
    <div class="h-full w-full flex items-center justify-center">
        <div class="bg-white rounded-xl px-6 py-5 shadow-xl border border-gray-200 text-center min-w-[260px]">
            <div class="mx-auto mb-3 w-9 h-9 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin"></div>
            <p class="text-sm font-medium text-gray-700">正在链上核实金额，请稍候...</p>
        </div>
    </div>
</div>

<!-- 一键删除全部指定状态订单的隐藏表单 -->
<form id="deleteAllForm" method="POST" style="display:none">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
    <input type="hidden" name="action" value="delete_all_status">
    <input type="hidden" name="del_status" id="deleteAllStatus" value="">
</form>

<script>
    function confirmDeleteAll(status, count, label) {
        if (confirm('⚠️ 确定要删除全部 ' + count + ' 条「' + label + '」订单吗？\n\n此操作不可恢复！')) {
            document.getElementById('deleteAllStatus').value = status;
            document.getElementById('deleteAllForm').submit();
        }
    }

    // Select All Checkbox
    const selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            document.querySelectorAll('.order-check').forEach(c => c.checked = selectAll.checked);
        });
    }

    // Copy to Clipboard
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const text = btn.getAttribute('data-clipboard-text');
            if(text) {
                try {
                    await navigator.clipboard.writeText(text);
                    const icon = btn.querySelector('i');
                    icon.classList.remove('fa-copy', 'fa-regular');
                    icon.classList.add('fa-check', 'fa-solid', 'text-green-500');
                    setTimeout(() => {
                        icon.classList.remove('fa-check', 'fa-solid', 'text-green-500');
                        icon.classList.add('fa-copy', 'fa-regular');
                    }, 1500);
                } catch(err) {}
            }
        });
    });

    // Modal Logic
    function openEditModal(id, currentAmount) {
        document.getElementById('modalOrderId').value = id;
        document.getElementById('modalAmount').value = currentAmount;
        document.getElementById('editAmountModal').classList.remove('hidden');
    }

    function closeEditModal() {
        document.getElementById('editAmountModal').classList.add('hidden');
    }

    const editAmountForm = document.getElementById('editAmountForm');
    const verifyLoadingMask = document.getElementById('verifyLoadingMask');
    const verifyAmountSubmitBtn = document.getElementById('verifyAmountSubmitBtn');
    if (editAmountForm) {
        editAmountForm.addEventListener('submit', function () {
            if (verifyAmountSubmitBtn) {
                verifyAmountSubmitBtn.disabled = true;
                verifyAmountSubmitBtn.classList.add('opacity-70', 'cursor-not-allowed');
                verifyAmountSubmitBtn.textContent = '核实中...';
            }
            if (verifyLoadingMask) {
                verifyLoadingMask.classList.remove('hidden');
            }
        });
    }

    function payMethodLabel(o) {
        const provider = String(o.pay_provider || '').toLowerCase();
        const chain = String(o.chain || '').toLowerCase();
        if (provider === 'stripe' || chain.includes('stripe')) return 'Stripe';
        if (provider === 'binance' || chain === 'binance_pay') return 'Binance Pay';
        if (provider === 'balance' || chain === 'balance') return '余额支付';
        if (provider === 'coupon' || chain === 'coupon') return '优惠券';
        return '加密支付';
    }

    function txLabelByMethod(o) {
        const method = payMethodLabel(o);
        if (method === 'Stripe') return 'Stripe 内部订单号';
        if (method === 'Binance Pay') return 'Binance Pay 订单编号';
        return '交易哈希（TX Hash）';
    }

    function explorerUrlByChain(chain, txHash) {
        const c = String(chain || '').toLowerCase();
        if (!txHash) return '';
        if (c.includes('trc')) return `https://tronscan.org/#/transaction/${txHash}`;
        if (c.includes('bsc')) return `https://bscscan.com/tx/${txHash}`;
        if (c.includes('polygon') || c === 'matic') return `https://polygonscan.com/tx/${txHash}`;
        if (c.includes('optimism') || c === 'op') return `https://optimistic.etherscan.io/tx/${txHash}`;
        if (c.includes('arbitrum') || c === 'arb') return `https://arbiscan.io/tx/${txHash}`;
        if (c.includes('base')) return `https://basescan.org/tx/${txHash}`;
        if (c.includes('avax') || c.includes('avalanche')) return `https://snowtrace.io/tx/${txHash}`;
        if (c.includes('eth') || c.includes('erc')) return `https://etherscan.io/tx/${txHash}`;
        return '';
    }

    window.openAdminOrderDetail = function (o) {
        if (!o) return;
        const isUpgrade = String(o.source || '').toLowerCase() === 'upgrade' || String(o.order_no || '').startsWith('UPG') || String(o.merchant_order_id || '').startsWith('PLAN-');
        const sourceMap = {
            merchant_order: '商户订单',
            merchant_customer_order: '商户客户订单',
            api: 'API 调用',
            payment_link: '收款链接',
            qr_code: '收款码',
            store: '店铺收款',
            shop: '店铺收款',
            upgrade: '套餐升级',
            recharge: '余额充值'
        };
        const statusMap = {
            paid: '已支付',
            pending: '待支付',
            expired: '已过期',
            refunded: '已退款',
            failed: '失败',
            cancelled: '已取消'
        };
        const rawOrigin = String(o.order_origin || o.source || '-').toLowerCase();
        const refundStatus = String(o.refund_status || '').toLowerCase();
        let refundTypeLabel = '-';
        if (refundStatus === 'full') refundTypeLabel = '全额退款';
        if (refundStatus === 'partial') refundTypeLabel = '部分退款';
        document.getElementById('adm_d_order_no').textContent = o.order_no || '-';
        document.getElementById('adm_d_merchant_order_id').textContent = o.merchant_order_id || '-';
        document.getElementById('adm_d_pay_method').textContent = payMethodLabel(o);
        document.getElementById('adm_d_status').textContent = statusMap[String(o.status || '').toLowerCase()] || o.status || '-';
        document.getElementById('adm_d_paid_at').textContent = o.paid_at || '-';
        document.getElementById('adm_d_amount').textContent = `${o.amount || '-'} ${o.currency || ''}`;
        document.getElementById('adm_d_chain').textContent = o.chain || '-';
        document.getElementById('adm_d_origin').textContent = sourceMap[rawOrigin] || (o.order_origin || o.source || '-');
        document.getElementById('adm_d_wallet_addr').textContent = o.wallet_address || '-';
        document.getElementById('adm_d_tx_label').textContent = txLabelByMethod(o);
        const txHashEl = document.getElementById('adm_d_tx_hash');
        const txHash = String(o.tx_hash || '').trim();
        const explorerUrl = String(o.tx_explorer_url || '').trim() || explorerUrlByChain(o.chain, txHash);
        if (txHash && explorerUrl) {
            const a = document.createElement('a');
            a.href = explorerUrl;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.className = 'text-blue-600 hover:underline';
            a.textContent = txHash;
            txHashEl.innerHTML = '';
            txHashEl.appendChild(a);
        } else {
            txHashEl.textContent = txHash || '-';
        }
        document.getElementById('adm_d_refund_type').textContent = refundTypeLabel;
        document.getElementById('adm_d_refund_amount').textContent = refundStatus ? `${o.refund_amount || '0'} ${o.currency || ''}` : '-';
        document.getElementById('adm_d_binance_uid').textContent = o.binance_payer_uid || o.binance_open_user_id || '-';
        document.getElementById('adm_d_binance_mid').textContent = o.binance_merchant_id || '-';
        document.getElementById('adm_d_upgrade_uid').textContent = isUpgrade ? (o.user_id || '-') : '-';
        document.getElementById('adm_d_upgrade_email').textContent = isUpgrade ? (o.email || '-') : '-';
        document.getElementById('adminOrderDetailModal').classList.remove('hidden');
    };

    window.closeAdminOrderDetail = function () {
        const el = document.getElementById('adminOrderDetailModal');
        if (el) el.classList.add('hidden');
    };
</script>

<?php require_once 'includes/footer.php'; ?>
