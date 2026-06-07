<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if (!is_superadmin()) json_error('Accès réservé au super-admin', 403);

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

if ($method === 'GET') {
    $rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
    json_success(array_column($rows, 'setting_value', 'setting_key'));
}

if ($method === 'POST') {
    $raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $allowed = [
        'app_name', 'app_url', 'theme', 'admin_email', 'mail_from_name',
        'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_from_name',
        'notif_backup_error', 'backup_kw_success', 'backup_kw_failure',
        // TOTP
        'totp_policy',
        // LDAP
        'ldap_enabled', 'ldap_host', 'ldap_port', 'ldap_base_dn',
        'ldap_bind_dn', 'ldap_bind_pass', 'ldap_user_filter',
        'ldap_use_tls', 'ldap_cert_verify', 'ldap_cert_content',
    ];

    foreach ($allowed as $key) {
        if (!array_key_exists($key, $raw)) continue;
        $value = trim((string)$raw[$key]);

        if ($key === 'app_name'     && $value === '') json_error('Le nom ne peut pas être vide');
        if ($key === 'theme'        && !in_array($value, ['dark','acid','aurora'])) json_error('Thème invalide');
        if ($key === 'totp_policy'  && !in_array($value, ['disabled','optional','required_all'])) json_error('Politique TOTP invalide');
        if ($key === 'ldap_enabled' && !in_array($value, ['0','1'])) json_error('Valeur ldap_enabled invalide');
        if ($key === 'app_url') {
            $value = rtrim($value, '/');
            if ($value && !preg_match('#^https?://#', $value)) json_error('URL invalide (doit commencer par http:// ou https://)');
        }

        // Persist cert content also to file for ldap extension
        if ($key === 'ldap_cert_content' && $value) {
            $cert_path = __DIR__ . '/../config/ldap_ca.pem';
            file_put_contents($cert_path, $value);
        }

        $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }

    unset($_SESSION['_app_settings']);
    session_write_close();

    audit_log('settings_update', 'settings', null);
    json_success([], 'Paramètres sauvegardés');
}

json_error('Méthode non supportée', 405);
