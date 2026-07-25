<?php

class Auth {
    public static function login($username, $password) {
        $db = Database::getInstance();
        $user = $db->fetch("SELECT * FROM admins WHERE username = ?", [$username]);
        
        if (!$user) {
            return false;
        }
        
        // Check if locked
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return 'locked';
        }
        
        if (password_verify($password, $user['password_hash'])) {
            // Reset failures
            $db->query("UPDATE admins SET failed_attempts = 0, locked_until = NULL WHERE id = ?", [$user['id']]);
            
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            session_regenerate_id(true);
            
            // Log login
            self::logAction('login', 'admin', $user['id'], 'Admin logged in');
            return true;
        } else {
            // Increment failures
            $failures = $user['failed_attempts'] + 1;
            $locked_until = null;
            if ($failures >= 5) {
                $locked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            }
            $db->query("UPDATE admins SET failed_attempts = ?, locked_until = ? WHERE id = ?", [$failures, $locked_until, $user['id']]);
            return false;
        }
    }
    
    public static function logout() {
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_username']);
        session_destroy();
    }
    
    public static function check() {
        if (!isset($_SESSION['admin_id'])) {
            // Use relative path 'login.php' instead of absolute path
            // This ensures it works even if the site is in a subdirectory or ADMIN_PATH_PREFIX is misconfigured
            Helper::redirect('login.php');
        }
    }
    
    public static function user() {
        if (isset($_SESSION['admin_id'])) {
            return ['id' => $_SESSION['admin_id'], 'username' => $_SESSION['admin_username']];
        }
        return null;
    }
    
    public static function logAction($action, $target_type = null, $target_id = null, $details = null) {
        $db = Database::getInstance();
        $admin_id = $_SESSION['admin_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $db->query("INSERT INTO audit_logs (admin_id, action, target_type, target_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)", 
            [$admin_id, $action, $target_type, $target_id, $details, $ip]);
    }
}
