<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../config/config.php';

function mb_resp(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function mb_http_get_json(string $url, int $timeout = 12): ?array
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
    $ret = curl_exec($ch);
    if ($ret === false) {
        curl_close($ch);
        return null;
    }
    curl_close($ch);
    $json = json_decode((string)$ret, true);
    return is_array($json) ? $json : null;
}

function mb_etherscan_result(array $json): ?string
{
    if (isset($json['result']) && (is_string($json['result']) || is_numeric($json['result']))) {
        return (string)$json['result'];
    }
    return null;
}

function mb_etherscan_call(array $params, string $apiKey): ?array
{
    $base = 'https://api.etherscan.io/v2/api';
    $params['apikey'] = $apiKey;
    $url = $base . '?' . http_build_query($params);
    return mb_http_get_json($url, 14);
}

function mb_dec_format(string $raw, int $decimals = 18, int $keep = 8): string
{
    $raw = trim($raw);
    if ($raw === '' || !preg_match('/^[0-9]+$/', $raw)) return '0';

    if (function_exists('bcdiv')) {
        $div = bcpow('10', (string)$decimals, 0);
        $val = bcdiv($raw, $div, max(2, $keep));
        $val = rtrim(rtrim($val, '0'), '.');
        return $val === '' ? '0' : $val;
    }

    $n = (float)$raw;
    if ($decimals > 0) $n /= pow(10, $decimals);
    $txt = number_format($n, max(2, min(10, $keep)), '.', '');
    $txt = rtrim(rtrim($txt, '0'), '.');
    return $txt === '' ? '0' : $txt;
}

$input = json_decode(file_get_contents('php://input'), true);
$address = trim((string)($input['address'] ?? $_GET['address'] ?? ''));

$isEvm = (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', $address);
$isTron = (bool)preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address);

if (!$address || (!$isEvm && !$isTron)) {
    mb_resp(['status' => 'error', 'message' => 'Please input a valid EVM (0x...) or TRON (T...) address'], 400);
}

if ($isTron) {
    $trx = '0';
    $usdt = '0';
    $usdc = '0';

    $acc = mb_http_get_json('https://apilist.tronscanapi.com/api/account?address=' . urlencode($address));
    if (is_array($acc)) {
        if (isset($acc['balance']) && is_numeric($acc['balance'])) {
            $trx = mb_dec_format((string)(int)$acc['balance'], 6, 6);
        }
        if (isset($acc['tokenBalances']) && is_array($acc['tokenBalances'])) {
            foreach ($acc['tokenBalances'] as $tb) {
                if (!is_array($tb)) continue;
                $abbr = strtoupper((string)($tb['tokenAbbr'] ?? ''));
                $contract = (string)($tb['tokenId'] ?? '');
                $balRaw = (string)($tb['balance'] ?? ($tb['amount'] ?? '0'));
                $dec = isset($tb['tokenDecimal']) && is_numeric($tb['tokenDecimal']) ? (int)$tb['tokenDecimal'] : 6;
                $val = mb_dec_format(preg_replace('/\D/', '', $balRaw) ?: '0', $dec, 6);
                if ($abbr === 'USDT' || $contract === 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t') $usdt = $val;
                if ($abbr === 'USDC' || $contract === 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8') $usdc = $val;
            }
        }
    }

    if ($trx === '0') {
        $tg = mb_http_get_json('https://api.trongrid.io/v1/accounts/' . urlencode($address));
        $bal = $tg['data'][0]['balance'] ?? null;
        if (is_numeric($bal)) $trx = mb_dec_format((string)(int)$bal, 6, 6);
    }

    mb_resp([
        'status' => 'success',
        'address' => $address,
        'chains' => [[
            'chain' => 'trc20',
            'label' => 'TRON',
            'color' => '#ef0027',
            'tokens' => ['NATIVE' => $trx, 'USDT' => $usdt, 'USDC' => $usdc],
            'error' => null,
        ]],
        'total_usdt' => $usdt,
        'total_usdc' => $usdc,
    ]);
}

$apiKey = defined('ETHERSCAN_API_KEY') ? (string)ETHERSCAN_API_KEY : '';
if ($apiKey === '' || strtoupper($apiKey) === 'YOUR_ETHERSCAN_KEY') {
    mb_resp(['status' => 'error', 'message' => 'ETHERSCAN_API_KEY is not configured'], 500);
}

$chains = [
    'eth' => ['chainid' => 1, 'label' => 'Ethereum', 'native' => 'ETH', 'color' => '#627eea'],
    'bsc' => ['chainid' => 56, 'label' => 'BSC', 'native' => 'BNB', 'color' => '#f0b90b'],
    'polygon' => ['chainid' => 137, 'label' => 'Polygon', 'native' => 'POL', 'color' => '#8247e5'],
    'arbitrum' => ['chainid' => 42161, 'label' => 'Arbitrum', 'native' => 'ETH', 'color' => '#28a0f0'],
    'optimism' => ['chainid' => 10, 'label' => 'Optimism', 'native' => 'ETH', 'color' => '#ff0420'],
    'base' => ['chainid' => 8453, 'label' => 'Base', 'native' => 'ETH', 'color' => '#0052ff'],
    'avalanche' => ['chainid' => 43114, 'label' => 'Avalanche', 'native' => 'AVAX', 'color' => '#e84142'],
    'linea' => ['chainid' => 59144, 'label' => 'Linea', 'native' => 'ETH', 'color' => '#7f5af0'],
    'opbnb' => ['chainid' => 204, 'label' => 'opBNB', 'native' => 'BNB', 'color' => '#f3ba2f'],
    'gnosis' => ['chainid' => 100, 'label' => 'Gnosis', 'native' => 'xDAI', 'color' => '#00b894'],
    'fantom' => ['chainid' => 250, 'label' => 'Fantom', 'native' => 'FTM', 'color' => '#1969ff'],
];

$tokenMap = [
    'eth' => [
        'USDT' => ['contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'decimals' => 6],
        'USDC' => ['contract' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'decimals' => 6],
    ],
    'bsc' => [
        'USDT' => ['contract' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18],
        'USDC' => ['contract' => '0x8AC76a51cc950d9822D68b83fE1Ad97B32Cd580d', 'decimals' => 18],
    ],
    'polygon' => [
        'USDT' => ['contract' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'decimals' => 6],
        'USDC' => ['contract' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359', 'decimals' => 6],
    ],
    'arbitrum' => [
        'USDT' => ['contract' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9', 'decimals' => 6],
        'USDC' => ['contract' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831', 'decimals' => 6],
    ],
    'optimism' => [
        'USDT' => ['contract' => '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58', 'decimals' => 6],
        'USDC' => ['contract' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85', 'decimals' => 6],
    ],
    'base' => [
        'USDT' => ['contract' => '0xfde4C96c8593536E31F229EA8f37b2ADa2699bb2', 'decimals' => 6],
        'USDC' => ['contract' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 'decimals' => 6],
    ],
    'avalanche' => [
        'USDT' => ['contract' => '0x9702230A8Ea53601f5cD2dc00fDBc13d4dF4A8c7', 'decimals' => 6],
        'USDC' => ['contract' => '0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E', 'decimals' => 6],
    ],
    'linea' => [
        'USDT' => ['contract' => '0xA219439258ca9da29E9Cc4cE5596924745e12B93', 'decimals' => 6],
        'USDC' => ['contract' => '0x176211869cA2b568f2A7D4EE941E073a821EE1ff', 'decimals' => 6],
    ],
    'opbnb' => [
        'USDT' => ['contract' => '0x9e5aac1ba1a2e6aed6b32689dfcf62a509ca96f3', 'decimals' => 18],
        'USDC' => ['contract' => '0x845E27B8A4ad1Fe3dc0b41b900dC8C1Bb45141C3', 'decimals' => 18],
    ],
    'gnosis' => [
        'USDT' => ['contract' => '0x4ECaBa5870353805a9F068101A40E0f32ed605C6', 'decimals' => 6],
        'USDC' => ['contract' => '0xDDAfbb505ad214D7b80b1f830fccc89B60fb7A83', 'decimals' => 6],
    ],
    'fantom' => [
        'USDT' => ['contract' => '0x049d68029688eAbF473097a2fC38ef61633A3C7A', 'decimals' => 6],
        'USDC' => ['contract' => '0x04068DA6C83AFCFA0e13ba15A6696662335D5B75', 'decimals' => 6],
    ],
];

$out = [];
$totalUsdt = 0.0;
$totalUsdc = 0.0;

foreach ($chains as $ck => $meta) {
    $chainid = (int)$meta['chainid'];
    $nativeRaw = null;
    $nativeErr = null;

    $nativeJson = mb_etherscan_call([
        'chainid' => $chainid,
        'module' => 'account',
        'action' => 'balance',
        'address' => $address,
        'tag' => 'latest',
    ], $apiKey);
    if (is_array($nativeJson)) {
        $nativeRaw = mb_etherscan_result($nativeJson);
    }
    if ($nativeRaw === null) {
        $nativeErr = 'native balance unavailable';
        $nativeRaw = '0';
    }

    $usdt = '0';
    $usdc = '0';
    foreach (($tokenMap[$ck] ?? []) as $sym => $tok) {
        $json = mb_etherscan_call([
            'chainid' => $chainid,
            'module' => 'account',
            'action' => 'tokenbalance',
            'contractaddress' => (string)$tok['contract'],
            'address' => $address,
            'tag' => 'latest',
        ], $apiKey);
        $raw = is_array($json) ? mb_etherscan_result($json) : null;
        $formatted = mb_dec_format($raw !== null ? $raw : '0', (int)$tok['decimals'], 8);
        if ($sym === 'USDT') $usdt = $formatted;
        if ($sym === 'USDC') $usdc = $formatted;
    }

    $native = mb_dec_format($nativeRaw, 18, 8);

    $out[] = [
        'chain' => $ck,
        'label' => (string)$meta['label'],
        'color' => (string)$meta['color'],
        'tokens' => [
            'NATIVE' => $native . ' ' . (string)$meta['native'],
            'USDT' => $usdt,
            'USDC' => $usdc,
        ],
        'error' => $nativeErr,
    ];

    $totalUsdt += (float)$usdt;
    $totalUsdc += (float)$usdc;
}

mb_resp([
    'status' => 'success',
    'address' => $address,
    'chains' => $out,
    'total_usdt' => (string)$totalUsdt,
    'total_usdc' => (string)$totalUsdc,
]);
