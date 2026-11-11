<?php
// includes/scene_data.php — everything the 3D client needs, scoped to who is asking.
require_once __DIR__ . '/beds.php';

/** Who is asking? ['mode'=>'student'|'staff', 'student'=>row|null, 'gender'=>'Male'|'Female'|null (null = all)] or null */
function scene_actor(PDO $pdo): ?array {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $onStudentSide = (bool)preg_match('#/students?/#', (string)($_SERVER['SCRIPT_NAME'] ?? '')) || (bool)preg_match('#/students?/#', (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH));
    if ($onStudentSide && !empty($_SESSION['magic_student_id'])) {       // a dean testing a student link in the same browser still gets the STUDENT experience on student pages
        $q = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=1"); $q->execute([(int)$_SESSION['magic_student_id']]); $s = $q->fetch();
        if ($s) return ['mode' => 'student', 'student' => $s, 'gender' => $s['gender'], 'admin' => false];
    }
    if (!empty($_SESSION['user_id'])) {
        return ['mode' => 'staff', 'student' => null, 'gender' => $_SESSION['gender_scope'] ?? null,
                'admin' => ($_SESSION['role'] ?? '') === 'admin', 'user_id' => (int)$_SESSION['user_id']];
    }
    if (!empty($_SESSION['magic_student_id'])) {
        $q = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=1");
        $q->execute([(int)$_SESSION['magic_student_id']]);
        $s = $q->fetch();
        if ($s) return ['mode' => 'student', 'student' => $s, 'gender' => $s['gender'], 'admin' => false];
    }
    return null;
}

function scene_my_hold(PDO $pdo, ?array $student): array {
    if (!$student) return ['bed_id' => null, 'booking_id' => null, 'status' => null, 'expires' => null];
    $q = $pdo->prepare("SELECT id, bed_id, status, otp_expires_at, action FROM bookings
                        WHERE student_id=? AND status IN ('pending_otp','pending_payment','pending_approval','confirmed') ORDER BY id DESC LIMIT 1");
    $q->execute([$student['id']]);
    $b = $q->fetch();
    if (!$b) return ['bed_id' => $student['current_bed_id'] ? (int)$student['current_bed_id'] : null, 'booking_id' => null, 'status' => null, 'expires' => null];
    return ['bed_id' => $b['bed_id'] ? (int)$b['bed_id'] : (($student['current_bed_id'] ?? null) ? (int)$student['current_bed_id'] : null),
            'booking_id' => (int)$b['id'], 'status' => $b['status'], 'action' => $b['action'],
            'expires' => ($b['status'] === 'pending_otp' && $b['otp_expires_at']) ? strtotime($b['otp_expires_at']) : null];
}

function scene_full(PDO $pdo, array $actor): array {
    beds_expire_holds($pdo);
    $gender = $actor['gender'];
    $blocks = $pdo->query("SELECT id, name, gender, floors, layout_json, is_active FROM blocks WHERE is_active=1 ORDER BY id")->fetchAll();
    $out = ['blocks' => [], 'rooms' => []];
    $accessible = [];
    foreach ($blocks as $b) {
        $ok = ($gender === null) || $b['gender'] === $gender;      // students & scoped staff see only their wing in detail
        $accessible[(int)$b['id']] = $ok;
        $layout = $b['layout_json'] ? json_decode($b['layout_json'], true) : null;
        $out['blocks'][] = ['id' => (int)$b['id'], 'name' => $b['name'], 'gender' => $b['gender'], 'floors' => (int)$b['floors'], 'layout' => $layout, 'accessible' => $ok];
    }
    $myBed = $actor['student']['current_bed_id'] ?? null;
    $hold = scene_my_hold($pdo, $actor['student']);
    $mineIds = array_filter([$myBed, $hold['bed_id']]);

    $rooms = $pdo->query("SELECT id, block_id, room_number, floor_no, wing_key, side, slot, width, depth, capacity, bath_type, status, gender FROM rooms ORDER BY block_id, floor_no, wing_key, side, slot")->fetchAll();
    $bedsBy = [];
    $beds = $pdo->query("SELECT bd.id, bd.room_id, bd.label, bd.kind, bd.bunk_no, bd.pos_x, bd.pos_z, bd.rot, bd.status, bd.student_id, bd.reserved_by_student_id,
                                s.full_name, s.reg_number, rs.full_name AS rname
                         FROM beds bd LEFT JOIN students s ON s.id=bd.student_id LEFT JOIN students rs ON rs.id=bd.reserved_by_student_id
                         WHERE bd.is_active=1 ORDER BY bd.room_id, bd.label")->fetchAll();
    foreach ($beds as $bd) $bedsBy[(int)$bd['room_id']][] = $bd;

    foreach ($rooms as $r) {
        $rid = (int)$r['id'];
        $show = $accessible[(int)$r['block_id']] ?? false;
        $row = ['id' => $rid, 'block' => (int)$r['block_id'], 'number' => $r['room_number'], 'floor' => (int)$r['floor_no'], 'wing' => $r['wing_key'],
                'side' => $r['side'], 'slot' => (int)$r['slot'], 'w' => (float)$r['width'], 'd' => (float)$r['depth'], 'cap' => (int)$r['capacity'],
                'bath' => $r['bath_type'], 'locked' => !$show, 'beds' => []];
        if ($show) foreach ($bedsBy[$rid] ?? [] as $bd) {
            $bid = (int)$bd['id'];
            $b = ['id' => $bid, 'label' => $bd['label'], 'kind' => $bd['kind'], 'bunk' => $bd['bunk_no'] ? (int)$bd['bunk_no'] : null,
                  'x' => $bd['pos_x'] !== null ? (float)$bd['pos_x'] : null, 'z' => $bd['pos_z'] !== null ? (float)$bd['pos_z'] : null,
                  'rot' => $bd['rot'] !== null ? (int)$bd['rot'] : null, 'status' => $bd['status'], 'mine' => in_array($bid, $mineIds, true)];
            if ($actor['mode'] === 'staff') $b['who'] = $bd['full_name'] ? $bd['full_name'] . ' (' . $bd['reg_number'] . ')' : ($bd['rname'] ? $bd['rname'] . ' (holding)' : null);
            $row['beds'][] = $b;
        }
        $out['rooms'][] = $row;
    }
    $out['me'] = ['mode' => $actor['mode'], 'gender' => $gender, 'student' => $actor['student'] ? ['id' => (int)$actor['student']['id'], 'name' => $actor['student']['full_name'], 'reg' => $actor['student']['reg_number']] : null, 'hold' => $hold,
                  'admin' => $actor['admin'] ?? false];
    $out['rev'] = (string)$pdo->query("SELECT COALESCE(MAX(UNIX_TIMESTAMP(updated_at)),0) FROM beds")->fetchColumn();
    $out['now'] = time();
    return $out;
}

/** Light polling payload: just bed states + my hold. */
function scene_state(PDO $pdo, array $actor): array {
    beds_expire_holds($pdo);
    $gender = $actor['gender'];
    $sql = "SELECT bd.id, bd.status, bd.student_id, bd.reserved_by_student_id FROM beds bd JOIN rooms r ON r.id=bd.room_id WHERE bd.is_active=1";
    $p = [];
    if ($gender) { $sql .= " AND r.gender=?"; $p[] = $gender; }
    $q = $pdo->prepare($sql); $q->execute($p);
    $hold = scene_my_hold($pdo, $actor['student']);
    $mine = array_filter([($actor['student']['current_bed_id'] ?? null), $hold['bed_id']]);
    $beds = [];
    foreach ($q as $b) $beds[] = [(int)$b['id'], $b['status'], in_array((int)$b['id'], array_map('intval', $mine), true) ? 1 : 0];
    return ['beds' => $beds, 'hold' => $hold, 'now' => time()];
}
