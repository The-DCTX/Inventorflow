<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/layout.php';
require_auth();

$client_id = current_client_id();
if (!$client_id) {
    header('Location: ' . APP_URL . '/pages/clients.php');
    exit;
}

// Stats
$pdo = db();

$total = (int)$pdo->prepare('SELECT COUNT(*) FROM assets WHERE client_id = ?')->execute([$client_id]) && false;
$stmt = $pdo->prepare('SELECT
    COUNT(*) as total,
    SUM(os_type="MAC") as mac,
    SUM(os_type="WIN") as win,
    SUM(os_type="LIN") as lin,
    SUM(status="active") as active,
    SUM(status="stock") as stock,
    SUM(status="repair") as repair,
    SUM(status="retired") as retired,
    SUM(assigned_to IS NOT NULL AND status="active") as assigned
FROM assets WHERE client_id = ?');
$stmt->execute([$client_id]);
$stats = $stmt->fetch();

$e = $pdo->prepare('SELECT COUNT(*) FROM employees WHERE client_id = ? AND active = 1');
$e->execute([$client_id]);
$emp_count = (int)$e->fetchColumn();


$ws = $pdo->prepare('SELECT COUNT(*) FROM assets WHERE client_id = ? AND warranty_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)');
$ws->execute([$client_id]);
$warranty_soon = (int)$ws->fetchColumn();

// Recent activity
$activity_stmt = $pdo->prepare('SELECT h.*, a.hostname, a.os_type,
    CONCAT(emp.first_name, " ", emp.last_name) as emp_name
FROM asset_history h
JOIN assets a ON h.asset_id = a.id
LEFT JOIN employees emp ON (h.to_value = emp.id OR h.from_value = emp.id)
WHERE a.client_id = ?
ORDER BY h.performed_at DESC LIMIT 10');
$activity_stmt->execute([$client_id]);
$activities = $activity_stmt->fetchAll();

// OS distribution for donut
$total_assets = (int)$stats['total'];
$mac_pct = $total_assets > 0 ? round(($stats['mac'] / $total_assets) * 100) : 0;
$win_pct = $total_assets > 0 ? round(($stats['win'] / $total_assets) * 100) : 0;
$lin_pct = $total_assets > 0 ? round(($stats['lin'] / $total_assets) * 100) : 0;

render_head('Tableau de bord');
render_icons();
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php render_sidebar('dashboard'); ?>
<div class="main-wrapper">
<?php render_topbar('Tableau de bord', date('l d F Y')); ?>
<main class="main-content">

<!-- KPI CARDS -->
<div class="kpi-grid">
    <div class="kpi-card blue">
        <div class="kpi-header">
            <div class="kpi-icon"><svg class="icon-md"><use href="#icon-monitor"/></svg></div>
        </div>
        <div class="kpi-value"><?= number_format((int)$stats['total']) ?></div>
        <div class="kpi-label">Total actifs</div>
    </div>
    <div class="kpi-card green">
        <div class="kpi-header">
            <div class="kpi-icon"><svg class="icon-md"><use href="#icon-check"/></svg></div>
        </div>
        <div class="kpi-value"><?= number_format((int)$stats['active']) ?></div>
        <div class="kpi-label">Actifs déployés</div>
    </div>
    <div class="kpi-card mac">
        <div class="kpi-header">
            <div class="kpi-icon"><svg class="icon-md"><use href="#icon-apple"/></svg></div>
        </div>
        <div class="kpi-value"><?= number_format((int)$stats['mac']) ?></div>
        <div class="kpi-label">macOS</div>
    </div>
    <div class="kpi-card win">
        <div class="kpi-header">
            <div class="kpi-icon"><svg class="icon-md"><use href="#icon-windows"/></svg></div>
        </div>
        <div class="kpi-value"><?= number_format((int)$stats['win']) ?></div>
        <div class="kpi-label">Windows</div>
    </div>
    <div class="kpi-card lin">
        <div class="kpi-header">
            <div class="kpi-icon"><svg class="icon-md"><use href="#icon-linux"/></svg></div>
        </div>
        <div class="kpi-value"><?= number_format((int)$stats['lin']) ?></div>
        <div class="kpi-label">Linux</div>
    </div>
    <div class="kpi-card amber">
        <div class="kpi-header">
            <div class="kpi-icon"><svg class="icon-md"><use href="#icon-alert"/></svg></div>
        </div>
        <div class="kpi-value"><?= number_format((int)$stats['repair']) ?></div>
        <div class="kpi-label">En réparation</div>
    </div>
    <div class="kpi-card purple">
        <div class="kpi-header">
            <div class="kpi-icon"><svg class="icon-md"><use href="#icon-users"/></svg></div>
        </div>
        <div class="kpi-value"><?= number_format($emp_count) ?></div>
        <div class="kpi-label">Utilisateurs actifs</div>
    </div>
    <div class="kpi-card <?= $warranty_soon > 0 ? 'amber' : 'green' ?>">
        <div class="kpi-header">
            <div class="kpi-icon"><svg class="icon-md"><use href="#icon-calendar"/></svg></div>
        </div>
        <div class="kpi-value"><?= number_format($warranty_soon) ?></div>
        <div class="kpi-label">Garanties < 90j</div>
    </div>
</div>

<!-- CHARTS + ACTIVITY -->
<div class="grid-2" style="gap:16px;align-items:start">

    <!-- OS Distribution -->
    <div class="card">
        <div class="card-header">
            <div>
                <div class="card-title">Distribution OS</div>
                <div class="card-subtitle"><?= number_format($total_assets) ?> postes au total</div>
            </div>
        </div>
        <div class="card-body" style="display:flex;align-items:center;gap:28px">
            <div class="donut-wrap">
                <div id="donut-os"></div>
                <div class="donut-label">
                    <span class="value"><?= $total_assets ?></span>
                    <span class="sub">postes</span>
                </div>
            </div>
            <div class="legend" style="flex:1">
                <div class="legend-item">
                    <div class="legend-dot" style="background:var(--os-mac)"></div>
                    <span class="legend-name">macOS</span>
                    <span class="legend-value"><?= $stats['mac'] ?></span>
                    <span class="legend-pct"><?= $mac_pct ?>%</span>
                </div>
                <div class="legend-item">
                    <div class="legend-dot" style="background:var(--os-win)"></div>
                    <span class="legend-name">Windows</span>
                    <span class="legend-value"><?= $stats['win'] ?></span>
                    <span class="legend-pct"><?= $win_pct ?>%</span>
                </div>
                <div class="legend-item">
                    <div class="legend-dot" style="background:var(--os-lin)"></div>
                    <span class="legend-name">Linux</span>
                    <span class="legend-value"><?= $stats['lin'] ?></span>
                    <span class="legend-pct"><?= $lin_pct ?>%</span>
                </div>
            </div>
        </div>
        <div class="card-body" style="padding-top:0">
            <div class="stat-row">
                <span class="stat-row-label">Assignés</span>
                <span class="stat-row-value text-success"><?= $stats['assigned'] ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-row-label">En stock</span>
                <span class="stat-row-value text-accent"><?= $stats['stock'] ?></span>
            </div>
            <div class="stat-row">
                <span class="stat-row-label">Retraités</span>
                <span class="stat-row-value text-muted"><?= $stats['retired'] ?></span>
            </div>
        </div>
    </div>

    <!-- Recent Activity -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">Activité récente</div>
            <a href="<?= APP_URL ?>/pages/assets.php" class="btn btn-ghost btn-sm">Voir tout</a>
        </div>
        <div class="card-body" style="padding:0 20px">
            <?php if (empty($activities)): ?>
            <div class="empty-state" style="padding:30px 0">
                <svg><use href="#icon-clock"/></svg>
                <p>Aucune activité récente</p>
            </div>
            <?php else: ?>
            <div class="activity-list">
                <?php foreach ($activities as $act):
                    $color = match($act['action']) {
                        'assigned' => 'green',
                        'unassigned' => 'amber',
                        'created' => 'blue',
                        'status_change' => 'amber',
                        default => 'blue'
                    };
                    $label = match($act['action']) {
                        'assigned' => 'Assigné',
                        'unassigned' => 'Désassigné',
                        'created' => 'Ajouté',
                        'status_change' => 'Statut modifié',
                        'updated' => 'Modifié',
                        default => $act['action']
                    };
                ?>
                <div class="activity-item">
                    <div class="activity-dot <?= $color ?>"></div>
                    <div class="activity-content">
                        <div class="activity-text">
                            <strong><?= h($act['hostname']) ?></strong> — <?= $label ?>
                            <?php if ($act['to_value'] && $act['action'] === 'assigned'): ?>
                            à <?= h($act['emp_name'] ?? 'utilisateur') ?>
                            <?php endif; ?>
                        </div>
                        <div class="activity-time"><?= time_ago($act['performed_at']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

</main>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    renderDonut('donut-os', [
        { label: 'macOS', value: <?= (int)$stats['mac'] ?>, color: 'var(--os-mac)' },
        { label: 'Windows', value: <?= (int)$stats['win'] ?>, color: 'var(--os-win)' },
        { label: 'Linux', value: <?= (int)$stats['lin'] ?>, color: 'var(--os-lin)' },
    ], { size: 130, stroke: 16 });
});
</script>

<?php render_footer(); ?>
