<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}
require_once __DIR__ . '/../src/Core/Database.php';
require_once __DIR__ . '/../src/Core/I18n.php';
I18n::init();

$page_title = __('merchant.plugins.title');
?>
<!DOCTYPE html>
<html lang="<?php echo I18n::getLang() === 'en' ? 'en' : 'zh-CN'; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/includes/user_head.php'; ?>
    <style>
        .plugin-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #e5e7eb;
            height: 100%;
            background: #fff;
            border-radius: 12px;
        }
        .plugin-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
            border-color: #d1d5db;
        }
        .plugin-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .recommend-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: #10b981;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            box-shadow: 0 2px 4px rgba(16, 185, 129, 0.2);
        }
    </style>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>

        <!-- Content -->
        <div class="col-md-9 col-lg-10 main-content">
            <?php include __DIR__ . '/includes/user_topbar.php'; ?>
            
            <div class="container-fluid px-4 py-4">
                <!-- Header Section -->
                <div class="mb-4">
                    <h4 class="fw-bold mb-1"><?php echo __('merchant.plugins.title'); ?></h4>
                    <p class="text-secondary small mb-0"><?php echo __('merchant.plugins.subtitle'); ?></p>
                </div>

                <div class="row g-4">
                    <!-- WordPress Plugin -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card plugin-card border-0 position-relative">
                            <span class="recommend-badge"><?php echo __('merchant.plugins.recommended'); ?></span>
                            <div class="card-body text-center p-4">
                                <div class="plugin-icon text-primary">
                                    <i class="fab fa-wordpress"></i>
                                </div>
                                <h5 class="fw-bold mb-2">WordPress</h5>
                                <p class="text-secondary small mb-4 px-2" style="min-height: 40px;">
                                    <?php echo __('merchant.plugins.wordpress_desc'); ?>
                                </p>
                                <div class="d-grid gap-2">
                                    <a href="downloads/uapi-wordpress-plugin.zip" class="btn btn-primary btn-sm rounded-pill" download>
                                        <i class="fas fa-download me-1"></i> <?php echo __('merchant.plugins.download_wp'); ?>
                                    </a>
                                    <button class="btn btn-outline-secondary btn-sm rounded-pill" data-bs-toggle="modal" data-bs-target="#wpGuideModal">
                                        <?php echo __('merchant.plugins.view_install_guide'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- WHMCS -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card plugin-card border-0 opacity-75">
                            <div class="card-body text-center p-4">
                                <div class="plugin-icon text-info">
                                    <i class="fas fa-server"></i>
                                </div>
                                <h5 class="fw-bold mb-2"><?php echo __('merchant.plugins.whmcs_title'); ?></h5>
                                <p class="text-secondary small mb-4 px-2" style="min-height: 40px;">
                                    <?php echo __('merchant.plugins.whmcs_desc'); ?>
                                </p>
                                <div class="d-grid">
                                    <button class="btn btn-light btn-sm rounded-pill text-secondary" disabled>
                                        <i class="fas fa-clock me-1"></i> <?php echo __('merchant.plugins.coming_soon'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- RESTful API -->
                    <div class="col-md-6 col-lg-4">
                        <div class="card plugin-card border-0">
                            <div class="card-body text-center p-4">
                                <div class="plugin-icon text-dark">
                                    <i class="fas fa-code"></i>
                                </div>
                                <h5 class="fw-bold mb-2">RESTful API</h5>
                                <p class="text-secondary small mb-4 px-2" style="min-height: 40px;">
                                    <?php echo __('merchant.plugins.api_desc'); ?>
                                </p>
                                <div class="d-grid">
                                    <a href="doc.php" class="btn btn-outline-dark btn-sm rounded-pill">
                                        <i class="fas fa-book me-1"></i> <?php echo __('merchant.plugins.view_docs'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- WP Guide Modal -->
<div class="modal fade" id="wpGuideModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><?php echo __('merchant.plugins.wp_guide.title'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6><?php echo __('merchant.plugins.wp_guide.step1_title'); ?></h6>
                <p class="small text-secondary">
                    <?php echo __('merchant.plugins.wp_guide.step1_desc'); ?>
                </p>
                
                <h6 class="mt-4"><?php echo __('merchant.plugins.wp_guide.step2_title'); ?></h6>
                <p class="small text-secondary">
                    <?php echo __('merchant.plugins.wp_guide.step2_desc'); ?>
                    <br><?php echo __('merchant.plugins.wp_guide.api_base'); ?>：<code><?php echo (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]/api/v1"; ?></code>
                </p>
                
                <h6 class="mt-4"><?php echo __('merchant.plugins.wp_guide.step3_title'); ?></h6>
                <p class="small text-secondary">
                    <?php echo __('merchant.plugins.wp_guide.step3_desc'); ?><br>
                    <code>[uapi_pay amount="1.00"] <?php echo __('merchant.plugins.wp_guide.shortcode_content'); ?> [/uapi_pay]</code><br>
                    <code>[uapi_pay amount="1.00" dual="1"] ... [/uapi_pay]</code>
                </p>
                
                <h6 class="mt-4"><?php echo __('merchant.plugins.wp_guide.step4_title'); ?></h6>
                <p class="small text-secondary">
                    <?php echo __('merchant.plugins.wp_guide.step4_desc'); ?>
                </p>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
