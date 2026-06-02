<?php
/**
 * InventorFlow — Vérification de mise à jour (super-admin).
 *
 * GET /api/update-check.php[?refresh=1]
 *   -> { success, data: { current, latest, has_update, notes_html, url, published_at } }
 *
 * Lecture seule : interroge GitHub (cache 24h, ?refresh=1 pour forcer).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_auth();
if (!is_superadmin()) {
    json_error('Accès réservé au super-admin', 403);
}

$force   = !empty($_GET['refresh']);
$release = github_latest_release($force);

$current    = APP_VERSION;
$latest     = $release['version'];
$has_update = $latest && version_compare($latest, $current, '>');

json_success([
    'current'      => $current,
    'latest'       => $latest,
    'has_update'   => (bool)$has_update,
    'notes_html'   => $release['notes'] !== '' ? md_to_html($release['notes']) : '',
    'url'          => $release['url'],
    'published_at' => $release['published_at'],
]);
