<?php
require_once __DIR__ . "/../config/app.php";
require_auth();

$client_id = (int)($_GET["client_id"] ?? 0);
if (!$client_id) json_error("client_id requis");

$pdo = db();

$devices_stmt = $pdo->prepare("SELECT id, hostname, os_type, status, model FROM assets WHERE client_id = ? AND status = \"active\" ORDER BY hostname");
$devices_stmt->execute([$client_id]);
$devices = $devices_stmt->fetchAll();

$users_stmt = $pdo->prepare("SELECT e.id, e.first_name, e.last_name, e.position, d.name as dept_name FROM employees e LEFT JOIN departments d ON e.department_id = d.id WHERE e.client_id = ? AND e.active = 1 ORDER BY e.last_name, e.first_name");
$users_stmt->execute([$client_id]);
$users = $users_stmt->fetchAll();

json_success([
    "device_count" => count($devices),
    "user_count"   => count($users),
    "devices"      => $devices,
    "users"        => $users,
]);
