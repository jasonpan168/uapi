<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Core/Migrator.php';
require_once __DIR__ . '/../../config/config.php';

$db = Database::getInstance();
$migrator = new Migrator($db->getConnection());
$migrator->run();
$db->autoMigrate();
try { $db->query("ALTER TABLE chains ADD COLUMN allow_derived TINYINT(1) DEFAULT 1"); } catch (Exception $e) {}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$admin_user_id = (int)$_SESSION['user_id'];

$cfgRows = $db->fetchAll("SELECT key_name, value FROM system_settings");
$sys = [];
foreach ($cfgRows as $r) {
    $sys[$r['key_name']] = $r['value'];
}

function is_valid_evm_address($address)
{
    return (bool)preg_match('/^0x[a-fA-F0-9]{40}$/', trim((string)$address));
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

function ensure_collection_extra_schema($db)
{
    try {
        $col = $db->fetch("SHOW COLUMNS FROM admin_collection_items LIKE 'tx_error'");
        if (!$col) {
            $db->query("ALTER TABLE admin_collection_items ADD COLUMN tx_error VARCHAR(255) NULL DEFAULT NULL AFTER tx_hash");
        }
    } catch (Exception $e) {
        // ignore
    }
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
        return null;
    }

    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return null;
    }
    if ((string)($data['status'] ?? '') !== '1') {
        if (isset($data['result']) && preg_match('/^[0-9]+$/', (string)$data['result'])) {
            return (string)$data['result'];
        }
        return null;
    }

    return (string)($data['result'] ?? '0');
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

function fetch_evm_tx_receipt($chainId, $txHash, $apiKey)
{
    $chainId = (int)$chainId;
    $txHash  = trim((string)$txHash);
    $apiKey  = trim((string)$apiKey);
    if ($chainId <= 0 || !preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash) || $apiKey === '') {
        return null;
    }
    $url = 'https://api.etherscan.io/v2/api?chainid=' . urlencode((string)$chainId)
        . '&module=proxy&action=eth_getTransactionReceipt'
        . '&txhash=' . urlencode($txHash)
        . '&apikey=' . urlencode($apiKey);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $resp = curl_exec($ch);
    curl_close($ch);
    if ($resp === false) return null;
    $data = json_decode($resp, true);
    if (!is_array($data)) return null;
    $result = $data['result'] ?? null;
    if (!is_array($result)) return null; // null means pending / not mined yet
    return $result; // array with 'status' => '0x1' or '0x0'
}

ensure_collection_extra_schema($db);

$allChains = $db->fetchAll("SELECT * FROM chains WHERE status = 1 AND is_evm = 1 AND COALESCE(allow_derived, 1) = 1 ORDER BY name ASC");
$evmChains = [];
foreach ($allChains as $c) {
    $slug = strtolower((string)($c['slug'] ?? ''));
    if ($slug === '' || !isset($chains_config[$slug])) {
        continue;
    }
    $conf = $chains_config[$slug];
    $chainId = (int)($c['chain_id'] ?? ($conf['chain_id'] ?? 0));
    $usdtContract = '';
    if (!empty($c['usdt_contract']) && is_valid_evm_address($c['usdt_contract'])) {
        $usdtContract = $c['usdt_contract'];
    } elseif (!empty($conf['usdt'][0]) && is_valid_evm_address($conf['usdt'][0])) {
        $usdtContract = $conf['usdt'][0];
    }

    $usdcContract = '';
    if (!empty($c['usdc_contract']) && is_valid_evm_address($c['usdc_contract'])) {
        $usdcContract = $c['usdc_contract'];
    } elseif (!empty($conf['usdc'][0]) && is_valid_evm_address($conf['usdc'][0])) {
        $usdcContract = $conf['usdc'][0];
    }

    $evmChains[$slug] = [
        'slug' => $slug,
        'name' => (string)$c['name'],
        'symbol' => (string)($c['symbol'] ?? 'USDT'),
        'chain_id' => $chainId,
        'decimals' => (int)($conf['decimals'] ?? 6),
        'usdt_contract' => $usdtContract,
        'usdc_contract' => $usdcContract,
    ];
}

if (empty($evmChains)) {
    $evmChains = [];
}

$preferredChain = $_GET['chain'] ?? ($sys['sweep_last_chain'] ?? ($sys['payment_collection_chain'] ?? array_key_first($evmChains)));
$selectedChain = $preferredChain;
if (!isset($evmChains[$selectedChain])) {
    $selectedChain = array_key_first($evmChains);
}

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        $message = '请求被拒绝（CSRF 校验失败）';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        $selectedChain = $_POST['chain'] ?? $selectedChain;
        $isAjax = (string)($_POST['ajax'] ?? '0') === '1';
        $ajaxPayload = null;
        if (!isset($evmChains[$selectedChain])) {
            $selectedChain = array_key_first($evmChains);
        }
        upsert_setting($db, 'sweep_last_chain', (string)$selectedChain);
        $sys['sweep_last_chain'] = (string)$selectedChain;

        $chainMeta = $evmChains[$selectedChain] ?? null;
        $masterSettingKey = 'sweep_master_' . $selectedChain;
        $xpubSettingKey = 'sweep_xpub_' . $selectedChain;
        $pathSettingKey = 'sweep_path_' . $selectedChain;
        $nextIndexSettingKey = 'sweep_next_index_' . $selectedChain;
        $poolTargetSettingKey = 'sweep_pool_target_' . $selectedChain;

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
                $message = '自动派生配置已保存';
            }
        }

        if ($action === 'disable_legacy_pool' && $chainMeta) {
            $db->query(
                "UPDATE admin_derived_wallets w
                 LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
                 SET w.status = 0, w.updated_at = NOW()
                 WHERE w.chain_slug = ? AND w.status = 1 AND w.source_type IN ('xpub','manual') AND a.id IS NULL",
                [$selectedChain]
            );
            $message = '已停用未分配的历史地址';
        }

        if ($action === 'refresh_balance' && $chainMeta) {
            $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
            if ($apiKey === '' || !is_valid_evm_address($chainMeta['usdt_contract'])) {
                $message = '请检查 API Key 或 USDT 合约配置';
                $messageType = 'danger';
            } else {
                $walletId = (int)($_POST['wallet_id'] ?? 0);
                $wallets = [];
                if ($walletId > 0) {
                    $wallets = $db->fetchAll("SELECT * FROM admin_derived_wallets WHERE id = ? AND chain_slug = ?", [$walletId, $selectedChain]);
                } else {
                    $wallets = $db->fetchAll(
                        "SELECT w.*
                         FROM admin_derived_wallets w
                         INNER JOIN (
                             SELECT a.wallet_id
                             FROM admin_fee_address_allocations a
                             INNER JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid'
                             GROUP BY a.wallet_id
                         ) p ON p.wallet_id = w.id
                         LEFT JOIN admin_collection_items ci ON ci.wallet_id = w.id AND ci.status = 'broadcasted'
                         WHERE w.chain_slug = ? AND w.status = 1 AND ci.id IS NULL
                         LIMIT 150",
                        [$selectedChain]
                    );
                }
                $ok = 0; $fail = 0;
                $addresses = array_map(function ($w) { return (string)$w['address']; }, $wallets);
                $balMap = fetch_evm_usdt_balances_parallel($chainMeta['chain_id'], $chainMeta['usdt_contract'], $addresses, $apiKey, 12);
                foreach ($wallets as $w) {
                    $addr = (string)$w['address'];
                    if (!isset($balMap[$addr])) { $fail++; continue; }
                    $wei = (string)$balMap[$addr];
                    $display = format_by_decimals($wei, $chainMeta['decimals']);
                    $db->query("UPDATE admin_derived_wallets SET last_balance_wei = ?, last_balance_display = ?, updated_at = NOW() WHERE id = ?", [$wei, $display, $w['id']]);
                    $ok++;
                }
                $message = "余额刷新完成：成功 $ok，失败 $fail";
                // If ajax single wallet refresh, return updated balance
                if ((string)($_POST['ajax'] ?? '0') === '1' && $walletId > 0) {
                    $updated = $db->fetch("SELECT last_balance_display, last_balance_wei FROM admin_derived_wallets WHERE id=?", [$walletId]);
                    $ajaxPayload = [
                        'ok' => true,
                        'message' => $message,
                        'balance_display' => (string)($updated['last_balance_display'] ?? '0'),
                    ];
                }
            }
        }

        if ($action === 'create_quick_single_batch' && $chainMeta) {
            $master = trim((string)($sys[$masterSettingKey] ?? ''));
            $fromAddress = strtolower(trim((string)($_POST['from_address'] ?? '')));
            $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
            $quickToken = strtoupper(trim((string)($_POST['quick_token'] ?? 'USDT')));
            if (!in_array($quickToken, ['USDT', 'USDC'], true)) $quickToken = 'USDT';
            $quickContract = ($quickToken === 'USDC') ? ($chainMeta['usdc_contract'] ?? '') : ($chainMeta['usdt_contract'] ?? '');
            if (!is_valid_evm_address($master) || !is_valid_evm_address($quickContract)) {
                $message = "请先配置主钱包和 {$quickToken} 合约";
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } elseif (!is_valid_evm_address($fromAddress)) {
                $message = '快速转账地址格式不正确';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } elseif ($apiKey === '') {
                $message = '请先在系统设置中配置 EVM API Key';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $balanceWei = fetch_evm_usdt_balance((int)$chainMeta['chain_id'], (string)$quickContract, $fromAddress, $apiKey);
                if (!is_string($balanceWei) || !preg_match('/^[0-9]+$/', $balanceWei)) {
                    $message = "读取该地址 {$quickToken} 余额失败，请稍后重试";
                    $messageType = 'danger';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } elseif (cmp_uint_str($balanceWei, '0') <= 0) {
                    $message = "该地址当前无可归集 {$quickToken} 余额";
                    $messageType = 'warning';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } else {
                    $display = format_by_decimals($balanceWei, $chainMeta['decimals']);
                    $data = build_erc20_transfer_data($master, $balanceWei);
                    $payload = [
                        'type' => 'uapi_quick_single_transfer',
                        'chain' => $selectedChain,
                        'chain_id' => $chainMeta['chain_id'],
                        'token_symbol' => $quickToken,
                        'token_contract' => $quickContract,
                        'from' => $fromAddress,
                        'to' => $master,
                        'amount_display' => $display,
                        'amount_wei' => $balanceWei,
                        'data' => $data,
                        'note' => '管理员单地址快速转账',
                    ];

                    $db->query(
                        "INSERT INTO admin_collection_batches
                         (chain_slug, chain_id, token_symbol, token_contract, token_decimals, master_address, total_items, total_amount_display, status, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, 1, ?, 'pending', NOW(), NOW())",
                        [$selectedChain, $chainMeta['chain_id'], $quickToken, $quickContract, $chainMeta['decimals'], $master, $display]
                    );
                    $batchId = (int)$db->lastInsertId();

                    $db->query(
                        "INSERT INTO admin_collection_items
                         (batch_id, wallet_id, from_address, to_address, amount_wei, amount_display, qr_payload, status, created_at, updated_at)
                         VALUES (?, 0, ?, ?, ?, ?, ?, 'pending_sign', NOW(), NOW())",
                        [$batchId, $fromAddress, $master, $balanceWei, $display, json_encode($payload, JSON_UNESCAPED_UNICODE)]
                    );
                    $itemId = (int)$db->lastInsertId();
                    $message = "快速转账批次 #$batchId 已生成，金额 " . number_format((float)$display, 6) . " {$quickToken}";
                    $ajaxPayload = [
                        'ok' => true,
                        'message' => $message,
                        'batch_id' => $batchId,
                        'mode' => 'quick_single',
                        'batch_items' => [[
                            'item_id' => $itemId,
                            'chain' => (string)$selectedChain,
                            'chain_id' => (int)$chainMeta['chain_id'],
                            'from' => (string)$fromAddress,
                            'to' => (string)$master,
                            'token_contract' => (string)$quickContract,
                            'amount_wei' => (string)$balanceWei,
                            'data' => (string)$data,
                            'derivation_path' => '',
                            'status' => 'pending_sign',
                        ]]
                    ];
                }
            }
        }

        if ($action === 'create_batch' && $chainMeta) {
            $master = trim((string)($sys[$masterSettingKey] ?? ''));
            $batchToken = strtoupper(trim((string)($_POST['batch_token'] ?? 'USDT')));
            if (!in_array($batchToken, ['USDT', 'USDC'], true)) $batchToken = 'USDT';
            $batchContract = ($batchToken === 'USDC') ? ($chainMeta['usdc_contract'] ?? '') : ($chainMeta['usdt_contract'] ?? '');
            if (!is_valid_evm_address($master) || !is_valid_evm_address($batchContract)) {
                $message = "请检查主钱包或 {$batchToken} 合约配置";
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $minDisplay = (float)($_POST['min_amount'] ?? 0);
                $minWei = (string)max(0, (int)round($minDisplay * pow(10, $chainMeta['decimals'])));
                // Filter paid orders by the selected token currency.
                // Also filter already-collected amounts only from batches of the same token.
                // IMPORTANT: filter by admin_user_id to prevent including merchant wallets
                $wallets = $db->fetchAll(
                    "SELECT w.*,
                            COALESCE(p.paid_amount_display, 0) AS paid_amount_display,
                            COALESCE(c.collected_amount_display, 0) AS collected_amount_display,
                            (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) AS expected_uncollected_display
                     FROM admin_derived_wallets w
                     LEFT JOIN (
                         SELECT a2.wallet_id, SUM(COALESCE(o.amount, 0)) AS paid_amount_display
                         FROM admin_fee_address_allocations a2
                         INNER JOIN orders o ON o.order_no = a2.order_no AND o.status = 'paid'
                             AND UPPER(COALESCE(o.currency, 'USDT')) = ?
                         WHERE a2.allocated_to_user_id = ?
                         GROUP BY a2.wallet_id
                     ) p ON p.wallet_id = w.id
                     LEFT JOIN (
                         SELECT ci.wallet_id, SUM(COALESCE(ci.amount_display, 0)) AS collected_amount_display
                         FROM admin_collection_items ci
                         INNER JOIN admin_collection_batches b ON b.id = ci.batch_id
                             AND UPPER(COALESCE(b.token_symbol, 'USDT')) = ?
                         WHERE ci.status = 'broadcasted'
                         GROUP BY ci.wallet_id
                     ) c ON c.wallet_id = w.id
                     WHERE w.chain_slug = ? AND w.status = 1 AND w.address <> ?
                       AND EXISTS (
                           SELECT 1 FROM admin_fee_address_allocations a_own
                           WHERE a_own.wallet_id = w.id AND a_own.allocated_to_user_id = ?
                       )
                       AND (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) > 0
                     ORDER BY (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) DESC, w.id DESC
                     LIMIT 500",
                    [$batchToken, $admin_user_id, $batchToken, $selectedChain, $master, $admin_user_id]
                );
                $items = [];
                $totalDisplay = 0.0;
                foreach ($wallets as $w) {
                    $expectedDisplay = (float)($w['expected_uncollected_display'] ?? 0);
                    if ($expectedDisplay <= 0) continue;
                    $wei = decimal_to_units_str((string)$expectedDisplay, (int)$chainMeta['decimals']);
                    if (cmp_uint_str($wei, $minWei) < 0) continue;
                    $display = format_by_decimals($wei, $chainMeta['decimals']);
                    $data = build_erc20_transfer_data($master, $wei);
                    $payload = [
                        'type' => 'uapi_sweep_evm_erc20',
                        'chain' => $selectedChain,
                        'chain_id' => $chainMeta['chain_id'],
                        'token_symbol' => $batchToken,
                        'token_contract' => $batchContract,
                        'from' => $w['address'],
                        'to' => $master,
                        'amount_display' => $display,
                        'amount_wei' => $wei,
                        'data' => $data,
                        'note' => '使用对应子地址签名',
                    ];
                    $items[] = [
                        'wallet_id' => (int)$w['id'],
                        'from' => $w['address'],
                        'to' => $master,
                        'wei' => $wei,
                        'display' => $display,
                        'chain_id' => (int)$chainMeta['chain_id'],
                        'token_contract' => (string)$batchContract,
                        'data' => $data,
                        'derivation_path' => (string)($w['derivation_path'] ?? ''),
                        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ];
                    $totalDisplay += $display;
                }
                if (empty($items)) {
                    $message = "没有达到阈值的可归集 {$batchToken} 地址";
                    $messageType = 'warning';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } else {
                    $db->query("INSERT INTO admin_collection_batches (chain_slug, chain_id, token_symbol, token_contract, token_decimals, master_address, total_items, total_amount_display, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())", [$selectedChain, $chainMeta['chain_id'], $batchToken, $batchContract, $chainMeta['decimals'], $master, count($items), $totalDisplay]);
                    $batchId = (int)$db->lastInsertId();
                    $ajaxBatchItems = [];
                    foreach ($items as $it) {
                        $db->query("INSERT INTO admin_collection_items (batch_id, wallet_id, from_address, to_address, amount_wei, amount_display, qr_payload, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_sign', NOW(), NOW())", [$batchId, $it['wallet_id'], $it['from'], $it['to'], $it['wei'], $it['display'], $it['payload']]);
                        $itemId = (int)$db->lastInsertId();
                        $ajaxBatchItems[] = ['item_id' => $itemId, 'chain' => (string)$selectedChain, 'chain_id' => (int)$it['chain_id'], 'from' => (string)$it['from'], 'to' => (string)$it['to'], 'token_contract' => (string)$it['token_contract'], 'amount_wei' => (string)$it['wei'], 'data' => (string)$it['data'], 'derivation_path' => (string)$it['derivation_path'], 'status' => 'pending_sign'];
                    }
                    $message = "归集批次 #$batchId 生成成功，共 " . count($items) . " 笔（{$batchToken}）";
                    $ajaxPayload = ['ok' => true, 'message' => $message, 'batch_id' => $batchId, 'batch_items' => $ajaxBatchItems];
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
                    "SELECT i.id, i.batch_id
                     FROM admin_collection_items i
                     INNER JOIN admin_collection_batches b ON b.id = i.batch_id
                     WHERE i.id = ? AND b.chain_slug = ?
                     LIMIT 1",
                    [$itemId, $selectedChain]
                );
                if (!$item) {
                    $message = '记录不存在';
                    $messageType = 'danger';
                } else {
                    $db->query(
                        "UPDATE admin_collection_items
                         SET status='pending_sign', tx_hash=NULL, tx_error='手动回滚：等待重新归集', updated_at=NOW()
                         WHERE id=?",
                        [$itemId]
                    );
                    $db->query("UPDATE admin_collection_batches SET status='pending', updated_at=NOW() WHERE id=?", [(int)$item['batch_id']]);
                    $message = '已回滚到待归集';
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
                $batch = $db->fetch(
                    "SELECT id, status FROM admin_collection_batches WHERE id = ? AND chain_slug = ? LIMIT 1",
                    [$batchId, $selectedChain]
                );
                if (!$batch) {
                    $message = '批次不存在';
                    $messageType = 'danger';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } elseif ((string)($batch['status'] ?? '') === 'completed') {
                    $message = '该批次已全部归集完成，无需回滚';
                    $messageType = 'warning';
                    $ajaxPayload = ['ok' => false, 'message' => $message];
                } else {
                    $db->query(
                        "UPDATE admin_collection_items
                         SET status='pending_sign', tx_hash=NULL, tx_error='批次回滚：等待重新签名归集', updated_at=NOW()
                         WHERE batch_id=?",
                        [$batchId]
                    );
                    $db->query(
                        "UPDATE admin_collection_batches SET status='pending', updated_at=NOW() WHERE id=?",
                        [$batchId]
                    );
                    $message = "批次 #$batchId 已全部回滚到待签名";
                    $messageType = 'success';
                    $ajaxPayload = ['ok' => true, 'message' => $message];
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
                $db->query("UPDATE admin_collection_items SET status = 'broadcasted', tx_hash = ?, updated_at = NOW() WHERE id = ?", [$txHash, $itemId]);
                $message = '已标记为已广播';
            }
        }

        if ($action === 'broadcast_signed') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $rawTx = trim((string)($_POST['signed_raw_tx'] ?? ''));
            if ($itemId <= 0 || !preg_match('/^0x[a-fA-F0-9]+$/', $rawTx)) {
                $message = '已签名原始交易格式不正确';
                $messageType = 'danger';
            } else {
                $item = $db->fetch("SELECT i.*, b.chain_id FROM admin_collection_items i JOIN admin_collection_batches b ON b.id = i.batch_id WHERE i.id = ? LIMIT 1", [$itemId]);
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
                            $db->query("UPDATE admin_collection_items SET status='broadcasted', tx_hash=?, tx_error=NULL, updated_at=NOW() WHERE id=?", [$txHash, $itemId]);
                            $message = '广播成功：' . substr($txHash, 0, 12) . '...' . substr($txHash, -10);
                            $messageType = 'success';
                        } else {
                            $errTxt = (string)($ret['error'] ?? '未知错误');
                            $db->query("UPDATE admin_collection_items SET status='failed', tx_error=?, updated_at=NOW() WHERE id=?", [mb_substr($errTxt, 0, 250), $itemId]);
                            $message = '广播失败：' . $errTxt;
                            $messageType = 'danger';
                        }
                    }
                }
            }
        }

        if ($action === 'broadcast_signed_batch') {
            $input = trim((string)($_POST['signed_batch_json'] ?? ''));
            $rows = json_decode($input, true);
            $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
            if (!is_array($rows) || $apiKey === '') {
                $message = '无效参数或未配置API Key';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $ok = 0; $fail = 0; $fails = [];
                $successes = [];
                $fail_details = [];
                $touchedBatch = [];
                foreach ($rows as $r) {
                    $itemId = (int)($r['item_id'] ?? 0);
                    $rawTx = trim((string)($r['signed_raw_tx'] ?? ''));
                    $item = $db->fetch("SELECT i.*, b.chain_id, b.token_contract, b.token_decimals FROM admin_collection_items i JOIN admin_collection_batches b ON b.id = i.batch_id WHERE i.id = ?", [$itemId]);
                    if (!$item || (string)$item['status'] === 'broadcasted') continue;
                    $touchedBatch[(int)$item['batch_id']] = true;
                    // Validate amount_wei is non-zero before broadcasting
                    if (empty($item['amount_wei']) || $item['amount_wei'] === '0') {
                        $fail++;
                        $reason = '归集金额为零，跳过广播';
                        $db->query("UPDATE admin_collection_items SET status='failed', tx_error=?, updated_at=NOW() WHERE id=?", [mb_substr($reason, 0, 250), $itemId]);
                        $fails[] = "#$itemId: " . $reason;
                        $fail_details[] = ['item_id' => $itemId, 'address' => (string)$item['from_address'], 'reason' => $reason];
                        continue;
                    }
                    // Pre-flight: verify actual on-chain token balance before broadcasting
                    $pfContract = trim((string)($item['token_contract'] ?? ''));
                    $pfDecimals = (int)($item['token_decimals'] ?? 6);
                    if ($apiKey !== '' && is_valid_evm_address($pfContract) && is_valid_evm_address((string)($item['from_address'] ?? ''))) {
                        $actualBalWei = fetch_evm_usdt_balance((int)$item['chain_id'], $pfContract, (string)$item['from_address'], $apiKey);
                        // If stored contract returned 0/null but expected > 0, try the chain's current
                        // configured USDT contract as a fallback (handles stale batch data after contract fix)
                        if (($actualBalWei === null || cmp_uint_str($actualBalWei, '0') <= 0) && cmp_uint_str((string)$item['amount_wei'], '0') > 0) {
                            $chainSlug = '';
                            foreach ($evmChains as $slug => $cm) {
                                if ((int)$cm['chain_id'] === (int)$item['chain_id']) { $chainSlug = $slug; break; }
                            }
                            $fallbackContract = $evmChains[$chainSlug]['usdt_contract'] ?? '';
                            if ($fallbackContract !== '' && $fallbackContract !== $pfContract && is_valid_evm_address($fallbackContract)) {
                                $fallbackBal = fetch_evm_usdt_balance((int)$item['chain_id'], $fallbackContract, (string)$item['from_address'], $apiKey);
                                if ($fallbackBal !== null && cmp_uint_str($fallbackBal, '0') > 0) {
                                    // Found balance via current config contract; update batch record and use it
                                    $db->query("UPDATE admin_collection_batches SET token_contract=? WHERE id=?", [$fallbackContract, (int)$item['batch_id']]);
                                    $actualBalWei = $fallbackBal;
                                    $pfContract   = $fallbackContract;
                                }
                            }
                        }
                        if ($actualBalWei !== null && cmp_uint_str($actualBalWei, (string)$item['amount_wei']) < 0) {
                            $fail++;
                            $actualDisp = format_by_decimals($actualBalWei, $pfDecimals);
                            $expectDisp = format_by_decimals((string)$item['amount_wei'], $pfDecimals);
                            $reason = "链上余额不足（实际={$actualDisp}，预期={$expectDisp}），跳过广播（资金可能已手动转移）";
                            $db->query("UPDATE admin_collection_items SET status='failed', tx_error=?, updated_at=NOW() WHERE id=?", [mb_substr($reason, 0, 250), $itemId]);
                            $fails[] = "#$itemId: " . $reason;
                            $fail_details[] = ['item_id' => $itemId, 'address' => (string)$item['from_address'], 'reason' => $reason];
                            continue;
                        }
                    }
                    $ret = broadcast_evm_raw_tx((int)$item['chain_id'], $rawTx, $apiKey);
                    if (!empty($ret['ok'])) {
                        $db->query("UPDATE admin_collection_items SET status='broadcasted', tx_hash=?, tx_error=NULL, updated_at=NOW() WHERE id=?", [$ret['tx_hash'], $itemId]);
                        $ok++;
                        $successes[] = ['item_id' => $itemId, 'address' => (string)$item['from_address'], 'tx_hash' => (string)$ret['tx_hash']];
                    } else {
                        $fail++;
                        $reason = (string)($ret['error'] ?? '未知');
                        $db->query("UPDATE admin_collection_items SET status='failed', tx_error=?, updated_at=NOW() WHERE id=?", [mb_substr($reason, 0, 250), $itemId]);
                        $fails[] = "#$itemId: " . $reason;
                        $fail_details[] = ['item_id' => $itemId, 'address' => (string)$item['from_address'], 'reason' => $reason];
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
                $message = "广播完成：成功 $ok，失败 $fail";
                $ajaxPayload = [
                    'ok' => $fail === 0,
                    'message' => $message,
                    'ok_count' => $ok,
                    'fail_count' => $fail,
                    'fails' => $fails,
                    'successes' => $successes ?? [],
                    'fail_details' => $fail_details ?? []
                ];
            }
        }

        if ($action === 'verify_tx_receipts') {
            $txMap = json_decode(trim((string)($_POST['tx_map'] ?? '')), true);
            $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
            $results = [];
            if (is_array($txMap) && $apiKey !== '') {
                foreach ($txMap as $itemId => $txHash) {
                    $itemId = (int)$itemId;
                    if ($itemId <= 0 || !preg_match('/^0x[a-fA-F0-9]{64}$/', (string)$txHash)) continue;
                    $item = $db->fetch("SELECT i.id, i.batch_id, b.chain_id FROM admin_collection_items i JOIN admin_collection_batches b ON b.id=i.batch_id WHERE i.id=? AND i.status='broadcasted'", [$itemId]);
                    if (!$item) { $results[] = ['item_id' => $itemId, 'status' => 'skipped']; continue; }
                    $receipt = fetch_evm_tx_receipt((int)$item['chain_id'], (string)$txHash, $apiKey);
                    if ($receipt === null) {
                        $results[] = ['item_id' => $itemId, 'status' => 'pending']; // not mined yet
                        continue;
                    }
                    $hexStatus = strtolower((string)($receipt['status'] ?? '0x1'));
                    $onChainOk = ($hexStatus === '0x1' || $hexStatus === '1');
                    if (!$onChainOk) {
                        $db->query("UPDATE admin_collection_items SET status='failed', tx_error='链上已提交但执行失败(revert)', updated_at=NOW() WHERE id=?", [$itemId]);
                        // Re-evaluate batch status
                        $bid = (int)$item['batch_id'];
                        $left = $db->fetch("SELECT COUNT(*) AS c FROM admin_collection_items WHERE batch_id=? AND status NOT IN ('broadcasted','failed')", [$bid]);
                        $hasFailed = (int)($db->fetch("SELECT COUNT(*) AS c FROM admin_collection_items WHERE batch_id=? AND status='failed'", [$bid])['c'] ?? 0);
                        $remaining = (int)($left['c'] ?? 0);
                        $newBatchStatus = $remaining === 0 ? ($hasFailed > 0 ? 'partial' : 'completed') : 'pending';
                        $db->query("UPDATE admin_collection_batches SET status=?, updated_at=NOW() WHERE id=?", [$newBatchStatus, $bid]);
                        $results[] = ['item_id' => $itemId, 'status' => 'reverted'];
                    } else {
                        $results[] = ['item_id' => $itemId, 'status' => 'success'];
                    }
                }
            }
            $ajaxPayload = ['ok' => true, 'results' => $results];
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

        if ($action === 'broadcast_raw_batch') {
            $chainId = (int)($_POST['chain_id'] ?? 0);
            $rawJson = trim((string)($_POST['raw_txs_json'] ?? ''));
            $rows = json_decode($rawJson, true);
            $apiKey = trim((string)($sys['eth_api_key'] ?? ''));
            if ($chainId <= 0 || !is_array($rows) || $apiKey === '') {
                $message = '参数错误';
                $messageType = 'danger';
                $ajaxPayload = ['ok' => false, 'message' => $message];
            } else {
                $ok = 0; $fail = 0;
                $results = [];
                foreach ($rows as $rawTx) {
                    $ret = broadcast_evm_raw_tx($chainId, $rawTx, $apiKey);
                    if (!empty($ret['ok'])) {
                        $ok++;
                        $results[] = ['ok' => true, 'tx_hash' => (string)$ret['tx_hash']];
                    } else {
                        $fail++;
                        $results[] = ['ok' => false, 'error' => (string)($ret['error'] ?? '未知错误')];
                    }
                }
                $message = "广播完成：成功 $ok，失败 $fail";
                $ajaxPayload = [
                    'ok' => $fail === 0,
                    'message' => $message,
                    'ok_count' => $ok,
                    'fail_count' => $fail,
                    'results' => $results
                ];
            }
        }

        if ($action === 'fetch_nonce') {
            $address = trim((string)($_POST['address'] ?? ''));
            $nonceHex = fetch_evm_tx_count((int)($evmChains[$selectedChain]['chain_id'] ?? 0), $address, trim((string)($sys['eth_api_key'] ?? '')));
            if ($nonceHex === null) {
                $ajaxPayload = ['ok' => false, 'message' => '读取 nonce 失败'];
            } else {
                $ajaxPayload = ['ok' => true, 'message' => "nonce: $nonceHex", 'nonce' => $nonceHex];
            }
        }

        if ($action === 'save_gas_profile') {
            $profile = trim((string)($_POST['gas_profile'] ?? 'evm_standard'));
            $path = trim((string)($_POST['gas_path'] ?? ''));
            $address = strtolower(trim((string)($_POST['gas_address'] ?? '')));
            $account = (int)($_POST['gas_account'] ?? 0);
            $index = (int)($_POST['gas_index'] ?? 0);
            upsert_setting($db, 'sweep_gas_profile_' . $selectedChain, $profile);
            upsert_setting($db, 'sweep_gas_path_' . $selectedChain, $path);
            upsert_setting($db, 'sweep_gas_address_' . $selectedChain, $address);
            upsert_setting($db, 'sweep_gas_account_' . $selectedChain, (string)$account);
            upsert_setting($db, 'sweep_gas_index_' . $selectedChain, (string)$index);
            $sys['sweep_gas_profile_' . $selectedChain] = $profile;
            $sys['sweep_gas_path_' . $selectedChain] = $path;
            $sys['sweep_gas_address_' . $selectedChain] = $address;
            $sys['sweep_gas_account_' . $selectedChain] = (string)$account;
            $sys['sweep_gas_index_' . $selectedChain] = (string)$index;
            $ajaxPayload = ['ok' => true, 'message' => 'Gas路径绑定成功'];
        }

        if ($action === 'get_batch_items') {
            $batchId = (int)($_POST['batch_id'] ?? 0);
            if ($batchId <= 0) {
                $ajaxPayload = ['ok' => false, 'message' => '参数错误'];
            } else {
                $batch = $db->fetch(
                    "SELECT id, status, total_items, total_amount_display FROM admin_collection_batches WHERE id = ? AND chain_slug = ? LIMIT 1",
                    [$batchId, $selectedChain]
                );
                if (!$batch) {
                    $ajaxPayload = ['ok' => false, 'message' => '批次不存在'];
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

        if ($isAjax && $ajaxPayload !== null) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($ajaxPayload, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

$masterMap = [];
foreach ($evmChains as $slug => $meta) {
    $masterMap[$slug] = trim((string)($sys['sweep_master_' . $slug] ?? ''));
}

$xpubMap = [];
$pathMap = [];
$nextIndexMap = [];
foreach ($evmChains as $slug => $meta) {
    $xpubMap[$slug] = trim((string)($sys['sweep_xpub_' . $slug] ?? ''));
    $pathMap[$slug] = trim((string)($sys['sweep_path_' . $slug] ?? "m/44'/60'/0'/0"));
    $nextIndexMap[$slug] = max(0, (int)($sys['sweep_next_index_' . $slug] ?? 0));
}

$allPoolRows = $db->fetchAll("SELECT w.chain_slug, COUNT(*) AS total_count, SUM(CASE WHEN a.id IS NULL THEN 1 ELSE 0 END) AS available_count FROM admin_derived_wallets w LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id WHERE w.status = 1 GROUP BY w.chain_slug");
$poolSummary = [];
foreach ($allPoolRows as $r) {
    $poolSummary[(string)$r['chain_slug']] = ['total' => (int)($r['total_count'] ?? 0), 'available' => (int)($r['available_count'] ?? 0)];
}

$recordsTab = strtolower(trim((string)($_GET['records_tab'] ?? 'batch')));
if (!in_array($recordsTab, ['batch', 'total', 'failed', 'unsettled'], true)) {
    $recordsTab = 'batch';
}

$pBatch = max(1, (int)($_GET['p_batch'] ?? 1));
$pTotal = max(1, (int)($_GET['p_total'] ?? 1));
$pFailed = max(1, (int)($_GET['p_failed'] ?? 1));
$pUnsettled = max(1, (int)($_GET['p_unsettled'] ?? 1));
$pSide = max(1, (int)($_GET['p_side'] ?? 1));
$perPageBatch = 20;
$perPageTotal = 20;
$perPageFailed = 20;
$perPageUnsettled = 20;
$perPageSide = 10;

$paidUnsettledWallets = [];
$unsettledWithBalance = [];
$unsettledTotal = 0;
$unsettledTotalPages = 1;
$sideTotalPages = 1;
if (!empty($selectedChain)) {
    $unsettledTotal = (int)($db->fetch(
        "SELECT COUNT(*) AS c
         FROM admin_derived_wallets w
         LEFT JOIN (
             SELECT a.wallet_id, SUM(COALESCE(o.amount, 0)) AS paid_amount_display
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
         WHERE w.chain_slug = ? AND w.status = 1
           AND EXISTS (SELECT 1 FROM admin_fee_address_allocations a2 WHERE a2.wallet_id = w.id AND a2.allocated_to_user_id = ?)
           AND (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) > 0",
        [$admin_user_id, $admin_user_id, $selectedChain, $admin_user_id]
    )['c'] ?? 0);
    $unsettledTotalPages = max(1, (int)ceil($unsettledTotal / $perPageUnsettled));
    $sideTotalPages = max(1, (int)ceil($unsettledTotal / $perPageSide));
    $pUnsettled = min($pUnsettled, $unsettledTotalPages);
    $pSide = min($pSide, $sideTotalPages);
    $offUnsettled = ($pUnsettled - 1) * $perPageUnsettled;
    $offSide = ($pSide - 1) * $perPageSide;

    $baseUnsettledSql = "SELECT w.*,
                COALESCE(p.paid_amount_display, 0) AS paid_amount_display,
                COALESCE(c.collected_amount_display, 0) AS collected_amount_display,
                (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) AS effective_balance_display,
                lp.latest_paid_order_no,
                lp.latest_paid_at,
                cur.currencies
         FROM admin_derived_wallets w
         LEFT JOIN (
            SELECT a.wallet_id, SUM(COALESCE(o.amount, 0)) AS paid_amount_display
            FROM admin_fee_address_allocations a
            INNER JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid' AND o.user_id = ?
            WHERE a.allocated_to_user_id = ?
            GROUP BY a.wallet_id
         ) p ON p.wallet_id = w.id
         LEFT JOIN (
            SELECT t.wallet_id,
                   SUBSTRING_INDEX(GROUP_CONCAT(t.order_no ORDER BY t.updated_at DESC SEPARATOR ','), ',', 1) AS latest_paid_order_no,
                   MAX(t.updated_at) AS latest_paid_at
            FROM (
                SELECT a.wallet_id, o.order_no, o.updated_at
                FROM admin_fee_address_allocations a
                INNER JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid' AND o.user_id = ?
            ) t
            GROUP BY t.wallet_id
         ) lp ON lp.wallet_id = w.id
         LEFT JOIN (
            SELECT wallet_id, SUM(COALESCE(amount_display, 0)) AS collected_amount_display
            FROM admin_collection_items
            WHERE status = 'broadcasted'
            GROUP BY wallet_id
         ) c ON c.wallet_id = w.id
         LEFT JOIN (
            SELECT a3.wallet_id,
                   GROUP_CONCAT(DISTINCT UPPER(COALESCE(o3.currency,'USDT')) ORDER BY o3.currency SEPARATOR ',') AS currencies
            FROM admin_fee_address_allocations a3
            INNER JOIN orders o3 ON o3.order_no = a3.order_no AND o3.status = 'paid'
            WHERE a3.allocated_to_user_id = ?
            GROUP BY a3.wallet_id
         ) cur ON cur.wallet_id = w.id
         WHERE w.chain_slug = ? AND w.status = 1
           AND EXISTS (SELECT 1 FROM admin_fee_address_allocations a2 WHERE a2.wallet_id = w.id AND a2.allocated_to_user_id = ?)
           AND (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) > 0
         ORDER BY (COALESCE(p.paid_amount_display, 0) - COALESCE(c.collected_amount_display, 0)) DESC, w.id DESC";

    $unsettledWithBalance = $db->fetchAll($baseUnsettledSql . " LIMIT $perPageUnsettled OFFSET $offUnsettled", [$admin_user_id, $admin_user_id, $admin_user_id, $admin_user_id, $selectedChain, $admin_user_id]);
    $paidUnsettledWallets = $db->fetchAll($baseUnsettledSql . " LIMIT $perPageSide OFFSET $offSide", [$admin_user_id, $admin_user_id, $admin_user_id, $admin_user_id, $selectedChain, $admin_user_id]);
}

// Per-currency counts — only wallets with effective pending balance > 0
$unsettledCurrencyCounts = ['USDT' => 0, 'USDC' => 0];
$autoToken = 'USDT';
if (!empty($selectedChain)) {
    try {
        foreach (['USDT', 'USDC'] as $ccySym) {
            $ccyCount = $db->fetch(
                "SELECT COUNT(*) AS c
                 FROM admin_derived_wallets w
                 LEFT JOIN (
                     SELECT a.wallet_id, SUM(COALESCE(o.amount,0)) AS paid_amount_display
                     FROM admin_fee_address_allocations a
                     INNER JOIN orders o ON o.order_no = a.order_no AND o.status = 'paid' AND o.user_id = ?
                     WHERE a.allocated_to_user_id = ?
                     GROUP BY a.wallet_id
                 ) p ON p.wallet_id = w.id
                 LEFT JOIN (
                     SELECT wallet_id, SUM(COALESCE(amount_display,0)) AS collected_amount_display
                     FROM admin_collection_items WHERE status = 'broadcasted' GROUP BY wallet_id
                 ) c ON c.wallet_id = w.id
                 WHERE w.chain_slug = ? AND w.status = 1
                   AND EXISTS (SELECT 1 FROM admin_fee_address_allocations a2 WHERE a2.wallet_id = w.id AND a2.allocated_to_user_id = ?)
                   AND (COALESCE(p.paid_amount_display,0) - COALESCE(c.collected_amount_display,0)) > 0
                   AND EXISTS (
                       SELECT 1 FROM admin_fee_address_allocations af
                       INNER JOIN orders of2 ON of2.order_no = af.order_no AND of2.status = 'paid'
                         AND UPPER(COALESCE(of2.currency,'USDT')) = ?
                       WHERE af.wallet_id = w.id AND af.allocated_to_user_id = ?
                   )",
                [$admin_user_id, $admin_user_id, $selectedChain, $admin_user_id, $ccySym, $admin_user_id]
            );
            $unsettledCurrencyCounts[$ccySym] = (int)($ccyCount['c'] ?? 0);
        }
        if ($unsettledCurrencyCounts['USDC'] > $unsettledCurrencyCounts['USDT']) {
            $autoToken = 'USDC';
        }
    } catch (Throwable $ignore) {}
}

$latestBatch = null;
$latestItems = [];
$allBatches = [];
$allCollectedRecords = [];
$failedCollectedRecords = [];
$batchTotal = 0;
$batchTotalPages = 1;
$totalCollected = 0;
$totalCollectedPages = 1;
$failedTotal = 0;
$failedTotalPages = 1;
$batchStats = ['total_batches' => 0, 'total_items' => 0, 'total_amount' => 0, 'broadcasted_items' => 0, 'today_amount' => 0];
if (!empty($selectedChain)) {
    $batchStatsRow = $db->fetch("SELECT COUNT(*) AS total_batches, COALESCE(SUM(total_items), 0) AS total_items, COALESCE(SUM(total_amount_display), 0) AS total_amount FROM admin_collection_batches WHERE chain_slug = ?", [$selectedChain]);
    $broadcastedRow = $db->fetch("SELECT COUNT(*) AS c FROM admin_collection_items i INNER JOIN admin_collection_batches b ON b.id = i.batch_id WHERE b.chain_slug = ? AND i.status = 'broadcasted'", [$selectedChain]);
    $batchStats = [
        'total_batches' => (int)($batchStatsRow['total_batches'] ?? 0),
        'total_items' => (int)($batchStatsRow['total_items'] ?? 0),
        'total_amount' => (float)($batchStatsRow['total_amount'] ?? 0),
        'broadcasted_items' => (int)($broadcastedRow['c'] ?? 0),
        'today_amount' => 0,
    ];
    $todayAmountRow = $db->fetch(
        "SELECT COALESCE(SUM(i.amount_display), 0) AS amt
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id
         WHERE b.chain_slug = ? AND i.status = 'broadcasted' AND DATE(i.updated_at) = CURDATE()",
        [$selectedChain]
    );
    $batchStats['today_amount'] = (float)($todayAmountRow['amt'] ?? 0);
    $latestBatch = $db->fetch("SELECT * FROM admin_collection_batches WHERE chain_slug = ? ORDER BY id DESC LIMIT 1", [$selectedChain]);
    if ($latestBatch) {
        $latestItems = $db->fetchAll("SELECT i.*, w.derivation_path FROM admin_collection_items i LEFT JOIN admin_derived_wallets w ON w.id = i.wallet_id WHERE i.batch_id = ? ORDER BY i.id ASC LIMIT 200", [$latestBatch['id']]);
    }
    $batchTotal = (int)($db->fetch("SELECT COUNT(*) AS c FROM admin_collection_batches WHERE chain_slug = ?", [$selectedChain])['c'] ?? 0);
    $batchTotalPages = max(1, (int)ceil($batchTotal / $perPageBatch));
    $pBatch = min($pBatch, $batchTotalPages);
    $offBatch = ($pBatch - 1) * $perPageBatch;
    $allBatches = $db->fetchAll(
        "SELECT b.id, b.chain_slug, b.total_items, b.total_amount_display, b.status, b.created_at, b.updated_at, COALESCE(SUM(CASE WHEN i.status = 'broadcasted' THEN 1 ELSE 0 END),0) AS done_items
         FROM admin_collection_batches b
         LEFT JOIN admin_collection_items i ON i.batch_id = b.id
         WHERE b.chain_slug = ?
         GROUP BY b.id
         ORDER BY b.id DESC
         LIMIT $perPageBatch OFFSET $offBatch",
        [$selectedChain]
    );

    $totalCollected = (int)($db->fetch(
        "SELECT COUNT(*) AS c
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id
         WHERE b.chain_slug = ? AND i.status = 'broadcasted'",
        [$selectedChain]
    )['c'] ?? 0);
    $totalCollectedPages = max(1, (int)ceil($totalCollected / $perPageTotal));
    $pTotal = min($pTotal, $totalCollectedPages);
    $offTotal = ($pTotal - 1) * $perPageTotal;
    $allCollectedRecords = $db->fetchAll(
        "SELECT i.id, i.from_address, i.to_address, i.amount_display, i.updated_at,
                i.tx_hash, i.tx_error, COALESCE(b.token_symbol, 'USDT') AS token_symbol, b.chain_id
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id
         WHERE b.chain_slug = ? AND i.status = 'broadcasted'
         ORDER BY i.updated_at DESC, i.id DESC
         LIMIT $perPageTotal OFFSET $offTotal",
        [$selectedChain]
    );

    $failedTotal = (int)($db->fetch(
        "SELECT COUNT(*) AS c
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id
         WHERE b.chain_slug = ? AND i.status = 'failed'",
        [$selectedChain]
    )['c'] ?? 0);
    $failedTotalPages = max(1, (int)ceil($failedTotal / $perPageFailed));
    $pFailed = min($pFailed, $failedTotalPages);
    $offFailed = ($pFailed - 1) * $perPageFailed;
    $failedCollectedRecords = $db->fetchAll(
        "SELECT i.id, i.batch_id, i.from_address, i.to_address, i.amount_display, i.updated_at,
                i.tx_error, i.tx_hash, COALESCE(b.token_symbol, 'USDT') AS token_symbol, b.chain_id
         FROM admin_collection_items i
         INNER JOIN admin_collection_batches b ON b.id = i.batch_id
         WHERE b.chain_slug = ? AND i.status = 'failed'
         ORDER BY i.updated_at DESC, i.id DESC
         LIMIT $perPageFailed OFFSET $offFailed",
        [$selectedChain]
    );
}

function adminDerivedPageUrl(array $overrides = []): string
{
    $params = array_merge($_GET, $overrides);
    return '?' . http_build_query($params);
}

$gasFunderAddress = trim((string)($sys['sweep_gas_funder_' . $selectedChain] ?? ''));
$gasProfile = trim((string)($sys['sweep_gas_profile_' . $selectedChain] ?? 'evm_standard'));
$savedGasPath = trim((string)($sys['sweep_gas_path_' . $selectedChain] ?? "m/44'/60'/0'/0/0"));
$savedGasAddress = trim((string)($sys['sweep_gas_address_' . $selectedChain] ?? ''));
$gasTopupWei = trim((string)($sys['sweep_gas_topup_wei_' . $selectedChain] ?? '300000000000000'));
if (!preg_match('/^[0-9]+$/', $gasTopupWei)) $gasTopupWei = '300000000000000';
$gasTopupCoinDisplay = rtrim(rtrim(number_format((float)format_by_decimals($gasTopupWei, 18), 6, '.', ''), '0'), '.');
$nativeCoinSymbol = strtoupper((string)($evmChains[$selectedChain]['symbol'] ?? 'COIN'));
$availablePoolCurrent = (int)(($poolSummary[$selectedChain]['available'] ?? 0));
$gasFunderNonceHex = null;
if ($gasFunderAddress !== '') {
    $apiKeyTmp = trim((string)($sys['eth_api_key'] ?? ''));
    if ($apiKeyTmp !== '') $gasFunderNonceHex = fetch_evm_tx_count((int)($evmChains[$selectedChain]['chain_id'] ?? 0), $gasFunderAddress, $apiKeyTmp);
}

if (!empty($selectedChain)) {
    upsert_setting($db, 'sweep_last_chain', (string)$selectedChain);
}

$active_menu = 'derived_wallets';
require_once 'includes/header.php';
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

    .flow-progress-track {
        background: linear-gradient(90deg, #e2e8f0 0%, #cbd5e1 100%);
    }
    .flow-progress-bar {
        background: linear-gradient(90deg, #2563eb 0%, #10b981 100%);
        box-shadow: 0 0 0 1px rgba(255,255,255,0.35) inset, 0 6px 16px rgba(37,99,235,0.28);
    }
    .stepper-item .stepper-circle {
        border: 2px solid #cbd5e1;
        color: #64748b;
        background: #fff;
        transition: all .22s ease;
    }
    .stepper-item .stepper-label {
        color: #64748b;
        transition: color .22s ease;
    }
    .stepper-item.active .stepper-circle {
        border-color: #2563eb;
        background: #2563eb;
        color: #fff;
        transform: translateY(-1px) scale(1.04);
        box-shadow: 0 10px 20px -12px rgba(37,99,235,.55);
    }
    .stepper-item.active .stepper-label { color: #1e3a8a; font-weight: 700; }
    .stepper-item.completed .stepper-circle {
        border-color: #10b981;
        background: #10b981;
        color: #fff;
    }
    .stepper-item.completed .stepper-label { color: #065f46; font-weight: 600; }

    .flow-log-line {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        font-size: 12px;
        line-height: 1.4;
        padding: 0;
        margin-bottom: 4px;
    }
    .flow-log-time { color: #94a3b8; flex-shrink: 0; }
    .flow-log-text { color: #e5e7eb; word-break: break-word; }
    .flow-log-line.flow-run .flow-log-text { color: #bfdbfe; }
    .flow-log-line.flow-ok .flow-log-text { color: #86efac; }
    .flow-log-line.flow-fail .flow-log-text { color: #fca5a5; }
    .flow-log-line.flow-info .flow-log-text { color: #cbd5e1; }

    .flow-final-banner {
        border-radius: 10px;
        padding: 10px 14px;
        margin-top: 8px;
        margin-bottom: 10px;
        font-size: 15px;
        font-weight: 700;
        text-align: center;
        border: 1px solid transparent;
    }
    .flow-final-banner.success {
        color: #065f46;
        background: #ecfdf5;
        border-color: #6ee7b7;
    }
    .flow-final-banner.fail {
        color: #991b1b;
        background: #fef2f2;
        border-color: #fca5a5;
    }
</style>

<!-- Batch Items Modal -->
<div class="modal fade" id="batchItemsModal" tabindex="-1" aria-labelledby="batchItemsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="batchItemsModalLabel">批次明细</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div id="batchItemsLoading" class="text-center py-4">
                    <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                    <span class="ms-2">加载中...</span>
                </div>
                <div id="batchItemsContent" class="d-none">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>来源地址</th>
                                <th>金额</th>
                                <th>状态</th>
                                <th>Tx Hash</th>
                                <th>错误</th>
                            </tr>
                        </thead>
                        <tbody id="batchItemsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="tw-font-sans tw-text-gray-800 tw-antialiased tw-min-h-screen tw-bg-gray-50 dark:tw-bg-gray-900 dark:tw-text-gray-100 tw-px-0 tw-pt-1 tw-pb-5 md:tw-pt-2 md:tw-pb-6">
    
    <!-- Top Stats / Header -->
    <div class="tw-flex tw-flex-col md:tw-flex-row tw-justify-between tw-items-center tw-mb-5 tw-gap-4">
        <div>
            <h1 class="tw-text-2xl tw-font-bold tw-tracking-tight tw-text-gray-900 dark:tw-text-white">派生钱包管理</h1>
            <p class="tw-text-sm tw-text-gray-500 dark:tw-text-gray-400">Command Center · <?php echo htmlspecialchars($evmChains[$selectedChain]['name']); ?></p>
        </div>
        <div class="tw-flex tw-items-center tw-gap-3">
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
            <button onclick="location.reload()" class="tw-p-2 tw-bg-white dark:tw-bg-gray-800 tw-border tw-border-gray-300 dark:tw-border-gray-700 tw-rounded-lg tw-shadow-sm hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700 tw-transition-colors">
                <svg class="tw-w-5 tw-h-5 tw-text-gray-600 dark:tw-text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            </button>
        </div>
    </div>

    <!-- Dashboard Grid -->
    <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-4 tw-gap-4 tw-mb-6">
        <!-- Stat Card 1 -->
        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-p-5 tw-shadow-sm tw-border tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
            <div>
                <p class="tw-text-sm tw-font-medium tw-text-gray-500 dark:tw-text-gray-400">总归集金额统计</p>
                <p class="tw-text-2xl tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mt-1">
                    <span class="tw-text-success"><?php echo number_format((float)$batchStats['total_amount'], 2); ?></span>
                    <span class="tw-text-gray-400 tw-text-lg">USDT</span>
                </p>
            </div>
            <div class="tw-p-3 tw-bg-blue-50 dark:tw-bg-blue-900/20 tw-rounded-lg">
                <svg class="tw-w-6 tw-h-6 tw-text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
        </div>
        <!-- Stat Card 2 -->
        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-p-5 tw-shadow-sm tw-border tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
            <div>
                <p class="tw-text-sm tw-font-medium tw-text-gray-500 dark:tw-text-gray-400">待归集地址数（已收款）</p>
                <p class="tw-text-2xl tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mt-1"><?php echo (int)$unsettledTotal; ?></p>
            </div>
            <div class="tw-p-3 tw-bg-yellow-50 dark:tw-bg-yellow-900/20 tw-rounded-lg">
                <svg class="tw-w-6 tw-h-6 tw-text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
        </div>
        <!-- Stat Card 3 -->
        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-p-5 tw-shadow-sm tw-border tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
            <div>
                <p class="tw-text-sm tw-font-medium tw-text-gray-500 dark:tw-text-gray-400">今日已归集</p>
                <p class="tw-text-2xl tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mt-1"><?php echo number_format($batchStats['today_amount'], 2); ?> <span class="tw-text-sm tw-font-normal tw-text-gray-500">USDT</span></p>
            </div>
            <div class="tw-p-3 tw-bg-green-50 dark:tw-bg-green-900/20 tw-rounded-lg">
                <svg class="tw-w-6 tw-h-6 tw-text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </div>
        </div>
        <!-- Stat Card 4 -->
        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-p-5 tw-shadow-sm tw-border tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
            <div>
                <p class="tw-text-sm tw-font-medium tw-text-gray-500 dark:tw-text-gray-400">配置状态</p>
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
                        批量操作台
                    </h2>
                    <div class="tw-flex tw-gap-2">
                        <button onclick="copyFlowResult()" class="tw-text-xs tw-text-blue-600 hover:tw-text-blue-700 tw-font-medium">复制结果</button>
                        <button onclick="clearFlowStatus()" class="tw-text-xs tw-text-gray-500 hover:tw-text-gray-700 dark:tw-text-gray-400">清除日志</button>
                    </div>
                </div>
                
                <div class="tw-p-6">
                    <!-- Progress Stepper -->
                    <div class="tw-mb-7">
                        <div class="tw-flex tw-items-center tw-justify-between tw-mb-2">
                            <div id="flowProgressText" class="tw-text-xs tw-font-semibold tw-text-gray-600 dark:tw-text-gray-300">等待执行</div>
                            <div id="flowProgressPercent" class="tw-text-xs tw-font-bold tw-text-primary">0%</div>
                        </div>
                        <div class="flow-progress-track tw-relative tw-h-2.5 tw-rounded-full tw-overflow-hidden">
                            <div id="flowProgressBar" class="flow-progress-bar tw-absolute tw-left-0 tw-top-0 tw-h-full tw-transition-all tw-duration-500" style="width: 0%"></div>
                        </div>
                        <div class="tw-relative tw-mt-4 tw-grid tw-grid-cols-4 tw-gap-2">
                            <div class="stepper-item tw-flex tw-flex-col tw-items-center tw-gap-2 active">
                                <div class="stepper-circle tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-xs tw-font-bold tw-z-10">1</div>
                                <span class="stepper-label tw-text-xs tw-font-medium">生成批次</span>
                            </div>
                            <div class="stepper-item tw-flex tw-flex-col tw-items-center tw-gap-2">
                                <div class="stepper-circle tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-xs tw-font-bold tw-z-10">2</div>
                                <span class="stepper-label tw-text-xs tw-font-medium">补 Gas</span>
                            </div>
                            <div class="stepper-item tw-flex tw-flex-col tw-items-center tw-gap-2">
                                <div class="stepper-circle tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-xs tw-font-bold tw-z-10">3</div>
                                <span class="stepper-label tw-text-xs tw-font-medium">链上确认</span>
                            </div>
                            <div class="stepper-item tw-flex tw-flex-col tw-items-center tw-gap-2">
                                <div class="stepper-circle tw-w-8 tw-h-8 tw-rounded-full tw-flex tw-items-center tw-justify-center tw-text-xs tw-font-bold tw-z-10">4</div>
                                <span class="stepper-label tw-text-xs tw-font-medium">归集广播</span>
                            </div>
                        </div>
                    </div>

                    <div id="flowFinalBanner" class="flow-final-banner tw-hidden"></div>

                    <!-- Console Output -->
                    <div id="flowStatusBoard" class="tw-bg-slate-950 tw-rounded-lg tw-p-4 tw-h-64 tw-overflow-y-auto tw-scrollbar-thin tw-font-mono tw-text-xs tw-mb-6 tw-border tw-border-slate-700">
                        <div class="flow-log-line flow-info"><span class="flow-log-time">[--:--:--]</span><span class="flow-log-text">System ready. Waiting for command...</span></div>
                    </div>

                    <!-- Token Toggle (prominent) -->
                    <div class="tw-mb-5 tw-flex tw-items-center tw-gap-3 tw-flex-wrap">
                        <span class="tw-text-sm tw-font-semibold tw-text-gray-700 dark:tw-text-gray-200">归集币种</span>
                        <div class="tw-inline-flex tw-rounded-lg tw-border tw-border-gray-200 dark:tw-border-gray-600 tw-overflow-hidden tw-shadow-sm" id="tokenToggleGroup">
                            <button type="button" id="tokenToggleUSDT" onclick="setFlowToken('USDT')"
                                class="tw-px-5 tw-py-2 tw-text-sm tw-font-bold tw-transition-colors tw-bg-green-500 tw-text-white">
                                USDT
                                <?php if ($unsettledCurrencyCounts['USDT'] > 0): ?>
                                    <span class="tw-ml-1 tw-text-xs tw-font-normal tw-opacity-80">(<?php echo (int)$unsettledCurrencyCounts['USDT']; ?>)</span>
                                <?php endif; ?>
                            </button>
                            <button type="button" id="tokenToggleUSDC" onclick="setFlowToken('USDC')"
                                class="tw-px-5 tw-py-2 tw-text-sm tw-font-bold tw-border-l tw-border-gray-200 dark:tw-border-gray-600 tw-transition-colors tw-bg-white dark:tw-bg-gray-700 tw-text-gray-500 dark:tw-text-gray-400 hover:tw-bg-gray-50">
                                USDC
                                <?php if ($unsettledCurrencyCounts['USDC'] > 0): ?>
                                    <span class="tw-ml-1 tw-text-xs tw-font-normal tw-opacity-80">(<?php echo (int)$unsettledCurrencyCounts['USDC']; ?>)</span>
                                <?php endif; ?>
                            </button>
                        </div>
                        <span class="tw-text-xs tw-text-gray-400">已自动选中待归集数量较多的币种</span>
                    </div>
                    <input type="hidden" id="flowBatchToken" value="<?php echo htmlspecialchars($autoToken); ?>">

                    <!-- Actions -->
                    <div class="tw-flex tw-flex-wrap tw-gap-3">
                        <button id="runFullFlowBtn" onclick="runFullFlow()" class="tw-flex-1 tw-bg-success hover:tw-bg-green-600 tw-text-white tw-font-medium tw-py-2.5 tw-px-4 tw-rounded-lg tw-shadow-sm tw-transition-colors tw-flex tw-items-center tw-justify-center tw-gap-2">
                            <svg class="tw-w-5 tw-h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            一键发起全流程
                        </button>
                        <button onclick="createBatchOnly()" class="tw-flex-1 tw-bg-white dark:tw-bg-gray-700 tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-text-gray-700 dark:tw-text-gray-200 tw-font-medium tw-py-2.5 tw-px-4 tw-rounded-lg hover:tw-bg-gray-50 dark:hover:tw-bg-gray-600 tw-transition-colors">
                            仅生成批次
                        </button>
                    </div>

                    <!-- Advanced Settings Button -->
                    <div class="tw-mt-6">
                        <button type="button" onclick="document.getElementById('advancedSettingsModal').classList.remove('tw-hidden')"
                            class="tw-inline-flex tw-items-center tw-gap-2 tw-px-4 tw-py-2 tw-rounded-lg tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-bg-gray-50 dark:tw-bg-gray-900 hover:tw-bg-gray-100 dark:hover:tw-bg-gray-800 tw-text-sm tw-font-medium tw-text-gray-700 dark:tw-text-gray-300 tw-transition-colors">
                            <svg fill="none" height="16" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            高级参数配置 (阈值 / Gas / 密钥)
                        </button>
                    </div>

                    <!-- Advanced Settings Modal -->
                    <div id="advancedSettingsModal" class="tw-hidden tw-fixed tw-inset-0 tw-z-50 tw-flex tw-items-center tw-justify-center" style="background:rgba(0,0,0,0.5);">
                        <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-2xl tw-w-full tw-max-w-3xl tw-max-h-screen tw-overflow-y-auto tw-mx-4 tw-my-6">
                            <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-4 tw-border-b tw-border-gray-200 dark:tw-border-gray-700 tw-sticky tw-top-0 tw-bg-white dark:tw-bg-gray-800 tw-z-10">
                                <h3 class="tw-font-semibold tw-text-base tw-text-gray-800 dark:tw-text-gray-100">高级参数配置 (阈值 / Gas / 密钥)</h3>
                                <button type="button" onclick="document.getElementById('advancedSettingsModal').classList.add('tw-hidden')"
                                    class="tw-text-gray-400 hover:tw-text-gray-600 dark:hover:tw-text-gray-300 tw-transition-colors">
                                    <svg fill="none" height="20" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>
                            <div class="tw-text-neutral-600 tw-px-6 tw-py-5">
                                <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-6">
                                    <!-- Flow Params -->
                                    <div>
                                        <h6 class="tw-font-bold tw-text-xs tw-uppercase tw-text-gray-500 tw-mb-3">流程参数</h6>
                                        <div class="tw-space-y-3">
                                            <div>
                                                <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">归集代币</label>
                                                <p class="tw-text-xs tw-text-gray-400 tw-mt-1">已在上方操作台选择，点击 USDT / USDC 切换。</p>
                                            </div>
                                            <div>
                                                <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">最小归集阈值</label>
                                                <input id="flowMinAmount" type="number" step="0.1" value="0.1" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-800 tw-text-sm">
                                            </div>
                                            <div>
                                                <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">链上确认等待 (秒)</label>
                                                <input id="flowWaitSeconds" type="number" value="30" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-800 tw-text-sm">
                                            </div>
                                            <div>
                                                <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">快速转账地址 (单地址)</label>
                                                <input id="quickTransferAddress" type="text" placeholder="0x...（可选）" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-800 tw-text-sm tw-font-mono">
                                                <p class="tw-text-[11px] tw-text-gray-400 tw-mt-1">填写后，本次流程只处理该地址，自动归集到主钱包。</p>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Signer Params -->
                                    <div>
                                        <h6 class="tw-font-bold tw-text-xs tw-uppercase tw-text-gray-500 tw-mb-3">签名配置</h6>
                                        <div class="tw-space-y-3">
                                             <div>
                                                <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">归集私钥/助记词</label>
                                                <div class="tw-flex tw-gap-4 tw-mb-2">
                                                    <label class="tw-inline-flex tw-items-center">
                                                        <input type="radio" name="sweepSignerMode" value="mnemonic" checked onchange="setSweepSignerModeUI()" class="tw-form-radio tw-text-primary">
                                                        <span class="tw-ml-2 tw-text-xs">助记词</span>
                                                    </label>
                                                    <label class="tw-inline-flex tw-items-center">
                                                        <input type="radio" name="sweepSignerMode" value="private_key" onchange="setSweepSignerModeUI()" class="tw-form-radio tw-text-primary">
                                                        <span class="tw-ml-2 tw-text-xs">私钥</span>
                                                    </label>
                                                </div>
                                                <div id="sweepMnemonicWrap" class="tw-hidden"><textarea id="batchMnemonic" rows="2" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs" placeholder="输入助记词"></textarea></div>
                                                <div id="sweepPrivateKeyWrap" class="tw-hidden"><input id="batchPrivateKey" type="password" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs" placeholder="0x..."></div>
                                                <div id="sweepPassphraseWrap" class="tw-hidden tw-mt-2"><input id="batchPassphrase" type="password" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs" placeholder="Passphrase (Optional)"></div>
                                             </div>
                                        </div>
                                    </div>
                                    <!-- Gas Params -->
                                    <div class="md:tw-col-span-2 tw-border-t tw-border-gray-200 dark:tw-border-gray-700 tw-pt-3">
                                        <h6 class="tw-font-bold tw-text-xs tw-uppercase tw-text-gray-500 tw-mb-3">Gas 补给配置</h6>
                                        <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-4">
                                            <div>
                                                 <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">Gas 来源</label>
                                                 <select id="gasWalletSource" onchange="setGasWalletModeUI()" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs tw-mb-2">
                                                     <option value="local">本站工具派生 (默认)</option>
                                                     <option value="external">外部钱包</option>
                                                 </select>
                                                 <div class="tw-flex tw-gap-4 tw-mb-2">
                                                    <label class="tw-inline-flex tw-items-center">
                                                        <input type="radio" name="gasSignerMode" value="mnemonic" checked onchange="setGasSignerModeUI()" class="tw-form-radio tw-text-primary">
                                                        <span class="tw-ml-2 tw-text-xs">助记词</span>
                                                    </label>
                                                    <label class="tw-inline-flex tw-items-center">
                                                        <input type="radio" name="gasSignerMode" value="private_key" onchange="setGasSignerModeUI()" class="tw-form-radio tw-text-primary">
                                                        <span class="tw-ml-2 tw-text-xs">私钥</span>
                                                    </label>
                                                </div>
                                                <div id="gasMnemonicWrap" class="tw-hidden"><textarea id="gasMnemonic" rows="2" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs" placeholder="Gas 助记词"></textarea></div>
                                                <div id="gasPrivateKeyWrap" class="tw-hidden"><input id="gasFunderPrivateKey" type="password" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs" placeholder="0x..."></div>
                                                <div id="gasPassphraseWrap" class="tw-hidden tw-mt-2"><input id="gasPassphrase" type="password" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs" placeholder="Passphrase"></div>
                                            </div>
                                            <div class="tw-space-y-2">
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">安全系数</label>
                                                    <input id="dynSafetyFactor" value="1.4" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">最小补给 (<?php echo htmlspecialchars($nativeCoinSymbol); ?>)</label>
                                                    <input id="dynMinTopupCoin" value="0.00025" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">最大补给 (<?php echo htmlspecialchars($nativeCoinSymbol); ?>)</label>
                                                    <input id="dynMaxTopupCoin" value="0.0006" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">失败二次补给 (<?php echo htmlspecialchars($nativeCoinSymbol); ?>)</label>
                                                    <input id="dynRetryExtraCoin" value="0.0002" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">归集默认 GasLimit</label>
                                                    <input id="dynDefaultSweepGasLimit" value="100000" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">补Gas并发 / 归集并发</label>
                                                    <input id="dynConcurrencyPair" value="3/2" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">轮询阈值(地址数)</label>
                                                    <input id="dynPollAddressThreshold" value="20" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                    <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">最小 GasPrice (Gwei)</label>
                                                    <input id="dynMinGasPriceGwei" value="1.2" class="tw-w-full tw-rounded-md tw-border-gray-300 tw-text-xs">
                                                </div>
                                                <div>
                                                     <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500">Gas 地址自动识别</label>
                                                     <div class="tw-flex tw-gap-2">
                                                         <input id="gasFunderExpectedAddress" placeholder="0x... (可选)" class="tw-flex-1 tw-rounded-md tw-border-gray-300 tw-text-xs" value="<?php echo htmlspecialchars((string)($savedGasAddress ?: $gasFunderAddress ?: '')); ?>">
                                                         <button onclick="testGasAddressMatch()" class="tw-px-3 tw-bg-gray-100 tw-border tw-border-gray-300 tw-rounded-md tw-text-xs hover:tw-bg-gray-200">测试</button>
                                                     </div>
                                                     <input id="gasFunderPath" type="hidden" value="m/44'/60'/0'/0/0">
                                                     <input id="gasPathScanDepth" type="hidden" value="1200">
                                                     <input id="gasPathProfile" type="hidden" value="auto">
                                                     <input id="gasStartNonce" type="hidden" value="0x0">
                                                     <input id="batchSweepFeeCoin" type="hidden" value="0.00009">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tw-flex tw-justify-end tw-px-6 tw-py-4 tw-border-t tw-border-gray-200 dark:tw-border-gray-700">
                                <button type="button" onclick="document.getElementById('advancedSettingsModal').classList.add('tw-hidden')"
                                    class="tw-px-5 tw-py-2 tw-rounded-lg tw-bg-blue-600 hover:tw-bg-blue-700 tw-text-white tw-text-sm tw-font-semibold tw-transition-colors">
                                    确认
                                </button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-overflow-hidden">
                <div class="tw-px-6 tw-py-4 tw-border-b tw-border-gray-200 dark:tw-border-gray-700 tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
                    <h2 class="tw-text-lg tw-font-bold tw-text-gray-900 dark:tw-text-white">归集记录</h2>
                    <div class="tw-inline-flex tw-rounded-lg tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-overflow-hidden">
                        <button id="recordsTabBatchBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-primary tw-text-white" onclick="switchRecordsTab('batch')">最近归集批次</button>
                        <button id="recordsTabTotalBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-white dark:tw-bg-gray-800 tw-text-gray-700 dark:tw-text-gray-200" onclick="switchRecordsTab('total')">总归集记录</button>
                        <button id="recordsTabFailedBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-white dark:tw-bg-gray-800 tw-text-gray-700 dark:tw-text-gray-200" onclick="switchRecordsTab('failed')">失败记录</button>
                        <button id="recordsTabUnsettledBtn" class="tw-px-3 tw-py-1.5 tw-text-sm tw-bg-white dark:tw-bg-gray-800 tw-text-gray-700 dark:tw-text-gray-200" onclick="switchRecordsTab('unsettled')">未归集有余额地址</button>
                    </div>
                </div>

                <div id="recordsTabBatch">
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">ID</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">时间</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">数量</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">金额 (USDT)</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">状态</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">操作</th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700">
                                <?php if (empty($allBatches)): ?>
                                    <tr><td colspan="6" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400">暂无历史批次</td></tr>
                                <?php else: ?>
                                    <?php foreach ($allBatches as $b): ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors">
                                        <td class="tw-px-6 tw-py-4">#<?php echo (int)$b['id']; ?></td>
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500"><?php echo htmlspecialchars((string)($b['created_at'] ?? '-')); ?></td>
                                        <td class="tw-px-6 tw-py-4"><?php echo (int)$b['done_items']; ?>/<?php echo (int)$b['total_items']; ?></td>
                                        <td class="tw-px-6 tw-py-4 tw-font-medium"><?php echo number_format((float)$b['total_amount_display'], 6); ?></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <?php if ((string)$b['status'] === 'completed'): ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-green-100 tw-text-green-800 tw-text-xs">已完成</span>
                                            <?php elseif ((string)$b['status'] === 'partial'): ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-orange-100 tw-text-orange-800 tw-text-xs">部分完成</span>
                                            <?php else: ?>
                                                <span class="tw-px-2 tw-py-1 tw-rounded-full tw-bg-yellow-100 tw-text-yellow-800 tw-text-xs"><?php echo htmlspecialchars((string)$b['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tw-px-6 tw-py-4 tw-flex tw-gap-2">
                                            <button onclick="viewBatchItems(<?php echo (int)$b['id']; ?>)" class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-bg-blue-50 tw-text-blue-700 hover:tw-bg-blue-100 tw-border tw-border-blue-200">查看明细</button>
                                            <?php if ((string)$b['status'] !== 'completed'): ?>
                                            <button onclick="rollbackBatch(<?php echo (int)$b['id']; ?>)" class="tw-px-2 tw-py-1 tw-text-xs tw-rounded tw-bg-red-50 tw-text-red-700 hover:tw-bg-red-100 tw-border tw-border-red-200">回滚批次</button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <span class="tw-text-xs tw-text-gray-500">共 <?php echo (int)$batchTotal; ?> 条</span>
                        <div class="tw-inline-flex tw-items-center tw-gap-2">
                            <a class="tw-px-3 tw-py-1.5 tw-rounded tw-border tw-text-xs <?php echo $pBatch <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['records_tab' => 'batch', 'p_batch' => max(1, $pBatch - 1)])); ?>">上一页</a>
                            <span class="tw-text-xs tw-text-gray-500"><?php echo $pBatch; ?> / <?php echo $batchTotalPages; ?></span>
                            <a class="tw-px-3 tw-py-1.5 tw-rounded tw-border tw-text-xs <?php echo $pBatch >= $batchTotalPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['records_tab' => 'batch', 'p_batch' => min($batchTotalPages, $pBatch + 1)])); ?>">下一页</a>
                        </div>
                    </div>
                </div>

                <div id="recordsTabTotal" class="tw-hidden">
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">时间</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">来源地址</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">归集到</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">币种</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">金额</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">交易哈希</th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700">
                                <?php if (empty($allCollectedRecords)): ?>
                                    <tr><td colspan="6" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400">暂无归集记录</td></tr>
                                <?php else: ?>
                                    <?php foreach ($allCollectedRecords as $r):
                                        $rToken = strtoupper((string)($r['token_symbol'] ?? 'USDT'));
                                        $rHash  = (string)($r['tx_hash'] ?? '');
                                        $rChainId = (int)($r['chain_id'] ?? 0);
                                        $explorerBase = [56=>'https://bscscan.com/tx/',1=>'https://etherscan.io/tx/',137=>'https://polygonscan.com/tx/',42161=>'https://arbiscan.io/tx/',10=>'https://optimistic.etherscan.io/tx/',8453=>'https://basescan.org/tx/',43114=>'https://snowtrace.io/tx/'];
                                        $explorerUrl = ($rHash !== '' && isset($explorerBase[$rChainId])) ? $explorerBase[$rChainId] . $rHash : '';
                                    ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors">
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500"><?php echo htmlspecialchars((string)($r['updated_at'] ?? '-')); ?></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$r['from_address'], 0, 8) . '...' . substr((string)$r['from_address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$r['to_address'], 0, 8) . '...' . substr((string)$r['to_address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4"><span class="tw-px-1.5 tw-py-0.5 tw-rounded tw-text-xs tw-font-bold <?php echo $rToken === 'USDC' ? 'tw-bg-blue-50 tw-text-blue-600' : 'tw-bg-green-50 tw-text-green-600'; ?>"><?php echo htmlspecialchars($rToken); ?></span></td>
                                        <td class="tw-px-6 tw-py-4 tw-font-medium"><?php echo number_format((float)$r['amount_display'], 6); ?></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <?php if ($rHash !== ''): ?>
                                                <?php if ($explorerUrl !== ''): ?>
                                                    <a href="<?php echo htmlspecialchars($explorerUrl); ?>" target="_blank" class="tw-font-mono tw-text-xs tw-text-primary hover:tw-underline" title="<?php echo htmlspecialchars($rHash); ?>"><?php echo htmlspecialchars(substr($rHash,0,8).'...'.substr($rHash,-6)); ?></a>
                                                <?php else: ?>
                                                    <code class="tw-text-xs tw-text-gray-500" title="<?php echo htmlspecialchars($rHash); ?>"><?php echo htmlspecialchars(substr($rHash,0,8).'...'.substr($rHash,-6)); ?></code>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="tw-text-gray-300">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <span class="tw-text-xs tw-text-gray-500">共 <?php echo (int)$totalCollected; ?> 条</span>
                        <div class="tw-inline-flex tw-items-center tw-gap-2">
                            <a class="tw-px-3 tw-py-1.5 tw-rounded tw-border tw-text-xs <?php echo $pTotal <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['records_tab' => 'total', 'p_total' => max(1, $pTotal - 1)])); ?>">上一页</a>
                            <span class="tw-text-xs tw-text-gray-500"><?php echo $pTotal; ?> / <?php echo $totalCollectedPages; ?></span>
                            <a class="tw-px-3 tw-py-1.5 tw-rounded tw-border tw-text-xs <?php echo $pTotal >= $totalCollectedPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['records_tab' => 'total', 'p_total' => min($totalCollectedPages, $pTotal + 1)])); ?>">下一页</a>
                        </div>
                    </div>
                </div>

                <div id="recordsTabUnsettled" class="tw-hidden">
                    <!-- Currency filter tabs -->
                    <div class="tw-flex tw-items-center tw-gap-2 tw-px-4 tw-py-3 tw-border-b tw-border-gray-100 dark:tw-border-gray-700">
                        <span class="tw-text-xs tw-text-gray-500 tw-mr-1">筛选：</span>
                        <button onclick="filterUnsettledTab('ALL')" id="ufTabAll"
                            class="tw-px-3 tw-py-1 tw-rounded-md tw-text-xs tw-font-semibold tw-bg-primary tw-text-white">
                            全部 <span class="tw-opacity-80">(<?php echo (int)$unsettledTotal; ?>)</span>
                        </button>
                        <button onclick="filterUnsettledTab('USDT')" id="ufTabUSDT"
                            class="tw-px-3 tw-py-1 tw-rounded-md tw-text-xs tw-font-semibold tw-bg-gray-100 dark:tw-bg-gray-700 tw-text-gray-600 dark:tw-text-gray-300 hover:tw-bg-green-50 hover:tw-text-green-700">
                            USDT <span class="tw-opacity-80">(<?php echo (int)$unsettledCurrencyCounts['USDT']; ?>)</span>
                        </button>
                        <button onclick="filterUnsettledTab('USDC')" id="ufTabUSDC"
                            class="tw-px-3 tw-py-1 tw-rounded-md tw-text-xs tw-font-semibold tw-bg-gray-100 dark:tw-bg-gray-700 tw-text-gray-600 dark:tw-text-gray-300 hover:tw-bg-blue-50 hover:tw-text-blue-700">
                            USDC <span class="tw-opacity-80">(<?php echo (int)$unsettledCurrencyCounts['USDC']; ?>)</span>
                        </button>
                    </div>
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">地址ID</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">地址</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">路径</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">币种</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">待归集金额</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">操作</th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700" id="unsettledTableBody">
                                <?php if (empty($unsettledWithBalance)): ?>
                                    <tr><td colspan="6" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400">暂无未归集有余额地址</td></tr>
                                <?php else: ?>
                                    <?php foreach ($unsettledWithBalance as $w):
                                        $wCurrencies = array_filter(array_map('trim', explode(',', (string)($w['currencies'] ?? 'USDT'))));
                                        if (empty($wCurrencies)) $wCurrencies = ['USDT'];
                                        $wCurrenciesStr = implode(',', $wCurrencies);
                                    ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors" data-currencies="<?php echo htmlspecialchars($wCurrenciesStr); ?>">
                                        <td class="tw-px-6 tw-py-4"><?php echo (int)$w['id']; ?></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$w['address'], 0, 8) . '...' . substr((string)$w['address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500"><?php echo htmlspecialchars((string)($w['derivation_path'] ?: '-')); ?></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <div class="tw-flex tw-gap-1">
                                                <?php foreach ($wCurrencies as $cur): ?>
                                                    <span class="tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-bold <?php echo $cur === 'USDC' ? 'tw-bg-blue-50 tw-text-blue-600' : 'tw-bg-green-50 tw-text-green-600'; ?>"><?php echo htmlspecialchars($cur); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                        <td class="tw-px-6 tw-py-4 tw-font-medium"><?php echo number_format((float)($w['effective_balance_display'] ?? 0), 6); ?></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <button onclick="ajaxRefreshBalance(<?php echo (int)$w['id']; ?>, this)"
                                                class="tw-text-xs tw-text-primary hover:tw-underline">单独刷新</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <span class="tw-text-xs tw-text-gray-500">共 <?php echo (int)$unsettledTotal; ?> 条</span>
                        <div class="tw-inline-flex tw-items-center tw-gap-2">
                            <a class="tw-px-3 tw-py-1.5 tw-rounded tw-border tw-text-xs <?php echo $pUnsettled <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['records_tab' => 'unsettled', 'p_unsettled' => max(1, $pUnsettled - 1)])); ?>">上一页</a>
                            <span class="tw-text-xs tw-text-gray-500"><?php echo $pUnsettled; ?> / <?php echo $unsettledTotalPages; ?></span>
                            <a class="tw-px-3 tw-py-1.5 tw-rounded tw-border tw-text-xs <?php echo $pUnsettled >= $unsettledTotalPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['records_tab' => 'unsettled', 'p_unsettled' => min($unsettledTotalPages, $pUnsettled + 1)])); ?>">下一页</a>
                        </div>
                    </div>
                </div>

                <div id="recordsTabFailed" class="tw-hidden">
                    <div class="tw-overflow-x-auto">
                        <table class="tw-w-full tw-text-left tw-text-sm">
                            <thead class="tw-bg-gray-50 dark:tw-bg-gray-900 tw-text-gray-500 dark:tw-text-gray-400">
                                <tr>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">时间</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">批次</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">失败地址</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">目标地址</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">币种</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">金额</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">哈希（链上已提交）</th>
                                    <th class="tw-px-6 tw-py-3 tw-font-medium">失败原因</th>
                                </tr>
                            </thead>
                            <tbody class="tw-divide-y tw-divide-gray-100 dark:tw-divide-gray-700">
                                <?php if (empty($failedCollectedRecords)): ?>
                                    <tr><td colspan="8" class="tw-px-6 tw-py-8 tw-text-center tw-text-gray-400">暂无失败记录</td></tr>
                                <?php else: ?>
                                    <?php foreach ($failedCollectedRecords as $r):
                                        $fToken = strtoupper((string)($r['token_symbol'] ?? 'USDT'));
                                        $fHash  = (string)($r['tx_hash'] ?? '');
                                        $fChainId = (int)($r['chain_id'] ?? 0);
                                        $fExplorerBase = [56=>'https://bscscan.com/tx/',1=>'https://etherscan.io/tx/',137=>'https://polygonscan.com/tx/',42161=>'https://arbiscan.io/tx/',10=>'https://optimistic.etherscan.io/tx/',8453=>'https://basescan.org/tx/',43114=>'https://snowtrace.io/tx/'];
                                        $fExplorerUrl = ($fHash !== '' && isset($fExplorerBase[$fChainId])) ? $fExplorerBase[$fChainId] . $fHash : '';
                                    ?>
                                    <tr class="hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50 tw-transition-colors">
                                        <td class="tw-px-6 tw-py-4 tw-text-gray-500"><?php echo htmlspecialchars((string)($r['updated_at'] ?? '-')); ?></td>
                                        <td class="tw-px-6 tw-py-4">#<?php echo (int)($r['batch_id'] ?? 0); ?></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$r['from_address'], 0, 8) . '...' . substr((string)$r['from_address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4"><code><?php echo htmlspecialchars(substr((string)$r['to_address'], 0, 8) . '...' . substr((string)$r['to_address'], -6)); ?></code></td>
                                        <td class="tw-px-6 tw-py-4"><span class="tw-px-1.5 tw-py-0.5 tw-rounded tw-text-xs tw-font-bold <?php echo $fToken === 'USDC' ? 'tw-bg-blue-50 tw-text-blue-600' : 'tw-bg-green-50 tw-text-green-600'; ?>"><?php echo htmlspecialchars($fToken); ?></span></td>
                                        <td class="tw-px-6 tw-py-4 tw-font-medium"><?php echo number_format((float)$r['amount_display'], 6); ?></td>
                                        <td class="tw-px-6 tw-py-4">
                                            <?php if ($fHash !== ''): ?>
                                                <?php if ($fExplorerUrl !== ''): ?>
                                                    <a href="<?php echo htmlspecialchars($fExplorerUrl); ?>" target="_blank" class="tw-font-mono tw-text-xs tw-text-orange-500 hover:tw-underline" title="已上链但执行失败，点击查看链上状态"><?php echo htmlspecialchars(substr($fHash,0,8).'...'.substr($fHash,-6)); ?> ⚠️</a>
                                                <?php else: ?>
                                                    <code class="tw-text-xs tw-text-orange-400" title="<?php echo htmlspecialchars($fHash); ?>"><?php echo htmlspecialchars(substr($fHash,0,8).'...'.substr($fHash,-6)); ?> ⚠️</code>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="tw-text-gray-300">未上链</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tw-px-6 tw-py-4 tw-text-red-500 tw-text-xs"><?php echo htmlspecialchars((string)($r['tx_error'] ?: '未知错误')); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="tw-flex tw-items-center tw-justify-between tw-px-6 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700">
                        <span class="tw-text-xs tw-text-gray-500">共 <?php echo (int)$failedTotal; ?> 条</span>
                        <div class="tw-inline-flex tw-items-center tw-gap-2">
                            <a class="tw-px-3 tw-py-1.5 tw-rounded tw-border tw-text-xs <?php echo $pFailed <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['records_tab' => 'failed', 'p_failed' => max(1, $pFailed - 1)])); ?>">上一页</a>
                            <span class="tw-text-xs tw-text-gray-500"><?php echo $pFailed; ?> / <?php echo $failedTotalPages; ?></span>
                            <a class="tw-px-3 tw-py-1.5 tw-rounded tw-border tw-text-xs <?php echo $pFailed >= $failedTotalPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['records_tab' => 'failed', 'p_failed' => min($failedTotalPages, $pFailed + 1)])); ?>">下一页</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- Right: Config & Wallet List (Takes 4 columns) -->
        <div class="lg:tw-col-span-4 tw-space-y-6">
            
            <!-- Config Card -->
            <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-p-5">
                <div class="tw-flex tw-items-center tw-justify-between tw-gap-2 tw-mb-4">
                    <h3 class="tw-font-bold tw-text-gray-900 dark:tw-text-white tw-mb-0">配置中心</h3>
                    <a href="/tools/vanity_generator.html" target="_blank" rel="noopener" class="tw-inline-flex tw-items-center tw-gap-1 tw-px-3 tw-py-1.5 tw-rounded-md tw-text-xs tw-font-semibold tw-bg-indigo-50 tw-text-indigo-700 hover:tw-bg-indigo-100 dark:tw-bg-indigo-900/30 dark:tw-text-indigo-300 dark:hover:tw-bg-indigo-900/50">
                        靓号生成器
                    </a>
                </div>
                <form method="POST" class="tw-space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                    <input type="hidden" name="action" value="save_master">
                    <input type="hidden" name="chain" value="<?php echo htmlspecialchars($selectedChain); ?>">
                    <div>
                        <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">主钱包 (Master Address)</label>
                        <div class="tw-flex tw-gap-2">
                            <input type="text" name="master_address" value="<?php echo htmlspecialchars($masterMap[$selectedChain] ?? ''); ?>" class="tw-flex-1 tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm" placeholder="0x..." required>
                            <button type="submit" class="tw-px-3 tw-bg-primary hover:tw-bg-blue-600 tw-text-white tw-rounded-md tw-text-sm">保存</button>
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
                            <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">起始索引</label>
                            <input type="number" name="start_index" value="<?php echo (int)($nextIndexMap[$selectedChain] ?? 0); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                         </div>
                         <div>
                            <label class="tw-block tw-text-xs tw-font-medium tw-text-gray-500 tw-mb-1">路径前缀</label>
                            <input type="text" name="path_prefix" value="<?php echo htmlspecialchars($pathMap[$selectedChain] ?? "m/44'/60'/0'/0"); ?>" class="tw-w-full tw-rounded-md tw-border-gray-300 dark:tw-border-gray-600 dark:tw-bg-gray-700 tw-text-sm">
                         </div>
                    </div>
                    <button type="submit" class="tw-w-full tw-bg-white dark:tw-bg-gray-700 tw-border tw-border-gray-300 dark:tw-border-gray-600 tw-text-gray-700 dark:tw-text-gray-200 tw-py-2 tw-rounded-md tw-text-sm hover:tw-bg-gray-50 dark:hover:tw-bg-gray-600">保存 Xpub 配置</button>
                </form>
            </div>

            <!-- Wallet List Mini -->
             <div class="tw-bg-white dark:tw-bg-gray-800 tw-rounded-xl tw-shadow-sm tw-border tw-border-gray-200 dark:tw-border-gray-700 tw-flex tw-flex-col tw-h-[600px]">
                <div class="tw-px-5 tw-py-4 tw-border-b tw-border-gray-200 dark:tw-border-gray-700 tw-flex tw-justify-between tw-items-center">
                    <h3 class="tw-font-bold tw-text-gray-900 dark:tw-text-white">已收款待处理地址</h3>
                    <span class="tw-text-[10px] tw-text-gray-400 tw-bg-gray-100 dark:tw-bg-gray-700 tw-px-2 tw-py-0.5 tw-rounded" title="管理员视图显示所有商户的待归集地址，商户后台仅显示该商户自己的地址">全商户</span>
                </div>
                <div class="tw-flex-1 tw-overflow-y-auto tw-scrollbar-thin">
                    <?php if (empty($paidUnsettledWallets)): ?>
                        <div class="tw-p-8 tw-text-center tw-text-gray-400 tw-text-sm">暂无待归集地址</div>
                    <?php else: ?>
                        <?php foreach ($paidUnsettledWallets as $w):
                            $wCurrencies = array_filter(array_map('trim', explode(',', (string)($w['currencies'] ?? 'USDT'))));
                            if (empty($wCurrencies)) $wCurrencies = ['USDT'];
                        ?>
                        <div class="tw-px-5 tw-py-3 tw-border-b tw-border-gray-100 dark:tw-border-gray-700 hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700/50">
                            <div class="tw-flex tw-justify-between tw-items-start tw-mb-1">
                                <span class="tw-font-mono tw-text-xs tw-bg-gray-100 dark:tw-bg-gray-700 tw-px-1.5 tw-py-0.5 tw-rounded tw-text-gray-600 dark:tw-text-gray-300">
                                    <?php echo htmlspecialchars(substr($w['address'], 0, 6) . '...' . substr($w['address'], -4)); ?>
                                </span>
                                <span class="tw-font-bold tw-text-sm tw-text-gray-900 dark:tw-text-white"><?php echo number_format((float)($w['effective_balance_display'] ?? 0), 4); ?></span>
                            </div>
                            <div class="tw-flex tw-justify-between tw-items-center">
                                <span class="tw-text-xs tw-text-gray-400">ID: <?php echo (int)$w['id']; ?></span>
                                <div class="tw-flex tw-items-center tw-gap-1">
                                    <?php foreach ($wCurrencies as $cur): ?>
                                    <span class="tw-px-1.5 tw-py-0.5 tw-rounded tw-text-[10px] tw-font-bold <?php echo $cur === 'USDC' ? 'tw-bg-blue-50 tw-text-blue-600' : 'tw-bg-green-50 tw-text-green-600'; ?>"><?php echo htmlspecialchars($cur); ?></span>
                                    <?php endforeach; ?>
                                    <span class="tw-px-1.5 tw-py-0.5 tw-rounded-full tw-bg-green-50 tw-text-green-600 tw-text-[10px] tw-font-medium">已收款</span>
                                </div>
                            </div>
                            <div class="tw-mt-1 tw-text-[11px] tw-text-gray-500 tw-leading-5">
                                <div>订单号: <?php echo htmlspecialchars((string)($w['latest_paid_order_no'] ?: '-')); ?></div>
                                <div>交易时间: <?php echo htmlspecialchars((string)($w['latest_paid_at'] ?: '-')); ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="tw-px-5 tw-py-3 tw-border-t tw-border-gray-100 dark:tw-border-gray-700 tw-flex tw-items-center tw-justify-between">
                    <span class="tw-text-xs tw-text-gray-500">共 <?php echo (int)$unsettledTotal; ?> 条</span>
                    <div class="tw-inline-flex tw-items-center tw-gap-2">
                        <a class="tw-px-2.5 tw-py-1 tw-rounded tw-border tw-text-xs <?php echo $pSide <= 1 ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['p_side' => max(1, $pSide - 1)])); ?>">上一页</a>
                        <span class="tw-text-xs tw-text-gray-500"><?php echo $pSide; ?> / <?php echo $sideTotalPages; ?></span>
                        <a class="tw-px-2.5 tw-py-1 tw-rounded tw-border tw-text-xs <?php echo $pSide >= $sideTotalPages ? 'tw-pointer-events-none tw-opacity-50' : 'hover:tw-bg-gray-50 dark:hover:tw-bg-gray-700'; ?>" href="<?php echo htmlspecialchars(adminDerivedPageUrl(['p_side' => min($sideTotalPages, $pSide + 1)])); ?>">下一页</a>
                    </div>
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
    return [
        'item_id' => (int)($it['id'] ?? 0),
        'chain' => (string)$selectedChain,
        'chain_id' => (int)($latestBatch['chain_id'] ?? 0),
        'from' => (string)($it['from_address'] ?? ''),
        'to' => (string)($it['to_address'] ?? ''),
        'token_contract' => (string)($latestBatch['token_contract'] ?? ''),
        'amount_wei' => (string)($it['amount_wei'] ?? '0'),
        'data' => (string)$transferData,
        'derivation_path' => (string)($it['derivation_path'] ?? ''),
        'status' => (string)($it['status'] ?? ''),
    ];
}, $latestItems), JSON_UNESCAPED_UNICODE);
?>;
const ACTIVE_CHAIN = <?php echo json_encode((string)$selectedChain, JSON_UNESCAPED_UNICODE); ?>;
const ACTIVE_CHAIN_ID = <?php echo (int)($evmChains[$selectedChain]['chain_id'] ?? 0); ?>;
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
let workingBatchItems = Array.isArray(latestBatchItems) ? latestBatchItems.slice() : [];
let currentFlowMode = 'batch';

function appendFlowStatus(msg, level = 'info') {
    const board = document.getElementById('flowStatusBoard');
    if (!board) return;
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const mm = String(now.getMinutes()).padStart(2, '0');
    const ss = String(now.getSeconds()).padStart(2, '0');
    const line = document.createElement('div');
    const safeLevel = ['ok', 'run', 'fail', 'info'].includes(String(level)) ? String(level) : 'info';
    line.className = 'flow-log-line flow-' + safeLevel;
    const time = document.createElement('span');
    time.className = 'flow-log-time';
    time.textContent = `[${hh}:${mm}:${ss}]`;
    const text = document.createElement('span');
    text.className = 'flow-log-text';
    text.textContent = String(msg);
    line.appendChild(time);
    line.appendChild(text);
    board.appendChild(line);
    board.scrollTop = board.scrollHeight;
}

function flowEvent({ step, status = 'INFO', address = '-', txHash = '-', detail = '' }) {
    const stepTxt = String(step || '-');
    const statusTxt = String(status || 'INFO').toUpperCase();
    const addrTxt = String(address || '-');
    const txTxt = String(txHash || '-');
    const detailTxt = String(detail || '');
    const levelByStatus = {
        OK: 'ok',
        RUN: 'run',
        FAIL: 'fail',
        INFO: 'info'
    };
    appendFlowStatus(`[${statusTxt}] [${stepTxt}] [${addrTxt}] tx=${txTxt} ${detailTxt}`.trim(), levelByStatus[statusTxt] || 'info');
}

function showFlowFinalBanner(ok, message) {
    const banner = document.getElementById('flowFinalBanner');
    if (!banner) return;
    banner.classList.remove('tw-hidden', 'success', 'fail');
    banner.classList.add(ok ? 'success' : 'fail');
    banner.textContent = String(message || (ok ? '流程执行成功' : '流程执行失败'));
}

function clearFlowStatus() {
    const board = document.getElementById('flowStatusBoard');
    if (board) board.innerHTML = '<div class="flow-log-line flow-info"><span class="flow-log-time">[--:--:--]</span><span class="flow-log-text">System ready. Waiting for command...</span></div>';
    const banner = document.getElementById('flowFinalBanner');
    if (banner) banner.classList.add('tw-hidden');
    setFlowProgress(0, '等待执行');
}

function setFlowProgress(step, text) {
    const bar = document.getElementById('flowProgressBar');
    const pctEl = document.getElementById('flowProgressPercent');
    const textEl = document.getElementById('flowProgressText');
    const items = document.querySelectorAll('.stepper-item');
    const s = Math.max(0, Math.min(4, Number(step) || 0));
    const pct = Math.round((s / 4) * 100);
    if (bar) bar.style.width = pct + '%';
    if (pctEl) pctEl.textContent = pct + '%';
    if (textEl) textEl.textContent = String(text || '处理中');
    items.forEach((item, index) => {
        const stepNum = index + 1;
        item.classList.remove('active', 'completed');
        if (stepNum < s) item.classList.add('completed');
        if (stepNum === s) item.classList.add('active');
    });
}

function copyFlowResult() {
    const board = document.getElementById('flowStatusBoard');
    if (!board) return;
    const text = board.innerText.trim();
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        appendFlowStatus('日志已复制到剪贴板', 'ok');
    }).catch(() => {
        appendFlowStatus('复制失败，请手动复制', 'fail');
    });
}

function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

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
    const safety = Number(document.getElementById('dynSafetyFactor')?.value || '1.4');
    const minCoin = String(document.getElementById('dynMinTopupCoin')?.value || '0.00025').trim();
    const maxCoin = String(document.getElementById('dynMaxTopupCoin')?.value || '0.0006').trim();
    const retryCoin = String(document.getElementById('dynRetryExtraCoin')?.value || '0.0002').trim();
    const minGasPriceGwei = String(document.getElementById('dynMinGasPriceGwei')?.value || '1.2').trim();
    const defaultSweepGasLimit = BigInt(Math.max(21000, parseInt(document.getElementById('dynDefaultSweepGasLimit')?.value || '100000', 10) || 100000));
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
    const params = new URLSearchParams();
    params.set('csrf_token', CSRF_TOKEN);
    params.set('action', action);
    params.set('chain', ACTIVE_CHAIN);
    params.set('ajax', '1');
    Object.keys(fields || {}).forEach(function (k) {
        params.set(k, String(fields[k] ?? ''));
    });
    const resp = await fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: params.toString(),
        credentials: 'same-origin'
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error('请求失败: HTTP ' + resp.status);
    return data;
}

function jumpChain(chain) {
    const url = new URL(window.location.href);
    url.searchParams.set('chain', chain);
    window.location.href = url.toString();
}

function switchRecordsTab(tab) {
    const tabs = {
        batch: document.getElementById('recordsTabBatch'),
        total: document.getElementById('recordsTabTotal'),
        failed: document.getElementById('recordsTabFailed'),
        unsettled: document.getElementById('recordsTabUnsettled')
    };
    const btns = {
        batch: document.getElementById('recordsTabBatchBtn'),
        total: document.getElementById('recordsTabTotalBtn'),
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

async function ajaxRefreshBalance(walletId, btn) {
    if (!walletId) return;
    const origText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = '刷新中...'; }
    try {
        const csrfToken = <?php echo json_encode($admin_csrf_token); ?>;
        const resp = await fetch(location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
            body: new URLSearchParams({
                csrf_token: csrfToken,
                action: 'refresh_balance',
                chain: <?php echo json_encode($selectedChain); ?>,
                wallet_id: String(walletId),
                ajax: '1'
            })
        });
        const data = await resp.json();
        if (data && data.ok) {
            // Update balance in the row
            const row = btn ? btn.closest('tr') : null;
            if (row) {
                const cells = row.querySelectorAll('td');
                // Balance is the 5th cell (index 4)
                if (cells[4]) {
                    const bal = parseFloat(data.balance_display || '0');
                    cells[4].textContent = bal.toFixed(6);
                    cells[4].classList.add('tw-text-green-600');
                    setTimeout(() => cells[4].classList.remove('tw-text-green-600'), 2000);
                }
            }
            if (btn) btn.textContent = '已刷新';
            setTimeout(() => { if (btn) btn.textContent = origText; }, 2000);
        } else {
            if (btn) { btn.textContent = origText; btn.disabled = false; }
            alert(data?.message || '刷新失败');
        }
    } catch (e) {
        if (btn) { btn.textContent = origText; btn.disabled = false; }
        console.error(e);
    } finally {
        if (btn) btn.disabled = false;
    }
}

function setFlowToken(token) {
    const hidden = document.getElementById('flowBatchToken');
    if (hidden) hidden.value = token;
    const btnUsdt = document.getElementById('tokenToggleUSDT');
    const btnUsdc = document.getElementById('tokenToggleUSDC');
    if (!btnUsdt || !btnUsdc) return;
    if (token === 'USDT') {
        btnUsdt.className = btnUsdt.className
            .replace('tw-bg-white dark:tw-bg-gray-700 tw-text-gray-500 dark:tw-text-gray-400 hover:tw-bg-gray-50', '')
            .replace('tw-bg-blue-500 tw-text-white', '');
        btnUsdt.classList.add('tw-bg-green-500', 'tw-text-white');
        btnUsdc.classList.remove('tw-bg-blue-500', 'tw-text-white');
        btnUsdc.classList.add('tw-bg-white', 'dark:tw-bg-gray-700', 'tw-text-gray-500', 'dark:tw-text-gray-400', 'hover:tw-bg-gray-50');
    } else {
        btnUsdc.className = btnUsdc.className
            .replace('tw-bg-white dark:tw-bg-gray-700 tw-text-gray-500 dark:tw-text-gray-400 hover:tw-bg-gray-50', '')
            .replace('tw-bg-green-500 tw-text-white', '');
        btnUsdc.classList.add('tw-bg-blue-500', 'tw-text-white');
        btnUsdt.classList.remove('tw-bg-green-500', 'tw-text-white');
        btnUsdt.classList.add('tw-bg-white', 'dark:tw-bg-gray-700', 'tw-text-gray-500', 'dark:tw-text-gray-400', 'hover:tw-bg-gray-50');
    }
}

function filterUnsettledTab(token) {
    // Update tab button styles
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
    // Filter table rows
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
            // Use 65000 gas limit for ETH topup transfers; Arbitrum and some L2s
            // require more than 21000 due to L1 data fee component in intrinsic gas
            let topupGasLimit = 65000n;
            const tx = {
                chainId: Number(ACTIVE_CHAIN_ID),
                nonce: txNonce,
                gasLimit: topupGasLimit,
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
                detail: `gasPrice=${gasPriceWei.toString()} gasLimit=${topupGasLimit.toString()} need=${ethers.formatEther(needWei)} topup=${ethers.formatEther(topupWei)}`
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
                    gasLimit: topupGasLimit,
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
            } else if ((!resp || !resp.ok) && /intrinsic gas too low/i.test(firstReason)) {
                // Intrinsic gas too low means gasLimit is insufficient; raise gasLimit further
                topupGasLimit = 200000n;
                const retryTx = {
                    chainId: Number(ACTIVE_CHAIN_ID),
                    nonce: txNonce,
                    gasLimit: topupGasLimit,
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
                    detail: `intrinsic gas too low，提高 gasLimit 重试 gasLimit=${topupGasLimit.toString()}`
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
            // This prevents "nonce too low" when the address has prior transactions
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
                let path = String(it.derivation_path || '').trim();
                let passphraseForItem = pass;
                let wallet = null;
                if (mode === 'mnemonic') {
                    if (!path) {
                        flowEvent({ step: passLabel, status: 'INFO', address: String(it.from), detail: '未提供路径，尝试自动匹配地址路径' });
                        const found = await findPathByAddressAsync(
                            phrase,
                            pass,
                            String(it.from),
                            String(document.getElementById('gasPathProfile')?.value || 'auto'),
                            String(document.getElementById('gasPathScanDepth')?.value || '1200')
                        );
                        if (!found.path) {
                            return { ok: false, item_id: Number(it.item_id), address: String(it.from), reason: `item_id=${it.item_id} 自动匹配路径失败` };
                        }
                        path = String(found.path);
                        passphraseForItem = String(found.passphrase || pass);
                        flowEvent({ step: passLabel, status: 'INFO', address: String(it.from), detail: `自动匹配路径成功：${path}` });
                    }
                    wallet = ethers.HDNodeWallet.fromPhrase(phrase, passphraseForItem, path);
                } else {
                    wallet = new ethers.Wallet(sweepPk);
                }
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

        // Receipt verification: check on-chain status for broadcasted txs
        const toVerify = firstPass.filter(r => r.ok && r.tx_hash && r.tx_hash !== '-' && r.item_id);
        if (toVerify.length > 0) {
            appendFlowStatus('[INFO] 等待区块确认后校验链上回执（15s）...');
            await new Promise(res => setTimeout(res, 15000));
            try {
                const txMap = {};
                toVerify.forEach(r => { txMap[r.item_id] = r.tx_hash; });
                const vResp = await postActionJson('verify_tx_receipts', { tx_map: JSON.stringify(txMap) });
                if (vResp && Array.isArray(vResp.results)) {
                    for (const vr of vResp.results) {
                        if (vr.status === 'reverted') {
                            appendFlowStatus(`[FAIL] 链上执行失败(revert)：item #${vr.item_id}，归集未到账，已标记为失败`);
                        } else if (vr.status === 'pending') {
                            appendFlowStatus(`[INFO] item #${vr.item_id} 交易待确认，建议稍后在浏览器核查`);
                        } else if (vr.status === 'success') {
                            appendFlowStatus(`[OK] item #${vr.item_id} 链上已确认到账`);
                        }
                    }
                }
            } catch (_ve) {
                appendFlowStatus('[WARN] 回执校验请求失败，请手动核查链上状态');
            }
        }

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
                    gasLimit: 65000n, // Use 65000 to handle L2 chains with higher intrinsic gas
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
    const quickAddress = String(document.getElementById('quickTransferAddress')?.value || '').trim();
    const batchToken = String(document.getElementById('flowBatchToken')?.value || 'USDT').trim().toUpperCase();
    const isQuick = /^0x[a-fA-F0-9]{40}$/.test(quickAddress);
    if (quickAddress && !isQuick) {
        const err = '快速转账地址格式不正确，请输入 0x 开头的 40 位地址';
        appendFlowStatus(err, 'fail');
        return { ok: false, message: err };
    }
    currentFlowMode = isQuick ? 'quick_single' : 'batch';
    appendFlowStatus(
        isQuick ? ('单地址模式：开始生成快速转账批次（' + batchToken + ' / ' + quickAddress + '）') : ('批量模式：开始生成 ' + batchToken + ' 归集批次'),
        'run'
    );
    setFlowProgress(1, '步骤1：生成批次');
    try {
        const resp = isQuick
            ? await postActionJson('create_quick_single_batch', { from_address: quickAddress, quick_token: batchToken })
            : await postActionJson('create_batch', { min_amount: min, batch_token: batchToken });
        if (!resp || resp.ok === false) throw new Error((resp && resp.message) ? resp.message : '生成归集批次失败');
        workingBatchItems = Array.isArray(resp.batch_items) ? resp.batch_items : [];
        appendFlowStatus('步骤1完成：' + (resp.message || '归集批次已生成'), 'ok');
        return resp;
    } catch (e) {
        appendFlowStatus('步骤1失败：' + (e && e.message ? e.message : String(e)), 'fail');
        return { ok: false, message: String(e && e.message ? e.message : e) };
    }
}

async function runFullFlow() {
    const btn = document.getElementById('runFullFlowBtn');
    const waitSec = Math.max(3, Math.min(90, parseInt(document.getElementById('flowWaitSeconds').value || '30', 10) || 30));
    try {
        if (btn) btn.disabled = true;
        clearFlowStatus();
        flowEvent({ step: '全流程', status: 'RUN', detail: '开始执行' });
        setFlowProgress(1, '步骤1：生成批次');
        const batchResp = await createBatchOnly();
        if (!batchResp || batchResp.ok === false) throw new Error(batchResp?.message || '生成归集批次失败');

        flowEvent({
            step: '补Gas',
            status: 'RUN',
            detail: currentFlowMode === 'quick_single' ? '单地址补Gas并广播' : '开始批量补Gas并广播'
        });
        setFlowProgress(2, '步骤2：补 Gas');
        const gasResp = await signAndBroadcastGasBatch();
        const gasOk = Number(gasResp?.ok_count || 0);
        const gasFail = Number(gasResp?.fail_count || 0);
        if (!gasResp || (gasResp.ok === false && gasOk <= 0)) throw new Error(gasResp?.message || '补Gas失败');
        if (gasFail > 0) {
            (gasResp.fail_details || []).forEach(row => {
                flowEvent({ step: '补Gas', status: 'FAIL', address: row.address || '-', detail: row.reason || '失败' });
            });
        }

        const pollThreshold = getDynPollThreshold();
        const targetCount = Number(gasResp?.target_count || 0);
        const successRows = Array.isArray(gasResp?.success_details) ? gasResp.success_details : [];
        if (targetCount > 0 && targetCount <= pollThreshold && successRows.length > 0) {
            flowEvent({ step: '链上确认', status: 'RUN', detail: `地址数 ${targetCount} <= 阈值 ${pollThreshold}，执行轮询确认` });
            setFlowProgress(3, '步骤3：链上确认');
            await waitTopupConfirmByHashes(successRows, waitSec);
        } else {
            flowEvent({ step: '链上确认', status: 'RUN', detail: `地址数 ${targetCount} > 阈值 ${pollThreshold}，固定等待 ${waitSec} 秒` });
            setFlowProgress(3, '步骤3：链上确认');
            for (let i = waitSec; i > 0; i--) {
                flowEvent({ step: '链上确认', status: 'INFO', detail: '剩余 ' + i + ' 秒' });
                await sleep(1000);
            }
        }

        flowEvent({ step: '归集广播', status: 'RUN', detail: '开始归集签名并广播' });
        setFlowProgress(4, '步骤4：归集广播');
        const sweepResp = await signAndBroadcastSweepBatch();
        const sweepOk = Number(sweepResp?.ok_count || 0);
        const sweepFail = Number(sweepResp?.fail_count || 0);
        if (!sweepResp || (sweepResp.ok === false && sweepOk <= 0)) throw new Error(sweepResp?.message || '归集广播失败');
        if (sweepFail > 0) {
            (sweepResp.fail_details || []).forEach(row => {
                flowEvent({ step: '归集广播', status: 'FAIL', address: row.address || '-', detail: row.reason || '失败' });
            });
        }
        flowEvent({
            step: '全流程',
            status: sweepFail > 0 ? 'INFO' : 'OK',
            detail: `完成：归集成功 ${sweepOk}，失败 ${sweepFail}`
        });
        showFlowFinalBanner(sweepFail <= 0, sweepFail > 0 ? `流程完成：成功 ${sweepOk}，失败 ${sweepFail}` : `流程成功完成：共成功 ${sweepOk} 笔`);
    } catch (e) {
        const errText = (e && e.message ? e.message : String(e));
        flowEvent({ step: '全流程', status: 'FAIL', detail: errText });
        showFlowFinalBanner(false, '流程失败：' + errText);
    } finally {
        if (btn) btn.disabled = false;
    }
}

setGasWalletModeUI();
setSweepSignerModeUI();
switchRecordsTab(<?php echo json_encode($recordsTab, JSON_UNESCAPED_UNICODE); ?>);
setFlowProgress(0, '等待执行');

// --- Batch Items Modal ---
async function viewBatchItems(batchId) {
    const modal = document.getElementById('batchItemsModal');
    if (!modal || !window.bootstrap) return;
    const inst = bootstrap.Modal.getOrCreateInstance(modal);
    document.getElementById('batchItemsLoading').classList.remove('d-none');
    document.getElementById('batchItemsContent').classList.add('d-none');
    document.getElementById('batchItemsModalLabel').textContent = '批次 #' + batchId + ' 明细';
    inst.show();
    try {
        const fd = new FormData();
        fd.append('action', 'get_batch_items');
        fd.append('batch_id', batchId);
        fd.append('chain', ACTIVE_CHAIN);
        fd.append('ajax', '1');
        fd.append('csrf_token', CSRF_TOKEN);
        const resp = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd });
        const data = await resp.json();
        if (!data.ok) {
            document.getElementById('batchItemsLoading').innerHTML = '<div class="text-danger p-3">' + (data.message || '加载失败') + '</div>';
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
                broadcasted: '<span class="badge bg-success">已广播</span>',
                failed: '<span class="badge bg-danger">失败</span>',
                pending_sign: '<span class="badge bg-warning text-dark">待签名</span>',
                pending: '<span class="badge bg-secondary">待处理</span>'
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
        document.getElementById('batchItemsLoading').innerHTML = '<div class="text-danger p-3">请求失败：' + e.message + '</div>';
    }
}

async function rollbackBatch(batchId) {
    if (!confirm('确定回滚批次 #' + batchId + ' 中的所有记录到待签名状态？此操作将重置所有（包括失败和已广播）记录。')) return;
    try {
        const fd = new FormData();
        fd.append('action', 'rollback_batch');
        fd.append('batch_id', batchId);
        fd.append('chain', ACTIVE_CHAIN);
        fd.append('ajax', '1');
        fd.append('csrf_token', CSRF_TOKEN);
        const resp = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) {
            alert(data.message || '回滚成功');
            location.reload();
        } else {
            alert('回滚失败：' + (data.message || '未知错误'));
        }
    } catch (e) {
        alert('请求失败：' + e.message);
    }
}
</script>

<?php require_once 'includes/footer.php'; ?>
