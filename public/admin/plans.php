<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
$db = Database::getInstance();
require_once __DIR__ . '/../../src/Core/Migrator.php';
require_once __DIR__ . '/../../src/Services/UpgradeOrderService.php';
$migrator = new Migrator($db->getConnection());
$migrator->run();

// Ensure legacy columns exist for plan/derived controls
try { $db->query("ALTER TABLE plans ADD COLUMN sync_interval INT DEFAULT 10"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE plans ADD COLUMN fast_sync_limit INT DEFAULT 0"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE chains ADD COLUMN allow_derived TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}
try {
    $db->query("CREATE TABLE IF NOT EXISTS plan_chain_derived (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        chain_id INT NOT NULL,
        enabled TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_plan_chain (plan_id, chain_id),
        KEY idx_plan (plan_id),
        KEY idx_chain (chain_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// Auto-fix DB tables silently
$silent = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clean_duplicates'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        header("Location: plans.php?msg=" . urlencode("CSRF validation failed"));
        exit;
    }
    // Keep the first ID for each name, delete others
    $db->query("DELETE p1 FROM plans p1 INNER JOIN plans p2 WHERE p1.id > p2.id AND p1.name = p2.name");
    header("Location: plans.php?msg=cleaned");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'force_repair_refunds_planpage') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        header("Location: plans.php?msg=" . urlencode("CSRF validation failed"));
        exit;
    }
    $orders = $db->fetchAll(
        "SELECT id, user_id
         FROM orders
         WHERE LOWER(chain) = 'binance_pay'
           AND (LOWER(source) = 'upgrade' OR order_no LIKE 'UPG%' OR merchant_order_id LIKE 'PLAN-%')
           AND (status = 'refunded' OR refund_status = 'full' OR COALESCE(refund_amount, 0) > 0)
         ORDER BY id DESC
         LIMIT 1000"
    );
    $fixed = 0;
    foreach ($orders as $it) {
        $uid = (int)($it['user_id'] ?? 0);
        $before = $uid > 0 ? $db->fetch("SELECT plan_id, expire_at FROM users WHERE id = ? LIMIT 1", [$uid]) : null;
        UpgradeOrderService::ensureUpgradeRollbackForRefund($db, (int)$it['id']);
        UpgradeOrderService::ensureRefundNotification($db, (int)$it['id']);
        $after = $uid > 0 ? $db->fetch("SELECT plan_id, expire_at FROM users WHERE id = ? LIMIT 1", [$uid]) : null;
        if ($before && $after && ((int)$before['plan_id'] !== (int)$after['plan_id'] || (string)($before['expire_at'] ?? '') !== (string)($after['expire_at'] ?? ''))) {
            $fixed++;
        }
    }
    header("Location: plans.php?msg=" . urlencode("回滚修复完成：扫描" . count($orders) . "条，修复" . $fixed . "条"));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manual_restore_plan_planpage') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        header("Location: plans.php?msg=" . urlencode("CSRF validation failed"));
        exit;
    }
    $merchantId = (int)($_POST['manual_user_id'] ?? 0);
    $planId = (int)($_POST['manual_plan_id'] ?? 0);
    $expireAtRaw = trim((string)($_POST['manual_expire_at'] ?? ''));
    if ($merchantId <= 0 || $planId <= 0) {
        header("Location: plans.php?msg=" . urlencode("请填写商户ID和目标套餐"));
        exit;
    }
    $plan = $db->fetch("SELECT id, name FROM plans WHERE id = ? LIMIT 1", [$planId]);
    if (!$plan) {
        header("Location: plans.php?msg=" . urlencode("目标套餐不存在"));
        exit;
    }
    $expireAt = null;
    if ($expireAtRaw !== '') {
        $ts = strtotime($expireAtRaw);
        if ($ts === false) {
            header("Location: plans.php?msg=" . urlencode("到期时间格式错误"));
            exit;
        }
        $expireAt = date('Y-m-d H:i:s', $ts);
    }
    $db->query("UPDATE users SET plan_id = ?, expire_at = ? WHERE id = ? LIMIT 1", [$planId, $expireAt, $merchantId]);
    header("Location: plans.php?msg=" . urlencode("手工回滚成功：商户#{$merchantId} -> {$plan['name']}"));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id']) && !isset($_POST['action'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        header("Location: plans.php?msg=" . urlencode("CSRF validation failed"));
        exit;
    }
    $id = (int)$_POST['id'];
    $price_m = (float)$_POST['price_monthly'];
    $price_q = (float)$_POST['price_quarterly'];
    $price_y = (float)$_POST['price_yearly'];
    $limit = (int)$_POST['api_limit_daily'];
    $desc = $_POST['description'] ?? '';
    
    // Fast Sync Settings & TG Bot
    $sync_interval = (int)$_POST['sync_interval'];
    $fast_sync_limit = (int)($_POST['fast_sync_limit'] ?? 0);
    $tg_notice_limit = (int)$_POST['tg_notice_limit'];
    $allow_tg_bot = isset($_POST['allow_tg_bot']) ? 1 : 0;
    $email_notice_limit = (int)($_POST['email_notice_limit'] ?? 0);
    $allow_email_notice = isset($_POST['allow_email_notice']) ? 1 : 0;
    $allow_webhook_notice = isset($_POST['allow_webhook_notice']) ? 1 : 0;
    $allow_derived_wallet = isset($_POST['allow_derived_wallet']) ? 1 : 0;
    
    // Check if column exists, if not, create it
    try { $db->query("ALTER TABLE plans ADD COLUMN description TEXT"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE plans ADD COLUMN allow_tg_bot TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE plans ADD COLUMN tg_notice_limit INT DEFAULT 0"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE plans ADD COLUMN allow_email_notice TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE plans ADD COLUMN email_notice_limit INT DEFAULT 0"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE plans ADD COLUMN allow_webhook_notice TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE plans ADD COLUMN allow_derived_wallet TINYINT(1) DEFAULT 0"); } catch (Exception $e) {}

    try {
        $db->query("UPDATE plans SET price_monthly=?, price_quarterly=?, price_yearly=?, api_limit_daily=?, description=?, sync_interval=?, fast_sync_limit=?, tg_notice_limit=?, allow_tg_bot=?, email_notice_limit=?, allow_email_notice=?, allow_webhook_notice=?, allow_derived_wallet=? WHERE id=?", 
            [$price_m, $price_q, $price_y, $limit, $desc, $sync_interval, $fast_sync_limit, $tg_notice_limit, $allow_tg_bot, $email_notice_limit, $allow_email_notice, $allow_webhook_notice, $allow_derived_wallet, $id]);
        
        // Update Plan Chains
        $db->query("DELETE FROM plan_chains WHERE plan_id = ?", [$id]);
        $selected_chain_ids = [];
        if (isset($_POST['chains']) && is_array($_POST['chains'])) {
            foreach ($_POST['chains'] as $chain_id) {
                $cid = (int)$chain_id;
                if ($cid <= 0) {
                    continue;
                }
                $selected_chain_ids[] = $cid;
                $db->query("INSERT INTO plan_chains (plan_id, chain_id) VALUES (?, ?)", [$id, $cid]);
            }
        }

        // Keep plan-derived mapping aligned with currently selected chains.
        if (empty($selected_chain_ids)) {
            $db->query("DELETE FROM plan_chain_derived WHERE plan_id = ?", [$id]);
        } else {
            $placeholders = implode(',', array_fill(0, count($selected_chain_ids), '?'));
            $params = array_merge([$id], $selected_chain_ids);
            $db->query("DELETE FROM plan_chain_derived WHERE plan_id = ? AND chain_id NOT IN ($placeholders)", $params);

            // Newly selected EVM chains default to enabled.
            $evmChains = $db->fetchAll("SELECT id FROM chains WHERE is_evm = 1 AND id IN ($placeholders)", $selected_chain_ids);
            foreach ($evmChains as $ec) {
                $db->query("INSERT INTO plan_chain_derived (plan_id, chain_id, enabled) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE plan_id = plan_id", [$id, (int)$ec['id']]);
            }
        }
        
        $message = "套餐更新成功！";
    } catch (Exception $e) {
        $message = "套餐更新失败: " . $e->getMessage();
    }
    
    // Redirect to self with message
    header("Location: plans.php?msg=" . urlencode($message));
    exit;
}

// Handle Chain Status Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_chain' && isset($_POST['id'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        header("Location: plans.php?msg=" . urlencode("CSRF validation failed"));
        exit;
    }
    $chain_id = (int)$_POST['id'];
    $chain = $db->fetch("SELECT status FROM chains WHERE id = ?", [$chain_id]);
    if ($chain) {
        $new_status = $chain['status'] == 1 ? 0 : 1;
        $db->query("UPDATE chains SET status = ? WHERE id = ?", [$new_status, $chain_id]);
        header("Location: plans.php?msg=Chain updated");
        exit;
    }
}

// Handle Derived Chain Support Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_chain_derived' && isset($_POST['id'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        header("Location: plans.php?msg=" . urlencode("CSRF validation failed"));
        exit;
    }
    $chain_id = (int)$_POST['id'];
    $chain = $db->fetch("SELECT is_evm, allow_derived FROM chains WHERE id = ?", [$chain_id]);
    if ($chain) {
        if ((int)($chain['is_evm'] ?? 0) !== 1) {
            header("Location: plans.php?msg=" . urlencode("仅 EVM 网络支持派生切换"));
            exit;
        }
        $new_status = ((int)($chain['allow_derived'] ?? 1) === 1) ? 0 : 1;
        $db->query("UPDATE chains SET allow_derived = ? WHERE id = ?", [$new_status, $chain_id]);
        header("Location: plans.php?msg=Derived chain updated");
        exit;
    }
}

// Handle Plan-Bound Derived Chain Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_plan_chain_derived' && isset($_POST['id']) && isset($_POST['plan_id'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        header("Location: plans.php?msg=" . urlencode("CSRF validation failed"));
        exit;
    }
    $chain_id = (int)$_POST['id'];
    $plan_id = (int)$_POST['plan_id'];

    $chain = $db->fetch("SELECT id, is_evm, status, COALESCE(allow_derived, 1) AS allow_derived FROM chains WHERE id = ?", [$chain_id]);
    if (!$chain) {
        header("Location: plans.php?msg=" . urlencode("链不存在"));
        exit;
    }
    if ((int)$chain['status'] !== 1) {
        header("Location: plans.php?msg=" . urlencode("该链未启用全网状态"));
        exit;
    }
    if ((int)$chain['is_evm'] !== 1) {
        header("Location: plans.php?msg=" . urlencode("仅 EVM 网络支持派生切换"));
        exit;
    }
    if ((int)$chain['allow_derived'] !== 1) {
        header("Location: plans.php?msg=" . urlencode("该链未开启全网派生支持"));
        exit;
    }

    $inPlan = $db->fetch("SELECT id FROM plan_chains WHERE plan_id = ? AND chain_id = ? LIMIT 1", [$plan_id, $chain_id]);
    if (!$inPlan) {
        header("Location: plans.php?msg=" . urlencode("该链未加入当前套餐"));
        exit;
    }

    $row = $db->fetch("SELECT enabled FROM plan_chain_derived WHERE plan_id = ? AND chain_id = ? LIMIT 1", [$plan_id, $chain_id]);
    $new_status = 1;
    if ($row) {
        $new_status = ((int)$row['enabled'] === 1) ? 0 : 1;
        $db->query("UPDATE plan_chain_derived SET enabled = ? WHERE plan_id = ? AND chain_id = ?", [$new_status, $plan_id, $chain_id]);
    } else {
        $db->query("INSERT INTO plan_chain_derived (plan_id, chain_id, enabled) VALUES (?, ?, 0)", [$plan_id, $chain_id]);
        $new_status = 0;
    }
    header("Location: plans.php?msg=Plan derived chain updated&plan=" . $plan_id);
    exit;
}

// Ensure description column exists for display
try { $db->query("ALTER TABLE plans ADD COLUMN description TEXT"); } catch (Exception $e) {}

$plans = $db->fetchAll("SELECT * FROM plans ORDER BY id ASC");
$all_chains = [];
try {
    $all_chains = $db->fetchAll("SELECT *, COALESCE(allow_derived, 1) AS allow_derived FROM chains ORDER BY is_evm DESC, name ASC");
} catch (Exception $e) {}

// Fetch plan chains mapping
$plan_chains = [];
try {
    $pc_rows = $db->fetchAll("SELECT * FROM plan_chains");
    foreach ($pc_rows as $r) {
        $plan_chains[$r['plan_id']][] = $r['chain_id'];
    }
} catch (Exception $e) {
    // If plan_chains table missing, create it
    $db->query("CREATE TABLE IF NOT EXISTS plan_chains (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        chain_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `plan_chain` (`plan_id`, `chain_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $plan_chains = [];
}

$plan_chain_derived = [];
try {
    $pcd_rows = $db->fetchAll("SELECT plan_id, chain_id, enabled FROM plan_chain_derived");
    foreach ($pcd_rows as $r) {
        $pid = (int)$r['plan_id'];
        $cid = (int)$r['chain_id'];
        $plan_chain_derived[$pid][$cid] = (int)($r['enabled'] ?? 1);
    }
} catch (Exception $e) {
    $plan_chain_derived = [];
}

$active_menu = 'plans';
require_once 'includes/header.php';
$active_plan_id = isset($_GET['plan']) ? (int)$_GET['plan'] : 0;
if ($active_plan_id <= 0 && !empty($plans)) {
    $active_plan_id = (int)$plans[0]['id'];
}
?>
<!-- Inject Tailwind -->
<script src="https://cdn.tailwindcss.com"></script>
<script>
  tailwind.config = {
    prefix: 'tw-',
    darkMode: ['class', '[data-bs-theme="dark"]'],
    theme: {
      extend: {
        colors: {
          primary: '#3b82f6',
          success: '#10b981',
          warning: '#f59e0b',
          danger: '#ef4444',
          dark: '#1f2937'
        }
      }
    }
  }
</script>
<style>
    /* Custom Scrollbar */
    .tw-scrollbar-thin::-webkit-scrollbar { width: 6px; height: 6px; }
    .tw-scrollbar-thin::-webkit-scrollbar-track { background: transparent; }
    .tw-scrollbar-thin::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
    .tw-scrollbar-thin::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    /* Hide number input arrows */
    input[type=number]::-webkit-inner-spin-button, 
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
</style>

<div class="tw-font-sans tw-text-gray-800 tw-antialiased tw-min-h-screen tw-bg-gray-50 dark:tw-bg-gray-900 dark:tw-text-gray-100 tw-p-4 md:tw-p-6">

    <!-- Header -->
    <div class="tw-flex tw-flex-col md:tw-flex-row tw-justify-between tw-items-center tw-mb-8 tw-gap-4">
        <div>
            <h1 class="tw-text-2xl tw-font-bold tw-tracking-tight tw-text-gray-900 dark:tw-text-white">套餐与网络管理</h1>
            <p class="tw-text-sm tw-text-gray-500 dark:tw-text-gray-400">配置订阅计划与区块链网络可用性</p>
        </div>
        
        <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-info alert-dismissible fade show tw-text-sm tw-py-2 tw-px-3 tw-mb-0" role="alert">
            <?php
            if($_GET['msg']=='cleaned') echo "重复套餐已清理。";
            elseif($_GET['msg']=='Chain updated') echo "区块链状态已更新。";
            elseif($_GET['msg']=='Derived chain updated') echo "全网派生网络支持已更新。";
            elseif($_GET['msg']=='Plan derived chain updated') echo "当前套餐派生网络支持已更新。";
            else echo htmlspecialchars($_GET['msg']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <form method="POST" onsubmit="return confirm('确定要清理重复套餐吗？');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
            <input type="hidden" name="clean_duplicates" value="1">
            <button type="submit" class="tw-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2 tw-bg-white dark:tw-bg-gray-800 tw-border tw-border-gray-300 dark:tw-border-gray-700 tw-text-gray-700 dark:tw-text-gray-300 tw-rounded-lg tw-text-sm hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700 tw-transition-colors">
                <svg class="tw-w-4 tw-h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                清理重复数据
            </button>
        </form>
    </div>

    <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-p-4 tw-mb-6">
        <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-2 tw-gap-4">
            <form method="POST" class="tw-space-y-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                <input type="hidden" name="action" value="force_repair_refunds_planpage">
                <div class="tw-font-semibold tw-text-gray-900 dark:tw-text-white">退款套餐回滚修复</div>
                <div class="tw-text-xs tw-text-gray-500">扫描 Binance Pay 已退款升级订单，自动回滚套餐并补发站内通知。</div>
                <button type="submit" class="tw-px-4 tw-py-2 tw-rounded-lg tw-bg-amber-400 hover:tw-bg-amber-500 tw-text-gray-900 tw-font-semibold tw-text-sm">一键强制修复</button>
            </form>
            <form method="POST" class="tw-grid tw-grid-cols-1 md:tw-grid-cols-4 tw-gap-2 tw-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                <input type="hidden" name="action" value="manual_restore_plan_planpage">
                <div>
                    <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">商户ID</label>
                    <input class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm" name="manual_user_id" required>
                </div>
                <div>
                    <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">目标套餐</label>
                    <select class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm" name="manual_plan_id" required>
                        <option value="">选择套餐</option>
                        <?php foreach ($plans as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars('#' . (int)$p['id'] . ' ' . (string)$p['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">到期时间</label>
                    <input class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm" name="manual_expire_at" placeholder="2026-12-31 23:59:59">
                </div>
                <button type="submit" class="tw-px-3 tw-py-2 tw-rounded-lg tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-text-sm">手工回滚</button>
            </form>
        </div>
    </div>

    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-8">
        
        <!-- Left: Plans Management (8 Cols) -->
        <div class="lg:tw-col-span-8 tw-space-y-6">
            
            <!-- Custom Tabs Navigation -->
            <div class="tw-flex tw-space-x-1 tw-bg-gray-200 dark:tw-bg-gray-800 tw-p-1 tw-rounded-xl tw-overflow-x-auto">
                <?php foreach ($plans as $index => $p): ?>
                <?php $is_tab_active = ((int)$p['id'] === (int)$active_plan_id); ?>
                <button onclick="switchTab('plan-<?php echo $p['id']; ?>')" id="tab-plan-<?php echo $p['id']; ?>" class="plan-tab-btn tw-flex-1 tw-px-4 tw-py-2.5 tw-text-sm tw-font-medium tw-rounded-lg tw-transition-all tw-whitespace-nowrap <?php echo $is_tab_active ? 'tw-bg-white dark:tw-bg-gray-700 tw-text-gray-900 dark:tw-text-white tw-shadow-sm' : 'tw-text-gray-500 hover:tw-text-gray-700 dark:hover:tw-text-gray-300'; ?>">
                    <?php echo htmlspecialchars($p['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>

            <!-- Tab Contents -->
            <?php foreach ($plans as $index => $p): ?>
            <div id="plan-<?php echo $p['id']; ?>" class="plan-content <?php echo ((int)$p['id'] === (int)$active_plan_id) ? '' : 'tw-hidden'; ?>">
                <form method="POST" class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-overflow-hidden">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                    <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">

                    <!-- Pricing Section -->
                    <div class="tw-p-6 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <h3 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4 tw-flex tw-items-center tw-gap-2">
                            <svg class="tw-w-4 tw-h-4 tw-text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            定价策略 ($)
                        </h3>
                        <div class="tw-grid tw-grid-cols-3 tw-gap-4">
                            <div>
                                <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">月付</label>
                                <input type="number" step="0.01" name="price_monthly" value="<?php echo $p['price_monthly']; ?>" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm focus:tw-ring-primary focus:tw-border-primary">
                            </div>
                            <div>
                                <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">季付</label>
                                <input type="number" step="0.01" name="price_quarterly" value="<?php echo $p['price_quarterly']; ?>" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm focus:tw-ring-primary focus:tw-border-primary">
                            </div>
                            <div>
                                <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">年付</label>
                                <input type="number" step="0.01" name="price_yearly" value="<?php echo $p['price_yearly']; ?>" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm focus:tw-ring-primary focus:tw-border-primary">
                            </div>
                        </div>
                    </div>

                    <!-- Quotas & Limits -->
                    <div class="tw-p-6 tw-border-b tw-border-gray-100 dark:tw-border-gray-700 tw-bg-gray-50/50 dark:tw-bg-gray-800/50">
                        <h3 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4 tw-flex tw-items-center tw-gap-2">
                            <svg class="tw-w-4 tw-h-4 tw-text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            配额限制
                        </h3>
                        <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-5 tw-gap-4">
                            <div>
                                <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">每日 API 请求</label>
                                <input type="number" name="api_limit_daily" value="<?php echo (int)$p['api_limit_daily']; ?>" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                            </div>
                            <div>
                                <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">监听间隔 (秒)</label>
                                <input type="number" name="sync_interval" value="<?php echo (int)($p['sync_interval'] ?? 10); ?>" min="3" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                            </div>
                            <div>
                                <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">极速监听条数/月</label>
                                <input type="number" name="fast_sync_limit" value="<?php echo (int)($p['fast_sync_limit'] ?? 0); ?>" min="0" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                            </div>
                            <div>
                                <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">TG 通知 (条/月)</label>
                                <input type="number" name="tg_notice_limit" value="<?php echo (int)($p['tg_notice_limit'] ?? 0); ?>" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                            </div>
                            <div>
                                <label class="tw-block tw-text-xs tw-text-gray-500 tw-mb-1">邮件通知 (封/月)</label>
                                <input type="number" name="email_notice_limit" value="<?php echo (int)($p['email_notice_limit'] ?? 0); ?>" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Feature Toggles -->
                    <div class="tw-p-6 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <h3 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-mb-4 tw-flex tw-items-center tw-gap-2">
                            <svg class="tw-w-4 tw-h-4 tw-text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            功能开关
                        </h3>
                        <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-4">
                            <label class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-bg-gray-50 dark:tw-bg-gray-700/50 tw-rounded-lg tw-cursor-pointer hover:tw-bg-gray-100 dark:hover:tw-bg-gray-700">
                                <span class="tw-text-sm tw-font-medium">TG 机器人</span>
                                <input type="checkbox" name="allow_tg_bot" class="tw-w-4 tw-h-4 tw-text-primary tw-rounded focus:tw-ring-primary" <?php echo !empty($p['allow_tg_bot']) ? 'checked' : ''; ?>>
                            </label>
                            <label class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-bg-gray-50 dark:tw-bg-gray-700/50 tw-rounded-lg tw-cursor-pointer hover:tw-bg-gray-100 dark:hover:tw-bg-gray-700">
                                <span class="tw-text-sm tw-font-medium">邮件通知</span>
                                <input type="checkbox" name="allow_email_notice" class="tw-w-4 tw-h-4 tw-text-primary tw-rounded focus:tw-ring-primary" <?php echo !empty($p['allow_email_notice']) ? 'checked' : ''; ?>>
                            </label>
                            <label class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-bg-gray-50 dark:tw-bg-gray-700/50 tw-rounded-lg tw-cursor-pointer hover:tw-bg-gray-100 dark:hover:tw-bg-gray-700">
                                <span class="tw-text-sm tw-font-medium">Webhook</span>
                                <input type="checkbox" name="allow_webhook_notice" class="tw-w-4 tw-h-4 tw-text-primary tw-rounded focus:tw-ring-primary" <?php echo (!isset($p['allow_webhook_notice']) || $p['allow_webhook_notice']) ? 'checked' : ''; ?>>
                            </label>
                            <label class="tw-flex tw-items-center tw-justify-between tw-p-3 tw-bg-gray-50 dark:tw-bg-gray-700/50 tw-rounded-lg tw-cursor-pointer hover:tw-bg-gray-100 dark:hover:tw-bg-gray-700">
                                <span class="tw-text-sm tw-font-medium">派生钱包</span>
                                <input type="checkbox" name="allow_derived_wallet" class="tw-w-4 tw-h-4 tw-text-primary tw-rounded focus:tw-ring-primary" <?php echo !empty($p['allow_derived_wallet']) ? 'checked' : ''; ?>>
                            </label>
                        </div>
                    </div>

                    <!-- Supported Chains -->
                    <div class="tw-p-6 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                         <div class="tw-flex tw-justify-between tw-items-center tw-mb-4">
                            <h3 class="tw-text-sm tw-font-bold tw-text-gray-900 dark:tw-text-white tw-uppercase tw-tracking-wider tw-flex tw-items-center tw-gap-2">
                                <svg class="tw-w-4 tw-h-4 tw-text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.384-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                                支持网络
                            </h3>
                            <div class="tw-flex tw-gap-2">
                                <button type="button" onclick="toggleAllChains(<?php echo $p['id']; ?>, true)" class="tw-text-xs tw-px-2 tw-py-1 tw-bg-gray-100 dark:tw-bg-gray-700 tw-rounded hover:tw-bg-gray-200">全选</button>
                                <button type="button" onclick="toggleAllChains(<?php echo $p['id']; ?>, false)" class="tw-text-xs tw-px-2 tw-py-1 tw-bg-gray-100 dark:tw-bg-gray-700 tw-rounded hover:tw-bg-gray-200">清空</button>
                            </div>
                        </div>
                        
                        <div class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-rounded-lg tw-p-4 tw-max-h-80 tw-overflow-y-auto tw-scrollbar-thin">
                            <?php 
                            $current_chains = $plan_chains[$p['id']] ?? [];
                            $grouped_chains = ['EVM' => [], 'Solana' => [], 'Tron' => [], 'Others' => []];
                            foreach($all_chains as $c) {
                                if($c['status'] != 1) continue;
                                if ($c['slug'] === 'solana') $grouped_chains['Solana'][] = $c;
                                elseif ($c['slug'] === 'trc20') $grouped_chains['Tron'][] = $c;
                                elseif ($c['is_evm']) $grouped_chains['EVM'][] = $c;
                                else $grouped_chains['Others'][] = $c;
                            }
                            ?>
                            
                            <?php foreach($grouped_chains as $type => $group): if(empty($group)) continue; ?>
                            <div class="tw-mb-3">
                                <div class="tw-text-xs tw-font-bold tw-text-gray-400 tw-uppercase tw-mb-2"><?php echo $type; ?></div>
                                <div class="tw-grid tw-grid-cols-2 md:tw-grid-cols-4 tw-gap-2">
                                    <?php foreach ($group as $c): ?>
                                    <label class="tw-flex tw-items-center tw-p-2 tw-bg-white dark:tw-bg-gray-800 tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-rounded tw-cursor-pointer hover:tw-border-primary dark:hover:tw-border-primary tw-transition-colors">
                                        <input type="checkbox" name="chains[]" value="<?php echo $c['id']; ?>" class="chain-check-<?php echo $p['id']; ?> tw-w-4 tw-h-4 tw-text-primary tw-rounded focus:tw-ring-primary" <?php echo in_array($c['id'], $current_chains) ? 'checked' : ''; ?>>
                                        <span class="tw-ml-2 tw-text-xs tw-font-medium tw-truncate"><?php echo htmlspecialchars($c['name']); ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Description -->
                    <div class="tw-p-6 tw-bg-gray-50/50 dark:tw-bg-gray-800/50">
                        <label class="tw-block tw-text-xs tw-font-bold tw-text-gray-500 tw-mb-2">功能描述 (每行一条)</label>
                        <textarea name="description" rows="3" class="tw-w-full tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm" placeholder="支持 20+ 条链..."><?php echo htmlspecialchars($p['description'] ?? ''); ?></textarea>
                    </div>

                    <!-- Action -->
                    <div class="tw-px-6 tw-py-4 tw-bg-gray-50 dark:tw-bg-gray-900 tw-border-t tw-border-gray-200 dark:tw-border-gray-700 tw-flex tw-justify-end">
                        <button type="submit" class="tw-px-6 tw-py-2 tw-bg-primary hover:tw-bg-blue-600 tw-text-white tw-font-medium tw-rounded-lg tw-shadow-sm tw-transition-colors">
                            保存 <?php echo htmlspecialchars($p['name']); ?> 配置
                        </button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Right: Global Chain Management (4 Cols) -->
        <div class="lg:tw-col-span-4">
            <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-overflow-hidden tw-sticky tw-top-6">
                <div class="tw-p-4 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                    <h3 class="tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mb-2">全网链开关（派生按套餐）</h3>
                    <div id="activePlanHint" class="tw-text-xs tw-text-gray-500 tw-mb-2">
                        当前套餐：<?php foreach($plans as $pp){ if((int)$pp['id']===(int)$active_plan_id){ echo htmlspecialchars($pp['name']); break; } } ?>
                    </div>
                    <div class="tw-relative">
                        <input type="text" id="chainSearch" placeholder="搜索链名称..." class="tw-w-full tw-pl-9 tw-pr-4 tw-py-2 tw-rounded-lg tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                        <svg class="tw-absolute tw-left-3 tw-top-2.5 tw-w-4 tw-h-4 tw-text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                </div>
                
                <div class="tw-max-h-[calc(100vh-300px)] tw-overflow-y-auto tw-scrollbar-thin">
                    <?php foreach($plans as $panelPlan): ?>
                    <?php $panelPid = (int)$panelPlan['id']; ?>
                    <?php $panelChains = $plan_chains[$panelPid] ?? []; ?>
                    <div id="chainsList-<?php echo $panelPid; ?>" class="chains-list-panel tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700 <?php echo $panelPid === (int)$active_plan_id ? '' : 'tw-hidden'; ?>">
                        <?php foreach($all_chains as $c): ?>
                        <?php
                            $chainId = (int)$c['id'];
                            $inPlan = in_array($chainId, $panelChains, true);
                            $planDerivedEnabled = (int)($plan_chain_derived[$panelPid][$chainId] ?? 1) === 1;
                            $canPlanDerivedToggle = ((int)($c['is_evm'] ?? 0) === 1) && ((int)($c['status'] ?? 0) === 1) && ((int)($c['allow_derived'] ?? 1) === 1) && $inPlan;
                        ?>
                        <div class="chain-item tw-flex tw-items-center tw-justify-between tw-p-4 hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50">
                            <div class="tw-flex-1 tw-min-w-0 tw-mr-3">
                                <div class="tw-flex tw-items-center tw-gap-2">
                                    <span class="chain-name tw-text-sm tw-font-medium tw-text-gray-900 dark:tw-text-white tw-truncate"><?php echo htmlspecialchars($c['name']); ?></span>
                                    <?php if($c['is_evm']): ?>
                                        <span class="tw-text-[10px] tw-bg-blue-100 tw-text-blue-800 tw-px-1.5 tw-rounded">EVM</span>
                                    <?php elseif($c['slug'] === 'solana'): ?>
                                        <span class="tw-text-[10px] tw-bg-purple-100 tw-text-purple-800 tw-px-1.5 tw-rounded">SOL</span>
                                    <?php elseif($c['slug'] === 'trc20'): ?>
                                        <span class="tw-text-[10px] tw-bg-red-100 tw-text-red-800 tw-px-1.5 tw-rounded">TRX</span>
                                    <?php endif; ?>
                                </div>
                                <div class="tw-text-xs tw-text-gray-500 tw-font-mono"><?php echo htmlspecialchars($c['slug']); ?></div>
                            </div>

                            <div class="tw-flex tw-items-center tw-gap-3">
                                <div class="tw-flex tw-items-center tw-gap-1.5">
                                    <span class="tw-text-[10px] tw-text-gray-400">全网</span>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                                        <input type="hidden" name="action" value="toggle_chain">
                                        <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                        <button type="submit" class="tw-relative tw-inline-flex tw-h-6 tw-w-11 tw-items-center tw-rounded-full tw-transition-colors focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-primary focus:tw-ring-offset-2 <?php echo $c['status'] ? 'tw-bg-success' : 'tw-bg-gray-200 dark:tw-bg-gray-600'; ?>">
                                            <span class="tw-inline-block tw-h-4 tw-w-4 tw-transform tw-rounded-full tw-bg-white tw-transition-transform <?php echo $c['status'] ? 'tw-translate-x-6' : 'tw-translate-x-1'; ?>"></span>
                                        </button>
                                    </form>
                                </div>

                                <div class="tw-flex tw-items-center tw-gap-1.5">
                                    <span class="tw-text-[10px] tw-text-gray-400">套餐派生</span>
                                    <?php if ((int)($c['is_evm'] ?? 0) !== 1): ?>
                                        <span class="tw-text-[10px] tw-text-gray-300">N/A</span>
                                    <?php elseif (!$inPlan): ?>
                                        <span class="tw-text-[10px] tw-text-gray-300">未加入套餐</span>
                                    <?php elseif ((int)($c['allow_derived'] ?? 1) !== 1): ?>
                                        <span class="tw-text-[10px] tw-text-gray-300">全网派生未开</span>
                                    <?php else: ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                                        <input type="hidden" name="action" value="toggle_plan_chain_derived">
                                        <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                        <input type="hidden" name="plan_id" value="<?php echo $panelPid; ?>">
                                        <button type="submit" class="tw-relative tw-inline-flex tw-h-6 tw-w-11 tw-items-center tw-rounded-full tw-transition-colors focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-primary focus:tw-ring-offset-2 <?php echo $planDerivedEnabled ? 'tw-bg-primary' : 'tw-bg-gray-200 dark:tw-bg-gray-600'; ?>" <?php echo $canPlanDerivedToggle ? '' : 'disabled'; ?>>
                                            <span class="tw-inline-block tw-h-4 tw-w-4 tw-transform tw-rounded-full tw-bg-white tw-transition-transform <?php echo $planDerivedEnabled ? 'tw-translate-x-6' : 'tw-translate-x-1'; ?>"></span>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
// Tab Switching Logic
function switchTab(targetId) {
    // Hide all contents
    document.querySelectorAll('.plan-content').forEach(el => el.classList.add('tw-hidden'));
    // Show target
    document.getElementById(targetId).classList.remove('tw-hidden');
    
    // Update button states
    document.querySelectorAll('.plan-tab-btn').forEach(btn => {
        btn.classList.remove('tw-bg-white', 'dark:tw-bg-gray-700', 'tw-text-gray-900', 'dark:tw-text-white', 'tw-shadow-sm');
        btn.classList.add('tw-text-gray-500');
    });
    
    // Activate target button
    const activeBtn = document.getElementById('tab-' + targetId);
    activeBtn.classList.remove('tw-text-gray-500');
    activeBtn.classList.add('tw-bg-white', 'dark:tw-bg-gray-700', 'tw-text-gray-900', 'dark:tw-text-white', 'tw-shadow-sm');

    const pid = String(targetId).replace('plan-', '');
    document.querySelectorAll('.chains-list-panel').forEach(el => el.classList.add('tw-hidden'));
    const panel = document.getElementById('chainsList-' + pid);
    if (panel) panel.classList.remove('tw-hidden');
    const hint = document.getElementById('activePlanHint');
    if (hint && activeBtn) {
        hint.textContent = '当前套餐：' + activeBtn.textContent.trim();
    }
}

// Chain Search
document.getElementById('chainSearch').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let rows = document.querySelectorAll('.chain-item');
    rows.forEach(row => {
        let name = row.querySelector('.chain-name').textContent.toLowerCase();
        row.style.display = name.includes(filter) ? '' : 'none';
    });
});

// Select All / Deselect All
function toggleAllChains(planId, checked) {
    document.querySelectorAll('.chain-check-' + planId).forEach(el => el.checked = checked);
}
</script>

<?php require_once 'includes/footer.php'; ?>
