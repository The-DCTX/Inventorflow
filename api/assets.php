<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$client_id = current_client_id();

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT a.*, CONCAT(e.first_name," ",e.last_name) as assigned_name, d.name as dept_name
        FROM assets a LEFT JOIN employees e ON a.assigned_to=e.id LEFT JOIN departments d ON a.department_id=d.id
        WHERE a.client_id=? ORDER BY a.created_at DESC');
    $stmt->execute([$client_id]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) { $r['custom_fields'] = cf_values('asset', (int)$r['id']); }
    unset($r);
    json_success($rows);
}

$raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'POST') {
    $hostname = strtoupper(trim($raw['hostname'] ?? ''));
    $os_type  = $raw['os_type'] ?? '';
    if (!$hostname || !in_array($os_type, ['MAC','WIN','LIN'])) {
        json_error('Hostname et OS obligatoires');
    }

    $existing = $pdo->prepare('SELECT id FROM assets WHERE hostname=? AND client_id=?');
    $existing->execute([$hostname, $client_id]);
    if ($existing->fetch()) json_error('Ce hostname existe déjà pour ce client');

    $stmt = $pdo->prepare('INSERT INTO assets
        (client_id, hostname, asset_tag, serial_number, os_type, os_version, brand, model, cpu,
         ram_gb, storage_gb, storage_type, ip_address, mac_address, location, purchase_date,
         warranty_until, status, assigned_to, department_id, notes, billable)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

    $stmt->execute([
        $client_id, $hostname,
        $raw['asset_tag'] ?: null, $raw['serial_number'] ?: null,
        $os_type, $raw['os_version'] ?: null,
        $raw['brand'] ?: null, $raw['model'] ?: null, $raw['cpu'] ?: null,
        $raw['ram_gb'] ?: null, $raw['storage_gb'] ?: null, $raw['storage_type'] ?: null,
        $raw['ip_address'] ?: null, $raw['mac_address'] ?: null, $raw['location'] ?: null,
        $raw['purchase_date'] ?: null, $raw['warranty_until'] ?: null,
        $raw['status'] ?? 'stock',
        $raw['assigned_to'] ?: null, $raw['department_id'] ?: null,
        $raw['notes'] ?: null,
        isset($raw['billable']) ? (int)$raw['billable'] : 1,
    ]);

    $id = (int)$pdo->lastInsertId();
    log_asset_action($id, 'created', null, $hostname);

    if ($raw['assigned_to']) {
        log_asset_action($id, 'assigned', null, $raw['assigned_to']);
    }

    auto_assign_monitoring($client_id);
    cf_save('asset', $id, $raw);
    json_success(["id" => $id], "Poste créé");
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $check = $pdo->prepare('SELECT id, hostname, assigned_to, status FROM assets WHERE id=? AND client_id=?');
    $check->execute([$id, $client_id]);
    $old = $check->fetch();
    if (!$old) json_error('Poste introuvable', 404);

    $hostname = strtoupper(trim($raw['hostname'] ?? ''));
    $os_type  = $raw['os_type'] ?? '';
    if (!$hostname || !in_array($os_type, ['MAC','WIN','LIN'])) {
        json_error('Hostname et OS obligatoires');
    }

    $dup = $pdo->prepare('SELECT id FROM assets WHERE hostname=? AND client_id=? AND id!=?');
    $dup->execute([$hostname, $client_id, $id]);
    if ($dup->fetch()) json_error('Ce hostname est déjà utilisé');

    $stmt = $pdo->prepare('UPDATE assets SET
        hostname=?, asset_tag=?, serial_number=?, os_type=?, os_version=?, brand=?, model=?, cpu=?,
        ram_gb=?, storage_gb=?, storage_type=?, ip_address=?, mac_address=?, location=?,
        purchase_date=?, warranty_until=?, status=?, assigned_to=?, department_id=?, notes=?
        WHERE id=? AND client_id=?');

    $stmt->execute([
        $hostname, $raw['asset_tag'] ?: null, $raw['serial_number'] ?: null,
        $os_type, $raw['os_version'] ?: null, $raw['brand'] ?: null, $raw['model'] ?: null,
        $raw['cpu'] ?: null, $raw['ram_gb'] ?: null, $raw['storage_gb'] ?: null,
        $raw['storage_type'] ?: null, $raw['ip_address'] ?: null, $raw['mac_address'] ?: null,
        $raw['location'] ?: null, $raw['purchase_date'] ?: null, $raw['warranty_until'] ?: null,
        $raw['status'] ?? 'stock', $raw['assigned_to'] ?: null, $raw['department_id'] ?: null,
        $raw['notes'] ?: null, $id, $client_id,
    ]);

    log_asset_action($id, 'updated');

    $new_assignee = $raw['assigned_to'] ?: null;
    if ($new_assignee != $old['assigned_to']) {
        if ($new_assignee) {
            log_asset_action($id, 'assigned', $old['assigned_to'], $new_assignee);
        } else {
            log_asset_action($id, 'unassigned', $old['assigned_to'], null);
        }
    }
    if (($raw['status'] ?? '') !== $old['status']) {
        log_asset_action($id, 'status_change', $old['status'], $raw['status']);
    }

    cf_save('asset', $id, $raw);
    json_success([], 'Poste mis à jour');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $check = $pdo->prepare('SELECT id FROM assets WHERE id=? AND client_id=?');
    $check->execute([$id, $client_id]);
    if (!$check->fetch()) json_error('Poste introuvable', 404);

    $pdo->prepare('DELETE FROM assets WHERE id=? AND client_id=?')->execute([$id, $client_id]);
    json_success([], 'Poste supprimé');
}

json_error('Méthode non supportée', 405);
