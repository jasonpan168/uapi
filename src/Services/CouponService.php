<?php

/**
 * Shared coupon rules for admin (plan upgrade) coupons and store (product) coupons.
 *
 * Two invariants are enforced here and must not be bypassed by callers:
 *
 *  1. A coupon can never bring an order down to zero or below. Percent coupons
 *     are capped strictly below 100% at creation time and every discount is
 *     clamped to [0, price] at checkout time, so a paid order always requires a
 *     real payment. Giving a plan away for free is an admin operation
 *     (admin/users.php), not a checkout outcome.
 *
 *  2. Redemption slots are taken with a conditional atomic UPDATE, so a coupon
 *     can never be redeemed more often than its usage_limit even when several
 *     checkouts race each other. The old "read used_count, compare, then
 *     increment" pattern was a check-then-act race.
 */
class CouponService
{
    /** usage_limit value meaning "no redemption limit". */
    public const UNLIMITED_USAGE = -1;

    /** Percent coupons must stay strictly below this value. */
    public const MAX_PERCENT_VALUE = 100.0;

    /** Coupon types accepted by the admin_coupons / store_coupons enums. */
    public const TYPES = ['fixed', 'percent'];

    public static function isValidType($type): bool
    {
        return in_array((string)$type, self::TYPES, true);
    }

    /**
     * Validate an admin/merchant supplied coupon configuration.
     * A percent coupon of 100% or more would produce free orders, so it is refused.
     */
    public static function isValidConfig($type, $value): bool
    {
        if (!self::isValidType($type)) {
            return false;
        }
        $value = (float)$value;
        if ($value <= 0) {
            return false;
        }
        if ((string)$type === 'percent' && $value >= self::MAX_PERCENT_VALUE) {
            return false;
        }
        return true;
    }

    /**
     * Whether a stored coupon row can be redeemed right now.
     * Also rejects legacy rows whose configuration is no longer acceptable.
     */
    public static function isRedeemable($coupon): bool
    {
        if (!is_array($coupon) || empty($coupon)) {
            return false;
        }
        if (strtolower((string)($coupon['status'] ?? 'active')) !== 'active') {
            return false;
        }
        if (!self::isValidConfig($coupon['type'] ?? '', $coupon['value'] ?? 0)) {
            return false;
        }
        $expiry = trim((string)($coupon['expiry_date'] ?? ''));
        if ($expiry !== '') {
            $expiryTs = strtotime($expiry);
            if ($expiryTs !== false && $expiryTs < time()) {
                return false;
            }
        }
        $limit = (int)($coupon['usage_limit'] ?? self::UNLIMITED_USAGE);
        if ($limit !== self::UNLIMITED_USAGE && (int)($coupon['used_count'] ?? 0) >= $limit) {
            return false;
        }
        return true;
    }

    /**
     * Discount for the given price. Always clamped to [0, price], so the caller
     * can never end up with a negative payable amount whatever the coupon holds.
     */
    public static function discountFor($type, $value, float $price): float
    {
        $value = (float)$value;
        if ($price <= 0 || $value <= 0) {
            return 0.0;
        }
        $discount = ((string)$type === 'fixed')
            ? $value
            : ($price * ($value / 100));
        return round(min($price, max(0.0, $discount)), 2);
    }

    /** Amount still to be paid after the discount, never below zero. */
    public static function payableAfterDiscount(float $price, float $discount): float
    {
        return round(max(0.0, $price - $discount), 2);
    }

    /**
     * Atomically take one redemption slot of an admin coupon.
     * Returns false when the coupon is missing, disabled or already exhausted.
     */
    public static function claimAdminCoupon($db, string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return false;
        }
        try {
            $stmt = $db->query(
                "UPDATE admin_coupons
                 SET used_count = used_count + 1
                 WHERE code = ?
                   AND status = 'active'
                   AND (usage_limit = ? OR used_count < usage_limit)",
                [$code, self::UNLIMITED_USAGE]
            );
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('[coupon] admin coupon claim failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Count one redemption for an order that has just been confirmed as paid.
     * The payment already settled at this point, so a full slot is only logged,
     * never turned into an order failure.
     */
    public static function countAdminRedemption($db, $order): void
    {
        $code = strtoupper(trim((string)($order['coupon_code'] ?? '')));
        if ($code === '') {
            return;
        }
        if (!self::claimAdminCoupon($db, $code)) {
            error_log('[coupon] no redemption slot left for code ' . $code
                . ' on order ' . (string)($order['order_no'] ?? ''));
        }
    }

    /**
     * Atomically take one redemption slot of a store coupon.
     * Returns false when the coupon is missing, disabled or already exhausted.
     */
    public static function claimStoreCoupon($db, int $couponId): bool
    {
        if ($couponId <= 0) {
            return false;
        }
        try {
            $stmt = $db->query(
                "UPDATE store_coupons
                 SET used_count = used_count + 1
                 WHERE id = ?
                   AND status = 'active'
                   AND (usage_limit = ? OR used_count < usage_limit)",
                [$couponId, self::UNLIMITED_USAGE]
            );
            return $stmt->rowCount() > 0;
        } catch (Throwable $e) {
            error_log('[coupon] store coupon claim failed: ' . $e->getMessage());
            return false;
        }
    }
}
