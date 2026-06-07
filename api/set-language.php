<?php
require_once __DIR__ . '/../config/app.php';
require_auth();
require_once __DIR__ . '/../includes/i18n.php';

$raw  = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$lang = $raw['lang'] ?? '';
if (!array_key_exists($lang, i18n_langs())) json_error('Langue invalide');

$uid = (int)(current_user()['id'] ?? 0);
if ($uid) db()->prepare('UPDATE users SET lang = ? WHERE id = ?')->execute([$lang, $uid]);
$_SESSION['user']['lang'] = $lang;
$_SESSION['_lang'] = $lang;

json_success([], 'Langue mise à jour');
