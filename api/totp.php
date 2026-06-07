<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$raw    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $_GET['action'] ?? $raw['action'] ?? '';
$me     = current_user();
$pdo    = db();

// ── GET status ──────────────────────────────────────────────────────────────
if ($method === 'GET' && $action === 'status') {
    $stmt = $pdo->prepare('SELECT totp_enabled, totp_secret FROM users WHERE id = ?');
    $stmt->execute([$me['id']]);
    $row = $stmt->fetch();
    json_success([
        'enabled'    => (bool)($row['totp_enabled'] ?? false),
        'configured' => !empty($row['totp_secret']),
        'policy'     => app_setting('totp_policy', 'disabled'),
    ]);
}

// ── GET setup — generate new secret + QR URI (no commit yet) ────────────────
if ($method === 'GET' && $action === 'setup') {
    $secret = totp_generate_secret();
    $_SESSION['totp_setup_secret'] = $secret;
    json_success([
        'secret' => $secret,
        'uri'    => totp_uri($me['username'], $secret),
    ]);
}

// ── POST activate — verify code then save ────────────────────────────────────
if ($method === 'POST' && $action === 'activate') {
    $code   = preg_replace('/\D/', '', $raw['code'] ?? '');
    $secret = $_SESSION['totp_setup_secret'] ?? '';
    if (!$secret) json_error('Session expirée, recommencez la configuration');
    if (!totp_verify($secret, $code)) json_error('Code incorrect, réessayez');

    $backup = totp_generate_backup_codes();
    $pdo->prepare('UPDATE users SET totp_secret=?, totp_enabled=1 WHERE id=?')
        ->execute([$secret, $me['id']]);
    totp_store_backup_codes((int)$me['id'], $backup);
    unset($_SESSION['totp_setup_secret']);

    // Refresh session user
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$me['id']]);
    $_SESSION['user'] = $stmt->fetch();

    json_success(['backup_codes' => $backup], 'TOTP activé');
}

// ── POST disable — désactiver TOTP (self ou superadmin sur n'importe quel user) ──
if ($method === 'POST' && $action === 'disable') {
    $target_id = (int)($raw['user_id'] ?? $me['id']);
    if ($target_id !== (int)$me['id'] && !is_superadmin()) json_error('Accès refusé', 403);

    $pdo->prepare('UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_backup_codes=NULL WHERE id=?')
        ->execute([$target_id]);

    if ($target_id === (int)$me['id']) {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$me['id']]);
        $_SESSION['user'] = $stmt->fetch();
    }
    json_success([], 'TOTP désactivé');
}

// ── POST regen-backup — régénérer les codes de secours ──────────────────────
if ($method === 'POST' && $action === 'regen-backup') {
    $target_id = (int)($raw['user_id'] ?? $me['id']);
    if ($target_id !== (int)$me['id'] && !is_superadmin()) json_error('Accès refusé', 403);

    $stmt = $pdo->prepare('SELECT totp_enabled FROM users WHERE id = ?');
    $stmt->execute([$target_id]);
    $row = $stmt->fetch();
    if (!$row || !$row['totp_enabled']) json_error('TOTP non activé sur ce compte');

    $backup = totp_generate_backup_codes();
    totp_store_backup_codes($target_id, $backup);
    json_success(['backup_codes' => $backup], 'Codes de secours régénérés');
}

json_error('Action non reconnue', 404);
