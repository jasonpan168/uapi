<?php

require_once __DIR__ . '/NotificationDispatcher.php';
require_once __DIR__ . '/CouponService.php';

class UpgradeOrderService
{
    public static function markPaidAndFulfill($db, int $orderId, string $txHash, string $chain = 'binance_pay', string $currency = 'USDT', array $meta = []): array
    {
        $order = $db->fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
        if (!$order) {
            throw new Exception('Order not found');
        }

        $payProvider = (string)($meta['pay_provider'] ?? (strtolower($chain) === 'stripe' ? 'stripe' : (strtolower($chain) === 'binance_pay' ? 'binance' : 'crypto')));
        $binancePayOrderId = (string)($meta['binance_pay_order_id'] ?? '');
        $binancePayerUid = (string)($meta['binance_payer_uid'] ?? '');
        $binanceOpenUserId = (string)($meta['binance_open_user_id'] ?? '');
        $binanceMerchantId = (string)($meta['binance_merchant_id'] ?? '');

        $updated = $db->query(
            "UPDATE orders
             SET status='paid',
                 pay_provider=?,
                 tx_hash=?,
                 chain=?,
                 currency=?,
                 paid_at=NOW(),
                 binance_pay_order_id=CASE WHEN ? <> '' THEN ? ELSE binance_pay_order_id END,
                 binance_payer_uid=CASE WHEN ? <> '' THEN ? ELSE binance_payer_uid END,
                 binance_open_user_id=CASE WHEN ? <> '' THEN ? ELSE binance_open_user_id END,
                 binance_merchant_id=CASE WHEN ? <> '' THEN ? ELSE binance_merchant_id END,
                 updated_at=NOW()
             WHERE id=? AND status='pending'",
            [
                $payProvider,
                $txHash,
                $chain,
                strtoupper($currency),
                $binancePayOrderId, $binancePayOrderId,
                $binancePayerUid, $binancePayerUid,
                $binanceOpenUserId, $binanceOpenUserId,
                $binanceMerchantId, $binanceMerchantId,
                $orderId
            ]
        );

        $justPaid = $updated->rowCount() > 0;
        if ($justPaid) {
            CouponService::countAdminRedemption($db, $order);
        }

        $order = $db->fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
        if ($order && strpos((string)($order['merchant_order_id'] ?? ''), 'PLAN-') === 0) {
            self::fulfillPlan($db, $order, $justPaid);
        }

        return [
            'updated' => $justPaid,
            'order' => $order,
        ];
    }

    private static function fulfillPlan($db, array $order, bool $justPaid): void
    {
        $parts = explode('-', (string)($order['merchant_order_id'] ?? ''));
        $planId = (int)($parts[1] ?? 0);
        $cycle = strtolower((string)($parts[2] ?? 'monthly'));
        self::applyPlanToUser($db, (int)($order['user_id'] ?? 0), $planId, $cycle, $justPaid);
    }

    public static function fulfillPlanFromOrder($db, array $order): void
    {
        if (strpos((string)($order['merchant_order_id'] ?? ''), 'PLAN-') !== 0) {
            return;
        }
        $parts = explode('-', (string)($order['merchant_order_id'] ?? ''));
        $planId = (int)($parts[1] ?? 0);
        $cycle = strtolower((string)($parts[2] ?? 'monthly'));
        self::applyPlanToUser($db, (int)($order['user_id'] ?? 0), $planId, $cycle, true);
    }

    public static function fulfillPlanDirect($db, int $userId, int $planId, string $cycle): void
    {
        self::applyPlanToUser($db, $userId, $planId, $cycle, true);
    }

    private static function applyPlanToUser($db, int $userId, int $planId, string $cycle, bool $justPaid): void
    {
        if ($planId <= 0 || $userId <= 0) {
            return;
        }

        $plan = $db->fetch("SELECT * FROM plans WHERE id = ? LIMIT 1", [$planId]);
        if (!$plan) {
            return;
        }

        $duration = '+1 month';
        if ($cycle === 'yearly') {
            $duration = '+1 year';
        } elseif ($cycle === 'quarterly') {
            $duration = '+3 months';
        }
        $fastSyncGrant = max(0, (int)($plan['fast_sync_limit'] ?? 0));

        $uRow = $db->fetch("SELECT plan_id, expire_at FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$uRow) {
            return;
        }

        $currentExpire = (!empty($uRow['expire_at']) && strtotime((string)$uRow['expire_at']) > time())
            ? (string)$uRow['expire_at']
            : date('Y-m-d H:i:s');
        $newExpire = date('Y-m-d H:i:s', strtotime($duration, strtotime($currentExpire)));

        if ($justPaid || (int)($uRow['plan_id'] ?? 0) !== $planId) {
            $db->query(
                "UPDATE users
                 SET plan_id=?, expire_at=?, fast_sync_remaining = COALESCE(fast_sync_remaining, 0) + ?
                 WHERE id=?",
                [$planId, $newExpire, $fastSyncGrant, $userId]
            );
        }
    }

    public static function applyRefund($db, int $orderId, float $refundAmount, string $refundRequestId = '', string $refundReason = ''): array
    {
        $order = $db->fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
        if (!$order) {
            throw new Exception('Order not found');
        }
        if ($refundRequestId !== '' && (string)($order['refund_request_id'] ?? '') === $refundRequestId) {
            return ['order' => $order, 'full_refund' => (strtolower((string)($order['refund_status'] ?? '')) === 'full'), 'duplicate' => true];
        }
        $orderAmount = (float)($order['amount'] ?? 0);
        if ($refundAmount <= 0) {
            throw new Exception('Refund amount must be positive');
        }
        $epsilon = 0.000001;
        $oldRefundAmount = (float)($order['refund_amount'] ?? 0);
        $newRefundAmountRaw = round($oldRefundAmount + $refundAmount, 6);
        $newRefundAmount = min($orderAmount, $newRefundAmountRaw);
        $isFull = ($newRefundAmount + $epsilon) >= $orderAmount;
        $refundStatus = $isFull ? 'full' : 'partial';
        $newOrderStatus = $isFull ? 'refunded' : (string)($order['status'] ?: 'paid');

        $db->query(
            "UPDATE orders
             SET refund_status = ?,
                 refund_amount = ?,
                 refund_count = COALESCE(refund_count, 0) + 1,
                 refund_request_id = CASE WHEN ? <> '' THEN ? ELSE refund_request_id END,
                 refund_reason = CASE WHEN ? <> '' THEN ? ELSE refund_reason END,
                 refunded_at = NOW(),
                 status = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $refundStatus,
                $newRefundAmount,
                $refundRequestId, $refundRequestId,
                $refundReason, $refundReason,
                $newOrderStatus,
                $orderId
            ]
        );

        if ($isFull && strtolower((string)($order['refund_status'] ?? '')) !== 'full' && self::isUpgradeOrder($order)) {
            self::rollbackUpgrade($db, $order);
        }

        $orderNew = $db->fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
        // Mark notification as sent atomically, then send
        $stmt = $db->query(
            "UPDATE orders SET refund_notification_sent_at = NOW() WHERE id = ? AND refund_notification_sent_at IS NULL",
            [$orderId]
        );
        if ($stmt->rowCount() > 0) {
            self::pushRefundNotification($db, $orderNew ?: $order);
        }
        return ['order' => $orderNew, 'full_refund' => $isFull];
    }

    public static function ensureRefundNotification($db, int $orderId): void
    {
        $order = $db->fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
        if (!$order) {
            return;
        }
        $rs = strtolower((string)($order['refund_status'] ?? ''));
        if ($rs !== 'full' && $rs !== 'partial' && (float)($order['refund_amount'] ?? 0) <= 0) {
            return;
        }
        // Idempotency guard: only send once per order
        if (!empty($order['refund_notification_sent_at'])) {
            return;
        }
        // Atomic claim: only the process that sets this row wins
        $stmt = $db->query(
            "UPDATE orders SET refund_notification_sent_at = NOW() WHERE id = ? AND refund_notification_sent_at IS NULL",
            [$orderId]
        );
        if ($stmt->rowCount() === 0) {
            return; // Another call already sent it
        }
        self::pushRefundNotification($db, $order);
    }

    public static function ensureUpgradeRollbackForRefund($db, int $orderId): void
    {
        $order = $db->fetch("SELECT * FROM orders WHERE id = ? LIMIT 1", [$orderId]);
        if (!$order) {
            return;
        }
        if (!self::isUpgradeOrder($order)) {
            return;
        }
        $rs = strtolower((string)($order['refund_status'] ?? ''));
        $status = strtolower((string)($order['status'] ?? ''));
        if ($rs !== 'full' && $status !== 'refunded') {
            return;
        }
        $parts = explode('-', (string)($order['merchant_order_id'] ?? ''));
        $upgradedPlanId = (int)($parts[1] ?? 0);
        if ($upgradedPlanId <= 0) {
            return;
        }
        $u = $db->fetch("SELECT plan_id FROM users WHERE id = ? LIMIT 1", [(int)$order['user_id']]);
        $currentPlanId = (int)($u['plan_id'] ?? 0);
        $planExists = $db->fetch("SELECT id FROM plans WHERE id = ? LIMIT 1", [$currentPlanId]);
        $defaultPlanId = self::resolveDefaultPlanId($db);
        if ($currentPlanId !== $upgradedPlanId && $planExists && $currentPlanId !== $defaultPlanId) {
            // User has moved to another valid plan; don't force rollback again.
            return;
        }
        self::rollbackUpgrade($db, $order);
    }

    private static function isUpgradeOrder(array $order): bool
    {
        $source = strtolower((string)($order['source'] ?? ''));
        if ($source === 'upgrade') {
            return true;
        }
        $merchantOrderId = (string)($order['merchant_order_id'] ?? '');
        $orderNo = (string)($order['order_no'] ?? '');
        return strpos($merchantOrderId, 'PLAN-') === 0 || strpos($orderNo, 'UPG') === 0;
    }

    private static function rollbackUpgrade($db, array $order): void
    {
        $userId = (int)($order['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $prevPlanId = (int)($order['upgrade_prev_plan_id'] ?? 0);
        $prevExpire = (string)($order['upgrade_prev_expire_at'] ?? '');
        $fastGrant = max(0, (int)($order['upgrade_fast_sync_grant'] ?? 0));

        // Best path: exact snapshot captured at order creation.
        if ($prevPlanId > 0) {
            if ($prevExpire === '') {
                $prevExpire = null;
            }
            $db->query(
                "UPDATE users
                 SET plan_id = ?,
                     expire_at = ?,
                     fast_sync_remaining = GREATEST(COALESCE(fast_sync_remaining, 0) - ?, 0)
                 WHERE id = ?",
                [$prevPlanId, $prevExpire, $fastGrant, $userId]
            );
            return;
        }

        // Fallback for historical orders without snapshot fields:
        // 1) infer previous plan from older paid (not fully refunded) upgrade orders.
        // 2) infer previous expire by subtracting this order cycle duration from current expire.
        // 3) if still unknown, fallback to free/default plan instead of plan_id=0.
        $parts = explode('-', (string)($order['merchant_order_id'] ?? ''));
        $upgradedPlanId = (int)($parts[1] ?? 0);
        $cycle = strtolower((string)($parts[2] ?? 'monthly'));
        if ($upgradedPlanId <= 0) {
            return;
        }
        $u = $db->fetch("SELECT plan_id, expire_at FROM users WHERE id = ? LIMIT 1", [$userId]);
        if ((int)($u['plan_id'] ?? 0) !== $upgradedPlanId) {
            return;
        }

        $inferredPrevExpire = self::inferPreviousExpireAt((string)($u['expire_at'] ?? ''), $cycle);
        $inferredPrevPlanId = self::inferPreviousPlanId($db, $order, $upgradedPlanId, $inferredPrevExpire);

        $db->query(
            "UPDATE users
             SET plan_id = ?,
                 expire_at = ?,
                 fast_sync_remaining = GREATEST(COALESCE(fast_sync_remaining, 0) - ?, 0)
             WHERE id = ?",
            [$inferredPrevPlanId, $inferredPrevExpire, $fastGrant, $userId]
        );
    }

    private static function inferPreviousPlanId($db, array $order, int $upgradedPlanId, ?string $inferredPrevExpire): int
    {
        $userId = (int)($order['user_id'] ?? 0);
        if ($userId <= 0) {
            return self::resolveDefaultPlanId($db);
        }

        $row = $db->fetch(
            "SELECT merchant_order_id
             FROM orders
             WHERE user_id = ?
               AND source = 'upgrade'
               AND created_at < ?
               AND status = 'paid'
               AND COALESCE(refund_status, '') <> 'full'
             ORDER BY id DESC
             LIMIT 1",
            [$userId, (string)($order['created_at'] ?? date('Y-m-d H:i:s'))]
        );
        $merchantOrderId = (string)($row['merchant_order_id'] ?? '');
        if ($merchantOrderId !== '' && strpos($merchantOrderId, 'PLAN-') === 0) {
            $parts = explode('-', $merchantOrderId);
            $pid = (int)($parts[1] ?? 0);
            if ($pid > 0 && $pid !== $upgradedPlanId) {
                return $pid;
            }
        }

        // Heuristic for legacy renewals without snapshot:
        // if subtracting current cycle still leaves an active expiration,
        // user likely already had this same plan before renewal.
        if ($inferredPrevExpire !== null && strtotime($inferredPrevExpire) !== false && strtotime($inferredPrevExpire) > time()) {
            return $upgradedPlanId;
        }

        // If user has no older upgrade record, prefer keeping same plan as conservative fallback.
        // This avoids accidental downgrade to free when historical snapshot is missing.
        return $upgradedPlanId > 0 ? $upgradedPlanId : self::resolveDefaultPlanId($db);
    }

    private static function resolveDefaultPlanId($db): int
    {
        $freePlan = $db->fetch(
            "SELECT id
             FROM plans
             WHERE COALESCE(price_monthly, 0) = 0
             ORDER BY id ASC
             LIMIT 1"
        );
        if (!empty($freePlan['id'])) {
            return (int)$freePlan['id'];
        }
        $first = $db->fetch("SELECT id FROM plans ORDER BY id ASC LIMIT 1");
        return (int)($first['id'] ?? 1);
    }

    private static function inferPreviousExpireAt(string $currentExpireAt, string $cycle): ?string
    {
        if ($currentExpireAt === '' || strtotime($currentExpireAt) === false) {
            return null;
        }
        $modifier = '-1 month';
        if ($cycle === 'yearly') {
            $modifier = '-1 year';
        } elseif ($cycle === 'quarterly') {
            $modifier = '-3 months';
        }
        $ts = strtotime($modifier, strtotime($currentExpireAt));
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private static function pushRefundNotification($db, array $order): void
    {
        $userId = (int)($order['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }
        $refundStatus = strtolower((string)($order['refund_status'] ?? ''));
        $refundType = $refundStatus === 'full' ? '全额退款' : ($refundStatus === 'partial' ? '部分退款' : '退款');
        $refundAmount = (string)($order['refund_amount'] ?? '0');
        $currency = (string)($order['currency'] ?? 'USDT');
        $refundOrderNo = (string)($order['refund_request_id'] ?? '-');
        $refundTime = (string)($order['refunded_at'] ?? date('Y-m-d H:i:s'));
        $orderNo = (string)($order['order_no'] ?? '-');

        $title = 'Binance Pay 退款已完成';
        $content = "您的订单 {$orderNo} 已完成{$refundType}。\n"
            . "退款金额：{$refundAmount} {$currency}\n"
            . "退款单号：{$refundOrderNo}\n"
            . "退款时间：{$refundTime}\n"
            . "退款已原路退回至原支付账户，请注意查收。";

        NotificationDispatcher::notifyUser($userId, [
            'type' => 'order',
            'in_app_type' => 'order',
            'title' => $title,
            'content' => $content,
            'subject' => $title,
            'dedupe_like' => $orderNo,
        ]);
    }
}
