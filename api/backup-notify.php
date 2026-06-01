<?php
/**
 * InventorFlow — Backup email notifier
 * Appelé par le script bash après un backup en erreur/warning
 * Usage : php backup-notify.php status message files_size db_size
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/mailer.php';

$status    = $argv[1] ?? 'error';
$message   = $argv[2] ?? '';
$filesSize = $argv[3] ?? '—';
$dbSize    = $argv[4] ?? '—';

try {
    Mailer::notifyBackupError($status, $message, $filesSize, $dbSize);
    echo "Email envoyé\n";
} catch (\Throwable $e) {
    echo "Erreur email : " . $e->getMessage() . "\n";
}
