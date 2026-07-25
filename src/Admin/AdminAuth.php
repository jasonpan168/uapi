<?php
class AdminAuth {
    public static function check() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id'])) {
            header("Location: /login.php");
            exit;
        }

        require_once __DIR__ . '/../Core/Database.php';
        require_once __DIR__ . '/../Core/I18n.php';
        I18n::init();
        $db = Database::getInstance();
        $user = $db->fetch("SELECT role FROM users WHERE id = ?", [$_SESSION['user_id']]);
        
        if (!$user || $user['role'] !== 'admin') {
            self::renderForbiddenPage($db, $user['role'] ?? null);
        }
    }

    private static function renderForbiddenPage($db, $currentRole = null) {
        http_response_code(403);

        $cfg = [];
        try {
            $settings = $db->fetchAll("SELECT key_name, value FROM system_settings");
            foreach ($settings as $s) {
                $cfg[(string)$s['key_name']] = (string)$s['value'];
            }
        } catch (Exception $e) {
            // Keep fallback defaults when settings query is unavailable.
        }

        $site_name = $cfg['site_name'] ?? 'UAPI';
        $site_logo = trim((string)($cfg['site_logo'] ?? ''));
        $is_en = I18n::getLang() === 'en';
        $tt = static function (string $zh, string $en) use ($is_en): string {
            return $is_en ? $en : $zh;
        };

        $roleLabel = '-';
        if ($currentRole === 'admin') {
            $roleLabel = $tt('管理员', 'Admin');
        } elseif ($currentRole === 'merchant' || $currentRole === 'user') {
            $roleLabel = $tt('商户', 'Merchant');
        } elseif (is_string($currentRole) && $currentRole !== '') {
            $roleLabel = htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8');
        }
        ?>
<!DOCTYPE html>
<html lang="<?php echo $is_en ? 'en' : 'zh-CN'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($tt('访问被拒绝', 'Access Denied')); ?> | <?php echo htmlspecialchars($site_name); ?></title>
    <meta name="description" content="<?php echo htmlspecialchars($tt('当前账户没有管理员权限，无法访问该页面。', 'Current account does not have admin permission for this page.')); ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
            background: radial-gradient(ellipse at top left, rgba(59,130,246,.15), transparent 55%), #f8fafc;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            width: 100%;
            max-width: 860px;
            border-radius: 24px;
            border: 1px solid #e2e8f0;
            background: rgba(255,255,255,.92);
            box-shadow: 0 18px 48px rgba(15,23,42,.08);
            padding: 28px;
        }
        .brand-logo {
            max-height: 32px;
            width: auto;
            display: block;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="flex items-center justify-between gap-4 mb-6">
            <a href="/" class="flex items-center gap-2 text-slate-900 no-underline">
                <?php if ($site_logo !== ''): ?>
                    <img src="<?php echo htmlspecialchars($site_logo); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" class="brand-logo">
                <?php else: ?>
                    <span class="inline-flex w-8 h-8 rounded-lg bg-blue-600 text-white items-center justify-center font-bold">U</span>
                    <span class="font-bold text-lg"><?php echo htmlspecialchars($site_name); ?></span>
                <?php endif; ?>
            </a>
            <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-700 border border-red-200">
                <i class="fa-solid fa-shield-halved"></i> 403
            </span>
        </div>

        <h1 class="text-3xl md:text-4xl font-bold mb-3"><?php echo htmlspecialchars($tt('访问被拒绝', 'Access Denied')); ?></h1>
        <p class="text-slate-600 mb-6"><?php echo htmlspecialchars($tt('该页面仅允许管理员访问。当前账户权限不足，因此系统已拦截请求。', 'This page is restricted to admin accounts. Your current account does not have sufficient privileges, so the request was blocked.')); ?></p>

        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 mb-6 text-sm leading-7">
            <div><strong><?php echo htmlspecialchars($tt('当前角色', 'Current role')); ?>:</strong> <?php echo $roleLabel; ?></div>
            <div><strong><?php echo htmlspecialchars($tt('需要角色', 'Required role')); ?>:</strong> <?php echo htmlspecialchars($tt('管理员（admin）', 'Admin (admin)')); ?></div>
            <div><strong><?php echo htmlspecialchars($tt('常见原因', 'Common reason')); ?>:</strong> <?php echo htmlspecialchars($tt('使用了商户账号访问了 /admin/ 下的后台页面。', 'A merchant account tried to access a page under /admin/.')); ?></div>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
            <a href="/login.php" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-slate-900 text-white font-semibold hover:opacity-90">
                <i class="fa-solid fa-right-to-bracket"></i>
                <?php echo htmlspecialchars($tt('返回登录并切换账号', 'Back to Login')); ?>
            </a>
            <a href="/" class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl border border-slate-300 bg-white text-slate-700 font-semibold hover:bg-slate-50">
                <i class="fa-solid fa-house"></i>
                <?php echo htmlspecialchars($tt('返回首页', 'Back Home')); ?>
            </a>
        </div>
    </div>
</body>
</html>
<?php
        exit;
    }
}
