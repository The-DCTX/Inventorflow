<?php
/**
 * InventorFlow — Auto-registration endpoint
 * Called by the inventorflow-agent.sh script on Linux/macOS workstations.
 *
 * POST /api/register.php
 * Header: X-API-Key: <key>
 * Body:   JSON payload with machine info
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// Authenticate via API key
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!$api_key || strlen($api_key) < 16) {
    json_error('Missing or invalid API key', 401);
}

$pdo = db();
$key_hash = hash('sha256', $api_key);

$stmt = $pdo->prepare('SELECT k.*, c.id as cid, c.code as client_code
    FROM api_keys k JOIN clients c ON k.client_id = c.id
    WHERE k.key_hash = ? AND k.active = 1 AND c.active = 1');
$stmt->execute([$key_hash]);
$key_row = $stmt->fetch();

if (!$key_row) {
    json_error('Invalid or inactive API key', 401);
}

$client_id = (int)$key_row['cid'];

// Update key usage stats
$pdo->prepare('UPDATE api_keys SET last_used = NOW(), used_count = used_count + 1 WHERE id = ?')
    ->execute([$key_row['id']]);

// Parse body
$raw = json_decode(file_get_contents('php://input'), true);
if (!$raw) {
    json_error('Invalid JSON body', 400);
}

// Sanitize inputs
$hostname      = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '-', trim($raw['hostname'] ?? '')));
$os_type       = strtoupper(trim($raw['os_type']       ?? ''));
$os_version    = trim($raw['os_version']    ?? '');
$brand         = trim($raw['brand']         ?? '');
$model         = trim($raw['model']         ?? '');
$cpu           = trim($raw['cpu']           ?? '');
$ram_gb        = isset($raw['ram_gb'])  ? (int)$raw['ram_gb']  : null;
$storage_gb    = isset($raw['storage_gb']) ? (int)$raw['storage_gb'] : null;
$storage_type  = trim($raw['storage_type'] ?? '');
$serial_number = trim($raw['serial_number'] ?? '');
$ip_address    = trim($raw['ip_address']    ?? '');
$mac_address   = trim($raw['mac_address']   ?? '');
$dept_code     = strtoupper(trim($raw['dept_code'] ?? ''));

if (!in_array($os_type, ['MAC', 'WIN', 'LIN'])) {
    json_error('os_type must be MAC, WIN or LIN', 400);
}

// Resolve department
$dept_id = null;
if ($dept_code) {
    $d = $pdo->prepare('SELECT id FROM departments WHERE client_id = ? AND code = ?');
    $d->execute([$client_id, $dept_code]);
    $dept = $d->fetch();
    if ($dept) $dept_id = (int)$dept['id'];
}

// Find existing asset by serial or hostname
$existing = null;
if ($serial_number) {
    $s = $pdo->prepare('SELECT * FROM assets WHERE client_id = ? AND serial_number = ? LIMIT 1');
    $s->execute([$client_id, $serial_number]);
    $existing = $s->fetch();
}
if (!$existing && $hostname) {
    $s = $pdo->prepare('SELECT * FROM assets WHERE client_id = ? AND hostname = ? LIMIT 1');
    $s->execute([$client_id, $hostname]);
    $existing = $s->fetch();
}

// Build update fields
$fields = array_filter([
    'os_version'    => $os_version    ?: null,
    'brand'         => $brand         ?: null,
    'model'         => $model         ?: null,
    'cpu'           => $cpu           ?: null,
    'ram_gb'        => $ram_gb,
    'storage_gb'    => $storage_gb,
    'storage_type'  => in_array($storage_type, ['SSD','NVMe','HDD']) ? $storage_type : null,
    'ip_address'    => $ip_address    ?: null,
    'mac_address'   => $mac_address   ?: null,
    'serial_number' => $serial_number ?: null,
    'department_id' => $dept_id,
], fn($v) => $v !== null && $v !== '');

if ($existing) {
    // UPDATE existing asset
    if (!empty($fields)) {
        $set_clause = implode(', ', array_map(fn($k) => "`{$k}` = ?", array_keys($fields)));
        $values = array_values($fields);
        $values[] = $existing['id'];
        $pdo->prepare("UPDATE assets SET {$set_clause}, updated_at = NOW() WHERE id = ?")
            ->execute($values);
    }

    // Log if IP changed (useful for tracking)
    if ($ip_address && $ip_address !== $existing['ip_address']) {
        $user_id = null;
        $stmt = $pdo->prepare('INSERT INTO asset_history (asset_id, action, from_value, to_value, notes, performed_by) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$existing['id'], 'updated', $existing['ip_address'], $ip_address, 'Auto-update via agent', null]);
    }

    json_success([
        'action'   => 'updated',
        'asset_id' => $existing['id'],
        'hostname' => $existing['hostname'],
    ], 'Asset updated');

} else {
    // CREATE new asset — generate hostname if not provided
    if (!$hostname) {
        $hostname = generate_hostname($client_id, $os_type, $dept_id ?? 0);
    }

    $stmt = $pdo->prepare('INSERT INTO assets
        (client_id, hostname, serial_number, os_type, os_version, brand, model, cpu,
         ram_gb, storage_gb, storage_type, ip_address, mac_address, department_id, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $client_id, $hostname,
        $serial_number ?: null, $os_type, $os_version ?: null,
        $brand ?: null, $model ?: null, $cpu ?: null,
        $ram_gb, $storage_gb,
        in_array($storage_type, ['SSD','NVMe','HDD']) ? $storage_type : null,
        $ip_address ?: null, $mac_address ?: null,
        $dept_id, 'active',
    ]);

    $asset_id = (int)$pdo->lastInsertId();
    $log = $pdo->prepare('INSERT INTO asset_history (asset_id, action, notes) VALUES (?,?,?)');
    $log->execute([$asset_id, 'created', 'Auto-registered via agent']);

    auto_assign_monitoring($client_id);
    json_success([
        'action'   => 'created',
        'asset_id' => $asset_id,
        'hostname' => $hostname,
    ], 'Asset registered');
}
