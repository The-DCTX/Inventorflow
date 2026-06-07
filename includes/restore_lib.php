<?php
// restore_lib.php — helpers partagés entre api/restore.php (session) et
// recovery.php (page de secours autonome). Aucune dépendance à la session.

function rl_backup_dir(): string {
    return dirname(__DIR__) . '/backups';
}

function rl_recovery_config_path(): string {
    return dirname(__DIR__) . '/config/recovery.php';
}

function rl_recovery_is_configured(): bool {
    $p = rl_recovery_config_path();
    if (!is_file($p)) return false;
    require_once $p;
    return defined('RECOVERY_HASH') && RECOVERY_HASH !== '';
}

function rl_recovery_verify(string $password): bool {
    if (!rl_recovery_is_configured()) return false;
    require_once rl_recovery_config_path();
    return password_verify($password, RECOVERY_HASH);
}

function rl_recovery_set_password(string $password): bool {
    if (strlen($password) < 8) return false;
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $php  = "<?php\n// Mot de passe de restauration de secours — indépendant de la base.\n"
          . "// Régénéré via Sauvegardes → Restauration de secours.\n"
          . "define('RECOVERY_HASH', " . var_export($hash, true) . ");\n";
    return file_put_contents(rl_recovery_config_path(), $php, LOCK_EX) !== false;
}

// Liste les jeux de sauvegarde (paires db/files regroupées par date) du dossier backups/.
function rl_list_backups(): array {
    $dir = rl_backup_dir();
    $sets = [];
    foreach (glob($dir . '/{db-*.sql.gz,files-*.tar.gz}', GLOB_BRACE) ?: [] as $f) {
        $base = basename($f);
        if (preg_match('/^(db|files)-(\d{4}-\d{2}-\d{2})\.(sql|tar)\.gz$/', $base, $m)) {
            $date = $m[2];
            $sets[$date] ??= ['date' => $date, 'db' => null, 'files' => null];
            if ($m[1] === 'db')    $sets[$date]['db']    = ['name' => $base, 'size' => filesize($f)];
            if ($m[1] === 'files') $sets[$date]['files'] = ['name' => $base, 'size' => filesize($f)];
        }
    }
    krsort($sets);
    return array_values($sets);
}

function rl_safe_backup_path(string $name): ?string {
    $name = basename($name);
    $path = rl_backup_dir() . '/' . $name;
    return is_file($path) ? $path : null;
}

// Exploration : liste des fichiers d'une archive tar.gz (chemins relatifs au projet).
function rl_explore_files(string $tarName, int $limit = 4000): array {
    $path = rl_safe_backup_path($tarName);
    if (!$path) return ['error' => 'Archive introuvable'];
    $out = shell_exec('tar tzf ' . escapeshellarg($path) . ' 2>/dev/null | head -n ' . (int)$limit);
    $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$out)), 'strlen'));
    return ['count' => count($lines), 'entries' => $lines];
}

// Exploration : tables d'un dump SQL compressé. Lecture en PHP (pas de shell :
// les backticks de `CREATE TABLE` seraient interprétés par /bin/sh).
function rl_explore_db(string $sqlName): array {
    $path = rl_safe_backup_path($sqlName);
    if (!$path) return ['error' => 'Dump introuvable'];
    $h = gzopen($path, 'rb');
    if (!$h) return ['error' => 'Lecture impossible'];
    $tables = [];
    while (($line = gzgets($h)) !== false) {
        if (preg_match('/CREATE TABLE `([^`]+)`/', $line, $m)) $tables[] = $m[1];
    }
    gzclose($h);
    return ['count' => count($tables), 'tables' => $tables];
}
