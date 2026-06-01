<?php
/**
 * InventorFlow — Monitoring data ingestion endpoint
 * Called by the inventorflow-agent.sh script after the register call.
 *
 * POST /api/monitoring.php
 * Header: X-API-Key: <key>
 * Body:   JSON payload with metrics and security events
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// ── GET: UI requests latest snapshot for an asset ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once __DIR__ . '/../config/app.php';
    if (!is_logged_in()) json_error('Non authentifié', 401);

    $pdo      = db();
    $asset_id = (int)($_GET['asset_id'] ?? 0);
    if (!$asset_id) json_error('asset_id requis');

    $snap = $pdo->prepare(
        'SELECT ms.*, a.hostname
         FROM monitoring_snapshots ms
         JOIN assets a ON ms.asset_id = a.id
         WHERE ms.asset_id = ?
         ORDER BY ms.collected_at DESC LIMIT 1'
    );
    $snap->execute([$asset_id]);
    $row = $snap->fetch(PDO::FETCH_ASSOC);

    json_success($row ?: null);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Method not allowed', 405);
}

// Authenticate via API key (same mechanism as register.php)
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? '';
if (!$api_key || strlen($api_key) < 16) {
    json_error('Missing or invalid API key', 401);
}

$pdo = db();
$key_hash = hash('sha256', $api_key);

$stmt = $pdo->prepare('SELECT k.*, c.id as cid
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

// Resolve asset by hostname + client_id
$hostname = strtoupper(trim($raw['hostname'] ?? ''));
if (!$hostname) {
    json_error('hostname is required', 400);
}

$asset_stmt = $pdo->prepare('SELECT id FROM assets WHERE client_id = ? AND hostname = ? LIMIT 1');
$asset_stmt->execute([$client_id, $hostname]);
$asset = $asset_stmt->fetch();

if (!$asset) {
    json_error('Asset not found — run the register call first', 404);
}

$asset_id = (int)$asset['id'];

// ── INSERT MONITORING SNAPSHOT ───────────────────────────────

$cpu_pct       = isset($raw['cpu_pct'])       ? (float)$raw['cpu_pct']       : null;
$ram_used_mb   = isset($raw['ram_used_mb'])   ? (int)$raw['ram_used_mb']     : null;
$ram_total_mb  = isset($raw['ram_total_mb'])  ? (int)$raw['ram_total_mb']    : null;
$disk_used_gb  = isset($raw['disk_used_gb'])  ? (int)$raw['disk_used_gb']    : null;
$disk_total_gb = isset($raw['disk_total_gb']) ? (int)$raw['disk_total_gb']   : null;
$load_1m       = isset($raw['load_1m'])       ? (float)$raw['load_1m']       : null;
$load_5m       = isset($raw['load_5m'])       ? (float)$raw['load_5m']       : null;
$load_15m      = isset($raw['load_15m'])      ? (float)$raw['load_15m']      : null;
$uptime_sec    = isset($raw['uptime_seconds']) ? (int)$raw['uptime_seconds'] : null;
$proc_count    = isset($raw['process_count']) ? (int)$raw['process_count']   : null;
$temp_celsius    = (isset($raw['temp_celsius']) && $raw['temp_celsius'] !== null && $raw['temp_celsius'] !== 'null') ? (float)$raw['temp_celsius'] : null;
$thermal_state   = (isset($raw['thermal_state']) && in_array($raw['thermal_state'], ['nominal','high','critical'])) ? $raw['thermal_state'] : null;

$pdo->prepare('INSERT INTO monitoring_snapshots
    (asset_id, cpu_pct, ram_used_mb, ram_total_mb, disk_used_gb, disk_total_gb,
     load_1m, load_5m, load_15m, uptime_seconds, process_count, temp_celsius, thermal_state)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([
        $asset_id, $cpu_pct, $ram_used_mb, $ram_total_mb,
        $disk_used_gb, $disk_total_gb,
        $load_1m, $load_5m, $load_15m,
        $uptime_sec, $proc_count, $temp_celsius, $thermal_state,
    ]);

// ── INSERT SECURITY EVENTS (brute force, deduplicated by source_ip + hour) ──

$brute_entries   = is_array($raw['brute_force'] ?? null) ? $raw['brute_force'] : [];
$failed_auth_1h  = isset($raw['failed_auth_1h'])  ? (int)$raw['failed_auth_1h']  : 0;
$failed_auth_24h = isset($raw['failed_auth_24h']) ? (int)$raw['failed_auth_24h'] : 0;
$events_inserted = 0;

foreach ($brute_entries as $entry) {
    $source_ip    = trim($entry['source_ip'] ?? '');
    $attempt_count = max(1, (int)($entry['attempts'] ?? 1));

    if (!$source_ip) continue;

    // Determine severity based on attempt count
    if ($attempt_count >= 100) {
        $severity = 'critical';
    } elseif ($attempt_count >= 20) {
        $severity = 'high';
    } elseif ($attempt_count >= 5) {
        $severity = 'medium';
    } else {
        $severity = 'low';
    }

    // Deduplicate: only insert if no existing brute_force event for this
    // asset + source_ip within the current hour window
    $dedup = $pdo->prepare('SELECT id FROM security_events
        WHERE asset_id = ? AND event_type = "brute_force" AND source_ip = ?
        AND detected_at >= DATE_FORMAT(NOW(), "%Y-%m-%d %H:00:00")
        LIMIT 1');
    $dedup->execute([$asset_id, $source_ip]);

    if ($dedup->fetch()) {
        // Update attempt count to highest seen this hour
        $pdo->prepare('UPDATE security_events SET attempt_count = GREATEST(attempt_count, ?)
            WHERE asset_id = ? AND event_type = "brute_force" AND source_ip = ?
            AND detected_at >= DATE_FORMAT(NOW(), "%Y-%m-%d %H:00:00")')
            ->execute([$attempt_count, $asset_id, $source_ip]);
    } else {
        $details = "Failed auth attempts from {$source_ip}: {$attempt_count} in last 24h";
        $pdo->prepare('INSERT INTO security_events
            (asset_id, event_type, severity, source_ip, attempt_count, details)
            VALUES (?,?,?,?,?,?)')
            ->execute([$asset_id, 'brute_force', $severity, $source_ip, $attempt_count, $details]);
        $events_inserted++;
    }
}

// Also insert a summary failed_auth event if there were failures last hour
// and we have no per-IP brute force entries (e.g. only a count was reported)
if ($failed_auth_1h > 0 && empty($brute_entries)) {
    $dedup_auth = $pdo->prepare('SELECT id FROM security_events
        WHERE asset_id = ? AND event_type = "failed_auth"
        AND detected_at >= DATE_FORMAT(NOW(), "%Y-%m-%d %H:00:00")
        LIMIT 1');
    $dedup_auth->execute([$asset_id]);

    if (!$dedup_auth->fetch()) {
        $sev = $failed_auth_1h >= 20 ? 'high' : ($failed_auth_1h >= 5 ? 'medium' : 'low');
        $pdo->prepare('INSERT INTO security_events
            (asset_id, event_type, severity, attempt_count, details)
            VALUES (?,?,?,?,?)')
            ->execute([
                $asset_id, 'failed_auth', $sev, $failed_auth_1h,
                "Failed auth attempts this hour: {$failed_auth_1h} (24h total: {$failed_auth_24h})",
            ]);
        $events_inserted++;
    }
}

// ── CLEANUP SNAPSHOTS OLDER THAN 30 DAYS ────────────────────

$pdo->prepare('DELETE FROM monitoring_snapshots WHERE asset_id = ? AND collected_at < NOW() - INTERVAL 30 DAY')
    ->execute([$asset_id]);

json_success([
    'asset_id'        => $asset_id,
    'hostname'        => $hostname,
    'events_inserted' => $events_inserted,
], 'Monitoring data recorded');
