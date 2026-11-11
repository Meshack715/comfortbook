<?php
// user/partial/sidebar.php
$current = basename($_SERVER['PHP_SELF']);
$root    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
if ($root === '' || $root === '\\') $root = '';

$isAdmin = is_admin();
$isDean  = is_dean();
$isSec   = is_secretary();

function nav_active(array $pages, string $current): string {
    return in_array($current, $pages, true) ? 'active' : '';
}
?>
<div id="layoutSidenav_nav" class="d-none d-lg-block">
    <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
        <div class="sb-sidenav-menu">
            <div class="nav">

                <div class="sidebar-header">
                    <img src="<?= $root ?>/assets/logo/ueab.png" alt="UEAB" class="sidebar-logo">
                    <h3>University of Eastern Africa, Baraton</h3>
                    <p>ComfortBook · <?= h(role_display()) ?></p>
                    <div class="decorative-line"></div>
                </div>

                <div class="sb-sidenav-menu-heading">Core</div>
                <a class="nav-link <?= nav_active(['dashboard.php'], $current) ?>"
                   href="<?= $root ?>/user/dashboard.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div>
                    Dashboard
                </a>
                <a class="nav-link <?= $current === 'map3d.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/map3d.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-cube"></i></div>
                    Hostel in 3D
                </a>

                <?php if ($isAdmin): ?>
                <div class="sb-sidenav-menu-heading">Infrastructure</div>

                <a class="nav-link <?= nav_active(['buildings.php','building_form.php'], $current) ?>"
                   href="#" data-bs-toggle="collapse" data-bs-target="#collapseBuildings">
                    <div class="sb-nav-link-icon"><i class="fas fa-building"></i></div>
                    Buildings
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="collapse <?= nav_active(['buildings.php','building_form.php'], $current) ? 'show' : '' ?>"
                     id="collapseBuildings" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= $current === 'buildings.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/buildings.php">
                            <i class="fas fa-list me-2"></i>All Buildings
                        </a>
                        <a class="nav-link <?= $current === 'building_form.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/building_form.php">
                            <i class="fas fa-plus-circle me-2"></i>Add Building
                        </a>
                    </nav>
                </div>

                <a class="nav-link <?= nav_active(['rooms.php','room_form.php'], $current) ?>"
                   href="#" data-bs-toggle="collapse" data-bs-target="#collapseRooms">
                    <div class="sb-nav-link-icon"><i class="fas fa-bed"></i></div>
                    Rooms
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="collapse <?= nav_active(['rooms.php','room_form.php'], $current) ? 'show' : '' ?>"
                     id="collapseRooms" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= $current === 'rooms.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/rooms.php">
                            <i class="fas fa-list me-2"></i>All Rooms
                        </a>
                        <a class="nav-link <?= $current === 'room_form.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/room_form.php">
                            <i class="fas fa-plus-circle me-2"></i>Add Room
                        </a>
                    </nav>
                </div>

                <a class="nav-link <?= $current === 'layout_studio.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/layout_studio.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-drafting-compass"></i></div>
                    Layout Studio
                </a>

                <div class="sb-sidenav-menu-heading">Administration</div>
                <a class="nav-link <?= nav_active(['students.php','student_form.php','student_import.php'], $current) ?>"
                   href="#" data-bs-toggle="collapse" data-bs-target="#collapseStudents">
                    <div class="sb-nav-link-icon"><i class="fas fa-user-graduate"></i></div>
                    Students
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="collapse <?= nav_active(['students.php','student_form.php','student_import.php'], $current) ? 'show' : '' ?>"
                     id="collapseStudents" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= $current === 'students.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/students.php">
                            <i class="fas fa-list me-2"></i>All Students
                        </a>
                        <a class="nav-link <?= $current === 'student_form.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/student_form.php">
                            <i class="fas fa-user-plus me-2"></i>Add Student
                        </a>
                        <a class="nav-link <?= $current === 'student_import.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/student_import.php">
                            <i class="fas fa-file-csv me-2"></i>Import CSV
                        </a>
                    </nav>
                </div>

                <a class="nav-link <?= nav_active(['staff.php','staff_form.php'], $current) ?>"
                   href="#" data-bs-toggle="collapse" data-bs-target="#collapseStaff">
                    <div class="sb-nav-link-icon"><i class="fas fa-users-cog"></i></div>
                    Staff Accounts
                    <div class="sb-sidenav-collapse-arrow"><i class="fas fa-angle-down"></i></div>
                </a>
                <div class="collapse <?= nav_active(['staff.php','staff_form.php'], $current) ? 'show' : '' ?>"
                     id="collapseStaff" data-bs-parent="#sidenavAccordion">
                    <nav class="sb-sidenav-menu-nested nav">
                        <a class="nav-link <?= $current === 'staff.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/staff.php">
                            <i class="fas fa-list me-2"></i>All Staff
                        </a>
                        <a class="nav-link <?= $current === 'staff_form.php' ? 'active' : '' ?>"
                           href="<?= $root ?>/user/staff_form.php">
                            <i class="fas fa-user-plus me-2"></i>Add Staff
                        </a>
                    </nav>
                </div>

                <div class="sb-sidenav-menu-heading">Booking Control</div>
                <a class="nav-link <?= $current === 'phase_control.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/phase_control.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-calendar-alt"></i></div>
                    Semester Control
                </a>
                <?php endif; ?>

                <?php if ($isDean): ?>
                <a class="nav-link <?= $current === 'layout_studio.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/layout_studio.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-drafting-compass"></i></div>
                    Layout Studio
                </a>
                <div class="sb-sidenav-menu-heading">Booking</div>
                <a class="nav-link <?= $current === 'phase_windows.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/phase_windows.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-clock"></i></div>
                    Phase Windows
                </a>
                <a class="nav-link <?= $current === 'send_links.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/send_links.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-paper-plane"></i></div>
                    Send Magic Links
                </a>
                <?php endif; ?>

                <?php if ($isDean || $isSec): ?>
                <div class="sb-sidenav-menu-heading">Allocations</div>
                <a class="nav-link <?= $current === 'approvals.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/approvals.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-check-circle"></i></div>
                    Pending Approvals
                </a>
                <a class="nav-link <?= $current === 'unplaced.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/unplaced.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-user-slash"></i></div>
                    Unplaced Students
                </a>
                <a class="nav-link <?= $current === 'room_assignment.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/room_assignment.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-random"></i></div>
                    Room Assignment
                </a>
                <a class="nav-link <?= nav_active(['students.php','student_form.php'], $current) ?>"
                   href="<?= $root ?>/user/students.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-user-graduate"></i></div>
                    Students
                </a>
                <a class="nav-link <?= nav_active(['rooms.php','room_form.php'], $current) ?>"
                   href="<?= $root ?>/user/rooms.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-bed"></i></div>
                    Rooms
                </a>
                <?php endif; ?>

                <?php if ($isDean): ?>
                <div class="sb-sidenav-menu-heading">Reports</div>
                <a class="nav-link <?= $current === 'reports.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/reports.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-chart-bar"></i></div>
                    Reports
                </a>
                <?php endif; ?>

                <div class="sb-sidenav-menu-heading">System</div>
                <?php if ($isAdmin): ?>
                <a class="nav-link <?= $current === 'ai_settings.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/ai_settings.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-wand-magic-sparkles"></i></div>
                    AI &amp; payments
                </a>
                <?php endif; ?>
                <a class="nav-link <?= $current === 'downloads.php' ? 'active' : '' ?>"
                   href="<?= $root ?>/user/downloads.php">
                    <div class="sb-nav-link-icon"><i class="fas fa-download"></i></div>
                    Downloads
                </a>

            </div>
        </div>
        <div class="sb-sidenav-footer">
            <div class="small"><?= h(role_display()) ?></div>
            <strong><?= h($_SESSION['full_name'] ?? 'User') ?></strong>
        </div>
    </nav>
</div>