<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$client_id = current_client_id();
$raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// GET: detail endpoints pour le panel département
if ($method === 'GET') {
    $detail   = $_GET['detail'] ?? '';
    $dept_id  = (int)($_GET['dept_id'] ?? 0);

    if ($detail === 'employees' && $dept_id) {
        $stmt = $pdo->prepare('SELECT e.id, e.first_name, e.last_name, e.position,
            (SELECT COUNT(*) FROM assets a WHERE a.assigned_to=e.id) as asset_count
            FROM employees e
            WHERE e.department_id=? AND e.client_id=? AND e.active=1
            ORDER BY e.last_name, e.first_name');
        $stmt->execute([$dept_id, $client_id]);
        json_success($stmt->fetchAll());
    }

    if ($detail === 'assets' && $dept_id) {
        $stmt = $pdo->prepare('SELECT a.id, a.hostname, a.os_type, a.status, a.model,
            CONCAT(e.first_name," ",e.last_name) as assigned_name
            FROM assets a
            LEFT JOIN employees e ON a.assigned_to=e.id
            WHERE a.department_id=? AND a.client_id=? AND a.status != "retired"
            ORDER BY a.hostname');
        $stmt->execute([$dept_id, $client_id]);
        json_success($stmt->fetchAll());
    }

    if ($detail === 'licenses' && $dept_id) {
        // Licences machines du département
        $s1 = $pdo->prepare('SELECT l.name, l.vendor, l.category, l.cost_per_seat, l.billing_period,
            a.hostname as assigned_to_label
            FROM license_assignments la
            JOIN licenses l ON la.license_id=l.id
            JOIN assets a ON la.asset_id=a.id
            WHERE a.department_id=? AND la.active=1
            ORDER BY l.name');
        $s1->execute([$dept_id]);
        $rows = $s1->fetchAll();

        // Licences nominatives des employés du département
        $s2 = $pdo->prepare('SELECT l.name, l.vendor, l.category, l.cost_per_seat, l.billing_period,
            CONCAT(e.first_name," ",e.last_name) as assigned_to_label
            FROM license_assignments la
            JOIN licenses l ON la.license_id=l.id
            JOIN employees e ON la.employee_id=e.id
            WHERE e.department_id=? AND e.active=1 AND la.active=1
            ORDER BY l.name');
        $s2->execute([$dept_id]);
        $rows = array_merge($rows, $s2->fetchAll());
        json_success($rows);
    }

    json_error('Paramètres manquants', 400);
}

if ($method === 'POST') {
    $name = trim($raw['name'] ?? '');
    $code = strtoupper(trim($raw['code'] ?? ''));
    if (!$name || !$code) json_error('Nom et code obligatoires');

    $pdo->prepare('INSERT INTO departments (client_id, name, code) VALUES (?,?,?)')
        ->execute([$client_id, $name, $code]);
    json_success(['id' => (int)$pdo->lastInsertId()], 'Département créé');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $check = $pdo->prepare('SELECT id FROM departments WHERE id=? AND client_id=?');
    $check->execute([$id, $client_id]);
    if (!$check->fetch()) json_error('Département introuvable', 404);

    $pdo->prepare('UPDATE departments SET name=?, code=? WHERE id=? AND client_id=?')
        ->execute([trim($raw['name'] ?? ''), strtoupper(trim($raw['code'] ?? '')), $id, $client_id]);
    json_success([], 'Département mis à jour');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $check = $pdo->prepare('SELECT id FROM departments WHERE id=? AND client_id=?');
    $check->execute([$id, $client_id]);
    if (!$check->fetch()) json_error('Département introuvable', 404);

    $pdo->prepare('DELETE FROM departments WHERE id=? AND client_id=?')->execute([$id, $client_id]);
    json_success([], 'Département supprimé');
}

json_error('Méthode non supportée', 405);
