<?php
require_once __DIR__ . '/../layout_presets.php';
require_once __DIR__ . '/ui_tools.php';
// includes/ai/tools.php — role-scoped tool registry. Read tools run immediately; write tools only
// create a pending ai_actions row that a human must confirm (api/ai_confirm.php).

require_once __DIR__ . '/../mpesa.php';
function ai_actor_ctx(PDO $pdo): ?array {
    $a = scene_actor($pdo); if (!$a) return null;
    if ($a['mode'] === 'student') return ['kind' => 'student', 'role' => 'student', 'gender' => $a['student']['gender'], 'student' => $a['student'], 'uid' => null];
    $role = $_SESSION['role'] ?? ''; $g = $role === 'admin' ? null : ($_SESSION['gender_scope'] ?? null);
    $kind = $role === 'admin' ? 'admin' : (str_starts_with($role, 'dean') ? 'dean' : 'secretary');
    return ['kind' => $kind, 'role' => $role, 'gender' => $g, 'student' => null, 'uid' => (int)$a['user_id']];
}

function ai_tool_defs_core(): array {
    $S = ['student', 'secretary', 'dean', 'admin']; $ST = ['secretary', 'dean', 'admin'];
    return [
        'vacancy_summary' => ['d' => 'Count vacant beds per hostel block and floor (only the blocks this user may see).', 'roles' => $S, 'w' => false,
            'p' => ['type' => 'object', 'properties' => ['block' => ['type' => 'string', 'description' => 'optional block name filter']]]],
        'find_beds' => ['d' => 'List specific vacant beds. Filter by block name, floor number, lower/upper/single kind, or ensuite bathroom.', 'roles' => $S, 'w' => false,
            'p' => ['type' => 'object', 'properties' => ['block' => ['type' => 'string'], 'floor' => ['type' => 'integer'], 'kind' => ['type' => 'string', 'enum' => ['lower', 'upper', 'single']], 'ensuite' => ['type' => 'boolean'], 'limit' => ['type' => 'integer']]]],
        'my_status' => ['d' => 'The signed-in student\'s current room, booking status, hold time left AND the exact booking-window state (not open yet / open / ended so a late fee applies / closed), the dates, and what they must do next.', 'roles' => ['student'], 'w' => false, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'cancel_my_hold' => ['d' => 'Cancel the student\'s own pending bed hold (needs confirmation).', 'roles' => ['student'], 'w' => true, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'pending_approvals' => ['d' => 'List bookings waiting for approval in this user\'s wing.', 'roles' => $ST, 'w' => false, 'p' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]]],
        'find_student' => ['d' => 'Look up a student by name, registration number or email (also shows whether their late fee is paid).', 'roles' => $ST, 'w' => false,
            'p' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]],
        'occupancy_stats' => ['d' => 'Overall occupancy: beds total, occupied, reserved, vacant, per block.', 'roles' => $ST, 'w' => false, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'approve_booking' => ['d' => 'Approve a booking that is pending approval (needs confirmation).', 'roles' => $ST, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['booking_id' => ['type' => 'integer']], 'required' => ['booking_id']]],
        'reject_booking' => ['d' => 'Reject a pending booking with a reason (needs confirmation).', 'roles' => $ST, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['booking_id' => ['type' => 'integer'], 'reason' => ['type' => 'string']], 'required' => ['booking_id']]],
        'message_student' => ['d' => 'Send an in-app + push notification to one student (needs confirmation).', 'roles' => $ST, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['student_id' => ['type' => 'integer'], 'message' => ['type' => 'string']], 'required' => ['student_id', 'message']]],
        'recommend_beds' => ['d' => 'Rank vacant beds for the student by their preferences (floor, lower/upper, ensuite bathroom, near stairs). Use when a student asks which bed to pick.', 'roles' => ['student'], 'w' => false,
            'p' => ['type' => 'object', 'properties' => ['floor' => ['type' => 'integer'], 'kind' => ['type' => 'string', 'enum' => ['lower', 'upper', 'single']], 'ensuite' => ['type' => 'boolean'], 'quiet' => ['type' => 'boolean', 'description' => 'prefer the far end of a corridor']]]],
        'daily_briefing' => ['d' => 'One-shot summary for staff: pending approvals and the oldest wait, unpaid late fees, holds about to expire, vacancy, unplaced students.', 'roles' => $ST, 'w' => false, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'unplaced_students' => ['d' => 'Students with no bed and no live booking in this wing.', 'roles' => $ST, 'w' => false, 'p' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]]],
        'payments_overview' => ['d' => 'M-Pesa late-fee payments: counts and totals today and overall, pending prompts, and payments needing attention. Only for totals; NOT for sending or testing prompts.', 'roles' => ['admin', 'dean', 'secretary'], 'w' => false, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'student_payment_status' => ['d' => 'Did this student pay? Shows what they owe, whether the late fee is paid, their booking status and their latest M-Pesa attempts in plain words. Identify the student by registration number, email or name.', 'roles' => $ST, 'w' => false,
            'p' => ['type' => 'object', 'properties' => ['student' => ['type' => 'string', 'description' => 'registration number, email or name']], 'required' => ['student']]],
        'recent_payments' => ['d' => 'The latest M-Pesa payments with plain-words results, including any that need a person to look at them.', 'roles' => $ST, 'w' => false, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'prompt_student_payment' => ['d' => 'Send an M-Pesa prompt to a student\'s phone for their late-booking fee (needs confirmation; the card then shows the live result). Use when staff say things like "prompt the late fee for SKIADA2411". The student does not need their email link. The amount is decided by the system.', 'roles' => $ST, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['student' => ['type' => 'string', 'description' => 'registration number, email or name of the student'], 'phone' => ['type' => 'string', 'description' => 'M-Pesa number to prompt; leave empty to use the number on the student\'s profile']], 'required' => ['student']]],
        'test_mpesa_prompt' => ['d' => 'Send a KES 1 M-Pesa TEST prompt to a phone number to prove payments work end to end (needs confirmation; the card then shows the live result). Use when staff ask to test M-Pesa or to be prompted. If no number is given, ask for it.', 'roles' => $ST, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['phone' => ['type' => 'string', 'description' => 'the phone to send the KES 1 test to']], 'required' => ['phone']]],
        'recent_audit' => ['d' => 'The latest audit log entries (who did what).', 'roles' => ['admin'], 'w' => false, 'p' => ['type' => 'object', 'properties' => ['limit' => ['type' => 'integer']]]],
        'bulk_approve' => ['d' => 'Approve ALL bookings currently waiting for approval in this wing, up to 25 at once (needs confirmation and an emailed code).', 'roles' => $ST, 'w' => true, 'sens' => 'code', 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'broadcast_message' => ['d' => 'Notify a group of students: unplaced, awaiting_payment, or holding (bed held but not verified). Max 300 recipients (needs confirmation and an emailed code).', 'roles' => $ST, 'w' => true, 'sens' => 'code',
            'p' => ['type' => 'object', 'properties' => ['audience' => ['type' => 'string', 'enum' => ['unplaced', 'awaiting_payment', 'holding']], 'message' => ['type' => 'string']], 'required' => ['audience', 'message']]],
        'apply_layout_preset' => ['d' => 'Re-arrange a building into a default design (changes the 3D layout of every room). Admin only; needs the admin password.', 'roles' => ['admin'], 'w' => true, 'sens' => 'password',
            'p' => ['type' => 'object', 'properties' => ['block_id' => ['type' => 'integer'], 'preset' => ['type' => 'string', 'enum' => ['straight', 'new_men', 'l_shape', 'u_shape', 'curved', 'pairbath']], 'floors' => ['type' => 'integer']], 'required' => ['block_id', 'preset']]],
        'layout_overview' => ['d' => 'Describe each building layout: floors, wings, rooms, bunks/singles, bathroom type.', 'roles' => ['admin', 'dean'], 'w' => false, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
    ];
}

function ai_tools_for(array $ctx): array {
    $out = [];
    foreach (ai_tool_defs() as $n => $t) if (in_array($ctx['kind'], $t['roles'], true)) $out[$n] = $t;
    return $out;
}
function ai_function_decls(array $ctx): array {
    $d = [];
    foreach (ai_tools_for($ctx) as $n => $t) { $p = $t['p']; if (isset($p['properties']) && $p['properties'] instanceof stdClass) { unset($p['properties']); $p = ['type' => 'object']; } $d[] = ['name' => $n, 'description' => $t['d'], 'parameters' => $p]; }
    return $d;
}

function ai_gender_sql(?string $g, string $col): array { return $g ? [" AND $col=?", [$g]] : ['', []]; }

/** Run a read tool. Returns an array for the model. */
function ai_run_read(PDO $pdo, array $ctx, string $name, array $a): array {
    switch ($name) {
        case 'vacancy_summary':
        case 'find_beds': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 'r.gender');
            $w = "b.is_active=1 AND b.status IN ('vacant','available_p2') $gs"; $p = $gp;
            if (!empty($a['block'])) { $w .= " AND bl.name LIKE ?"; $p[] = '%' . $a['block'] . '%'; }
            if ($name === 'vacancy_summary') {
                $q = $pdo->prepare("SELECT bl.name block, r.floor_no floor, COUNT(*) vacant_beds FROM beds b JOIN rooms r ON r.id=b.room_id JOIN blocks bl ON bl.id=r.block_id WHERE $w GROUP BY bl.id,r.floor_no ORDER BY bl.name,r.floor_no");
                $q->execute($p); return ['rows' => $q->fetchAll()];
            }
            if (!empty($a['floor'])) { $w .= " AND r.floor_no=?"; $p[] = (int)$a['floor']; }
            if (!empty($a['kind'])) { $w .= " AND b.kind=?"; $p[] = $a['kind']; }
            if (!empty($a['ensuite'])) $w .= " AND r.bath_type='ensuite'";
            $lim = max(1, min(15, (int)($a['limit'] ?? 8)));
            $q = $pdo->prepare("SELECT b.id bed_id, bl.name block, r.room_number room, r.floor_no floor, b.label bed, b.kind, r.bath_type FROM beds b JOIN rooms r ON r.id=b.room_id JOIN blocks bl ON bl.id=r.block_id WHERE $w ORDER BY r.floor_no, r.room_number, b.label LIMIT $lim");
            $q->execute($p); return ['beds' => $q->fetchAll(), 'note' => 'Students pick beds in the 3D explorer (Explore 3D).'];
        }
        case 'my_status': {
            $s = $ctx['student']; $h = scene_my_hold($pdo, $s);
            $q = $pdo->prepare("SELECT r.room_number, bl.name block, b.label, b.kind FROM beds b JOIN rooms r ON r.id=b.room_id JOIN blocks bl ON bl.id=r.block_id WHERE b.id=?");
            $q->execute([$h['bed_id'] ?: 0]); $bed = $q->fetch() ?: null;
            $bs = booking_state($pdo, $s, (int)($_SESSION['magic_semester_id'] ?? 0));
            $cur = null; if (!empty($s['current_room_id'])) { $cq = $pdo->prepare("SELECT r.room_number room, bl.name block FROM rooms r JOIN blocks bl ON bl.id=r.block_id WHERE r.id=?"); $cq->execute([$s['current_room_id']]); $cur = $cq->fetch() ?: null; }
            return ['name' => $s['full_name'], 'current_room' => $cur, 'phase1_choice' => $s['phase1_action'], 'bed' => $bed, 'booking_status' => $h['status'] ?: 'no booking in progress', 'seconds_left' => $h['expires'] ? max(0, $h['expires'] - time()) : null,
                'booking_window' => ['state' => $bs['state'], 'summary' => $bs['headline'], 'detail' => $bs['detail'], 'booking_for' => $bs['target_name'], 'late_fee_kes' => $bs['fee'], 'late_fee_paid' => $bs['fee_paid'], 'must_pay_late_fee_first' => $bs['needs_fee'], 'can_choose_room_now' => $bs['can_book'], 'can_keep_room_now' => $bs['can_keep'],
                    'timeline' => array_map(fn($t) => $t['label'] . ': ' . $t['when'] . ' (' . $t['status'] . ')', $bs['timeline'])]];
        }
        case 'pending_approvals': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 's.gender'); $lim = max(1, min(!empty($a['_big']) ? 500 : 20, (int)($a['limit'] ?? 10)));
            $q = $pdo->prepare("SELECT bk.id booking_id, s.full_name, s.reg_number, r.room_number room, bl.name block, bd.label bed FROM bookings bk JOIN students s ON s.id=bk.student_id JOIN rooms r ON r.id=bk.room_id JOIN blocks bl ON bl.id=r.block_id LEFT JOIN beds bd ON bd.id=bk.bed_id WHERE bk.status='pending_approval' $gs ORDER BY bk.id LIMIT $lim");
            $q->execute($gp); return ['pending' => $q->fetchAll()];
        }
        case 'find_student': {
            require_once __DIR__ . '/../payments_staff.php'; $act = pay_actor_from_ctx($ctx); $out = [];
            foreach (pay_find_students($pdo, $act, (string)($a['query'] ?? ''), 6) as $s) {
                $rm = $pdo->prepare("SELECT room_number FROM rooms WHERE id=?"); $rm->execute([$s['current_room_id']]); $f = pay_fee_status($pdo, $s);
                $out[] = ['student_id' => (int)$s['id'], 'full_name' => $s['full_name'], 'reg_number' => $s['reg_number'], 'email' => $s['email'], 'year_of_study' => $s['year_of_study'], 'current_room' => $rm->fetchColumn() ?: null, 'late_fee' => $f['paid'] ? 'paid' : ($f['due'] ? 'owes KES ' . $f['amount'] : 'none due')];
            }
            return ['students' => $out];
        }
        case 'student_payment_status': {
            require_once __DIR__ . '/../payments_staff.php'; $act = pay_actor_from_ctx($ctx); $hits = pay_find_students($pdo, $act, (string)($a['student'] ?? ''), 3);
            if (!$hits) return ['error' => 'No student matches that in your wing. Check the registration number or email.'];
            if (count($hits) > 1 && strcasecmp($hits[0]['reg_number'], trim((string)$a['student'])) !== 0 && strcasecmp($hits[0]['email'], trim((string)$a['student'])) !== 0) return ['error' => 'More than one student matches; ask for the registration number.', 'matches' => array_map(fn($x) => "{$x['full_name']} ({$x['reg_number']})", $hits)];
            $s = $hits[0]; $f = pay_fee_status($pdo, $s); mpesa_sweep($pdo);
            $q = $pdo->prepare("SELECT * FROM mpesa_payments WHERE student_id=? ORDER BY id DESC LIMIT 4"); $q->execute([$s['id']]);
            $att = array_map(function ($p) { $v = mpesa_view($p, 'staff'); return ['when' => $p['created_at'], 'amount_kes' => $v['amount'], 'result' => $v['title'], 'detail' => $v['message'], 'receipt' => $p['receipt'], 'demo' => $p['env'] === 'demo']; }, $q->fetchAll());
            return ['student' => $s['full_name'], 'reg_number' => $s['reg_number'], 'late_booker' => (bool)$s['late_booker'], 'fee_paid_this_semester' => $f['paid'], 'owes_kes' => $f['due'] ? $f['amount'] : 0, 'booking_status' => $f['booking']['status'] ?? 'none', 'note' => $f['reason'], 'latest_attempts' => $att];
        }
        case 'recent_payments': {
            require_once __DIR__ . '/../payments_staff.php'; $act = pay_actor_from_ctx($ctx);
            return ['payments' => array_map(fn($v) => ['when' => $v['at'], 'who' => $v['who'], 'amount_kes' => $v['amount'], 'result' => $v['title'], 'detail' => $v['status'] === 'success' ? '' : $v['message'], 'receipt' => $v['receipt'], 'needs_attention' => (bool)$v['needs_review'], 'demo' => $v['demo']], pay_recent($pdo, $act, 10))];
        }
        case 'occupancy_stats': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 'r.gender');
            $q = $pdo->prepare("SELECT bl.name block, COUNT(*) beds, SUM(b.status='occupied') occupied, SUM(b.status='reserved') reserved, SUM(b.status IN ('vacant','available_p2')) vacant FROM beds b JOIN rooms r ON r.id=b.room_id JOIN blocks bl ON bl.id=r.block_id WHERE b.is_active=1 $gs GROUP BY bl.id");
            $q->execute($gp); return ['blocks' => $q->fetchAll()];
        }
        case 'recommend_beds': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 'r.gender'); $beds = $pdo->prepare("SELECT b.id bed_id, bl.name block, r.room_number room, r.floor_no floor, b.label bed, b.kind, r.bath_type, r.slot, (SELECT MAX(slot) FROM rooms r2 WHERE r2.block_id=r.block_id AND r2.wing_key=r.wing_key) maxslot FROM beds b JOIN rooms r ON r.id=b.room_id JOIN blocks bl ON bl.id=r.block_id WHERE b.is_active=1 AND b.status IN ('vacant','available_p2') $gs LIMIT 600");
            $beds->execute($gp); $out = [];
            foreach ($beds->fetchAll() as $x) {
                $sc = 0; if (!empty($a['floor']) && (int)$a['floor'] === (int)$x['floor']) $sc += 3; if (!empty($a['kind']) && $a['kind'] === $x['kind']) $sc += 3;
                if (!empty($a['ensuite']) && $x['bath_type'] === 'ensuite') $sc += 3; if (!empty($a['quiet'])) $sc += (int)round(2 * $x['slot'] / max(1, $x['maxslot']));
                $x['score'] = $sc; unset($x['slot'], $x['maxslot']); $out[] = $x;
            }
            usort($out, fn($p, $q) => $q['score'] <=> $p['score'] ?: $p['bed_id'] <=> $q['bed_id']);
            return ['best' => array_slice($out, 0, 5), 'note' => 'Open Explore 3D and tap the bed to book it.'];
        }
        case 'daily_briefing': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 's.gender');
            $q = $pdo->prepare("SELECT COUNT(*) n, COALESCE(MAX(TIMESTAMPDIFF(HOUR, bk.updated_at, NOW())),0) oldest_hours FROM bookings bk JOIN students s ON s.id=bk.student_id WHERE bk.status='pending_approval' $gs"); $q->execute($gp); $ap = $q->fetch();
            $q = $pdo->prepare("SELECT COUNT(*) FROM bookings bk JOIN students s ON s.id=bk.student_id WHERE bk.status='pending_payment' $gs"); $q->execute($gp); $pay = (int)$q->fetchColumn();
            $q = $pdo->prepare("SELECT COUNT(*) FROM bookings bk JOIN students s ON s.id=bk.student_id WHERE bk.status='pending_otp' AND bk.otp_expires_at < DATE_ADD(NOW(), INTERVAL 2 MINUTE) $gs"); $q->execute($gp); $exp = (int)$q->fetchColumn();
            [$g2, $p2] = ai_gender_sql($ctx['gender'], 'r.gender'); $q = $pdo->prepare("SELECT COUNT(*) total, SUM(b.status IN ('vacant','available_p2')) vacant FROM beds b JOIN rooms r ON r.id=b.room_id WHERE b.is_active=1 $g2"); $q->execute($p2); $v = $q->fetch();
            $q = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.is_active=1 AND s.current_bed_id IS NULL AND s.current_room_id IS NULL AND NOT EXISTS (SELECT 1 FROM bookings bk WHERE bk.student_id=s.id AND bk.status IN ('pending_otp','pending_payment','pending_approval','confirmed')) $gs"); $q->execute($gp); $un = (int)$q->fetchColumn();
            return ['pending_approvals' => (int)$ap['n'], 'oldest_waiting_hours' => (int)$ap['oldest_hours'], 'awaiting_payment' => $pay, 'holds_expiring_soon' => $exp, 'beds_total' => (int)$v['total'], 'beds_vacant' => (int)$v['vacant'], 'unplaced_students' => $un];
        }
        case 'unplaced_students': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 's.gender'); $lim = max(1, min(!empty($a['_big']) ? 1000 : 25, (int)($a['limit'] ?? 10)));
            $q = $pdo->prepare("SELECT s.id student_id, s.full_name, s.reg_number, s.year_of_study FROM students s WHERE s.is_active=1 AND s.current_bed_id IS NULL AND s.current_room_id IS NULL AND NOT EXISTS (SELECT 1 FROM bookings bk WHERE bk.student_id=s.id AND bk.status IN ('pending_otp','pending_payment','pending_approval','confirmed')) $gs ORDER BY s.year_of_study DESC, s.full_name LIMIT $lim");
            $q->execute($gp); return ['students' => $q->fetchAll()];
        }
        case 'payments_overview': {
            require_once __DIR__ . '/../mpesa.php'; mpesa_ensure_schema($pdo); mpesa_sweep($pdo);
            $r = $pdo->query("SELECT SUM(status='success') paid, SUM(status='pending') pending, SUM(status IN ('failed','cancelled','timeout')) failed, SUM(needs_review=1) needs_attention, COALESCE(SUM(IF(status='success',amount,0)),0) total_kes, COALESCE(SUM(IF(status='success' AND DATE(completed_at)=CURDATE(),amount,0)),0) today_kes FROM mpesa_payments WHERE env<>'demo' AND purpose='late_fee'")->fetch();
            $r['paid'] = (int)$r['paid']; $r['pending'] = (int)$r['pending']; $r['failed'] = (int)$r['failed']; $r['needs_attention'] = (int)$r['needs_attention'];
            $r['demo_and_test_payments_not_counted'] = (int)$pdo->query("SELECT COUNT(*) FROM mpesa_payments WHERE env='demo' OR purpose='test'")->fetchColumn(); return $r;
        }
        case 'recent_audit': {
            $lim = max(1, min(!empty($a['_big']) ? 500 : 15, (int)($a['limit'] ?? 8)));
            return ['entries' => $pdo->query("SELECT a.created_at, u.full_name AS user, a.action, LEFT(a.details,120) details FROM audit_log a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT $lim")->fetchAll()];
        }
        case 'layout_overview': {
            $q = $pdo->query("SELECT bl.name block, bl.gender, bl.floors, COUNT(r.id) rooms, SUM(r.bunks) bunks, SUM(r.singles) singles, SUM(r.bath_type='ensuite') ensuite_rooms FROM blocks bl LEFT JOIN rooms r ON r.block_id=bl.id WHERE bl.is_active=1 GROUP BY bl.id");
            $rows = $q->fetchAll(); if ($ctx['gender']) $rows = array_values(array_filter($rows, fn($r) => $r['gender'] === $ctx['gender']));
            return ['blocks' => $rows];
        }
    }
    return ['error' => 'unknown tool'];
}

/** Describe and validate a write tool; returns [summary|null, error|null]. */
function ai_prepare_write(PDO $pdo, array $ctx, string $name, array $a): array {
    if (in_array($name, SA_TOOLS, true)) return $ctx['student'] ? sa_prepare($pdo, $ctx, $name, $a) : [null, 'Only students can do that.'];
    switch ($name) {
        case 'cancel_my_hold':
            return [$ctx['student'] ? 'Cancel your pending bed hold' : null, $ctx['student'] ? null : 'Not a student'];
        case 'approve_booking': case 'reject_booking': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 's.gender');
            $q = $pdo->prepare("SELECT s.full_name, r.room_number FROM bookings bk JOIN students s ON s.id=bk.student_id JOIN rooms r ON r.id=bk.room_id WHERE bk.id=? AND bk.status='pending_approval' $gs");
            $q->execute(array_merge([(int)($a['booking_id'] ?? 0)], $gp)); $x = $q->fetch();
            if (!$x) return [null, 'No such booking waiting for approval in your wing.'];
            return [($name === 'approve_booking' ? 'Approve' : 'Reject') . " {$x['full_name']}'s booking for room {$x['room_number']}" . ($name === 'reject_booking' && !empty($a['reason']) ? " — \"{$a['reason']}\"" : ''), null];
        }
        case 'bulk_approve': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 's.gender'); $q = $pdo->prepare("SELECT COUNT(*) FROM bookings bk JOIN students s ON s.id=bk.student_id WHERE bk.status='pending_approval' $gs"); $q->execute($gp); $n = (int)$q->fetchColumn();
            return $n ? ["Approve " . min($n, 25) . " waiting booking" . ($n === 1 ? '' : 's') . ($n > 25 ? " (the oldest 25 of $n)" : ''), null] : [null, 'Nothing is waiting for approval.'];
        }
        case 'broadcast_message': {
            $aud = $a['audience'] ?? ''; if (!in_array($aud, ['unplaced', 'awaiting_payment', 'holding'], true) || trim((string)($a['message'] ?? '')) === '') return [null, 'Choose an audience and write a message.'];
            $ids = ai_audience_ids($pdo, $ctx, $aud); if (!$ids) return [null, 'No students match that audience right now.'];
            return ["Send to " . count($ids) . " student(s) ($aud): \"" . mb_substr(trim($a['message']), 0, 100) . '"', null];
        }
        case 'apply_layout_preset': {
            $q = $pdo->prepare("SELECT name FROM blocks WHERE id=?"); $q->execute([(int)($a['block_id'] ?? 0)]); $n = $q->fetchColumn();
            return ($n && isset(layout_presets()[$a['preset'] ?? ''])) ? ["Re-arrange $n into the '" . layout_presets()[$a['preset']]['name'] . "' design (all bed positions reset)", null] : [null, 'Unknown building or design.'];
        }
        case 'prompt_student_payment': {
            require_once __DIR__ . '/../payments_staff.php'; $act = pay_actor_from_ctx($ctx); $c = pay_prepare_fee($pdo, $act, (string)($a['student'] ?? ''), (string)($a['phone'] ?? ''));
            if (!$c['ok']) return [null, $c['error']];
            $s = $c['student']; $env = mpesa_env() === 'demo' ? ' (DEMO mode: no real money moves)' : '';
            return [($c['mark_late'] ? "Mark {$s['full_name']} as a late booker and send" : 'Send') . " an M-Pesa prompt of KES {$c['fee']['amount']} (late booking fee) to " . mpesa_phone_pretty($c['phone']) . " for {$s['full_name']} ({$s['reg_number']})$env", null];
        }
        case 'test_mpesa_prompt': {
            require_once __DIR__ . '/../payments_staff.php'; $c = pay_prepare_test([], (string)($a['phone'] ?? '')); if (!$c['ok']) return [null, $c['error']];
            return ['Send a KES 1 test M-Pesa prompt to ' . mpesa_phone_pretty($c['phone']) . (mpesa_env() === 'demo' ? ' (DEMO mode: no real money moves)' : ''), null];
        }
        case 'message_student': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 'gender');
            $q = $pdo->prepare("SELECT full_name FROM students WHERE id=? $gs"); $q->execute(array_merge([(int)($a['student_id'] ?? 0)], $gp)); $n = $q->fetchColumn();
            return $n ? ["Message {$n}: \"" . mb_substr((string)$a['message'], 0, 120) . '"', null] : [null, 'Student not found in your scope.'];
        }
    }
    return [null, 'unknown action'];
}

/** Execute a confirmed write. */
function ai_execute_write(PDO $pdo, array $ctx, string $name, array $a): array {
    if (in_array($name, SA_TOOLS, true)) { if (!$ctx['student']) return ['ok' => false, 'message' => 'Only students can do that.']; $r = sa_execute($pdo, $ctx, $name, $a); if (!empty($r['ok'])) $r['changed'] = true; return $r; }
    switch ($name) {
        case 'cancel_my_hold': {
            $n = $pdo->prepare("UPDATE bookings SET status='cancelled', cancelled_reason='Cancelled by student' WHERE student_id=? AND status='pending_otp'");
            $n->execute([$ctx['student']['id']]); beds_reconcile($pdo); return ['ok' => true, 'message' => $n->rowCount() ? 'Hold cancelled.' : 'You had no active hold.'];
        }
        case 'approve_booking': case 'reject_booking': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 's.gender');
            $q = $pdo->prepare("SELECT bk.*, s.full_name, r.room_number, bl.name block_name FROM bookings bk JOIN students s ON s.id=bk.student_id JOIN rooms r ON r.id=bk.room_id JOIN blocks bl ON bl.id=r.block_id WHERE bk.id=? AND bk.status='pending_approval' $gs");
            $q->execute(array_merge([(int)$a['booking_id']], $gp)); $b = $q->fetch(); if (!$b) return ['ok' => false, 'message' => 'Booking is no longer pending.'];
            if ($name === 'approve_booking') {
                $sem = (int)($b['semester_id'] ?: (active_semester_id() ?? 0));
                $pdo->prepare("UPDATE bookings SET status='confirmed', approved_by=?, approved_at=NOW(), semester_id=? WHERE id=?")->execute([$ctx['uid'], $sem, $b['id']]);
                notify_student($pdo, (int)$b['student_id'], 'Booking confirmed', "Your room {$b['room_number']} ({$b['block_name']}) is confirmed.", '/student/book.php', 'approved');
                booking_emails($pdo, (int)$b['id'], 'approved');
                audit_log($ctx['uid'], (int)$b['student_id'], 'booking_approve', "AI assistant (confirmed by staff): approved booking #{$b['id']}");
                return ['ok' => true, 'message' => "Approved {$b['full_name']}."];
            }
            $reason = trim((string)($a['reason'] ?? '')) ?: 'Rejected by staff';
            $pdo->prepare("UPDATE bookings SET status='cancelled', cancelled_reason=? WHERE id=?")->execute([$reason, $b['id']]); beds_reconcile($pdo);
            notify_student($pdo, (int)$b['student_id'], 'Booking not approved', "$reason You can choose another bed.", '/student/explore.php', 'rejected');
            booking_emails($pdo, (int)$b['id'], 'rejected', $reason);
            audit_log($ctx['uid'], (int)$b['student_id'], 'booking_reject', "AI assistant (confirmed by staff): rejected booking #{$b['id']}: $reason");
            return ['ok' => true, 'message' => "Rejected {$b['full_name']}'s booking."];
        }
        case 'bulk_approve': {
            [$gs, $gp] = ai_gender_sql($ctx['gender'], 's.gender');
            $q = $pdo->prepare("SELECT bk.id, bk.student_id, bk.semester_id, r.room_number, bl.name block_name FROM bookings bk JOIN students s ON s.id=bk.student_id JOIN rooms r ON r.id=bk.room_id JOIN blocks bl ON bl.id=r.block_id WHERE bk.status='pending_approval' $gs ORDER BY bk.id LIMIT 25");
            $q->execute($gp); $n = 0;
            foreach ($q->fetchAll() as $b) {
                $sem = (int)($b['semester_id'] ?: (active_semester_id() ?? 0));
                $u = $pdo->prepare("UPDATE bookings SET status='confirmed', approved_by=?, approved_at=NOW(), semester_id=? WHERE id=? AND status='pending_approval'"); $u->execute([$ctx['uid'], $sem, $b['id']]);
                if ($u->rowCount()) { $n++; notify_student($pdo, (int)$b['student_id'], 'Booking confirmed', "Your room {$b['room_number']} ({$b['block_name']}) is confirmed.", '/student/book.php', 'approved'); booking_emails($pdo, (int)$b['id'], 'approved', '', false); }
            }
            if ($n && !empty($ctx['uid'])) mail_user($pdo, (int)$ctx['uid'], 'Bulk approval done', "<p>You approved <strong>$n</strong> booking(s). Each student has been emailed.</p>");
            audit_log($ctx['uid'], null, 'booking_bulk_approve', "AI assistant (code-verified): approved $n bookings"); return ['ok' => true, 'message' => "Approved $n booking(s)."];
        }
        case 'broadcast_message': {
            $ids = ai_audience_ids($pdo, $ctx, $a['audience']); $m = mb_substr(trim((string)$a['message']), 0, 300);
            foreach ($ids as $sid) notify_student($pdo, (int)$sid, 'Message from hostel office', $m, '/student/book.php', 'message');
            audit_log($ctx['uid'], null, 'ai_broadcast', 'Sent to ' . count($ids) . " students ({$a['audience']}): " . mb_substr($m, 0, 80)); return ['ok' => true, 'message' => 'Sent to ' . count($ids) . ' student(s).'];
        }
        case 'apply_layout_preset': {
            require_once __DIR__ . '/../layout_presets.php'; $pdo->beginTransaction(); layout_apply_preset($pdo, (int)$a['block_id'], $a['preset'], (int)($a['floors'] ?? 3)); $pdo->commit();
            $pdo->prepare("UPDATE beds bd JOIN rooms r ON r.id=bd.room_id SET bd.pos_x=NULL, bd.pos_z=NULL, bd.rot=NULL WHERE r.block_id=?")->execute([(int)$a['block_id']]);
            audit_log($ctx['uid'], null, 'layout_preset', "AI assistant (password-verified): applied {$a['preset']} to block #{$a['block_id']}"); return ['ok' => true, 'message' => 'Design applied. Review it in Layout Studio.'];
        }
        case 'prompt_student_payment': {
            require_once __DIR__ . '/../payments_staff.php'; $act = pay_actor_from_ctx($ctx); if (!$act) return ['ok' => false, 'message' => 'Not allowed.'];
            $r = pay_send_fee($pdo, $act, (string)($a['student'] ?? ''), (string)($a['phone'] ?? ''), 'ai');
            return ['ok' => (bool)$r['ok'], 'message' => $r['message'] . (!empty($r['ok']) && !empty($r['student']) ? " ({$r['student']})" : ''), 'pay' => !empty($r['payment_id']) ? ['id' => (int)$r['payment_id']] : null];
        }
        case 'test_mpesa_prompt': {
            require_once __DIR__ . '/../payments_staff.php'; $act = pay_actor_from_ctx($ctx); if (!$act) return ['ok' => false, 'message' => 'Not allowed.'];
            $r = pay_send_test($pdo, $act, (string)($a['phone'] ?? ''), 'ai');
            return ['ok' => (bool)$r['ok'], 'message' => $r['message'], 'pay' => !empty($r['payment_id']) ? ['id' => (int)$r['payment_id']] : null];
        }
        case 'message_student':
            notify_student($pdo, (int)$a['student_id'], 'Message from hostel office', mb_substr((string)$a['message'], 0, 300), '/student/book.php', 'message');
            audit_log($ctx['uid'], (int)$a['student_id'], 'ai_message', 'Sent message via assistant'); return ['ok' => true, 'message' => 'Message sent.'];
    }
    return ['ok' => false, 'message' => 'Unknown action.'];
}


/** Student ids for a broadcast audience, limited to the actor's wing and 300 people. */
function ai_audience_ids(PDO $pdo, array $ctx, string $aud): array {
    [$gs, $gp] = ai_gender_sql($ctx['gender'], 's.gender');
    $sql = match ($aud) {
        'unplaced' => "SELECT s.id FROM students s WHERE s.is_active=1 AND s.current_bed_id IS NULL AND s.current_room_id IS NULL AND NOT EXISTS (SELECT 1 FROM bookings bk WHERE bk.student_id=s.id AND bk.status IN ('pending_otp','pending_payment','pending_approval','confirmed')) $gs",
        'awaiting_payment' => "SELECT DISTINCT s.id FROM students s JOIN bookings bk ON bk.student_id=s.id AND bk.status='pending_payment' WHERE 1=1 $gs",
        'holding' => "SELECT DISTINCT s.id FROM students s JOIN bookings bk ON bk.student_id=s.id AND bk.status='pending_otp' WHERE 1=1 $gs",
        default => null,
    };
    if (!$sql) return [];
    $q = $pdo->prepare($sql . ' LIMIT 300'); $q->execute($gp); return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
}


/* ====================================================================== action cards (editable, then one press) */
/** Button words for each write tool. */
function ai_action_label(string $tool): string {
    if ($l = sa_label($tool)) return $l;
    return ['approve_booking' => 'Approve booking', 'reject_booking' => 'Reject booking', 'message_student' => 'Send message', 'bulk_approve' => 'Approve all waiting', 'broadcast_message' => 'Send to everyone',
        'apply_layout_preset' => 'Apply this design', 'cancel_my_hold' => 'Cancel my hold', 'prompt_student_payment' => 'Send M-Pesa prompt', 'test_mpesa_prompt' => 'Send test prompt'][$tool] ?? 'Confirm';
}
/** The fields a person may change before pressing the button: every tool argument except internal ids. */
function ai_card_fields(array $def, array $args): array {
    $props = $def['p']['properties'] ?? []; if ($props instanceof stdClass) $props = []; $out = [];
    foreach ($props as $name => $p) {
        if (preg_match('/(^|_)id$/', $name)) continue;
        $enum = $p['enum'] ?? null; $type = $p['type'] ?? 'string'; $val = $args[$name] ?? ($type === 'boolean' ? false : '');
        $f = ['name' => $name, 'label' => ucfirst(str_replace('_', ' ', $name)), 'value' => is_bool($val) ? ($val ? 'yes' : 'no') : (string)$val];
        if ($enum) { $f['type'] = 'select'; $f['options'] = array_values($enum); }
        elseif ($type === 'boolean') { $f['type'] = 'select'; $f['options'] = ['yes', 'no']; }
        elseif ($type === 'integer' || $type === 'number') $f['type'] = 'number';
        elseif (in_array($name, ['message', 'reason', 'text', 'note'], true)) $f['type'] = 'textarea';
        else $f['type'] = 'text';
        $out[] = $f;
    }
    return $out;
}
function ai_card(array $def, string $tool, int $id, string $summary, array $args): array {
    return ['id' => $id, 'tool' => $tool, 'summary' => $summary, 'btn' => ai_action_label($tool), 'fields' => ai_card_fields($def, $args), 'sens' => $def['sens'] ?? ''];
}
/** Merge a person's edits into the arguments. Only declared, non-id fields are accepted and every value is cast to its declared type. */
function ai_apply_edits(array $def, array $args, array $edits): array {
    $props = $def['p']['properties'] ?? []; if ($props instanceof stdClass) $props = [];
    foreach ($props as $name => $p) {
        if (preg_match('/(^|_)id$/', $name) || !array_key_exists($name, $edits)) continue;
        $v = $edits[$name]; $type = $p['type'] ?? 'string';
        if (isset($p['enum'])) { if (!in_array((string)$v, $p['enum'], true)) continue; $args[$name] = (string)$v; }
        elseif ($type === 'boolean') $args[$name] = in_array(strtolower((string)$v), ['1', 'yes', 'true', 'on'], true);
        elseif ($type === 'integer') $args[$name] = (int)$v;
        elseif ($type === 'number') $args[$name] = (float)$v;
        else $args[$name] = mb_substr(trim((string)$v), 0, 600);
    }
    return $args;
}

/* ====================================================================== ready-made datasets for documents */
/** Live data shaped for a document section: ['title','stats'=>[[label,value]],'columns','rows','note'] or null when this role may not see it. */
function ai_dataset(PDO $pdo, array $ctx, string $key): ?array {
    $tools = ai_tools_for($ctx); $n = fn($v) => (string)(int)$v;
    $need = ['briefing' => 'daily_briefing', 'occupancy' => 'occupancy_stats', 'vacancy' => 'vacancy_summary', 'approvals' => 'pending_approvals', 'unplaced' => 'unplaced_students', 'payments' => 'payments_overview', 'audit' => 'recent_audit', 'layout' => 'layout_overview'];
    $alias = ['recent_audit' => 'audit', 'occupancy_stats' => 'occupancy', 'pending_approvals' => 'approvals', 'unplaced_students' => 'unplaced', 'payments_overview' => 'payments', 'layout_overview' => 'layout', 'daily_briefing' => 'briefing', 'vacancy_summary' => 'vacancy'];
    $key = $alias[$key] ?? $key; if (!isset($need[$key]) || !isset($tools[$need[$key]])) return null;
    $r = ai_run_read($pdo, $ctx, $need[$key], ['_big' => true, 'limit' => 500]);
    switch ($key) {
        case 'briefing':
            return ['title' => 'Today at a glance', 'stats' => [['Pending approvals', $n($r['pending_approvals'])], ['Awaiting payment', $n($r['awaiting_payment'])], ['Holds expiring soon', $n($r['holds_expiring_soon'])], ['Vacant beds', $n($r['beds_vacant']) . ' of ' . $n($r['beds_total'])], ['Unplaced students', $n($r['unplaced_students'])]]];
        case 'occupancy':
            $rows = []; $T = $O = $R = $V = 0;
            foreach ($r['blocks'] as $x) { $T += $x['beds']; $O += $x['occupied']; $R += $x['reserved']; $V += $x['vacant']; $rows[] = [$x['block'], $n($x['beds']), $n($x['occupied']), $n($x['reserved']), $n($x['vacant']), $x['beds'] ? round($x['occupied'] / $x['beds'] * 100) . '%' : '-']; }
            return ['title' => 'Occupancy by hostel block', 'stats' => [['Total beds', $n($T)], ['Occupied', $n($O)], ['Reserved', $n($R)], ['Vacant', $n($V)], ['Full', $T ? round($O / $T * 100) . '%' : '-']], 'columns' => ['Block', 'Total beds', 'Occupied', 'Reserved', 'Vacant', 'Full'], 'rows' => $rows];
        case 'vacancy':
            $rows = []; foreach ($r['rows'] as $x) $rows[] = [$x['block'], 'Floor ' . $x['floor'], $n($x['vacant_beds'])];
            return ['title' => 'Vacant beds by block and floor', 'columns' => ['Block', 'Floor', 'Vacant beds'], 'rows' => $rows];
        case 'approvals':
            $rows = []; foreach ($r['pending'] as $x) $rows[] = ['#' . $x['booking_id'], $x['full_name'], $x['reg_number'], $x['block'], (string)$x['room'], (string)($x['bed'] ?? '')];
            return ['title' => 'Bookings waiting for approval', 'columns' => ['Booking', 'Student', 'Reg no.', 'Block', 'Room', 'Bed'], 'rows' => $rows, 'note' => $rows ? '' : 'Nothing is waiting for approval.'];
        case 'unplaced':
            $rows = []; foreach ($r['students'] as $x) $rows[] = [$x['full_name'], $x['reg_number'], 'Year ' . $x['year_of_study']];
            return ['title' => 'Students without a bed', 'columns' => ['Student', 'Reg no.', 'Year'], 'rows' => $rows, 'note' => $rows ? '' : 'Every active student has a bed or a live booking.'];
        case 'payments':
            return ['title' => 'M-Pesa late-booking fees', 'stats' => [['Collected (KES)', number_format((float)$r['total_kes'])], ['Today (KES)', number_format((float)$r['today_kes'])], ['Paid', $n($r['paid'])], ['Pending', $n($r['pending'])], ['Failed', $n($r['failed'])]]];
        case 'audit':
            $rows = []; foreach ($r['entries'] as $x) $rows[] = [(string)$x['created_at'], (string)($x['user'] ?? 'System'), (string)$x['action'], (string)($x['details'] ?? '')];
            return ['title' => 'System activity and audit log', 'columns' => ['Date & time', 'User', 'Action', 'Details'], 'rows' => $rows];
        case 'layout':
            $rows = []; foreach ($r['blocks'] as $x) $rows[] = [$x['block'], ucfirst((string)$x['gender']), $n($x['floors']), $n($x['rooms']), $n($x['bunks']), $n($x['singles']), $n($x['bunks'] * 2 + $x['singles']), $n($x['ensuite_rooms'])];
            return ['title' => 'Hostel layout', 'columns' => ['Block', 'Gender', 'Floors', 'Rooms', 'Bunks', 'Singles', 'Beds', 'Ensuite rooms'], 'rows' => $rows];
    }
    return null;
}
