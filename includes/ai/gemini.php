<?php
// includes/ai/gemini.php — Gemini client built to ANSWER, FAST, even when most keys are dead.
//
//  * Health memory (table ai_gem_state): a key that is invalid / suspended / not offered the model is skipped with ZERO network calls
//    until its block expires. Rate limits (429) block only that key+model for the time Google says. Overloaded models (503) are paused briefly.
//    Times are stored as unix seconds, so PHP/MySQL timezone differences can never make a block "stick" or "vanish".
//  * Hedged race: the best key+model is called first; if it errors the next one starts INSTANTLY, and if it is merely slow a second
//    one starts after a short delay. The first good answer wins and the rest are cancelled. Up to 3 calls fly at once.
//  * Models are discovered from Google (ListModels) every 12 h, so a retired model (like gemini-2.5-flash for new keys) drops out by itself
//    and a new one (gemini-3.8-flash) is picked up with no code change. A built-in list is the fallback.
//  * Thinking is switched off/minimal for speed; if a model refuses that setting we silently retry without it and remember.
// Keys: config gemini_keys (JSON list, managed in AI & payments), older gemini_key_1..3, or env GEMINI_API_KEYS.

require_once __DIR__ . '/log.php';

const GEMINI_FALLBACK_MODELS = ['gemini-flash-latest', 'gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3-flash-preview', 'gemini-2.5-flash', 'gemini-3.1-flash-lite', 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest'];
const GEMINI_MODELS = GEMINI_FALLBACK_MODELS;           // kept for older pages (test_gemini.php) that still reference it
const GEMINI_MAX_PARALLEL = 3;                           // calls in flight at once
const GEMINI_HEDGE_SECS = 3.0;                           // start another call if the first has not answered after this long
const GEMINI_CALL_TIMEOUT = 20;                          // one HTTP call gives up after this many seconds
const GEMINI_BUDGET_SECS = 26;                           // whole answer budget (the page limit is 110 s)

/* ------------------------------------------------------------------ keys */
function gemini_keys(): array {
    $list = [];
    $add = function ($v) use (&$list) { $v = trim((string)$v); if ($v !== '' && !in_array($v, $list, true)) $list[] = $v; };
    $j = json_decode((string)get_config('gemini_keys', '[]'), true); if (is_array($j)) foreach ($j as $v) $add($v);
    foreach ([1, 2, 3] as $i) $add(get_config("gemini_key_$i", ''));
    foreach (explode(',', (string)getenv('GEMINI_API_KEYS')) as $v) $add($v);
    $k = []; foreach ($list as $v) $k[sprintf('%u', crc32($v))] = $v;     // id used by the settings page (remove checkbox)
    return $k;
}
function gemini_enabled(): bool { return get_config('ai_enabled', '1') === '1' && (bool)gemini_keys(); }
function gemini_kid(string $key): string { return substr(sha1($key), 0, 12); }

/* ------------------------------------------------------------------ health state */
function gemini_state_ready(PDO $pdo): bool {
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_gem_state (
            kid CHAR(12) NOT NULL, model VARCHAR(64) NOT NULL, day CHAR(10) NOT NULL DEFAULT '', used INT NOT NULL DEFAULT 0,
            until_ts INT NOT NULL DEFAULT 0, why VARCHAR(160) NOT NULL DEFAULT '', ok_ts INT NOT NULL DEFAULT 0, ms INT NOT NULL DEFAULT 0, fails INT NOT NULL DEFAULT 0,
            PRIMARY KEY (kid, model)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}
/** All state rows as "kid|model" => row. kid '*' = whole key blocked, model '*' = whole key; kid '-' = the model is paused for every key. */
function gemini_load_state(PDO $pdo, bool $fresh = false): array {
    static $cache = null; if ($cache !== null && !$fresh) return $cache; $cache = [];
    if (!gemini_state_ready($pdo)) return $cache;
    try { foreach ($pdo->query("SELECT * FROM ai_gem_state")->fetchAll() as $r) $cache[$r['kid'] . '|' . $r['model']] = $r; } catch (Throwable $e) {}
    return $cache;
}
function gemini_block(PDO $pdo, string $kid, string $model, int $secs, string $why): void {
    $until = time() + max(5, $secs);
    if (!gemini_state_ready($pdo)) return;
    try {
        $pdo->prepare("INSERT INTO ai_gem_state (kid,model,day,until_ts,why,fails) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE until_ts=VALUES(until_ts), why=VALUES(why), fails=fails+1")
            ->execute([$kid, $model, date('Y-m-d'), $until, mb_substr($why, 0, 160)]);
        gemini_load_state($pdo, true);
    } catch (Throwable $e) {}
}
function gemini_good(PDO $pdo, string $kid, string $model, int $ms): void {
    if (!gemini_state_ready($pdo)) return;
    try {
        $pdo->prepare("INSERT INTO ai_gem_state (kid,model,day,used,ok_ts,ms) VALUES (?,?,?,1,?,?) ON DUPLICATE KEY UPDATE used=IF(day=VALUES(day),used+1,1), day=VALUES(day), ok_ts=VALUES(ok_ts), ms=VALUES(ms), fails=0, until_ts=0, why=''")
            ->execute([$kid, $model, date('Y-m-d'), time(), $ms]);
        gemini_load_state($pdo, true);
    } catch (Throwable $e) {}
}
function gemini_reset_state(PDO $pdo): void { if (gemini_state_ready($pdo)) { try { $pdo->exec("UPDATE ai_gem_state SET until_ts=0, why='', fails=0"); $pdo->exec("DELETE FROM ai_gem_state WHERE kid IN ('*','-')"); } catch (Throwable $e) {} gemini_load_state($pdo, true); } }
function gemini_clear_key(PDO $pdo, string $key): void { if (gemini_state_ready($pdo)) { try { $pdo->prepare("UPDATE ai_gem_state SET until_ts=0, why='', fails=0 WHERE kid=?")->execute([gemini_kid($key)]); } catch (Throwable $e) {} gemini_load_state($pdo, true); } }
/** Seconds this key+model is blocked for (0 = usable) and the reason. */
function gemini_blocked(array $st, string $kid, string $model): array {
    $now = time(); $best = [0, ''];
    foreach (["$kid|$model", "$kid|*", "-|$model"] as $k) { $r = $st[$k] ?? null; if ($r && (int)$r['until_ts'] > $now && (int)$r['until_ts'] - $now > $best[0]) $best = [(int)$r['until_ts'] - $now, (string)$r['why']]; }
    return $best;
}

/* ------------------------------------------------------------------ models */
function gemini_models(PDO $pdo, bool $refreshNow = false): array {
    static $mem = null; if ($mem !== null && !$refreshNow) return $mem;
    $c = json_decode((string)get_config('gemini_models_cache', ''), true); $now = time();
    $list = (is_array($c) && !empty($c['list']) && is_array($c['list'])) ? $c['list'] : [];
    $stale = !$list || $now - (int)($c['t'] ?? 0) > 12 * 3600;
    $lastTry = (int)get_config('gemini_models_try', '0');
    if (($stale && $now - $lastTry > 900) || $refreshNow) {
        set_config('gemini_models_try', (string)$now);
        $found = gemini_discover($pdo);
        if ($found) { $list = $found; set_config('gemini_models_cache', json_encode(['t' => $now, 'list' => $found])); ai_log('info', 'models', 'Model list refreshed from Google: ' . implode(', ', $found)); }
        elseif (!$list) ai_log('warn', 'models', 'Could not read the model list from Google; using the built-in list.');
    }
    return $mem = ($list ?: GEMINI_FALLBACK_MODELS);
}
/** Ask Google which flash models this project can call. Returns names best-first, or [] on failure. */
function gemini_discover(PDO $pdo): array {
    $st = gemini_load_state($pdo); $keys = gemini_keys(); $now = time(); $try = [];
    foreach ($keys as $k) { if (gemini_blocked($st, gemini_kid($k), '*')[0] === 0) $try[] = $k; }
    $base = rtrim((string)(get_config('gemini_endpoint', '') ?: 'https://generativelanguage.googleapis.com/v1beta'), '/');
    foreach (array_slice($try, 0, 3) as $key) {
        $ch = curl_init("$base/models?pageSize=200"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $key]]);
        $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = $raw ? json_decode($raw, true) : null; if ($code !== 200 || empty($j['models'])) continue;
        $sc = [];
        foreach ($j['models'] as $m) {
            $n = preg_replace('#^models/#', '', (string)($m['name'] ?? '')); if (!in_array('generateContent', (array)($m['supportedGenerationMethods'] ?? []), true)) continue;
            if (!preg_match('/^gemini-/', $n) || preg_match('/image|tts|live|audio|native|embed|thinking|exp|vision|robotics|computer|custom|pro|nano|banana|gemma|learnlm|aqa|8b/i', $n)) continue;
            if ($n === 'gemini-flash-latest') { $sc[$n] = 900; continue; } if ($n === 'gemini-flash-lite-latest') { $sc[$n] = 40; continue; }
            if (!preg_match('/^gemini-(\d+(?:\.\d+)?)-flash(-lite)?(-preview.*)?$/', $n, $mm)) continue;
            $sc[$n] = (float)$mm[1] * 100 + (empty($mm[2]) ? 30 : 0) - (empty($mm[3]) ? 0 : 8);       // newer first, full flash before lite, stable before preview
        }
        arsort($sc); if ($sc) return array_slice(array_keys($sc), 0, 7);
    }
    return [];
}

/** thinking setting per model family; $off = the model refused it before, so send none. */
function gemini_think_cfg(string $model, bool $off): ?array {
    if ($off) return null;
    if (preg_match('/^gemini-2\.5-flash/', $model)) return ['thinkingBudget' => 0];
    if (preg_match('/^gemini-(\d+)/', $model, $m) && (int)$m[1] >= 3) return ['thinkingLevel' => 'minimal'];
    if (strpos($model, '-latest') !== false) return ['thinkingLevel' => 'minimal'];
    return null;
}
function gemini_body(string $model, array $contents, string $system, array $decls, bool $noThink): string {
    $gc = ['temperature' => 0.3, 'maxOutputTokens' => 4096]; $tc = gemini_think_cfg($model, $noThink); if ($tc) $gc['thinkingConfig'] = $tc;
    $b = ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => $contents, 'generationConfig' => $gc];
    if ($decls) $b['tools'] = [['functionDeclarations' => $decls]];
    $j = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); return $j === false ? '{}' : $j;
}

/* ------------------------------------------------------------------ HTTP */
function gemini_curl(string $model, string $key, string $json, string $mode, int $timeout) {
    $base = rtrim((string)(get_config('gemini_endpoint', '') ?: 'https://generativelanguage.googleapis.com/v1beta'), '/');
    $url = "$base/models/" . rawurlencode($model) . ':generateContent' . ($mode === 'q' ? '?key=' . rawurlencode($key) : '');
    $h = ['Content-Type: application/json']; if ($mode !== 'q') $h[] = 'x-goog-api-key: ' . $key;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_HTTPHEADER => $h, CURLOPT_POSTFIELDS => $json, CURLOPT_ENCODING => '', CURLOPT_TCP_NODELAY => true]);
    return $ch;
}
/** One blocking call (used by key tests and old pages). Returns [httpCode, decodedBody|null]. */
function gemini_http(string $model, string $key, array $body): array {
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'; $res = null; $code = 0; $cerr = '';
    foreach (['h', 'q'] as $mode) {
        $ch = gemini_curl($model, $key, $json, $mode, 25); $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
        $res = $raw ? json_decode($raw, true) : null; if ($code === 200 || $code === 429 || !in_array($code, [400, 401, 403], true)) break;
    }
    $GLOBALS['gemini_last_error'] = $code === 200 ? ($res['candidates'][0]['finishReason'] ?? '') : ($cerr ?: ($res['error']['message'] ?? ('HTTP ' . $code)));
    return [$code, $res];
}
function gemini_err_text($res, int $code, string $cerr = ''): string { return (string)($res['error']['message'] ?? ($cerr ?: ('HTTP ' . $code))); }
function gemini_retry_secs(string $msg): int {
    if (preg_match('/retry in (?:(\d+)h)?(?:(\d+)m)?(?:([\d.]+)s)?/i', $msg, $m)) { $s = (int)($m[1] ?? 0) * 3600 + (int)($m[2] ?? 0) * 60 + (int)ceil((float)($m[3] ?? 0)); if ($s > 0) return max(20, min(8 * 3600, $s + 3)); }
    return preg_match('/per day|daily|PerDay/i', $msg) ? 3 * 3600 : 60;
}
function gemini_ok_reply($res): bool { return !empty($res['candidates'][0]['content']['parts']); }

/** Race the candidates. $cands = [['model','key','kid'], ...] best first. Returns the response array or null. */
function gemini_race(PDO $pdo, array $cands, array $contents, string $system, array $decls, float $budget, string $label = ''): ?array {
    $t0 = microtime(true); $deadline = $t0 + $budget; $mh = curl_multi_init(); $fly = []; $next = 0; $nextAt = 0.0; $faults = 0; $win = null; $kb = round(strlen(gemini_body('x', $contents, $system, $decls, true)) / 1024, 1);
    $GLOBALS['gemini_nothink'] ??= []; $lastTick = $t0; $nt = count($decls);
    $launch = function (array $c, string $mode, bool $noThink) use (&$fly, $mh, $contents, $system, $decls) {
        $json = gemini_body($c['model'], $contents, $system, $decls, $noThink); $ch = gemini_curl($c['model'], $c['key'], $json, $mode, GEMINI_CALL_TIMEOUT); curl_multi_add_handle($mh, $ch);
        $fly[spl_object_id($ch)] = ['ch' => $ch, 'c' => $c, 'mode' => $mode, 'nt' => $noThink, 't' => microtime(true)];
    };
    while (true) {
        $now = microtime(true);
        if ($now > $deadline) break;
        if ($GLOBALS['ai_aborted'] ?? false) break;
        if (count($fly) < GEMINI_MAX_PARALLEL && $next < count($cands) && (!$fly || $now >= $nextAt)) {
            $c = $cands[$next++]; $launch($c, 'h', !empty($GLOBALS['gemini_nothink'][$c['model']])); $nextAt = $now + GEMINI_HEDGE_SECS;
        }
        if (!$fly) { if ($next >= count($cands)) break; continue; }
        curl_multi_exec($mh, $running); if (curl_multi_select($mh, 0.1) === -1) usleep(20000);
        if ($now - $lastTick > 1.2 && !empty($GLOBALS['ai_tick'])) { $lastTick = $now; ($GLOBALS['ai_tick'])(); }
        while ($done = curl_multi_info_read($mh)) {
            $ch = $done['handle']; $f = $fly[spl_object_id($ch)] ?? null; if (!$f) { curl_multi_remove_handle($mh, $ch); curl_close($ch); continue; }
            unset($fly[spl_object_id($ch)]); $raw = curl_multi_getcontent($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch);
            curl_multi_remove_handle($mh, $ch); curl_close($ch);
            $ms = (int)round((microtime(true) - $f['t']) * 1000); $c = $f['c']; $res = $raw ? json_decode($raw, true) : null; $err = gemini_err_text($res, $code, $cerr);
            $info = ['model' => $c['model'], 'key' => $c['key'], 'code' => $code, 'ms' => $ms];
            if ($code === 200 && gemini_ok_reply($res)) {
                ai_log('info', 'attempt', 'OK · ' . $label . 'request ' . $kb . ' KB · ' . $nt . ' tools · finish ' . ($res['candidates'][0]['finishReason'] ?? '?') . ($f['mode'] === 'q' ? ' · key sent in URL' : ''), $info);
                gemini_good($pdo, $c['kid'], $c['model'], $ms); $win = $res; $GLOBALS['gemini_meta'] = ['model' => $c['model'], 'ms' => $ms]; break 2;
            }
            if ($code === 200) {                                         // blocked or empty answer: the request is fine, this attempt just produced nothing
                $why = 'finishReason=' . ($res['candidates'][0]['finishReason'] ?? '-') . ' promptFeedback=' . json_encode($res['promptFeedback'] ?? null);
                ai_log('warn', 'empty_reply', 'HTTP 200 but no content · ' . $why, $info); $GLOBALS['gemini_errors'][] = $c['model'] . ': empty reply (' . $why . ')'; $nextAt = 0; continue;
            }
            $short = mb_substr($err, 0, 200); $keyBad = in_array($code, [400, 401, 403], true) && preg_match('/API key|permission|denied|disabled|suspended|billing|not valid|expired|unauthenticated/i', $err);
            if ($keyBad && $f['mode'] === 'h') {                         // some keys only answer when the key is sent in the URL: try that before judging the key
                ai_log('warn', 'attempt', "HTTP $code · $short · retrying with the key in the URL", $info); $launch($c, 'q', $f['nt']); continue;
            }
            $fault = $code === 400 && !$keyBad;
            ai_log($fault ? 'error' : 'warn', $fault ? 'rejected_request' : 'attempt', ($fault ? 'Google rejected the REQUEST (not the key): ' : '') . "HTTP $code · $short · request {$kb} KB · $nt tools", $info);
            $GLOBALS['gemini_errors'][] = $c['model'] . ': HTTP ' . $code . ' ' . mb_substr($err, 0, 160);
            if ($keyBad) gemini_block($pdo, $c['kid'], '*', 6 * 3600, 'key rejected: ' . mb_substr($err, 0, 80));
            elseif ($code === 429) gemini_block($pdo, $c['kid'], $c['model'], gemini_retry_secs($err), 'rate limit: ' . mb_substr($err, 0, 60));
            elseif ($code === 404) gemini_block($pdo, $c['kid'], $c['model'], 6 * 3600, 'model not offered to this key');
            elseif ($code >= 500) gemini_block($pdo, '-', $c['model'], 20, "HTTP $code overloaded");
            elseif ($code === 0) gemini_block($pdo, $c['kid'], $c['model'], 25, 'no answer / timeout');
            elseif ($fault) {
                if (preg_match('/thinking/i', $err) && !$f['nt']) { $GLOBALS['gemini_nothink'][$c['model']] = true; $launch($c, $f['mode'], true); continue; }   // this model dislikes the thinking setting
                $faults++; if ($faults >= 2) { $win = false; break 2; }                                                                                    // two different models both reject the request itself: stop hammering
            }
            $nextAt = 0;
        }
    }
    foreach ($fly as $f) { curl_multi_remove_handle($mh, $f['ch']); curl_close($f['ch']); }
    curl_multi_close($mh);
    return $win ?: null;
}

/** Build the ordered candidate list: the pair that answered most recently first, then by model order, then least-used key. */
function gemini_candidates($pdo, array $keys, array $models, array $st, bool $rescue = false): array {
    $now = time(); $out = []; $hot = null; $hotTs = 0;
    foreach ($st as $r) if ($r['model'] !== '*' && $r['kid'] !== '-' && (int)$r['ok_ts'] > $hotTs && $now - (int)$r['ok_ts'] < 900) { $hot = $r['kid'] . '|' . $r['model']; $hotTs = (int)$r['ok_ts']; }
    foreach ($models as $mi => $m) foreach ($keys as $k) {
        $kid = gemini_kid($k); [$left, $why] = gemini_blocked($st, $kid, $m);
        if ($left > 0) { $transient = $left <= 120 && !preg_match('/key rejected|not offered/i', $why); if (!$rescue || !$transient) continue; }
        elseif ($rescue) continue;
        $used = (int)(($st["$kid|$m"]['day'] ?? '') === date('Y-m-d') ? ($st["$kid|$m"]['used'] ?? 0) : 0);
        $out[] = ['model' => $m, 'key' => $k, 'kid' => $kid, 'mi' => $mi, 'used' => $used, 'hot' => ("$kid|$m" === $hot) ? 0 : 1];
    }
    usort($out, fn($a, $b) => [$a['hot'], $a['mi'], $a['used']] <=> [$b['hot'], $b['mi'], $b['used']]);
    // interleave so the first wave touches different KEYS (one bad key must not eat every early slot)
    $seen = []; $first = []; $rest = [];
    foreach ($out as $c) { if (!isset($seen[$c['kid']])) { $seen[$c['kid']] = 1; $first[] = $c; } else $rest[] = $c; }
    return array_merge(array_slice($first, 0, 4), $rest, array_slice($first, 4));
}

/** Ask Gemini. Returns the response array or null (details are in ai_log and $GLOBALS['gemini_errors']). */
function gemini_generate(PDO $pdo, array $contents, string $system, array $functionDecls = []): ?array {
    $keys = gemini_keys(); $GLOBALS['gemini_errors'] ??= [];
    if (!$keys) { ai_log('error', 'no_keys', 'No Gemini keys are saved, or the assistant is switched off in AI & payments.'); return null; }
    $t0 = microtime(true); $models = gemini_models($pdo); $st = gemini_load_state($pdo);
    $cands = gemini_candidates($pdo, $keys, $models, $st);
    $total = count($keys) * count($models); $skipped = $total - count($cands);
    if ($cands) {
        if ($skipped > 0) ai_log('info', 'skip', "Skipped $skipped of $total key+model pairs without calling Google (blocked: invalid key, quota or overloaded). Trying " . count($cands) . '.');
        $r = gemini_race($pdo, $cands, $contents, $system, $functionDecls, GEMINI_BUDGET_SECS); if ($r) return $r;
    }
    if (empty($GLOBALS['ai_aborted']) && ($left = GEMINI_BUDGET_SECS - (microtime(true) - $t0)) > 4) {        // rescue pass: short-lived blocks (overload, rate limit under 2 min) are retried once
        $resc = gemini_candidates($pdo, $keys, $models, gemini_load_state($pdo, true), true);
        if ($resc) { ai_log('info', 'rescue', 'Nothing answered. Retrying ' . count($resc) . ' short-lived blocked key+model pair(s) once.'); $r = gemini_race($pdo, array_slice($resc, 0, 6), $contents, $system, $functionDecls, $left, 'rescue · '); if ($r) return $r; }
    }
    if (!$cands) ai_log('error', 'no_candidates', "Every one of $total key+model pairs is blocked right now (see AI log → key health). No call was made.");
    ai_log('error', 'exhausted', 'No model answered after ' . (int)round((microtime(true) - $t0) * 1000) . ' ms. Request ' . round(strlen(gemini_body('x', $contents, $system, $functionDecls, true)) / 1024, 1) . ' KB, ' . count($functionDecls) . ' tools.');
    return null;
}

/** Test one key on its own. Returns [ok, human message]. A working test also clears any block the key was given. */
function gemini_test_key(string $key): array {
    global $pdo; $rate = false; $last = 'no model answered'; $models = isset($pdo) ? gemini_models($pdo) : GEMINI_FALLBACK_MODELS;
    foreach (array_slice($models, 0, 5) as $m) {
        [$c, $r] = gemini_http($m, $key, ['contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with OK']]]], 'generationConfig' => ['maxOutputTokens' => 64]]);
        if ($c === 200) { if (isset($pdo)) gemini_clear_key($pdo, $key); return [true, "works ($m)"]; }
        $msg = (string)($r['error']['message'] ?? ($GLOBALS['gemini_last_error'] ?? "HTTP $c")); $last = "HTTP $c: " . mb_substr($msg, 0, 160);
        if ($c === 0) return [false, mb_substr((string)($GLOBALS['gemini_last_error'] ?? 'could not reach Google'), 0, 200)];
        if ($c === 403 || $c === 401 || ($c === 400 && stripos($msg, 'API key') !== false)) return [false, $last];
        if ($c === 429) $rate = true;
    }
    return $rate ? [true, 'key is valid but rate-limited right now'] : [false, $last];
}
