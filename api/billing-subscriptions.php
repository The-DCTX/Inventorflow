<?php
require_once __DIR__ . '/../config/app.php';
if (!is_logged_in()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success'=>false,'error'=>'Non authentifie']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = db();
$raw    = json_decode(file_get_contents('php://input'), true) ?? $_POST;

function calc_subscription_revenue(array $sub, PDO $pdo): array {
    $price = (float)($sub['custom_price'] ?? $sub['service_price'] ?? 0);
    $discount = (float)($sub['discount_pct'] ?? 0);
    $unit = $sub['unit'] ?? 'per_device';
    $period = $sub['billing_period'] ?? 'monthly';
    $client_id = (int)$sub['client_id'];

    // Auto-calculate qty
    if ($sub['qty_override'] !== null && $sub['qty_override'] !== '') {
        $qty = (int)$sub['qty_override'];
    } else {
        $qty = match($unit) {
            'per_device' => (int)$pdo->prepare('SELECT COUNT(*) FROM assets WHERE client_id=? AND status="active" AND billable=1')->execute([$client_id]) ? (int)$pdo->prepare('SELECT COUNT(*) FROM assets WHERE client_id=? AND status="active" AND billable=1')->execute([$client_id]) : 0,
            'per_user'   => 0,
            default      => 1,
        };
        if ($unit === 'per_device') {
            $s = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE client_id=? AND status="active" AND billable=1');
            $s->execute([$client_id]);
            $qty = (int)$s->fetchColumn();
        } elseif ($unit === 'per_user') {
            $s = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id=? AND active=1');
            $s->execute([$client_id]);
            $qty = (int)$s->fetchColumn();
        } elseif ($unit === 'flat') {
            $qty = 1;
        } else {
            $qty = 0;
        }
    }

    $gross   = $price * $qty;
    $net     = $gross * (1 - $discount / 100);
    $monthly = match($period) {
        'annual'   => $net / 12,
        'one_time' => 0,
        default    => $net,
    };

    return ['qty' => $qty, 'gross' => round($gross, 2), 'net' => round($net, 2), 'monthly' => round($monthly, 2)];
}

// Quick GET for client subscriptions (used by asset panel)
if ($method === 'GET') {
    $cid = (int)($_GET['client_id'] ?? current_client_id());
    $stmt = $pdo->prepare('SELECT bs.*, s.name, s.category, s.color, s.price, s.unit, s.billing_period
        FROM billing_subscriptions bs JOIN billing_services s ON bs.service_id=s.id
        WHERE bs.client_id=? AND bs.active=1 ORDER BY s.category');
    $stmt->execute([$cid]);
    json_success($stmt->fetchAll());
}

if ($method === 'POST') {
    $client_id  = (int)($raw['client_id'] ?? current_client_id());
    $service_id = (int)($raw['service_id'] ?? 0);
    if (!$client_id || !$service_id) json_error('client_id et service_id requis');

    $pdo->prepare('INSERT INTO billing_subscriptions (client_id, service_id, qty_override, discount_pct, custom_price, notes, active, start_date) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$client_id, $service_id,
            $raw['qty_override'] !== '' ? ($raw['qty_override'] ?: null) : null,
            (float)($raw['discount_pct'] ?? 0),
            $raw['custom_price'] !== '' ? ($raw['custom_price'] ?: null) : null,
            $raw['notes'] ?: null,
            (int)($raw['active'] ?? 1),
            $raw['start_date'] ?: date('Y-m-d')]);
    json_success(['id' => (int)$pdo->lastInsertId()], 'Abonnement créé');
}

if ($method === 'PUT') {
    $id = (int)($raw['id'] ?? 0);
    if (!$id) json_error('ID manquant');
    $pdo->prepare('UPDATE billing_subscriptions SET qty_override=?,discount_pct=?,custom_price=?,notes=?,active=? WHERE id=?')
        ->execute([
            $raw['qty_override'] !== '' ? ($raw['qty_override'] ?: null) : null,
            (float)($raw['discount_pct'] ?? 0),
            $raw['custom_price'] !== '' ? ($raw['custom_price'] ?: null) : null,
            $raw['notes'] ?: null,
            (int)($raw['active'] ?? 1),
            $id]);
    json_success([], 'Abonnement mis à jour');
}

if ($method === 'DELETE') {
    $id = (int)($raw['id'] ?? 0);
    $pdo->prepare('DELETE FROM billing_subscriptions WHERE id=?')->execute([$id]);
    json_success([], 'Abonnement supprimé');
}

json_error('Méthode non supportée', 405);
