<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$client_id = current_client_id();
$raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'POST') {
    $name = trim($raw['name'] ?? '');
    if (!$name) json_error('Nom obligatoire');

    // Generate a secure random key
    $plain_key = 'if_' . bin2hex(random_bytes(20));
    $key_hash  = hash('sha256', $plain_key);

    $pdo->prepare('INSERT INTO api_keys (client_id, name, key_hash) VALUES (?,?,?)')
        ->execute([$client_id, $name, $key_hash]);

    json_success([
        'id'        => (int)$pdo->lastInsertId(),
        'plain_key' => $plain_key,
        'name'      => $name,
    ], 'Clé créée — copiez-la maintenant, elle ne sera plus affichée');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    $pdo->prepare('DELETE FROM api_keys WHERE id = ? AND client_id = ?')->execute([$id, $client_id]);
    json_success([], 'Clé révoquée');
}

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT id, name, active, last_used, used_count, created_at FROM api_keys WHERE client_id = ? ORDER BY created_at DESC');
    $stmt->execute([$client_id]);
    json_success($stmt->fetchAll());
}

json_error('Méthode non supportée', 405);
