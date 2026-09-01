<?php
// public/api/v1/store/verify_coupon.php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/CouponService.php';

$store_id = $_GET['store_id'] ?? 0;
$code = $_GET['code'] ?? '';

if (!$store_id || empty($code)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request']);
    exit;
}

$db = Database::getInstance();

$coupon = $db->fetch("SELECT * FROM store_coupons WHERE store_id = ? AND code = ? AND status = 'active'", [$store_id, $code]);

if (!$coupon) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Coupon Code']);
    exit;
}

// Check Expiry
if ($coupon['expiry_date'] && strtotime($coupon['expiry_date']) < time()) {
    echo json_encode(['status' => 'error', 'message' => 'Coupon Expired']);
    exit;
}

// Check usage limit and coupon configuration (a percent value >= 100 is refused)
if (!CouponService::isRedeemable($coupon)) {
    echo json_encode(['status' => 'error', 'message' => 'Coupon Limit Reached']);
    exit;
}

echo json_encode([
    'status' => 'success',
    'coupon' => [
        'code' => $coupon['code'],
        'type' => $coupon['type'],
        'value' => $coupon['value']
    ]
]);
