<?php
// src/Services/WebhookService.php

class WebhookService {
    
    public static function send($order) {
        if (empty($order['notify_url'])) {
            return false;
        }

        $payload = [
            'status' => 'paid',
            'order_no' => $order['order_no'],
            'merchant_order_id' => $order['merchant_order_id'],
            'amount' => $order['amount'],
            'chain' => $order['chain'],
            'currency' => $order['currency'] ?? 'USDT',
            'tx_hash' => $order['tx_hash'],
            'paid_at' => date('c')
        ];

        $db = Database::getInstance();
        $user = $db->fetch("SELECT api_key, plan_id FROM users WHERE id = ?", [$order['user_id']]);
        $apiKey = $user ? $user['api_key'] : '';
        if (!$user) {
            return false;
        }

        $plan = $db->fetch("SELECT allow_webhook_notice FROM plans WHERE id = ?", [$user['plan_id']]);
        if ($plan && (int)$plan['allow_webhook_notice'] !== 1) {
            return false;
        }

        // HMAC-SHA256 headers with timestamp + event_id
        $timestamp = time();
        $eventId = bin2hex(random_bytes(8));
        $bodyForSign = $order['order_no'] . $order['amount'] . $order['merchant_order_id'] . $timestamp;
        $hmac = $apiKey ? hash_hmac('sha256', $bodyForSign, $apiKey) : '';

        $maxAttempts = 3;
        $attempt = 0;
        $finalStatus = 'failed';
        $lastCode = null;
        $lastBody = null;
        while ($attempt < $maxAttempts) {
            $attempt++;
            $ch = curl_init($order['notify_url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $headers = ['Content-Type: application/json'];
            if ($hmac) {
                $headers[] = 'X-UAPI-Signature: ' . $hmac;
                $headers[] = 'X-UAPI-Timestamp: ' . $timestamp;
                $headers[] = 'X-UAPI-Event: order.paid';
                $headers[] = 'X-UAPI-Event-ID: ' . $eventId;
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $scheme = strtolower((string)parse_url($order['notify_url'], PHP_URL_SCHEME));
            if ($scheme === 'https') {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            }
            curl_setopt($ch, CURLOPT_USERAGENT, 'UAPI-Webhook/1.0');
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $lastCode = $http_code;
            $lastBody = $response;

            // Log webhook attempt
            try {
                $db->query(
                    "INSERT INTO webhook_logs (order_id, payload, response_code, response_body) VALUES (?, ?, ?, ?)",
                    [$order['id'], json_encode($payload, JSON_UNESCAPED_UNICODE), $http_code, (string)$response]
                );
            } catch (\Throwable $e) {
                error_log("[WebhookService] webhook_logs insert failed: " . $e->getMessage());
            }

            $ok = ($http_code >= 200 && $http_code < 300) || (is_string($response) && trim(strtolower($response)) === 'success');
            // Update notify status per attempt
            try {
                $db->query("UPDATE orders SET notify_retries = notify_retries + 1, last_notify_at = NOW(), notify_status = ? WHERE id = ?", [
                    $ok ? 'success' : 'failed',
                    $order['id']
                ]);
            } catch (\Throwable $e) {
                error_log("[WebhookService] notify_status update failed for order #{$order['id']}: " . $e->getMessage());
            }

            if ($ok) {
                $finalStatus = 'success';
                break;
            }
            // backoff
            if ($attempt < $maxAttempts) {
                sleep($attempt); // 1s, 2s
            }
        }

        return $finalStatus === 'success';
    }
}
