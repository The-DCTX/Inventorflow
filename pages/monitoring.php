<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();

// ── LATEST SNAPSHOT PER ASSET ────────────────────────────────
// One row per asset: most recent monitoring_snapshots entry
$snap_stmt = $pdo->prepare('
    SELECT a.id AS asset_id, a.hostname, a.os_type, a.ip_address,
           a.status as asset_status,
           ms.cpu_pct, ms.ram_used_mb, ms.ram_total_mb,
           ms.disk_used_gb, ms.disk_total_gb,
           ms.load_1m, ms.load_5m, ms.load_15m,
           ms.uptime_seconds, ms.process_count, ms.temp_celsius, ms.thermal_state,
           ms.collected_at
    FROM assets a
    LEFT JOIN monitoring_snapshots ms ON ms.id = (
        SELECT id FROM monitoring_snapshots
        WHERE asset_id = a.id
        ORDER BY collected_at DESC LIMIT 1
    )
    WHERE a.client_id = ?
    ORDER BY ms.collected_at DESC, a.hostname
');
$snap_stmt->execute([$client_id]);
$snapshots = $snap_stmt->fetchAll();

// ── SECURITY EVENTS — LAST 48H ────────────────────────────────
$sec_stmt = $pdo->prepare('
    SELECT se.*, a.hostname, a.os_type
    FROM security_events se
    JOIN assets a ON se.asset_id = a.id
    WHERE a.client_id = ?
      AND se.detected_at >= NOW() - INTERVAL 48 HOUR
    ORDER BY se.detected_at DESC
    LIMIT 200
');
$sec_stmt->execute([$client_id]);
$security_events = $sec_stmt->fetchAll();

// ── COMPUTE KPIs ─────────────────────────────────────────────
$machines_monitored = count($snapshots);

// Active alerts: CPU > 90% or RAM > 90% or disk > 90%
$active_alerts = 0;
$machines_with_incidents = 0;
$total_cpu = 0;
$cpu_count  = 0;

// Collect asset IDs with security incidents
$incident_asset_ids = array_unique(array_column($security_events, 'asset_id'));

foreach ($snapshots as $snap) {
    $alert = false;
    // Alert on CPU/RAM/disk AND on "possibly offline" (>24h without heartbeat)
    $seen_status = last_seen_status($snap['collected_at'] ?? null);
    if ($seen_status['alert']) { $active_alerts++; $alert = true; }
    if (!empty($snap['collected_at']) && $snap['cpu_pct'] !== null && (float)$snap['cpu_pct'] > 90) { $active_alerts++; $alert = true; }
    if ($snap['ram_total_mb'] > 0 && $snap['ram_used_mb'] !== null) {
        $ram_pct = (float)$snap['ram_used_mb'] / (float)$snap['ram_total_mb'] * 100;
        if ($ram_pct > 90) { $active_alerts++; $alert = true; }
    }
    if ($snap['disk_total_gb'] > 0 && $snap['disk_used_gb'] !== null) {
        $disk_pct = (float)$snap['disk_used_gb'] / (float)$snap['disk_total_gb'] * 100;
        if ($disk_pct > 90) { $active_alerts++; $alert = true; }
    }
    if (!empty($snap['collected_at']) && $snap['cpu_pct'] !== null) { $total_cpu += (float)$snap['cpu_pct']; $cpu_count++; }
    if (in_array($snap['asset_id'], $incident_asset_ids)) $machines_with_incidents++;
}

$avg_cpu = $cpu_count > 0 ? round($total_cpu / $cpu_count, 1) : 0;

// ── HELPERS ──────────────────────────────────────────────────

function pct_color(float $pct): string {
    if ($pct >= 90) return 'var(--danger)';
    if ($pct >= 70) return 'var(--warning)';
    return 'var(--success)';
}

function pct_bar_html(float $pct, string $label): string {
    $color = pct_color($pct);
    $w     = min(100, round($pct));
    return '<div style="min-width:90px">'
         . '<div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-secondary);margin-bottom:3px">'
         . '<span>' . h($label) . '</span><span style="color:' . $color . ';font-weight:600">' . round($pct) . '%</span></div>'
         . '<div style="height:5px;border-radius:3px;background:var(--bg-hover);overflow:hidden">'
         . '<div style="height:100%;width:' . $w . '%;background:' . $color . ';border-radius:3px;transition:width .4s"></div>'
         . '</div></div>';
}

function uptime_human(int $seconds): string {
    if ($seconds <= 0) return '—';
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    $m = intdiv($seconds % 3600, 60);
    if ($d > 0) return "{$d}j {$h}h";
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}

function last_seen_status(?string $collected_at): array {
    if (!$collected_at) return ['label' => 'En attente',         'color' => 'var(--text-muted)', 'bg' => 'var(--bg-elevated)',   'alert' => false];
    $diff = time() - strtotime($collected_at);
    // Cron horaire : 65 min de grâce (60 min + 5 min)
    if ($diff < 3900)  return ['label' => 'En ligne',           'color' => 'var(--success)', 'bg' => 'var(--success-dim)',  'alert' => false];
    if ($diff < 86400) return ['label' => 'Récemment vu',       'color' => 'var(--warning)', 'bg' => 'var(--warning-dim)',  'alert' => false];
    return                    ['label' => 'Possiblement éteint','color' => 'var(--danger)',  'bg' => 'var(--danger-dim)',    'alert' => true];
}

$severity_cfg = [
    'low'      => ['label' => 'Faible',   'color' => 'var(--success)', 'bg' => 'var(--success-dim)'],
    'medium'   => ['label' => 'Moyen',    'color' => 'var(--warning)', 'bg' => 'var(--warning-dim)'],
    'high'     => ['label' => 'Élevé',    'color' => '#fb923c',        'bg' => 'rgba(251,146,60,.12)'],
    'critical' => ['label' => 'Critique', 'color' => 'var(--danger)',  'bg' => 'var(--danger-dim)'],
];

$event_type_labels = [
    'brute_force'  => 'Brute force',
    'failed_auth'  => 'Auth échouée',
    'port_scan'    => 'Port scan',
    'ssh_success'  => 'SSH réussi',
    'other'        => 'Autre',
];

render_head('Supervision');
render_icons();
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php render_sidebar('monitoring'); ?>
<div class="main-wrapper">
<?php render_topbar('Supervision', 'Métriques temps réel · sécurité · ' . date('d F Y')); ?>

<main class="main-content">

<!-- ── KPI ROW ───────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">

    <!-- Machines supervisées -->
    <div class="card" style="background:linear-gradient(135deg,rgba(79,126,248,.08),rgba(79,126,248,.03));border-color:rgba(79,126,248,.2)">
        <div class="card-body" style="padding:20px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                <div style="width:34px;height:34px;border-radius:var(--radius-sm);background:var(--accent-dim);display:flex;align-items:center;justify-content:center">
                    <svg class="icon-md" style="color:var(--accent)"><use href="#icon-monitor"/></svg>
                </div>
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">Supervisées</span>
            </div>
            <div style="font-size:32px;font-weight:800;color:var(--accent);font-variant-numeric:tabular-nums;line-height:1"><?= $machines_monitored ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px">machines actives</div>
        </div>
    </div>

    <!-- Alertes actives -->
    <div class="card" style="background:linear-gradient(135deg,<?= $active_alerts > 0 ? 'rgba(255,71,87,.08),rgba(255,71,87,.03));border-color:rgba(255,71,87,.2)' : 'rgba(34,211,160,.08),rgba(34,211,160,.03));border-color:rgba(34,211,160,.2)' ?>">
        <div class="card-body" style="padding:20px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                <div style="width:34px;height:34px;border-radius:var(--radius-sm);background:<?= $active_alerts > 0 ? 'var(--danger-dim)' : 'var(--success-dim)' ?>;display:flex;align-items:center;justify-content:center">
                    <svg class="icon-md" style="color:<?= $active_alerts > 0 ? 'var(--danger)' : 'var(--success)' ?>"><use href="#icon-alert"/></svg>
                </div>
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">Alertes</span>
            </div>
            <div style="font-size:32px;font-weight:800;color:<?= $active_alerts > 0 ? 'var(--danger)' : 'var(--success)' ?>;font-variant-numeric:tabular-nums;line-height:1"><?= $active_alerts ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px"><?= $active_alerts > 0 ? 'seuils critiques dépassés' : 'tout est nominal' ?></div>
        </div>
    </div>

    <!-- CPU moyen -->
    <div class="card">
        <div class="card-body" style="padding:20px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                <div style="width:34px;height:34px;border-radius:var(--radius-sm);background:var(--cyan-dim);display:flex;align-items:center;justify-content:center">
                    <svg class="icon-md" style="color:var(--cyan)"><use href="#icon-cpu"/></svg>
                </div>
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">CPU moyen</span>
            </div>
            <div style="font-size:32px;font-weight:800;color:<?= pct_color($avg_cpu) ?>;font-variant-numeric:tabular-nums;line-height:1"><?= $avg_cpu ?>%</div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px">sur <?= $cpu_count ?> machine<?= $cpu_count !== 1 ? 's' : '' ?></div>
        </div>
    </div>

    <!-- Incidents de sécurité -->
    <div class="card" style="<?= $machines_with_incidents > 0 ? 'background:linear-gradient(135deg,rgba(255,71,87,.06),transparent);border-color:rgba(255,71,87,.15)' : '' ?>">
        <div class="card-body" style="padding:20px">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                <div style="width:34px;height:34px;border-radius:var(--radius-sm);background:<?= $machines_with_incidents > 0 ? 'var(--danger-dim)' : 'var(--purple-dim)' ?>;display:flex;align-items:center;justify-content:center">
                    <svg class="icon-md" style="color:<?= $machines_with_incidents > 0 ? 'var(--danger)' : 'var(--purple)' ?>"><use href="#icon-key"/></svg>
                </div>
                <span style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">Incidents</span>
            </div>
            <div style="font-size:32px;font-weight:800;color:<?= $machines_with_incidents > 0 ? 'var(--danger)' : 'var(--success)' ?>;font-variant-numeric:tabular-nums;line-height:1"><?= $machines_with_incidents ?></div>
            <div style="font-size:12px;color:var(--text-muted);margin-top:4px">machine<?= $machines_with_incidents !== 1 ? 's' : '' ?> avec événements 48h</div>
        </div>
    </div>
</div>

<!-- ── ASSETS TABLE ───────────────────────────────────────── -->
<div class="card" style="margin-bottom:24px">
    <div class="card-header">
        <div class="card-title">État du parc supervisé</div>
        <div class="card-subtitle">Dernière collecte par machine</div>
    </div>
    <?php if (empty($snapshots)): ?>
    <div class="card-body">
        <div class="empty-state" style="padding:48px 24px;text-align:center">
            <svg class="icon-xl" style="width:48px;height:48px;color:var(--text-muted);margin:0 auto 16px;display:block"><use href="#icon-monitor"/></svg>
            <p style="font-size:15px;font-weight:600;color:var(--text-secondary);margin-bottom:8px">Aucune donnée de supervision</p>
            <p style="font-size:13px;color:var(--text-muted);max-width:420px;margin:0 auto 20px">
                Déployez et exécutez l'agent <code style="background:var(--bg-elevated);padding:2px 6px;border-radius:4px;font-size:12px">inventorflow-agent.sh</code>
                sur vos machines pour commencer à collecter des métriques.
            </p>
            <a href="<?= APP_URL ?>/pages/assets.php" class="btn btn-secondary btn-sm">
                <svg><use href="#icon-monitor"/></svg> Voir le parc
            </a>
        </div>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="table" style="min-width:900px">
            <thead>
                <tr>
                    <th>Machine</th>
                    <th>CPU</th>
                    <th>RAM</th>
                    <th>Disque</th>
                    <th>Load 1m / 5m</th>
                    <th>Uptime</th>
                    <th>Température</th>
                    <th>Processus</th>
                    <th>Dernière vue</th>
                    <th>Sécurité</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($snapshots as $snap):
                $cpu_pct   = $snap['cpu_pct'] !== null   ? (float)$snap['cpu_pct']   : null;
                $ram_pct   = ($snap['ram_total_mb'] > 0 && $snap['ram_used_mb'] !== null)
                             ? (float)$snap['ram_used_mb'] / (float)$snap['ram_total_mb'] * 100 : null;
                $disk_pct  = ($snap['disk_total_gb'] > 0 && $snap['disk_used_gb'] !== null)
                             ? (float)$snap['disk_used_gb'] / (float)$snap['disk_total_gb'] * 100 : null;
                $seen      = last_seen_status($snap['collected_at']);
                $has_incident = in_array($snap['asset_id'], $incident_asset_ids);
            ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:8px">
                        <?= os_badge($snap['os_type']) ?>
                        <div>
                            <div style="font-weight:600;font-size:13.5px"><?= h($snap['hostname']) ?></div>
                            <?php if ($snap['ip_address']): ?>
                            <div style="font-size:11.5px;color:var(--text-muted)"><?= h($snap['ip_address']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if ($cpu_pct !== null): ?>
                    <?= pct_bar_html($cpu_pct, 'CPU') ?>
                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($ram_pct !== null): ?>
                    <?= pct_bar_html($ram_pct, round($snap['ram_used_mb'] / 1024, 1) . '/' . round($snap['ram_total_mb'] / 1024, 1) . ' GB') ?>
                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($disk_pct !== null): ?>
                    <?= pct_bar_html($disk_pct, $snap['disk_used_gb'] . '/' . $snap['disk_total_gb'] . ' GB') ?>
                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($snap['load_1m'] !== null): ?>
                    <span style="font-variant-numeric:tabular-nums;font-size:13px"><?= number_format((float)$snap['load_1m'], 2) ?></span>
                    <span style="color:var(--text-muted);font-size:12px"> / <?= number_format((float)$snap['load_5m'], 2) ?></span>
                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                </td>
                <td style="font-size:13px;font-variant-numeric:tabular-nums">
                    <?= $snap['uptime_seconds'] !== null ? uptime_human((int)$snap['uptime_seconds']) : '—' ?>
                </td>
                <td style="font-size:13px;font-variant-numeric:tabular-nums">
                    <?php
                    $temp    = $snap['temp_celsius'];
                    $thermal = $snap['thermal_state'];
                    if ($thermal !== null) {
                        // Apple Silicon: afficher l'état thermique
                        $th_cfg = [
                            'nominal'  => ['label'=>'Nominal',   'color'=>'var(--success)', 'bg'=>'var(--success-dim)'],
                            'high'     => ['label'=>'Surchauffe','color'=>'var(--danger)',  'bg'=>'var(--danger-dim)'],
                            'critical' => ['label'=>'Throttling','color'=>'var(--warning)', 'bg'=>'var(--warning-dim)'],
                        ];
                        $cfg = $th_cfg[$thermal] ?? ['label'=>ucfirst($thermal),'color'=>'var(--text-secondary)','bg'=>'var(--bg-elevated)'];
                        echo '<span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;padding:2px 8px;border-radius:20px;background:'.$cfg['bg'].';color:'.$cfg['color'].'">'.$cfg['label'].'</span>';
                        // Comptage des alertes thermiques
                        if ($thermal === 'high') { $active_alerts++; }
                    } elseif ($temp !== null) {
                        // Linux/Intel: afficher la température numérique
                        $tc = (float)$temp;
                        $t_color = $tc >= 80 ? 'var(--danger)' : ($tc >= 60 ? 'var(--warning)' : 'var(--success)');
                        $t_bg    = $tc >= 80 ? 'var(--danger-dim)' : ($tc >= 60 ? 'var(--warning-dim)' : 'var(--success-dim)');
                        echo '<span style="display:inline-flex;align-items:center;gap:4px;font-size:13px;font-weight:700;padding:2px 8px;border-radius:20px;background:'.$t_bg.';color:'.$t_color.';font-variant-numeric:tabular-nums">'.$tc.'°C</span>';
                    } else {
                        echo '<span style="color:var(--text-muted);font-size:12px">N/A</span>';
                    }
                    ?>
                    </td>
                    <td>
                    <?= $snap['process_count'] !== null ? number_format((int)$snap['process_count']) : '—' ?>
                </td>
                <td>
                    <div style="display:flex;flex-direction:column;gap:3px">
                        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600;padding:2px 8px;border-radius:20px;background:<?= $seen['bg'] ?>;color:<?= $seen['color'] ?>">
                            <span style="width:6px;height:6px;border-radius:50%;background:currentColor"></span>
                            <?= h($seen['label']) ?>
                        </span>
                        <span style="font-size:11px;color:var(--text-muted)"><?= $snap['collected_at'] ? time_ago($snap['collected_at']) : '' ?></span>
                    </div>
                </td>
                <td>
                    <?php if ($has_incident): ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600;padding:2px 8px;border-radius:20px;background:var(--danger-dim);color:var(--danger)">
                        <svg style="width:11px;height:11px"><use href="#icon-alert"/></svg> Incident
                    </span>
                    <?php else: ?>
                    <span style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:600;padding:2px 8px;border-radius:20px;background:var(--success-dim);color:var(--success)">
                        <svg style="width:11px;height:11px"><use href="#icon-check"/></svg> OK
                    </span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ── SECURITY EVENTS ────────────────────────────────────── -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Événements de sécurité</div>
            <div class="card-subtitle">Dernières 48 heures · <?= count($security_events) ?> événement<?= count($security_events) !== 1 ? 's' : '' ?></div>
        </div>
        <?php if (!empty($security_events)): ?>
        <button class="btn btn-secondary btn-sm" style="color:var(--success);border-color:rgba(34,211,160,.3)" onclick="resetAll()">
            <svg><use href="#icon-check"/></svg> Tout réinitialiser
        </button>
        <?php endif; ?>
    </div>
    <?php if (empty($security_events)): ?>
    <div class="card-body">
        <div class="empty-state" style="padding:32px;text-align:center">
            <svg class="icon-xl" style="width:40px;height:40px;color:var(--success);margin:0 auto 12px;display:block"><use href="#icon-check"/></svg>
            <p style="font-size:14px;font-weight:600;color:var(--success)">Aucun événement de sécurité détecté</p>
            <p style="font-size:12.5px;color:var(--text-muted);margin-top:6px">Les 48 dernières heures sont propres.</p>
        </div>
    </div>
    <?php else: ?>
    <div style="overflow-x:auto">
        <table class="table" style="min-width:760px">
            <thead>
                <tr>
                    <th>Sévérité</th>
                    <th>Type</th>
                    <th>Machine</th>
                    <th>IP source</th>
                    <th>Tentatives</th>
                    <th>Détails</th>
                    <th>Détecté</th>
                    <th style="cursor:default">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($security_events as $ev):
                $scfg = $severity_cfg[$ev['severity']] ?? $severity_cfg['medium'];
                $type_label = $event_type_labels[$ev['event_type']] ?? h($ev['event_type']);
            ?>
            <tr style="<?= $ev['severity'] === 'critical' ? 'background:rgba(255,71,87,.04)' : '' ?>">
                <td>
                    <span style="display:inline-block;font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:20px;background:<?= $scfg['bg'] ?>;color:<?= $scfg['color'] ?>;text-transform:uppercase;letter-spacing:.04em">
                        <?= h($scfg['label']) ?>
                    </span>
                </td>
                <td>
                    <span style="font-size:13px;font-weight:500"><?= h($type_label) ?></span>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:6px">
                        <?= os_badge($ev['os_type']) ?>
                        <span style="font-size:13px;font-weight:600"><?= h($ev['hostname']) ?></span>
                    </div>
                </td>
                <td>
                    <?php if ($ev['source_ip']): ?>
                    <code style="font-size:12.5px;background:var(--bg-elevated);padding:2px 7px;border-radius:4px;color:var(--text-primary)"><?= h($ev['source_ip']) ?></code>
                    <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
                </td>
                <td style="font-variant-numeric:tabular-nums;font-weight:600;font-size:13px;color:<?= (int)$ev['attempt_count'] >= 20 ? 'var(--danger)' : 'var(--text-primary)' ?>">
                    <?= number_format((int)$ev['attempt_count']) ?>
                </td>
                <td style="max-width:280px">
                    <span style="font-size:12px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block" title="<?= h($ev['details'] ?? '') ?>">
                        <?= h($ev['details'] ? (strlen($ev['details']) > 60 ? substr($ev['details'], 0, 60) . '…' : $ev['details']) : '—') ?>
                    </span>
                </td>
                <td style="white-space:nowrap">
                    <div style="font-size:12.5px;color:var(--text-secondary)"><?= time_ago($ev['detected_at']) ?></div>
                    <div style="font-size:11px;color:var(--text-muted)"><?= date('d/m H:i', strtotime($ev['detected_at'])) ?></div>
                </td>
                <td>
                    <button class="btn btn-ghost btn-sm" style="color:var(--success);border-color:rgba(34,211,160,.3)" onclick="resetAsset(<?= $ev['asset_id'] ?>, '<?= h(addslashes($ev['hostname'])) ?>')" title="Marquer comme résolu">
                        <svg><use href="#icon-check"/></svg> OK
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

</main>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';
// Sidebar toggle — mirror pattern from app.js
document.addEventListener('DOMContentLoaded', function() {
    const menuBtn      = document.getElementById('menuBtn');
    const sidebar      = document.getElementById('sidebar');
    const overlay      = document.getElementById('sidebarOverlay');
    const sidebarToggle = document.getElementById('sidebarToggle');

    function openSidebar() {
        if (sidebar) sidebar.classList.add('open');
        if (overlay) overlay.classList.add('visible');
    }
    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('visible');
    }

    if (menuBtn)       menuBtn.addEventListener('click', openSidebar);
    if (overlay)       overlay.addEventListener('click', closeSidebar);
    if (sidebarToggle) sidebarToggle.addEventListener('click', closeSidebar);
});

// ── SECURITY RESET ─────────────────────────────────────────
async function resetAsset(assetId, hostname) {
    if (!confirm('Marquer tous les incidents de "' + hostname + '" comme résolus ?')) return;
    try {
        await api(APP_URL + '/api/security-reset.php', { method: 'POST', body: { asset_id: assetId } });
        toast(hostname + ' — remis en vert', 'success');
        setTimeout(() => location.reload(), 600);
    } catch(e) {}
}

async function resetAll() {
    if (!confirm('Réinitialiser TOUS les événements de sécurité ?')) return;
    try {
        await api(APP_URL + '/api/security-reset.php', { method: 'POST', body: { all: true } });
        toast('Tous les événements réinitialisés', 'success');
        setTimeout(() => location.reload(), 600);
    } catch(e) {}
}
</script>

<?php render_footer(); ?>
