<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/custom_fields.php';
require_auth();
if (!is_superadmin()) { header('Location: ' . APP_URL . '/'); exit; }

render_head('Champs personnalisés');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('custom-fields'); ?>
<div class="main-wrapper">
<?php render_topbar('Champs personnalisés', 'Ajoutez vos propres champs aux fiches'); ?>
<main class="main-content" style="max-width:900px">

<div class="toolbar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <div class="form-group" style="margin:0;min-width:220px">
        <select id="entity-select" class="form-control" onchange="loadFields()">
            <?php foreach (cf_entities() as $k => $lbl): ?>
            <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="margin-left:auto">
        <button class="btn btn-primary" onclick="openModal()"><svg><use href="#icon-plus"/></svg> Nouveau champ</button>
    </div>
</div>

<div class="table-wrapper">
<table id="main-table">
    <thead><tr><th>Libellé</th><th>Clé</th><th>Type</th><th>Obligatoire</th><th>Statut</th><th style="cursor:default">Actions</th></tr></thead>
    <tbody id="fields-body"><tr><td colspan="6" class="text-muted" style="text-align:center;padding:24px">Chargement…</td></tr></tbody>
</table>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="modal-cf" style="display:none">
<div class="modal" style="max-width:460px;width:100%">
    <div class="modal-header">
        <h2 class="modal-title" id="cf-modal-title">Nouveau champ</h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-cf')"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div class="modal-body">
        <form id="form-cf" autocomplete="off">
            <input type="hidden" id="cf-id">
            <div class="form-group"><label class="form-label">Libellé <span class="required">*</span></label>
                <input type="text" id="cf-label" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Type <span class="required">*</span></label>
                <select id="cf-type" class="form-control" onchange="onTypeChange()">
                    <?php foreach (cf_types() as $k => $lbl): ?><option value="<?= h($k) ?>"><?= h($lbl) ?></option><?php endforeach; ?>
                </select></div>
            <div class="form-group" id="cf-options-group" style="display:none">
                <label class="form-label">Options (une par ligne)</label>
                <textarea id="cf-options" class="form-control" rows="4" placeholder="Option 1&#10;Option 2"></textarea>
            </div>
            <div class="form-group"><label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" id="cf-required" style="accent-color:var(--accent);width:16px;height:16px"> Champ obligatoire</label></div>
            <div class="form-group" id="cf-active-group" style="display:none"><label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" id="cf-active" style="accent-color:var(--accent);width:16px;height:16px"> Actif</label></div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="Modal.close('modal-cf')">Annuler</button>
        <button class="btn btn-primary" onclick="saveField()" id="cf-save-btn"><svg><use href="#icon-check"/></svg> Enregistrer</button>
    </div>
</div>
</div>

<script>
const APP_URL = '<?= APP_URL ?>';
const TYPES = <?= json_encode(cf_types(), JSON_HEX_TAG) ?>;
let FIELDS = [];

function entity() { return document.getElementById('entity-select').value; }

async function loadFields() {
    const body = document.getElementById('fields-body');
    body.innerHTML = '<tr><td colspan="6" class="text-muted" style="text-align:center;padding:24px">Chargement…</td></tr>';
    try {
        const r = await api(`${APP_URL}/api/custom-fields.php?entity=${entity()}`, {method:'GET'});
        FIELDS = r.data || [];
        if (!FIELDS.length) { body.innerHTML = '<tr><td colspan="6" class="text-muted" style="text-align:center;padding:24px">Aucun champ. Cliquez sur « Nouveau champ ».</td></tr>'; return; }
        body.innerHTML = FIELDS.map(f => `<tr>
            <td style="font-weight:600">${esc(f.label)}</td>
            <td style="font-family:monospace;font-size:12px;color:var(--text-muted)">${esc(f.field_key)}</td>
            <td>${esc(TYPES[f.field_type] || f.field_type)}</td>
            <td>${f.required == 1 ? 'Oui' : '—'}</td>
            <td>${f.active == 1 ? '<span class="badge badge-active">Actif</span>' : '<span class="badge badge-retired">Inactif</span>'}</td>
            <td><div class="td-actions">
                <button class="btn btn-ghost btn-icon" onclick='openModal(${f.id})' title="Modifier"><svg><use href="#icon-edit"/></svg></button>
                <button class="btn btn-ghost btn-icon" onclick="deleteField(${f.id}, '${esc(f.label)}')" title="Supprimer"><svg style="color:var(--danger)"><use href="#icon-trash"/></svg></button>
            </div></td></tr>`).join('');
    } catch(e) {}
}

function esc(s){ return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

function onTypeChange() {
    document.getElementById('cf-options-group').style.display = document.getElementById('cf-type').value === 'select' ? '' : 'none';
}

function openModal(id) {
    document.getElementById('form-cf').reset();
    document.getElementById('cf-id').value = '';
    document.getElementById('cf-active-group').style.display = 'none';
    document.getElementById('cf-modal-title').textContent = 'Nouveau champ';
    onTypeChange();
    if (id) {
        const f = FIELDS.find(x => x.id == id);
        if (!f) return;
        document.getElementById('cf-modal-title').textContent = 'Modifier : ' + f.label;
        document.getElementById('cf-id').value = f.id;
        document.getElementById('cf-label').value = f.label;
        document.getElementById('cf-type').value = f.field_type;
        document.getElementById('cf-required').checked = f.required == 1;
        document.getElementById('cf-active').checked = f.active == 1;
        document.getElementById('cf-active-group').style.display = '';
        try { document.getElementById('cf-options').value = (JSON.parse(f.options || '[]') || []).join('\n'); } catch(e){ document.getElementById('cf-options').value=''; }
        onTypeChange();
    }
    Modal.open('modal-cf');
}

async function saveField() {
    const id = document.getElementById('cf-id').value;
    const label = document.getElementById('cf-label').value.trim();
    if (!label) { toast('Libellé requis', 'error'); return; }
    const body = {
        label,
        field_type: document.getElementById('cf-type').value,
        options: document.getElementById('cf-options').value,
        required: document.getElementById('cf-required').checked ? 1 : 0,
    };
    if (id) { body.id = id; body.active = document.getElementById('cf-active').checked ? 1 : 0; }
    else { body.entity_type = entity(); }
    const btn = document.getElementById('cf-save-btn'); btn.disabled = true;
    try {
        await api(`${APP_URL}/api/custom-fields.php`, {method: id ? 'PUT' : 'POST', body});
        toast(id ? 'Champ mis à jour' : 'Champ créé', 'success');
        Modal.close('modal-cf');
        loadFields();
    } catch(e) {} finally { btn.disabled = false; }
}

async function deleteField(id, label) {
    if (!confirm(`Supprimer le champ « ${label} » ? Les valeurs saisies seront perdues.`)) return;
    try {
        await api(`${APP_URL}/api/custom-fields.php`, {method:'DELETE', body:{id}});
        toast('Champ supprimé', 'success');
        loadFields();
    } catch(e) {}
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadFields);
else loadFields();
</script>
</main>
<?php render_footer(); ?>
