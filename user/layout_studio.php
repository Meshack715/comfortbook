<?php
// user/layout_studio.php — plan editor for building shapes, wings, rooms, facilities and bed positions.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin', 'dean_men', 'dean_women']);
$root = APP_BASE;
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">
            <div class="d-flex flex-wrap align-items-center gap-2 my-3">
                <h1 class="h4 mb-0 me-auto"><i class="fas fa-compass-drafting text-primary me-2"></i>Layout Studio</h1>
                <select id="stBlock" class="form-select form-select-sm w-auto" aria-label="Building"></select>
                <button id="stPreset" class="btn btn-sm btn-outline-dark"><i class="fas fa-shapes me-1"></i>Default designs</button>
                <a id="st3d" class="btn btn-sm btn-outline-dark" href="<?= $root ?>/user/map3d.php"><i class="fas fa-cube me-1"></i>See in 3D</a>
                <button id="stSave" class="btn btn-sm btn-success" disabled><i class="fas fa-floppy-disk me-1"></i>Save</button>
            </div>
            <div id="stMsg"></div>
            <div class="alert alert-info alert-dismissible fade show" role="note" id="stHelp"><strong><i class="fas fa-circle-info me-1" aria-hidden="true"></i>How to redesign a building</strong>
                <ul class="mb-0 mt-1 ps-3 small"><li><b>Default designs</b> gives you ready shapes (straight, L, U, curved, angled) to start from.</li><li><b>Plan</b>: drag a room to move it, tap one to rotate it or change its beds, <b>+ Room</b> adds one.</li><li><b>Wings</b>: add, bend, angle or curve a wing of the building and change its length.</li><li><b>Facilities</b>: place stairs, bathrooms, lounge and the entrance.</li><li>Press <b>Save</b>, then <b>See in 3D</b> to walk through the result.</li></ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss help"></button></div>
            <script>try{if(localStorage.getItem('cbStudioHelp')==='1')document.getElementById('stHelp')?.remove();document.getElementById('stHelp')?.addEventListener('closed.bs.alert',()=>localStorage.setItem('cbStudioHelp','1'));}catch(e){}</script>
            <ul class="nav nav-pills mb-3" id="stTabs">
                <li class="nav-item"><button class="nav-link active" data-tab="plan">Plan</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="wings">Wings</button></li>
                <li class="nav-item"><button class="nav-link" data-tab="fac">Facilities</button></li>
            </ul>
            <section data-pane="plan">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <div class="btn-group btn-group-sm" id="stFloors" role="group" aria-label="Floor"></div>
                    <div class="input-group input-group-sm w-auto"><label class="input-group-text" for="stFloorCount">Floors</label><input id="stFloorCount" type="number" min="1" max="8" class="form-control" style="max-width:5rem"></div>
                    <button id="stAddRoom" class="btn btn-sm btn-primary ms-auto"><i class="fas fa-plus me-1"></i>Room</button>
                    <button id="stAddFac" class="btn btn-sm btn-outline-primary"><i class="fas fa-plus me-1"></i>Facility</button>
                </div>
                <div class="card shadow-sm"><div class="card-body p-1">
                    <svg id="stSvg" class="w-100 d-block bg-body-tertiary rounded" style="height:62vh;touch-action:none" role="img" aria-label="Floor plan"></svg>
                </div></div>
                <p class="form-text">Tap a room or facility to edit it. Drag the background to pan, pinch or scroll to zoom. Rooms are placed along wings by <em>slot</em> order.</p>
            </section>
            <section data-pane="wings" class="d-none"><div id="stWings" class="row g-3"></div><button id="stAddWing" class="btn btn-outline-primary btn-sm mt-3"><i class="fas fa-plus me-1"></i>Add wing</button></section>
            <section data-pane="fac" class="d-none"><div id="stFacList" class="list-group"></div></section>
<script>window.STUDIO_ROOT = <?= json_encode($root) ?>;</script>
<script type="module" src="<?= $root ?>/assets/js/studio.js"></script>
<?php include __DIR__ . '/partial/footer.php'; ?>
