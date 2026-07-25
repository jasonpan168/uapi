<?php
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../config/config.php';

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

$chain   = strtolower(trim($_GET['chain'] ?? ''));
$address = trim($_GET['address'] ?? '');
$currency = strtoupper(trim($_GET['currency'] ?? 'USDT'));

if (empty($chain) || empty($address)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing chain or address']);
    exit;
}

// Verify this wallet belongs to the user
$wallet = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND chain = ? AND address = ?", [$user_id, $chain, $address]);
if (!$wallet) {
    http_response_code(403);
    echo json_encode(['error' => 'Wallet not found or not authorized']);
    exit;
}

// Query balance based on chain type
global $chains_config;
$chain_cfg = $chains_config[$chain] ?? null;
if (!$chain_cfg) {
    echo json_encode(['balance' => null, 'error' => 'Chain not supported']);
    exit;
}

$balance = null;
$error   = null;

try {
    if ($chain === 'trc20') {
        // TRC20: use TronGrid public API
        $api_url = "https://apilist.tronscanapi.com/api/account/tokens?address=" . urlencode($address) . "&token=TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t&limit=1";
        $ch = curl_init($api_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'UAPI/1.0',
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $resp) {
            $data = json_decode($resp, true);
            $tokens = $data['data'] ?? [];
            foreach ($tokens as $tok) {
                $tc = strtoupper($tok['tokenAbbr'] ?? '');
                if ($tc === 'USDT' || ($tok['tokenId'] ?? '') === 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t') {
                    $balance = (float)($tok['quantity'] ?? 0);
                    break;
                }
            }
            if ($balance === null) $balance = 0.0;
        } else {
            $error = 'TronScan API error';
        }
    } else {
        // EVM chains: use public JSON-RPC to call balanceOf
        $rpc_map = [
            'bsc'       => 'https://bsc-dataseed.binance.org/',
            'eth'       => 'https://eth.llamarpc.com',
            'polygon'   => 'https://polygon-rpc.com',
            'optimism'  => 'https://mainnet.optimism.io',
            'arbitrum'  => 'https://arb1.arbitrum.io/rpc',
            'base'      => 'https://mainnet.base.org',
            'avalanche' => 'https://api.avax.network/ext/bc/C/rpc',
        ];
        $rpc = $rpc_map[$chain] ?? null;
        if (!$rpc) {
            echo json_encode(['balance' => null, 'error' => 'RPC not configured for chain']);
            exit;
        }
        // Get contract address for currency
        $contract = null;
        if ($currency === 'USDT') {
            $contracts = $chain_cfg['usdt'] ?? [];
            $contract  = is_array($contracts) ? ($contracts[0] ?? null) : $contracts;
        } elseif ($currency === 'USDC') {
            $contracts = $chain_cfg['usdc'] ?? [];
            $contract  = is_array($contracts) ? ($contracts[0] ?? null) : $contracts;
        }
        if (!$contract) {
            echo json_encode(['balance' => 0, 'currency' => $currency]);
            exit;
        }
        $decimals = (int)($chain_cfg['decimals'] ?? 18);
        // balanceOf(address) calldata
        $padded_address = str_pad(ltrim($address, '0x'), 64, '0', STR_PAD_LEFT);
        $data = '0x70a08231' . $padded_address;
        $payload = json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'eth_call',
            'params' => [['to' => $contract, 'data' => $data], 'latest']
        ]);
        $ch = curl_init($rpc);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERAGENT => 'UAPI/1.0',
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200 && $resp) {
            $rdata = json_decode($resp, true);
            $hex = $rdata['result'] ?? '0x0';
            if ($hex && $hex !== '0x') {
                $raw = hexdec(ltrim($hex, '0x'));
                $balance = $raw / pow(10, $decimals);
            } else {
                $balance = 0.0;
            }
        } else {
            $error = 'RPC error (HTTP ' . $code . ')';
        }
    }
} catch (Throwable $e) {
    error_log('[chain/balance] ' . $e->getMessage());
    $error = 'Query failed';
}

echo json_encode([
    'balance'  => $balance,
    'currency' => $currency,
    'chain'    => $chain,
    'address'  => $address,
    'error'    => $error,
]);
