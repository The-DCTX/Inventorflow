<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

$method = $_SERVER['REQUEST_METHOD'];
$client_id = current_client_id();
$pdo = db();

// GET: suggest names
if ($method === 'GET') {
    $os_type = strtoupper(trim($_GET['os'] ?? ''));
    $dept_id = (int)($_GET['dept_id'] ?? 0);
    $count   = min((int)($_GET['count'] ?? 5), 10);

    if (!in_array($os_type, ['MAC', 'WIN', 'LIN'])) json_error('OS invalide (MAC, WIN ou LIN)');

    $suggestions = suggest_hostnames($client_id, $os_type, $dept_id, $count);
    json_success(['suggestions' => $suggestions]);
}

// POST: save templates
if ($method === 'POST') {
    $raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $conventions = $raw['conventions'] ?? [];

    foreach ($conventions as $os => $data) {
        if (!in_array($os, ['ALL', 'MAC', 'WIN', 'LIN'])) continue;
        $template = trim($data['template'] ?? '{CLIENT}-{OS}-{SEQ:3}');
        $existing_id = (int)($data['id'] ?? 0);

        if ($existing_id) {
            $pdo->prepare('UPDATE naming_conventions SET template=? WHERE id=? AND client_id=?')
                ->execute([$template, $existing_id, $client_id]);
        } else {
            $pdo->prepare('INSERT INTO naming_conventions (client_id, os_type, template) VALUES (?,?,?) ON DUPLICATE KEY UPDATE template=VALUES(template)')
                ->execute([$client_id, $os, $template]);
        }
    }
    json_success([], 'Modèles sauvegardés');
}

json_error('Méthode non supportée', 405);
