<?php
// students/_layout.php — student app shell. Bootstrap only: phone = app bar + bottom tabs, desktop = top nav.
require_once __DIR__ . '/../includes/shell.php';

function student_header(string $title, array $student = [], array $opts = []): void {
    $root = shell_root();
    $cur = basename($_SERVER['SCRIPT_NAME']);
    $full = !empty($opts['full']);            // full-screen pages (3D explorer) hide the footer text
    $GLOBALS['__student_cur'] = $cur;
?>
<!doctype html>
<html lang="en" <?= shell_theme_attr() ?>>
<head><?php shell_head_tags($title, $root); ?>
<?php /* The original ComfortBook student look (navy header, gold rule, soft cards) is kept as the core developer designed it. */ ?>
<style>
    body { background: #f4f6f9; }
    .student-header { background: linear-gradient(135deg,#003366 0%,#002244 100%); color:#fff; padding:14px 0; border-bottom:3px solid #ffc107; }
    .student-header .logo { width:38px; height:38px; border-radius:50%; border:2px solid #ffc107; object-fit:cover; }
    .student-card { border-radius:14px; overflow:hidden; border:0; box-shadow:0 6px 24px rgba(0,51,102,.1); }
    .btn-primary { --bs-btn-bg:#003366; --bs-btn-border-color:#003366; --bs-btn-hover-bg:#002244; --bs-btn-hover-border-color:#002244; --bs-btn-active-bg:#002244; --bs-btn-active-border-color:#002244; --bs-btn-disabled-bg:#003366; --bs-btn-disabled-border-color:#003366; }
</style></head>
<body class="bg-body-tertiary<?= $full ? ' overflow-hidden' : '' ?>">
<header class="student-header sticky-top" style="padding-top:calc(14px + env(safe-area-inset-top))">
  <div class="container d-flex align-items-center gap-2">
    <a class="d-flex align-items-center gap-2 me-auto text-white text-decoration-none" href="<?= $root ?>/student/book.php">
      <img src="<?= $root ?>/assets/logo/ueab.png" alt="UEAB" height="38" class="logo">
      <span><span class="fw-semibold d-block lh-1">ComfortBook</span><small class="opacity-75" style="font-size:.72rem">UEAB Hostel Booking</small></span>
    </a>
    <nav class="d-none d-lg-flex gap-1" aria-label="Student">
      <a class="btn btn-sm <?= $cur === 'book.php' ? 'btn-warning' : 'btn-outline-light border-0' ?>" href="<?= $root ?>/student/book.php"><i class="fas fa-house me-1"></i>My booking</a>
      <a class="btn btn-sm <?= $cur === 'explore.php' ? 'btn-warning' : 'btn-outline-light border-0' ?>" href="<?= $root ?>/student/explore.php"><i class="fas fa-cube me-1"></i>Explore in 3D</a>
      <button class="btn btn-sm btn-outline-light border-0" type="button" data-ai-open><i class="fas fa-wand-magic-sparkles me-1"></i>Assistant</button>
    </nav>
    <button class="btn btn-sm btn-outline-light border-0 d-none" id="pushBtn" type="button" title="Turn on notifications"><i class="fas fa-bell"></i></button>
    <button class="btn btn-sm btn-warning d-none" type="button" data-pwa-install><i class="fas fa-download me-1"></i>Install</button>
    <?php if (!empty($student)): ?>
      <span class="badge text-bg-light d-none d-md-inline"><i class="fas fa-user me-1"></i><?= h($student['full_name'] ?? '') ?> · <?= h($student['reg_number'] ?? '') ?></span>
    <?php endif; ?>
  </div>
</header>
<main class="<?= $full ? 'container-fluid p-0' : 'container py-3 pb-5 mb-5 mb-lg-0' ?>">
<?php
}

function student_footer(array $opts = []): void {
    $root = shell_root();
    $cur = $GLOBALS['__student_cur'] ?? '';
?>
</main>
<?php if (empty($opts['full'])): ?>
<footer class="text-center small text-body-secondary py-4 d-none d-lg-block">&copy; <?= date('Y') ?> University of Eastern Africa, Baraton · ComfortBook</footer>
<?php endif; ?>
<nav class="navbar fixed-bottom bg-body border-top shadow-lg d-lg-none p-0" aria-label="Student quick navigation" style="padding-bottom:env(safe-area-inset-bottom)!important;z-index:1030">
  <ul class="nav nav-justified w-100 flex-nowrap align-items-end m-0">
    <?= shell_tab($root . '/student/book.php', 'fa-house', 'Booking', $cur === 'book.php') ?>
    <?= shell_tab($root . '/student/explore.php', 'fa-cube', 'Explore 3D', $cur === 'explore.php') ?>
    <?= shell_tab_ai() ?>
    <?= shell_tab('#', 'fa-bell', 'Alerts', false, 'data-notif-open') ?>
    <?= shell_tab('#', 'fa-circle-question', 'Help', false, 'data-ai-open data-ai-prompt="How does this booking work? Explain the steps, phases, fees and approval."') ?>
  </ul>
</nav>
<?php if (empty($opts['full'])): ?>
<button type="button" class="btn btn-primary rounded-pill shadow-lg position-fixed d-none d-lg-flex align-items-center gap-2 px-4 py-2" style="right:1.5rem;bottom:1.5rem;z-index:1030" data-ai-open aria-label="Open the assistant (Ctrl+J)"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i><span class="fw-semibold">Ask AI</span></button>
<?php endif; ?>
<?php shell_scripts($root); ?>
</body>
</html>
<?php
}
