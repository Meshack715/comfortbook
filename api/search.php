<?php
// api/search.php — quick entity search for the header
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['ok'=>true, 'results'=>[]]);
    exit;
}

$gender  = gender_filter();     // null for admin
$isAdmin = is_admin();
$like    = "%$q%";
$results = [];

// ---------- STUDENTS ----------
$sql = "
    SELECT s.id, s.full_name, s.reg_number, s.gender, r.room_number, b.name AS block_name
    FROM students s
    LEFT JOIN rooms r ON r.id = s.current_room_id
    LEFT JOIN blocks b ON b.id = r.block_id
    WHERE s.is_active = 1
      AND (s.full_name LIKE ? OR s.reg_number LIKE ? OR s.email LIKE ?)
";
$params = [$like, $like, $like];
if ($gender) { $sql .= " AND s.gender = ?"; $params[] = $gender; }
$sql .= " ORDER BY s.full_name LIMIT 5";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
foreach ($stmt as $r) {
    $results[] = [
        'type'      => 'student',
        'icon'      => 'fa-user-graduate',
        'color'     => 'primary',
        'title'     => $r['full_name'],
        'subtitle'  => $r['reg_number'] . ($r['room_number'] ? ' · ' . $r['room_number'] . ' · ' . $r['block_name'] : ''),
        'url'       => APP_BASE . '/user/students.php?q=' . urlencode($r['reg_number']),
    ];
}

// ---------- ROOMS ----------
$sql = "
    SELECT r.id, r.room_number, r.capacity, r.gender, b.name AS block_name
    FROM rooms r JOIN blocks b ON b.id = r.block_id
    WHERE (r.room_number LIKE ? OR b.name LIKE ?)
";
$params = [$like, $like];
if ($gender) { $sql .= " AND r.gender = ?"; $params[] = $gender; }
$sql .= " ORDER BY b.name, r.room_number LIMIT 5";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
foreach ($stmt as $r) {
    $results[] = [
        'type'      => 'room',
        'icon'      => 'fa-bed',
        'color'     => 'success',
        'title'     => 'Room ' . $r['room_number'],
        'subtitle'  => $r['block_name'] . ' · ' . $r['capacity'] . ' capacity',
        'url'       => APP_BASE . '/user/rooms.php?q=' . urlencode($r['room_number']),
    ];
}

// ---------- BLOCKS (admin only) ----------
if ($isAdmin) {
    $stmt = $pdo->prepare("
        SELECT id, name, gender FROM blocks
        WHERE is_active = 1 AND name LIKE ?
        ORDER BY name LIMIT 3
    ");
    $stmt->execute([$like]);
    foreach ($stmt as $r) {
        $results[] = [
            'type'      => 'block',
            'icon'      => 'fa-building',
            'color'     => 'info',
            'title'     => $r['name'],
            'subtitle'  => $r['gender'] . ' · Building',
            'url'       => APP_BASE . '/user/buildings.php?q=' . urlencode($r['name']),
        ];
    }

    // ---------- STAFF (admin only) ----------
    $stmt = $pdo->prepare("
        SELECT id, username, full_name, role FROM users
        WHERE is_active = 1
          AND (full_name LIKE ? OR username LIKE ? OR email LIKE ?)
        ORDER BY full_name LIMIT 3
    ");
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt as $r) {
        $results[] = [
            'type'      => 'staff',
            'icon'      => 'fa-user-tie',
            'color'     => 'warning',
            'title'     => $r['full_name'],
            'subtitle'  => '@' . $r['username'] . ' · ' . ucwords(str_replace('_',' ', $r['role'])),
            'url'       => APP_BASE . '/user/staff.php?q=' . urlencode($r['username']),
        ];
    }
}

echo json_encode(['ok'=>true, 'results'=>$results]);