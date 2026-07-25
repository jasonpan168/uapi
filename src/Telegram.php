<?php

require_once __DIR__ . '/Services/NotificationPolicy.php';

class Telegram {
    
    // Send to Admin (Global/System notifications)
    public static function send($message) {
        $db = Database::getInstance();
        
        // Fetch settings from system_settings table
        $settings = $db->fetchAll("SELECT key_name, value FROM system_settings WHERE key_name IN ('tg_bot_token', 'tg_admin_chat_id', 'telegram_enable')");
        $config = [];
        foreach ($settings as $s) $config[$s['key_name']] = $s['value'];

        $token = $config['tg_bot_token'] ?? '';
        $chat_id = $config['tg_admin_chat_id'] ?? ''; // You might need to add this setting if not exists
        $enabled = $config['telegram_enable'] ?? false;

        if (!$enabled || empty($token) || empty($chat_id)) {
            return false;
        }
        
        return self::sendRaw($token, $chat_id, $message);
    }

    // Send to User (Quota & Toggle Controlled)
    public static function sendToUser($user_id, $message, $type = 'system') {
        $db = Database::getInstance();
        $user = $db->fetch("SELECT id, tg_chat_id, notification_settings, plan_id, notice_cycle_ym, tg_notice_used_month FROM users WHERE id = ?", [$user_id]);
        
        if (!$user || empty($user['tg_chat_id'])) return false;

        // 1. Check user settings (default OFF)
        $settings = NotificationPolicy::parse($user['notification_settings'] ?? '{}');
        if (!NotificationPolicy::isTypeEnabled($settings, (string)$type)) return false;
        if (!NotificationPolicy::isChannelEnabled($settings, 'tg')) return false;

        // 2. Check Plan Permission (Optional check)
        $plan = $db->fetch("SELECT allow_tg_bot, tg_notice_limit FROM plans WHERE id = ?", [$user['plan_id']]);
        if (!$plan || !$plan['allow_tg_bot']) return false;

        // 3. Monthly limit follows plan (0 = unlimited)
        self::resetMonthlyCountersIfNeeded($db, $user);
        $limit = (int)($plan['tg_notice_limit'] ?? 0);
        $used = (int)($user['tg_notice_used_month'] ?? 0);
        if ($limit > 0 && $used >= $limit) {
            return false;
        }

        // 4. Get Bot Token
        $tokenRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'tg_bot_token'");
        $token = $tokenRow['value'] ?? '';
        if (empty($token)) return false;

        // 5. Send Message
        $result = self::sendRaw($token, $user['tg_chat_id'], $message);
        
        // 6. Track monthly usage
        if ($result) {
            $db->query("UPDATE users SET tg_notice_used_month = tg_notice_used_month + 1 WHERE id = ?", [$user_id]);
        }
        
        return $result;
    }

    // Test send for settings page:
    // - bypass user type/channel toggles
    // - no monthly quota consumption
    public static function sendTestToUser($user_id, $message) {
        $db = Database::getInstance();
        $user = $db->fetch("SELECT id, tg_chat_id, plan_id FROM users WHERE id = ?", [$user_id]);
        if (!$user || empty($user['tg_chat_id'])) return false;

        $plan = $db->fetch("SELECT allow_tg_bot FROM plans WHERE id = ?", [$user['plan_id']]);
        if (!$plan || !$plan['allow_tg_bot']) return false;

        $tokenRow = $db->fetch("SELECT value FROM system_settings WHERE key_name = 'tg_bot_token'");
        $token = $tokenRow['value'] ?? '';
        if (empty($token)) return false;

        return self::sendRaw($token, $user['tg_chat_id'], $message);
    }

    private static function resetMonthlyCountersIfNeeded($db, &$user) {
        $ym = date('Y-m');
        if (($user['notice_cycle_ym'] ?? '') === $ym) {
            return;
        }

        $db->query(
            "UPDATE users
             SET notice_cycle_ym = ?, tg_notice_used_month = 0, email_notice_used_month = 0
             WHERE id = ?",
            [$ym, $user['id']]
        );

        $user['notice_cycle_ym'] = $ym;
        $user['tg_notice_used_month'] = 0;
    }

    private static function sendRaw($token, $chat_id, $message) {
        $url = "https://api.telegram.org/bot{$token}/sendMessage";
        $data = [
            'chat_id' => $chat_id,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200;
    }
}
