<?php
require_once __DIR__ . '/../src/Core/Database.php';
$db = Database::getInstance();

try {
    // 1. Add role and expire_at to users
    $db->query("ALTER TABLE users ADD COLUMN role ENUM('user', 'admin') DEFAULT 'user' AFTER status");
    $db->query("ALTER TABLE users ADD COLUMN expire_at DATETIME NULL AFTER role");
    // NOTE: the old "promote user id=1 to admin" statement was removed on purpose.
    // Fresh installs get a proper admin account via Migrator::seedDefaultAdmin().

    // 2. Create system_settings table
    $db->query("CREATE TABLE IF NOT EXISTS `system_settings` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `key_name` varchar(50) NOT NULL,
      `value` text,
      `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `key_name` (`key_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Insert default settings
    $defaults = [
        'site_name' => 'UAPI',
        'tron_api_provider' => 'tronscan', // tronscan, trongrid, local
        'tron_api_key' => '',
        'eth_api_key' => '', // Etherscan
        'bsc_api_key' => '', // BscScan
        'stripe_public_key' => '',
        'stripe_secret_key' => '',
        'usdt_admin_wallet' => '', // For membership payment
        'payment_method' => 'usdt', // usdt, stripe
    ];

    foreach ($defaults as $k => $v) {
        $db->query("INSERT IGNORE INTO system_settings (key_name, value) VALUES (?, ?)", [$k, $v]);
    }

    // 3. Update plans table for more flexible pricing
    $db->query("ALTER TABLE plans ADD COLUMN price_quarterly DECIMAL(10,2) DEFAULT 0.00 AFTER price_monthly");
    $db->query("ALTER TABLE plans ADD COLUMN price_yearly DECIMAL(10,2) DEFAULT 0.00 AFTER price_quarterly");
    
    // Update default prices
    $db->query("UPDATE plans SET price_quarterly = price_monthly * 3 * 0.9, price_yearly = price_monthly * 12 * 0.8 WHERE price_monthly > 0");

    echo "Database upgrade successful.\n";

} catch (Exception $e) {
    echo "Upgrade failed (may be already upgraded): " . $e->getMessage() . "\n";
}
