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

<nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
    <a class="navbar-brand ps-3" href="<?= $root ?>/user/dashboard.php">
        <img src="<?= $root ?>/assets/logo/ueab.png" alt="UEAB" height="35"
             class="d-inline-block align-text-top me-2 rounded-circle">
        <span>ComfortBook</span>
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
(function () {
    const input    = document.getElementById('topSearch');
    const results  = document.getElementById('topSearchResults');
    const spinner  = document.getElementById('topSearchSpinner');
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
})();
</script>