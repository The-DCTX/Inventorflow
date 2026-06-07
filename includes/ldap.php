<?php
function ldap_get_settings(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows  = db()->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'ldap_%'")->fetchAll();
    $cache = array_column($rows, 'setting_value', 'setting_key');
    return $cache;
}

function ldap_build_connection(array $s): array {
    $host       = trim($s['ldap_host'] ?? '');
    $port       = (int)($s['ldap_port'] ?? 389);
    $use_tls    = ($s['ldap_use_tls']   ?? '0') === '1' && $port !== 636;
    $cert_verify = ($s['ldap_cert_verify'] ?? '1') !== '0';
    $scheme     = ($port === 636) ? 'ldaps' : 'ldap';
    $cert_path  = __DIR__ . '/../config/ldap_ca.pem';
    $cert_content = trim($s['ldap_cert_content'] ?? '');

    if (!$cert_verify) {
        putenv('LDAPTLS_REQCERT=never');
        ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_NEVER);
    } elseif ($cert_content) {
        file_put_contents($cert_path, $cert_content);
        ldap_set_option(null, LDAP_OPT_X_TLS_CACERTFILE, realpath($cert_path));
    }

    $conn = @ldap_connect("$scheme://$host:$port");
    if (!$conn) return [null, "Impossible de se connecter à $scheme://$host:$port"];

    ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($conn, LDAP_OPT_REFERRALS,        0);
    ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT,  5);

    if ($use_tls && !@ldap_start_tls($conn)) {
        return [null, 'StartTLS échoué — vérifiez le certificat et le port'];
    }

    return [$conn, null];
}

function ldap_authenticate(string $username, string $password): bool {
    if (!function_exists('ldap_connect') || !$password) return false;

    $s = ldap_get_settings();
    if (($s['ldap_enabled'] ?? '0') !== '1') return false;

    [$conn, $err] = ldap_build_connection($s);
    if (!$conn) return false;

    $base    = $s['ldap_base_dn']     ?? '';
    $filter  = str_replace('{username}',
        ldap_escape($username, '', LDAP_ESCAPE_FILTER),
        $s['ldap_user_filter'] ?? '(sAMAccountName={username})');
    $bindDn  = $s['ldap_bind_dn']    ?? '';
    $bindPw  = $s['ldap_bind_pass']  ?? '';

    // Find user DN via service account
    if ($bindDn && $bindPw) {
        if (!@ldap_bind($conn, $bindDn, $bindPw)) { @ldap_unbind($conn); return false; }
        $sr = @ldap_search($conn, $base, $filter, ['dn'], 0, 1);
        if (!$sr) { @ldap_unbind($conn); return false; }
        $entries = ldap_get_entries($conn, $sr);
        if ($entries['count'] < 1) { @ldap_unbind($conn); return false; }
        $userDn = $entries[0]['dn'];
    } else {
        $userDn = 'cn=' . ldap_escape($username, '', LDAP_ESCAPE_DN) . ',' . $base;
    }

    $ok = @ldap_bind($conn, $userDn, $password);
    @ldap_unbind($conn);
    return (bool)$ok;
}

function ldap_test_connection(): array {
    if (!function_exists('ldap_connect')) {
        return ['ok' => false, 'error' => 'Extension PHP ldap non installée (php-ldap manquant)'];
    }

    $s = ldap_get_settings();
    if (empty(trim($s['ldap_host'] ?? ''))) {
        return ['ok' => false, 'error' => 'Aucun hôte LDAP configuré'];
    }

    [$conn, $err] = ldap_build_connection($s);
    if (!$conn) return ['ok' => false, 'error' => $err];

    $bindDn = $s['ldap_bind_dn']   ?? '';
    $bindPw = $s['ldap_bind_pass'] ?? '';

    if ($bindDn) {
        $ok = @ldap_bind($conn, $bindDn, $bindPw);
        @ldap_unbind($conn);
        return $ok
            ? ['ok' => true,  'message' => 'Connexion et bind service account réussis']
            : ['ok' => false, 'error'   => 'Connexion OK mais bind service account échoué — vérifiez le DN/mot de passe'];
    }

    $ok = @ldap_bind($conn);
    @ldap_unbind($conn);
    return $ok
        ? ['ok' => true,  'message' => 'Connexion LDAP réussie (bind anonyme)']
        : ['ok' => false, 'error'   => 'Bind anonyme refusé — configurez un service account'];
}
