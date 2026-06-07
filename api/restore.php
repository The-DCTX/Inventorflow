<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
if (!is_superadmin()) json_error('Accès réservé au superadmin', 403);
require_once __DIR__ . '/../includes/restore_lib.php';

$method = $_SERVER['REQUEST_METHOD'];
$raw    = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $_GET['action'] ?? $raw['action'] ?? '';

if ($method === 'GET') {
    switch ($action) {
        case 'list':
            json_success(['sets' => rl_list_backups(), 'recovery_configured' => rl_recovery_is_configured()]);
        case 'explore_files':
            json_success(rl_explore_files($_GET['name'] ?? ''));
        case 'explore_db':
            json_success(rl_explore_db($_GET['name'] ?? ''));
    }
    json_error('Action non reconnue', 404);
}

if ($method === 'POST') {
    if ($action === 'set_recovery_password') {
        $pw = (string)($raw['password'] ?? '');
        if (strlen($pw) < 8) json_error('Mot de passe de secours trop court (8 caractères min)');
        rl_recovery_set_password($pw)
            ? json_success([], 'Mot de passe de secours défini')
            : json_error('Impossible d\'écrire config/recovery.php (permissions ?)', 500);
    }

    if ($action === 'restore') {
        $db    = trim((string)($raw['db'] ?? ''));
        $files = trim((string)($raw['files'] ?? ''));
        if (!$db && !$files) json_error('Sélectionnez la base et/ou les fichiers à restaurer');
        if ($db && !rl_safe_backup_path($db))       json_error('Dump base introuvable');
        if ($files && !rl_safe_backup_path($files)) json_error('Archive fichiers introuvable');

        $script = realpath(__DIR__ . '/../maintenance/restore.sh');
        if (!$script) json_error('restore.sh introuvable', 500);

        $cmd = 'sudo -n ' . escapeshellarg($script);
        if ($db)    $cmd .= ' --db '    . escapeshellarg(basename($db));
        if ($files) $cmd .= ' --files ' . escapeshellarg(basename($files));
        $cmd .= ' -y 2>&1';

        exec($cmd, $output, $code);
        $out = implode("\n", $output);

        if ($code !== 0) {
            if (preg_match('/sudo:.*(password|terminal|not allowed|no tty)/i', $out)) {
                json_error("La règle sudo dédiée n'est pas configurée. Lancez : sudo bash maintenance/enable-oneclick.sh", 500);
            }
            json_error("Échec de la restauration : " . $out, 500);
        }
        json_success(['log' => $out], 'Restauration effectuée (une sauvegarde de sécurité a été créée avant)');
    }

    json_error('Action non reconnue', 404);
}

json_error('Méthode non supportée', 405);
