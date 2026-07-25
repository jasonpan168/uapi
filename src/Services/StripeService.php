<?php

class StripeService {
    private $apiKey;

    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }

    public static function createCheckoutSession($amount, $currency, $productName, $orderNo, $successUrl, $cancelUrl, $locale = 'auto') {
        // Get API Key from DB
        require_once __DIR__ . '/../Core/Database.php';
        $db = Database::getInstance();
        $row = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'stripe_secret_key'");
        if (!$row || empty($row['value'])) {
            throw new Exception("Stripe secret key not configured");
        }
        $apiKey = $row['value'];

        $url = 'https://api.stripe.com/v1/checkout/sessions';
        
        $params = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => [
                        'name' => $productName,
                    ],
                    'unit_amount' => intval($amount * 100), // Amount in cents
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'locale' => $locale ?: 'auto',
            'client_reference_id' => $orderNo,
            'metadata' => [
                'order_no' => $orderNo
            ]
        ];

        // Stripe API expects nested params like: line_items[0][price_data][currency]=usd
        // http_build_query() produces: line_items[0][price_data][currency]=usd
        // This is standard and compatible.
        
        $postFields = http_build_query($params);
        
        // However, Stripe sometimes prefers array bracket notation without indices for lists if not strictly ordered map?
        // No, standard PHP http_build_query works fine for Stripe API.
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode !== 200) {
            $msg = $result['error']['message'] ?? 'Unknown error';
            throw new Exception("Stripe API Error ($httpCode): " . $msg);
        }

        return $result;
    }

    public static function getCheckoutSession($sessionId) {
        if (!$sessionId) {
            throw new Exception("Missing Stripe session id");
        }

        require_once __DIR__ . '/../Core/Database.php';
        $db = Database::getInstance();
        $row = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'stripe_secret_key'");
        if (!$row || empty($row['value'])) {
            throw new Exception("Stripe secret key not configured");
        }
        $apiKey = $row['value'];

        $url = 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode((string)$sessionId);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode((string)$response, true);
        if ($httpCode !== 200) {
            $msg = $result['error']['message'] ?? 'Unknown error';
            throw new Exception("Stripe API Error ($httpCode): " . $msg);
        }

        return $result;
    }

    public static function listCheckoutSessions(int $limit = 100, ?int $createdGte = null): array {
        require_once __DIR__ . '/../Core/Database.php';
        $db = Database::getInstance();
        $row = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'stripe_secret_key'");
        if (!$row || empty($row['value'])) {
            throw new Exception("Stripe secret key not configured");
        }
        $apiKey = $row['value'];

        $params = ['limit' => max(1, min(100, $limit))];
        if ($createdGte !== null && $createdGte > 0) {
            $params['created[gte]'] = (int)$createdGte;
        }
        $url = 'https://api.stripe.com/v1/checkout/sessions?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, $apiKey . ':');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode((string)$response, true);
        if ($httpCode !== 200) {
            $msg = $result['error']['message'] ?? 'Unknown error';
            throw new Exception("Stripe API Error ($httpCode): " . $msg);
        }

        return isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
    }
}
