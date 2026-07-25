<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../config/config.php';

$db = Database::getInstance();
$db->autoMigrate();

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$cfgRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$sys = [];
foreach ($cfgRows as $r) { $sys[$r['key_name']] = $r['value']; }
$site_name = $sys['site_name'] ?? 'UAPI';
$site_logo = $sys['site_logo'] ?? '';
$active_menu = 'merchant_derived';

// 1. Summary stats
try {
    $summaryStats = $db->fetch("
        SELECT
            COUNT(DISTINCT a.allocated_to_user_id) AS merchant_count,
            COUNT(DISTINCT a.wallet_id) AS wallet_count,
            COUNT(DISTINCT CASE WHEN o.status='paid' THEN o.id END) AS paid_order_count,
            COALESCE(SUM(CASE WHEN o.status='paid' THEN o.amount ELSE 0 END), 0) AS total_paid
        FROM admin_fee_address_allocations a
        LEFT JOIN orders o ON o.order_no = a.order_no
        WHERE a.allocated_to_user_id IS NOT NULL
    ") ?: [];
} catch (Throwable $e) { $summaryStats = []; }

// 2. Total pending collection
try {
    $pendingTotal = (float)($db->fetch("
        SELECT COALESCE(SUM(sub.eff), 0) AS pending_total
        FROM (
            SELECT (COALESCE(p.paid_amount_display,0) - COALESCE(c.collected_amount_display,0)) AS eff
            FROM admin_derived_wallets w
            LEFT JOIN (
                SELECT a.wallet_id, SUM(COALESCE(o.amount,0)) AS paid_amount_display
                FROM admin_fee_address_allocations a
                INNER JOIN orders o ON o.order_no = a.order_no AND o.status='paid'
                GROUP BY a.wallet_id
            ) p ON p.wallet_id = w.id
            LEFT JOIN (
                SELECT wallet_id, SUM(COALESCE(amount_display,0)) AS collected_amount_display
                FROM admin_collection_items WHERE status='broadcasted' GROUP BY wallet_id
            ) c ON c.wallet_id = w.id
            WHERE w.status=1
              AND EXISTS (SELECT 1 FROM admin_fee_address_allocations a2 WHERE a2.wallet_id = w.id)
              AND (COALESCE(p.paid_amount_display,0) - COALESCE(c.collected_amount_display,0)) > 0
        ) sub
    ")['pending_total'] ?? 0);
} catch (Throwable $e) { $pendingTotal = 0.0; }

// 3. Per-merchant list (all, for client-side search/filter)
try {
    $merchants = $db->fetchAll("
        SELECT u.id AS user_id, u.email, COALESCE(u.username, u.email) AS username, u.created_at AS reg_at,
               COUNT(DISTINCT a.wallet_id) AS wallet_count,
               COUNT(DISTINCT CASE WHEN o.status='paid' THEN o.id END) AS paid_count,
               COALESCE(SUM(CASE WHEN o.status='paid' THEN o.amount ELSE 0 END), 0) AS total_paid,
               COALESCE(SUM(CASE WHEN o.status='paid' AND UPPER(COALESCE(o.currency,'USDT'))='USDT' THEN o.amount ELSE 0 END),0) AS usdt_paid,
               COALESCE(SUM(CASE WHEN o.status='paid' AND UPPER(COALESCE(o.currency,'USDT'))='USDC' THEN o.amount ELSE 0 END),0) AS usdc_paid,
               GROUP_CONCAT(DISTINCT a.chain_slug ORDER BY a.chain_slug SEPARATOR ',') AS chains
        FROM users u
        INNER JOIN admin_fee_address_allocations a ON a.allocated_to_user_id = u.id
        LEFT JOIN orders o ON o.order_no = a.order_no
        GROUP BY u.id, u.email, u.username, u.created_at
        ORDER BY total_paid DESC
        LIMIT 500
    ") ?: [];
} catch (Throwable $e) { $merchants = []; }

// 4. Chain stats
try {
    $chainStats = $db->fetchAll("
        SELECT a.chain_slug,
               COUNT(DISTINCT a.allocated_to_user_id) AS merchant_count,
               COUNT(DISTINCT a.wallet_id) AS wallet_count,
               COUNT(DISTINCT CASE WHEN o.status='paid' THEN o.id END) AS paid_count,
               COALESCE(SUM(CASE WHEN o.status='paid' THEN o.amount ELSE 0 END), 0) AS total_amount
        FROM admin_fee_address_allocations a
        LEFT JOIN orders o ON o.order_no = a.order_no
        WHERE a.allocated_to_user_id IS NOT NULL
        GROUP BY a.chain_slug
        ORDER BY total_amount DESC
    ") ?: [];
} catch (Throwable $e) { $chainStats = []; }

// 5. Pending wallets (top 200 for filtering)
try {
    $pendingWallets = $db->fetchAll("
        SELECT w.id, w.address, w.chain_slug,
               COALESCE(p.paid_amount_display,0) AS paid_amount,
               COALESCE(c.collected_amount_display,0) AS collected_amount,
               (COALESCE(p.paid_amount_display,0) - COALESCE(c.collected_amount_display,0)) AS pending_amount,
               u.email AS merchant_email,
               pcur.currencies
        FROM admin_derived_wallets w
        LEFT JOIN (
            SELECT a.wallet_id, SUM(COALESCE(o.amount,0)) AS paid_amount_display, MAX(a.allocated_to_user_id) AS user_id
            FROM admin_fee_address_allocations a
            INNER JOIN orders o ON o.order_no = a.order_no AND o.status='paid'
            GROUP BY a.wallet_id
        ) p ON p.wallet_id = w.id
        LEFT JOIN (
            SELECT wallet_id, SUM(COALESCE(amount_display,0)) AS collected_amount_display
            FROM admin_collection_items WHERE status='broadcasted' GROUP BY wallet_id
        ) c ON c.wallet_id = w.id
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN (
            SELECT a3.wallet_id, GROUP_CONCAT(DISTINCT UPPER(COALESCE(o3.currency,'USDT')) ORDER BY o3.currency SEPARATOR ',') AS currencies
            FROM admin_fee_address_allocations a3
            INNER JOIN orders o3 ON o3.order_no = a3.order_no AND o3.status='paid'
            GROUP BY a3.wallet_id
        ) pcur ON pcur.wallet_id = w.id
        WHERE w.status=1
          AND EXISTS (SELECT 1 FROM admin_fee_address_allocations a2 WHERE a2.wallet_id = w.id)
          AND (COALESCE(p.paid_amount_display,0) - COALESCE(c.collected_amount_display,0)) > 0
        ORDER BY pending_amount DESC
        LIMIT 200
    ") ?: [];
} catch (Throwable $e) { $pendingWallets = []; }

// 6. Recent paid orders (top 200 for filtering)
try {
    $recentOrders = $db->fetchAll("
        SELECT o.order_no, o.amount, UPPER(COALESCE(o.currency,'USDT')) AS currency,
               o.chain, o.paid_at, o.tx_hash,
               u.email AS merchant_email,
               a.address AS wallet_address
        FROM orders o
        INNER JOIN admin_fee_address_allocations a ON a.order_no = o.order_no
        INNER JOIN users u ON u.id = o.user_id
        WHERE o.status = 'paid'
        ORDER BY o.paid_at DESC
        LIMIT 200
    ") ?: [];
} catch (Throwable $e) { $recentOrders = []; }

// 7. Collected records (top 300)
try {
    $collectedRecords = $db->fetchAll("
        SELECT i.id, i.from_address, i.to_address, i.amount_display, i.tx_hash,
               i.updated_at AS collected_at,
               COALESCE(b.token_symbol, 'USDT') AS token_symbol,
               b.chain_slug, b.chain_id, b.id AS batch_id,
               u.email AS merchant_email,
               GROUP_CONCAT(DISTINCT o.order_no ORDER BY o.order_no SEPARATOR ',') AS order_nos
        FROM admin_collection_items i
        INNER JOIN admin_collection_batches b ON b.id = i.batch_id
        LEFT JOIN admin_derived_wallets w ON w.id = i.wallet_id
        LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
        LEFT JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid'
        LEFT JOIN users u ON u.id = a.allocated_to_user_id
        WHERE i.status = 'broadcasted'
        GROUP BY i.id, i.from_address, i.to_address, i.amount_display, i.tx_hash,
                 i.updated_at, b.token_symbol, b.chain_slug, b.chain_id, b.id, u.email
        ORDER BY i.updated_at DESC
        LIMIT 300
    ") ?: [];
} catch (Throwable $e) { $collectedRecords = []; }

// 8. Pending wallets with order info
try {
    $pendingWalletsWithOrders = $db->fetchAll("
        SELECT w.id AS wallet_id, w.address, w.chain_slug,
               (COALESCE(p.paid_amount_display,0) - COALESCE(c.collected_amount_display,0)) AS pending_amount,
               COALESCE(p.paid_amount_display,0) AS paid_amount,
               COALESCE(c.collected_amount_display,0) AS collected_amount,
               u.email AS merchant_email,
               pcur.currencies,
               orders_info.order_list
        FROM admin_derived_wallets w
        LEFT JOIN (
            SELECT a.wallet_id, SUM(COALESCE(o.amount,0)) AS paid_amount_display, MAX(a.allocated_to_user_id) AS user_id
            FROM admin_fee_address_allocations a
            INNER JOIN orders o ON o.order_no = a.order_no AND o.status='paid'
            GROUP BY a.wallet_id
        ) p ON p.wallet_id = w.id
        LEFT JOIN (
            SELECT wallet_id, SUM(COALESCE(amount_display,0)) AS collected_amount_display
            FROM admin_collection_items WHERE status='broadcasted' GROUP BY wallet_id
        ) c ON c.wallet_id = w.id
        LEFT JOIN users u ON u.id = p.user_id
        LEFT JOIN (
            SELECT a3.wallet_id, GROUP_CONCAT(DISTINCT UPPER(COALESCE(o3.currency,'USDT')) ORDER BY o3.currency SEPARATOR ',') AS currencies
            FROM admin_fee_address_allocations a3
            INNER JOIN orders o3 ON o3.order_no = a3.order_no AND o3.status='paid'
            GROUP BY a3.wallet_id
        ) pcur ON pcur.wallet_id = w.id
        LEFT JOIN (
            SELECT a4.wallet_id,
                   GROUP_CONCAT(DISTINCT CONCAT(o4.order_no,'|',COALESCE(o4.amount,0),'|',UPPER(COALESCE(o4.currency,'USDT'))) ORDER BY o4.id SEPARATOR ';') AS order_list
            FROM admin_fee_address_allocations a4
            INNER JOIN orders o4 ON o4.order_no = a4.order_no AND o4.status='paid'
            GROUP BY a4.wallet_id
        ) orders_info ON orders_info.wallet_id = w.id
        WHERE w.status=1
          AND EXISTS (SELECT 1 FROM admin_fee_address_allocations a2 WHERE a2.wallet_id = w.id)
          AND (COALESCE(p.paid_amount_display,0) - COALESCE(c.collected_amount_display,0)) > 0
        ORDER BY pending_amount DESC
        LIMIT 200
    ") ?: [];
} catch (Throwable $e) { $pendingWalletsWithOrders = $pendingWallets; }

$page_title = '商户派生管理';
require_once __DIR__ . '/includes/header.php';
?>

<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="main-content">
    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-1">商户派生管理</h4>
            <p class="text-muted small mb-0">查看所有商户的派生钱包使用情况</p>
        </div>
    </div>

    <!-- Summary Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3 px-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:40px;height:40px;flex-shrink:0;">
                            <i class="fas fa-users text-primary"></i>
                        </div>
                        <div>
                            <div class="text-muted small">使用派生商户数</div>
                            <div class="fw-bold fs-5 text-primary"><?php echo number_format((int)($summaryStats['merchant_count'] ?? 0)); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3 px-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-info bg-opacity-10 d-flex align-items-center justify-content-center" style="width:40px;height:40px;flex-shrink:0;">
                            <i class="fas fa-wallet text-info"></i>
                        </div>
                        <div>
                            <div class="text-muted small">总分配钱包数</div>
                            <div class="fw-bold fs-5"><?php echo number_format((int)($summaryStats['wallet_count'] ?? 0)); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3 px-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-success bg-opacity-10 d-flex align-items-center justify-content-center" style="width:40px;height:40px;flex-shrink:0;">
                            <i class="fas fa-dollar-sign text-success"></i>
                        </div>
                        <div>
                            <div class="text-muted small">总收款金额</div>
                            <div class="fw-bold fs-5 text-success"><?php echo number_format((float)($summaryStats['total_paid'] ?? 0), 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3 px-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle bg-warning bg-opacity-10 d-flex align-items-center justify-content-center" style="width:40px;height:40px;flex-shrink:0;">
                            <i class="fas fa-hourglass-half text-warning"></i>
                        </div>
                        <div>
                            <div class="text-muted small">待归集金额</div>
                            <div class="fw-bold fs-5 <?php echo $pendingTotal > 0 ? 'text-warning' : 'text-muted'; ?>"><?php echo number_format($pendingTotal, 2); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-bottom px-0 pb-0 pt-3">
            <ul class="nav nav-tabs px-3" id="mdTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-semibold" id="tab-merchants-btn" data-md-tab="merchants" type="button">
                        <i class="fas fa-users me-1"></i>商户明细
                        <span class="badge bg-primary ms-1" style="font-size:0.65rem;"><?php echo count($merchants); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-semibold" id="tab-chains-btn" data-md-tab="chains" type="button">
                        <i class="fas fa-link me-1"></i>链分布统计
                        <span class="badge bg-secondary ms-1" style="font-size:0.65rem;"><?php echo count($chainStats); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-semibold" id="tab-pending-btn" data-md-tab="pending" type="button">
                        <i class="fas fa-hourglass-half me-1"></i>待归集地址
                        <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;"><?php echo count($pendingWallets); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-semibold" id="tab-orders-btn" data-md-tab="orders" type="button">
                        <i class="fas fa-history me-1"></i>收款记录
                        <span class="badge bg-success ms-1" style="font-size:0.65rem;"><?php echo count($recentOrders); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-semibold" id="tab-collected-btn" data-md-tab="collected" type="button">
                        <i class="fas fa-check-circle me-1"></i>已归集明细
                        <span class="badge bg-info ms-1" style="font-size:0.65rem;"><?php echo count($collectedRecords); ?></span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body p-0">

            <!-- Tab: 商户明细 -->
            <div id="md-tab-merchants" class="md-tab-pane">
                <div class="p-3 border-bottom bg-light d-flex flex-wrap gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="max-width:280px;">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control" id="merchantSearch" placeholder="搜索商户邮箱...">
                    </div>
                    <div class="input-group input-group-sm" style="max-width:180px;">
                        <span class="input-group-text bg-white"><i class="fas fa-link text-muted"></i></span>
                        <select class="form-select" id="merchantChainFilter">
                            <option value="">全部链</option>
                            <?php foreach ($chainStats as $cs): ?>
                            <option value="<?php echo htmlspecialchars(strtolower($cs['chain_slug'])); ?>"><?php echo htmlspecialchars(strtoupper($cs['chain_slug'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <span class="text-muted small ms-auto" id="merchantResultCount"><?php echo count($merchants); ?> 个商户</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="merchantTable">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3">商户邮箱</th>
                                <th>链</th>
                                <th class="text-center">钱包数</th>
                                <th class="text-center">已收款单</th>
                                <th class="text-end">USDT</th>
                                <th class="text-end">USDC</th>
                                <th class="text-end pe-3">合计</th>
                                <th class="text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody id="merchantTableBody">
                        <?php if (empty($merchants)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">暂无商户数据</td></tr>
                        <?php else: ?>
                            <?php foreach ($merchants as $m):
                                $chainList = array_filter(explode(',', (string)($m['chains'] ?? '')));
                                $chainStr = implode(' ', array_map('strtolower', $chainList));
                            ?>
                            <tr data-email="<?php echo htmlspecialchars(strtolower($m['email'])); ?>" data-chains="<?php echo htmlspecialchars($chainStr); ?>">
                                <td class="ps-3 small fw-semibold"><?php echo htmlspecialchars($m['email']); ?></td>
                                <td>
                                    <?php foreach ($chainList as $cSlug): ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary me-1" style="font-size:0.7rem;"><?php echo htmlspecialchars(strtoupper($cSlug)); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-center"><?php echo (int)($m['wallet_count'] ?? 0); ?></td>
                                <td class="text-center"><span class="badge bg-success bg-opacity-15 text-success"><?php echo (int)($m['paid_count'] ?? 0); ?></span></td>
                                <td class="text-end small"><?php echo number_format((float)($m['usdt_paid'] ?? 0), 2); ?></td>
                                <td class="text-end small"><?php echo number_format((float)($m['usdc_paid'] ?? 0), 2); ?></td>
                                <td class="text-end pe-3 fw-bold text-success"><?php echo number_format((float)($m['total_paid'] ?? 0), 2); ?></td>
                                <td class="text-center">
                                    <a href="users.php?id=<?php echo (int)$m['user_id']; ?>" class="btn btn-sm btn-outline-primary px-2" title="查看用户">
                                        <i class="fas fa-user"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-top d-flex flex-wrap align-items-center gap-2 justify-content-between bg-white" id="pg-ctrl-merchantTableBody"></div>
            </div>

            <!-- Tab: 链分布统计 -->
            <div id="md-tab-chains" class="md-tab-pane d-none">
                <div class="p-3 border-bottom bg-light d-flex flex-wrap gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="max-width:220px;">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control" id="chainSearch" placeholder="搜索链名称...">
                    </div>
                    <span class="text-muted small ms-auto" id="chainResultCount"><?php echo count($chainStats); ?> 条链记录</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="chainTable">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3">链</th>
                                <th class="text-center">商户数</th>
                                <th class="text-center">钱包数</th>
                                <th class="text-center">收款笔数</th>
                                <th class="text-end pe-3">收款总额</th>
                            </tr>
                        </thead>
                        <tbody id="chainTableBody">
                        <?php if (empty($chainStats)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">暂无链数据</td></tr>
                        <?php else: ?>
                            <?php foreach ($chainStats as $cs): ?>
                            <tr data-chain="<?php echo htmlspecialchars(strtolower((string)($cs['chain_slug'] ?? ''))); ?>">
                                <td class="ps-3"><span class="badge bg-dark" style="font-size:0.75rem;"><?php echo htmlspecialchars(strtoupper((string)($cs['chain_slug'] ?? ''))); ?></span></td>
                                <td class="text-center"><?php echo (int)($cs['merchant_count'] ?? 0); ?></td>
                                <td class="text-center"><?php echo (int)($cs['wallet_count'] ?? 0); ?></td>
                                <td class="text-center"><?php echo (int)($cs['paid_count'] ?? 0); ?></td>
                                <td class="text-end pe-3 fw-bold text-success"><?php echo number_format((float)($cs['total_amount'] ?? 0), 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-top d-flex flex-wrap align-items-center gap-2 justify-content-between bg-white" id="pg-ctrl-chainTableBody"></div>
            </div>

            <!-- Tab: 待归集地址 -->
            <div id="md-tab-pending" class="md-tab-pane d-none">
                <div class="p-3 border-bottom bg-light d-flex flex-wrap gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="max-width:320px;">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control" id="pendingSearch" placeholder="搜索地址、商户邮箱或订单号...">
                    </div>
                    <div class="input-group input-group-sm" style="max-width:160px;">
                        <span class="input-group-text bg-white"><i class="fas fa-link text-muted"></i></span>
                        <select class="form-select" id="pendingChainFilter">
                            <option value="">全部链</option>
                            <?php foreach ($chainStats as $cs): ?>
                            <option value="<?php echo htmlspecialchars(strtolower($cs['chain_slug'])); ?>"><?php echo htmlspecialchars(strtoupper($cs['chain_slug'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group input-group-sm" style="max-width:130px;">
                        <span class="input-group-text bg-white"><i class="fas fa-coins text-muted"></i></span>
                        <select class="form-select" id="pendingTokenFilter">
                            <option value="">全部币种</option>
                            <option value="USDT">USDT</option>
                            <option value="USDC">USDC</option>
                        </select>
                    </div>
                    <span class="text-muted small ms-auto" id="pendingResultCount"><?php echo count($pendingWallets); ?> 个待归集地址</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="pendingTable">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3" style="min-width:140px;">地址</th>
                                <th style="min-width:70px;">链</th>
                                <th style="min-width:160px;">商户</th>
                                <th class="text-center" style="min-width:70px;">币种</th>
                                <th class="text-end" style="min-width:100px;">待归集</th>
                                <th class="text-end" style="min-width:100px;">已收款</th>
                                <th class="text-end" style="min-width:100px;">已归集</th>
                                <th class="pe-3" style="min-width:260px;">关联订单</th>
                            </tr>
                        </thead>
                        <tbody id="pendingTableBody">
                        <?php if (empty($pendingWalletsWithOrders)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-4">暂无待归集地址</td></tr>
                        <?php else: ?>
                            <?php foreach ($pendingWalletsWithOrders as $pw):
                                $pwCurrencies = array_filter(array_map('strtoupper', explode(',', (string)($pw['currencies'] ?? 'USDT'))));
                                if (empty($pwCurrencies)) $pwCurrencies = ['USDT'];
                                $pwCurStr = implode(',', $pwCurrencies);
                                // Parse order_list: "order_no|amount|ccy;..."
                                $orderItems = [];
                                foreach (array_filter(explode(';', (string)($pw['order_list'] ?? ''))) as $oi) {
                                    $parts = explode('|', $oi);
                                    if (count($parts) >= 3) $orderItems[] = ['no' => $parts[0], 'amt' => $parts[1], 'ccy' => $parts[2]];
                                }
                            ?>
                            <?php $pwOrderNos = array_column($orderItems, 'no'); ?>
                            <tr data-address="<?php echo htmlspecialchars(strtolower((string)($pw['address'] ?? ''))); ?>"
                                data-email="<?php echo htmlspecialchars(strtolower((string)($pw['merchant_email'] ?? ''))); ?>"
                                data-chain="<?php echo htmlspecialchars(strtolower((string)($pw['chain_slug'] ?? ''))); ?>"
                                data-currencies="<?php echo htmlspecialchars($pwCurStr); ?>"
                                data-orders="<?php echo htmlspecialchars(strtolower(implode(' ', $pwOrderNos))); ?>">
                                <td class="ps-3">
                                    <code class="small"><?php $addr = (string)($pw['address'] ?? ''); echo htmlspecialchars(substr($addr,0,10).'...'.substr($addr,-8)); ?></code>
                                </td>
                                <td><span class="badge bg-dark" style="font-size:0.7rem;"><?php echo htmlspecialchars(strtoupper((string)($pw['chain_slug'] ?? ''))); ?></span></td>
                                <td class="small text-muted"><?php echo htmlspecialchars((string)($pw['merchant_email'] ?? '-')); ?></td>
                                <td class="text-center">
                                    <?php foreach ($pwCurrencies as $cur): ?>
                                    <span class="badge <?php echo $cur === 'USDC' ? 'bg-info' : 'bg-success'; ?> bg-opacity-75 me-1" style="font-size:0.65rem;"><?php echo htmlspecialchars($cur); ?></span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="text-end fw-bold text-warning"><?php echo number_format((float)($pw['pending_amount'] ?? 0), 4); ?></td>
                                <td class="text-end small text-success"><?php echo number_format((float)($pw['paid_amount'] ?? 0), 4); ?></td>
                                <td class="text-end small text-muted"><?php echo number_format((float)($pw['collected_amount'] ?? 0), 4); ?></td>
                                <td class="pe-3">
                                    <?php if (empty($orderItems)): ?>
                                        <span class="text-muted small">-</span>
                                    <?php else: ?>
                                        <div class="d-flex flex-wrap gap-1">
                                        <?php foreach (array_slice($orderItems, 0, 5) as $oi): ?>
                                            <span class="badge bg-light text-dark border" style="font-size:0.65rem;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo htmlspecialchars($oi['no']); ?>">
                                                <span class="font-monospace"><?php echo htmlspecialchars($oi['no']); ?></span>
                                                <span class="ms-1 text-success"><?php echo number_format((float)$oi['amt'],2); ?></span>
                                                <span class="<?php echo $oi['ccy']==='USDC'?'text-info':'text-success'; ?>"><?php echo htmlspecialchars($oi['ccy']); ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($orderItems) > 5): ?>
                                            <span class="text-muted small">+<?php echo count($orderItems)-5; ?></span>
                                        <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-top d-flex flex-wrap align-items-center gap-2 justify-content-between bg-white" id="pg-ctrl-pendingTableBody"></div>
            </div>

            <!-- Tab: 收款记录 -->
            <div id="md-tab-orders" class="md-tab-pane d-none">
                <div class="p-3 border-bottom bg-light d-flex flex-wrap gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="max-width:260px;">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control" id="orderSearch" placeholder="搜索商户、订单号或地址...">
                    </div>
                    <div class="input-group input-group-sm" style="max-width:160px;">
                        <span class="input-group-text bg-white"><i class="fas fa-link text-muted"></i></span>
                        <select class="form-select" id="orderChainFilter">
                            <option value="">全部链</option>
                            <?php foreach ($chainStats as $cs): ?>
                            <option value="<?php echo htmlspecialchars(strtolower($cs['chain_slug'])); ?>"><?php echo htmlspecialchars(strtoupper($cs['chain_slug'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group input-group-sm" style="max-width:130px;">
                        <span class="input-group-text bg-white"><i class="fas fa-coins text-muted"></i></span>
                        <select class="form-select" id="orderTokenFilter">
                            <option value="">全部币种</option>
                            <option value="USDT">USDT</option>
                            <option value="USDC">USDC</option>
                        </select>
                    </div>
                    <span class="text-muted small ms-auto" id="orderResultCount"><?php echo count($recentOrders); ?> 条记录</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" id="orderTable">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3">时间</th>
                                <th>商户</th>
                                <th>链</th>
                                <th class="text-center">币种</th>
                                <th class="text-end">金额</th>
                                <th>订单号</th>
                                <th class="pe-3">钱包地址</th>
                            </tr>
                        </thead>
                        <tbody id="orderTableBody">
                        <?php if (empty($recentOrders)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">暂无收款记录</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentOrders as $ro):
                                $roCur = strtoupper((string)($ro['currency'] ?? 'USDT'));
                            ?>
                            <tr data-email="<?php echo htmlspecialchars(strtolower((string)($ro['merchant_email'] ?? ''))); ?>"
                                data-order="<?php echo htmlspecialchars(strtolower((string)($ro['order_no'] ?? ''))); ?>"
                                data-address="<?php echo htmlspecialchars(strtolower((string)($ro['wallet_address'] ?? ''))); ?>"
                                data-chain="<?php echo htmlspecialchars(strtolower((string)($ro['chain'] ?? ''))); ?>"
                                data-currency="<?php echo htmlspecialchars($roCur); ?>">
                                <td class="ps-3 small text-muted"><?php echo htmlspecialchars((string)($ro['paid_at'] ?? '-')); ?></td>
                                <td class="small"><?php echo htmlspecialchars((string)($ro['merchant_email'] ?? '-')); ?></td>
                                <td><span class="badge bg-secondary" style="font-size:0.7rem;"><?php echo htmlspecialchars(strtoupper((string)($ro['chain'] ?? ''))); ?></span></td>
                                <td class="text-center">
                                    <span class="badge <?php echo $roCur === 'USDC' ? 'bg-info' : 'bg-success'; ?>"><?php echo htmlspecialchars($roCur); ?></span>
                                </td>
                                <td class="text-end fw-bold text-success"><?php echo number_format((float)($ro['amount'] ?? 0), 2); ?></td>
                                <td class="small"><code><?php echo htmlspecialchars((string)($ro['order_no'] ?? '-')); ?></code></td>
                                <td class="pe-3 small">
                                    <?php $wa = (string)($ro['wallet_address'] ?? ''); ?>
                                    <code><?php echo htmlspecialchars($wa !== '' ? substr($wa,0,8).'...'.substr($wa,-6) : '-'); ?></code>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-top d-flex flex-wrap align-items-center gap-2 justify-content-between bg-white" id="pg-ctrl-orderTableBody"></div>
            </div>

            <!-- Tab: 已归集明细 -->
            <div id="md-tab-collected" class="md-tab-pane d-none">
                <div class="p-3 border-bottom bg-light d-flex flex-wrap gap-2 align-items-center">
                    <div class="input-group input-group-sm" style="max-width:320px;">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" class="form-control" id="collectedSearch" placeholder="搜索地址、商户、订单号或 Tx Hash...">
                    </div>
                    <div class="input-group input-group-sm" style="max-width:160px;">
                        <span class="input-group-text bg-white"><i class="fas fa-link text-muted"></i></span>
                        <select class="form-select" id="collectedChainFilter">
                            <option value="">全部链</option>
                            <?php foreach ($chainStats as $cs): ?>
                            <option value="<?php echo htmlspecialchars(strtolower($cs['chain_slug'])); ?>"><?php echo htmlspecialchars(strtoupper($cs['chain_slug'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group input-group-sm" style="max-width:130px;">
                        <span class="input-group-text bg-white"><i class="fas fa-coins text-muted"></i></span>
                        <select class="form-select" id="collectedTokenFilter">
                            <option value="">全部币种</option>
                            <option value="USDT">USDT</option>
                            <option value="USDC">USDC</option>
                        </select>
                    </div>
                    <span class="text-muted small ms-auto" id="collectedResultCount"><?php echo count($collectedRecords); ?> 条记录</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-3">归集时间</th>
                                <th>链</th>
                                <th>批次</th>
                                <th>商户</th>
                                <th class="text-center">币种</th>
                                <th class="text-end">金额</th>
                                <th>来源地址</th>
                                <th>关联订单</th>
                                <th class="pe-3">Tx Hash</th>
                            </tr>
                        </thead>
                        <tbody id="collectedTableBody">
                        <?php if (empty($collectedRecords)): ?>
                            <tr><td colspan="9" class="text-center text-muted py-4">暂无已归集记录</td></tr>
                        <?php else: ?>
                            <?php
                            $explorerMap = [56=>'https://bscscan.com/tx/',1=>'https://etherscan.io/tx/',137=>'https://polygonscan.com/tx/',42161=>'https://arbiscan.io/tx/',10=>'https://optimistic.etherscan.io/tx/',8453=>'https://basescan.org/tx/',43114=>'https://snowtrace.io/tx/'];
                            foreach ($collectedRecords as $cr):
                                $crTok = strtoupper((string)($cr['token_symbol'] ?? 'USDT'));
                                $crHash = (string)($cr['tx_hash'] ?? '');
                                $crChainId = (int)($cr['chain_id'] ?? 0);
                                $crExplorer = ($crHash !== '' && isset($explorerMap[$crChainId])) ? $explorerMap[$crChainId] . $crHash : '';
                                $crAddr = (string)($cr['from_address'] ?? '');
                                $crOrderNos = array_filter(explode(',', (string)($cr['order_nos'] ?? '')));
                            ?>
                            <tr data-address="<?php echo htmlspecialchars(strtolower($crAddr)); ?>"
                                data-email="<?php echo htmlspecialchars(strtolower((string)($cr['merchant_email'] ?? ''))); ?>"
                                data-hash="<?php echo htmlspecialchars(strtolower($crHash)); ?>"
                                data-chain="<?php echo htmlspecialchars(strtolower((string)($cr['chain_slug'] ?? ''))); ?>"
                                data-currency="<?php echo htmlspecialchars($crTok); ?>"
                                data-orders="<?php echo htmlspecialchars(strtolower((string)($cr['order_nos'] ?? ''))); ?>">
                                <td class="ps-3 small text-muted"><?php echo htmlspecialchars((string)($cr['collected_at'] ?? '-')); ?></td>
                                <td><span class="badge bg-dark" style="font-size:0.7rem;"><?php echo htmlspecialchars(strtoupper((string)($cr['chain_slug'] ?? ''))); ?></span></td>
                                <td class="small">#<?php echo (int)($cr['batch_id'] ?? 0); ?></td>
                                <td class="small text-muted"><?php echo htmlspecialchars((string)($cr['merchant_email'] ?? '-')); ?></td>
                                <td class="text-center">
                                    <span class="badge <?php echo $crTok==='USDC'?'bg-info':'bg-success'; ?>"><?php echo htmlspecialchars($crTok); ?></span>
                                </td>
                                <td class="text-end fw-bold text-success"><?php echo number_format((float)($cr['amount_display'] ?? 0), 4); ?></td>
                                <td class="small">
                                    <code><?php echo htmlspecialchars($crAddr !== '' ? substr($crAddr,0,8).'...'.substr($crAddr,-6) : '-'); ?></code>
                                </td>
                                <td class="small">
                                    <?php if (empty($crOrderNos)): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <div class="d-flex flex-wrap gap-1">
                                        <?php foreach (array_slice($crOrderNos, 0, 3) as $on): ?>
                                            <code class="text-primary" style="font-size:0.65rem;"><?php echo htmlspecialchars($on); ?></code>
                                        <?php endforeach; ?>
                                        <?php if (count($crOrderNos) > 3): ?><span class="text-muted small">+<?php echo count($crOrderNos)-3; ?></span><?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="pe-3 small">
                                    <?php if ($crHash !== ''): ?>
                                        <?php if ($crExplorer !== ''): ?>
                                            <a href="<?php echo htmlspecialchars($crExplorer); ?>" target="_blank" class="text-decoration-none font-monospace text-primary">
                                                <?php echo htmlspecialchars(substr($crHash,0,8).'...'.substr($crHash,-6)); ?> <i class="fas fa-external-link-alt" style="font-size:0.6rem;"></i>
                                            </a>
                                        <?php else: ?>
                                            <code><?php echo htmlspecialchars(substr($crHash,0,8).'...'.substr($crHash,-6)); ?></code>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-3 border-top d-flex flex-wrap align-items-center gap-2 justify-content-between bg-white" id="pg-ctrl-collectedTableBody"></div>
            </div>

        </div><!-- card-body -->
    </div><!-- card -->
</div>

<style>
.md-tab-pane { min-height: 300px; }
#mdTabs .nav-link { color: #6b7280; border-bottom: 2px solid transparent; border-radius: 0; padding: 0.6rem 1rem; }
#mdTabs .nav-link.active { color: #3b82f6; border-bottom-color: #3b82f6; background: transparent; font-weight: 600; }
#mdTabs .nav-link:hover:not(.active) { color: #374151; background: #f9fafb; }
.md-pagination { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.md-pagination .page-btn { padding: 2px 8px; font-size: 0.75rem; border: 1px solid #dee2e6; border-radius: 4px; background: #fff; cursor: pointer; }
.md-pagination .page-btn.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
.md-pagination .page-btn:disabled { opacity: 0.45; cursor: default; }
</style>

<script>
// Tab switching
document.querySelectorAll('[data-md-tab]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('[data-md-tab]').forEach(function(b) { b.classList.remove('active'); });
        document.querySelectorAll('.md-tab-pane').forEach(function(p) { p.classList.add('d-none'); });
        btn.classList.add('active');
        var pane = document.getElementById('md-tab-' + btn.dataset.mdTab);
        if (pane) pane.classList.remove('d-none');
    });
});

// ─── Pagination State ───────────────────────────────────────────────────────
var mdPagination = {};

function initPagination(tbodyId, pageSize) {
    mdPagination[tbodyId] = { page: 1, pageSize: pageSize || 10 };
}

function getFilteredRows(tbodyId) {
    var tbody = document.getElementById(tbodyId);
    if (!tbody) return [];
    return Array.from(tbody.querySelectorAll('tr')).filter(function(r) {
        return r.style.display !== 'none' || r.getAttribute('data-paged') === null;
    });
}

function applyPagination(tbodyId, countId, allVisibleRows) {
    var state = mdPagination[tbodyId];
    if (!state) return;
    var pageSize = state.pageSize;
    var total = allVisibleRows.length;
    var totalPages = Math.max(1, Math.ceil(total / pageSize));
    if (state.page > totalPages) state.page = totalPages;
    var start = (state.page - 1) * pageSize;
    var end = start + pageSize;

    // Show/hide based on page
    allVisibleRows.forEach(function(row, i) {
        row.style.display = (i >= start && i < end) ? '' : 'none';
    });

    // Update count label
    var countEl = document.getElementById(countId);
    if (countEl) {
        var showing = Math.min(end, total) - start;
        countEl.textContent = '共 ' + total + ' 条，第 ' + state.page + '/' + totalPages + ' 页';
    }

    // Render pagination controls
    renderPaginationControls(tbodyId, total, totalPages, state.page, pageSize);
}

function renderPaginationControls(tbodyId, total, totalPages, currentPage, pageSize) {
    var ctrlId = 'pg-ctrl-' + tbodyId;
    var ctrlEl = document.getElementById(ctrlId);
    if (!ctrlEl) return;

    var html = '';
    // Page size selector
    html += '<select class="form-select form-select-sm" style="width:auto;" onchange="changePageSize(\'' + tbodyId + '\', this.value)">';
    [10, 50, 100].forEach(function(s) {
        html += '<option value="' + s + '"' + (pageSize === s ? ' selected' : '') + '>' + s + ' 条/页</option>';
    });
    html += '</select>';

    html += '<div class="md-pagination">';
    html += '<button class="page-btn" onclick="goPage(\'' + tbodyId + '\', 1)"' + (currentPage <= 1 ? ' disabled' : '') + '>«</button>';
    html += '<button class="page-btn" onclick="goPage(\'' + tbodyId + '\', ' + (currentPage - 1) + ')"' + (currentPage <= 1 ? ' disabled' : '') + '>‹</button>';

    // Show up to 5 page buttons around current
    var start = Math.max(1, currentPage - 2);
    var end = Math.min(totalPages, start + 4);
    if (end - start < 4) start = Math.max(1, end - 4);
    for (var p = start; p <= end; p++) {
        html += '<button class="page-btn' + (p === currentPage ? ' active' : '') + '" onclick="goPage(\'' + tbodyId + '\', ' + p + ')">' + p + '</button>';
    }

    html += '<button class="page-btn" onclick="goPage(\'' + tbodyId + '\', ' + (currentPage + 1) + ')"' + (currentPage >= totalPages ? ' disabled' : '') + '>›</button>';
    html += '<button class="page-btn" onclick="goPage(\'' + tbodyId + '\', ' + totalPages + ')"' + (currentPage >= totalPages ? ' disabled' : '') + '>»</button>';
    html += '</div>';

    ctrlEl.innerHTML = html;
}

function goPage(tbodyId, page) {
    if (!mdPagination[tbodyId]) return;
    mdPagination[tbodyId].page = page;
    refreshTable(tbodyId);
}

function changePageSize(tbodyId, size) {
    if (!mdPagination[tbodyId]) return;
    mdPagination[tbodyId].pageSize = parseInt(size);
    mdPagination[tbodyId].page = 1;
    refreshTable(tbodyId);
}

// Each table has its own "all rows" cache so we can re-paginate without re-filtering
var mdAllRows = {};

function setAllRows(tbodyId, rows) {
    mdAllRows[tbodyId] = rows;
}

function refreshTable(tbodyId) {
    var cfg = mdTableConfigs[tbodyId];
    if (!cfg) return;
    cfg.filter();
}

// ─── Generic filter + paginate helper ────────────────────────────────────────
function filterAndPage(tbodyId, countId, rowFilter) {
    var tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    var allRows = Array.from(tbody.querySelectorAll('tr[data-paged]'));

    // First pass: apply filter (mark hidden/visible without pagination)
    var visibleRows = [];
    allRows.forEach(function(row) {
        var show = rowFilter(row);
        if (show) visibleRows.push(row);
        else row.style.display = 'none';
    });

    applyPagination(tbodyId, countId, visibleRows);
}

// ─── Table configs ────────────────────────────────────────────────────────────
var mdTableConfigs = {};

function registerTable(tbodyId, countId, filterFn) {
    mdTableConfigs[tbodyId] = {
        countId: countId,
        filter: function() { filterAndPage(tbodyId, countId, filterFn); }
    };
    initPagination(tbodyId, 10);
}

// Mark all data rows with data-paged attribute (so we can distinguish data rows from empty/placeholder rows)
function markDataRows(tbodyId) {
    var tbody = document.getElementById(tbodyId);
    if (!tbody) return;
    tbody.querySelectorAll('tr').forEach(function(r) {
        // Only mark rows that have actual data attributes (not empty-state rows)
        if (r.hasAttribute('data-email') || r.hasAttribute('data-chain') ||
            r.hasAttribute('data-address') || r.hasAttribute('data-order') ||
            r.hasAttribute('data-currency') || r.hasAttribute('data-orders')) {
            r.setAttribute('data-paged', '1');
        }
    });
}

// ─── Merchant tab ─────────────────────────────────────────────────────────────
markDataRows('merchantTableBody');
registerTable('merchantTableBody', 'merchantResultCount', function(row) {
    var q = (document.getElementById('merchantSearch')?.value || '').toLowerCase().trim();
    var chain = (document.getElementById('merchantChainFilter')?.value || '').toLowerCase().trim();
    var emailMatch = !q || (row.dataset.email || '').includes(q);
    var chainMatch = !chain || (row.dataset.chains || '').includes(chain);
    return emailMatch && chainMatch;
});
document.getElementById('merchantSearch')?.addEventListener('input', function() { mdPagination['merchantTableBody'].page=1; refreshTable('merchantTableBody'); });
document.getElementById('merchantChainFilter')?.addEventListener('change', function() { mdPagination['merchantTableBody'].page=1; refreshTable('merchantTableBody'); });

// ─── Chain tab ────────────────────────────────────────────────────────────────
markDataRows('chainTableBody');
registerTable('chainTableBody', 'chainResultCount', function(row) {
    var q = (document.getElementById('chainSearch')?.value || '').toLowerCase().trim();
    return !q || (row.dataset.chain || '').includes(q);
});
document.getElementById('chainSearch')?.addEventListener('input', function() { mdPagination['chainTableBody'].page=1; refreshTable('chainTableBody'); });

// ─── Pending wallets tab ──────────────────────────────────────────────────────
markDataRows('pendingTableBody');
registerTable('pendingTableBody', 'pendingResultCount', function(row) {
    var q = (document.getElementById('pendingSearch')?.value || '').toLowerCase().trim();
    var chain = (document.getElementById('pendingChainFilter')?.value || '').toLowerCase().trim();
    var token = (document.getElementById('pendingTokenFilter')?.value || '').toUpperCase().trim();
    var textMatch = !q || (row.dataset.address || '').includes(q) || (row.dataset.email || '').includes(q) || (row.dataset.orders || '').includes(q);
    var chainMatch = !chain || (row.dataset.chain || '') === chain;
    var tokenMatch = !token || (row.dataset.currencies || '').toUpperCase().includes(token);
    return textMatch && chainMatch && tokenMatch;
});
document.getElementById('pendingSearch')?.addEventListener('input', function() { mdPagination['pendingTableBody'].page=1; refreshTable('pendingTableBody'); });
document.getElementById('pendingChainFilter')?.addEventListener('change', function() { mdPagination['pendingTableBody'].page=1; refreshTable('pendingTableBody'); });
document.getElementById('pendingTokenFilter')?.addEventListener('change', function() { mdPagination['pendingTableBody'].page=1; refreshTable('pendingTableBody'); });

// ─── Orders tab ───────────────────────────────────────────────────────────────
markDataRows('orderTableBody');
registerTable('orderTableBody', 'orderResultCount', function(row) {
    var q = (document.getElementById('orderSearch')?.value || '').toLowerCase().trim();
    var chain = (document.getElementById('orderChainFilter')?.value || '').toLowerCase().trim();
    var token = (document.getElementById('orderTokenFilter')?.value || '').toUpperCase().trim();
    var textMatch = !q || (row.dataset.email || '').includes(q) || (row.dataset.order || '').includes(q) || (row.dataset.address || '').includes(q);
    var chainMatch = !chain || (row.dataset.chain || '') === chain;
    var tokenMatch = !token || (row.dataset.currency || '') === token;
    return textMatch && chainMatch && tokenMatch;
});
document.getElementById('orderSearch')?.addEventListener('input', function() { mdPagination['orderTableBody'].page=1; refreshTable('orderTableBody'); });
document.getElementById('orderChainFilter')?.addEventListener('change', function() { mdPagination['orderTableBody'].page=1; refreshTable('orderTableBody'); });
document.getElementById('orderTokenFilter')?.addEventListener('change', function() { mdPagination['orderTableBody'].page=1; refreshTable('orderTableBody'); });

// ─── Collected tab ────────────────────────────────────────────────────────────
markDataRows('collectedTableBody');
registerTable('collectedTableBody', 'collectedResultCount', function(row) {
    var q = (document.getElementById('collectedSearch')?.value || '').toLowerCase().trim();
    var chain = (document.getElementById('collectedChainFilter')?.value || '').toLowerCase().trim();
    var token = (document.getElementById('collectedTokenFilter')?.value || '').toUpperCase().trim();
    var textMatch = !q || (row.dataset.address || '').includes(q) || (row.dataset.email || '').includes(q) || (row.dataset.hash || '').includes(q) || (row.dataset.orders || '').includes(q);
    var chainMatch = !chain || (row.dataset.chain || '') === chain;
    var tokenMatch = !token || (row.dataset.currency || '') === token;
    return textMatch && chainMatch && tokenMatch;
});
document.getElementById('collectedSearch')?.addEventListener('input', function() { mdPagination['collectedTableBody'].page=1; refreshTable('collectedTableBody'); });
document.getElementById('collectedChainFilter')?.addEventListener('change', function() { mdPagination['collectedTableBody'].page=1; refreshTable('collectedTableBody'); });
document.getElementById('collectedTokenFilter')?.addEventListener('change', function() { mdPagination['collectedTableBody'].page=1; refreshTable('collectedTableBody'); });

// ─── Initial render ───────────────────────────────────────────────────────────
['merchantTableBody','chainTableBody','pendingTableBody','orderTableBody','collectedTableBody'].forEach(function(id) {
    refreshTable(id);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
