<?php
if (!isset($db)) {
    require_once __DIR__ . '/../../src/Core/Database.php';
    $db = Database::getInstance();
}
if (!class_exists('I18n')) {
    require_once __DIR__ . '/../../src/Core/I18n.php';
    I18n::init();
}
if (!isset($_SESSION['user_id'])) return;
$user_id = $_SESSION['user_id'];

// Fetch User if not set
if (!isset($user)) {
    $user = $db->fetch("SELECT * FROM users WHERE id = ?", [$user_id]);
}

// Fetch Notifications (All recent, not just unread, so we can show read status)
$notifs = $db->fetchAll("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10", [$user_id]);

// Count unread
$unread_count = $db->fetch("SELECT count(*) as c FROM notifications WHERE user_id = ? AND is_read = 0", [$user_id])['c'];

// Fetch Announcements (Active)
$announcements = $db->fetchAll("SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT 5");

// Check read status for announcements
// We need a way to track which announcements user has read. 
// For now, let's use a cookie or local storage, or a simple 'announcement_reads' table.
// But to keep it simple and effective as per request "red dot":
// We can check if there are ANY active announcements created after user's last login or last check.
// Better approach: 'user_reads' table for announcements.
// Let's create a table on the fly if needed or just use cookie for now to simulate?
// No, the user wants "red dot", implies persistence.
// Let's add a quick table `user_read_announcements` via autoMigrate or raw query.

// Try to create the table if not exists (Silent fail if no perm, but should work)
try {
    $db->getConnection()->exec("CREATE TABLE IF NOT EXISTS user_read_announcements (
        user_id INT NOT NULL,
        announcement_id INT NOT NULL,
        read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, announcement_id)
    )");
} catch (Exception $e) {}

// Count unread announcements
$unread_ann_count = 0;
foreach ($announcements as $a) {
    $read = $db->fetch("SELECT 1 FROM user_read_announcements WHERE user_id = ? AND announcement_id = ?", [$user_id, $a['id']]);
    if (!$read) {
        $unread_ann_count++;
    }
}

// Total unread (Notifications + Announcements)
$total_unread = $unread_count + $unread_ann_count; // $unread_count was notifications from line 23

$name = explode('@', $user['email'])[0];
$current_lang = I18n::getLang();
?>
<style>
@media (max-width: 767.98px) {
    .top-header.user-topbar {
        flex-wrap: wrap;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 20px;
    }
    .user-topbar .greeting {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
        font-size: 1.125rem;
        width: 100%;
    }
    .user-topbar .user-topbar-actions {
        width: 100%;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 10px !important;
    }
    .user-topbar .dropdown-menu {
        width: min(100vw - 24px, 320px) !important;
    }
    .user-topbar .theme-toggle {
        width: 42px;
        height: 42px;
    }
}
</style>
<div class="top-header user-topbar">
    <div class="greeting">
        <button type="button" class="theme-toggle d-inline-flex d-md-none" onclick="toggleUserSidebar()" aria-label="Open menu">
            <i class="fas fa-bars"></i>
        </button>
        <?php if(basename($_SERVER['PHP_SELF']) == 'dashboard.php'): ?>
        <?php 
            $h = date('H');
            $greet = __('merchant.greet.morning');
            if ($h >= 12 && $h < 18) $greet = __('merchant.greet.afternoon');
            elseif ($h >= 18) $greet = __('merchant.greet.evening');
        ?>
        👋 <?php echo $greet; ?>，<?php echo htmlspecialchars(ucfirst($name)); ?>
        <?php else: ?>
        <?php echo isset($page_title) ? htmlspecialchars($page_title) : __('merchant.sidebar.console'); ?>
        <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-3 user-topbar-actions">
        <!-- Theme Toggle -->
        <div class="theme-toggle" onclick="toggleTheme()">
            <i class="fas fa-adjust"></i>
        </div>

        <!-- Language Switch (unified component) -->
        <?php include __DIR__ . '/lang_switcher.php'; ?>

        <!-- Notifications -->
        <div class="dropdown">
            <button class="theme-toggle position-relative" data-bs-toggle="dropdown" aria-expanded="false" id="notifDropdownBtn">
                <i class="far fa-bell"></i>
                <?php if($total_unread > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle p-1 bg-danger border border-light rounded-circle"></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow border-0 p-0" aria-labelledby="notifDropdownBtn" style="width: 320px; background: var(--card-bg); border: 1px solid var(--border-color)!important;">
                <ul class="nav nav-tabs nav-fill border-bottom border-secondary border-opacity-10" id="notifTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                            <button class="nav-link active py-3 small fw-bold" id="tab-ann-btn" data-bs-toggle="tab" data-bs-target="#tab-announcements" type="button" role="tab" aria-controls="tab-announcements" aria-selected="true" style="color: var(--text-primary); border:none; background:transparent;" onclick="event.stopPropagation()">
                            <?php echo __('merchant.topbar.announcements'); ?> <?php if($unread_ann_count>0) echo "<span class='badge bg-danger rounded-pill'>$unread_ann_count</span>"; ?>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-3 small fw-bold" id="tab-notif-btn" data-bs-toggle="tab" data-bs-target="#tab-notifications" type="button" role="tab" aria-controls="tab-notifications" aria-selected="false" style="color: var(--text-primary); border:none; background:transparent;" onclick="event.stopPropagation()">
                            <?php echo __('merchant.topbar.my_notifications'); ?> <?php if($unread_count>0) echo "<span class='badge bg-danger rounded-pill'>$unread_count</span>"; ?>
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="notifTabContent" style="max-height: 400px; overflow-y: auto;">
                    <!-- Announcements Tab -->
                    <div class="tab-pane fade show active" id="tab-announcements" role="tabpanel" aria-labelledby="tab-ann-btn">
                        <?php if(empty($announcements)): ?>
                            <div class="p-4 text-center text-muted small"><?php echo __('merchant.topbar.no_announcements'); ?></div>
                        <?php else: ?>
                            <?php foreach($announcements as $a): ?>
                            <?php 
                                $is_read = $db->fetch("SELECT 1 FROM user_read_announcements WHERE user_id = ? AND announcement_id = ?", [$user_id, $a['id']]);
                            ?>
                            <div class="p-3 border-bottom border-secondary border-opacity-10 notif-row-ann"
                                 data-type-label="<?php echo htmlspecialchars((string)__('merchant.topbar.notice'), ENT_QUOTES, 'UTF-8'); ?>"
                                 data-title="<?php echo htmlspecialchars((string)$a['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-content="<?php echo htmlspecialchars((string)$a['content'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-date="<?php echo htmlspecialchars((string)$a['created_at'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-ann-id="<?php echo (int)$a['id']; ?>"
                                 style="cursor:pointer;">
                                <div class="d-flex align-items-center mb-1">
                                    <?php if(!$is_read): ?>
                                        <span class="badge bg-danger me-2 p-1 rounded-circle" style="width:8px;height:8px;" id="ann-dot-<?php echo $a['id']; ?>"> </span>
                                    <?php endif; ?>
                                    <span class="badge bg-primary bg-opacity-10 text-primary me-2" style="font-size:10px;"><?php echo __('merchant.topbar.notice'); ?></span>
                                    <span class="fw-medium small text-dark"><?php echo htmlspecialchars($a['title']); ?></span>
                                </div>
                                <div class="text-secondary small mb-1 text-truncate" style="font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 280px;"><?php echo htmlspecialchars($a['content']); ?></div>
                                <div class="text-end text-muted" style="font-size: 10px;"><?php echo date('m-d H:i', strtotime($a['created_at'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Notifications Tab -->
                    <div class="tab-pane fade" id="tab-notifications" role="tabpanel" aria-labelledby="tab-notif-btn">
                        <?php if(empty($notifs)): ?>
                            <div class="p-4 text-center text-muted small"><?php echo __('merchant.topbar.no_notifications'); ?></div>
                        <?php else: ?>
                            <?php foreach($notifs as $n): ?>
                            <div id="notif-item-<?php echo $n['id']; ?>" class="p-3 border-bottom border-secondary border-opacity-10 position-relative notif-row-user"
                                 data-type-label="<?php echo htmlspecialchars((string)__('merchant.topbar.notification'), ENT_QUOTES, 'UTF-8'); ?>"
                                 data-title="<?php echo htmlspecialchars((string)$n['title'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-content="<?php echo htmlspecialchars((string)$n['content'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-date="<?php echo htmlspecialchars((string)$n['created_at'], ENT_QUOTES, 'UTF-8'); ?>"
                                 data-notif-id="<?php echo (int)$n['id']; ?>"
                                 style="cursor:pointer;">
                                <div class="d-flex align-items-center mb-1">
                                    <?php if($n['is_read']): ?>
                                        <span class="badge bg-success bg-opacity-10 text-success me-2" style="font-size:10px;" id="tag-<?php echo $n['id']; ?>"><?php echo __('merchant.topbar.read'); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-warning bg-opacity-10 text-warning me-2" style="font-size:10px;" id="tag-<?php echo $n['id']; ?>"><?php echo __('merchant.topbar.unread'); ?></span>
                                    <?php endif; ?>
                                    <span class="fw-medium small text-dark"><?php echo htmlspecialchars($n['title']); ?></span>
                                </div>
                                <div class="text-secondary small mb-1 text-truncate" style="font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 280px;"><?php echo htmlspecialchars($n['content']); ?></div>
                                <div class="text-end text-muted" style="font-size: 10px;"><?php echo date('m-d H:i', strtotime($n['created_at'])); ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile -->
        <div class="dropdown">
            <div class="d-flex align-items-center gap-2 px-3 py-1 rounded-pill border shadow-sm" style="background: var(--card-bg); border-color: var(--border-color)!important; cursor: pointer;" data-bs-toggle="dropdown">
                <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 14px;">
                    <?php echo strtoupper(substr($name, 0, 1)); ?>
                </div>
                <span class="fw-medium small d-none d-sm-block" style="color: var(--text-primary);"><?php echo htmlspecialchars($name); ?></span>
                <i class="fas fa-chevron-down small text-secondary ms-1" style="font-size: 10px;"></i>
            </div>
            <ul class="dropdown-menu dropdown-menu-end shadow border-0 mt-2" style="min-width: 160px;">
                <li><h6 class="dropdown-header small text-uppercase"><?php echo __('merchant.topbar.account'); ?></h6></li>
                <li><a class="dropdown-item small py-2" href="settings.php"><i class="fas fa-cog me-2 text-secondary"></i><?php echo __('merchant.topbar.settings'); ?></a></li>
                <li><a class="dropdown-item small py-2" href="balance.php"><i class="fas fa-wallet me-2 text-secondary"></i><?php echo __('merchant.topbar.assets'); ?></a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item small py-2 text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i><?php echo __('merchant.nav.logout'); ?></a></li>
            </ul>
        </div>
    </div>
</div>

<!-- Message Modal -->
<div class="modal fade" id="msgModal" tabindex="-1" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-bottom-0 pb-0">
                <h5 class="modal-title fw-bold" id="msgModalTitle"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-4">
                <h6 class="fw-bold mb-3" id="msgModalSubject"></h6>
                <div class="text-secondary small mb-4" id="msgModalContent" style="white-space: pre-wrap;"></div>
                <div class="text-end text-muted small" id="msgModalDate"></div>
            </div>
            <div class="modal-footer border-top-0 pt-0">
                <button type="button" class="btn btn-primary px-4 rounded-pill" data-bs-dismiss="modal"><?php echo __('merchant.topbar.got_it'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
function showMsg(type, title, content, date, notifId = null, annId = null) {
    document.getElementById('msgModalTitle').innerText = type + ' <?php echo jsesc(__('merchant.topbar.details')); ?>';
    document.getElementById('msgModalSubject').innerText = title;
    document.getElementById('msgModalContent').innerText = content;
    document.getElementById('msgModalDate').innerText = date;
    new bootstrap.Modal(document.getElementById('msgModal')).show();
    
    if (notifId) {
        // Update UI immediately
        const tag = document.getElementById('tag-' + notifId);
        if(tag && tag.classList.contains('text-warning')) {
             tag.className = 'badge bg-success bg-opacity-10 text-success me-2';
             tag.innerText = <?php echo json_encode(__('merchant.topbar.read')); ?>;
             
             // Decrease badge count for Notifications Tab
             const tabBadge = document.querySelector('#tab-notif-btn .badge');
             if(tabBadge) {
                 let c = parseInt(tabBadge.innerText);
                 if(c > 1) tabBadge.innerText = c - 1;
                 else tabBadge.style.display = 'none';
             }
             
             // Check total unread to remove top bell dot
             // We can't know exact total in JS without tracking both counts.
             // Just remove the main dot if both tabs have no badges? 
             // Or just let it be until refresh.
        }

        fetch('/api/v1/user/mark_read.php?id=' + notifId)
        .then(() => {});
    }
    
    if (annId) {
        // Update UI immediately for Announcement
        const dot = document.getElementById('ann-dot-' + annId);
        if(dot) {
            dot.style.display = 'none';
            
            // Decrease badge count for Announcements Tab
             const tabBadge = document.querySelector('#tab-ann-btn .badge');
             if(tabBadge) {
                 let c = parseInt(tabBadge.innerText);
                 if(c > 1) tabBadge.innerText = c - 1;
                 else tabBadge.style.display = 'none';
             }
        }
        
        // Mark as read via API
        fetch('/api/v1/user/mark_announcement_read.php?id=' + annId)
        .then(() => {});
    }
}

document.querySelectorAll('.notif-row-user').forEach(function (el) {
    el.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const typeLabel = el.getAttribute('data-type-label') || '';
        const title = el.getAttribute('data-title') || '';
        const content = el.getAttribute('data-content') || '';
        const date = el.getAttribute('data-date') || '';
        const notifId = parseInt(el.getAttribute('data-notif-id') || '0', 10) || null;
        showMsg(typeLabel, title, content, date, notifId, null);
    });
});

document.querySelectorAll('.notif-row-ann').forEach(function (el) {
    el.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const typeLabel = el.getAttribute('data-type-label') || '';
        const title = el.getAttribute('data-title') || '';
        const content = el.getAttribute('data-content') || '';
        const date = el.getAttribute('data-date') || '';
        const annId = parseInt(el.getAttribute('data-ann-id') || '0', 10) || null;
        showMsg(typeLabel, title, content, date, null, annId);
    });
});
</script>
