<?php
// includes/ai/ui_tools.php — tools that DRAW things in the chat (tap choices, tables, charts, forms, links) and tools that manage memory.
// None of them change hostel data: real changes still go through the confirm-card write tools.
require_once __DIR__ . '/../shell.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/docs.php';
require_once __DIR__ . '/log.php';

require_once __DIR__ . '/../student_actions.php';
function ai_tool_defs(): array { return ai_tool_defs_core() + ai_ui_tool_defs() + ai_student_tool_defs(); }

function ai_ui_tool_defs(): array {
    $all = ['student', 'secretary', 'dean', 'admin']; $s = ['type' => 'string'];
    return [
        'ask_choices' => ['d' => 'Ask the user a question they answer with ONE TAP (floor, bed type, which student, yes/no, what next). 2-6 short options. Use it instead of asking in plain text. After calling it write one short lead-in line and STOP; the tap arrives as their next message.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['question' => $s, 'options' => ['type' => 'array', 'items' => $s], 'multi' => ['type' => 'boolean', 'description' => 'allow picking several']], 'required' => ['question', 'options']]],
        'show_table' => ['d' => 'Show data as a table in the chat (lists of beds, students, bookings, payments). Fetch real data with the read tools first; never invent rows. Max 8 columns, 40 rows. Then add one sentence of insight.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['title' => $s, 'columns' => ['type' => 'array', 'items' => $s], 'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $s]]], 'required' => ['title', 'columns', 'rows']]],
        'show_chart' => ['d' => 'Show a chart in the chat (occupancy per block, payments per day, vacancy per floor). shape: bars (default), line (trend over time) or donut (share of a whole). Use real numbers from the read tools. Max 12 points.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['title' => $s, 'labels' => ['type' => 'array', 'items' => $s], 'values' => ['type' => 'array', 'items' => ['type' => 'number']], 'unit' => ['type' => 'string', 'description' => 'e.g. beds, KES, %'], 'shape' => $s], 'required' => ['title', 'labels', 'values']]],
        'show_stats' => ['d' => 'Show 2-6 big key numbers as tiles (e.g. beds free, students unplaced, KES collected). Real numbers only.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['title' => $s, 'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['label' => $s, 'value' => $s, 'hint' => $s]]]], 'required' => ['items']]],
        'show_form' => ['d' => 'Put a small form in the chat when you need several details at once (e.g. message to a student, rejection reason, preferences). The user fills it and it comes back as their next message; then continue, and use a write tool if something must change. Field input is one of text, number, select, textarea, date.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['title' => $s, 'intro' => $s, 'submit_label' => $s, 'fields' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => $s, 'label' => $s, 'input' => $s, 'choices' => ['type' => 'array', 'items' => $s], 'must' => ['type' => 'boolean'], 'hint' => $s, 'value' => $s]]]], 'required' => ['title', 'fields']]],
        'ask_steps' => ['d' => 'Run a guided flow: several questions shown ONE AT A TIME, each answered with a single tap, with a progress bar. Use it for surveys, preference matching (finding the right bed), onboarding, or QUIZZES. For a quiz give the correct answer (exact option text) and a short plain-words explanation per question: the user gets instant right/wrong feedback and a score at the end. 2-12 questions, 2-6 options each. After calling write one short line and STOP; the finished answers come back as their next message.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['title' => $s, 'intro' => $s, 'questions' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['q' => $s, 'options' => ['type' => 'array', 'items' => $s], 'answer' => $s, 'why' => $s, 'multi' => ['type' => 'boolean']]]]], 'required' => ['title', 'questions']]],
        'create_document' => ['d' => 'Create a real, designed, downloadable document (PDF by default, or Word .docx when they ask for Word/editable) with the UEAB logo and colours. Landscape is chosen automatically for wide tables. Structure it in sections, each with an optional heading, text, bullets and a table. To put LIVE data in a table do NOT retype rows: give the section a "from" key (occupancy, vacancy, approvals, unplaced, payments, audit, layout, briefing) and the server fills it. Never invent figures. Afterwards say in one line that it is ready.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['title' => $s, 'subtitle' => $s, 'format' => $s, 'orientation' => ['type' => 'string', 'enum' => ['auto', 'portrait', 'landscape']], 'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['heading' => $s, 'text' => $s, 'bullets' => ['type' => 'array', 'items' => $s], 'columns' => ['type' => 'array', 'items' => $s], 'rows' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => $s]], 'from' => $s]]]], 'required' => ['title', 'sections']]],
        'make_report' => ['d' => 'Build a complete, designed report document from LIVE data in one step (fastest way to answer "make a report", "export", "PDF of ..."). topics: any of briefing, occupancy, vacancy, approvals, unplaced, payments, audit, layout (leave empty for a sensible default for their role). format: pdf (default) or docx.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['title' => $s, 'topics' => ['type' => 'array', 'items' => $s], 'format' => $s, 'orientation' => ['type' => 'string', 'enum' => ['auto', 'portrait', 'landscape']]]]],
        'go_to' => ['d' => 'Show a button that opens a page of the system. Use it when the user asks where something is or wants to go somewhere. Keys: students use book or explore; staff use dashboard, map3d, approvals, unplaced, room_assignment, students, rooms, buildings, layout_studio, staff, phase_control, phase_windows, send_links, reports, downloads, ai_settings, profile (only those their role has).', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['page' => $s, 'label' => $s], 'required' => ['page']]],
        'remember' => ['d' => 'Save something durable the user told you so you can personalise later: preferences (quiet floor, lower bunk), habits, how they like answers. Also use it when they give YOU a name (kind=assistant_name) or tell you what to call them (kind=user_nickname). Do not save passwords, ID numbers or private details of other people. Do not announce it at length.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['fact' => $s, 'kind' => ['type' => 'string', 'enum' => ['fact', 'assistant_name', 'user_nickname']]], 'required' => ['fact']]],
        'forget' => ['d' => 'Remove a remembered item when the user asks you to forget it. Pass a word or phrase that appears in it.', 'roles' => $all, 'w' => false, 'ui' => true,
            'p' => ['type' => 'object', 'properties' => ['contains' => $s], 'required' => ['contains']]],
    ];
}

function ai_go_to_pages(array $ctx): array {
    $root = shell_root();
    if ($ctx['kind'] === 'student') return ['book' => [$root . '/student/book.php', 'My booking', 'fa-house'], 'explore' => [$root . '/student/explore.php', 'Explore in 3D', 'fa-cube']];
    $out = [];
    foreach (shell_nav() as $i) $out[preg_replace('/\.php$/', '', $i[2])] = [$root . '/user/' . $i[2], $i[1], $i[3]];
    return $out;
}

/** Runs a UI/memory tool. Rich output is appended to $blocks (sent to the browser); the model only gets a short result. */
function ai_run_ui(PDO $pdo, array $ctx, string $n, array $a, array &$blocks): array {
    $clip = fn($v, $m = 80) => mb_substr(trim((string)(is_scalar($v) ? $v : '')), 0, $m);
    [$col, $oid] = ai_owner($ctx);
    switch ($n) {
        case 'ask_choices':
            $o = array_slice(array_values(array_filter(array_map(fn($x) => $clip($x, 60), (array)($a['options'] ?? [])), 'strlen')), 0, 6);
            if (count($o) < 2) return ['error' => 'Give at least two options.'];
            $blocks[] = ['t' => 'choices', 'q' => $clip($a['question'] ?? 'Choose one', 200), 'opts' => $o, 'multi' => !empty($a['multi'])];
            return ['shown' => true, 'note' => 'Options are on screen. Say one short line and wait for the tap.'];
        case 'show_table':
            $cols = array_slice(array_map(fn($c) => $clip($c, 40), (array)($a['columns'] ?? [])), 0, 8); if (!$cols) return ['error' => 'No columns.'];
            $rows = []; foreach (array_slice((array)($a['rows'] ?? []), 0, 40) as $r) { $r = array_slice(array_map(fn($c) => $clip($c, 90), (array)$r), 0, count($cols)); $rows[] = array_pad($r, count($cols), ''); }
            $blocks[] = ['t' => 'table', 'title' => $clip($a['title'] ?? '', 90), 'cols' => $cols, 'rows' => $rows];
            return ['shown' => true, 'rows' => count($rows)];
        case 'show_chart':
            $l = array_slice(array_map(fn($x) => $clip($x, 30), (array)($a['labels'] ?? [])), 0, 12); $v = array_slice(array_map('floatval', (array)($a['values'] ?? [])), 0, count($l));
            if (!$l || count($v) !== count($l)) return ['error' => 'labels and values must match.'];
            $blocks[] = ['t' => 'chart', 'title' => $clip($a['title'] ?? '', 90), 'labels' => $l, 'values' => $v, 'unit' => $clip($a['unit'] ?? '', 12), 'shape' => in_array($a['shape'] ?? 'bars', ['bars', 'line', 'donut'], true) ? ($a['shape'] ?? 'bars') : 'bars'];
            return ['shown' => true];
        case 'ask_steps':
            $qs = []; foreach (array_slice((array)($a['questions'] ?? []), 0, 12) as $x) { $x = (array)$x;
                $o = array_slice(array_values(array_filter(array_map(fn($z) => $clip($z, 80), (array)($x['options'] ?? [])), 'strlen')), 0, 6); if (count($o) < 2 || $clip($x['q'] ?? '', 200) === '') continue;
                $ans = $clip($x['answer'] ?? '', 80); $qs[] = ['q' => $clip($x['q'], 200), 'opts' => $o, 'ans' => in_array($ans, $o, true) ? $ans : '', 'why' => $clip($x['why'] ?? '', 260), 'multi' => !empty($x['multi'])]; }
            if (!$qs) return ['error' => 'Give at least one question with 2+ options.'];
            $blocks[] = ['t' => 'steps', 'title' => $clip($a['title'] ?? 'Quick questions', 90), 'intro' => $clip($a['intro'] ?? '', 240), 'qs' => $qs, 'quiz' => (bool)array_filter($qs, fn($q) => $q['ans'] !== '')];
            return ['shown' => true, 'questions' => count($qs), 'note' => 'The questions are on screen. Say one short line and wait for the results message.'];
        case 'create_document':
            $secs = []; foreach (array_slice((array)($a['sections'] ?? []), 0, 30) as $x) { $x = (array)$x;
                $sec = ['heading' => $clip($x['heading'] ?? '', 120), 'text' => $clip($x['text'] ?? '', 4000), 'bullets' => array_slice(array_map(fn($b) => $clip($b, 400), (array)($x['bullets'] ?? [])), 0, 40),
                    'columns' => array_slice(array_map(fn($c) => $clip($c, 60), (array)($x['columns'] ?? [])), 0, 12), 'rows' => array_slice(array_map(fn($r) => array_map(fn($c) => $clip($c, 200), (array)$r), (array)($x['rows'] ?? [])), 0, 1000)];
                $from = $clip($x['from'] ?? '', 30);
                if ($from !== '') { $ds = ai_dataset($pdo, $ctx, $from); if ($ds) { if ($sec['heading'] === '') $sec['heading'] = $ds['title']; $sec['stats'] = $ds['stats'] ?? []; $sec['columns'] = $ds['columns'] ?? []; $sec['rows'] = $ds['rows'] ?? []; if (!empty($ds['note']) && $sec['text'] === '') $sec['text'] = $ds['note']; } else $sec['text'] = trim($sec['text'] . ' (That data is not available for your role.)'); }
                $secs[] = $sec; }
            if (!$secs) return ['error' => 'No sections.'];
            return ai_store_document($pdo, $ctx, $blocks, $clip($a['format'] ?? 'pdf', 20), $clip($a['title'] ?? 'Document', 100), $clip($a['subtitle'] ?? '', 140), $secs, $clip($a['orientation'] ?? 'auto', 12));
        case 'make_report':
            $kinds = ['briefing', 'occupancy', 'vacancy', 'approvals', 'unplaced', 'payments', 'audit', 'layout']; $want = array_values(array_filter(array_map(fn($x) => strtolower($clip($x, 20)), (array)($a['topics'] ?? [])), fn($x) => in_array($x, $kinds, true)));
            if (!$want) $want = $ctx['kind'] === 'student' ? ['vacancy'] : ($ctx['kind'] === 'admin' ? ['briefing', 'occupancy', 'approvals', 'unplaced', 'payments', 'layout'] : ['briefing', 'occupancy', 'approvals', 'unplaced']);
            $secs = []; foreach (array_unique($want) as $k) { $ds = ai_dataset($pdo, $ctx, $k); if (!$ds) continue;
                $secs[] = ['heading' => $ds['title'], 'text' => $ds['note'] ?? '', 'bullets' => [], 'stats' => $ds['stats'] ?? [], 'columns' => $ds['columns'] ?? [], 'rows' => $ds['rows'] ?? []]; }
            if (!$secs) return ['error' => 'None of those topics are available for your role.'];
            $scope = $ctx['gender'] ? ucfirst((string)$ctx['gender']) . ' wing' : 'All hostels';
            return ai_store_document($pdo, $ctx, $blocks, $clip($a['format'] ?? 'pdf', 20), $clip($a['title'] ?? '', 100) ?: 'Hostel status report', $scope . ' · ' . date('j F Y, H:i'), $secs, $clip($a['orientation'] ?? 'auto', 12));
        case 'show_stats':
            $it = []; foreach (array_slice((array)($a['items'] ?? []), 0, 6) as $x) { $x = (array)$x; $it[] = ['label' => $clip($x['label'] ?? '', 40), 'value' => $clip($x['value'] ?? '', 24), 'hint' => $clip($x['hint'] ?? '', 50)]; }
            if (count($it) < 1) return ['error' => 'No items.'];
            $blocks[] = ['t' => 'stats', 'title' => $clip($a['title'] ?? '', 80), 'items' => $it]; return ['shown' => true];
        case 'show_form':
            $f = []; foreach (array_slice((array)($a['fields'] ?? []), 0, 8) as $x) { $x = (array)$x; $in = (string)($x['input'] ?? $x['type'] ?? 'text'); $t = in_array($in, ['text', 'number', 'select', 'textarea', 'date'], true) ? $in : 'text';
                $f[] = ['name' => $clip($x['name'] ?? 'f' . count($f), 30), 'label' => $clip($x['label'] ?? '', 60), 'type' => $t, 'options' => array_slice(array_map(fn($z) => $clip($z, 50), (array)($x['choices'] ?? $x['options'] ?? [])), 0, 12), 'required' => !empty($x['must'] ?? $x['required'] ?? false), 'placeholder' => $clip($x['hint'] ?? $x['placeholder'] ?? '', 60), 'value' => $clip($x['value'] ?? '', 120)]; }
            if (!$f) return ['error' => 'No fields.'];
            $blocks[] = ['t' => 'form', 'title' => $clip($a['title'] ?? 'Details', 90), 'intro' => $clip($a['intro'] ?? '', 200), 'submit' => $clip($a['submit_label'] ?? 'Send', 30), 'fields' => $f];
            return ['shown' => true, 'note' => 'Form is on screen. Say one short line and wait for it to come back.'];
        case 'go_to':
            $pages = ai_go_to_pages($ctx); $k = preg_replace('/\.php$/', '', $clip($a['page'] ?? '', 40));
            if (!isset($pages[$k])) return ['error' => 'Page not available for this role. Valid keys: ' . implode(', ', array_keys($pages))];
            $blocks[] = ['t' => 'link', 'href' => $pages[$k][0], 'label' => $clip($a['label'] ?? '', 40) ?: 'Open ' . $pages[$k][1], 'icon' => $pages[$k][2]];
            return ['shown' => true];
        case 'remember':
            $fact = $clip($a['fact'] ?? '', 200); if ($fact === '') return ['error' => 'Nothing to remember.'];
            $kind = in_array($a['kind'] ?? 'fact', ['fact', 'assistant_name', 'user_nickname'], true) ? ($a['kind'] ?? 'fact') : 'fact';
            if ($kind !== 'fact') { $fact = ai_clean_name($fact); if ($fact === '') return ['error' => 'Give just the name itself, for example Zuri.']; }
            if ($kind !== 'fact') { $pdo->prepare("DELETE FROM ai_memory WHERE $col=? AND kind=?")->execute([$oid, $kind]); }
            else {
                $d = $pdo->prepare("SELECT 1 FROM ai_memory WHERE $col=? AND kind='fact' AND fact=?"); $d->execute([$oid, $fact]); if ($d->fetchColumn()) return ['saved' => true];
                $c = $pdo->prepare("SELECT COUNT(*) FROM ai_memory WHERE $col=? AND kind='fact'"); $c->execute([$oid]);
                if ((int)$c->fetchColumn() >= 40) $pdo->prepare("DELETE FROM ai_memory WHERE $col=? AND kind='fact' ORDER BY id ASC LIMIT 1")->execute([$oid]);
            }
            $pdo->prepare("INSERT INTO ai_memory ($col,kind,fact) VALUES (?,?,?)")->execute([$oid, $kind, $fact]);
            $blocks[] = ['t' => 'note', 'icon' => 'fa-brain', 'text' => $kind === 'assistant_name' ? "I'll go by $fact now" : ($kind === 'user_nickname' ? "I'll call you $fact" : "Remembered: $fact"), 'kind' => $kind, 'val' => $fact];
            return ['saved' => true];
        case 'forget':
            $w = $clip($a['contains'] ?? '', 60); if (mb_strlen($w) < 2) return ['error' => 'Say what to forget.'];
            $q = $pdo->prepare("DELETE FROM ai_memory WHERE $col=? AND fact LIKE ?"); $q->execute([$oid, '%' . addcslashes($w, '%_\\') . '%']);
            $blocks[] = ['t' => 'note', 'icon' => 'fa-eraser', 'text' => $q->rowCount() ? "Forgot: $w" : "Nothing matched \"$w\""];
            return ['removed' => $q->rowCount()];
    }
    return ['error' => 'Unknown tool.'];
}


/** Build the PDF/Word file, keep it for the person, and add the download card to the reply. */
function ai_store_document(PDO $pdo, array $ctx, array &$blocks, string $format, string $title, string $sub, array $secs, string $orient = 'auto'): array {
    [$col, $oid] = ai_owner($ctx); $fmt = (strtolower($format) === 'docx' || stripos($format, 'word') !== false) ? 'docx' : 'pdf';
    $by = $ctx['student'] ? ($ctx['student']['full_name'] ?? '') : (string)($_SESSION['full_name'] ?? '');
    $opts = ['orientation' => in_array($orient, ['portrait', 'landscape'], true) ? $orient : 'auto', 'author' => $by];
    try { $bin = $fmt === 'docx' ? ai_docx($title, $sub, $secs, $opts) : ai_pdf($title, $sub, $secs, $opts); } catch (Throwable $e) { ai_log('error', 'document', get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); return ['error' => 'Could not build the document.']; }
    ai_files_ready($pdo); $name = (trim(preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-') ?: 'document') . '-' . date('Ymd-Hi') . '.' . $fmt;
    $pdo->prepare("INSERT INTO ai_files ($col,name,mime,data) VALUES (?,?,?,?)")->execute([$oid, $name, $fmt === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $bin]);
    $land = ai_doc_is_landscape($secs, $opts['orientation']);
    $blocks[] = ['t' => 'file', 'id' => (int)$pdo->lastInsertId(), 'name' => $name, 'fmt' => $fmt, 'size' => strlen($bin), 'title' => $title, 'land' => $land];
    ai_log('info', 'document', "$name · " . round(strlen($bin) / 1024, 1) . ' KB · ' . count($secs) . ' section(s) · ' . ($land ? 'landscape' : 'portrait'));
    return ['created' => true, 'file' => $name, 'orientation' => $land ? 'landscape' : 'portrait'];
}
