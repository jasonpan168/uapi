<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Http.php';
require_once __DIR__ . '/../src/Core/I18n.php';
require_once __DIR__ . '/../src/Helper.php';
I18n::init();
$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

// Get user info to check plan
$user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
$plan = $db->fetch("SELECT * FROM plans WHERE id = ?", [$user['plan_id']]);

// Check if plan allows multiple websites (Assuming free=1, others=unlimited or configurable)
// Since requirements said "User upgrade plan can only bind ONE website", we enforce limit=1 for now unless specified
// Wait, user said "用户升级套餐后只能绑定一个网站域名... 在后台直接展示... 方便用户在升级后选择网站类型... 用户升级套餐后必须绑定指定网站"
// This implies 1 website per user, or maybe 1 website per upgrade? Let's assume 1 active website for now.
$max_websites = 1;

$count_row = $db->fetch("SELECT COUNT(*) AS c FROM websites WHERE user_id = ?", [$user_id]);
$count = (int)($count_row['c'] ?? 0);

$flashes = flash_consume_all();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Helper::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        header("Location: websites.php?msg=csrf_invalid");
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'add') {
        if ($count >= $max_websites) {
            flash_add('error', __('merchant.websites.error.limit_reached', ['count' => $max_websites]));
            redirect_303('websites.php');
        } else {
            $domain = trim($_POST['domain'] ?? '');
            $category = trim($_POST['category'] ?? '');
            $domain = preg_replace('#^https?://#i', '', $domain);
            $domain = preg_replace('#/.*$#', '', $domain);
            $domain = rtrim($domain, '/');
            $domain = preg_replace('/^www\./i', '', $domain);
            $domain = strtolower($domain);
            if ($domain === '' || !preg_match('/^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $domain)) {
                flash_add('error', __('merchant.websites.error.invalid_domain'));
                redirect_303('websites.php');
            }
            $existsByOther = $db->fetch(
                "SELECT id FROM websites WHERE domain = ? AND user_id <> ? LIMIT 1",
                [$domain, $user_id]
            );
            if ($existsByOther) {
                flash_add('error', __('merchant.websites.error.domain_taken'));
                redirect_303('websites.php');
            }
            try {
                $db->query("INSERT INTO websites (user_id, domain, category) VALUES (?, ?, ?)", [$user_id, $domain, $category]);
                flash_add('success', __('merchant.websites.success.bound'));
                redirect_303('websites.php');
            } catch (Exception $e) {
                $err = strtolower($e->getMessage());
                if (strpos($err, 'duplicate') !== false || strpos($err, 'uniq_domain_global') !== false || strpos($err, '23000') !== false) {
                    flash_add('error', __('merchant.websites.error.domain_taken'));
                } else {
                    flash_add('error', __('merchant.websites.error.add_failed'));
                }
                redirect_303('websites.php');
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->query("DELETE FROM websites WHERE id = ? AND user_id = ?", [$id, $user_id]);
            flash_add('success', __('merchant.websites.success.unbound'));
        } else {
            flash_add('error', __('merchant.websites.error.invalid_param'));
        }
        redirect_303('websites.php');
    }
}

// Site settings
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$site_logo = $cfg['site_logo'] ?? '';
$page_title = __('merchant.websites.title');
$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil($count / $per_page));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per_page;
$websites = $db->fetchAll(
    "SELECT * FROM websites WHERE user_id = ? ORDER BY id DESC LIMIT $per_page OFFSET $offset",
    [$user_id]
);

?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Content -->
        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.websites.title'); include __DIR__ . '/includes/user_topbar.php'; ?>
            
            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?php echo $f['type']==='error'?'danger':($f['type']==='success'?'success':'info'); ?>">
                    <?php echo htmlspecialchars($f['message']); ?>
                </div>
            <?php endforeach; ?>

            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> <?php echo __('merchant.websites.info_prefix'); ?>
                <br><?php echo __('merchant.websites.info_limit', ['count' => $max_websites]); ?>
            </div>

            <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px; overflow: hidden;">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-globe text-primary me-2"></i> <?php echo __('merchant.websites.bound_list'); ?></h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th class="ps-4 py-3 text-secondary text-uppercase small fw-bold"><?php echo __('merchant.websites.table.domain'); ?></th>
                                <th class="py-3 text-secondary text-uppercase small fw-bold"><?php echo __('merchant.websites.table.category'); ?></th>
                                <th class="py-3 text-secondary text-uppercase small fw-bold"><?php echo __('merchant.websites.table.status'); ?></th>
                                <th class="py-3 text-secondary text-uppercase small fw-bold"><?php echo __('merchant.websites.table.bound_at'); ?></th>
                                <th class="text-end pe-4 py-3 text-secondary text-uppercase small fw-bold"><?php echo __('merchant.websites.table.actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($websites as $w): ?>
                            <tr>
                                <td class="ps-4 py-3">
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded p-2 me-3 text-primary">
                                            <i class="fas fa-link"></i>
                                        </div>
                                        <span class="fw-bold text-dark"><?php echo htmlspecialchars($w['domain']); ?></span>
                                    </div>
                                </td>
                                <?php
                                    $category_key = sprintf('merchant.websites.category.%s', $w['category']);
                                    $category_label = __($category_key);
                                    if ($category_label === $category_key) {
                                        $category_label = $w['category'];
                                    }
                                ?>
                                <td><span class="badge bg-secondary bg-opacity-10 text-secondary px-3 py-2 rounded-pill"><?php echo htmlspecialchars($category_label); ?></span></td>
                                <td>
                                    <?php if($w['status'] == 'active'): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success px-3 py-2 rounded-pill"><i class="fas fa-check-circle me-1"></i> <?php echo __('merchant.websites.status.active'); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger bg-opacity-10 text-danger px-3 py-2 rounded-pill"><i class="fas fa-ban me-1"></i> <?php echo __('merchant.websites.status.blocked'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?php echo date('Y-m-d H:i', strtotime($w['created_at'])); ?></td>
                                <td class="text-end pe-4">
                                    <form method="POST" onsubmit="return confirm(<?php echo json_encode(__('merchant.websites.confirm_unbind')); ?>);" class="d-inline"><?php echo Helper::csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $w['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0"><i class="fas fa-trash-alt"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($websites)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted"><?php echo __('merchant.websites.no_sites'); ?></td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top bg-white">
                    <small class="text-muted"><?php echo __('merchant.common.page_status', ['page' => (int)$page, 'pages' => (int)$pages, 'total' => (int)$count]); ?></small>
                    <div class="d-flex gap-2">
                        <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                        <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($pages, $page + 1); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                    </div>
                </div>
            </div>

            <?php if ($count < $max_websites): ?>
            <div class="card border-0 shadow-sm" style="border-radius: 12px;">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-plus-circle text-success me-2"></i> <?php echo __('merchant.websites.bind_new'); ?></h5>
                </div>
                <div class="card-body pt-0 pb-4">
                    <form method="POST" onsubmit="disableSubmit(this)"><?php echo Helper::csrfField(); ?>
                        <input type="hidden" name="action" value="add">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-5">
                                <label class="form-label text-secondary small fw-bold text-uppercase"><?php echo __('merchant.websites.form.domain'); ?></label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light border-end-0"><i class="fas fa-globe text-muted"></i></span>
                                    <input type="text" name="domain" class="form-control border-start-0 ps-0" placeholder="example.com" required pattern="^[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$" title="<?php echo __('merchant.websites.form.domain_title'); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-secondary small fw-bold text-uppercase"><?php echo __('merchant.websites.form.category'); ?></label>
                                <select name="category" class="form-select">
                                    <option value="ecommerce"><?php echo __('merchant.websites.category.ecommerce'); ?></option>
                                    <option value="gaming"><?php echo __('merchant.websites.category.gaming'); ?></option>
                                    <option value="service"><?php echo __('merchant.websites.category.service'); ?></option>
                                    <option value="digital"><?php echo __('merchant.websites.category.digital'); ?></option>
                                    <option value="other"><?php echo __('merchant.websites.category.other'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="fas fa-check me-2"></i> <?php echo __('merchant.websites.form.bind_now'); ?></button>
                            </div>
                        </div>
                        <div class="form-text mt-2"><i class="fas fa-info-circle me-1"></i> <?php echo __('merchant.websites.form.domain_hint'); ?></div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function disableSubmit(form) {
    const btn = form.querySelector('button[type="submit"]');
    if (btn) { btn.disabled = true; btn.innerText = <?php echo json_encode(__('merchant.websites.processing')); ?>; }
}
</script>
</body>
</html>
