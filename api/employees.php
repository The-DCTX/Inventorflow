<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$client_id = current_client_id();
$raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'POST') {
    $first = trim($raw['first_name'] ?? '');
    $last  = trim($raw['last_name']  ?? '');
    if (!$first || !$last) json_error('Prénom et nom obligatoires');

    $stmt = $pdo->prepare('INSERT INTO employees (client_id, first_name, last_name, email, phone, department_id, position, active) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $client_id, $first, $last,
        $raw['email'] ?: null, $raw['phone'] ?: null,
        $raw['department_id'] ?: null, $raw['position'] ?: null,
        isset($raw['active']) ? (int)$raw['active'] : 1,
    ]);
    json_success(['id' => (int)$pdo->lastInsertId()], 'Utilisateur créé');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $check = $pdo->prepare('SELECT id, active FROM employees WHERE id=? AND client_id=?');
    $check->execute([$id, $client_id]);
    $old_emp = $check->fetch();
    if (!$old_emp) json_error('Utilisateur introuvable', 404);

    $new_active = (int)($raw['active'] ?? 1);
    $was_active = (int)$old_emp['active'];

    $pdo->prepare('UPDATE employees SET first_name=?, last_name=?, email=?, phone=?, department_id=?, position=?, active=? WHERE id=? AND client_id=?')
        ->execute([
            trim($raw['first_name'] ?? ''), trim($raw['last_name'] ?? ''),
            $raw['email'] ?: null, $raw['phone'] ?: null,
            $raw['department_id'] ?: null, $raw['position'] ?: null,
            $new_active, $id, $client_id,
        ]);

    // Cascade quand on désactive un employé actif
    if ($was_active === 1 && $new_active === 0) {
        _offboard_employee($pdo, $id, 'Désactivation utilisateur');
    }

    json_success([], 'Utilisateur mis à jour');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $check = $pdo->prepare('SELECT id FROM employees WHERE id=? AND client_id=?');
    $check->execute([$id, $client_id]);
    if (!$check->fetch()) json_error('Utilisateur introuvable', 404);

    _offboard_employee($pdo, $id, 'Suppression utilisateur');
    $pdo->prepare('DELETE FROM employees WHERE id=? AND client_id=?')->execute([$id, $client_id]);
    json_success([], 'Utilisateur supprimé');
}

// Action d'offboarding depuis le wizard dédié
if ($method === 'PATCH') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $check = $pdo->prepare('SELECT id FROM employees WHERE id=? AND client_id=?');
    $check->execute([$id, $client_id]);
    if (!$check->fetch()) json_error('Utilisateur introuvable', 404);

    // assets: [{ asset_id, action: 'stock'|'reassign', reassign_to }]
    $asset_actions = $raw['assets'] ?? [];
    foreach ($asset_actions as $aa) {
        $asset_id = (int)($aa['asset_id'] ?? 0);
        if (!$asset_id) continue;
        if (($aa['action'] ?? '') === 'reassign' && !empty($aa['reassign_to'])) {
            $new_owner = (int)$aa['reassign_to'];
            $pdo->prepare('UPDATE assets SET assigned_to=?, status="active" WHERE id=? AND client_id=?')
                ->execute([$new_owner, $asset_id, $client_id]);
            log_asset_action($asset_id, 'assigned', (string)$id, (string)$new_owner, 'Réassignation offboarding');
        } else {
            $pdo->prepare('UPDATE assets SET assigned_to=NULL, status="stock" WHERE id=? AND client_id=?')
                ->execute([$asset_id, $client_id]);
            log_asset_action($asset_id, 'unassigned', (string)$id, null, 'Retour en stock — offboarding');
            log_asset_action($asset_id, 'status_change', 'active', 'stock', 'Retour en stock — offboarding');
        }
    }

    // licenses: [{ assignment_id, license_id, action: 'revoke'|'keep'|'transfer', transfer_to }]
    $lic_actions = $raw['licenses'] ?? [];
    foreach ($lic_actions as $la) {
        $asgn_id = (int)($la['assignment_id'] ?? 0);
        $lic_id  = (int)($la['license_id'] ?? 0);
        if (!$asgn_id) continue;
        if (($la['action'] ?? '') === 'transfer' && !empty($la['transfer_to'])) {
            $pdo->prepare('UPDATE license_assignments SET employee_id=?, revoked_at=NULL WHERE id=?')
                ->execute([(int)$la['transfer_to'], $asgn_id]);
        } elseif (($la['action'] ?? '') === 'revoke') {
            $pdo->prepare('UPDATE license_assignments SET active=0, revoked_at=CURDATE() WHERE id=?')
                ->execute([$asgn_id]);
            if ($lic_id) {
                $pdo->prepare('UPDATE licenses SET used_seats=(SELECT COUNT(*) FROM license_assignments WHERE license_id=? AND active=1) WHERE id=?')
                    ->execute([$lic_id, $lic_id]);
            }
        }
        // 'keep' = no action
    }

    // Désactiver l'employé
    $pdo->prepare('UPDATE employees SET active=0 WHERE id=? AND client_id=?')->execute([$id, $client_id]);

    json_success([], 'Offboarding effectué');
}

/**
 * Libère les ressources liées à un employé (assets → stock, licences → révoquées).
 * Appelé sur désactivation ET suppression.
 */
function _offboard_employee(PDO $pdo, int $emp_id, string $reason): void {
    // 1. Assets → stock
    $assets = $pdo->prepare('SELECT id FROM assets WHERE assigned_to=?');
    $assets->execute([$emp_id]);
    foreach ($assets->fetchAll() as $a) {
        $pdo->prepare('UPDATE assets SET assigned_to=NULL, status="stock" WHERE id=?')->execute([$a['id']]);
        log_asset_action($a['id'], 'unassigned', (string)$emp_id, null, $reason);
        log_asset_action($a['id'], 'status_change', 'active', 'stock', $reason);
    }

    // 2. Licences → révoquées (soft delete pour garder l'historique)
    $lics = $pdo->prepare('SELECT id, license_id FROM license_assignments WHERE employee_id=? AND active=1');
    $lics->execute([$emp_id]);
    $affected_lics = [];
    foreach ($lics->fetchAll() as $la) {
        $pdo->prepare('UPDATE license_assignments SET active=0, revoked_at=CURDATE() WHERE id=?')
            ->execute([$la['id']]);
        $affected_lics[] = (int)$la['license_id'];
    }

    // 3. Recalculer used_seats pour chaque licence affectée
    foreach (array_unique($affected_lics) as $lic_id) {
        $pdo->prepare('UPDATE licenses SET used_seats=(SELECT COUNT(*) FROM license_assignments WHERE license_id=? AND active=1) WHERE id=?')
            ->execute([$lic_id, $lic_id]);
    }
}

json_error('Méthode non supportée', 405);
