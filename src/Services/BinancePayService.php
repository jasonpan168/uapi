<?php

class BinancePayService
{
    private const WEBHOOK_CERT_CACHE_KEY = 'binance_pay_webhook_certs';

    public static function loadConfig($db): array
    {
        $rows = $db->fetchAll("SELECT key_name, value FROM system_settings");
        $cfg = [];
        foreach ($rows as $row) {
            $cfg[(string)$row['key_name']] = (string)$row['value'];
        }
        return [
            'enabled' => (($cfg['enable_payment_binance'] ?? '0') === '1'),
            'base_url' => rtrim((string)($cfg['binance_pay_base_url'] ?? 'https://bpay.binanceapi.com'), '/'),
            'api_key' => trim((string)($cfg['binance_pay_api_key'] ?? '')),
            'secret_key' => trim((string)($cfg['binance_pay_api_secret'] ?? '')),
            // Binance Pay header BinancePay-Certificate-SN is API identity key.
            // Many merchants only have API Key + Secret, so fallback to API Key if SN is empty.
            'certificate_sn' => trim((string)($cfg['binance_pay_certificate_sn'] ?? '')) ?: trim((string)($cfg['binance_pay_api_key'] ?? '')),
            'webhook_secret' => trim((string)($cfg['binance_pay_webhook_secret'] ?? '')),
        ];
    }

    private static function signPayload(string $payload, string $secretKey): string
    {
        if ($secretKey === '') {
            throw new Exception('Binance Pay secret key missing');
        }
        // Binance Pay request signature: HMAC-SHA512 hex uppercase.
        return strtoupper(hash_hmac('sha512', $payload, $secretKey));
    }

    public static function request(array $cfg, string $path, $body = [], string $method = 'POST'): array
    {
        if (empty($cfg['api_key']) || empty($cfg['secret_key']) || empty($cfg['certificate_sn'])) {
            throw new Exception('Binance Pay API credentials not configured');
        }

        $method = strtoupper(trim($method));
        if ($method === '') {
            $method = 'POST';
        }

        // Binance Pay expects JSON object for most endpoints; avoid sending [].
        if ($body === [] || $body === null) {
            $body = new stdClass();
        }
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody === false) {
            $jsonBody = '{}';
        }

        $timestamp = (string)round(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $payloadToSign = $timestamp . "\n" . $nonce . "\n" . $jsonBody . "\n";
        $signature = self::signPayload($payloadToSign, (string)$cfg['secret_key']);

        $url = rtrim((string)$cfg['base_url'], '/') . '/' . ltrim($path, '/');
        $headers = [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . (string)$cfg['certificate_sn'],
            'BinancePay-Signature: ' . $signature,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            throw new Exception('Binance Pay HTTP request failed: ' . ($err ?: 'unknown'));
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data)) {
            throw new Exception('Binance Pay invalid JSON response (HTTP ' . $httpCode . ')');
        }

        return [
            'http_code' => $httpCode,
            'raw' => $resp,
            'data' => $data,
        ];
    }

    public static function createOrder(array $cfg, array $payload): array
    {
        return self::request($cfg, '/binancepay/openapi/v3/order', $payload, 'POST');
    }

    public static function queryOrder(array $cfg, string $merchantTradeNo = '', string $prepayId = ''): array
    {
        $payload = [];
        if ($merchantTradeNo !== '') {
            $payload['merchantTradeNo'] = $merchantTradeNo;
        }
        if ($prepayId !== '') {
            $payload['prepayId'] = $prepayId;
        }
        return self::request($cfg, '/binancepay/openapi/v2/order/query', $payload, 'POST');
    }

    public static function normalizeOrderStatus(array $resp): string
    {
        $data = $resp['data']['data'] ?? $resp['data'] ?? [];
        $status = strtoupper((string)($data['status'] ?? $data['orderStatus'] ?? ''));
        if (in_array($status, ['REFUNDED', 'REFUND_SUCCESS', 'FULL_REFUNDED'], true)) {
            return 'refunded';
        }
        if (in_array($status, ['PAID', 'SUCCESS', 'COMPLETED'], true)) {
            return 'paid';
        }
        if (in_array($status, ['PARTIAL_REFUNDED', 'PARTIALLY_REFUNDED'], true)) {
            return 'paid';
        }
        if (in_array($status, ['EXPIRED', 'CANCELLED', 'CLOSED', 'FAIL'], true)) {
            return 'expired';
        }
        return 'pending';
    }

    public static function extractRefundInfoFromOrderQuery(array $resp, float $orderAmount = 0.0): array
    {
        $data = $resp['data']['data'] ?? $resp['data'] ?? [];
        $status = strtoupper((string)($data['status'] ?? $data['orderStatus'] ?? ''));
        $isPartial = in_array($status, ['PARTIAL_REFUNDED', 'PARTIALLY_REFUNDED'], true);
        $isFull = in_array($status, ['REFUNDED', 'REFUND_SUCCESS', 'FULL_REFUNDED'], true);
        $hasRefund = $isPartial || $isFull || (strpos($status, 'REFUND') !== false);

        $refundInfo = (isset($data['refundInfo']) && is_array($data['refundInfo'])) ? $data['refundInfo'] : [];
        $amountRaw = $data['refundAmount']
            ?? $data['refundedAmount']
            ?? ($refundInfo['refundAmount'] ?? null)
            ?? ($refundInfo['amount'] ?? null)
            ?? null;
        $amount = 0.0;
        if ($amountRaw !== null && $amountRaw !== '') {
            $amount = (float)$amountRaw;
            $hasRefund = true;
        } elseif ($isFull && $orderAmount > 0) {
            $amount = $orderAmount;
        }

        $requestId = (string)($data['refundRequestId']
            ?? ($refundInfo['refundRequestId'] ?? '')
            ?? ($refundInfo['refundId'] ?? ''));

        $refundStatus = '';
        if ($hasRefund) {
            $refundStatus = $isFull ? 'full' : 'partial';
            if (!$isFull && !$isPartial && $amount > 0 && $orderAmount > 0 && $amount + 0.000001 >= $orderAmount) {
                $refundStatus = 'full';
            }
        }

        return [
            'has_refund' => $hasRefund,
            'refund_status' => $refundStatus, // full|partial|''
            'refund_amount' => $amount,
            'refund_request_id' => $requestId,
        ];
    }

    public static function queryBalanceV2(array $cfg, string $wallet = 'FUNDING_WALLET', string $currency = ''): array
    {
        $wallet = strtoupper(trim($wallet));
        if ($wallet === '') {
            $wallet = 'FUNDING_WALLET';
        }
        $payload = ['wallet' => $wallet];
        $currency = strtoupper(trim($currency));
        if ($currency !== '') {
            $payload['currency'] = $currency;
        }
        return self::request($cfg, '/binancepay/openapi/v2/balance', $payload, 'POST');
    }

    public static function queryAllBalances(array $cfg, string $currency = ''): array
    {
        $wallets = ['FUNDING_WALLET', 'SPOT_WALLET'];
        $rows = [];
        $errors = [];

        foreach ($wallets as $wallet) {
            $resp = self::queryBalanceV2($cfg, $wallet, $currency);
            $data = $resp['data'] ?? [];
            $status = strtoupper((string)($data['status'] ?? ''));
            $code = (string)($data['code'] ?? '');
            if (!($status === 'SUCCESS' || $code === '000000')) {
                $errors[] = [
                    'wallet' => $wallet,
                    'code' => $code,
                    'errorMessage' => (string)($data['errorMessage'] ?? $data['msg'] ?? 'Unknown error'),
                ];
                continue;
            }

            $d = $data['data'] ?? [];
            $list = isset($d['balance']) && is_array($d['balance']) ? $d['balance'] : [];
            foreach ($list as $item) {
                $rows[] = [
                    'wallet' => $d['wallet'] ?? $wallet,
                    'asset' => $item['asset'] ?? '',
                    'available' => $item['available'] ?? '',
                    'freeze' => $item['freeze'] ?? '',
                    'availableFiatValuation' => $item['availableFiatValuation'] ?? '',
                    'availableBtcValuation' => $item['availableBtcValuation'] ?? '',
                    'fiat' => $d['fiat'] ?? '',
                    'updateTime' => $d['updateTime'] ?? '',
                ];
            }
        }

        return [
            'status' => empty($errors) ? 'SUCCESS' : (empty($rows) ? 'FAIL' : 'PARTIAL_SUCCESS'),
            'code' => empty($errors) ? '000000' : (empty($rows) ? '400001' : '000001'),
            'data' => [
                'balances' => $rows,
                'errors' => $errors,
            ],
        ];
    }

    public static function convertQuote(array $cfg, string $fromAsset, string $toAsset, string $fromAmount): array
    {
        $amount = self::normalizeAmountString($fromAmount);
        $payload = [
            'wallet' => 'FUNDING_WALLET',
            'fromAsset' => strtoupper(trim($fromAsset)),
            'toAsset' => strtoupper(trim($toAsset)),
            'fromAmount' => $amount,
        ];
        $primary = self::request($cfg, '/binancepay/openapi/otc-portal/get-quote', $payload, 'POST');
        if (self::isSuccess($primary)) {
            return $primary;
        }

        // Fallback for merchants still on legacy convert permission routing.
        $fallback = self::request($cfg, '/binancepay/openapi/wallet/exchangeRate', $payload, 'POST');
        if (self::isSuccess($fallback)) {
            return $fallback;
        }

        return $primary;
    }

    public static function convertExecute(array $cfg, string $fromAsset, string $toAsset, string $fromAmount, string $requestId = ''): array
    {
        // Binance Pay Convert requires quote first, then execute by quoteId.
        $quoteResp = self::convertQuote($cfg, $fromAsset, $toAsset, $fromAmount);
        if (!self::isSuccess($quoteResp)) {
            return $quoteResp;
        }
        $quoteData = $quoteResp['data']['data'] ?? [];
        $quoteId = trim((string)($quoteData['quoteId'] ?? ''));
        if ($quoteId === '') {
            return [
                'http_code' => $quoteResp['http_code'] ?? 200,
                'raw' => $quoteResp['raw'] ?? '',
                'data' => [
                    'status' => 'FAIL',
                    'code' => '400100',
                    'errorMessage' => 'QuoteId missing from convert quote response',
                ],
            ];
        }
        $payload = [
            'quoteId' => $quoteId,
        ];
        $exec = self::request($cfg, '/binancepay/openapi/otc-portal/execute-quote', $payload, 'POST');
        if (self::isSuccess($exec)) {
            return $exec;
        }

        // Fallback for older convert API path.
        $legacyPayload = [
            'wallet' => 'FUNDING_WALLET',
            'fromAsset' => strtoupper(trim($fromAsset)),
            'toAsset' => strtoupper(trim($toAsset)),
            'fromAmount' => self::normalizeAmountString($fromAmount),
        ];
        $legacy = self::request($cfg, '/binancepay/openapi/wallet/convert', $legacyPayload, 'POST');
        if (self::isSuccess($legacy)) {
            return $legacy;
        }

        return $exec;
    }

    private static function normalizeAmountString(string $value): string
    {
        $num = trim($value);
        if ($num === '' || !is_numeric($num)) {
            return '0';
        }
        $n = (float)$num;
        if ($n <= 0) {
            return '0';
        }
        $fmt = number_format($n, 8, '.', '');
        return rtrim(rtrim($fmt, '0'), '.');
    }

    public static function refund(array $cfg, array $payload): array
    {
        return self::request($cfg, '/binancepay/openapi/order/refund', $payload, 'POST');
    }

    public static function isSuccess(array $resp): bool
    {
        $data = $resp['data'] ?? [];
        $status = strtoupper((string)($data['status'] ?? ''));
        $code = (string)($data['code'] ?? '');
        return $status === 'SUCCESS' || $code === '000000';
    }

    public static function queryCertificates(array $cfg): array
    {
        return self::request($cfg, '/binancepay/openapi/certificates', new stdClass(), 'POST');
    }

    public static function extractBinanceHeaders(): array
    {
        $h = [];
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                $h[strtolower((string)$k)] = (string)$v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                if (!isset($h[$name])) {
                    $h[$name] = (string)$v;
                }
            }
        }
        return [
            'serial' => (string)($h['binancepay-certificate-sn'] ?? ''),
            'nonce' => (string)($h['binancepay-nonce'] ?? ''),
            'timestamp' => (string)($h['binancepay-timestamp'] ?? ''),
            'signature' => (string)($h['binancepay-signature'] ?? ''),
        ];
    }

    public static function resolveWebhookPublicKey($db, array $cfg, string $serial): string
    {
        $serial = trim($serial);
        if ($serial === '') {
            throw new Exception('Missing Binance certificate serial');
        }

        $cache = [];
        $cacheRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = ? LIMIT 1", [self::WEBHOOK_CERT_CACHE_KEY]);
        if ($cacheRow && !empty($cacheRow['value'])) {
            $decoded = json_decode((string)$cacheRow['value'], true);
            if (is_array($decoded)) {
                $cache = $decoded;
            }
        }

        if (!empty($cache[$serial]['certPublic'])) {
            return (string)$cache[$serial]['certPublic'];
        }

        $certResp = self::queryCertificates($cfg);
        $data = $certResp['data'] ?? [];
        if (!self::isSuccess($certResp)) {
            $msg = (string)($data['errorMessage'] ?? $data['msg'] ?? 'Query certificates failed');
            throw new Exception($msg);
        }
        $list = $data['data'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }
        $now = time();
        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }
            $s = trim((string)($item['certSerial'] ?? ''));
            $p = trim((string)($item['certPublic'] ?? ''));
            if ($s === '' || $p === '') {
                continue;
            }
            $cache[$s] = ['certPublic' => $p, 'updatedAt' => $now];
        }

        $cacheJson = json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($cacheJson !== false) {
            $exists = $db->fetch("SELECT 1 FROM system_settings WHERE key_name = ? LIMIT 1", [self::WEBHOOK_CERT_CACHE_KEY]);
            if ($exists) {
                $db->query("UPDATE system_settings SET value = ? WHERE key_name = ?", [$cacheJson, self::WEBHOOK_CERT_CACHE_KEY]);
            } else {
                $db->query("INSERT INTO system_settings (key_name, value) VALUES (?, ?)", [self::WEBHOOK_CERT_CACHE_KEY, $cacheJson]);
            }
        }

        if (!empty($cache[$serial]['certPublic'])) {
            return (string)$cache[$serial]['certPublic'];
        }
        throw new Exception('Webhook public key not found for serial: ' . $serial);
    }

    public static function verifyWebhookSignature(array $hdr, string $body, string $publicKey): bool
    {
        $timestamp = (string)($hdr['timestamp'] ?? '');
        $nonce = (string)($hdr['nonce'] ?? '');
        $signature = (string)($hdr['signature'] ?? '');
        if ($timestamp === '' || $nonce === '' || $signature === '' || $publicKey === '') {
            return false;
        }
        $payload = $timestamp . "\n" . $nonce . "\n" . $body . "\n";
        $decodedSig = base64_decode($signature, true);
        if ($decodedSig === false) {
            return false;
        }
        $ok = openssl_verify($payload, $decodedSig, $publicKey, OPENSSL_ALGO_SHA256);
        return $ok === 1;
    }
}
