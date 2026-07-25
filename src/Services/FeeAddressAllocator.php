<?php

class FeeAddressAllocator
{
    public static function loadSettings($db)
    {
        $rows = $db->fetchAll("SELECT key_name, value FROM system_settings");
        $cfg = [];
        foreach ($rows as $r) {
            $cfg[$r['key_name']] = $r['value'];
        }
        return $cfg;
    }

    public static function resolveChargeWallet($db, $orderNo, $purpose, $payerUserId, $preferredChain = null, $cfg = null)
    {
        self::ensureAllocatorTables($db);

        if (!is_array($cfg)) {
            $cfg = self::loadSettings($db);
        }

        $mode = strtolower(trim((string)($cfg['admin_fee_address_mode'] ?? 'fixed')));
        if ($mode !== 'derived') {
            $mode = 'fixed';
        }

        if ($mode === 'derived') {
            return self::resolveDerivedWallet($db, $orderNo, $purpose, $payerUserId, $preferredChain, $cfg);
        }

        return self::resolveFixedWallet($db, $preferredChain, $cfg);
    }

    private static function resolveFixedWallet($db, $preferredChain, $cfg)
    {
        $chain = self::normalizeChain($preferredChain ?: ($cfg['payment_collection_chain'] ?? 'trc20'));
        $address = self::fixedAddressForChain($chain, $cfg);
        if ($address === '') {
            $fallbackChains = ['trc20', 'solana', 'bsc', 'eth', 'polygon', 'arbitrum', 'optimism', 'base', 'avalanche'];
            foreach ($fallbackChains as $fc) {
                $fa = self::fixedAddressForChain($fc, $cfg);
                if ($fa !== '') {
                    $chain = $fc;
                    $address = $fa;
                    break;
                }
            }
        }
        if ($address === '') {
            throw new Exception('固定收款地址未配置，请在管理员后台支付配置中完善。');
        }

        $adminId = self::getAdminId($db);
        $wallet = $db->fetch("SELECT id, address FROM wallets WHERE user_id = ? AND chain = ? AND address = ? LIMIT 1", [$adminId, $chain, $address]);
        if ($wallet) {
            return [
                'mode' => 'fixed',
                'chain' => $chain,
                'address' => $wallet['address'],
                'wallet_id' => (int)$wallet['id'],
                'derived_wallet_id' => null,
            ];
        }

        $db->query("INSERT INTO wallets (user_id, chain, address, status, created_at) VALUES (?, ?, ?, 1, NOW())", [$adminId, $chain, $address]);
        return [
            'mode' => 'fixed',
            'chain' => $chain,
            'address' => $address,
            'wallet_id' => (int)$db->lastInsertId(),
            'derived_wallet_id' => null,
        ];
    }

    private static function resolveDerivedWallet($db, $orderNo, $purpose, $payerUserId, $preferredChain, $cfg)
    {
        $planId = self::getUserPlanId($db, (int)$payerUserId);
        $chain = self::normalizeChain($preferredChain ?: ($cfg['payment_collection_chain'] ?? 'bsc'));
        if (!self::isEvmChain($chain) || !self::isDerivedEnabledChain($db, $chain, $planId)) {
            $chain = self::findAvailableDerivedChain($db, [], $planId);
            if ($chain === '') {
                throw new Exception('当前没有可用的派生收款网络。请在【套餐与链管理】开启至少一条 EVM 派生网络。');
            }
        }

        // Preferred path: real-time derive one address for each order.
        // If realtime service is configured and chain xpub exists, enforce realtime mode (no fallback to old pool).
        $deriveError = '';
        $realtimeConfigured = self::isRealtimeDeriveConfiguredForChain($chain, $cfg, (int)$payerUserId);
        $fresh = self::tryGenerateDerivedWalletForOrder($db, $chain, $cfg, (int)$payerUserId, $deriveError);
        if ($fresh) {
            return self::allocateWalletRow($db, $fresh, $chain, $orderNo, $purpose, $payerUserId);
        }
        if ($realtimeConfigured) {
            throw new Exception('实时派生地址失败：' . ($deriveError !== '' ? $deriveError : '未知错误'));
        }

        $maxRetries = 3;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            $w = $db->fetch(
                "SELECT w.*
                 FROM admin_derived_wallets w
                 LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
                 WHERE w.chain_slug = ? AND w.status = 1 AND a.id IS NULL
                 ORDER BY w.id ASC
                 LIMIT 1",
                [$chain]
            );

            if (!$w) {
                $fallbackChain = self::findAvailableDerivedChain($db, [$chain], $planId);
                if ($fallbackChain !== '') {
                    $chain = $fallbackChain;
                    $w = $db->fetch(
                        "SELECT w.*
                         FROM admin_derived_wallets w
                         LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
                         WHERE w.chain_slug = ? AND w.status = 1 AND a.id IS NULL
                         ORDER BY w.id ASC
                         LIMIT 1",
                        [$chain]
                    );
                }
            }

            if (!$w) {
                if ($deriveError !== '') {
                    throw new Exception('实时派生地址失败：' . $deriveError . '；且地址池无可用地址。');
                }
                throw new Exception('派生地址池不足，请先在【派生管理】页面补充地址池。');
            }

            try {
                return self::allocateWalletRow($db, $w, $chain, $orderNo, $purpose, $payerUserId);
            } catch (Throwable $e) {
                if ($attempt === $maxRetries - 1) {
                    throw $e;
                }
                error_log("[FeeAddressAllocator] Allocation race on wallet #{$w['id']}, retrying (attempt " . ($attempt + 1) . ")");
            }
        }
    }

    private static function allocateWalletRow($db, $walletRow, $chain, $orderNo, $purpose, $payerUserId)
    {
        $w = $walletRow;
        if (!$w || empty($w['id']) || empty($w['address'])) {
            throw new Exception('待分配地址无效');
        }

        $adminId = self::getAdminId($db);
        $address = trim((string)$w['address']);

        $wallet = $db->fetch("SELECT id FROM wallets WHERE user_id = ? AND chain = ? AND address = ? LIMIT 1", [$adminId, $chain, $address]);
        if ($wallet) {
            $walletTableId = (int)$wallet['id'];
        } else {
            $db->query("INSERT INTO wallets (user_id, chain, address, status, created_at) VALUES (?, ?, ?, 1, NOW())", [$adminId, $chain, $address]);
            $walletTableId = (int)$db->lastInsertId();
        }

        $db->query(
            "INSERT INTO admin_fee_address_allocations (wallet_id, wallet_table_id, chain_slug, address, order_no, purpose, allocated_to_user_id, allocated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [(int)$w['id'], $walletTableId, $chain, $address, $orderNo, $purpose, $payerUserId]
        );

        return [
            'mode' => 'derived',
            'chain' => $chain,
            'address' => $address,
            'wallet_id' => $walletTableId,
            'derived_wallet_id' => (int)$w['id'],
        ];
    }

    private static function tryGenerateDerivedWalletForOrder($db, $chain, $cfg, $payerUserId = 0, &$deriveError = '')
    {
        $deriveError = '';
        $chain = strtolower(trim((string)$chain));
        $xpubCtx = self::getScopedSettingCtx($db, $cfg, 'sweep_xpub_' . $chain, (int)$payerUserId, '');
        $xpub = $xpubCtx['value'];
        if ($xpub === '') {
            $deriveError = '该链未配置 xpub';
            return null;
        }

        $pathCtx = self::getScopedSettingCtx($db, $cfg, 'sweep_path_' . $chain, (int)$payerUserId, "m/44'/60'/0'/0");
        $pathPrefix = $pathCtx['value'];
        if ($pathPrefix === '') {
            $pathPrefix = "m/44'/60'/0'/0";
        }

        $serviceUrl = trim((string)($cfg['derived_addr_service_url'] ?? getenv('DERIVED_ADDR_SERVICE_URL') ?: 'http://127.0.0.1:8787'));
        $serviceToken = trim((string)($cfg['derived_addr_service_token'] ?? getenv('DERIVED_ADDR_SERVICE_TOKEN') ?: ''));
        $serviceTimeout = (int)($cfg['derived_addr_service_timeout'] ?? getenv('DERIVED_ADDR_SERVICE_TIMEOUT') ?: 5);
        if ($serviceTimeout < 2) $serviceTimeout = 2;
        if ($serviceTimeout > 15) $serviceTimeout = 15;

        if ($serviceUrl === '') {
            $deriveError = '未配置实时派生服务地址';
            return null;
        }

        $nextCtx = self::getScopedSettingCtx($db, $cfg, 'sweep_next_index_' . $chain, (int)$payerUserId, 0);
        $nextKey = trim((string)($nextCtx['key'] ?? ''));
        if ($nextKey === '') {
            $nextKey = self::scopedSettingKey('sweep_next_index_' . $chain, (int)$payerUserId);
        }
        $xpubHint = strlen($xpub) > 16 ? (substr($xpub, 0, 10) . '...' . substr($xpub, -6)) : $xpub;
        $baseIndex = (int)$nextCtx['value'];
        if ($baseIndex < 0) {
            $baseIndex = 0;
        }

        for ($i = 0; $i < 256; $i++) {
            $index = self::readSettingInt($db, $nextKey, $baseIndex + $i);
            if ($index < 0) $index = 0;
            try {
                $derived = self::callDerivedAddressService($serviceUrl, $serviceToken, $chain, $xpub, $index, $pathPrefix, $serviceTimeout);
                $address = trim((string)($derived['address'] ?? ''));
                $path = trim((string)($derived['path'] ?? ($pathPrefix . '/' . $index)));
                if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
                    throw new Exception('派生服务返回地址无效');
                }

                $db->query(
                    "INSERT INTO admin_derived_wallets (chain_slug, derivation_path, address, source_type, xpub_hint, status, created_at, updated_at)
                     VALUES (?, ?, ?, 'xpub_realtime', ?, 1, NOW(), NOW())",
                    [$chain, $path, strtolower($address), $xpubHint]
                );
                $wid = (int)$db->lastInsertId();
                self::upsertSetting($db, $nextKey, (string)($index + 1));
                return [
                    'id' => $wid,
                    'address' => strtolower($address),
                    'derivation_path' => $path
                ];
            } catch (Exception $e) {
                $msg = trim((string)$e->getMessage());
                $deriveError = $msg;
                $dup = stripos($msg, 'Duplicate') !== false || stripos($msg, 'duplicate') !== false || stripos($msg, 'UNIQUE') !== false;
                if ($dup) {
                    self::upsertSetting($db, $nextKey, (string)($index + 1));
                    continue;
                }
                return null;
            }
        }

        if ($deriveError === '') {
            $deriveError = '连续重试后仍未派生成功';
        }
        return null;
    }

    private static function isRealtimeDeriveConfiguredForChain($chain, $cfg, $payerUserId = 0)
    {
        $chain = strtolower(trim((string)$chain));
        $xpubCtx = self::getScopedSettingCtx(null, $cfg, 'sweep_xpub_' . $chain, (int)$payerUserId, '');
        $xpub = $xpubCtx['value'];
        $serviceUrl = trim((string)($cfg['derived_addr_service_url'] ?? getenv('DERIVED_ADDR_SERVICE_URL') ?: ''));
        return $xpub !== '' && $serviceUrl !== '';
    }

    private static function getScopedSettingCtx($db, $cfg, $baseKey, $userId, $default = '')
    {
        $uid = (int)$userId;
        $userScoped = self::scopedSettingKey($baseKey, $uid);
        if (isset($cfg[$userScoped]) && trim((string)$cfg[$userScoped]) !== '') {
            return ['value' => trim((string)$cfg[$userScoped]), 'key' => $userScoped];
        }
        if (isset($cfg[$baseKey]) && trim((string)$cfg[$baseKey]) !== '') {
            return ['value' => trim((string)$cfg[$baseKey]), 'key' => $baseKey];
        }

        $adminScoped = '';
        try {
            if ($db) {
                $adminId = self::getAdminId($db);
                if ($adminId > 0) {
                    $adminScoped = self::scopedSettingKey($baseKey, $adminId);
                }
            }
        } catch (Exception $e) {
            $adminScoped = '';
        }
        if ($adminScoped !== '' && isset($cfg[$adminScoped]) && trim((string)$cfg[$adminScoped]) !== '') {
            return ['value' => trim((string)$cfg[$adminScoped]), 'key' => $adminScoped];
        }
        return ['value' => (string)$default, 'key' => $userScoped];
    }

    private static function scopedSettingKey($baseKey, $userId)
    {
        $uid = (int)$userId;
        if ($uid <= 0) {
            return (string)$baseKey;
        }
        return (string)$baseKey . '_u' . $uid;
    }

    private static function getScopedSetting($cfg, $baseKey, $userId, $default = '')
    {
        $scoped = self::scopedSettingKey($baseKey, $userId);
        if (isset($cfg[$scoped]) && trim((string)$cfg[$scoped]) !== '') {
            return trim((string)$cfg[$scoped]);
        }
        if (isset($cfg[$baseKey]) && trim((string)$cfg[$baseKey]) !== '') {
            return trim((string)$cfg[$baseKey]);
        }
        return (string)$default;
    }

    private static function callDerivedAddressService($serviceUrl, $serviceToken, $chain, $xpub, $index, $pathPrefix, $timeoutSec = 5)
    {
        $base = rtrim((string)$serviceUrl, '/');
        if ($base === '') {
            throw new Exception('派生服务地址为空');
        }
        $url = $base . '/v1/derive';
        $payload = json_encode([
            'chain' => (string)$chain,
            'xpub' => (string)$xpub,
            'index' => (int)$index,
            'path_prefix' => (string)$pathPrefix,
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        if (trim((string)$serviceToken) !== '') {
            $headers[] = 'X-Api-Key: ' . trim((string)$serviceToken);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeoutSec);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new Exception($err !== '' ? $err : '连接派生服务失败');
        }
        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new Exception('派生服务返回格式无效');
        }
        if ($http !== 200 || empty($data['ok'])) {
            $m = trim((string)($data['message'] ?? '派生服务错误'));
            if ($m === '') $m = '派生服务错误';
            throw new Exception($m);
        }
        return [
            'address' => (string)($data['address'] ?? ''),
            'path' => (string)($data['path'] ?? '')
        ];
    }

    private static function readSettingInt($db, $key, $default = 0)
    {
        $row = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [$key]);
        if ($row && isset($row['value'])) {
            return (int)$row['value'];
        }
        return (int)$default;
    }

    private static function upsertSetting($db, $key, $value)
    {
        $exists = $db->fetch("SELECT key_name FROM system_settings WHERE key_name = ? LIMIT 1", [$key]);
        if ($exists) {
            $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [(string)$value, (string)$key]);
        } else {
            $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [(string)$key, (string)$value]);
        }
    }

    private static function getAdminId($db)
    {
        $admin = $db->fetch("SELECT id FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        if (!$admin) {
            throw new Exception('系统未检测到管理员账户。');
        }
        return (int)$admin['id'];
    }

    private static function normalizeChain($chain)
    {
        $c = strtolower(trim((string)$chain));
        if ($c === '') {
            return 'trc20';
        }
        return $c;
    }

    private static function isEvmChain($chain)
    {
        return in_array($chain, ['eth', 'bsc', 'polygon', 'optimism', 'arbitrum', 'base', 'avalanche'], true);
    }

    private static function fixedAddressForChain($chain, $cfg)
    {
        if ($chain === 'trc20') {
            return trim((string)($cfg['usdt_admin_wallet'] ?? ''));
        }
        if ($chain === 'solana') {
            return trim((string)($cfg['usdt_admin_wallet_sol'] ?? ''));
        }
        if (self::isEvmChain($chain)) {
            return trim((string)($cfg['usdt_admin_wallet_evm'] ?? ''));
        }
        return '';
    }

    private static function findAvailableDerivedChain($db, $exclude = [], $planId = 0)
    {
        $exclude = array_values(array_filter(array_map('strtolower', $exclude)));
        $planId = (int)$planId;
        if ($planId > 0) {
            $rows = $db->fetchAll(
                "SELECT w.chain_slug, COUNT(*) AS c
                 FROM admin_derived_wallets w
                 INNER JOIN chains c2 ON c2.slug = w.chain_slug
                 INNER JOIN plan_chains pc ON pc.chain_id = c2.id AND pc.plan_id = ?
                 LEFT JOIN plan_chain_derived pcd ON pcd.plan_id = pc.plan_id AND pcd.chain_id = pc.chain_id
                 LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
                 WHERE w.status = 1 AND a.id IS NULL
                   AND c2.status = 1
                   AND COALESCE(c2.allow_derived, 1) = 1
                   AND COALESCE(pcd.enabled, 1) = 1
                 GROUP BY w.chain_slug
                 ORDER BY c DESC, w.chain_slug ASC",
                [$planId]
            );
        } else {
            $rows = $db->fetchAll(
                "SELECT w.chain_slug, COUNT(*) AS c
                 FROM admin_derived_wallets w
                 INNER JOIN chains c2 ON c2.slug = w.chain_slug
                 LEFT JOIN admin_fee_address_allocations a ON a.wallet_id = w.id
                 WHERE w.status = 1 AND a.id IS NULL
                   AND c2.status = 1
                   AND COALESCE(c2.allow_derived, 1) = 1
                 GROUP BY w.chain_slug
                 ORDER BY c DESC, w.chain_slug ASC"
            );
        }
        foreach ($rows as $r) {
            $slug = strtolower((string)($r['chain_slug'] ?? ''));
            if ($slug === '' || !self::isEvmChain($slug)) {
                continue;
            }
            if (in_array($slug, $exclude, true)) {
                continue;
            }
            if ((int)($r['c'] ?? 0) > 0) {
                return $slug;
            }
        }
        return '';
    }

    private static function isDerivedEnabledChain($db, $chain, $planId = 0)
    {
        $slug = strtolower(trim((string)$chain));
        if ($slug === '' || !self::isEvmChain($slug)) {
            return false;
        }
        $row = $db->fetch(
            "SELECT status, COALESCE(allow_derived, 1) AS allow_derived
             FROM chains
             WHERE slug = ?
             LIMIT 1",
            [$slug]
        );
        if (!$row) {
            return false;
        }
        if (!((int)($row['status'] ?? 0) === 1 && (int)($row['allow_derived'] ?? 1) === 1)) {
            return false;
        }
        $planId = (int)$planId;
        if ($planId <= 0) {
            return true;
        }
        $planRow = $db->fetch(
            "SELECT pc.chain_id, COALESCE(pcd.enabled, 1) AS enabled
             FROM plan_chains pc
             INNER JOIN chains c ON c.id = pc.chain_id
             LEFT JOIN plan_chain_derived pcd ON pcd.plan_id = pc.plan_id AND pcd.chain_id = pc.chain_id
             WHERE pc.plan_id = ? AND c.slug = ?
             LIMIT 1",
            [$planId, $slug]
        );
        return $planRow ? ((int)($planRow['enabled'] ?? 1) === 1) : false;
    }

    private static function getUserPlanId($db, $userId)
    {
        $uid = (int)$userId;
        if ($uid <= 0) {
            return 0;
        }
        $row = $db->fetch("SELECT plan_id FROM users WHERE id = ? LIMIT 1", [$uid]);
        return (int)($row['plan_id'] ?? 0);
    }

    private static function ensureAllocatorTables($db)
    {
        $db->query("CREATE TABLE IF NOT EXISTS admin_fee_address_allocations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            wallet_id INT NOT NULL,
            wallet_table_id INT DEFAULT NULL,
            chain_slug VARCHAR(32) NOT NULL,
            address VARCHAR(100) NOT NULL,
            order_no VARCHAR(32) DEFAULT NULL,
            purpose VARCHAR(40) DEFAULT 'merchant_fee',
            allocated_to_user_id INT DEFAULT NULL,
            allocated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_wallet_once (wallet_id),
            UNIQUE KEY uniq_order_no (order_no),
            INDEX idx_chain (chain_slug),
            INDEX idx_user (allocated_to_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->query("CREATE TABLE IF NOT EXISTS plan_chain_derived (
            id INT AUTO_INCREMENT PRIMARY KEY,
            plan_id INT NOT NULL,
            chain_id INT NOT NULL,
            enabled TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_plan_chain (plan_id, chain_id),
            INDEX idx_plan (plan_id),
            INDEX idx_chain (chain_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
