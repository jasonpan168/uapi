<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Core/Migrator.php';

$db = Database::getInstance();
$migrator = new Migrator($db->getConnection());
$migrator->run();
$db->autoMigrate();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['admin_csrf_token'])) {
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
}
$admin_csrf_token = $_SESSION['admin_csrf_token'];

$cfgRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$sys = [];
foreach ($cfgRows as $r) {
    $sys[$r['key_name']] = $r['value'];
}

function is_valid_evm_address($address)
{
    return (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', trim((string)$address));
}

function broadcast_evm_raw_tx($chainId, $rawTxHex, $apiKey)
{
    $chainId = (int)$chainId;
    $rawTxHex = trim((string)$rawTxHex);
    $apiKey = trim((string)$apiKey);

    if ($chainId <= 0 || !preg_match('/^0x[a-fA-F0-9]+$/', $rawTxHex) || $apiKey === '') {
        return ['ok' => false, 'error' => '参数无效'];
    }

    $url = 'https://api.etherscan.io/v2/api?chainid=' . urlencode((string)$chainId)
        . '&module=proxy&action=eth_sendRawTransaction'
        . '&hex=' . urlencode($rawTxHex)
        . '&apikey=' . urlencode($apiKey);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $http !== 200) {
        return ['ok' => false, 'error' => '网络错误: ' . ($curlErr ?: ('HTTP ' . $http))];
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => '返回格式错误'];
    }
    if (!empty($data['result']) && preg_match('/^0x[a-fA-F0-9]{64}$/', (string)$data['result'])) {
        return ['ok' => true, 'tx_hash' => (string)$data['result']];
    }
    $err = (string)($data['message'] ?? '');
    if (isset($data['result']) && is_string($data['result']) && $data['result'] !== '') {
        $err .= ($err !== '' ? ' | ' : '') . $data['result'];
    }
    if (isset($data['error']['message'])) {
        $err .= ($err !== '' ? ' | ' : '') . (string)$data['error']['message'];
    }
    if ($err === '') $err = '广播失败';
    return ['ok' => false, 'error' => $err];
}

function fetch_evm_nonce($chainId, $address, $apiKey)
{
    $chainId = (int)$chainId;
    $address = trim((string)$address);
    $apiKey = trim((string)$apiKey);
    if ($chainId <= 0 || !is_valid_evm_address($address) || $apiKey === '') {
        return null;
    }
    $url = 'https://api.etherscan.io/v2/api?chainid=' . urlencode((string)$chainId)
        . '&module=proxy&action=eth_getTransactionCount'
        . '&address=' . urlencode($address)
        . '&tag=pending'
        . '&apikey=' . urlencode($apiKey);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $http !== 200) {
        return null;
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return null;
    }
    $hex = (string)($data['result'] ?? '');
    if (!preg_match('/^0x[0-9a-fA-F]+$/', $hex)) {
        return null;
    }
    return strtolower($hex);
}

$evmChains = [
    'bsc' => [
        'name' => 'BSC',
        'chain_id' => 56,
        'usdt_contract' => trim((string)($sys['usdt_contract_bsc'] ?? '0x55d398326f99059fF775485246999027B3197955')),
    ],
    'eth' => [
        'name' => 'Ethereum',
        'chain_id' => 1,
        'usdt_contract' => trim((string)($sys['usdt_contract_eth'] ?? '0xdAC17F958D2ee523a2206206994597C13D831ec7')),
    ],
    'polygon' => [
        'name' => 'Polygon',
        'chain_id' => 137,
        'usdt_contract' => trim((string)($sys['usdt_contract_polygon'] ?? '0xc2132D05D31c914a87C6611C10748AEb04B58e8F')),
    ],
    'arbitrum' => [
        'name' => 'Arbitrum',
        'chain_id' => 42161,
        'usdt_contract' => trim((string)($sys['usdt_contract_arbitrum'] ?? '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9')),
    ],
    'base' => [
        'name' => 'Base',
        'chain_id' => 8453,
        'usdt_contract' => trim((string)($sys['usdt_contract_base'] ?? '0xfde4C96c8593536E31F229EA8f37b2ADa2699bb2')),
    ],
    'optimism' => [
        'name' => 'Optimism',
        'chain_id' => 10,
        'usdt_contract' => trim((string)($sys['usdt_contract_optimism'] ?? '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58')),
    ],
];

$selectedChain = strtolower(trim((string)($_REQUEST['chain'] ?? ($sys['sweep_last_chain'] ?? 'bsc'))));
if (!isset($evmChains[$selectedChain])) {
    $selectedChain = 'bsc';
}
$chainMeta = $evmChains[$selectedChain];
$apiKey = trim((string)($sys['eth_api_key'] ?? ''));

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($admin_csrf_token, $csrfToken)) {
        http_response_code(403);
        if (($_POST['ajax'] ?? '0') === '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'CSRF 校验失败'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $message = 'CSRF 校验失败';
        $messageType = 'danger';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $isAjax = (($_POST['ajax'] ?? '0') === '1');
        $ajaxPayload = null;

        if ($action === 'broadcast_raw_batch') {
            $chainId = (int)($_POST['chain_id'] ?? 0);
            $rawJson = trim((string)($_POST['raw_txs_json'] ?? ''));
            if ($chainId <= 0 || $rawJson === '') {
                $ajaxPayload = ['ok' => false, 'message' => '缺少链ID或交易数据'];
            } elseif ($apiKey === '') {
                $ajaxPayload = ['ok' => false, 'message' => '未配置 eth_api_key'];
            } else {
                $rows = json_decode($rawJson, true);
                if (!is_array($rows) || empty($rows)) {
                    $ajaxPayload = ['ok' => false, 'message' => '交易 JSON 无效'];
                } else {
                    $ok = 0;
                    $fail = 0;
                    $errors = [];
                    foreach ($rows as $idx => $rawTx) {
                        $raw = trim((string)$rawTx);
                        if (!preg_match('/^0x[a-fA-F0-9]+$/', $raw)) {
                            $fail++;
                            $errors[] = '第' . ($idx + 1) . '笔格式错误';
                            continue;
                        }
                        $ret = broadcast_evm_raw_tx($chainId, $raw, $apiKey);
                        if (!empty($ret['ok'])) {
                            $ok++;
                        } else {
                            $fail++;
                            $errors[] = '第' . ($idx + 1) . '笔失败: ' . (string)($ret['error'] ?? '未知错误');
                        }
                    }
                    $msg = '批量广播完成：成功 ' . $ok . '，失败 ' . $fail;
                    if (!empty($errors)) {
                        $msg .= '；' . implode(' | ', array_slice($errors, 0, 5));
                    }
                    $ajaxPayload = [
                        'ok' => $fail === 0,
                        'message' => $msg,
                        'ok_count' => $ok,
                        'fail_count' => $fail,
                        'errors' => $errors
                    ];
                }
            }
        }
        if ($action === 'fetch_nonce') {
            $address = trim((string)($_POST['address'] ?? ''));
            $nonceHex = fetch_evm_nonce((int)$chainMeta['chain_id'], $address, $apiKey);
            if ($nonceHex === null) {
                $ajaxPayload = ['ok' => false, 'message' => '读取 nonce 失败，请检查地址或 API 配置'];
            } else {
                $ajaxPayload = ['ok' => true, 'nonce' => $nonceHex, 'message' => 'nonce 已读取: ' . $nonceHex];
            }
        }

        if ($isAjax && $ajaxPayload !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($ajaxPayload, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

$pendingRows = $db->fetchAll(
    "SELECT DISTINCT i.from_address
     FROM admin_collection_items i
     JOIN admin_collection_batches b ON b.id = i.batch_id
     WHERE b.chain_slug = ? AND i.status <> 'broadcasted'
     LIMIT 500",
    [$selectedChain]
);
$pendingTargets = [];
foreach ($pendingRows as $r) {
    $a = strtolower(trim((string)($r['from_address'] ?? '')));
    if (is_valid_evm_address($a)) {
        $pendingTargets[] = $a;
    }
}
$pendingTargets = array_values(array_unique($pendingTargets));

$paidRows = $db->fetchAll(
    "SELECT DISTINCT w.address
     FROM admin_derived_wallets w
     INNER JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
     INNER JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid'
     LEFT JOIN admin_collection_items ci ON ci.wallet_id = w.id AND ci.status = 'broadcasted'
     WHERE w.chain_slug = ? AND ci.id IS NULL
     ORDER BY w.id DESC
     LIMIT 500",
    [$selectedChain]
);
$paidTargets = [];
foreach ($paidRows as $r) {
    $a = strtolower(trim((string)($r['address'] ?? '')));
    if (is_valid_evm_address($a)) {
        $paidTargets[] = $a;
    }
}
$paidTargets = array_values(array_unique($paidTargets));

$defaultGasPath = trim((string)($sys['sweep_gas_path_' . $selectedChain] ?? "m/44'/60'/0'/0/0"));
$defaultGasAddress = trim((string)($sys['sweep_gas_address_' . $selectedChain] ?? ''));
$defaultGasTopupWei = trim((string)($sys['sweep_gas_topup_wei_' . $selectedChain] ?? '300000000000000'));
if (!preg_match('/^[0-9]+$/', $defaultGasTopupWei)) $defaultGasTopupWei = '300000000000000';
$defaultGasPriceWei = trim((string)($sys['sweep_gas_price_wei_' . $selectedChain] ?? '1000000000'));
if (!preg_match('/^[0-9]+$/', $defaultGasPriceWei)) $defaultGasPriceWei = '1000000000';

$active_menu = 'gas_topup_debug';
require_once 'includes/header.php';
?>

<?php if ($message !== ''): ?>
<div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-bold">自动补 Gas 调试页（独立）</div>
    <div class="card-body">
        <div class="alert alert-info mb-3">
            本页仅用于排查“路径识别/补Gas”问题。先用“测试匹配地址”，再执行“批量补Gas广播”。
        </div>
        <div class="row g-3">
            <div class="col-lg-3">
                <label class="form-label">链</label>
                <select id="chainSelect" class="form-select" onchange="jumpChain(this.value)">
                    <?php foreach ($evmChains as $slug => $meta): ?>
                        <option value="<?php echo htmlspecialchars($slug); ?>" <?php echo $slug === $selectedChain ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($meta['name']); ?> (<?php echo (int)$meta['chain_id']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-9 d-flex align-items-end">
                <div class="small text-muted">
                    当前链：<?php echo htmlspecialchars($chainMeta['name']); ?> · Chain ID <?php echo (int)$chainMeta['chain_id']; ?> ·
                    待归集地址 <?php echo count($pendingTargets); ?> 个 · 已支付未归集地址 <?php echo count($paidTargets); ?> 个
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-lg-6">
                <label class="form-label">助记词（用于Gas钱包）</label>
                <textarea id="mnemonic" class="form-control" rows="3" placeholder="12/24 助记词"></textarea>
            </div>
            <div class="col-lg-6">
                <label class="form-label">私钥（可选，填了则优先）</label>
                <input id="privateKey" type="password" class="form-control" placeholder="0x...">
                <label class="form-label mt-2">附加密码（可空）</label>
                <input id="passphrase" type="password" class="form-control" placeholder="passphrase">
            </div>
            <div class="col-lg-4">
                <label class="form-label">Gas付款钱包地址（可空）</label>
                <input id="expectedAddress" class="form-control" value="<?php echo htmlspecialchars($defaultGasAddress); ?>" placeholder="0x...">
            </div>
            <div class="col-lg-4">
                <label class="form-label">派生路径</label>
                <input id="path" class="form-control" value="<?php echo htmlspecialchars($defaultGasPath); ?>" placeholder="m/44'/60'/0'/0/0">
            </div>
            <div class="col-lg-4">
                <label class="form-label">路径标准</label>
                <select id="profile" class="form-select">
                    <option value="auto">自动匹配常见路径</option>
                    <option value="evm_standard">EVM 标准 (m/44'/60'/0'/0/i)</option>
                    <option value="ledger_live">Ledger Live (m/44'/60'/account'/0/0)</option>
                </select>
            </div>
            <div class="col-lg-4">
                <label class="form-label">扫描深度</label>
                <input id="depth" type="number" min="20" max="10000" value="1200" class="form-control">
            </div>
            <div class="col-lg-4">
                <label class="form-label">交易序号起点</label>
                <input id="startNonce" class="form-control" value="0x0">
            </div>
            <div class="col-lg-4">
                <label class="form-label">每个地址补主币(wei)</label>
                <input id="topupWei" class="form-control" value="<?php echo htmlspecialchars($defaultGasTopupWei); ?>">
            </div>
            <div class="col-lg-4">
                <label class="form-label">补Gas手续费单价(wei)</label>
                <input id="gasPriceWei" class="form-control" value="<?php echo htmlspecialchars($defaultGasPriceWei); ?>">
            </div>
            <div class="col-lg-8 d-flex align-items-end gap-2">
                <button class="btn btn-outline-primary" type="button" onclick="testMatch()">测试匹配地址</button>
                <button class="btn btn-outline-primary" type="button" onclick="fetchNonceNow()">自动读取 nonce</button>
                <button class="btn btn-outline-secondary" type="button" onclick="usePendingTargets()">载入最新批次待归集地址</button>
                <button class="btn btn-outline-secondary" type="button" onclick="usePaidTargets()">载入已支付未归集地址</button>
                <button class="btn btn-primary" type="button" onclick="runTopup()">批量补 Gas 广播</button>
            </div>
            <div class="col-12">
                <label class="form-label">补Gas目标地址（每行一个）</label>
                <textarea id="targets" class="form-control" rows="8"></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">执行日志</label>
                <textarea id="log" class="form-control" rows="8" readonly></textarea>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/ethers@6.13.2/dist/ethers.umd.min.js"></script>
<script>
const ACTIVE_CHAIN = <?php echo json_encode($selectedChain, JSON_UNESCAPED_UNICODE); ?>;
const ACTIVE_CHAIN_ID = <?php echo (int)$chainMeta['chain_id']; ?>;
const CSRF_TOKEN = <?php echo json_encode($admin_csrf_token, JSON_UNESCAPED_UNICODE); ?>;
const pendingTargets = <?php echo json_encode($pendingTargets, JSON_UNESCAPED_UNICODE); ?>;
const paidTargets = <?php echo json_encode($paidTargets, JSON_UNESCAPED_UNICODE); ?>;

function log(msg) {
    const el = document.getElementById('log');
    const now = new Date();
    const t = [now.getHours(), now.getMinutes(), now.getSeconds()].map(v => String(v).padStart(2, '0')).join(':');
    el.value += '[' + t + '] ' + msg + '\n';
    el.scrollTop = el.scrollHeight;
}

function jumpChain(chain) {
    const url = new URL(window.location.href);
    url.searchParams.set('chain', chain);
    window.location.href = url.toString();
}

function normalizeMnemonic(s) {
    return String(s || '').trim().replace(/\s+/g, ' ');
}

function toBigHex(v) {
    const s = String(v || '').trim();
    if (s === '') throw new Error('值为空');
    if (/^0x[0-9a-fA-F]+$/.test(s)) return s.toLowerCase();
    if (!/^[0-9]+$/.test(s)) throw new Error('仅支持十进制或0x十六进制');
    return '0x' + BigInt(s).toString(16);
}

function getTargets() {
    return String(document.getElementById('targets').value || '')
        .split('\n')
        .map(s => s.trim().toLowerCase())
        .filter(s => /^0x[a-f0-9]{40}$/.test(s))
        .filter((v, i, arr) => arr.indexOf(v) === i);
}

function usePendingTargets() {
    document.getElementById('targets').value = (pendingTargets || []).join('\n');
    log('已载入最新批次待归集地址：' + (pendingTargets || []).length + ' 个');
}

function usePaidTargets() {
    document.getElementById('targets').value = (paidTargets || []).join('\n');
    log('已载入已支付未归集地址：' + (paidTargets || []).length + ' 个');
}

function buildPathCandidates(profile, scanDepth) {
    const depth = Math.max(20, Math.min(10000, parseInt(scanDepth || '1200', 10) || 1200));
    const out = [];
    const push = (p) => { if (!out.includes(p)) out.push(p); };
    if (profile === 'ledger_live') {
        for (let a = 0; a < depth; a++) push(`m/44'/60'/${a}'/0/0`);
    } else if (profile === 'evm_standard') {
        for (let i = 0; i < depth; i++) push(`m/44'/60'/0'/0/${i}`);
    } else {
        for (let i = 0; i < depth; i++) push(`m/44'/60'/0'/0/${i}`);
        for (let i = 0; i < depth; i++) push(`m/44'/60'/0'/1/${i}`);
        for (let i = 0; i < depth; i++) push(`m/44'/60'/0'/${i}`);
        for (let a = 0; a < depth; a++) push(`m/44'/60'/${a}'/0/0`);
        for (let a = 0; a < depth; a++) push(`m/44'/60'/${a}'/0`);
    }
    return out;
}

async function findPathByAddressAsync(mnemonic, passphrase, expectedAddress, profile, depth) {
    const target = String(expectedAddress || '').trim().toLowerCase();
    if (!/^0x[a-f0-9]{40}$/.test(target)) throw new Error('目标地址格式无效');
    const passSet = Array.from(new Set([String(passphrase || ''), '']));
    const quickPaths = new Set([
        String(document.getElementById('path').value || '').trim(),
        "m/44'/60'/0'/0/0",
        "m/44'/60'/0'/0/1",
        "m/44'/60'/1'/0/0",
        "m/44'/60'/0'/1/0"
    ]);
    for (const pp of passSet) {
        for (const p of quickPaths) {
            if (!p || !p.startsWith('m/')) continue;
            try {
                const w = ethers.HDNodeWallet.fromPhrase(mnemonic, pp, p);
                if (w.address.toLowerCase() === target) return { path: p, passphrase: pp };
            } catch (_) {}
        }
    }
    const candidates = buildPathCandidates(profile, depth);
    for (let i = 0; i < candidates.length; i++) {
        const p = candidates[i];
        for (const pp of passSet) {
            try {
                const w = ethers.HDNodeWallet.fromPhrase(mnemonic, pp, p);
                if (w.address.toLowerCase() === target) return { path: p, passphrase: pp };
            } catch (_) {}
        }
        if (i > 0 && i % 250 === 0) {
            log('扫描进度: ' + i + '/' + candidates.length);
            await new Promise(r => setTimeout(r, 0));
        }
    }
    return { path: '', passphrase: String(passphrase || '') };
}

async function postActionJson(action, fields) {
    const params = new URLSearchParams();
    params.set('csrf_token', CSRF_TOKEN);
    params.set('action', action);
    params.set('chain', ACTIVE_CHAIN);
    params.set('ajax', '1');
    Object.keys(fields || {}).forEach(k => params.set(k, String(fields[k] ?? '')));
    const resp = await fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
        credentials: 'same-origin'
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    return data;
}

async function fetchNonceNow(resolvedWallet) {
    try {
        const address = resolvedWallet && resolvedWallet.wallet
            ? resolvedWallet.wallet.address
            : String(document.getElementById('expectedAddress').value || '').trim();
        if (!/^0x[a-fA-F0-9]{40}$/.test(address)) throw new Error('请先填写有效 Gas 地址，或先测试匹配地址');
        const resp = await postActionJson('fetch_nonce', { address: address });
        if (!resp || !resp.ok) throw new Error((resp && resp.message) ? resp.message : '读取失败');
        document.getElementById('startNonce').value = String(resp.nonce || '0x0');
        log('自动读取 nonce 成功: ' + String(resp.nonce || '0x0') + ' [' + address + ']');
    } catch (e) {
        log('读取 nonce 失败: ' + (e && e.message ? e.message : String(e)));
    }
}

async function resolveGasWallet() {
    const pk = String(document.getElementById('privateKey').value || '').trim();
    const expected = String(document.getElementById('expectedAddress').value || '').trim().toLowerCase();
    let path = String(document.getElementById('path').value || '').trim();
    const profile = String(document.getElementById('profile').value || 'auto');
    const depth = String(document.getElementById('depth').value || '1200');
    if (pk) {
        if (!/^0x[a-fA-F0-9]{64}$/.test(pk)) throw new Error('私钥格式错误');
        const wallet = new ethers.Wallet(pk);
        log('使用私钥地址: ' + wallet.address);
        return { wallet: wallet, path: 'private_key' };
    }
    const mnemonic = normalizeMnemonic(document.getElementById('mnemonic').value);
    let passphrase = String(document.getElementById('passphrase').value || '').trim();
    if (!mnemonic) throw new Error('请填写助记词或私钥');
    if (!ethers.Mnemonic.isValidMnemonic(mnemonic)) throw new Error('助记词格式错误');
    if (!path) path = "m/44'/60'/0'/0/0";

    let wallet = ethers.HDNodeWallet.fromPhrase(mnemonic, passphrase, path);
    log('当前路径地址: ' + wallet.address + ' [' + path + ']');

    if (expected && wallet.address.toLowerCase() !== expected) {
        log('与目标地址不一致，开始自动识别路径...');
        const found = await findPathByAddressAsync(mnemonic, passphrase, expected, profile, depth);
        if (found.path) {
            document.getElementById('path').value = found.path;
            if (found.passphrase !== passphrase) {
                passphrase = found.passphrase;
                log('识别命中：使用空附加密码');
            }
            wallet = ethers.HDNodeWallet.fromPhrase(mnemonic, passphrase, found.path);
            log('识别成功: ' + found.path + ' -> ' + wallet.address);
            return { wallet: wallet, path: found.path };
        }
        log('未识别到匹配路径，将继续使用当前路径地址进行补Gas');
    }
    return { wallet: wallet, path: path };
}

async function testMatch() {
    try {
        log('开始测试匹配地址...');
        const ret = await resolveGasWallet();
        log('测试完成，最终地址: ' + ret.wallet.address + '，路径: ' + ret.path);
    } catch (e) {
        log('测试失败: ' + (e && e.message ? e.message : String(e)));
    }
}

async function runTopup() {
    try {
        const targets = getTargets();
        if (!targets.length) throw new Error('没有可用目标地址');
        const resolved = await resolveGasWallet();
        const wallet = resolved.wallet;
        await fetchNonceNow(resolved);
        const startNonce = BigInt(toBigHex(document.getElementById('startNonce').value || '0x0'));
        const topupWei = BigInt(toBigHex(document.getElementById('topupWei').value));
        const gasPrice = BigInt(toBigHex(document.getElementById('gasPriceWei').value));
        const raws = [];
        for (let i = 0; i < targets.length; i++) {
            const tx = {
                chainId: Number(ACTIVE_CHAIN_ID),
                nonce: startNonce + BigInt(i),
                gasLimit: 21000n,
                gasPrice: gasPrice,
                to: targets[i],
                value: topupWei,
                data: '0x',
                type: 0
            };
            raws.push(await wallet.signTransaction(tx));
        }
        log('已签名 ' + raws.length + ' 笔，开始广播...');
        const resp = await postActionJson('broadcast_raw_batch', {
            chain_id: String(ACTIVE_CHAIN_ID),
            raw_txs_json: JSON.stringify(raws)
        });
        log(resp && resp.message ? resp.message : '广播完成');
    } catch (e) {
        log('执行失败: ' + (e && e.message ? e.message : String(e)));
    }
}

(function init() {
    usePendingTargets();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
