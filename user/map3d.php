<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/explore_ui.php';
include __DIR__ . '/partial/header.php';
?>
<div id="layoutSidenav" class="d-lg-flex">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content" class="col-lg-9 col-xl-10">
        <main class="p-0"><?php explore_render("staff"); ?>
<?php include __DIR__ . '/partial/footer.php'; ?>
