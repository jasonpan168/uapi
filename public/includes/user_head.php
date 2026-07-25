<?php
if (!isset($db)) {
    require_once __DIR__ . '/../../src/Core/Database.php';
    $db = Database::getInstance();
}
if (!class_exists('I18n')) {
    require_once __DIR__ . '/../../src/Core/I18n.php';
}
I18n::init();
// Ensure settings are loaded
if (!isset($cfg)) {
    $settings = $db->fetchAll("SELECT * FROM system_settings");
    $cfg = [];
    foreach ($settings as $s) { $cfg[$s['key_name']] = $s['value']; }
}
$site_name = $cfg['site_name'] ?? 'UAPI';
$current_lang = I18n::getLang();
$html_lang = match ($current_lang) {
    'zh-cn' => 'zh-CN',
    'zh-tw' => 'zh-TW',
    'ja'    => 'ja',
    default => 'en',
};
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo isset($page_title) ? htmlspecialchars($page_title . ' - ' . $site_name) : htmlspecialchars($site_name); ?></title>
<?php if (!empty($cfg['site_favicon'])): ?>
<link rel="icon" href="<?php echo htmlspecialchars($cfg['site_favicon']); ?>">
<?php endif; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="/assets/css/all.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    :root {
        --bg-body: #f9fafb;
        --text-primary: #111827;
        --text-secondary: #6b7280;
        --card-bg: #ffffff;
        --accent-blue: #3b82f6;
        --border-color: #e5e7eb;
        --sidebar-bg: #ffffff;
    }
    [data-bs-theme="dark"] {
        --bg-body: #111827;
        --text-primary: #f9fafb;
        --text-secondary: #9ca3af;
        --card-bg: #1f2937;
        --accent-blue: #60a5fa;
        --border-color: #374151;
        --sidebar-bg: #1f2937;
    }
    body {
        background-color: var(--bg-body);
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: var(--text-primary);
    }
    .main-content { padding: 32px; min-height: 100vh; }
    :root { --merchant-sidebar-width: 236px; }
    @media (min-width: 768px) {
        .container-fluid.g-0 > .row.g-0 {
            display: flex;
            flex-wrap: nowrap;
            align-items: stretch;
            min-width: 0;
        }
        .container-fluid.g-0 > .row.g-0 > .sidebar {
            flex: 0 0 var(--merchant-sidebar-width) !important;
            max-width: var(--merchant-sidebar-width) !important;
            width: var(--merchant-sidebar-width) !important;
            min-width: var(--merchant-sidebar-width) !important;
        }
        .container-fluid.g-0 > .row.g-0 > .main-content {
            flex: 1 1 auto !important;
            width: auto !important;
            max-width: none !important;
            min-width: 0 !important;
            transition: all 0.3s ease;
        }
        body.merchant-sidebar-collapsed { --merchant-sidebar-width: 80px; }
    }
    @media (max-width: 767.98px) {
        .container-fluid.g-0 > .row.g-0 > .main-content {
            flex: 1 1 auto !important;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
        }
    }
    .mole-card {
        background: var(--card-bg);
        border-radius: 16px;
        border: 1px solid var(--border-color);
        box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        padding: 24px;
        height: 100%;
        transition: all 0.2s;
    }
    .mole-card:hover { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
    .stat-icon-wrapper {
        width: 48px; height: 48px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin-right: 16px; flex-shrink: 0;
    }
    .stat-label { font-size: 0.875rem; color: var(--text-secondary); font-weight: 500; margin-bottom: 4px; }
    .stat-value { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); line-height: 1.2; }
    .top-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
    .greeting { font-size: 1.5rem; font-weight: 600; color: var(--text-primary); }
    .badge-mole { padding: 4px 8px; border-radius: 6px; font-weight: 500; font-size: 0.75rem; }
    .badge-mole.success { background: rgba(16, 185, 129, 0.1); color: #10b981; }
    .badge-mole.warning { background: rgba(245, 158, 11, 0.1); color: #f59e0b; }
    .badge-mole.gray { background: rgba(107, 114, 128, 0.1); color: #6b7280; }
    .theme-toggle {
        cursor: pointer; width: 40px; height: 40px; display: flex;
        align-items: center; justify-content: center; border-radius: 50%;
        background: var(--card-bg); border: 1px solid var(--border-color);
        color: var(--text-secondary); transition: all 0.2s;
    }
    .theme-toggle:hover { color: var(--accent-blue); border-color: var(--accent-blue); }
    
    /* Table Override for Dark Mode */
    .table { color: var(--text-primary) !important; }
    .table th { border-color: var(--border-color) !important; color: var(--text-secondary) !important; background: transparent; }
    .table td { border-color: var(--border-color) !important; background: transparent !important; color: var(--text-primary) !important; }
    
    /* Sidebar Override */
    .sidebar { background-color: var(--sidebar-bg) !important; border-right-color: var(--border-color) !important; }
    .sidebar .nav-link { color: var(--text-secondary) !important; }
    .sidebar .nav-link:hover, .sidebar .nav-link.active { color: var(--accent-blue) !important; background: rgba(59,130,246,0.1) !important; }
    .sidebar-brand span { color: var(--text-primary) !important; }

    /* Global dark-mode compatibility for merchant pages */
    [data-bs-theme="dark"] .bg-white,
    [data-bs-theme="dark"] .card-header.bg-white {
        background-color: #1f2937 !important;
        color: #f3f4f6 !important;
    }
    [data-bs-theme="dark"] .bg-light,
    [data-bs-theme="dark"] .table-light,
    [data-bs-theme="dark"] .thead-light {
        background-color: #111827 !important;
        color: #d1d5db !important;
    }
    [data-bs-theme="dark"] .text-dark { color: #f3f4f6 !important; }
    [data-bs-theme="dark"] .text-muted,
    [data-bs-theme="dark"] .text-secondary { color: #9ca3af !important; }
    [data-bs-theme="dark"] .card,
    [data-bs-theme="dark"] .modal-content,
    [data-bs-theme="dark"] .dropdown-menu,
    [data-bs-theme="dark"] .list-group-item {
        background-color: #1f2937 !important;
        color: #f3f4f6 !important;
        border-color: #374151 !important;
    }
    [data-bs-theme="dark"] .form-control,
    [data-bs-theme="dark"] .form-select,
    [data-bs-theme="dark"] .input-group-text {
        background-color: #111827 !important;
        color: #f3f4f6 !important;
        border-color: #374151 !important;
    }
    [data-bs-theme="dark"] .form-control::placeholder,
    [data-bs-theme="dark"] textarea::placeholder {
        color: #9ca3af !important;
    }
    [data-bs-theme="dark"] .table tbody tr:hover > * {
        background-color: rgba(59,130,246,0.08) !important;
    }
    [data-bs-theme="dark"] .alert-light {
        background-color: #111827 !important;
        border-color: #374151 !important;
        color: #d1d5db !important;
    }

</style>
<script>
    const theme = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-bs-theme', theme);
    function toggleTheme() {
        const current = document.documentElement.getAttribute('data-bs-theme');
        const next = current === 'light' ? 'dark' : 'light';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('theme', next);
    }

</script>
<?php require_once __DIR__ . '/notify_ui.php'; ?>
