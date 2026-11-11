<?php
// includes/booking_state.php — ONE place that decides what booking looks like right now, for a wing and for a student.
// Used by the student page, the booking API, the AI assistant, and the dean/admin badges, so they can never disagree.
//
// Booking happens during the ACTIVE semester, for the NEXT semester (the "booking semester"). The windows belong to the wing.
//
//   not_set   the dean has not set dates                      before    first window has not started yet
//   phase1    keep-or-release is open                         between   Phase 1 is over, Phase 2 has not started
//   phase2    choose-a-room is open                           late      all windows are over, late booking (with a fee) is open
//   closed    all windows are over and late booking is off/over     booked   the student already has a booking in progress
//   no_semester   nothing active
// Late booking: after the last window, a student with no booking can still book if they pay the late fee first.
// Dean/admin controls: config late_booking_enabled (1/0) and late_booking_until (optional datetime; default = the day the booking semester starts).

function booking_semester(PDO $pdo): ?array {
    static $c = false; if ($c !== false) return $c;
    $a = active_semester();
    if ($a) {
        $q = $pdo->prepare("SELECT * FROM semesters WHERE (academic_year > ? OR (academic_year = ? AND semester_no > ?)) ORDER BY academic_year ASC, semester_no ASC LIMIT 1");
        $q->execute([$a['academic_year'], $a['academic_year'], (int)$a['semester_no']]);
        return $c = ($q->fetch() ?: $a);
    }
    return $c = ($pdo->query("SELECT * FROM semesters ORDER BY start_date ASC LIMIT 1")->fetch() ?: null);
}
function booking_sem_id(PDO $pdo): int { $s = booking_semester($pdo); return $s ? (int)$s['id'] : 0; }

function booking_fmt(?string $dt, bool $short = false): string { return $dt ? date($short ? 'j M, H:i' : 'D j M, H:i', strtotime($dt)) : '—'; }
function booking_in(int $s): string {
    if ($s < 90) return 'less than a minute';
    if ($s < 3600) return floor($s / 60) . ' min';
    if ($s < 86400) { $h = floor($s / 3600); $m = floor(($s % 3600) / 60); return $h . ' hr' . ($m ? " $m min" : ''); }
    $d = floor($s / 86400); $h = floor(($s % 86400) / 3600); return $d . ' day' . ($d > 1 ? 's' : '') . ($h ? " $h hr" : '');
}
function booking_wing(string $gender): string { return $gender === 'Female' ? "Women's wing" : "Men's wing"; }

/** Late-booking switches. 'until' is a unix time or null. */
function booking_late_cfg(?array $target): array {
    $enabled = get_config('late_booking_enabled', '1') === '1';
    $u = trim((string)get_config('late_booking_until', ''));
    $until = $u !== '' ? strtotime($u) : ($target ? strtotime($target['start_date'] . ' 23:59:59') : null);
    return ['enabled' => $enabled, 'until' => $until ?: null, 'fee' => (int)get_config('penalty_amount', '200')];
}

/** The wing's window status (no student needed). */
function window_status(PDO $pdo, string $gender): array {
    $now = time(); $sem = active_semester(); $r = ['state' => 'not_set', 'phase' => null, 'now' => $now, 'p1s' => null, 'p1e' => null, 'p2s' => null, 'p2e' => null, 'next_at' => null, 'last_end' => null];
    if (!$sem) { $r['state'] = 'no_semester'; return $r; }
    $w = semester_window((int)$sem['id'], $gender); if (!$w) return $r;
    foreach (['p1s' => 'phase1_start', 'p1e' => 'phase1_end', 'p2s' => 'phase2_start', 'p2e' => 'phase2_end'] as $k => $col) $r[$k] = $w[$col] ? strtotime($w[$col]) : null;
    $starts = array_filter([$r['p1s'], $r['p2s']]); $ends = array_filter([$r['p1e'], $r['p2e']]);
    if (!$starts || !$ends) return $r;
    $phase = current_phase($gender); $r['phase'] = $phase; $r['last_end'] = max($ends);
    if ($phase === 1) { $r['state'] = 'phase1'; $r['next_at'] = $r['p1e']; }
    elseif ($phase === 2) { $r['state'] = 'phase2'; $r['next_at'] = $r['p2e']; }
    elseif ($now < min($starts)) { $r['state'] = 'before'; $r['next_at'] = min($starts); }
    elseif ($now >= $r['last_end']) { $r['state'] = 'after'; }
    else { $r['state'] = 'between'; $up = array_filter($starts, fn($t) => $t > $now); $r['next_at'] = $up ? min($up) : null; }
    return $r;
}

/** Everything the student page, booking API and assistant need to know about this student right now. */
function booking_state(PDO $pdo, array $student, int $targetSemId = 0): array {
    $ws = window_status($pdo, $student['gender']); $now = time();
    $target = null; if ($targetSemId) { $q = $pdo->prepare("SELECT * FROM semesters WHERE id=?"); $q->execute([$targetSemId]); $target = $q->fetch() ?: null; }
    if (!$target) $target = booking_semester($pdo);
    $semId = $target ? (int)$target['id'] : 0; $cfg = booking_late_cfg($target); $wing = booking_wing($student['gender']); $tname = $target ? trim(preg_replace('/\s+/', ' ', $target['name'])) : 'next semester';
    $bk = null;
    if ($semId) { $q = $pdo->prepare("SELECT id, status, action, penalty_amount FROM bookings WHERE student_id=? AND semester_id=? AND status IN ('pending_otp','pending_payment','pending_approval','confirmed') ORDER BY id DESC LIMIT 1"); $q->execute([$student['id'], $semId]); $bk = $q->fetch() ?: null; }
    $feePaid = $semId > 0 && (int)($student['late_fee_paid_sem'] ?? 0) === $semId;
    $s = $ws + ['booking' => $bk, 'fee' => $cfg['fee'], 'fee_paid' => $feePaid, 'target' => $target, 'target_name' => $tname, 'wing' => $wing, 'late_open' => false, 'late_until' => $cfg['until'],
        'needs_fee' => false, 'can_book' => false, 'can_keep' => false, 'headline' => '', 'detail' => '', 'tone' => 'secondary', 'icon' => 'fa-clock', 'seconds_to_next' => null];
    $st = $ws['state'];
    if ($st === 'after') {
        if ($bk) $st = 'booked';
        elseif (!$cfg['enabled'] || ($cfg['until'] && $now > $cfg['until'])) $st = 'closed';
        else { $st = 'late'; $s['late_open'] = true; }
    } elseif ($bk && in_array($st, ['before', 'between', 'not_set'], true)) $st = 'booked';
    $s['state'] = $st; if ($ws['next_at']) $s['seconds_to_next'] = max(0, $ws['next_at'] - $now);
    $hasRoom = !empty($student['current_room_id']);
    $s['can_book'] = in_array($st, ['phase2'], true) || ($st === 'late' && ($feePaid || $cfg['fee'] < 1));
    $s['can_keep'] = ($st === 'phase1' || ($st === 'late' && ($feePaid || $cfg['fee'] < 1))) && $hasRoom && ($student['phase1_action'] ?? 'none') === 'none';
    $s['needs_fee'] = $st === 'late' && !$feePaid && $cfg['fee'] > 0;
    $p1 = $ws['p1s'] ? booking_fmt(date('Y-m-d H:i:s', $ws['p1s'])) . ' → ' . booking_fmt(date('Y-m-d H:i:s', $ws['p1e']), true) : '';
    switch ($st) {
        case 'no_semester': $s['headline'] = 'Booking is not set up yet'; $s['detail'] = 'No semester is active at the moment. Please check back later.'; break;
        case 'not_set': $s['headline'] = 'Booking dates not announced yet'; $s['detail'] = "Your Dean has not set the booking dates for the $wing yet. Once they do, you will see them here and get an alert."; $s['icon'] = 'fa-calendar-days'; break;
        case 'before': $s['headline'] = 'Booking opens ' . booking_fmt(date('Y-m-d H:i:s', $ws['next_at'])); $s['detail'] = 'It opens in ' . booking_in((int)$s['seconds_to_next']) . ". This page will unlock by itself; you do not need to do anything until then."; $s['tone'] = 'info'; $s['icon'] = 'fa-hourglass-start'; break;
        case 'phase1': $s['headline'] = 'Phase 1 is open: keep or release your room'; $s['detail'] = 'Closes ' . booking_fmt(date('Y-m-d H:i:s', $ws['p1e'])) . ' (in ' . booking_in((int)$s['seconds_to_next']) . ').'; $s['tone'] = 'primary'; $s['icon'] = 'fa-door-open'; break;
        case 'phase2': $s['headline'] = 'Phase 2 is open: choose your room'; $s['detail'] = 'Closes ' . booking_fmt(date('Y-m-d H:i:s', $ws['p2e'])) . ' (in ' . booking_in((int)$s['seconds_to_next']) . '). After that, booking needs a late fee of KES ' . number_format($cfg['fee']) . '.'; $s['tone'] = 'success'; $s['icon'] = 'fa-door-closed'; break;
        case 'between': $s['headline'] = 'Phase 1 is over. Phase 2 opens ' . ($ws['next_at'] ? booking_fmt(date('Y-m-d H:i:s', $ws['next_at'])) : 'soon'); $s['detail'] = $ws['next_at'] ? 'In ' . booking_in((int)$s['seconds_to_next']) . '. This page will unlock by itself.' : 'Your Dean will announce the date.'; $s['tone'] = 'info'; $s['icon'] = 'fa-hourglass-half'; break;
        case 'late':
            $s['headline'] = 'The booking windows ended ' . booking_fmt(date('Y-m-d H:i:s', $ws['last_end']));
            $s['detail'] = $feePaid ? 'Your late fee is paid. You can book now.' : ($cfg['fee'] > 0 ? 'You have not booked, so a late fee of KES ' . number_format($cfg['fee']) . ' applies. Pay it first (M-Pesa), then you can choose your room.' : 'You can still book a room.');
            $s['tone'] = 'warning'; $s['icon'] = 'fa-triangle-exclamation'; break;
        case 'closed': $s['headline'] = 'Booking has closed'; $s['detail'] = 'The booking windows ended ' . booking_fmt(date('Y-m-d H:i:s', $ws['last_end'])) . ' and late booking is not open. Please see your Dean or Secretary.'; $s['tone'] = 'danger'; $s['icon'] = 'fa-lock'; break;
        case 'booked': $s['headline'] = 'You already have a booking'; $s['detail'] = 'Status: ' . str_replace('_', ' ', $bk['status']) . '.'; $s['tone'] = 'success'; $s['icon'] = 'fa-circle-check'; break;
    }
    // the timeline shown to the student
    $step = function (string $label, ?int $a, ?int $b) use ($now) { if (!$a || !$b) return ['label' => $label, 'when' => 'Dates not set', 'status' => 'upcoming']; return ['label' => $label, 'when' => date('D j M, H:i', $a) . ' → ' . date('D j M, H:i', $b), 'status' => $now > $b ? 'done' : ($now >= $a ? 'live' : 'upcoming')]; };
    $s['timeline'] = [$step('Phase 1: keep or release', $ws['p1s'], $ws['p1e']), $step('Phase 2: choose a room', $ws['p2s'], $ws['p2e'])];
    if ($cfg['enabled'] && $cfg['fee'] > 0 && $ws['last_end']) $s['timeline'][] = ['label' => 'Late booking (KES ' . number_format($cfg['fee']) . ' fee)', 'when' => 'From ' . date('D j M, H:i', $ws['last_end']) . ($cfg['until'] ? ' until ' . date('D j M', $cfg['until']) : ''), 'status' => $st === 'late' ? 'live' : (in_array($st, ['closed'], true) ? 'done' : 'upcoming')];
    return $s;
}

/** Short label for dean/admin badges: [text, bootstrap colour]. */
function window_badge(PDO $pdo, string $gender): array {
    $w = window_status($pdo, $gender); $late = get_config('late_booking_enabled', '1') === '1';
    return match ($w['state']) {
        'phase1' => ['Phase 1 live', 'primary'], 'phase2' => ['Phase 2 live', 'success'],
        'before' => ['Opens in ' . booking_in(max(0, ($w['next_at'] ?? 0) - time())), 'info'],
        'between' => ['Between phases', 'secondary'],
        'after' => $late ? ['Ended: late booking open', 'warning'] : ['Ended', 'secondary'],
        'not_set' => ['Dates not set', 'secondary'], default => ['No active semester', 'secondary'],
    };
}
