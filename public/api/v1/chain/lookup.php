<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
@set_time_limit(20);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../config/config.php';

function chain_lookup_response(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function chain_lookup_http_get_json(string $url, int $timeout = 12): ?array
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

function chain_lookup_http_post_json(string $url, array $payload, int $timeout = 12): ?array
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

function chain_lookup_http_get_text(string $url, int $timeout = 12): string
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

function chain_lookup_rpc_call(string $rpc, string $method, array $params = []): mixed
{
    $resp = chain_lookup_http_post_json($rpc, [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => $method,
        'params' => $params,
    ]);
    if (!is_array($resp) || array_key_exists('error', $resp)) {
        return null;
    }
    return $resp['result'] ?? null;
}

function chain_lookup_mul_dec(string $num, int $mul): string
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

function chain_lookup_add_dec(string $num, int $add): string
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

function chain_lookup_hex_to_dec(string $hex): string
{
    $h = strtolower(trim($hex));
    if (str_starts_with($h, '0x')) {
        $h = substr($h, 2);
    }
    $h = ltrim($h, '0');
    if ($h === '') return '0';
    $dec = '0';
    for ($i = 0; $i < strlen($h); $i++) {
        $nibble = hexdec($h[$i]);
        $dec = chain_lookup_mul_dec($dec, 16);
        if ($nibble > 0) $dec = chain_lookup_add_dec($dec, $nibble);
    }
    return $dec;
}

function chain_lookup_format_units(string $intStr, int $decimals): string
{
    $s = ltrim($intStr, '0');
    if ($s === '') $s = '0';
    if ($decimals <= 0) return $s;
    if (strlen($s) <= $decimals) {
        $s = str_pad($s, $decimals + 1, '0', STR_PAD_LEFT);
    }
    $p = strlen($s) - $decimals;
    $whole = substr($s, 0, $p);
    $frac = rtrim(substr($s, $p), '0');
    return $frac === '' ? $whole : ($whole . '.' . $frac);
}

function chain_lookup_decode_abi_string(?string $hex): string
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
        $lenHex = substr($raw, 64, 64);
        $len = hexdec($lenHex);
        if ($len > 0 && strlen($raw) >= 128 + ($len * 2)) {
            $txtHex = substr($raw, 128, $len * 2);
            $txt = @hex2bin($txtHex);
            if ($txt !== false && preg_match('/^[\x20-\x7E]+$/', $txt)) return $txt;
        }
    }

    return '';
}

function chain_lookup_decode_abi_uint(?string $hex): ?string
{
    $h = strtolower((string)$hex);
    if (!preg_match('/^0x[0-9a-f]+$/', $h)) return null;
    $raw = substr($h, 2);
    if ($raw === '') return null;
    if (strlen($raw) < 64) {
        return chain_lookup_hex_to_dec('0x' . $raw);
    }
    return chain_lookup_hex_to_dec('0x' . substr($raw, 0, 64));
}

function chain_lookup_eth_call_str(string $rpc, string $to, string $data): string
{
    $ret = chain_lookup_rpc_call($rpc, 'eth_call', [[
        'to' => $to,
        'data' => $data,
    ], 'latest']);
    return is_string($ret) ? chain_lookup_decode_abi_string($ret) : '';
}

function chain_lookup_eth_call_uint(string $rpc, string $to, string $data): ?string
{
    $ret = chain_lookup_rpc_call($rpc, 'eth_call', [[
        'to' => $to,
        'data' => $data,
    ], 'latest']);
    return is_string($ret) ? chain_lookup_decode_abi_uint($ret) : null;
}

function chain_lookup_detect_query_type(string $q): string
{
    if (preg_match('/^0x[a-fA-F0-9]{64}$/', $q)) return 'tx_or_block_hash';
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $q)) return 'address';
    if (preg_match('/^[0-9]+$/', $q)) return 'block_number';
    if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $q)) return 'address';
    if (preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{20,90}$/', $q)) return 'address';
    if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,90}$/', $q)) return 'tx_or_address';
    return 'unknown';
}

function chain_lookup_explorer_url(string $chain, string $type, string $query): string
{
    $chain = strtolower($chain);
    $map = [
        'bsc' => 'https://bscscan.com',
        'eth' => 'https://etherscan.io',
        'polygon' => 'https://polygonscan.com',
        'arbitrum' => 'https://arbiscan.io',
        'optimism' => 'https://optimistic.etherscan.io',
        'base' => 'https://basescan.org',
        'avalanche' => 'https://snowtrace.io',
        'fantom' => 'https://ftmscan.com',
        'linea' => 'https://lineascan.build',
        'zksync' => 'https://explorer.zksync.io',
        'opbnb' => 'https://opbnbscan.com',
        'gnosis' => 'https://gnosisscan.io',
        'solana' => 'https://solscan.io',
        'trc20' => 'https://tronscan.org',
        'btc' => 'https://mempool.space',
    ];
    $base = $map[$chain] ?? '';
    if ($base === '') return '';

    if ($chain === 'trc20') {
        if ($type === 'tx') return $base . '/#/transaction/' . $query;
        if ($type === 'address') return $base . '/#/address/' . $query;
        return $base;
    }
    if ($chain === 'solana') {
        if ($type === 'tx') return $base . '/tx/' . $query;
        if ($type === 'address') return $base . '/account/' . $query;
        return $base;
    }
    if ($chain === 'btc') {
        if ($type === 'tx') return $base . '/tx/' . $query;
        if ($type === 'address') return $base . '/address/' . $query;
        if ($type === 'block') return $base . '/block/' . $query;
        return $base;
    }

    if ($type === 'tx') return $base . '/tx/' . $query;
    if ($type === 'address') return $base . '/address/' . $query;
    if ($type === 'block') return $base . '/block/' . $query;
    return $base;
}

function chain_lookup_evm_chain_id(string $chain): ?int
{
    global $chains_config;
    $chain = strtolower($chain);
    if (isset($chains_config[$chain]['chain_id']) && is_numeric($chains_config[$chain]['chain_id'])) {
        $cid = (int)$chains_config[$chain]['chain_id'];
        if ($cid > 0) return $cid;
    }
    return match (strtolower($chain)) {
        'eth' => 1,
        'bsc' => 56,
        'polygon' => 137,
        'arbitrum' => 42161,
        'optimism' => 10,
        'base' => 8453,
        'avalanche' => 43114,
        'linea' => 59144,
        'opbnb' => 204,
        'zksync' => 324,
        'fantom' => 250,
        'gnosis' => 100,
        default => null,
    };
}

function chain_lookup_etherscan_call(array $params, string $apiKey): ?array
{
    if ($apiKey === '' || strtoupper($apiKey) === 'YOUR_ETHERSCAN_KEY') return null;
    $params['apikey'] = $apiKey;
    $url = 'https://api.etherscan.io/v2/api?' . http_build_query($params);
    return chain_lookup_http_get_json($url, 14);
}

function chain_lookup_etherscan_result(?array $json): mixed
{
    if (!is_array($json)) return null;
    return $json['result'] ?? null;
}

function chain_lookup_resolve_evm_etherscan(string $chain, string $query, string $queryType, string $apiKey): ?array
{
    $chainId = chain_lookup_evm_chain_id($chain);
    if ($chainId === null) return null;

    if ($queryType === 'tx_or_block_hash' || $queryType === 'tx') {
        $txJson = chain_lookup_etherscan_call([
            'chainid' => $chainId,
            'module' => 'proxy',
            'action' => 'eth_getTransactionByHash',
            'txhash' => $query,
        ], $apiKey);
        $tx = chain_lookup_etherscan_result($txJson);
        if (is_array($tx) && !empty($tx['hash'])) {
            $receipt = chain_lookup_etherscan_result(chain_lookup_etherscan_call([
                'chainid' => $chainId,
                'module' => 'proxy',
                'action' => 'eth_getTransactionReceipt',
                'txhash' => $query,
            ], $apiKey));

            $timestamp = null;
            if (!empty($tx['blockNumber'])) {
                $blk = chain_lookup_etherscan_result(chain_lookup_etherscan_call([
                    'chainid' => $chainId,
                    'module' => 'proxy',
                    'action' => 'eth_getBlockByNumber',
                    'tag' => (string)$tx['blockNumber'],
                    'boolean' => 'false',
                ], $apiKey));
                if (is_array($blk) && !empty($blk['timestamp'])) $timestamp = hexdec((string)$blk['timestamp']);
            }

            $gasUsed = (is_array($receipt) && isset($receipt['gasUsed'])) ? chain_lookup_hex_to_dec((string)$receipt['gasUsed']) : null;
            $gasPriceRaw = isset($tx['gasPrice']) ? chain_lookup_hex_to_dec((string)$tx['gasPrice']) : null;
            $txFeeNative = null;
            if ($gasUsed !== null && $gasPriceRaw !== null && ctype_digit($gasUsed) && ctype_digit($gasPriceRaw)) {
                if (function_exists('bcmul')) {
                    $txFeeNative = chain_lookup_format_units(bcmul($gasUsed, $gasPriceRaw, 0), 18);
                } else {
                    $txFeeNative = chain_lookup_format_units((string)((float)$gasUsed * (float)$gasPriceRaw), 18);
                }
            }

            $status = null;
            if (is_array($receipt) && isset($receipt['status'])) {
                $status = strtolower((string)$receipt['status']) === '0x1' ? 'success' : 'failed';
            }

            return [
                'query_type' => 'tx',
                'chain' => $chain,
                'explorer_url' => chain_lookup_explorer_url($chain, 'tx', (string)$tx['hash']),
                'data' => [
                    'hash' => (string)$tx['hash'],
                    'block_number' => isset($tx['blockNumber']) ? hexdec((string)$tx['blockNumber']) : null,
                    'timestamp' => $timestamp,
                    'from' => strtolower((string)($tx['from'] ?? '')),
                    'to' => strtolower((string)($tx['to'] ?? '')),
                    'value_native' => chain_lookup_format_units(chain_lookup_hex_to_dec((string)($tx['value'] ?? '0x0')), 18),
                    'nonce' => isset($tx['nonce']) ? hexdec((string)$tx['nonce']) : null,
                    'gas' => isset($tx['gas']) ? chain_lookup_hex_to_dec((string)$tx['gas']) : null,
                    'gas_limit' => isset($tx['gas']) ? chain_lookup_hex_to_dec((string)$tx['gas']) : null,
                    'gas_used' => $gasUsed,
                    'gas_price' => isset($tx['gasPrice']) ? chain_lookup_format_units(chain_lookup_hex_to_dec((string)$tx['gasPrice']), 9) : null,
                    'tx_fee_native' => $txFeeNative,
                    'native_symbol' => chain_lookup_evm_native_symbol($chain),
                    'status' => $status,
                    'input' => (string)($tx['input'] ?? ''),
                    'method_id' => (is_string($tx['input'] ?? null) && preg_match('/^0x[0-9a-fA-F]{8,}$/', (string)$tx['input'])) ? ('0x' . substr((string)$tx['input'], 2, 8)) : null,
                    'source' => 'etherscan_v2',
                ],
            ];
        }

        $blk = chain_lookup_etherscan_result(chain_lookup_etherscan_call([
            'chainid' => $chainId,
            'module' => 'proxy',
            'action' => 'eth_getBlockByHash',
            'tag' => $query,
            'boolean' => 'true',
        ], $apiKey));
        if (is_array($blk) && !empty($blk['hash'])) {
            return [
                'query_type' => 'block',
                'chain' => $chain,
                'explorer_url' => chain_lookup_explorer_url($chain, 'block', (string)$blk['hash']),
                'data' => [
                    'hash' => (string)$blk['hash'],
                    'number' => isset($blk['number']) ? hexdec((string)$blk['number']) : null,
                    'timestamp' => isset($blk['timestamp']) ? hexdec((string)$blk['timestamp']) : null,
                    'miner' => strtolower((string)($blk['miner'] ?? '')),
                    'gas_limit' => isset($blk['gasLimit']) ? chain_lookup_hex_to_dec((string)$blk['gasLimit']) : null,
                    'gas_used' => isset($blk['gasUsed']) ? chain_lookup_hex_to_dec((string)$blk['gasUsed']) : null,
                    'tx_count' => is_array($blk['transactions'] ?? null) ? count($blk['transactions']) : 0,
                    'source' => 'etherscan_v2',
                ],
            ];
        }
    }

    if ($queryType === 'block_number') {
        $numHex = '0x' . dechex((int)$query);
        $blk = chain_lookup_etherscan_result(chain_lookup_etherscan_call([
            'chainid' => $chainId,
            'module' => 'proxy',
            'action' => 'eth_getBlockByNumber',
            'tag' => $numHex,
            'boolean' => 'true',
        ], $apiKey));
        if (is_array($blk) && !empty($blk['hash'])) {
            return [
                'query_type' => 'block',
                'chain' => $chain,
                'explorer_url' => chain_lookup_explorer_url($chain, 'block', (string)$blk['hash']),
                'data' => [
                    'hash' => (string)$blk['hash'],
                    'number' => (int)$query,
                    'timestamp' => isset($blk['timestamp']) ? hexdec((string)$blk['timestamp']) : null,
                    'miner' => strtolower((string)($blk['miner'] ?? '')),
                    'gas_limit' => isset($blk['gasLimit']) ? chain_lookup_hex_to_dec((string)$blk['gasLimit']) : null,
                    'gas_used' => isset($blk['gasUsed']) ? chain_lookup_hex_to_dec((string)$blk['gasUsed']) : null,
                    'tx_count' => is_array($blk['transactions'] ?? null) ? count($blk['transactions']) : 0,
                    'source' => 'etherscan_v2',
                ],
            ];
        }
    }

    if ($queryType === 'address' && preg_match('/^0x[a-fA-F0-9]{40}$/', $query)) {
        $balRaw = chain_lookup_etherscan_result(chain_lookup_etherscan_call([
            'chainid' => $chainId,
            'module' => 'account',
            'action' => 'balance',
            'address' => $query,
            'tag' => 'latest',
        ], $apiKey));
        if ($balRaw === null || !preg_match('/^[0-9]+$/', (string)$balRaw)) return null;

        $nonceHex = chain_lookup_etherscan_result(chain_lookup_etherscan_call([
            'chainid' => $chainId,
            'module' => 'proxy',
            'action' => 'eth_getTransactionCount',
            'address' => $query,
            'tag' => 'latest',
        ], $apiKey));
        $nonceVal = (is_string($nonceHex) && preg_match('/^0x[0-9a-fA-F]+$/', $nonceHex)) ? hexdec($nonceHex) : null;

        return [
            'query_type' => 'address',
            'chain' => $chain,
            'explorer_url' => chain_lookup_explorer_url($chain, 'address', strtolower($query)),
            'data' => [
                'address' => strtolower($query),
                'native_balance' => chain_lookup_format_units((string)$balRaw, 18),
                'native_symbol' => chain_lookup_evm_native_symbol($chain),
                'nonce' => $nonceVal,
                'tx_count' => $nonceVal,
                'risk_level' => 'N/A',
                'source' => 'etherscan_v2',
            ],
        ];
    }

    return null;
}

function chain_lookup_token_logo_url(string $chain, string $contract): ?string
{
    $c = strtolower(trim($chain));
    $addr = strtolower(trim($contract));
    if (!preg_match('/^0x[a-f0-9]{40}$/', $addr)) return null;

    $map = [
        'eth' => 'ethereum',
        'bsc' => 'smartchain',
        'polygon' => 'polygon',
        'arbitrum' => 'arbitrum',
        'avalanche' => 'avalanchec',
    ];
    $tw = $map[$c] ?? null;
    if ($tw === null) return null;
    return 'https://raw.githubusercontent.com/trustwallet/assets/master/blockchains/' . $tw . '/assets/' . $addr . '/logo.png';
}

function chain_lookup_address_scan_window(string $chain): int
{
    return match (strtolower($chain)) {
        'eth' => 12000,
        'bsc', 'polygon' => 8000,
        default => 6000,
    };
}

function chain_lookup_probe_evm_address_quick(string $chain, string $address, array $rpcs): ?array
{
    foreach ($rpcs as $rpc) {
        $balanceHex = chain_lookup_rpc_call((string)$rpc, 'eth_getBalance', [$address, 'latest']);
        $nonceHex = chain_lookup_rpc_call((string)$rpc, 'eth_getTransactionCount', [$address, 'latest']);
        if (!is_string($balanceHex) || !preg_match('/^0x[0-9a-fA-F]+$/', $balanceHex)) continue;
        if (!is_string($nonceHex) || !preg_match('/^0x[0-9a-fA-F]+$/', $nonceHex)) continue;
        $code = chain_lookup_rpc_call((string)$rpc, 'eth_getCode', [$address, 'latest']);
        return [
            'chain' => $chain,
            'rpc' => (string)$rpc,
            'balance' => chain_lookup_format_units(chain_lookup_hex_to_dec($balanceHex), 18),
            'tx_count' => hexdec($nonceHex),
            'is_contract' => is_string($code) && strtolower($code) !== '0x',
        ];
    }
    return null;
}

function chain_lookup_pick_best_chain_for_address(string $address, array $evmMap): ?array
{
    $candidates = ['eth', 'bsc', 'arbitrum', 'base', 'polygon', 'optimism'];
    $best = null;
    $bestScore = -1.0;
    foreach ($candidates as $chain) {
        $probe = chain_lookup_probe_evm_address_quick($chain, $address, (array)($evmMap[$chain]['rpcs'] ?? []));
        if (!$probe) continue;
        $tx = (int)($probe['tx_count'] ?? 0);
        $bal = (float)($probe['balance'] ?? 0);
        $score = ($tx * 1000) + ($bal > 0 ? 1.0 : 0.0);
        if ($score > $bestScore) {
            $best = $probe;
            $bestScore = $score;
        }
        if ($tx > 0 && $chain === 'eth') {
            break;
        }
    }
    return $best;
}

function chain_lookup_blockscout_address_stats(string $chain, string $address): ?array
{
    $map = [
        'eth' => 'https://eth.blockscout.com/api/v2/addresses/',
        'arbitrum' => 'https://arbitrum.blockscout.com/api/v2/addresses/',
        'optimism' => 'https://optimism.blockscout.com/api/v2/addresses/',
        'base' => 'https://base.blockscout.com/api/v2/addresses/',
        'polygon' => 'https://polygon.blockscout.com/api/v2/addresses/',
    ];
    $base = $map[strtolower($chain)] ?? '';
    if ($base === '') return null;
    $ret = chain_lookup_http_get_json($base . strtolower($address));
    if (!is_array($ret)) return null;
    $txCount = null;
    foreach (['tx_count', 'transactions_count'] as $k) {
        if (isset($ret[$k]) && is_numeric($ret[$k])) {
            $txCount = (int)$ret[$k];
            break;
        }
    }
    $tokenTransfers = null;
    foreach (['token_transfers_count', 'token_transfer_count'] as $k) {
        if (isset($ret[$k]) && is_numeric($ret[$k])) {
            $tokenTransfers = (int)$ret[$k];
            break;
        }
    }
    return [
        'tx_count' => $txCount,
        'token_transfers_count' => $tokenTransfers,
    ];
}

function chain_lookup_auto_score(array $result): int
{
    $qt = (string)($result['query_type'] ?? '');
    $d = is_array($result['data'] ?? null) ? $result['data'] : [];
    if ($qt === 'tx') return 1000;
    if ($qt === 'block') return 900;
    if ($qt === 'token') return 800;
    if ($qt !== 'address') return 0;

    $score = 0;
    $nb = isset($d['native_balance']) ? (float)$d['native_balance'] : 0.0;
    if ($nb > 0) $score += 5;
    $txCount = isset($d['tx_count']) && is_numeric($d['tx_count']) ? (int)$d['tx_count'] : 0;
    if ($txCount > 0) $score += 8;
    $tokenCount = isset($d['token_count']) && is_numeric($d['token_count']) ? (int)$d['token_count'] : 0;
    if ($tokenCount > 0) $score += 5;
    if (!empty($d['recent_token_transfers']) && is_array($d['recent_token_transfers'])) $score += 6;
    return $score;
}

function chain_lookup_resolve_evm(string $chain, string $query, string $queryType, string $rpc): ?array
{
    $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    if ($queryType === 'tx_or_block_hash' || $queryType === 'tx') {
        $tx = chain_lookup_rpc_call($rpc, 'eth_getTransactionByHash', [$query]);
        if (is_array($tx) && !empty($tx['hash'])) {
            $receipt = chain_lookup_rpc_call($rpc, 'eth_getTransactionReceipt', [$query]);
            $block = null;
            if (!empty($tx['blockHash'])) {
                $block = chain_lookup_rpc_call($rpc, 'eth_getBlockByHash', [$tx['blockHash'], false]);
            }
            $timestamp = null;
            if (is_array($block) && !empty($block['timestamp'])) {
                $timestamp = hexdec((string)$block['timestamp']);
            }
            $nativeValue = chain_lookup_format_units(chain_lookup_hex_to_dec((string)($tx['value'] ?? '0x0')), 18);
            $status = null;
            $confirmations = null;
            if (is_array($receipt) && isset($receipt['status'])) {
                $status = strtolower((string)$receipt['status']) === '0x1' ? 'success' : 'failed';
            }
            $gasUsed = (is_array($receipt) && isset($receipt['gasUsed'])) ? chain_lookup_hex_to_dec((string)$receipt['gasUsed']) : null;
            $gasPriceRaw = isset($tx['gasPrice']) ? chain_lookup_hex_to_dec((string)$tx['gasPrice']) : null;
            $txFeeNative = null;
            if ($gasUsed !== null && $gasPriceRaw !== null && ctype_digit($gasUsed) && ctype_digit($gasPriceRaw)) {
                if (function_exists('bcmul')) {
                    $feeWei = bcmul($gasUsed, $gasPriceRaw, 0);
                    $txFeeNative = chain_lookup_format_units($feeWei, 18);
                } else {
                    $txFeeNative = chain_lookup_format_units((string)((float)$gasUsed * (float)$gasPriceRaw), 18);
                }
            }
            if (!empty($tx['blockNumber'])) {
                $latestHex = chain_lookup_rpc_call($rpc, 'eth_blockNumber', []);
                if (is_string($latestHex) && preg_match('/^0x[0-9a-fA-F]+$/', $latestHex)) {
                    $confirmations = max(0, hexdec($latestHex) - hexdec((string)$tx['blockNumber']) + 1);
                }
            }

            $transfers = [];
            if (is_array($receipt) && !empty($receipt['logs']) && is_array($receipt['logs'])) {
                foreach ($receipt['logs'] as $log) {
                    if (!is_array($log)) continue;
                    $topics = $log['topics'] ?? [];
                    if (!is_array($topics) || count($topics) < 3) continue;
                    if (strtolower((string)$topics[0]) !== $transferTopic) continue;
                    $from = '0x' . substr((string)$topics[1], -40);
                    $to = '0x' . substr((string)$topics[2], -40);
                    $dataHex = (string)($log['data'] ?? '0x0');
                    $amountRaw = chain_lookup_hex_to_dec($dataHex);
                    $symbol = '';
                    $decimals = 18;
                    $contract = (string)($log['address'] ?? '');
                    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $contract)) {
                        $decRet = chain_lookup_eth_call_uint($rpc, $contract, '0x313ce567');
                        if ($decRet !== null && ctype_digit($decRet)) {
                            $decimals = (int)$decRet;
                            if ($decimals < 0 || $decimals > 30) $decimals = 18;
                        }
                        $symbol = chain_lookup_eth_call_str($rpc, $contract, '0x95d89b41');
                    }
                    $transfers[] = [
                        'contract' => $contract,
                        'from' => strtolower($from),
                        'to' => strtolower($to),
                        'amount' => chain_lookup_format_units($amountRaw, $decimals),
                        'amount_raw' => $amountRaw,
                        'decimals' => $decimals,
                        'symbol' => $symbol,
                    ];
                }
            }

            return [
                'query_type' => 'tx',
                'chain' => $chain,
                'explorer_url' => chain_lookup_explorer_url($chain, 'tx', (string)$tx['hash']),
                'data' => [
                    'hash' => (string)$tx['hash'],
                    'block_number' => isset($tx['blockNumber']) ? hexdec((string)$tx['blockNumber']) : null,
                    'timestamp' => $timestamp,
                    'from' => strtolower((string)($tx['from'] ?? '')),
                    'to' => strtolower((string)($tx['to'] ?? '')),
                    'value_native' => $nativeValue,
                    'nonce' => isset($tx['nonce']) ? hexdec((string)$tx['nonce']) : null,
                    'gas' => isset($tx['gas']) ? chain_lookup_hex_to_dec((string)$tx['gas']) : null,
                    'gas_limit' => isset($tx['gas']) ? chain_lookup_hex_to_dec((string)$tx['gas']) : null,
                    'gas_used' => $gasUsed,
                    'gas_price' => isset($tx['gasPrice']) ? chain_lookup_format_units(chain_lookup_hex_to_dec((string)$tx['gasPrice']), 9) : null,
                    'tx_fee_native' => $txFeeNative,
                    'native_symbol' => chain_lookup_evm_native_symbol($chain),
                    'status' => $status,
                    'confirmations' => $confirmations,
                    'input' => (string)($tx['input'] ?? ''),
                    'method_id' => (is_string($tx['input'] ?? null) && preg_match('/^0x[0-9a-fA-F]{8,}$/', (string)$tx['input'])) ? ('0x' . substr((string)$tx['input'], 2, 8)) : null,
                    'token_transfers' => $transfers,
                ],
            ];
        }

        $block = chain_lookup_rpc_call($rpc, 'eth_getBlockByHash', [$query, true]);
        if (is_array($block) && !empty($block['hash'])) {
            return [
                'query_type' => 'block',
                'chain' => $chain,
                'explorer_url' => chain_lookup_explorer_url($chain, 'block', (string)$block['hash']),
                'data' => [
                    'hash' => (string)$block['hash'],
                    'number' => isset($block['number']) ? hexdec((string)$block['number']) : null,
                    'timestamp' => isset($block['timestamp']) ? hexdec((string)$block['timestamp']) : null,
                    'miner' => strtolower((string)($block['miner'] ?? '')),
                    'gas_limit' => isset($block['gasLimit']) ? chain_lookup_hex_to_dec((string)$block['gasLimit']) : null,
                    'gas_used' => isset($block['gasUsed']) ? chain_lookup_hex_to_dec((string)$block['gasUsed']) : null,
                    'tx_count' => is_array($block['transactions'] ?? null) ? count($block['transactions']) : 0,
                ],
            ];
        }
    }

    if ($queryType === 'block_number') {
        $numHex = '0x' . dechex((int)$query);
        $block = chain_lookup_rpc_call($rpc, 'eth_getBlockByNumber', [$numHex, true]);
        if (is_array($block) && !empty($block['hash'])) {
            return [
                'query_type' => 'block',
                'chain' => $chain,
                'explorer_url' => chain_lookup_explorer_url($chain, 'block', (string)$block['hash']),
                'data' => [
                    'hash' => (string)$block['hash'],
                    'number' => (int)$query,
                    'timestamp' => isset($block['timestamp']) ? hexdec((string)$block['timestamp']) : null,
                    'miner' => strtolower((string)($block['miner'] ?? '')),
                    'gas_limit' => isset($block['gasLimit']) ? chain_lookup_hex_to_dec((string)$block['gasLimit']) : null,
                    'gas_used' => isset($block['gasUsed']) ? chain_lookup_hex_to_dec((string)$block['gasUsed']) : null,
                    'tx_count' => is_array($block['transactions'] ?? null) ? count($block['transactions']) : 0,
                ],
            ];
        }
    }

    if ($queryType === 'address' && preg_match('/^0x[a-fA-F0-9]{40}$/', $query)) {
        $code = chain_lookup_rpc_call($rpc, 'eth_getCode', [$query, 'latest']);
        $isContract = is_string($code) && strtolower($code) !== '0x';
        if ($isContract) {
            $symbol = chain_lookup_eth_call_str($rpc, $query, '0x95d89b41');
            $name = chain_lookup_eth_call_str($rpc, $query, '0x06fdde03');
            $decimalsRaw = chain_lookup_eth_call_uint($rpc, $query, '0x313ce567');
            $totalSupplyRaw = chain_lookup_eth_call_uint($rpc, $query, '0x18160ddd');
            if ($symbol !== '' || $name !== '' || $decimalsRaw !== null || $totalSupplyRaw !== null) {
                $decimals = (ctype_digit((string)$decimalsRaw) ? (int)$decimalsRaw : 18);
                if ($decimals < 0 || $decimals > 30) $decimals = 18;
                return [
                    'query_type' => 'token',
                    'chain' => $chain,
                    'explorer_url' => chain_lookup_explorer_url($chain, 'address', strtolower($query)),
                    'data' => [
                        'contract' => strtolower($query),
                        'token_logo_url' => chain_lookup_token_logo_url($chain, strtolower($query)),
                        'symbol' => $symbol !== '' ? $symbol : null,
                        'name' => $name !== '' ? $name : null,
                        'decimals' => $decimals,
                        'total_supply' => $totalSupplyRaw !== null ? chain_lookup_format_units($totalSupplyRaw, $decimals) : null,
                        'holders' => null,
                        'transfers' => null,
                    ],
                ];
            }
        }

        $balanceHex = chain_lookup_rpc_call($rpc, 'eth_getBalance', [$query, 'latest']);
        $nonceHex = chain_lookup_rpc_call($rpc, 'eth_getTransactionCount', [$query, 'latest']);
        if (!is_string($balanceHex) || !preg_match('/^0x[0-9a-fA-F]+$/', $balanceHex)) {
            return null;
        }
        $balance = chain_lookup_format_units(chain_lookup_hex_to_dec($balanceHex), 18);
        $latestHex = chain_lookup_rpc_call($rpc, 'eth_blockNumber', []);
        $latest = (is_string($latestHex) && preg_match('/^0x[0-9a-fA-F]+$/', $latestHex)) ? hexdec($latestHex) : 0;
        $scanWindow = chain_lookup_address_scan_window($chain);
        $from = max(0, $latest - $scanWindow);
        $topicAddr = '0x000000000000000000000000' . strtolower(substr($query, 2));

        $incoming = chain_lookup_fetch_transfer_logs_range($rpc, $from, $latest, $transferTopic, $topicAddr, 'in');
        $outgoing = chain_lookup_fetch_transfer_logs_range($rpc, $from, $latest, $transferTopic, $topicAddr, 'out');

        $recent = [];
        $firstBlock = null;
        $lastBlock = null;
        $tokenContracts = [];
        foreach ([$incoming, $outgoing] as $logs) {
            if (!is_array($logs)) continue;
            foreach ($logs as $log) {
                if (!is_array($log)) continue;
                $topics = $log['topics'] ?? [];
                if (!is_array($topics) || count($topics) < 3) continue;
                $bn = isset($log['blockNumber']) ? hexdec((string)$log['blockNumber']) : null;
                if (is_int($bn)) {
                    $firstBlock = $firstBlock === null ? $bn : min($firstBlock, $bn);
                    $lastBlock = $lastBlock === null ? $bn : max($lastBlock, $bn);
                }
                $fromAddr = '0x' . substr((string)$topics[1], -40);
                $toAddr = '0x' . substr((string)$topics[2], -40);
                $contractAddr = strtolower((string)($log['address'] ?? ''));
                if (preg_match('/^0x[a-fA-F0-9]{40}$/', $contractAddr)) {
                    $tokenContracts[$contractAddr] = 1;
                }
                $recent[] = [
                    'tx_hash' => (string)($log['transactionHash'] ?? ''),
                    'contract' => $contractAddr,
                    'from' => strtolower($fromAddr),
                    'to' => strtolower($toAddr),
                    'block_number' => $bn,
                    'amount_raw' => chain_lookup_hex_to_dec((string)($log['data'] ?? '0x0')),
                ];
            }
        }
        usort($recent, function ($a, $b) {
            return (int)($b['block_number'] ?? 0) <=> (int)($a['block_number'] ?? 0);
        });
        $recent = array_slice($recent, 0, 30);
        $nonceVal = (is_string($nonceHex) && preg_match('/^0x[0-9a-fA-F]+$/', $nonceHex)) ? hexdec($nonceHex) : null;
        $blockscoutStats = chain_lookup_blockscout_address_stats($chain, $query);
        $txCount = $nonceVal;
        if (is_array($blockscoutStats) && isset($blockscoutStats['tx_count']) && is_numeric($blockscoutStats['tx_count'])) {
            $txCount = max((int)($nonceVal ?? 0), (int)$blockscoutStats['tx_count']);
        }

        $knownBalances = chain_lookup_probe_known_token_balances($chain, $query, $rpc);
        $knownCount = 0;
        foreach ($knownBalances as $kb) {
            if ((float)($kb['balance'] ?? 0) > 0) $knownCount++;
        }
        $tokenCount = max(count($tokenContracts), $knownCount);

        return [
            'query_type' => 'address',
            'chain' => $chain,
            'explorer_url' => chain_lookup_explorer_url($chain, 'address', strtolower($query)),
            'data' => [
                'address' => strtolower($query),
                'native_balance' => $balance,
                'native_symbol' => chain_lookup_evm_native_symbol($chain),
                'nonce' => $nonceVal,
                'tx_count' => $txCount,
                'first_seen_block' => $firstBlock,
                'last_active_block' => $lastBlock,
                'risk_level' => 'N/A',
                'scan_window_blocks' => $scanWindow,
                'token_count' => $tokenCount > 0 ? $tokenCount : null,
                'token_balances' => $knownBalances,
                'recent_token_transfers' => $recent,
            ],
        ];
    }

    return null;
}

function chain_lookup_fetch_transfer_logs_range(string $rpc, int $from, int $to, string $topicTransfer, string $topicAddr, string $mode, int $depth = 0): array
{
    if ($to < $from) return [];
    $topics = ($mode === 'in')
        ? [$topicTransfer, null, $topicAddr]
        : [$topicTransfer, $topicAddr];
    $ret = chain_lookup_rpc_call($rpc, 'eth_getLogs', [[
        'fromBlock' => '0x' . dechex($from),
        'toBlock' => '0x' . dechex($to),
        'topics' => $topics,
    ]]);
    if (is_array($ret)) return $ret;

    if (($to - $from) <= 400 || $depth >= 6) {
        return [];
    }
    $mid = intdiv($from + $to, 2);
    $left = chain_lookup_fetch_transfer_logs_range($rpc, $from, $mid, $topicTransfer, $topicAddr, $mode, $depth + 1);
    $right = chain_lookup_fetch_transfer_logs_range($rpc, $mid + 1, $to, $topicTransfer, $topicAddr, $mode, $depth + 1);
    return array_merge($left, $right);
}

function chain_lookup_known_token_catalog(string $chain): array
{
    $c = strtolower($chain);
    return match ($c) {
        'eth' => [
            ['symbol' => 'USDT', 'contract' => '0xdAC17F958D2ee523a2206206994597C13D831ec7', 'decimals' => 6],
            ['symbol' => 'USDC', 'contract' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48', 'decimals' => 6],
            ['symbol' => 'DAI',  'contract' => '0x6B175474E89094C44Da98b954EedeAC495271d0F', 'decimals' => 18],
        ],
        'bsc' => [
            ['symbol' => 'USDT', 'contract' => '0x55d398326f99059fF775485246999027B3197955', 'decimals' => 18],
            ['symbol' => 'USDC', 'contract' => '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d', 'decimals' => 18],
            ['symbol' => 'BUSD', 'contract' => '0xe9e7cea3dedca5984780bafc599bd69add087d56', 'decimals' => 18],
        ],
        'arbitrum' => [
            ['symbol' => 'USDT', 'contract' => '0xFd086bC7CD5C481DCC9C85ebe478A1C0b69FCbb9', 'decimals' => 6],
            ['symbol' => 'USDC', 'contract' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831', 'decimals' => 6],
        ],
        'base' => [
            ['symbol' => 'USDC', 'contract' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', 'decimals' => 6],
        ],
        'polygon' => [
            ['symbol' => 'USDT', 'contract' => '0xc2132D05D31c914a87C6611C10748AEb04B58e8F', 'decimals' => 6],
            ['symbol' => 'USDC', 'contract' => '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359', 'decimals' => 6],
        ],
        default => [],
    };
}

function chain_lookup_probe_known_token_balances(string $chain, string $address, string $rpc): array
{
    $ret = [];
    $addrWord = strtolower(substr($address, 2));
    $addrWord = str_pad($addrWord, 64, '0', STR_PAD_LEFT);
    foreach (chain_lookup_known_token_catalog($chain) as $t) {
        $contract = (string)($t['contract'] ?? '');
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $contract)) continue;
        $balRaw = chain_lookup_rpc_call($rpc, 'eth_call', [[
            'to' => $contract,
            'data' => '0x70a08231' . $addrWord,
        ], 'latest']);
        if (!is_string($balRaw) || !preg_match('/^0x[0-9a-fA-F]+$/', $balRaw)) continue;
        $dec = (int)($t['decimals'] ?? 18);
        $amt = chain_lookup_format_units(chain_lookup_hex_to_dec($balRaw), $dec);
        $ret[] = [
            'symbol' => (string)($t['symbol'] ?? 'TOKEN'),
            'contract' => strtolower($contract),
            'balance' => $amt,
        ];
    }
    return $ret;
}

function chain_lookup_parse_rpc_candidates(string $override, array $defaults): array
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
        $u = trim((string)$d);
        if ($u !== '') $list[] = $u;
    }
    return array_values(array_unique($list));
}

function chain_lookup_resolve_evm_with_fallback(string $chain, string $query, string $queryType, array $rpcs): ?array
{
    foreach ($rpcs as $rpc) {
        $result = chain_lookup_resolve_evm($chain, $query, $queryType, (string)$rpc);
        if ($result) {
            $result['rpc_used'] = (string)$rpc;
            return $result;
        }
    }
    return null;
}

function chain_lookup_evm_native_symbol(string $chain): string
{
    return match (strtolower($chain)) {
        'bsc', 'opbnb' => 'BNB',
        'polygon' => 'MATIC',
        'avalanche' => 'AVAX',
        'fantom' => 'FTM',
        'gnosis' => 'xDAI',
        default => 'ETH',
    };
}

function chain_lookup_resolve_solana(string $query, string $queryType, string $rpc): ?array
{
    if ($queryType === 'tx_or_block_hash' || $queryType === 'tx') {
        $tx = chain_lookup_rpc_call($rpc, 'getTransaction', [$query, ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0]]);
        if (is_array($tx) && !empty($tx['transaction'])) {
            return [
                'query_type' => 'tx',
                'chain' => 'solana',
                'explorer_url' => chain_lookup_explorer_url('solana', 'tx', $query),
                'data' => [
                    'hash' => $query,
                    'slot' => $tx['slot'] ?? null,
                    'block_time' => $tx['blockTime'] ?? null,
                    'fee_lamports' => $tx['meta']['fee'] ?? null,
                    'success' => empty($tx['meta']['err']),
                ],
            ];
        }
    }

    if (($queryType === 'address' || $queryType === 'tx_or_address') && preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,64}$/', $query)) {
        $balance = chain_lookup_rpc_call($rpc, 'getBalance', [$query]);
        $sigs = chain_lookup_rpc_call($rpc, 'getSignaturesForAddress', [$query, ['limit' => 20]]);
        if (is_array($balance) || is_array($sigs)) {
            return [
                'query_type' => 'address',
                'chain' => 'solana',
                'explorer_url' => chain_lookup_explorer_url('solana', 'address', $query),
                'data' => [
                    'address' => $query,
                    'balance_sol' => isset($balance['value']) ? ((float)$balance['value'] / 1_000_000_000) : null,
                    'recent_signatures' => is_array($sigs) ? $sigs : [],
                ],
            ];
        }
    }

    if ($queryType === 'block_number') {
        $blk = chain_lookup_rpc_call($rpc, 'getBlock', [(int)$query, ['maxSupportedTransactionVersion' => 0]]);
        if (is_array($blk)) {
            return [
                'query_type' => 'block',
                'chain' => 'solana',
                'explorer_url' => '',
                'data' => [
                    'slot' => (int)$query,
                    'block_time' => $blk['blockTime'] ?? null,
                    'tx_count' => is_array($blk['transactions'] ?? null) ? count($blk['transactions']) : 0,
                ],
            ];
        }
    }

    return null;
}

function chain_lookup_resolve_solana_with_fallback(string $query, string $queryType, array $rpcs): ?array
{
    foreach ($rpcs as $rpc) {
        $ret = chain_lookup_resolve_solana($query, $queryType, (string)$rpc);
        if ($ret) return $ret;
    }
    return null;
}

function chain_lookup_resolve_tron(string $query, string $queryType): ?array
{
    if ($queryType === 'tx_or_block_hash' || $queryType === 'tx') {
        $tx = chain_lookup_http_get_json('https://apilist.tronscanapi.com/api/transaction-info?hash=' . urlencode($query));
        if (!is_array($tx) || empty($tx['hash'])) {
            $tg = chain_lookup_http_get_json('https://api.trongrid.io/v1/transactions/' . urlencode($query));
            if (is_array($tg) && isset($tg['data'][0]) && is_array($tg['data'][0])) {
                $x = $tg['data'][0];
                $tx = [
                    'hash' => $x['txID'] ?? $query,
                    'block' => $x['blockNumber'] ?? null,
                    'confirmed' => (($x['ret'][0]['contractRet'] ?? '') === 'SUCCESS'),
                    'contractRet' => $x['ret'][0]['contractRet'] ?? null,
                    'timestamp' => $x['block_timestamp'] ?? null,
                ];
            }
        }
        if (is_array($tx) && !empty($tx['hash'])) {
            return [
                'query_type' => 'tx',
                'chain' => 'trc20',
                'explorer_url' => chain_lookup_explorer_url('trc20', 'tx', (string)$tx['hash']),
                'data' => [
                    'hash' => (string)$tx['hash'],
                    'block' => $tx['block'] ?? null,
                    'confirmed' => (bool)($tx['confirmed'] ?? false),
                    'result' => $tx['contractRet'] ?? null,
                    'timestamp' => $tx['timestamp'] ?? null,
                ],
            ];
        }
    }

    if ($queryType === 'address') {
        $account = chain_lookup_http_get_json('https://apilist.tronscanapi.com/api/account?address=' . urlencode($query));
        if (!is_array($account) || empty($account['address'])) {
            $tg = chain_lookup_http_get_json('https://api.trongrid.io/v1/accounts/' . urlencode($query));
            if (is_array($tg) && isset($tg['data'][0]) && is_array($tg['data'][0])) {
                $d = $tg['data'][0];
                $account = [
                    'address' => $query,
                    'balance' => $d['balance'] ?? 0,
                    'tokenBalances' => $d['trc20'] ?? [],
                ];
            }
        }
        if (is_array($account) && !empty($account['address'])) {
            return [
                'query_type' => 'address',
                'chain' => 'trc20',
                'explorer_url' => chain_lookup_explorer_url('trc20', 'address', (string)$account['address']),
                'data' => [
                    'address' => (string)$account['address'],
                    'trx_balance' => isset($account['balance']) ? ((float)$account['balance'] / 1_000_000) : null,
                    'token_count' => count($account['tokenBalances'] ?? []),
                ],
            ];
        }
    }

    return null;
}

function chain_lookup_resolve_btc(string $query, string $queryType): ?array
{
    if ($queryType === 'tx_or_block_hash' || $queryType === 'tx') {
        $tx = chain_lookup_http_get_json('https://mempool.space/api/tx/' . urlencode($query));
        if (!is_array($tx) || empty($tx['txid'])) {
            $tx = chain_lookup_http_get_json('https://blockstream.info/api/tx/' . urlencode($query));
        }
        if (is_array($tx) && !empty($tx['txid'])) {
            return [
                'query_type' => 'tx',
                'chain' => 'btc',
                'explorer_url' => chain_lookup_explorer_url('btc', 'tx', (string)$tx['txid']),
                'data' => [
                    'txid' => (string)$tx['txid'],
                    'fee_sats' => $tx['fee'] ?? null,
                    'vsize' => $tx['vsize'] ?? null,
                    'confirmed' => (bool)($tx['status']['confirmed'] ?? false),
                    'block_height' => $tx['status']['block_height'] ?? null,
                    'block_time' => $tx['status']['block_time'] ?? null,
                ],
            ];
        }
    }

    if ($queryType === 'address') {
        $addr = chain_lookup_http_get_json('https://mempool.space/api/address/' . urlencode($query));
        if (!is_array($addr) || !isset($addr['address'])) {
            $addr = chain_lookup_http_get_json('https://blockstream.info/api/address/' . urlencode($query));
        }
        if (is_array($addr) && isset($addr['address'])) {
            return [
                'query_type' => 'address',
                'chain' => 'btc',
                'explorer_url' => chain_lookup_explorer_url('btc', 'address', (string)$addr['address']),
                'data' => [
                    'address' => (string)$addr['address'],
                    'chain_stats' => $addr['chain_stats'] ?? [],
                    'mempool_stats' => $addr['mempool_stats'] ?? [],
                ],
            ];
        }
    }

    if ($queryType === 'block_number') {
        $hash = chain_lookup_http_get_text('https://mempool.space/api/block-height/' . urlencode($query));
        if ($hash === '') {
            $hash = chain_lookup_http_get_text('https://blockstream.info/api/block-height/' . urlencode($query));
        }
        if ($hash !== '') {
            $blk = chain_lookup_http_get_json('https://mempool.space/api/block/' . urlencode($hash));
            if (!is_array($blk)) {
                $blk = chain_lookup_http_get_json('https://blockstream.info/api/block/' . urlencode($hash));
            }
            if (is_array($blk)) {
                return [
                    'query_type' => 'block',
                    'chain' => 'btc',
                    'explorer_url' => chain_lookup_explorer_url('btc', 'block', $hash),
                    'data' => [
                        'id' => $blk['id'] ?? $hash,
                        'height' => $blk['height'] ?? (int)$query,
                        'timestamp' => $blk['timestamp'] ?? null,
                        'tx_count' => $blk['tx_count'] ?? null,
                        'size' => $blk['size'] ?? null,
                    ],
                ];
            }
        }
    }

    return null;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    chain_lookup_response(['status' => 'error', 'message' => 'Invalid payload'], 400);
}

$query = trim((string)($input['query'] ?? ''));
$chain = strtolower(trim((string)($input['chain'] ?? 'auto')));
if ($query === '') {
    chain_lookup_response(['status' => 'error', 'message' => 'Query is required'], 400);
}

$db = Database::getInstance();
$settingsRows = $db->fetchAll('SELECT key_name, value FROM system_settings');
$cfg = [];
foreach ($settingsRows as $row) {
    $cfg[(string)$row['key_name']] = (string)$row['value'];
}

$solanaRpc = trim((string)($cfg['sol_rpc_url'] ?? ''));
if ($solanaRpc === '') $solanaRpc = 'https://api.mainnet-beta.solana.com';
$solanaRpcs = array_values(array_unique(array_filter([
    $solanaRpc,
    'https://solana-rpc.publicnode.com',
    'https://rpc.ankr.com/solana',
])));
$etherscanApiKey = trim((string)($cfg['eth_api_key'] ?? ''));
if ($etherscanApiKey === '') {
    $etherscanApiKey = defined('ETHERSCAN_API_KEY') ? (string)ETHERSCAN_API_KEY : '';
}
$etherscanReady = ($etherscanApiKey !== '' && strtoupper($etherscanApiKey) !== 'YOUR_ETHERSCAN_KEY');

$evmOrder = [];
if (isset($chains_config) && is_array($chains_config)) {
    foreach ($chains_config as $k => $meta) {
        $ck = strtolower((string)$k);
        $cid = isset($meta['chain_id']) ? (int)$meta['chain_id'] : 0;
        if ($cid > 0) $evmOrder[] = $ck;
    }
}
if (empty($evmOrder)) {
    $evmOrder = ['eth', 'bsc', 'arbitrum', 'base', 'polygon', 'optimism', 'avalanche', 'linea', 'opbnb', 'zksync', 'fantom', 'gnosis'];
}

$queryType = chain_lookup_detect_query_type($query);
$result = null;

if ($chain === 'auto') {
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $query) || preg_match('/^0x[a-fA-F0-9]{64}$/', $query) || $queryType === 'block_number') {
        if ($etherscanReady) {
            foreach ($evmOrder as $ck) {
                $result = chain_lookup_resolve_evm_etherscan($ck, $query, $queryType, $etherscanApiKey);
                if ($result) break;
            }
        } else {
            chain_lookup_response(['status' => 'error', 'message' => 'EVM API key is required. Please set eth_api_key in admin settings.'], 500);
        }
    } elseif (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $query)) {
        $result = chain_lookup_resolve_tron($query, $queryType === 'unknown' ? 'address' : $queryType);
    } elseif (preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{20,90}$/', $query)) {
        $result = chain_lookup_resolve_btc($query, $queryType === 'unknown' ? 'address' : $queryType);
    }
    if (!$result) {
        $result = chain_lookup_resolve_solana_with_fallback($query, $queryType, $solanaRpcs);
    }
} elseif (in_array($chain, $evmOrder, true)) {
    if (!$etherscanReady) {
        chain_lookup_response(['status' => 'error', 'message' => 'EVM API key is required. Please set eth_api_key in admin settings.'], 500);
    }
    $result = chain_lookup_resolve_evm_etherscan($chain, $query, $queryType, $etherscanApiKey);
} elseif ($chain === 'solana') {
    $result = chain_lookup_resolve_solana_with_fallback($query, $queryType, $solanaRpcs);
} elseif ($chain === 'trc20') {
    $result = chain_lookup_resolve_tron($query, $queryType);
} elseif ($chain === 'btc') {
    $result = chain_lookup_resolve_btc($query, $queryType);
}

if (!$result) {
    chain_lookup_response([
        'status' => 'error',
        'message' => 'No on-chain data found for this query on selected chain.',
        'query_type' => $queryType,
    ], 404);
}

chain_lookup_response([
    'status' => 'success',
    'query' => $query,
    'query_type' => $result['query_type'],
    'chain' => $result['chain'],
    'explorer_url' => $result['explorer_url'],
    'data' => $result['data'],
]);
