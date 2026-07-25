<?php
require_once __DIR__ . '/../../src/Admin/AdminAuth.php';
AdminAuth::check();

require_once __DIR__ . '/../../src/Core/Database.php';
$db = Database::getInstance();

require_once __DIR__ . '/../../src/Core/Migrator.php';
$migrator = new Migrator($db->getConnection());
$migrator->run();

$message = '';

// Handle Manual Renew / Update Plan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['admin_csrf_token']) || !hash_equals($_SESSION['admin_csrf_token'], $csrf)) {
        http_response_code(403);
        $message = "请求已被拒绝（CSRF 校验失败）。";
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'delete' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            if ($id !== (int)$_SESSION['user_id']) {
                $db->query("DELETE FROM users WHERE id = ?", [$id]);
                $message = "用户已删除。";
            }
        } elseif ($action === 'toggle_status' && isset($_POST['id'])) {
            $id = (int)$_POST['id'];
            if ($id !== (int)$_SESSION['user_id']) {
                $user = $db->fetch("SELECT status FROM users WHERE id = ?", [$id]);
                if ($user) {
                    $new_status = $user['status'] === 'active' ? 'banned' : 'active';
                    $db->query("UPDATE users SET status = ? WHERE id = ?", [$new_status, $id]);
                    $message = "用户状态已更新。";
                }
            }
        } elseif (isset($_POST['user_id'])) {
            $user_id = (int)$_POST['user_id'];
            $plan_id = (int)$_POST['plan_id'];
            $expire_date = trim((string)($_POST['expire_date'] ?? ''));
            if ($expire_date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expire_date)) {
                $message = "日期格式无效。";
            } elseif ($plan_id <= 0) {
                $message = "套餐无效。";
            } else {
                $user_exists = $db->fetch("SELECT id FROM users WHERE id = ?", [$user_id]);
                if ($user_exists) {
                    $db->query("UPDATE users SET plan_id = ?, expire_at = ? WHERE id = ?", [$plan_id, $expire_date !== '' ? $expire_date . ' 23:59:59' : null, $user_id]);
                    $message = "用户更新成功。";
                } else {
                    $message = "用户不存在。";
                }
            }
        }
    }
    header("Location: users.php?msg=" . urlencode($message));
    exit;
}

if (isset($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

// Fetch Users
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$keyword = trim((string)($_GET['q'] ?? ''));
$limit = 20;
$offset = ($page - 1) * $limit;
$where = "1=1";
$params = [];
if ($keyword !== '') {
    $where .= " AND (u.email LIKE ? OR u.id = ?)";
    $params[] = '%' . $keyword . '%';
    $params[] = (int)$keyword;
}
$users = $db->fetchAll(
    "SELECT u.*, p.name as plan_name, COUNT(o.id) as order_count
     FROM users u
     LEFT JOIN plans p ON u.plan_id = p.id
     LEFT JOIN orders o ON o.user_id = u.id
     WHERE {$where}
     GROUP BY u.id
     ORDER BY u.created_at DESC
     LIMIT {$limit} OFFSET {$offset}",
    $params
);

$total_users = (int)($db->fetch("SELECT COUNT(*) as c FROM users u WHERE {$where}", $params)['c'] ?? 0);
$total_pages = max(1, (int)ceil($total_users / $limit));
$plans = $db->fetchAll("SELECT * FROM plans");

$active_menu = 'users';
require_once 'includes/header.php';
?>

<?php if($message): ?>
    <div class="alert alert-success"><?php echo $message; ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header bg-white border-bottom">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <input type="text" class="form-control" name="q" value="<?php echo htmlspecialchars($keyword); ?>" placeholder="搜索邮箱或用户ID">
            <button class="btn btn-primary" type="submit">搜索</button>
            <?php if ($keyword !== ''): ?>
                <a class="btn btn-outline-secondary" href="users.php">清空</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead class="bg-light">
                <tr>
                    <th>ID</th>
                    <th>邮箱</th>
                    <th>角色</th>
                    <th>套餐</th>
                    <th>订单数</th>
                    <th>过期时间</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($users as $u): ?>
                <tr>
                    <td><?php echo $u['id']; ?></td>
                    <td>
                        <?php echo htmlspecialchars($u['email']); ?>
                        <br>
                        <small class="text-muted text-break" style="font-size:0.75rem"><?php echo htmlspecialchars($u['api_key']); ?></small>
                    </td>
                    <td><span class="badge bg-secondary"><?php echo $u['role']=='admin'?'管理员':'用户'; ?></span></td>
                    <td>
                        <?php
                            $planName = strtolower((string)($u['plan_name'] ?? 'free'));
                            $planClass = 'bg-secondary';
                            if (strpos($planName, 'free') !== false || strpos($planName, '免费') !== false) $planClass = 'bg-light text-dark border';
                            elseif (strpos($planName, 'pro') !== false) $planClass = 'bg-primary';
                            elseif (strpos($planName, 'business') !== false || strpos($planName, 'biz') !== false) $planClass = 'bg-warning text-dark';
                        ?>
                        <span class="badge <?php echo $planClass; ?>"><?php echo htmlspecialchars((string)$u['plan_name']); ?></span>
                    </td>
                    <td><?php echo $u['order_count']; ?></td>
                    <td>
                        <?php if($u['expire_at']): ?>
                            <span class="<?php echo strtotime($u['expire_at']) < time() ? 'text-danger fw-bold' : ''; ?>">
                                <?php echo date('Y-m-d', strtotime($u['expire_at'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">永久/无</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo $u['status']=='active'?'success':'danger'; ?>">
                            <?php echo $u['status']=='active'?'正常':'封禁'; ?>
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-primary" onclick="editUser(<?php echo htmlspecialchars(json_encode($u)); ?>)">编辑</button>
                        <?php if($u['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-<?php echo $u['status']=='active'?'warning':'success'; ?>">
                                    <?php echo $u['status']=='active'?'封禁':'解封'; ?>
                                </button>
                            </form>
                            <form method="POST" class="d-inline" onsubmit="return confirm('确定删除吗？')">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger">删除</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer">
        <nav>
            <ul class="pagination justify-content-center mb-0">
                <?php for($i=1; $i<=$total_pages; $i++): ?>
                <li class="page-item <?php echo $page==$i?'active':''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?>&q=<?php echo urlencode($keyword); ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($admin_csrf_token); ?>">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-header">
                <h5 class="modal-title">编辑用户套餐</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">邮箱</label>
                    <input type="text" class="form-control" id="edit_email" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label">套餐</label>
                    <select name="plan_id" class="form-select" id="edit_plan_id">
                        <?php foreach($plans as $p): ?>
                        <option value="<?php echo $p['id']; ?>"><?php echo $p['name']; ?> ($<?php echo $p['price_monthly']; ?>/月)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">快速延期</label>
                    <div class="btn-group w-100" role="group">
                        <button type="button" class="btn btn-outline-primary" onclick="addTime(1, 'month')">+1 个月</button>
                        <button type="button" class="btn btn-outline-primary" onclick="addTime(3, 'month')">+1 季度</button>
                        <button type="button" class="btn btn-outline-primary" onclick="addTime(1, 'year')">+1 年</button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">过期时间</label>
                    <input type="date" name="expire_date" class="form-control" id="edit_expire_date">
                    <div class="form-text">设置付费套餐的过期时间。</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">关闭</button>
                <button type="submit" class="btn btn-primary">保存更改</button>
            </div>
        </form>
    </div>
</div>

<script>
var editModal;
document.addEventListener('DOMContentLoaded', function() {
    editModal = new bootstrap.Modal(document.getElementById('editModal'));
});

function editUser(user) {
    document.getElementById('edit_user_id').value = user.id;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_plan_id').value = user.plan_id;
    
    // Format date for input type=date
    let date = '';
    if (user.expire_at) {
        // Handle full datetime string "YYYY-MM-DD HH:MM:SS"
        date = user.expire_at.split(' ')[0];
    } else {
        // If no expiry (e.g. Free plan), set to today
        date = new Date().toISOString().split('T')[0];
    }
    document.getElementById('edit_expire_date').value = date;
    
    editModal.show();
}

function addTime(amount, unit) {
    let dateField = document.getElementById('edit_expire_date');
    let currentDateStr = dateField.value;
    
    if (!currentDateStr) {
        currentDateStr = new Date().toISOString().split('T')[0];
    }
    
    let date = new Date(currentDateStr);
    
    if (unit === 'month') {
        date.setMonth(date.getMonth() + amount);
    } else if (unit === 'year') {
        date.setFullYear(date.getFullYear() + amount);
    }
    
    // Format back to YYYY-MM-DD
    let year = date.getFullYear();
    let month = (date.getMonth() + 1).toString().padStart(2, '0');
    let day = date.getDate().toString().padStart(2, '0');
    
    dateField.value = `${year}-${month}-${day}`;
}
</script>

<?php require_once 'includes/footer.php'; ?>
