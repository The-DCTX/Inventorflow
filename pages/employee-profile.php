<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/'); exit; }

$emp_id = (int)($_GET['id'] ?? 0);
if (!$emp_id) { header('Location: ' . APP_URL . '/pages/employees.php'); exit; }

$pdo = db();

$stmt = $pdo->prepare('SELECT e.*, d.name as dept_name, d.id as dept_id
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE e.id = ? AND e.client_id = ?');
$stmt->execute([$emp_id, $client_id]);
$emp = $stmt->fetch();
if (!$emp) { header('Location: ' . APP_URL . '/pages/employees.php'); exit; }

// Machines assignées
$assets_stmt = $pdo->prepare('SELECT a.*,
    ms.cpu_pct,
    ms.ram_used_mb, ms.ram_total_mb,
    ms.disk_used_gb, ms.disk_total_gb,
    ms.collected_at as last_seen
    FROM assets a
    LEFT JOIN (
        SELECT asset_id, cpu_pct, ram_used_mb, ram_total_mb, disk_used_gb, disk_total_gb, collected_at
        FROM monitoring_snapshots ms2
        WHERE ms2.collected_at = (SELECT MAX(collected_at) FROM monitoring_snapshots WHERE asset_id = ms2.asset_id)
    ) ms ON ms.asset_id = a.id
    WHERE a.assigned_to = ?');
$assets_stmt->execute([$emp_id]);
$emp_assets = $assets_stmt->fetchAll();

// Licences actives de l'employé
$lics_stmt = $pdo->prepare('SELECT la.id as assignment_id, la.assigned_at, la.active, la.revoked_at,
    l.id as license_id, l.name, l.vendor, l.category, l.license_type, l.cost_per_seat, l.billing_period
    FROM license_assignments la
    JOIN licenses l ON la.license_id = l.id
    WHERE la.employee_id = ? ORDER BY la.active DESC, l.category, l.name');
$lics_stmt->execute([$emp_id]);
$emp_lics = $lics_stmt->fetchAll();

// Licences des machines de l'employé
$asset_lics_rows = [];
if (!empty($emp_assets)) {
    $asset_ids = array_column($emp_assets, 'id');
    $placeholders = implode(',', array_fill(0, count($asset_ids), '?'));
    $al_stmt = $pdo->prepare("SELECT la.id as assignment_id, la.asset_id, la.assigned_at, la.active, la.revoked_at,
        l.id as license_id, l.name, l.vendor, l.category, l.license_type, l.cost_per_seat, l.billing_period,
        a.hostname
        FROM license_assignments la
        JOIN licenses l ON la.license_id = l.id
        JOIN assets a ON la.asset_id = a.id
        WHERE la.asset_id IN ($placeholders) AND la.active = 1
        ORDER BY a.hostname, l.category, l.name");
    $al_stmt->execute($asset_ids);
    $asset_lics_rows = $al_stmt->fetchAll();
}

// Coût mensuel estimé (licences employé + licences machines)
$monthly_cost = 0;
foreach ($emp_lics as $l) {
    if (!$l['active']) continue;
    $cost = (float)$l['cost_per_seat'];
    $monthly_cost += ($l['billing_period'] === 'annual') ? $cost / 12 : $cost;
}
foreach ($asset_lics_rows as $l) {
    $cost = (float)$l['cost_per_seat'];
    $monthly_cost += ($l['billing_period'] === 'annual') ? $cost / 12 : $cost;
}

// Autres employés du client pour réassignation
$others_stmt = $pdo->prepare('SELECT id, first_name, last_name FROM employees WHERE client_id=? AND active=1 AND id!=? ORDER BY last_name, first_name');
$others_stmt->execute([$client_id, $emp_id]);
$other_employees = $others_stmt->fetchAll();

$active_lics_count = count(array_filter($emp_lics, fn($l) => $l['active']));
$revoked_lics_count = count(array_filter($emp_lics, fn($l) => !$l['active']));

render_head(h($emp['first_name'] . ' ' . $emp['last_name']));
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('employees'); ?>
<div class="main-wrapper">
<?php render_topbar(
    h($emp['first_name'] . ' ' . $emp['last_name']),
    h(($emp['position'] ?: '') . ($emp['dept_name'] ? ' · ' . $emp['dept_name'] : ''))
); ?>
<main class="main-content">

<style>
.profile-header{display:flex;align-items:center;gap:20px;padding:20px 24px;background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:20px}
.profile-avatar{width:64px;height:64px;border-radius:50%;background:var(--accent-dim);border:2px solid rgba(79,126,248,0.3);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;flex-shrink:0}
.profile-meta{flex:1;min-width:0}
.profile-name{font-size:20px;font-weight:700;color:var(--text-primary);margin-bottom:4px}
.profile-sub{font-size:13.5px;color:var(--text-muted);display:flex;flex-wrap:wrap;gap:8px 16px}
.profile-actions{display:flex;gap:8px;flex-shrink:0}
.section-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:12px}
.profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
@media(max-width:900px){.profile-grid{grid-template-columns:1fr}}
.profile-card{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:20px}
.profile-card-full{background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:20px}
.info-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border)}
.info-row:last-child{border-bottom:none}
.info-label{font-size:12.5px;color:var(--text-muted);font-weight:500}
.info-value{font-size:13.5px;color:var(--text-primary);font-weight:500;text-align:right}
.asset-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)}
.asset-row:last-child{border-bottom:none}
.asset-row-info{flex:1;min-width:0}
.asset-hostname{font-size:13.5px;font-weight:600;font-family:monospace;color:var(--accent)}
.asset-meta{font-size:12px;color:var(--text-muted);margin-top:2px}
.lic-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)}
.lic-row:last-child{border-bottom:none}
.lic-row.revoked{opacity:.55}
.lic-name{font-size:13px;font-weight:600;flex:1;min-width:0}
.lic-meta{font-size:11.5px;color:var(--text-muted)}
.cost-banner{background:linear-gradient(135deg,var(--accent-dim),rgba(34,211,160,0.08));border:1px solid var(--accent-glow);border-radius:var(--radius);padding:16px 20px;display:flex;align-items:center;gap:16px;margin-bottom:20px}
.cost-amount{font-size:28px;font-weight:800;color:var(--accent)}
.cost-label{font-size:13px;color:var(--text-muted)}
.inactive-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;background:var(--danger-dim);color:var(--danger);font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
</style>

<!-- Header profil -->
<div class="profile-header">
    <div class="profile-avatar"><?= strtoupper(substr($emp['first_name'],0,1).substr($emp['last_name'],0,1)) ?></div>
    <div class="profile-meta">
        <div class="profile-name">
            <?= h($emp['first_name'] . ' ' . $emp['last_name']) ?>
            <?php if (!$emp['active']): ?>
            <span class="inactive-badge" style="font-size:11px;margin-left:8px">Inactif</span>
            <?php endif; ?>
        </div>
        <div class="profile-sub">
            <?php if ($emp['position']): ?><span><?= h($emp['position']) ?></span><?php endif; ?>
            <?php if ($emp['dept_name']): ?><span style="color:var(--accent)"><?= h($emp['dept_name']) ?></span><?php endif; ?>
            <?php if ($emp['email']): ?><a href="mailto:<?= h($emp['email']) ?>" style="color:var(--text-muted);text-decoration:none"><?= h($emp['email']) ?></a><?php endif; ?>
            <?php if ($emp['phone']): ?><span><?= h($emp['phone']) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="profile-actions">
        <button class="btn btn-secondary btn-sm" onclick="editEmployee()">
            <svg><use href="#icon-edit"/></svg> Modifier
        </button>
        <?php if ($emp['active']): ?>
        <button class="btn btn-danger btn-sm" onclick="openOffboarding()">
            <svg><use href="#icon-x"/></svg> Offboarding
        </button>
        <?php else: ?>
        <button class="btn btn-secondary btn-sm" onclick="reactivate()">
            <svg><use href="#icon-check"/></svg> Réactiver
        </button>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/pages/employees.php" class="btn btn-ghost btn-sm">Retour</a>
    </div>
</div>

<!-- Coût mensuel estimé -->
<?php if ($monthly_cost > 0): ?>
<div class="cost-banner">
    <svg style="width:32px;height:32px;color:var(--accent);flex-shrink:0"><use href="#icon-tag"/></svg>
    <div>
        <div class="cost-amount"><?= number_format($monthly_cost, 2, ',', ' ') ?> €<span style="font-size:15px;font-weight:400;color:var(--text-muted)">/mois</span></div>
        <div class="cost-label">Coût licences estimé (employé + machines assignées)</div>
    </div>
</div>
<?php endif; ?>

<div class="profile-grid">
    <!-- Informations -->
    <div class="profile-card">
        <div class="section-title">Informations</div>
        <div class="info-row"><span class="info-label">Prénom</span><span class="info-value"><?= h($emp['first_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Nom</span><span class="info-value"><?= h($emp['last_name']) ?></span></div>
        <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?= $emp['email'] ? '<a href="mailto:'.h($emp['email']).'" style="color:var(--accent)">'.h($emp['email']).'</a>' : '<span class="text-muted">—</span>' ?></span></div>
        <div class="info-row"><span class="info-label">Téléphone</span><span class="info-value"><?= $emp['phone'] ? h($emp['phone']) : '<span class="text-muted">—</span>' ?></span></div>
        <div class="info-row"><span class="info-label">Poste</span><span class="info-value"><?= $emp['position'] ? h($emp['position']) : '<span class="text-muted">—</span>' ?></span></div>
        <div class="info-row"><span class="info-label">Département</span><span class="info-value"><?= $emp['dept_name'] ? h($emp['dept_name']) : '<span class="text-muted">—</span>' ?></span></div>
        <div class="info-row"><span class="info-label">Statut</span><span class="info-value"><?= $emp['active'] ? '<span class="badge badge-active">Actif</span>' : '<span class="badge badge-retired">Inactif</span>' ?></span></div>
        <div class="info-row"><span class="info-label">Depuis</span><span class="info-value"><?= date('d/m/Y', strtotime($emp['created_at'])) ?></span></div>
    </div>

    <!-- Machines assignées -->
    <div class="profile-card">
        <div class="section-title">Machines assignées (<?= count($emp_assets) ?>)</div>
        <?php if (empty($emp_assets)): ?>
        <div class="text-muted" style="font-size:13px;padding:12px 0">Aucune machine assignée</div>
        <?php else: ?>
        <?php foreach ($emp_assets as $a): ?>
        <div class="asset-row">
            <?= os_badge($a['os_type']) ?>
            <div class="asset-row-info">
                <div class="asset-hostname">
                    <a href="<?= APP_URL ?>/pages/assets.php#asset-<?= $a['id'] ?>" style="color:var(--accent);text-decoration:none"><?= h($a['hostname']) ?></a>
                </div>
                <div class="asset-meta"><?= h(implode(' · ', array_filter([$a['model'] ?: $a['brand'], $a['ram_gb'] ? $a['ram_gb'].'Go RAM' : null, $a['storage_gb'] ? $a['storage_gb'].'Go' : null]))) ?></div>
            </div>
            <?= status_badge($a['status']) ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Licences employé -->
<div class="profile-card-full">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div class="section-title" style="margin:0">Licences nominatives (<?= $active_lics_count ?> actives<?= $revoked_lics_count ? ', '.$revoked_lics_count.' révoquées' : '' ?>)</div>
        <?php if ($revoked_lics_count): ?>
        <button class="btn btn-ghost btn-sm" onclick="toggleRevoked(this)" id="toggle-revoked-btn">Afficher révoquées</button>
        <?php endif; ?>
    </div>
    <?php if (empty($emp_lics)): ?>
    <div class="text-muted" style="font-size:13px;padding:8px 0">Aucune licence nominative</div>
    <?php else: ?>
    <?php foreach ($emp_lics as $l): ?>
    <div class="lic-row <?= $l['active'] ? '' : 'revoked revoked-row' ?>" style="<?= $l['active'] ? '' : 'display:none' ?>">
        <div style="width:8px;height:8px;border-radius:50%;background:<?= $l['active'] ? 'var(--success)' : 'var(--danger)' ?>;flex-shrink:0"></div>
        <div style="flex:1;min-width:0">
            <div class="lic-name"><?= h($l['name']) ?><?= $l['vendor'] ? ' <span class="text-muted">('.h($l['vendor']).')</span>' : '' ?></div>
            <div class="lic-meta">
                <?= h(ucfirst($l['category'])) ?> ·
                <?= $l['cost_per_seat'] > 0 ? number_format((float)$l['cost_per_seat'],2,',','').'€/'.(($l['billing_period']==='annual')?'an':'mois') : 'Inclus' ?>
                <?php if (!$l['active'] && $l['revoked_at']): ?> · <span style="color:var(--danger)">Révoquée le <?= date('d/m/Y', strtotime($l['revoked_at'])) ?></span><?php endif; ?>
            </div>
        </div>
        <span class="badge <?= $l['active'] ? 'badge-active' : 'badge-retired' ?>"><?= $l['active'] ? 'Active' : 'Révoquée' ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Licences machines -->
<?php if (!empty($asset_lics_rows)): ?>
<div class="profile-card-full">
    <div class="section-title">Licences machines (<?= count($asset_lics_rows) ?>)</div>
    <?php foreach ($asset_lics_rows as $l): ?>
    <div class="lic-row">
        <div style="width:8px;height:8px;border-radius:50%;background:var(--accent);flex-shrink:0"></div>
        <div style="flex:1;min-width:0">
            <div class="lic-name"><?= h($l['name']) ?><?= $l['vendor'] ? ' <span class="text-muted">('.h($l['vendor']).')</span>' : '' ?></div>
            <div class="lic-meta"><?= h($l['hostname']) ?> · <?= h(ucfirst($l['category'])) ?> · <?= $l['cost_per_seat'] > 0 ? number_format((float)$l['cost_per_seat'],2,',','').'€' : 'Inclus' ?></div>
        </div>
        <span class="badge badge-active">Active</span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal Offboarding -->
<div class="modal-backdrop" id="modal-offboarding" style="display:none">
<div class="modal" style="max-width:640px;width:100%">
    <div class="modal-header">
        <h2 class="modal-title">Offboarding — <?= h($emp['first_name'] . ' ' . $emp['last_name']) ?></h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-offboarding')"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:20px">
            Choisissez que faire des ressources de cet utilisateur avant de le désactiver.
        </p>

        <!-- Machines -->
        <div style="margin-bottom:20px">
            <div class="section-title">Machines (<?= count($emp_assets) ?>)</div>
            <?php if (empty($emp_assets)): ?>
            <div class="text-muted" style="font-size:13px">Aucune machine assignée</div>
            <?php else: ?>
            <div id="offb-assets">
            <?php foreach ($emp_assets as $a): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)" data-asset-id="<?= $a['id'] ?>">
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600;font-family:monospace;color:var(--accent)"><?= h($a['hostname']) ?></div>
                    <div style="font-size:12px;color:var(--text-muted)"><?= h($a['os_type']) ?><?= $a['model'] ? ' · '.h($a['model']) : '' ?></div>
                </div>
                <select class="form-control" style="width:auto;font-size:12.5px" onchange="offbAssetChange(this,<?= $a['id'] ?>)">
                    <option value="stock">Mettre en stock</option>
                    <?php if (!empty($other_employees)): ?>
                    <option value="reassign">Réassigner à…</option>
                    <?php endif; ?>
                </select>
                <select class="form-control offb-reassign-sel" id="offb-reassign-<?= $a['id'] ?>" style="width:auto;font-size:12.5px;display:none">
                    <?php foreach ($other_employees as $oe): ?>
                    <option value="<?= $oe['id'] ?>"><?= h($oe['first_name'] . ' ' . $oe['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Licences nominatives actives -->
        <?php $active_emp_lics = array_filter($emp_lics, fn($l) => $l['active']); ?>
        <?php if (!empty($active_emp_lics)): ?>
        <div style="margin-bottom:20px">
            <div class="section-title">Licences nominatives (<?= count($active_emp_lics) ?>)</div>
            <div id="offb-licenses">
            <?php foreach ($active_emp_lics as $l): ?>
            <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border)" data-asgn-id="<?= $l['assignment_id'] ?>" data-lic-id="<?= $l['license_id'] ?>">
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600"><?= h($l['name']) ?></div>
                    <div style="font-size:12px;color:var(--text-muted)"><?= h(ucfirst($l['category'])) ?></div>
                </div>
                <select class="form-control" style="width:auto;font-size:12.5px" onchange="offbLicChange(this,<?= $l['assignment_id'] ?>)">
                    <option value="revoke">Révoquer</option>
                    <?php if (!empty($other_employees)): ?>
                    <option value="transfer">Transférer à…</option>
                    <?php endif; ?>
                    <option value="keep">Conserver</option>
                </select>
                <select class="form-control offb-lic-reassign-sel" id="offb-lic-reassign-<?= $l['assignment_id'] ?>" style="width:auto;font-size:12.5px;display:none">
                    <?php foreach ($other_employees as $oe): ?>
                    <option value="<?= $oe['id'] ?>"><?= h($oe['first_name'] . ' ' . $oe['last_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div style="background:var(--warning-dim);border:1px solid rgba(245,166,35,0.3);border-radius:var(--radius-sm);padding:12px 14px;font-size:13px;color:var(--warning);margin-bottom:16px">
            L'utilisateur sera désactivé. Son compte reste visible dans l'historique.
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="Modal.close('modal-offboarding')">Annuler</button>
        <button class="btn btn-danger" onclick="confirmOffboarding()" id="btn-offboard">
            <svg><use href="#icon-x"/></svg> Confirmer l'offboarding
        </button>
    </div>
</div>
</div>

<!-- Modal Edit employee -->
<div class="modal-backdrop" id="modal-emp" style="display:none">
<div class="modal" style="max-width:500px;width:100%">
    <div class="modal-header">
        <h2 class="modal-title">Modifier l'utilisateur</h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-emp')"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div class="modal-body">
        <form id="form-emp">
            <input type="hidden" name="id" value="<?= $emp_id ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="first_name" class="form-control" value="<?= h($emp['first_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Nom</label>
                    <input type="text" name="last_name" class="form-control" value="<?= h($emp['last_name']) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= h($emp['email'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Téléphone</label>
                <input type="text" name="phone" class="form-control" value="<?= h($emp['phone'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Poste</label>
                <input type="text" name="position" class="form-control" value="<?= h($emp['position'] ?? '') ?>">
            </div>
            <input type="hidden" name="active" value="<?= $emp['active'] ?>">
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="Modal.close('modal-emp')">Annuler</button>
        <button class="btn btn-primary" onclick="saveEmployee()"><svg><use href="#icon-check"/></svg> Enregistrer</button>
    </div>
</div>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';
const EMP_ID  = <?= $emp_id ?>;

function toggleRevoked(btn) {
    const rows = document.querySelectorAll('.revoked-row');
    const showing = btn.textContent.includes('Masquer');
    rows.forEach(r => r.style.display = showing ? 'none' : 'flex');
    btn.textContent = showing ? 'Afficher révoquées' : 'Masquer révoquées';
}

function editEmployee() { Modal.open('modal-emp'); }

async function saveEmployee() {
    const form = document.getElementById('form-emp');
    const data = Object.fromEntries(new FormData(form));
    try {
        await api(APP_URL + '/api/employees.php', { method: 'PUT', body: data });
        toast('Utilisateur mis à jour', 'success');
        Modal.close('modal-emp');
        setTimeout(() => location.reload(), 700);
    } catch(e) {}
}

async function reactivate() {
    if (!confirm('Réactiver cet utilisateur ?')) return;
    try {
        await api(APP_URL + '/api/employees.php', { method: 'PUT', body: {
            id: EMP_ID,
            first_name: <?= json_encode($emp['first_name']) ?>,
            last_name: <?= json_encode($emp['last_name']) ?>,
            email: <?= json_encode($emp['email']) ?>,
            phone: <?= json_encode($emp['phone']) ?>,
            position: <?= json_encode($emp['position']) ?>,
            department_id: <?= json_encode($emp['department_id']) ?>,
            active: 1
        }});
        toast('Utilisateur réactivé', 'success');
        setTimeout(() => location.reload(), 700);
    } catch(e) {}
}

function openOffboarding() { Modal.open('modal-offboarding'); }

function offbAssetChange(sel, assetId) {
    const rsel = document.getElementById('offb-reassign-' + assetId);
    if (rsel) rsel.style.display = sel.value === 'reassign' ? '' : 'none';
}

function offbLicChange(sel, asgnId) {
    const rsel = document.getElementById('offb-lic-reassign-' + asgnId);
    if (rsel) rsel.style.display = sel.value === 'transfer' ? '' : 'none';
}

async function confirmOffboarding() {
    const btn = document.getElementById('btn-offboard');
    btn.disabled = true; btn.textContent = 'Traitement…';

    const assets = [];
    document.querySelectorAll('#offb-assets [data-asset-id]').forEach(row => {
        const assetId = parseInt(row.dataset.assetId);
        const sel = row.querySelector('select:not(.offb-reassign-sel)');
        const rsel = row.querySelector('.offb-reassign-sel');
        const action = sel ? sel.value : 'stock';
        const entry = { asset_id: assetId, action };
        if (action === 'reassign' && rsel) entry.reassign_to = parseInt(rsel.value);
        assets.push(entry);
    });

    const licenses = [];
    document.querySelectorAll('#offb-licenses [data-asgn-id]').forEach(row => {
        const asgnId = parseInt(row.dataset.asgnId);
        const licId  = parseInt(row.dataset.licId);
        const sel = row.querySelector('select:not(.offb-lic-reassign-sel)');
        const rsel = row.querySelector('.offb-lic-reassign-sel');
        const action = sel ? sel.value : 'revoke';
        const entry = { assignment_id: asgnId, license_id: licId, action };
        if (action === 'transfer' && rsel) entry.transfer_to = parseInt(rsel.value);
        licenses.push(entry);
    });

    try {
        await api(APP_URL + '/api/employees.php', { method: 'PATCH', body: { id: EMP_ID, assets, licenses }});
        toast('Offboarding effectué', 'success');
        Modal.close('modal-offboarding');
        setTimeout(() => location.reload(), 800);
    } catch(e) {
        btn.disabled = false;
        btn.textContent = 'Confirmer l\'offboarding';
    }
}
</script>
<?php render_footer(); ?>
