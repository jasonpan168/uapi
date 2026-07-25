<?php
// src/Services/CryptoService.php
require_once __DIR__ . '/../../config/config.php';

class CryptoService {
    private static $externalUsageContext = [];
    private static $apiRequestSchemaEnsured = false;
    private static $schemaCache = [];

    public static function setExternalUsageContext(array $context): void
    {
        self::$externalUsageContext = [
            'user_id' => isset($context['user_id']) ? (int)$context['user_id'] : null,
            'order_id' => isset($context['order_id']) ? (int)$context['order_id'] : null,
            'order_no' => isset($context['order_no']) ? substr((string)$context['order_no'], 0, 64) : null,
            'chain' => isset($context['chain']) ? strtolower(substr((string)$context['chain'], 0, 32)) : '',
            'source' => isset($context['source']) ? substr((string)$context['source'], 0, 50) : '',
            'trigger_mode' => isset($context['trigger_mode']) ? substr((string)$context['trigger_mode'], 0, 20) : '',
        ];
    }

    public static function clearExternalUsageContext(): void
    {
        self::$externalUsageContext = [];
    }

    public static function ensureExternalRequestLogTable(): void
    {
        self::ensureApiRequestSchema();
    }

    public static function ensureApiRequestSchema(): void
    {
        if (self::$apiRequestSchemaEnsured) {
            return;
        }
        try {
            require_once __DIR__ . '/../Core/Database.php';
            $db = Database::getInstance();
            $db->query("CREATE TABLE IF NOT EXISTS api_request_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NULL,
                chain VARCHAR(32) DEFAULT '',
                api_provider VARCHAR(100) DEFAULT '',
                api_name VARCHAR(100) DEFAULT '',
                status_code INT DEFAULT 0,
                billable TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_order_created (order_id, created_at),
                INDEX idx_created_at (created_at),
                INDEX idx_provider_name (api_provider, api_name),
                INDEX idx_billable_created (billable, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->query("CREATE TABLE IF NOT EXISTS api_usage_daily (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                usage_date DATE NOT NULL,
                used_count INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_date (user_id, usage_date),
                INDEX idx_usage_date (usage_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $db->query("CREATE TABLE IF NOT EXISTS external_request_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                order_id INT NULL,
                order_no VARCHAR(64) DEFAULT NULL,
                chain VARCHAR(32) DEFAULT '',
                source VARCHAR(50) DEFAULT '',
                trigger_mode VARCHAR(20) DEFAULT '',
                request_method VARCHAR(10) NOT NULL,
                request_type VARCHAR(100) DEFAULT '',
                target_host VARCHAR(191) DEFAULT '',
                target_path VARCHAR(255) DEFAULT '',
                status_code INT DEFAULT 0,
                success TINYINT(1) DEFAULT 0,
                error_message VARCHAR(255) DEFAULT '',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_created (user_id, created_at),
                INDEX idx_order_created (order_id, created_at),
                INDEX idx_created_at (created_at),
                INDEX idx_request_type (request_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try {
                $db->query("ALTER TABLE users ADD COLUMN api_request_total INT NOT NULL DEFAULT 0");
            } catch (Throwable $ignore) {
                error_log("[CryptoService] ensureApiRequestSchema column add: " . $ignore->getMessage());
            }
            self::$apiRequestSchemaEnsured = true;
        } catch (Throwable $e) {
            error_log("[CryptoService] ensureApiRequestSchema DDL failed: " . $e->getMessage());
        }
    }

    private static function hasTable($db, string $table): bool
    {
        $key = 'table:' . $table;
        if (array_key_exists($key, self::$schemaCache)) {
            return self::$schemaCache[$key];
        }
        try {
            $row = $db->fetch("SHOW TABLES LIKE ?", [$table]);
            self::$schemaCache[$key] = is_array($row) && !empty($row);
        } catch (Throwable $ignore) {
            self::$schemaCache[$key] = false;
            error_log("[CryptoService] hasTable($table) check failed: " . $ignore->getMessage());
        }
        return self::$schemaCache[$key];
    }

    private static function hasColumn($db, string $table, string $column): bool
    {
        $key = 'column:' . $table . ':' . $column;
        if (array_key_exists($key, self::$schemaCache)) {
            return self::$schemaCache[$key];
        }
        try {
            $row = $db->fetch("SHOW COLUMNS FROM `$table` LIKE ?", [$column]);
            self::$schemaCache[$key] = is_array($row) && !empty($row);
        } catch (Throwable $ignore) {
            self::$schemaCache[$key] = false;
            error_log("[CryptoService] hasColumn($table, $column) check failed: " . $ignore->getMessage());
        }
        return self::$schemaCache[$key];
    }

    private static function buildDateClause(string $column, ?string $date, array &$params): string
    {
        if ($date === null || $date === '') {
            return '';
        }
        $params[] = $date;
        return " AND DATE($column) = ?";
    }

    public static function getMerchantBillableRequestCount(int $userId, ?string $date = null): int
    {
        self::ensureApiRequestSchema();
        try {
            require_once __DIR__ . '/../Core/Database.php';
            $db = Database::getInstance();
            if (self::hasTable($db, 'external_request_logs') && self::hasColumn($db, 'external_request_logs', 'order_id')) {
                $params = [$userId];
                $dateClause = self::buildDateClause('erl.created_at', $date, $params);
                $row = $db->fetch(
                    "SELECT COUNT(*) AS c
                     FROM external_request_logs erl
                     JOIN orders o ON o.id = erl.order_id
                     WHERE o.user_id = ?
                       AND erl.status_code BETWEEN 200 AND 299" . $dateClause,
                    $params
                );
                return (int)($row['c'] ?? 0);
            }

            if (self::hasTable($db, 'api_request_logs')) {
                $params = [$userId];
                $dateClause = self::buildDateClause('arl.created_at', $date, $params);
                $row = $db->fetch(
                    "SELECT COUNT(*) AS c
                     FROM api_request_logs arl
                     JOIN orders o ON o.id = arl.order_id
                     WHERE o.user_id = ?
                       AND arl.billable = 1" . $dateClause,
                    $params
                );
                return (int)($row['c'] ?? 0);
            }

            return 0;
        } catch (Throwable $ignore) {
            error_log("[CryptoService] getMerchantBillableRequestCount failed for user $userId: " . $ignore->getMessage());
            return 0;
        }
    }

    public static function getMerchantBillableOrderCount(int $userId, ?string $date = null): int
    {
        self::ensureApiRequestSchema();
        try {
            require_once __DIR__ . '/../Core/Database.php';
            $db = Database::getInstance();
            $orderIds = [];

            if (self::hasTable($db, 'external_request_logs') && self::hasColumn($db, 'external_request_logs', 'order_id')) {
                $params = [$userId];
                $dateClause = self::buildDateClause('erl.created_at', $date, $params);
                $rows = $db->fetchAll(
                    "SELECT DISTINCT erl.order_id
                    FROM external_request_logs erl
                     JOIN orders o ON o.id = erl.order_id
                     WHERE o.user_id = ?
                       AND erl.order_id IS NOT NULL
                       AND erl.status_code BETWEEN 200 AND 299" . $dateClause,
                    $params
                );
                foreach ($rows as $row) {
                    $orderId = (int)($row['order_id'] ?? 0);
                    if ($orderId > 0) {
                        $orderIds[$orderId] = true;
                    }
                }
            } elseif (self::hasTable($db, 'api_request_logs')) {
                $params = [$userId];
                $dateClause = self::buildDateClause('arl.created_at', $date, $params);
                $rows = $db->fetchAll(
                    "SELECT DISTINCT arl.order_id
                     FROM api_request_logs arl
                     JOIN orders o ON o.id = arl.order_id
                     WHERE o.user_id = ?
                       AND arl.order_id IS NOT NULL
                       AND arl.billable = 1" . $dateClause,
                    $params
                );
                foreach ($rows as $row) {
                    $orderId = (int)($row['order_id'] ?? 0);
                    if ($orderId > 0) {
                        $orderIds[$orderId] = true;
                    }
                }
            }

            return count($orderIds);
        } catch (Throwable $ignore) {
            error_log("[CryptoService] getMerchantBillableOrderCount failed for user $userId: " . $ignore->getMessage());
            return 0;
        }
    }

    public static function getSystemBillableRequestCount(?string $date = null): int
    {
        self::ensureApiRequestSchema();
        try {
            require_once __DIR__ . '/../Core/Database.php';
            $db = Database::getInstance();

            if (self::hasTable($db, 'external_request_logs')) {
                $params = [];
                $dateClause = self::buildDateClause('created_at', $date, $params);
                $row = $db->fetch(
                    "SELECT COUNT(*) AS c
                     FROM external_request_logs
                     WHERE status_code BETWEEN 200 AND 299" . $dateClause,
                    $params
                );
                return (int)($row['c'] ?? 0);
            }

            if (self::hasTable($db, 'api_request_logs')) {
                $params = [];
                $dateClause = self::buildDateClause('created_at', $date, $params);
                $row = $db->fetch(
                    "SELECT COUNT(*) AS c
                     FROM api_request_logs
                     WHERE billable = 1" . $dateClause,
                    $params
                );
                return (int)($row['c'] ?? 0);
            }

            return 0;
        } catch (Throwable $ignore) {
            error_log("[CryptoService] getSystemBillableRequestCount failed: " . $ignore->getMessage());
            return 0;
        }
    }

    private static function getSolanaRpcUrl() {
        // Try to get from DB setting first
        try {
            if (class_exists('Database')) {
                $db = Database::getInstance();
                $row = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'sol_rpc_url'");
                if ($row && !empty($row['value'])) return $row['value'];
            }
        } catch (Exception $e) {
            error_log("[CryptoService] getSolanaRpcUrl DB lookup failed: " . $e->getMessage());
        }

        // Default Public RPC
        return 'https://api.mainnet-beta.solana.com';
    }

    public static function getExplorerUrl($chain, $hash) {
        $chain = strtolower($chain);
        if ($chain === 'trc20' || $chain === 'tron') {
            return "https://tronscan.org/#/transaction/$hash";
        } elseif ($chain === 'solana') {
            return "https://solscan.io/tx/$hash";
        } elseif ($chain === 'bsc') {
            return "https://bscscan.com/tx/$hash";
        } elseif ($chain === 'polygon') {
            return "https://polygonscan.com/tx/$hash";
        } elseif ($chain === 'optimism') {
            return "https://optimistic.etherscan.io/tx/$hash";
        } elseif ($chain === 'arbitrum') {
            return "https://arbiscan.io/tx/$hash";
        } elseif ($chain === 'base') {
            return "https://basescan.org/tx/$hash";
        } elseif ($chain === 'avalanche') {
            return "https://snowtrace.io/tx/$hash";
        }
        return "https://etherscan.io/tx/$hash";
    }

    /**
     * Check Solana USDT Transfers using RPC
     */
    public static function checkSolana($address, $expected_amount, $min_timestamp, $currency = 'USDT') {
        $rpc = self::getSolanaRpcUrl();
        $usdt_mint = 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB';
        $usdc_mint = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v'; // USDC Mint
        $target_mint = ($currency === 'USDC') ? $usdc_mint : $usdt_mint;

        // 1. Get Signatures
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getSignaturesForAddress',
            'params' => [
                $address,
                ['limit' => 10]
            ]
        ];
        
        $resp = self::curlPost($rpc, $payload);
        
        if (!isset($resp['result']) || !is_array($resp['result'])) return false;

        foreach ($resp['result'] as $sigInfo) {
            if (isset($sigInfo['err']) && $sigInfo['err']) continue; // Skip failed txs
            
            // Time Check with 5 min tolerance (drift)
            // If tx is older than order creation - 5 mins, skip.
            // But we iterate latest first, so we just check if it's too old?
            // Actually, we want to find a tx that happened AFTER min_timestamp.
            // But due to clock drift, we allow tx time to be slightly before min_timestamp (e.g. 5 mins).
            // Logic: if tx_time < (min_timestamp - 300), it's definitely too old.
            if ($sigInfo['blockTime'] < ($min_timestamp - 300)) continue;

            $signature = $sigInfo['signature'];
            
            // 2. Get Transaction Details
            $txPayload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'getTransaction',
                'params' => [
                    $signature,
                    ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0]
                ]
            ];
            
            $txResp = self::curlPost($rpc, $txPayload);
            if (!isset($txResp['result'])) continue;
            
            $tx = $txResp['result'];
            if (!isset($tx['meta'])) continue;
            $meta = $tx['meta'];
            
            // Calculate Balance Change
            $change = self::calculateSolanaTokenChange($meta, $address, $target_mint);
            
            // Check Amount
            if ($change > 0 && abs($change - $expected_amount) < 0.0001) {
                return [
                    'hash' => $signature,
                    'amount' => $change,
                    'time' => $sigInfo['blockTime']
                ];
            }
        }
        
        return false;
    }

    /**
     * Helper to calc token balance change from meta
     */
    private static function calculateSolanaTokenChange($meta, $address, $mint) {
        $pre = 0;
        $post = 0;
        
        if (isset($meta['preTokenBalances'])) {
            foreach ($meta['preTokenBalances'] as $b) {
                if ($b['mint'] === $mint && isset($b['owner']) && $b['owner'] === $address) {
                    $pre = $b['uiTokenAmount']['uiAmount'] ?? 0;
                    break;
                }
            }
        }
        
        if (isset($meta['postTokenBalances'])) {
            foreach ($meta['postTokenBalances'] as $b) {
                if ($b['mint'] === $mint && isset($b['owner']) && $b['owner'] === $address) {
                    $post = $b['uiTokenAmount']['uiAmount'] ?? 0;
                    break;
                }
            }
        }
        
        return $post - $pre;
    }

    /**
     * 检查 TRC20 交易
     */
    public static function checkTrc20($address, $expected_amount, $min_timestamp) {
        global $chains_config;
        $contract = $chains_config['trc20']['contract'];
        
        // TronScan API
        $url = "https://apilist.tronscan.org/api/token_trc20/transfers?limit=20&start=0&sort=-timestamp&count=true&relatedAddress={$address}&contract_address={$contract}";
        
        $data = self::curlGet($url);
        
        if (!isset($data['token_transfers']) || !is_array($data['token_transfers'])) {
            return false;
        }

        foreach ($data['token_transfers'] as $tx) {
            if ($tx['to_address'] !== $address) continue;
            
            $amount = floatval($tx['quant']) / 1000000;
            $time = $tx['block_ts'] / 1000;
            
            // 匹配条件: 时间 > 订单创建时间 且 金额一致 (容差 0.0001)
            if ($time >= $min_timestamp && abs($amount - $expected_amount) < 0.0001) {
                return [
                    'hash' => $tx['transaction_id'],
                    'amount' => $amount,
                    'time' => $time
                ];
            }
        }
        return false;
    }

    /**
     * 检查 EVM 交易 (BSC, ETH 等)
     */
    public static function checkEvm($chain_key, $address, $expected_amount, $min_timestamp, $currency = 'USDT') {
        global $chains_config;
        if (!isset($chains_config[$chain_key])) return false;
        
        $config = $chains_config[$chain_key];
        $chain_id = $config['chain_id'];
        
        // Load API Key
        $api_key = defined('ETHERSCAN_API_KEY') ? ETHERSCAN_API_KEY : '';
        if (!$api_key || $api_key === 'YOUR_ETHERSCAN_KEY') {
             require_once __DIR__ . '/../Core/Database.php';
             try {
                 $db = Database::getInstance();
                 $row = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'eth_api_key'");
                 if ($row && !empty($row['value'])) $api_key = $row['value'];
             } catch (Exception $e) {
                 error_log("[CryptoService] eth_api_key lookup failed: " . $e->getMessage());
             }
        }

        // Get USDC Contract if needed
        $target_contracts = [];
        if ($currency === 'USDC') {
             $target_contracts = [];
             // 1) Prefer config-defined list (supports multi-contract chains like ARB USDC/USDC.e)
             if (!empty($config['usdc']) && is_array($config['usdc'])) {
                 foreach ($config['usdc'] as $uc) {
                     $uc = trim((string)$uc);
                     if ($uc !== '') $target_contracts[] = $uc;
                 }
             }
             // 2) Append merchant chain override from DB
             require_once __DIR__ . '/../Core/Database.php';
             $db = Database::getInstance();
             try {
                 $chain_info = $db->fetch("SELECT usdc_contract FROM chains WHERE slug = ?", [$chain_key]);
                 if ($chain_info && !empty($chain_info['usdc_contract'])) {
                     $target_contracts[] = (string)$chain_info['usdc_contract'];
                 }
             } catch (Exception $e) {
                 error_log("[CryptoService] USDC contract lookup failed for $chain_key: " . $e->getMessage());
             }
             // 3) Fallback hardcoded popular USDC
             if (empty($target_contracts)) {
                 $map = [
                     'eth' => '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48',
                     'bsc' => '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d',
                     'polygon' => '0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359',
                     'arbitrum' => '0xaf88d065e77c8cc2239327c5edb3a432268e5831',
                     'optimism' => '0x0b2c639c533813f4aa9d7837caf62653d097ff85',
                     'base' => '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913',
                     'avalanche' => '0xb97ef9ef8734c71904d8002f8b6bc66dd9c48a6e',
                     'trc20' => 'TEkxiTehnzSmSe2XqrBj4w32RUN966rdz8'
                 ];
                 if (isset($map[$chain_key])) $target_contracts[] = $map[$chain_key];
             }
             $target_contracts = array_values(array_unique(array_filter($target_contracts, function ($v) {
                 return trim((string)$v) !== '';
             })));
        } else {
             // USDT
             $target_contracts = $config['usdt'] ?? [];
        }
        
        // Etherscan V2 API (paid key only)
        // Increase scan window to reduce missed matches when transfer frequency is high.
        $pages = [1, 2, 3];
        $offset = 100;
        foreach ($pages as $page) {
            $url = "https://api.etherscan.io/v2/api?chainid={$chain_id}&module=account&action=tokentx&address={$address}&page={$page}&offset={$offset}&sort=desc&apikey={$api_key}";
            $data = self::curlGet($url);
            if (!isset($data['status']) || (string)$data['status'] !== '1' || empty($data['result']) || !is_array($data['result'])) {
                continue;
            }
            foreach ($data['result'] as $tx) {
                if (strcasecmp((string)($tx['to'] ?? ''), $address) !== 0) continue;
                
                // 检查合约地址
                $is_valid_token = false;
                foreach ($target_contracts as $contract) {
                    if (strcasecmp((string)($tx['contractAddress'] ?? ''), $contract) === 0) {
                        $is_valid_token = true;
                        break;
                    }
                }
                
                // Fuzzy match fallback
                if (!$is_valid_token) {
                    $symbol = strtoupper(isset($tx['tokenSymbol']) ? (string)$tx['tokenSymbol'] : '');
                    $name = strtoupper(isset($tx['tokenName']) ? (string)$tx['tokenName'] : '');
                    if ($currency === 'USDC') {
                        if (strpos($symbol, 'USDC') !== false || strpos($name, 'USD COIN') !== false) {
                            $is_valid_token = true;
                        }
                    } else {
                        if (strpos($symbol, 'USDT') !== false || strpos($symbol, 'USD₮') !== false || strpos($name, 'TETHER') !== false) {
                            $is_valid_token = true;
                        }
                    }
                }
                if (!$is_valid_token) continue;

                $decimals = isset($tx['tokenDecimal']) ? intval($tx['tokenDecimal']) : (int)($config['decimals'] ?? 6);
                $amount = floatval((string)($tx['value'] ?? '0')) / pow(10, $decimals);
                $timeStamp = (int)($tx['timeStamp'] ?? 0);
                if ($timeStamp >= $min_timestamp && abs($amount - $expected_amount) < 0.0001) {
                    return [
                        'hash' => (string)$tx['hash'],
                        'amount' => $amount,
                        'time' => $timeStamp
                    ];
                }
            }
        }

        // Fallback: query chain logs directly via RPC, avoids scanner API misses/rate-limit issues.
        $rpcTx = self::checkEvmByRpcLogs($chain_key, $address, (float)$expected_amount, (int)$min_timestamp, (array)$target_contracts, (int)($config['decimals'] ?? 6));
        if ($rpcTx) {
            return $rpcTx;
        }

        return false;
    }

    private static function checkEvmByRpcLogs($chain_key, $address, $expected_amount, $min_timestamp, $target_contracts, $defaultDecimals = 6)
    {
        $rpc = self::getEvmRpcUrl($chain_key);
        if ($rpc === '' || !preg_match('/^0x[a-fA-F0-9]{40}$/', (string)$address) || empty($target_contracts)) {
            return false;
        }

        $latestHex = self::rpcCall($rpc, 'eth_blockNumber', []);
        if (!is_string($latestHex) || !preg_match('/^0x[0-9a-fA-F]+$/', $latestHex)) {
            return false;
        }
        $latest = hexdec($latestHex);
        if ($latest <= 0) return false;

        $from = max(0, $latest - 2500);
        $fromHex = '0x' . dechex($from);
        $toHex = '0x' . dechex($latest);
        $topicTransfer = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        $toTopic = '0x000000000000000000000000' . strtolower(substr((string)$address, 2));

        foreach ($target_contracts as $contract) {
            $contract = strtolower(trim((string)$contract));
            if (!preg_match('/^0x[a-f0-9]{40}$/', $contract)) continue;
            $logs = self::rpcCall($rpc, 'eth_getLogs', [[
                'fromBlock' => $fromHex,
                'toBlock' => $toHex,
                'address' => $contract,
                'topics' => [$topicTransfer, null, $toTopic]
            ]]);
            if (!is_array($logs) || empty($logs)) continue;
            usort($logs, function ($a, $b) {
                $ab = hexdec((string)($a['blockNumber'] ?? '0x0'));
                $bb = hexdec((string)($b['blockNumber'] ?? '0x0'));
                return $bb <=> $ab;
            });
            foreach ($logs as $log) {
                $txHash = (string)($log['transactionHash'] ?? '');
                $bnHex = (string)($log['blockNumber'] ?? '');
                $dataHex = (string)($log['data'] ?? '');
                if (!preg_match('/^0x[a-fA-F0-9]{64}$/', $txHash)) continue;
                if (!preg_match('/^0x[0-9a-fA-F]+$/', $bnHex)) continue;
                if (!preg_match('/^0x[0-9a-fA-F]+$/', $dataHex)) continue;

                $blk = self::rpcCall($rpc, 'eth_getBlockByNumber', [$bnHex, false]);
                $tsHex = is_array($blk) ? (string)($blk['timestamp'] ?? '') : '';
                if (!preg_match('/^0x[0-9a-fA-F]+$/', $tsHex)) continue;
                $timeStamp = hexdec($tsHex);
                if ($timeStamp < ((int)$min_timestamp - 300)) continue;

                $raw = ltrim(substr($dataHex, 2), '0');
                if ($raw === '') $raw = '0';
                $amount = hexdec($raw) / pow(10, max(0, (int)$defaultDecimals));
                if (abs($amount - (float)$expected_amount) < 0.0001) {
                    return [
                        'hash' => $txHash,
                        'amount' => $amount,
                        'time' => $timeStamp
                    ];
                }
            }
        }
        return false;
    }

    private static function getEvmRpcUrl($chain_key)
    {
        $key = strtolower(trim((string)$chain_key));
        try {
            if (class_exists('Database')) {
                $db = Database::getInstance();
                $row = $db->fetch("SELECT value FROM system_settings WHERE key_name = ?", ['rpc_url_' . $key]);
                if ($row && !empty($row['value'])) {
                    return trim((string)$row['value']);
                }
            }
        } catch (Exception $e) {
            error_log("[CryptoService] RPC URL lookup failed for $key: " . $e->getMessage());
        }

        $map = [
            'eth' => 'https://rpc.ankr.com/eth',
            'bsc' => 'https://bsc-dataseed.binance.org',
            'polygon' => 'https://polygon-rpc.com',
            'arbitrum' => 'https://arb1.arbitrum.io/rpc',
            'optimism' => 'https://mainnet.optimism.io',
            'base' => 'https://mainnet.base.org',
            'avalanche' => 'https://api.avax.network/ext/bc/C/rpc'
        ];
        return $map[$key] ?? '';
    }

    private static function rpcCall($rpcUrl, $method, $params = [])
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => (string)$method,
            'params' => is_array($params) ? $params : []
        ];
        // Free public RPC calls — do not log, they are not billed by any paid API provider.
        $resp = self::curlPost($rpcUrl, $payload, ['Content-Type: application/json'], false);
        if (!is_array($resp) || array_key_exists('error', $resp)) return null;
        return $resp['result'] ?? null;
    }

    /**
     * 手动验证单笔交易哈希
     */
    public static function verifyHash($chain_key, $hash, $expected_to, $expected_amount, $min_timestamp = 0) {
        global $chains_config;
        $hash = trim($hash);

        if ($chain_key === 'solana') {
            return self::checkSolanaHash($hash, $expected_to, $expected_amount);
        }

        if ($chain_key === 'trc20') {
            // TronScan API
            $url = "https://apilist.tronscanapi.com/api/transaction-info?hash=" . $hash;
            $data = self::curlGet($url);

            if (isset($data['contractRet']) && $data['contractRet'] === 'SUCCESS' && $data['confirmed']) {
                // Reject transactions older than order creation (5-minute tolerance)
                if ($min_timestamp > 0 && isset($data['timestamp'])) {
                    $txTime = (int)((int)$data['timestamp'] / 1000);
                    if ($txTime > 0 && $txTime < ($min_timestamp - 300)) {
                        return false;
                    }
                }
                $contract = $chains_config['trc20']['contract'];
                if (isset($data['trc20TransferInfo'][0])) {
                    $trc20 = $data['trc20TransferInfo'][0];
                    if ($trc20['to_address'] === $expected_to && $trc20['contract_address'] === $contract) {
                        $amount = (float)$trc20['amount_str'] / 1000000;
                        if (abs($amount - (float)$expected_amount) < 0.0001) return $amount;
                    }
                }
            }
        } else {
            // EVM Chains
            if (!isset($chains_config[$chain_key])) return false;
            $config = $chains_config[$chain_key];
            $chain_id = $config['chain_id'];

            // 获取 API Key
            $api_key = defined('ETHERSCAN_API_KEY') ? ETHERSCAN_API_KEY : '';
            if (!$api_key || $api_key === 'YOUR_ETHERSCAN_KEY') {
                require_once __DIR__ . '/../Core/Database.php';
                try {
                    $db = Database::getInstance();
                    $row = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'eth_api_key'");
                    if ($row && !empty($row['value'])) $api_key = $row['value'];
                } catch (Exception $e) {
                    error_log("[CryptoService] eth_api_key lookup failed (verifyHash): " . $e->getMessage());
                }
            }

            // 使用 eth_getTransactionReceipt 获取 Logs
            $url = "https://api.etherscan.io/v2/api?chainid={$chain_id}&module=proxy&action=eth_getTransactionReceipt&txhash={$hash}&apikey={$api_key}";
            $data = self::curlGet($url);

            if (isset($data['result']) && isset($data['result']['status']) && $data['result']['status'] === '0x1') {
                $receipt = $data['result'];

                // Validate block timestamp against order creation time
                if ($min_timestamp > 0) {
                    $blockNum = (string)($receipt['blockNumber'] ?? '');
                    if (preg_match('/^0x[0-9a-fA-F]+$/', $blockNum)) {
                        $blockUrl = "https://api.etherscan.io/v2/api?chainid={$chain_id}&module=proxy&action=eth_getBlockByNumber&tag={$blockNum}&boolean=false&apikey={$api_key}";
                        $blockData = self::curlGet($blockUrl);
                        $tsHex = (string)($blockData['result']['timestamp'] ?? '');
                        if (preg_match('/^0x[0-9a-fA-F]+$/', $tsHex)) {
                            $txTime = hexdec($tsHex);
                            if ($txTime > 0 && $txTime < ($min_timestamp - 300)) {
                                return false;
                            }
                        }
                    }
                }

                $logs = $receipt['logs'] ?? [];

                // USDT Transfer Event
                $transfer_topic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

                // Expected To Address (Pad to 32 bytes)
                $clean_to = strtolower(str_replace('0x', '', $expected_to));
                $padded_to = '0x000000000000000000000000' . $clean_to;

                foreach ($logs as $log) {
                    // Check Contract Address
                    $is_valid_contract = false;
                    if (isset($config['usdt']) && is_array($config['usdt'])) {
                        foreach ($config['usdt'] as $c) {
                            if (strcasecmp($log['address'], $c) === 0) {
                                $is_valid_contract = true;
                                break;
                            }
                        }
                    }
                    if (!$is_valid_contract) continue;

                    // Check Topics
                    if (isset($log['topics'][0]) && $log['topics'][0] === $transfer_topic &&
                        isset($log['topics'][2]) && strcasecmp($log['topics'][2], $padded_to) === 0) {

                        // Parse Amount
                        $hex_amount = str_replace('0x', '', $log['data']);
                        $amount_wei = hexdec($hex_amount);
                        $decimals = $config['decimals'] ?? 18;
                        $amount = $amount_wei / pow(10, $decimals);

                        if (abs($amount - (float)$expected_amount) < 0.0001) return $amount;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check Solana Hash specifically using RPC
     */
    private static function checkSolanaHash($hash, $expected_to, $expected_amount) {
        $rpc = self::getSolanaRpcUrl();
        $usdt_mint = 'Es9vMFrzaCERmJfrF4H2FYD4KCoNkY11McCe8BenwNYB';

        $txPayload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'getTransaction',
            'params' => [
                $hash,
                ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0]
            ]
        ];
        
        $txResp = self::curlPost($rpc, $txPayload);
        
        if (!isset($txResp['result']) || !$txResp['result']) return false;
        
        $tx = $txResp['result'];
        if (isset($tx['meta']['err']) && $tx['meta']['err']) return false; // Failed tx
        
        $meta = $tx['meta'];
        $change = self::calculateSolanaTokenChange($meta, $expected_to, $usdt_mint);
        
        if ($change > 0 && abs($change - $expected_amount) < 0.0001) {
            return $change;
        }
        
        return false;
    }

    private static function detectApiName($url, $data = null): string
    {
        if (is_array($data) && !empty($data['method'])) {
            return substr((string)$data['method'], 0, 100);
        }

        $query = [];
        parse_str((string)(parse_url((string)$url, PHP_URL_QUERY) ?? ''), $query);
        $module = trim((string)($query['module'] ?? ''));
        $action = trim((string)($query['action'] ?? ''));
        if ($module !== '' || $action !== '') {
            $name = trim($module . ($action !== '' ? ('.' . $action) : ''), '.');
            if ($name !== '') {
                return substr($name, 0, 100);
            }
        }

        $path = trim((string)(parse_url((string)$url, PHP_URL_PATH) ?? ''), '/');
        if ($path !== '') {
            $path = str_replace('/', '.', $path);
            return substr($path, 0, 100);
        }

        return 'external.request';
    }

    private static function detectApiProvider($url): string
    {
        return substr(strtolower((string)(parse_url((string)$url, PHP_URL_HOST) ?? 'unknown')), 0, 100);
    }

    private static function queueApiRequestLog($url, $statusCode, $data = null): void
    {
        self::ensureApiRequestSchema();
        if (!self::$apiRequestSchemaEnsured) {
            return;
        }
        $ctx = is_array(self::$externalUsageContext) ? self::$externalUsageContext : [];
        $statusCode = max(0, (int)$statusCode);
        $billable = ($statusCode >= 200 && $statusCode < 300) ? 1 : 0;
        $createdAt = date('Y-m-d H:i:s');
        $apiProvider = self::detectApiProvider((string)$url);
        $apiName = self::detectApiName((string)$url, $data);
        $requestMethod = strtoupper(is_array($data) && !empty($data['method']) ? 'POST' : 'GET');
        $targetPath = substr((string)(parse_url((string)$url, PHP_URL_PATH) ?? ''), 0, 255);

        try {
            require_once __DIR__ . '/../Core/Database.php';
            $db = Database::getInstance();
            $db->query(
                "INSERT INTO external_request_logs
                 (user_id, order_id, order_no, chain, source, trigger_mode, request_method, request_type, target_host, target_path, status_code, success, error_message, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?)",
                [
                    isset($ctx['user_id']) ? (int)$ctx['user_id'] : null,
                    isset($ctx['order_id']) ? (int)$ctx['order_id'] : null,
                    isset($ctx['order_no']) ? (string)$ctx['order_no'] : null,
                    isset($ctx['chain']) ? substr((string)$ctx['chain'], 0, 32) : '',
                    isset($ctx['source']) ? substr((string)$ctx['source'], 0, 50) : '',
                    isset($ctx['trigger_mode']) ? substr((string)$ctx['trigger_mode'], 0, 20) : '',
                    $requestMethod,
                    $apiName,
                    $apiProvider,
                    $targetPath,
                    $statusCode,
                    $billable,
                    $createdAt,
                ]
            );
        } catch (Throwable $ignore) {
            error_log("[CryptoService] logExternalRequest insert failed: " . $ignore->getMessage());
        }
    }

    public static function flushApiRequestLogs(): void
    {
        return;
    }

    private static function request($method, $url, $data = null, $headers = [], $timeout = 15, bool $log = true): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; HTTPClient/1.0)');
        $httpMethod = strtoupper((string)$method);
        if ($httpMethod === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $headers[] = 'Content-Type: application/json';
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, array_values(array_unique($headers)));
        }
        $resp = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($log) {
            self::queueApiRequestLog((string)$url, $statusCode, $data);
        }
        return [
            'status_code' => $statusCode,
            'body' => $resp === false ? '' : (string)$resp,
            'json' => json_decode($resp === false ? '' : (string)$resp, true),
        ];
    }

    private static function curlGet($url, $headers = []) {
        $resp = self::request('GET', (string)$url, null, $headers, 10);
        return $resp['json'];
    }

    private static function curlPost($url, $data, $headers = [], bool $log = true) {
        $resp = self::request('POST', (string)$url, $data, $headers, 15, $log);
        return $resp['json'];
    }
}
