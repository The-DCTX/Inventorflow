<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$raw  = json_decode(file_get_contents('php://input'), true) ?? [];
$pdo  = db();
$cid  = current_client_id();

if (!empty($raw['all'])) {
    // Reset all events for assets belonging to this client
    $pdo->prepare('DELETE se FROM security_events se
        JOIN assets a ON se.asset_id = a.id
        WHERE a.client_id = ?')->execute([$cid]);
    json_success([], 'Tous les événements réinitialisés');
}

$asset_id = (int)($raw['asset_id'] ?? 0);
if (!$asset_id) json_error('asset_id requis');

// Verify asset belongs to current client
$check = $pdo->prepare('SELECT id FROM assets WHERE id = ? AND client_id = ?');
$check->execute([$asset_id, $cid]);
if (!$check->fetch()) json_error('Asset introuvable', 404);

$pdo->prepare('DELETE FROM security_events WHERE asset_id = ?')->execute([$asset_id]);
json_success([], 'Événements réinitialisés');
