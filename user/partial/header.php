<?php
// user/partial/header.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/shell.php';

$fullName    = $_SESSION['full_name'] ?? 'User';
$username    = $_SESSION['username'] ?? 'guest';
$roleDisplay = role_display();

$root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
if ($root === '' || $root === '\\') $root = '';
$curPage = basename($_SERVER['PHP_SELF']); $pageTitle = 'ComfortBook';
foreach (shell_nav() as $__n) { if (in_array($curPage, $__n[4], true)) { $pageTitle = $__n[1]; break; } }
?>
<!doctype html>
<html lang="en" <?= shell_theme_attr() ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#003366">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?= $root ?>/manifest.php">
    <link rel="icon" href="<?= $root ?>/assets/logo/ueab.png">
    <link rel="apple-touch-icon" href="<?= $root ?>/assets/logo/ueab.png">
    <script>window.CB = {root: <?= json_encode($root) ?>, vapid: <?= json_encode(get_config('vapid_public', '')) ?>, ai: <?= json_encode(get_config('ai_enabled', '1') === '1') ?>};</script>
    <script src="<?= $root ?>/assets/js/pwa.js" defer></script>
    <title><?= SITE_NAME ?> — <?= h($roleDisplay) ?></title>

    <link href="<?= $root ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?= $root ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="<?= $root ?>/assets/css/styles.css" rel="stylesheet">
    <link href="<?= $root ?>/assets/css/ueab-theme.css" rel="stylesheet">

    <style>
        /* Header search styling */
        .topsearch-wrap {
            position: relative;
            max-width: 380px;
            flex: 1 1 280px;
        }
        .topsearch-wrap input {
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            color: #fff;
            padding-left: 2.25rem;
        }
        .topsearch-wrap input::placeholder { color: rgba(255,255,255,.55); }
        .topsearch-wrap input:focus {
            background: rgba(255,255,255,.18);
            border-color: #ffc107;
            box-shadow: 0 0 0 .2rem rgba(255,193,7,.2);
            color: #fff;
        }
        .topsearch-icon {
            position: absolute;
            left: .65rem;
            top: 50%;
            transform: translateY(-50%);
            color: rgba(255,255,255,.6);
            pointer-events: none;
        }
        .topsearch-spinner {
            position: absolute;
            right: .65rem;
            top: 50%;
            transform: translateY(-50%);
        }

        /* Results dropdown */
        .topsearch-results {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            right: 0;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 8px 30px rgba(0,0,0,.18);
            max-height: 420px;
            overflow-y: auto;
            z-index: 2000;
            display: none;
        }
        .topsearch-results.show { display: block; }
        .topsearch-results .search-header {
            padding: 8px 14px 4px;
            font-size: .7rem;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #6c757d;
            background: #f8f9fa;
        }
        .topsearch-results .search-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 14px;
            text-decoration: none;
            color: #212529;
            border-bottom: 1px solid #f1f3f5;
            transition: background .1s;
        }
        .topsearch-results .search-item:last-child { border-bottom: none; }
        .topsearch-results .search-item:hover { background: #f0f4f8; }
        .topsearch-results .search-icon {
            width: 32px; height: 32px;
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .topsearch-results .search-title {
            font-size: .88rem;
            font-weight: 600;
            line-height: 1.2;
        }
        .topsearch-results .search-sub {
            font-size: .74rem;
            color: #6c757d;
            margin-top: 2px;
        }
        .topsearch-results .search-empty {
            padding: 24px 14px;
            text-align: center;
            color: #6c757d;
            font-size: .85rem;
        }
    </style>
</head>
<body class="sb-nav-fixed">

<!-- PHONE: compact app bar (Bootstrap utilities only) -->
<nav class="navbar fixed-top bg-primary navbar-dark d-lg-none px-3 shadow-sm" style="height:56px;z-index:1039" aria-label="App bar">
    <a class="d-flex align-items-center gap-2 text-white text-decoration-none me-auto min-w-0" href="<?= $root ?>/user/dashboard.php">
        <img src="<?= $root ?>/assets/logo/ueab.png" alt="" height="32" width="32" class="rounded-circle border border-2 border-warning flex-shrink-0">
        <span class="fw-semibold text-truncate"><?= h($pageTitle) ?></span>
    </a>
    <button class="btn btn-link text-white px-2" type="button" data-bs-toggle="collapse" data-bs-target="#mSearch" aria-expanded="false" aria-controls="mSearch" aria-label="Search"><i class="fas fa-magnifying-glass"></i></button>
    <button class="btn btn-link text-white px-2" type="button" data-notif-open aria-label="Notifications"><i class="fas fa-bell"></i></button>
    <div class="dropdown">
        <button class="btn btn-link text-white px-2" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account"><i class="fas fa-circle-user fs-4"></i></button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
            <li class="dropdown-header"><strong><?= h($fullName) ?></strong><br><small><?= h($roleDisplay) ?></small></li>
            <li><a class="dropdown-item py-2" href="<?= $root ?>/user/profile.php"><i class="fas fa-id-badge fa-fw me-2"></i>My profile</a></li>
            <li><a class="dropdown-item py-2 text-danger" href="<?= $root ?>/logout.php"><i class="fas fa-right-from-bracket fa-fw me-2"></i>Log out</a></li>
        </ul>
    </div>
</nav>
<div class="collapse fixed-top bg-body border-bottom shadow-sm p-2 d-lg-none" id="mSearch" style="top:56px;z-index:1038">
    <div class="position-relative">
        <input type="search" id="topSearchM" class="form-control" placeholder="Search students, rooms<?= is_admin() ? ', staff, blocks' : '' ?>…" autocomplete="off" aria-label="Search">
        <span id="topSearchSpinnerM" class="topsearch-spinner d-none"><span class="spinner-border spinner-border-sm text-primary" style="width:.9rem;height:.9rem;"></span></span>
        <div id="topSearchResultsM" class="topsearch-results"></div>
    </div>
</div>

<!-- DESKTOP: classic top bar -->
<nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark d-none d-lg-flex">
    <a class="navbar-brand ps-3" href="<?= $root ?>/user/dashboard.php">
        <img src="<?= $root ?>/assets/logo/ueab.png" alt="UEAB" height="35"
             class="d-inline-block align-text-top me-2 rounded-circle">
        <span class="d-none d-sm-inline">ComfortBook</span>
    </a>

    <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- SEARCH -->
    <div class="topsearch-wrap d-none d-md-block me-auto ms-md-3">
        <i class="fas fa-search topsearch-icon"></i>
        <input type="text" id="topSearch" class="form-control form-control-sm"
               placeholder="Search students, rooms<?= is_admin() ? ', staff, blocks' : '' ?>..."
               autocomplete="off">
        <span id="topSearchSpinner" class="topsearch-spinner d-none">
            <span class="spinner-border spinner-border-sm text-warning" style="width:.9rem;height:.9rem;"></span>
        </span>
        <div id="topSearchResults" class="topsearch-results"></div>
    </div>

    <div class="d-flex align-items-center gap-2 me-2">
        <button type="button" class="btn btn-sm btn-warning d-none" data-pwa-install><i class="fas fa-download me-1" aria-hidden="true"></i><span class="d-none d-md-inline">Install</span></button>
        <span class="d-none d-md-inline"><button type="button" class="btn btn-sm btn-outline-light d-none" id="pushBtn" aria-label="Notifications"><i class="fas fa-bell-slash" aria-hidden="true"></i></button></span>
        <button type="button" class="btn btn-sm btn-outline-warning d-none d-md-inline-block" data-notif-open aria-label="Notification centre"><i class="fas fa-inbox" aria-hidden="true"></i></button>
        <?php if (get_config('ai_enabled', '1') === '1'): ?><button type="button" class="btn btn-sm btn-outline-warning" data-ai-open><i class="fas fa-wand-magic-sparkles me-1" aria-hidden="true"></i><span class="d-none d-md-inline">Assistant</span></button><?php endif; ?>
    </div>

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

<script>
function cbBindSearch(inputId, resultsId, spinnerId) {
    const input    = document.getElementById(inputId);
    const results  = document.getElementById(resultsId);
    const spinner  = document.getElementById(spinnerId);
    if (!input) return;
    const searchUrl = '<?= $root ?>/api/search.php';

    let timer    = null;
    let lastQuery = '';
    let activeIndex = -1;

    function hide() {
        results.classList.remove('show');
        activeIndex = -1;
    }

    function show() {
        results.classList.add('show');
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function renderResults(items) {
        activeIndex = -1;

        if (!items.length) {
            results.innerHTML = `
                <div class="search-empty">
                    <i class="fas fa-search me-2 opacity-50"></i>
                    No results
                </div>`;
            show();
            return;
        }

        // Group by type
        const groups = { student: [], room: [], block: [], staff: [] };
        items.forEach(it => {
            if (groups[it.type]) groups[it.type].push(it);
        });

        const labelMap = {
            student: 'Students',
            room:    'Rooms',
            block:   'Blocks',
            staff:   'Staff',
        };

        let html = '';
        ['student','room','block','staff'].forEach(type => {
            const list = groups[type];
            if (!list.length) return;
            html += `<div class="search-header">${labelMap[type]}</div>`;
            list.forEach(it => {
                html += `
                    <a href="${it.url}" class="search-item">
                        <div class="search-icon bg-${it.color}-subtle text-${it.color}">
                            <i class="fas ${it.icon}"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="search-title">${escapeHtml(it.title)}</div>
                            <div class="search-sub">${escapeHtml(it.subtitle)}</div>
                        </div>
                        <i class="fas fa-arrow-right ms-2 text-muted small align-self-center"></i>
                    </a>`;
            });
        });

        results.innerHTML = html;
        show();
    }

    function doSearch(q) {
        if (q === lastQuery) return;
        lastQuery = q;

        if (q.length < 2) {
            hide();
            results.innerHTML = '';
            spinner.classList.add('d-none');
            return;
        }

        spinner.classList.remove('d-none');

        fetch(searchUrl + '?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return;
                renderResults(data.results || []);
            })
            .catch(() => {
                results.innerHTML = '<div class="search-empty">Search failed</div>';
                show();
            })
            .finally(() => spinner.classList.add('d-none'));
    }

    // Debounced input
    input?.addEventListener('input', e => {
        clearTimeout(timer);
        const q = e.target.value.trim();
        if (q.length < 2) { hide(); return; }
        spinner.classList.remove('d-none');
        timer = setTimeout(() => doSearch(q), 350);
    });

    // Keyboard nav
    input?.addEventListener('keydown', e => {
        const items = results.querySelectorAll('.search-item');
        if (!results.classList.contains('show') || !items.length) {
            if (e.key === 'Escape') hide();
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = (activeIndex + 1) % items.length;
            items.forEach((it, i) => it.style.background = i === activeIndex ? '#f0f4f8' : '');
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = activeIndex <= 0 ? items.length - 1 : activeIndex - 1;
            items.forEach((it, i) => it.style.background = i === activeIndex ? '#f0f4f8' : '');
        } else if (e.key === 'Enter') {
            if (activeIndex >= 0 && items[activeIndex]) {
                e.preventDefault();
                window.location = items[activeIndex].getAttribute('href');
            }
        } else if (e.key === 'Escape') {
            hide();
        }
    });

    // Click outside closes
    document.addEventListener('click', e => {
        if (!results.contains(e.target) && e.target !== input) hide();
    });

    // Focus shows recent results if text is present
    input?.addEventListener('focus', () => {
        if (input.value.trim().length >= 2 && results.innerHTML) show();
    });

    // Ctrl+K / Cmd+K shortcut to focus the search box
    document.addEventListener('keydown', e => {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            input?.focus();
        }
    });

    // Reset on back/forward (bfcache)
    window.addEventListener('pageshow', () => {
        if (input) input.value = '';
        lastQuery = '';
        hide();
        spinner?.classList.add('d-none');
    });
}
cbBindSearch('topSearch', 'topSearchResults', 'topSearchSpinner');
cbBindSearch('topSearchM', 'topSearchResultsM', 'topSearchSpinnerM');
</script>