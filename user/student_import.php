<?php
// user/student_import.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$report = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv'])) {
    $file = $_FILES['csv'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $report = ['error' => 'Upload failed. Try again.', 'rows'=>[]];
    } elseif (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['csv','txt'], true)) {
        $report = ['error' => 'Only .csv files allowed.', 'rows'=>[]];
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            $report = ['error' => 'Cannot read file.', 'rows'=>[]];
        } else {
            $header = fgetcsv($handle);
            $rows   = [];
            $inserted = 0; $skipped = 0;

            // Build room lookup by room_number+gender
            $roomLookup = [];
            foreach ($pdo->query("SELECT id, room_number, gender FROM rooms") as $r) {
                $roomLookup[$r['room_number'].'|'.$r['gender']] = (int)$r['id'];
            }

            while (($row = fgetcsv($handle)) !== false) {
                $rec = array_combine(
                    array_map('strtolower', array_map('trim', $header)),
                    array_map('trim', $row)
                );

                $reg    = $rec['reg_number']     ?? '';
                $name   = $rec['full_name']      ?? '';
                $email  = $rec['email']          ?? '';
                $phone  = $rec['phone']          ?? '';
                $gender = ucfirst(strtolower($rec['gender'] ?? ''));
                $year   = (int)($rec['year_of_study'] ?? 1);
                $course = $rec['course']         ?? '';
                $rnum   = $rec['current_room_number'] ?? '';

                $status = 'ok'; $msg = '';

                if ($reg === '' || $name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $status = 'error'; $msg = 'Missing reg/name/valid email';
                } elseif (!in_array($gender, ['Male','Female'], true)) {
                    $status = 'error'; $msg = 'Invalid gender';
                } else {
                    // Duplicate check
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE reg_number = ? OR email = ?");
                    $stmt->execute([$reg, $email]);
                    if ((int)$stmt->fetchColumn() > 0) {
                        $status = 'skip'; $msg = 'Reg or email exists';
                        $skipped++;
                    } else {
                        $roomId = null;
                        if ($rnum !== '') {
                            $key = $rnum.'|'.$gender;
                            if (isset($roomLookup[$key])) $roomId = $roomLookup[$key];
                            else { $status = 'skip'; $msg = "Room $rnum not found for $gender"; $skipped++; }
                        }

                        if ($status === 'ok') {
                            try {
                                $pdo->prepare("
                                    INSERT INTO students (reg_number, full_name, email, phone, gender,
                                        year_of_study, course, current_room_id, is_active)
                                    VALUES (?,?,?,?,?,?,?,?,1)
                                ")->execute([$reg, $name, $email, $phone ?: null, $gender, $year, $course ?: null, $roomId]);
                                $inserted++;
                            } catch (Throwable $e) {
                                $status = 'error'; $msg = 'DB error';
                            }
                        }
                    }
                }

                $rows[] = ['reg'=>$reg, 'name'=>$name, 'email'=>$email, 'gender'=>$gender, 'status'=>$status, 'msg'=>$msg];
            }
            fclose($handle);

            audit_log($_SESSION['user_id'], null, 'student_import', "CSV import: $inserted inserted, $skipped skipped");
            $report = ['inserted'=>$inserted, 'skipped'=>$skipped, 'rows'=>$rows];
        }
    }
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav" class="d-lg-flex">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content" class="col-lg-9 col-xl-10">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">

            <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-file-csv text-primary me-2"></i>Import Students (CSV)</h4>
                    <p class="text-muted small mb-0">Bulk-register students from a spreadsheet</p>
                </div>
                <a href="students.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
            </div>

            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm"><div class="card-body">
                        <h6 class="fw-bold mb-3"><i class="fas fa-info-circle text-primary me-2"></i>CSV Format</h6>
                        <p class="small text-muted mb-2">First row must be a header. Columns:</p>
                        <pre class="bg-light p-3 rounded small mb-3">reg_number,full_name,email,phone,gender,year_of_study,course,current_room_number
UEAB/100,Jane Doe,jane@ueab.ac.ke,0712000000,Female,2,CS,C-101</pre>
                        <ul class="small text-muted mb-3">
                            <li><code>gender</code> must be <code>Male</code> or <code>Female</code></li>
                            <li><code>year_of_study</code> is a number 1–6</li>
                            <li><code>current_room_number</code> is optional — must match a room in the student's wing</li>
                        </ul>

                        <form method="post" enctype="multipart/form-data">
                            <label class="form-label">Select CSV</label>
                            <input type="file" name="csv" class="form-control mb-3" accept=".csv,.txt" required>
                            <button class="btn btn-primary w-100"><i class="fas fa-upload me-1"></i>Upload &amp; Import</button>
                        </form>

                        <hr>
                        <a href="data:text/csv;charset=utf-8,reg_number%2Cfull_name%2Cemail%2Cphone%2Cgender%2Cyear_of_study%2Ccourse%2Ccurrent_room_number%0A"
                           download="students_template.csv" class="btn btn-outline-secondary btn-sm w-100">
                            <i class="fas fa-download me-1"></i>Download Template
                        </a>
                    </div></div>
                </div>

                <div class="col-lg-7">
                    <?php if ($report): ?>
                        <div class="card border-0 shadow-sm"><div class="card-body">
                            <?php if (isset($report['error'])): ?>
                                <div class="alert alert-danger mb-0"><?= h($report['error']) ?></div>
                            <?php else: ?>
                                <h6 class="fw-bold mb-3"><i class="fas fa-clipboard-check text-success me-2"></i>Import Result</h6>
                                <div class="row g-2 mb-3 text-center">
                                    <div class="col-4"><div class="p-3 bg-success-subtle rounded">
                                        <div class="fs-4 fw-bold text-success"><?= $report['inserted'] ?></div>
                                        <div class="small">Inserted</div>
                                    </div></div>
                                    <div class="col-4"><div class="p-3 bg-warning-subtle rounded">
                                        <div class="fs-4 fw-bold text-warning"><?= $report['skipped'] ?></div>
                                        <div class="small">Skipped</div>
                                    </div></div>
                                    <div class="col-4"><div class="p-3 bg-light rounded">
                                        <div class="fs-4 fw-bold"><?= count($report['rows']) ?></div>
                                        <div class="small">Rows</div>
                                    </div></div>
                                </div>

                                <div class="table-responsive" style="max-height:400px;overflow-y:auto;">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light"><tr><th>Reg #</th><th>Name</th><th>Gender</th><th>Status</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($report['rows'] as $r): ?>
                                            <tr>
                                                <td class="small"><?= h($r['reg']) ?></td>
                                                <td class="small"><?= h($r['name']) ?></td>
                                                <td class="small"><?= h($r['gender']) ?></td>
                                                <td>
                                                    <?php if ($r['status']==='ok'): ?>
                                                        <span class="badge bg-success">OK</span>
                                                    <?php elseif ($r['status']==='skip'): ?>
                                                        <span class="badge bg-warning text-dark" title="<?= h($r['msg']) ?>">Skip</span>
                                                        <small class="text-muted d-block" style="font-size:.7rem;"><?= h($r['msg']) ?></small>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Error</span>
                                                        <small class="text-muted d-block" style="font-size:.7rem;"><?= h($r['msg']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <a href="students.php" class="btn btn-primary btn-sm mt-3"><i class="fas fa-users me-1"></i>View Students</a>
                            <?php endif; ?>
                        </div></div>
                    <?php else: ?>
                        <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-5 text-muted">
                            <i class="fas fa-file-upload fa-3x mb-3 opacity-25"></i>
                            <h6>Upload a CSV to see results</h6>
                        </div></div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>