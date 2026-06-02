<?php
/**
 * InventorFlow — Exécuteur de migrations (forward-only, idempotent).
 *
 *   php maintenance/migrate.php           # applique les migrations en attente
 *   php maintenance/migrate.php --status  # affiche l'état sans rien appliquer
 *
 * RÈGLE DE SÛRETÉ : les migrations sont UNIQUEMENT additives
 * (CREATE TABLE IF NOT EXISTS, ALTER TABLE ... ADD COLUMN ...). On ne supprime
 * et on n'écrase JAMAIS de données existantes. Chaque migration ne s'applique
 * qu'une seule fois (suivi dans la table `schema_version`).
 *
 * Fichiers : migrations/NNN_description.sql (NNN = entier croissant).
 */

require_once __DIR__ . '/../config/db.php';

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_version (
    version    INT          NOT NULL PRIMARY KEY,
    name       VARCHAR(190) NOT NULL,
    applied_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$status_only = in_array('--status', $argv, true);

$dir   = __DIR__ . '/../migrations';
$files = is_dir($dir) ? (glob($dir . '/[0-9]*.sql') ?: []) : [];
sort($files, SORT_STRING);

$applied = array_map('intval', $pdo->query("SELECT version FROM schema_version")->fetchAll(PDO::FETCH_COLUMN));

$pending = [];
foreach ($files as $f) {
    if (!preg_match('/(\d+)_/', basename($f), $m)) {
        continue;
    }
    $v = (int)$m[1];
    if (!in_array($v, $applied, true)) {
        $pending[$v] = $f;
    }
}
ksort($pending);

if ($status_only) {
    sort($applied);
    echo "Appliquées : " . (empty($applied) ? '(aucune)' : implode(', ', $applied)) . "\n";
    echo "En attente : " . (empty($pending) ? '(aucune)' : implode(', ', array_keys($pending))) . "\n";
    exit(0);
}

if (empty($pending)) {
    echo "Base à jour — aucune migration à appliquer.\n";
    exit(0);
}

$record = $pdo->prepare("INSERT INTO schema_version (version, name) VALUES (?, ?)");
foreach ($pending as $v => $f) {
    $sql = (string)file_get_contents($f);
    echo "→ Migration {$v} : " . basename($f) . "\n";
    try {
        $pdo->exec($sql);
        $record->execute([$v, basename($f)]);
        echo "  ✓ appliquée\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, "  ✗ ÉCHEC migration {$v} : " . $e->getMessage() . "\n");
        fwrite(STDERR, "  (migrations additives/idempotentes : corrigez puis relancez)\n");
        exit(1);
    }
}
echo "Migrations terminées.\n";
