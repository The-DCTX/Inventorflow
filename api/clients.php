<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if (!is_superadmin()) json_error('Accès refusé', 403);

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();
$raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

if ($method === 'POST') {
    $name = trim($raw['name'] ?? '');
    $code = strtoupper(trim($raw['code'] ?? ''));
    if (!$name || !$code) json_error('Nom et code obligatoires');

    $dup = $pdo->prepare('SELECT id FROM clients WHERE code = ?');
    $dup->execute([$code]);
    if ($dup->fetch()) json_error('Ce code client existe déjà');

    $pdo->prepare('INSERT INTO clients (name, code, contact_name, contact_email, phone, address, active) VALUES (?,?,?,?,?,?,?)')
        ->execute([$name, $code, $raw['contact_name'] ?: null, $raw['contact_email'] ?: null, $raw['phone'] ?: null, $raw['address'] ?: null, (int)($raw['active'] ?? 1)]);

    $id = (int)$pdo->lastInsertId();

    // Default naming conventions
    foreach (['MAC','WIN','LIN'] as $os) {
        $pdo->prepare('INSERT INTO naming_conventions (client_id, os_type, template) VALUES (?,?,?)')
            ->execute([$id, $os, '{CLIENT}-{OS}-{DEPT}-{SEQ:3}']);
    }

    // Auto-generate deploy key
    ensure_deploy_key($id);

    // Auto-select this client in session for superadmin
    if (is_superadmin()) {
        $_SESSION['selected_client_id'] = $id;
    }
    json_success(['id' => $id], 'Client créé');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');

    $code = strtoupper(trim($raw['code'] ?? ''));
    $dup = $pdo->prepare('SELECT id FROM clients WHERE code=? AND id!=?');
    $dup->execute([$code, $id]);
    if ($dup->fetch()) json_error('Ce code est déjà utilisé');

    $pdo->prepare('UPDATE clients SET name=?, code=?, contact_name=?, contact_email=?, phone=?, address=?, active=? WHERE id=?')
        ->execute([trim($raw['name'] ?? ''), $code, $raw['contact_name'] ?: null, $raw['contact_email'] ?: null, $raw['phone'] ?: null, $raw['address'] ?: null, (int)($raw['active'] ?? 1), $id]);

    json_success([], 'Client mis à jour');
}

json_error('Méthode non supportée', 405);
