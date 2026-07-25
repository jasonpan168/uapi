#!/usr/bin/env php
<?php
/**
 * UAPI CLI installer.
 *
 * Usage:  php scripts/install.php
 *
 * Steps:
 *   1. Load .env / config (DB_* constants)
 *   2. Test the database connection
 *   3. Run the full schema migration (src/Core/Migrator.php + Database::autoMigrate())
 *      — Migrator::run() also seeds default plans, settings, chains and, when the
 *        users table is empty, the default admin account (admin@example.com / admin123).
 *
 * Idempotent: safe to re-run at any time. Existing tables, columns, settings
 * and accounts are never overwritten.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line.\n");
}

$root = dirname(__DIR__);

echo "==============================\n";
echo " UAPI installer\n";
echo "==============================\n";

// ---------------------------------------------------------------------------
// 1. Load environment / configuration
// ---------------------------------------------------------------------------
if (!file_exists($root . '/.env') && !file_exists($root . '/config/db.php')) {
    fwrite(STDERR, "[FAIL] No .env (and no config/db.php) found.\n");
    fwrite(STDERR, "       Copy .env.example to .env, fill in DB_HOST / DB_NAME / DB_USER / DB_PASS, then re-run.\n");
    exit(1);
}

require_once $root . '/config/config.php';

if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || DB_HOST === '' || DB_NAME === '' || DB_USER === '') {
    fwrite(STDERR, "[FAIL] Database configuration incomplete (DB_HOST / DB_NAME / DB_USER).\n");
    fwrite(STDERR, "       Check your .env file.\n");
    exit(1);
}
echo "[1/3] Configuration loaded (database: " . DB_NAME . " @ " . DB_HOST . ")\n";

// ---------------------------------------------------------------------------
// 2. Test database connection
// ---------------------------------------------------------------------------
try {
    // Same DSN shape as src/Core/Database.php
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    new PDO($dsn, DB_USER, defined('DB_PASS') ? DB_PASS : '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "[2/3] Database connection OK\n";
} catch (PDOException $e) {
    fwrite(STDERR, "[FAIL] Cannot connect to the database: " . $e->getMessage() . "\n");
    fwrite(STDERR, "       Make sure the database exists and DB_* values in .env are correct.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 3. Run migrations + seeds
// ---------------------------------------------------------------------------
require_once $root . '/src/Core/Database.php';
require_once $root . '/src/Core/Migrator.php';

$db = Database::getInstance();

$migrator = new Migrator($db->getConnection());
$log = $migrator->run();   // core tables + plans/settings/chains/default-admin seeds
$db->autoMigrate();        // remaining tables and incremental columns

if (empty($log)) {
    echo "[3/3] Schema already up to date — nothing to do.\n";
} else {
    echo "[3/3] Migration applied (" . count($log) . " change(s)):\n";
    foreach ($log as $line) {
        echo "      - " . $line . "\n";
    }
}

// ---------------------------------------------------------------------------
// Result summary
// ---------------------------------------------------------------------------
$adminSeeded = false;
foreach ($log as $line) {
    if (strpos($line, 'default admin seeded') === 0) {
        $adminSeeded = true;
        break;
    }
}

echo "\n==============================\n";
echo " Installation complete\n";
echo "==============================\n";
if ($adminSeeded) {
    echo "Default admin account created:\n";
    echo "  Login URL: /login.php  (sign in with the EMAIL below)\n";
    echo "  Email:     admin@example.com\n";
    echo "  Password:  admin123\n";
    echo "\n";
    echo "  !! SECURITY: these default credentials are public knowledge.\n";
    echo "  !! Log in and change the password IMMEDIATELY.\n";
} else {
    echo "Users already exist — default admin seeding skipped.\n";
}
echo "\nNext steps: point your web server's document root at public/ and log in.\n";
exit(0);
