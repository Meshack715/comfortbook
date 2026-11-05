<?php
// user/student_form.php — add + edit
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$student = null;
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    if (!$student) { $_SESSION['flash']=['type'=>'danger','msg'=>'Student not found.']; redirect(APP_BASE.'/user/students.php'); }
}

// All rooms (for current_room_id dropdown)
$allRooms = $pdo->query("
    SELECT r.id, r.room_number, r.gender, b.name block_name
    FROM rooms r JOIN blocks b ON b.id = r.block_id
    ORDER BY r.gender, b.name, r.room_number
")->fetchAll();

$errors = [];
$form = $isEdit ? [
    'reg_number'=>$student['reg_number'], 'full_name'=>$student['full_name'],
    'email'=>$student['email'], 'phone'=>$student['phone'] ?? '',
    'gender'=>$student['gender'], 'year_of_study'=>(int)$student['year_of_study'],
    'course'=>$student['course'] ?? '', 'current_room_id'=>(int)($student['current_room_id'] ?? 0),
    'is_active'=>(int)$student['is_active'],
] : [
    'reg_number'=>'', 'full_name'=>'', 'email'=>'', 'phone'=>'',
    'gender'=>'Male', 'year_of_study'=>1, 'course'=>'', 'current_room_id'=>0, 'is_active'=>1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['reg_number','full_name','email','phone','course'] as $k) $form[$k] = trim($_POST[$k] ?? '');
    $form['gender']         = $_POST['gender'] ?? 'Male';
    $form['year_of_study']  = (int)($_POST['year_of_study'] ?? 1);
    $form['current_room_id']= (int)($_POST['current_room_id'] ?? 0);
    $form['is_active']      = isset($_POST['is_active']) ? 1 : 0;

    if ($form['reg_number'] === '') $errors[] = 'Reg number is required.';
    if ($form['full_name'] === '')  $errors[] = 'Full name is required.';
    if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (!in_array($form['gender'], ['Male','Female'], true)) $errors[] = 'Gender is required.';
    if ($form['year_of_study'] < 1 || $form['year_of_study'] > 6) $errors[] = 'Year 1–6.';

    // Room gender must match student gender
    if (!$errors && $form['current_room_id'] > 0) {
        $stmt = $pdo->prepare("SELECT gender FROM rooms WHERE id = ?");
        $stmt->execute([$form['current_room_id']]);
        $rg = $stmt->fetchColumn();
        if ($rg !== $form['gender']) $errors[] = 'Selected room is for ' . $rg . ' students.';
    }

    // Uniqueness
    if (!$errors) {
        $sql = "SELECT COUNT(*) FROM students WHERE reg_number = ?"; $p = [$form['reg_number']];
        if ($isEdit) { $sql .= " AND id <> ?"; $p[] = $id; }
        $stmt = $pdo->prepare($sql); $stmt->execute($p);
        if ((int)$stmt->fetchColumn() > 0) $errors[] = 'Reg number already exists.';

        $sql = "SELECT COUNT(*) FROM students WHERE email = ?"; $p = [$form['email']];
        if ($isEdit) { $sql .= " AND id <> ?"; $p[] = $id; }
        $stmt = $pdo->prepare($sql); $stmt->execute($p);
        if ((int)$stmt->fetchColumn() > 0) $errors[] = 'Email already exists.';
    }

    if (!$errors) {
        $roomId = $form['current_room_id'] ?: null;
        if ($isEdit) {
            $pdo->prepare("
                UPDATE students SET reg_number=?, full_name=?, email=?, phone=?, gender=?,
                    year_of_study=?, course=?, current_room_id=?, is_active=?
                WHERE id=?
            ")->execute([
                $form['reg_number'], $form['full_name'], $form['email'], $form['phone'] ?: null,
                $form['gender'], $form['year_of_study'], $form['course'] ?: null,
                $roomId, $form['is_active'], $id,
            ]);
            audit_log($_SESSION['user_id'], $id, 'student_edit', "Edited student #$id");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Student updated.'];
        } else {
            $pdo->prepare("
                INSERT INTO students (reg_number, full_name, email, phone, gender,
                    year_of_study, course, current_room_id, is_active)
                VALUES (?,?,?,?,?,?,?,?,?)
            ")->execute([
                $form['reg_number'], $form['full_name'], $form['email'], $form['phone'] ?: null,
                $form['gender'], $form['year_of_study'], $form['course'] ?: null,
                $roomId, $form['is_active'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($_SESSION['user_id'], $newId, 'student_add', "Added student #$newId");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Student created.'];
        }
        redirect(APP_BASE . '/user/students.php');
    }
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-<?= $isEdit?'edit':'user-plus' ?> text-primary me-2"></i><?= $isEdit?'Edit':'Add' ?> Student</h4>
                    <p class="text-muted small mb-0"><?= $isEdit?'#'.$id.' · '.h($student['full_name']):'Register a new student' ?></p>
                </div>
                <a href="students.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <form method="post">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Reg Number <span class="text-danger">*</span></label>
                            <input type="text" name="reg_number" class="form-control" required value="<?= h($form['reg_number']) ?>" placeholder="UEAB/001">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required value="<?= h($form['full_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required value="<?= h($form['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= h($form['phone']) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-select" required>
                                <option value="Male"   <?= $form['gender']==='Male'?'selected':'' ?>>Male</option>
                                <option value="Female" <?= $form['gender']==='Female'?'selected':'' ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Year <span class="text-danger">*</span></label>
                            <input type="number" name="year_of_study" class="form-control" required min="1" max="6" value="<?= (int)$form['year_of_study'] ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Course</label>
                            <input type="text" name="course" class="form-control" value="<?= h($form['course']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Current Room</label>
                            <select name="current_room_id" class="form-select">
                                <option value="0">— None —</option>
                                <?php
                                $groupedRooms = ['Male'=>[], 'Female'=>[]];
                                foreach ($allRooms as $r) $groupedRooms[$r['gender']][] = $r;
                                foreach ($groupedRooms as $rg => $list):
                                    if (empty($list)) continue;
                                ?>
                                    <optgroup label="<?= $rg ?> Rooms">
                                        <?php foreach ($list as $r): ?>
                                            <option value="<?= (int)$r['id'] ?>" <?= $form['current_room_id']===(int)$r['id']?'selected':'' ?>>
                                                <?= h($r['room_number']) ?> · <?= h($r['block_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Must match student's gender.</small>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-4">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= $form['is_active']?'checked':'' ?>>
                                <label class="form-check-label" for="isActive">Active</label>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <button class="btn btn-primary"><i class="fas fa-save me-1"></i><?= $isEdit?'Save Changes':'Create' ?></button>
                    <a href="students.php" class="btn btn-outline-secondary">Cancel</a>
                </form>
            </div></div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>