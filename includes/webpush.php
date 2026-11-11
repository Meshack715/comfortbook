<?php
// includes/webpush.php — dependency-free Web Push (RFC 8030 + VAPID RFC 8292 + aes128gcm RFC 8291).
// Needs only PHP's openssl and curl extensions (both ship with XAMPP).

function wp_b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function wp_b64u_dec(string $s): string { return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** DER ECDSA signature → raw r||s (64 bytes) as JWT ES256 needs. */
function wp_der_to_raw(string $der): string {
    $o = 3; $rl = ord($der[$o]); $r = substr($der, $o + 1, $rl); $o += 1 + $rl + 1; $sl = ord($der[$o]); $s = substr($der, $o + 1, $sl);
    $r = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT); $s = str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    return $r . $s;
}
function wp_pub_pem(string $point65): string {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point65;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}
function wp_point(OpenSSLAsymmetricKey $k): string {
    $d = openssl_pkey_get_details($k)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}
function wp_new_key(): OpenSSLAsymmetricKey {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) throw new RuntimeException('OpenSSL cannot create EC keys');
    return $k;
}

/** Create and store VAPID keys once. Returns [publicB64u, privatePem]. */
function wp_vapid_keys(PDO $pdo): array {
    $pub = get_config('vapid_public', ''); $pem = get_config('vapid_private', '');
    if ($pub && $pem) return [$pub, $pem];
    $k = wp_new_key(); openssl_pkey_export($k, $pem); $pub = wp_b64u(wp_point($k));
    foreach (['vapid_public' => $pub, 'vapid_private' => $pem] as $key => $val)
        $pdo->prepare("INSERT INTO config (config_key, config_value) VALUES (?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)")->execute([$key, $val]);
    return [$pub, $pem];
}

/** RFC 8291 encryption. $eph / $salt are injectable for tests. */
function wp_encrypt(string $payload, string $uaPublic, string $authSecret, ?OpenSSLAsymmetricKey $eph = null, ?string $salt = null): string {
    $eph = $eph ?: wp_new_key(); $asPublic = wp_point($eph); $salt = $salt ?? random_bytes(16);
    $peer = openssl_pkey_get_public(wp_pub_pem($uaPublic));
    $secret = openssl_pkey_derive($peer, $eph, 32);
    if ($secret === false) throw new RuntimeException('ECDH failed');
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    return $salt . pack('N', 4096) . chr(65) . $asPublic . $ct . $tag;
}

function wp_vapid_header(PDO $pdo, string $endpoint): string {
    [$pub, $pem] = wp_vapid_keys($pdo);
    $u = parse_url($endpoint); $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $h = wp_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])); $c = wp_b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . (defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : 'admin@example.com')]));
    openssl_sign("$h.$c", $der, openssl_pkey_get_private($pem), OPENSSL_ALGO_SHA256);
    return 'vapid t=' . "$h.$c." . wp_b64u(wp_der_to_raw($der)) . ', k=' . $pub;
}

/** Send one push. Returns HTTP status (201 = queued; 404/410 = subscription is gone). */
function wp_send(PDO $pdo, array $sub, array $payload, int $ttl = 86400): int {
    $body = wp_encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE), wp_b64u_dec($sub['p256dh']), wp_b64u_dec($sub['auth']));
    $ch = curl_init($sub['endpoint']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: ' . $ttl, 'Urgency: high', 'Authorization: ' . wp_vapid_header($pdo, $sub['endpoint'])]]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return $code;
}
