<?php

require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/NotificationDispatcher.php';
require_once __DIR__ . '/BinancePayService.php';

class ReferralService
{
    public static function rateForUser($db, int $userId): float
    {
        $globalRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'referral_rate' LIMIT 1");
        $global = max(0.0, (float)($globalRow['value'] ?? 10.0));
        if ($userId <= 0) {
            return $global;
        }
        $userRow = $db->fetch("SELECT referral_rate_override FROM users WHERE id = ? LIMIT 1", [$userId]);
        if ($userRow && $userRow['referral_rate_override'] !== null && $userRow['referral_rate_override'] !== '') {
            return max(0.0, (float)$userRow['referral_rate_override']);
        }
        return $global;
    }

    public static function ensureSchema($db): void
    {
        try {
            $db->query("ALTER TABLE users ADD COLUMN referral_rate_override DECIMAL(8,4) DEFAULT NULL");
        } catch (Throwable $ignore) {
            error_log("[ReferralService] DDL referral_rate_override: " . $ignore->getMessage());
        }

        $db->query("CREATE TABLE IF NOT EXISTS referral_earnings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            source_order_id INT NOT NULL,
            amount DECIMAL(20,6) NOT NULL DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'USDT',
            rate DECIMAL(8,4) DEFAULT 0,
            status VARCHAR(20) DEFAULT 'available',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            UNIQUE KEY uniq_referral_order (user_id, source_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $db->query("ALTER TABLE referral_earnings ADD COLUMN status VARCHAR(20) DEFAULT 'available'");
        } catch (Throwable $ignore) {
            error_log("[ReferralService] DDL referral_earnings.status: " . $ignore->getMessage());
        }
        try {
            $db->query("ALTER TABLE referral_earnings MODIFY source_order_id INT NOT NULL");
        } catch (Throwable $ignore) {
            error_log("[ReferralService] DDL modify source_order_id: " . $ignore->getMessage());
        }
        try {
            $db->query("ALTER TABLE referral_earnings ADD UNIQUE KEY uniq_referral_order (user_id, source_order_id)");
        } catch (Throwable $ignore) {
            error_log("[ReferralService] DDL uniq_referral_order: " . $ignore->getMessage());
        }

        $db->query("CREATE TABLE IF NOT EXISTS referral_withdrawals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            amount DECIMAL(20,6) NOT NULL DEFAULT 0,
            currency VARCHAR(10) DEFAULT 'USDT',
            method VARCHAR(20) NOT NULL DEFAULT 'balance',
            target_account VARCHAR(191) DEFAULT NULL,
            target_network VARCHAR(30) DEFAULT NULL,
            audit_status VARCHAR(20) DEFAULT 'pending',
            payout_status VARCHAR(20) DEFAULT 'pending',
            status VARCHAR(20) DEFAULT 'pending',
            review_note VARCHAR(255) DEFAULT NULL,
            reviewed_by INT DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            tx_ref VARCHAR(120) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_created (user_id, created_at),
            INDEX idx_status (status, audit_status, payout_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public static function grantForOrder($db, int $orderId): bool
    {
        self::ensureSchema($db);
        $order = $db->fetch("SELECT id, user_id, amount, currency, status, source FROM orders WHERE id = ? LIMIT 1", [$orderId]);
        if (!$order || strtolower((string)($order['status'] ?? '')) !== 'paid') {
            return false;
        }
        $source = strtolower((string)($order['source'] ?? ''));
        if (in_array($source, ['recharge', 'upgrade', 'dashboard_test'], true)) {
            return false;
        }

        $buyer = $db->fetch("SELECT ref_by FROM users WHERE id = ? LIMIT 1", [(int)$order['user_id']]);
        $referrerId = (int)($buyer['ref_by'] ?? 0);
        if ($referrerId <= 0) {
            return false;
        }

        $exists = $db->fetch(
            "SELECT id FROM referral_earnings WHERE user_id = ? AND source_order_id = ? LIMIT 1",
            [$referrerId, (int)$order['id']]
        );
        if ($exists) {
            return false;
        }

        $rate = self::rateForUser($db, $referrerId);
        if ($rate <= 0) {
            return false;
        }

        $commission = round((float)$order['amount'] * ($rate / 100), 6);
        if ($commission <= 0) {
            return false;
        }

        $db->query(
            "INSERT INTO referral_earnings (user_id, source_order_id, amount, currency, rate, status, created_at)
             VALUES (?, ?, ?, ?, ?, 'available', NOW())",
            [$referrerId, (int)$order['id'], $commission, (string)($order['currency'] ?? 'USDT'), $rate]
        );
        return true;
    }

    public static function availableAmount($db, int $userId): float
    {
        self::ensureSchema($db);
        $earn = $db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS s
             FROM referral_earnings
             WHERE user_id = ? AND status = 'available'",
            [$userId]
        );
        $used = $db->fetch(
            "SELECT COALESCE(SUM(amount), 0) AS s
             FROM referral_withdrawals
             WHERE user_id = ? AND status IN ('pending', 'approved', 'paid')",
            [$userId]
        );
        return max(0.0, (float)($earn['s'] ?? 0) - (float)($used['s'] ?? 0));
    }

    public static function submitWithdrawal($db, int $userId, array $input): array
    {
        self::ensureSchema($db);
        $amount = round((float)($input['amount'] ?? 0), 6);
        $method = strtolower(trim((string)($input['method'] ?? 'balance')));
        $currency = strtoupper(trim((string)($input['currency'] ?? 'USDT')));
        if ($currency === '') {
            $currency = 'USDT';
        }
        if ($amount <= 0) {
            throw new Exception('提现金额必须大于 0');
        }
        $available = self::availableAmount($db, $userId);
        if ($amount > $available) {
            throw new Exception('可提现收益不足');
        }
        if (!in_array($method, ['balance', 'binance', 'wallet'], true)) {
            throw new Exception('提现方式无效');
        }

        $user = $db->fetch("SELECT email, withdraw_address, withdraw_network, binance_uid FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            throw new Exception('用户不存在');
        }

        $targetAccount = '';
        $targetNetwork = null;
        if ($method === 'balance') {
            $targetAccount = (string)($user['email'] ?? '');
        } elseif ($method === 'binance') {
            $uid = trim((string)($user['binance_uid'] ?? ''));
            if ($uid === '') {
                throw new Exception('未绑定 Binance UID，请先到个人设置中绑定');
            }
            $targetAccount = $uid;
        } else {
            $addr = trim((string)($user['withdraw_address'] ?? ''));
            if ($addr === '') {
                throw new Exception('未设置提现地址，请先到个人设置中配置');
            }
            $targetAccount = $addr;
            $targetNetwork = strtoupper(trim((string)($user['withdraw_network'] ?? 'TRC20')));
        }

        $db->query(
            "INSERT INTO referral_withdrawals
             (user_id, amount, currency, method, target_account, target_network, audit_status, payout_status, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', 'pending', 'pending', NOW(), NOW())",
            [$userId, $amount, $currency, $method, $targetAccount, $targetNetwork]
        );

        NotificationDispatcher::notifyUser($userId, [
            'type' => 'balance',
            'in_app_type' => 'balance',
            'title' => '邀请收益提现申请已提交',
            'content' => "提现金额：{$amount} {$currency}\n提现方式：" . self::methodLabel($method) . "\n状态：待审核",
            'subject' => '邀请收益提现申请已提交',
        ]);

        return ['ok' => true];
    }

    public static function reviewWithdrawal($db, int $withdrawalId, int $adminId, string $decision, string $note = '', string $txRef = ''): array
    {
        self::ensureSchema($db);
        $row = $db->fetch("SELECT * FROM referral_withdrawals WHERE id = ? LIMIT 1", [$withdrawalId]);
        if (!$row) {
            throw new Exception('提现申请不存在');
        }
        if ((string)($row['status'] ?? '') !== 'pending' && !(($decision === 'mark_paid') && (string)($row['status'] ?? '') === 'approved')) {
            throw new Exception('当前状态不可审核');
        }

        $uid = (int)$row['user_id'];
        $amount = (float)$row['amount'];
        $currency = (string)($row['currency'] ?? 'USDT');
        $method = (string)$row['method'];

        if ($decision === 'reject') {
            $db->query(
                "UPDATE referral_withdrawals
                 SET status='rejected', audit_status='rejected', review_note=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                 WHERE id = ?",
                [$note !== '' ? $note : '审核拒绝', $adminId, $withdrawalId]
            );
            NotificationDispatcher::notifyUser($uid, [
                'type' => 'balance',
                'in_app_type' => 'balance',
                'title' => '邀请收益提现审核未通过',
                'content' => "金额：{$amount} {$currency}\n方式：" . self::methodLabel($method) . "\n原因：" . ($note !== '' ? $note : '请联系管理员'),
                'subject' => '邀请收益提现审核未通过',
            ]);
            return ['status' => 'rejected'];
        }

        if ($decision === 'approve_pay_binance') {
            if ($method !== 'binance') {
                throw new Exception('仅 Binance 提现方式可使用“通过并立即打款”');
            }
            $cfg = BinancePayService::loadConfig($db);
            if (empty($cfg['enabled'])) {
                throw new Exception('Binance Pay 未启用，无法自动打款');
            }
            $to = trim((string)($row['target_account'] ?? ''));
            if ($to === '' || !ctype_digit($to)) {
                throw new Exception('Binance UID 无效，无法自动打款');
            }
            $payload = [
                'requestId' => 'RWD-' . date('YmdHis') . '-' . rand(1000, 9999),
                'toType' => 'BUID',
                'to' => $to,
                'currency' => strtoupper(trim((string)$currency)),
                'amount' => rtrim(rtrim(number_format($amount, 6, '.', ''), '0'), '.'),
                'memo' => 'Referral withdrawal #' . $withdrawalId,
            ];
            $resp = BinancePayService::request($cfg, '/binancepay/openapi/wallet/transfer', $payload, 'POST');
            if (!BinancePayService::isSuccess($resp)) {
                $code = (string)($resp['data']['code'] ?? '');
                $msg = (string)(($resp['data']['errorMessage'] ?? '') ?: ($resp['data']['msg'] ?? 'Binance 转账失败'));
                throw new Exception(($code !== '' ? '[' . $code . '] ' : '') . $msg);
            }
            $tx = (string)($resp['data']['data']['transactionId'] ?? $resp['data']['data']['bizId'] ?? $resp['data']['data']['requestId'] ?? '');
            $db->query(
                "UPDATE referral_withdrawals
                 SET status='paid', audit_status='approved', payout_status='completed', review_note=?, reviewed_by=?, reviewed_at=NOW(), paid_at=NOW(),
                     tx_ref = CASE WHEN ? <> '' THEN ? ELSE tx_ref END, updated_at=NOW()
                 WHERE id = ?",
                [$note !== '' ? $note : '审核通过并自动打款', $adminId, $tx, $tx, $withdrawalId]
            );
            NotificationDispatcher::notifyUser($uid, [
                'type' => 'balance',
                'in_app_type' => 'balance',
                'title' => '邀请收益提现已完成打款',
                'content' => "金额：{$amount} {$currency}\n方式：Binance 账户\n状态：已完成\n参考号：" . ($tx !== '' ? $tx : '-'),
                'subject' => '邀请收益提现已完成打款',
            ]);
            return ['status' => 'paid', 'tx_ref' => $tx];
        }

        if ($decision === 'mark_paid') {
            $db->query(
                "UPDATE referral_withdrawals
                 SET status='paid', payout_status='completed', tx_ref = CASE WHEN ? <> '' THEN ? ELSE tx_ref END, paid_at=NOW(), updated_at=NOW()
                 WHERE id = ?",
                [$txRef, $txRef, $withdrawalId]
            );
            NotificationDispatcher::notifyUser($uid, [
                'type' => 'balance',
                'in_app_type' => 'balance',
                'title' => '邀请收益提现已打款完成',
                'content' => "金额：{$amount} {$currency}\n方式：" . self::methodLabel($method) . "\n状态：已完成",
                'subject' => '邀请收益提现已打款完成',
            ]);
            return ['status' => 'paid'];
        }

        // approve
        if ($amount > self::availableAmount($db, $uid)) {
            throw new Exception('用户可提现收益不足，无法审核通过');
        }
        if ($method === 'balance') {
            $db->query("UPDATE users SET balance = balance + ? WHERE id = ?", [$amount, $uid]);
            $u = $db->fetch("SELECT balance FROM users WHERE id = ? LIMIT 1", [$uid]);
            $balanceAfter = (float)($u['balance'] ?? 0);
            $desc = '邀请收益提现入账';
            $db->query(
                "INSERT INTO transactions (user_id, type, amount, balance_after, description, status)
                 VALUES (?, 'recharge', ?, ?, ?, 'completed')",
                [$uid, $amount, $balanceAfter, $desc]
            );
            $db->query(
                "UPDATE referral_withdrawals
                 SET status='paid', audit_status='approved', payout_status='completed', review_note=?, reviewed_by=?, reviewed_at=NOW(), paid_at=NOW(), updated_at=NOW()
                 WHERE id = ?",
                [$note, $adminId, $withdrawalId]
            );
        } else {
            $db->query(
                "UPDATE referral_withdrawals
                 SET status='approved', audit_status='approved', payout_status='pending_manual', review_note=?, reviewed_by=?, reviewed_at=NOW(), updated_at=NOW()
                 WHERE id = ?",
                [$note, $adminId, $withdrawalId]
            );
        }

        NotificationDispatcher::notifyUser($uid, [
            'type' => 'balance',
            'in_app_type' => 'balance',
            'title' => '邀请收益提现审核通过',
            'content' => "金额：{$amount} {$currency}\n方式：" . self::methodLabel($method) . "\n状态：" . ($method === 'balance' ? '已入账余额' : '待打款'),
            'subject' => '邀请收益提现审核通过',
        ]);
        return ['status' => ($method === 'balance' ? 'paid' : 'approved')];
    }

    public static function methodLabel(string $method): string
    {
        if ($method === 'balance') return '站内余额';
        if ($method === 'binance') return 'Binance 账户';
        if ($method === 'wallet') return '个人钱包地址';
        return $method;
    }
}
