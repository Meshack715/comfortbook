<?php
// includes/notify.php — in-app notifications + smart push.
// "Smart": pushes are targeted by role/wing, de-duplicated (same title+body within 10 min is skipped),
// and the in-app list is always written first, so nothing is lost if push is unavailable.
require_once __DIR__ . '/webpush.php';

function notify_push(PDO $pdo, string $where, array $params, array $payload): void {
    if (!function_exists('curl_init') || !get_config('push_enabled', '1')) return;
    try {
        $q = $pdo->prepare("SELECT * FROM push_subscriptions WHERE fail_count < 5 AND ($where)"); $q->execute($params);
        foreach ($q->fetchAll() as $sub) {
            $code = wp_send($pdo, $sub, $payload);
            if ($code === 404 || $code === 410) $pdo->prepare("DELETE FROM push_subscriptions WHERE id=?")->execute([$sub['id']]);
            elseif ($code >= 200 && $code < 300) $pdo->prepare("UPDATE push_subscriptions SET last_ok_at=NOW(), fail_count=0 WHERE id=?")->execute([$sub['id']]);
            else $pdo->prepare("UPDATE push_subscriptions SET fail_count=fail_count+1 WHERE id=?")->execute([$sub['id']]);
        }
    } catch (Throwable $e) { error_log('push: ' . $e->getMessage()); }
}

function notify_dupe(PDO $pdo, string $col, int $id, string $title, string $body): bool {
    $q = $pdo->prepare("SELECT 1 FROM notifications WHERE $col=? AND title=? AND body=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) LIMIT 1");
    $q->execute([$id, $title, $body]); return (bool)$q->fetchColumn();
}

function notify_student(PDO $pdo, int $studentId, string $title, string $body, string $url = '', string $kind = 'info'): void {
    try {
        if (notify_dupe($pdo, 'student_id', $studentId, $title, $body)) return;
        $pdo->prepare("INSERT INTO notifications (student_id,title,body,url,kind) VALUES (?,?,?,?,?)")->execute([$studentId, $title, mb_substr($body, 0, 400), $url ?: null, $kind]);
        notify_push($pdo, "student_id=?", [$studentId], ['title' => $title, 'body' => $body, 'url' => $url ?: '/student/book.php', 'kind' => $kind]);
    } catch (Throwable $e) { error_log('notify_student: ' . $e->getMessage()); }
}

function notify_user(PDO $pdo, int $userId, string $title, string $body, string $url = '', string $kind = 'info'): void {
    try {
        if (notify_dupe($pdo, 'user_id', $userId, $title, $body)) return;
        $pdo->prepare("INSERT INTO notifications (user_id,title,body,url,kind) VALUES (?,?,?,?,?)")->execute([$userId, $title, mb_substr($body, 0, 400), $url ?: null, $kind]);
        notify_push($pdo, "user_id=?", [$userId], ['title' => $title, 'body' => $body, 'url' => $url ?: '/user/dashboard.php', 'kind' => $kind]);
    } catch (Throwable $e) { error_log('notify_user: ' . $e->getMessage()); }
}

/** Notify staff who handle a wing: $roles are prefixes like ['dean','secretary']; admins are included when 'admin' is listed. */
function notify_staff(PDO $pdo, array $roles, ?string $gender, string $title, string $body, string $url = '', string $kind = 'info'): void {
    try {
        $conds = []; $p = [];
        foreach ($roles as $r) { if ($r === 'admin') $conds[] = "role='admin'"; else { $conds[] = "role LIKE ?"; $p[] = $r . '\\_%'; } }
        if (!$conds) return;
        $sql = "SELECT id FROM users WHERE is_active=1 AND (" . implode(' OR ', $conds) . ")";
        if ($gender) { $sql .= " AND (role='admin' OR gender_scope=?)"; $p[] = $gender; }
        $q = $pdo->prepare($sql); $q->execute($p);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $uid) notify_user($pdo, (int)$uid, $title, $body, $url, $kind);
    } catch (Throwable $e) { error_log('notify_staff: ' . $e->getMessage()); }
}


/* ---------------------------------------------------------------- booking emails: one place, student AND staff, never throws */
/** Staff (secretary/dean of the student's wing) who should hear about a booking. */
function booking_staff_recipients(PDO $pdo, ?string $gender): array {
    try {
        $sql = "SELECT id, full_name, email FROM users WHERE is_active=1 AND email IS NOT NULL AND email<>'' AND (role LIKE 'secretary\\_%' OR role LIKE 'dean\\_%')";
        $p = []; if ($gender) { $sql .= " AND gender_scope=?"; $p[] = $gender; }
        $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchAll();
    } catch (Throwable $e) { error_log('booking_staff_recipients: ' . $e->getMessage()); return []; }
}

/** Email one staff user (used for summaries). */
function mail_user(PDO $pdo, int $userId, string $subject, string $html): void {
    try {
        require_once __DIR__ . '/mailer.php';
        $q = $pdo->prepare("SELECT full_name, email FROM users WHERE id=?"); $q->execute([$userId]); $u = $q->fetch();
        if ($u && $u['email']) @send_mail($u['email'], $u['full_name'], $subject, email_template($subject, $html));
    } catch (Throwable $e) { error_log('mail_user: ' . $e->getMessage()); }
}

/**
 * Send the right emails for a booking event to BOTH sides.
 * $event: submitted (verified/paid, now waiting) | approved | rejected.   $toStaff=false for bulk runs (caller sends one summary).
 */
function booking_emails(PDO $pdo, int $bookingId, string $event, string $reason = '', bool $toStaff = true): void {
    static $sent = [];
    $key = $bookingId . ':' . $event; if (isset($sent[$key])) return; $sent[$key] = true;
    try {
        require_once __DIR__ . '/mailer.php';
        $q = $pdo->prepare("SELECT bk.id, bk.action, bk.penalty_amount, s.full_name, s.email, s.reg_number, s.gender, r.room_number, bl.name AS block_name, bd.label AS bed_label
            FROM bookings bk JOIN students s ON s.id=bk.student_id JOIN rooms r ON r.id=bk.room_id JOIN blocks bl ON bl.id=r.block_id LEFT JOIN beds bd ON bd.id=bk.bed_id WHERE bk.id=?");
        $q->execute([$bookingId]); $b = $q->fetch(); if (!$b) return;
        $where = h($b['block_name']) . ' · Room ' . h($b['room_number']) . ($b['bed_label'] ? ' · Bed ' . h($b['bed_label']) : '');
        $card = "<div style='background:#f4f8fc;border-left:4px solid #003366;padding:12px 16px;border-radius:8px;margin:14px 0;'><div style='font-size:12px;color:#666;text-transform:uppercase;'>Booking</div><div style='font-size:17px;font-weight:700;color:#003366;'>$where</div></div>";
        $name = h($b['full_name']); $reg = h($b['reg_number']);
        $stu = null; $stf = null;
        if ($event === 'submitted') {
            $stu = ['ComfortBook — Booking received', 'Booking Received', "<p>Hello <strong>$name</strong>,</p><p>Your booking is complete and is now <strong>waiting for approval</strong> by the hostel Secretary.</p>$card<p style='font-size:13px;color:#666;'>You will get an email as soon as it is decided.</p>"];
            $stf = ['ComfortBook — New booking awaiting approval', 'Booking Awaiting Approval', "<p>$name ($reg) has finished booking and needs your approval.</p>$card<p>Open <strong>Approvals</strong> in ComfortBook to approve or reject.</p>"];
        } elseif ($event === 'approved') {
            $stu = ['ComfortBook — Booking Approved', 'Booking Approved', "<p>Hello <strong>$name</strong>,</p><p>Good news: your booking has been <strong style='color:#198754;'>approved</strong>.</p>$card<p style='font-size:13px;color:#666;'>Your room is assigned when the semester starts. You will get another email then.</p>"];
            $stf = ['ComfortBook — Booking approved: ' . $b['reg_number'], 'Booking Approved', "<p>The booking of $name ($reg) was <strong style='color:#198754;'>approved</strong>. The student has been emailed.</p>$card"];
        } elseif ($event === 'rejected') {
            $why = h($reason ?: 'Not approved by the hostel office');
            $stu = ['ComfortBook — Booking not approved', 'Booking Not Approved', "<p>Hello <strong>$name</strong>,</p><p>Your booking could not be approved.</p>$card<p><strong>Reason:</strong> $why</p><p style='font-size:13px;color:#666;'>You can choose another bed from your booking page or by asking the assistant.</p>"];
            $stf = ['ComfortBook — Booking rejected: ' . $b['reg_number'], 'Booking Rejected', "<p>The booking of $name ($reg) was <strong style='color:#dc3545;'>rejected</strong> ($why). The student has been emailed.</p>$card"];
        }
        if ($stu && $b['email']) @send_mail($b['email'], $b['full_name'], $stu[0], email_template($stu[1], $stu[2]));
        if ($stf && $toStaff) foreach (booking_staff_recipients($pdo, $b['gender'] ?? null) as $u) @send_mail($u['email'], $u['full_name'], $stf[0], email_template($stf[1], $stf[2]));
    } catch (Throwable $e) { error_log('booking_emails: ' . $e->getMessage()); }
}

/** Latest booking of a student in a status, then send its emails (used where only the student is known, e.g. a payment landing). */
function booking_emails_latest(PDO $pdo, int $studentId, string $status, string $event): void {
    try { $q = $pdo->prepare("SELECT id FROM bookings WHERE student_id=? AND status=? ORDER BY id DESC LIMIT 1"); $q->execute([$studentId, $status]); if ($id = (int)$q->fetchColumn()) booking_emails($pdo, $id, $event); }
    catch (Throwable $e) { error_log('booking_emails_latest: ' . $e->getMessage()); }
}
