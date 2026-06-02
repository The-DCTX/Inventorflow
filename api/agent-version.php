<?php
/**
 * InventorFlow — Version courante du produit (pour l'auto-update des agents).
 *
 * GET /api/agent-version.php  ->  {"version":"1.0.6"}
 *
 * Lecture seule, aucune donnée sensible : renvoie uniquement le numéro de
 * version lu dans le fichier VERSION à la racine. Pas d'authentification
 * requise (un agent compare ce numéro au sien avant de se mettre à jour).
 */
header('Content-Type: application/json');
header('Cache-Control: no-store');

$version = '0.0.0';
$file = __DIR__ . '/../VERSION';
if (is_file($file)) {
    $raw = trim((string)@file_get_contents($file));
    if (preg_match('/\d+\.\d+\.\d+/', $raw, $m)) {
        $version = $m[0];
    }
}

echo json_encode(['version' => $version]);
