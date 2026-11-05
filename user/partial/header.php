<?php
// user/partial/header.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';

$fullName    = $_SESSION['full_name'] ?? 'User';
$username    = $_SESSION['username'] ?? 'guest';
$roleDisplay = role_display();

$root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
if ($root === '' || $root === '\\') $root = '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= SITE_NAME ?> — <?= h($roleDisplay) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?= $root ?>/assets/css/styles.css" rel="stylesheet">
    <link href="<?= $root ?>/assets/css/ueab-theme.css" rel="stylesheet">
</head>
<body class="sb-nav-fixed">

<nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
    <a class="navbar-brand ps-3" href="<?= $root ?>/user/dashboard.php">
        <img src="<?= $root ?>/assets/logo/ueab.png" alt="UEAB" height="35"
             class="d-inline-block align-text-top me-2 rounded-circle">
        <span>ComfortBook</span>
    </a>

    <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <ul class="navbar-nav ms-auto ms-md-0 me-3 me-lg-4">
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle d-flex align-items-center" id="userDropdown"
               href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-user-circle fa-fw me-2 fa-2x"></i>
                <span class="d-none d-lg-inline text-white small">
                    <span style="color:#ffc107;"><?= h($username) ?></span><br>
                    <small style="color:rgba(255,255,255,0.7);"><?= h($roleDisplay) ?></small>
                </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                <li class="dropdown-header text-center">
                    <strong style="color:#ffc107;"><?= h($fullName) ?></strong><br>
                    <small style="color:rgba(255,255,255,0.7);"><?= h($username) ?></small>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= $root ?>/user/profile.php">
                    <i class="fas fa-id-badge me-2"></i>My Profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= $root ?>/logout.php">
                    <i class="fas fa-sign-out-alt me-2" style="color:#dc3545;"></i>Logout</a></li>
            </ul>
        </li>
    </ul>
</nav>