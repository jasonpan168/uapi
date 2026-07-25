<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();

require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Core/I18n.php';
I18n::init();
$httpHelpers = __DIR__ . '/../../src/Core/Http.php';
if (file_exists($httpHelpers)) { require_once $httpHelpers; }
$db = Database::getInstance();
// Auto-migrate database on page load
$db->autoMigrate();

// Check if table exists manually to avoid 500 error
try {
    $db->fetch("SELECT 1 FROM admin_coupons LIMIT 1");
} catch (Exception $e) {
    echo '<div style="padding: 20px; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; border-radius: 5px; margin: 20px;">
        <strong>' . htmlspecialchars(__('admin.marketing.error.db_missing_title')) . ' (Database Error)</strong><br>
        ' . htmlspecialchars(__('admin.marketing.error.db_missing_desc')) . '<br>
        <a href="/fix_db_manual.php" target="_blank" style="color: #0056b3; font-weight: bold; text-decoration: underline;">' . htmlspecialchars(__('admin.marketing.error.db_missing_action')) . '</a>
    </div>';
    exit;
}

$flashes = function_exists('flash_consume_all') ? flash_consume_all() : [];

$active_menu = 'marketing';

function generate_admin_coupon_code(string $prefix = 'UPG', int $randLen = 8): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $rand = '';
    for ($i = 0; $i < $randLen; $i++) {
        $rand .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $cleanPrefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', $prefix));
    if ($cleanPrefix === '') {
        $cleanPrefix = 'UPG';
    }
    return $cleanPrefix . '-' . $rand;
}

// Handle Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        if (function_exists('flash_add')) flash_add('error', __('admin.marketing.flash.csrf'));
        if (function_exists('redirect_303')) { redirect_303('marketing.php'); } else { header("Location: marketing.php", true, 303); exit; }
    }
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $prefix = strtoupper(trim($_POST['code_prefix'] ?? 'UPG'));
    $quantity = max(1, min(500, (int)($_POST['quantity'] ?? 1)));
    $type = $_POST['type'];
    $value = floatval($_POST['value']);
    $limit = intval($_POST['usage_limit']);
    $expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $autoGenerate = isset($_POST['auto_generate']) ? (int)$_POST['auto_generate'] === 1 : false;
    
    if ($value <= 0) {
        if (function_exists('flash_add')) flash_add('error', __('admin.marketing.flash.invalid_input'));
        if (function_exists('redirect_303')) { redirect_303('marketing.php'); } else { header("Location: marketing.php", true, 303); exit; }
    } else {
        try {
            $codes = [];
            if (!$autoGenerate && $quantity === 1) {
                if ($code === '') {
                    throw new Exception(__('admin.marketing.flash.code_required'));
                }
                $codes[] = $code;
            } else {
                $attempt = 0;
                while (count($codes) < $quantity && $attempt < ($quantity * 30)) {
                    $candidate = generate_admin_coupon_code($prefix, 8);
                    if (!in_array($candidate, $codes, true)) {
                        $exists = $db->fetch("SELECT id FROM admin_coupons WHERE code = ? LIMIT 1", [$candidate]);
                        if (!$exists) {
                            $codes[] = $candidate;
                        }
                    }
                    $attempt++;
                }
                if (count($codes) < $quantity) {
                    throw new Exception(__('admin.marketing.flash.auto_generate_failed'));
                }
            }

            $created = 0;
            foreach ($codes as $newCode) {
                $db->query("INSERT INTO admin_coupons (code, type, value, usage_limit, expiry_date) VALUES (?, ?, ?, ?, ?)",
                    [$newCode, $type, $value, $limit, $expiry]);
                $created++;
            }

            if (function_exists('flash_add')) {
                if ($created === 1) {
                    flash_add('success', __('admin.marketing.flash.created_single', ['code' => $codes[0]]));
                } else {
                    flash_add('success', __('admin.marketing.flash.created_batch', ['count' => $created]));
                }
            }
            if (function_exists('redirect_303')) { redirect_303('marketing.php'); } else { header("Location: marketing.php", true, 303); exit; }
        } catch (Exception $e) {
            if (function_exists('flash_add')) flash_add('error', __('admin.marketing.flash.create_failed', ['message' => $e->getMessage()]));
            if (function_exists('redirect_303')) { redirect_303('marketing.php'); } else { header("Location: marketing.php", true, 303); exit; }
        }
    }
}

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        if (function_exists('flash_add')) flash_add('error', __('admin.marketing.flash.csrf'));
    } else {
        $db->query("DELETE FROM admin_coupons WHERE id = ?", [(int)$_POST['id']]);
        if (function_exists('flash_add')) flash_add('success', __('admin.marketing.flash.deleted'));
    }
    if (function_exists('redirect_303')) { redirect_303('marketing.php'); } else { header("Location: marketing.php", true, 303); exit; }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$total_coupons = (int)($db->fetch("SELECT COUNT(*) AS c FROM admin_coupons")['c'] ?? 0);
$total_pages = max(1, (int)ceil($total_coupons / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;
$coupons = $db->fetchAll("SELECT * FROM admin_coupons ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");

require_once 'includes/header.php';
?>

<!-- Content Wrapper -->
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center mb-4">
    <h1 class="h2 mb-0"><?php echo __('admin.marketing.page_title'); ?></h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="fas fa-plus"></i> <?php echo __('admin.marketing.new_coupon'); ?>
    </button>
</div>

<?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?php echo $f['type']==='error'?'danger':($f['type']==='success'?'success':'info'); ?>">
        <?php echo htmlspecialchars($f['message']); ?>
    </div>
<?php endforeach; ?>

<div class="mole-card p-0 overflow-hidden">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4"><?php echo __('admin.marketing.table.code'); ?></th>
                        <th><?php echo __('admin.marketing.table.type'); ?></th>
                        <th><?php echo __('admin.marketing.table.value'); ?></th>
                        <th><?php echo __('admin.marketing.table.usage'); ?></th>
                        <th><?php echo __('admin.marketing.table.expiry'); ?></th>
                        <th><?php echo __('admin.marketing.table.status'); ?></th>
                        <th class="text-end pe-4"><?php echo __('admin.marketing.table.actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($coupons as $c): ?>
                    <tr>
                        <td class="ps-4 font-monospace fw-bold text-primary"><?php echo htmlspecialchars($c['code']); ?></td>
                        <td>
                            <?php if($c['type'] == 'fixed'): ?>
                                <span class="badge bg-info text-dark"><?php echo __('admin.marketing.type.fixed'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark"><?php echo __('admin.marketing.type.percent'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-bold">
                            <?php echo $c['type'] == 'fixed' ? '$'.number_format($c['value'], 2) : $c['value'].'% OFF'; ?>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo $c['used_count']; ?> / <?php echo $c['usage_limit'] == -1 ? '∞' : $c['usage_limit']; ?></span>
                        </td>
                        <td>
                            <?php echo $c['expiry_date'] ? date('Y-m-d', strtotime($c['expiry_date'])) : __('admin.marketing.expiry.forever'); ?>
                        </td>
                        <td>
                            <?php if($c['status'] == 'active'): ?>
                                <span class="badge bg-success"><?php echo __('admin.marketing.status.active'); ?></span>
                            <?php else: ?>
                                <span class="badge bg-danger"><?php echo __('admin.marketing.status.disabled'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end pe-4">
                            <form method="POST" class="d-inline" onsubmit="return confirm(<?php echo json_encode(__('admin.marketing.confirm_delete')); ?>)">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger"><?php echo __('merchant.common.delete'); ?></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<div class="card-body border-top d-flex justify-content-between align-items-center">
        <small class="text-muted"><?php echo __('merchant.common.total_count', ['count' => $total_coupons]); ?></small>
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
            <span class="btn btn-light disabled"><?php echo $page; ?> / <?php echo $total_pages; ?></span>
            <a class="btn btn-outline-secondary <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($total_pages, $page + 1); ?>"><?php echo __('merchant.common.next_page'); ?></a>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" onsubmit="disableSubmit(this)">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo __('admin.marketing.modal.title'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('admin.marketing.modal.code'); ?></label>
                        <input type="text" name="code" class="form-control" placeholder="<?php echo __('admin.marketing.modal.code_placeholder'); ?>">
                        <div class="form-text"><?php echo __('admin.marketing.modal.code_hint'); ?></div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col">
                            <label class="form-label"><?php echo __('admin.marketing.modal.quantity'); ?></label>
                            <input type="number" min="1" max="500" name="quantity" class="form-control" value="1">
                        </div>
                        <div class="col">
                            <label class="form-label"><?php echo __('admin.marketing.modal.prefix'); ?></label>
                            <input type="text" name="code_prefix" class="form-control" value="UPG" placeholder="<?php echo __('admin.marketing.modal.prefix_placeholder'); ?>">
                        </div>
                    </div>
                    <input type="hidden" name="auto_generate" id="autoGenerateInput" value="0">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="autoGenerateSwitch">
                        <label class="form-check-label" for="autoGenerateSwitch"><?php echo __('admin.marketing.modal.auto_generate'); ?></label>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col">
                            <label class="form-label"><?php echo __('admin.marketing.modal.type'); ?></label>
                            <select name="type" class="form-select">
                                <option value="fixed"><?php echo __('admin.marketing.modal.type_fixed'); ?></option>
                                <option value="percent"><?php echo __('admin.marketing.modal.type_percent'); ?></option>
                            </select>
                        </div>
                        <div class="col">
                            <label class="form-label"><?php echo __('admin.marketing.modal.value'); ?></label>
                            <input type="number" step="0.01" name="value" class="form-control" required placeholder="<?php echo __('admin.marketing.modal.value_placeholder'); ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('admin.marketing.modal.usage_limit'); ?></label>
                        <input type="number" name="usage_limit" class="form-control" value="-1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo __('admin.marketing.modal.expiry'); ?></label>
                        <input type="datetime-local" name="expiry_date" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo __('merchant.common.create'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
<script>
function disableSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerText = <?php echo json_encode(__('admin.marketing.creating')); ?>; }
}
document.addEventListener('DOMContentLoaded', function () {
    const sw = document.getElementById('autoGenerateSwitch');
    const val = document.getElementById('autoGenerateInput');
    if (sw && val) {
        sw.addEventListener('change', function () {
            val.value = sw.checked ? '1' : '0';
        });
    }
});
</script>
