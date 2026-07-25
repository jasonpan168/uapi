<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../src/Core/Database.php';

function trace_resp(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function trace_post_json(string $url, array $payload, int $timeout = 15): ?array
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
    if ($resp === false) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return is_array($data) ? $data : null;
}

function trace_rpc(string $rpc, string $method, array $params = []): mixed
{
    $ret = trace_post_json($rpc, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]);
    if (!is_array($ret) || array_key_exists('error', $ret)) return null;
    return $ret['result'] ?? null;
}

function trace_fetch_logs_range(string $rpc, int $from, int $to, string $topicTransfer, string $topicAddr, string $mode, int $depth = 0): array
{
    if ($to < $from) return [];
    $topics = ($mode === 'in')
        ? [$topicTransfer, null, $topicAddr]
        : [$topicTransfer, $topicAddr];
    $ret = trace_rpc($rpc, 'eth_getLogs', [[
        'fromBlock' => '0x' . dechex($from),
        'toBlock' => '0x' . dechex($to),
        'topics' => $topics,
    ]]);
    if (is_array($ret)) return $ret;
    if (($to - $from) <= 80 || $depth >= 10) return [];
    $mid = intdiv($from + $to, 2);
    $left = trace_fetch_logs_range($rpc, $from, $mid, $topicTransfer, $topicAddr, $mode, $depth + 1);
    $right = trace_fetch_logs_range($rpc, $mid + 1, $to, $topicTransfer, $topicAddr, $mode, $depth + 1);
    return array_merge($left, $right);
}

function trace_hex_to_dec(string $hex): string
{
    $h = strtolower(trim($hex));
    if (str_starts_with($h, '0x')) $h = substr($h, 2);
    $h = ltrim($h, '0');
    if ($h === '') return '0';
    $dec = '0';
    for ($i = 0; $i < strlen($h); $i++) {
        $n = hexdec($h[$i]);
        if (function_exists('bcmul') && function_exists('bcadd')) {
            $dec = bcmul($dec, '16', 0);
            if ($n > 0) $dec = bcadd($dec, (string)$n, 0);
        } else {
            $dec = trace_mul_dec($dec, 16);
            if ($n > 0) $dec = trace_add_dec($dec, $n);
        }
    }
    return $dec;
}

function trace_mul_dec(string $num, int $mul): string
{
    $carry = 0;
    $out = '';
    for ($i = strlen($num) - 1; $i >= 0; $i--) {
        $d = (int)$num[$i];
        $p = $d * $mul + $carry;
        $out = (string)($p % 10) . $out;
        $carry = intdiv($p, 10);
    }
    while ($carry > 0) {
        $out = (string)($carry % 10) . $out;
        $carry = intdiv($carry, 10);
    }
    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
}

function trace_add_dec(string $num, int $add): string
{
    $carry = $add;
    $out = '';
    for ($i = strlen($num) - 1; $i >= 0; $i--) {
        $d = (int)$num[$i];
        $s = $d + $carry;
        $out = (string)($s % 10) . $out;
        $carry = intdiv($s, 10);
    }
    while ($carry > 0) {
        $out = (string)($carry % 10) . $out;
        $carry = intdiv($carry, 10);
    }
    $out = ltrim($out, '0');
    return $out === '' ? '0' : $out;
}

function trace_format_units(string $intStr, int $decimals): string
{
    $s = ltrim($intStr, '0');
    if ($s === '') $s = '0';
    if ($decimals <= 0) return $s;
    if (strlen($s) <= $decimals) $s = str_pad($s, $decimals + 1, '0', STR_PAD_LEFT);
    $p = strlen($s) - $decimals;
    $whole = substr($s, 0, $p);
    $frac = rtrim(substr($s, $p), '0');
    return $frac === '' ? $whole : ($whole . '.' . $frac);
}

function trace_decode_string(?string $hex): string
{
    $h = strtolower((string)$hex);
    if (!preg_match('/^0x[0-9a-f]+$/', $h)) return '';
    $raw = substr($h, 2);
    if (strlen($raw) < 64) return '';
    if (strlen($raw) === 64) {
        $txt = rtrim(hex2bin($raw) ?: '', "\0");
        return preg_match('/^[\x20-\x7E]+$/', $txt) ? $txt : '';
    }
    if (strlen($raw) >= 128) {
        $len = hexdec(substr($raw, 64, 64));
        if ($len > 0 && strlen($raw) >= 128 + ($len * 2)) {
            $txt = @hex2bin(substr($raw, 128, $len * 2));
            if ($txt !== false && preg_match('/^[\x20-\x7E]+$/', $txt)) return $txt;
        }
    }
    return '';
}

function trace_decode_uint(?string $hex): ?int
{
    $h = strtolower((string)$hex);
    if (!preg_match('/^0x[0-9a-f]+$/', $h)) return null;
    $raw = substr($h, 2);
    if ($raw === '') return null;
    $use = strlen($raw) >= 64 ? substr($raw, 0, 64) : $raw;
    $dec = trace_hex_to_dec('0x' . $use);
    if (!preg_match('/^[0-9]+$/', $dec)) return null;
    return (int)$dec;
}

function trace_rpc_list(string $override, array $defaults): array
{
    $list = [];
    $ov = trim($override);
    if ($ov !== '') {
        $parts = preg_split('/[\s,]+/', $ov) ?: [];
        foreach ($parts as $p) {
            $u = trim((string)$p);
            if ($u !== '') $list[] = $u;
        }
    }
    foreach ($defaults as $d) {
        $d = trim((string)$d);
        if ($d !== '') $list[] = $d;
    }
    return array_values(array_unique($list));
}

function trace_window_blocks(string $chain, string $window): int
{
    $per = match ($chain) {
        'bsc' => 3,
        'polygon' => 2,
        'arbitrum' => 1,
        'optimism' => 2,
        'base' => 2,
        default => 12,
    };
    $sec = match ($window) {
        'today' => 86400,
        '7d' => 86400 * 7,
        '30d' => 86400 * 30,
        default => 86400 * 7,
    };
    $blocks = (int)ceil($sec / max(1, $per));
    return max(100, min($blocks, 300000));
}

$knownExchange = [
    'eth' => [
        '0x28c6c06298d514db089934071355e5743bf21d60' => 'Binance',
        '0x21a31ee1afc51d94c2efccaa2092ad1028285549' => 'Binance',
        '0x564286362092d8e7936f0549571a803b203aaced' => 'Coinbase',
    ],
    'bsc' => [
        '0x8894e0a0c962cb723c1976a4421c95949be2d4e3' => 'Binance',
    ],
];

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) trace_resp(['status' => 'error', 'message' => 'Invalid payload'], 400);

$chain = strtolower(trim((string)($input['chain'] ?? 'eth')));
$address = strtolower(trim((string)($input['address'] ?? '')));
$window = strtolower(trim((string)($input['window'] ?? '7d')));
if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
    trace_resp(['status' => 'error', 'message' => 'Address must be EVM hex address'], 400);
}

$allowedChains = ['eth','bsc','arbitrum','optimism','base','polygon','avalanche','linea','opbnb','zksync','fantom','gnosis'];
if (!in_array($chain, $allowedChains, true)) {
    trace_resp(['status' => 'error', 'message' => 'Only EVM chains are supported for tracing'], 400);
}
if (!in_array($window, ['today','7d','30d'], true)) $window = '7d';

$db = Database::getInstance();
$db->query("CREATE TABLE IF NOT EXISTS chain_address_labels (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chain VARCHAR(32) NOT NULL,
    address VARCHAR(64) NOT NULL,
    label VARCHAR(128) DEFAULT '',
    label_type VARCHAR(32) DEFAULT 'unknown',
    confidence DECIMAL(4,3) DEFAULT 0.500,
    note VARCHAR(255) DEFAULT '',
    UNIQUE KEY uniq_chain_address (chain, address),
    INDEX idx_chain (chain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$cfgRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$cfg = [];
foreach ($cfgRows as $r) $cfg[(string)$r['key_name']] = (string)$r['value'];

$defaultRpc = [
    'eth' => ['https://ethereum-rpc.publicnode.com', 'https://eth.llamarpc.com'],
    'bsc' => ['https://bsc-dataseed.binance.org', 'https://bsc-rpc.publicnode.com'],
    'arbitrum' => ['https://arb1.arbitrum.io/rpc', 'https://arbitrum-one-rpc.publicnode.com'],
    'optimism' => ['https://mainnet.optimism.io', 'https://optimism-rpc.publicnode.com'],
    'base' => ['https://mainnet.base.org', 'https://base-rpc.publicnode.com'],
    'polygon' => ['https://polygon-bor-rpc.publicnode.com', 'https://polygon.llamarpc.com'],
    'avalanche' => ['https://api.avax.network/ext/bc/C/rpc'],
    'linea' => ['https://rpc.linea.build'],
    'opbnb' => ['https://opbnb-mainnet-rpc.bnbchain.org'],
    'zksync' => ['https://mainnet.era.zksync.io'],
    'fantom' => ['https://rpc.ftm.tools'],
    'gnosis' => ['https://rpc.gnosischain.com'],
];
$rpcs = trace_rpc_list((string)($cfg['rpc_url_' . $chain] ?? ''), $defaultRpc[$chain] ?? []);
$rpc = $rpcs[0] ?? '';
if ($rpc === '') trace_resp(['status' => 'error', 'message' => 'RPC unavailable'], 500);

$latestHex = trace_rpc($rpc, 'eth_blockNumber', []);
if (!is_string($latestHex) || !preg_match('/^0x[0-9a-fA-F]+$/', $latestHex)) {
    trace_resp(['status' => 'error', 'message' => 'Unable to fetch latest block'], 502);
}
$latest = hexdec($latestHex);
$lookback = trace_window_blocks($chain, $window);
$fromBlock = max(0, $latest - $lookback);
$topicTransfer = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
$topicAddr = '0x000000000000000000000000' . substr($address, 2);

$inLogs = trace_fetch_logs_range($rpc, $fromBlock, $latest, $topicTransfer, $topicAddr, 'in');
$outLogs = trace_fetch_logs_range($rpc, $fromBlock, $latest, $topicTransfer, $topicAddr, 'out');
$logs = array_merge($inLogs, $outLogs);
if (count($logs) > 12000) {
    usort($logs, static function ($a, $b) {
        $ab = isset($a['blockNumber']) && is_string($a['blockNumber']) ? hexdec((string)$a['blockNumber']) : 0;
        $bb = isset($b['blockNumber']) && is_string($b['blockNumber']) ? hexdec((string)$b['blockNumber']) : 0;
        return $bb <=> $ab;
    });
    $logs = array_slice($logs, 0, 12000);
}

$tokenMeta = [];
$blockTs = [];
$labelsRows = $db->fetchAll("SELECT address, label, label_type, confidence FROM chain_address_labels WHERE chain = ?", [$chain]);
$labelMap = [];
foreach ($labelsRows as $row) {
    $labelMap[strtolower((string)$row['address'])] = [
        'label' => (string)($row['label'] ?? ''),
        'type' => (string)($row['label_type'] ?? 'unknown'),
        'confidence' => (float)($row['confidence'] ?? 0.5),
        'source' => 'local',
    ];
}

$seen = [];
$entries = [];
$outAggCount = [];
$outAggAmount = [];
$inCount = 0;
$outCount = 0;
$inAmt = 0.0;
$outAmt = 0.0;

foreach ($logs as $log) {
    if (!is_array($log)) continue;
    $topics = $log['topics'] ?? [];
    if (!is_array($topics) || count($topics) < 3) continue;
    $k = (string)($log['transactionHash'] ?? '') . ':' . (string)($log['logIndex'] ?? '');
    if (isset($seen[$k])) continue;
    $seen[$k] = 1;

    $from = strtolower('0x' . substr((string)$topics[1], -40));
    $to = strtolower('0x' . substr((string)$topics[2], -40));
    $direction = ($to === $address) ? 'in' : (($from === $address) ? 'out' : 'unknown');
    if ($direction === 'unknown') continue;
    $counterparty = ($direction === 'in') ? $from : $to;

    $contract = strtolower((string)($log['address'] ?? ''));
    if (!isset($tokenMeta[$contract])) {
        $decRet = trace_rpc($rpc, 'eth_call', [[ 'to' => $contract, 'data' => '0x313ce567' ], 'latest']);
        $symRet = trace_rpc($rpc, 'eth_call', [[ 'to' => $contract, 'data' => '0x95d89b41' ], 'latest']);
        $dec = is_string($decRet) ? trace_decode_uint($decRet) : null;
        $sym = is_string($symRet) ? trace_decode_string($symRet) : '';
        $tokenMeta[$contract] = [
            'decimals' => ($dec !== null && $dec >= 0 && $dec <= 30) ? $dec : 18,
            'symbol' => $sym !== '' ? $sym : 'TOKEN',
        ];
    }
    $meta = $tokenMeta[$contract];
    $amountRaw = trace_hex_to_dec((string)($log['data'] ?? '0x0'));
    $amount = trace_format_units($amountRaw, (int)$meta['decimals']);

    $bnHex = (string)($log['blockNumber'] ?? '0x0');
    $bn = preg_match('/^0x[0-9a-fA-F]+$/', $bnHex) ? hexdec($bnHex) : 0;
    if (!isset($blockTs[$bn])) {
        $b = trace_rpc($rpc, 'eth_getBlockByNumber', ['0x' . dechex($bn), false]);
        $blockTs[$bn] = (is_array($b) && isset($b['timestamp']) && is_string($b['timestamp'])) ? hexdec((string)$b['timestamp']) : 0;
    }
    $ts = (int)($blockTs[$bn] ?? 0);

    $label = $labelMap[$counterparty] ?? null;
    if ($label === null && isset($knownExchange[$chain][$counterparty])) {
        $label = ['label' => $knownExchange[$chain][$counterparty], 'type' => 'exchange', 'confidence' => 0.9, 'source' => 'builtin'];
    }
    if ($label === null) {
        $code = trace_rpc($rpc, 'eth_getCode', [$counterparty, 'latest']);
        $isContract = is_string($code) && strtolower($code) !== '0x';
        $label = [
            'label' => $isContract ? 'Smart Contract/Platform' : 'Personal Wallet',
            'type' => $isContract ? 'platform' : 'personal',
            'confidence' => 0.72,
            'source' => 'heuristic',
        ];
    }

    $entries[] = [
        'tx_hash' => (string)($log['transactionHash'] ?? ''),
        'timestamp' => $ts,
        'time' => $ts > 0 ? date('Y-m-d H:i:s', $ts) : '',
        'chain' => $chain,
        'direction' => $direction,
        'token_symbol' => (string)$meta['symbol'],
        'token_contract' => $contract,
        'amount' => $amount,
        'amount_raw' => $amountRaw,
        'from' => $from,
        'to' => $to,
        'counterparty' => $counterparty,
        'counterparty_label' => (string)($label['label'] ?? ''),
        'counterparty_type' => (string)($label['type'] ?? 'unknown'),
        'counterparty_confidence' => (float)($label['confidence'] ?? 0.5),
        'counterparty_source' => (string)($label['source'] ?? 'unknown'),
    ];

    $amtF = (float)$amount;
    if ($direction === 'out') {
        $outCount++;
        $outAmt += $amtF;
        if (!isset($outAggCount[$counterparty])) $outAggCount[$counterparty] = 0;
        if (!isset($outAggAmount[$counterparty])) $outAggAmount[$counterparty] = 0.0;
        $outAggCount[$counterparty]++;
        $outAggAmount[$counterparty] += $amtF;
    } else {
        $inCount++;
        $inAmt += $amtF;
    }
}

usort($entries, static function ($a, $b) {
    return (int)($b['timestamp'] ?? 0) <=> (int)($a['timestamp'] ?? 0);
});

arsort($outAggCount);
arsort($outAggAmount);
$countPie = [];
$amountPie = [];
$i = 0;
foreach ($outAggCount as $addr => $cnt) {
    if ($i++ >= 10) break;
    $countPie[] = ['name' => $addr, 'value' => (int)$cnt];
}
$i = 0;
foreach ($outAggAmount as $addr => $sum) {
    if ($i++ >= 10) break;
    $amountPie[] = ['name' => $addr, 'value' => round((float)$sum, 6)];
}

$nodes = [[
    'id' => $address,
    'name' => 'TARGET',
    'symbolSize' => 52,
    'category' => 'target',
]];
$links = [];
$n = 0;
foreach ($outAggCount as $addr => $cnt) {
    if ($n++ >= 20) break;
    $lbl = $labelMap[$addr] ?? null;
    if ($lbl === null && isset($knownExchange[$chain][$addr])) $lbl = ['type' => 'exchange', 'label' => $knownExchange[$chain][$addr]];
    $cat = (string)($lbl['type'] ?? 'counterparty');
    $nodes[] = [
        'id' => $addr,
        'name' => (strlen($addr) > 14 ? substr($addr, 0, 8) . '...' . substr($addr, -4) : $addr),
        'symbolSize' => min(42, 12 + (int)$cnt * 2),
        'category' => $cat,
    ];
    $links[] = [
        'source' => $address,
        'target' => $addr,
        'value' => (int)$cnt,
        'label' => ['show' => true, 'formatter' => (string)$cnt],
    ];
}

trace_resp([
    'status' => 'success',
    'chain' => $chain,
    'address' => $address,
    'window' => $window,
    'range' => ['from_block' => $fromBlock, 'to_block' => $latest],
    'summary' => [
        'total' => count($entries),
        'in_count' => $inCount,
        'out_count' => $outCount,
        'in_amount' => round($inAmt, 6),
        'out_amount' => round($outAmt, 6),
        'top_recipient' => count($countPie) ? $countPie[0]['name'] : null,
    ],
    'entries' => array_slice($entries, 0, 800),
    'charts' => [
        'count_pie' => $countPie,
        'amount_pie' => $amountPie,
        'mindmap' => ['nodes' => $nodes, 'links' => $links],
    ],
]);
