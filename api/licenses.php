<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method    = $_SERVER['REQUEST_METHOD'];
$pdo       = db();
$client_id = current_client_id();
$raw       = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'GET') {
    $cid    = $client_id; // toujours le client courant (on n'honore aucun client_id de requête — anti-IDOR)
    $target = $_GET['target'] ?? null; // 'asset' | 'employee' | null = tous

    $sql = 'SELECT l.*,
        (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id = l.id AND la.active=1 AND la.asset_id IS NOT NULL) as assigned_devices,
        (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id = l.id AND la.active=1 AND la.employee_id IS NOT NULL) as assigned_users,
        (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id = l.id AND la.active=1) as total_assigned
        FROM licenses l WHERE l.client_id = ? AND l.active = 1';
    $params = [$cid];
    if ($target) { $sql .= ' AND l.target = ?'; $params[] = $target; }
    $sql .= ' ORDER BY l.category, l.name';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    json_success($stmt->fetchAll());
}

if ($method === 'POST') {
    if (!($raw['name'] ?? '')) json_error('Nom obligatoire');
    $target = in_array($raw['target'] ?? '', ['asset','employee']) ? $raw['target'] : 'employee';
    $pdo->prepare('INSERT INTO licenses (client_id,name,vendor,category,target,license_type,total_seats,cost_per_seat,sell_price_per_seat,billing_period,purchase_date,renewal_date,product_key,vendor_contact,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$client_id, trim($raw['name']), $raw['vendor']??null, $raw['category']??'other',
            $target, $raw['license_type']??'subscription', (int)($raw['total_seats']??1),
            (float)($raw['cost_per_seat']??0), (float)($raw['sell_price_per_seat']??0),
            $raw['billing_period']??'annual',
            $raw['purchase_date']?:null, $raw['renewal_date']?:null,
            $raw['product_key']?:null, $raw['vendor_contact']?:null, $raw['notes']?:null]);
    $lid = (int)$pdo->lastInsertId();
    cf_save('license', $lid, $raw);
    json_success(['id' => $lid], 'Licence créée');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    $target = in_array($raw['target'] ?? '', ['asset','employee']) ? $raw['target'] : 'employee';
    $pdo->prepare('UPDATE licenses SET name=?,vendor=?,category=?,target=?,license_type=?,total_seats=?,cost_per_seat=?,sell_price_per_seat=?,billing_period=?,purchase_date=?,renewal_date=?,product_key=?,vendor_contact=?,notes=? WHERE id=? AND client_id=?')
        ->execute([trim($raw['name']), $raw['vendor']??null, $raw['category']??'other',
            $target, $raw['license_type']??'subscription', (int)($raw['total_seats']??1),
            (float)($raw['cost_per_seat']??0), (float)($raw['sell_price_per_seat']??0),
            $raw['billing_period']??'annual',
            $raw['purchase_date']?:null, $raw['renewal_date']?:null,
            $raw['product_key']?:null, $raw['vendor_contact']?:null, $raw['notes']?:null,
            $id, $client_id]);
    cf_save('license', $id, $raw);
    json_success([], 'Licence mise à jour');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    $pdo->prepare('DELETE FROM licenses WHERE id=? AND client_id=?')->execute([$id, $client_id]);
    json_success([], 'Licence supprimée');
}

json_error('Méthode non supportée', 405);
