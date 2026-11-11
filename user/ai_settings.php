<?php
// user/ai_settings.php — admin: Gemini keys, assistant and push switches, key health.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai/gemini.php';
require_role(['admin']);
if (!function_exists('config_widen')) { function config_widen(PDO $pdo): void { try { $t = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'config' AND COLUMN_NAME = 'config_value'")->fetchColumn(); if ($t && !in_array(strtolower((string)$t), ['text', 'mediumtext'], true)) $pdo->exec('ALTER TABLE config MODIFY config_value TEXT NOT NULL'); } catch (Throwable $e) {} } }
config_widen($pdo);   // repairs the 255-character limit that used to cut long key lists
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        $keys = array_values(gemini_keys());
        foreach ((array)($_POST['remove'] ?? []) as $h) $keys = array_values(array_filter($keys, fn($k) => sprintf('%u', crc32($k)) !== (string)$h));
        $added = []; $ignored = 0;
        $pasted = (string)($_POST['new_keys'] ?? '');
        preg_match_all('/AIza[0-9A-Za-z_\-]{35}|AQ\.[0-9A-Za-z_.\-]{20,90}/', $pasted, $mm);   // also works if you paste whole lines like GEMINI_API_KEY_3=AIza…
        foreach (array_unique($mm[0]) as $v) if (!in_array($v, $keys, true)) { $keys[] = $v; $added[] = $v; }
        if (!$mm[0] && trim($pasted) !== '') $ignored = count(preg_split('/\s+/', trim($pasted)));
        set_config('gemini_keys', json_encode($keys)); foreach ([1, 2, 3] as $i) set_config("gemini_key_$i", '');
        set_config('ai_enabled', empty($_POST['ai_enabled']) ? '0' : '1');
        set_config('push_enabled', empty($_POST['push_enabled']) ? '0' : '1');
        gemini_reset_state($pdo);
        $msgs = []; $good = 0; $kt = [];
        foreach ($added as $k) { [$ok, $m] = gemini_test_key($k); $kt[sprintf('%u', crc32($k))] = [$ok, $m]; if ($ok) $good++; $msgs[] = substr($k, 0, 6) . '…' . substr($k, -4) . ': ' . $m; }
        if ($kt) $_SESSION['key_tests'] = $kt;
        $txt = 'Settings saved.' . ($added ? ' New keys tested — ' . implode(' · ', $msgs) : '') . ($ignored ? " $ignored item(s) did not look like a Gemini key and were ignored." : '');
        $_SESSION['flash'] = ['type' => ($added && !$good) ? 'warning' : 'success', 'msg' => $txt];
    } elseif ($a === 'save_pay') {
        $env = in_array($_POST['mpesa_env'] ?? '', ['demo', 'sandbox', 'live'], true) ? $_POST['mpesa_env'] : 'demo';
        set_config('mpesa_env', $env); set_config('mpesa_demo_outcome', in_array($_POST['mpesa_demo_outcome'] ?? '', ['success','cancelled','timeout','balance','wrong_pin','fail_send'], true) ? $_POST['mpesa_demo_outcome'] : 'success'); set_config('mpesa_shortcode', preg_replace('/\D/', '', $_POST['mpesa_shortcode'] ?? '174379') ?: '174379');
        foreach (['mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_passkey'] as $k) { $v = trim($_POST[$k] ?? ''); if ($v !== '' && preg_match('/^[A-Za-z0-9+\/=_\-]{8,200}$/', $v)) set_config($k, $v); }
        $u = trim($_POST['app_public_url'] ?? ''); set_config('app_public_url', preg_match('#^https://[^\s]+$#', $u) ? rtrim($u, '/') : '');
        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Payment settings saved.'];
    } elseif ($a === 'test_keys') {
        $res = []; gemini_models($pdo, true);   // also refresh the model list from Google
        foreach (gemini_keys() as $id => $k) { [$ok, $m] = gemini_test_key($k); $res[$id] = [$ok, $m]; if (!$ok && strpos($m, 'HTTP 4') === 0) gemini_block($pdo, gemini_kid($k), '*', 6 * 3600, 'test failed: ' . mb_substr($m, 0, 80)); }
        $_SESSION['key_tests'] = $res; $_SESSION['flash'] = ['type' => 'info', 'msg' => 'Key test finished. See the list below.'];
    } elseif ($a === 'test') {
        $r = gemini_generate($pdo, [['role' => 'user', 'parts' => [['text' => 'Reply with the single word OK.']]]], 'You are a connectivity test.');
        $_SESSION['flash'] = $r ? ['type' => 'success', 'msg' => 'Gemini answered: ' . mb_substr($r['candidates'][0]['content']['parts'][0]['text'] ?? '', 0, 60)]
                                : ['type' => 'danger', 'msg' => 'Gemini did not answer. Details: ' . implode(' | ', array_slice(array_unique($GLOBALS['gemini_errors'] ?? ['no request was made']), 0, 4))];
    }
    redirect(APP_BASE . '/user/ai_settings.php');
}
gemini_state_ready($pdo); $state = $pdo->query("SELECT * FROM ai_gem_state ORDER BY kid, model")->fetchAll();
$tails = []; foreach (gemini_keys() as $k) $tails[gemini_kid($k)] = '…' . substr($k, -4);
$mask = fn($k) => $k ? substr($k, 0, 4) . '…' . substr($k, -4) : '';
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">
            <h1 class="h4 my-3"><i class="fas fa-wand-magic-sparkles text-warning me-2"></i>AI, payments &amp; notifications</h1>
            <?php if ($flash): ?><div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show"><?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <div class="row g-3">
                <div class="col-lg-7">
                    <form method="post" class="card shadow-sm"><div class="card-body">
                        <input type="hidden" name="action" value="save">
                        <h2 class="h6">Gemini API keys <span class="badge text-bg-secondary"><?= count(gemini_keys()) ?></span></h2>
                        <p class="small text-body-secondary">Add as many free keys as you like (one per line). Requests spread across keys, and a key that is rate-limited, invalid or on a suspended Google project is skipped automatically, then the next model is tried.</p>
                        <?php $tests = $_SESSION['key_tests'] ?? []; unset($_SESSION['key_tests']); ?>
                        <?php if (gemini_keys()): ?><ul class="list-group mb-3">
                        <?php foreach (gemini_keys() as $id => $k): ?>
                            <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
                                <code><?= h($mask($k)) ?></code>
                                <?php if (isset($tests[$id])): ?><span class="badge text-bg-<?= $tests[$id][0] ? 'success' : 'danger' ?>"><?= $tests[$id][0] ? 'works' : 'failed' ?></span><span class="small text-body-secondary"><?= h($tests[$id][1]) ?></span><?php endif; ?>
                                <span class="form-check ms-auto mb-0"><input class="form-check-input" type="checkbox" name="remove[]" value="<?= h($id) ?>" id="rm<?= h($id) ?>"><label class="form-check-label small" for="rm<?= h($id) ?>">Remove</label></span>
                            </li>
                        <?php endforeach; ?></ul><?php endif; ?>
                        <label class="form-label small mb-1" for="newKeys">Add keys (one per line)</label>
                        <textarea id="newKeys" name="new_keys" class="form-control mb-3" rows="3" autocomplete="off" spellcheck="false" placeholder="AIza..."></textarea>
                        <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="ai_enabled" id="aiOn" value="1" <?= get_config('ai_enabled', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="aiOn">Assistant enabled</label></div>
                        <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="push_enabled" id="pushOn" value="1" <?= get_config('push_enabled', '1') === '1' ? 'checked' : '' ?>><label class="form-check-label" for="pushOn">Push notifications enabled</label></div>
                        <button class="btn btn-primary">Save</button>
                    </div></form>
                </div>
                <div class="col-lg-5">
                    <div class="card shadow-sm mb-3"><div class="card-body">
                        <h2 class="h6">Connection</h2>
                        <p class="small text-body-secondary mb-2"><?= gemini_enabled() ? 'Live AI is on.' : 'No key yet: the assistant runs in basic mode.' ?></p>
                        <a class="btn btn-outline-primary btn-sm mb-2" href="<?= $root ?? '..' ?>/test_gemini.php" target="_blank" rel="noopener"><i class="fas fa-vial me-1" aria-hidden="true"></i>Open detailed key tester</a>
                        <a class="btn btn-outline-primary btn-sm mb-2" href="ai_log.php"><i class="fas fa-clipboard-list me-1" aria-hidden="true"></i>Open AI log</a>
                        <form method="post" class="d-flex flex-wrap gap-2"><input type="hidden" name="action" value="test"><button class="btn btn-outline-dark btn-sm" <?= gemini_keys() ? '' : 'disabled' ?>>Send a test message</button><button class="btn btn-outline-dark btn-sm" name="action" value="test_keys" <?= gemini_keys() ? '' : 'disabled' ?>>Test every key</button></form>
                    </div></div>
                    <div class="card shadow-sm"><div class="card-body">
                        <h2 class="h6">Today's key health</h2>
                        <?php if (!$state): ?><p class="small text-body-secondary mb-0">No calls yet today.</p><?php else: ?>
                        <ul class="list-group list-group-flush small">
                        <?php foreach ($state as $s): $left = (int)$s['until_ts'] - time(); ?><li class="list-group-item px-0 d-flex justify-content-between gap-2"><span><?= h($s['kid'] === '-' ? 'all keys' : ($tails[$s['kid']] ?? 'removed key')) ?> · <?= h($s['model'] === '*' ? 'whole key' : $s['model']) ?></span>
                            <span class="text-end"><?= (int)$s['used'] ?> calls today <?= $left > 0 ? '<span class="badge text-bg-' . ($left > 3600 ? 'danger' : 'warning') . '">blocked ' . ($left > 3600 ? round($left / 3600, 1) . ' h' : max(1, (int)ceil($left / 60)) . ' min') . '</span><div class="text-body-secondary">' . h($s['why']) . '</div>' : '<span class="badge text-bg-success">ready</span>' ?></span></li><?php endforeach; ?>
                        </ul><?php endif; ?>
                    </div></div>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-lg-7">
                    <form method="post" class="card shadow-sm"><div class="card-body">
                        <input type="hidden" name="action" value="save_pay">
                        <h2 class="h6">M-Pesa STK push (late-booking fee)</h2>
                        <p class="small text-body-secondary">Students get a payment prompt on their phone. <strong>Demo</strong> needs no keys and approves a test payment after a few seconds. <strong>Sandbox</strong> and <strong>Live</strong> use Safaricom Daraja keys. Safaricom must be able to reach your site over HTTPS for the confirmation callback.</p>
                        <?php $mk = fn($k) => (string)get_config($k, ''); ?>
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label small mb-1" for="mEnv">Mode</label><select class="form-select" id="mEnv" name="mpesa_env"><?php foreach (['demo' => 'Demo (no money)', 'sandbox' => 'Sandbox', 'live' => 'Live'] as $v => $t): ?><option value="<?= $v ?>" <?= get_config('mpesa_env', 'demo') === $v ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
                            <div class="col-12"><label class="form-label small mb-1" for="mDemo">Demo mode result (to rehearse every message without money)</label><select class="form-select" id="mDemo" name="mpesa_demo_outcome"><?php foreach (['success' => 'Paid', 'cancelled' => 'Student cancels the prompt', 'timeout' => 'No answer / no PIN entered', 'balance' => 'Not enough M-Pesa balance', 'wrong_pin' => 'Wrong PIN', 'fail_send' => 'Prompt cannot be sent'] as $v => $t): ?><option value="<?= $v ?>" <?= get_config('mpesa_demo_outcome', 'success') === $v ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select></div>
                            <div class="col-6"><label class="form-label small mb-1" for="mSc">Shortcode</label><input class="form-control" id="mSc" name="mpesa_shortcode" inputmode="numeric" value="<?= h($mk('mpesa_shortcode')) ?>"></div>
                            <div class="col-12"><label class="form-label small mb-1" for="mCk">Consumer key <?= $mk('mpesa_consumer_key') ? '<span class="badge text-bg-success">saved</span>' : '' ?></label><input type="password" class="form-control" id="mCk" name="mpesa_consumer_key" autocomplete="off" placeholder="<?= $mk('mpesa_consumer_key') ? 'Leave blank to keep' : '' ?>"></div>
                            <div class="col-12"><label class="form-label small mb-1" for="mCs">Consumer secret <?= $mk('mpesa_consumer_secret') ? '<span class="badge text-bg-success">saved</span>' : '' ?></label><input type="password" class="form-control" id="mCs" name="mpesa_consumer_secret" autocomplete="off" placeholder="<?= $mk('mpesa_consumer_secret') ? 'Leave blank to keep' : '' ?>"></div>
                            <div class="col-12"><label class="form-label small mb-1" for="mPk">Passkey <?= $mk('mpesa_passkey') ? '<span class="badge text-bg-success">saved</span>' : '' ?></label><input type="password" class="form-control" id="mPk" name="mpesa_passkey" autocomplete="off" placeholder="<?= $mk('mpesa_passkey') ? 'Leave blank to keep' : '' ?>"></div>
                            <div class="col-12"><label class="form-label small mb-1" for="mUrl">Public site address (https)</label><input class="form-control" id="mUrl" name="app_public_url" placeholder="https://hostel.example.ac.ke" value="<?= h($mk('app_public_url')) ?>"><div class="form-text">The confirmation callback will be sent to this address.</div></div>
                        </div>
                        <button class="btn btn-primary mt-3">Save payment settings</button>
                    </div></form>
                </div>
            </div>
<?php include __DIR__ . '/partial/footer.php'; ?>
