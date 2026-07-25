<?php
if (!isset($page_title) || $page_title === '') {
    $page_title = __('merchant.nav.derived_wallets');
}
?>
<!DOCTYPE html>
<html lang="<?php echo match (I18n::getLang()) { 'zh-cn' => 'zh-CN', 'zh-tw' => 'zh-TW', 'ja' => 'ja', default => 'en' }; ?>" data-bs-theme="light">
<head>
    <?php include __DIR__ . '/user_head.php'; ?>
</head>
<body>
<div class="container-fluid g-0">
    <div class="row g-0">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="col-md-9 col-lg-10 main-content">
            <?php include __DIR__ . '/user_topbar.php'; ?>
