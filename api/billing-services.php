<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT * FROM billing_services ORDER BY category, price');
    json_success($stmt->fetchAll());
}

if ($method === 'POST') {
    $name = trim($raw['name'] ?? '');
    if (!$name) json_error('Nom obligatoire');
    $pdo->prepare('INSERT INTO billing_services (name, description, category, price, unit, billing_period, color) VALUES (?,?,?,?,?,?,?)')
        ->execute([$name, $raw['description'] ?: null, $raw['category'] ?? 'other',
            (float)($raw['price'] ?? 0), $raw['unit'] ?? 'per_device',
            $raw['billing_period'] ?? 'monthly', $raw['color'] ?? '#4f7ef8']);
    json_success(['id' => (int)$pdo->lastInsertId()], 'Service créé');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    $pdo->prepare('UPDATE billing_services SET name=?,description=?,category=?,price=?,unit=?,billing_period=?,color=?,active=? WHERE id=?')
        ->execute([trim($raw['name'] ?? ''), $raw['description'] ?: null, $raw['category'] ?? 'other',
            (float)($raw['price'] ?? 0), $raw['unit'] ?? 'per_device',
            $raw['billing_period'] ?? 'monthly', $raw['color'] ?? '#4f7ef8',
            (int)($raw['active'] ?? 1), $id]);
    json_success([], 'Service mis à jour');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    $pdo->prepare('DELETE FROM billing_services WHERE id=?')->execute([$id]);
    json_success([], 'Service supprimé');
}

json_error('Méthode non supportée', 405);
