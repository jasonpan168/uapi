<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../src/Core/Database.php';

function diag_resp(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function diag_post_json(string $url, array $payload, int $timeout = 10): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = (string)curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'error' => $err !== '' ? $err : 'curl failed'];
    }
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'invalid json'];
    if (isset($json['error'])) {
        $msg = is_array($json['error']) ? (string)($json['error']['message'] ?? 'rpc error') : 'rpc error';
        return ['ok' => false, 'error' => $msg];
    }
    return ['ok' => true, 'result' => $json['result'] ?? null];
}

function diag_rpc(string $rpc, string $method, array $params = []): array
{
    return diag_post_json($rpc, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]);
}

function diag_parse_rpcs(string $override, array $defaults): array
{
    $all = [];
    $ov = trim($override);
    if ($ov !== '') {
        $parts = preg_split('/[\s,]+/', $ov) ?: [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p !== '') $all[] = $p;
        }
    }
    foreach ($defaults as $d) {
        $d = trim((string)$d);
        if ($d !== '') $all[] = $d;
    }
    return array_values(array_unique($all));
}

function diag_query_type(string $q): string
{
    if (preg_match('/^0x[a-fA-F0-9]{64}$/', $q)) return 'tx';
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $q)) return 'address';
    if (preg_match('/^[0-9]+$/', $q)) return 'block';
    return 'unknown';
}

function diag_probe_query(string $rpc, string $query, string $queryType): array
{
    if ($queryType === 'tx') {
        $tx = diag_rpc($rpc, 'eth_getTransactionByHash', [$query]);
        if (!(bool)($tx['ok'] ?? false) || !is_array($tx['result'] ?? null)) {
            return ['ok' => false, 'error' => 'tx not found'];
        }
        $receipt = diag_rpc($rpc, 'eth_getTransactionReceipt', [$query]);
        $blockNumber = (string)($tx['result']['blockNumber'] ?? '');
        $status = null;
        if ((bool)($receipt['ok'] ?? false) && is_array($receipt['result'] ?? null)) {
            $status = strtolower((string)($receipt['result']['status'] ?? ''));
        }
        return ['ok' => true, 'fingerprint' => json_encode(['tx' => $query, 'block' => $blockNumber, 'status' => $status]), 'value' => ['block' => $blockNumber, 'status' => $status]];
    }
    if ($queryType === 'address') {
        $bal = diag_rpc($rpc, 'eth_getBalance', [$query, 'latest']);
        $nonce = diag_rpc($rpc, 'eth_getTransactionCount', [$query, 'latest']);
        if (!(bool)($bal['ok'] ?? false) || !(bool)($nonce['ok'] ?? false)) {
            return ['ok' => false, 'error' => 'address probe failed'];
        }
        $b = strtolower((string)($bal['result'] ?? ''));
        $n = strtolower((string)($nonce['result'] ?? ''));
        return ['ok' => true, 'fingerprint' => json_encode(['balance' => $b, 'nonce' => $n]), 'value' => ['balance' => $b, 'nonce' => $n]];
    }
    if ($queryType === 'block') {
        $numHex = '0x' . dechex((int)$query);
        $blk = diag_rpc($rpc, 'eth_getBlockByNumber', [$numHex, false]);
        if (!(bool)($blk['ok'] ?? false) || !is_array($blk['result'] ?? null)) {
            return ['ok' => false, 'error' => 'block not found'];
        }
        $hash = strtolower((string)($blk['result']['hash'] ?? ''));
        return ['ok' => true, 'fingerprint' => json_encode(['height' => (int)$query, 'hash' => $hash]), 'value' => ['hash' => $hash]];
    }
    return ['ok' => false, 'error' => 'unsupported query type'];
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) diag_resp(['status' => 'error', 'message' => 'Invalid payload'], 400);

$chain = strtolower(trim((string)($input['chain'] ?? '')));
$query = trim((string)($input['query'] ?? ''));
if ($chain === '' || $query === '') {
    diag_resp(['status' => 'error', 'message' => 'Chain and query are required'], 400);
}

$db = Database::getInstance();
$rows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$cfg = [];
foreach ($rows as $r) {
    $cfg[(string)$r['key_name']] = (string)$r['value'];
}

$evmDefault = [
    'bsc' => ['https://bsc-dataseed.binance.org', 'https://bsc-rpc.publicnode.com'],
    'eth' => ['https://ethereum-rpc.publicnode.com', 'https://eth.llamarpc.com'],
    'arbitrum' => ['https://arb1.arbitrum.io/rpc', 'https://arbitrum-one-rpc.publicnode.com'],
    'optimism' => ['https://mainnet.optimism.io', 'https://optimism-rpc.publicnode.com'],
    'base' => ['https://mainnet.base.org', 'https://base-rpc.publicnode.com'],
    'polygon' => ['https://polygon-bor-rpc.publicnode.com', 'https://polygon.llamarpc.com'],
    'avalanche' => ['https://api.avax.network/ext/bc/C/rpc', 'https://avalanche-c-chain-rpc.publicnode.com'],
    'linea' => ['https://rpc.linea.build', 'https://linea-rpc.publicnode.com'],
    'opbnb' => ['https://opbnb-mainnet-rpc.bnbchain.org', 'https://opbnb-rpc.publicnode.com'],
    'zksync' => ['https://mainnet.era.zksync.io'],
    'fantom' => ['https://rpc.ftm.tools', 'https://fantom-rpc.publicnode.com'],
    'gnosis' => ['https://rpc.gnosischain.com', 'https://gnosis-rpc.publicnode.com'],
];

if (!isset($evmDefault[$chain])) {
    diag_resp(['status' => 'error', 'message' => 'Diagnostics currently supports EVM chains only'], 400);
}

$rpcs = diag_parse_rpcs((string)($cfg['rpc_url_' . $chain] ?? ''), $evmDefault[$chain]);
$rpcs = array_slice($rpcs, 0, 4);
$queryType = diag_query_type($query);

$nodes = [];
$maxBlock = null;
foreach ($rpcs as $rpc) {
    $bn = diag_rpc($rpc, 'eth_blockNumber', []);
    $latest = null;
    $err = null;
    if ((bool)($bn['ok'] ?? false) && is_string($bn['result'] ?? null) && preg_match('/^0x[0-9a-fA-F]+$/', (string)$bn['result'])) {
        $latest = hexdec((string)$bn['result']);
        $maxBlock = $maxBlock === null ? $latest : max($maxBlock, $latest);
    } else {
        $err = (string)($bn['error'] ?? 'eth_blockNumber failed');
    }

    $probe = diag_probe_query($rpc, $query, $queryType);
    if (!(bool)($probe['ok'] ?? false)) {
        $err = $err ?: (string)($probe['error'] ?? 'probe failed');
    }

    $nodes[] = [
        'rpc' => $rpc,
        'ok' => $err === null,
        'latest_block' => $latest,
        'probe' => $probe['value'] ?? null,
        'fingerprint' => $probe['fingerprint'] ?? null,
        'error' => $err,
    ];
}

$fingerprints = array_values(array_unique(array_filter(array_map(static fn($n) => (string)($n['fingerprint'] ?? ''), $nodes))));
$consistentProbe = count($fingerprints) <= 1;
$healthyNodes = count(array_filter($nodes, static fn($n) => (bool)($n['ok'] ?? false)));
$lagging = [];
foreach ($nodes as $n) {
    if ($maxBlock === null || !is_int($n['latest_block'])) continue;
    if ($maxBlock - (int)$n['latest_block'] >= 2) {
        $lagging[] = ['rpc' => (string)$n['rpc'], 'lag' => $maxBlock - (int)$n['latest_block']];
    }
}

diag_resp([
    'status' => 'success',
    'chain' => $chain,
    'query' => $query,
    'query_type' => $queryType,
    'healthy_nodes' => $healthyNodes,
    'total_nodes' => count($nodes),
    'consistent_probe' => $consistentProbe,
    'max_block' => $maxBlock,
    'lagging_nodes' => $lagging,
    'nodes' => $nodes,
]);
