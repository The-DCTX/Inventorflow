<?php
/**
 * InventorFlow — API d'attribution de licences
 * GET  ?available=1&target=asset|employee&entity_id=X : licences disponibles avec état d'attribution
 * POST : attribuer
 * DELETE : révoquer
 */
require_once __DIR__ . '/../config/app.php';
require_auth();

$method    = $_SERVER['REQUEST_METHOD'];
$pdo       = db();
$client_id = current_client_id();
$raw       = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// GET : licences disponibles pour un asset ou un employé
if ($method === 'GET' && !empty($_GET['available'])) {
    $target    = $_GET['target']    ?? 'employee'; // 'asset' | 'employee'
    $entity_id = (int)($_GET['entity_id'] ?? 0);
    if (!$entity_id) json_error('entity_id requis');

    $col = $target === 'asset' ? 'asset_id' : 'employee_id';

    $stmt = $pdo->prepare("
        SELECT l.id, l.name, l.vendor, l.category, l.target,
               l.license_type, l.total_seats, l.used_seats,
               l.cost_per_seat, l.billing_period,
               la.id as assignment_id, la.active as is_assigned
        FROM licenses l
        LEFT JOIN license_assignments la
            ON la.license_id = l.id AND la.$col = ? AND la.active = 1
        WHERE l.client_id = ? AND l.active = 1 AND l.target = ?
        ORDER BY l.category, l.name
    ");
    $stmt->execute([$entity_id, $client_id, $target]);
    $rows = $stmt->fetchAll();

    // Ajouter les sièges restants
    foreach ($rows as &$r) {
        $r['available_seats'] = max(0, (int)$r['total_seats'] - (int)$r['used_seats']);
        $r['is_assigned']     = !empty($r['assignment_id']) && $r['is_assigned'];
        $r['can_assign']      = $r['available_seats'] > 0 || $r['is_assigned'];
    }
    unset($r);
    json_success($rows);
}

// POST : attribuer une licence
if ($method === 'POST') {
    $license_id = (int)($raw['license_id'] ?? 0);
    $target     = $raw['target'] ?? '';
    $entity_id  = (int)($raw['entity_id'] ?? 0);
    if (!$license_id || !$entity_id || !in_array($target, ['asset','employee'])) {
        json_error('Paramètres manquants');
    }

    $col = $target === 'asset' ? 'asset_id' : 'employee_id';

    // Vérifier que la licence appartient à ce client et a le bon target
    $lic = $pdo->prepare('SELECT id, total_seats, used_seats, target FROM licenses WHERE id=? AND client_id=? AND active=1');
    $lic->execute([$license_id, $client_id]);
    $lic = $lic->fetch();
    if (!$lic) json_error('Licence introuvable', 404);
    if ($lic['target'] !== $target) json_error('Cette licence ne peut pas être attribuée à ce type de ressource');

    // Déjà attribuée ?
    $dup = $pdo->prepare("SELECT id FROM license_assignments WHERE license_id=? AND $col=? AND active=1");
    $dup->execute([$license_id, $entity_id]);
    if ($dup->fetch()) json_error('Déjà attribuée');

    // Siège disponible ?
    if ((int)$lic['used_seats'] >= (int)$lic['total_seats']) {
        json_error('Aucun siège disponible ('.$lic['total_seats'].'/'.$lic['total_seats'].')', 409);
    }

    $pdo->prepare("INSERT INTO license_assignments (license_id, $col, assigned_at, active) VALUES (?,?,CURDATE(),1)")
        ->execute([$license_id, $entity_id]);

    // Recalcul used_seats
    $pdo->prepare('UPDATE licenses SET used_seats=(SELECT COUNT(*) FROM license_assignments WHERE license_id=? AND active=1) WHERE id=?')
        ->execute([$license_id, $license_id]);

    json_success(['assignment_id' => (int)$pdo->lastInsertId()], 'Licence attribuée');
}

// DELETE : révoquer
if ($method === 'DELETE') {
    $assignment_id = (int)($raw['assignment_id'] ?? 0);
    $license_id    = (int)($raw['license_id'] ?? 0);
    if (!$assignment_id) json_error('assignment_id requis');

    $pdo->prepare('UPDATE license_assignments SET active=0, revoked_at=CURDATE() WHERE id=?')
        ->execute([$assignment_id]);

    if ($license_id) {
        $pdo->prepare('UPDATE licenses SET used_seats=(SELECT COUNT(*) FROM license_assignments WHERE license_id=? AND active=1) WHERE id=?')
            ->execute([$license_id, $license_id]);
    }
    json_success([], 'Licence révoquée');
}

json_error('Méthode non supportée', 405);
