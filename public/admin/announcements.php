<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();
require_once __DIR__ . '/../../src/Core/Database.php';
require_once __DIR__ . '/../../src/Services/NotificationDispatcher.php';
$db = Database::getInstance();

$message = '';

// Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        http_response_code(403);
        if ($_POST['action'] === 'toggle') {
            echo 'forbidden';
            exit;
        }
        $message = '请求已被拒绝（CSRF 校验失败）';
    } else {
        if ($_POST['action'] === 'create') {
            $title = $_POST['title'];
            $content = $_POST['content'];
            $db->query("INSERT INTO announcements (title, content, is_active) VALUES (?, ?, 1)", [$title, $content]);
            $sentUsers = NotificationDispatcher::broadcastAnnouncement((string)$title, (string)$content);
            $message = '公告发布成功，已触达通知用户：' . (int)$sentUsers;
        }
        // Delete
        if ($_POST['action'] === 'delete') {
            $id = (int)$_POST['id'];
            $db->query("DELETE FROM announcements WHERE id = ?", [$id]);
            $message = '公告已删除';
        }
        // Toggle Status
        if ($_POST['action'] === 'toggle') {
            $id = (int)$_POST['id'];
            $current = $db->fetch("SELECT is_active FROM announcements WHERE id = ?", [$id])['is_active'];
            $new = $current ? 0 : 1;
            $db->query("UPDATE announcements SET is_active = ? WHERE id = ?", [$new, $id]);
            echo 'ok';
            exit; // AJAX response
        }
    }
}

$per_page = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$total_row = $db->fetch("SELECT COUNT(*) AS c FROM announcements");
$total = (int)($total_row['c'] ?? 0);
$pages = max(1, (int)ceil($total / $per_page));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $per_page;
$announcements = $db->fetchAll("SELECT * FROM announcements ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$active_menu = 'announcements';
require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="fw-bold mb-0">系统公告管理</h4>
    <button class="btn btn-primary rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#createModal">
        <i class="fas fa-plus me-2"></i>发布公告
    </button>
</div>

<?php if($message): ?>
    <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" role="alert">
        <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="mole-card p-0 overflow-hidden">
    <div class="table-responsive">
        <table class="table table-custom mb-0">
            <thead>
                <tr>
                    <th style="width: 50px;">ID</th>
                    <th>标题</th>
                    <th>内容摘要</th>
                    <th>状态</th>
                    <th>发布时间</th>
                    <th class="text-end">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($announcements as $a): ?>
                <tr>
                    <td class="text-secondary">#<?php echo $a['id']; ?></td>
                    <td class="fw-medium"><?php echo htmlspecialchars($a['title']); ?></td>
                    <td class="text-secondary small text-truncate" style="max-width: 300px;">
                        <?php echo htmlspecialchars(mb_strimwidth($a['content'], 0, 50, '...')); ?>
                    </td>
                    <td>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" 
                                   <?php echo $a['is_active'] ? 'checked' : ''; ?>
                                   onchange="toggleStatus(<?php echo $a['id']; ?>)">
                        </div>
                    </td>
                    <td class="text-secondary small"><?php echo $a['created_at']; ?></td>
                    <td class="text-end">
                        <form method="POST" class="d-inline" onsubmit="return confirm('确定要删除吗？');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo $a['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-light text-danger border">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($announcements)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">暂无公告数据</td>
                </tr>
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

<!-- Create Modal -->
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
            <input type="hidden" name="action" value="create">
            <div class="modal-header border-bottom-0">
                <h5 class="modal-title fw-bold">发布新公告</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">公告标题</label>
                    <input type="text" name="title" class="form-control" required placeholder="请输入标题...">
                </div>
                <div class="mb-3">
                    <label class="form-label">公告内容</label>
                    <textarea name="content" class="form-control" rows="5" required placeholder="请输入详细内容..."></textarea>
                </div>
            </div>
            <div class="modal-footer border-top-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">取消</button>
                <button type="submit" class="btn btn-primary px-4 rounded-pill">立即发布</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleStatus(id) {
    fetch('announcements.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=toggle&id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent('<?php echo $admin_csrf_token; ?>')
    });
}
</script>

<?php require_once 'includes/footer.php'; ?>
