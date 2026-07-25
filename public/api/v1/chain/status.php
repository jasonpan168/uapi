<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../src/Core/Database.php';

function status_resp(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function http_get_json_status(string $url, int $timeout = 10): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return is_array($data) ? $data : null;
}

function http_get_json_list_status(string $url, int $timeout = 10): ?array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return is_array($data) ? $data : null;
}

function http_get_text_status(string $url, int $timeout = 10): string
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        curl_close($ch);
        return '';
    }
    curl_close($ch);
    return trim((string)$resp);
}

function http_post_json_status(string $url, array $payload, int $timeout = 10): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($resp === false) {
        $err = (string)curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'http_code' => $httpCode, 'error' => $err !== '' ? $err : 'curl_exec failed'];
    }
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http_code' => $httpCode, 'error' => 'invalid json response'];
    }
    return ['ok' => true, 'http_code' => $httpCode, 'json' => $data];
}

function rpc_call_status(string $rpc, string $method, array $params = []): array
{
    $resp = http_post_json_status($rpc, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]);
    if (!(bool)($resp['ok'] ?? false)) {
        return ['ok' => false, 'error' => (string)($resp['error'] ?? 'rpc request failed')];
    }
    $json = is_array($resp['json'] ?? null) ? $resp['json'] : [];
    if (isset($json['error'])) {
        $msg = is_array($json['error']) ? (string)($json['error']['message'] ?? 'rpc error') : 'rpc error';
        return ['ok' => false, 'error' => $msg];
    }
    if (!array_key_exists('result', $json)) {
        return ['ok' => false, 'error' => 'rpc result missing'];
    }
    return ['ok' => true, 'result' => $json['result']];
}

function parse_rpc_candidates(string $override, array $defaults): array
{
    $list = [];
    $raw = trim($override);
    if ($raw !== '') {
        $parts = preg_split('/[\s,]+/', $raw) ?: [];
        foreach ($parts as $p) {
            $u = trim((string)$p);
            if ($u !== '') $list[] = $u;
        }
    }
    foreach ($defaults as $d) {
        $u = trim((string)$d);
        if ($u !== '') $list[] = $u;
    }
    return array_values(array_unique($list));
}

function evm_status(array $rpcs, string $symbol): array
{
    $errs = [];
    foreach ($rpcs as $rpc) {
        $bn = rpc_call_status((string)$rpc, 'eth_blockNumber', []);
        if (!(bool)($bn['ok'] ?? false)) {
            $errs[] = ((string)$rpc) . ': ' . (string)($bn['error'] ?? 'eth_blockNumber failed');
            continue;
        }
        $bnHex = $bn['result'] ?? null;
        if (!is_string($bnHex) || !preg_match('/^0x[0-9a-fA-F]+$/', $bnHex)) {
            $errs[] = ((string)$rpc) . ': invalid eth_blockNumber result';
            continue;
        }

        $gp = rpc_call_status((string)$rpc, 'eth_gasPrice', []);
        $gpHex = (bool)($gp['ok'] ?? false) ? ($gp['result'] ?? null) : null;
        $gwei = null;
        if (is_string($gpHex) && preg_match('/^0x[0-9a-fA-F]+$/', $gpHex)) {
            $wei = (float)hexdec($gpHex);
            $gwei = $wei / 1_000_000_000;
        }

        return [
            'ok' => true,
            'latest_block' => hexdec($bnHex),
            'gas_gwei' => $gwei === null ? null : round($gwei, 3),
            'gas_text' => $gwei === null ? 'N/A' : (string)round($gwei, 3) . ' Gwei',
            'native_symbol' => $symbol,
            'rpc' => (string)$rpc,
            'error' => null,
        ];
    }

    return [
        'ok' => false,
        'latest_block' => null,
        'gas_gwei' => null,
        'gas_text' => 'N/A',
        'native_symbol' => $symbol,
        'rpc' => $rpcs[0] ?? null,
        'error' => implode(' | ', array_slice($errs, 0, 2)),
    ];
}

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$cfg = [];
foreach ($rows as $r) {
    $cfg[(string)$r['key_name']] = (string)$r['value'];
}

$evmMap = [
    'bsc' => ['rpcs' => ['https://bsc-dataseed.binance.org', 'https://bsc-rpc.publicnode.com'], 'native' => 'BNB'],
    'eth' => ['rpcs' => ['https://ethereum-rpc.publicnode.com', 'https://eth.llamarpc.com'], 'native' => 'ETH'],
    'arbitrum' => ['rpcs' => ['https://arb1.arbitrum.io/rpc', 'https://arbitrum-one-rpc.publicnode.com'], 'native' => 'ETH'],
    'base' => ['rpcs' => ['https://mainnet.base.org', 'https://base-rpc.publicnode.com'], 'native' => 'ETH'],
    'polygon' => ['rpcs' => ['https://polygon-bor-rpc.publicnode.com', 'https://polygon.llamarpc.com'], 'native' => 'MATIC'],
];
foreach (array_keys($evmMap) as $k) {
    $override = trim((string)($cfg['rpc_url_' . $k] ?? ''));
    $evmMap[$k]['rpcs'] = parse_rpc_candidates($override, $evmMap[$k]['rpcs'] ?? []);
}

$statusList = [];
foreach ($evmMap as $chain => $meta) {
    $s = evm_status((array)($meta['rpcs'] ?? []), (string)$meta['native']);
    $statusList[] = [
        'chain' => $chain,
        'ok' => (bool)($s['ok'] ?? false),
        'latest_block' => $s['latest_block'] ?? null,
        'gas_gwei' => $s['gas_gwei'] ?? null,
        'gas_text' => $s['gas_text'] ?? null,
        'native_symbol' => $s['native_symbol'] ?? null,
        'rpc' => $s['rpc'] ?? null,
        'error' => $s['error'] ?? null,
    ];
}

$solRpc = trim((string)($cfg['sol_rpc_url'] ?? ''));
if ($solRpc === '') $solRpc = 'https://api.mainnet-beta.solana.com';
$solSlotResp = rpc_call_status($solRpc, 'getSlot', []);
$solFeeText = 'N/A';
$solFeeResp = rpc_call_status($solRpc, 'getRecentPrioritizationFees', [[]]);
if ((bool)($solFeeResp['ok'] ?? false) && is_array($solFeeResp['result'] ?? null)) {
    $vals = [];
    foreach ((array)$solFeeResp['result'] as $x) {
        if (is_array($x) && isset($x['prioritizationFee']) && is_numeric($x['prioritizationFee'])) {
            $vals[] = (int)$x['prioritizationFee'];
        }
    }
    if (!empty($vals)) {
        sort($vals);
        $mid = $vals[(int)floor(count($vals) / 2)];
        $solFeeText = (string)$mid . ' micro-lamports/CU';
    } else {
        $solFeeText = '0 micro-lamports/CU';
    }
}
$solSlot = ((bool)($solSlotResp['ok'] ?? false) && is_int($solSlotResp['result'] ?? null)) ? (int)$solSlotResp['result'] : null;
$statusList[] = [
    'chain' => 'solana',
    'ok' => is_int($solSlot),
    'latest_block' => is_int($solSlot) ? $solSlot : null,
    'gas_gwei' => null,
    'gas_text' => $solFeeText,
    'native_symbol' => 'SOL',
    'rpc' => $solRpc,
    'error' => is_int($solSlot) ? null : (string)($solSlotResp['error'] ?? 'getSlot failed'),
];

$tronResp = http_post_json_status('https://api.trongrid.io/wallet/getnowblock', []);
$tronGasText = 'N/A';
$tronParamResp = http_post_json_status('https://api.trongrid.io/wallet/getchainparameters', []);
if ((bool)($tronParamResp['ok'] ?? false)) {
    $plist = (array)(($tronParamResp['json'] ?? [])['chainParameter'] ?? []);
    foreach ($plist as $p) {
        if (!is_array($p)) continue;
        $key = (string)($p['key'] ?? '');
        if ($key === 'getEnergyFee' && is_numeric($p['value'] ?? null)) {
            $tronGasText = (string)((int)$p['value']) . ' sun/energy';
            break;
        }
    }
}
$tronNum = null;
if ((bool)($tronResp['ok'] ?? false)) {
    $tronJson = is_array($tronResp['json'] ?? null) ? $tronResp['json'] : [];
    $tronNum = isset($tronJson['block_header']['raw_data']['number']) ? (int)$tronJson['block_header']['raw_data']['number'] : null;
}
$statusList[] = [
    'chain' => 'trc20',
    'ok' => is_int($tronNum),
    'latest_block' => is_int($tronNum) ? $tronNum : null,
    'gas_gwei' => null,
    'gas_text' => $tronGasText,
    'native_symbol' => 'TRX',
    'rpc' => 'https://api.trongrid.io',
    'error' => is_int($tronNum) ? null : (string)($tronResp['error'] ?? 'getnowblock failed'),
];

$btcTip = http_get_text_status('https://mempool.space/api/blocks/tip/height');
$btcFeeText = 'N/A';
$btcFeeResp = http_get_json_list_status('https://mempool.space/api/v1/fees/recommended');
if (is_array($btcFeeResp) && is_numeric($btcFeeResp['halfHourFee'] ?? null)) {
    $btcFeeText = (string)((int)$btcFeeResp['halfHourFee']) . ' sat/vB';
}
$btcHeight = is_numeric($btcTip) ? (int)$btcTip : null;
$statusList[] = [
    'chain' => 'btc',
    'ok' => is_int($btcHeight),
    'latest_block' => is_int($btcHeight) ? $btcHeight : null,
    'gas_gwei' => null,
    'gas_text' => $btcFeeText,
    'native_symbol' => 'BTC',
    'rpc' => 'https://mempool.space/api',
    'error' => is_int($btcHeight) ? null : 'tip height unavailable',
];

status_resp([
    'status' => 'success',
    'generated_at' => time(),
    'chains' => $statusList,
]);
