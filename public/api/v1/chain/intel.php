<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../config/config.php';

function intel_resp(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function intel_post_json(string $url, array $payload, int $timeout = 12): ?array
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
    $ret = curl_exec($ch);
    if ($ret === false) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $json = json_decode((string)$ret, true);
    return is_array($json) ? $json : null;
}

function intel_rpc(string $rpc, string $method, array $params = []): mixed
{
    $ret = intel_post_json($rpc, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]);
    if (!is_array($ret) || isset($ret['error'])) return null;
    return $ret['result'] ?? null;
}

function intel_parse_rpcs(string $override, array $defaults): array
{
    $all = [];
    $ov = trim($override);
    if ($ov !== '') {
        $parts = preg_split('/[\s,]+/', $ov) ?: [];
        foreach ($parts as $p) {
            $u = trim((string)$p);
            if ($u !== '') $all[] = $u;
        }
    }
    foreach ($defaults as $d) {
        $u = trim((string)$d);
        if ($u !== '') $all[] = $u;
    }
    return array_values(array_unique($all));
}

function intel_hex_to_dec_str(string $hex): string
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
            $dec = (string)((float)$dec * 16.0 + (float)$n);
        }
    }
    return $dec;
}

function intel_transfer_logs(string $rpc, int $from, int $to, string $topicTransfer, string $topicAddr, string $mode, int $depth = 0): array
{
    if ($to < $from) return [];
    $topics = ($mode === 'in')
        ? [$topicTransfer, null, $topicAddr]
        : [$topicTransfer, $topicAddr];
    $ret = intel_rpc($rpc, 'eth_getLogs', [[
        'fromBlock' => '0x' . dechex($from),
        'toBlock' => '0x' . dechex($to),
        'topics' => $topics,
    ]]);
    if (is_array($ret)) return $ret;
    if (($to - $from) <= 120 || $depth >= 9) return [];
    $mid = intdiv($from + $to, 2);
    $l = intel_transfer_logs($rpc, $from, $mid, $topicTransfer, $topicAddr, $mode, $depth + 1);
    $r = intel_transfer_logs($rpc, $mid + 1, $to, $topicTransfer, $topicAddr, $mode, $depth + 1);
    return array_merge($l, $r);
}

function intel_window_blocks(string $chain, string $window): int
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
        '30d' => 86400 * 30,
        default => 86400 * 7,
    };
    return max(200, min((int)ceil($sec / max(1, $per)), 400000));
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) intel_resp(['status' => 'error', 'message' => 'Invalid payload'], 400);

$chain = strtolower(trim((string)($input['chain'] ?? 'eth')));
$address = strtolower(trim((string)($input['address'] ?? '')));
$peer = strtolower(trim((string)($input['peer'] ?? '')));
$window = strtolower(trim((string)($input['window'] ?? '7d')));
$mode = strtolower(trim((string)($input['mode'] ?? 'time')));

if (!preg_match('/^0x[a-f0-9]{40}$/', $address)) {
    intel_resp(['status' => 'error', 'message' => 'Address must be EVM hex address'], 400);
}
if ($peer !== '' && !preg_match('/^0x[a-f0-9]{40}$/', $peer)) {
    intel_resp(['status' => 'error', 'message' => 'Peer address must be EVM hex address'], 400);
}
if (!in_array($window, ['today', '7d', '30d'], true)) $window = '7d';
if (!in_array($mode, ['time', 'gas'], true)) $mode = 'time';

$allowedChains = [];
global $chains_config;
if (isset($chains_config) && is_array($chains_config)) {
    foreach ($chains_config as $k => $meta) {
        $ck = strtolower((string)$k);
        $cid = isset($meta['chain_id']) ? (int)$meta['chain_id'] : 0;
        if ($cid > 0) $allowedChains[] = $ck;
    }
}
if (empty($allowedChains)) intel_resp(['status' => 'error', 'message' => 'No EVM chains configured in platform settings'], 500);
if (!in_array($chain, $allowedChains, true)) {
    intel_resp(['status' => 'error', 'message' => 'Only EVM chains are supported'], 400);
}

$db = Database::getInstance();
$db->query("CREATE TABLE IF NOT EXISTS chain_risk_addresses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chain VARCHAR(32) NOT NULL,
    address VARCHAR(64) NOT NULL,
    risk_type VARCHAR(32) NOT NULL DEFAULT 'black',
    note VARCHAR(255) DEFAULT '',
    score INT NOT NULL DEFAULT 80,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chain_addr (chain, address)
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
$rpcs = intel_parse_rpcs((string)($cfg['rpc_url_' . $chain] ?? ''), $defaultRpc[$chain] ?? []);
$rpc = $rpcs[0] ?? '';
if ($rpc === '') intel_resp(['status' => 'error', 'message' => 'RPC unavailable'], 500);

$latestHex = intel_rpc($rpc, 'eth_blockNumber', []);
if (!is_string($latestHex) || !preg_match('/^0x[0-9a-fA-F]+$/', $latestHex)) {
    intel_resp(['status' => 'error', 'message' => 'Unable to fetch latest block'], 502);
}
$latest = hexdec($latestHex);
$fromBlock = max(0, $latest - intel_window_blocks($chain, $window));

$topicTransfer = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
$topicAddr = '0x000000000000000000000000' . substr($address, 2);
$inLogs = intel_transfer_logs($rpc, $fromBlock, $latest, $topicTransfer, $topicAddr, 'in');
$outLogs = intel_transfer_logs($rpc, $fromBlock, $latest, $topicTransfer, $topicAddr, 'out');
$logs = array_merge($inLogs, $outLogs);
if (count($logs) > 15000) $logs = array_slice($logs, 0, 15000);

$riskRows = $db->fetchAll("SELECT address, risk_type, note, score FROM chain_risk_addresses WHERE chain = ?", [$chain]);
$riskMap = [];
foreach ($riskRows as $rr) {
    $riskMap[strtolower((string)$rr['address'])] = [
        'type' => (string)$rr['risk_type'],
        'note' => (string)($rr['note'] ?? ''),
        'score' => (int)($rr['score'] ?? 80),
    ];
}

$builtinMixer = [
    'eth' => ['0xd90e2f925da726b50c4ed8d0fb90ad053324f31b','0x910cbd523d972eb0a6f4cae4618ad62622b39dbf'],
];
$mixerSet = array_fill_keys($builtinMixer[$chain] ?? [], true);

$entries = [];
$seen = [];
$counterpartyAgg = [];
$hourly = array_fill(0, 24, 0);
$tokenContracts = [];
$txHashes = [];
$inCount = 0;
$outCount = 0;
$inPeers = [];
$outPeers = [];
$suspiciousInteractions = 0;
$mixerInteractions = 0;

foreach ($logs as $log) {
    if (!is_array($log)) continue;
    $topics = $log['topics'] ?? [];
    if (!is_array($topics) || count($topics) < 3) continue;
    $txHash = strtolower((string)($log['transactionHash'] ?? ''));
    if ($txHash === '') continue;
    $uniq = $txHash . ':' . (string)($log['logIndex'] ?? '');
    if (isset($seen[$uniq])) continue;
    $seen[$uniq] = 1;

    $from = strtolower('0x' . substr((string)$topics[1], -40));
    $to = strtolower('0x' . substr((string)$topics[2], -40));
    $direction = ($to === $address) ? 'in' : (($from === $address) ? 'out' : 'unknown');
    if ($direction === 'unknown') continue;
    $counterparty = ($direction === 'in') ? $from : $to;
    $contract = strtolower((string)($log['address'] ?? ''));
    if (preg_match('/^0x[a-f0-9]{40}$/', $contract)) $tokenContracts[$contract] = 1;

    $bnHex = (string)($log['blockNumber'] ?? '0x0');
    $bn = preg_match('/^0x[0-9a-fA-F]+$/', $bnHex) ? hexdec($bnHex) : 0;
    $block = intel_rpc($rpc, 'eth_getBlockByNumber', ['0x' . dechex($bn), false]);
    $ts = (is_array($block) && isset($block['timestamp']) && is_string($block['timestamp'])) ? hexdec((string)$block['timestamp']) : 0;
    if ($ts > 0) {
        $h = (int)date('G', $ts);
        if (isset($hourly[$h])) $hourly[$h]++;
    }

    $amountRaw = intel_hex_to_dec_str((string)($log['data'] ?? '0x0'));
    $amountF = (float)$amountRaw;
    if (!isset($counterpartyAgg[$counterparty])) {
        $counterpartyAgg[$counterparty] = ['count' => 0, 'amount_raw' => 0.0, 'last_ts' => 0];
    }
    $counterpartyAgg[$counterparty]['count']++;
    $counterpartyAgg[$counterparty]['amount_raw'] += $amountF;
    $counterpartyAgg[$counterparty]['last_ts'] = max((int)$counterpartyAgg[$counterparty]['last_ts'], $ts);

    if ($direction === 'in') {
        $inCount++;
        $inPeers[$counterparty] = 1;
    } else {
        $outCount++;
        $outPeers[$counterparty] = 1;
    }

    if (isset($riskMap[$counterparty]) && in_array($riskMap[$counterparty]['type'], ['black', 'scam'], true)) {
        $suspiciousInteractions++;
    }
    if (isset($mixerSet[$counterparty])) $mixerInteractions++;

    $entries[] = [
        'tx_hash' => $txHash,
        'timestamp' => $ts,
        'time' => $ts > 0 ? date('Y-m-d H:i:s', $ts) : '',
        'block' => $bn,
        'direction' => $direction,
        'from' => $from,
        'to' => $to,
        'counterparty' => $counterparty,
        'amount_raw' => $amountRaw,
        'token_contract' => $contract,
    ];
    $txHashes[$txHash] = 1;
}

usort($entries, static function ($a, $b) { return (int)$b['timestamp'] <=> (int)$a['timestamp']; });

$gasRows = [];
$txList = array_slice(array_keys($txHashes), 0, 80);
foreach ($txList as $h) {
    $tx = intel_rpc($rpc, 'eth_getTransactionByHash', [$h]);
    if (!is_array($tx) || empty($tx['hash'])) continue;
    $receipt = intel_rpc($rpc, 'eth_getTransactionReceipt', [$h]);
    $gasUsed = (is_array($receipt) && isset($receipt['gasUsed'])) ? (float)intel_hex_to_dec_str((string)$receipt['gasUsed']) : 0.0;
    $gasPriceWei = isset($tx['gasPrice']) ? (float)intel_hex_to_dec_str((string)$tx['gasPrice']) : 0.0;
    $gasPriceGwei = $gasPriceWei > 0 ? ($gasPriceWei / 1e9) : 0.0;
    $feeNative = ($gasUsed > 0 && $gasPriceWei > 0) ? (($gasUsed * $gasPriceWei) / 1e18) : 0.0;
    $gasRows[] = [
        'tx_hash' => strtolower((string)$tx['hash']),
        'from' => strtolower((string)($tx['from'] ?? '')),
        'to' => strtolower((string)($tx['to'] ?? '')),
        'gas_used' => (int)$gasUsed,
        'gas_price_gwei' => round($gasPriceGwei, 3),
        'fee_native' => round($feeNative, 8),
    ];
}
usort($gasRows, static function ($a, $b) { return (float)$b['gas_price_gwei'] <=> (float)$a['gas_price_gwei']; });

$links = [];
foreach ($counterpartyAgg as $cp => $agg) {
    $cnt = (int)($agg['count'] ?? 0);
    $amt = (float)($agg['amount_raw'] ?? 0.0);
    $score = min(100, (int)round(($cnt * 7.5) + (log10(max(1.0, $amt)) * 5)));
    $links[] = [
        'address' => $cp,
        'interaction_count' => $cnt,
        'amount_raw_sum' => round($amt, 0),
        'last_interaction' => (int)($agg['last_ts'] ?? 0),
        'relation_score' => $score,
        'risk_type' => $riskMap[$cp]['type'] ?? null,
    ];
}
usort($links, static function ($a, $b) { return (int)$b['interaction_count'] <=> (int)$a['interaction_count']; });
$topLinks = array_slice($links, 0, 20);

$peerRelation = null;
if ($peer !== '') {
    $agg = $counterpartyAgg[$peer] ?? ['count' => 0, 'amount_raw' => 0.0, 'last_ts' => 0];
    $peerRelation = [
        'peer' => $peer,
        'interaction_count' => (int)$agg['count'],
        'amount_raw_sum' => round((float)$agg['amount_raw'], 0),
        'last_interaction' => (int)$agg['last_ts'],
        'relation_score' => min(100, (int)round(((int)$agg['count'] * 10) + (log10(max(1.0, (float)$agg['amount_raw'])) * 6))),
    ];
}

$riskScore = 12;
$riskReasons = [];
$addrRiskType = $riskMap[$address]['type'] ?? null;
$isBlack = in_array($addrRiskType, ['black'], true);
$isScam = in_array($addrRiskType, ['scam'], true);
$isMixer = isset($mixerSet[$address]) || $mixerInteractions > 0;

if ($isBlack) { $riskScore += 55; $riskReasons[] = 'Address is in black list'; }
if ($isScam) { $riskScore += 45; $riskReasons[] = 'Address is tagged as scam'; }
if ($isMixer) { $riskScore += 35; $riskReasons[] = 'Mixer interaction detected'; }
if ($suspiciousInteractions > 0) { $riskScore += min(20, $suspiciousInteractions * 2); $riskReasons[] = 'Interacts with risky counterparties'; }
if (count($outPeers) >= 40 && $outCount > ($inCount * 2)) { $riskScore += 18; $riskReasons[] = 'High fan-out transfer pattern'; }
$riskScore = max(0, min(100, $riskScore));
$riskLevel = $riskScore >= 80 ? 'critical' : ($riskScore >= 60 ? 'high' : ($riskScore >= 35 ? 'medium' : 'low'));

$totalTx = count($entries);
$days = $window === 'today' ? 1 : ($window === '30d' ? 30 : 7);
$txPerDay = $days > 0 ? ($totalTx / $days) : $totalTx;
$tags = [];
if ($outCount > ($inCount * 1.8) && count($outPeers) >= 15) $tags[] = 'Distributor';
if ($inCount > ($outCount * 1.8) && count($inPeers) >= 15) $tags[] = 'Collector';
if ($txPerDay >= 60) $tags[] = 'Bot-like Activity';
if (count($tokenContracts) >= 8) $tags[] = 'DeFi Active';
$avgGas = 0.0;
if (!empty($gasRows)) {
    $sum = 0.0;
    foreach ($gasRows as $g) $sum += (float)$g['gas_price_gwei'];
    $avgGas = $sum / count($gasRows);
    if ($avgGas >= 30) $tags[] = 'High Gas Trader';
}
if (empty($tags)) $tags[] = 'General Trader';

arsort($hourly);
$activeHours = [];
foreach (array_slice(array_keys($hourly), 0, 5) as $h) {
    $activeHours[] = ['hour' => (int)$h, 'tx_count' => (int)$hourly[$h]];
}

$profileSummary = 'Wallet appears as ' . implode(', ', $tags) . '.';

$timePath = array_map(static function ($e) {
    return [
        'tx_hash' => (string)$e['tx_hash'],
        'time' => (string)$e['time'],
        'direction' => (string)$e['direction'],
        'from' => (string)$e['from'],
        'to' => (string)$e['to'],
        'amount_raw' => (string)$e['amount_raw'],
    ];
}, array_slice($entries, 0, 30));

$gasPath = array_slice($gasRows, 0, 30);

intel_resp([
    'status' => 'success',
    'chain' => $chain,
    'address' => $address,
    'window' => $window,
    'mode' => $mode,
    'summary' => [
        'total_transfers' => $totalTx,
        'in_count' => $inCount,
        'out_count' => $outCount,
        'unique_in_peers' => count($inPeers),
        'unique_out_peers' => count($outPeers),
        'avg_gas_price_gwei' => round($avgGas, 3),
    ],
    'association' => [
        'top_links' => $topLinks,
        'peer_relation' => $peerRelation,
    ],
    'fund_path' => [
        'time_mode' => $timePath,
        'gas_mode' => $gasPath,
    ],
    'risk' => [
        'score' => $riskScore,
        'level' => $riskLevel,
        'flags' => [
            'blacklisted' => $isBlack,
            'scam' => $isScam,
            'mixer' => $isMixer,
        ],
        'reasons' => $riskReasons,
    ],
    'profile' => [
        'tags' => $tags,
        'active_hours' => $activeHours,
        'token_contract_count' => count($tokenContracts),
        'summary' => $profileSummary,
    ],
]);
