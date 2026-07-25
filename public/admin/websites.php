<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
$db = Database::getInstance();

// Create table if not exists (using Migrator later ideally)
$db->query("CREATE TABLE IF NOT EXISTS websites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    domain VARCHAR(255) NOT NULL,
    category VARCHAR(50) DEFAULT 'other',
    status ENUM('active','banned') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_domain (user_id, domain),
    UNIQUE KEY uniq_domain_global (domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        $message = "请求已被拒绝（CSRF 校验失败）。";
    } else {
        $id = (int)$_POST['id'];
        $db->query("DELETE FROM websites WHERE id = ?", [$id]);
        $message = "网站已删除。";
    }
}

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$total_row = $db->fetch("SELECT COUNT(*) AS c FROM websites");
$total = (int)($total_row['c'] ?? 0);
$pages = max(1, (int)ceil($total / $per_page));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per_page;
$websites = $db->fetchAll(
    "SELECT w.*, u.email FROM websites w LEFT JOIN users u ON w.user_id = u.id ORDER BY w.created_at DESC LIMIT $per_page OFFSET $offset"
);

$active_menu = 'websites';
require_once 'includes/header.php';
?>

<?php if($message): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="bg-light">
                <tr>
                    <th>ID</th>
                    <th>用户</th>
                    <th>域名</th>
                    <th>分类</th>
                    <th>添加时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($websites as $w): ?>
                <tr>
                    <td><?php echo $w['id']; ?></td>
                    <td><?php echo htmlspecialchars($w['email']); ?></td>
                    <td><a href="<?php echo htmlspecialchars($w['domain']); ?>" target="_blank"><?php echo htmlspecialchars($w['domain']); ?></a></td>
                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($w['category']); ?></span></td>
                    <td><?php echo $w['created_at']; ?></td>
                    <td>
                        <form method="POST" class="d-inline" onsubmit="return confirm('确定删除吗？')">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-danger">删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($websites)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">暂无数据</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="d-flex justify-content-between align-items-center p-3 border-top bg-white">
        <small class="text-muted">第 <?php echo (int)$page; ?> / <?php echo (int)$pages; ?> 页，共 <?php echo (int)$total; ?> 条</small>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary <?php echo $page <= 1 ? 'disabled' : ''; ?>" href="?page=<?php echo max(1, $page - 1); ?>">上一页</a>
            <a class="btn btn-sm btn-outline-secondary <?php echo $page >= $pages ? 'disabled' : ''; ?>" href="?page=<?php echo min($pages, $page + 1); ?>">下一页</a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
