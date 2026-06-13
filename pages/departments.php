<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();
$stmt = $pdo->prepare('SELECT d.*,
    (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.active=1) as emp_count,
    (SELECT COUNT(*) FROM assets a WHERE a.department_id = d.id AND a.status != "retired") as asset_count
FROM departments d WHERE d.client_id = ? ORDER BY d.name');
$stmt->execute([$client_id]);
$departments = $stmt->fetchAll();

// Simplify: compute monthly cost directly
$dept_costs = [];
foreach ($departments as $d) {
    // Asset licenses
    $s1 = $pdo->prepare('SELECT l.cost_per_seat, l.billing_period
        FROM license_assignments la
        JOIN licenses l ON la.license_id = l.id
        JOIN assets a ON la.asset_id = a.id
        WHERE a.department_id = ? AND la.active = 1');
    $s1->execute([$d['id']]);
    $cost = 0;
    foreach ($s1->fetchAll() as $r) {
        $cost += $r['billing_period'] === 'annual' ? (float)$r['cost_per_seat'] / 12 : (float)$r['cost_per_seat'];
    }
    // Employee licenses
    $s2 = $pdo->prepare('SELECT l.cost_per_seat, l.billing_period
        FROM license_assignments la
        JOIN licenses l ON la.license_id = l.id
        JOIN employees e ON la.employee_id = e.id
        WHERE e.department_id = ? AND la.active = 1 AND e.active = 1');
    $s2->execute([$d['id']]);
    foreach ($s2->fetchAll() as $r) {
        $cost += $r['billing_period'] === 'annual' ? (float)$r['cost_per_seat'] / 12 : (float)$r['cost_per_seat'];
    }
    // License count
    $lc1 = $pdo->prepare('SELECT COUNT(*) FROM license_assignments la JOIN assets a ON la.asset_id=a.id WHERE a.department_id=? AND la.active=1');
    $lc1->execute([$d['id']]);
    $lc2 = $pdo->prepare('SELECT COUNT(*) FROM license_assignments la JOIN employees e ON la.employee_id=e.id WHERE e.department_id=? AND la.active=1 AND e.active=1');
    $lc2->execute([$d['id']]);
    $dept_costs[$d['id']] = [
        'monthly_cost' => $cost,
        'lic_count'    => (int)$lc1->fetchColumn() + (int)$lc2->fetchColumn(),
    ];
}

render_head('Départements');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('departments'); ?>
<div class="main-wrapper">
<?php render_topbar('Départements', count($departments) . ' départements'); ?>
<main class="main-content">

<div class="toolbar">
    <div class="search-box">
        <svg><use href="#icon-search"/></svg>
        <input type="text" id="search-input" class="search-input" placeholder="Rechercher un département…">
    </div>
    <div style="margin-left:auto">
        <button class="btn btn-primary" onclick="Modal.open('modal-dept')">
            <svg><use href="#icon-plus"/></svg> Nouveau département
        </button>
    </div>
</div>

<div class="table-wrapper">
    <table id="main-table">
        <thead>
            <tr>
                <th data-sort="name">Département</th>
                <th data-sort="code">Code</th>
                <th data-sort="employees">Utilisateurs</th>
                <th data-sort="assets">Postes</th>
                <th data-sort="licenses">Licences</th>
                <th data-sort="cost">Coût/mois</th>
                <th style="cursor:default">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($departments)): ?>
            <tr><td colspan="7">
                <div class="empty-state">
                    <svg><use href="#icon-building"/></svg>
                    <h3>Aucun département</h3>
                    <button class="btn btn-primary" onclick="Modal.open('modal-dept')"><svg><use href="#icon-plus"/></svg> Ajouter</button>
                </div>
            </td></tr>
        <?php else: ?>
        <?php foreach ($departments as $d):
            $cost = $dept_costs[$d['id']]['monthly_cost'] ?? 0;
            $lics = $dept_costs[$d['id']]['lic_count'] ?? 0;
        ?>
            <tr data-id="<?= $d['id'] ?>" style="cursor:pointer" onclick="window.location.href='<?= APP_URL ?>/pages/employees.php?dept=<?= urlencode(strtolower($d['name'])) ?>'" title="Voir les utilisateurs de ce département">
                <td data-col="name" style="font-weight:600"><?= h($d['name']) ?></td>
                <td data-col="code"><span class="badge badge-stock" style="font-family:monospace"><?= h($d['code']) ?></span></td>
                <td data-col="employees">
                    <a href="<?= APP_URL ?>/pages/employees.php?dept=<?= urlencode(strtolower($d['name'])) ?>" onclick="event.stopPropagation()" style="color:var(--text-primary);text-decoration:none">
                        <span class="fw-600"><?= $d['emp_count'] ?></span> <span class="text-muted">utilisateurs</span>
                    </a>
                </td>
                <td data-col="assets">
                    <a href="<?= APP_URL ?>/pages/assets.php" onclick="event.stopPropagation()" style="color:var(--text-primary);text-decoration:none">
                        <span class="fw-600"><?= $d['asset_count'] ?></span> <span class="text-muted">postes</span>
                    </a>
                </td>
                <td data-col="licenses">
                    <?php if ($lics > 0): ?>
                    <span class="badge badge-active"><?= $lics ?> licence<?= $lics > 1 ? 's' : '' ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td data-col="cost">
                    <?php if ($cost > 0): ?>
                    <span style="font-weight:600;color:var(--accent)"><?= number_format($cost,2,',','') ?> €</span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td onclick="event.stopPropagation()">
                    <div class="td-actions">
                        <button class="btn btn-ghost btn-icon" onclick="openDeptPanel(<?= $d['id'] ?>)" title="Détails"><svg><use href="#icon-layers"/></svg></button>
                        <button class="btn btn-ghost btn-icon" onclick="editDept(<?= $d['id'] ?>)" title="Modifier"><svg><use href="#icon-edit"/></svg></button>
                        <button class="btn btn-ghost btn-icon" onclick="deleteDept(<?= $d['id'] ?>, '<?= h(addslashes($d['name'])) ?>')" title="Supprimer"><svg style="color:var(--danger)"><use href="#icon-trash"/></svg></button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Panneau latéral détail département -->
<div class="asset-panel-backdrop" id="dp-backdrop" onclick="closeDeptPanel()" style="display:none"></div>
<div class="asset-panel" id="dp-panel" style="display:none;width:480px">
    <div class="asset-panel-header">
        <div>
            <div class="asset-panel-title" id="dp-title">Département</div>
            <div class="asset-panel-sub" id="dp-sub"></div>
        </div>
        <button class="btn btn-ghost btn-icon" onclick="closeDeptPanel()"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div style="display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:0">
        <button class="asset-panel-tab active" onclick="dpTab(this,'dp-tab-employees')" style="flex:1">Utilisateurs</button>
        <button class="asset-panel-tab" onclick="dpTab(this,'dp-tab-assets')" style="flex:1">Postes</button>
        <button class="asset-panel-tab" onclick="dpTab(this,'dp-tab-licenses')" style="flex:1">Licences</button>
    </div>
    <div class="asset-panel-body" style="overflow-y:auto">
        <div id="dp-tab-employees">
            <div id="dp-employees-list" style="padding:8px 0"></div>
        </div>
        <div id="dp-tab-assets" style="display:none">
            <div id="dp-assets-list" style="padding:8px 0"></div>
        </div>
        <div id="dp-tab-licenses" style="display:none">
            <div id="dp-licenses-list" style="padding:8px 0"></div>
        </div>
    </div>
    <!-- Résumé coûts -->
    <div id="dp-cost-footer" style="border-top:1px solid var(--border);padding:16px 20px;background:var(--bg-elevated);display:none">
        <div style="display:flex;justify-content:space-between;align-items:center">
            <div style="font-size:12.5px;color:var(--text-muted)">Coût licences estimé/mois</div>
            <div style="font-size:18px;font-weight:700;color:var(--accent)" id="dp-cost-value"></div>
        </div>
    </div>
</div>
</main>
</div>

<div class="modal-backdrop" id="modal-dept" style="display:none">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <h2 class="modal-title" id="modal-dept-title">Nouveau département</h2>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body">
            <form id="form-dept" autocomplete="off">
                <input type="hidden" name="id" id="dept-id">
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <div class="form-group">
                    <label>Nom <span class="required">*</span></label>
                    <input type="text" name="name" id="dept-name" class="form-control" required placeholder="Informatique">
                </div>
                <div class="form-group">
                    <label>Code <span class="required">*</span></label>
                    <input type="text" name="code" id="dept-code" class="form-control" required placeholder="IT" maxlength="20"
                        style="text-transform:uppercase;font-family:monospace">
                    <div class="form-hint">Utilisé dans la génération des noms de postes (ex : {DEPT})</div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-modal-close>Annuler</button>
            <button class="btn btn-primary" onclick="saveDept()"><svg><use href="#icon-check"/></svg> Enregistrer</button>
        </div>
    </div>
</div>

<?php
$depts_json = json_encode(array_map(function($d) use ($dept_costs) {
    $arr = array_map(fn($v) => $v === null ? '' : $v, $d);
    $arr['monthly_cost'] = round($dept_costs[$d['id']]['monthly_cost'] ?? 0, 2);
    $arr['lic_count']    = $dept_costs[$d['id']]['lic_count'] ?? 0;
    return $arr;
}, $departments), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<script>
const DEPTS = <?= $depts_json ?>;
const APP_URL = '<?= APP_URL ?>';

function editDept(id) {
    const d = DEPTS.find(x => x.id == id);
    if (!d) return;
    document.getElementById('modal-dept-title').textContent = 'Modifier : ' + d.name;
    document.getElementById('dept-id').value = d.id;
    document.getElementById('dept-name').value = d.name;
    document.getElementById('dept-code').value = d.code;
    Modal.open('modal-dept');
}

async function saveDept() {
    const form = document.getElementById('form-dept');
    const data = Object.fromEntries(new FormData(form));
    data.code = data.code.toUpperCase();
    try {
        await api(`${APP_URL}/api/departments.php`, { method: data.id ? 'PUT' : 'POST', body: data });
        toast(data.id ? 'Département mis à jour' : 'Département créé', 'success');
        Modal.close('modal-dept');
        setTimeout(() => location.reload(), 700);
    } catch(e) {}
}

async function deleteDept(id, name) {
    if (!confirm('Supprimer "' + name + '" ? Les postes et utilisateurs associés seront désassociés.')) return;
    try {
        await api(`${APP_URL}/api/departments.php`, { method: 'DELETE', body: { id } });
        toast('Département supprimé', 'success');
        setTimeout(() => location.reload(), 600);
    } catch(e) {}
}

// ── PANEL ──
function dpTab(btn, tabId) {
    document.querySelectorAll('#dp-panel .asset-panel-tab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    ['dp-tab-employees','dp-tab-assets','dp-tab-licenses'].forEach(id => {
        document.getElementById(id).style.display = id === tabId ? '' : 'none';
    });
}

async function openDeptPanel(deptId) {
    const d = DEPTS.find(x => x.id == deptId);
    if (!d) return;
    document.getElementById('dp-title').textContent = d.name;
    document.getElementById('dp-sub').textContent = 'Code : ' + d.code;
    document.getElementById('dp-panel').style.display = '';
    document.getElementById('dp-backdrop').style.display = '';
    document.getElementById('dp-employees-list').innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:16px 0">Chargement…</div>';
    document.getElementById('dp-assets-list').innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:16px 0">Chargement…</div>';
    document.getElementById('dp-licenses-list').innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:16px 0">Chargement…</div>';

    if (d.monthly_cost > 0) {
        document.getElementById('dp-cost-footer').style.display = '';
        document.getElementById('dp-cost-value').textContent = parseFloat(d.monthly_cost).toLocaleString('fr-FR',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' €';
    } else {
        document.getElementById('dp-cost-footer').style.display = 'none';
    }

    try {
        const [emps, assets, lics] = await Promise.all([
            fetch(`${APP_URL}/api/departments.php?detail=employees&dept_id=${deptId}`, {credentials:'same-origin'}).then(r=>r.json()),
            fetch(`${APP_URL}/api/departments.php?detail=assets&dept_id=${deptId}`, {credentials:'same-origin'}).then(r=>r.json()),
            fetch(`${APP_URL}/api/departments.php?detail=licenses&dept_id=${deptId}`, {credentials:'same-origin'}).then(r=>r.json()),
        ]);
        renderDpEmployees(emps.data || []);
        renderDpAssets(assets.data || []);
        renderDpLicenses(lics.data || []);
    } catch(e) {
        document.getElementById('dp-employees-list').innerHTML = '<div style="color:var(--danger);font-size:13px">Erreur de chargement</div>';
    }
}

function closeDeptPanel() {
    document.getElementById('dp-panel').style.display = 'none';
    document.getElementById('dp-backdrop').style.display = 'none';
}

function renderDpEmployees(list) {
    const el = document.getElementById('dp-employees-list');
    if (!list.length) { el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:12px 0">Aucun utilisateur actif</div>'; return; }
    el.innerHTML = list.map(e => `
        <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)">
            <div style="width:32px;height:32px;border-radius:50%;background:var(--accent-dim);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">
                ${escapeHtml((e.first_name[0]+e.last_name[0]).toUpperCase())}
            </div>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600">
                    <a href="${APP_URL}/pages/employee-profile.php?id=${e.id}" style="color:var(--text-primary);text-decoration:none" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-primary)'">${escapeHtml(e.first_name)} ${escapeHtml(e.last_name)}</a>
                </div>
                <div style="font-size:12px;color:var(--text-muted)">${escapeHtml(e.position||'—')}</div>
            </div>
            <span style="font-size:12px;color:var(--text-muted)">${e.asset_count} poste${e.asset_count!=1?'s':''}</span>
        </div>`).join('');
}

function renderDpAssets(list) {
    const el = document.getElementById('dp-assets-list');
    const osBadge = os => ({MAC:'<span class="badge badge-mac">MAC</span>',WIN:'<span class="badge badge-win">WIN</span>',LIN:'<span class="badge badge-lin">LIN</span>'}[os]||escapeHtml(os));
    const stBadge = s => ({active:'<span class="badge badge-active">Actif</span>',stock:'<span class="badge badge-stock">Stock</span>',repair:'<span class="badge badge-repair">Réparation</span>',retired:'<span class="badge badge-retired">Retraité</span>'}[s]||escapeHtml(s));
    if (!list.length) { el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:12px 0">Aucun poste</div>'; return; }
    el.innerHTML = list.map(a => `
        <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border)">
            ${osBadge(a.os_type)}
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600;font-family:monospace;color:var(--accent)">${escapeHtml(a.hostname)}</div>
                <div style="font-size:12px;color:var(--text-muted)">${escapeHtml(a.assigned_name||'Non assigné')}${a.model?' · '+escapeHtml(a.model):''}</div>
            </div>
            ${stBadge(a.status)}
        </div>`).join('');
}

function renderDpLicenses(list) {
    const el = document.getElementById('dp-licenses-list');
    if (!list.length) { el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:12px 0">Aucune licence active</div>'; return; }
    el.innerHTML = list.map(l => {
        const cost = parseFloat(l.cost_per_seat||0);
        const costStr = cost > 0 ? cost.toLocaleString('fr-FR',{minimumFractionDigits:2})+'€/'+(l.billing_period==='annual'?'an':'mois') : 'Inclus';
        return `<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
            <div style="width:8px;height:8px;border-radius:50%;background:var(--success);flex-shrink:0"></div>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600">${escapeHtml(l.name)}${l.vendor?' <span style="color:var(--text-muted)">('+escapeHtml(l.vendor)+')</span>':''}</div>
                <div style="font-size:12px;color:var(--text-muted)">${escapeHtml(l.assigned_to_label||'')} · ${costStr}</div>
            </div>
            <span class="badge badge-active">Active</span>
        </div>`;
    }).join('');
}
</script>
<?php render_footer(); ?>
