<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Core/Migrator.php';
$db = Database::getInstance();
$migrator = new Migrator($db->getConnection());
$log = $migrator->run();
header('Content-Type: application/json');
echo json_encode(['status'=>'ok','changes'=>$log], JSON_UNESCAPED_UNICODE);
