<?php
// student/_guard.php — validates magic token, sets $_SESSION['magic_student_id'] + 'magic_semester_id'
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already validated in this session
if (!empty($_SESSION['magic_student_id'])) {
    $stmt = $pdo->prepare("
        SELECT s.*, r.room_number AS current_room_number, b.name AS current_block
        FROM students s
        LEFT JOIN rooms r ON r.id = s.current_room_id
        LEFT JOIN blocks b ON b.id = r.block_id
        WHERE s.id = ? AND s.is_active = 1
    ");
    $stmt->execute([$_SESSION['magic_student_id']]);
    $STUDENT = $stmt->fetch();
    if (!$STUDENT) {
        unset($_SESSION['magic_student_id'], $_SESSION['magic_semester_id']);
        die('Student account not found.');
    }
    $MAGIC_SEM_ID = (int)($_SESSION['magic_semester_id'] ?? 0);
    return;
}

// Validate token from URL
$raw  = $_GET['token'] ?? '';
$hash = hash('sha256', $raw);

$stmt = $pdo->prepare("
    SELECT ml.id AS link_id, ml.student_id, ml.semester_id, ml.expires_at, ml.used_at,
           s.full_name, s.email, s.gender, s.current_room_id, s.phase1_action, s.placed
    FROM magic_links ml
    JOIN students s ON s.id = ml.student_id
    WHERE ml.token_hash = ? AND ml.expires_at > NOW()
    LIMIT 1
");
$stmt->execute([$hash]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(403);
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Invalid Link</title>
    <link href="<?= defined("APP_BASE") ? APP_BASE : "" ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?= defined("APP_BASE") ? APP_BASE : "" ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    </head><body class="bg-light"><div class="container py-5">
    <div class="card shadow-sm border-0"><div class="card-body text-center p-5">
        <i class="fas fa-link-slash fa-3x text-danger mb-3"></i>
        <h3>Invalid or Expired Link</h3>
        <p class="text-muted">This booking link has expired or is invalid.</p>
        <p class="text-muted small">Please contact your Dean or Secretary to get a new link.</p>
    </div></div></div></body></html>
    <?php
    exit;
}

// Mark link used
$pdo->prepare("UPDATE magic_links SET used_at = NOW() WHERE id = ?")->execute([$row['link_id']]);

// Set session
$_SESSION['magic_student_id']  = (int)$row['student_id'];
$_SESSION['magic_semester_id'] = (int)$row['semester_id'];
$_SESSION['magic_link_id']     = (int)$row['link_id'];
$MAGIC_SEM_ID = (int)$row['semester_id'];

// Reload full student
$stmt = $pdo->prepare("
    SELECT s.*, r.room_number AS current_room_number, b.name AS current_block
    FROM students s
    LEFT JOIN rooms r ON r.id = s.current_room_id
    LEFT JOIN blocks b ON b.id = r.block_id
    WHERE s.id = ?
");
$stmt->execute([$row['student_id']]);
$STUDENT = $stmt->fetch();