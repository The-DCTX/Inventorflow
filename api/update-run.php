<?php
/**
 * InventorFlow — Lancement de la mise à jour (super-admin).
 *
 * POST /api/update-run.php
 *   -> { success, data: { code, output, version_before, version_after } }
 *
 * Sécurité : exécute UNIQUEMENT maintenance/update.sh en root via une règle
 * sudoers dédiée et verrouillée (www-data ne peut lancer que ce script,
 * lui-même root-owned 0755 donc non modifiable par le web). Voir install.sh.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();
if (!is_superadmin()) {
    json_error('Accès réservé au super-admin', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Méthode non autorisée', 405);
}

$script = realpath(__DIR__ . '/../maintenance/update.sh');
if (!$script || !is_file($script)) {
    json_error('Script de mise à jour introuvable', 500);
}

$version_before = APP_VERSION;

@set_time_limit(300);
$cmd = 'sudo -n ' . escapeshellarg($script) . ' -y 2>&1';
$output = [];
$code   = 0;
exec($cmd, $output, $code);
$out = implode("\n", $output);

// Le serveur a-t-il le droit (sudoers) ?
if ($code !== 0 && preg_match('/sudo:.*(password|terminal|not allowed|no tty)/i', $out)) {
    json_error("La règle sudo dédiée n'est pas configurée sur le serveur. "
        . "Lancez l'installeur (qui l'ajoute) ou exécutez manuellement : sudo bash maintenance/update.sh", 500);
}

// Relire la version après mise à jour
$version_after = $version_before;
$vfile = __DIR__ . '/../VERSION';
if (is_file($vfile) && preg_match('/\d+\.\d+\.\d+/', (string)@file_get_contents($vfile), $m)) {
    $version_after = $m[0];
}

if (function_exists('opcache_reset')) {
    @opcache_reset();
}

if ($code !== 0) {
    json_error("La mise à jour a échoué (code {$code}).\n" . $out, 500);
}

json_success([
    'code'           => $code,
    'output'         => $out,
    'version_before' => $version_before,
    'version_after'  => $version_after,
], 'Mise à jour terminée');
