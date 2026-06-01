<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();
$departments = get_departments($client_id);
$employees   = get_employees($client_id);

$stmt = $pdo->prepare('SELECT a.*,
    CONCAT(e.first_name, " ", e.last_name) as assigned_name,
    d.name as dept_name, d.code as dept_code
FROM assets a
LEFT JOIN employees e ON a.assigned_to = e.id
LEFT JOIN departments d ON a.department_id = d.id
WHERE a.client_id = ?
ORDER BY a.created_at DESC');
$stmt->execute([$client_id]);
$assets = $stmt->fetchAll();

$client_stmt = $pdo->prepare('SELECT code FROM clients WHERE id = ?');
$client_stmt->execute([$client_id]);
$client = $client_stmt->fetch();

$keys_stmt = $pdo->prepare('SELECT id, name FROM api_keys WHERE client_id = ? AND active = 1 ORDER BY created_at DESC');
$keys_stmt->execute([$client_id]);
$api_keys = $keys_stmt->fetchAll();

render_head('Parc informatique');
render_icons();
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<?php render_sidebar('assets'); ?>
<div class="main-wrapper">
<?php render_topbar('Parc informatique', count($assets) . ' postes enregistrés'); ?>

<main class="main-content">

<!-- TOOLBAR -->
<div class="toolbar">
    <div class="search-box">
        <svg><use href="#icon-search"/></svg>
        <input type="text" id="search-input" class="search-input" placeholder="Rechercher un poste, hostname, utilisateur…">
    </div>
    <select id="os-filter" class="filter-select">
        <option value="">Tous les OS</option>
        <option value="mac">macOS</option>
        <option value="win">Windows</option>
        <option value="lin">Linux</option>
    </select>
    <select id="status-filter" class="filter-select">
        <option value="">Tous les statuts</option>
        <option value="actif">Actif</option>
        <option value="stock">En stock</option>
        <option value="réparation">En réparation</option>
        <option value="retraité">Retraité</option>
    </select>
    <select id="dept-filter" class="filter-select">
        <option value="">Tous les depts</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?= h(strtolower($d['name'])) ?>"><?= h($d['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <div style="margin-left:auto;display:flex;gap:8px">
        <button class="btn btn-secondary btn-sm" onclick="exportCSV()">
            <svg><use href="#icon-download"/></svg> Export
        </button>
        <button class="btn btn-secondary btn-sm" onclick="Modal.open('modal-endpoint')" style="border-color:rgba(157,123,255,0.4);color:var(--purple)">
            <svg><use href="#icon-layers"/></svg> Endpoint
        </button>
        <button class="btn btn-primary" onclick="Modal.open('modal-asset')">
            <svg><use href="#icon-plus"/></svg> Nouveau poste
        </button>
    </div>
</div>

<!-- TABLE -->
<div class="table-wrapper">
    <table id="main-table">
        <thead>
            <tr>
                <th data-sort="hostname">Hostname</th>
                <th data-sort="os">OS</th>
                <th data-sort="model">Modèle</th>
                <th data-sort="assigned">Assigné à</th>
                <th data-sort="dept">Département</th>
                <th data-sort="status">Statut</th>
                <th data-sort="warranty">Garantie</th>
                <th style="width:100px;cursor:default">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($assets)): ?>
            <tr class="empty-row">
                <td colspan="8">
                    <div class="empty-state">
                        <svg><use href="#icon-monitor"/></svg>
                        <h3>Aucun poste enregistré</h3>
                        <p>Commencez par ajouter votre premier poste informatique.</p>
                        <button class="btn btn-primary" onclick="Modal.open('modal-asset')">
                            <svg><use href="#icon-plus"/></svg> Ajouter un poste
                        </button>
                    </div>
                </td>
            </tr>
        <?php else: ?>
        <?php foreach ($assets as $a):
            $warranty_class = '';
            if ($a['warranty_until']) {
                $days_left = (strtotime($a['warranty_until']) - time()) / 86400;
                if ($days_left < 0) $warranty_class = 'text-danger';
                elseif ($days_left < 90) $warranty_class = 'text-warning';
                else $warranty_class = 'text-success';
            }
        ?>
            <tr data-id="<?= $a['id'] ?>">
                <td class="td-hostname" data-col="hostname">
                    <span><?= h($a['hostname']) ?></span>
                    <?php if ($a['ip_address']): ?>
                    <div class="td-meta"><?= h($a['ip_address']) ?></div>
                    <?php endif; ?>
                </td>
                <td data-col="os"><span class="col-os"><?= os_badge($a['os_type']) ?></span></td>
                <td data-col="model">
                    <?php if ($a['brand'] || $a['model']): ?>
                    <span><?= h(trim($a['brand'] . ' ' . $a['model'])) ?></span>
                    <?php if ($a['cpu'] || $a['ram_gb']): ?>
                    <div class="td-meta"><?= h(implode(' · ', array_filter([$a['cpu'], $a['ram_gb'] ? $a['ram_gb'].'Go' : null]))) ?></div>
                    <?php endif; ?>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td data-col="assigned">
                    <?php if ($a['assigned_name']): ?>
                    <span><?= h($a['assigned_name']) ?></span>
                    <?php else: ?><span class="text-muted">Non assigné</span><?php endif; ?>
                </td>
                <td data-col="dept" class="col-dept">
                    <?= $a['dept_name'] ? h($a['dept_name']) : '<span class="text-muted">—</span>' ?>
                </td>
                <td data-col="status" class="col-status"><?= status_badge($a['status']) ?></td>
                <td data-col="warranty">
                    <?php if ($a['warranty_until']): ?>
                    <span class="<?= $warranty_class ?>"><?= date('d/m/Y', strtotime($a['warranty_until'])) ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td>
                    <div class="td-actions">
                        <button class="btn btn-ghost btn-icon" title="Modifier" onclick="editAsset(<?= $a['id'] ?>)">
                            <svg><use href="#icon-edit"/></svg>
                        </button>
                        <button class="btn btn-ghost btn-icon" title="Détails" onclick="viewAsset(<?= $a['id'] ?>)">
                            <svg><use href="#icon-info"/></svg>
                        </button>
                        <button class="btn btn-ghost btn-icon" title="Supprimer" onclick="deleteAsset(<?= $a['id'] ?>, '<?= h(addslashes($a['hostname'])) ?>')">
                            <svg style="color:var(--danger)"><use href="#icon-trash"/></svg>
                        </button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main>
</div>

<!-- MODAL: ADD / EDIT ASSET -->
<div class="modal-backdrop" id="modal-asset" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h2 class="modal-title" id="modal-asset-title">Nouveau poste</h2>
            <button class="modal-close" data-modal-close>
                <svg><use href="#icon-x"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <form id="form-asset" autocomplete="off">
                <input type="hidden" name="id" id="asset-id">
                <input type="hidden" name="client_id" value="<?= $client_id ?>">

                <!-- OS + Hostname -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Type OS <span class="required">*</span></label>
                        <select name="os_type" id="asset-os" class="form-control" required onchange="onOsOrDeptChange()">
                            <option value="">Sélectionner</option>
                            <option value="MAC">macOS</option>
                            <option value="WIN">Windows</option>
                            <option value="LIN">Linux</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Département</label>
                        <select name="department_id" id="asset-dept" class="form-control" onchange="onOsOrDeptChange()">
                            <option value="">Sélectionner</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>"><?= h($d['name']) ?> (<?= h($d['code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Hostname <span class="required">*</span></label>
                    <div style="display:flex;gap:8px">
                        <input type="text" name="hostname" id="asset-hostname" class="form-control" required
                            placeholder="Ex: SAG-MAC-IT-001" style="font-family:monospace;font-weight:600;text-transform:uppercase"
                            oninput="this.value=this.value.toUpperCase()">
                        <button type="button" class="btn btn-secondary" onclick="loadSuggestions()" id="btn-suggest" disabled title="Suggérer des noms">
                            <svg><use href="#icon-layers"/></svg>
                        </button>
                    </div>
                    <div id="suggestions-wrap" style="display:none;margin-top:10px">
                        <div style="font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:7px">
                            Noms disponibles — cliquez pour sélectionner
                        </div>
                        <div id="suggestions-list" style="display:flex;flex-wrap:wrap;gap:7px"></div>
                    </div>
                </div>

                <!-- Hardware -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Marque</label>
                        <input type="text" name="brand" id="asset-brand" class="form-control" placeholder="Apple, Dell, Lenovo…">
                    </div>
                    <div class="form-group">
                        <label>Modèle</label>
                        <input type="text" name="model" id="asset-model" class="form-control" placeholder="MacBook Pro 14, Latitude 7430…">
                    </div>
                </div>
                <div class="form-row-3">
                    <div class="form-group">
                        <label>RAM (Go)</label>
                        <input type="number" name="ram_gb" id="asset-ram" class="form-control" placeholder="16" min="1">
                    </div>
                    <div class="form-group">
                        <label>Stockage (Go)</label>
                        <input type="number" name="storage_gb" id="asset-storage" class="form-control" placeholder="512" min="1">
                    </div>
                    <div class="form-group">
                        <label>Type stockage</label>
                        <select name="storage_type" id="asset-storage-type" class="form-control">
                            <option value="">—</option>
                            <option value="SSD">SSD</option>
                            <option value="NVMe">NVMe</option>
                            <option value="HDD">HDD</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Processeur</label>
                        <input type="text" name="cpu" id="asset-cpu" class="form-control" placeholder="Apple M3 Pro, Intel i7-1265U…">
                    </div>
                    <div class="form-group">
                        <label>Version OS</label>
                        <input type="text" name="os_version" id="asset-os-version" class="form-control" placeholder="macOS 14.4, Windows 11 23H2…">
                    </div>
                </div>

                <!-- Network -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Adresse IP</label>
                        <input type="text" name="ip_address" id="asset-ip" class="form-control" placeholder="192.168.1.100">
                    </div>
                    <div class="form-group">
                        <label>Adresse MAC</label>
                        <input type="text" name="mac_address" id="asset-mac" class="form-control" placeholder="AA:BB:CC:DD:EE:FF">
                    </div>
                </div>

                <!-- Info -->
                <div class="form-row">
                    <div class="form-group">
                        <label>N° Série</label>
                        <input type="text" name="serial_number" id="asset-serial" class="form-control" placeholder="C02X…">
                    </div>
                    <div class="form-group">
                        <label>N° Inventaire</label>
                        <input type="text" name="asset_tag" id="asset-tag" class="form-control" placeholder="INV-2024-001">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Date d'achat</label>
                        <input type="date" name="purchase_date" id="asset-purchase" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Fin de garantie</label>
                        <input type="date" name="warranty_until" id="asset-warranty" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Statut</label>
                        <select name="status" id="asset-status" class="form-control">
                            <option value="stock">En stock</option>
                            <option value="active">Actif</option>
                            <option value="repair">En réparation</option>
                            <option value="retired">Retraité</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Assigné à</label>
                        <select name="assigned_to" id="asset-assignee" class="form-control">
                            <option value="">Non assigné</option>
                            <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>"><?= h($emp['first_name'] . ' ' . $emp['last_name']) ?><?= $emp['dept_name'] ? ' (' . h($emp['dept_name']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Localisation</label>
                    <input type="text" name="location" id="asset-location" class="form-control" placeholder="Bureau 3A, Salle serveurs, Site Lyon…">
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="asset-notes" class="form-control" rows="3" placeholder="Informations complémentaires…"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-modal-close>Annuler</button>
            <button class="btn btn-primary" onclick="saveAsset()" id="btn-save-asset">
                <svg><use href="#icon-check"/></svg> Enregistrer
            </button>
        </div>
    </div>
</div>

<!-- MODAL: DETAIL VIEW -->
<div class="modal-backdrop" id="modal-detail" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="detail-hostname">Détail poste</h2>
                <div id="detail-badges" style="display:flex;gap:6px;margin-top:6px"></div>
            </div>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body" id="detail-body"></div>
    </div>
</div>

<!-- MODAL: ENDPOINT -->
<div class="modal-backdrop" id="modal-endpoint" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div>
                <h2 class="modal-title">Enregistrement automatique</h2>
                <div style="font-size:12.5px;color:var(--text-secondary);margin-top:3px">Déployez l'agent sur vos postes pour les enregistrer automatiquement</div>
            </div>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body">

            <!-- API KEY SELECTOR -->
            <?php if (empty($api_keys)): ?>
            <div style="padding:14px;background:var(--warning-dim);border:1px solid rgba(245,166,35,0.25);border-radius:var(--radius-sm);margin-bottom:20px;display:flex;align-items:center;gap:10px">
                <svg class="icon-sm" style="color:var(--warning);flex-shrink:0"><use href="#icon-alert"/></svg>
                <span style="font-size:13.5px;color:var(--warning)">Aucune clé API active. <a href="<?= APP_URL ?>/pages/apikeys.php" style="color:var(--warning);font-weight:700;text-decoration:underline">Créer une clé →</a></span>
            </div>
            <?php else: ?>
            <div class="form-group" style="margin-bottom:20px">
                <label>Clé API à utiliser</label>
                <select id="ep-key-select" class="form-control" onchange="updateEndpoints()">
                    <?php foreach ($api_keys as $k): ?>
                    <option value="<?= h($k['id']) ?>" data-name="<?= h($k['name']) ?>"><?= h($k['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-hint">La clé sera intégrée dans les commandes ci-dessous. <a href="<?= APP_URL ?>/pages/apikeys.php" style="color:var(--accent)">Gérer les clés →</a></div>
            </div>
            <?php endif; ?>

            <!-- TABS -->
            <div class="tabs">
                <button class="tab active" onclick="epTab(this,'ep-linux')">
                    <svg class="icon-xs" style="margin-right:5px"><use href="#icon-linux"/></svg>Linux
                </button>
                <button class="tab" onclick="epTab(this,'ep-mac')">
                    <svg class="icon-xs" style="margin-right:5px"><use href="#icon-apple"/></svg>macOS
                </button>
            </div>

            <!-- LINUX -->
            <div id="ep-linux">
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:10px">Copiez-collez dans un terminal root sur le poste Linux cible :</p>

                <div class="ep-label">1 — Télécharger et installer l'agent</div>
                <div class="ep-block" id="ep-linux-install">
wget -O /usr/local/bin/inventorflow-agent.sh http://<?= h($_SERVER['HTTP_HOST'] ?? 'localhost:8083') ?>/agent/inventorflow-agent.sh
chmod +x /usr/local/bin/inventorflow-agent.sh</div>

                <div class="ep-label" style="margin-top:14px">2 — Configurer la clé API</div>
                <div class="ep-block" id="ep-linux-config">sed -i 's|IF_API_KEY=.*|IF_API_KEY="[SÉLECTIONNEZ UNE CLÉ]"|' /usr/local/bin/inventorflow-agent.sh</div>

                <div class="ep-label" style="margin-top:14px">3 — Lancer + planifier (cron toutes les heures)</div>
                <div class="ep-block">
/usr/local/bin/inventorflow-agent.sh
(crontab -l 2>/dev/null; echo "0 * * * * /usr/local/bin/inventorflow-agent.sh >> /var/log/inventorflow-agent.log 2>&1") | crontab -</div>

                <div style="margin-top:12px;padding:10px 14px;background:var(--success-dim);border:1px solid rgba(34,211,160,0.2);border-radius:var(--radius-sm);font-size:12.5px;color:var(--success)">
                    Le poste apparaît automatiquement dans l'inventaire après le premier lancement.
                </div>
            </div>

            <!-- MACOS -->
            <div id="ep-mac" style="display:none">
                <p style="font-size:13px;color:var(--text-secondary);margin-bottom:10px">Copiez-collez dans Terminal (avec sudo) sur le Mac cible :</p>

                <div class="ep-label">1 — Télécharger et installer l'agent</div>
                <div class="ep-block" id="ep-mac-install">
curl -o /usr/local/bin/inventorflow-agent.sh http://<?= h($_SERVER['HTTP_HOST'] ?? 'localhost:8083') ?>/agent/inventorflow-agent.sh
chmod +x /usr/local/bin/inventorflow-agent.sh</div>

                <div class="ep-label" style="margin-top:14px">2 — Configurer la clé API</div>
                <div class="ep-block" id="ep-mac-config">sed -i '' 's|IF_API_KEY=.*|IF_API_KEY="[SÉLECTIONNEZ UNE CLÉ]"|' /usr/local/bin/inventorflow-agent.sh</div>

                <div class="ep-label" style="margin-top:14px">3 — Lancer + planifier (LaunchDaemon toutes les heures)</div>
                <div class="ep-block">
sudo /usr/local/bin/inventorflow-agent.sh
sudo tee /Library/LaunchDaemons/com.inventorflow.agent.plist &lt;&lt;'EOF'
&lt;?xml version="1.0" encoding="UTF-8"?&gt;
&lt;!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd"&gt;
&lt;plist version="1.0"&gt;
&lt;dict&gt;
    &lt;key&gt;Label&lt;/key&gt;&lt;string&gt;com.inventorflow.agent&lt;/string&gt;
    &lt;key&gt;ProgramArguments&lt;/key&gt;
    &lt;array&gt;&lt;string&gt;/usr/local/bin/inventorflow-agent.sh&lt;/string&gt;&lt;/array&gt;
    &lt;key&gt;StartInterval&lt;/key&gt;&lt;integer&gt;3600&lt;/integer&gt;
    &lt;key&gt;RunAtLoad&lt;/key&gt;&lt;true/&gt;
&lt;/dict&gt;
&lt;/plist&gt;
EOF
sudo launchctl load /Library/LaunchDaemons/com.inventorflow.agent.plist</div>

                <div style="margin-top:12px;padding:10px 14px;background:var(--os-mac-dim);border:1px solid rgba(167,139,250,0.2);border-radius:var(--radius-sm);font-size:12.5px;color:var(--os-mac)">
                    Sur macOS, <code>system_profiler</code> est utilisé — aucune dépendance requise.
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.ep-label { font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:6px; }
.ep-block {
    background:var(--bg-base);
    border:1px solid var(--border);
    border-radius:var(--radius-sm);
    padding:12px 14px;
    font-family:'SF Mono','Consolas','Fira Code',monospace;
    font-size:12.5px;
    color:var(--text-primary);
    white-space:pre;
    overflow-x:auto;
    cursor:pointer;
    transition:border-color var(--transition);
    position:relative;
}
.ep-block:hover { border-color:var(--accent); }
.ep-block:hover::after {
    content:'Copier';
    position:absolute;
    top:8px;right:10px;
    font-size:11px;font-family:inherit;
    color:var(--accent);font-weight:600;
    letter-spacing:.04em;
}
</style>

<?php
$assets_json = json_encode(array_map(function($a) {
    return array_map(fn($v) => $v === null ? '' : $v, $a);
}, $assets), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<script>
const ASSETS = <?= $assets_json ?>;
const APP_URL = '<?= APP_URL ?>';

function getAsset(id) {
    return ASSETS.find(a => a.id == id);
}

// Suggestions
function onOsOrDeptChange() {
    const os = document.getElementById('asset-os').value;
    document.getElementById('btn-suggest').disabled = !os;
    document.getElementById('suggestions-wrap').style.display = 'none';
}

async function loadSuggestions() {
    const os     = document.getElementById('asset-os').value;
    const deptId = document.getElementById('asset-dept').value;
    if (!os) return;

    const btn = document.getElementById('btn-suggest');
    btn.disabled = true;
    btn.style.opacity = '.5';

    const wrap = document.getElementById('suggestions-wrap');
    const list = document.getElementById('suggestions-list');
    list.textContent = '';
    wrap.style.display = 'block';

    // Loading chips
    for (let i = 0; i < 5; i++) {
        const ph = document.createElement('div');
        ph.style.cssText = 'height:30px;width:130px;background:var(--bg-elevated);border-radius:20px;animation:pulse 1.2s ease-in-out infinite';
        ph.style.animationDelay = (i * 0.1) + 's';
        list.appendChild(ph);
    }

    try {
        const res = await fetch(`${APP_URL}/api/naming.php?os=${encodeURIComponent(os)}&dept_id=${encodeURIComponent(deptId)}&count=6`);
        const data = await res.json();
        list.textContent = '';

        if (data.data?.suggestions?.length) {
            data.data.suggestions.forEach(name => {
                const chip = document.createElement('button');
                chip.type = 'button';
                chip.style.cssText = `
                    padding:5px 14px;border-radius:20px;font-family:monospace;font-size:13px;
                    font-weight:700;background:var(--bg-elevated);border:1px solid var(--border);
                    color:var(--accent);cursor:pointer;letter-spacing:.04em;
                    transition:all 150ms ease;`;
                chip.textContent = name;
                chip.addEventListener('mouseenter', () => { chip.style.background = 'var(--accent-dim)'; chip.style.borderColor = 'var(--accent)'; });
                chip.addEventListener('mouseleave', () => {
                    if (document.getElementById('asset-hostname').value !== name) {
                        chip.style.background = 'var(--bg-elevated)'; chip.style.borderColor = 'var(--border)';
                    }
                });
                chip.addEventListener('click', () => {
                    document.getElementById('asset-hostname').value = name;
                    list.querySelectorAll('button').forEach(c => {
                        c.style.background = 'var(--bg-elevated)'; c.style.borderColor = 'var(--border)'; c.style.color = 'var(--accent)';
                    });
                    chip.style.background = 'var(--accent)'; chip.style.borderColor = 'var(--accent)'; chip.style.color = '#fff';
                    toast('Hostname sélectionné : ' + name, 'success');
                });
                list.appendChild(chip);
            });
        } else {
            const msg = document.createElement('span');
            msg.style.cssText = 'font-size:13px;color:var(--text-muted)';
            msg.textContent = 'Aucun modèle configuré — entrez un nom manuellement.';
            list.appendChild(msg);
        }
    } catch(e) {
        list.textContent = '';
    }
    btn.disabled = false;
    btn.style.opacity = '1';
}

async function saveAsset() {
    const form = document.getElementById('form-asset');
    const btn = document.getElementById('btn-save-asset');
    const data = Object.fromEntries(new FormData(form));

    btn.disabled = true;
    btn.textContent = 'Enregistrement…';

    try {
        const res = await api(`${APP_URL}/api/assets.php`, {
            method: data.id ? 'PUT' : 'POST',
            body: data,
        });
        toast(data.id ? 'Poste mis à jour' : 'Poste ajouté avec succès', 'success');
        Modal.close('modal-asset');
        setTimeout(() => location.reload(), 800);
    } catch(e) {
        btn.disabled = false;
        resetSaveBtn(btn);
    }
}

function resetSaveBtn(btn) {
    const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttributeNS('http://www.w3.org/1999/xlink', 'href', '#icon-check');
    icon.appendChild(use);
    btn.textContent = '';
    btn.appendChild(icon);
    btn.appendChild(document.createTextNode(' Enregistrer'));
}

function resetForm() {
    document.getElementById('form-asset').reset();
    document.getElementById('asset-id').value = '';
    document.getElementById('suggestions-wrap').style.display = 'none';
    document.getElementById('btn-suggest').disabled = true;
    document.getElementById('modal-asset-title').textContent = 'Nouveau poste';
    const btn = document.getElementById('btn-save-asset');
    btn.disabled = false;
    resetSaveBtn(btn);
}

document.getElementById('modal-asset').addEventListener('click', e => {
    if (e.target.id === 'modal-asset') resetForm();
});
document.querySelector('[data-modal-close]')?.addEventListener('click', resetForm);

function editAsset(id) {
    const a = getAsset(id);
    if (!a) return;
    resetForm();
    document.getElementById('modal-asset-title').textContent = 'Modifier : ' + a.hostname;
    const fields = {
        'asset-id': 'id', 'asset-os': 'os_type', 'asset-hostname': 'hostname',
        'asset-brand': 'brand', 'asset-model': 'model', 'asset-cpu': 'cpu',
        'asset-ram': 'ram_gb', 'asset-storage': 'storage_gb', 'asset-storage-type': 'storage_type',
        'asset-os-version': 'os_version', 'asset-ip': 'ip_address', 'asset-mac': 'mac_address',
        'asset-serial': 'serial_number', 'asset-tag': 'asset_tag', 'asset-purchase': 'purchase_date',
        'asset-warranty': 'warranty_until', 'asset-status': 'status', 'asset-assignee': 'assigned_to',
        'asset-dept': 'department_id', 'asset-location': 'location', 'asset-notes': 'notes',
    };
    Object.entries(fields).forEach(([elId, key]) => {
        const el = document.getElementById(elId);
        if (el) el.value = a[key] || '';
    });
    Modal.open('modal-asset');
}

function viewAsset(id) {
    const a = getAsset(id);
    if (!a) return;

    document.getElementById('detail-hostname').textContent = a.hostname;

    const osBadges = { MAC: 'badge-mac', WIN: 'badge-win', LIN: 'badge-lin' };
    const statusBadges = { active: 'badge-active', stock: 'badge-stock', repair: 'badge-repair', retired: 'badge-retired' };
    const statusLabels = { active: 'Actif', stock: 'En stock', repair: 'En réparation', retired: 'Retraité' };

    const badgesEl = document.getElementById('detail-badges');
    badgesEl.innerHTML = '';
    const osBadge = document.createElement('span');
    osBadge.className = `badge ${osBadges[a.os_type] || ''}`;
    osBadge.textContent = a.os_type;
    const stBadge = document.createElement('span');
    stBadge.className = `badge ${statusBadges[a.status] || ''}`;
    stBadge.textContent = statusLabels[a.status] || a.status;
    badgesEl.appendChild(osBadge);
    badgesEl.appendChild(stBadge);

    const items = [
        ['Marque / Modèle', [a.brand, a.model].filter(Boolean).join(' ') || '—'],
        ['Processeur', a.cpu || '—'],
        ['RAM', a.ram_gb ? a.ram_gb + ' Go' : '—'],
        ['Stockage', a.storage_gb ? a.storage_gb + ' Go ' + (a.storage_type || '') : '—'],
        ['Version OS', a.os_version || '—'],
        ['Adresse IP', a.ip_address || '—'],
        ['Adresse MAC', a.mac_address || '—'],
        ['N° Série', a.serial_number || '—'],
        ['N° Inventaire', a.asset_tag || '—'],
        ['Assigné à', a.assigned_name || 'Non assigné'],
        ['Département', a.dept_name || '—'],
        ['Localisation', a.location || '—'],
        ['Date achat', a.purchase_date ? new Date(a.purchase_date).toLocaleDateString('fr-FR') : '—'],
        ['Fin garantie', a.warranty_until ? new Date(a.warranty_until).toLocaleDateString('fr-FR') : '—'],
    ];

    const grid = document.createElement('div');
    grid.className = 'detail-grid';
    items.forEach(([label, value]) => {
        const item = document.createElement('div');
        item.className = 'detail-item';
        const l = document.createElement('div');
        l.className = 'detail-label';
        l.textContent = label;
        const v = document.createElement('div');
        v.className = 'detail-value';
        v.textContent = value;
        item.appendChild(l);
        item.appendChild(v);
        grid.appendChild(item);
    });

    const body = document.getElementById('detail-body');
    body.innerHTML = '';
    body.appendChild(grid);

    if (a.notes) {
        const notesEl = document.createElement('div');
        notesEl.style.cssText = 'padding:16px;background:var(--bg-elevated);border-radius:var(--radius-sm);margin-top:16px;font-size:13.5px;color:var(--text-secondary)';
        notesEl.textContent = a.notes;
        body.appendChild(notesEl);
    }

    Modal.open('modal-detail');
}

async function deleteAsset(id, hostname) {
    if (!confirm(`Supprimer le poste "${hostname}" ? Cette action est irréversible.`)) return;
    try {
        await api(`${APP_URL}/api/assets.php`, {
            method: 'DELETE',
            body: { id },
        });
        toast('Poste supprimé', 'success');
        setTimeout(() => location.reload(), 600);
    } catch(e) {}
}

// ── ENDPOINT MODAL ────────────────────────────────────────
function epTab(btn, tabId) {
    document.querySelectorAll('#modal-endpoint .tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    ['ep-linux','ep-mac'].forEach(id => {
        document.getElementById(id).style.display = id === tabId ? 'block' : 'none';
    });
}

function updateEndpoints() {
    const sel = document.getElementById('ep-key-select');
    if (!sel) return;
    const keyName = sel.options[sel.selectedIndex]?.dataset.name || '';
    const placeholder = '[SÉLECTIONNEZ UNE CLÉ]';
    const notice = keyName
        ? `⚠ Clé "${keyName}" — ne partagez pas cette commande publiquement`
        : placeholder;

    ['ep-linux-config','ep-mac-config'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = el.textContent.replace(/IF_API_KEY="[^"]*"/, `IF_API_KEY="[RÉCUPÉREZ LA CLÉ DANS CLÉS API]"`);
    });
}

// Copy on click for ep-blocks
document.addEventListener('click', e => {
    const block = e.target.closest('.ep-block');
    if (!block) return;
    navigator.clipboard.writeText(block.textContent.trim()).then(() => toast('Commande copiée', 'success'));
});

function exportCSV() {
    const rows = document.querySelectorAll('#main-table tbody tr:not([style*="display: none"])');
    const headers = ['Hostname', 'OS', 'Marque', 'Modèle', 'Assigné à', 'Département', 'Statut', 'IP', 'N° Série'];
    const csvRows = [headers.join(';')];
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const rowData = [
            row.querySelector('.td-hostname span')?.textContent.trim() || '',
            row.querySelector('.col-os')?.textContent.trim() || '',
            ASSETS.find(a => a.id == row.dataset.id)?.brand || '',
            ASSETS.find(a => a.id == row.dataset.id)?.model || '',
            cells[3]?.textContent.trim() || '',
            cells[4]?.textContent.trim() || '',
            cells[5]?.textContent.trim() || '',
            ASSETS.find(a => a.id == row.dataset.id)?.ip_address || '',
            ASSETS.find(a => a.id == row.dataset.id)?.serial_number || '',
        ];
        csvRows.push(rowData.map(v => `"${String(v).replace(/"/g, '""')}"`).join(';'));
    });
    const blob = new Blob(['﻿' + csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'inventorflow-export.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php render_footer(); ?>
