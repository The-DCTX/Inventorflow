<?php
require_once __DIR__ . '/../config/app.php';
require_auth();

if (!is_superadmin()) {
    header('Location: ' . APP_URL . '/');
    exit;
}

$id = (int)($_POST['client_id'] ?? 0);
if ($id > 0) {
    $stmt = db()->prepare('SELECT id FROM clients WHERE id = ? AND active = 1');
    $stmt->execute([$id]);
    if ($stmt->fetch()) {
        $_SESSION['selected_client_id'] = $id;
    }
}

$redirect = $_SERVER['HTTP_REFERER'] ?? APP_URL . '/';
header('Location: ' . $redirect);
exit;
