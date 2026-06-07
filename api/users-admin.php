<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if (!is_superadmin()) json_error('Accès réservé au superadmin', 403);

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();
$raw    = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT id, username, full_name, email, role, client_id, active, auth_source, totp_enabled, last_login, created_at FROM users ORDER BY role, username');
    json_success($stmt->fetchAll());
}

if ($method === 'POST') {
    $username    = trim($raw['username'] ?? '');
    $password    = $raw['password'] ?? '';
    $role        = in_array($raw['role'] ?? '', ['superadmin','admin','viewer']) ? $raw['role'] : 'admin';
    $auth_source = ($raw['auth_source'] ?? 'local') === 'ldap' ? 'ldap' : 'local';

    if (!$username) json_error('Nom d\'utilisateur obligatoire');
    if ($auth_source === 'local' && !$password) json_error('Mot de passe obligatoire pour un compte local');
    if ($auth_source === 'local' && strlen($password) < 8) json_error('Mot de passe trop court (8 caractères min)');

    $dup = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $dup->execute([$username]);
    if ($dup->fetch()) json_error('Ce nom d\'utilisateur est déjà pris');

    $client_id = ($role !== 'superadmin' && !empty($raw['client_id'])) ? (int)$raw['client_id'] : null;
    $hash      = ($auth_source === 'local' && $password) ? password_hash($password, PASSWORD_DEFAULT) : '';

    $pdo->prepare('INSERT INTO users (username, password, full_name, email, role, client_id, active, auth_source)
        VALUES (?,?,?,?,?,?,1,?)')
        ->execute([$username, $hash, trim($raw['full_name'] ?? ''), $raw['email'] ?: null, $role, $client_id, $auth_source]);

    audit_log('user_create', 'user', (int)$pdo->lastInsertId(), ['username' => $username, 'role' => $role]);
    json_success(['id' => (int)$pdo->lastInsertId()], 'Compte créé');
}

if ($method === 'PUT') {
    $id          = (int)($raw['id'] ?? 0);
    $role        = in_array($raw['role'] ?? '', ['superadmin','admin','viewer']) ? $raw['role'] : 'admin';
    $auth_source = ($raw['auth_source'] ?? 'local') === 'ldap' ? 'ldap' : 'local';
    if (!$id) json_error('ID manquant');

    $me = current_user();
    if ($id === (int)$me['id'] && isset($raw['active']) && !(int)$raw['active']) {
        json_error('Vous ne pouvez pas désactiver votre propre compte');
    }
    if (isset($raw['role']) && $raw['role'] !== 'superadmin') {
        $others = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role="superadmin" AND active=1 AND id != ?');
        $others->execute([$id]);
        if ((int)$others->fetchColumn() === 0) json_error('Il doit rester au moins un superadmin actif');
    }

    // TOTP reset action
    if (!empty($raw['totp_reset'])) {
        $pdo->prepare('UPDATE users SET totp_secret=NULL, totp_enabled=0, totp_backup_codes=NULL WHERE id=?')
            ->execute([$id]);
        audit_log('totp_reset', 'user', $id);
        json_success([], 'TOTP réinitialisé');
    }

    $client_id = ($role !== 'superadmin' && !empty($raw['client_id'])) ? (int)$raw['client_id'] : null;
    $active    = isset($raw['active']) ? (int)$raw['active'] : 1;

    $pdo->prepare('UPDATE users SET username=?, full_name=?, email=?, role=?, client_id=?, active=?, auth_source=? WHERE id=?')
        ->execute([trim($raw['username'] ?? ''), trim($raw['full_name'] ?? ''), $raw['email'] ?: null, $role, $client_id, $active, $auth_source, $id]);

    if (!empty($raw['password'])) {
        if (strlen($raw['password']) < 8) json_error('Mot de passe trop court (8 caractères min)');
        $pdo->prepare('UPDATE users SET password=? WHERE id=?')
            ->execute([password_hash($raw['password'], PASSWORD_DEFAULT), $id]);
    }

    audit_log('user_update', 'user', $id, ['role' => $role]);
    json_success([], 'Compte mis à jour');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $me = current_user();
    if ($id === (int)$me['id']) json_error('Vous ne pouvez pas supprimer votre propre compte');

    $target = $pdo->prepare('SELECT role FROM users WHERE id=?');
    $target->execute([$id]);
    $t = $target->fetch();
    if ($t && $t['role'] === 'superadmin') {
        $cnt = $pdo->query('SELECT COUNT(*) FROM users WHERE role="superadmin" AND active=1')->fetchColumn();
        if ((int)$cnt <= 1) json_error('Impossible : dernier superadmin');
    }

    $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    audit_log('user_delete', 'user', $id);
    json_success([], 'Compte supprimé');
}

json_error('Méthode non supportée', 405);
