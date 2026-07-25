<?php
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../../../../src/Core/Database.php';
require_once __DIR__ . '/../../../../src/Services/BinancePayService.php';

try {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        throw new Exception('Unauthorized');
    }

    $db = Database::getInstance();
    $user = $db->fetch("SELECT role FROM users WHERE id = ? LIMIT 1", [$_SESSION['user_id']]);
    if (!$user || (string)$user['role'] !== 'admin') {
        http_response_code(403);
        throw new Exception('Forbidden');
    }

    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        throw new Exception('Invalid JSON body');
    }

    $action = trim((string)($input['action'] ?? ''));
    if ($action === '') {
        throw new Exception('Missing action');
    }

    $upsert = static function ($dbConn, string $key, string $value): void {
        $exists = $dbConn->fetch("SELECT 1 FROM system_settings WHERE key_name = ? LIMIT 1", [$key]);
        if ($exists) {
            $dbConn->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$value, $key]);
        } else {
            $dbConn->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [$key, $value]);
        }
    };

    if ($action === 'save_config') {
        $allowed = [
            'enable_payment_binance',
            'binance_pay_base_url',
            'binance_pay_api_key',
            'binance_pay_api_secret',
            'binance_pay_certificate_sn',
            'binance_pay_webhook_secret',
        ];
        $data = isset($input['data']) && is_array($input['data']) ? $input['data'] : [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $val = (string)$data[$key];
            if ($key === 'enable_payment_binance') {
                $val = ((string)$val === '1' || $val === 'true') ? '1' : '0';
            }
            $upsert($db, $key, $val);
        }
        echo json_encode(['status' => 'success']);
        exit;
    }

    $cfg = BinancePayService::loadConfig($db);

    if ($action === 'query_balance') {
        $resp = BinancePayService::queryAllBalances($cfg, (string)($input['currency'] ?? ''));
        echo json_encode(['status' => 'success', 'data' => $resp]);
        exit;
    }
    if ($action === 'query_order') {
        $merchantTradeNo = trim((string)($input['merchantTradeNo'] ?? ''));
        $prepayId = trim((string)($input['prepayId'] ?? ''));
        $resp = BinancePayService::queryOrder($cfg, $merchantTradeNo, $prepayId);
        echo json_encode(['status' => 'success', 'data' => $resp['data'] ?? $resp]);
        exit;
    }
    if ($action === 'refund') {
        $payload = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : [];
        $resp = BinancePayService::refund($cfg, $payload);
        echo json_encode(['status' => 'success', 'data' => $resp['data'] ?? $resp]);
        exit;
    }
    if ($action === 'call') {
        $path = trim((string)($input['path'] ?? ''));
        $method = strtoupper(trim((string)($input['method'] ?? 'POST')));
        $payload = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : [];
        if ($path === '') {
            throw new Exception('Missing path');
        }
        $resp = BinancePayService::request($cfg, $path, $payload, $method);
        echo json_encode(['status' => 'success', 'data' => $resp['data'] ?? $resp]);
        exit;
    }

    throw new Exception('Unsupported action');
} catch (Throwable $e) {
    if (http_response_code() < 400) {
        http_response_code(400);
    }
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
