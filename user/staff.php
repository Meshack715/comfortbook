<?php
// user/staff.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$gender = gender_filter(); // always null for admin
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && $id !== (int)$_SESSION['user_id']) {
        $pdo->prepare("DELETE FROM users WHERE id = ? AND role <> 'admin'")->execute([$id]);
        audit_log($_SESSION['user_id'], null, 'staff_delete', "Deleted staff #$id");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Staff account deleted.'];
    } else {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cannot delete your own account.'];
    }
    redirect(APP_BASE . '/user/staff.php');
}

$filterRole = $_GET['role'] ?? '';
$search     = trim($_GET['q'] ?? '');

$where = " WHERE 1=1"; $params = [];
if (in_array($filterRole, ['admin','dean_men','dean_women','secretary_men','secretary_women'], true)) {
    $where .= ' AND role = ?'; $params[] = $filterRole;
}
if ($search !== '') {
    $where .= ' AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $pdo->prepare("SELECT * FROM users $where ORDER BY role, full_name");
$stmt->execute($params);
$staff = $stmt->fetchAll();

function role_badge(string $r): string {
    return match($r) {
        'admin'           => '<span class="badge bg-dark">Admin</span>',
        'dean_men'        => '<span class="badge bg-primary">Dean · Men</span>',
        'dean_women'      => '<span class="badge bg-danger">Dean · Women</span>',
        'secretary_men'   => '<span class="badge bg-info text-dark">Sec · Men</span>',
        'secretary_women' => '<span class="badge bg-warning text-dark">Sec · Women</span>',
        default           => '<span class="badge bg-light text-dark">' . h($r) . '</span>',
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
                    <h4 class="mb-1"><i class="fas fa-users-cog text-primary me-2"></i>Staff Accounts</h4>
                    <p class="text-muted small mb-0"><?= count($staff) ?> account<?= count($staff)===1?'':'s' ?></p>
                </div>
                <a href="staff_form.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus me-1"></i>Add Staff</a>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-5"><label class="form-label small mb-1">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Name, username, email"></div>
                    <div class="col-md-4"><label class="form-label small mb-1">Role</label>
                        <select name="role" class="form-select form-select-sm">
                            <option value="">All roles</option>
                            <?php foreach (['admin'=>'Admin','dean_men'=>'Dean · Men','dean_women'=>'Dean · Women','secretary_men'=>'Sec · Men','secretary_women'=>'Sec · Women'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= $filterRole===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill"><i class="fas fa-filter me-1"></i>Filter</button>
                        <a href="staff.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($staff)): ?>
                    <div class="text-center py-5 text-muted"><i class="fas fa-users-cog fa-3x mb-3 opacity-25"></i><h6>No staff found</h6></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr><th>Name</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th class="text-end">Actions</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($staff as $u): ?>
                                <tr>
                                    <td class="fw-semibold"><?= h($u['full_name']) ?></td>
                                    <td class="small"><?= h($u['username']) ?></td>
                                    <td class="small text-muted"><?= h($u['email']) ?></td>
                                    <td><?= role_badge($u['role']) ?></td>
                                    <td><?= $u['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
                                    <td class="text-end">
                                        <a href="staff_form.php?id=<?= (int)$u['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                                        <?php if ($u['role'] !== 'admin' && (int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this staff account?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
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