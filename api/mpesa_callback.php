<?php
// Safaricom posts the STK result here (success, cancel, wrong PIN, no funds, timeout ...).
// The URL carries a secret. Every message is stored first so a "missing" payment can always be traced, then applied.
// Money that arrives for a prompt we no longer know about is never dropped: it is kept as a "needs review" payment for staff.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/mpesa.php';
header('Content-Type: application/json');
$ok = hash_equals(hash_hmac('sha256', 'cb', (string)get_config('mpesa_callback_secret', 'x')), (string)($_GET['k'] ?? ''));
if (!$ok) { http_response_code(403); echo '{}'; exit; }
mpesa_ensure_schema($pdo);
$raw = (string)file_get_contents('php://input'); $j = json_decode($raw, true);
$d = is_array($j) ? ($j['Body']['stkCallback'] ?? null) : null;
$cid = is_array($d) ? (string)($d['CheckoutRequestID'] ?? '') : ''; $code = is_array($d) && isset($d['ResultCode']) ? (string)$d['ResultCode'] : '';
$matched = 0;
try {
    if ($cid !== '') {
        $q = $pdo->prepare("SELECT id FROM mpesa_payments WHERE checkout_request_id=?"); $q->execute([$cid]); $id = (int)$q->fetchColumn();
        $meta = []; foreach (($d['CallbackMetadata']['Item'] ?? []) as $it) if (isset($it['Name'])) $meta[$it['Name']] = $it['Value'] ?? null;
        if ($id) {
            $matched = 1;
            if ($code === '0') mpesa_finish($pdo, $id, 'success', isset($meta['MpesaReceiptNumber']) ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$meta['MpesaReceiptNumber'])) : null, 'Payment received.',
                isset($meta['Amount']) ? (int)round((float)$meta['Amount']) : null, '0', (string)($d['ResultDesc'] ?? ''), 'callback');
            else { [$s, $m] = mpesa_interpret($code, (string)($d['ResultDesc'] ?? '')); mpesa_finish($pdo, $id, $s, null, $m, null, $code, (string)($d['ResultDesc'] ?? ''), 'callback'); }
        } elseif ($code === '0') {                                                  // paid, but we have no record of asking: keep the money visible
            $rc = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($meta['MpesaReceiptNumber'] ?? '')));
            $ph = preg_replace('/\D/', '', (string)($meta['PhoneNumber'] ?? ''));
            $pdo->prepare("INSERT IGNORE INTO mpesa_payments (phone,amount,env,purpose,via,checkout_request_id,status,receipt,result_desc,result_code,completed_at,needs_review,review_note) VALUES (?,?,?,'late_fee','callback',?,'review',?,?,'0',NOW(),1,?)")
                ->execute([substr($ph, 0, 15), (int)round((float)($meta['Amount'] ?? 0)), mpesa_env(), $cid, $rc ?: null, 'Money was received but no matching request exists.', 'Received KES ' . (int)($meta['Amount'] ?? 0) . ' from ' . substr($ph, 0, 15) . ' with no matching request. Find the student and apply it by hand.']);
            require_once __DIR__ . '/../includes/notify.php';
            notify_staff($pdo, ['dean', 'secretary', 'admin'], null, 'Unmatched M-Pesa payment', 'KES ' . (int)($meta['Amount'] ?? 0) . ' was received with no matching request. Open Collect payment to review it.', '/user/collect_payment.php', 'payment');
        }
    }
    $pdo->prepare("INSERT INTO mpesa_callbacks (checkout_request_id, result_code, matched, payload) VALUES (?,?,?,?)")->execute([$cid ?: null, $code !== '' ? $code : null, $matched, mb_substr($raw, 0, 4000)]);
    mpesa_sweep($pdo);
} catch (Throwable $e) { mpesa_tech_log('error', friendly_error($e, 'callback handling')); }
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);   // always accepted, so Safaricom does not keep retrying
