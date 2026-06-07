<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$raw = json_decode(file_get_contents('php://input'), true) ?? [];
$current = $raw['current_password'] ?? '';
$new     = $raw['new_password'] ?? '';

if (!$current || !$new) json_error('Champs obligatoires manquants');
if (strlen($new) < 8)   json_error('Minimum 8 caractères');

$pdo = db();
$user_id = (int)($_SESSION['user_id'] ?? 0);

$stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
$stmt->execute([$user_id]);
$row = $stmt->fetch();

if (!$row || !password_verify($current, $row['password'])) {
    json_error('Mot de passe actuel incorrect', 403);
}

$hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
$pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$hash, $user_id]);

// Invalidate session settings cache so next login is clean
unset($_SESSION['_app_settings']);

audit_log('password_change', 'user', (int)(current_user()['id'] ?? 0));
json_success([], 'Mot de passe changé');
