<?php
require_once __DIR__ . '/notify.php';
// includes/beds.php — bed-level model helpers.
// Truth: students.current_bed_id (occupant) + live bookings.bed_id (holds).
// beds.status is the materialised, atomically-claimed state; rooms.status is derived from it
// so every older page that reads rooms.status / students.current_room_id keeps working.

const BED_LIVE_BOOKING = "('pending_otp','pending_payment','pending_approval','confirmed')";

/** Make sure a room has exactly bunks*2 + singles bed rows (never deletes a bed in use). */
function beds_ensure_for_room(PDO $pdo, int $roomId): void {
    $r = $pdo->prepare("SELECT id, capacity, bunks, singles FROM rooms WHERE id=?");
    $r->execute([$roomId]);
    $room = $r->fetch();
    if (!$room) return;

    $bunks = (int)$room['bunks']; $singles = (int)$room['singles'];
    if ($bunks * 2 + $singles === 0) {                 // not configured yet: derive from capacity
        $bunks = intdiv((int)$room['capacity'], 2);
        $singles = (int)$room['capacity'] % 2;
        $pdo->prepare("UPDATE rooms SET bunks=?, singles=? WHERE id=?")->execute([$bunks, $singles, $roomId]);
    }
    $want = [];   // label => [kind, bunk_no]
    $letter = 0;
    for ($b = 1; $b <= $bunks; $b++) {
        $want[chr(65 + $letter++)] = ['lower', $b];
        $want[chr(65 + $letter++)] = ['upper', $b];
    }
    for ($s = 1; $s <= $singles; $s++) $want[chr(65 + $letter++)] = ['single', null];

    $cur = $pdo->prepare("SELECT id, label, kind, bunk_no, status FROM beds WHERE room_id=? AND is_active=1 ORDER BY label");
    $cur->execute([$roomId]);
    $have = [];
    foreach ($cur as $b) $have[$b['label']] = $b;

    $ins = $pdo->prepare("INSERT INTO beds (room_id,label,kind,bunk_no,status) VALUES (?,?,?,?, 'vacant')
                          ON DUPLICATE KEY UPDATE is_active=1, kind=VALUES(kind), bunk_no=VALUES(bunk_no)");
    foreach ($want as $label => [$kind, $bn]) {
        if (!isset($have[$label])) $ins->execute([$roomId, $label, $kind, $bn]);
        elseif ($have[$label]['kind'] !== $kind || (int)$have[$label]['bunk_no'] !== (int)$bn)
            $pdo->prepare("UPDATE beds SET kind=?, bunk_no=? WHERE id=?")->execute([$kind, $bn, $have[$label]['id']]);
    }
    foreach ($have as $label => $b) {                   // surplus beds: retire only if free
        if (!isset($want[$label]) && in_array($b['status'], ['vacant', 'available_p2'], true)) {
            $pdo->prepare("UPDATE beds SET is_active=0 WHERE id=?")->execute([$b['id']]);
        }
    }
    $cap = $bunks * 2 + $singles;
    $pdo->prepare("UPDATE rooms SET capacity=? WHERE id=?")->execute([max(1, $cap), $roomId]);
}

/** Recompute rooms.status from its beds. */
function beds_sync_room(PDO $pdo, int $roomId): void {
    $q = $pdo->prepare("SELECT status, COUNT(*) n FROM beds WHERE room_id=? AND is_active=1 GROUP BY status");
    $q->execute([$roomId]);
    $c = ['vacant' => 0, 'available_p2' => 0, 'reserved' => 0, 'occupied' => 0];
    foreach ($q as $r) $c[$r['status']] = (int)$r['n'];
    if ($c['vacant'] + $c['available_p2'] > 0) $st = $c['available_p2'] > 0 ? 'available_p2' : 'vacant';
    elseif ($c['reserved'] > 0) $st = 'reserved';
    else $st = 'occupied';
    $pdo->prepare("UPDATE rooms SET status=?, reserved_by_student_id=NULL, reserved_at=NULL WHERE id=?")->execute([$st, $roomId]);
}

/** Release holds whose OTP window passed without verification. */
function beds_expire_holds(PDO $pdo): int {
    $rows = $pdo->query("SELECT id, student_id, bed_id, room_id, phase, action FROM bookings
                         WHERE status='pending_otp' AND otp_expires_at IS NOT NULL AND otp_expires_at < NOW()")->fetchAll();
    foreach ($rows as $b) {
        $pdo->prepare("UPDATE bookings SET status='cancelled', cancelled_reason='Verification time ran out' WHERE id=? AND status='pending_otp'")->execute([$b['id']]);
        if (function_exists('notify_student')) notify_student($pdo, (int)$b['student_id'], 'Bed hold expired', 'Your 5-minute hold ran out and the bed was released.', '/student/explore.php', 'expired');
    }
    if ($rows) beds_reconcile($pdo);
    return count($rows);
}

/** Bring beds, students and rooms back into agreement after any legacy room-level write. */
function beds_reconcile(PDO $pdo): void {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        // 1) bookings that are live but have no bed yet (legacy flows) -> give them a free bed in their room
        $orph = $pdo->query("SELECT b.id, b.room_id, b.student_id, b.action FROM bookings b
                             WHERE b.status IN " . BED_LIVE_BOOKING . " AND b.bed_id IS NULL")->fetchAll();
        foreach ($orph as $o) {
            if ($o['action'] === 'release') continue;
            if ($o['action'] === 'keep') {
                $bid = $pdo->prepare("SELECT current_bed_id FROM students WHERE id=?"); $bid->execute([$o['student_id']]);
                $bed = (int)$bid->fetchColumn();
            } else $bed = 0;
            if (!$bed) {
                $f = $pdo->prepare("SELECT id FROM beds WHERE room_id=? AND is_active=1 AND id NOT IN (SELECT bed_id FROM bookings WHERE bed_id IS NOT NULL AND status IN " . BED_LIVE_BOOKING . ")
                                    AND id NOT IN (SELECT current_bed_id FROM students WHERE current_bed_id IS NOT NULL) ORDER BY id LIMIT 1");
                $f->execute([$o['room_id']]); $bed = (int)$f->fetchColumn();
            }
            if ($bed) $pdo->prepare("UPDATE bookings SET bed_id=? WHERE id=?")->execute([$bed, $o['id']]);
        }
        // 2) occupants: students who have a room but a missing/wrong bed
        $stu = $pdo->query("SELECT s.id, s.current_room_id, s.current_bed_id, bd.room_id AS bed_room FROM students s
                            LEFT JOIN beds bd ON bd.id=s.current_bed_id WHERE s.current_room_id IS NOT NULL")->fetchAll();
        foreach ($stu as $s) {
            if ($s['current_bed_id'] && (int)$s['bed_room'] === (int)$s['current_room_id']) continue;
            $pref = $pdo->prepare("SELECT bed_id FROM bookings WHERE student_id=? AND status='confirmed' AND bed_id IS NOT NULL AND room_id=? ORDER BY id DESC LIMIT 1");
            $pref->execute([$s['id'], $s['current_room_id']]); $bed = (int)$pref->fetchColumn();
            if (!$bed) {
                $f = $pdo->prepare("SELECT id FROM beds WHERE room_id=? AND is_active=1 AND id NOT IN (SELECT current_bed_id FROM students WHERE current_bed_id IS NOT NULL)
                                    AND id NOT IN (SELECT bed_id FROM bookings WHERE bed_id IS NOT NULL AND status IN " . BED_LIVE_BOOKING . ") ORDER BY id LIMIT 1");
                $f->execute([$s['current_room_id']]); $bed = (int)$f->fetchColumn();
            }
            $pdo->prepare("UPDATE students SET current_bed_id=? WHERE id=?")->execute([$bed ?: null, $s['id']]);
        }
        $pdo->exec("UPDATE students SET current_bed_id=NULL WHERE current_room_id IS NULL AND current_bed_id IS NOT NULL");
        // 3) materialise bed status
        $pdo->exec("UPDATE beds SET prev_status = status WHERE is_active=1");
        $pdo->exec("UPDATE beds SET status = CASE WHEN status IN ('occupied','reserved') THEN 'available_p2' ELSE status END,
                    student_id=NULL, reserved_by_student_id=NULL, reserved_at=NULL WHERE is_active=1");
        $pdo->exec("UPDATE beds bd JOIN bookings b ON b.bed_id=bd.id AND b.status IN " . BED_LIVE_BOOKING . "
                    SET bd.status='reserved', bd.reserved_by_student_id=b.student_id, bd.reserved_at=b.created_at WHERE bd.is_active=1");
        $pdo->exec("UPDATE beds bd JOIN students s ON s.current_bed_id=bd.id AND s.is_active=1 AND s.current_room_id IS NOT NULL
                    SET bd.status='occupied', bd.student_id=s.id, bd.reserved_by_student_id=NULL, bd.reserved_at=NULL WHERE bd.is_active=1");
        $pdo->exec("UPDATE beds SET updated_at=NOW() WHERE is_active=1 AND (status<>prev_status OR prev_status IS NULL)");
        foreach ($pdo->query("SELECT id FROM rooms")->fetchAll(PDO::FETCH_COLUMN) as $rid) beds_sync_room($pdo, (int)$rid);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Atomic claim of a single free bed. Returns true only for the one caller who wins. */
function bed_claim(PDO $pdo, int $bedId, int $studentId): bool {
    $u = $pdo->prepare("UPDATE beds bd JOIN rooms r ON r.id=bd.room_id
                        SET bd.status='reserved', bd.reserved_by_student_id=?, bd.reserved_at=NOW(), bd.updated_at=NOW()
                        WHERE bd.id=? AND bd.is_active=1 AND bd.status IN ('vacant','available_p2')");
    $u->execute([$studentId, $bedId]);
    return $u->rowCount() === 1;
}

function bed_kind_label(string $kind): string {
    return ['lower' => 'Lower bunk', 'upper' => 'Upper bunk', 'single' => 'Single bed'][$kind] ?? $kind;
}
