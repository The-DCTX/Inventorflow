<?php
/**
 * InventorFlow — Mailer via Brevo API
 * Pas de dépendance externe — HTTP natif PHP
 */
class Mailer
{
    public static function send(string $to, string $subject, string $body, bool $isHtml = false): void
    {
        $api_key  = app_setting('brevo_api_key');
        $from     = app_setting('smtp_from');
        $fromName = app_setting('smtp_from_name', 'InventorFlow');

        if (!$api_key) throw new \RuntimeException('Clé API Brevo non configurée');
        if (!$from)    throw new \RuntimeException('Adresse expéditeur non configurée');

        $payload = json_encode([
            'sender'      => ['name' => $fromName, 'email' => $from],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'textContent' => $isHtml ? strip_tags($body) : $body,
            'htmlContent' => $isHtml ? $body : nl2br(htmlspecialchars($body)),
        ]);

        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'api-key: ' . $api_key,
            ]),
            'content'         => $payload,
            'timeout'         => 15,
            'ignore_errors'   => true,
        ]]);

        $raw = file_get_contents('https://api.brevo.com/v3/smtp/email', false, $ctx);
        $res = json_decode($raw ?: '{}', true);

        // Succès = réponse contient un messageId
        if (empty($res['messageId'])) {
            $err = $res['message'] ?? ($raw ?: 'Aucune réponse');
            throw new \RuntimeException('Brevo API : ' . $err);
        }
    }

    public static function notifyBackupError(string $status, string $message, string $filesSize, string $dbSize): void
    {
        if (app_setting('notif_backup_error') !== '1') return;
        $to = app_setting('admin_email');
        if (!$to) return;

        $date    = date('d/m/Y à H:i');
        $appName = defined('APP_NAME') ? APP_NAME : 'InventorFlow';
        $appUrl  = defined('APP_SERVER_URL') ? APP_SERVER_URL : '';

        self::send($to,
            "[{$appName}] Backup {$status} — {$date}",
            "Le backup du {$date} a rencontré un problème.\n\n"
            . "Statut   : {$status}\n"
            . "Détail   : {$message}\n"
            . "Fichiers : {$filesSize}\n"
            . "Base     : {$dbSize}\n\n"
            . "Connectez-vous : {$appUrl}/pages/backups.php"
        );
    }
}
