<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();
$raw    = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// GET: all packs with their services
if ($method === 'GET') {
    $stmt = $pdo->query('SELECT p.*, GROUP_CONCAT(ps.service_id ORDER BY ps.service_id) as svc_ids
        FROM billing_packs p
        LEFT JOIN billing_pack_services ps ON p.id = ps.pack_id
        GROUP BY p.id ORDER BY p.sort_order');
    $packs = $stmt->fetchAll();
    foreach ($packs as &$pk) {
        $pk['service_ids'] = $pk['svc_ids']
            ? array_map('intval', explode(',', $pk['svc_ids']))
            : [];
        unset($pk['svc_ids']);
    }
    json_success($packs);
}

// POST: create new pack
if ($method === 'POST') {
    $name = trim($raw['name'] ?? '');
    if (!$name) json_error('Nom obligatoire');
    $pdo->prepare('INSERT INTO billing_packs (name, description, color, icon, sort_order) VALUES (?,?,?,?,?)')
        ->execute([$name, $raw['description']??'', $raw['color']??'#4f7ef8', $raw['icon']??'layers',
            (int)($raw['sort_order']??99)]);
    $id = (int)$pdo->lastInsertId();
    // Insert service associations
    foreach (($raw['service_ids'] ?? []) as $sid) {
        $pdo->prepare('INSERT IGNORE INTO billing_pack_services (pack_id,service_id) VALUES (?,?)')->execute([$id,(int)$sid]);
    }
    json_success(['id'=>$id], 'Pack créé');
}

// PUT: update pack (name, description, color) + services
if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    $pdo->prepare('UPDATE billing_packs SET name=?,description=?,color=?,icon=? WHERE id=?')
        ->execute([
            trim($raw['name'] ?? ''),
            $raw['description'] ?? '',
            $raw['color'] ?? '#4f7ef8',
            $raw['icon'] ?? 'layers',
            $id
        ]);
    // Replace service associations
    $pdo->prepare('DELETE FROM billing_pack_services WHERE pack_id=?')->execute([$id]);
    foreach (($raw['service_ids'] ?? []) as $sid) {
        $pdo->prepare('INSERT IGNORE INTO billing_pack_services (pack_id,service_id) VALUES (?,?)')->execute([$id,(int)$sid]);
    }
    json_success([], 'Pack mis à jour');
}

// DELETE
if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    $pdo->prepare('DELETE FROM billing_packs WHERE id=?')->execute([$id]);
    json_success([], 'Pack supprimé');
}

json_error('Méthode non supportée', 405);
