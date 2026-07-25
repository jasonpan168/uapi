<?php

if (!defined('CSRF_TOKEN_NAME')) {
    define('CSRF_TOKEN_NAME', 'csrf_token');
}

class Helper {
    private const CACHE_TTL = 30; // seconds
    private const CACHE_MAX_KEYS = 100;

    private static function cachePath(): string {
        return sys_get_temp_dir() . '/uapi_hset_' . substr(md5(__DIR__), 0, 8) . '.json';
    }

    private static function cacheRead(string $key): ?string {
        $file = self::cachePath();
        if (!file_exists($file)) return null;
        $mtime = @filemtime($file);
        if (!$mtime || (time() - $mtime) > self::CACHE_TTL) return null;
        $raw = @file_get_contents($file);
        if ($raw === false) return null;
        $data = json_decode($raw, true);
        if (!is_array($data)) return null;
        return array_key_exists($key, $data) ? (string)$data[$key] : null;
    }

    private static function cacheWrite(string $key, string $value): void {
        try {
            $file = self::cachePath();
            $data = [];
            if (file_exists($file)) {
                $raw = @file_get_contents($file);
                if ($raw !== false) {
                    $data = json_decode($raw, true) ?? [];
                }
            }
            if (count($data) > self::CACHE_MAX_KEYS) {
                $data = array_slice($data, -self::CACHE_MAX_KEYS, self::CACHE_MAX_KEYS, true);
            }
            $data[$key] = $value;
            @file_put_contents($file, json_encode($data), LOCK_EX);
        } catch (\Throwable $e) {
            // Cache failure must never break application
        }
    }

    public static function e($string) {
        return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function jsesc($string): string {
        return substr(json_encode((string)($string ?? ''), JSON_UNESCAPED_UNICODE), 1, -1);
    }

    public static function getStatusColor($status) {
        switch ($status) {
            case 'new': return 'primary';
            case 'processing': return 'info';
            case 'completed': return 'success';
            case 'rejected': return 'danger';
            case 'archived': return 'secondary';
            default: return 'secondary';
        }
    }

    public static function getStatusText($status) {
        $map = [
            'new' => '新询盘',
            'processing' => '处理中',
            'completed' => '已完成',
            'rejected' => '已拒绝',
            'archived' => '已归档'
        ];
        return $map[$status] ?? '未知状态';
    }

    public static function formatMoney($amount) {
        return number_format((float)$amount, 2);
    }

    public static function generateCsrfToken() {
        if (empty($_SESSION[CSRF_TOKEN_NAME])) {
            $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
        }
        return $_SESSION[CSRF_TOKEN_NAME];
    }

    public static function verifyCsrfToken($token) {
        if (!isset($_SESSION[CSRF_TOKEN_NAME]) || !hash_equals($_SESSION[CSRF_TOKEN_NAME], $token)) {
            return false;
        }
        return true;
    }
    
    public static function csrfField() {
        $token = self::generateCsrfToken();
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    public static function jsonResponse($data, $status = 200) {
        header('Content-Type: application/json');
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    public static function redirect($url) {
        header("Location: $url");
        exit;
    }
    
    public static function flash($key, $message = null) {
        if ($message) {
            $_SESSION['flash_' . $key] = $message;
        } else {
            if (isset($_SESSION['flash_' . $key])) {
                $msg = $_SESSION['flash_' . $key];
                unset($_SESSION['flash_' . $key]);
                return $msg;
            }
            return null;
        }
    }
    
    public static function generateRandomString($length = 10) {
        return substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil($length/strlen($x)) )),1,$length);
    }
    
    public static function getSetting($key, $default = null) {
        $defaults = [
            'site_title' => 'UAPI',
            'brand_name' => 'UAPI',
            'contact_email' => 'support@example.com',
            'contact_tg' => '@your_support',
            'hero_title' => 'Self-hosted crypto payments',
            'hero_subtitle' => 'Accept stablecoin payments across multiple chains.',
            'deposit_percentage' => '30',
            'upload_max_size' => '20971520',
            'allowed_extensions' => 'pdf,doc,docx,jpg,png,zip'
        ];

        // File cache (30s TTL)
        $cached = self::cacheRead($key);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $db = Database::getInstance();
            $row = $db->fetch("SELECT value FROM settings WHERE key_name = ?", [$key]);
            if ($row) {
                $val = (string)$row['value'];
                self::cacheWrite($key, $val);
                return $val;
            }
        } catch (Exception $e) {
            error_log("Helper::getSetting Error: " . $e->getMessage());
        }

        return $defaults[$key] ?? $default;
    }
    
    public static function setSetting($key, $value) {
        $db = Database::getInstance();
        $exists = $db->fetch("SELECT key_name FROM settings WHERE key_name = ?", [$key]);
        if ($exists) {
            $db->query("UPDATE settings SET value = ? WHERE key_name = ?", [$value, $key]);
        } else {
            $db->query("INSERT INTO settings (key_name, value) VALUES (?, ?)", [$key, $value]);
        }
        self::cacheWrite($key, (string)$value);
    }

    public static function calculateScore($inquiry) {
        $score = 0;
        $rules = [
            'budget_high' => 20, // Example logic
            'platform_match' => 10,
            'keywords' => 10,
            'attachments' => 5
        ];
        
        // Simple logic for demonstration
        if (strpos($inquiry['budget'], '5000+') !== false) $score += 20;
        elseif (strpos($inquiry['budget'], '1000-5000') !== false) $score += 10;
        
        if (!empty($inquiry['promo_video_url'])) $score += 10;
        if (!empty($inquiry['product_advantages'])) $score += 5;
        
        // Ensure score is 0-100
        return min(100, max(0, $score));
    }
    
    public static function checkRisk($ip, $email) {
        $db = Database::getInstance();
        // Check Blacklist
        $blocked = $db->fetch("SELECT id FROM risk_rules WHERE rule_type = 'block' AND (
            (target = 'ip' AND value = ?) OR 
            (target = 'email' AND value = ?) OR
            (target = 'domain' AND ? LIKE CONCAT('%', value))
        )", [$ip, $email, $email]);
        
        if ($blocked) return true; // Is risky
        return false;
    }
}
