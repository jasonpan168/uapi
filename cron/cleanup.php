<?php
ini_set('memory_limit', '256M');
require_once __DIR__ . '/../src/Core/Database.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.");
}

echo "Starting Cleanup...\n";

$db = Database::getInstance();
$db->autoMigrate();

// Record heartbeat for cleanup cron
try {
    $db->query(
        "INSERT INTO cron_heartbeats (job_name, last_run_at, run_count, last_status, last_message)
         VALUES ('cleanup', NOW(), 1, 'ok', 'started')
         ON DUPLICATE KEY UPDATE
             last_run_at = NOW(),
             run_count = run_count + 1,
             last_status = 'ok',
             last_message = 'started'"
    );
} catch (Throwable $e) {}

// 1. Clean api_logs older than 7 days
$result = $db->query("DELETE FROM api_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
$count = $result->rowCount();
echo "Deleted $count old api_logs (>7 days).\n";

// 2. Clean api_request_logs older than 7 days
$result = $db->query("DELETE FROM api_request_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
$count = $result->rowCount();
echo "Deleted $count old api_request_logs (>7 days).\n";

// 3. Clean external_request_logs older than 7 days
$result = $db->query("DELETE FROM external_request_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
$count = $result->rowCount();
echo "Deleted $count old external_request_logs (>7 days).\n";

// 4. Clean notification_send_logs older than 30 days
$result = $db->query("DELETE FROM notification_send_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
$count = $result->rowCount();
echo "Deleted $count old notification_send_logs (>30 days).\n";

// 5. Clean expired active_sessions older than 1 day
$result = $db->query("DELETE FROM active_sessions WHERE last_active < DATE_SUB(NOW(), INTERVAL 1 DAY)");
$count = $result->rowCount();
echo "Deleted $count expired active_sessions (>1 day).\n";

// 6. Clean webhook_logs older than 30 days
$result = $db->query("DELETE FROM webhook_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
$count = $result->rowCount();
echo "Deleted $count old webhook_logs (>30 days).\n";

// 7. Mark expired pending orders as expired
// 7a. 有 expire_at 字段的：到期即过期
$result = $db->query(
    "UPDATE orders SET status = 'expired', updated_at = NOW()
     WHERE status = 'pending' AND expire_at IS NOT NULL AND expire_at < NOW()"
);
$count = $result->rowCount();
echo "Marked $count pending orders as expired (by expire_at).\n";

// 7b. 兜底：expire_at 为 NULL 但创建超过 1 小时的，也标记过期
$result = $db->query(
    "UPDATE orders SET status = 'expired', updated_at = NOW()
     WHERE status = 'pending' AND expire_at IS NULL AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
);
$count = $result->rowCount();
echo "Marked $count pending orders as expired (no expire_at, created >1h ago).\n";

// Update cleanup heartbeat status to completed
try {
    $db->query(
        "UPDATE cron_heartbeats SET last_status = 'ok', last_message = 'completed' WHERE job_name = 'cleanup'"
    );
} catch (Throwable $e) {}

echo "Cleanup completed.\n";
