<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$client_id = (int)($raw['client_id'] ?? 0);

if (!$client_id) json_error('client_id requis');
if (!is_superadmin() && $client_id !== current_client_id()) json_error('Accès refusé', 403);

// Verify client exists
$c = db()->prepare('SELECT name, code FROM clients WHERE id = ? AND active = 1');
$c->execute([$client_id]);
$client = $c->fetch();
if (!$client) json_error('Client introuvable', 404);

$plain_key = ensure_deploy_key($client_id);

json_success([
    'plain_key'   => $plain_key,
    'client_name' => $client['name'],
    'client_code' => $client['code'],
]);
