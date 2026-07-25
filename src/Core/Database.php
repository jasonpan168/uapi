<?php
// src/Core/Database.php

class Database {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        require_once __DIR__ . '/../../config/config.php';
        if (empty(DB_HOST) || empty(DB_NAME) || empty(DB_USER)) {
            if (php_sapi_name() !== 'cli') {
                http_response_code(500);
                echo "Database configuration missing.";
                exit;
            }
            fwrite(STDERR, "DB configuration missing\n");
            exit(1);
        }
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $isApi = strpos($uri, '/api/') !== false;
            if (php_sapi_name() === 'cli') {
                fwrite(STDERR, "DB Connection Failed: " . $e->getMessage() . PHP_EOL);
                exit(1);
            }
            if ($isApi) {
                if (!headers_sent()) {
                    header('Content-Type: application/json');
                }
                http_response_code(500);
                echo json_encode(['status'=>'error','error'=>'数据库连接失败']);
                exit;
            } else {
                if (!headers_sent()) {
                    http_response_code(500);
                    echo "Database Connection Error.";
                }
                exit;
            }
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->pdo;
    }

    public function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetch($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }
    
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }

    /**
     * Automatically ensure database tables and columns exist.
     * This is a self-healing mechanism for seamless upgrades.
     */
    public function autoMigrate() {
        try {
            // 1. payment_links
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS payment_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                price_type VARCHAR(50) DEFAULT 'fixed',
                amount DECIMAL(20, 6) DEFAULT 0,
                currency VARCHAR(10) DEFAULT 'USDT',
                chain VARCHAR(20) DEFAULT 'trc20',
                receive_mode VARCHAR(20) DEFAULT 'wallet',
                redirect_url TEXT,
                status VARCHAR(20) DEFAULT 'active',
                views INT DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $this->ensureColumn('payment_links', 'receive_mode', "VARCHAR(20) DEFAULT 'wallet'");
            $this->ensureColumn('payment_links', 'currency', "VARCHAR(10) DEFAULT 'USDT'");

            // 2. qr_codes
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS qr_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                chain VARCHAR(20) DEFAULT 'trc20',
                style VARCHAR(50) DEFAULT 'default',
                status VARCHAR(20) DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS websites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                domain VARCHAR(255) NOT NULL,
                category VARCHAR(50) DEFAULT 'other',
                status ENUM('active','banned') DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY user_domain (user_id, domain)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $this->ensureUniqueIndex('websites', 'uniq_domain_global', ['domain']);

            // 3. Update orders table columns
            // Helper to add column safely
            $this->ensureColumn('orders', 'notify_url', 'TEXT');
            $this->ensureColumn('orders', 'notify_status', "VARCHAR(20) DEFAULT 'pending'");
            $this->ensureColumn('orders', 'notify_retries', "INT DEFAULT 0");
            $this->ensureColumn('orders', 'last_notify_at', "DATETIME NULL");
            $this->ensureColumn('orders', 'source', "VARCHAR(50) DEFAULT 'api'");
            $this->ensureColumn('orders', 'source_id', "INT DEFAULT 0");
            $this->ensureColumn('orders', 'pay_access_token', "VARCHAR(64) DEFAULT NULL");
            $this->ensureColumn('orders', 'merchant_order_unique', "VARCHAR(128) DEFAULT NULL");
            $this->ensureColumn('orders', 'pay_provider', "VARCHAR(30) DEFAULT NULL");
            $this->ensureColumn('orders', 'order_origin', "VARCHAR(30) DEFAULT 'merchant_customer_order'");
            $this->ensureColumn('orders', 'paid_at', "DATETIME DEFAULT NULL");
            $this->ensureColumn('orders', 'refund_status', "VARCHAR(20) DEFAULT NULL");
            $this->ensureColumn('orders', 'refund_amount', "DECIMAL(20,6) DEFAULT 0");
            $this->ensureColumn('orders', 'refund_count', "INT DEFAULT 0");
            $this->ensureColumn('orders', 'refund_request_id', "VARCHAR(80) DEFAULT NULL");
            $this->ensureColumn('orders', 'refund_reason', "VARCHAR(255) DEFAULT NULL");
            $this->ensureColumn('orders', 'refunded_at', "DATETIME DEFAULT NULL");
            $this->ensureColumn('orders', 'refund_notification_sent_at', "DATETIME DEFAULT NULL");
            // One-time backfill: mark existing refunded orders as notification already sent
            // so they are never re-sent after the column is first added.
            try {
                $this->query(
                    "UPDATE orders SET refund_notification_sent_at = COALESCE(refunded_at, NOW())
                     WHERE refund_notification_sent_at IS NULL
                       AND refund_status IN ('full', 'partial')"
                );
            } catch (\Throwable $ignore) {}
            $this->ensureColumn('orders', 'upgrade_prev_plan_id', "INT DEFAULT NULL");
            $this->ensureColumn('orders', 'upgrade_prev_expire_at', "DATETIME DEFAULT NULL");
            $this->ensureColumn('orders', 'upgrade_fast_sync_grant', "INT DEFAULT 0");
            $this->ensureColumn('orders', 'binance_pay_order_id', "VARCHAR(120) DEFAULT NULL");
            $this->ensureColumn('orders', 'binance_payer_uid', "VARCHAR(64) DEFAULT NULL");
            $this->ensureColumn('orders', 'binance_open_user_id', "VARCHAR(64) DEFAULT NULL");
            $this->ensureColumn('orders', 'binance_merchant_id', "VARCHAR(64) DEFAULT NULL");
            $this->ensureUniqueIndex('orders', 'uniq_merchant_order_unique', ['merchant_order_unique']);
            $this->ensureColumn('users', 'webhook_url', "VARCHAR(255) DEFAULT NULL");
            $this->ensureColumn('users', 'binance_uid', "VARCHAR(64) DEFAULT NULL");
            $this->ensureColumn('users', 'notification_settings', "TEXT");
            $this->ensureColumn('users', 'tg_chat_id', "VARCHAR(64) DEFAULT NULL");
            $this->ensureColumn('users', 'notice_cycle_ym', "VARCHAR(7) DEFAULT NULL");
            $this->ensureColumn('users', 'tg_notice_used_month', "INT DEFAULT 0");
            $this->ensureColumn('users', 'email_notice_used_month', "INT DEFAULT 0");
            $this->ensureColumn('users', 'email_notice_address', "VARCHAR(191) DEFAULT NULL");
            $this->ensureColumn('users', 'email_notice_use_custom_smtp', "TINYINT(1) DEFAULT 0");
            $this->ensureColumn('users', 'smtp_host', "VARCHAR(191) DEFAULT NULL");
            $this->ensureColumn('users', 'smtp_port', "INT DEFAULT NULL");
            $this->ensureColumn('users', 'smtp_username', "VARCHAR(191) DEFAULT NULL");
            $this->ensureColumn('users', 'smtp_password', "VARCHAR(255) DEFAULT NULL");
            $this->ensureColumn('users', 'smtp_encryption', "VARCHAR(10) DEFAULT 'tls'");
            $this->ensureColumn('users', 'smtp_from_name', "VARCHAR(191) DEFAULT NULL");
            $this->ensureColumn('users', 'smtp_from_email', "VARCHAR(191) DEFAULT NULL");
            $this->ensureColumn('users', 'full_name', "VARCHAR(120) DEFAULT NULL");
            $this->ensureColumn('users', 'phone', "VARCHAR(32) DEFAULT NULL");
            $this->ensureColumn('users', 'company_name', "VARCHAR(120) DEFAULT NULL");
            $this->ensureColumn('users', 'country_region', "VARCHAR(80) DEFAULT NULL");
            $this->ensureColumn('users', 'user_timezone', "VARCHAR(64) DEFAULT NULL");
            $this->ensureColumn('users', 'two_factor_enabled', "TINYINT(1) DEFAULT 0");
            $this->ensureColumn('users', 'two_factor_secret', "VARCHAR(64) DEFAULT NULL");
            $this->ensureColumn('users', 'two_factor_enabled_at', "DATETIME DEFAULT NULL");
            $this->ensureColumn('users', 'two_factor_scenes', "TEXT NULL");
            $this->ensureColumn('users', 'email_verified', "TINYINT(1) DEFAULT 1");
            $this->ensureColumn('users', 'email_verified_at', "DATETIME DEFAULT NULL");
            $this->ensureColumn('users', 'email_verify_token', "VARCHAR(80) DEFAULT NULL");
            $this->ensureColumn('users', 'email_verify_expires_at', "DATETIME DEFAULT NULL");
            $this->ensureColumn('users', 'referral_rate_override', "DECIMAL(8,4) DEFAULT NULL");
            $this->ensureColumn('users', 'api_request_total', "INT NOT NULL DEFAULT 0");
            $this->ensureColumn('users', 'balance', "DECIMAL(20,6) DEFAULT 0");
            $this->ensureColumn('users', 'withdrawable_balance', "DECIMAL(20,6) DEFAULT 0");
            $this->ensureColumn('users', 'fast_sync_remaining', "INT DEFAULT 0");
            $this->ensureColumn('users', 'withdraw_address', "VARCHAR(100) DEFAULT NULL");
            $this->ensureColumn('users', 'withdraw_network', "VARCHAR(10) DEFAULT 'TRC20'");
            $this->ensureColumn('users', 'ref_code', "VARCHAR(16) DEFAULT NULL");
            $this->ensureColumn('users', 'ref_by', "INT DEFAULT NULL");
            $this->ensureColumn('users', 'failed_attempts', "INT DEFAULT 0");
            $this->ensureColumn('users', 'locked_until', "DATETIME DEFAULT NULL");
            $this->ensureColumn('plans', 'allow_tg_bot', "TINYINT(1) DEFAULT 0");
            $this->ensureColumn('plans', 'tg_notice_limit', "INT DEFAULT 0");
            $this->ensureColumn('plans', 'sync_interval', "INT DEFAULT 10");
            $this->ensureColumn('plans', 'fast_sync_limit', "INT DEFAULT 0");
            $this->ensureColumn('plans', 'allow_email_notice', "TINYINT(1) DEFAULT 0");
            $this->ensureColumn('plans', 'email_notice_limit', "INT DEFAULT 0");
            $this->ensureColumn('plans', 'allow_webhook_notice', "TINYINT(1) DEFAULT 1");
            $this->ensureColumn('plans', 'allow_derived_wallet', "TINYINT(1) DEFAULT 0");
            $this->ensureColumn('chains', 'allow_derived', "TINYINT(1) DEFAULT 1");
            $this->ensureColumn('chains', 'usdc_contract', "VARCHAR(64) DEFAULT NULL");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS plan_chain_derived (
                id INT AUTO_INCREMENT PRIMARY KEY,
                plan_id INT NOT NULL,
                chain_id INT NOT NULL,
                enabled TINYINT(1) DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_plan_chain (plan_id, chain_id),
                KEY idx_plan (plan_id),
                KEY idx_chain (chain_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_request_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id INT NULL,
                chain VARCHAR(32) DEFAULT '',
                api_provider VARCHAR(100) DEFAULT '',
                api_name VARCHAR(100) DEFAULT '',
                status_code INT DEFAULT 0,
                billable TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_order_created (order_id, created_at),
                INDEX idx_created_at (created_at),
                INDEX idx_provider_name (api_provider, api_name),
                INDEX idx_billable_created (billable, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_usage_daily (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                usage_date DATE NOT NULL,
                used_count INT NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_date (user_id, usage_date),
                INDEX idx_usage_date (usage_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            // 4. Admin Coupons
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_coupons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) NOT NULL UNIQUE,
                type ENUM('fixed', 'percent') NOT NULL DEFAULT 'fixed',
                value DECIMAL(10,2) NOT NULL,
                usage_limit INT DEFAULT -1,
                used_count INT DEFAULT 0,
                expiry_date DATETIME DEFAULT NULL,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 5. Store Coupons
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS store_coupons (
                id INT AUTO_INCREMENT PRIMARY KEY,
                store_id INT NOT NULL,
                code VARCHAR(50) NOT NULL,
                type ENUM('fixed', 'percent') NOT NULL DEFAULT 'fixed',
                value DECIMAL(10,2) NOT NULL,
                usage_limit INT DEFAULT -1,
                used_count INT DEFAULT 0,
                expiry_date DATETIME DEFAULT NULL,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (store_id),
                UNIQUE KEY `unique_store_code` (`store_id`, `code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS store_coupon_usages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                coupon_id INT NOT NULL,
                store_id INT NOT NULL,
                order_id INT NOT NULL,
                order_no VARCHAR(64) NOT NULL,
                product_id INT DEFAULT 0,
                product_name VARCHAR(255) DEFAULT NULL,
                discount_amount DECIMAL(20,6) DEFAULT 0,
                currency VARCHAR(10) DEFAULT 'USDT',
                paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_order_coupon_usage (order_id),
                INDEX idx_store_coupon (store_id, coupon_id),
                INDEX idx_paid_at (paid_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 6. Logistics & Physical Products
            $this->ensureColumn('store_products', 'is_physical', "TINYINT(1) DEFAULT 0");
            $this->ensureColumn('store_products', 'requires_shipping', "TINYINT(1) DEFAULT 0");
            $this->ensureColumn('store_products', 'weight', "DECIMAL(10,2) DEFAULT 0.00");
            
            $this->ensureColumn('orders', 'shipping_info', "TEXT DEFAULT NULL COMMENT 'JSON: name, phone, address, city, etc'");
            $this->ensureColumn('orders', 'tracking_number', "VARCHAR(100) DEFAULT NULL");
            $this->ensureColumn('orders', 'logistics_company', "VARCHAR(100) DEFAULT NULL");
            $this->ensureColumn('orders', 'logistics_status', "ENUM('pending', 'shipped', 'delivered', 'returned') DEFAULT 'pending'");
            $this->ensureColumn('orders', 'coupon_code', "VARCHAR(50) DEFAULT NULL");
            $this->ensureColumn('orders', 'discount_amount', "DECIMAL(10,2) DEFAULT 0.00");
            $this->ensureColumn('orders', 'product_id', "INT DEFAULT 0");
            $this->ensureColumn('orders', 'customer_email', "VARCHAR(191) DEFAULT NULL");
            $this->ensureColumn('orders', 'receipt_sent_at', "DATETIME DEFAULT NULL");
            $this->ensureColumn('stores', 'logo_url', "VARCHAR(255) DEFAULT NULL");

            // 7. Support Tickets
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                subject VARCHAR(255) NOT NULL,
                status ENUM('open', 'answered', 'closed') DEFAULT 'open',
                priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
                ticket_type VARCHAR(20) DEFAULT 'support',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX (user_id),
                INDEX (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $this->ensureColumn('tickets', 'ticket_type', "VARCHAR(20) DEFAULT 'support'");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS ticket_replies (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ticket_id INT NOT NULL,
                user_id INT NOT NULL,
                is_admin TINYINT(1) DEFAULT 0,
                message TEXT NOT NULL,
                attachment VARCHAR(255) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX (ticket_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 8. Notification delivery logs (TG/Email usage analytics)
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS notification_send_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                channel VARCHAR(20) NOT NULL,
                notice_type VARCHAR(50) DEFAULT 'system',
                status VARCHAR(20) DEFAULT 'success',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_created_at (created_at),
                INDEX idx_user (user_id),
                INDEX idx_channel (channel),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 9. Admin derived wallet sweep (EVM, non-custodial)
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_derived_wallets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chain_slug VARCHAR(32) NOT NULL,
                derivation_path VARCHAR(120) DEFAULT NULL,
                address VARCHAR(64) NOT NULL,
                source_type VARCHAR(20) DEFAULT 'manual',
                xpub_hint VARCHAR(40) DEFAULT NULL,
                status TINYINT(1) DEFAULT 1,
                last_balance_wei VARCHAR(80) DEFAULT '0',
                last_balance_display DECIMAL(30,6) DEFAULT 0,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_chain_addr (chain_slug, address),
                INDEX idx_chain_status (chain_slug, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_collection_batches (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chain_slug VARCHAR(32) NOT NULL,
                chain_id INT DEFAULT 0,
                token_symbol VARCHAR(16) DEFAULT 'USDT',
                token_contract VARCHAR(64) DEFAULT NULL,
                token_decimals INT DEFAULT 6,
                master_address VARCHAR(64) NOT NULL,
                total_items INT DEFAULT 0,
                total_amount_display DECIMAL(30,6) DEFAULT 0,
                status VARCHAR(20) DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_chain_created (chain_slug, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_collection_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                batch_id INT NOT NULL,
                wallet_id INT NOT NULL,
                from_address VARCHAR(64) NOT NULL,
                to_address VARCHAR(64) NOT NULL,
                amount_wei VARCHAR(80) NOT NULL,
                amount_display DECIMAL(30,6) DEFAULT 0,
                qr_payload TEXT,
                eip681_uri TEXT,
                status VARCHAR(20) DEFAULT 'pending_sign',
                tx_hash VARCHAR(120) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_batch (batch_id),
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS admin_fee_address_allocations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                wallet_id INT NOT NULL,
                wallet_table_id INT DEFAULT NULL,
                chain_slug VARCHAR(32) NOT NULL,
                address VARCHAR(100) NOT NULL,
                order_no VARCHAR(32) DEFAULT NULL,
                purpose VARCHAR(40) DEFAULT 'merchant_fee',
                allocated_to_user_id INT DEFAULT NULL,
                allocated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_wallet_once (wallet_id),
                UNIQUE KEY uniq_order_no (order_no),
                INDEX idx_chain (chain_slug),
                INDEX idx_user (allocated_to_user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 10. Merchant derived wallets
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS derived_wallets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                address VARCHAR(64) NOT NULL,
                private_key VARCHAR(120) DEFAULT NULL,
                batch_id VARCHAR(32) DEFAULT NULL,
                status VARCHAR(20) DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                INDEX idx_batch (batch_id),
                UNIQUE KEY uniq_address (address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 11. API IP Whitelist
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS api_ip_whitelist (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                ip_address VARCHAR(64) NOT NULL,
                label VARCHAR(100) DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id),
                UNIQUE KEY uniq_user_ip (user_id, ip_address)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 12. Cron Heartbeats (for monitoring)
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS cron_heartbeats (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_name VARCHAR(50) NOT NULL,
                last_run_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                run_count INT DEFAULT 0,
                last_status VARCHAR(20) DEFAULT 'ok',
                last_message VARCHAR(255) DEFAULT NULL,
                UNIQUE KEY uniq_job (job_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 13. Telegram bind codes (random nonce for TG binding verification)
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS tg_bind_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                chat_id VARCHAR(64) NOT NULL,
                code VARCHAR(16) NOT NULL,
                used TINYINT(1) DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_chat_code (chat_id, code),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            // 14. Broadcast logs
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS broadcast_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                target VARCHAR(100) DEFAULT 'all',
                recipient_count INT DEFAULT 0,
                sent_count INT DEFAULT 0,
                status VARCHAR(20) DEFAULT 'pending',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        } catch (Exception $e) {
            // Log error but don't crash if possible
            error_log("AutoMigrate Error: " . $e->getMessage());
        }
    }

    private static function isValidIdentifier($name) {
        return preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name) === 1;
    }

    private function ensureColumn($table, $column, $definition) {
        if (!self::isValidIdentifier($table) || !self::isValidIdentifier($column)) {
            error_log("[autoMigrate] ensureColumn rejected invalid identifier: $table.$column");
            return;
        }
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (Exception $e) {
            error_log("[autoMigrate] ensureColumn($table, $column) failed: " . $e->getMessage());
        }
    }

    private function ensureUniqueIndex($table, $indexName, $columns) {
        if (!self::isValidIdentifier($table) || !self::isValidIdentifier($indexName)) {
            error_log("[autoMigrate] ensureUniqueIndex rejected invalid identifier: $table.$indexName");
            return;
        }
        foreach ($columns as $col) {
            if (!self::isValidIdentifier($col)) {
                error_log("[autoMigrate] ensureUniqueIndex rejected invalid column: $col");
                return;
            }
        }
        try {
            $stmt = $this->pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = " . $this->pdo->quote($indexName));
            if ($stmt->rowCount() == 0) {
                $cols = implode('`,`', $columns);
                $this->pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `$indexName` (`$cols`)");
            }
        } catch (Exception $e) {
            error_log("[autoMigrate] ensureUniqueIndex($table, $indexName) failed: " . $e->getMessage());
        }
    }
}
