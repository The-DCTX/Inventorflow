<?php
/**
 * InventorFlow — Agent Command Queue
 * GET  : agent polls for pending commands (authenticated via X-API-Key)
 * POST : UI creates a new command (authenticated via session)
 * PUT  : agent marks command as executed (authenticated via X-API-Key)
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();

// ── Agent calls (X-API-Key) ──────────────────────────────────
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if ($api_key) {
    $key_hash = hash('sha256', $api_key);
    $stmt = $pdo->prepare('SELECT k.client_id FROM api_keys k WHERE k.key_hash=? AND k.active=1 LIMIT 1');
    $stmt->execute([$key_hash]);
    $key_row = $stmt->fetch();
    if (!$key_row) { json_error('Invalid API key', 401); }
    $agent_client_id = (int)$key_row['client_id'];

    // GET: return oldest pending command for this asset
    if ($method === 'GET') {
        $hostname = strtoupper(trim($_GET['hostname'] ?? ''));
        if (!$hostname) json_error('hostname requis');

        $asset = $pdo->prepare('SELECT id FROM assets WHERE client_id=? AND hostname=? LIMIT 1');
        $asset->execute([$agent_client_id, $hostname]);
        $a = $asset->fetch();
        if (!$a) json_success(null); // no asset = no command

        $cmd = $pdo->prepare(
            'SELECT id, command FROM agent_commands
             WHERE asset_id=? AND status="pending"
             ORDER BY created_at ASC LIMIT 1'
        );
        $cmd->execute([(int)$a['id']]);
        $row = $cmd->fetch();

        if ($row) {
            // Mark as executing
            $pdo->prepare('UPDATE agent_commands SET status="done", executed_at=NOW() WHERE id=?')
                ->execute([$row['id']]);
            json_success(['id' => $row['id'], 'command' => $row['command']]);
        } else {
            json_success(null); // no pending command
        }
    }

    // PUT: mark as done with result
    if ($method === 'PUT') {
        $raw = json_decode(file_get_contents('php://input'), true) ?? [];
        $id  = (int)($raw['id'] ?? 0);
        if ($id) {
            $pdo->prepare('UPDATE agent_commands SET status=?,result=?,executed_at=NOW() WHERE id=?')
                ->execute([$raw['status'] ?? 'done', $raw['result'] ?? null, $id]);
        }
        json_success([], 'OK');
    }

    json_error('Méthode non supportée', 405);
}

// ── UI calls (session) ───────────────────────────────────────
session_name('inventorflow_session');
session_start();
if (!isset($_SESSION['user_id'])) json_error('Non authentifié', 401);

$raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

// POST: create command
if ($method === 'POST') {
    $asset_id = (int)($raw['asset_id'] ?? 0);
    $command  = $raw['command'] ?? 'force_report';
    if (!$asset_id) json_error('asset_id requis');
    if (!in_array($command, ['force_report', 'ping', 'update_agent'])) json_error('Commande invalide');

    // Cancel any existing pending command for this asset+command
    $pdo->prepare('UPDATE agent_commands SET status="failed" WHERE asset_id=? AND command=? AND status="pending"')
        ->execute([$asset_id, $command]);

    $pdo->prepare('INSERT INTO agent_commands (asset_id, command, created_by) VALUES (?,?,?)')
        ->execute([$asset_id, $command, (int)$_SESSION['user_id']]);

    json_success(['id' => (int)$pdo->lastInsertId()], 'Commande envoyée — sera exécutée au prochain cycle (< 5 min)');
}

// GET: check command status (for UI polling)
if ($method === 'GET') {
    $asset_id = (int)($_GET['asset_id'] ?? 0);
    if (!$asset_id) json_error('asset_id requis');

    $cmd = $pdo->prepare(
        'SELECT id, command, status, created_at, executed_at
         FROM agent_commands WHERE asset_id=? ORDER BY created_at DESC LIMIT 1'
    );
    $cmd->execute([$asset_id]);
    json_success($cmd->fetch() ?: null);
}

json_error('Méthode non supportée', 405);
