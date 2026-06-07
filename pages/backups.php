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

<!-- ─── RESTAURATION ─────────────────────────────────────────────────────── -->
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Restaurer une sauvegarde</div>
            <div class="card-subtitle">Explorez le contenu puis restaurez la base et/ou les fichiers. Une sauvegarde de sécurité est créée avant.</div>
        </div>
    </div>
    <div class="card-body">
        <div id="restore-list"><p class="text-muted" style="font-size:13.5px">Chargement…</p></div>
    </div>
</div>

<!-- ─── RESTAURATION DE SECOURS ──────────────────────────────────────────── -->
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Restauration de secours</div>
            <div class="card-subtitle">Page autonome utilisable même si l'application est cassée, protégée par un mot de passe dédié (indépendant de la base).</div>
        </div>
        <span id="recovery-state" class="badge badge-retired">Non configuré</span>
    </div>
    <div class="card-body">
        <div class="form-row" style="align-items:flex-end">
            <div class="form-group">
                <label class="form-label">Mot de passe de secours</label>
                <input type="password" id="recovery-pw" class="form-control" placeholder="8 caractères minimum" autocomplete="new-password">
                <div class="form-hint">Sera demandé sur la page de secours. Stocké haché dans <code>config/recovery.php</code> (hors base).</div>
            </div>
            <div class="form-group" style="flex:0 0 auto">
                <button class="btn btn-primary" onclick="setRecoveryPassword()"><svg><use href="#icon-lock"/></svg> Définir</button>
            </div>
        </div>
        <a href="<?= APP_URL ?>/recovery.php" target="_blank" rel="noopener" class="btn btn-ghost btn-sm" style="margin-top:6px">
            Ouvrir la page de secours →
        </a>
    </div>
</div>

<!-- Modal exploration -->
<div class="modal-backdrop" id="modal-explore" style="display:none">
<div class="modal" style="max-width:640px;width:100%">
    <div class="modal-header">
        <h2 class="modal-title" id="explore-title">Exploration</h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-explore')"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div class="modal-body">
        <div id="explore-meta" style="font-size:12.5px;color:var(--text-muted);margin-bottom:8px"></div>
        <div id="explore-body" style="background:var(--bg-base);border:1px solid var(--border);border-radius:8px;padding:12px;max-height:340px;overflow:auto;font-family:monospace;font-size:12px;white-space:pre-wrap"></div>
    </div>
</div>
</div>

<!-- Modal restauration -->
<div class="modal-backdrop" id="modal-restore" style="display:none">
<div class="modal" style="max-width:460px;width:100%">
    <div class="modal-header">
        <h2 class="modal-title">Restaurer la sauvegarde</h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-restore')"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div class="modal-body">
        <div style="background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.3);color:#f5a623;border-radius:8px;padding:10px 14px;font-size:13px;margin-bottom:14px">
            ⚠ Les données actuelles seront <b>écrasées</b>. Une sauvegarde de sécurité est créée automatiquement avant.
        </div>
        <div id="restore-date" style="font-weight:700;margin-bottom:10px"></div>
        <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
            <label id="restore-db-lbl" style="font-size:13.5px"><input type="checkbox" id="restore-db" checked> Base de données</label>
            <label id="restore-files-lbl" style="font-size:13.5px"><input type="checkbox" id="restore-files"> Fichiers de l'application</label>
        </div>
        <input type="text" id="restore-confirm" class="form-control" placeholder="Tapez RESTAURER pour confirmer">
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="Modal.close('modal-restore')">Annuler</button>
        <button class="btn btn-primary" id="restore-go" style="background:var(--danger)" onclick="doRestore()">Restaurer</button>
    </div>
</div>
</div>


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

// ── RESTAURATION ────────────────────────────────────────
let _restoreSet = null;
const _fmtSize = b => b > 1048576 ? (b/1048576).toFixed(1)+' Mo' : Math.round(b/1024)+' Ko';

async function initRestore() {
    try {
        const r = await api(`${APP_URL}/api/restore.php?action=list`, {method:'GET'});
        const sets = r.data.sets || [];
        document.getElementById('recovery-state').className = 'badge ' + (r.data.recovery_configured ? 'badge-active' : 'badge-retired');
        document.getElementById('recovery-state').textContent = r.data.recovery_configured ? 'Configuré' : 'Non configuré';
        const box = document.getElementById('restore-list');
        if (!sets.length) { box.innerHTML = '<p class="text-muted" style="font-size:13.5px">Aucune sauvegarde disponible.</p>'; return; }
        box.innerHTML = sets.map(s => {
            const db = s.db ? `<button class="btn btn-ghost btn-sm" onclick="explore('db','${s.db.name}')">Explorer base</button>` : '';
            const fl = s.files ? `<button class="btn btn-ghost btn-sm" onclick="explore('files','${s.files.name}')">Explorer fichiers</button>` : '';
            const dbInfo = s.db ? `base ${_fmtSize(s.db.size)}` : '';
            const flInfo = s.files ? `fichiers ${_fmtSize(s.files.size)}` : '';
            return `<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:10px">
                <span style="font-weight:700">${s.date}</span>
                <span style="font-size:12.5px;color:var(--text-muted)">${[dbInfo,flInfo].filter(Boolean).join(' · ')}</span>
                <span style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">${db}${fl}
                    <button class="btn btn-primary btn-sm" onclick='openRestore(${JSON.stringify(s)})'>Restaurer</button>
                </span>
            </div>`;
        }).join('');
    } catch(e) {}
}

async function explore(kind, name) {
    try {
        const r = await api(`${APP_URL}/api/restore.php?action=explore_${kind}&name=${encodeURIComponent(name)}`, {method:'GET'});
        const d = r.data;
        document.getElementById('explore-title').textContent = 'Exploration — ' + name;
        document.getElementById('explore-meta').textContent = (d.count ?? 0) + (kind==='db' ? ' tables' : ' entrées');
        document.getElementById('explore-body').textContent = d.error ? d.error : (kind==='db' ? d.tables : d.entries).join('\n');
        Modal.open('modal-explore');
    } catch(e) {}
}

function openRestore(s) {
    _restoreSet = s;
    document.getElementById('restore-date').textContent = 'Sauvegarde du ' + s.date;
    document.getElementById('restore-db-lbl').style.display    = s.db ? '' : 'none';
    document.getElementById('restore-files-lbl').style.display = s.files ? '' : 'none';
    document.getElementById('restore-db').checked = !!s.db;
    document.getElementById('restore-files').checked = false;
    document.getElementById('restore-confirm').value = '';
    Modal.open('modal-restore');
}

async function doRestore() {
    if (document.getElementById('restore-confirm').value !== 'RESTAURER') { toast('Tapez RESTAURER pour confirmer', 'warning'); return; }
    const body = { action:'restore' };
    if (document.getElementById('restore-db').checked    && _restoreSet.db)    body.db    = _restoreSet.db.name;
    if (document.getElementById('restore-files').checked && _restoreSet.files) body.files = _restoreSet.files.name;
    if (!body.db && !body.files) { toast('Sélectionnez la base et/ou les fichiers', 'warning'); return; }
    const btn = document.getElementById('restore-go'); btn.disabled = true; btn.textContent = 'Restauration…';
    try {
        await api(`${APP_URL}/api/restore.php`, {method:'POST', body});
        toast('Restauration effectuée (sauvegarde de sécurité créée avant)', 'success');
        Modal.close('modal-restore');
    } catch(e) {} finally { btn.disabled = false; btn.textContent = 'Restaurer'; }
}

async function setRecoveryPassword() {
    const pw = document.getElementById('recovery-pw').value;
    if (pw.length < 8) { toast('Mot de passe trop court (8 min)', 'warning'); return; }
    try {
        await api(`${APP_URL}/api/restore.php`, {method:'POST', body:{action:'set_recovery_password', password:pw}});
        toast('Mot de passe de secours défini', 'success');
        document.getElementById('recovery-pw').value = '';
        initRestore();
    } catch(e) {}
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initRestore);
else initRestore();

</script>

<?php render_footer(); ?>
