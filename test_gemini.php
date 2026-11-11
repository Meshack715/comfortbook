<?php
/* test_gemini.php — standalone Gemini key tester. Admin only. Shows the raw Google answer for every key and model.
   Open: /test_gemini.php   (log in as admin first). Pasted keys are tested and NOT saved. */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!function_exists('is_admin') || !is_admin()) { http_response_code(403); exit('Admin login required. <a href="index.php">Sign in</a>'); }

const T_MODELS = ['gemini-2.5-flash', 'gemini-3.1-flash-lite', 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest', 'gemini-3-flash-preview', 'gemini-3.5-flash'];
$base = rtrim((string)get_config('gemini_endpoint', 'https://generativelanguage.googleapis.com'), '/');
if (!preg_match('#/v1(beta)?$#', $base)) $base .= '/v1beta';

function t_call(string $method, string $url, string $key, ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $key]]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $t = microtime(true); $raw = curl_exec($ch);
    $o = ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'ms' => (int)((microtime(true) - $t) * 1000), 'err' => curl_error($ch), 'errno' => curl_errno($ch), 'raw' => (string)$raw];
    curl_close($ch); $o['json'] = $raw ? json_decode($raw, true) : null; $o['via'] = 'header';
    if (in_array($o['code'], [400, 401, 403], true)) {            // second try exactly like Dishi: key in the URL
        $u2 = $url . (str_contains($url, '?') ? '&' : '?') . 'key=' . rawurlencode($key); $c2 = curl_init($u2);
        curl_setopt_array($c2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
        if ($body !== null) curl_setopt_array($c2, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
        $raw2 = curl_exec($c2); $code2 = (int)curl_getinfo($c2, CURLINFO_HTTP_CODE); curl_close($c2);
        $o['first'] = $o['code']; if ($code2 === 200 || $code2 === 429) { $o['code'] = $code2; $o['raw'] = (string)$raw2; $o['json'] = json_decode($raw2, true); $o['via'] = 'url ?key='; }
    }
    return $o;
}
function t_mask(string $k): string { return substr($k, 0, 6) . '…' . substr($k, -4) . ' (' . strlen($k) . ' chars)'; }
function t_why(array $r): string {
    if ($r['code'] === 0) return 'No answer from Google: ' . ($r['err'] ?: 'unknown network error') . (in_array($r['errno'], [60, 77], true) ? ' → this server has no CA bundle. Download cacert.pem from curl.se, set curl.cainfo in php.ini, restart.' : ($r['errno'] === 6 ? ' → DNS: the server cannot resolve the name.' : ''));
    $m = (string)($r['json']['error']['message'] ?? '');
    return match (true) {
        $r['code'] === 200 => 'OK',
        $r['code'] === 403 && stripos($m, 'suspended') !== false => 'The Google project behind this key is SUSPENDED. Create a key under another Google account/project.',
        $r['code'] === 403 => 'Key refused (403): the Generative Language API is not enabled for its project, or the key is restricted. ' . $m,
        $r['code'] === 400 && stripos($m, 'API key') !== false => 'Not a valid Gemini key (400). Copy it again from aistudio.google.com/apikey. ' . $m,
        $r['code'] === 404 => 'This model name does not exist (any more) for this key.',
        $r['code'] === 429 => 'Valid key, but rate-limited / quota used up right now. Resets daily (midnight Pacific).',
        $r['code'] >= 500 => 'Google had a temporary problem. Try again.',
        default => "HTTP {$r['code']}: $m",
    };
}

$keys = [];
foreach ((array)json_decode((string)get_config('gemini_keys', '[]'), true) as $k) if (is_string($k) && $k !== '') $keys['Saved key'][] = $k;
foreach (['gemini_key_1', 'gemini_key_2', 'gemini_key_3'] as $c) { $v = trim((string)get_config($c, '')); if ($v !== '') $keys['Saved key'][] = $v; }
if (getenv('GEMINI_API_KEY')) $keys['Saved key'][] = getenv('GEMINI_API_KEY');
$rawSaved = (string)get_config('gemini_keys', '[]'); $jsonOk = json_decode($rawSaved, true) !== null || $rawSaved === 'null';
$colType = ''; try { $colType = (string)$pdo->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='config' AND COLUMN_NAME='config_value'")->fetchColumn(); } catch (Throwable $e) {}
$list = []; foreach ($keys['Saved key'] ?? [] as $k) $list[] = ['Saved', $k];
$pasted = []; if ($_SERVER['REQUEST_METHOD'] === 'POST') { preg_match_all('/AIza[0-9A-Za-z_\-]{35}|AQ\.[0-9A-Za-z_.\-]{20,90}/', (string)($_POST['keys'] ?? ''), $mm); $pasted = array_values(array_unique($mm[0])); }   // paste whole .env lines or bare keys
foreach ($pasted as $k) $list[] = ['Pasted', $k];
$run = $_SERVER['REQUEST_METHOD'] === 'POST';
$cv = curl_version();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gemini key tester</title>
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet"><link href="assets/vendor/fontawesome/css/all.min.css" rel="stylesheet"></head>
<body class="bg-body-tertiary"><main class="container py-3" style="max-width:860px">
<h1 class="h4"><i class="fas fa-vial text-primary me-2" aria-hidden="true"></i>Gemini key tester</h1>
<p class="text-body-secondary">Sends one tiny request per model and shows exactly what Google answers. Nothing here is saved.</p>
<div class="card mb-3"><div class="card-body small">
<div class="row g-2"><div class="col-6 col-md-3"><b>PHP</b><br><?= htmlspecialchars(PHP_VERSION) ?></div><div class="col-6 col-md-3"><b>cURL</b><br><?= htmlspecialchars($cv['version']) ?></div><div class="col-6 col-md-3"><b>TLS</b><br><?= htmlspecialchars($cv['ssl_version'] ?? '—') ?></div><div class="col-6 col-md-3"><b>CA file</b><br><?= htmlspecialchars(ini_get('curl.cainfo') ?: 'system default') ?></div></div>
<?php if (!$jsonOk || stripos($colType, 'varchar') !== false): ?><div class="alert alert-danger mt-2 mb-2" role="alert"><b>Saved keys are damaged.</b> The database column that stores them is <code><?= htmlspecialchars($colType) ?></code> (max 255 characters), so a long key list was cut off<?= $jsonOk ? '' : ' and can no longer be read' ?>. Open Admin → AI &amp; payments once (it repairs the column), delete the old entries and paste the keys again.</div><?php endif; ?>
<div class="mt-2"><b>Saved list length</b> <?= strlen($rawSaved) ?> chars · <b>Endpoint</b> <code><?= htmlspecialchars($base) ?></code> · <b>Saved keys</b> <?= count($keys['Saved key'] ?? []) ?></div></div></div>
<form method="post" class="card mb-3"><div class="card-body"><label class="form-label fw-semibold" for="keys">Paste keys or whole GEMINI_API_KEY… lines from your .env (optional — not saved)</label>
<textarea id="keys" name="keys" rows="2" class="form-control mb-2" autocomplete="off" spellcheck="false" placeholder="AIza…"></textarea>
<button class="btn btn-primary"><i class="fas fa-play me-1" aria-hidden="true"></i>Test all keys now</button></div></form>
<?php if ($run): if (!$list): ?><div class="alert alert-warning" role="alert">No keys found. Add keys in Admin → AI &amp; payments, or paste one above.</div><?php endif;
foreach ($list as $i => [$src, $k]):
    $lm = t_call('GET', "$base/models?pageSize=100", $k);
    $valid = $lm['code'] === 200; $refused = in_array($lm['code'], [400, 401, 403], true); $models = [];
    if ($valid) foreach (($lm['json']['models'] ?? []) as $m) if (in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) $models[] = preg_replace('#^models/#', '', $m['name']);
?>
<div class="card mb-3"><div class="card-header d-flex flex-wrap gap-2 align-items-center"><span class="badge text-bg-secondary"><?= $src ?></span><code><?= htmlspecialchars(t_mask($k)) ?></code>
 <span class="ms-auto badge text-bg-<?= $valid ? 'success' : ($refused ? 'danger' : 'warning') ?>"><?= $valid ? 'Key accepted by Google' : ($refused ? 'Key refused' : 'Could not list models') ?></span></div>
<div class="card-body">
<p class="mb-2 <?= $valid ? 'text-success' : 'text-danger' ?>"><b>Step 1 · ListModels:</b> <?= ($lm['via'] ?? '') === 'url ?key=' ? '(worked only when the key is sent in the URL like Dishi does)' : '' ?> <?= htmlspecialchars($valid ? count($models) . ' models can generate text for this key.' : t_why($lm)) ?> <span class="text-body-secondary">(<?= $lm['ms'] ?> ms)</span></p>
<?php if ($refused || $lm['code'] === 0): ?><details class="small"><summary>Raw reply from Google</summary><pre class="mb-0 mt-2 small text-wrap"><?= htmlspecialchars(mb_substr($lm['raw'] ?: $lm['err'], 0, 900)) ?></pre></details>
<?php else: ?>
<?php if (!$valid): ?><p class="small text-body-secondary">ListModels was not available (<?= htmlspecialchars(t_why($lm)) ?>) — trying the models directly.</p><?php endif; ?>
<p class="mb-1"><b>Step 2 · Real request per model</b></p>
<div class="table-responsive"><table class="table table-sm align-middle mb-2"><thead><tr><th>Model</th><th>Listed</th><th>Result</th><th class="text-end">ms</th></tr></thead><tbody>
<?php $okc = 0; foreach (T_MODELS as $m):
    $r = t_call('POST', "$base/models/$m:generateContent", $k, ['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with OK']]]], 'generationConfig' => ['maxOutputTokens' => 64]]);
    $txt = $r['code'] === 200 ? trim((string)($r['json']['candidates'][0]['content']['parts'][0]['text'] ?? '(empty reply)')) : ''; if ($r['code'] === 200) $okc++; ?>
<tr><td><code><?= htmlspecialchars($m) ?></code></td><td><?= !$valid ? '—' : (in_array($m, $models, true) ? 'yes' : '<span class="text-danger">no</span>') ?></td>
<td><?php if ($r['code'] === 200): ?><span class="badge text-bg-success">OK</span> <span class="small text-body-secondary">“<?= htmlspecialchars(mb_substr($txt, 0, 40)) ?>”</span><?php else: ?><span class="badge text-bg-<?= $r['code'] === 429 ? 'warning' : 'danger' ?>"><?= $r['code'] ?: 'ERR' ?></span> <span class="small"><?= htmlspecialchars(t_why($r)) ?></span><?php endif; ?></td><td class="text-end small"><?= $r['ms'] ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="mb-0 fw-semibold <?= $okc ? 'text-success' : 'text-warning-emphasis' ?>"><?= $okc ? "$okc of " . count(T_MODELS) . ' models answer — the assistant will work with this key.' : 'The key is valid but no model answered right now (see messages above).' ?></p>
<?php endif; ?></div></div>
<?php endforeach; endif; ?>
<p class="small text-body-secondary">After a successful test, add working keys in <a href="user/ai_settings.php">Admin → AI &amp; payments</a>. Delete this file from the server when you are done.</p>
</main></body></html>
