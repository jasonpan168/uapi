<?php
header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../../../src/Core/Database.php';

function watch_resp(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function watch_detect_type(string $q): string
{
    if (preg_match('/^0x[a-fA-F0-9]{64}$/', $q)) return 'tx';
    if (preg_match('/^0x[a-fA-F0-9]{40}$/', $q)) return 'address';
    if (preg_match('/^[0-9]+$/', $q)) return 'block';
    if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $q)) return 'address';
    if (preg_match('/^(bc1|[13])[a-zA-HJ-NP-Z0-9]{20,90}$/', $q)) return 'address';
    if (preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,90}$/', $q)) return 'tx_or_address';
    return 'unknown';
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];
$db->query("CREATE TABLE IF NOT EXISTS chain_watchlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    chain VARCHAR(32) NOT NULL,
    query_value VARCHAR(255) NOT NULL,
    query_type VARCHAR(32) DEFAULT 'unknown',
    private_tag VARCHAR(64) DEFAULT '',
    private_note VARCHAR(255) DEFAULT '',
    notify_enabled TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_chain_query (user_id, chain, query_value),
    INDEX idx_user (user_id),
    INDEX idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $items = $db->fetchAll(
        "SELECT id, chain, query_value, query_type, private_tag, private_note, notify_enabled, created_at, updated_at
         FROM chain_watchlist WHERE user_id = ? ORDER BY updated_at DESC, id DESC LIMIT 200",
        [$userId]
    );
    watch_resp(['status' => 'success', 'items' => $items]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    watch_resp(['status' => 'error', 'message' => 'Invalid payload'], 400);
}

$action = strtolower(trim((string)($input['action'] ?? '')));
if ($action === 'add') {
    $chain = strtolower(trim((string)($input['chain'] ?? 'auto')));
    $query = trim((string)($input['query'] ?? ''));
    $tag = trim((string)($input['private_tag'] ?? ''));
    $note = trim((string)($input['private_note'] ?? ''));
    $notify = isset($input['notify_enabled']) ? (int)(!!$input['notify_enabled']) : 1;
    if ($query === '' || $chain === '') {
        watch_resp(['status' => 'error', 'message' => 'Chain and query are required'], 400);
    }
    $queryType = watch_detect_type($query);
    $db->query(
        "INSERT INTO chain_watchlist (user_id, chain, query_value, query_type, private_tag, private_note, notify_enabled)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            query_type = VALUES(query_type),
            private_tag = VALUES(private_tag),
            private_note = VALUES(private_note),
            notify_enabled = VALUES(notify_enabled),
            updated_at = CURRENT_TIMESTAMP",
        [$userId, $chain, $query, $queryType, substr($tag, 0, 64), substr($note, 0, 255), $notify]
    );
    watch_resp(['status' => 'success']);
}

if ($action === 'update') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) watch_resp(['status' => 'error', 'message' => 'Invalid id'], 400);
    $tag = substr(trim((string)($input['private_tag'] ?? '')), 0, 64);
    $note = substr(trim((string)($input['private_note'] ?? '')), 0, 255);
    $notify = isset($input['notify_enabled']) ? (int)(!!$input['notify_enabled']) : null;
    if ($notify === null) {
        $db->query("UPDATE chain_watchlist SET private_tag = ?, private_note = ? WHERE id = ? AND user_id = ?", [$tag, $note, $id, $userId]);
    } else {
        $db->query("UPDATE chain_watchlist SET private_tag = ?, private_note = ?, notify_enabled = ? WHERE id = ? AND user_id = ?", [$tag, $note, $notify, $id, $userId]);
    }
    watch_resp(['status' => 'success']);
}

if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) watch_resp(['status' => 'error', 'message' => 'Invalid id'], 400);
    $db->query("DELETE FROM chain_watchlist WHERE id = ? AND user_id = ?", [$id, $userId]);
    watch_resp(['status' => 'success']);
}

watch_resp(['status' => 'error', 'message' => 'Unsupported action'], 400);
