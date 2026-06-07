<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if (!is_superadmin()) json_error('Accès réservé au superadmin', 403);
require_once __DIR__ . '/../includes/custom_fields.php';

$pdo    = db();
$method  = $_SERVER['REQUEST_METHOD'];
$raw     = json_decode(file_get_contents('php://input'), true) ?? $_POST;

function cf_slug(string $s): string {
    $s = strtolower(trim($s));
    if (function_exists('iconv')) $s = @iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s;
    $s = preg_replace('/[^a-z0-9]+/', '_', $s);
    return trim($s, '_');
}
function cf_options_from(string $raw): ?string {
    $opts = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw)), 'strlen'));
    return $opts ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
}

if ($method === 'GET') {
    if (($_GET['action'] ?? '') === 'values') {
        $ve = $_GET['entity'] ?? ''; if (!cf_valid_entity($ve)) json_error('Entité invalide');
        json_success(cf_values($ve, (int)($_GET['id'] ?? 0)));
    }
    $e = $_GET['entity'] ?? '';
    if ($e !== '' && !cf_valid_entity($e)) json_error('Entité invalide');
    if ($e !== '') json_success(cf_defs($e, false));
    json_success($pdo->query('SELECT * FROM custom_field_defs ORDER BY entity_type, sort_order, id')->fetchAll());
}

if ($method === 'POST') {
    $entity = $raw['entity_type'] ?? '';
    if (!cf_valid_entity($entity)) json_error('Entité invalide');
    $label = trim($raw['label'] ?? '');
    if ($label === '') json_error('Libellé obligatoire');
    $type = $raw['field_type'] ?? 'text';
    if (!array_key_exists($type, cf_types())) json_error('Type invalide');
    $key = cf_slug($raw['field_key'] ?? '') ?: cf_slug($label);
    if ($key === '') json_error('Clé invalide');

    $dup = $pdo->prepare('SELECT id FROM custom_field_defs WHERE entity_type = ? AND field_key = ?');
    $dup->execute([$entity, $key]);
    if ($dup->fetch()) json_error('Une clé identique existe déjà pour cette entité');

    $options = $type === 'select' ? cf_options_from($raw['options'] ?? '') : null;
    $so = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM custom_field_defs')->fetchColumn();
    $pdo->prepare('INSERT INTO custom_field_defs (entity_type, field_key, label, field_type, options, required, sort_order)
                   VALUES (?,?,?,?,?,?,?)')
        ->execute([$entity, $key, $label, $type, $options, !empty($raw['required']) ? 1 : 0, $so]);
    $id = (int)$pdo->lastInsertId();
    audit_log('custom_field_create', $entity, $id, ['label' => $label, 'type' => $type]);
    json_success(['id' => $id], 'Champ créé');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    $label = trim($raw['label'] ?? '');
    if ($label === '') json_error('Libellé obligatoire');
    $type = $raw['field_type'] ?? 'text';
    if (!array_key_exists($type, cf_types())) json_error('Type invalide');
    $options = $type === 'select' ? cf_options_from($raw['options'] ?? '') : null;
    $active  = isset($raw['active']) ? (int)$raw['active'] : 1;
    $pdo->prepare('UPDATE custom_field_defs SET label=?, field_type=?, options=?, required=?, active=? WHERE id=?')
        ->execute([$label, $type, $options, !empty($raw['required']) ? 1 : 0, $active, $id]);
    audit_log('custom_field_update', null, $id, ['label' => $label]);
    json_success([], 'Champ mis à jour');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    $pdo->prepare('DELETE FROM custom_field_defs WHERE id=?')->execute([$id]);
    audit_log('custom_field_delete', null, $id);
    json_success([], 'Champ supprimé');
}

json_error('Méthode non supportée', 405);
