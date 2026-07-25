<?php
require_once __DIR__ . '/../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../src/Core/Database.php';
$db = Database::getInstance();
$logs = $db->fetchAll("SELECT * FROM api_logs ORDER BY id DESC LIMIT 20");
echo "ID | Method | Endpoint | Status | Chain | Created\n";
foreach($logs as $l) {
    echo "{$l['id']} | {$l['method']} | {$l['endpoint']} | {$l['status_code']} | {$l['chain']} | {$l['created_at']}\n";
}
