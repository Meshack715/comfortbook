<?php
// user/rooms.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$isAdmin = is_admin();
$gender  = gender_filter();
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE room_id = ? AND status IN ('pending_otp','pending_payment','pending_approval','confirmed')");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cannot delete — room has active bookings.'];
        } else {
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
            audit_log($_SESSION['user_id'], null, 'room_delete', "Deleted room #$id");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Room deleted.'];
        }
    }
    redirect(APP_BASE . '/user/rooms.php');
}

$filterBlock  = (int)($_GET['block_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where = ' WHERE 1=1'; $params = [];
if ($gender !== null) { $where .= ' AND r.gender = ?'; $params[] = $gender; }
if ($filterBlock > 0) { $where .= ' AND r.block_id = ?'; $params[] = $filterBlock; }
if (in_array($filterStatus, ['vacant','available_p2','reserved','occupied'], true)) { $where .= ' AND r.status = ?'; $params[] = $filterStatus; }
if ($search !== '') { $where .= ' AND (r.room_number LIKE ? OR b.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }

$stmt = $pdo->prepare("
    SELECT r.*, b.name block_name, b.gender block_gender
    FROM rooms r JOIN blocks b ON b.id = r.block_id
    $where ORDER BY b.gender, b.name, r.room_number LIMIT 500
");
$stmt->execute($params);
$rooms = $stmt->fetchAll();

$bWhere = ' WHERE is_active = 1'; $bParams = [];
if ($gender !== null) { $bWhere .= ' AND gender = ?'; $bParams[] = $gender; }
$stmt = $pdo->prepare("SELECT id, name, gender FROM blocks $bWhere ORDER BY gender, name");
$stmt->execute($bParams); $blocks = $stmt->fetchAll();

$counts = ['vacant'=>0,'available_p2'=>0,'reserved'=>0,'occupied'=>0];
foreach ($rooms as $r) if (isset($counts[$r['status']])) $counts[$r['status']]++;

function status_badge(string $s): string {
    return match($s) {
        'vacant'       => '<span class="badge bg-success">Vacant</span>',
        'available_p2' => '<span class="badge bg-info text-dark">Released</span>',
        'reserved'     => '<span class="badge bg-warning text-dark">Reserved</span>',
        'occupied'     => '<span class="badge bg-primary">Occupied</span>',
        default        => '<span class="badge bg-light text-dark">' . h($s) . '</span>',
    };
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-bed text-primary me-2"></i>Rooms</h4>
                    <p class="text-muted small mb-0"><?= count($rooms) ?> room<?= count($rooms)===1?'':'s' ?><?= $gender ? ' · '.h($gender).' wing' : ' · All wings' ?></p>
                </div>
                <?php if ($isAdmin): ?>
                <a href="room_form.php" class="btn btn-primary btn-sm"><i class="fas fa-plus-circle me-1"></i>Add Room</a>
                <?php endif; ?>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="d-flex flex-wrap gap-2 mb-3">
                <a href="rooms.php" class="btn btn-sm <?= $filterStatus===''?'btn-primary':'btn-outline-primary' ?>">All <span class="badge bg-light text-dark ms-1"><?= count($rooms) ?></span></a>
                <a href="rooms.php?status=vacant" class="btn btn-sm <?= $filterStatus==='vacant'?'btn-success':'btn-outline-success' ?>">Vacant <span class="badge bg-light text-dark ms-1"><?= $counts['vacant'] ?></span></a>
                <a href="rooms.php?status=available_p2" class="btn btn-sm <?= $filterStatus==='available_p2'?'btn-info':'btn-outline-info' ?>">Released <span class="badge bg-light text-dark ms-1"><?= $counts['available_p2'] ?></span></a>
                <a href="rooms.php?status=reserved" class="btn btn-sm <?= $filterStatus==='reserved'?'btn-warning':'btn-outline-warning' ?>">Reserved <span class="badge bg-light text-dark ms-1"><?= $counts['reserved'] ?></span></a>
                <a href="rooms.php?status=occupied" class="btn btn-sm <?= $filterStatus==='occupied'?'btn-primary':'btn-outline-primary' ?>">Occupied <span class="badge bg-light text-dark ms-1"><?= $counts['occupied'] ?></span></a>
            </div>

            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-4"><label class="form-label small mb-1">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Room or building"></div>
                    <div class="col-md-3"><label class="form-label small mb-1">Building</label>
                        <select name="block_id" class="form-select form-select-sm">
                            <option value="0">All</option>
                            <?php foreach ($blocks as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $filterBlock===(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3"><label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All</option>
                            <?php foreach (['vacant','available_p2','reserved','occupied'] as $s): ?>
                                <option value="<?= $s ?>" <?= $filterStatus===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill"><i class="fas fa-filter me-1"></i>Filter</button>
                        <a href="rooms.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($rooms)): ?>
                    <div class="text-center py-5 text-muted"><i class="fas fa-bed fa-3x mb-3 opacity-25"></i><h6>No rooms found</h6></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light"><tr><th>Room</th><th>Building</th><th>Wing</th><th class="text-center">Cap</th><th>Status</th>
                            <?php if ($isAdmin): ?><th class="text-end">Actions</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($rooms as $r): ?>
                                <tr>
                                    <td class="fw-semibold"><?= h($r['room_number']) ?></td>
                                    <td class="small"><?= h($r['block_name']) ?></td>
                                    <td><?= $r['block_gender']==='Male'?'<span class="badge bg-primary"><i class="fas fa-mars"></i></span>':'<span class="badge bg-danger"><i class="fas fa-venus"></i></span>' ?></td>
                                    <td class="text-center"><?= (int)$r['capacity'] ?></td>
                                    <td><?= status_badge($r['status']) ?></td>
                                    <?php if ($isAdmin): ?>
                                    <td class="text-end">
                                        <a href="room_form.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this room?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div></div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>