<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/Migrator.php';
require_once __DIR__ . '/../src/Services/TotpService.php';
require_once __DIR__ . '/../src/Services/User2FAService.php';
require_once __DIR__ . '/../config/config.php';

$db = Database::getInstance();
$migrator = new Migrator($db->getConnection());
$migrator->run();
$db->autoMigrate();
try { $db->query("ALTER TABLE chains ADD COLUMN allow_derived TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}

if (empty($_SESSION['merchant_csrf_token'])) {
    $_SESSION['merchant_csrf_token'] = bin2hex(random_bytes(32));
}
$merchant_csrf_token = (string)$_SESSION['merchant_csrf_token'];

$user_id = (int)$_SESSION['user_id'];
$user = $db->fetch(
    "SELECT u.*, p.allow_derived_wallet
     FROM users u
     LEFT JOIN plans p ON p.id = u.plan_id
     WHERE u.id = ? LIMIT 1",
    [$user_id]
);
if (!$user) {
    header("Location: login.php");
    exit;
}
$can_use_derived_wallet = ($user['role'] ?? '') === 'admin' || (int)($user['allow_derived_wallet'] ?? 0) === 1;
$derived2faRequired = User2FAService::isSceneEnabled((array)$user, 'derived_wallet');

$cfgRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$sys = [];
foreach ($cfgRows as $r) {
    $sys[$r['key_name']] = $r['value'];
}

if (!$can_use_derived_wallet) {
    $active_menu = 'derived_wallets';
    $page_title = __('merchant.nav.derived_wallets');
    require_once __DIR__ . '/includes/merchant_derived_header.php';
    ?>
    <div class="alert alert-warning">
        <?php echo I18n::getLang() === 'en'
            ? 'Your current plan does not include Derived Management. Please upgrade your plan to enable it.'
            : '当前套餐未开通派生管理能力，请先升级套餐后再使用。'; ?>
    </div>
    <a class="btn btn-primary" href="upgrade.php">
        <?php echo __('merchant.nav.upgrade'); ?>
    </a>
    <?php
    require_once __DIR__ . '/includes/merchant_derived_footer.php';
    exit;
}

$dtt = static function (string $zh, string $en): string {
    return I18n::getLang() === 'en' ? $en : $zh;
};

function is_valid_evm_address($address)
{
    return (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', trim((string)$address));
}

function normalize_evm_contract_candidates($candidates)
{
    $out = [];
    foreach ((array)$candidates as $addr) {
        $lc = strtolower(trim((string)$addr));
        if (is_valid_evm_address($lc)) {
            $out[] = $lc;
        }
    }
    return array_values(array_unique($out));
}

function resolve_chain_token_candidates($chainRow, $chainConf, $tokenSymbol)
{
    $symbol = strtoupper(trim((string)$tokenSymbol));
    $key = strtolower($symbol);
    $dbColumn = $key . '_contract';
    $raw = [];
    if (!empty($chainRow[$dbColumn])) {
        $raw[] = (string)$chainRow[$dbColumn];
    }
    if (isset($chainConf[$key])) {
        if (is_array($chainConf[$key])) {
            foreach ($chainConf[$key] as $addr) {
                $raw[] = (string)$addr;
            }
        } else {
            $raw[] = (string)$chainConf[$key];
        }
    }
    return normalize_evm_contract_candidates($raw);
}

function upsert_setting($db, $key, $value)
{
    $exists = $db->fetch("SELECT 1 FROM system_settings WHERE key_name = ?", [$key]);
    if ($exists) {
        $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$value, $key]);
    } else {
        $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$key, $value]);
    }
}

function scoped_setting_key($baseKey, $userId)
{
    return $baseKey . '_u' . (int)$userId;
}

function get_scoped_setting($sys, $baseKey, $userId, $default = '')
{
    $scopedKey = scoped_setting_key($baseKey, $userId);
    if (array_key_exists($scopedKey, $sys)) {
        return $sys[$scopedKey];
    }
    return $default;
}

function dec_to_hex_str($dec)
{
    $dec = ltrim((string)$dec, '0');
    if ($dec === '' || $dec === '0') {
        return '0';
    }

    $digits = '0123456789abcdef';
    $result = '';
    $num = $dec;

    while ($num !== '0') {
        $carry = 0;
        $quotient = '';
        $len = strlen($num);
        for ($i = 0; $i < $len; $i++) {
            $n = $carry * 10 + (int)$num[$i];
            $q = intdiv($n, 16);
            $carry = $n % 16;
            if (!($quotient === '' && $q === 0)) {
                $quotient .= (string)$q;
            }
        }
        if ($quotient === '') {
            $quotient = '0';
        }
        $result = $digits[$carry] . $result;
        $num = $quotient;
    }

    return $result;
}

function build_erc20_transfer_data($to, $amountWei)
{
    $toClean = strtolower(ltrim((string)$to, '0x'));
    $toHex = str_pad($toClean, 64, '0', STR_PAD_LEFT);
    $amtHex = dec_to_hex_str((string)$amountWei);
    $amtPadded = str_pad($amtHex, 64, '0', STR_PAD_LEFT);
    return '0xa9059cbb' . $toHex . $amtPadded;
}

function cmp_uint_str($a, $b)
{
    $a = ltrim(preg_replace('/\\D+/', '', (string)$a), '0');
    $b = ltrim(preg_replace('/\\D+/', '', (string)$b), '0');
    if ($a === '') $a = '0';
    if ($b === '') $b = '0';
    if (strlen($a) > strlen($b)) return 1;
    if (strlen($a) < strlen($b)) return -1;
    return strcmp($a, $b);
}

function normalize_uint_hex($hex)
{
    $s = strtolower(trim((string)$hex));
    if (strpos($s, '0x') === 0) {
        $s = substr($s, 2);
    }
    $s = preg_replace('/[^0-9a-f]/', '', $s);
    $s = ltrim($s, '0');
    return $s === '' ? '0' : $s;
}

function normalize_evm_topic_address($topic)
{
    $t = strtolower(trim((string)$topic));
    if (strpos($t, '0x') !== 0) return '';
    $hex = substr($t, 2);
    if (strlen($hex) !== 64) return '';
    $addr = substr($hex, -40);
    return preg_match('/^[0-9a-f]{40}$/', $addr) ? ('0x' . $addr) : '';
}

function fetch_evm_tx_receipt($chainId, $txHash, $apiKey)
{
    $chainId = (int)$chainId;
    $txHash = trim((string)$txHash);
    $apiKey = trim((string)$apiKey);
    if ($chainId <= 0 || !preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash) || $apiKey === '') {
        return false;
    }
    $url = 'https://api.etherscan.io/v2/api?chainid=' . urlencode((string)$chainId)
        . '&module=proxy&action=eth_getTransactionReceipt'
        . '&txhash=' . urlencode($txHash)
        . '&apikey=' . urlencode($apiKey);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $http !== 200) {
        return false;
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return false;
    }
    if (!array_key_exists('result', $data)) {
        return false;
    }
    if ($data['result'] === null) {
        return null; // pending
    }
    return is_array($data['result']) ? $data['result'] : false;
}

function wait_evm_tx_receipt($chainId, $txHash, $apiKey, $tries = 10, $sleepMs = 1200)
{
    $tries = max(1, min(30, (int)$tries));
    $sleepMs = max(100, min(5000, (int)$sleepMs));
    for ($i = 0; $i < $tries; $i++) {
        $receipt = fetch_evm_tx_receipt($chainId, $txHash, $apiKey);
        if (is_array($receipt)) return $receipt;
        if ($receipt === false) return false;
        usleep($sleepMs * 1000);
    }
    return null;
}

function has_expected_erc20_transfer($receipt, $tokenContract, $from, $to, $amountWei)
{
    if (!is_array($receipt)) return false;
    $logs = $receipt['logs'] ?? null;
    if (!is_array($logs)) return false;
    $expToken = strtolower(trim((string)$tokenContract));
    $expFrom = strtolower(trim((string)$from));
    $expTo = strtolower(trim((string)$to));
    $expAmountHex = normalize_uint_hex('0x' . dec_to_hex_str((string)$amountWei));
    $transferTopic0 = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    foreach ($logs as $log) {
        if (!is_array($log)) continue;
        $addr = strtolower(trim((string)($log['address'] ?? '')));
        if ($addr !== $expToken) continue;
        $topics = $log['topics'] ?? [];
        if (!is_array($topics) || count($topics) < 3) continue;
        if (strtolower((string)$topics[0]) !== $transferTopic0) continue;
        $fromLog = normalize_evm_topic_address((string)$topics[1]);
        $toLog = normalize_evm_topic_address((string)$topics[2]);
        if ($fromLog === '' || $toLog === '') continue;
        if (strtolower($fromLog) !== $expFrom || strtolower($toLog) !== $expTo) continue;
        $amtHex = normalize_uint_hex((string)($log['data'] ?? '0x0'));
        if ($amtHex === $expAmountHex) {
            return true;
        }
    }
    return false;
}

function evm_explorer_tx_url($slug, $txHash)
{
    $slug = strtolower(trim((string)$slug));
    $txHash = trim((string)$txHash);
    if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) return '';
    $map = [
        'eth' => 'https://etherscan.io/tx/',
        'bsc' => 'https://bscscan.com/tx/',
        'polygon' => 'https://polygonscan.com/tx/',
        'optimism' => 'https://optimistic.etherscan.io/tx/',
        'arbitrum' => 'https://arbiscan.io/tx/',
        'base' => 'https://basescan.org/tx/',
        'avalanche' => 'https://snowtrace.io/tx/',
    ];
    $base = $map[$slug] ?? '';
    return $base === '' ? '' : ($base . $txHash);
}

function resolve_item_token_contract($item, $fallback = '')
{
    $cands = [];
    $fromItem = strtolower(trim((string)($item['token_contract'] ?? '')));
    if (is_valid_evm_address($fromItem)) $cands[] = $fromItem;

    $payloadRaw = (string)($item['qr_payload'] ?? '');
    if ($payloadRaw !== '') {
        $payload = json_decode($payloadRaw, true);
        if (is_array($payload)) {
            $pc = strtolower(trim((string)($payload['token_contract'] ?? '')));
            if (is_valid_evm_address($pc)) $cands[] = $pc;
        }
    }

    $eip = (string)($item['eip681_uri'] ?? '');
    if (preg_match('/^ethereum:(0x[a-fA-F0-9]{40})@/i', $eip, $m)) {
        $ec = strtolower(trim((string)$m[1]));
        if (is_valid_evm_address($ec)) $cands[] = $ec;
    }

    $fb = strtolower(trim((string)$fallback));
    if (is_valid_evm_address($fb)) $cands[] = $fb;

    $cands = array_values(array_unique($cands));
    return $cands[0] ?? '';
}

function fetch_evm_usdt_balance($chainId, $contract, $address, $apiKey)
{
    if ((int)$chainId <= 0 || !is_valid_evm_address($address) || !is_valid_evm_address($contract) || trim((string)$apiKey) === '') {
        return null;
    }
    $url = 'https://api.etherscan.io/v2/api?chainid=' . urlencode((string)$chainId)
        . '&module=account&action=tokenbalance'
        . '&contractaddress=' . urlencode($contract)
        . '&address=' . urlencode($address)
        . '&tag=latest&apikey=' . urlencode($apiKey);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $http !== 200) {
        return fetch_evm_erc20_balance_via_rpc((int)$chainId, (string)$contract, (string)$address);
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return fetch_evm_erc20_balance_via_rpc((int)$chainId, (string)$contract, (string)$address);
    }
    if ((string)($data['status'] ?? '') !== '1') {
        // Etherscan may return status 0 with message for no tx yet; keep as 0 if result exists numeric
        if (isset($data['result']) && preg_match('/^[0-9]+$/', (string)$data['result'])) {
            return (string)$data['result'];
        }
        $rpcBal = fetch_evm_erc20_balance_via_rpc((int)$chainId, (string)$contract, (string)$address);
        return $rpcBal;
    }

    return (string)($data['result'] ?? '0');
}

function evm_default_rpc_by_chain_id($chainId)
{
    $chainId = (int)$chainId;
    $map = [
        1 => 'https://rpc.ankr.com/eth',
        56 => 'https://bsc-dataseed.binance.org',
        137 => 'https://polygon-rpc.com',
        42161 => 'https://arb1.arbitrum.io/rpc',
        10 => 'https://mainnet.optimism.io',
        8453 => 'https://mainnet.base.org',
        43114 => 'https://api.avax.network/ext/bc/C/rpc'
    ];
    return (string)($map[$chainId] ?? '');
}

function hex_to_dec_str($hex)
{
    $hex = strtolower(trim((string)$hex));
    if (strpos($hex, '0x') === 0) {
        $hex = substr($hex, 2);
    }
    $hex = preg_replace('/[^0-9a-f]/', '', $hex);
    $hex = ltrim($hex, '0');
    if ($hex === '') return '0';

    $digits = '0123456789abcdef';
    $dec = '0';
    $len = strlen($hex);
    for ($i = 0; $i < $len; $i++) {
        $nibble = strpos($digits, $hex[$i]);
        if ($nibble === false) return null;
        $carry = $nibble;
        $tmp = '';
        for ($j = strlen($dec) - 1; $j >= 0; $j--) {
            $v = ((int)$dec[$j]) * 16 + $carry;
            $tmp = (string)($v % 10) . $tmp;
            $carry = intdiv($v, 10);
        }
        while ($carry > 0) {
            $tmp = (string)($carry % 10) . $tmp;
            $carry = intdiv($carry, 10);
        }
        $dec = ltrim($tmp, '0');
        if ($dec === '') $dec = '0';
    }
    return $dec;
}

function fetch_evm_erc20_balance_via_rpc($chainId, $contract, $address)
{
    $chainId = (int)$chainId;
    $contract = strtolower(trim((string)$contract));
    $address = strtolower(trim((string)$address));
    if ($chainId <= 0 || !is_valid_evm_address($contract) || !is_valid_evm_address($address)) {
        return null;
    }
    $rpcUrl = evm_default_rpc_by_chain_id($chainId);
    if ($rpcUrl === '') {
        return null;
    }

    $addrNo0x = substr($address, 2);
    $data = '0x70a08231' . str_pad($addrNo0x, 64, '0', STR_PAD_LEFT);
    $payload = json_encode([
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'eth_call',
        'params' => [
            ['to' => $contract, 'data' => $data],
            'latest'
        ]
    ], JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return null;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $rpcUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false || $http < 200 || $http >= 300) {
        return null;
    }

    $obj = json_decode($resp, true);
    if (!is_array($obj) || isset($obj['error'])) {
        return null;
    }
    $retHex = trim((string)($obj['result'] ?? ''));
    if (!preg_match('/^0x[0-9a-fA-F]+$/', $retHex)) {
        return null;
    }
    return hex_to_dec_str($retHex);
}

function fetch_evm_usdt_balances_parallel($chainId, $contract, $addresses, $apiKey, $concurrency = 12)
{
    $results = [];
    $queue = [];
    foreach ($addresses as $a) {
        $addr = trim((string)$a);
        if (!is_valid_evm_address($addr)) {
            continue;
        }
        $queue[] = $addr;
    }
    if (empty($queue) || (int)$chainId <= 0 || !is_valid_evm_address($contract) || trim((string)$apiKey) === '') {
        return $results;
    }
    $concurrency = max(1, min(30, (int)$concurrency));
    $mh = curl_multi_init();
    $handles = [];
    $next = 0;
    $total = count($queue);

    $createHandle = function ($addr) use ($chainId, $contract, $apiKey) {
        $url = 'https://api.etherscan.io/v2/api?chainid=' . urlencode((string)$chainId)
            . '&module=account&action=tokenbalance'
            . '&contractaddress=' . urlencode($contract)
            . '&address=' . urlencode($addr)
            . '&tag=latest&apikey=' . urlencode($apiKey);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        return $ch;
    };

    while ($next < $total && count($handles) < $concurrency) {
        $addr = $queue[$next++];
        $ch = $createHandle($addr);
        $key = (int)$ch;
        $handles[$key] = ['ch' => $ch, 'addr' => $addr];
        curl_multi_add_handle($mh, $ch);
    }

    do {
        do {
            $status = curl_multi_exec($mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = (int)$ch;
            $addr = isset($handles[$key]) ? $handles[$key]['addr'] : '';
            $resp = curl_multi_getcontent($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($addr !== '' && $resp !== false && $http === 200) {
                $data = json_decode($resp, true);
                if (is_array($data) && isset($data['result']) && preg_match('/^[0-9]+$/', (string)$data['result'])) {
                    $results[$addr] = (string)$data['result'];
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$key]);

            if ($next < $total) {
                $nextAddr = $queue[$next++];
                $nextCh = $createHandle($nextAddr);
                $nextKey = (int)$nextCh;
                $handles[$nextKey] = ['ch' => $nextCh, 'addr' => $nextAddr];
                curl_multi_add_handle($mh, $nextCh);
            }
        }

        if ($running > 0) {
            curl_multi_select($mh, 0.4);
        }
    } while ($running > 0 || !empty($handles));

    curl_multi_close($mh);
    return $results;
}

function format_by_decimals($weiStr, $decimals)
{
    $weiStr = preg_replace('/\D+/', '', (string)$weiStr);
    if ($weiStr === '') {
        $weiStr = '0';
    }
    $decimals = max(0, (int)$decimals);
    if ($decimals === 0) {
        return (float)$weiStr;
    }

    $len = strlen($weiStr);
    if ($len <= $decimals) {
        $weiStr = str_pad($weiStr, $decimals + 1, '0', STR_PAD_LEFT);
        $len = strlen($weiStr);
    }
    $intPart = substr($weiStr, 0, $len - $decimals);
    $fracPart = substr($weiStr, -$decimals);
    $fracPart = rtrim($fracPart, '0');
    $display = $fracPart === '' ? $intPart : ($intPart . '.' . $fracPart);
    return (float)$display;
}

function decimal_to_units_str($amount, $decimals)
{
    $decimals = max(0, (int)$decimals);
    $s = trim((string)$amount);
    if ($s === '') return '0';
    $s = str_replace(',', '', $s);
    if (!preg_match('/^\d+(\.\d+)?$/', $s)) return '0';
    $parts = explode('.', $s, 2);
    $int = ltrim($parts[0], '0');
    if ($int === '') $int = '0';
    $frac = $parts[1] ?? '';
    if ($decimals > 0) {
        $frac = substr($frac, 0, $decimals);
        $frac = str_pad($frac, $decimals, '0');
    } else {
        $frac = '';
    }
    $out = ltrim($int . $frac, '0');
    return $out === '' ? '0' : $out;
}

function broadcast_evm_raw_tx($chainId, $rawTxHex, $apiKey)
{
    $chainId = (int)$chainId;
    $rawTxHex = trim((string)$rawTxHex);
    $apiKey = trim((string)$apiKey);
    if ($chainId <= 0 || $rawTxHex === '' || $apiKey === '') {
        return ['ok' => false, 'error' => '参数缺失'];
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
    curl_close($ch);

    if ($resp === false || $http !== 200) {
        return ['ok' => false, 'error' => '广播请求失败'];
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => '广播响应无效'];
    }
    $result = trim((string)($data['result'] ?? ''));
    if (preg_match('/^0x[a-fA-F0-9]{64}$/', $result)) {
        return ['ok' => true, 'tx_hash' => $result];
    }
    $err = '';
    if (isset($data['error']) && is_array($data['error'])) {
        $err = trim((string)($data['error']['message'] ?? ''));
    }
    if ($err === '') {
        $err = trim((string)($data['message'] ?? ''));
    }
    if ($err === '') {
        $err = '链上广播失败';
    }
    return ['ok' => false, 'error' => $err];
}

function fetch_evm_tx_count($chainId, $address, $apiKey)
{
    $chainId = (int)$chainId;
    $address = trim((string)$address);
    $apiKey = trim((string)$apiKey);
    if ($chainId <= 0 || !preg_match('/^0x[a-fA-F0-9]{40}$/', $address) || $apiKey === '') {
        return null;
    }
    $url = 'https://api.etherscan.io/v2/api?chainid=' . urlencode((string)$chainId)
        . '&module=proxy&action=eth_getTransactionCount'
        . '&address=' . urlencode($address)
        . '&tag=pending&apikey=' . urlencode($apiKey);
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
    $result = trim((string)($data['result'] ?? ''));
    if (!preg_match('/^0x[0-9a-fA-F]+$/', $result)) {
        return null;
    }
    return $result;
}

$isAdminUser = (($user['role'] ?? '') === 'admin');
if ($isAdminUser) {
    $allChains = $db->fetchAll(
        "SELECT c.*
         FROM chains c
         WHERE c.status = 1
           AND c.is_evm = 1
           AND COALESCE(c.allow_derived, 1) = 1
         ORDER BY c.name ASC"
    );
} else {
    $allChains = $db->fetchAll(
        "SELECT c.*
         FROM chains c
         INNER JOIN plan_chains pc ON pc.chain_id = c.id AND pc.plan_id = ?
         LEFT JOIN plan_chain_derived pcd ON pcd.plan_id = pc.plan_id AND pcd.chain_id = pc.chain_id
         WHERE c.status = 1
           AND c.is_evm = 1
           AND COALESCE(c.allow_derived, 1) = 1
           AND COALESCE(pcd.enabled, 1) = 1
         ORDER BY c.name ASC",
        [(int)($user['plan_id'] ?? 0)]
    );
}
$evmChains = [];
foreach ($allChains as $c) {
    $slug = strtolower((string)($c['slug'] ?? ''));
    if ($slug === '' || !isset($chains_config[$slug])) {
        continue;
    }
    $conf = $chains_config[$slug];
    $chainId = (int)($c['chain_id'] ?? ($conf['chain_id'] ?? 0));
    $usdtCandidates = resolve_chain_token_candidates($c, $conf, 'USDT');
    $usdcCandidates = resolve_chain_token_candidates($c, $conf, 'USDC');

    $evmChains[$slug] = [
        'slug' => $slug,
        'name' => (string)$c['name'],
        'symbol' => (string)($c['symbol'] ?? 'USDT'),
        'chain_id' => $chainId,
        'decimals' => (int)($conf['decimals'] ?? 6),
        'usdt_contract' => $usdtCandidates[0] ?? '',
        'usdt_contract_candidates' => $usdtCandidates,
        'usdc_contract' => $usdcCandidates[0] ?? '',
        'usdc_contract_candidates' => $usdcCandidates,
        'tokens' => [
            'USDT' => [
                'contract' => $usdtCandidates[0] ?? '',
                'candidates' => $usdtCandidates,
            ],
            'USDC' => [
                'contract' => $usdcCandidates[0] ?? '',
                'candidates' => $usdcCandidates,
            ],
        ],
    ];
}

if (empty($evmChains)) {
    $evmChains = [];
}

$merchantLastChainKey = scoped_setting_key('sweep_last_chain', $user_id);
$preferredChain = $_GET['chain'] ?? ($sys[$merchantLastChainKey] ?? array_key_first($evmChains));
$selectedChain = $preferredChain;
if (!isset($evmChains[$selectedChain])) {
    $selectedChain = array_key_first($evmChains);
}
$merchantLastTokenKey = scoped_setting_key('sweep_last_token', $user_id);
$preferredToken = strtoupper(trim((string)($_GET['token'] ?? ($sys[$merchantLastTokenKey] ?? 'USDT'))));
$selectedTokenSymbol = in_array($preferredToken, ['USDT', 'USDC'], true) ? $preferredToken : 'USDT';
$selectedChainTokenMeta = $evmChains[$selectedChain]['tokens'] ?? [];
$availableFlowTokens = [];
foreach (['USDT', 'USDC'] as $tokenCandidate) {
    if (is_valid_evm_address((string)($selectedChainTokenMeta[$tokenCandidate]['contract'] ?? ''))) {
        $availableFlowTokens[] = $tokenCandidate;
    }
}
// 补充：若该链已产生对应币种收款订单，也将其加入下拉可选（即使暂未配置合约，也要允许选择并给出明确提示）
try {
    $tokenRows = $db->fetchAll(
        "SELECT DISTINCT UPPER(COALESCE(o.currency, 'USDT')) AS ccy
         FROM orders o
         INNER JOIN admin_fee_address_allocations a ON a.order_no = o.order_no AND a.allocated_to_user_id = ?
         INNER JOIN admin_derived_wallets w ON w.id = a.wallet_id AND w.chain_slug = ?
         WHERE o.user_id = ?
           AND o.status = 'paid'
           AND UPPER(COALESCE(o.currency, 'USDT')) IN ('USDT','USDC')",
        [$user_id, $selectedChain, $user_id]
    );
    foreach ($tokenRows as $tr) {
        $ccy = strtoupper(trim((string)($tr['ccy'] ?? '')));
        if (in_array($ccy, ['USDT', 'USDC'], true)) {
            $availableFlowTokens[] = $ccy;
        }
    }
    $availableFlowTokens = array_values(array_unique($availableFlowTokens));
} catch (Throwable $ignore) {
}
if (empty($availableFlowTokens)) {
    $availableFlowTokens = ['USDT'];
}
if (!in_array($selectedTokenSymbol, $availableFlowTokens, true)) {
    $selectedTokenSymbol = $availableFlowTokens[0];
}

if (empty($evmChains)) {
    $active_menu = 'derived_wallets';
    require_once __DIR__ . '/includes/merchant_derived_header.php';
    echo '<div class=\"alert alert-warning\">未检测到可用 EVM 链，请先在“套餐与链”中启用 EVM 链并配置 chain_id。</div>';
    require_once __DIR__ . '/includes/merchant_derived_footer.php';
    exit;
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['merchant_csrf_token']) || !hash_equals($_SESSION['merchant_csrf_token'], $csrf)) {
        $message = '请求被拒绝（CSRF 校验失败）';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        $selectedChain = $_POST['chain'] ?? $selectedChain;
        $selectedTokenSymbol = strtoupper(trim((string)($_POST['token_symbol'] ?? $selectedTokenSymbol)));
        if (!in_array($selectedTokenSymbol, ['USDT', 'USDC'], true)) {
            $selectedTokenSymbol = 'USDT';
        }
        $isAjax = (string)($_POST['ajax'] ?? '0') === '1';
        $ajaxPayload = null;
        $sensitive2faActions = [
            'save_master', 'save_xpub_config', 'disable_legacy_pool',
            'rollback_collected_item', 'rollback_batch', 'mark_sent', 'broadcast_signed', 'broadcast_signed_batch',
            'broadcast_raw_batch', 'broadcast_raw_single', 'save_gas_profile'
        ];
        if (in_array($action, $sensitive2faActions, true) && User2FAService::isSceneEnabled((array)$user, 'derived_wallet')) {
            $otp = trim((string)($_POST['security_otp'] ?? ($_SERVER['HTTP_X_SECURITY_OTP'] ?? '')));
            if (!TotpService::verifyCode(trim((string)($user['two_factor_secret'] ?? '')), $otp, 1)) {
                $message = '派生钱包关键操作需要谷歌验证码（6 位动态码）';
                $messageType = 'danger';
                if ($isAjax) {
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                }
                $action = '__otp_blocked__';
            }
        }
        try {
            if (!isset($evmChains[$selectedChain])) {
                $selectedChain = array_key_first($evmChains);
            }
            upsert_setting($db, $merchantLastChainKey, (string)$selectedChain);
            $sys[$merchantLastChainKey] = (string)$selectedChain;
            $chainTokenMeta = $evmChains[$selectedChain]['tokens'] ?? [];
            $allowedTokens = [];
            foreach (['USDT', 'USDC'] as $symbol) {
                if (is_valid_evm_address((string)($chainTokenMeta[$symbol]['contract'] ?? ''))) {
                    $allowedTokens[] = $symbol;
                }
            }
            if (empty($allowedTokens)) {
                $allowedTokens = ['USDT'];
            }
            if (!in_array($selectedTokenSymbol, $allowedTokens, true)) {
                $selectedTokenSymbol = $allowedTokens[0];
            }
            upsert_setting($db, $merchantLastTokenKey, (string)$selectedTokenSymbol);
            $sys[$merchantLastTokenKey] = (string)$selectedTokenSymbol;

            $chainMeta = $evmChains[$selectedChain] ?? null;
            $activeTokenMeta = is_array($chainMeta) ? (array)($chainMeta['tokens'][$selectedTokenSymbol] ?? []) : [];
            $activeTokenContract = strtolower((string)($activeTokenMeta['contract'] ?? ''));
            $activeTokenCandidates = normalize_evm_contract_candidates((array)($activeTokenMeta['candidates'] ?? []));
            $masterBaseKey = 'sweep_master_' . $selectedChain;
            $xpubBaseKey = 'sweep_xpub_' . $selectedChain;
            $pathBaseKey = 'sweep_path_' . $selectedChain;
            $nextIndexBaseKey = 'sweep_next_index_' . $selectedChain;
            $poolTargetBaseKey = 'sweep_pool_target_' . $selectedChain;
            $masterSettingKey = scoped_setting_key($masterBaseKey, $user_id);
            $xpubSettingKey = scoped_setting_key($xpubBaseKey, $user_id);
            $pathSettingKey = scoped_setting_key($pathBaseKey, $user_id);
            $nextIndexSettingKey = scoped_setting_key($nextIndexBaseKey, $user_id);
            $poolTargetSettingKey = scoped_setting_key($poolTargetBaseKey, $user_id);
            $masterCurrent = trim((string)get_scoped_setting($sys, $masterBaseKey, $user_id, ''));

        if ($action === 'save_master' && $chainMeta) {
            $master = trim((string)($_POST['master_address'] ?? ''));
            if (!is_valid_evm_address($master)) {
                $message = '主钱包地址格式不正确';
                $messageType = 'danger';
            } else {
                upsert_setting($db, $masterSettingKey, $master);
                $sys[$masterSettingKey] = $master;
                $message = '主钱包已保存';
            }
        }

        if ($action === 'switch_chain' && $chainMeta) {
            $message = '已启用当前链作为派生钱包收款';
            $messageType = 'success';
            if ($isAjax) {
                $ajaxPayload = [
                    'ok' => true,
                    'message' => $message,
                    'chain' => (string)$selectedChain
                ];
            }
        }

        if ($action === 'save_xpub_config' && $chainMeta) {
            $xpub = trim((string)($_POST['xpub'] ?? ''));
            $pathPrefix = trim((string)($_POST['path_prefix'] ?? "m/44'/60'/0'/0"));
            $startIndex = max(0, (int)($_POST['start_index'] ?? 0));
            $poolTarget = max(1, min(1000, (int)($_POST['pool_target'] ?? 30)));

            if ($xpub === '') {
                $message = 'xpub 不能为空';
                $messageType = 'danger';
            } else {
                upsert_setting($db, $xpubSettingKey, $xpub);
                upsert_setting($db, $pathSettingKey, $pathPrefix);
                upsert_setting($db, $nextIndexSettingKey, (string)$startIndex);
                upsert_setting($db, $poolTargetSettingKey, (string)$poolTarget);

                $sys[$xpubSettingKey] = $xpub;
                $sys[$pathSettingKey] = $pathPrefix;
                $sys[$nextIndexSettingKey] = (string)$startIndex;
                $sys[$poolTargetSettingKey] = (string)$poolTarget;
                $message = '自动派生配置已保存，将自动补足地址池';
            }
        }

        if ($action === 'disable_legacy_pool' && $chainMeta) {
            $message = '商户后台不支持停用历史地址池，请在管理员后台操作。';
            $messageType = 'warning';
        }

        if ($action === 'refresh_balance' && $chainMeta) {
            $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
            if ($apiKey === '') {
                $message = '请先在系统设置中填写 EVM API Key（eth_api_key）';
                $messageType = 'danger';
            } elseif (!is_valid_evm_address($activeTokenContract)) {
                $message = '当前链未配置有效 ' . $selectedTokenSymbol . ' 合约地址';
                $messageType = 'danger';
            } else {
                $walletId = (int)($_POST['wallet_id'] ?? 0);
                $ok = 0;
                $fail = 0;
                $wallets = [];
                $t0 = microtime(true);
                if ($walletId > 0) {
                    $one = $db->fetch(
                        "SELECT w.*
                         FROM admin_derived_wallets w
                         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = w.id AND a.allocated_to_user_id = ?
                         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
                         WHERE w.id = ? AND w.chain_slug = ?
                         LIMIT 1",
                        [$user_id, $user_id, $walletId, $selectedChain]
                    );
                    if ($one) {
                        $wallets[] = $one;
                    }
                } else {
                    // Bulk refresh only for paid + unsettled addresses.
                    // Unallocated / unpaid / collected addresses can still be refreshed manually.
                    $wallets = $db->fetchAll(
                        "SELECT w.*
                         FROM admin_derived_wallets w
                         INNER JOIN (
                             SELECT a.wallet_id
                             FROM admin_fee_address_allocations a
                             INNER JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid' AND o.user_id = ?
                             GROUP BY a.wallet_id
                         ) p ON p.wallet_id = w.id
                         LEFT JOIN admin_collection_items ci ON ci.wallet_id = w.id AND ci.status = 'broadcasted'
                         WHERE w.chain_slug = ? AND w.status = 1
                           AND ci.id IS NULL
                         ORDER BY CAST(w.last_balance_wei AS DECIMAL(65,0)) DESC, w.id DESC
                         LIMIT 150",
                        [$user_id, $selectedChain]
                    );
                }
                if (count($wallets) === 1) {
                    $w = $wallets[0];
                    $wei = fetch_evm_usdt_balance($chainMeta['chain_id'], $activeTokenContract, $w['address'], $apiKey);
                    if ($wei === null) {
                        $fail++;
                    } else {
                        $display = format_by_decimals($wei, $chainMeta['decimals']);
                        $db->query("UPDATE admin_derived_wallets SET last_balance_wei = ?, last_balance_display = ?, updated_at = NOW() WHERE id = ?", [
                            $wei,
                            $display,
                            $w['id']
                        ]);
                        $ok++;
                    }
                } else {
                    $addresses = array_map(function ($w) { return (string)$w['address']; }, $wallets);
                    $balMap = fetch_evm_usdt_balances_parallel($chainMeta['chain_id'], $activeTokenContract, $addresses, $apiKey, 12);
                    foreach ($wallets as $w) {
                        $addr = (string)$w['address'];
                        if (!isset($balMap[$addr])) {
                            $fail++;
                            continue;
                        }
                        $wei = (string)$balMap[$addr];
                        $display = format_by_decimals($wei, $chainMeta['decimals']);
                        $db->query("UPDATE admin_derived_wallets SET last_balance_wei = ?, last_balance_display = ?, updated_at = NOW() WHERE id = ?", [
                            $wei,
                            $display,
                            $w['id']
                        ]);
                        $ok++;
                    }
                }
                $elapsed = round((microtime(true) - $t0), 2);
                $message = '余额刷新完成：成功 ' . $ok . '，失败 ' . $fail . '（耗时 ' . $elapsed . 's）';
            }
        }

        if ($action === 'create_batch' && $chainMeta) {
            $master = $masterCurrent;
            if (!is_valid_evm_address($master)) {
                $message = '请先配置该链主钱包地址';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } elseif (!is_valid_evm_address($activeTokenContract)) {
                $message = '当前链未配置有效 ' . $selectedTokenSymbol . ' 合约地址';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $minDisplay = (float)($_POST['min_amount'] ?? 0);
                if ($minDisplay < 0) $minDisplay = 0;
                $minWei = (string)max(0, (int)round($minDisplay * pow(10, $chainMeta['decimals'])));

                $wallets = $db->fetchAll(
                    "SELECT w.*,
                            COALESCE(p.paid_amount_display, 0) AS paid_amount_display,
                            COALESCE(c.collected_amount_display, 0) AS collected_amount_display,
                            (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) AS expected_uncollected_display
                     FROM admin_derived_wallets w
                     INNER JOIN admin_fee_address_allocations a ON a.wallet_id = w.id AND a.allocated_to_user_id = ?
                     LEFT JOIN (
                         SELECT a2.wallet_id, SUM(COALESCE(o.amount, 0)) AS paid_amount_display
                         FROM admin_fee_address_allocations a2
                         INNER JOIN orders o ON o.order_no = a2.order_no AND o.status = 'paid' AND o.user_id = ?
                           AND UPPER(COALESCE(o.currency,'USDT')) = ?
                         WHERE a2.allocated_to_user_id = ?
                         GROUP BY a2.wallet_id
                     ) p ON p.wallet_id = w.id
                     LEFT JOIN (
                         SELECT ci.wallet_id, SUM(COALESCE(ci.amount_display, 0)) AS collected_amount_display
                         FROM admin_collection_items ci
                         INNER JOIN admin_collection_batches cb ON cb.id = ci.batch_id
                         WHERE ci.status = 'broadcasted'
                           AND UPPER(COALESCE(cb.token_symbol,'USDT')) = ?
                         GROUP BY ci.wallet_id
                     ) c ON c.wallet_id = w.id
                     WHERE w.chain_slug = ? AND w.status = 1 AND w.address <> ?
                       AND (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) > 0
                     ORDER BY (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) DESC
                     LIMIT 500",
                    [$user_id, $user_id, $selectedTokenSymbol, $user_id, $selectedTokenSymbol, $selectedChain, $master]
                );
                $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
                $contractCandidates = $activeTokenCandidates;
                if (empty($contractCandidates) && is_valid_evm_address((string)$activeTokenContract)) {
                    $contractCandidates[] = strtolower((string)$activeTokenContract);
                }
                $contractCandidates = normalize_evm_contract_candidates($contractCandidates);
                $balanceByContract = [];
                if ($apiKey !== '' && !empty($wallets) && !empty($contractCandidates)) {
                    $addressesForScan = array_values(array_unique(array_map(function ($w) {
                        return strtolower((string)($w['address'] ?? ''));
                    }, $wallets)));
                    foreach ($contractCandidates as $contractAddr) {
                        $balanceByContract[strtolower($contractAddr)] = fetch_evm_usdt_balances_parallel(
                            (int)$chainMeta['chain_id'],
                            (string)$contractAddr,
                            $addressesForScan,
                            $apiKey,
                            12
                        );
                    }
                }

                $items = [];
                $totalDisplay = 0.0;
                foreach ($wallets as $w) {
                    $expectedDisplay = (float)($w['expected_uncollected_display'] ?? 0);
                    if ($expectedDisplay <= 0) continue;
                    $wei = decimal_to_units_str((string)$expectedDisplay, (int)$chainMeta['decimals']);
                    if (cmp_uint_str($wei, $minWei) < 0) {
                        continue;
                    }
                    $display = format_by_decimals($wei, $chainMeta['decimals']);
                    if ($display <= 0) {
                        continue;
                    }
                    $fromAddr = strtolower((string)$w['address']);
                    $selectedContract = strtolower((string)$activeTokenContract);
                    $selectedBalanceWei = '0';
                    if (!empty($balanceByContract)) {
                        foreach ($balanceByContract as $contractAddr => $balMap) {
                            $bal = (string)($balMap[$fromAddr] ?? '0');
                            if (cmp_uint_str($bal, $selectedBalanceWei) > 0) {
                                $selectedBalanceWei = $bal;
                                $selectedContract = strtolower((string)$contractAddr);
                            }
                        }
                    }
                    if (!is_valid_evm_address($selectedContract)) {
                        continue;
                    }
                    // 某些 RPC/API 查询会短时返回 0，不能直接丢弃应归集地址；
                    // 无余额探测结果时回退到订单应归集金额，确保批次完整。
                    $actualWei = $wei;
                    if (cmp_uint_str($selectedBalanceWei, '0') > 0 && cmp_uint_str($selectedBalanceWei, $actualWei) < 0) {
                        $actualWei = $selectedBalanceWei;
                    }
                    if (cmp_uint_str($actualWei, $minWei) < 0) {
                        continue;
                    }
                    $actualDisplay = format_by_decimals($actualWei, $chainMeta['decimals']);
                    if ($actualDisplay <= 0) {
                        continue;
                    }
                    $data = build_erc20_transfer_data($master, $actualWei);
                    $eip681 = 'ethereum:' . $selectedContract . '@' . $chainMeta['chain_id']
                        . '/transfer?address=' . $master . '&uint256=' . $actualWei;
                    $payload = [
                        'type' => 'uapi_sweep_evm_erc20',
                        'chain' => $selectedChain,
                        'chain_id' => $chainMeta['chain_id'],
                        'token_symbol' => $selectedTokenSymbol,
                        'token_contract' => $selectedContract,
                        'from' => $w['address'],
                        'to' => $master,
                        'amount_display' => $actualDisplay,
                        'amount_wei' => $actualWei,
                        'data' => $data,
                        'eip681' => $eip681,
                        'note' => '使用对应子地址签名，Ledger确认后广播',
                    ];
                    $items[] = [
                        'wallet_id' => (int)$w['id'],
                        'from' => $w['address'],
                        'to' => $master,
                        'wei' => $actualWei,
                        'display' => $actualDisplay,
                        'chain_id' => (int)$chainMeta['chain_id'],
                        'token_contract' => $selectedContract,
                        'data' => $data,
                        'derivation_path' => (string)($w['derivation_path'] ?? ''),
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'eip681' => $eip681,
                    ];
                    $totalDisplay += $actualDisplay;
                }

                if (empty($items)) {
                    $altRows = $db->fetchAll(
                        "SELECT UPPER(COALESCE(o.currency,'USDT')) AS ccy, COUNT(*) AS c
                         FROM orders o
                         INNER JOIN admin_fee_address_allocations a ON a.order_no = o.order_no AND a.allocated_to_user_id = ?
                         INNER JOIN admin_derived_wallets w ON w.id = a.wallet_id AND w.chain_slug = ?
                         WHERE o.user_id = ?
                           AND o.status = 'paid'
                           AND UPPER(COALESCE(o.currency,'USDT')) IN ('USDT','USDC')
                         GROUP BY UPPER(COALESCE(o.currency,'USDT'))",
                        [$user_id, $selectedChain, $user_id]
                    );
                    $altHint = '';
                    foreach ($altRows as $ar) {
                        $ccy = strtoupper((string)($ar['ccy'] ?? ''));
                        if ($ccy !== '' && $ccy !== strtoupper($selectedTokenSymbol) && (int)($ar['c'] ?? 0) > 0) {
                            $altHint = '；检测到该链有 ' . $ccy . ' 收款，请切换“归集币种”后重试';
                            break;
                        }
                    }
                    $message = '当前币种(' . strtoupper($selectedTokenSymbol) . ')没有达到阈值的可归集地址' . $altHint;
                    $messageType = 'warning';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } else {
                    $db->query(
                        "INSERT INTO admin_collection_batches
                        (chain_slug, chain_id, token_symbol, token_contract, token_decimals, master_address, total_items, total_amount_display, status, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())",
                        [
                            $selectedChain,
                            $chainMeta['chain_id'],
                            $selectedTokenSymbol,
                            $activeTokenContract,
                            $chainMeta['decimals'],
                            $master,
                            count($items),
                            $totalDisplay,
                        ]
                    );
                    $batchId = (int)$db->lastInsertId();
                    $ajaxBatchItems = [];
                    foreach ($items as $it) {
                        $db->query(
                            "INSERT INTO admin_collection_items
                            (batch_id, wallet_id, from_address, to_address, amount_wei, amount_display, qr_payload, eip681_uri, status, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_sign', NOW(), NOW())",
                            [
                                $batchId,
                                $it['wallet_id'],
                                $it['from'],
                                $it['to'],
                                $it['wei'],
                                $it['display'],
                                $it['payload'],
                                $it['eip681'],
                            ]
                        );
                        $itemId = (int)$db->lastInsertId();
                        $ajaxBatchItems[] = [
                            'item_id' => $itemId,
                            'chain' => (string)$selectedChain,
                            'chain_id' => (int)$it['chain_id'],
                            'from' => (string)$it['from'],
                            'to' => (string)$it['to'],
                            'token_symbol' => (string)$selectedTokenSymbol,
                            'token_contract' => (string)$it['token_contract'],
                            'amount_wei' => (string)$it['wei'],
                            'data' => (string)$it['data'],
                            'derivation_path' => (string)$it['derivation_path'],
                            'status' => 'pending_sign',
                        ];
                    }
                    $message = $selectedTokenSymbol . ' 归集批次已生成，批次 #' . $batchId . '，共 ' . count($items) . ' 笔';
                    $ajaxPayload = [
                        'ok' => true,
                        'message' => $message,
                        'batch_id' => $batchId,
                        'batch_items' => $ajaxBatchItems
                    ];
                }
            }
        }

        if ($action === 'rollback_collected_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            if ($itemId <= 0) {
                $message = '参数错误';
                $messageType = 'danger';
            } else {
                $item = $db->fetch(
                    "SELECT i.id, i.batch_id, i.tx_hash
                     FROM admin_collection_items i
                     INNER JOIN admin_collection_batches b ON b.id = i.batch_id AND b.chain_slug = ?
                     INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
                     INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
                     WHERE i.id = ? LIMIT 1",
                    [$selectedChain, $user_id, $user_id, $itemId]
                );
                if (!$item) {
                    $message = '记录不存在';
                    $messageType = 'danger';
                } elseif (trim((string)($item['tx_hash'] ?? '')) !== '') {
                    $message = '该记录已有交易哈希，归集已成功，不能回滚';
                    $messageType = 'danger';
                } else {
                    $db->query(
                        "UPDATE admin_collection_items
                         SET status='pending_sign', tx_hash=NULL, tx_error='手动回滚：等待重新归集', updated_at=NOW()
                         WHERE id=?",
                        [$itemId]
                    );
                    $db->query(
                        "UPDATE admin_collection_batches SET status='pending', updated_at=NOW() WHERE id=?",
                        [(int)$item['batch_id']]
                    );
                    $message = '已回滚到待归集，可重新执行';
                    $messageType = 'success';
                }
            }
        }

        if ($action === 'rollback_batch') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            if ($batchId <= 0) {
                $message = '参数错误';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                // Verify the batch belongs to this merchant via order ownership
                $batch = $db->fetch(
                    "SELECT b.id, b.status
                     FROM admin_collection_batches b
                     WHERE b.id = ? AND b.chain_slug = ?
                       AND EXISTS (
                           SELECT 1 FROM admin_collection_items i
                           INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
                           INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
                           WHERE i.batch_id = b.id
                       )
                     LIMIT 1",
                    [$batchId, $selectedChain, $user_id, $user_id]
                );
                if (!$batch) {
                    $message = '批次不存在或无权限';
                    $messageType = 'danger';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } elseif ((string)($batch['status'] ?? '') === 'completed') {
                    $message = '该批次已全部归集完成，无需回滚';
                    $messageType = 'warning';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } else {
                    // Smart rollback: detect items whose wallet was collected in another batch
                    $pendingItems = $db->fetchAll(
                        "SELECT i.id, i.wallet_id
                         FROM admin_collection_items i
                         WHERE i.batch_id = ? AND i.status != 'broadcasted'",
                        [$batchId]
                    );
                    $cancelCount = 0;
                    $resetCount = 0;
                    foreach ($pendingItems as $pi) {
                        $walletId = (int)$pi['wallet_id'];
                        // Check if this wallet was successfully collected in a DIFFERENT batch
                        $coveredBy = $db->fetch(
                            "SELECT i2.batch_id FROM admin_collection_items i2
                             WHERE i2.wallet_id = ? AND i2.batch_id != ? AND i2.status = 'broadcasted'
                             ORDER BY i2.batch_id DESC LIMIT 1",
                            [$walletId, $batchId]
                        );
                        if ($coveredBy) {
                            // Already swept in another batch — mark as cancelled
                            $db->query(
                                "UPDATE admin_collection_items
                                 SET status='failed', tx_hash=NULL,
                                     tx_error=CONCAT('已被批次 #', ?, ' 完成，本条目自动取消'),
                                     updated_at=NOW()
                                 WHERE id=?",
                                [(int)$coveredBy['batch_id'], (int)$pi['id']]
                            );
                            $cancelCount++;
                        } else {
                            // Genuinely uncollected — reset to pending_sign
                            $db->query(
                                "UPDATE admin_collection_items
                                 SET status='pending_sign', tx_hash=NULL,
                                     tx_error='批次回滚：等待重新签名归集', updated_at=NOW()
                                 WHERE id=?",
                                [(int)$pi['id']]
                            );
                            $resetCount++;
                        }
                    }
                    // Update batch status: if all items are now done/cancelled, mark completed
                    $remaining = $db->fetch(
                        "SELECT COUNT(*) AS c FROM admin_collection_items
                         WHERE batch_id = ? AND status NOT IN ('broadcasted', 'failed')",
                        [$batchId]
                    );
                    $newBatchStatus = ((int)($remaining['c'] ?? 0) === 0) ? 'completed' : 'pending';
                    $db->query(
                        "UPDATE admin_collection_batches SET status=?, updated_at=NOW() WHERE id=?",
                        [$newBatchStatus, $batchId]
                    );
                    $parts = [];
                    if ($resetCount > 0) $parts[] = "{$resetCount} 条已重置待签名";
                    if ($cancelCount > 0) $parts[] = "{$cancelCount} 条已被其他批次完成自动取消";
                    $message = "批次 #$batchId 回滚完成：" . implode('，', $parts ?: ['无待处理条目']);
                    $messageType = 'success';
                    $ajaxPayload = ['ok' => true, 'message' => $message, 'reset_count' => $resetCount, 'cancel_count' => $cancelCount];
                }
            }
        }

        if ($action === 'get_batch_items') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            if ($batchId <= 0) {
                $ajaxPayload = ['ok' => false, 'message' => '参数错误'];
            } else {
                $batch = $db->fetch(
                    "SELECT b.id, b.status, b.total_items, b.total_amount_display
                     FROM admin_collection_batches b
                     WHERE b.id = ? AND b.chain_slug = ?
                       AND EXISTS (
                           SELECT 1 FROM admin_collection_items i
                           INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
                           INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
                           WHERE i.batch_id = b.id
                       )
                     LIMIT 1",
                    [$batchId, $selectedChain, $user_id, $user_id]
                );
                if (!$batch) {
                    $ajaxPayload = ['ok' => false, 'message' => '批次不存在或无权限'];
                } else {
                    $items = $db->fetchAll(
                        "SELECT i.id, i.from_address, i.amount_display, i.status, i.tx_hash, i.tx_error
                         FROM admin_collection_items i
                         WHERE i.batch_id = ?
                         ORDER BY i.id ASC",
                        [$batchId]
                    );
                    $ajaxPayload = ['ok' => true, 'batch' => $batch, 'items' => $items];
                }
            }
        }

        if ($action === 'mark_sent') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $txHash = trim((string)($_POST['tx_hash'] ?? ''));
            if ($itemId <= 0 || !preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) {
                $message = '交易哈希格式不正确';
                $messageType = 'danger';
            } else {
                $ownedItem = $db->fetch(
                    "SELECT i.id
                     FROM admin_collection_items i
                     INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
                     INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
                     WHERE i.id = ? LIMIT 1",
                    [$user_id, $user_id, $itemId]
                );
                if (!$ownedItem) {
                    $message = '任务不存在或无权限';
                    $messageType = 'danger';
                } else {
                    $db->query("UPDATE admin_collection_items SET status = 'broadcasted', tx_hash = ?, updated_at = NOW() WHERE id = ?", [$txHash, $itemId]);
                    $message = '已标记为已广播';
                }
            }
        }

        if ($action === 'broadcast_signed') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $rawTx = trim((string)($_POST['signed_raw_tx'] ?? ''));
            if ($itemId <= 0 || !preg_match('/^0x[a-fA-F0-9]+$/', $rawTx)) {
                $message = '已签名原始交易格式不正确';
                $messageType = 'danger';
            } else {
                $item = $db->fetch(
                    "SELECT i.*, b.chain_id
                     FROM admin_collection_items i
                     JOIN admin_collection_batches b ON b.id = i.batch_id
                     INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
                     INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
                     WHERE i.id = ? LIMIT 1",
                    [$user_id, $user_id, $itemId]
                );
                if (!$item) {
                    $message = '任务不存在';
                    $messageType = 'danger';
                } elseif ((string)$item['status'] === 'broadcasted') {
                    $message = '该任务已广播';
                    $messageType = 'warning';
                } else {
                    $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
                    if ($apiKey === '') {
                        $message = '请先在系统设置中配置 EVM API Key';
                        $messageType = 'danger';
                    } else {
                        $ret = broadcast_evm_raw_tx((int)$item['chain_id'], $rawTx, $apiKey);
                        if (!empty($ret['ok'])) {
                            $txHash = (string)$ret['tx_hash'];
                            $db->query("UPDATE admin_collection_items SET status='broadcasted', tx_hash=?, updated_at=NOW() WHERE id=?", [$txHash, $itemId]);
                            $message = '广播成功：' . substr($txHash, 0, 12) . '...' . substr($txHash, -10);
                            $messageType = 'success';
                        } else {
                            $message = '广播失败：' . (string)($ret['error'] ?? '未知错误');
                            $messageType = 'danger';
                        }
                    }
                }
            }
        }

        if ($action === 'broadcast_signed_batch') {
            $input = trim((string)($_POST['signed_batch_json'] ?? ''));
            if ($input === '') {
                $message = '请粘贴批量签名结果 JSON';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $rows = json_decode($input, true);
                if (!is_array($rows)) {
                    $message = '批量签名结果 JSON 格式无效';
                    $messageType = 'danger';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } else {
                    $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
                    if ($apiKey === '') {
                        $message = '请先在系统设置中配置 EVM API Key';
                        $messageType = 'danger';
                        $ajaxPayload = ['ok' => false, 'message' => $message];
                    } else {
                        $ok = 0;
                        $skip = 0;
                        $fail = 0;
                        $fails = [];
                        $successes = [];
                        $touchedBatch = [];
                        foreach ($rows as $r) {
                            $itemId = (int)($r['item_id'] ?? 0);
                            $rawTx = trim((string)($r['signed_raw_tx'] ?? ''));
                            if ($itemId <= 0 || !preg_match('/^0x[a-fA-F0-9]+$/', $rawTx)) {
                                $skip++;
                                continue;
                            }
                            $item = $db->fetch(
                                "SELECT i.*, b.chain_id, b.token_contract AS batch_token_contract, b.token_symbol AS batch_token_symbol, b.token_decimals AS batch_token_decimals
                                 FROM admin_collection_items i
                                 JOIN admin_collection_batches b ON b.id = i.batch_id
                                 INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
                                 INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
                                 WHERE i.id = ? LIMIT 1",
                                [$user_id, $user_id, $itemId]
                            );
                            if (!$item || (string)$item['status'] === 'broadcasted') {
                                $skip++;
                                continue;
                            }
                            $touchedBatch[(int)$item['batch_id']] = true;
                            $tokenContract = resolve_item_token_contract($item, (string)($item['batch_token_contract'] ?? ''));
                            if (!is_valid_evm_address($tokenContract)) {
                                $fail++;
                                $reason = '#' . $itemId . ': token_contract 无效';
                                $fails[] = $reason;
                                $db->query("UPDATE admin_collection_items SET status='pending_sign', tx_error=?, updated_at=NOW() WHERE id=?", [$reason, $itemId]);
                                continue;
                            }
                            $itemTokenSymbol = strtoupper(trim((string)($item['batch_token_symbol'] ?? 'USDT')));
                            if (!in_array($itemTokenSymbol, ['USDT', 'USDC'], true)) {
                                $itemTokenSymbol = 'USDT';
                            }
                            $tokenDecimals = (int)($item['batch_token_decimals'] ?? ($chainMeta['decimals'] ?? 6));
                            $bal = fetch_evm_usdt_balance((int)$item['chain_id'], $tokenContract, (string)$item['from_address'], $apiKey);
                            if ($bal === null) {
                                $fail++;
                                $reason = '#' . $itemId . ': 读取链上' . $itemTokenSymbol . '余额失败（chain_id=' . (int)$item['chain_id'] . ', contract=' . $tokenContract . '）';
                                $fails[] = $reason;
                                $db->query("UPDATE admin_collection_items SET status='pending_sign', tx_error=?, updated_at=NOW() WHERE id=?", [$reason, $itemId]);
                                continue;
                            }
                            if (cmp_uint_str((string)$bal, (string)$item['amount_wei']) < 0) {
                                $altHit = '';
                                $needWeiStr = (string)$item['amount_wei'];
                                $candContracts = normalize_evm_contract_candidates((array)($chainMeta['tokens'][$itemTokenSymbol]['candidates'] ?? []));
                                foreach ($candContracts as $cand) {
                                    $candLc = strtolower(trim((string)$cand));
                                    if (!is_valid_evm_address($candLc) || $candLc === $tokenContract) continue;
                                    $candBal = fetch_evm_usdt_balance((int)$item['chain_id'], $candLc, (string)$item['from_address'], $apiKey);
                                    if ($candBal !== null && cmp_uint_str((string)$candBal, $needWeiStr) >= 0) {
                                        $altHit = $candLc;
                                        break;
                                    }
                                }
                                $fail++;
                                $reason = '#' . $itemId . ': 子地址余额不足，当前=' . format_by_decimals((string)$bal, $tokenDecimals) . ' 需=' . format_by_decimals((string)$item['amount_wei'], $tokenDecimals) . '（' . $itemTokenSymbol . ' 合约 ' . $tokenContract . '）';
                                if ($altHit !== '') {
                                    $reason .= '；检测到另一' . $itemTokenSymbol . '合约有余额 ' . $altHit . '，请回滚后重新生成任务并重签';
                                }
                                $fails[] = $reason;
                                $db->query("UPDATE admin_collection_items SET status='pending_sign', tx_error=?, updated_at=NOW() WHERE id=?", [$reason, $itemId]);
                                continue;
                            }
                            $ret = broadcast_evm_raw_tx((int)$item['chain_id'], $rawTx, $apiKey);
                            if (!empty($ret['ok'])) {
                                $txHash = (string)$ret['tx_hash'];
                                $receipt = wait_evm_tx_receipt((int)$item['chain_id'], $txHash, $apiKey, 10, 1200);
                                if (!is_array($receipt)) {
                                    $fail++;
                                    $reason = '#' . $itemId . ': 广播成功但未拿到回执（请稍后重试）';
                                    $fails[] = $reason;
                                    $db->query("UPDATE admin_collection_items SET status='pending_sign', tx_hash=?, tx_error=?, updated_at=NOW() WHERE id=?", [$txHash, $reason, $itemId]);
                                    continue;
                                }
                                if (strtolower((string)($receipt['status'] ?? '0x0')) !== '0x1') {
                                    $fail++;
                                    $reason = '#' . $itemId . ': 链上执行失败(revert)';
                                    $fails[] = $reason;
                                    $db->query("UPDATE admin_collection_items SET status='pending_sign', tx_hash=?, tx_error=?, updated_at=NOW() WHERE id=?", [$txHash, $reason, $itemId]);
                                    continue;
                                }
                                $okTransfer = has_expected_erc20_transfer(
                                    $receipt,
                                    $tokenContract,
                                    (string)$item['from_address'],
                                    (string)$item['to_address'],
                                    (string)$item['amount_wei']
                                );
                                if (!$okTransfer) {
                                    $fail++;
                                    $reason = '#' . $itemId . ': 回执无匹配Transfer日志，未确认到账';
                                    $fails[] = $reason;
                                    $db->query("UPDATE admin_collection_items SET status='pending_sign', tx_hash=?, tx_error=?, updated_at=NOW() WHERE id=?", [$txHash, $reason, $itemId]);
                                    continue;
                                }
                                $db->query("UPDATE admin_collection_items SET status='broadcasted', tx_hash=?, tx_error=NULL, updated_at=NOW() WHERE id=?", [$txHash, $itemId]);
                                $ok++;
                                $successes[] = [
                                    'item_id' => $itemId,
                                    'tx_hash' => $txHash,
                                    'from' => (string)$item['from_address'],
                                    'to' => (string)$item['to_address'],
                                    'token_contract' => $tokenContract
                                ];
                            } else {
                                $fail++;
                                $reason = '#' . $itemId . ': ' . (string)($ret['error'] ?? '未知错误');
                                $fails[] = $reason;
                                $db->query("UPDATE admin_collection_items SET status='pending_sign', tx_error=?, updated_at=NOW() WHERE id=?", [$reason, $itemId]);
                            }
                        }
                        foreach (array_keys($touchedBatch) as $bid) {
                            $left = $db->fetch("SELECT COUNT(*) AS c FROM admin_collection_items WHERE batch_id = ? AND status NOT IN ('broadcasted', 'failed')", [(int)$bid]);
                            $remaining = (int)($left['c'] ?? 0);
                            if ($remaining === 0) {
                                $hasFailed = (int)($db->fetch("SELECT COUNT(*) AS c FROM admin_collection_items WHERE batch_id = ? AND status = 'failed'", [(int)$bid])['c'] ?? 0);
                                $batchStatus = $hasFailed > 0 ? 'partial' : 'completed';
                            } else {
                                $batchStatus = 'pending';
                            }
                            $db->query(
                                "UPDATE admin_collection_batches SET status = ?, updated_at = NOW() WHERE id = ?",
                                [$batchStatus, (int)$bid]
                            );
                        }
                        $message = '批量广播完成：成功 ' . $ok . '，跳过 ' . $skip . '，失败 ' . $fail;
                        if (!empty($fails)) {
                            $message .= '；失败明细：' . implode(' | ', array_slice($fails, 0, 5));
                        }
                        $messageType = $fail > 0 ? 'warning' : 'success';
                        $ajaxPayload = [
                            'ok' => $fail === 0,
                            'message' => $message,
                            'ok_count' => $ok,
                            'skip_count' => $skip,
                            'fail_count' => $fail,
                            'fails' => $fails,
                            'successes' => $successes
                        ];
                    }
                }
            }
        }

        if ($action === 'broadcast_raw_batch') {
            $chainId = (int)($_POST['chain_id'] ?? 0);
            $rawJson = trim((string)($_POST['raw_txs_json'] ?? ''));
            if ($chainId <= 0 || $rawJson === '') {
                $message = '缺少链ID或原始交易数据';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $rows = json_decode($rawJson, true);
                if (!is_array($rows) || empty($rows)) {
                    $message = '原始交易 JSON 格式无效';
                    $messageType = 'danger';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } else {
                    $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
                    if ($apiKey === '') {
                        $message = '请先在系统设置中配置 EVM API Key';
                        $messageType = 'danger';
                        $ajaxPayload = ['ok' => false, 'message' => $message];
                    } else {
                        $ok = 0;
                        $fail = 0;
                        $errors = [];
                        $successes = [];
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
                                $successes[] = [
                                    'index' => $idx,
                                    'tx_hash' => (string)$ret['tx_hash']
                                ];
                            } else {
                                $fail++;
                                $errors[] = '第' . ($idx + 1) . '笔失败: ' . (string)($ret['error'] ?? '未知');
                            }
                        }
                        $message = '批量广播完成：成功 ' . $ok . '，失败 ' . $fail;
                        if (!empty($errors)) {
                            $message .= '；' . implode(' | ', array_slice($errors, 0, 5));
                        }
                        $messageType = $fail > 0 ? 'warning' : 'success';
                        $ajaxPayload = [
                            'ok' => $fail === 0,
                            'message' => $message,
                            'ok_count' => $ok,
                            'fail_count' => $fail,
                            'errors' => $errors,
                            'successes' => $successes
                        ];
                    }
                }
            }
        }

        if ($action === 'broadcast_raw_single') {
            $chainId = (int)($_POST['chain_id'] ?? 0);
            $rawTx = trim((string)($_POST['raw_tx'] ?? ''));
            $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
            if ($chainId <= 0 || !preg_match('/^0x[a-fA-F0-9]+$/', $rawTx) || $apiKey === '') {
                $ajaxPayload = ['ok' => false, 'message' => '参数错误'];
            } else {
                $ret = broadcast_evm_raw_tx($chainId, $rawTx, $apiKey);
                if (!empty($ret['ok'])) {
                    $ajaxPayload = ['ok' => true, 'tx_hash' => (string)$ret['tx_hash'], 'message' => '广播成功'];
                } else {
                    $ajaxPayload = ['ok' => false, 'message' => (string)($ret['error'] ?? '广播失败')];
                }
            }
        }

        if ($action === 'fetch_nonce') {
            $address = trim((string)($_POST['address'] ?? ''));
            $nonceHex = fetch_evm_tx_count((int)($evmChains[$selectedChain]['chain_id'] ?? 0), $address, trim((string)($sys['eth_api_key'] ?? '')));
            if ($nonceHex === null) {
                $message = '读取 nonce 失败';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $message = 'nonce 已读取: ' . $nonceHex;
                $ajaxPayload = ['ok' => true, 'message' => $message, 'nonce' => $nonceHex];
            }
        }

        if ($action === 'save_gas_profile') {
            $profile = trim((string)($_POST['gas_profile'] ?? 'evm_standard'));
            $path = trim((string)($_POST['gas_path'] ?? ''));
            $address = strtolower(trim((string)($_POST['gas_address'] ?? '')));
            $account = (int)($_POST['gas_account'] ?? 0);
            $index = (int)($_POST['gas_index'] ?? 0);
            if (!in_array($profile, ['evm_standard', 'ledger_live'], true)) {
                $message = '路径标准无效';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } elseif ($path === '' || !preg_match("/^m\/44'\/60'\/[0-9]+'\/[0-9]+\/[0-9]+$/", $path)) {
                $message = '派生路径格式无效';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } elseif (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
                $message = 'Gas钱包地址无效';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $gasProfileKey = scoped_setting_key('sweep_gas_profile_' . $selectedChain, $user_id);
                $gasPathKey = scoped_setting_key('sweep_gas_path_' . $selectedChain, $user_id);
                $gasAddrKey = scoped_setting_key('sweep_gas_address_' . $selectedChain, $user_id);
                $gasAccountKey = scoped_setting_key('sweep_gas_account_' . $selectedChain, $user_id);
                $gasIndexKey = scoped_setting_key('sweep_gas_index_' . $selectedChain, $user_id);
                upsert_setting($db, $gasProfileKey, $profile);
                upsert_setting($db, $gasPathKey, $path);
                upsert_setting($db, $gasAddrKey, $address);
                upsert_setting($db, $gasAccountKey, (string)$account);
                upsert_setting($db, $gasIndexKey, (string)$index);
                $sys[$gasProfileKey] = $profile;
                $sys[$gasPathKey] = $path;
                $sys[$gasAddrKey] = $address;
                $sys[$gasAccountKey] = (string)$account;
                $sys[$gasIndexKey] = (string)$index;
                $message = 'Gas钱包路径绑定成功（后续秒用）';
                $messageType = 'success';
                $ajaxPayload = ['ok' => true, 'message' => $message];
            }
        }

        } catch (Throwable $e) {
            $message = '操作失败：' . $e->getMessage();
            $messageType = 'danger';
            if ($isAjax) {
                $ajaxPayload = ['ok' => false, 'message' => $message];
            }
        }

        if ($isAjax && $ajaxPayload === null) {
            $ajaxPayload = [
                'ok' => false,
                'message' => $message !== '' ? $message : '请求未返回有效结果，请重试'
            ];
        }

        if ($isAjax && $ajaxPayload !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($ajaxPayload, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (!$isAjax) {
            $_SESSION['merchant_derived_flash'] = [
                'message' => (string)$message,
                'type' => (string)$messageType,
            ];
            $redir = ['chain' => (string)$selectedChain];
            if ($action === 'rollback_collected_item') {
                $redir['records_tab'] = 'total';
            }
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($redir));
            exit;
        }
    }
}

if (!empty($_SESSION['merchant_derived_flash']) && is_array($_SESSION['merchant_derived_flash'])) {
    $message = (string)($_SESSION['merchant_derived_flash']['message'] ?? '');
    $messageType = (string)($_SESSION['merchant_derived_flash']['type'] ?? 'success');
    unset($_SESSION['merchant_derived_flash']);
}

$listPerPage = 10;
$getPage = static function (string $name): int {
    $v = (int)($_GET[$name] ?? 1);
    return $v > 0 ? $v : 1;
};
$buildPageUrl = static function (array $overrides = []): string {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = (string)$v;
        }
    }
    $qs = http_build_query($q);
    return $qs === '' ? '?' : ('?' . $qs);
};
$derivedSearch = trim((string)($_GET['derived_kw'] ?? ''));
$derivedStatus = strtolower(trim((string)($_GET['derived_status'] ?? 'all')));
if (!in_array($derivedStatus, ['all', 'paid', 'pending', 'expired', 'failed'], true)) {
    $derivedStatus = 'all';
}
$pageBatch = $getPage('p_batch');
$pageTotal = $getPage('p_total');
$pageDerived = $getPage('p_derived');
$pageFailed = $getPage('p_failed');
$pageUnsettled = $getPage('p_unsettled');
$pageSide = $getPage('p_side');

$masterMap = [];
foreach ($evmChains as $slug => $meta) {
    $masterMap[$slug] = trim((string)get_scoped_setting($sys, 'sweep_master_' . $slug, $user_id, ''));
}

$xpubMap = [];
$pathMap = [];
$nextIndexMap = [];
foreach ($evmChains as $slug => $meta) {
    $xpubMap[$slug] = trim((string)get_scoped_setting($sys, 'sweep_xpub_' . $slug, $user_id, ''));
    $pathMap[$slug] = trim((string)get_scoped_setting($sys, 'sweep_path_' . $slug, $user_id, "m/44'/60'/0'/0"));
    $nextIndexMap[$slug] = max(0, (int)get_scoped_setting($sys, 'sweep_next_index_' . $slug, $user_id, 0));
}

// Chain pool summary for direct visibility.
$allPoolRows = $db->fetchAll(
    "SELECT w.chain_slug,
            COUNT(*) AS total_count,
            COUNT(DISTINCT CASE WHEN o.status = 'paid' THEN w.id ELSE NULL END) AS paid_count
     FROM admin_derived_wallets w
     INNER JOIN admin_fee_address_allocations a ON a.wallet_id = w.id AND a.allocated_to_user_id = ?
     INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
     WHERE w.status = 1
     GROUP BY w.chain_slug"
    ,
    [$user_id, $user_id]
);
$poolSummary = [];
foreach ($allPoolRows as $r) {
    $poolSummary[(string)$r['chain_slug']] = [
        'total' => (int)($r['total_count'] ?? 0),
        'available' => (int)($r['paid_count'] ?? 0),
    ];
}

$wallets = [];
if (!empty($selectedChain)) {
    // No automatic chain scan on page load. Use order/collection data as primary display,
    // and keep chain querying only for manual refresh actions.

    $wallets = $db->fetchAll(
        "SELECT w.*,
                CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS is_allocated,
                CASE WHEN COALESCE(p.paid_amount_display, 0) > 0 THEN 1 ELSE 0 END AS is_paid,
                CASE WHEN COALESCE(c.collected_amount_display, 0) > 0
                          AND (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) <= 0
                     THEN 1 ELSE 0 END AS is_collected,
                COALESCE(p.paid_amount_display, 0) AS paid_amount_display,
                COALESCE(c.collected_amount_display, 0) AS collected_amount_display,
                COALESCE(op.order_no, '') AS latest_paid_order_no,
                COALESCE(UPPER(op.currency), '') AS latest_paid_currency,
                lp.latest_paid_at
         FROM admin_derived_wallets w
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = w.id AND a.allocated_to_user_id = ?
         INNER JOIN orders ou ON ou.order_no = a.order_no AND ou.user_id = ?
         LEFT JOIN (
             SELECT a.wallet_id,
                    SUM(COALESCE(o.amount, 0)) AS paid_amount_display
             FROM admin_fee_address_allocations a
             INNER JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid' AND o.user_id = ?
             WHERE a.allocated_to_user_id = ?
             GROUP BY a.wallet_id
         ) p ON p.wallet_id = w.id
         LEFT JOIN (
             SELECT wallet_id, SUM(COALESCE(amount_display, 0)) AS collected_amount_display
             FROM admin_collection_items
             WHERE status = 'broadcasted'
             GROUP BY wallet_id
         ) c ON c.wallet_id = w.id
         LEFT JOIN (
             SELECT a.wallet_id,
                    MAX(o.id) AS latest_paid_order_id,
                    MAX(o.updated_at) AS latest_paid_at
             FROM admin_fee_address_allocations a
             INNER JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid' AND o.user_id = ?
             WHERE a.allocated_to_user_id = ?
             GROUP BY a.wallet_id
         ) lp ON lp.wallet_id = w.id
         LEFT JOIN orders op ON op.id = lp.latest_paid_order_id
         WHERE w.chain_slug = ? AND w.status = 1
         ORDER BY (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) DESC, w.id DESC
         LIMIT 300",
        [$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $selectedChain]
    );
}
$walletCount = count($wallets);
$paidUnsettledWallets = [];
$unpaidUnsettledWallets = [];
$collectedWallets = [];
$unallocatedWallets = [];
foreach ($wallets as $w) {
    $pendingAmount = (float)($w['paid_amount_display'] ?? 0) - (float)($w['collected_amount_display'] ?? 0);
    if ($pendingAmount < 0) $pendingAmount = 0.0;
    $w['pending_amount_display'] = $pendingAmount;
    $w['effective_balance_display'] = $pendingAmount > 0 ? $pendingAmount : (float)($w['last_balance_display'] ?? 0);
    if ((float)$pendingAmount <= 0 && (float)($w['collected_amount_display'] ?? 0) > 0) {
        $collectedWallets[] = $w;
    } elseif ((int)($w['is_allocated'] ?? 0) === 1) {
        if ((float)$pendingAmount > 0) {
            $paidUnsettledWallets[] = $w;
        } else {
            $unpaidUnsettledWallets[] = $w;
        }
    } else {
        $unallocatedWallets[] = $w;
    }
}
$paidUnsettledCount = count($paidUnsettledWallets);
$unpaidUnsettledCount = count($unpaidUnsettledWallets);
$collectedCount = count($collectedWallets);
$unallocatedCount = count($unallocatedWallets);
$paidUnsettledAmount = array_reduce($paidUnsettledWallets, function ($sum, $w) {
    return $sum + (float)($w['effective_balance_display'] ?? 0);
}, 0.0);
$sideTotal = count($paidUnsettledWallets);
$sidePages = max(1, (int)ceil($sideTotal / $listPerPage));
if ($pageSide > $sidePages) $pageSide = $sidePages;
$sideOffset = ($pageSide - 1) * $listPerPage;
$paidUnsettledWalletsPaged = array_slice($paidUnsettledWallets, $sideOffset, $listPerPage);

$unsettledTotal = count($paidUnsettledWallets);
$unsettledPages = max(1, (int)ceil($unsettledTotal / $listPerPage));
if ($pageUnsettled > $unsettledPages) $pageUnsettled = $unsettledPages;
$unsettledOffset = ($pageUnsettled - 1) * $listPerPage;
$unsettledWithBalancePaged = array_slice($paidUnsettledWallets, $unsettledOffset, $listPerPage);

// Per-currency counts for tab display and auto-detection
$unsettledCurrencyCounts = ['USDT' => 0, 'USDC' => 0];
foreach ($paidUnsettledWallets as $_uw) {
    $ccy = strtoupper(trim((string)($_uw['latest_paid_currency'] ?? 'USDT')));
    if ($ccy === '') $ccy = 'USDT';
    if (isset($unsettledCurrencyCounts[$ccy])) $unsettledCurrencyCounts[$ccy]++;
}
// Auto-detect: if user hasn't explicitly picked a token this request, suggest the dominant one
$autoToken = $unsettledCurrencyCounts['USDC'] > $unsettledCurrencyCounts['USDT'] ? 'USDC' : 'USDT';

$apiConfigured = trim((string)($sys['eth_api_key'] ?? '')) !== '';

$latestBatch = null;
$latestItems = [];
$allBatches = [];
$allBatchesTotal = 0;
$allBatchesPages = 1;
$batchStats = [
    'total_batches' => 0,
    'total_items' => 0,
    'total_amount' => 0,
    'broadcasted_items' => 0,
    'today_amount' => 0,
    'total_usdt' => 0,
    'total_usdc' => 0,
    'today_usdt' => 0,
    'today_usdc' => 0,
];
if (!empty($selectedChain)) {
    $staleRows = $db->fetchAll(
        "SELECT i.id
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id AND b.chain_slug = ?
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE i.status = 'broadcasted' AND (i.tx_hash IS NULL OR i.tx_hash = '')
         ORDER BY i.id DESC
         LIMIT 50",
        [$selectedChain, $user_id, $user_id]
    );
    foreach ($staleRows as $sr) {
        $db->query(
            "UPDATE admin_collection_items
             SET status='pending_sign', tx_error='自动回滚：缺少链上哈希，待重新归集', updated_at=NOW()
             WHERE id=?",
            [(int)$sr['id']]
        );
    }

    $batchStatsRow = $db->fetch(
        "SELECT COUNT(DISTINCT b.id) AS total_batches,
                COALESCE(COUNT(i.id), 0) AS total_items,
                COALESCE(SUM(CASE WHEN i.status='broadcasted' THEN i.amount_display ELSE 0 END), 0) AS total_amount
         FROM admin_collection_batches b
         INNER JOIN admin_collection_items i ON i.batch_id = b.id
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE b.chain_slug = ?",
        [$user_id, $user_id, $selectedChain]
    );
    $broadcastedRow = $db->fetch(
        "SELECT COUNT(*) AS c
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE b.chain_slug = ? AND i.status = 'broadcasted'",
        [$user_id, $user_id, $selectedChain]
    );
    $batchStats = [
        'total_batches' => (int)($batchStatsRow['total_batches'] ?? 0),
        'total_items' => (int)($batchStatsRow['total_items'] ?? 0),
        'total_amount' => (float)($batchStatsRow['total_amount'] ?? 0),
        'broadcasted_items' => (int)($broadcastedRow['c'] ?? 0),
        'today_amount' => 0,
        'total_usdt' => 0,
        'total_usdc' => 0,
        'today_usdt' => 0,
        'today_usdc' => 0,
    ];
    $amountByTokenRows = $db->fetchAll(
        "SELECT UPPER(COALESCE(b.token_symbol, 'USDT')) AS token_symbol,
                COALESCE(SUM(CASE WHEN i.status='broadcasted' THEN i.amount_display ELSE 0 END), 0) AS total_amount,
                COALESCE(SUM(CASE WHEN i.status='broadcasted' AND DATE(i.updated_at)=CURDATE() THEN i.amount_display ELSE 0 END), 0) AS today_amount
         FROM admin_collection_batches b
         INNER JOIN admin_collection_items i ON i.batch_id = b.id
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE b.chain_slug = ?
         GROUP BY UPPER(COALESCE(b.token_symbol, 'USDT'))",
        [$user_id, $user_id, $selectedChain]
    );
    foreach ($amountByTokenRows as $tokenRow) {
        $symbol = strtoupper(trim((string)($tokenRow['token_symbol'] ?? 'USDT')));
        $totalAmt = (float)($tokenRow['total_amount'] ?? 0);
        $todayAmt = (float)($tokenRow['today_amount'] ?? 0);
        if ($symbol === 'USDT') {
            $batchStats['total_usdt'] = $totalAmt;
            $batchStats['today_usdt'] = $todayAmt;
        } elseif ($symbol === 'USDC') {
            $batchStats['total_usdc'] = $totalAmt;
            $batchStats['today_usdc'] = $todayAmt;
        }
    }
    $batchStats['total_amount'] = (float)$batchStats['total_usdt'] + (float)$batchStats['total_usdc'];
    $batchStats['today_amount'] = (float)$batchStats['today_usdt'] + (float)$batchStats['today_usdc'];

    $latestBatch = $db->fetch(
        "SELECT b.*
         FROM admin_collection_batches b
         INNER JOIN admin_collection_items i ON i.batch_id = b.id
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE b.chain_slug = ?
         GROUP BY b.id
         ORDER BY b.id DESC
         LIMIT 1",
        [$user_id, $user_id, $selectedChain]
    );
    if ($latestBatch) {
        $latestItems = $db->fetchAll(
            "SELECT i.*, w.derivation_path
             FROM admin_collection_items i
             LEFT JOIN admin_derived_wallets w ON w.id = i.wallet_id
             INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
             INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
             WHERE i.batch_id = ?
             ORDER BY i.id ASC
             LIMIT 200",
            [$user_id, $user_id, $latestBatch['id']]
        );
    }
    $batchCountRow = $db->fetch(
        "SELECT COUNT(DISTINCT b.id) AS c
         FROM admin_collection_batches b
         LEFT JOIN admin_collection_items i ON i.batch_id = b.id
         LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         LEFT JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE b.chain_slug = ? AND a.id IS NOT NULL",
        [$user_id, $user_id, $selectedChain]
    );
    $allBatchesTotal = (int)($batchCountRow['c'] ?? 0);
    $allBatchesPages = max(1, (int)ceil($allBatchesTotal / $listPerPage));
    if ($pageBatch > $allBatchesPages) $pageBatch = $allBatchesPages;
    $batchOffset = ($pageBatch - 1) * $listPerPage;
    $allBatches = $db->fetchAll(
        "SELECT b.id, b.chain_slug, b.token_symbol, b.total_items, b.total_amount_display, b.status, b.created_at, b.updated_at,
                COALESCE(SUM(CASE WHEN i.status = 'broadcasted' THEN 1 ELSE 0 END),0) AS done_items,
                COALESCE(SUM(CASE WHEN i.status NOT IN ('broadcasted','failed')
                                   AND EXISTS (SELECT 1 FROM admin_collection_items i2
                                               WHERE i2.wallet_id = i.wallet_id
                                                 AND i2.batch_id != b.id
                                                 AND i2.status = 'broadcasted')
                              THEN 1 ELSE 0 END), 0) AS superseded_items,
                COALESCE(SUM(CASE WHEN i.status NOT IN ('broadcasted','failed') THEN 1 ELSE 0 END), 0) AS pending_items
         FROM admin_collection_batches b
         LEFT JOIN admin_collection_items i ON i.batch_id = b.id
         LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         LEFT JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE b.chain_slug = ? AND a.id IS NOT NULL
         GROUP BY b.id
         ORDER BY b.id DESC
         LIMIT $listPerPage OFFSET $batchOffset",
        [$user_id, $user_id, $selectedChain]
    );
}

$allCollectedRecords = [];
$failedCollectedRecords = [];
$allDerivedRecords = [];
$allCollectedTotal = 0;
$allCollectedPages = 1;
$failedCollectedTotal = 0;
$failedCollectedPages = 1;
$allDerivedTotal = 0;
$allDerivedPages = 1;
if (!empty($selectedChain)) {
    $countTotalRow = $db->fetch(
        "SELECT COUNT(*) AS c
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id AND b.chain_slug = ?
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE i.status = 'broadcasted'",
        [$selectedChain, $user_id, $user_id]
    );
    $allCollectedTotal = (int)($countTotalRow['c'] ?? 0);
    $allCollectedPages = max(1, (int)ceil($allCollectedTotal / $listPerPage));
    if ($pageTotal > $allCollectedPages) $pageTotal = $allCollectedPages;
    $totalOffset = ($pageTotal - 1) * $listPerPage;
    $allCollectedRecords = $db->fetchAll(
        "SELECT i.id,
                i.updated_at,
                i.from_address,
                i.to_address,
                i.amount_display,
                i.tx_hash,
                i.eip681_uri,
                b.token_symbol,
                b.id AS batch_id
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id AND b.chain_slug = ?
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE i.status = 'broadcasted'
         ORDER BY i.id DESC
         LIMIT $listPerPage OFFSET $totalOffset",
        [$selectedChain, $user_id, $user_id]
    );
    $countFailedRow = $db->fetch(
        "SELECT COUNT(*) AS c
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id AND b.chain_slug = ?
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE i.status <> 'broadcasted' AND COALESCE(i.tx_error, '') <> ''",
        [$selectedChain, $user_id, $user_id]
    );
    $failedCollectedTotal = (int)($countFailedRow['c'] ?? 0);
    $failedCollectedPages = max(1, (int)ceil($failedCollectedTotal / $listPerPage));
    if ($pageFailed > $failedCollectedPages) $pageFailed = $failedCollectedPages;
    $failedOffset = ($pageFailed - 1) * $listPerPage;
    $failedCollectedRecords = $db->fetchAll(
        "SELECT i.updated_at,
                i.from_address,
                i.to_address,
                i.amount_display,
                i.tx_error,
                b.id AS batch_id,
                b.token_symbol
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id AND b.chain_slug = ?
         INNER JOIN admin_fee_address_allocations a ON a.wallet_id = i.wallet_id AND a.allocated_to_user_id = ?
         INNER JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE i.status <> 'broadcasted' AND COALESCE(i.tx_error, '') <> ''
         ORDER BY i.id DESC
         LIMIT $listPerPage OFFSET $failedOffset",
        [$selectedChain, $user_id, $user_id]
    );

    $derivedParams = [$user_id, $user_id, $selectedChain];
    $derivedWhere = '';
    if ($derivedStatus !== 'all') {
        $derivedWhere .= " AND COALESCE(o.status, 'pending') = ?";
        $derivedParams[] = $derivedStatus;
    }
    if ($derivedSearch !== '') {
        $like = '%' . $derivedSearch . '%';
        $derivedWhere .= " AND (w.address LIKE ? OR a.order_no LIKE ? OR COALESCE(o.merchant_order_id, '') LIKE ?)";
        array_push($derivedParams, $like, $like, $like);
    }
    $derivedCountParams = $derivedParams;
    $derivedCountRow = $db->fetch(
        "SELECT COUNT(*) AS c
         FROM admin_fee_address_allocations a
         INNER JOIN admin_derived_wallets w ON w.id = a.wallet_id
         LEFT JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE a.allocated_to_user_id = ?
           AND w.chain_slug = ? $derivedWhere",
        $derivedCountParams
    );
    $allDerivedTotal = (int)($derivedCountRow['c'] ?? 0);
    $allDerivedPages = max(1, (int)ceil($allDerivedTotal / $listPerPage));
    if ($pageDerived > $allDerivedPages) $pageDerived = $allDerivedPages;
    $derivedOffset = ($pageDerived - 1) * $listPerPage;
    $allDerivedRecords = $db->fetchAll(
        "SELECT a.id AS alloc_id,
                a.wallet_id,
                a.address AS allocated_address,
                a.order_no,
                a.allocated_at,
                w.derivation_path,
                w.created_at AS wallet_created_at,
                o.merchant_order_id,
                o.status AS payment_status,
                o.amount,
                o.currency,
                o.created_at AS order_created_at,
                o.updated_at AS order_updated_at
         FROM admin_fee_address_allocations a
         INNER JOIN admin_derived_wallets w ON w.id = a.wallet_id
         LEFT JOIN orders o ON o.order_no = a.order_no AND o.user_id = ?
         WHERE a.allocated_to_user_id = ?
           AND w.chain_slug = ? $derivedWhere
         ORDER BY a.id DESC
         LIMIT $listPerPage OFFSET $derivedOffset",
        $derivedParams
    );
}

$gasDefaultsByChain = [
    // Values are tuned for non-wasteful ERC20 collection topups by chain characteristics.
    'eth' => [
        'topup_wei' => '1200000000000000', // 0.0012 ETH
        'gas_price_wei' => '700000000', // 0.7 gwei
        'dyn_min_topup' => '0.0008',
        'dyn_max_topup' => '0.0030',
        'dyn_retry_extra' => '0.0005',
        'dyn_min_gas_gwei' => '0.7',
        'dyn_safety_factor' => '1.60',
        'dyn_default_sweep_gas_limit' => '120000'
    ],
    'polygon' => [
        'topup_wei' => '30000000000000000', // 0.03 POL
        'gas_price_wei' => '25000000000', // 25 gwei
        'dyn_min_topup' => '0.03',
        'dyn_max_topup' => '0.12',
        'dyn_retry_extra' => '0.015',
        'dyn_min_gas_gwei' => '25',
        'dyn_safety_factor' => '1.70',
        'dyn_default_sweep_gas_limit' => '100000'
    ],
    'avalanche' => [
        'topup_wei' => '5000000000000000', // 0.005 AVAX
        'gas_price_wei' => '25000000000', // 25 gwei
        'dyn_min_topup' => '0.005',
        'dyn_max_topup' => '0.02',
        'dyn_retry_extra' => '0.003',
        'dyn_min_gas_gwei' => '20',
        'dyn_safety_factor' => '1.55',
        'dyn_default_sweep_gas_limit' => '100000'
    ],
    'arbitrum' => [
        'topup_wei' => '300000000000000', // 0.0003 ETH
        'gas_price_wei' => '50000000', // 0.05 gwei
        'dyn_min_topup' => '0.0002',
        'dyn_max_topup' => '0.0008',
        'dyn_retry_extra' => '0.00015',
        'dyn_min_gas_gwei' => '0.05',
        'dyn_safety_factor' => '1.45',
        'dyn_default_sweep_gas_limit' => '100000'
    ],
    'optimism' => [
        'topup_wei' => '350000000000000', // 0.00035 ETH
        'gas_price_wei' => '50000000', // 0.05 gwei
        'dyn_min_topup' => '0.00025',
        'dyn_max_topup' => '0.0010',
        'dyn_retry_extra' => '0.0002',
        'dyn_min_gas_gwei' => '0.05',
        'dyn_safety_factor' => '1.50',
        'dyn_default_sweep_gas_limit' => '100000'
    ],
    'base' => [
        'topup_wei' => '300000000000000', // 0.0003 ETH
        'gas_price_wei' => '50000000', // 0.05 gwei
        'dyn_min_topup' => '0.0002',
        'dyn_max_topup' => '0.0008',
        'dyn_retry_extra' => '0.00015',
        'dyn_min_gas_gwei' => '0.05',
        'dyn_safety_factor' => '1.45',
        'dyn_default_sweep_gas_limit' => '100000'
    ],
    'bsc' => [
        'topup_wei' => '200000000000000', // 0.0002 BNB
        'gas_price_wei' => '1000000000', // 1 gwei
        'dyn_min_topup' => '0.00015',
        'dyn_max_topup' => '0.0006',
        'dyn_retry_extra' => '0.0001',
        'dyn_min_gas_gwei' => '1',
        'dyn_safety_factor' => '1.40',
        'dyn_default_sweep_gas_limit' => '100000'
    ]
];
$gasDefaultProfile = $gasDefaultsByChain[$selectedChain] ?? [
    'topup_wei' => '300000000000000',
    'gas_price_wei' => '1000000000',
    'dyn_min_topup' => '0.00025',
    'dyn_max_topup' => '0.0008',
    'dyn_retry_extra' => '0.0002',
    'dyn_min_gas_gwei' => '1',
    'dyn_safety_factor' => '1.45',
    'dyn_default_sweep_gas_limit' => '100000'
];

$gasFunderAddress = trim((string)get_scoped_setting($sys, 'sweep_gas_funder_' . $selectedChain, $user_id, ''));
$gasProfile = trim((string)get_scoped_setting($sys, 'sweep_gas_profile_' . $selectedChain, $user_id, 'evm_standard'));
$savedGasPath = trim((string)get_scoped_setting($sys, 'sweep_gas_path_' . $selectedChain, $user_id, "m/44'/60'/0'/0/0"));
$savedGasAddress = trim((string)get_scoped_setting($sys, 'sweep_gas_address_' . $selectedChain, $user_id, ''));
$savedGasAccount = (int)get_scoped_setting($sys, 'sweep_gas_account_' . $selectedChain, $user_id, 0);
$savedGasIndex = (int)get_scoped_setting($sys, 'sweep_gas_index_' . $selectedChain, $user_id, 0);
$gasTopupWei = trim((string)get_scoped_setting($sys, 'sweep_gas_topup_wei_' . $selectedChain, $user_id, (string)$gasDefaultProfile['topup_wei']));
if (!preg_match('/^[0-9]+$/', $gasTopupWei)) {
    $gasTopupWei = (string)$gasDefaultProfile['topup_wei'];
}
$defaultGasPriceWei = trim((string)get_scoped_setting($sys, 'sweep_gas_price_wei_' . $selectedChain, $user_id, (string)$gasDefaultProfile['gas_price_wei']));
if (!preg_match('/^[0-9]+$/', $defaultGasPriceWei)) {
    $defaultGasPriceWei = (string)$gasDefaultProfile['gas_price_wei'];
}
$gasTopupCoinDisplay = rtrim(rtrim(number_format((float)format_by_decimals($gasTopupWei, 18), 6, '.', ''), '0'), '.');
if ($gasTopupCoinDisplay === '') $gasTopupCoinDisplay = '0.001';
$gasPriceGweiDisplay = rtrim(rtrim(number_format((float)format_by_decimals($defaultGasPriceWei, 9), 3, '.', ''), '0'), '.');
if ($gasPriceGweiDisplay === '') $gasPriceGweiDisplay = '1';
$sweepFeeCoinDisplay = rtrim(rtrim(number_format((float)format_by_decimals((string)((int)$defaultGasPriceWei * 90000), 18), 6, '.', ''), '0'), '.');
if ($sweepFeeCoinDisplay === '') $sweepFeeCoinDisplay = '0.00009';
$topupFeeCoinDisplay = rtrim(rtrim(number_format((float)format_by_decimals((string)((int)$defaultGasPriceWei * 21000), 18), 6, '.', ''), '0'), '.');
if ($topupFeeCoinDisplay === '') $topupFeeCoinDisplay = '0.000021';
$dynSafetyFactorDefault = (string)($gasDefaultProfile['dyn_safety_factor'] ?? '1.45');
$dynMinTopupDefault = (string)($gasDefaultProfile['dyn_min_topup'] ?? '0.00025');
$dynMaxTopupDefault = (string)($gasDefaultProfile['dyn_max_topup'] ?? '0.0008');
$dynRetryExtraDefault = (string)($gasDefaultProfile['dyn_retry_extra'] ?? '0.0002');
$dynMinGasGweiDefault = (string)($gasDefaultProfile['dyn_min_gas_gwei'] ?? $gasPriceGweiDisplay);
$dynDefaultSweepGasLimit = (string)($gasDefaultProfile['dyn_default_sweep_gas_limit'] ?? '100000');
$nativeCoinSymbol = strtoupper((string)($evmChains[$selectedChain]['symbol'] ?? 'COIN'));
$availablePoolCurrent = (int)(($poolSummary[$selectedChain]['available'] ?? 0));
$gasFunderNonceHex = null;
if ($gasFunderAddress !== '') {
    $apiKeyTmp = trim((string)($sys['eth_api_key'] ?? ''));
    $chainIdTmp = (int)($evmChains[$selectedChain]['chain_id'] ?? 0);
    if ($apiKeyTmp !== '' && $chainIdTmp > 0) {
        $gasFunderNonceHex = fetch_evm_tx_count($chainIdTmp, $gasFunderAddress, $apiKeyTmp);
    }
}

if (!empty($selectedChain)) {
    upsert_setting($db, $merchantLastChainKey, (string)$selectedChain);
    $sys[$merchantLastChainKey] = (string)$selectedChain;
}

$active_menu = 'derived_wallets';
$page_title = __('merchant.nav.derived_wallets');
$admin_csrf_token = (string)$merchant_csrf_token;
$unsettledWithBalance = $unsettledWithBalancePaged;
$allCollectedRecords = (isset($allCollectedRecords) && is_array($allCollectedRecords)) ? $allCollectedRecords : [];
$allDerivedRecords = (isset($allDerivedRecords) && is_array($allDerivedRecords)) ? $allDerivedRecords : [];
$failedCollectedRecords = (isset($failedCollectedRecords) && is_array($failedCollectedRecords)) ? $failedCollectedRecords : [];
require_once __DIR__ . '/includes/merchant_derived_header.php';
?>
<!-- Inject Tailwind via CDN with Prefix to avoid Bootstrap conflicts -->
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
          dark: '#1f2937',
          light: '#f9fafb'
        },
        fontFamily: {
            sans: ['Inter', 'system-ui', 'sans-serif'],
            mono: ['SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace'],
        }
      }
    }
  }
</script>
<style>
    /* Custom Scrollbar for console */
    .tw-scrollbar-thin::-webkit-scrollbar { width: 6px; height: 6px; }
    .tw-scrollbar-thin::-webkit-scrollbar-track { background: transparent; }
    .tw-scrollbar-thin::-webkit-scrollbar-thumb { background: #4b5563; border-radius: 3px; }
    .tw-scrollbar-thin::-webkit-scrollbar-thumb:hover { background: #6b7280; }
    
    /* Stepper */
    .stepper-item.active .stepper-circle { @apply tw-bg-primary tw-text-white tw-border-primary; }
    .stepper-item.completed .stepper-circle { @apply tw-bg-success tw-text-white tw-border-success; }
    .stepper-item.completed .stepper-line { @apply tw-bg-success; }
    .flow-progress-track {
        background: linear-gradient(90deg, rgba(59,130,246,.08), rgba(16,185,129,.08));
        border: 1px solid rgba(148,163,184,.28);
    }
    .flow-progress-bar {
        background: linear-gradient(90deg, #3b82f6 0%, #22c55e 70%, #10b981 100%);
    }
</style>

<div class="tw-font-sans tw-text-gray-800 tw-antialiased tw-min-h-screen tw-bg-gray-50 dark:tw-bg-gray-900 dark:tw-text-gray-100 tw-px-0 tw-pt-1 tw-pb-5 md:tw-pt-2 md:tw-pb-6">
    
    <!-- Top Stats / Header -->
    <div class="tw-flex tw-flex-col md:tw-flex-row tw-justify-between tw-items-center tw-mb-5 tw-gap-4">
        <div>
            <h1 class="tw-text-2xl tw-font-bold tw-tracking-tight tw-text-gray-900 dark:tw-text-white"><?php echo __('merchant.derived.title'); ?></h1>
            <p class="tw-text-sm tw-text-gray-500 dark:tw-text-gray-400">Command Center · <?php echo htmlspecialchars($evmChains[$selectedChain]['name']); ?></p>
        </div>
        <div class="tw-flex tw-items-end tw-gap-3">
             <div class="tw-flex tw-flex-col">
                <div class="tw-text-[11px] tw-text-gray-500 tw-mb-1"><?php echo __('merchant.derived.current_network'); ?></div>
                <div class="tw-relative">
                <select onchange="jumpChain(this.value)" class="tw-appearance-none tw-bg-white dark:tw-bg-gray-800 tw-border tw-border-gray-300 dark:tw-border-gray-700 tw-text-gray-700 dark:tw-text-gray-200 tw-py-2 tw-pl-4 tw-pr-10 tw-rounded-lg tw-shadow-sm tw-text-sm focus:tw-outline-none focus:tw-ring-2 focus:tw-ring-primary">
                    <?php foreach ($evmChains as $slug => $meta): ?>
                        <option value="<?php echo htmlspecialchars($slug); ?>" <?php echo $selectedChain === $slug ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($meta['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="tw-pointer-events-none tw-absolute tw-inset-y-0 tw-right-0 tw-flex tw-items-center tw-px-2 tw-text-gray-500">
                    <svg class="tw-w-4 tw-h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </div>
                </div>
            </div>
            <button onclick="location.reload()" class="tw-h-[42px] tw-w-[42px] tw-flex tw-items-center tw-justify-center tw-shrink-0 tw-bg-white dark:tw-bg-gray-800 tw-border tw-border-gray-300 dark:tw-border-gray-700 tw-rounded-lg tw-shadow-sm hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700 tw-transition-colors">
                <svg class="tw-w-5 tw-h-5 tw-text-gray-600 dark:tw-text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </button>
        </div>
    </div>

    <?php if ($derived2faRequired): ?>
    <input id="derivedSecurityOtp" type="hidden" value="">
    <?php endif; ?>

    <!-- Dashboard Grid -->
    <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-4 tw-gap-4 tw-mb-6">
        <!-- Stat Card 1 -->
        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-p-5 tw-shadow-sm tw-border tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
            <div>
                <p class="tw-text-sm tw-font-medium tw-text-gray-500 dark:tw-text-gray-400"><?php echo __('merchant.derived.total_collected'); ?></p>
                <p class="tw-text-2xl tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mt-1">
                    <span class="tw-text-success"><?php echo number_format((float)$batchStats['total_amount'], 2); ?> U</span>
                </p>
                <p class="tw-text-xs tw-text-gray-500 tw-mt-1">
                    USDT <?php echo number_format((float)($batchStats['total_usdt'] ?? 0), 2); ?> U ·
                    USDC <?php echo number_format((float)($batchStats['total_usdc'] ?? 0), 2); ?> U
                </p>
            </div>
            <div class="tw-p-3 tw-bg-blue-50 dark:tw-bg-blue-900/20 tw-rounded-lg">
                <svg class="tw-w-6 tw-h-6 tw-text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
        </div>
        <!-- Stat Card 2 -->
        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-p-5 tw-shadow-sm tw-border tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
            <div>
                <p class="tw-text-sm tw-font-medium tw-text-gray-500 dark:tw-text-gray-400"><?php echo __('merchant.derived.pending_count'); ?></p>
                <p class="tw-text-2xl tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mt-1"><?php echo count($paidUnsettledWallets); ?></p>
            </div>
            <div class="tw-p-3 tw-bg-yellow-50 dark:tw-bg-yellow-900/20 tw-rounded-lg">
                <svg class="tw-w-6 tw-h-6 tw-text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
        </div>
        <!-- Stat Card 3 -->
        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-p-5 tw-shadow-sm tw-border tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
            <div>
                <p class="tw-text-sm tw-font-medium tw-text-gray-500 dark:tw-text-gray-400"><?php echo __('merchant.derived.today_collected'); ?></p>
                <p class="tw-text-2xl tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mt-1"><?php echo number_format((float)$batchStats['today_amount'], 2); ?> <span class="tw-text-sm tw-font-normal tw-text-gray-500">U</span></p>
                <p class="tw-text-xs tw-text-gray-500 tw-mt-1">
                    USDT <?php echo number_format((float)($batchStats['today_usdt'] ?? 0), 2); ?> U ·
                    USDC <?php echo number_format((float)($batchStats['today_usdc'] ?? 0), 2); ?> U
                </p>
            </div>
            <div class="tw-p-3 tw-bg-green-50 dark:tw-bg-green-900/20 tw-rounded-lg">
                <svg class="tw-w-6 tw-h-6 tw-text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
        </div>
        <!-- Stat Card 4 -->
        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-p-5 tw-shadow-sm tw-border tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
            <div>
                <p class="tw-text-sm tw-font-medium tw-text-gray-500 dark:tw-text-gray-400"><?php echo __('merchant.derived.config_status'); ?></p>
                <div class="tw-flex tw-gap-2 tw-mt-2">
                    <span class="tw-px-2 tw-py-1 tw-rounded-md tw-text-xs tw-font-medium <?php echo !empty($xpubMap[$selectedChain]) ? 'tw-bg-green-100 tw-text-green-800' : 'tw-bg-gray-100 tw-text-gray-500'; ?>">xpub</span>
                    <span class="tw-px-2 tw-py-1 tw-rounded-md tw-text-xs tw-font-medium <?php echo is_valid_evm_address($masterMap[$selectedChain] ?? '') ? 'tw-bg-green-100 tw-text-green-800' : 'tw-bg-gray-100 tw-text-gray-500'; ?>">Master</span>
                </div>
            </div>
            <div class="tw-p-3 tw-bg-purple-50 dark:tw-bg-purple-900/20 tw-rounded-lg">
                <svg class="tw-w-6 tw-h-6 tw-text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-12 tw-gap-6">
        
        <!-- Left: Command Console (Takes 8 columns on large screens) -->
        <div class="lg:tw-col-span-8 tw-space-y-6">
            
            <!-- Batch Console Card -->
            <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-overflow-hidden">
                <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-200 dark:tw-border-gray-700 tw-flex tw-justify-between tw-items-center">
                    <h2 class="tw-text-lg tw-font-bold tw-text-gray-900 dark:tw-text-white tw-flex tw-items-center tw-gap-2">
                        <svg class="tw-w-5 tw-h-5 tw-text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <?php echo __('merchant.derived.batch_console'); ?>
                        <button type="button" class="tw-inline-flex tw-items-center tw-justify-center tw-w-5 tw-h-5 tw-rounded-full tw-bg-blue-50 tw-text-blue-600 tw-text-xs tw-font-bold hover:tw-bg-blue-100" data-bs-toggle="modal" data-bs-target="#batchGuideModal" title="<?php echo __('merchant.derived.view_guide'); ?>">?</button>
                    </h2>
                    <div class="tw-flex tw-gap-2">
                        <button onclick="clearFlowStatus()" class="tw-text-xs tw-text-gray-500 hover:tw-text-gray-700 dark:tw-text-gray-400"><?php echo __('merchant.derived.clear_logs'); ?></button>
                    </div>
                </div>
                
                <div class="tw-p-6">
                    <!-- Progress Stepper -->
                    <div class="tw-relative tw-mb-8">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                            <div id="flowProgressText" class="tw-text-xs tw-font-semibold tw-text-gray-600 dark:tw-text-gray-300"><?php echo __('merchant.derived.waiting'); ?></div>
                            <div id="flowProgressPercent" class="tw-text-xs tw-font-bold tw-text-primary">0%</div>
                        </div>
                        <div class="flow-progress-track tw-relative tw-h-2.5 tw-rounded-full tw-overflow-hidden tw-mb-4">
                            <div id="flowProgressBar" class="flow-progress-bar tw-absolute tw-left-0 tw-top-0 tw-h-full tw-transition-all tw-duration-500" style="width: 0%"></div>
                        </div>
                        <div class="tw-relative tw-flex tw-justify-between">
                            <div class="stepper-item tw-flex tw-flex-col tw-items-center tw-gap-2 active">
                                <div class="stepper-circle tw-w-8 tw-h-8 tw-rounded-full tw-bg-white dark:tw-bg-gray-800 tw-border-2 tw-border-gray-300 dark:tw-border-gray-600 tw-flex tw-items-center tw-justify-center tw-text-xs tw-font-bold tw-z-10">1</div>
                                <span class="tw-text-xs tw-font-medium tw-text-gray-600 dark:tw-text-gray-400"><?php echo __('merchant.derived.step.generate'); ?></span>
                            </div>
                            <div class="stepper-item tw-flex tw-flex-col tw-items-center tw-gap-2">
                                <div class="stepper-circle tw-w-8 tw-h-8 tw-rounded-full tw-bg-white dark:tw-bg-gray-800 tw-border-2 tw-border-gray-300 dark:tw-border-gray-600 tw-flex tw-items-center tw-justify-center tw-text-xs tw-font-bold tw-z-10">2</div>
                                <span class="tw-text-xs tw-font-medium tw-text-gray-600 dark:tw-text-gray-400"><?php echo __('merchant.derived.step.topup'); ?></span>
                            </div>
                            <div class="stepper-item tw-flex tw-flex-col tw-items-center tw-gap-2">
                                <div class="stepper-circle tw-w-8 tw-h-8 tw-rounded-full tw-bg-white dark:tw-bg-gray-800 tw-border-2 tw-border-gray-300 dark:tw-border-gray-600 tw-flex tw-items-center tw-justify-center tw-text-xs tw-font-bold tw-z-10">3</div>
                                <span class="tw-text-xs tw-font-medium tw-text-gray-600 dark:tw-text-gray-400"><?php echo __('merchant.derived.step.confirm'); ?></span>
                            </div>
                            <div class="stepper-item tw-flex tw-flex-col tw-items-center tw-gap-2">
                                <div class="stepper-circle tw-w-8 tw-h-8 tw-rounded-full tw-bg-white dark:tw-bg-gray-800 tw-border-2 tw-border-gray-300 dark:tw-border-gray-600 tw-flex tw-items-center tw-justify-center tw-text-xs tw-font-bold tw-z-10">4</div>
                                <span class="tw-text-xs tw-font-medium tw-text-gray-600 dark:tw-text-gray-400"><?php echo __('merchant.derived.step.broadcast'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Console Output -->
                    <div class="tw-relative tw-mb-6">
                        <div id="flowStatusBoard" class="tw-bg-gray-900 tw-rounded-lg tw-p-4 tw-pr-4 tw-pb-14 tw-h-64 tw-overflow-y-auto tw-scrollbar-thin tw-font-mono tw-text-xs tw-text-gray-300 tw-border tw-border-gray-700">
                            <div class="tw-text-gray-500"><?php echo $dtt('系统已就绪，等待执行命令...', 'System ready. Waiting for command...'); ?></div>
                        </div>
                        <button id="copyTaskCodeBtn" type="button" onclick="copyLatestTaskCode()" class="tw-absolute tw-bottom-3 tw-right-3 tw-inline-flex tw-items-center tw-gap-1 tw-rounded-md tw-bg-blue-600 hover:tw-bg-blue-700 tw-text-white tw-text-xs tw-font-semibold tw-px-3 tw-py-1.5 tw-shadow tw-opacity-50 tw-pointer-events-none">
                            <svg class="tw-w-3.5 tw-h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 10h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                            <?php echo __('merchant.derived.copy_task_code'); ?>
                        </button>
                    </div>

                    <!-- Advanced Settings Button -->
                    <div class="tw-mt-4">
                        <button type="button" onclick="document.getElementById('advancedSettingsModal').classList.remove('tw-hidden')"
                            class="tw-inline-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2 tw-rounded-lg tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-bg-gray-50 dark:tw-bg-gray-900 hover:tw-bg-gray-100 dark:hover:tw-bg-gray-800 tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300 tw-transition-colors">
                            <svg fill="none" height="16" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            <?php echo __('merchant.derived.advanced_settings'); ?>
                        </button>
                    </div>

                    <!-- Advanced Settings Modal -->
                    <div id="advancedSettingsModal" class="tw-hidden tw-fixed tw-inset-0 tw-z-50 tw-flex tw-items-center tw-justify-center" style="background:rgba(0,0,0,0.5);">
                        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-2xl tw-w-full tw-max-w-3xl tw-max-h-screen tw-overflow-y-auto tw-mx-4 tw-my-6">
                            <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-4 tw-border-b tw-border-gray-200 dark:tw-border-gray-700 tw-sticky tw-top-0 tw-bg-white dark:tw-bg-gray-800 tw-z-10">
                                <h3 class="tw-font-semibold tw-text-base tw-text-gray-800 dark:tw-text-gray-100"><?php echo __('merchant.derived.advanced_settings'); ?></h3>
                                <button type="button" onclick="document.getElementById('advancedSettingsModal').classList.add('tw-hidden')"
                                    class="tw-text-gray-400 hover:tw-text-gray-600 dark:hover:tw-text-gray-300 tw-transition-colors">
                                    <svg fill="none" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>
                            <div class="tw-text-neutral-600 tw-px-6 tw-py-5">
                                <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                                    <!-- Flow Params -->
                                    <div>
                                        <h6 class="tw-font-bold tw-text-xs tw-uppercase tw-text-gray-500 tw-mb-3"><?php echo __('merchant.derived.flow_params'); ?></h6>
                                        <div class="tw-space-y-3">
                                            <div>
                                                <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1"><?php echo __('merchant.derived.token'); ?></label>
                                                <p class="tw-text-xs tw-text-gray-400 tw-mt-1"><?php echo $dtt('已在上方操作台选择，点击 USDT / USDC 切换。', 'Selected above. Click USDT / USDC to switch.'); ?></p>
                                                <input type="hidden" id="flowTokenSymbol" value="<?php echo htmlspecialchars($selectedTokenSymbol); ?>">
                                            </div>
                                            <div>
                                                <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1"><?php echo __('merchant.derived.min_threshold'); ?></label>
                                                <input id="flowMinAmount" type="number" step="0.1" value="0.1" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-800 tw-text-sm">
                                            </div>
                                            <div>
                                                <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1"><?php echo __('merchant.derived.wait_seconds'); ?></label>
                                                <input id="flowWaitSeconds" type="number" value="30" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-800 tw-text-sm">
                                            </div>
                                            <label class="tw-inline-flex tw-items-center tw-gap-2 tw-text-xs tw-text-gray-600">
                                                <input id="flowSkipTopupIfSufficient" type="checkbox" checked class="tw-rounded tw-border-gray-300">
                                                <?php echo __('merchant.derived.smart_skip_topup'); ?>
                                            </label>
                                        </div>
                                    </div>
                                    <div>
                                        <h6 class="tw-font-bold tw-text-xs tw-uppercase tw-text-gray-500 tw-mb-3"><?php echo __('merchant.derived.security_title'); ?></h6>
                                        <div class="tw-rounded-lg tw-border tw-border-emerald-200 tw-bg-emerald-50 tw-p-3 tw-text-xs tw-leading-5 tw-text-emerald-700">
                                            <?php echo __('merchant.derived.security_notice'); ?>
                                        </div>
                                    </div>
                                    <!-- Gas Params -->
                                    <div class="md:tw-col-span-2 tw-border-t tw-border-gray-200 dark:tw-border-gray-700 tw-pt-3">
                                        <h6 class="tw-font-bold tw-text-xs tw-uppercase tw-text-gray-500 tw-mb-3"><?php echo $dtt('Gas 补给配置', 'Gas Top-up Settings'); ?></h6>
                                        <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-4">
                                            <div class="tw-space-y-2">
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('Gas 钱包地址（仅公开地址）', 'Gas Wallet Address (public address only)'); ?></label>
                                                    <input id="gasFunderExpectedAddress" placeholder="0x..." class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs" value="<?php echo htmlspecialchars((string)($savedGasAddress ?: $gasFunderAddress ?: '')); ?>">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('安全系数', 'Safety Factor'); ?></label>
                                                    <input id="dynSafetyFactor" value="<?php echo htmlspecialchars($dynSafetyFactorDefault); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('最小补给', 'Minimum Top-up'); ?> (<?php echo htmlspecialchars($nativeCoinSymbol); ?>)</label>
                                                    <input id="dynMinTopupCoin" value="<?php echo htmlspecialchars($dynMinTopupDefault); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('最大补给', 'Maximum Top-up'); ?> (<?php echo htmlspecialchars($nativeCoinSymbol); ?>)</label>
                                                    <input id="dynMaxTopupCoin" value="<?php echo htmlspecialchars($dynMaxTopupDefault); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('失败二次补给', 'Retry Top-up on Failure'); ?> (<?php echo htmlspecialchars($nativeCoinSymbol); ?>)</label>
                                                    <input id="dynRetryExtraCoin" value="<?php echo htmlspecialchars($dynRetryExtraDefault); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('归集默认 GasLimit', 'Default Sweep GasLimit'); ?></label>
                                                    <input id="dynDefaultSweepGasLimit" value="<?php echo htmlspecialchars($dynDefaultSweepGasLimit); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('补Gas并发 / 归集并发', 'Top-up Concurrency / Sweep Concurrency'); ?></label>
                                                    <input id="dynConcurrencyPair" value="3/2" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('轮询阈值(地址数)', 'Polling Threshold (address count)'); ?></label>
                                                    <input id="dynPollAddressThreshold" value="20" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500"><?php echo $dtt('最小 GasPrice (Gwei)', 'Minimum GasPrice (Gwei)'); ?></label>
                                                    <input id="dynMinGasPriceGwei" value="<?php echo htmlspecialchars($dynMinGasGweiDefault); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <input id="gasFunderPath" type="hidden" value="m/44'/60'/0'/0/0">
                                                <input id="gasPathScanDepth" type="hidden" value="1200">
                                                <input id="gasPathProfile" type="hidden" value="auto">
                                                <input id="gasStartNonce" type="hidden" value="0x0">
                                                <input id="batchSweepFeeCoin" type="hidden" value="<?php echo htmlspecialchars($sweepFeeCoinDisplay); ?>">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tw-flex tw-justify-end tw-px-6 tw-py-4 tw-border-t tw-border-gray-200 dark:tw-border-gray-700">
                                <button type="button" onclick="document.getElementById('advancedSettingsModal').classList.add('tw-hidden')"
                                    class="tw-px-5 tw-py-2 tw-rounded-lg tw-bg-blue-600 hover:tw-bg-blue-700 tw-text-white tw-text-sm tw-font-semibold tw-transition-colors">
                                    <?php echo $dtt('确认', 'Confirm'); ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Token Toggle (prominent) -->
                    <div class="tw-mt-5 tw-mb-4 tw-flex tw-items-center tw-gap-3 tw-flex-wrap">
                        <span class="tw-text-sm tw-font-semibold tw-text-gray-700 dark:tw-text-gray-200"><?php echo $dtt('归集币种', 'Collection Token'); ?></span>
                        <div class="tw-inline-flex tw-rounded-lg tw-border tw-border-gray-200 dark:tw-border-gray-600 tw-overflow-hidden tw-shadow-sm" id="tokenToggleGroup">
                            <?php foreach ($availableFlowTokens as $tk): ?>
                            <button type="button" id="tokenToggle<?php echo htmlspecialchars($tk); ?>" onclick="setFlowToken('<?php echo htmlspecialchars($tk); ?>')"
                                class="tw-px-5 tw-py-2 tw-text-sm tw-font-bold tw-transition-colors <?php echo $tk !== $availableFlowTokens[0] ? 'tw-border-l tw-border-gray-200 dark:tw-border-gray-600' : ''; ?> <?php echo $selectedTokenSymbol === $tk ? ($tk === 'USDC' ? 'tw-bg-blue-500 tw-text-white' : 'tw-bg-green-500 tw-text-white') : 'tw-bg-white dark:tw-bg-gray-700 tw-text-gray-500 dark:tw-text-gray-400 hover:tw-bg-gray-50'; ?>">
                                <?php echo htmlspecialchars($tk); ?>
                                <?php if (($unsettledCurrencyCounts[$tk] ?? 0) > 0): ?>
                                    <span class="tw-ml-1 tw-text-xs tw-font-normal tw-opacity-80">(<?php echo (int)$unsettledCurrencyCounts[$tk]; ?>)</span>
                                <?php endif; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <span class="tw-text-xs tw-text-gray-400"><?php echo $dtt('已自动选中待归集数量较多的币种', 'Auto-selected the token with more pending items'); ?></span>
                    </div>

                    <!-- Actions -->
                    <div class="tw-flex tw-flex-wrap tw-gap-3 tw-mt-4">
                        <button id="runFullFlowBtn" onclick="runFullFlow()" class="tw-flex-1 tw-min-w-[220px] tw-bg-success hover:tw-bg-green-600 tw-text-white tw-font-medium tw-py-2.5 tw-px-4 tw-rounded-lg tw-shadow-sm tw-transition-colors tw-flex tw-items-center tw-justify-center tw-gap-2">
                            <svg class="tw-w-5 tw-h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <?php echo $dtt('1) 生成离线签名任务码', '1) Generate Offline Signing Task Code'); ?>
                        </button>
                        <button onclick="openSignedResultModal()" class="tw-flex-1 tw-min-w-[220px] tw-bg-white dark:tw-bg-gray-700 tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-text-gray-700 dark:tw-text-gray-200 tw-font-medium tw-py-2.5 tw-px-4 tw-rounded-lg hover:tw-bg-gray-50 dark:hover:tw-bg-gray-600 tw-transition-colors">
                            <?php echo $dtt('2) 粘贴并执行已签名结果', '2) Paste and Execute Signed Result'); ?>
                        </button>
                        <div class="tw-flex-1 tw-min-w-[220px] tw-grid tw-grid-cols-2 tw-gap-2">
                            <a href="/tools/derived_offline_signer.html" download class="tw-bg-indigo-600 hover:tw-bg-indigo-700 tw-text-white tw-font-medium tw-text-sm tw-py-2.5 tw-px-3 tw-rounded-lg tw-shadow-sm tw-transition-colors tw-text-center tw-whitespace-nowrap">
                                <?php echo $dtt('下载签名器', 'Get Signer'); ?>
                            </a>
                            <a href="/bip39-standalone.html" download="bip39-standalone.html" class="tw-bg-amber-500 hover:tw-bg-amber-600 tw-text-white tw-font-medium tw-text-sm tw-py-2.5 tw-px-3 tw-rounded-lg tw-shadow-sm tw-transition-colors tw-text-center tw-whitespace-nowrap">
                                <?php echo $dtt('派生钱包离线', 'Wallet Generator'); ?>
                            </a>
                        </div>
                    </div>

                </div>
            </div>

            <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-overflow-hidden">
                <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-200 dark:tw-border-gray-700 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
                    <h2 class="tw-text-lg tw-font-bold tw-text-gray-900 dark:tw-text-white"><?php echo $dtt('归集记录', 'Collection Records'); ?></h2>
                    <div class="tw-inline-flex tw-rounded-lg tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-overflow-hidden">
                        <button id="recordsTabBatchBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-primary tw-text-white" onclick="switchRecordsTab('batch')"><?php echo $dtt('最近归集批次', 'Recent Batches'); ?></button>
                        <button id="recordsTabTotalBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-white dark:tw-bg-gray-800 tw-text-gray-700 dark:tw-text-gray-200" onclick="switchRecordsTab('total')"><?php echo $dtt('总归集记录', 'All Collection Records'); ?></button>
                        <button id="recordsTabDerivedBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-white dark:tw-bg-gray-800 tw-text-gray-700 dark:tw-text-gray-200" onclick="switchRecordsTab('derived')"><?php echo $dtt('总派生记录', 'All Derived Records'); ?></button>
                        <button id="recordsTabFailedBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-white dark:tw-bg-gray-800 tw-text-gray-700 dark:tw-text-gray-200" onclick="switchRecordsTab('failed')"><?php echo $dtt('失败记录', 'Failed Records'); ?></button>
                        <button id="recordsTabUnsettledBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-white dark:tw-bg-gray-800 tw-text-gray-700 dark:tw-text-gray-200" onclick="switchRecordsTab('unsettled')"><?php echo $dtt('未归集有余额地址', 'Uncollected Addresses with Balance'); ?></button>
                    </div>
                </div>

                <div id="recordsTabBatch">
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">ID</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('时间', 'Time'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('数量', 'Count'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('币种', 'Token'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('金额', 'Amount'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('状态', 'Status'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('操作', 'Actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700">
                                <?php if (empty($allBatches)): ?>
                                    <tr><td colspan="7" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400"><?php echo $dtt('暂无历史批次', 'No batch history'); ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($allBatches as $b): ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors">
                                        <td class="tw-px-6 tw-py-4">#<?php echo (int)$b['id']; ?></td>
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500"><?php echo htmlspecialchars((string)($b['created_at'] ?? '-')); ?></td>
                                        <td class="tw-px-6 tw-py-4"><?php echo (int)$b['done_items']; ?>/<?php echo (int)$b['total_items']; ?></td>
                                        <td class="tw-px-6 tw-py-4"><span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-bg-gray-100 tw-text-gray-700 tw-text-xs"><?php echo htmlspecialchars((string)($b['token_symbol'] ?? 'USDT')); ?></span></td>
                                        <td class="tw-px-6 tw-py-4 tw-font-medium"><?php echo number_format((float)$b['total_amount_display'], 6); ?></td>
                                        <?php
                                            $bStatus = (string)$b['status'];
                                            $bPending = (int)($b['pending_items'] ?? 0);
                                            $bSuperseded = (int)($b['superseded_items'] ?? 0);
                                            // All pending items have been covered by other batches
                                            $bAllSuperseded = ($bPending > 0 && $bSuperseded >= $bPending);
                                        ?>
                                        <td class="tw-px-6 tw-py-4">
                                            <?php if ($bStatus === 'completed'): ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-green-100 tw-text-green-800 tw-text-xs"><?php echo $dtt('已完成', 'Completed'); ?></span>
                                            <?php elseif ($bStatus === 'partial' || $bAllSuperseded): ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-orange-100 tw-text-orange-800 tw-text-xs"><?php echo $dtt('部分完成', 'Partial'); ?></span>
                                                <?php if ($bAllSuperseded): ?>
                                                <span class="tw-ml-1 tw-px-2 tw-py-1 tw-rounded-full tw-bg-blue-100 tw-text-blue-700 tw-text-xs"><?php echo $dtt('其他批次已覆盖', 'Covered by later batch'); ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-yellow-100 tw-text-yellow-800 tw-text-xs"><?php echo htmlspecialchars($bStatus); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tw-px-6 tw-py-4 tw-flex tw-gap-2">
                                            <button onclick="viewBatchItems(<?php echo (int)$b['id']; ?>)" class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-bg-blue-50 tw-text-blue-700 hover:tw-bg-blue-100 tw-border tw-border-blue-200"><?php echo $dtt('查看明细', 'View Items'); ?></button>
                                            <?php if ($bStatus !== 'completed'): ?>
                                                <?php if ($bAllSuperseded): ?>
                                                <button onclick="rollbackBatch(<?php echo (int)$b['id']; ?>)" class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-bg-gray-50 tw-text-gray-600 hover:tw-bg-gray-100 tw-border tw-border-gray-300" title="<?php echo $dtt('所有未完成条目已被其他批次完成，点击自动取消这些条目', 'All pending items covered by later batches. Click to auto-cancel stale items.'); ?>">
                                                    <?php echo $dtt('自动关闭', 'Auto-close'); ?>
                                                </button>
                                                <?php else: ?>
                                                <button onclick="rollbackBatch(<?php echo (int)$b['id']; ?>)" class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-bg-red-50 tw-text-red-700 hover:tw-bg-red-100 tw-border tw-border-red-200"><?php echo $dtt('回滚批次', 'Rollback'); ?></button>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <div class="tw-text-xs tw-text-gray-500"><?php echo __('merchant.common.page_status', ['current' => (int)$pageBatch, 'total_pages' => (int)$allBatchesPages, 'total_count' => (int)$allBatchesTotal]); ?></div>
                        <div class="tw-flex tw-gap-2">
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageBatch <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_batch' => max(1, $pageBatch - 1)])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageBatch >= $allBatchesPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_batch' => min($allBatchesPages, $pageBatch + 1)])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                        </div>
                    </div>
                </div>

                <div id="recordsTabTotal" class="tw-hidden">
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('时间', 'Time'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('来源地址', 'Source Address'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('归集到', 'Collected To'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('币种', 'Token'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('金额', 'Amount'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('合约', 'Contract'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">Tx Hash</th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700">
                                <?php if (empty($allCollectedRecords)): ?>
                                    <tr><td colspan="7" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400"><?php echo $dtt('暂无归集记录', 'No collection records'); ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($allCollectedRecords as $r): ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors">
                                        <?php
                                            $contractText = '-';
                                            $eip = (string)($r['eip681_uri'] ?? '');
                                            if (preg_match('/^ethereum:(0x[a-fA-F0-9]{40})@/i', $eip, $mm)) {
                                                $contractText = strtolower((string)$mm[1]);
                                            }
                                        ?>
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500"><?php echo htmlspecialchars((string)($r['updated_at'] ?? '-')); ?></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$r['from_address'], 0, 8) . '...' . substr((string)$r['from_address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$r['to_address'], 0, 8) . '...' . substr((string)$r['to_address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4"><span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-bg-gray-100 tw-text-gray-700 tw-text-xs"><?php echo htmlspecialchars((string)($r['token_symbol'] ?? 'USDT')); ?></span></td>
                                        <td class="tw-px-6 tw-py-4 tw-font-medium"><?php echo number_format((float)$r['amount_display'], 6); ?></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <?php if ($contractText !== '-'): ?>
                                                <code><?php echo htmlspecialchars(substr((string)$contractText, 0, 8) . '...' . substr((string)$contractText, -6)); ?></code>
                                            <?php else: ?>
                                                <span class="tw-text-gray-400 tw-text-xs">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tw-px-6 tw-py-4">
                                            <?php $txHashTxt = trim((string)($r['tx_hash'] ?? '')); ?>
                                            <?php $txUrl = evm_explorer_tx_url($selectedChain, $txHashTxt); ?>
                                            <?php if ($txUrl !== ''): ?>
                                                <a class="tw-text-primary hover:tw-underline tw-font-mono tw-text-xs" href="<?php echo htmlspecialchars($txUrl); ?>" target="_blank" rel="noopener noreferrer">
                                                    <?php echo htmlspecialchars(substr($txHashTxt, 0, 12) . '...' . substr($txHashTxt, -10)); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="tw-text-gray-400 tw-text-xs">-</span>
                                            <?php endif; ?>
                                            <?php if ($txHashTxt === ''): ?>
                                            <form method="POST" class="tw-mt-2">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($merchant_csrf_token); ?>">
                                                <input type="hidden" name="action" value="rollback_collected_item">
                                                <input type="hidden" name="chain" value="<?php echo htmlspecialchars($selectedChain); ?>">
                                                <input type="hidden" name="item_id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="tw-text-xs tw-px-2 tw-py-1 tw-rounded tw-border tw-border-gray-300 hover:tw-bg-gray-50"><?php echo $dtt('回滚待归集', 'Rollback to Pending'); ?></button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <div class="tw-text-xs tw-text-gray-500"><?php echo __('merchant.common.page_status', ['current' => (int)$pageTotal, 'total_pages' => (int)$allCollectedPages, 'total_count' => (int)$allCollectedTotal]); ?></div>
                        <div class="tw-flex tw-gap-2">
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageTotal <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_total' => max(1, $pageTotal - 1)])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageTotal >= $allCollectedPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_total' => min($allCollectedPages, $pageTotal + 1)])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                        </div>
                    </div>
                </div>

                <div id="recordsTabDerived" class="tw-hidden">
                    <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <form method="GET" class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
                            <input type="hidden" name="chain" value="<?php echo htmlspecialchars($selectedChain); ?>">
                            <input type="text" name="derived_kw" value="<?php echo htmlspecialchars($derivedSearch); ?>" placeholder="<?php echo htmlspecialchars($dtt('搜索派生地址 / 订单号 / 商户单号', 'Search derived address / order no / merchant order no')); ?>" class="tw-w-full md:tw-w-80 tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                            <select name="derived_status" class="tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                                <option value="all" <?php echo $derivedStatus === 'all' ? 'selected' : ''; ?>><?php echo $dtt('全部状态', 'All Statuses'); ?></option>
                                <option value="paid" <?php echo $derivedStatus === 'paid' ? 'selected' : ''; ?>><?php echo $dtt('已支付', 'Paid'); ?></option>
                                <option value="pending" <?php echo $derivedStatus === 'pending' ? 'selected' : ''; ?>><?php echo $dtt('待支付', 'Pending'); ?></option>
                                <option value="expired" <?php echo $derivedStatus === 'expired' ? 'selected' : ''; ?>><?php echo $dtt('已过期', 'Expired'); ?></option>
                                <option value="failed" <?php echo $derivedStatus === 'failed' ? 'selected' : ''; ?>><?php echo $dtt('失败', 'Failed'); ?></option>
                            </select>
                            <button type="submit" class="tw-px-3 tw-py-2 tw-text-sm tw-bg-primary hover:tw-bg-blue-600 tw-text-white tw-rounded-md"><?php echo __('merchant.orders.search'); ?></button>
                            <?php if ($derivedSearch !== '' || $derivedStatus !== 'all'): ?>
                            <a href="<?php echo htmlspecialchars($buildPageUrl(['derived_kw' => null, 'derived_status' => 'all', 'p_derived' => 1])); ?>" class="tw-px-3 tw-py-2 tw-text-sm tw-bg-white dark:tw-bg-gray-700 tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-text-gray-700 dark:tw-text-gray-200 tw-rounded-md"><?php echo __('merchant.orders.reset'); ?></a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('派生地址', 'Derived Address'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('订单号', 'Order No.'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('币种', 'Token'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('交易时间', 'Transaction Time'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('支付状态', 'Payment Status'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700">
                                <?php if (empty($allDerivedRecords)): ?>
                                    <tr><td colspan="5" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400"><?php echo $dtt('暂无派生记录', 'No derived records'); ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($allDerivedRecords as $r): ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors">
                                        <td class="tw-px-6 tw-py-4">
                                            <div class="tw-flex tw-items-center tw-gap-2">
                                                <code><?php echo htmlspecialchars(substr((string)$r['allocated_address'], 0, 8) . '...' . substr((string)$r['allocated_address'], -6)); ?></code>
                                                <button type="button" onclick="copyDerivedAddress('<?php echo htmlspecialchars((string)$r['allocated_address'], ENT_QUOTES); ?>', this)" class="tw-text-[11px] tw-px-2 tw-py-1 tw-rounded tw-border tw-border-gray-300 hover:tw-bg-gray-50"><?php echo __('merchant.common.copy'); ?></button>
                                            </div>
                                            <div class="tw-text-[11px] tw-text-gray-500 tw-mt-1"><?php echo htmlspecialchars((string)($r['derivation_path'] ?: '-')); ?></div>
                                        </td>
                                        <td class="tw-px-6 tw-py-4">
                                            <div class="tw-font-mono"><?php echo htmlspecialchars((string)($r['order_no'] ?: '-')); ?></div>
                                            <div class="tw-text-[11px] tw-text-gray-500"><?php echo htmlspecialchars((string)($r['merchant_order_id'] ?: '-')); ?></div>
                                        </td>
                                        <td class="tw-px-6 tw-py-4">
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-bg-blue-50 tw-text-blue-700 tw-text-xs tw-font-semibold">
                                                <?php echo htmlspecialchars(strtoupper((string)($r['currency'] ?: 'USDT'))); ?>
                                            </span>
                                        </td>
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500">
                                            <?php
                                            $txTime = (string)($r['payment_status'] === 'paid'
                                                ? ($r['order_updated_at'] ?? $r['order_created_at'] ?? $r['allocated_at'])
                                                : ($r['allocated_at'] ?? $r['order_created_at'] ?? '-'));
                                            echo htmlspecialchars($txTime !== '' ? $txTime : '-');
                                            ?>
                                        </td>
                                        <td class="tw-px-6 tw-py-4">
                                            <?php $pst = strtolower((string)($r['payment_status'] ?? 'pending')); ?>
                                            <?php if ($pst === 'paid'): ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-green-100 tw-text-green-800 tw-text-xs"><?php echo $dtt('已支付', 'Paid'); ?></span>
                                            <?php elseif ($pst === 'expired' || $pst === 'failed'): ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-red-100 tw-text-red-700 tw-text-xs"><?php echo htmlspecialchars($pst); ?></span>
                                            <?php else: ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-yellow-100 tw-text-yellow-800 tw-text-xs"><?php echo htmlspecialchars($pst === '' ? 'pending' : $pst); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <div class="tw-text-xs tw-text-gray-500"><?php echo __('merchant.common.page_status', ['current' => (int)$pageDerived, 'total_pages' => (int)$allDerivedPages, 'total_count' => (int)$allDerivedTotal]); ?></div>
                        <div class="tw-flex tw-gap-2">
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageDerived <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_derived' => max(1, $pageDerived - 1)])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageDerived >= $allDerivedPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_derived' => min($allDerivedPages, $pageDerived + 1)])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                        </div>
                    </div>
                </div>

                <div id="recordsTabUnsettled" class="tw-hidden">
                    <!-- Currency filter tabs -->
                    <div class="tw-flex tw-items-center tw-gap-2 tw-px-4 tw-py-3 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <span class="tw-text-xs tw-text-gray-500 tw-mr-1"><?php echo $dtt('筛选：', 'Filter:'); ?></span>
                        <button onclick="filterUnsettledTab('ALL')" id="ufTabAll"
                            class="tw-px-3 tw-py-1 tw-rounded-md tw-text-xs tw-font-semibold tw-bg-primary tw-text-white">
                            <?php echo $dtt('全部', 'All'); ?> <span class="tw-opacity-80">(<?php echo (int)$unsettledTotal; ?>)</span>
                        </button>
                        <?php foreach (['USDT' => 'green', 'USDC' => 'blue'] as $tabTok => $tabColor): ?>
                        <button onclick="filterUnsettledTab('<?php echo $tabTok; ?>')" id="ufTab<?php echo $tabTok; ?>"
                            class="tw-px-3 tw-py-1 tw-rounded-md tw-text-xs tw-font-semibold tw-bg-gray-100 dark:tw-bg-gray-700 tw-text-gray-600 dark:tw-text-gray-300 hover:tw-bg-<?php echo $tabColor; ?>-50 hover:tw-text-<?php echo $tabColor; ?>-700">
                            <?php echo $tabTok; ?> <span class="tw-opacity-80">(<?php echo (int)($unsettledCurrencyCounts[$tabTok] ?? 0); ?>)</span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('地址ID', 'Address ID'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('地址', 'Address'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('路径', 'Path'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('币种', 'Token'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('待归集金额', 'Pending Amount'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo __('merchant.orders.actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700" id="unsettledTableBody">
                                <?php if (empty($unsettledWithBalance)): ?>
                                    <tr><td colspan="6" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400"><?php echo $dtt('暂无未归集有余额地址', 'No uncollected addresses with balance'); ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($unsettledWithBalance as $w):
                                        $wCcy = strtoupper(trim((string)($w['latest_paid_currency'] ?: 'USDT')));
                                    ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors" data-currencies="<?php echo htmlspecialchars($wCcy); ?>">
                                        <td class="tw-px-6 tw-py-4"><?php echo (int)$w['id']; ?></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$w['address'], 0, 8) . '...' . substr((string)$w['address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500"><?php echo htmlspecialchars((string)($w['derivation_path'] ?: '-')); ?></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-text-xs tw-font-semibold <?php echo $wCcy === 'USDC' ? 'tw-bg-blue-50 tw-text-blue-600' : 'tw-bg-green-50 tw-text-green-600'; ?>">
                                                <?php echo htmlspecialchars($wCcy); ?>
                                            </span>
                                        </td>
                                        <td class="tw-px-6 tw-py-4 tw-font-medium"><?php echo number_format((float)($w['effective_balance_display'] ?? 0), 6); ?></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <form method="POST" class="tw-inline js-refresh-balance-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                                                <input type="hidden" name="action" value="refresh_balance">
                                                <input type="hidden" name="chain" value="<?php echo htmlspecialchars($selectedChain); ?>">
                                                <input type="hidden" name="wallet_id" value="<?php echo (int)$w['id']; ?>">
                                                <button type="submit" class="tw-text-xs tw-text-primary hover:tw-underline"><?php echo $dtt('刷新余额', 'Refresh Balance'); ?></button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <div class="tw-text-xs tw-text-gray-500"><?php echo __('merchant.common.page_status', ['current' => (int)$pageUnsettled, 'total_pages' => (int)$unsettledPages, 'total_count' => (int)$unsettledTotal]); ?></div>
                        <div class="tw-flex tw-gap-2">
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageUnsettled <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_unsettled' => max(1, $pageUnsettled - 1)])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageUnsettled >= $unsettledPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_unsettled' => min($unsettledPages, $pageUnsettled + 1)])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                        </div>
                    </div>
                </div>

                <div id="recordsTabFailed" class="tw-hidden">
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('时间', 'Time'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('批次', 'Batch'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('失败地址', 'Failed Address'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('目标地址', 'Target Address'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('币种', 'Token'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('金额', 'Amount'); ?></th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium"><?php echo $dtt('失败原因', 'Failure Reason'); ?></th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700">
                                <?php if (empty($failedCollectedRecords)): ?>
                                    <tr><td colspan="7" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400"><?php echo $dtt('暂无失败记录', 'No failed records'); ?></td></tr>
                                <?php else: ?>
                                    <?php foreach ($failedCollectedRecords as $r): ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors">
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500"><?php echo htmlspecialchars((string)($r['updated_at'] ?? '-')); ?></td>
                                        <td class="tw-px-6 tw-py-4">#<?php echo (int)($r['batch_id'] ?? 0); ?></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$r['from_address'], 0, 8) . '...' . substr((string)$r['from_address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$r['to_address'], 0, 8) . '...' . substr((string)$r['to_address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <span class="tw-inline-flex tw-px-2 tw-py-0.5 tw-rounded tw-bg-blue-50 tw-text-blue-700 tw-text-xs tw-font-semibold">
                                                <?php echo htmlspecialchars(strtoupper((string)($r['token_symbol'] ?: 'USDT'))); ?>
                                            </span>
                                        </td>
                                        <td class="tw-px-6 tw-py-4 tw-font-medium"><?php echo number_format((float)$r['amount_display'], 6); ?></td>
                                        <td class="tw-px-6 tw-py-4 tw-text-red-500"><?php echo htmlspecialchars((string)($r['tx_error'] ?: $dtt('未知错误', 'Unknown error'))); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <div class="tw-text-xs tw-text-gray-500"><?php echo __('merchant.common.page_status', ['current' => (int)$pageFailed, 'total_pages' => (int)$failedCollectedPages, 'total_count' => (int)$failedCollectedTotal]); ?></div>
                        <div class="tw-flex tw-gap-2">
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageFailed <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_failed' => max(1, $pageFailed - 1)])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                            <a class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-border tw-border-gray-300 <?php echo $pageFailed >= $failedCollectedPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_failed' => min($failedCollectedPages, $pageFailed + 1)])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right: Config & Wallet List (Takes 4 columns) -->
        <div class="lg:tw-col-span-4 tw-space-y-6">
            
            <!-- Config Card -->
            <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-p-5">
                <h3 class="tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mb-4"><?php echo __('merchant.derived.config_center'); ?></h3>
                <form method="POST" class="tw-space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                    <input type="hidden" name="action" value="save_master">
                    <input type="hidden" name="chain" value="<?php echo htmlspecialchars($selectedChain); ?>">
                    <div>
                        <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1"><?php echo __('merchant.derived.master_address'); ?></label>
                        <div class="tw-flex tw-gap-2">
                            <input type="text" name="master_address" value="<?php echo htmlspecialchars($masterMap[$selectedChain] ?? ''); ?>" class="tw-flex-1 tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm" placeholder="0x..." required>
                            <button type="submit" class="tw-px-3 tw-bg-primary hover:tw-bg-blue-600 tw-text-white tw-rounded-md tw-text-sm"><?php echo __('merchant.common.save'); ?></button>
                        </div>
                    </div>
                </form>
                <div class="tw-my-4 tw-border-t tw-border-gray-100 dark:tw-border-gray-700"></div>
                 <form method="POST" class="tw-space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                    <input type="hidden" name="action" value="save_xpub_config">
                    <input type="hidden" name="chain" value="<?php echo htmlspecialchars($selectedChain); ?>">
                    <div>
                        <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">Xpub (Auto Derive)</label>
                        <textarea name="xpub" rows="2" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm tw-font-mono" placeholder="xpub..."><?php echo htmlspecialchars($xpubMap[$selectedChain] ?? ''); ?></textarea>
                    </div>
                    <div class="tw-grid tw-grid-cols-2 tw-gap-3">
                         <div>
                            <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1"><?php echo __('merchant.derived.start_index'); ?></label>
                            <input type="number" name="start_index" value="<?php echo (int)($nextIndexMap[$selectedChain] ?? 0); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                         </div>
                         <div>
                            <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1"><?php echo __('merchant.derived.path_prefix'); ?></label>
                            <input type="text" name="path_prefix" value="<?php echo htmlspecialchars($pathMap[$selectedChain] ?? "m/44'/60'/0'/0"); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                         </div>
                    </div>
                    <button type="submit" class="tw-w-full tw-bg-white dark:tw-bg-gray-700 tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-text-gray-700 dark:tw-text-gray-200 tw-py-2 tw-rounded-md tw-text-sm hover:tw-bg-gray-50 dark:hover:tw-bg-gray-600"><?php echo __('merchant.derived.save_xpub'); ?></button>
                </form>
            </div>

            <!-- Wallet List Mini -->
             <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-flex tw-flex-col tw-h-[600px]">
                <div class="tw-px-5 tw-py-4 tw-border-b tw-border-gray-200 dark:tw-border-gray-700 tw-flex tw-justify-between tw-items-center">
                    <h3 class="tw-font-bold tw-text-gray-900 dark:tw-text-white"><?php echo __('merchant.derived.pending_wallets'); ?></h3>
                </div>
                <div class="tw-flex-1 tw-overflow-y-auto tw-scrollbar-thin">
                    <?php if (empty($paidUnsettledWalletsPaged)): ?>
                        <div class="tw-p-8 tw-text-center tw-text-gray-400 tw-text-sm"><?php echo __('merchant.derived.no_pending_wallets'); ?></div>
                    <?php else: ?>
                        <?php foreach ($paidUnsettledWalletsPaged as $w): ?>
                        <div class="tw-px-5 tw-py-3 tw-border-b tw-border-gray-100 dark:tw-border-gray-700 hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50">
                            <div class="tw-flex tw-justify-between tw-items-start tw-mb-1">
                                <span class="tw-font-mono tw-text-xs tw-bg-gray-100 dark:tw-bg-gray-700 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-gray-600 dark:tw-text-gray-300">
                                    <?php echo htmlspecialchars(substr($w['address'], 0, 6) . '...' . substr($w['address'], -4)); ?>
                                </span>
                                <span class="tw-font-bold tw-text-sm tw-text-gray-900 dark:tw-text-white"><?php echo number_format((float)($w['effective_balance_display'] ?? 0), 4); ?></span>
                            </div>
                            <div class="tw-flex tw-justify-between tw-items-center">
                                <span class="tw-text-xs tw-text-gray-400">ID: <?php echo (int)$w['id']; ?></span>
                                <span class="tw-px-1.5 tw-py-0.5 tw-rounded-full tw-bg-green-50 tw-text-green-600 tw-text-[10px] tw-font-medium"><?php echo __('merchant.derived.paid'); ?></span>
                            </div>
                            <div class="tw-mt-1 tw-text-[11px] tw-text-gray-500 tw-leading-5">
                                <div><?php echo __('merchant.derived.order_no'); ?>: <?php echo htmlspecialchars((string)($w['latest_paid_order_no'] ?: '-')); ?></div>
                                <div><?php echo __('merchant.derived.paid_at'); ?>: <?php echo htmlspecialchars((string)($w['latest_paid_at'] ?: '-')); ?></div>
                                <div>
                                    <?php echo __('merchant.derived.chain'); ?>:
                                    <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-bg-gray-100 dark:tw-bg-gray-700 tw-text-gray-700 dark:tw-text-gray-200 tw-font-semibold">
                                        <?php echo htmlspecialchars(strtoupper((string)($w['chain_slug'] ?: $selectedChain))); ?>
                                    </span>
                                    <span class="tw-inline-flex tw-px-1.5 tw-py-0.5 tw-rounded tw-bg-blue-50 dark:tw-bg-blue-900/30 tw-text-blue-700 dark:tw-text-blue-200 tw-font-semibold tw-ml-1">
                                        <?php echo htmlspecialchars((string)($w['latest_paid_currency'] ?: 'USDT')); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="tw-px-5 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
                    <div class="tw-text-[11px] tw-text-gray-500"><?php echo __('merchant.common.page_status', ['current' => (int)$pageSide, 'total_pages' => (int)$sidePages, 'total_count' => (int)$sideTotal]); ?></div>
                    <div class="tw-flex tw-gap-2">
                        <a class="tw-px-2 tw-py-1 tw-text-[11px] tw-rounded tw-border tw-border-gray-300 <?php echo $pageSide <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_side' => max(1, $pageSide - 1)])); ?>"><?php echo __('merchant.common.prev_page'); ?></a>
                        <a class="tw-px-2 tw-py-1 tw-text-[11px] tw-rounded tw-border tw-border-gray-300 <?php echo $pageSide >= $sidePages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50'; ?>" href="<?php echo htmlspecialchars($buildPageUrl(['p_side' => min($sidePages, $pageSide + 1)])); ?>"><?php echo __('merchant.common.next_page'); ?></a>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<div class="modal fade" id="batchGuideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content tw-rounded-xl tw-border tw-border-gray-200 dark:tw-border-gray-700">
            <div class="modal-header">
                <h5 class="modal-title tw-font-bold"><?php echo $dtt('批量操作台使用说明', 'Batch Console Guide'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo $dtt('关闭', 'Close'); ?>"></button>
            </div>
            <div class="modal-body tw-text-sm tw-leading-7 tw-text-gray-700 dark:tw-text-gray-200">
                <div class="tw-space-y-4">
                    <div>
                        <div class="tw-font-semibold tw-text-gray-900 dark:tw-text-white"><?php echo $dtt('1. 页面目标', '1. Page Goal'); ?></div>
                        <div><?php echo $dtt('将已收款但未归集的派生地址资金，按链与币种批量归集到主钱包地址。', 'Collect funds from paid but unsettled derived addresses into the master wallet by chain and token.'); ?></div>
                    </div>

                    <div>
                        <div class="tw-font-semibold tw-text-gray-900 dark:tw-text-white"><?php echo $dtt('2. 当前支持范围（请按此范围使用）', '2. Supported Scope'); ?></div>
                        <ul class="tw-list-disc tw-pl-5 tw-space-y-1">
                            <li><span class="tw-font-medium"><?php echo $dtt('支持链', 'Supported Chains'); ?></span><?php echo $dtt('：BSC、ARB（Arbitrum）。', ': BSC, ARB (Arbitrum).'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('支持币种', 'Supported Tokens'); ?></span><?php echo $dtt('：USDT、USDC。', ': USDT, USDC.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('归集规则', 'Collection Rule'); ?></span><?php echo $dtt('：按“当前链 + 当前币种”独立归集，不混链、不混币。', ': Collection runs independently by current chain and token, without mixing chains or tokens.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('Gas 币', 'Gas Token'); ?></span><?php echo $dtt('：BSC 使用 BNB；ARB 使用 ETH。', ': BSC uses BNB; ARB uses ETH.'); ?></li>
                        </ul>
                    </div>

                    <div>
                        <div class="tw-font-semibold tw-text-gray-900 dark:tw-text-white"><?php echo $dtt('3. 开始前必须配置', '3. Required Before Starting'); ?></div>
                        <ul class="tw-list-disc tw-pl-5 tw-space-y-1">
                            <li><span class="tw-font-medium"><?php echo $dtt('收款主钱包（Master Address）', 'Master Address'); ?></span><?php echo $dtt('：归集目标地址，必须是当前链的有效地址。', ': The destination address for collection. It must be a valid address on the current chain.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('Xpub（Auto Derive）', 'Xpub (Auto Derive)'); ?></span><?php echo $dtt('：用于自动派生收款地址与路径。', ': Used to derive receiving addresses and paths automatically.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('链', 'Chain'); ?></span><?php echo $dtt('：在页面顶部切换，所有操作按当前链执行。', ': Switch it at the top of the page. All actions run on the current chain.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('归集币种', 'Collection Token'); ?></span><?php echo $dtt('：在“高级参数配置”中选择（如 USDT/USDC），必须与待归集地址币种一致。', ': Select it in Advanced Settings (for example USDT/USDC). It must match the token of the pending addresses.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('链上浏览器 API Key（建议）', 'Explorer API Key (Recommended)'); ?></span><?php echo $dtt('：用于余额刷新和链上确认稳定性。', ': Improves balance refresh and on-chain confirmation stability.'); ?></li>
                        </ul>
                    </div>

                    <div>
                        <div class="tw-font-semibold tw-text-gray-900 dark:tw-text-white"><?php echo $dtt('4. 名词解释', '4. Terminology'); ?></div>
                        <ul class="tw-list-disc tw-pl-5 tw-space-y-1">
                            <li><span class="tw-font-medium"><?php echo $dtt('生成批次', 'Generate Batch'); ?></span><?php echo $dtt('：筛选出达到阈值的待归集地址，创建本次归集任务。', ': Filters pending addresses that reach the threshold and creates the current collection task.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('补 Gas', 'Top Up Gas'); ?></span><?php echo $dtt('：给源地址补足主链币，保障 ERC20 转账可执行。', ': Tops up native gas to source addresses so ERC20 transfers can execute.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('链上确认', 'On-chain Confirm'); ?></span><?php echo $dtt('：等待补 Gas 交易确认，避免后续广播失败。', ': Waits for gas top-up confirmations to avoid failures in later broadcast steps.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('归集广播', 'Broadcast Collection'); ?></span><?php echo $dtt('：广播签名后的归集交易，把代币归集到主钱包。', ': Broadcasts signed collection transactions to move tokens into the master wallet.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('任务码', 'Task Code'); ?></span><?php echo $dtt('：离线签名器读取的任务数据，自动复制后也可手动复制。', ': The task payload read by the offline signer. It is auto-copied and can also be copied manually.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('已签名结果', 'Signed Result'); ?></span><?php echo $dtt('：离线签名器输出的 JSON，粘贴后执行广播。', ': The JSON output from the offline signer. Paste it back to execute the broadcast.'); ?></li>
                        </ul>
                    </div>

                    <div>
                        <div class="tw-font-semibold tw-text-gray-900 dark:tw-text-white"><?php echo $dtt('5. 标准操作步骤（新用户建议按此顺序）', '5. Standard Workflow'); ?></div>
                        <ol class="tw-list-decimal tw-pl-5 tw-space-y-1">
                            <li><?php echo $dtt('切换到正确链，确认右侧“已收款待处理地址”有目标币种和余额。', 'Switch to the correct chain and confirm the right-side pending list contains the target token and balance.'); ?></li>
                            <li><?php echo $dtt('在“高级参数配置”设置归集币种、最小归集阈值、链上确认等待秒数。', 'Set the collection token, minimum threshold, and confirmation wait time in Advanced Settings.'); ?></li>
                            <li><?php echo $dtt('点击“1) 生成离线签名任务码”，观察状态框输出是否成功。', 'Click "1) Generate Offline Signing Task Code" and check whether the status output succeeds.'); ?></li>
                            <li><?php echo $dtt('下载离线签名器，在离线环境导入任务码并完成签名。', 'Download the offline signer, import the task code in an offline environment, and finish signing.'); ?></li>
                            <li><?php echo $dtt('点击“2) 粘贴并执行已签名结果”，粘贴签名 JSON 并执行。', 'Click "2) Paste and Execute Signed Result", paste the signed JSON, and execute it.'); ?></li>
                            <li><?php echo $dtt('在“总归集记录 / 失败记录”检查最终结果。', 'Check the final result in All Collection Records / Failed Records.'); ?></li>
                        </ol>
                    </div>

                    <div>
                        <div class="tw-font-semibold tw-text-gray-900 dark:tw-text-white"><?php echo $dtt('6. 缺失项检查清单（失败先看这里）', '6. Checklist Before Troubleshooting'); ?></div>
                        <ul class="tw-list-disc tw-pl-5 tw-space-y-1">
                            <li><?php echo $dtt('当前链是否选对（BSC 或 ARB）。', 'Confirm the current chain is correct (BSC or ARB).'); ?></li>
                            <li><?php echo $dtt('当前币种是否选对（USDT 或 USDC）。', 'Confirm the current token is correct (USDT or USDC).'); ?></li>
                            <li><?php echo $dtt('主钱包地址是否与当前链匹配。', 'Confirm the master address matches the current chain.'); ?></li>
                            <li><?php echo $dtt('Xpub 是否已保存且路径前缀可用。', 'Confirm the Xpub is saved and the path prefix is valid.'); ?></li>
                            <li><?php echo $dtt('源地址是否有足够 Gas（BNB/ETH）。', 'Confirm source addresses have enough gas (BNB/ETH).'); ?></li>
                            <li><?php echo $dtt('待归集金额是否达到最小阈值。', 'Confirm the pending amount reaches the minimum threshold.'); ?></li>
                        </ul>
                    </div>

                    <div>
                        <div class="tw-font-semibold tw-text-gray-900 dark:tw-text-white"><?php echo $dtt('7. 常见问题排查', '7. Common Issues'); ?></div>
                        <ul class="tw-list-disc tw-pl-5 tw-space-y-1">
                            <li><span class="tw-font-medium"><?php echo $dtt('提示“没有达到阈值的可归集地址”', '"No eligible addresses reached the threshold"'); ?></span><?php echo $dtt('：通常是币种不一致或阈值过高，先核对币种标签再降低阈值。', ': This is usually caused by a token mismatch or a threshold that is too high. Check the token label first, then lower the threshold.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('只归集了部分地址', 'Only part of the addresses were collected'); ?></span><?php echo $dtt('：检查每个地址是否达到阈值、是否同一币种、是否 Gas 足够。', ': Check whether each address reached the threshold, uses the same token, and has sufficient gas.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('执行签名时报错 500/JSON 错误', '500/JSON error during signing'); ?></span><?php echo $dtt('：先确认已登录状态有效、签名 JSON 完整、格式为标准 JSON 对象。', ': First confirm your login session is still valid and the signed JSON is complete and in standard JSON format.'); ?></li>
                            <li><span class="tw-font-medium"><?php echo $dtt('提示 2FA', '2FA prompt'); ?></span><?php echo $dtt('：仅在关键步骤触发，输入 6 位谷歌验证码后继续执行。', ': It appears only during sensitive steps. Enter the 6-digit Google Authenticator code to continue.'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal"><?php echo $dtt('我知道了', 'Understood'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php if ($derived2faRequired): ?>
<div class="modal fade" id="derivedOtpModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content tw-rounded-xl">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $dtt('谷歌验证器验证', 'Google Authenticator Verification'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo $dtt('关闭', 'Close'); ?>"></button>
            </div>
            <div class="modal-body">
                <div class="tw-text-sm tw-text-gray-600 tw-mb-3"><?php echo $dtt('当前操作属于关键改动，请输入 6 位动态码后继续。', 'This is a sensitive action. Enter the 6-digit code to continue.'); ?></div>
                <input id="derivedOtpModalInput" type="text" inputmode="numeric" pattern="\\d{6}" maxlength="6" class="form-control" placeholder="<?php echo $dtt('输入 6 位动态码', 'Enter the 6-digit code'); ?>">
                <div class="tw-text-xs tw-text-gray-500 tw-mt-2"><?php echo $dtt('验证码仅用于本次关键操作提交。', 'This code is used only for this submission.'); ?></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?php echo __('merchant.common.cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="derivedOtpModalConfirmBtn"><?php echo $dtt('继续提交', 'Continue'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="signedResultModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content tw-rounded-xl">
            <div class="modal-header">
                <h5 class="modal-title"><?php echo $dtt('已签名结果', 'Signed Result'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo $dtt('关闭', 'Close'); ?>"></button>
            </div>
            <div class="modal-body">
                <div class="tw-text-center tw-text-lg md:tw-text-xl tw-font-semibold tw-text-gray-700 dark:tw-text-gray-200 tw-mb-3">
                    <?php echo $dtt('将离线签名器导出的已签名 JSON 粘贴到下方后执行', 'Paste the signed JSON exported by the offline signer below to execute'); ?>
                </div>
                <div class="tw-flex tw-items-center tw-justify-end tw-gap-2 tw-mb-2">
                    <button type="button" onclick="copyTextareaValue('offlineSignedResult', <?php echo json_encode($dtt('已签名结果', 'Signed Result')); ?>)" class="tw-text-xs tw-px-2 tw-py-1 tw-rounded tw-border tw-border-gray-300 hover:tw-bg-gray-50"><?php echo __('merchant.common.copy'); ?></button>
                    <button type="button" onclick="clearTextareaValue('offlineSignedResult', <?php echo json_encode($dtt('已签名结果', 'Signed Result')); ?>)" class="tw-text-xs tw-px-2 tw-py-1 tw-rounded tw-border tw-border-gray-300 hover:tw-bg-gray-50"><?php echo $dtt('清除', 'Clear'); ?></button>
                </div>
                <textarea id="offlineSignedResult" rows="14" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-800 tw-text-xs tw-font-mono" placeholder="<?php echo htmlspecialchars($dtt('将离线签名器导出的已签名 JSON 粘贴到这里', 'Paste the signed JSON exported by the offline signer here')); ?>"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?php echo $dtt('暂不执行', 'Not now'); ?></button>
                <button type="button" class="btn btn-primary" onclick="executeSignedFlow()"><?php echo $dtt('执行已签名结果', 'Execute Signed Result'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Batch Items Modal -->
<div class="modal fade" id="batchItemsModal" tabindex="-1" aria-labelledby="batchItemsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchItemsModalLabel"><?php echo $dtt('批次明细', 'Batch Items'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo $dtt('关闭', 'Close'); ?>"></button>
            </div>
            <div class="modal-body p-0">
                <div id="batchItemsLoading" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span class="ms-2"><?php echo $dtt('加载中...', 'Loading...'); ?></span>
                </div>
                <div id="batchItemsContent" class="d-none">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th><?php echo $dtt('来源地址', 'Source Address'); ?></th>
                                <th><?php echo $dtt('金额', 'Amount'); ?></th>
                                <th><?php echo $dtt('状态', 'Status'); ?></th>
                                <th>Tx Hash</th>
                                <th><?php echo $dtt('错误', 'Error'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="batchItemsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Logic Scripts -->
<script src="https://cdn.jsdelivr.net/npm/ethers@6.13.2/dist/ethers.umd.min.js"></script>
<script>
const latestBatchItems = <?php
echo json_encode(array_map(function ($it) use ($latestBatch, $selectedChain) {
    $payloadRow = json_decode((string)($it['qr_payload'] ?? ''), true);
    $transferData = '';
    if (is_array($payloadRow) && isset($payloadRow['data'])) {
        $transferData = preg_replace('/\s+/', '', (string)$payloadRow['data']);
    }
    $tokenContract = (string)($latestBatch['token_contract'] ?? '');
    if (is_array($payloadRow) && is_valid_evm_address((string)($payloadRow['token_contract'] ?? ''))) {
        $tokenContract = strtolower((string)$payloadRow['token_contract']);
    }
    return [
        'item_id' => (int)($it['id'] ?? 0),
        'chain' => (string)$selectedChain,
        'chain_id' => (int)($latestBatch['chain_id'] ?? 0),
        'from' => (string)($it['from_address'] ?? ''),
        'to' => (string)($it['to_address'] ?? ''),
        'token_symbol' => (string)($latestBatch['token_symbol'] ?? 'USDT'),
        'token_contract' => (string)$tokenContract,
        'amount_wei' => (string)($it['amount_wei'] ?? '0'),
        'data' => (string)$transferData,
        'derivation_path' => (string)($it['derivation_path'] ?? ''),
        'status' => (string)($it['status'] ?? ''),
    ];
}, $latestItems), JSON_UNESCAPED_UNICODE);
?>;
const ACTIVE_CHAIN = <?php echo json_encode((string)$selectedChain, JSON_UNESCAPED_UNICODE); ?>;
const ACTIVE_CHAIN_ID = <?php echo (int)($evmChains[$selectedChain]['chain_id'] ?? 0); ?>;
const ACTIVE_TOKEN_SYMBOL = <?php echo json_encode((string)$selectedTokenSymbol, JSON_UNESCAPED_UNICODE); ?>;
const ACTIVE_CHAIN_TOKEN_CONTRACTS = <?php
echo json_encode([
    'USDT' => (string)($evmChains[$selectedChain]['tokens']['USDT']['contract'] ?? ''),
    'USDC' => (string)($evmChains[$selectedChain]['tokens']['USDC']['contract'] ?? ''),
], JSON_UNESCAPED_UNICODE);
?>;
const ACTIVE_TOKEN_CONTRACT = String(ACTIVE_CHAIN_TOKEN_CONTRACTS[ACTIVE_TOKEN_SYMBOL] || '');
const ACTIVE_RPC_MAP = {
    bsc: 'https://bsc-dataseed.binance.org',
    eth: 'https://rpc.ankr.com/eth',
    polygon: 'https://polygon-rpc.com',
    arbitrum: 'https://arb1.arbitrum.io/rpc',
    optimism: 'https://mainnet.optimism.io',
    base: 'https://mainnet.base.org',
    avalanche: 'https://api.avax.network/ext/bc/C/rpc'
};
const CSRF_TOKEN = <?php echo json_encode((string)$admin_csrf_token, JSON_UNESCAPED_UNICODE); ?>;
const SERVER_FLASH_MESSAGE = <?php echo json_encode((string)$message, JSON_UNESCAPED_UNICODE); ?>;
const SERVER_FLASH_TYPE = <?php echo json_encode((string)$messageType, JSON_UNESCAPED_UNICODE); ?>;
const DERIVED_2FA_REQUIRED = <?php echo $derived2faRequired ? 'true' : 'false'; ?>;
const DERIVED_I18N = {
    otp_required: <?php echo json_encode($dtt('请输入 6 位谷歌验证码', 'Please enter the 6-digit Google Authenticator code'), JSON_UNESCAPED_UNICODE); ?>,
    refreshing: <?php echo json_encode($dtt('刷新中...', 'Refreshing...'), JSON_UNESCAPED_UNICODE); ?>,
    refreshed: <?php echo json_encode($dtt('刷新完成', 'Refresh complete'), JSON_UNESCAPED_UNICODE); ?>,
    refresh_failed: <?php echo json_encode($dtt('刷新失败', 'Refresh failed'), JSON_UNESCAPED_UNICODE); ?>,
    refresh_balance: <?php echo json_encode($dtt('刷新余额', 'Refresh Balance'), JSON_UNESCAPED_UNICODE); ?>,
    no_task_code: <?php echo json_encode($dtt('当前还没有可复制的任务码，请先生成离线签名任务', 'There is no task code to copy yet. Generate the offline signing task first.'), JSON_UNESCAPED_UNICODE); ?>,
    waiting: <?php echo json_encode($dtt('等待执行', 'Waiting'), JSON_UNESCAPED_UNICODE); ?>,
    step_prefix: <?php echo json_encode($dtt('步骤', 'Step '), JSON_UNESCAPED_UNICODE); ?>,
    system_ready: <?php echo json_encode($dtt('系统已就绪，等待执行命令...', 'System ready. Waiting for command...'), JSON_UNESCAPED_UNICODE); ?>,
    copied: <?php echo json_encode($dtt('派生地址已复制', 'Derived address copied'), JSON_UNESCAPED_UNICODE); ?>,
    copied_short: <?php echo json_encode($dtt('已复制', 'Copied'), JSON_UNESCAPED_UNICODE); ?>,
    copy_failed: <?php echo json_encode($dtt('复制失败，请手动复制', 'Copy failed, please copy manually'), JSON_UNESCAPED_UNICODE); ?>
};
const DYN_DEFAULTS = {
    safetyFactor: <?php echo json_encode((string)$dynSafetyFactorDefault, JSON_UNESCAPED_UNICODE); ?>,
    minTopupCoin: <?php echo json_encode((string)$dynMinTopupDefault, JSON_UNESCAPED_UNICODE); ?>,
    maxTopupCoin: <?php echo json_encode((string)$dynMaxTopupDefault, JSON_UNESCAPED_UNICODE); ?>,
    retryExtraCoin: <?php echo json_encode((string)$dynRetryExtraDefault, JSON_UNESCAPED_UNICODE); ?>,
    minGasPriceGwei: <?php echo json_encode((string)$dynMinGasGweiDefault, JSON_UNESCAPED_UNICODE); ?>,
    defaultSweepGasLimit: <?php echo json_encode((string)$dynDefaultSweepGasLimit, JSON_UNESCAPED_UNICODE); ?>
};
let workingBatchItems = Array.isArray(latestBatchItems) ? latestBatchItems.slice() : [];
let lastUnsignedTaskCode = '';
const DERIVED_CHAIN_NOTICE_KEY = 'derived_chain_switch_notice_v1';

function notifyUnified(type, message) {
    const mapped = (String(type || 'info').toLowerCase() === 'error' || String(type || '').toLowerCase() === 'danger') ? 'danger' : String(type || 'info').toLowerCase();
    const text = String(message || '');
    if (!text) return;
    if (window.UapiNotify && typeof window.UapiNotify.show === 'function') {
        window.UapiNotify.show(mapped, text);
        return;
    }
    if (window.showUapiToast && typeof window.showUapiToast === 'function') {
        window.showUapiToast(mapped === 'danger' ? 'error' : mapped, text);
        return;
    }
    if (window.toast && typeof window.toast === 'function') {
        window.toast(text, mapped === 'danger' ? 'error' : mapped);
    }
}

function setPendingChainNotice(type, message) {
    try {
        const payload = {
            type: String(type || 'info'),
            message: String(message || ''),
            ts: Date.now()
        };
        sessionStorage.setItem(DERIVED_CHAIN_NOTICE_KEY, JSON.stringify(payload));
    } catch (_) {}
}

function consumePendingChainNotice() {
    try {
        const raw = sessionStorage.getItem(DERIVED_CHAIN_NOTICE_KEY);
        if (!raw) return;
        sessionStorage.removeItem(DERIVED_CHAIN_NOTICE_KEY);
        const data = JSON.parse(raw);
        const msg = String(data && data.message ? data.message : '').trim();
        if (!msg) return;
        notifyUnified(String(data && data.type ? data.type : 'info'), msg);
    } catch (_) {}
}
const SENSITIVE_2FA_ACTIONS = new Set([
    'save_master', 'save_xpub_config', 'disable_legacy_pool',
    'rollback_collected_item', 'rollback_batch', 'mark_sent', 'broadcast_signed', 'broadcast_signed_batch',
    'broadcast_raw_batch', 'broadcast_raw_single', 'save_gas_profile'
]);
let derivedOtpModalInst = null;
let derivedOtpModalPromise = null;

function openSignedResultModal() {
    const el = document.getElementById('signedResultModal');
    if (!el || !window.bootstrap) return;
    const inst = bootstrap.Modal.getOrCreateInstance(el);
    inst.show();
}

function closeSignedResultModal() {
    const el = document.getElementById('signedResultModal');
    if (!el || !window.bootstrap) return;
    const inst = bootstrap.Modal.getOrCreateInstance(el);
    inst.hide();
}

function getDerivedOtpValue() {
    const otpInput = document.getElementById('derivedSecurityOtp');
    return otpInput ? String(otpInput.value || '').trim() : '';
}

function setDerivedOtpValue(v) {
    const otpInput = document.getElementById('derivedSecurityOtp');
    if (otpInput) otpInput.value = String(v || '').trim();
}

function promptDerivedOtpModal() {
    if (!DERIVED_2FA_REQUIRED) return Promise.resolve(true);
    if (derivedOtpModalPromise) return derivedOtpModalPromise;
    const modalEl = document.getElementById('derivedOtpModal');
    const inputEl = document.getElementById('derivedOtpModalInput');
    const confirmEl = document.getElementById('derivedOtpModalConfirmBtn');
    if (!modalEl || !inputEl || !confirmEl || !window.bootstrap) {
        return Promise.resolve(false);
    }
    if (!derivedOtpModalInst) {
        derivedOtpModalInst = bootstrap.Modal.getOrCreateInstance(modalEl);
    }
    derivedOtpModalPromise = new Promise((resolve) => {
        const cleanup = () => {
            confirmEl.removeEventListener('click', onConfirm);
            modalEl.removeEventListener('hidden.bs.modal', onHidden);
            derivedOtpModalPromise = null;
        };
        const onHidden = () => {
            cleanup();
            resolve(false);
        };
        const onConfirm = () => {
            const code = String(inputEl.value || '').trim();
            if (!/^\d{6}$/.test(code)) {
                notifyUnified('warning', DERIVED_I18N.otp_required);
                inputEl.focus();
                return;
            }
            setDerivedOtpValue(code);
            inputEl.value = code;
            cleanup();
            derivedOtpModalInst.hide();
            resolve(true);
        };
        confirmEl.addEventListener('click', onConfirm);
        modalEl.addEventListener('hidden.bs.modal', onHidden, { once: true });
        inputEl.value = getDerivedOtpValue();
        derivedOtpModalInst.show();
        setTimeout(() => inputEl.focus(), 80);
    });
    return derivedOtpModalPromise;
}

async function ensureOtpForAction(actionName) {
    if (!DERIVED_2FA_REQUIRED) return true;
    const act = String(actionName || '').trim();
    if (!SENSITIVE_2FA_ACTIONS.has(act)) return true;
    if (/^\d{6}$/.test(getDerivedOtpValue())) return true;
    return await promptDerivedOtpModal();
}

document.addEventListener('submit', function(e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !DERIVED_2FA_REQUIRED) return;
    const actionInput = form.querySelector('input[name="action"]');
    const actionName = String(actionInput ? actionInput.value : '').trim();
    if (!SENSITIVE_2FA_ACTIONS.has(actionName)) return;
    const currentOtp = getDerivedOtpValue();
    if (/^\d{6}$/.test(currentOtp)) {
        let hidden = form.querySelector('input[name="security_otp"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'security_otp';
            form.appendChild(hidden);
        }
        hidden.value = currentOtp;
        return;
    }
    e.preventDefault();
    promptDerivedOtpModal().then((ok) => {
        if (!ok) return;
        let hidden = form.querySelector('input[name="security_otp"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'security_otp';
            form.appendChild(hidden);
        }
        hidden.value = getDerivedOtpValue();
        form.submit();
    });
}, true);

document.addEventListener('DOMContentLoaded', function () {
    consumePendingChainNotice();
    if (!SERVER_FLASH_MESSAGE) return;
    const t = String(SERVER_FLASH_TYPE || 'info').toLowerCase();
    const mapped = t === 'danger' ? 'danger' : (t === 'warning' ? 'warning' : (t === 'success' ? 'success' : 'info'));
    notifyUnified(mapped, SERVER_FLASH_MESSAGE);
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-refresh-balance-form').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const walletId = String(form.querySelector('input[name="wallet_id"]')?.value || '').trim();
            if (!walletId) return;
            const btn = form.querySelector('button[type="submit"]');
            const oldText = btn ? btn.textContent : '';
            if (btn) {
                btn.disabled = true;
                btn.textContent = DERIVED_I18N.refreshing;
            }
            try {
                const ret = await postActionJson('refresh_balance', { wallet_id: walletId });
                notifyUnified(ret && ret.ok ? 'success' : 'warning', (ret && ret.message) ? String(ret.message) : DERIVED_I18N.refreshed);
            } catch (err) {
                notifyUnified('danger', String(err && err.message ? err.message : err || DERIVED_I18N.refresh_failed));
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = oldText || DERIVED_I18N.refresh_balance;
                }
            }
        });
    });
});

function appendFlowStatus(msg) {
    const board = document.getElementById('flowStatusBoard');
    if (!board) return;
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    const ss = String(now.getSeconds()).padStart(2, '0');
    const line = document.createElement('div');
    const text = String(msg || '');
    const isErr = text.includes('失败') || text.includes('error') || text.includes('Error') || text.includes('[FAIL]');
    const isRun = text.includes('[RUN]');
    const isOk = text.includes('[OK]');
    line.className = 'tw-mb-1 ' + (isErr ? 'tw-text-red-400' : (isRun ? 'tw-text-amber-300' : (isOk ? 'tw-text-emerald-300' : 'tw-text-gray-300')));
    line.textContent = `[${hh}:${mm}:${ss}] ${msg}`;
    board.appendChild(line);
    board.scrollTop = board.scrollHeight;
}

function setTaskCodeCopyState(enabled) {
    const btn = document.getElementById('copyTaskCodeBtn');
    if (!btn) return;
    if (enabled) {
        btn.classList.remove('tw-opacity-50', 'tw-pointer-events-none');
    } else {
        btn.classList.add('tw-opacity-50', 'tw-pointer-events-none');
    }
}

async function copyLatestTaskCode() {
    if (!String(lastUnsignedTaskCode || '').trim()) {
        notifyUnified('warning', DERIVED_I18N.no_task_code);
        return;
    }
    await copyPlainText(lastUnsignedTaskCode, '待签名任务码');
}

function flowEvent({ step, status = 'INFO', address = '-', txHash = '-', detail = '' }) {
    const stepTxt = String(step || '-');
    const statusTxt = String(status || 'INFO').toUpperCase();
    const addrTxt = String(address || '-');
    const txTxt = String(txHash || '-');
    const detailTxt = String(detail || '');
    appendFlowStatus(`[${statusTxt}] [${stepTxt}] [${addrTxt}] tx=${txTxt} ${detailTxt}`.trim());
}

function clearFlowStatus() {
    const board = document.getElementById('flowStatusBoard');
    if (board) board.innerHTML = '<div class="tw-text-gray-500">' + DERIVED_I18N.system_ready + '</div>';
    setFlowProgress(0, '');
}

function setFlowProgress(step, text) {
    const bar = document.getElementById('flowProgressBar');
    const pctEl = document.getElementById('flowProgressPercent');
    const textEl = document.getElementById('flowProgressText');
    const items = document.querySelectorAll('.stepper-item');
    const s = Math.max(0, Math.min(4, Number(step) || 0));
    const percent = Math.round((s / 4) * 100);
    if (bar) bar.style.width = percent + '%';
    if (pctEl) pctEl.textContent = percent + '%';
    if (textEl) textEl.textContent = text || (s <= 0 ? DERIVED_I18N.waiting : (DERIVED_I18N.step_prefix + s));
    items.forEach((item, index) => {
        const stepNum = index + 1;
        item.classList.remove('active', 'completed');
        if (stepNum < s) item.classList.add('completed');
        if (stepNum === s) item.classList.add('active');
    });
}

function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

async function copyTextareaValue(id, label) {
    try {
        const el = document.getElementById(id);
        const text = String(el?.value || '');
        if (!text.trim()) throw new Error((label || '内容') + '为空');
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
            flowEvent({ step: '离线签名', status: 'OK', detail: `${label || '内容'}已复制` });
            return;
        }
        if (!el) throw new Error('未找到文本框');
        el.focus();
        el.select();
        const ok = document.execCommand('copy');
        if (!ok) throw new Error((label || '内容') + '复制失败');
        flowEvent({ step: '离线签名', status: 'OK', detail: `${label || '内容'}已复制` });
    } catch (e) {
        flowEvent({ step: '离线签名', status: 'FAIL', detail: String(e?.message || e) });
    }
}

async function copyPlainText(text, label) {
    const v = String(text || '');
    if (!v.trim()) throw new Error((label || '内容') + '为空');
    if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(v);
        return true;
    }
    const ta = document.createElement('textarea');
    ta.value = v;
    ta.setAttribute('readonly', 'readonly');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    ta.style.top = '-9999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    if (!ok) throw new Error((label || '内容') + '复制失败');
    return true;
}

function clearTextareaValue(id, label) {
    const el = document.getElementById(id);
    if (!el) {
        flowEvent({ step: '离线签名', status: 'FAIL', detail: '未找到文本框' });
        return;
    }
    el.value = '';
    flowEvent({ step: '离线签名', status: 'OK', detail: `${label || '内容'}已清除` });
}

async function pMapLimit(items, limit, worker) {
    const out = new Array(items.length);
    let idx = 0;
    const runners = Array.from({ length: Math.min(limit, items.length) }, async () => {
        while (true) {
            const i = idx++;
            if (i >= items.length) break;
            out[i] = await worker(items[i], i);
        }
    });
    await Promise.all(runners);
    return out;
}

function getActiveRpcUrl() {
    return String(ACTIVE_RPC_MAP[String(ACTIVE_CHAIN || '').toLowerCase()] || '');
}

function getFlowTokenSymbol() {
    const v = String(document.getElementById('flowTokenSymbol')?.value || ACTIVE_TOKEN_SYMBOL || 'USDT').toUpperCase().trim();
    return (v === 'USDC') ? 'USDC' : 'USDT';
}

function getFlowTokenContract() {
    const symbol = getFlowTokenSymbol();
    return String((ACTIVE_CHAIN_TOKEN_CONTRACTS && ACTIVE_CHAIN_TOKEN_CONTRACTS[symbol]) || '');
}

function getReadProvider() {
    const rpc = getActiveRpcUrl();
    if (!rpc) throw new Error('当前链未配置可用 RPC');
    return new ethers.JsonRpcProvider(rpc, Number(ACTIVE_CHAIN_ID));
}

async function getDynamicGasPriceWei(provider, minGasPriceWei) {
    const cacheKey = 'uapi:lastGasPriceWei:' + ACTIVE_CHAIN;
    try {
        const fee = await provider.getFeeData();
        const gp = fee && fee.gasPrice ? BigInt(fee.gasPrice) : 0n;
        if (gp <= 0n) throw new Error('gasPrice empty');
        const safeGp = gp < BigInt(minGasPriceWei || 0n) ? BigInt(minGasPriceWei || 0n) : gp;
        sessionStorage.setItem(cacheKey, safeGp.toString());
        return safeGp;
    } catch (e) {
        const cached = sessionStorage.getItem(cacheKey);
        if (!cached || !/^[0-9]+$/.test(cached)) throw new Error('RPC gasPrice 获取失败，且无缓存值');
        const c = BigInt(cached);
        return c < BigInt(minGasPriceWei || 0n) ? BigInt(minGasPriceWei || 0n) : c;
    }
}

function parseDynConcurrency() {
    const v = String(document.getElementById('dynConcurrencyPair')?.value || '3/2').trim();
    const m = v.match(/^(\d+)\s*\/\s*(\d+)$/);
    const topup = Math.max(1, Math.min(8, m ? parseInt(m[1], 10) : 3));
    const sweep = Math.max(1, Math.min(8, m ? parseInt(m[2], 10) : 2));
    return { topup, sweep };
}

function getDynPollThreshold() {
    const n = parseInt(document.getElementById('dynPollAddressThreshold')?.value || '20', 10);
    return Math.max(1, Math.min(100, Number.isFinite(n) ? n : 20));
}

function getDynTopupConfig() {
    const safety = Number(document.getElementById('dynSafetyFactor')?.value || DYN_DEFAULTS.safetyFactor || '1.45');
    const minCoin = String(document.getElementById('dynMinTopupCoin')?.value || DYN_DEFAULTS.minTopupCoin || '0.00025').trim();
    const maxCoin = String(document.getElementById('dynMaxTopupCoin')?.value || DYN_DEFAULTS.maxTopupCoin || '0.0008').trim();
    const retryCoin = String(document.getElementById('dynRetryExtraCoin')?.value || DYN_DEFAULTS.retryExtraCoin || '0.0002').trim();
    const minGasPriceGwei = String(document.getElementById('dynMinGasPriceGwei')?.value || DYN_DEFAULTS.minGasPriceGwei || '1').trim();
    const defaultSweepGasLimit = BigInt(Math.max(21000, parseInt(document.getElementById('dynDefaultSweepGasLimit')?.value || DYN_DEFAULTS.defaultSweepGasLimit || '100000', 10) || 100000));
    return {
        safetyFactor: Number.isFinite(safety) && safety > 1 ? safety : 1.4,
        minTopupWei: toWeiByUnits(minCoin, 18, '最小补给金额'),
        maxTopupWei: toWeiByUnits(maxCoin, 18, '最大补给金额'),
        retryExtraWei: toWeiByUnits(retryCoin, 18, '失败二次补给金额'),
        minGasPriceWei: toWeiByUnits(minGasPriceGwei, 9, '最小GasPrice'),
        defaultSweepGasLimit
    };
}

function calcDynamicTopupWei(gasPriceWei, gasLimit, cfg) {
    const factorScaled = BigInt(Math.round(Number(cfg.safetyFactor) * 10000));
    let wei = (BigInt(gasPriceWei) * BigInt(gasLimit) * factorScaled) / 10000n;
    if (wei < cfg.minTopupWei) wei = cfg.minTopupWei;
    if (wei > cfg.maxTopupWei) wei = cfg.maxTopupWei;
    return wei;
}

function isGasRelatedErrorText(msg) {
    const m = String(msg || '').toLowerCase();
    return m.includes('insufficient funds') || m.includes('out of gas') || m.includes('intrinsic gas too low') || m.includes('gas required exceeds') || m.includes('fee too low');
}

async function estimateSweepGasLimit(provider, item, fallbackGasLimit) {
    try {
        const est = await provider.estimateGas({
            from: String(item.from),
            to: String(item.token_contract),
            value: 0,
            data: String(item.data || '0x')
        });
        return est > 0n ? BigInt(est) : BigInt(fallbackGasLimit);
    } catch (e) {
        return BigInt(fallbackGasLimit);
    }
}

async function waitTopupConfirmByHashes(successDetails, timeoutSec) {
    const provider = getReadProvider();
    const timeout = Math.max(5, Math.min(120, parseInt(String(timeoutSec || 30), 10) || 30));
    const pending = {};
    (successDetails || []).forEach((r, idx) => {
        const h = String(r && r.tx_hash ? r.tx_hash : '').trim();
        if (/^0x[a-fA-F0-9]{64}$/.test(h)) pending[h.toLowerCase()] = { hash: h, address: String(r.address || '-') };
    });
    let left = Object.keys(pending).length;
    if (left === 0) return { ok: true, confirmed: 0, total: 0 };

    flowEvent({ step: '链上确认', status: 'RUN', detail: `按交易确认轮询：${left} 笔，超时 ${timeout} 秒` });
    for (let sec = 0; sec < timeout && left > 0; sec++) {
        const keys = Object.keys(pending);
        for (const k of keys) {
            const it = pending[k];
            try {
                const rc = await provider.getTransactionReceipt(it.hash);
                if (rc && rc.blockNumber) {
                    flowEvent({ step: '链上确认', status: 'OK', address: it.address, txHash: it.hash, detail: '补Gas已确认' });
                    delete pending[k];
                    left--;
                }
            } catch (_) {}
        }
        if (left <= 0) break;
        if (sec % 3 === 0) {
            flowEvent({ step: '链上确认', status: 'INFO', detail: `待确认 ${left} 笔，剩余 ${timeout - sec} 秒` });
        }
        await sleep(1000);
    }
    const remain = Object.keys(pending).length;
    if (remain > 0) {
        flowEvent({ step: '链上确认', status: 'INFO', detail: `轮询结束，仍有 ${remain} 笔未确认，继续后续流程` });
    }
    return { ok: true, confirmed: (Object.keys(successDetails || {}).length - remain), total: Object.keys(successDetails || {}).length };
}

async function postActionJson(action, fields) {
    const allowed = await ensureOtpForAction(action);
    if (!allowed) {
        throw new Error('已取消操作');
    }
    const params = new URLSearchParams();
    params.set('csrf_token', CSRF_TOKEN);
    params.set('action', action);
    params.set('chain', ACTIVE_CHAIN);
    params.set('token_symbol', getFlowTokenSymbol());
    params.set('ajax', '1');
    const otpVal = getDerivedOtpValue();
    if (otpVal) {
        params.set('security_otp', otpVal);
    }
    Object.keys(fields || {}).forEach(function (k) {
        params.set(k, String(fields[k] ?? ''));
    });
    const resp = await fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
        credentials: 'same-origin'
    });
    const rawText = await resp.text();
    let data = null;
    if (rawText !== '') {
        try {
            data = JSON.parse(rawText);
        } catch (e) {
            const compact = rawText.replace(/\s+/g, ' ').trim().slice(0, 180);
            throw new Error('服务端返回非 JSON：' + (compact || ('HTTP ' + resp.status)));
        }
    }
    if (!resp.ok) {
        const serverMsg = data && data.message ? String(data.message) : '';
        if (DERIVED_2FA_REQUIRED && /谷歌验证码|动态码|2FA/i.test(serverMsg)) {
            setDerivedOtpValue('');
        }
        throw new Error(serverMsg || ('请求失败: HTTP ' + resp.status));
    }
    if (!data || typeof data !== 'object') {
        throw new Error('服务端返回为空，请重试');
    }
    if (data.ok === false && DERIVED_2FA_REQUIRED && /谷歌验证码|动态码|2FA/i.test(String(data.message || ''))) {
        setDerivedOtpValue('');
    }
    return data;
}

function notifyChainSwitch(msg, ok) {
    const text = String(msg || '');
    notifyUnified(ok ? 'success' : 'danger', text);
}

async function copyDerivedAddress(address, btn) {
    const text = String(address || '').trim();
    if (!text) return;
    try {
        await navigator.clipboard.writeText(text);
        notifyUnified('success', DERIVED_I18N.copied);
        if (btn) {
            const old = btn.textContent;
            btn.textContent = DERIVED_I18N.copied_short;
            setTimeout(function () { btn.textContent = old; }, 900);
        }
    } catch (e) {
        notifyUnified('danger', DERIVED_I18N.copy_failed);
    }
}

async function jumpChain(chain) {
    const nextChain = String(chain || '').trim().toLowerCase();
    if (!nextChain || nextChain === String(ACTIVE_CHAIN || '').toLowerCase()) return;
    try {
        const ret = await postActionJson('switch_chain', { chain: nextChain, token_symbol: getFlowTokenSymbol() });
        const msg = (ret && ret.message) ? String(ret.message) : '已启用当前链作为派生钱包收款';
        setPendingChainNotice('success', msg);
    } catch (e) {
        setPendingChainNotice('danger', '保存网络失败，已切换页面后重试。');
    }
    const url = new URL(window.location.href);
    url.searchParams.set('chain', nextChain);
    url.searchParams.set('token', getFlowTokenSymbol());
    window.location.href = url.toString();
}

function switchRecordsTab(tab) {
    const tabs = {
        batch: document.getElementById('recordsTabBatch'),
        total: document.getElementById('recordsTabTotal'),
        derived: document.getElementById('recordsTabDerived'),
        failed: document.getElementById('recordsTabFailed'),
        unsettled: document.getElementById('recordsTabUnsettled')
    };
    const btns = {
        batch: document.getElementById('recordsTabBatchBtn'),
        total: document.getElementById('recordsTabTotalBtn'),
        derived: document.getElementById('recordsTabDerivedBtn'),
        failed: document.getElementById('recordsTabFailedBtn'),
        unsettled: document.getElementById('recordsTabUnsettledBtn')
    };
    Object.keys(tabs).forEach(function (k) {
        if (tabs[k]) tabs[k].classList.toggle('tw-hidden', k !== tab);
        if (btns[k]) {
            const active = k === tab;
            btns[k].classList.toggle('tw-bg-primary', active);
            btns[k].classList.toggle('tw-text-white', active);
            btns[k].classList.toggle('tw-bg-white', !active);
            btns[k].classList.toggle('dark:tw-bg-gray-800', !active);
            btns[k].classList.toggle('tw-text-gray-700', !active);
            btns[k].classList.toggle('dark:tw-text-gray-200', !active);
        }
    });
}

function setFlowToken(token) {
    // Sync hidden input
    const hidden = document.getElementById('flowTokenSymbol');
    if (hidden) hidden.value = token;
    // Update toggle button styles
    document.querySelectorAll('#tokenToggleGroup button').forEach(function(btn) {
        const t = btn.id.replace('tokenToggle', '');
        const isActive = t === token;
        btn.classList.remove('tw-bg-green-500', 'tw-bg-blue-500', 'tw-text-white',
            'tw-bg-white', 'dark:tw-bg-gray-700', 'tw-text-gray-500', 'dark:tw-text-gray-400', 'hover:tw-bg-gray-50');
        if (isActive) {
            btn.classList.add(t === 'USDC' ? 'tw-bg-blue-500' : 'tw-bg-green-500', 'tw-text-white');
        } else {
            btn.classList.add('tw-bg-white', 'dark:tw-bg-gray-700', 'tw-text-gray-500', 'dark:tw-text-gray-400', 'hover:tw-bg-gray-50');
        }
    });
}

function filterUnsettledTab(token) {
    ['ALL', 'USDT', 'USDC'].forEach(function(t) {
        const btn = document.getElementById('ufTab' + t);
        if (!btn) return;
        const active = t === token;
        btn.classList.toggle('tw-bg-primary', active);
        btn.classList.toggle('tw-text-white', active);
        btn.classList.toggle('tw-bg-gray-100', !active);
        btn.classList.toggle('dark:tw-bg-gray-700', !active);
        btn.classList.toggle('tw-text-gray-600', !active);
        btn.classList.toggle('dark:tw-text-gray-300', !active);
    });
    const tbody = document.getElementById('unsettledTableBody');
    if (!tbody) return;
    tbody.querySelectorAll('tr[data-currencies]').forEach(function(row) {
        if (token === 'ALL') {
            row.style.display = '';
        } else {
            const curs = (row.dataset.currencies || '').split(',').map(c => c.trim().toUpperCase());
            row.style.display = curs.includes(token) ? '' : 'none';
        }
    });
}

function toggleHidden(el, hide) {
    if (!el) return;
    el.classList.toggle('tw-hidden', !!hide);
    el.classList.toggle('d-none', !!hide);
}

function toBigHex(v) {
    const s = String(v || '').trim();
    if (s === '') throw new Error('值为空');
    if (/^0x[0-9a-fA-F]+$/.test(s)) return s.toLowerCase();
    if (!/^[0-9]+$/.test(s)) throw new Error('仅支持十进制或0x十六进制');
    return '0x' + BigInt(s).toString(16);
}

function toWeiByUnits(value, unitDecimals, label) {
    const s = String(value || '').trim();
    if (s === '') throw new Error((label || '数值') + ' 不能为空');
    if (!/^\d+(\.\d+)?$/.test(s)) throw new Error((label || '数值') + ' 格式无效');
    return ethers.parseUnits(s, unitDecimals);
}

function normalizeMnemonic(s) {
    return String(s || '').trim().replace(/\s+/g, ' ');
}

function getCheckedValue(name) {
    const el = document.querySelector('input[name="' + name + '"]:checked');
    return el ? String(el.value || '').trim() : '';
}

function getGasMnemonicAndPassphrase() {
    const source = String(document.getElementById('gasWalletSource')?.value || 'local');
    const gasPass = String(document.getElementById('gasPassphrase')?.value || '').trim();
    const gasMnemonic = normalizeMnemonic(document.getElementById('gasMnemonic')?.value || '');
    return { mnemonic: gasMnemonic, passphrase: gasPass, source: source };
}

function setGasWalletModeUI() {
    const signerMode = getCheckedValue('gasSignerMode');
    const source = String(document.getElementById('gasWalletSource')?.value || 'local');
    toggleHidden(document.getElementById('gasPrivateKeyWrap'), signerMode !== 'private_key');
    toggleHidden(document.getElementById('gasMnemonicWrap'), signerMode !== 'mnemonic');
    toggleHidden(document.getElementById('gasPassphraseWrap'), !(signerMode === 'mnemonic' && source !== 'external'));
}

function setGasSignerModeUI() { setGasWalletModeUI(); }

function setSweepSignerModeUI() {
    const mode = getCheckedValue('sweepSignerMode');
    toggleHidden(document.getElementById('sweepMnemonicWrap'), mode !== 'mnemonic');
    toggleHidden(document.getElementById('sweepPassphraseWrap'), mode !== 'mnemonic');
    toggleHidden(document.getElementById('sweepPrivateKeyWrap'), mode !== 'private_key');
}

function toGasPriceWeiFromFeeCoin(feeCoinInputId, gasLimit, label) {
    const feeWei = toWeiByUnits(document.getElementById(feeCoinInputId).value, 18, label);
    const limit = BigInt(gasLimit);
    if (limit <= 0n) throw new Error('gas limit 无效');
    const gasPrice = feeWei / limit;
    if (gasPrice <= 0n) throw new Error((label || '手续费') + ' 过小，请增大');
    return gasPrice;
}

function buildPathCandidates(profile, scanDepth) {
    const depth = Math.max(20, Math.min(10000, parseInt(scanDepth || '120', 10) || 120));
    const out = [];
    const push = (p) => { if (!out.includes(p)) out.push(p); };
    if (profile === 'ledger_live') {
        for (let account = 0; account < depth; account++) push(`m/44'/60'/${account}'/0/0`);
    } else if (profile === 'auto') {
        for (let i = 0; i < depth; i++) push(`m/44'/60'/0'/0/${i}`);
        for (let i = 0; i < depth; i++) push(`m/44'/60'/0'/1/${i}`);
        for (let i = 0; i < depth; i++) push(`m/44'/60'/0'/${i}`);
        for (let account = 0; account < depth; account++) push(`m/44'/60'/${account}'/0/0`);
        for (let account = 0; account < depth; account++) push(`m/44'/60'/${account}'/0`);
    } else {
        for (let i = 0; i < depth; i++) push(`m/44'/60'/0'/0/${i}`);
    }
    return out;
}

async function findPathByAddressAsync(mnemonic, passphrase, expectedAddress, profile, depth) {
    const target = String(expectedAddress || '').trim().toLowerCase();
    if (!/^0x[a-f0-9]{40}$/.test(target)) throw new Error('目标地址格式无效');
    const passCandidates = Array.from(new Set([String(passphrase || ''), '']));
    const quick = [
        String(document.getElementById('gasFunderPath')?.value || '').trim(),
        "m/44'/60'/0'/0/0",
        "m/44'/60'/0'/0/1",
        "m/44'/60'/1'/0/0",
        "m/44'/60'/0'/1/0"
    ].filter(Boolean);
    for (const pp of passCandidates) {
        for (const p of quick) {
            try {
                const w = ethers.HDNodeWallet.fromPhrase(mnemonic, pp, p);
                if (w.address.toLowerCase() === target) return { path: p, passphrase: pp };
            } catch (_) {}
        }
    }
    const candidates = buildPathCandidates(profile, depth);
    for (let i = 0; i < candidates.length; i++) {
        const path = candidates[i];
        for (const pp of passCandidates) {
            try {
                const w = ethers.HDNodeWallet.fromPhrase(mnemonic, pp, path);
                if (w.address.toLowerCase() === target) return { path: path, passphrase: pp };
            } catch (_) {}
        }
        if (i % 200 === 0) await new Promise(resolve => setTimeout(resolve, 0));
    }
    return { path: '', passphrase: String(passphrase || '') };
}

async function fetchGasNonceNow(resolvedWallet) {
    try {
        const address = resolvedWallet && resolvedWallet.wallet
            ? resolvedWallet.wallet.address
            : String(document.getElementById('gasFunderExpectedAddress')?.value || '').trim();
        if (!/^0x[a-fA-F0-9]{40}$/.test(address)) throw new Error('请先填写有效 Gas 地址，或先测试匹配地址');
        const resp = await postActionJson('fetch_nonce', { address: address });
        if (!resp || !resp.ok) throw new Error((resp && resp.message) ? resp.message : '读取 nonce 失败');
        document.getElementById('gasStartNonce').value = String(resp.nonce || '0x0');
        appendFlowStatus('nonce 已自动更新：' + String(resp.nonce || '0x0') + ' [' + address + ']');
        return resp.nonce || '0x0';
    } catch (e) {
        appendFlowStatus('读取 nonce 失败：' + (e && e.message ? e.message : String(e)));
        return null;
    }
}

async function resolveGasWalletForTopup() {
    const warnings = [];
    const signerMode = getCheckedValue('gasSignerMode');
    if (!signerMode) throw new Error('请先选择 Gas 签名方式（助记词或私钥）');
    if (signerMode === 'private_key') {
        const pk = String(document.getElementById('gasFunderPrivateKey')?.value || '').trim();
        if (!/^0x[a-fA-F0-9]{64}$/.test(pk)) throw new Error('Gas主钱包私钥格式错误');
        return { wallet: new ethers.Wallet(pk), warnings };
    }

    const creds = getGasMnemonicAndPassphrase();
    const mnemonic = creds.mnemonic;
    let passphrase = creds.passphrase;
    let path = String(document.getElementById('gasFunderPath')?.value || '').trim();
    const expected = String(document.getElementById('gasFunderExpectedAddress')?.value || '').trim();
    const depth = String(document.getElementById('gasPathScanDepth')?.value || '1200');
    const profile = String(document.getElementById('gasPathProfile')?.value || 'auto');
    if (!mnemonic) throw new Error('请填写 Gas 主钱包助记词');
    if (!ethers.Mnemonic.isValidMnemonic(mnemonic)) throw new Error('助记词格式错误');

    if (!path && expected) {
        appendFlowStatus('未填写路径，开始自动识别...');
        const found = await findPathByAddressAsync(mnemonic, passphrase, expected, profile, depth);
        if (found.path) {
            path = found.path;
            passphrase = found.passphrase;
            document.getElementById('gasFunderPath').value = path;
        }
    }
    if (!path) path = "m/44'/60'/0'/0/0";
    let wallet = ethers.HDNodeWallet.fromPhrase(mnemonic, passphrase, path);

    if (expected && wallet.address.toLowerCase() !== expected.toLowerCase()) {
        const found2 = await findPathByAddressAsync(mnemonic, passphrase, expected, profile, depth);
        if (found2.path) {
            path = found2.path;
            passphrase = found2.passphrase;
            document.getElementById('gasFunderPath').value = path;
            wallet = ethers.HDNodeWallet.fromPhrase(mnemonic, passphrase, path);
        } else {
            warnings.push('未匹配到填写地址，将按当前路径地址签名广播（' + wallet.address + '）');
        }
    }
    return { wallet, warnings };
}

async function testGasAddressMatch() {
    try {
        const ret = await resolveGasWalletForTopup();
        if (ret.warnings && ret.warnings.length) ret.warnings.forEach(w => appendFlowStatus('提示：' + w));
        appendFlowStatus('匹配成功：最终 Gas 地址 ' + ret.wallet.address);
    } catch (e) {
        appendFlowStatus('匹配失败：' + (e && e.message ? e.message : String(e)));
    }
}

async function signAndBroadcastGasBatch() {
    try {
        if (!window.ethers) throw new Error('ethers 未加载');
        const provider = getReadProvider();
        const dyn = getDynTopupConfig();
        const conc = parseDynConcurrency();
        const resolved = await resolveGasWalletForTopup();
        const wallet = resolved.wallet;
        if (resolved.warnings && resolved.warnings.length) resolved.warnings.forEach(w => appendFlowStatus('提示：' + w));
        const nonceHex = await fetchGasNonceNow(resolved);
        if (nonceHex) document.getElementById('gasStartNonce').value = String(nonceHex);

        let nextNonce = BigInt(toBigHex(document.getElementById('gasStartNonce').value || '0x0'));
        const nextLocalNonce = () => {
            const out = nextNonce;
            nextNonce += 1n;
            return out;
        };
        const pending = (workingBatchItems || []).filter(it => String(it.status) !== 'broadcasted');
        if (!pending.length) throw new Error('没有待归集地址可补Gas');

        const seen = {};
        const targets = [];
        pending.forEach((it) => {
            const a = String(it.from || '').toLowerCase();
            if (/^0x[a-f0-9]{40}$/.test(a) && !seen[a]) {
                seen[a] = true;
                targets.push({
                    address: a,
                    sampleItem: it
                });
            }
        });
        if (!targets.length) throw new Error('无可用补Gas目标地址');

        const results = await pMapLimit(targets, conc.topup, async (target) => {
            let gasPriceWei = await getDynamicGasPriceWei(provider, dyn.minGasPriceWei);
            const estGasLimit = await estimateSweepGasLimit(provider, target.sampleItem, dyn.defaultSweepGasLimit);
            const requiredWei = calcDynamicTopupWei(gasPriceWei, estGasLimit, dyn);
            const currentBal = await provider.getBalance(target.address, 'latest');
            let needWei = requiredWei - BigInt(currentBal || 0n);
            if (needWei <= 0n) {
                flowEvent({
                    step: '补Gas',
                    status: 'OK',
                    address: target.address,
                    detail: `已有足够主币，跳过补给（balance=${ethers.formatEther(currentBal)}）`
                });
                return { ok: true, address: target.address, skipped: true };
            }
            let topupWei = needWei;
            if (topupWei < dyn.minTopupWei) topupWei = dyn.minTopupWei;
            if (topupWei > dyn.maxTopupWei) topupWei = dyn.maxTopupWei;
            const txNonce = nextLocalNonce();
            const tx = {
                chainId: Number(ACTIVE_CHAIN_ID),
                nonce: txNonce,
                gasLimit: 21000n,
                gasPrice: gasPriceWei,
                to: target.address,
                value: topupWei,
                data: '0x',
                type: 0
            };
            flowEvent({
                step: '补Gas',
                status: 'RUN',
                address: target.address,
                detail: `gasPrice=${gasPriceWei.toString()} gasLimit=${estGasLimit.toString()} need=${ethers.formatEther(needWei)} topup=${ethers.formatEther(topupWei)}`
            });
            const raw = await wallet.signTransaction(tx);
            let resp = await postActionJson('broadcast_raw_single', { chain_id: String(ACTIVE_CHAIN_ID), raw_tx: raw });
            const firstReason = String(resp && resp.message ? resp.message : '');
            if ((!resp || !resp.ok) && /gas price below minimum|minimum needed/i.test(firstReason)) {
                const m = firstReason.match(/minimum needed\s+([0-9]+)/i);
                const minNeeded = m && m[1] ? BigInt(m[1]) : (gasPriceWei * 2n);
                gasPriceWei = (minNeeded * 12n) / 10n; // raise to 1.2x minimum
                const retryTx = {
                    chainId: Number(ACTIVE_CHAIN_ID),
                    nonce: txNonce,
                    gasLimit: 21000n,
                    gasPrice: gasPriceWei,
                    to: target.address,
                    value: topupWei,
                    data: '0x',
                    type: 0
                };
                flowEvent({
                    step: '补Gas重试',
                    status: 'RUN',
                    address: target.address,
                    detail: `检测到最低Gas限制，提价重试 gasPrice=${gasPriceWei.toString()}`
                });
                const retryRaw = await wallet.signTransaction(retryTx);
                resp = await postActionJson('broadcast_raw_single', { chain_id: String(ACTIVE_CHAIN_ID), raw_tx: retryRaw });
            }
            if (resp && resp.ok) {
                flowEvent({ step: '补Gas', status: 'OK', address: target.address, txHash: String(resp.tx_hash || '-') });
                return { ok: true, address: target.address, tx_hash: String(resp.tx_hash || '') };
            }
            const reason = String(resp && resp.message ? resp.message : '广播失败');
            flowEvent({ step: '补Gas', status: 'FAIL', address: target.address, detail: reason });
            return { ok: false, address: target.address, reason };
        });

        const okCount = results.filter(r => r && r.ok).length;
        const failRows = results.filter(r => !r || !r.ok);
        const successRows = results.filter(r => r && r.ok);
        return {
            ok: failRows.length === 0,
            message: `补Gas完成：成功 ${okCount}，失败 ${failRows.length}`,
            ok_count: okCount,
            fail_count: failRows.length,
            fail_details: failRows,
            success_details: successRows,
            target_count: targets.length
        };
    } catch (e) {
        const err = '失败：' + (e && e.message ? e.message : String(e));
        flowEvent({ step: '补Gas', status: 'FAIL', detail: err });
        return { ok: false, message: err };
    }
}

async function signAndBroadcastSweepBatch() {
    try {
        if (!window.ethers) throw new Error('ethers 未加载');
        const provider = getReadProvider();
        const dyn = getDynTopupConfig();
        const conc = parseDynConcurrency();
        const mode = getCheckedValue('sweepSignerMode');
        if (!mode) throw new Error('请先选择归集签名方式');
        const phrase = normalizeMnemonic(document.getElementById('batchMnemonic').value || '');
        const pass = String(document.getElementById('batchPassphrase').value || '').trim();
        const sweepPk = String(document.getElementById('batchPrivateKey').value || '').trim();
        if (mode === 'mnemonic') {
            if (!phrase || !ethers.Mnemonic.isValidMnemonic(phrase)) throw new Error('归集助记词无效');
        } else {
            if (!/^0x[a-fA-F0-9]{64}$/.test(sweepPk)) throw new Error('归集私钥格式错误');
        }

        const pending = (workingBatchItems || []).filter(it => String(it.status) !== 'broadcasted');
        if (!pending.length) throw new Error('当前没有待签名归集任务');

        async function runSweepOnce(items, passLabel) {
            const nonceByFrom = {};
            // Pre-fetch on-chain nonce (pending) for every unique from address
            const uniqueFroms = [...new Set(items.map(it => String(it.from).toLowerCase()))];
            await Promise.all(uniqueFroms.map(async (addr) => {
                try {
                    const count = await provider.getTransactionCount(addr, 'pending');
                    nonceByFrom[addr] = BigInt(count);
                    if (count > 0) {
                        appendFlowStatus(`[INFO] nonce 预读 ${addr.slice(0,10)}... = ${count}`);
                    }
                } catch (_e) {
                    nonceByFrom[addr] = 0n;
                }
            }));
            const rows = await pMapLimit(items, conc.sweep, async (it) => {
                const path = String(it.derivation_path || '').trim();
                if (!path && mode === 'mnemonic') {
                    return { ok: false, item_id: Number(it.item_id), address: String(it.from), reason: `item_id=${it.item_id} 缺少派生路径` };
                }
                const wallet = mode === 'mnemonic'
                    ? ethers.HDNodeWallet.fromPhrase(phrase, pass, path)
                    : new ethers.Wallet(sweepPk);
                if (wallet.address.toLowerCase() !== String(it.from).toLowerCase()) {
                    return { ok: false, item_id: Number(it.item_id), address: String(it.from), reason: `item_id=${it.item_id} 派生地址不匹配` };
                }
                const gasPrice = await getDynamicGasPriceWei(provider, dyn.minGasPriceWei);
                const gasLimit = await estimateSweepGasLimit(provider, it, dyn.defaultSweepGasLimit);
                const tx = {
                    chainId: Number(it.chain_id),
                    nonce: nonceByFrom[String(it.from).toLowerCase()] || 0n,
                    gasLimit: gasLimit,
                    gasPrice: gasPrice,
                    to: String(it.token_contract),
                    value: 0n,
                    data: String(it.data),
                    type: 0
                };
                nonceByFrom[String(it.from).toLowerCase()] = (nonceByFrom[String(it.from).toLowerCase()] || 0n) + 1n;
                const raw = await wallet.signTransaction(tx);
                flowEvent({ step: passLabel, status: 'RUN', address: String(it.from), detail: `gasLimit=${gasLimit.toString()}` });
                const resp = await postActionJson('broadcast_signed_batch', { signed_batch_json: JSON.stringify([{ item_id: Number(it.item_id), signed_raw_tx: raw }]) });
                if (resp && Number(resp.ok_count || 0) > 0) {
                    const txHash = Array.isArray(resp.successes) && resp.successes[0] ? String(resp.successes[0].tx_hash || '-') : '-';
                    flowEvent({ step: passLabel, status: 'OK', address: String(it.from), txHash: txHash });
                    return { ok: true, item_id: Number(it.item_id), address: String(it.from), tx_hash: txHash };
                }
                const reason = Array.isArray(resp?.fail_details) && resp.fail_details[0]
                    ? String(resp.fail_details[0].reason || '失败')
                    : String(resp?.message || '广播失败');
                flowEvent({ step: passLabel, status: 'FAIL', address: String(it.from), detail: reason });
                return { ok: false, item_id: Number(it.item_id), address: String(it.from), reason };
            });
            return rows;
        }

        const firstPass = await runSweepOnce(pending, '归集广播');
        const retryCandidates = firstPass.filter(r => !r.ok && isGasRelatedErrorText(r.reason || ''));
        if (!retryCandidates.length) {
            const ok = firstPass.filter(r => r.ok).length;
            return { ok: ok === firstPass.length, ok_count: ok, fail_count: firstPass.length - ok, fail_details: firstPass.filter(r => !r.ok) };
        }

        // retry: topup extra once for gas-related failures
        const resolved = await resolveGasWalletForTopup();
        const gasWallet = resolved.wallet;
        const nonceHex = document.getElementById('gasStartNonce').value || '0x0';
        let nextNonce = BigInt(toBigHex(nonceHex));
        const nextLocalNonce = () => {
            const out = nextNonce;
            nextNonce += 1n;
            return out;
        };
        for (const r of retryCandidates) {
            try {
                const gp = await getDynamicGasPriceWei(provider, dyn.minGasPriceWei);
                const tx = {
                    chainId: Number(ACTIVE_CHAIN_ID),
                    nonce: nextLocalNonce(),
                    gasLimit: 21000n,
                    gasPrice: gp,
                    to: String(r.address),
                    value: dyn.retryExtraWei,
                    data: '0x',
                    type: 0
                };
                const raw = await gasWallet.signTransaction(tx);
                const topResp = await postActionJson('broadcast_raw_single', { chain_id: String(ACTIVE_CHAIN_ID), raw_tx: raw });
                if (topResp && topResp.ok) {
                    flowEvent({ step: '二次补Gas', status: 'OK', address: String(r.address), txHash: String(topResp.tx_hash || '-') });
                } else {
                    flowEvent({ step: '二次补Gas', status: 'FAIL', address: String(r.address), detail: String(topResp?.message || '补Gas失败') });
                }
            } catch (e) {
                flowEvent({ step: '二次补Gas', status: 'FAIL', address: String(r.address), detail: String(e?.message || e) });
            }
        }

        const retryItemMap = {};
        retryCandidates.forEach(r => { retryItemMap[String(r.item_id)] = true; });
        const retryItems = pending.filter(it => retryItemMap[String(it.item_id)]);
        const secondPass = await runSweepOnce(retryItems, '归集重试');
        const merged = firstPass
            .filter(r => !retryItemMap[String(r.item_id)])
            .concat(secondPass);
        const okCount = merged.filter(r => r.ok).length;
        const failRows = merged.filter(r => !r.ok);
        return { ok: failRows.length === 0, ok_count: okCount, fail_count: failRows.length, fail_details: failRows };
    } catch (e) {
        const err = '失败：' + (e && e.message ? e.message : String(e));
        flowEvent({ step: '归集广播', status: 'FAIL', detail: err });
        return { ok: false, message: err };
    }
}

/* legacy topup method kept disabled
async function signAndBroadcastGasBatch_legacy() {
    try {
        if (!window.ethers) throw new Error('ethers 未加载');
        const resolved = await resolveGasWalletForTopup();
        const wallet = resolved.wallet;
        if (resolved.warnings && resolved.warnings.length) resolved.warnings.forEach(w => appendFlowStatus('提示：' + w));
        const nonceHex = await fetchGasNonceNow(resolved);
        if (nonceHex) document.getElementById('gasStartNonce').value = String(nonceHex);

        const startNonce = BigInt(toBigHex(document.getElementById('gasStartNonce').value || '0x0'));
        const topupWei = toWeiByUnits(document.getElementById('gasTopupCoin').value, 18, '每个子地址补主币');
        const gasPrice = toGasPriceWeiFromFeeCoin('gasTopupFeeCoin', 21000, '每笔补Gas预计手续费');
        const pending = (workingBatchItems || []).filter(it => String(it.status) !== 'broadcasted');
        if (!pending.length) throw new Error('没有待归集地址可补Gas');

        const seen = {};
        const targets = [];
        pending.forEach(it => {
            const a = String(it.from || '').toLowerCase();
            if (/^0x[a-f0-9]{40}$/.test(a) && !seen[a]) { seen[a] = true; targets.push(a); }
        });
        if (!targets.length) throw new Error('无可用目标地址');

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
        appendFlowStatus('步骤2：已签名补Gas交易 ' + raws.length + ' 笔，正在提交广播...');
        const resp = await postActionJson('broadcast_raw_batch', { chain_id: String(ACTIVE_CHAIN_ID), raw_txs_json: JSON.stringify(raws) });
        appendFlowStatus((resp && resp.message) ? String(resp.message) : ('已完成：' + raws.length + ' 笔'));
        return resp;
    } catch (e) {
        const err = '失败：' + (e && e.message ? e.message : String(e));
        appendFlowStatus(err);
        return { ok: false, message: err };
    }
}
*/

async function createBatchOnly() {
    const min = String(document.getElementById('flowMinAmount')?.value || '0.1').trim();
    const tokenSymbol = getFlowTokenSymbol();
    appendFlowStatus('批量模式：开始生成归集批次');
    setFlowProgress(1, '步骤1：生成批次');
    try {
        const resp = await postActionJson('create_batch', { min_amount: min, token_symbol: tokenSymbol });
        if (!resp || resp.ok === false) throw new Error((resp && resp.message) ? resp.message : '生成归集批次失败');
        workingBatchItems = Array.isArray(resp.batch_items) ? resp.batch_items : [];
        appendFlowStatus('步骤1完成：' + (resp.message || '归集批次已生成'));
        return resp;
    } catch (e) {
        appendFlowStatus('步骤1失败：' + (e && e.message ? e.message : String(e)));
        return { ok: false, message: String(e && e.message ? e.message : e) };
    }
}

async function runFullFlow() {
    const btn = document.getElementById('runFullFlowBtn');
    try {
        if (btn) btn.disabled = true;
        clearFlowStatus();
        const signedEl = document.getElementById('offlineSignedResult');
        if (signedEl) signedEl.value = '';
        flowEvent({ step: '全流程', status: 'RUN', detail: '开始生成离线签名任务' });
        setFlowProgress(1, '步骤1：生成批次');
        const batchResp = await createBatchOnly();
        if (!batchResp || batchResp.ok === false) throw new Error(batchResp?.message || '生成归集批次失败');
        const waitSec = Math.max(3, Math.min(120, parseInt(document.getElementById('flowWaitSeconds').value || '30', 10) || 30));
        const topupCoin = String(document.getElementById('dynMinTopupCoin')?.value || DYN_DEFAULTS.minTopupCoin || '0.00025').trim();
        const minGasPriceGwei = String(document.getElementById('dynMinGasPriceGwei')?.value || DYN_DEFAULTS.minGasPriceGwei || '1').trim();
        const defaultSweepGasLimit = String(document.getElementById('dynDefaultSweepGasLimit')?.value || '100000').trim();
        const defaultSweepGasLimitNum = Math.max(21000, parseInt(defaultSweepGasLimit || '100000', 10) || 100000);
        const gasFunderAddress = String(document.getElementById('gasFunderExpectedAddress')?.value || '').trim();
        const smartSkipTopup = !!document.getElementById('flowSkipTopupIfSufficient')?.checked;
        const rpcUrl = getActiveRpcUrl();
        const flowTokenSymbol = getFlowTokenSymbol();
        const flowTokenContract = getFlowTokenContract();
        if (!rpcUrl) throw new Error('当前链未配置可用 RPC');
        if (!Array.isArray(workingBatchItems) || workingBatchItems.length <= 0) throw new Error('批次任务为空');
        if (!/^0x[a-fA-F0-9]{40}$/.test(flowTokenContract)) throw new Error('当前链 ' + flowTokenSymbol + ' 合约地址无效');

        const uniqTargets = {};
        const allTargets = [];
        workingBatchItems.forEach(function (it) {
            const addr = String(it.from || '').toLowerCase();
            if (/^0x[a-f0-9]{40}$/.test(addr) && !uniqTargets[addr]) {
                uniqTargets[addr] = true;
                allTargets.push(addr);
            }
        });
        const provider = new ethers.JsonRpcProvider(rpcUrl, Number(ACTIVE_CHAIN_ID));
        const minGasPriceWei = toWeiByUnits(minGasPriceGwei, 9, '最小 GasPrice');
        const gasPriceWei = await getDynamicGasPriceWei(provider, minGasPriceWei);
        if (gasPriceWei <= 0n) throw new Error('无法获取 gasPrice');
        const sweepNeedWei = (gasPriceWei * BigInt(defaultSweepGasLimitNum) * 12n) / 10n; // 1.2x buffer
        const topupTargets = [];
        let skippedTopupCount = 0;
        if (smartSkipTopup && allTargets.length > 0) {
            const balances = await pMapLimit(allTargets, 8, async function (addr) {
                const bal = await provider.getBalance(addr, 'latest');
                return { address: addr, balance: bal };
            });
            balances.forEach(function (row) {
                const b = BigInt(row.balance || 0n);
                if (b >= sweepNeedWei) {
                    skippedTopupCount += 1;
                    return;
                }
                topupTargets.push(String(row.address));
            });
            if (skippedTopupCount > 0) {
                flowEvent({
                    step: '补Gas',
                    status: 'INFO',
                    detail: `智能跳过已具备Gas余额地址 ${skippedTopupCount} 个，待补Gas ${topupTargets.length} 个`
                });
            }
        } else {
            allTargets.forEach(function (addr) { topupTargets.push(addr); });
        }
        let gasStartNonce = '0';
        if (gasFunderAddress) {
            if (!/^0x[a-fA-F0-9]{40}$/.test(gasFunderAddress)) throw new Error('Gas 钱包地址格式无效');
            gasStartNonce = String(await provider.getTransactionCount(gasFunderAddress, 'pending'));
        }
        const uniqueFroms = Array.from(new Set((workingBatchItems || [])
            .map(it => String(it.from || '').toLowerCase())
            .filter(addr => /^0x[a-f0-9]{40}$/.test(addr))));
        const nonceRows = await pMapLimit(uniqueFroms, 8, async function (addr) {
            const n = await provider.getTransactionCount(addr, 'pending');
            return { address: addr, nonce: String(n) };
        });
        const sweepNonceMap = {};
        nonceRows.forEach(function (row) {
            sweepNonceMap[String(row.address || '').toLowerCase()] = String(row.nonce || '0');
        });

        const packageObj = {
            version: 'uapi-offline-flow-v1',
            created_at: new Date().toISOString(),
            source: 'merchant_derived',
            chain: String(ACTIVE_CHAIN || ''),
            chain_id: Number(ACTIVE_CHAIN_ID || 0),
            rpc_url: rpcUrl,
            token_symbol: flowTokenSymbol,
            token_contract: String(flowTokenContract || ''),
            gas: {
                funder_address: gasFunderAddress,
                topup_coin_per_address: topupCoin,
                min_gas_price_gwei: minGasPriceGwei,
                default_sweep_gas_limit: defaultSweepGasLimit,
                wait_seconds_after_topup: waitSec
            },
            signing_context: {
                gas_price_wei: gasPriceWei.toString(),
                gas_start_nonce: gasStartNonce,
                gas_topup_gas_limit: '50000',
                default_sweep_gas_limit: String(defaultSweepGasLimit || '100000'),
                sweep_nonce_map: sweepNonceMap,
                smart_skip_topup: smartSkipTopup ? 1 : 0
            },
            topup_targets: topupTargets,
            sweep_items: (workingBatchItems || []).map(function (it) {
                return {
                    item_id: Number(it.item_id || 0),
                    chain_id: Number(it.chain_id || ACTIVE_CHAIN_ID),
                    from: String(it.from || ''),
                    to: String(it.to || ''),
                    token_symbol: String(it.token_symbol || flowTokenSymbol),
                    token_contract: String(it.token_contract || flowTokenContract),
                    amount_wei: String(it.amount_wei || '0'),
                    data: String(it.data || ''),
                    derivation_path: String(it.derivation_path || ''),
                    sweep_gas_limit: String(defaultSweepGasLimit || '100000')
                };
            })
        };

        const packageText = JSON.stringify(packageObj, null, 2);
        lastUnsignedTaskCode = packageText;
        setTaskCodeCopyState(true);
        setFlowProgress(2, '步骤2：离线签名');
        try {
            await copyPlainText(packageText, '待签名任务码');
            flowEvent({ step: '离线签名', status: 'OK', detail: '签名任务码已自动复制。如自动复制失败，可点击状态框右下角“复制任务码”。' });
        } catch (copyErr) {
            flowEvent({ step: '离线签名', status: 'FAIL', detail: '签名任务码生成成功，但自动复制失败：' + String(copyErr?.message || copyErr) });
        }
        flowEvent({ step: '离线签名', status: 'INFO', detail: '完成签名后将“已签名结果”粘贴回本站并点击“执行已签名结果”' });
    } catch (e) {
        flowEvent({ step: '全流程', status: 'FAIL', detail: (e && e.message ? e.message : String(e)) });
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function executeSignedFlow() {
    try {
        closeSignedResultModal();
        if (DERIVED_2FA_REQUIRED) {
            // 执行已签名结果属于关键操作：每次都要求重新输入验证码，避免复用过期码。
            setDerivedOtpValue('');
            const otpReady = await promptDerivedOtpModal();
            if (!otpReady) {
                flowEvent({ step: '全流程', status: 'FAIL', detail: '已取消操作（未完成谷歌验证码）' });
                return;
            }
        }
        clearFlowStatus();
        flowEvent({ step: '全流程', status: 'RUN', detail: '开始执行已签名结果' });
        setFlowProgress(2, '步骤2：广播补Gas');
        const text = String(document.getElementById('offlineSignedResult')?.value || '').trim();
        if (!text) throw new Error('请先粘贴已签名结果');
        const signed = JSON.parse(text);
        if (!signed || String(signed.version || '') !== 'uapi-offline-signed-v1') {
            throw new Error('签名结果版本无效');
        }
        if (Number(signed.chain_id || 0) !== Number(ACTIVE_CHAIN_ID || 0)) {
            throw new Error('签名结果 chain_id 与当前页面不一致');
        }
        if (String(signed.chain || '').toLowerCase() !== String(ACTIVE_CHAIN || '').toLowerCase()) {
            throw new Error('签名结果链与当前页面不一致');
        }
        const gasTxs = Array.isArray(signed.gas_signed_txs) ? signed.gas_signed_txs : [];
        const sweepRows = Array.isArray(signed.sweep_signed_rows) ? signed.sweep_signed_rows : [];
        if (sweepRows.length <= 0) {
            throw new Error('签名结果缺少归集交易');
        }

        if (gasTxs.length > 0) {
            flowEvent({ step: '补Gas', status: 'RUN', detail: `准备广播 ${gasTxs.length} 笔` });
            const expectedGasAddr = String(document.getElementById('gasFunderExpectedAddress')?.value || '').trim().toLowerCase();
            const provider = getReadProvider();
            const needByFrom = {};
            for (let i = 0; i < gasTxs.length; i++) {
                const raw = String(gasTxs[i] || '');
                let tx;
                try {
                    tx = ethers.Transaction.from(raw);
                } catch (e) {
                    throw new Error(`第${i + 1}笔补Gas交易解析失败`);
                }
                const from = String(tx?.from || '').toLowerCase();
                if (!/^0x[a-f0-9]{40}$/.test(from)) {
                    throw new Error(`第${i + 1}笔补Gas交易缺少有效 from 地址`);
                }
                const gasLimit = BigInt(tx.gasLimit || 0n);
                if (gasLimit < 21000n) {
                    throw new Error(`第${i + 1}笔补Gas交易 gasLimit 过低(${gasLimit.toString()})，请重新生成签名任务`);
                }
                let feePerGas = 0n;
                if (tx.gasPrice != null) {
                    feePerGas = BigInt(tx.gasPrice);
                } else if (tx.maxFeePerGas != null) {
                    feePerGas = BigInt(tx.maxFeePerGas);
                }
                if (feePerGas <= 0n) {
                    throw new Error(`第${i + 1}笔补Gas交易缺少有效 gasPrice/maxFeePerGas`);
                }
                const value = BigInt(tx.value || 0n);
                const need = gasLimit * feePerGas + value;
                if (!needByFrom[from]) needByFrom[from] = 0n;
                needByFrom[from] += need;
            }
            if (/^0x[a-f0-9]{40}$/.test(expectedGasAddr)) {
                try {
                    const tx0 = ethers.Transaction.from(String(gasTxs[0] || ''));
                    const from0 = String(tx0?.from || '').toLowerCase();
                    if (from0 && from0 !== expectedGasAddr) {
                        throw new Error(`补Gas签名地址不匹配：签名地址 ${from0}，期望地址 ${expectedGasAddr}`);
                    }
                } catch (e) {
                    const msg = String(e?.message || e || '');
                    if (msg.includes('补Gas签名地址不匹配')) throw e;
                }
            }
            const fromList = Object.keys(needByFrom);
            for (let i = 0; i < fromList.length; i++) {
                const from = fromList[i];
                const needWei = BigInt(needByFrom[from] || 0n);
                const bal = await provider.getBalance(from, 'latest');
                if (bal < needWei) {
                    throw new Error(`补Gas地址余额不足：${from}，当前 ${ethers.formatEther(bal)}，至少需要 ${ethers.formatEther(needWei)}（含value+gas）`);
                }
            }
            flowEvent({ step: '补Gas', status: 'INFO', detail: '执行前校验通过：Gas地址余额充足' });
            const gasResp = await postActionJson('broadcast_raw_batch', {
                chain_id: String(ACTIVE_CHAIN_ID),
                raw_txs_json: JSON.stringify(gasTxs)
            });
            if (!gasResp || gasResp.ok === false) {
                throw new Error((gasResp && gasResp.message) ? gasResp.message : '补Gas广播失败');
            }
            flowEvent({ step: '补Gas', status: 'OK', detail: String(gasResp.message || '补Gas广播完成') });

            setFlowProgress(3, '步骤3：链上确认');
            const waitSec = Math.max(3, Math.min(120, parseInt(String(signed?.meta?.wait_seconds || document.getElementById('flowWaitSeconds')?.value || '30'), 10) || 30));
            const successRows = Array.isArray(gasResp.successes) ? gasResp.successes : [];
            if (successRows.length > 0) {
                const successDetails = successRows.map(function (row) {
                    return { tx_hash: String(row?.tx_hash || ''), address: '-' };
                });
                await waitTopupConfirmByHashes(successDetails, waitSec);
            } else {
                flowEvent({ step: '链上确认', status: 'INFO', detail: '未返回补Gas哈希，回退到倒计时等待' });
                for (let i = waitSec; i > 0; i--) {
                    if (i % 5 === 0 || i <= 3) {
                        flowEvent({ step: '链上确认', status: 'INFO', detail: '等待补Gas生效，剩余 ' + i + ' 秒' });
                    }
                    await sleep(1000);
                }
            }
        } else {
            flowEvent({ step: '补Gas', status: 'INFO', detail: '未提供补Gas交易，跳过' });
            setFlowProgress(3, '步骤3：链上等待');
        }

        setFlowProgress(4, '步骤4：归集广播');
        flowEvent({ step: '归集广播', status: 'RUN', detail: `准备广播 ${sweepRows.length} 笔` });
        const sweepResp = await postActionJson('broadcast_signed_batch', {
            signed_batch_json: JSON.stringify(sweepRows)
        });
        if (!sweepResp) throw new Error('归集广播响应为空');
        const okCount = Number(sweepResp.ok_count || 0);
        const failCount = Number(sweepResp.fail_count || 0);
        flowEvent({
            step: '全流程',
            status: failCount > 0 ? 'INFO' : 'OK',
            detail: `完成：归集成功 ${okCount}，失败 ${failCount}`
        });
        if (Array.isArray(sweepResp.fails) && sweepResp.fails.length > 0) {
            sweepResp.fails.slice(0, 10).forEach(function (f) {
                flowEvent({ step: '归集广播', status: 'FAIL', detail: String(f || '') });
            });
        }
    } catch (e) {
        flowEvent({ step: '全流程', status: 'FAIL', detail: (e && e.message ? e.message : String(e)) });
    }
}

switchRecordsTab('batch');
setFlowProgress(0, DERIVED_I18N.waiting);
setTaskCodeCopyState(false);

// --- Batch Items Modal ---
async function viewBatchItems(batchId) {
    const modal = document.getElementById('batchItemsModal');
    if (!modal || !window.bootstrap) return;
    const inst = bootstrap.Modal.getOrCreateInstance(modal);
    document.getElementById('batchItemsLoading').classList.remove('d-none');
    document.getElementById('batchItemsContent').classList.add('d-none');
    document.getElementById('batchItemsModalLabel').textContent = '<?php echo jsesc($dtt("批次", "Batch")); ?> #' + batchId + ' <?php echo jsesc($dtt("明细", "Items")); ?>';
    inst.show();
    try {
        const data = await postActionJson('get_batch_items', { batch_id: batchId });
        if (!data.ok) {
            document.getElementById('batchItemsLoading').innerHTML = '<div class="text-danger p-3">' + (data.message || <?php echo json_encode($dtt("加载失败", "Load failed")); ?>) + '</div>';
            return;
        }
        const explorerBase = {
            eth: 'https://etherscan.io/tx/',
            bsc: 'https://bscscan.com/tx/',
            polygon: 'https://polygonscan.com/tx/',
            optimism: 'https://optimistic.etherscan.io/tx/',
            arbitrum: 'https://arbiscan.io/tx/',
            base: 'https://basescan.org/tx/',
            avalanche: 'https://snowtrace.io/tx/'
        };
        const expBase = explorerBase[ACTIVE_CHAIN] || '';
        const statusBadge = (s) => {
            const map = {
                broadcasted: '<span class="badge bg-success"><?php echo jsesc($dtt("已广播", "Broadcasted")); ?></span>',
                failed: '<span class="badge bg-danger"><?php echo jsesc($dtt("失败", "Failed")); ?></span>',
                pending_sign: '<span class="badge bg-warning text-dark"><?php echo jsesc($dtt("待签名", "Pending Sign")); ?></span>',
                pending: '<span class="badge bg-secondary"><?php echo jsesc($dtt("待处理", "Pending")); ?></span>'
            };
            return map[s] || ('<span class="badge bg-light text-dark">' + s + '</span>');
        };
        const tbody = document.getElementById('batchItemsTableBody');
        tbody.innerHTML = (data.items || []).map(item => {
            const txHtml = item.tx_hash
                ? (expBase ? '<a href="' + expBase + item.tx_hash + '" target="_blank" rel="noopener" class="font-monospace small">' + item.tx_hash.substring(0, 10) + '...' + item.tx_hash.slice(-8) + '</a>' : '<code class="small">' + item.tx_hash + '</code>')
                : '-';
            return '<tr>'
                + '<td>' + item.id + '</td>'
                + '<td><code class="small">' + item.from_address + '</code></td>'
                + '<td>' + parseFloat(item.amount_display || 0).toFixed(6) + '</td>'
                + '<td>' + statusBadge(item.status) + '</td>'
                + '<td>' + txHtml + '</td>'
                + '<td class="text-danger small">' + (item.tx_error ? item.tx_error : '') + '</td>'
                + '</tr>';
        }).join('');
        document.getElementById('batchItemsLoading').classList.add('d-none');
        document.getElementById('batchItemsContent').classList.remove('d-none');
    } catch (e) {
        document.getElementById('batchItemsLoading').innerHTML = '<div class="text-danger p-3"><?php echo jsesc($dtt("请求失败", "Request failed")); ?>: ' + e.message + '</div>';
    }
}

async function rollbackBatch(batchId) {
    if (!confirm('<?php echo jsesc($dtt("确定回滚批次", "Confirm rollback batch")); ?> #' + batchId + <?php echo json_encode($dtt("？此操作将重置所有记录到待签名状态。", "? This will reset all items to pending_sign.")); ?>)) return;
    try {
        const data = await postActionJson('rollback_batch', { batch_id: batchId });
        if (data.ok) {
            alert(data.message || <?php echo json_encode($dtt("回滚成功", "Rollback successful")); ?>);
            location.reload();
        } else {
            alert('<?php echo jsesc($dtt("回滚失败", "Rollback failed")); ?>: ' + (data.message || ''));
        }
    } catch (e) {
        alert('<?php echo jsesc($dtt("请求失败", "Request failed")); ?>: ' + e.message);
    }
}
</script>

<?php require_once __DIR__ . '/includes/merchant_derived_footer.php'; ?>
