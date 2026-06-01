<?php
/**
 * InventorFlow — API Backup
 * POST : lance un backup immédiat (superadmin uniquement)
 * GET  : retourne les derniers logs
 */
require_once __DIR__ . '/../config/app.php';
if (!is_logged_in()) json_error('Non authentifié', 401);
if (!is_superadmin()) json_error('Accès refusé', 403);

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $logs = $pdo->query(
        'SELECT id, run_at, status, files_size, db_size, duration_sec, message
         FROM backup_logs ORDER BY run_at DESC LIMIT 10'
    )->fetchAll();
    json_success($logs);
}

if ($method === 'POST') {
    $raw = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    // Test email
    if (($raw['action'] ?? '') === 'test_email') {
        $to = filter_var($raw['to'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$to) json_error('Email invalide');


        require_once __DIR__ . '/../includes/mailer.php';
        try {
            Mailer::send($to, '[InventorFlow] Test de notification',
                "Ce message confirme que les notifications email fonctionnent correctement.\n\nServeur : " . APP_SERVER_URL);
            json_success([], 'Email envoyé à ' . $to);
        } catch (\Throwable $e) {
            json_error('Échec envoi : ' . $e->getMessage());
        }
    }

    // Test email
    if (($raw['action'] ?? '') === 'test_email') {
        $to = filter_var($raw['to'] ?? '', FILTER_VALIDATE_EMAIL);
        if (!$to) json_error('Email invalide');
        require_once __DIR__ . '/../includes/mailer.php';
        try {
            Mailer::send($to, '[InventorFlow] Test de notification',
                "Ce message confirme que les notifications email fonctionnent.\n\nServeur : " . APP_SERVER_URL);
            json_success([], 'Email envoyé à ' . $to);
        } catch (\Throwable $e) {
            json_error('Échec : ' . $e->getMessage());
        }
    }

    // Lancer un backup immédiat
    $backup_script = __DIR__ . '/../maintenance/backup.sh';
    if (!file_exists($backup_script)) {
        json_error('Script de backup introuvable : ' . realpath(dirname($backup_script)) . '/backup.sh');
    }

    // Exécution en arrière-plan
    $output = [];
    $code   = 0;
    exec('bash ' . escapeshellarg($backup_script) . ' 2>&1', $output, $code);

    if ($code !== 0) {
        json_error('Backup échoué (code ' . $code . ') : ' . implode(' ', $output));
    }

    // Récupérer le dernier log inséré
    $last = $pdo->query(
        'SELECT * FROM backup_logs ORDER BY run_at DESC LIMIT 1'
    )->fetch();

    json_success($last ?: [], 'Backup terminé');
}

json_error('Méthode non supportée', 405);
