<?php

class StoreCouponService
{
    public static function applyOnPaid($db, $orderId)
    {
        $orderId = (int)$orderId;
        if ($orderId <= 0) {
            return false;
        }

        $order = $db->fetch(
            "SELECT o.id, o.order_no, o.source, o.source_id, o.product_id, o.coupon_code, o.discount_amount, o.currency, o.status, o.updated_at, p.name AS product_name
             FROM orders o
             LEFT JOIN store_products p ON p.id = o.product_id
             WHERE o.id = ? LIMIT 1",
            [$orderId]
        );
        if (!$order) return false;
        if (strtolower((string)($order['source'] ?? '')) !== 'store') return false;
        if (strtolower((string)($order['status'] ?? '')) !== 'paid') return false;

        $couponCode = strtoupper(trim((string)($order['coupon_code'] ?? '')));
        if ($couponCode === '') return false;

        $storeId = (int)($order['source_id'] ?? 0);
        if ($storeId <= 0) return false;
        $db->query("START TRANSACTION");
        try {
            $exists = $db->fetch("SELECT id FROM store_coupon_usages WHERE order_id = ? LIMIT 1", [$orderId]);
            if ($exists) {
                $db->query("COMMIT");
                return true;
            }

            $coupon = $db->fetch(
                "SELECT id, store_id, code FROM store_coupons WHERE store_id = ? AND code = ? LIMIT 1",
                [$storeId, $couponCode]
            );
            if (!$coupon) {
                $db->query("COMMIT");
                return false;
            }

            $db->query(
                "INSERT INTO store_coupon_usages
                (coupon_id, store_id, order_id, order_no, product_id, product_name, discount_amount, currency, paid_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    (int)$coupon['id'],
                    $storeId,
                    $orderId,
                    (string)($order['order_no'] ?? ''),
                    (int)($order['product_id'] ?? 0),
                    (string)($order['product_name'] ?? ''),
                    (float)($order['discount_amount'] ?? 0),
                    (string)($order['currency'] ?? 'USDT'),
                    (string)($order['updated_at'] ?? date('Y-m-d H:i:s')),
                ]
            );

            $db->query("UPDATE store_coupons SET used_count = used_count + 1 WHERE id = ?", [(int)$coupon['id']]);
            $db->query("COMMIT");
            return true;
        } catch (Throwable $e) {
            $db->query("ROLLBACK");
            return false;
        }
    }
}
