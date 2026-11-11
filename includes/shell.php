<?php
// includes/shell.php — the ONE app shell (Bootstrap 5.3 only, no custom stylesheet).
//  * lg+ screens : fixed sidebar + top bar
//  * phones      : top app bar + bottom tab bar, sidebar becomes a slide-in sheet,
//                  modals become bottom sheets (see assets/js/app.js  AppSheet)
//  * The only colour customisation is the documented Bootstrap variable override on <html>.

function shell_root(): string {
    $root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    return ($root === '' || $root === '\\') ? '' : $root;
}

/** Navigation model per role: [heading, label, href, icon, matching files, role-check] */
function shell_nav(): array {
    $a = is_admin(); $d = is_dean(); $s = is_secretary();
    $n = [];
    $n[] = ['Core', 'Dashboard', 'dashboard.php', 'fa-gauge-high', ['dashboard.php'], true];
    $n[] = ['Core', 'Hostel in 3D', 'map3d.php', 'fa-cube', ['map3d.php'], true];
    if ($a) {
        $n[] = ['Infrastructure', 'Buildings', 'buildings.php', 'fa-building', ['buildings.php', 'building_form.php'], true];
        $n[] = ['Infrastructure', 'Layout Studio', 'layout_studio.php', 'fa-drafting-compass', ['layout_studio.php'], true];
        $n[] = ['Infrastructure', 'Rooms & beds', 'rooms.php', 'fa-bed', ['rooms.php', 'room_form.php'], true];
        $n[] = ['Administration', 'Students', 'students.php', 'fa-user-graduate', ['students.php', 'student_form.php', 'student_import.php'], true];
        $n[] = ['Administration', 'Staff', 'staff.php', 'fa-users-gear', ['staff.php', 'staff_form.php'], true];
        $n[] = ['Booking control', 'Semesters & phases', 'phase_control.php', 'fa-calendar-days', ['phase_control.php'], true];
    }
    if ($d) {
        $n[] = ['Booking', 'Phase windows', 'phase_windows.php', 'fa-clock', ['phase_windows.php'], true];
        $n[] = ['Booking', 'Send magic links', 'send_links.php', 'fa-paper-plane', ['send_links.php'], true];
    }
    if ($d || $s) {
        $n[] = ['Allocations', 'Approvals', 'approvals.php', 'fa-circle-check', ['approvals.php'], true];
        $n[] = ['Allocations', 'Unplaced students', 'unplaced.php', 'fa-user-slash', ['unplaced.php'], true];
        $n[] = ['Allocations', 'Room assignment', 'room_assignment.php', 'fa-shuffle', ['room_assignment.php'], true];
        $n[] = ['Allocations', 'Students', 'students.php', 'fa-user-graduate', ['students.php'], !$a];
        $n[] = ['Allocations', 'Rooms & beds', 'rooms.php', 'fa-bed', ['rooms.php'], !$a];
    }
    $n[] = ['Allocations', 'Collect payment', 'collect_payment.php', 'fa-mobile-screen-button', ['collect_payment.php'], true];
    if ($d) $n[] = ['Reports', 'Reports', 'reports.php', 'fa-chart-column', ['reports.php'], true];
    $n[] = ['System', 'Downloads', 'downloads.php', 'fa-download', ['downloads.php'], true];
    $n[] = ['System', 'AI & payments', 'ai_settings.php', 'fa-wand-magic-sparkles', ['ai_settings.php'], $a];
    $n[] = ['System', 'AI log', 'ai_log.php', 'fa-clipboard-list', ['ai_log.php'], $a];
    $n[] = ['System', 'My profile', 'profile.php', 'fa-circle-user', ['profile.php'], true];
    return array_values(array_filter($n, fn($i) => $i[5]));
}

/** Documented Bootstrap variable override: UEAB navy as the primary colour. Delete this one line to get stock Bootstrap blue. */
function shell_theme_attr(): string {
    return 'data-bs-theme="light" style="--bs-primary:#003366;--bs-primary-rgb:0,51,102;--bs-link-color:#003366;--bs-link-color-rgb:0,51,102;--bs-link-hover-color:#002244;--bs-link-hover-color-rgb:0,34,68;--bs-secondary-color:#495057;--bs-warning:#ffc107"';
}

function shell_head_tags(string $title, string $root, bool $withThree = false): void { ?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#003366">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="<?= $root ?>/manifest.php">
<link rel="icon" href="<?= $root ?>/assets/logo/ueab.png">
<link rel="apple-touch-icon" href="<?= $root ?>/assets/logo/ueab.png">
<title><?= h($title) ?> · <?= SITE_NAME ?></title>
<link href="<?= $root ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="<?= $root ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
<script>window.CB = {root: <?= json_encode($root) ?>, role: <?= json_encode(function_exists('user_role') ? user_role() : null) ?>, vapid: <?= json_encode(function_exists('get_config') ? get_config('vapid_public', '') : '') ?>, ai: <?= json_encode(function_exists('get_config') ? get_config('ai_enabled', '1') === '1' : false) ?>, v: <?= (int)max(@filemtime(dirname(__DIR__) . '/assets/js/copilot.js'), @filemtime(dirname(__DIR__) . '/assets/css/cb-ai.css')) ?>};</script>
<script src="<?= $root ?>/assets/js/pwa.js" defer></script>
<?php }

/** One tab in the phone bar. Active = primary colour + a bar on top. */
function shell_tab(string $href, string $icon, string $label, bool $on, string $attr = ''): string {
    return '<li class="nav-item"><a class="nav-link d-flex flex-column align-items-center justify-content-center py-2 px-0 border-top border-3 border-primary ' . ($on ? 'text-primary fw-semibold' : 'text-secondary border-opacity-0') . '" style="min-height:58px" href="' . $href . '" ' . ($on ? 'aria-current="page" ' : '') . $attr . '><i class="fas ' . h($icon) . ' fs-5 mb-1" aria-hidden="true"></i><span style="font-size:.7rem">' . h($label) . '</span></a></li>';
}
/** The raised centre button that opens the AI from any page. */
function shell_tab_ai(): string {
    return '<li class="nav-item d-flex justify-content-center align-items-start"><button type="button" class="btn btn-primary rounded-circle shadow border border-4 border-body d-flex align-items-center justify-content-center p-0" style="width:60px;height:60px;margin-top:-24px" data-ai-open aria-label="Open the assistant"><i class="fas fa-wand-magic-sparkles fs-4" aria-hidden="true"></i></button></li>';
}

/** Staff phone app chrome: bottom tab bar with a raised AI button + "More" sheet with every page; desktop gets a floating AI button. */
function shell_bottom_tabs(array $nav, string $current, string $root, string $aiUrl): void {
    $by = []; foreach ($nav as $i) $by[$i[2]] = $by[$i[2]] ?? $i;
    $second = $by['approvals.php'] ?? $by['buildings.php'] ?? $by['students.php'] ?? null;
    $tab = fn($i) => shell_tab($root . '/user/' . $i[2], $i[3], explode(' ', $i[1])[0], in_array($current, $i[4], true));
    $groups = []; foreach ($nav as $i) $groups[$i[0]][] = $i;
    $moreOn = !in_array($current, ['dashboard.php', 'map3d.php', $second[2] ?? ''], true);
?>
<nav class="navbar fixed-bottom bg-body border-top shadow-lg d-lg-none p-0" aria-label="Quick navigation" style="padding-bottom:env(safe-area-inset-bottom)!important;z-index:1030">
  <ul class="nav nav-justified w-100 flex-nowrap align-items-end m-0">
    <?= isset($by['dashboard.php']) ? $tab($by['dashboard.php']) : '' ?>
    <?= $second ? $tab($second) : '' ?>
    <?= shell_tab_ai() ?>
    <?= isset($by['map3d.php']) ? $tab($by['map3d.php']) : '' ?>
    <?= shell_tab('#', 'fa-grip', 'More', $moreOn, 'data-bs-toggle="offcanvas" data-bs-target="#moreSheet" role="button" aria-controls="moreSheet"') ?>
  </ul>
</nav>

<div class="offcanvas offcanvas-bottom rounded-top-4 h-auto d-lg-none" tabindex="-1" id="moreSheet" aria-labelledby="moreTitle" style="max-height:88dvh">
  <div class="d-flex justify-content-center pt-2"><span class="bg-secondary-subtle rounded-pill" style="width:42px;height:5px"></span></div>
  <div class="offcanvas-header pb-2">
    <div class="d-flex align-items-center gap-2 me-auto"><span class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center" style="width:40px;height:40px"><i class="fas fa-user"></i></span>
      <div class="lh-sm"><h2 class="h6 mb-0" id="moreTitle"><?= h($_SESSION['full_name'] ?? 'Menu') ?></h2><small class="text-body-secondary"><?= h(function_exists('role_display') ? role_display() : '') ?></small></div></div>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close menu"></button>
  </div>
  <div class="offcanvas-body pt-0">
    <div class="input-group mb-3"><span class="input-group-text bg-body"><i class="fas fa-magnifying-glass"></i></span><input type="search" class="form-control" id="moreFilter" placeholder="Find a page" autocomplete="off" aria-label="Find a page"></div>
    <?php foreach ($groups as $head => $items): ?>
      <div class="more-group mb-3"><div class="text-uppercase small fw-semibold text-body-secondary mb-2"><?= h($head) ?></div>
        <div class="row row-cols-3 g-2">
        <?php foreach ($items as $i): $on = in_array($current, $i[4], true); ?>
          <div class="col more-item" data-t="<?= h(strtolower($i[1] . ' ' . $head)) ?>"><a class="btn <?= $on ? 'btn-primary' : 'btn-light border' ?> w-100 h-100 py-3 px-1 d-flex flex-column align-items-center justify-content-center gap-1" href="<?= $root ?>/user/<?= h($i[2]) ?>"><i class="fas <?= h($i[3]) ?> fs-4 <?= $on ? '' : 'text-primary' ?>" aria-hidden="true"></i><span class="small lh-sm text-center"><?= h($i[1]) ?></span></a></div>
        <?php endforeach; ?>
        </div></div>
    <?php endforeach; ?>
    <div class="d-flex flex-wrap gap-2 pt-2 border-top">
      <button type="button" class="btn btn-outline-secondary rounded-pill" data-notif-open data-bs-dismiss="offcanvas"><i class="fas fa-inbox me-1"></i>Notifications</button>
      <button type="button" class="btn btn-warning rounded-pill d-none" data-pwa-install><i class="fas fa-download me-1"></i>Install app</button>
      <a class="btn btn-outline-danger rounded-pill ms-auto" href="<?= $root ?>/logout.php"><i class="fas fa-right-from-bracket me-1"></i>Log out</a>
    </div>
  </div>
</div>
<script>
document.getElementById('moreFilter')?.addEventListener('input', function () {
  const q = this.value.trim().toLowerCase();
  document.querySelectorAll('#moreSheet .more-item').forEach(c => c.classList.toggle('d-none', q && !c.dataset.t.includes(q)));
  document.querySelectorAll('#moreSheet .more-group').forEach(g => g.classList.toggle('d-none', !g.querySelector('.more-item:not(.d-none)')));
});
</script>
<?php if ($current !== 'map3d.php' && $current !== 'layout_studio.php'): ?>
<button type="button" class="btn btn-primary rounded-pill shadow-lg position-fixed d-none d-lg-flex align-items-center gap-2 px-4 py-2" style="right:1.5rem;bottom:1.5rem;z-index:1030" data-ai-open aria-label="Open the assistant (Ctrl+J)"><i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i><span class="fw-semibold">Ask AI</span><kbd class="bg-primary-subtle text-primary-emphasis small">Ctrl J</kbd></button>
<?php endif; ?>
<?php }

/** Common closing: sheet host, toasts, scripts. */
function shell_scripts(string $root): void { ?>
<div class="toast-container position-fixed top-0 end-0 p-3" id="appToasts" style="z-index:2000"></div>
<script src="<?= $root ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="<?= $root ?>/assets/js/app.js"></script>
<?php if (function_exists('get_config') && get_config('ai_enabled', '1') === '1'): ?><script src="<?= $root ?>/assets/js/copilot.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/js/copilot.js') ?>" defer></script><?php endif; ?>
<?php }
