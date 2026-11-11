<?php
// user/buildings.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$isAdmin = is_admin();
$gender  = gender_filter();
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Delete handler (admin only)
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE block_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cannot delete — building has rooms.'];
        } else {
            $pdo->prepare("DELETE FROM blocks WHERE id = ?")->execute([$id]);
            audit_log($_SESSION['user_id'], null, 'block_delete', "Deleted block #$id");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Building deleted.'];
        }
    }
    redirect(APP_BASE . '/user/buildings.php');
}

$where = ' WHERE 1=1'; $params = [];
if ($gender !== null) { $where .= ' AND b.gender = ?'; $params[] = $gender; }

$stmt = $pdo->prepare("
    SELECT b.*,
           (SELECT COUNT(*) FROM rooms r WHERE r.block_id = b.id) room_count,
           (SELECT COUNT(*) FROM rooms r WHERE r.block_id = b.id AND r.status='occupied') occupied_count
    FROM blocks b $where
    ORDER BY b.gender ASC, b.name ASC
");
$stmt->execute($params);
$blocks = $stmt->fetchAll();

$grouped = ['Male'=>[], 'Female'=>[]];
foreach ($blocks as $b) $grouped[$b['gender']][] = $b;

function h_gender_badge(string $g): string {
    return $g === 'Male'
        ? '<span class="badge bg-primary"><i class="fas fa-mars me-1"></i>Male</span>'
        : '<span class="badge bg-danger"><i class="fas fa-venus me-1"></i>Female</span>';
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav" class="d-lg-flex">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content" class="col-lg-9 col-xl-10">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-building text-primary me-2"></i>Buildings</h4>
                    <p class="text-muted small mb-0"><?= count($blocks) ?> building<?= count($blocks)===1?'':'s' ?><?= $gender ? ' · '.h($gender).' wing' : ' · All wings' ?></p>
                </div>
                <?php if ($isAdmin): ?>
                <a href="building_form.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus-circle me-1"></i>Add Building
                </a>
                <?php endif; ?>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php foreach (['Male','Female'] as $wing): ?>
                <?php if ($gender !== null && $gender !== $wing) continue; ?>
                <?php if (empty($grouped[$wing])) continue; ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><?= h_gender_badge($wing) ?></h6></div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Name</th><th>Description</th><th class="text-center">Rooms</th><th class="text-center">Occupied</th><th>Status</th>
                                    <?php if ($isAdmin): ?><th class="text-end">Actions</th><?php endif; ?></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($grouped[$wing] as $b): ?>
                                    <tr>
                                        <td><div class="fw-semibold"><?= h($b['name']) ?></div><div class="text-muted" style="font-size:.72rem;">#<?= (int)$b['id'] ?></div></td>
                                        <td class="small text-muted"><?= h($b['description'] ?: '—') ?></td>
                                        <td class="text-center"><span class="badge bg-light text-dark"><?= (int)$b['room_count'] ?></span></td>
                                        <td class="text-center"><span class="badge bg-success"><?= (int)$b['occupied_count'] ?></span></td>
                                        <td><?= $b['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                                        <?php if ($isAdmin): ?>
                                        <td class="text-end">
                                            <a href="building_form.php?id=<?= (int)$b['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this building?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($blocks)): ?>
                <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted">
                    <i class="fas fa-building fa-3x mb-3 opacity-25"></i><h6>No buildings yet</h6>
                    <?php if ($isAdmin): ?>
                        <a href="building_form.php" class="btn btn-primary btn-sm mt-2"><i class="fas fa-plus-circle me-1"></i>Create First Building</a>
                    <?php endif; ?>
                </div></div>
            <?php endif; ?>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>