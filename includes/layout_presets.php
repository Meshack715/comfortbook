<?php
// includes/layout_presets.php — default building designs. The admin can change every one of them
// in the Layout Studio; these only give each block a sensible starting shape.
//
// Layout JSON (blocks.layout_json), all lengths in metres, angles in degrees:
// { v:1, floors:3, floor_h:3.2,
//   campus:{x,z,rot},
//   wings:[{key,name,attach:null|{to,at:'start'|'end',angle}, x,z,angle, bend, corridor}],
//   facilities:[{id,floor:'all'|1|2|3,wing,side:'A'|'B',slot,type,w}] }
// Rooms hold floor_no / wing_key / side / slot / width / depth. A "cell" is a room or a facility;
// cells on one side of a wing are laid out in slot order along the wing's centre line.

const LAYOUT_FACILITY_TYPES = [
    'stairs' => 'Stairs', 'wash' => 'Bathrooms & toilets', 'pairbath' => 'Shared bathroom (between 2 rooms)',
    'lounge' => 'Students lounge', 'office' => 'Office', 'dean' => 'Dean office', 'exit' => 'Exit / entrance',
    'store' => 'Store', 'laundry' => 'Laundry',
];

function layout_presets(): array {
    return [
        'straight' => ['name' => 'Straight block', 'desc' => 'One long corridor, rooms on both sides.'],
        'new_men'  => ['name' => 'Bent wing (like New Men)', 'desc' => 'Main corridor with a second wing angled off one end, lounge and offices at the far end.'],
        'l_shape'  => ['name' => 'L-shape', 'desc' => 'Two wings meeting at 90°.'],
        'u_shape'  => ['name' => 'U-shape courtyard', 'desc' => 'Three wings around a courtyard.'],
        'curved'   => ['name' => 'Curved block', 'desc' => 'One wing that bends gently.'],
        'pairbath' => ['name' => 'Rooms with shared bathrooms', 'desc' => 'Every two rooms share a bathroom between them.'],
    ];
}

/** Build layout JSON + room cell assignment for $roomIds (natural order) and write them. */
function layout_apply_preset(PDO $pdo, int $blockId, string $preset, int $floors = 3): array {
    $floors = max(1, min(6, $floors));
    $rooms = $pdo->prepare("SELECT id, room_number FROM rooms WHERE block_id=?");
    $rooms->execute([$blockId]);
    $rooms = $rooms->fetchAll();
    usort($rooms, fn($a, $b) => strnatcasecmp($a['room_number'], $b['room_number']));
    $n = count($rooms);
    $perFloor = max(1, (int)ceil($n / $floors));

    $W = fn($key, $name, $attach = null, $angle = 0, $bend = 0) => ['key' => $key, 'name' => $name, 'attach' => $attach, 'x' => 0, 'z' => 0, 'angle' => $angle, 'bend' => $bend, 'corridor' => 2.2];
    $weights = [];  $wings = [];
    switch ($preset) {
        case 'new_men':
            $wings = [$W('W1', 'Main wing', null, 0), $W('W2', 'Angled wing', ['to' => 'W1', 'at' => 'start', 'angle' => -150], 0)];
            $wings[1]['attach'] = ['to' => 'W1', 'at' => 'start', 'angle' => 155];   // swings back from the start of W1
            $weights = ['W1' => .62, 'W2' => .38]; break;
        case 'l_shape':
            $wings = [$W('W1', 'Long wing', null, 0), $W('W2', 'Short wing', ['to' => 'W1', 'at' => 'end', 'angle' => 90])];
            $weights = ['W1' => .6, 'W2' => .4]; break;
        case 'u_shape':
            $wings = [$W('W1', 'West wing', null, 0), $W('W2', 'Link wing', ['to' => 'W1', 'at' => 'end', 'angle' => 90]), $W('W3', 'East wing', ['to' => 'W2', 'at' => 'end', 'angle' => 90])];
            $weights = ['W1' => .38, 'W2' => .24, 'W3' => .38]; break;
        case 'curved':
            $wings = [$W('W1', 'Curved wing', null, 0, 70)]; $weights = ['W1' => 1]; break;
        case 'pairbath':
        case 'straight':
        default:
            $wings = [$W('W1', 'Main wing', null, 0)]; $weights = ['W1' => 1]; $preset = $preset === 'pairbath' ? 'pairbath' : 'straight';
    }

    // distribute this floor's rooms across wing sides by weight
    $sides = [];
    foreach ($wings as $w) { $sides[] = [$w['key'], 'A']; $sides[] = [$w['key'], 'B']; }
    $quota = [];
    $left = $perFloor;
    foreach ($sides as $i => [$wk, $sd]) {
        $q = (int)round($perFloor * $weights[$wk] / 2);
        $quota[$i] = $q; $left -= $q;
    }
    for ($i = 0; $left > 0; $i = ($i + 1) % count($sides)) { $quota[$i]++; $left--; }
    for ($i = 0; $left < 0; $i = ($i + 1) % count($sides)) { if ($quota[$i] > 0) { $quota[$i]--; $left++; } }

    $fac = []; $fid = 1;
    $addF = function ($floor, $wing, $side, $slot, $type, $w) use (&$fac, &$fid) {
        $fac[] = ['id' => 'f' . $fid++, 'floor' => $floor, 'wing' => $wing, 'side' => $side, 'slot' => $slot, 'type' => $type, 'w' => $w];
    };

    $upd = $pdo->prepare("UPDATE rooms SET floor_no=?, wing_key=?, side=?, slot=?, width=?, depth=? WHERE id=?");
    $idx = 0;
    $slotsUsed = [];
    for ($f = 1; $f <= $floors; $f++) {
        foreach ($sides as $i => [$wk, $sd]) {
            $slot = 0;
            for ($k = 0; $k < $quota[$i] && $idx < $n; $k++) {
                if ($preset === 'pairbath' && $k > 0 && $k % 2 === 0) $slot++;          // gap for a shared bathroom
                $upd->execute([$f, $wk, $sd, $slot++, 4.0, 5.0, $rooms[$idx++]['id']]);
            }
            $slotsUsed[$f][$wk][$sd] = $slot;
        }
    }
    // facilities
    $first = $wings[0]['key']; $last = end($wings)['key'];
    $addF('all', $first, 'A', 100, 'stairs', 3.0);                                   // stairs at the end of main wing
    $addF('all', $first, 'B', 100, 'wash', 4.5);                                     // bathrooms & toilets section on every floor
    if (count($wings) > 1) $addF('all', $last, 'A', 100, 'stairs', 3.0);
    $addF(1, $first, 'A', 101, 'exit', 2.4);
    if ($preset === 'new_men') { $addF(1, $first, 'B', 101, 'lounge', 7.0); $addF(1, $first, 'A', 102, 'office', 3.5); $addF(1, $first, 'A', 103, 'dean', 3.5); }
    if ($preset === 'pairbath') {                                                      // bathroom cells in the gaps
        foreach (['A', 'B'] as $sd) for ($s = 2; $s < 40; $s += 3) $addF('all', 'W1', $sd, $s, 'pairbath', 2.2);
    }
    $layout = ['v' => 1, 'preset' => $preset, 'floors' => $floors, 'floor_h' => 3.2, 'campus' => ['x' => 0, 'z' => 0, 'rot' => 0], 'wings' => $wings, 'facilities' => $fac];
    // pair-bath slots: rooms skipped k%2==0 gaps starting at slot 2,5,8… — align them
    if ($preset === 'pairbath') {
        $layout['facilities'] = array_values(array_filter($layout['facilities'], fn($x) => $x['type'] !== 'pairbath'));
        foreach (['A', 'B'] as $sd) { $m = $slotsUsed[1]['W1'][$sd] ?? 0; for ($s = 2; $s < $m; $s += 3) $layout['facilities'][] = ['id' => 'f' . $fid++, 'floor' => 'all', 'wing' => 'W1', 'side' => $sd, 'slot' => $s, 'type' => 'pairbath', 'w' => 2.2]; }
    }
    $pdo->prepare("UPDATE blocks SET layout_json=?, floors=? WHERE id=?")->execute([json_encode($layout, JSON_UNESCAPED_SLASHES), $floors, $blockId]);
    return $layout;
}
