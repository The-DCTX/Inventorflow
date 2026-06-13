<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method    = $_SERVER['REQUEST_METHOD'];
$pdo       = db();
$client_id = current_client_id();
$raw       = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'GET') {
    $license_id      = (int)($_GET['license_id'] ?? 0);
    $asset_id_filter = (int)($_GET['asset_id'] ?? 0);
    $employee_filter = (int)($_GET['employee_id'] ?? 0);
    $include_revoked = !empty($_GET['include_revoked']);

    // Licences d'un poste spécifique
    if ($asset_id_filter) {
        $sql = 'SELECT la.id, la.license_id, la.assigned_at, la.active, la.revoked_at,
            l.name as license_name, l.vendor, l.category,
            l.license_type, l.billing_period, l.cost_per_seat
            FROM license_assignments la
            JOIN licenses l ON la.license_id = l.id
            WHERE la.asset_id = ? AND l.client_id = ?';
        $params = [$asset_id_filter, $client_id];
        if (!$include_revoked) { $sql .= ' AND la.active = 1'; }
        $sql .= ' ORDER BY la.active DESC, l.category, l.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_success($stmt->fetchAll());
    }

    // Licences d'un employé spécifique
    if ($employee_filter) {
        $sql = 'SELECT la.id, la.license_id, la.assigned_at, la.active, la.revoked_at,
            l.name as license_name, l.vendor, l.category,
            l.license_type, l.billing_period, l.cost_per_seat
            FROM license_assignments la
            JOIN licenses l ON la.license_id = l.id
            WHERE la.employee_id = ? AND l.client_id = ?';
        $params = [$employee_filter, $client_id];
        if (!$include_revoked) { $sql .= ' AND la.active = 1'; }
        $sql .= ' ORDER BY la.active DESC, l.category, l.name';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        json_success($stmt->fetchAll());
    }

    if (!$license_id) json_error('license_id requis');
    $sql = 'SELECT la.*,
        a.hostname, a.os_type, a.model,
        CONCAT(e.first_name," ",e.last_name) as employee_name, e.position
        FROM license_assignments la
        JOIN licenses l ON la.license_id = l.id
        LEFT JOIN assets a ON la.asset_id = a.id
        LEFT JOIN employees e ON la.employee_id = e.id
        WHERE la.license_id = ? AND l.client_id = ?';
    $params = [$license_id, $client_id];
    if (!$include_revoked) { $sql .= ' AND la.active = 1'; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_success($stmt->fetchAll());
}

if ($method === 'POST') {
    $lic_id  = (int)($raw['license_id'] ?? 0);
    $asset   = $raw['asset_id'] ?: null;
    $emp     = $raw['employee_id'] ?: null;
    if (!$lic_id || (!$asset && !$emp)) json_error('license_id et asset_id ou employee_id requis');

    // Appartenance au client courant (anti-IDOR) : licence + ressource cible
    $own = $pdo->prepare('SELECT id FROM licenses WHERE id=? AND client_id=?');
    $own->execute([$lic_id, $client_id]);
    if (!$own->fetch()) json_error('Licence introuvable', 404);
    if ($asset) {
        $c = $pdo->prepare('SELECT id FROM assets WHERE id=? AND client_id=?');
        $c->execute([$asset, $client_id]);
        if (!$c->fetch()) json_error('Poste introuvable', 404);
    }
    if ($emp) {
        $c = $pdo->prepare('SELECT id FROM employees WHERE id=? AND client_id=?');
        $c->execute([$emp, $client_id]);
        if (!$c->fetch()) json_error('Employé introuvable', 404);
    }

    // Check seat availability (only count active assignments)
    $seats = $pdo->prepare('SELECT total_seats, (SELECT COUNT(*) FROM license_assignments WHERE license_id=l.id AND active=1) as used FROM licenses l WHERE id=?');
    $seats->execute([$lic_id]);
    $s = $seats->fetch();
    if ($s && $s['used'] >= $s['total_seats']) {
        json_error('Nombre de sièges maximum atteint ('.$s['total_seats'].' / '.$s['total_seats'].')', 409);
    }

    // Check for duplicate active assignment
    if ($asset) {
        $dup = $pdo->prepare('SELECT id FROM license_assignments WHERE license_id=? AND asset_id=? AND active=1');
        $dup->execute([$lic_id, $asset]);
        if ($dup->fetch()) json_error('Ce poste a déjà cette licence');
    }
    if ($emp) {
        $dup = $pdo->prepare('SELECT id FROM license_assignments WHERE license_id=? AND employee_id=? AND active=1');
        $dup->execute([$lic_id, $emp]);
        if ($dup->fetch()) json_error('Cet utilisateur a déjà cette licence');
    }

    $pdo->prepare('INSERT INTO license_assignments (license_id,asset_id,employee_id,assigned_at,notes,active) VALUES (?,?,?,?,?,1)')
        ->execute([$lic_id, $asset, $emp, $raw['assigned_at']??date('Y-m-d'), $raw['notes']??null]);

    $pdo->prepare('UPDATE licenses SET used_seats=(SELECT COUNT(*) FROM license_assignments WHERE license_id=? AND active=1) WHERE id=?')
        ->execute([$lic_id, $lic_id]);

    json_success(['id' => (int)$pdo->lastInsertId()], 'Licence attribuée');
}

if ($method === 'DELETE') {
    $id     = (int)($raw['id'] ?? 0);
    $lic_id = (int)($raw['license_id'] ?? 0);
    $pdo->prepare('DELETE la FROM license_assignments la JOIN licenses l ON la.license_id=l.id WHERE la.id=? AND l.client_id=?')->execute([$id, $client_id]);
    if ($lic_id) {
        $pdo->prepare('UPDATE licenses SET used_seats=(SELECT COUNT(*) FROM license_assignments WHERE license_id=? AND active=1) WHERE id=?')
            ->execute([$lic_id, $lic_id]);
    }
    json_success([], 'Attribution supprimée');
}

json_error('Méthode non supportée', 405);
