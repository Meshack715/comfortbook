<?php
// user/ai_log.php — admin: the full trace of every assistant request, key health, and a self-test that pinpoints what Google rejects.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
require_once __DIR__ . '/../includes/beds.php';
require_once __DIR__ . '/../includes/ai/chat.php';
require_role(['admin']);
ai_ensure_schema($pdo); ai_log_ready();
$GLOBALS['ai_actor'] = 'admin#' . ($_SESSION['user_id'] ?? '?');
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); $self = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'clear') { $pdo->exec("DELETE FROM ai_log"); $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Log cleared.']; header('Location: ai_log.php'); exit; }
    if ($a === 'reset') { gemini_reset_state($pdo); $_SESSION['flash'] = ['type' => 'success', 'msg' => 'All key cooldowns and “invalid” marks cleared for today.']; header('Location: ai_log.php'); exit; }
    if ($a === 'selftest') {
        @set_time_limit(150);
        $ctx = ai_actor_ctx($pdo); $ctx['mem'] = ['assistant' => '', 'nickname' => '', 'facts' => []]; $ctx['brief'] = ''; $ctx['page'] = 'ai_log.php';
        $full = ai_function_decls($ctx); $tools = ai_tools_for($ctx); $core = array_values(array_filter($full, fn($d) => empty($tools[$d['name']]['ui'])));
        $ui = array_values(array_filter($full, fn($d) => !empty($tools[$d['name']]['ui'])));
        $msg = [['role' => 'user', 'parts' => [['text' => 'Say hello in three words.']]]];
        $self = [];
        foreach ([['1. No tools, tiny prompt', [], 'You are a test.'], ['2. Original data tools only (' . count($core) . ')', $core, 'You are a test.'], ['3. New draw/memory tools only (' . count($ui) . ')', $ui, 'You are a test.'], ['4. Everything + the real system prompt (' . count($full) . ' tools)', $full, ai_system_prompt($ctx)]] as [$name, $d, $sys]) {
            $GLOBALS['gemini_errors'] = []; $t = microtime(true); $r = gemini_generate($pdo, $msg, $sys, $d);
            $self[] = ['name' => $name, 'ok' => (bool)$r, 'ms' => (int)round((microtime(true) - $t) * 1000), 'text' => $r ? mb_substr((string)($r['candidates'][0]['content']['parts'][0]['text'] ?? '[function call]'), 0, 120) : '', 'err' => implode("\n", array_slice(array_unique($GLOBALS['gemini_errors']), 0, 6))];
            ai_log($r ? 'info' : 'error', 'selftest', $name . ' → ' . ($r ? 'OK' : 'FAILED'));
        }
    }
}
$rows = $pdo->query("SELECT * FROM ai_log ORDER BY id DESC LIMIT 700")->fetchAll();
$groups = []; foreach ($rows as $r) $groups[$r['rid']][] = $r;
$groups = array_slice($groups, 0, 30, true);
$tails = []; foreach (gemini_keys() as $id => $k) $tails[(string)$id] = '…' . substr($k, -4) . ' (' . substr($k, 0, 4) . ')';
gemini_state_ready($pdo); $state = $pdo->query("SELECT * FROM ai_gem_state ORDER BY kid, model")->fetchAll(); $ktail = []; foreach (gemini_keys() as $k) $ktail[gemini_kid($k)] = '…' . substr($k, -4) . ' (' . substr($k, 0, 4) . ')';
$lvl = ['info' => 'secondary', 'warn' => 'warning', 'error' => 'danger'];
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">
            <div class="d-flex flex-wrap align-items-center gap-2 my-3">
                <h1 class="h4 mb-0 me-auto"><i class="fas fa-clipboard-list text-warning me-2"></i>AI log</h1>
                <a class="btn btn-outline-secondary btn-sm" href="ai_log.php"><i class="fas fa-rotate me-1"></i>Refresh</a>
                <form method="post" class="d-inline"><input type="hidden" name="action" value="selftest"><button class="btn btn-primary btn-sm"><i class="fas fa-vial me-1"></i>Run self-test</button></form>
                <form method="post" class="d-inline"><input type="hidden" name="action" value="reset"><button class="btn btn-outline-warning btn-sm">Reset key cooldowns</button></form>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete the whole log?')"><input type="hidden" name="action" value="clear"><button class="btn btn-outline-danger btn-sm">Clear</button></form>
            </div>
            <?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div><?php endif; ?>

            <?php if ($self): ?>
            <div class="card mb-3 border-primary"><div class="card-header fw-semibold">Self-test result <span class="text-body-secondary small">— sends the same kind of request the chat sends, with and without the tools</span></div>
              <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
              <?php foreach ($self as $t): ?><tr><td class="text-nowrap"><?= h($t['name']) ?></td><td><?= $t['ok'] ? '<span class="badge text-bg-success">works</span>' : '<span class="badge text-bg-danger">failed</span>' ?></td><td class="text-nowrap"><?= (int)$t['ms'] ?> ms</td><td class="small"><?= $t['ok'] ? h($t['text']) : '<pre class="mb-0 small text-danger" style="white-space:pre-wrap">' . h($t['err'] ?: 'No HTTP call was made: every key is cooling down or marked invalid (see key health below).') . '</pre>' ?></td></tr><?php endforeach; ?>
              </tbody></table></div>
              <div class="card-footer small text-body-secondary">If 1 works and 2 or 3 fails, Google is rejecting that tool group. If 1 fails too, it is the keys or the network. Full details are in the log below.</div></div>
            <?php endif; ?>

            <div class="card mb-3"><div class="card-header fw-semibold">Key health (a blocked key costs zero waiting)</div>
              <?php if (!$state): ?><div class="card-body small text-body-secondary">No key has been used yet today. If chat still says it is unavailable, run the self-test.</div><?php else: ?>
              <div class="table-responsive"><table class="table table-sm align-middle mb-0 small"><thead class="table-light"><tr><th>Key</th><th>Model</th><th>Used</th><th>State</th></tr></thead><tbody>
              <?php foreach ($state as $s): $left = (int)$s['until_ts'] - time(); ?>
                <tr><td><code><?= h($s['kid'] === '-' ? 'all keys' : ($ktail[$s['kid']] ?? 'removed key')) ?></code></td><td><?= h($s['model'] === '*' ? 'whole key' : $s['model']) ?></td><td><?= (int)$s['used'] ?></td>
                <td><?= $left > 0 ? '<span class="badge text-bg-' . ($left > 3600 ? 'danger' : 'warning') . '">blocked ' . ($left > 3600 ? round($left / 3600, 1) . ' h' : max(1, (int)ceil($left / 60)) . ' min') . '</span> <span class="text-body-secondary">' . h($s['why']) . '</span>' : '<span class="badge text-bg-success">ready</span>' . ($s['ms'] ? ' <span class="text-body-secondary">' . (int)$s['ms'] . ' ms last</span>' : '') ?></td></tr>
              <?php endforeach; ?></tbody></table></div><?php endif; ?></div>

            <h2 class="h6 text-uppercase text-body-secondary">Requests (newest first)</h2>
            <?php if (!$groups): ?><div class="alert alert-info">Nothing logged yet. Send a message to the assistant, then refresh.</div><?php endif; ?>
            <?php foreach ($groups as $rid => $g): $g = array_reverse($g); $err = array_filter($g, fn($r) => $r['level'] === 'error'); $okr = array_filter($g, fn($r) => $r['event'] === 'reply'); $txt = ''; ?>
            <div class="card mb-2 <?= $err ? 'border-danger-subtle' : '' ?>">
              <div class="card-header d-flex flex-wrap align-items-center gap-2 py-2">
                <code><?= h($rid) ?></code><span class="small text-body-secondary"><?= h($g[0]['created_at']) ?> · <?= h($g[0]['actor'] ?? '') ?></span>
                <?= $okr && !$err ? '<span class="badge text-bg-success">answered</span>' : ($err ? '<span class="badge text-bg-danger">' . count($err) . ' error(s)</span>' : '<span class="badge text-bg-secondary">no reply logged</span>') ?>
                <button class="btn btn-sm btn-outline-secondary ms-auto py-0" type="button" data-copy="#t<?= h($rid) ?>"><i class="fas fa-copy me-1"></i>Copy</button>
                <button class="btn btn-sm btn-outline-primary py-0" type="button" data-bs-toggle="collapse" data-bs-target="#g<?= h($rid) ?>">Details</button>
              </div>
              <div class="collapse <?= $err ? 'show' : '' ?>" id="g<?= h($rid) ?>"><div class="table-responsive"><table class="table table-sm align-top mb-0 small">
                <tbody><?php foreach ($g as $r): $txt .= "[{$r['created_at']}] {$r['level']} {$r['event']} {$r['model']} {$r['key_tail']} HTTP {$r['http_code']} {$r['ms']}ms\n  {$r['detail']}\n"; ?>
                  <tr><td class="text-nowrap text-body-secondary"><?= h(substr($r['created_at'], 11)) ?></td><td><span class="badge text-bg-<?= $lvl[$r['level']] ?? 'secondary' ?>"><?= h($r['event']) ?></span></td>
                  <td class="text-nowrap"><?= h($r['model']) ?> <?= $r['key_tail'] ? '<code>' . h($r['key_tail']) . '</code>' : '' ?> <?= $r['http_code'] !== null ? '<span class="badge text-bg-light border">HTTP ' . (int)$r['http_code'] . '</span>' : '' ?> <?= $r['ms'] !== null ? '<span class="text-body-secondary">' . (int)$r['ms'] . ' ms</span>' : '' ?></td>
                  <td style="white-space:pre-wrap;overflow-wrap:anywhere"><?= h($r['detail']) ?></td></tr><?php endforeach; ?></tbody></table></div>
                <textarea id="t<?= h($rid) ?>" class="d-none"><?= h($txt) ?></textarea></div>
            </div>
            <?php endforeach; ?>
            <script>
            document.querySelectorAll('[data-copy]').forEach(b => b.addEventListener('click', () => {
              const t = document.querySelector(b.dataset.copy).value; (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).catch(() => { const a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); document.execCommand('copy'); a.remove(); });
              b.innerHTML = '<i class="fas fa-check me-1"></i>Copied'; setTimeout(() => b.innerHTML = '<i class="fas fa-copy me-1"></i>Copy', 1500); }));
            </script>
<?php include __DIR__ . '/partial/footer.php'; ?>
