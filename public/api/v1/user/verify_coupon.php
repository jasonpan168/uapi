<?php
// public/api/v1/user/verify_coupon.php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/CouponService.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$code = $_GET['code'] ?? '';
$type = $_GET['type'] ?? 'admin'; // 'admin' for plans, 'store' for products (not used here yet)

if (empty($code)) {
    echo json_encode(['status' => 'error', 'message' => '请输入优惠码']);
    exit;
}

$db = Database::getInstance();

if ($type === 'admin') {
    $coupon = $db->fetch("SELECT * FROM admin_coupons WHERE code = ? AND status = 'active'", [$code]);
    if (!$coupon) {
        echo json_encode(['status' => 'error', 'message' => '优惠码不存在']);
        exit;
    }
    
    // Check expiry
    if ($coupon['expiry_date'] && strtotime($coupon['expiry_date']) < time()) {
        echo json_encode(['status' => 'error', 'message' => '优惠码已过期']);
        exit;
    }
    
    // Check usage limit and coupon configuration (a percent value >= 100 is refused)
    if (!CouponService::isRedeemable($coupon)) {
        echo json_encode(['status' => 'error', 'message' => '优惠码已失效']);
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
}
