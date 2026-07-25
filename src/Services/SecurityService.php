<?php
// src/Services/SecurityService.php

class SecurityService {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Check if IP is banned
     */
    public function checkBlocked($ip) {
        $row = $this->db->fetch("SELECT * FROM blocked_ips WHERE ip_address = ?", [$ip]);
        if ($row) {
            if ($row['expires_at'] && strtotime($row['expires_at']) < time()) {
                // Expired
                $this->db->query("DELETE FROM blocked_ips WHERE id = ?", [$row['id']]);
                return false;
            }
            return $row['reason'] ?? 'IP Blocked';
        }
        return false;
    }
    
    /**
     * Track Payment Page Concurrency
     * Returns: true (Allowed), false (Blocked)
     */
    public function trackPaymentPage($order_no, $session_token, $ip, $is_admin = false) {
        if ($is_admin) return true; // Admins are exempt
        
        // 1. Cleanup old sessions (probabilistic)
        if (rand(1, 20) == 1) {
            $this->db->query("UPDATE active_sessions SET status = 'closed' WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 45 SECOND) AND status = 'active'");
        }
        
        // 2. Checkout monitoring is globally unique per order.
        // If any other active session is monitoring the same order, block it.
        $active = $this->db->fetch("SELECT * FROM active_sessions 
            WHERE order_no = ?
            AND status = 'active' 
            AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 30 SECOND)
            AND session_token != ?
            LIMIT 1", [$order_no, $session_token]);
            
        if ($active) {
            // Found another active tab
            return false;
        }
        
        // 3. Update or Insert current session
        $exists = $this->db->fetch("SELECT id FROM active_sessions WHERE session_token = ?", [$session_token]);
        
        $user_id = $_SESSION['user_id'] ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        // Limit UA length
        $ua = substr($ua, 0, 255);
        
        if ($exists) {
            $this->db->query("UPDATE active_sessions SET last_heartbeat = NOW(), status = 'active', ip_address = ?, order_no = ? WHERE id = ?", [$ip, $order_no, $exists['id']]);
        } else {
            $this->db->query("INSERT INTO active_sessions (session_token, ip_address, user_id, order_no, user_agent, last_heartbeat, status) VALUES (?, ?, ?, ?, ?, NOW(), 'active')", 
                [$session_token, $ip, $user_id, $order_no, $ua]);
        }
        
        return true;
    }
    
    public function banIp($ip, $reason, $minutes = 60) {
        $expires = date('Y-m-d H:i:s', time() + ($minutes * 60));
        try {
            $this->db->query("INSERT INTO blocked_ips (ip_address, reason, expires_at) VALUES (?, ?, ?)", [$ip, $reason, $expires]);
        } catch (Exception $e) {
            // Already exists, update expiry
            $this->db->query("UPDATE blocked_ips SET expires_at = ?, reason = ? WHERE ip_address = ?", [$expires, $reason, $ip]);
        }
    }
    
    public function unbanIp($ip) {
        $this->db->query("DELETE FROM blocked_ips WHERE ip_address = ?", [$ip]);
    }

    /**
     * Check if the given IP is allowed to call this user's API.
     * If no whitelist entries exist for the user, all IPs are allowed (backward-compatible).
     * @return bool true = allowed, false = blocked
     */
    public function checkApiIpWhitelist($user_id, $ip) {
        $rows = $this->db->fetchAll(
            "SELECT ip_address FROM api_ip_whitelist WHERE user_id = ?",
            [(int)$user_id]
        );
        if (empty($rows)) {
            return true; // No whitelist configured = open to all (backward compatible)
        }
        foreach ($rows as $row) {
            if ($row['ip_address'] === $ip) {
                return true;
            }
        }
        return false;
    }

    public function clearSessions($ip, $order_no = null) {
        if ($order_no !== null && $order_no !== '') {
            $this->db->query("DELETE FROM active_sessions WHERE ip_address = ? AND order_no = ?", [$ip, $order_no]);
            return;
        }
        $this->db->query("DELETE FROM active_sessions WHERE ip_address = ?", [$ip]);
    }

    public function clearOrderSessions($order_no) {
        $this->db->query("DELETE FROM active_sessions WHERE order_no = ?", [$order_no]);
    }

    public function checkRateLimit($ip, $endpoint, $limit = 60, $window = 60) {
        $count = $this->db->fetch("SELECT COUNT(*) as c FROM api_logs 
            WHERE ip_address = ? AND endpoint = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)", 
            [$ip, $endpoint, $window])['c'];
            
        return $count < $limit;
    }
    
    public function logRequest($user_id, $endpoint, $method, $chain, $ip, $status_code = 200) {
        $this->db->query("INSERT INTO api_logs (user_id, endpoint, method, chain, ip_address, status_code) VALUES (?, ?, ?, ?, ?, ?)", 
            [$user_id, $endpoint, $method, $chain, $ip, $status_code]);
    }
}
