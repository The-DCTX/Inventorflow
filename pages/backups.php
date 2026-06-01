<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();
if (!is_superadmin()) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();

// Logs backup
$logs = $pdo->query(
    'SELECT * FROM backup_logs ORDER BY run_at DESC LIMIT 30'
)->fetchAll();

// Stats
$total    = count($logs);
$errors   = array_filter($logs, fn($l) => $l['status'] === 'error');
$warnings = array_filter($logs, fn($l) => $l['status'] === 'warning');
$last     = $logs[0] ?? null;

// Fichiers backup disponibles
$backup_dir = '/var/www/backups/inventorflow';
$backup_files = [];
if (is_dir($backup_dir)) {
    foreach (glob($backup_dir . '/*.{tar.gz,sql.gz}', GLOB_BRACE) as $f) {
        $backup_files[] = [
            'name' => basename($f),
            'size' => round(filesize($f) / 1024, 1) . ' Ko',
            'date' => date('d/m/Y H:i', filemtime($f)),
            'type' => str_ends_with($f, '.sql.gz') ? 'db' : 'files',
        ];
    }
    usort($backup_files, fn($a, $b) => strcmp($b['date'], $a['date']));
}

render_head('Sauvegardes');
render_icons();
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php render_sidebar('backups'); ?>
<div class="main-wrapper">
<?php render_topbar('Sauvegardes', 'Monitoring et historique des backups'); ?>
<main class="main-content">

<!-- KPI -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px">

    <div class="card" style="padding:16px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px">Dernier backup</div>
        <?php if ($last): ?>
        <div style="font-size:15px;font-weight:700;color:<?= $last['status']==='success'?'var(--success)':($last['status']==='warning'?'var(--warning)':'var(--danger)') ?>">
            <?= $last['status']==='success' ? '✓ Succès' : ($last['status']==='warning' ? '⚠ Warning' : '✗ Erreur') ?>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px"><?= date('d/m/Y H:i', strtotime($last['run_at'])) ?></div>
        <?php else: ?>
        <div style="font-size:15px;font-weight:700;color:var(--text-muted)">Aucun backup</div>
        <?php endif; ?>
    </div>

    <div class="card" style="padding:16px">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px">Total backups</div>
        <div style="font-size:28px;font-weight:800;color:var(--text-primary)"><?= $total ?></div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">30 derniers</div>
    </div>

    <div class="card" style="padding:16px;border-color:<?= count($errors)>0?'rgba(255,71,87,.3)':'var(--border)' ?>">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px">Erreurs</div>
        <div style="font-size:28px;font-weight:800;color:<?= count($errors)>0?'var(--danger)':'var(--success)' ?>">
            <?= count($errors) ?>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Sur 30 backups</div>
    </div>

    <div class="card" style="padding:16px;border-color:<?= count($warnings)>0?'rgba(245,166,35,.3)':'var(--border)' ?>">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px">Avertissements</div>
        <div style="font-size:28px;font-weight:800;color:<?= count($warnings)>0?'var(--warning)':'var(--success)' ?>">
            <?= count($warnings) ?>
        </div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:4px">Intégrité à vérifier</div>
    </div>

</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

<!-- HISTORIQUE -->
<div class="card">
    <div class="card-header">
        <div>
            <div class="card-title">Historique des exécutions</div>
            <div class="card-subtitle">30 derniers backups</div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="runBackup()" id="run-btn">
            <svg><use href="#icon-refresh"/></svg> Lancer maintenant
        </button>
    </div>
    <div style="overflow-y:auto;max-height:420px">
        <?php if (empty($logs)): ?>
        <div class="empty-state" style="padding:40px 20px">
            <svg><use href="#icon-clock"/></svg>
            <p>Aucun backup enregistré</p>
        </div>
        <?php else: ?>
        <table style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
                <tr style="border-bottom:1px solid var(--border)">
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">Date</th>
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">Statut</th>
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">Fichiers</th>
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">DB</th>
                    <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted)">Durée</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
            <?php
                $sc = ['success'=>'var(--success)','warning'=>'var(--warning)','error'=>'var(--danger)'][$log['status']] ?? 'var(--text-muted)';
                $sl = ['success'=>'✓ Succès','warning'=>'⚠ Warning','error'=>'✗ Erreur'][$log['status']] ?? $log['status'];
            ?>
            <tr style="border-bottom:1px solid var(--border-subtle);cursor:pointer;transition:background var(--transition)"
                onmouseenter="this.style.background='var(--bg-elevated)'" onmouseleave="this.style.background=''"
                onclick="showLogDetail(<?= htmlspecialchars(json_encode($log), ENT_QUOTES) ?>)">
                <td style="padding:10px 16px;color:var(--text-secondary)"><?= date('d/m/Y H:i', strtotime($log['run_at'])) ?></td>
                <td style="padding:10px 16px">
                    <span style="font-weight:700;color:<?= $sc ?>"><?= $sl ?></span>
                </td>
                <td style="padding:10px 16px;color:var(--text-secondary);font-variant-numeric:tabular-nums"><?= h($log['files_size'] ?: '—') ?></td>
                <td style="padding:10px 16px;color:var(--text-secondary);font-variant-numeric:tabular-nums"><?= h($log['db_size'] ?: '—') ?></td>
                <td style="padding:10px 16px;color:var(--text-muted)"><?= $log['duration_sec'] ?>s</td>
            </tr>
            <?php if ($log['status'] !== 'success' && $log['message']): ?>
            <tr style="border-bottom:1px solid var(--border-subtle);background:var(--danger-dim)">
                <td colspan="5" style="padding:6px 16px;font-size:12px;color:var(--danger)">
                    ↳ <?= h($log['message']) ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- FICHIERS DISPONIBLES -->
<div class="card">
    <div class="card-header">
        <div class="card-title">Fichiers de backup</div>
        <div class="card-subtitle"><?= count($backup_files) ?> fichier<?= count($backup_files)>1?'s':'' ?> disponible<?= count($backup_files)>1?'s':'' ?></div>
    </div>
    <div style="overflow-y:auto;max-height:420px">
        <?php if (empty($backup_files)): ?>
        <div class="empty-state" style="padding:40px 20px">
            <svg><use href="#icon-layers"/></svg>
            <p>Aucun fichier de backup</p>
        </div>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:0">
        <?php foreach ($backup_files as $bf): ?>
        <div style="display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border-subtle)">
            <div style="width:34px;height:34px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0;background:<?= $bf['type']==='db'?'rgba(79,126,248,.15)':'rgba(34,211,160,.12)' ?>">
                <svg width="16" height="16" style="color:<?= $bf['type']==='db'?'var(--accent)':'var(--success)' ?>">
                    <use href="<?= $bf['type']==='db'?'#icon-layers':'#icon-monitor' ?>"/>
                </svg>
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($bf['name']) ?></div>
                <div style="font-size:11.5px;color:var(--text-muted)"><?= $bf['date'] ?> · <?= $bf['size'] ?></div>
            </div>
            <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;background:<?= $bf['type']==='db'?'rgba(79,126,248,.15)':'rgba(34,211,160,.12)' ?>;color:<?= $bf['type']==='db'?'var(--accent)':'var(--success)' ?>">
                <?= $bf['type']==='db' ? 'SQL' : 'FILES' ?>
            </span>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="card-footer" style="font-size:12px;color:var(--text-muted)">
        Chemin : <code style="background:var(--bg-elevated);padding:2px 6px;border-radius:4px"><?= h($backup_dir) ?></code>
    </div>
</div>

</div><!-- /grid -->

<!-- MODAL détail log -->
<div class="modal-backdrop" id="modal-log-detail" style="display:none">
<div class="modal" style="max-width:520px">
    <div class="modal-header">
        <h2 class="modal-title">Détail du backup</h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-log-detail')">
            <svg><use href="#icon-x"/></svg>
        </button>
    </div>
    <div class="modal-body" id="modal-log-body"></div>
</div>
</div>

</main>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';

function _mkRow(label, valueEl) {
    const row = document.createElement('div');
    row.style.cssText = 'display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--border-subtle);align-items:flex-start';
    const l = document.createElement('div');
    l.style.cssText = 'font-size:12px;font-weight:600;color:var(--text-muted);min-width:130px;text-transform:uppercase;letter-spacing:.06em;padding-top:2px';
    l.textContent = label;
    const v = document.createElement('div');
    v.style.cssText = 'font-size:13.5px;color:var(--text-primary);flex:1;word-break:break-all';
    if (typeof valueEl === 'string') { v.textContent = valueEl; }
    else { v.appendChild(valueEl); }
    row.appendChild(l); row.appendChild(v);
    return row;
}

function _statusEl(log) {
    const sc = {success:'var(--success)',warning:'var(--warning)',error:'var(--danger)'}[log.status] || 'var(--text-muted)';
    const sl = {success:'✓ Succès',warning:'⚠ Warning',error:'✗ Erreur'}[log.status] || log.status;
    const s = document.createElement('span');
    s.style.cssText = 'font-weight:700;color:' + sc;
    s.textContent = sl;
    return s;
}

function _codeEl(text) {
    const c = document.createElement('code');
    c.style.cssText = 'font-size:11.5px;background:var(--bg-elevated);padding:2px 5px;border-radius:3px';
    c.textContent = text;
    return c;
}

function showLogDetail(log) {
    const body = document.getElementById('modal-log-body');
    body.textContent = '';
    const grid = document.createElement('div');
    grid.style.cssText = 'display:flex;flex-direction:column;gap:0';

    grid.appendChild(_mkRow('Date',           new Date(log.run_at).toLocaleString('fr-FR')));
    grid.appendChild(_mkRow('Statut',         _statusEl(log)));
    grid.appendChild(_mkRow('Fichiers',       log.files_size || '—'));
    grid.appendChild(_mkRow('Base de données',log.db_size    || '—'));
    grid.appendChild(_mkRow('Durée',          log.duration_sec + 's'));
    grid.appendChild(_mkRow('Message',        log.message    || 'Aucun'));
    grid.appendChild(_mkRow('Archive fichiers', log.files_path ? _codeEl(log.files_path) : '—'));
    grid.appendChild(_mkRow('Archive DB',       log.db_path   ? _codeEl(log.db_path)    : '—'));

    body.appendChild(grid);
    Modal.open('modal-log-detail');
}

async function runBackup() {
    const btn = document.getElementById('run-btn');
    btn.disabled = true;
    btn.textContent = 'En cours…';
    try {
        const r = await fetch(`${APP_URL}/api/backup.php`, {method:'POST', credentials:'same-origin'});
        const d = await r.json();
        if (d.success) {
            toast('Backup lancé — la page va se recharger', 'success');
            setTimeout(() => location.reload(), 3000);
        } else throw new Error(d.error);
    } catch(e) {
        toast('Erreur : ' + e.message, 'error');
        btn.disabled = false;
        btn.innerHTML = '<svg><use href="#icon-refresh"/></svg> Lancer maintenant';
    }
}
</script>

<?php render_footer(); ?>
