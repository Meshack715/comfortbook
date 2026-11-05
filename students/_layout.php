<?php
// student/_layout.php
// Usage: require '_layout.php'; then call student_header($title); ... student_footer();
function student_header(string $title, array $student = []): void {
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> · ComfortBook</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/ueab-theme.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', Roboto, sans-serif; }
        .student-header {
            background: linear-gradient(135deg,#003366 0%,#002244 100%);
            color: #fff; padding: 14px 0;
            border-bottom: 3px solid #ffc107;
        }
        .student-header .logo { width: 38px; height: 38px; border-radius: 50%;
            border: 2px solid #ffc107; object-fit: cover; }
        .student-header h1 { font-size: 1.05rem; margin: 0; font-weight: 600; }
        .student-header p  { font-size: .72rem; margin: 0; opacity: .8; }
        .student-badge { background: rgba(255,255,255,.12); border-radius: 20px;
            padding: 3px 12px; font-size: .74rem; }
        .student-card { border-radius: 14px; overflow: hidden;
            border: 0; box-shadow: 0 6px 24px rgba(0,51,102,.1); }
        .room-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: .75rem; }
        .room-tile {
            background: #fff; border: 2px solid #eef1f4; border-radius: 12px;
            padding: 1rem; cursor: pointer; text-align: center;
            transition: all .15s; position: relative;
        }
        .room-tile:hover { border-color: #003366; transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,51,102,.12); }
        .room-tile.selected { border-color: #198754; background: #f0f8f4; }
        .room-tile .room-num { font-size: 1.1rem; font-weight: 700; color: #003366; }
        .room-tile .room-blk { font-size: .75rem; color: #6c757d; }
        .room-tile .room-cap { font-size: .7rem; color: #adb5bd; margin-top: .25rem; }
        .btn-primary { background: #003366 !important; border-color: #003366 !important; }
        .btn-primary:hover { background: #002244 !important; border-color: #002244 !important; }
        .phase-badge { font-size: .7rem; padding: 4px 10px; border-radius: 20px; }
    </style>
</head>
<body>

<div class="student-header">
    <div class="container d-flex align-items-center gap-3">
        <img src="../assets/logo/ueab.png" alt="UEAB" class="logo">
        <div class="flex-grow-1">
            <h1>ComfortBook</h1>
            <p>UEAB Hostel Booking</p>
        </div>
        <?php if (!empty($student)): ?>
        <div class="text-end">
            <div class="student-badge">
                <i class="fas fa-user me-1"></i><?= h($student['full_name'] ?? '') ?>
            </div>
            <div style="font-size:.68rem;opacity:.75;margin-top:2px;">
                <?= h($student['reg_number'] ?? '') ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="container py-4">
<?php
}

function student_footer(): void {
?>
</div>

<div class="text-center py-4 text-muted" style="font-size:.78rem;">
    &copy; <?= date('Y') ?> University of Eastern Africa, Baraton · ComfortBook
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}