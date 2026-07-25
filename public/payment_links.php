<?php
// public/payment_links.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();
$db = Database::getInstance();
// Auto-migrate on access to ensure table exists
$db->autoMigrate();

$user_id = $_SESSION['user_id'];
$user = $db->fetch("SELECT id, plan_id FROM users WHERE id = ?", [$user_id]);
$settings = $db->fetchAll("SELECT * FROM system_settings");
$cfg = [];
foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
$site_name = $cfg['site_name'] ?? 'UAPI';
$page_title = __('merchant.payment_links.title');
$receiveModeKey = 'merchant_receive_mode_u' . (int)$user_id;
$receiveModeRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$receiveModeKey]);
$receive_mode = strtolower(trim((string)($receiveModeRow['value'] ?? 'wallet')));
if (!in_array($receive_mode, ['wallet', 'derived'], true)) {
    $receive_mode = 'wallet';
}
try { $db->query("ALTER TABLE payment_links ADD COLUMN receive_mode VARCHAR(20) DEFAULT 'wallet'"); } catch (Exception $e) {}
try { $db->query("ALTER TABLE payment_links ADD COLUMN currency VARCHAR(10) DEFAULT 'USDT'"); } catch (Exception $e) {}
$platformCurrencies = [];
if (($cfg['enable_payment_usdt'] ?? '1') === '1') $platformCurrencies[] = 'USDT';
if (($cfg['enable_usdc'] ?? '0') === '1') $platformCurrencies[] = 'USDC';
if (empty($platformCurrencies)) $platformCurrencies[] = 'USDT';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim($_POST['title']);
        $amount = (float)$_POST['amount'];
        $chain = strtolower(trim((string)($_POST['chain'] ?? '')));
        $linkReceiveMode = strtolower(trim((string)($_POST['link_receive_mode'] ?? 'wallet')));
        $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USDT')));
        if (!in_array($linkReceiveMode, ['wallet', 'derived'], true)) {
            $linkReceiveMode = 'wallet';
        }
        if (!in_array($currency, $platformCurrencies, true)) {
            $currency = 'USDT';
        }
        if ($currency === 'USDC' && $chain === 'trc20') {
            $currency = 'USDT';
        }
        
        if ($title && $chain) {
            if ($linkReceiveMode === 'derived') {
                $okChain = $db->fetch(
                    "SELECT c.id
                     FROM chains c
                     INNER JOIN plan_chains pc ON pc.chain_id = c.id AND pc.plan_id = ?
                     LEFT JOIN plan_chain_derived pcd ON pcd.plan_id = pc.plan_id AND pcd.chain_id = pc.chain_id
                     WHERE c.slug = ? AND c.status = 1 AND c.is_evm = 1 AND COALESCE(c.allow_derived, 1) = 1 AND COALESCE(pcd.enabled, 1) = 1
                     LIMIT 1",
                    [(int)$user['plan_id'], strtolower((string)$chain)]
                );
                if (!$okChain) {
                    header("Location: payment_links.php");
                    exit;
                }
            } else {
                $okWallet = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND LOWER(chain) = ? AND status = 1 LIMIT 1", [$user_id, strtolower((string)$chain)]);
                if (!$okWallet) {
                    header("Location: payment_links.php");
                    exit;
                }
            }
            $db->query("INSERT INTO payment_links (user_id, title, amount, currency, chain, receive_mode) VALUES (?, ?, ?, ?, ?, ?)", [
                $user_id, $title, $amount, $currency, $chain, $linkReceiveMode
            ]);
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $db->query("DELETE FROM payment_links WHERE id = ? AND user_id = ?", [$id, $user_id]);
    }
    header("Location: payment_links.php");
    exit;
}

$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$total_row = $db->fetch("SELECT COUNT(*) AS c FROM payment_links WHERE user_id = ?", [$user_id]);
$total = (int)($total_row['c'] ?? 0);
$pages = max(1, (int)ceil($total / $per_page));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per_page;
$links = $db->fetchAll(
    "SELECT * FROM payment_links WHERE user_id = ? ORDER BY id DESC LIMIT $per_page OFFSET $offset",
    [$user_id]
);
$wallets = $db->fetchAll("SELECT * FROM wallets WHERE user_id = ? AND status = 1", [$user_id]);
$derived_chains = $db->fetchAll(
    "SELECT c.slug, c.name
     FROM chains c
     INNER JOIN plan_chains pc ON pc.chain_id = c.id AND pc.plan_id = ?
     LEFT JOIN plan_chain_derived pcd ON pcd.plan_id = pc.plan_id AND pcd.chain_id = pc.chain_id
     WHERE c.status = 1 AND c.is_evm = 1 AND COALESCE(c.allow_derived, 1) = 1 AND COALESCE(pcd.enabled, 1) = 1
     ORDER BY c.name ASC",
    [(int)$user['plan_id']]
);
$chainRows = $db->fetchAll("SELECT slug, name FROM chains WHERE status = 1");
$chainNameMap = [];
foreach ($chainRows as $cr) {
    $chainNameMap[strtolower((string)($cr['slug'] ?? ''))] = (string)($cr['name'] ?? '');
}
$walletOptions = [];
foreach ($wallets as $w) {
    $slug = strtolower((string)($w['chain'] ?? ''));
    if ($slug === '') continue;
    $walletOptions[$slug] = [
        'slug' => $slug,
        'name' => (string)($chainNameMap[$slug] ?? strtoupper($slug)),
        'address' => (string)($w['address'] ?? '')
    ];
}
$walletOptions = array_values($walletOptions);
$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$can_create_link = !empty($walletOptions) || !empty($derived_chains);
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

        <!-- Main Content -->
        <div class="col-md-9 col-lg-10 main-content">
            <?php $page_title = __('merchant.payment_links.title'); include __DIR__ . '/includes/user_topbar.php'; ?>

            <div class="d-flex justify-content-end mb-4">
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
                    <i class="fas fa-plus me-2"></i><?php echo __('merchant.payment_links.create_link'); ?>
                </button>
            </div>

            <?php if (empty($walletOptions)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo __('merchant.payment_links.no_wallet_prefix'); ?> <a href="dashboard.php"><?php echo __('merchant.payment_links.overview_page'); ?></a> <?php echo __('merchant.payment_links.no_wallet_suffix'); ?>
            </div>
            <?php endif; ?>
            <?php if (empty($derived_chains)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo I18n::getLang()==='en' ? 'Derived mode is enabled, but no supported derived chain is available for your current plan.' : '已启用派生收款，但当前套餐没有可用的派生网络。'; ?>
            </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo __('merchant.payment_links.table.title'); ?></th>
                                    <th><?php echo __('merchant.payment_links.table.amount'); ?></th>
                                    <th><?php echo I18n::getLang()==='en' ? 'Currency' : '币种'; ?></th>
                                    <th><?php echo __('merchant.payment_links.table.network'); ?></th>
                                    <th><?php echo I18n::getLang()==='en' ? 'Receive Mode' : '收款方式'; ?></th>
                                    <th><?php echo __('merchant.payment_links.table.link'); ?></th>
                                    <th><?php echo __('merchant.payment_links.table.actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($links as $link): 
                                    $url = $base_url . '/easy_pay.php?id=' . $link['id'];
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($link['title']); ?></td>
                                    <td><?php echo $link['amount'] > 0 ? number_format($link['amount'], 2) : '<span class="badge bg-info">' . __('merchant.payment_links.user_input_amount') . '</span>'; ?></td>
                                    <td>
                                        <span class="badge bg-dark"><?php echo htmlspecialchars(strtoupper((string)($link['currency'] ?? 'USDT'))); ?></span>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo strtoupper($link['chain']); ?></span></td>
                                    <td>
                                        <?php $rowMode = strtolower(trim((string)($link['receive_mode'] ?? 'wallet'))); ?>
                                        <span class="badge <?php echo $rowMode === 'derived' ? 'bg-warning text-dark' : 'bg-success'; ?>">
                                            <?php echo $rowMode === 'derived'
                                                ? (I18n::getLang()==='en' ? 'Derived' : '派生地址')
                                                : (I18n::getLang()==='en' ? 'Fixed Wallet' : '固定地址'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="input-group input-group-sm" style="width: 300px;">
                                            <input type="text" class="form-control" value="<?php echo $url; ?>" readonly id="link-<?php echo $link['id']; ?>">
                                            <button class="btn btn-outline-secondary" onclick="copyLink('link-<?php echo $link['id']; ?>')"><i class="far fa-copy"></i></button>
                                            <a href="<?php echo $url; ?>" target="_blank" class="btn btn-outline-secondary"><i class="fas fa-external-link-alt"></i></a>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="POST" onsubmit="return confirm(<?php echo json_encode(__('merchant.payment_links.confirm_delete')); ?>);">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo $link['id']; ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($links)): ?>
                                <tr><td colspan="7" class="text-center py-4 text-muted"><?php echo __('merchant.payment_links.no_links'); ?></td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center border-top pt-3 mt-2">
                        <small class="text-muted">第 <?php echo (int)$page; ?> / <?php echo (int)$pages; ?> 页，共 <?php echo (int)$total; ?> 条</small>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>">上一页</a>
                            <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($pages, $page + 1); ?>">下一页</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo __('merchant.payment_links.modal.create_title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label"><?php echo __('merchant.payment_links.modal.form_title'); ?></label>
                    <input type="text" name="title" class="form-control" placeholder="<?php echo __('merchant.payment_links.modal.title_placeholder'); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo __('merchant.payment_links.modal.amount'); ?></label>
                    <input type="number" name="amount" class="form-control" placeholder="<?php echo __('merchant.payment_links.modal.amount_placeholder'); ?>" step="0.01" min="0">
                    <div class="form-text"><?php echo __('merchant.payment_links.modal.amount_hint'); ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo I18n::getLang()==='en' ? 'Currency' : '币种'; ?></label>
                    <select name="currency" class="form-select">
                        <?php foreach ($platformCurrencies as $pc): ?>
                        <option value="<?php echo htmlspecialchars($pc); ?>"><?php echo htmlspecialchars($pc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label"><?php echo I18n::getLang()==='en' ? 'Receive Mode' : '收款方式'; ?></label>
                    <select name="link_receive_mode" id="linkReceiveMode" class="form-select" onchange="toggleLinkChainOptions()">
                        <option value="wallet"><?php echo I18n::getLang()==='en' ? 'Fixed Wallet' : '固定地址收款'; ?></option>
                        <option value="derived"><?php echo I18n::getLang()==='en' ? 'Derived Address' : '派生地址收款'; ?></option>
                    </select>
                </div>
                <div class="mb-3" id="walletChainWrap">
                    <label class="form-label"><?php echo __('merchant.payment_links.modal.chain'); ?></label>
                    <select name="chain" id="walletChainSelect" class="form-select" <?php echo empty($walletOptions) ? 'disabled' : ''; ?>>
                        <?php foreach($walletOptions as $wo): ?>
                        <option value="<?php echo htmlspecialchars($wo['slug']); ?>"><?php echo strtoupper($wo['slug']); ?> (<?php echo substr((string)$wo['address'], 0, 8); ?>...)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($walletOptions)): ?>
                    <div class="form-text text-danger"><?php echo __('merchant.payment_links.modal.add_wallet_first'); ?></div>
                    <?php endif; ?>
                </div>
                <div class="mb-3" id="derivedChainWrap" style="display:none;">
                    <label class="form-label"><?php echo __('merchant.payment_links.modal.chain'); ?></label>
                    <select id="derivedChainSelect" class="form-select" <?php echo empty($derived_chains) ? 'disabled' : ''; ?>>
                        <?php foreach($derived_chains as $dc): ?>
                        <option value="<?php echo htmlspecialchars($dc['slug']); ?>"><?php echo strtoupper($dc['slug']); ?> (<?php echo htmlspecialchars($dc['name']); ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($derived_chains)): ?>
                    <div class="form-text text-danger"><?php echo I18n::getLang()==='en' ? 'No derived chain available' : '当前无可用派生网络'; ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                <button type="submit" class="btn btn-primary" <?php echo !$can_create_link?'disabled':''; ?>><?php echo __('merchant.payment_links.modal.create'); ?></button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyLink(id) {
    var copyText = document.getElementById(id);
    copyText.select();
    document.execCommand("copy");
    alert(<?php echo json_encode(__('merchant.payment_links.copy_success')); ?>);
}

function toggleLinkChainOptions() {
    const modeEl = document.getElementById('linkReceiveMode');
    const mode = String(modeEl ? modeEl.value : 'wallet');
    const walletWrap = document.getElementById('walletChainWrap');
    const derivedWrap = document.getElementById('derivedChainWrap');
    const walletSelect = document.getElementById('walletChainSelect');
    const derivedSelect = document.getElementById('derivedChainSelect');
    const hasWallet = <?php echo !empty($walletOptions) ? 'true' : 'false'; ?>;
    const hasDerived = <?php echo !empty($derived_chains) ? 'true' : 'false'; ?>;

    if (mode === 'derived') {
        if (walletSelect) walletSelect.disabled = true;
        if (derivedSelect) derivedSelect.disabled = !hasDerived;
        if (walletWrap) walletWrap.style.display = 'none';
        if (derivedWrap) derivedWrap.style.display = '';
        if (derivedSelect && hasDerived) derivedSelect.setAttribute('name', 'chain');
        if (walletSelect) walletSelect.removeAttribute('name');
    } else {
        if (derivedSelect) derivedSelect.disabled = true;
        if (walletSelect) walletSelect.disabled = !hasWallet;
        if (derivedWrap) derivedWrap.style.display = 'none';
        if (walletWrap) walletWrap.style.display = '';
        if (walletSelect && hasWallet) walletSelect.setAttribute('name', 'chain');
        if (derivedSelect) derivedSelect.removeAttribute('name');
    }
    syncLinkCurrencyOptions();
}

function syncLinkCurrencyOptions() {
    const modeEl = document.getElementById('linkReceiveMode');
    const mode = String(modeEl ? modeEl.value : 'wallet');
    const walletSelect = document.getElementById('walletChainSelect');
    const derivedSelect = document.getElementById('derivedChainSelect');
    const currencySelect = document.querySelector('select[name="currency"]');
    if (!currencySelect) return;
    const chainSelect = mode === 'derived' ? derivedSelect : walletSelect;
    const chain = chainSelect && chainSelect.value ? String(chainSelect.value).toLowerCase() : '';
    const usdcOption = currencySelect.querySelector('option[value="USDC"]');
    if (!usdcOption) return;
    if (chain === 'trc20') {
        usdcOption.disabled = true;
        if (currencySelect.value === 'USDC') currencySelect.value = 'USDT';
    } else {
        usdcOption.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    toggleLinkChainOptions();
    const walletSelect = document.getElementById('walletChainSelect');
    const derivedSelect = document.getElementById('derivedChainSelect');
    if (walletSelect) walletSelect.addEventListener('change', syncLinkCurrencyOptions);
    if (derivedSelect) derivedSelect.addEventListener('change', syncLinkCurrencyOptions);
});
</script>
</body>
</html>
