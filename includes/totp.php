<?php
function totp_base32_decode(string $s): string {
    $s     = strtoupper(preg_replace('/=+$/', '', trim($s)));
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buf = 0; $bits = 0; $out = '';
    foreach (str_split($s) as $c) {
        $v = strpos($alpha, $c);
        if ($v === false) continue;
        $buf   = ($buf << 5) | $v;
        $bits += 5;
        if ($bits >= 8) { $bits -= 8; $out .= chr(($buf >> $bits) & 0xFF); }
    }
    return $out;
}

function totp_generate_secret(int $len = 16): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = '';
    foreach (str_split(random_bytes($len)) as $b) $s .= $alpha[ord($b) & 31];
    return $s;
}

function totp_code(string $secret, int $ts = 0): string {
    $t    = intdiv($ts ?: time(), 30);
    $time = pack('N*', 0) . pack('N*', $t & 0xFFFFFFFF);
    $key  = totp_base32_decode($secret);
    $hmac = hash_hmac('sha1', $time, $key, true);
    $off  = ord($hmac[19]) & 0xf;
    $code = (
        ((ord($hmac[$off])   & 0x7f) << 24) |
        ((ord($hmac[$off+1]) & 0xff) << 16) |
        ((ord($hmac[$off+2]) & 0xff) <<  8) |
         (ord($hmac[$off+3]) & 0xff)
    ) % 1_000_000;
    return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $t = time();
    foreach ([-30, 0, 30] as $d) {
        if (hash_equals(totp_code($secret, $t + $d), $code)) return true;
    }
    return false;
}

function totp_generate_backup_codes(): array {
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(4)));
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
    }
    return $codes;
}

function totp_verify_backup(int $user_id, string $input): bool {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT totp_backup_codes FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row  = $stmt->fetch();
    if (!$row || !$row['totp_backup_codes']) return false;

    $hashes = json_decode($row['totp_backup_codes'], true) ?: [];
    $clean  = strtoupper(preg_replace('/[^A-F0-9]/i', '', $input));
    $needle = substr($clean, 0, 4) . '-' . substr($clean, 4, 4);

    foreach ($hashes as $i => $hash) {
        if (hash_equals($hash, hash('sha256', $needle))) {
            unset($hashes[$i]);
            $pdo->prepare('UPDATE users SET totp_backup_codes = ? WHERE id = ?')
                ->execute([json_encode(array_values($hashes)), $user_id]);
            return true;
        }
    }
    return false;
}

function totp_store_backup_codes(int $user_id, array $codes): void {
    $hashes = array_map(fn($c) => hash('sha256', $c), $codes);
    db()->prepare('UPDATE users SET totp_backup_codes = ? WHERE id = ?')
       ->execute([json_encode($hashes), $user_id]);
}

function totp_issuer(): string {
    return defined('APP_NAME') ? APP_NAME : 'InventorFlow';
}

function totp_uri(string $username, string $secret): string {
    return 'otpauth://totp/' . rawurlencode(totp_issuer() . ':' . $username)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode(totp_issuer())
         . '&digits=6&period=30';
}
