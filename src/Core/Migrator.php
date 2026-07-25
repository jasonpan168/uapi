<?php
class Migrator {
    private $pdo;
    private $log = [];
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function run() {
        $this->ensureTables();
        $this->ensureColumns();
        $this->seedPlans();
        $this->seedSettings();
        $this->seedChains();
        $this->seedDefaultAdmin();
        return $this->log;
    }
    private function exec($sql) {
        $this->pdo->exec($sql);
    }
    private function existsTable($table) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    }
    private function existsColumn($table, $column) {
        $stmt = $this->pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    }
    private function ensureTables() {
        if (!$this->existsTable('users')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `users` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `email` varchar(100) NOT NULL,
              `password_hash` varchar(255) NOT NULL,
              `api_key` varchar(64) NOT NULL,
              `webhook_url` varchar(255) DEFAULT NULL,
              `plan_id` int(11) DEFAULT 1,
              `status` enum('active','banned') DEFAULT 'active',
              `role` enum('user','admin') DEFAULT 'user',
              `expire_at` datetime DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `email` (`email`),
              UNIQUE KEY `api_key` (`api_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'users table created';
        }
        if (!$this->existsTable('wallets')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `wallets` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `user_id` int(11) NOT NULL,
              `chain` varchar(20) NOT NULL,
              `address` varchar(100) NOT NULL,
              `status` tinyint(1) DEFAULT 1,
              PRIMARY KEY (`id`),
              KEY `user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'wallets table created';
        }
        if (!$this->existsTable('orders')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `orders` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `order_no` varchar(32) NOT NULL,
              `merchant_order_id` varchar(64) NOT NULL,
              `user_id` int(11) NOT NULL,
              `wallet_id` int(11) DEFAULT NULL,
              `amount` decimal(20,6) NOT NULL,
              `currency` varchar(10) DEFAULT 'USDT',
              `chain` varchar(20) NOT NULL,
              `status` varchar(20) DEFAULT 'pending',
              `tx_hash` varchar(100) DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `order_no` (`order_no`),
              KEY `status_idx` (`status`),
              KEY `user_idx` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'orders table created';
        }
        if (!$this->existsTable('webhook_logs')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `webhook_logs` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `order_id` int(11) NOT NULL,
              `payload` text,
              `response_code` int(11) DEFAULT NULL,
              `response_body` text,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'webhook_logs table created';
        }
        if (!$this->existsTable('plans')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `plans` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `name` varchar(50) NOT NULL,
              `price_monthly` decimal(10,2) NOT NULL,
              `api_limit_daily` int(11) DEFAULT 1000,
              `price_quarterly` decimal(10,2) DEFAULT 0.00,
              `price_yearly` decimal(10,2) DEFAULT 0.00,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'plans table created';
        }
        if (!$this->existsTable('system_settings')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `key_name` varchar(50) NOT NULL,
              `value` text,
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `key_name` (`key_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'system_settings table created';
        }
        if (!$this->existsTable('announcements')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `announcements` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `title` VARCHAR(255) NOT NULL,
                `content` TEXT NOT NULL,
                `is_active` TINYINT(1) DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'announcements table created';
        }
        if (!$this->existsTable('chains')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `chains` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `slug` varchar(32) NOT NULL,
              `name` varchar(50) NOT NULL,
              `symbol` varchar(16) DEFAULT 'USDT',
              `chain_id` int(11) DEFAULT 0,
              `is_evm` tinyint(1) DEFAULT 0,
              `status` tinyint(1) DEFAULT 1,
              `allow_derived` tinyint(1) DEFAULT 1,
              `usdc_contract` varchar(64) DEFAULT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'chains table created';
        }
        if (!$this->existsTable('plan_chains')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `plan_chains` (
              `id` int(11) NOT NULL AUTO_INCREMENT,
              `plan_id` int(11) NOT NULL,
              `chain_id` int(11) NOT NULL,
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `plan_chain` (`plan_id`,`chain_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'plan_chains table created';
        }
        if (!$this->existsTable('notifications')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `notifications` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `type` VARCHAR(50) DEFAULT 'system',
                `title` VARCHAR(255) NOT NULL,
                `content` TEXT,
                `is_read` TINYINT(1) DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_user` (`user_id`),
                INDEX `idx_read` (`is_read`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'notifications table created';
        }
        if (!$this->existsTable('transactions')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `transactions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `type` VARCHAR(32) NOT NULL,
                `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `balance_after` DECIMAL(20,6) DEFAULT NULL,
                `description` TEXT,
                `status` VARCHAR(20) DEFAULT 'completed',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_user` (`user_id`),
                INDEX `idx_user_type` (`user_id`,`type`),
                INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'transactions table created';
        }
        if (!$this->existsTable('active_sessions')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `active_sessions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `session_token` VARCHAR(128) NOT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_id` INT DEFAULT NULL,
                `order_no` VARCHAR(64) DEFAULT NULL,
                `user_agent` VARCHAR(255) DEFAULT NULL,
                `last_heartbeat` DATETIME DEFAULT NULL,
                `status` VARCHAR(20) DEFAULT 'active',
                `last_active` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_session_token` (`session_token`),
                INDEX `idx_order_no` (`order_no`),
                INDEX `idx_status_heartbeat` (`status`,`last_heartbeat`),
                INDEX `idx_ip` (`ip_address`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'active_sessions table created';
        }
        if (!$this->existsTable('blocked_ips')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `blocked_ips` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `ip_address` VARCHAR(45) NOT NULL,
                `reason` VARCHAR(255) DEFAULT NULL,
                `expires_at` DATETIME DEFAULT NULL,
                `blocked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_ip` (`ip_address`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'blocked_ips table created';
        }
        if (!$this->existsTable('stores')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `stores` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `slug` VARCHAR(191) NOT NULL,
                `description` TEXT,
                `contact_info` TEXT,
                `logo_url` VARCHAR(255) DEFAULT NULL,
                `status` VARCHAR(20) DEFAULT 'active',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_slug` (`slug`),
                INDEX `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'stores table created';
        }
        if (!$this->existsTable('store_products')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `store_products` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `store_id` INT NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `price` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `description` TEXT,
                `category` VARCHAR(100) DEFAULT NULL,
                `image_url` VARCHAR(500) DEFAULT NULL,
                `is_featured` TINYINT(1) DEFAULT 0,
                `features` TEXT,
                `faq` TEXT,
                `is_physical` TINYINT(1) DEFAULT 0,
                `requires_shipping` TINYINT(1) DEFAULT 0,
                `weight` DECIMAL(10,2) DEFAULT 0.00,
                `status` VARCHAR(20) DEFAULT 'active',
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_store` (`store_id`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'store_products table created';
        }
        if (!$this->existsTable('binance_pay_links')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `binance_pay_links` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `merchant_trade_no` VARCHAR(64) NOT NULL,
                `title` VARCHAR(255) DEFAULT NULL,
                `description` TEXT,
                `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `currency` VARCHAR(10) DEFAULT 'USDT',
                `checkout_url` TEXT,
                `qr_url` TEXT,
                `source` VARCHAR(32) DEFAULT 'payment_link',
                `status` VARCHAR(32) DEFAULT 'pending',
                `binance_prepay_id` VARCHAR(120) DEFAULT NULL,
                `paid_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_merchant_trade_no` (`merchant_trade_no`),
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'binance_pay_links table created';
        }
        if (!$this->existsTable('chain_watchlist')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `chain_watchlist` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `chain` VARCHAR(32) NOT NULL,
                `query_value` VARCHAR(191) NOT NULL,
                `query_type` VARCHAR(32) DEFAULT NULL,
                `private_tag` VARCHAR(64) DEFAULT NULL,
                `private_note` VARCHAR(255) DEFAULT NULL,
                `notify_enabled` TINYINT(1) DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_user_chain_query` (`user_id`,`chain`,`query_value`),
                INDEX `idx_user_updated` (`user_id`,`updated_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'chain_watchlist table created';
        }
        if (!$this->existsTable('chain_risk_addresses')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `chain_risk_addresses` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `chain` VARCHAR(32) NOT NULL,
                `address` VARCHAR(128) NOT NULL,
                `risk_type` VARCHAR(50) DEFAULT NULL,
                `note` VARCHAR(255) DEFAULT NULL,
                `score` DECIMAL(6,2) DEFAULT 0,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_chain_address` (`chain`,`address`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'chain_risk_addresses table created';
        }
        if (!$this->existsTable('chain_address_labels')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `chain_address_labels` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `chain` VARCHAR(32) NOT NULL,
                `address` VARCHAR(128) NOT NULL,
                `label` VARCHAR(191) DEFAULT NULL,
                `label_type` VARCHAR(50) DEFAULT 'unknown',
                `confidence` DECIMAL(4,2) DEFAULT 0.50,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_chain_address` (`chain`,`address`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'chain_address_labels table created';
        }
        if (!$this->existsTable('user_balance_transfers')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `user_balance_transfers` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `from_user_id` INT NOT NULL,
                `to_user_id` INT NOT NULL,
                `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `source_bucket` VARCHAR(32) DEFAULT NULL,
                `note` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_from_user` (`from_user_id`),
                INDEX `idx_to_user` (`to_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'user_balance_transfers table created';
        }
        if (!$this->existsTable('settings')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `settings` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `key_name` VARCHAR(191) NOT NULL,
                `value` TEXT,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_key_name` (`key_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'settings table created';
        }
        if (!$this->existsTable('risk_rules')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `risk_rules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `rule_type` VARCHAR(20) NOT NULL DEFAULT 'block',
                `target` VARCHAR(20) NOT NULL,
                `value` VARCHAR(255) NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_rule` (`rule_type`,`target`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'risk_rules table created';
        }
        if (!$this->existsTable('admins')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `admins` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `username` VARCHAR(100) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `failed_attempts` INT DEFAULT 0,
                `locked_until` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uniq_username` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'admins table created';
        }
        if (!$this->existsTable('audit_logs')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `audit_logs` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `admin_id` INT DEFAULT NULL,
                `action` VARCHAR(100) NOT NULL,
                `target_type` VARCHAR(50) DEFAULT NULL,
                `target_id` VARCHAR(64) DEFAULT NULL,
                `details` TEXT,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_admin` (`admin_id`),
                INDEX `idx_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'audit_logs table created';
        }
        if (!$this->existsTable('services')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `services` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(255) NOT NULL,
                `type` VARCHAR(32) DEFAULT NULL,
                `amount` INT DEFAULT 0,
                `value` VARCHAR(255) DEFAULT NULL,
                `price` DECIMAL(20,6) NOT NULL DEFAULT 0,
                `status` TINYINT(1) DEFAULT 1,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'services table created';
        }
    }
    private function ensureColumns() {
        if ($this->existsTable('users')) {
            if (!$this->existsColumn('users','role')) { $this->exec("ALTER TABLE `users` ADD COLUMN `role` enum('user','admin') DEFAULT 'user' AFTER `status`"); $this->log[]='users.role added'; }
            if (!$this->existsColumn('users','expire_at')) { $this->exec("ALTER TABLE `users` ADD COLUMN `expire_at` datetime DEFAULT NULL AFTER `role`"); $this->log[]='users.expire_at added'; }
            if (!$this->existsColumn('users','binance_uid')) { $this->exec("ALTER TABLE `users` ADD COLUMN `binance_uid` varchar(64) DEFAULT NULL"); $this->log[]='users.binance_uid added'; }
            if (!$this->existsColumn('users','notification_settings')) { $this->exec("ALTER TABLE `users` ADD COLUMN `notification_settings` text NULL"); $this->log[]='users.notification_settings added'; }
            if (!$this->existsColumn('users','tg_chat_id')) { $this->exec("ALTER TABLE `users` ADD COLUMN `tg_chat_id` varchar(64) DEFAULT NULL"); $this->log[]='users.tg_chat_id added'; }
            if (!$this->existsColumn('users','notice_cycle_ym')) { $this->exec("ALTER TABLE `users` ADD COLUMN `notice_cycle_ym` varchar(7) DEFAULT NULL"); $this->log[]='users.notice_cycle_ym added'; }
            if (!$this->existsColumn('users','tg_notice_used_month')) { $this->exec("ALTER TABLE `users` ADD COLUMN `tg_notice_used_month` int DEFAULT 0"); $this->log[]='users.tg_notice_used_month added'; }
            if (!$this->existsColumn('users','email_notice_used_month')) { $this->exec("ALTER TABLE `users` ADD COLUMN `email_notice_used_month` int DEFAULT 0"); $this->log[]='users.email_notice_used_month added'; }
            if (!$this->existsColumn('users','email_notice_address')) { $this->exec("ALTER TABLE `users` ADD COLUMN `email_notice_address` varchar(191) DEFAULT NULL"); $this->log[]='users.email_notice_address added'; }
            if (!$this->existsColumn('users','email_notice_use_custom_smtp')) { $this->exec("ALTER TABLE `users` ADD COLUMN `email_notice_use_custom_smtp` tinyint(1) DEFAULT 0"); $this->log[]='users.email_notice_use_custom_smtp added'; }
            if (!$this->existsColumn('users','smtp_host')) { $this->exec("ALTER TABLE `users` ADD COLUMN `smtp_host` varchar(191) DEFAULT NULL"); $this->log[]='users.smtp_host added'; }
            if (!$this->existsColumn('users','smtp_port')) { $this->exec("ALTER TABLE `users` ADD COLUMN `smtp_port` int DEFAULT NULL"); $this->log[]='users.smtp_port added'; }
            if (!$this->existsColumn('users','smtp_username')) { $this->exec("ALTER TABLE `users` ADD COLUMN `smtp_username` varchar(191) DEFAULT NULL"); $this->log[]='users.smtp_username added'; }
            if (!$this->existsColumn('users','smtp_password')) { $this->exec("ALTER TABLE `users` ADD COLUMN `smtp_password` varchar(255) DEFAULT NULL"); $this->log[]='users.smtp_password added'; }
            if (!$this->existsColumn('users','smtp_encryption')) { $this->exec("ALTER TABLE `users` ADD COLUMN `smtp_encryption` varchar(10) DEFAULT 'tls'"); $this->log[]='users.smtp_encryption added'; }
            if (!$this->existsColumn('users','smtp_from_name')) { $this->exec("ALTER TABLE `users` ADD COLUMN `smtp_from_name` varchar(191) DEFAULT NULL"); $this->log[]='users.smtp_from_name added'; }
            if (!$this->existsColumn('users','smtp_from_email')) { $this->exec("ALTER TABLE `users` ADD COLUMN `smtp_from_email` varchar(191) DEFAULT NULL"); $this->log[]='users.smtp_from_email added'; }
            if (!$this->existsColumn('users','full_name')) { $this->exec("ALTER TABLE `users` ADD COLUMN `full_name` varchar(120) DEFAULT NULL"); $this->log[]='users.full_name added'; }
            if (!$this->existsColumn('users','phone')) { $this->exec("ALTER TABLE `users` ADD COLUMN `phone` varchar(32) DEFAULT NULL"); $this->log[]='users.phone added'; }
            if (!$this->existsColumn('users','company_name')) { $this->exec("ALTER TABLE `users` ADD COLUMN `company_name` varchar(120) DEFAULT NULL"); $this->log[]='users.company_name added'; }
            if (!$this->existsColumn('users','country_region')) { $this->exec("ALTER TABLE `users` ADD COLUMN `country_region` varchar(80) DEFAULT NULL"); $this->log[]='users.country_region added'; }
            if (!$this->existsColumn('users','user_timezone')) { $this->exec("ALTER TABLE `users` ADD COLUMN `user_timezone` varchar(64) DEFAULT NULL"); $this->log[]='users.user_timezone added'; }
            if (!$this->existsColumn('users','two_factor_enabled')) { $this->exec("ALTER TABLE `users` ADD COLUMN `two_factor_enabled` tinyint(1) DEFAULT 0"); $this->log[]='users.two_factor_enabled added'; }
            if (!$this->existsColumn('users','two_factor_secret')) { $this->exec("ALTER TABLE `users` ADD COLUMN `two_factor_secret` varchar(64) DEFAULT NULL"); $this->log[]='users.two_factor_secret added'; }
            if (!$this->existsColumn('users','two_factor_enabled_at')) { $this->exec("ALTER TABLE `users` ADD COLUMN `two_factor_enabled_at` datetime DEFAULT NULL"); $this->log[]='users.two_factor_enabled_at added'; }
            if (!$this->existsColumn('users','two_factor_scenes')) { $this->exec("ALTER TABLE `users` ADD COLUMN `two_factor_scenes` text NULL"); $this->log[]='users.two_factor_scenes added'; }
            if (!$this->existsColumn('users','email_verified')) { $this->exec("ALTER TABLE `users` ADD COLUMN `email_verified` tinyint(1) DEFAULT 1"); $this->log[]='users.email_verified added'; }
            if (!$this->existsColumn('users','email_verified_at')) { $this->exec("ALTER TABLE `users` ADD COLUMN `email_verified_at` datetime DEFAULT NULL"); $this->log[]='users.email_verified_at added'; }
            if (!$this->existsColumn('users','email_verify_token')) { $this->exec("ALTER TABLE `users` ADD COLUMN `email_verify_token` varchar(80) DEFAULT NULL"); $this->log[]='users.email_verify_token added'; }
            if (!$this->existsColumn('users','email_verify_expires_at')) { $this->exec("ALTER TABLE `users` ADD COLUMN `email_verify_expires_at` datetime DEFAULT NULL"); $this->log[]='users.email_verify_expires_at added'; }
            if (!$this->existsColumn('users','referral_rate_override')) { $this->exec("ALTER TABLE `users` ADD COLUMN `referral_rate_override` decimal(8,4) DEFAULT NULL"); $this->log[]='users.referral_rate_override added'; }
        }
        if ($this->existsTable('orders')) {
            if (!$this->existsColumn('orders','pay_access_token')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `pay_access_token` varchar(64) DEFAULT NULL"); $this->log[]='orders.pay_access_token added'; }
            if (!$this->existsColumn('orders','merchant_order_unique')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `merchant_order_unique` varchar(128) DEFAULT NULL"); $this->log[]='orders.merchant_order_unique added'; }
            if (!$this->existsColumn('orders','pay_provider')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `pay_provider` varchar(30) DEFAULT NULL"); $this->log[]='orders.pay_provider added'; }
            if (!$this->existsColumn('orders','order_origin')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `order_origin` varchar(30) DEFAULT 'merchant_customer_order'"); $this->log[]='orders.order_origin added'; }
            if (!$this->existsColumn('orders','paid_at')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `paid_at` datetime DEFAULT NULL"); $this->log[]='orders.paid_at added'; }
            if (!$this->existsColumn('orders','refund_status')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `refund_status` varchar(20) DEFAULT NULL"); $this->log[]='orders.refund_status added'; }
            if (!$this->existsColumn('orders','refund_amount')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `refund_amount` decimal(20,6) DEFAULT 0"); $this->log[]='orders.refund_amount added'; }
            if (!$this->existsColumn('orders','refund_count')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `refund_count` int DEFAULT 0"); $this->log[]='orders.refund_count added'; }
            if (!$this->existsColumn('orders','refund_request_id')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `refund_request_id` varchar(80) DEFAULT NULL"); $this->log[]='orders.refund_request_id added'; }
            if (!$this->existsColumn('orders','refund_reason')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `refund_reason` varchar(255) DEFAULT NULL"); $this->log[]='orders.refund_reason added'; }
            if (!$this->existsColumn('orders','refunded_at')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `refunded_at` datetime DEFAULT NULL"); $this->log[]='orders.refunded_at added'; }
            if (!$this->existsColumn('orders','upgrade_prev_plan_id')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `upgrade_prev_plan_id` int DEFAULT NULL"); $this->log[]='orders.upgrade_prev_plan_id added'; }
            if (!$this->existsColumn('orders','upgrade_prev_expire_at')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `upgrade_prev_expire_at` datetime DEFAULT NULL"); $this->log[]='orders.upgrade_prev_expire_at added'; }
            if (!$this->existsColumn('orders','upgrade_fast_sync_grant')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `upgrade_fast_sync_grant` int DEFAULT 0"); $this->log[]='orders.upgrade_fast_sync_grant added'; }
            if (!$this->existsColumn('orders','binance_pay_order_id')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `binance_pay_order_id` varchar(120) DEFAULT NULL"); $this->log[]='orders.binance_pay_order_id added'; }
            if (!$this->existsColumn('orders','binance_payer_uid')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `binance_payer_uid` varchar(64) DEFAULT NULL"); $this->log[]='orders.binance_payer_uid added'; }
            if (!$this->existsColumn('orders','binance_open_user_id')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `binance_open_user_id` varchar(64) DEFAULT NULL"); $this->log[]='orders.binance_open_user_id added'; }
            if (!$this->existsColumn('orders','binance_merchant_id')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `binance_merchant_id` varchar(64) DEFAULT NULL"); $this->log[]='orders.binance_merchant_id added'; }
            if (!$this->existsColumn('orders','customer_email')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `customer_email` varchar(191) DEFAULT NULL"); $this->log[]='orders.customer_email added'; }
            if (!$this->existsColumn('orders','receipt_sent_at')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `receipt_sent_at` datetime DEFAULT NULL"); $this->log[]='orders.receipt_sent_at added'; }
            if (!$this->existsColumn('orders','is_fast_sync')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `is_fast_sync` tinyint(1) DEFAULT 0"); $this->log[]='orders.is_fast_sync added'; }
            if (!$this->existsColumn('orders','expire_at')) { $this->exec("ALTER TABLE `orders` ADD COLUMN `expire_at` datetime DEFAULT NULL"); $this->log[]='orders.expire_at added'; }
            try {
                $this->exec("ALTER TABLE `orders` ADD UNIQUE KEY `uniq_merchant_order_unique` (`merchant_order_unique`)");
                $this->log[]='orders.uniq_merchant_order_unique added';
            } catch (Exception $e) {}
        }
        if ($this->existsTable('stores')) {
            if (!$this->existsColumn('stores','logo_url')) { $this->exec("ALTER TABLE `stores` ADD COLUMN `logo_url` varchar(255) DEFAULT NULL"); $this->log[]='stores.logo_url added'; }
        }
        if ($this->existsTable('plans')) {
            if (!$this->existsColumn('plans','price_quarterly')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `price_quarterly` decimal(10,2) DEFAULT 0.00 AFTER `price_monthly`"); $this->log[]='plans.price_quarterly added'; }
            if (!$this->existsColumn('plans','price_yearly')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `price_yearly` decimal(10,2) DEFAULT 0.00 AFTER `price_quarterly`"); $this->log[]='plans.price_yearly added'; }
            if (!$this->existsColumn('plans','sync_interval')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `sync_interval` int DEFAULT 10"); $this->log[]='plans.sync_interval added'; }
            if (!$this->existsColumn('plans','fast_sync_limit')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `fast_sync_limit` int DEFAULT 0"); $this->log[]='plans.fast_sync_limit added'; }
            if (!$this->existsColumn('plans','allow_tg_bot')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `allow_tg_bot` tinyint(1) DEFAULT 0"); $this->log[]='plans.allow_tg_bot added'; }
            if (!$this->existsColumn('plans','tg_notice_limit')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `tg_notice_limit` int DEFAULT 0"); $this->log[]='plans.tg_notice_limit added'; }
            if (!$this->existsColumn('plans','allow_email_notice')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `allow_email_notice` tinyint(1) DEFAULT 0"); $this->log[]='plans.allow_email_notice added'; }
            if (!$this->existsColumn('plans','email_notice_limit')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `email_notice_limit` int DEFAULT 0"); $this->log[]='plans.email_notice_limit added'; }
            if (!$this->existsColumn('plans','allow_webhook_notice')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `allow_webhook_notice` tinyint(1) DEFAULT 1"); $this->log[]='plans.allow_webhook_notice added'; }
            if (!$this->existsColumn('plans','allow_derived_wallet')) { $this->exec("ALTER TABLE `plans` ADD COLUMN `allow_derived_wallet` tinyint(1) DEFAULT 0"); $this->log[]='plans.allow_derived_wallet added'; }
        }
        if ($this->existsTable('chains')) {
            if (!$this->existsColumn('chains','allow_derived')) { $this->exec("ALTER TABLE `chains` ADD COLUMN `allow_derived` tinyint(1) DEFAULT 1"); $this->log[]='chains.allow_derived added'; }
        }
        if (!$this->existsTable('admin_fee_address_allocations')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `admin_fee_address_allocations` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `wallet_id` int(11) NOT NULL,
                `wallet_table_id` int(11) DEFAULT NULL,
                `chain_slug` varchar(32) NOT NULL,
                `address` varchar(100) NOT NULL,
                `order_no` varchar(32) DEFAULT NULL,
                `purpose` varchar(40) DEFAULT 'merchant_fee',
                `allocated_to_user_id` int(11) DEFAULT NULL,
                `allocated_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_wallet_once` (`wallet_id`),
                UNIQUE KEY `uniq_order_no` (`order_no`),
                KEY `idx_chain` (`chain_slug`),
                KEY `idx_user` (`allocated_to_user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'admin_fee_address_allocations table created';
        }
        if (!$this->existsTable('plan_chain_derived')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `plan_chain_derived` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `plan_id` int(11) NOT NULL,
                `chain_id` int(11) NOT NULL,
                `enabled` tinyint(1) DEFAULT 1,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_plan_chain` (`plan_id`,`chain_id`),
                KEY `idx_plan` (`plan_id`),
                KEY `idx_chain` (`chain_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'plan_chain_derived table created';
        }
        if (!$this->existsTable('notification_send_logs')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `notification_send_logs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) DEFAULT NULL,
                `channel` varchar(20) NOT NULL,
                `notice_type` varchar(50) DEFAULT 'system',
                `status` varchar(20) DEFAULT 'success',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_created_at` (`created_at`),
                KEY `idx_user` (`user_id`),
                KEY `idx_channel` (`channel`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'notification_send_logs table created';
        }
        if (!$this->existsTable('binance_webhook_logs')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `binance_webhook_logs` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `order_no` varchar(64) DEFAULT NULL,
                `event_type` varchar(64) DEFAULT NULL,
                `verify_status` varchar(20) DEFAULT 'pending',
                `process_status` varchar(20) DEFAULT 'pending',
                `error_message` text,
                `request_headers` text,
                `request_body` longtext,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order_no` (`order_no`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'binance_webhook_logs table created';
        }
        if (!$this->existsTable('admin_derived_wallets')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `admin_derived_wallets` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `chain_slug` varchar(32) NOT NULL,
                `derivation_path` varchar(120) DEFAULT NULL,
                `address` varchar(64) NOT NULL,
                `source_type` varchar(20) DEFAULT 'manual',
                `xpub_hint` varchar(40) DEFAULT NULL,
                `status` tinyint(1) DEFAULT 1,
                `last_balance_wei` varchar(80) DEFAULT '0',
                `last_balance_display` decimal(30,6) DEFAULT 0,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_chain_addr` (`chain_slug`,`address`),
                KEY `idx_chain_status` (`chain_slug`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'admin_derived_wallets table created';
        }
        if (!$this->existsTable('admin_collection_batches')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `admin_collection_batches` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `chain_slug` varchar(32) NOT NULL,
                `chain_id` int(11) DEFAULT 0,
                `token_symbol` varchar(16) DEFAULT 'USDT',
                `token_contract` varchar(64) DEFAULT NULL,
                `token_decimals` int(11) DEFAULT 6,
                `master_address` varchar(64) NOT NULL,
                `total_items` int(11) DEFAULT 0,
                `total_amount_display` decimal(30,6) DEFAULT 0,
                `status` varchar(20) DEFAULT 'pending',
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_chain_created` (`chain_slug`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'admin_collection_batches table created';
        }
        if (!$this->existsTable('admin_collection_items')) {
            $this->exec("CREATE TABLE IF NOT EXISTS `admin_collection_items` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `batch_id` int(11) NOT NULL,
                `wallet_id` int(11) NOT NULL,
                `from_address` varchar(64) NOT NULL,
                `to_address` varchar(64) NOT NULL,
                `amount_wei` varchar(80) NOT NULL,
                `amount_display` decimal(30,6) DEFAULT 0,
                `qr_payload` text,
                `eip681_uri` text,
                `status` varchar(20) DEFAULT 'pending_sign',
                `tx_hash` varchar(120) DEFAULT NULL,
                `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_batch` (`batch_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $this->log[] = 'admin_collection_items table created';
        }
    }
    private function seedPlans() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM plans");
        if ((int)$stmt->fetchColumn() === 0) {
            $this->exec("INSERT INTO `plans` (`name`,`price_monthly`,`api_limit_daily`,`price_quarterly`,`price_yearly`) VALUES
            ('Free',0.00,100,0.00,0.00),
            ('Pro',29.00,5000,78.30,278.40),
            ('Business',99.00,50000,267.30,950.40)");
            $this->log[] = 'plans seeded';
        }
    }
    private function seedChains() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM chains");
        if ((int)$stmt->fetchColumn() === 0) {
            // Mirrors $chains_config in config/config.php (slug => display name / EVM chain id)
            $this->exec("INSERT INTO `chains` (`slug`,`name`,`symbol`,`chain_id`,`is_evm`,`status`,`allow_derived`) VALUES
            ('trc20','Tron','USDT',0,0,1,0),
            ('bsc','BSC','USDT',56,1,1,1),
            ('eth','Ethereum','USDT',1,1,1,1),
            ('polygon','Polygon','USDT',137,1,1,1),
            ('optimism','Optimism','USDT',10,1,1,1),
            ('arbitrum','Arbitrum One','USDT',42161,1,1,1),
            ('base','Base','USDT',8453,1,1,1),
            ('avalanche','Avalanche','USDT',43114,1,1,1)");
            $this->log[] = 'chains seeded';
        }
        // Fresh install: give every plan access to every chain so merchants can
        // pick a network immediately. Admin can restrict per-plan later.
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM plan_chains");
        if ((int)$stmt->fetchColumn() === 0) {
            $this->exec("INSERT INTO plan_chains (plan_id, chain_id) SELECT p.id, c.id FROM plans p CROSS JOIN chains c");
            $this->log[] = 'plan_chains seeded (all plans x all chains)';
        }
    }
    /**
     * Seed a default administrator account on a fresh install (users table empty).
     *
     * Default credentials (documented in the install guide):
     *   Email:    admin@example.com   (login on /login.php is by EMAIL —
     *                                  the users table has no username column;
     *                                  "admin" is stored in full_name for display)
     *   Password: admin123
     *
     * Login chain this must satisfy: public/login.php checks users.email +
     * password_verify(users.password_hash) + users.email_verified = 1, then
     * routes role='admin' to /admin/index.php where src/Admin/AdminAuth.php
     * re-checks users.role = 'admin' via $_SESSION['user_id'].
     *
     * SECURITY NOTE: these credentials ship publicly with the open-source
     * release. We intentionally do NOT force a password change in code (no
     * such flow exists); instead a `default_admin_seeded` flag is written to
     * system_settings so the UI/docs can warn, and the CLI installer prints a
     * loud "change this password immediately" reminder. Seeding only happens
     * when the users table is completely empty, so it can never overwrite or
     * add accounts on an existing deployment (idempotent).
     */
    private function seedDefaultAdmin() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users");
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }
        $ins = $this->pdo->prepare(
            "INSERT INTO users (email, password_hash, api_key, plan_id, status, role, email_verified, full_name)
             VALUES (?, ?, ?, 1, 'active', 'admin', 1, ?)"
        );
        $ins->execute([
            'admin@example.com',
            password_hash('admin123', PASSWORD_DEFAULT),
            'pk_live_' . bin2hex(random_bytes(16)),
            'admin',
        ]);
        // Marker for UI/docs: default credentials are in place until the operator changes them.
        $mark = $this->pdo->prepare("INSERT INTO system_settings (key_name, value) VALUES ('default_admin_seeded', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
        $mark->execute([date('Y-m-d H:i:s')]);
        $this->log[] = 'default admin seeded: admin@example.com / admin123 — CHANGE THIS PASSWORD IMMEDIATELY';
    }
    private function seedSettings() {
        $defaults = [
            'site_name'=>'UAPI',
            'seo_title'=>'UAPI - 非托管加密货币支付网关 | TRC20 SOL EVM API v1.0',
            'seo_description'=>'UAPI 提供非托管链上收款服务，支持 TRC20、SOL 与 EVM 兼容链。几分钟完成 API 集成，支持支付回调、订单追踪与自动对账，资产直达个人钱包。',
            'seo_keywords'=>'UAPI,加密货币支付,USDT收款,非托管支付,TRC20支付,SOL支付,EVM支付,链上收款,支付API,Webhook,crypto payment gateway,USDT payment API',
            'seo_og_image'=>'',
            'seo_canonical'=>'',
            'tron_api_provider'=>'tronscan',
            'tron_api_key'=>'',
            'eth_api_key'=>'',
            'bsc_api_key'=>'',
            'stripe_public_key'=>'',
            'stripe_secret_key'=>'',
            'enable_payment_binance'=>'0',
            'binance_pay_base_url'=>'https://bpay.binanceapi.com',
            'binance_pay_api_key'=>'',
            'binance_pay_api_secret'=>'',
            'binance_pay_certificate_sn'=>'',
            'binance_pay_webhook_secret'=>'',
            'usdt_admin_wallet'=>'',
            'payment_method'=>'usdt',
            'smtp_enabled'=>'0',
            'smtp_host'=>'',
            'smtp_port'=>'587',
            'smtp_username'=>'',
            'smtp_password'=>'',
            'smtp_encryption'=>'tls',
            'smtp_from_name'=>'UAPI',
            'smtp_from_email'=>'',
            'admin_fee_address_mode'=>'fixed'
        ];
        foreach ($defaults as $k=>$v) {
            $stmt = $this->pdo->prepare("SELECT 1 FROM system_settings WHERE key_name = ?");
            $stmt->execute([$k]);
            if (!$stmt->fetchColumn()) {
                $ins = $this->pdo->prepare("INSERT INTO system_settings (key_name, value) VALUES (?, ?)");
                $ins->execute([$k, $v]);
                $this->log[] = "setting $k inserted";
            }
        }
    }
}
