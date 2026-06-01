<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if (!is_superadmin()) json_error('Accès réservé au super-admin', 403);

$method = $_SERVER['REQUEST_METHOD'];
$pdo = db();

if ($method === 'GET') {
    $rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
    json_success(array_column($rows, 'setting_value', 'setting_key'));
}

if ($method === 'POST') {
    $raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $allowed = ['app_name', 'app_url'];
    foreach ($allowed as $key) {
        if (!isset($raw[$key])) continue;
        $value = trim($raw[$key]);
        if ($key === 'app_name' && $value === '') json_error('Le nom ne peut pas être vide');
        if ($key === 'app_url') {
            $value = rtrim($value, '/');
            if (!preg_match('#^https?://#', $value)) json_error('URL invalide (doit commencer par http:// ou https://)');
        }
        $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([$key, $value]);
    }

    // Invalidate session cache so changes apply immediately
    unset($_SESSION['_app_settings']);

    json_success([], 'Paramètres sauvegardés');
}

json_error('Méthode non supportée', 405);
