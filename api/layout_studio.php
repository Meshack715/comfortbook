<?php
// api/layout_studio.php — Layout Studio backend. Admin (all blocks) and deans (their wing's blocks).
//   GET  ?block=ID            → layout + rooms + beds for the editor
//   POST {action:'preset', block_id, preset, floors}  → apply a default design (re-lays out the existing rooms)
//   POST {action:'save', block_id, layout, rooms[], delete[]}  → save the edited design
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/beds.php';
require_once __DIR__ . '/../includes/layout_presets.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'dean_men', 'dean_women'], true)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Only the admin or a dean can edit layouts.']); exit; }
$scope = $role === 'admin' ? null : ($_SESSION['gender_scope'] ?? null);
function fail(string $m, int $c = 422) { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }
function load_block(PDO $pdo, int $id, ?string $scope): array { $q = $pdo->prepare("SELECT * FROM blocks WHERE id=?"); $q->execute([$id]); $b = $q->fetch(); if (!$b || ($scope && $b['gender'] !== $scope)) fail('Building not found.', 404); return $b; }

function studio_payload(PDO $pdo, array $b): array {
    $rs = $pdo->prepare("SELECT id, room_number, floor_no, wing_key, side, slot, width, depth, bunks, singles, bath_type, capacity FROM rooms WHERE block_id=? ORDER BY floor_no, wing_key, side, slot");
    $rs->execute([$b['id']]); $rooms = [];
    $bq = $pdo->prepare("SELECT id, label, kind, bunk_no, pos_x, pos_z, rot, status FROM beds WHERE room_id=? AND is_active=1 ORDER BY label");
    foreach ($rs->fetchAll() as $r) {
        $bq->execute([$r['id']]); $beds = $bq->fetchAll();
        $rooms[] = ['id' => (int)$r['id'], 'number' => $r['room_number'], 'floor' => (int)$r['floor_no'], 'wing' => $r['wing_key'], 'side' => $r['side'], 'slot' => (int)$r['slot'],
            'w' => (float)$r['width'], 'd' => (float)$r['depth'], 'bunks' => (int)$r['bunks'], 'singles' => (int)$r['singles'], 'bath' => $r['bath_type'],
            'beds' => array_map(fn($x) => ['id' => (int)$x['id'], 'label' => $x['label'], 'kind' => $x['kind'], 'bunk' => $x['bunk_no'] ? (int)$x['bunk_no'] : null,
                'x' => $x['pos_x'] !== null ? (float)$x['pos_x'] : null, 'z' => $x['pos_z'] !== null ? (float)$x['pos_z'] : null, 'rot' => $x['rot'] !== null ? (int)$x['rot'] : null, 'status' => $x['status']], $beds)];
    }
    $layout = $b['layout_json'] ? json_decode($b['layout_json'], true) : null;
    return ['ok' => true, 'block' => ['id' => (int)$b['id'], 'name' => $b['name'], 'gender' => $b['gender'], 'floors' => (int)$b['floors']], 'layout' => $layout, 'rooms' => $rooms,
            'presets' => layout_presets(), 'facility_types' => array_map(fn($f) => $f['label'], FAC_LABELS())];
}
function FAC_LABELS(): array { return ['stairs' => ['label' => 'Stairs'], 'wash' => ['label' => 'Bathrooms & toilets'], 'pairbath' => ['label' => 'Shared bathroom'], 'lounge' => ['label' => "Students lounge"], 'office' => ['label' => 'Office'], 'dean' => ['label' => 'Dean office'], 'exit' => ['label' => 'Entrance'], 'store' => ['label' => 'Store'], 'laundry' => ['label' => 'Laundry']]; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $blocks = $pdo->query("SELECT id,name,gender FROM blocks WHERE is_active=1 ORDER BY name")->fetchAll();
    if ($scope) $blocks = array_values(array_filter($blocks, fn($x) => $x['gender'] === $scope));
    $id = (int)($_GET['block'] ?? ($blocks[0]['id'] ?? 0)); if (!$id) fail('No buildings yet.', 404);
    echo json_encode(['blocks' => $blocks] + studio_payload($pdo, load_block($pdo, $id, $scope))); exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$b = load_block($pdo, (int)($in['block_id'] ?? 0), $scope); $act = $in['action'] ?? '';

if ($act === 'preset') {
    $p = $in['preset'] ?? ''; if (!isset(layout_presets()[$p])) fail('Unknown design.');
    try { $pdo->beginTransaction(); layout_apply_preset($pdo, (int)$b['id'], $p, (int)($in['floors'] ?? 3)); $pdo->commit(); }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fail(friendly_error($e, 'applying the layout'), 500); }
    $pdo->prepare("UPDATE beds bd JOIN rooms r ON r.id=bd.room_id SET bd.pos_x=NULL, bd.pos_z=NULL, bd.rot=NULL WHERE r.block_id=?")->execute([$b['id']]);
    audit_log((int)$_SESSION['user_id'], null, 'layout_preset', "Applied '$p' design to {$b['name']}");
    echo json_encode(studio_payload($pdo, load_block($pdo, (int)$b['id'], $scope))); exit;
}
if ($act !== 'save') fail('Unknown action.');

// ---------- validate layout ----------
$L = $in['layout'] ?? null; if (!is_array($L)) fail('Missing layout.');
$floors = max(1, min(8, (int)($L['floors'] ?? 1)));
$types = array_keys(FAC_LABELS()); $wings = []; $keys = [];
foreach (($L['wings'] ?? []) as $w) {
    $k = (string)($w['key'] ?? ''); if (!preg_match('/^[A-Za-z0-9]{1,8}$/', $k) || isset($keys[$k])) fail('Each wing needs a unique short key.');
    $keys[$k] = 1; $at = $w['attach'] ?? null;
    $wings[] = ['key' => $k, 'name' => mb_substr((string)($w['name'] ?? $k), 0, 40), 'x' => (float)($w['x'] ?? 0), 'z' => (float)($w['z'] ?? 0),
        'angle' => max(-360, min(360, (float)($w['angle'] ?? 0))), 'bend' => max(-180, min(180, (float)($w['bend'] ?? 0))), 'corridor' => max(1.5, min(4, (float)($w['corridor'] ?? 2.2))),
        'attach' => (is_array($at) && !empty($at['to'])) ? ['to' => (string)$at['to'], 'at' => ($at['at'] ?? 'end') === 'start' ? 'start' : 'end', 'angle' => max(-360, min(360, (float)($at['angle'] ?? 0)))] : null];
}
if (!$wings) fail('A building needs at least one wing.');
foreach ($wings as $w) {                                                    // attach targets must exist and not loop
    $seen = []; $c = $w;
    while ($c && $c['attach']) { if (isset($seen[$c['key']])) fail('Wings cannot attach in a circle.'); $seen[$c['key']] = 1; $t = array_values(array_filter($wings, fn($x) => $x['key'] === $c['attach']['to'])); if (!$t) fail("Wing {$w['key']} attaches to a wing that does not exist."); $c = $t[0]; }
}
$fac = []; $fid = [];
foreach (($L['facilities'] ?? []) as $f) {
    $id = (string)($f['id'] ?? ''); if (!preg_match('/^[A-Za-z0-9_]{1,12}$/', $id) || isset($fid[$id])) fail('Bad facility id.'); $fid[$id] = 1;
    if (!in_array($f['type'] ?? '', $types, true) || !isset($keys[$f['wing'] ?? ''])) fail('A facility has an unknown type or wing.');
    $fl = ($f['floor'] ?? 'all') === 'all' ? 'all' : max(1, min($floors, (int)$f['floor']));
    $fac[] = ['id' => $id, 'floor' => $fl, 'wing' => $f['wing'], 'side' => ($f['side'] ?? 'A') === 'B' ? 'B' : 'A', 'slot' => max(0, min(300, (int)($f['slot'] ?? 0))), 'type' => $f['type'], 'w' => max(1.5, min(12, (float)($f['w'] ?? 3)))];
}
$layout = ['v' => 1, 'preset' => $L['preset'] ?? 'custom', 'floors' => $floors, 'floor_h' => 3.2, 'campus' => $L['campus'] ?? ['x' => 0, 'z' => 0, 'rot' => 0], 'wings' => $wings, 'facilities' => $fac];

try {
    $pdo->beginTransaction();
    // deletions (only rooms with no history)
    foreach (($in['delete'] ?? []) as $rid) {
        $rid = (int)$rid; $own = $pdo->prepare("SELECT id FROM rooms WHERE id=? AND block_id=?"); $own->execute([$rid, $b['id']]); if (!$own->fetch()) continue;
        $u = $pdo->prepare("SELECT (SELECT COUNT(*) FROM bookings WHERE room_id=?) + (SELECT COUNT(*) FROM students WHERE current_room_id=?) + (SELECT COUNT(*) FROM beds WHERE room_id=? AND status<>'vacant' AND status<>'available_p2')");
        $u->execute([$rid, $rid, $rid]);
        if ((int)$u->fetchColumn() > 0) throw new RuntimeException('A room you removed has bookings or students, so it cannot be deleted. Set its bunks and singles to 0 instead, or keep it.');
        $pdo->prepare("DELETE FROM beds WHERE room_id=?")->execute([$rid]); $pdo->prepare("DELETE FROM rooms WHERE id=?")->execute([$rid]);
    }
    $upd = $pdo->prepare("UPDATE rooms SET room_number=?, floor_no=?, wing_key=?, side=?, slot=?, width=?, depth=?, bunks=?, singles=?, bath_type=? WHERE id=? AND block_id=?");
    $ins = $pdo->prepare("INSERT INTO rooms (block_id, room_number, capacity, gender, status, floor_no, wing_key, side, slot, width, depth, bunks, singles, bath_type) VALUES (?,?,?,?,'vacant',?,?,?,?,?,?,?,?,?)");
    $seenNum = [];
    foreach (($in['rooms'] ?? []) as $r) {
        $num = trim((string)($r['number'] ?? '')); if ($num === '' || mb_strlen($num) > 20) throw new RuntimeException('Every room needs a number.');
        if (isset($seenNum[$num])) throw new RuntimeException("Room number $num is used twice."); $seenNum[$num] = 1;
        if (!isset($keys[$r['wing'] ?? ''])) throw new RuntimeException("Room $num is on a wing that does not exist.");
        $f = max(1, min($floors, (int)($r['floor'] ?? 1))); $sd = ($r['side'] ?? 'A') === 'B' ? 'B' : 'A'; $sl = max(0, min(300, (int)($r['slot'] ?? 0)));
        $w = max(2.4, min(9, (float)($r['w'] ?? 4))); $d = max(2.8, min(9, (float)($r['d'] ?? 5)));
        $bk = max(0, min(4, (int)($r['bunks'] ?? 0))); $sg = max(0, min(6, (int)($r['singles'] ?? 0))); if ($bk * 2 + $sg === 0) throw new RuntimeException("Room $num needs at least one bed.");
        $bath = ($r['bath'] ?? 'none') === 'ensuite' ? 'ensuite' : 'none';
        $rid = (int)($r['id'] ?? 0);
        if ($rid > 0) { $upd->execute([$num, $f, $r['wing'], $sd, $sl, $w, $d, $bk, $sg, $bath, $rid, $b['id']]); }
        else { $ins->execute([$b['id'], $num, $bk * 2 + $sg, $b['gender'], $f, $r['wing'], $sd, $sl, $w, $d, $bk, $sg, $bath]); $rid = (int)$pdo->lastInsertId(); }
        beds_ensure_for_room($pdo, $rid);
        // bed positions: keys b1..bn for bunks, s1..sn for singles
        $pos = is_array($r['beds'] ?? null) ? $r['beds'] : [];
        $bq = $pdo->prepare("SELECT id, kind, bunk_no FROM beds WHERE room_id=? AND is_active=1 ORDER BY label"); $bq->execute([$rid]); $si = 0;
        $setp = $pdo->prepare("UPDATE beds SET pos_x=?, pos_z=?, rot=? WHERE id=?");
        foreach ($bq->fetchAll() as $bd) {
            $key = $bd['kind'] === 'single' ? 's' . (++$si) : 'b' . $bd['bunk_no']; $p = $pos[$key] ?? null;
            if (is_array($p) && isset($p['x'], $p['z'])) $setp->execute([max(-$w / 2, min($w / 2, (float)$p['x'])), max(-$d / 2, min($d / 2, (float)$p['z'])), ((int)($p['rot'] ?? 0) % 360 + 360) % 360, $bd['id']]);
            else $setp->execute([null, null, null, $bd['id']]);
        }
    }
    $pdo->prepare("UPDATE blocks SET layout_json=?, floors=? WHERE id=?")->execute([json_encode($layout, JSON_UNESCAPED_SLASHES), $floors, $b['id']]);
    $pdo->commit();
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fail(friendly_error($e, 'saving the layout')); }
beds_reconcile($pdo);
audit_log((int)$_SESSION['user_id'], null, 'layout_save', "Edited layout of {$b['name']}");
echo json_encode(studio_payload($pdo, load_block($pdo, (int)$b['id'], $scope)));
