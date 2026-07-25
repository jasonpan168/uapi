<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    die('Unauthorized');
}
require_once __DIR__ . '/../../../../src/Core/Database.php';

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

// Build Filter (Same as orders.php)
$where = "user_id = ?";
$params = [$user_id];

if (!empty($_GET['order_no'])) {
    $where .= " AND (order_no LIKE ? OR merchant_order_id LIKE ?)";
    $params[] = '%' . $_GET['order_no'] . '%';
    $params[] = '%' . $_GET['order_no'] . '%';
}
if (!empty($_GET['status'])) {
    $where .= " AND status = ?";
    $params[] = $_GET['status'];
}
if (!empty($_GET['chain'])) {
    $where .= " AND chain = ?";
    $params[] = $_GET['chain'];
}
if (!empty($_GET['source'])) {
    $where .= " AND source = ?";
    $params[] = $_GET['source'];
}

// Fetch all matching records with Wallet Address
$sql = "SELECT o.*, w.address as wallet_address 
        FROM orders o 
        LEFT JOIN wallets w ON o.wallet_id = w.id 
        WHERE $where 
        ORDER BY o.id DESC";

$orders = $db->fetchAll($sql, $params);

function csv_safe($value) {
    $value = (string)($value ?? '');
    if ($value !== '' && preg_match('/^([=\-+@]|\t|\r)/u', $value)) {
        return "'" . $value;
    }
    return $value;
}

// Generate CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=orders_export_' . date('Y-m-d_His') . '.csv');

$output = fopen('php://output', 'w');

// Add BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Header
fputcsv($output, [
    '系统订单号', 
    '商户订单号', 
    '金额', 
    '货币',
    '网络', 
    '状态', 
    '来源', 
    '钱包地址',
    '交易哈希',
    '创建时间',
    '完成时间'
]);

foreach ($orders as $o) {
    // Fetch wallet address if needed, or join in query. 
    // To keep it simple, we do a quick fetch or just export wallet_id if address not in order table.
    // Actually orders table doesn't have wallet_address column, only wallet_id.
    // But we can join wallets table.
    
    // Optimized: Re-query with JOIN to get address
    // But for now, let's just export what we have.
    
    fputcsv($output, [
        csv_safe($o['order_no']),
        csv_safe($o['merchant_order_id']),
        csv_safe($o['amount']),
        csv_safe($o['currency'] ?? 'USDT'),
        csv_safe(strtoupper($o['chain'])),
        csv_safe($o['status']),
        csv_safe($o['source'] ?? 'api'),
        csv_safe($o['wallet_address'] ?? '已删除钱包'),
        csv_safe($o['tx_hash'] ?? '-'),
        csv_safe($o['created_at']),
        csv_safe($o['updated_at'])
    ]);
}

fclose($output);
exit;
