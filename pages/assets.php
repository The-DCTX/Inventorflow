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

// Coverage: which service categories are active for this client
$coverage_stmt = $pdo->prepare(
    'SELECT DISTINCT s.category FROM billing_subscriptions bs
     JOIN billing_services s ON bs.service_id = s.id
     WHERE bs.client_id = ? AND bs.active = 1'
);
$coverage_stmt->execute([$client_id]);
$covered_cats = array_column($coverage_stmt->fetchAll(), 'category');
$covered_cats_json = json_encode($covered_cats, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// Also get per-asset software licenses count
$lic_count_stmt = $pdo->prepare(
    'SELECT la.asset_id, COUNT(*) as cnt FROM license_assignments la
     JOIN licenses l ON la.license_id = l.id
     WHERE l.client_id = ? GROUP BY la.asset_id'
);
$lic_count_stmt->execute([$client_id]);
$lic_counts = array_column($lic_count_stmt->fetchAll(), 'cnt', 'asset_id');
$lic_counts_json = json_encode($lic_counts, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

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
    <select id="billable-filter" class="filter-select" onchange="filterByBillable()">
        <option value="">Facturation normale</option>
        <option value="0">Hors facturation</option>
        <option value="all">Tous</option>
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
        <button class="btn btn-secondary btn-sm" onclick="openEndpoint()" style="border-color:rgba(157,123,255,0.4);color:var(--purple)">
            <svg><use href="#icon-layers"/></svg> Endpoint
        </button>
        <button class="btn btn-primary" onclick="Modal.open('modal-asset')">
            <svg><use href="#icon-plus"/></svg> Nouveau poste
        </button>
    </div>
</div>

<!-- BARRE SÉLECTION MULTIPLE -->
<div id="bulk-bar" style="display:none;align-items:center;gap:12px;padding:10px 16px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:12px">
    <span id="bulk-count" style="font-size:13px;font-weight:600;color:var(--text-primary)"></span>
    <div style="margin-left:auto;display:flex;gap:8px">
        <button class="btn btn-secondary btn-sm" onclick="clearSelection()">Désélectionner tout</button>
        <button class="btn btn-secondary btn-sm" onclick="bulkAssignLicenses()" style="border-color:rgba(79,126,248,0.4);color:var(--accent)">
            <svg><use href="#icon-key"/></svg> Attribuer licences
        </button>
        <button class="btn btn-danger btn-sm" onclick="deleteSelected()" style="background:var(--danger);color:#fff;border-color:var(--danger)">
            <svg><use href="#icon-trash"/></svg> Supprimer la sélection
        </button>
    </div>
</div>

<!-- Panneau attribution licences groupée (Machine) -->
<div id="bulk-lic-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(5px);-webkit-backdrop-filter:blur(5px);z-index:1100" onclick="closeBulkLicPanel()"></div>
<div id="bulk-lic-panel" style="display:none;position:fixed;top:0;right:0;height:100vh;width:460px;max-width:100vw;background:var(--bg-card);border-left:1px solid var(--border);z-index:1101;flex-direction:column;overflow:hidden">
    <div style="display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border);flex-shrink:0">
        <div style="flex:1;min-width:0">
            <div style="font-size:15px;font-weight:700;color:var(--text-primary)">Attribuer des licences machine</div>
            <div style="font-size:12.5px;color:var(--text-muted);margin-top:2px" id="blp-subtitle"></div>
        </div>
        <button onclick="closeBulkLicPanel()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <div style="padding:12px 20px;background:var(--accent-dim);border-bottom:1px solid var(--border);font-size:12.5px;color:var(--accent);flex-shrink:0" id="blp-info"></div>
    <div style="flex:1;overflow-y:auto" id="blp-list"></div>
    <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;gap:8px;flex-shrink:0">
        <button class="btn btn-secondary" style="flex:1" onclick="closeBulkLicPanel()">Fermer</button>
        <button class="btn btn-primary" style="flex:1" id="blp-apply-btn" onclick="applyBulkLicenses()">
            <svg><use href="#icon-check"/></svg> Appliquer la sélection
        </button>
    </div>
</div>

<!-- TABLE -->
<div class="table-wrapper">
    <table id="main-table">
        <thead>
            <tr>
                <th style="width:36px;cursor:default"><input type="checkbox" id="chk-all" title="Tout sélectionner" style="accent-color:var(--accent);cursor:pointer" onclick="event.stopPropagation()" onchange="toggleAll(this.checked)"></th>
                <th data-sort="hostname">Hostname</th>
                <th data-sort="os">OS</th>
                <th data-sort="model">Modèle</th>
                <th data-sort="assigned">Assigné à</th>
                <th data-sort="dept">Département</th>
                <th data-sort="status">Statut</th>
                <th data-sort="coverage" style="white-space:nowrap">Couverture</th>
                <th data-sort="warranty">Garantie</th>
                <th style="width:100px;cursor:default">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($assets)): ?>
            <tr class="empty-row">
                <td colspan="10">
                    <div class="empty-state" style="padding:48px 24px">
                        <svg style="width:48px;height:48px;color:var(--text-muted);margin:0 auto 16px;display:block"><use href="#icon-monitor"/></svg>
                        <h3 style="font-size:17px;font-weight:700;margin-bottom:8px">Aucun poste enregistré</h3>
                        <p style="color:var(--text-secondary);margin-bottom:24px;max-width:380px;margin-left:auto;margin-right:auto">
                            Ajoutez des postes manuellement ou déployez l'agent sur vos machines pour un enregistrement automatique.
                        </p>
                        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">
                            <button class="btn btn-primary" onclick="Modal.open('modal-asset')">
                                <svg><use href="#icon-plus"/></svg> Ajouter manuellement
                            </button>
                            <button class="btn btn-secondary" onclick="openEndpoint()">
                                <svg><use href="#icon-layers"/></svg> Déployer l'agent
                            </button>
                        </div>
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
            <tr data-id="<?= $a['id'] ?>" onclick="viewAsset(<?= $a['id'] ?>)" style="cursor:pointer">
                <td style="cursor:default" onclick="event.stopPropagation()"><input type="checkbox" class="row-chk" data-id="<?= $a['id'] ?>" data-hostname="<?= h(addslashes($a['hostname'])) ?>" style="accent-color:var(--accent);cursor:pointer" onchange="onRowCheck()"></td>
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
                    <a href="<?= APP_URL ?>/pages/employee-profile.php?id=<?= $a['assigned_to'] ?>" onclick="event.stopPropagation()" style="color:var(--text-primary);text-decoration:none" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-primary)'"><?= h($a['assigned_name']) ?></a>
                    <?php else: ?><span class="text-muted">Non assigné</span><?php endif; ?>
                </td>
                <td data-col="dept" class="col-dept">
                    <?= $a['dept_name'] ? h($a['dept_name']) : '<span class="text-muted">—</span>' ?>
                </td>
                <td data-col="status" class="col-status"><?= status_badge($a['status']) ?>
                    <?php if (!($a['billable'] ?? 1)): ?>
                    <span class="badge" style="background:rgba(100,116,139,.15);color:#64748b;border:1px solid rgba(100,116,139,.3);font-size:10px" title="Exclue des calculs de facturation">Hors fact.</span>
                    <?php endif; ?></td>
                <td data-col="coverage" onclick="event.stopPropagation()">
<div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap">
<?php
$cov_icons = [
    'monitoring'     => ['#38d9f5','wifi',     'Supervision'],
    'security'       => ['#22d3a0','key',       'Sécurité'],
    'backup'         => ['#f5a623','download',  'Sauvegarde'],
    'support'        => ['#9d7bff','users',     'Support'],
    'infrastructure' => ['#4f7ef8','cpu',       'Infra'],
];
$has_any = false;
foreach ($cov_icons as $cat => [$color, $icon, $label]):
    if (!in_array($cat, $covered_cats)) continue;
    $has_any = true;
?>
<span data-tip="<?= h($label) ?>" style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:4px;background:<?= h($color) ?>18;cursor:default" title="<?= h($label) ?>">
    <svg width="11" height="11" style="color:<?= h($color) ?>"><use href="#icon-<?= h($icon) ?>"/></svg>
</span>
<?php endforeach;
$lic_cnt = $lic_counts[$a['id']] ?? 0;
if ($lic_cnt > 0): ?>
<span data-tip="<?= $lic_cnt ?> licence<?= $lic_cnt>1?'s':'' ?> logicielle<?= $lic_cnt>1?'s':'' ?>" style="display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:4px;background:rgba(79,126,248,0.12);cursor:default" title="<?= $lic_cnt ?> licence<?= $lic_cnt>1?'s':'' ?>">
    <svg width="11" height="11" style="color:var(--accent)"><use href="#icon-layers"/></svg>
</span>
<?php endif;
if (!$has_any && !$lic_cnt): ?>
<span style="font-size:11px;color:var(--text-muted)">—</span>
<?php endif; ?>
</div>
</td>
                <td data-col="warranty">
                    <?php if ($a['warranty_until']): ?>
                    <span class="<?= $warranty_class ?>"><?= date('d/m/Y', strtotime($a['warranty_until'])) ?></span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td>
                    <div class="td-actions" onclick="event.stopPropagation()">
                        <button class="btn btn-ghost btn-icon" title="Modifier" onclick="editAsset(<?= $a['id'] ?>)">
                            <svg><use href="#icon-edit"/></svg>
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
                <?= cf_render_inputs('asset') ?>
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

<!-- ASSET 360° PANEL -->
<div class="asset-panel-backdrop" id="ap-backdrop" onclick="closeAssetPanel()"></div>
<div class="asset-panel" id="asset-panel">
    <div class="asset-panel-header">
        <div style="display:flex;align-items:flex-start;justify-content:space-between">
            <div style="min-width:0;flex:1">
                <div class="asset-panel-hostname" id="ap-hostname">—</div>
                <div class="asset-panel-meta" id="ap-meta"></div>
            </div>
            <div style="display:flex;gap:6px;flex-shrink:0;margin-left:10px">
                <button class="btn btn-ghost btn-sm" id="ap-edit-btn" title="Modifier">
                    <svg><use href="#icon-edit"/></svg>
                </button>
                <button class="btn btn-ghost btn-icon" onclick="closeAssetPanel()" title="Fermer">
                    <svg><use href="#icon-x"/></svg>
                </button>
            </div>
        </div>
        <div class="asset-panel-tabs" id="ap-tabs">
            <button class="asset-panel-tab active" onclick="apTab(this,'ap-tab-info')">
                <svg><use href="#icon-cpu"/></svg> Infos
            </button>
            <button class="asset-panel-tab" onclick="apTab(this,'ap-tab-supervision')">
                <svg><use href="#icon-wifi"/></svg> Supervision
            </button>
            <button class="asset-panel-tab" onclick="apTab(this,'ap-tab-services')">
                <svg><use href="#icon-layers"/></svg> Services
            </button>
            <button class="asset-panel-tab" onclick="apTab(this,'ap-tab-licenses');apLoadLicenses()">
                <svg><use href="#icon-key"/></svg> Licences
            </button>
        </div>
    </div>
    <div class="asset-panel-body">
        <!-- TAB: INFOS -->
        <div id="ap-tab-info" class="asset-panel-tab-content active">
            <div class="ap-section">
                <div class="ap-section-title">Matériel</div>
                <div class="ap-grid" id="ap-hw-grid"></div>
            </div>
            <div class="ap-section">
                <div class="ap-section-title">Réseau & Identifiants</div>
                <div class="ap-grid" id="ap-net-grid"></div>
            </div>
            <div id="ap-notes-section" style="display:none">
                <div class="ap-section-title">Notes</div>
                <div id="ap-notes" style="padding:12px;background:var(--bg-elevated);border-radius:var(--radius-sm);font-size:13.5px;color:var(--text-secondary);line-height:1.6"></div>
            </div>
        </div>
        <!-- TAB: SUPERVISION -->
        <div id="ap-tab-supervision" class="asset-panel-tab-content">
            <div id="ap-monitoring-content">
                <div style="text-align:center;padding:40px 0;color:var(--text-muted)">
                    <svg width="32" height="32" style="margin:0 auto 10px;display:block;color:var(--text-muted)"><use href="#icon-wifi"/></svg>
                    <div style="font-size:13px">Chargement des métriques…</div>
                </div>
            </div>
        </div>
        <!-- TAB: SERVICES -->
        <div id="ap-tab-services" class="asset-panel-tab-content">
            <div id="ap-services-content"></div>
        </div>
        <!-- TAB: LICENCES -->
        <div id="ap-tab-licenses" class="asset-panel-tab-content">
            <div style="padding:16px 20px 8px;display:flex;align-items:center;justify-content:space-between">
                <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted)">Licences attribuées</div>
                <button class="btn btn-primary btn-sm" id="ap-assign-lic-btn" onclick="apOpenLicAssign()">
                    <svg><use href="#icon-plus"/></svg> Attribuer
                </button>
            </div>
            <div id="ap-licenses-list" style="padding:0 4px"></div>
        </div>
    </div>
</div>

<!-- MODAL: ENDPOINT -->
<div class="modal-backdrop" id="modal-endpoint" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div>
                <h2 class="modal-title">Deployer l'agent</h2>
                <div style="font-size:12.5px;color:var(--text-secondary);margin-top:3px">Une commande — detection OS automatique, cle integree</div>
            </div>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body" id="ep-body">
            <div style="text-align:center;padding:30px;color:var(--text-muted)">Chargement...</div>
        </div>
    </div>
</div>

<style>
.ep-cmd {
    background:var(--bg-base);border:1px solid var(--border);border-radius:var(--radius-sm);
    padding:14px 16px;font-family:"SF Mono","Consolas","Fira Code",monospace;font-size:13px;
    color:var(--success);word-break:break-all;cursor:pointer;line-height:1.7;position:relative;
    transition:border-color var(--transition),box-shadow var(--transition);
}
.ep-cmd:hover { border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-dim); }
.ep-cmd::after { content:"Cliquer pour copier";position:absolute;top:8px;right:10px;font-size:11px;color:var(--accent);font-weight:600; }
</style>

<?php
// Services de facturation disponibles (pour le dropdown du modal d'asset)
$svc_avail_stmt = $pdo->query('SELECT id, name, category, color, price, unit, billing_period FROM billing_services WHERE active=1 ORDER BY category, price');
$client_services_raw = $svc_avail_stmt->fetchAll();
// Abonnements actifs du client
$sub_stmt = $pdo->prepare('SELECT bs.id, bs.service_id, s.name, s.category, s.color, s.price, s.unit, s.billing_period FROM billing_subscriptions bs JOIN billing_services s ON bs.service_id=s.id WHERE bs.client_id=? AND bs.active=1 ORDER BY s.category');
$sub_stmt->execute([$client_id]);
$client_subs = $sub_stmt->fetchAll();
$client_licenses_json = json_encode(array_map(fn($s)=>array_map(fn($v)=>$v===null?'':$v,$s),$client_services_raw), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$client_subs_json   = json_encode(array_map(fn($s)=>array_map(fn($v)=>$v===null?'':$v,$s),$client_subs), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
// Packs for the coverage panel
$packs_stmt = $pdo->query('SELECT p.*, GROUP_CONCAT(ps.service_id) as svc_ids,
    SUM(s.price) as total_price
    FROM billing_packs p
    LEFT JOIN billing_pack_services ps ON p.id=ps.pack_id
    LEFT JOIN billing_services s ON ps.service_id=s.id AND s.active=1
    GROUP BY p.id ORDER BY p.sort_order');
$packs_data = $packs_stmt ? $packs_stmt->fetchAll() : [];
foreach ($packs_data as &$pk) {
    $pk['service_ids'] = $pk['svc_ids'] ? array_map('intval', explode(',', $pk['svc_ids'])) : [];
}
unset($pk);
$packs_json = json_encode(array_map(fn($p)=>array_map(fn($v)=>$v===null?'':$v,$p),$packs_data), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

$assets_json = json_encode(array_map(function($a) {
    return array_map(fn($v) => $v === null ? '' : $v, $a);
}, $assets), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<script>
const ASSETS = <?= $assets_json ?>;
const APP_URL = '<?= APP_URL ?>';
const SERVER_URL = '<?= h(APP_SERVER_URL) ?>';
const CURRENT_CLIENT_ID = <?= (int)$client_id ?>;
const COVERED_CATS = <?= $covered_cats_json ?>;
const LIC_COUNTS = <?= $lic_counts_json ?>;
const CLIENT_LICENSES = <?= $client_licenses_json ?>;
const CLIENT_SUBS = <?= $client_subs_json ?>;
const BILLING_PACKS = <?= $packs_json ?>;

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
        'asset-warranty': 'warranty_until', 'asset-status': 'status', 'asset-billable': 'billable', 'asset-assignee': 'assigned_to',
        'asset-dept': 'department_id', 'asset-location': 'location', 'asset-notes': 'notes',
    };
    Object.entries(fields).forEach(([elId, key]) => {
        const el = document.getElementById(elId);
        if (el) el.value = a[key] || '';
    });
    cfFill('modal-asset', a.custom_fields || {});
    Modal.open('modal-asset');
}

function viewAsset(id) {
    const a = getAsset(id);
    if (!a) return;
    _currentAssetId = id;

    // Hostname + badges
    document.getElementById('ap-hostname').textContent = a.hostname;
    const metaEl = document.getElementById('ap-meta');
    metaEl.textContent = '';
    const osBadge = document.createElement('span');
    osBadge.className = `badge badge-${{'MAC':'mac','WIN':'win','LIN':'lin'}[a.os_type]||''}`;
    osBadge.textContent = a.os_type;
    const stLabels = {active:'Actif',stock:'En stock',repair:'En réparation',retired:'Retraité'};
    const stBadge = document.createElement('span');
    stBadge.className = `badge badge-${a.status||'stock'}`;
    stBadge.textContent = stLabels[a.status]||a.status;
    metaEl.appendChild(osBadge); metaEl.appendChild(stBadge);
    if (a.ip_address) {
        const ipEl = document.createElement('span');
        ipEl.style.cssText = 'font-size:12px;color:var(--text-muted);font-family:monospace';
        ipEl.textContent = a.ip_address;
        metaEl.appendChild(ipEl);
    }

    // Edit button
    document.getElementById('ap-edit-btn').onclick = () => { closeAssetPanel(); editAsset(id); };

    // Reset tabs
    apTab(document.querySelector('.asset-panel-tab'), 'ap-tab-info');

    // Tab: Infos — hardware
    const hwItems = [
        ['Marque', a.brand||'—'], ['Modèle', a.model||'—'],
        ['Processeur', a.cpu||'—'], ['RAM', a.ram_gb ? a.ram_gb+' Go' : '—'],
        ['Stockage', a.storage_gb ? a.storage_gb+' Go '+(a.storage_type||'') : '—'],
        ['Version OS', a.os_version||'—'],
    ];
    const netItems = [
        ['Adresse IP', a.ip_address||'—'], ['Adresse MAC', a.mac_address||'—'],
        ['N° Série', a.serial_number||'—'], ['N° Inventaire', a.asset_tag||'—'],
        ['Assigné à', a.assigned_name||'Non assigné'], ['Département', a.dept_name||'—'],
        ['Localisation', a.location||'—'],
        ['Date achat', a.purchase_date ? new Date(a.purchase_date).toLocaleDateString('fr-FR') : '—'],
        ['Fin garantie', a.warranty_until ? new Date(a.warranty_until).toLocaleDateString('fr-FR') : '—'],
    ];

    function fillGrid(gridId, items) {
        const g = document.getElementById(gridId);
        g.textContent = '';
        items.forEach(([label, value]) => {
            const item = document.createElement('div');
            item.className = 'ap-item';
            const l = document.createElement('div'); l.className = 'ap-item-label'; l.textContent = label;
            const v = document.createElement('div');
            v.className = 'ap-item-value' + (['Adresse IP','Adresse MAC','N° Série'].includes(label) ? ' mono' : '');
            v.textContent = value;
            item.appendChild(l); item.appendChild(v);
            g.appendChild(item);
        });
    }
    fillGrid('ap-hw-grid', hwItems);
    fillGrid('ap-net-grid', netItems);

    const notesSection = document.getElementById('ap-notes-section');
    if (a.notes) {
        document.getElementById('ap-notes').textContent = a.notes;
        notesSection.style.display = 'block';
    } else {
        notesSection.style.display = 'none';
    }

    // Tab: Supervision — load async
    loadApSupervision(id);

    // Tab: Services
    const svcContent = document.getElementById('ap-services-content');
    svcContent.textContent = '';
    loadAssetLicenses(id, svcContent);

    // Open panel
    document.getElementById('ap-backdrop').classList.add('active');
    const panel = document.getElementById('asset-panel');
    panel.style.display = 'flex';
    requestAnimationFrame(() => { requestAnimationFrame(() => panel.classList.add('open')); });
    document.body.style.overflow = 'hidden';
}

let _currentAssetId = null;

function closeAssetPanel() {
    const panel = document.getElementById('asset-panel');
    panel.classList.remove('open');
    document.getElementById('ap-backdrop').classList.remove('active');
    panel.addEventListener('transitionend', () => { document.body.style.overflow = ''; }, {once:true});
}

function apTab(btn, tabId) {
    document.querySelectorAll('.asset-panel-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.asset-panel-tab-content').forEach(t => t.classList.remove('active'));
    document.getElementById(tabId)?.classList.add('active');
}

async function loadApSupervision(assetId) {
    const el = document.getElementById('ap-monitoring-content');
    el.textContent = '';
      const skWrap = document.createElement('div');
      skWrap.style.padding = '4px 0';
      [
          ['skeleton skeleton-block', ''],
          ['skeleton skeleton-block', ''],
          ['skeleton skeleton-text wide', ''],
          ['skeleton skeleton-text medium', ''],
          ['skeleton skeleton-text short', ''],
      ].forEach(([cls]) => {
          const s = document.createElement('div');
          s.className = cls;
          skWrap.appendChild(s);
      });
      el.appendChild(skWrap);
    try {
        const r = await fetch(`${APP_URL}/api/monitoring.php?asset_id=${assetId}`, {credentials:'same-origin'});
        if (!r.ok) throw new Error('HTTP '+r.status);
        const d = await r.json();
        const snap = d.data;
        if (!snap || !snap.collected_at) {
            el.innerHTML = '<div style="text-align:center;padding:30px;color:var(--text-muted);font-size:13px">Aucune donnée de supervision<br><small>Déployez l\'agent sur ce poste</small></div>';
            return;
        }
        el.innerHTML = '';
        const metrics = [
            ['CPU', snap.cpu_pct != null ? snap.cpu_pct+'%' : '—', snap.cpu_pct],
            ['RAM', snap.ram_used_mb && snap.ram_total_mb ? Math.round(snap.ram_used_mb/1024*10)/10+'/'+Math.round(snap.ram_total_mb/1024*10)/10+' Go' : '—', snap.ram_total_mb>0 ? snap.ram_used_mb/snap.ram_total_mb*100 : null],
            ['Disque', snap.disk_used_gb && snap.disk_total_gb ? snap.disk_used_gb+'/'+snap.disk_total_gb+' Go' : '—', snap.disk_total_gb>0 ? snap.disk_used_gb/snap.disk_total_gb*100 : null],
            ['Load', snap.load_1m != null ? snap.load_1m+' / '+snap.load_5m : '—', null],
            ['Uptime', snap.uptime_seconds ? formatUptime(snap.uptime_seconds) : '—', null],
            ['Processus', snap.process_count || '—', null],
        ];
        const grid = document.createElement('div');
        grid.className = 'ap-grid';
        metrics.forEach(([label, value, pct]) => {
            const item = document.createElement('div');
            item.className = 'ap-item';
            const l = document.createElement('div'); l.className = 'ap-item-label'; l.textContent = label;
            const v = document.createElement('div'); v.className = 'ap-item-value'; v.textContent = value;
            item.appendChild(l); item.appendChild(v);
            if (pct !== null && pct !== undefined) {
                const bar = document.createElement('div');
                bar.className = 'progress';
                bar.style.marginTop = '6px';
                const inner = document.createElement('div');
                inner.className = 'progress-bar ' + (pct>90?'red':pct>70?'amber':'green');
                inner.style.width = Math.min(pct,100)+'%';
                bar.appendChild(inner); item.appendChild(bar);
            }
            grid.appendChild(item);
        });
        el.appendChild(grid);

        // Température
        if (snap.thermal_state || snap.temp_celsius) {
            const tempDiv = document.createElement('div');
            tempDiv.style.cssText = 'margin-top:14px;padding:12px;background:var(--bg-elevated);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:space-between';
            const tl = document.createElement('div');
            tl.style.cssText = 'font-size:12px;color:var(--text-muted);font-weight:600;text-transform:uppercase;letter-spacing:.06em';
            tl.textContent = 'Température';
            const tv = document.createElement('div');
            tv.style.cssText = 'font-size:15px;font-weight:700;color:var(--success)';
            if (snap.thermal_state) {
                const cfg = {nominal:{c:'var(--success)',t:'Nominal'},high:{c:'var(--danger)',t:'Surchauffe'},critical:{c:'var(--warning)',t:'Throttling'}};
                const tc = cfg[snap.thermal_state]||{c:'var(--text-secondary)',t:snap.thermal_state};
                tv.style.color = tc.c; tv.textContent = tc.t;
            } else { tv.textContent = snap.temp_celsius+'°C'; }
            tempDiv.appendChild(tl); tempDiv.appendChild(tv);
            el.appendChild(tempDiv);
        }

        const ts = document.createElement('div');
        ts.style.cssText = 'margin-top:12px;font-size:11.5px;color:var(--text-muted);text-align:right';
        ts.textContent = 'Collecté ' + timeAgo(snap.collected_at);
        el.appendChild(ts);

    const reportBtn = document.createElement('button');
    reportBtn.className = 'btn btn-secondary btn-sm';
    reportBtn.style.cssText = 'width:100%;justify-content:center;margin-top:10px';
    reportBtn.textContent = 'Rapport maintenant';
    reportBtn.addEventListener('click', async () => {
        reportBtn.disabled = true;
        reportBtn.textContent = 'En attente du prochain cycle...';
        try {
            await api(APP_URL+'/api/commands.php', {method:'POST', body:{asset_id:assetId, command:'force_report'}});
            toast('Rapport demande - execute au prochain cycle (< 5 min)', 'success');
            reportBtn.textContent = 'Commande envoyee';
            setTimeout(() => { reportBtn.disabled=false; reportBtn.textContent='Rapport maintenant'; }, 30000);
        } catch(e) { reportBtn.disabled=false; reportBtn.textContent='Rapport maintenant'; }
    });
    el.appendChild(reportBtn);
    } catch(e) {
        el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:20px">Supervision non disponible</div>';
    }
}

function formatUptime(sec) {
    const d=Math.floor(sec/86400), h=Math.floor(sec%86400/3600);
    return d>0 ? d+'j '+h+'h' : h+'h';
}

function timeAgo(dt) {
    const diff = Math.floor((Date.now() - new Date(dt))/1000);
    if (diff<60) return 'à l\'instant';
    if (diff<3600) return Math.floor(diff/60)+'min';
    if (diff<86400) return Math.floor(diff/3600)+'h';
    return Math.floor(diff/86400)+'j';
}

// Keyboard shortcuts
document.addEventListener('keydown', e => {
    // Ignore when typing in inputs
    if (['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) return;

    const panelOpen = document.getElementById('asset-panel')?.classList.contains('open');

    if (e.key === 'Escape') {
        if (panelOpen) closeAssetPanel();
        // Close any open modals
        document.querySelectorAll('.modal-backdrop.active').forEach(m => Modal.close(m.id));
        return;
    }
    // Panel-specific shortcuts
    if (panelOpen && _currentAssetId) {
        if (e.key === 'e' || e.key === 'E') {
            e.preventDefault();
            closeAssetPanel();
            editAsset(_currentAssetId);
        }
        if ((e.key === 'Delete' || e.key === 'Backspace') && e.shiftKey) {
            e.preventDefault();
            const a = getAsset(_currentAssetId);
            if (a) deleteAsset(a.id, a.hostname);
        }
    }
});



async function loadAssetLicenses(assetId, body) {
    const wrap = document.createElement('div');
    wrap.style.cssText = 'margin-top:20px;border-top:1px solid var(--border-subtle);padding-top:16px';
    body.appendChild(wrap);
    renderClientServices(assetId, wrap);
}

// ── Onglet Licences machine ──────────────────────────────────────────────────
async function apLoadLicenses() {
    if (!_currentAssetId) return;
    const list = document.getElementById('ap-licenses-list');
    list.innerHTML = '<div style="padding:20px;color:var(--text-muted);font-size:13px;text-align:center">Chargement…</div>';
    try {
        const r = await fetch(`${APP_URL}/api/assign-licenses.php?available=1&target=asset&entity_id=${_currentAssetId}`, {credentials:'same-origin'});
        const d = await r.json();
        _apRenderLicenses(d.data || []);
    } catch(e) {
        list.innerHTML = '<div style="padding:20px;color:var(--danger);font-size:13px">Erreur</div>';
    }
}

const _CAT_COLORS_AP = {office:'#2563eb',security:'#22d3a0',os:'#9d7bff',productivity:'#f5a623',development:'#38d9f5',design:'#fb923c',erp:'#ff4757',other:'#64748b'};

function _apRenderLicenses(lics) {
    const list = document.getElementById('ap-licenses-list');
    const assigned = lics.filter(l => l.is_assigned);
    const avail    = lics.filter(l => !l.is_assigned);

    if (!assigned.length) {
        list.innerHTML = '<div style="padding:16px 20px;color:var(--text-muted);font-size:13px">Aucune licence machine attribuée.</div>';
    } else {
        list.innerHTML = '';
        assigned.forEach(l => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 20px;border-bottom:1px solid var(--border)';
            row.innerHTML = `
                <div style="width:8px;height:8px;border-radius:50%;background:${_CAT_COLORS_AP[l.category]||'#64748b'};flex-shrink:0"></div>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600">${escapeHtml(l.name)}${l.vendor?' <span style="color:var(--text-muted)">('+escapeHtml(l.vendor)+')</span>':''}</div>
                    <div style="font-size:12px;color:var(--text-muted)">${parseFloat(l.cost_per_seat||0)>0?parseFloat(l.cost_per_seat).toLocaleString('fr-FR',{minimumFractionDigits:2})+'€':'Inclus'}</div>
                </div>
                <span style="font-size:11px;font-weight:700;padding:3px 8px;border-radius:20px;background:rgba(34,211,160,.15);color:var(--success)">Active</span>
                <button onclick="apRevokeLic(${l.assignment_id},${l.id})" style="background:none;border:none;cursor:pointer;color:var(--text-muted);padding:4px;display:flex;align-items:center" title="Révoquer">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>`;
            list.appendChild(row);
        });
    }
}

async function apRevokeLic(assignmentId, licenseId) {
    if (!confirm('Révoquer cette licence ?')) return;
    try {
        await api(APP_URL+'/api/assign-licenses.php', {method:'DELETE', body:{assignment_id:assignmentId, license_id:licenseId}});
        toast('Licence révoquée', 'success');
        apLoadLicenses();
    } catch(e) {}
}

function apOpenLicAssign() {
    if (!_currentAssetId) return;
    const a = getAsset(_currentAssetId);
    openLicAssignPanel('asset', _currentAssetId, a ? a.hostname : 'Poste #'+_currentAssetId);
    // Recharger l'onglet licences à la fermeture du panel
    const orig = window.closeLicAssignPanel;
    window.closeLicAssignPanel = function() { orig(); apLoadLicenses(); window.closeLicAssignPanel = orig; };
}
// ── /Licences machine ────────────────────────────────────────────────────────

const _removedPackIds = new Set(); // packs retirés dans cette session
function renderClientServices(assetId, container) {
    container.textContent = '';

    const CAT_COLORS = {
        monitoring:'#38d9f5', security:'#22d3a0', backup:'#f5a623',
        support:'#9d7bff', infrastructure:'#4f7ef8', other:'#64748b'
    };
    const PERIODS = {monthly:'/ mois', annual:'/ an', one_time:'unique'};
    const UNITS = {per_device:'/poste', per_user:'/util.', flat:'', per_hour:'/h'};

    const packs = (typeof BILLING_PACKS !== 'undefined') ? BILLING_PACKS : [];

    // Packs entièrement actifs
    const activePacks = packs.filter(p =>
        p.service_ids.length > 0 &&
        p.service_ids.every(sid => CLIENT_SUBS.some(s => String(s.service_id||s.id) === String(sid)))
    );
    const packedSvcIds = new Set(activePacks.flatMap(p => p.service_ids.map(String)));
    const looseServices = CLIENT_SUBS.filter(s => !packedSvcIds.has(String(s.service_id||s.id)));

    // ── SECTION: Packs actifs (vignettes) ──────────────────────────────────────
    if (activePacks.length) {
        const hdrP = document.createElement('div');
        hdrP.style.cssText = 'font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px';
        hdrP.textContent = 'Packs actifs';
        container.appendChild(hdrP);

        const packGrid = document.createElement('div');
        packGrid.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px';

        activePacks.forEach(pack => {
            const chip = document.createElement('div');
            chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;padding:5px 10px;background:var(--bg-elevated);border:1px solid '+(pack.color||'var(--accent)')+';border-radius:20px;font-size:12.5px;font-weight:600;transition:all var(--transition)';

            const dot = document.createElement('div');
            dot.style.cssText = 'width:7px;height:7px;border-radius:50%;background:'+(pack.color||'var(--accent)')+';flex-shrink:0';

            const lbl = document.createElement('span');
            lbl.textContent = pack.name;

            const rmBtn = document.createElement('button');
            rmBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:rgba(255,255,255,.1);border:none;cursor:pointer;color:var(--text-muted);font-size:14px;line-height:1;padding:0;margin-left:2px;transition:all var(--transition)';
            rmBtn.textContent = '−';
            rmBtn.title = 'Retirer ' + pack.name;
            rmBtn.addEventListener('mouseenter', () => { rmBtn.style.background='var(--danger)'; rmBtn.style.color='#fff'; });
            rmBtn.addEventListener('mouseleave', () => { rmBtn.style.background='rgba(255,255,255,.1)'; rmBtn.style.color='var(--text-muted)'; });
            rmBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                rmBtn.disabled = true;
                let removed = 0;
                for (const sid of pack.service_ids) {
                    const sub = CLIENT_SUBS.find(s => String(s.service_id||s.id) === String(sid));
                    if (!sub) continue;
                    try {
                        const subId = sub.id || sub.subscription_id;
                        if (subId) {
                            await api(APP_URL+'/api/billing-subscriptions.php', {method:'DELETE', body:{id:subId}});
                            CLIENT_SUBS.splice(CLIENT_SUBS.indexOf(sub), 1);
                            removed++;
                        }
                    } catch(err) {}
                }
                toast(pack.name+' retiré ('+removed+' service'+(removed>1?'s':'')+' supprimé'+(removed>1?'s':'')+')', 'success');
                _removedPackIds.add(String(pack.id));
                renderClientServices(assetId, container);
            });

            chip.appendChild(dot); chip.appendChild(lbl); chip.appendChild(rmBtn);
            packGrid.appendChild(chip);
        });
        container.appendChild(packGrid);
    }

    // ── SECTION: Services individuels ──────────────────────────────────────────
    const hdr1 = document.createElement('div');
    hdr1.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:8px';
    const t1 = document.createElement('div');
    t1.style.cssText = 'font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted)';
    t1.textContent = 'Services';
    const addBtn = document.createElement('button');
    addBtn.className = 'btn btn-ghost btn-sm';
    addBtn.style.cssText = 'font-size:11.5px;color:var(--accent)';
    addBtn.textContent = '+ Service';
    addBtn.addEventListener('click', () => openAddLicense(assetId, container));
    hdr1.appendChild(t1); hdr1.appendChild(addBtn);
    container.appendChild(hdr1);

    if (!CLIENT_SUBS.length) {
        const noCov = document.createElement('div');
        noCov.style.cssText = 'padding:12px;background:var(--warning-dim);border:1px solid rgba(245,166,35,.25);border-radius:var(--radius-sm);margin-bottom:16px;font-size:13px;color:var(--warning);display:flex;align-items:center;gap:8px';
        const warnSvg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        warnSvg.setAttribute('width','16'); warnSvg.setAttribute('height','16');
        const warnUse = document.createElementNS('http://www.w3.org/2000/svg','use');
        warnUse.setAttributeNS('http://www.w3.org/1999/xlink','href','#icon-alert');
        warnSvg.appendChild(warnUse);
        const warnTxt = document.createElement('span');
        warnTxt.textContent = 'Ce client n’a aucun service actif — choisissez un pack ci-dessous';
        noCov.appendChild(warnSvg); noCov.appendChild(warnTxt);
        container.appendChild(noCov);
    } else if (looseServices.length) {
        const svcList = document.createElement('div');
        svcList.style.cssText = 'display:flex;flex-direction:column;gap:5px;margin-bottom:16px';

        looseServices.forEach(s => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:7px 10px;background:var(--bg-elevated);border-radius:var(--radius-sm);border-left:2px solid '+(s.color||CAT_COLORS[s.category]||'var(--accent)');

            const dot = document.createElement('div');
            dot.style.cssText = 'width:6px;height:6px;border-radius:50%;background:'+(s.color||CAT_COLORS[s.category]||'var(--accent)')+';flex-shrink:0';

            const name = document.createElement('div');
            name.style.cssText = 'flex:1;font-size:13px;font-weight:600';
            name.textContent = s.name;

            const price = document.createElement('div');
            price.style.cssText = 'font-size:12px;color:var(--success);font-weight:600;white-space:nowrap;font-variant-numeric:tabular-nums';
            price.textContent = parseFloat(s.price||0).toFixed(2).replace('.',',')+'€'+(UNITS[s.unit]||'')+(PERIODS[s.billing_period]?' '+PERIODS[s.billing_period]:'');

            const delBtn = document.createElement('button');
            delBtn.style.cssText = 'display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:transparent;border:1px solid var(--border);cursor:pointer;color:var(--text-muted);font-size:14px;line-height:1;padding:0;flex-shrink:0;transition:all var(--transition)';
            delBtn.textContent = '−';
            delBtn.title = 'Retirer ce service';
            delBtn.addEventListener('mouseenter', () => { delBtn.style.background='var(--danger)'; delBtn.style.borderColor='var(--danger)'; delBtn.style.color='#fff'; });
            delBtn.addEventListener('mouseleave', () => { delBtn.style.background='transparent'; delBtn.style.borderColor='var(--border)'; delBtn.style.color='var(--text-muted)'; });
            delBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                delBtn.disabled = true;
                try {
                    const subId = s.id || s.subscription_id;
                    await api(APP_URL+'/api/billing-subscriptions.php', {method:'DELETE', body:{id:subId}});
                    CLIENT_SUBS.splice(CLIENT_SUBS.indexOf(s), 1);
                    toast(s.name+' retiré', 'success');
                    renderClientServices(assetId, container);
                } catch(err) { delBtn.disabled = false; }
            });

            row.appendChild(dot); row.appendChild(name); row.appendChild(price); row.appendChild(delBtn);
            svcList.appendChild(row);
        });

        const total = looseServices.reduce((acc,s) => {
            const p = parseFloat(s.price||0);
            return acc + (s.billing_period==='annual'?p/12:s.billing_period==='one_time'?0:p);
        }, 0);
        if (total > 0) {
            const tot = document.createElement('div');
            tot.style.cssText = 'display:flex;justify-content:space-between;padding:6px 10px;border-top:1px solid var(--border-subtle);margin-top:2px';
            const tl = document.createElement('span'); tl.style.cssText='font-size:11.5px;color:var(--text-muted)'; tl.textContent='Total mensuel client';
            const tv = document.createElement('span'); tv.style.cssText='font-size:13px;font-weight:800;color:var(--success)'; tv.textContent=total.toFixed(2).replace('.',',')+'€/mois';
            tot.appendChild(tl); tot.appendChild(tv);
            svcList.appendChild(tot);
        }
        container.appendChild(svcList);
    } else if (activePacks.length) {
        const note = document.createElement('div');
        note.style.cssText = 'font-size:12px;color:var(--text-muted);padding:4px 0 16px';
        note.textContent = 'Tous les services sont inclus dans les packs actifs.';
        container.appendChild(note);
    }

    // ── SECTION: Packs disponibles ────────────────────────────────────────────
    const availPacks = packs.filter(p =>
        !_removedPackIds.has(String(p.id)) &&
        !p.service_ids.every(sid => CLIENT_SUBS.some(s => String(s.service_id||s.id) === String(sid)))
    );
    if (availPacks.length) {
        const hdr2 = document.createElement('div');
        hdr2.style.cssText = 'font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:10px;margin-top:4px';
        hdr2.textContent = 'Packs disponibles';
        container.appendChild(hdr2);

        availPacks.forEach(pack => {
            const card = document.createElement('div');
            card.style.cssText = 'padding:12px 14px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:8px;transition:all var(--transition)';
            card.addEventListener('mouseenter',()=>{card.style.borderColor='var(--accent)';card.style.background='var(--accent-dim)';});
            card.addEventListener('mouseleave',()=>{card.style.borderColor='var(--border)';card.style.background='var(--bg-elevated)';});

            const top = document.createElement('div');
            top.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:6px';

            const nameWrap = document.createElement('div');
            nameWrap.style.cssText = 'display:flex;align-items:center;gap:8px';
            const packDot = document.createElement('div');
            packDot.style.cssText = 'width:8px;height:8px;border-radius:50%;background:'+(pack.color||'var(--accent)');
            const packName = document.createElement('div');
            packName.style.cssText = 'font-size:14px;font-weight:700';
            packName.textContent = pack.name;
            nameWrap.appendChild(packDot); nameWrap.appendChild(packName);

            const applyBtn = document.createElement('button');
            applyBtn.className = 'btn btn-primary btn-sm';
            applyBtn.style.fontSize = '12px';
            applyBtn.textContent = 'Appliquer';
            applyBtn.addEventListener('click', async (e) => {
                e.stopPropagation();
                applyBtn.disabled = true; applyBtn.textContent = '…';
                let applied = 0;
                for (const sid of pack.service_ids) {
                    if (CLIENT_SUBS.some(s => String(s.service_id||s.id) === String(sid))) continue;
                    try {
                        const res = await api(APP_URL+'/api/billing-subscriptions.php', {method:'POST', body:{service_id:sid, client_id:CURRENT_CLIENT_ID}});
                        const svc = CLIENT_LICENSES.find(s=>String(s.id)===String(sid));
                        if (svc) CLIENT_SUBS.push({...svc, service_id:svc.id, id:res?.data?.id||null});
                        applied++;
                    } catch(e) {}
                }
                toast(pack.name+' appliqué ('+applied+' service'+(applied>1?'s':'')+' ajouté'+(applied>1?'s':'')+')', 'success');
                renderClientServices(assetId, container);
            });

            top.appendChild(nameWrap); top.appendChild(applyBtn);

            const desc = document.createElement('div');
            desc.style.cssText = 'font-size:12px;color:var(--text-muted);line-height:1.5';
            desc.textContent = pack.description || '';

            const priceEl = document.createElement('div');
            priceEl.style.cssText = 'font-size:12px;color:var(--text-secondary);margin-top:5px';
            priceEl.textContent = pack.total_price ? parseFloat(pack.total_price).toFixed(2).replace('.',',')+'€/poste/mois estimé' : '';

            card.appendChild(top); card.appendChild(desc);
            if (pack.total_price) card.appendChild(priceEl);
            container.appendChild(card);
        });
    }
}

async function refreshAssetLicenses(assetId) {
    const list = document.getElementById(`lic-list-${assetId}`);
    if (list) renderClientServices(assetId, list);
}

function openAddLicense(assetId, wrap) {
    const existingForm = wrap.querySelector('.add-svc-form');
    if (existingForm) { existingForm.remove(); return; }

    const form = document.createElement('div');
    form.className = 'add-svc-form';
    form.style.cssText = `
        margin-top:10px;padding:12px;
        background:var(--bg-input);border:1px solid var(--border-active);
        border-radius:var(--radius-sm);
        animation:fadeSlideIn 150ms ease;
    `;

    const formTitle = document.createElement('div');
    formTitle.style.cssText = 'font-size:11.5px;font-weight:600;color:var(--text-secondary);margin-bottom:8px';
    formTitle.textContent = 'Activer un service pour ce client';

    const sel = document.createElement('select');
    sel.className = 'form-control';
    sel.style.cssText = 'font-size:13px;margin-bottom:8px';
    const defOpt = document.createElement('option');
    defOpt.value = ''; defOpt.textContent = 'Choisir un service à activer…';
    sel.appendChild(defOpt);

    const assignedIds = new Set(CLIENT_SUBS.map(s => String(s.service_id || s.id)));
    const CAT_ORDER = ['monitoring','security','support','backup','infrastructure','other'];
    const grouped = {};
    CLIENT_LICENSES.forEach(s => {
        if (assignedIds.has(String(s.id))) return;
        if (!grouped[s.category]) grouped[s.category] = [];
        grouped[s.category].push(s);
    });

    const catNames = {monitoring:'Supervision',security:'Sécurité',support:'Support',
        backup:'Sauvegarde',infrastructure:'Infrastructure',other:'Autre'};

    CAT_ORDER.forEach(cat => {
        if (!grouped[cat] || !grouped[cat].length) return;
        const og = document.createElement('optgroup');
        og.label = catNames[cat] || cat;
        grouped[cat].forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name + '  —  ' + parseFloat(s.price).toFixed(2).replace('.',',') + ' €';
            og.appendChild(opt);
        });
        sel.appendChild(og);
    });

    const btns = document.createElement('div');
    btns.style.cssText = 'display:flex;gap:8px';

    const confirmBtn = document.createElement('button');
    confirmBtn.className = 'btn btn-primary btn-sm';
    confirmBtn.style.flex = '1';
    confirmBtn.style.justifyContent = 'center';
    confirmBtn.textContent = 'Activer le service';
    confirmBtn.addEventListener('click', async () => {
        if (!sel.value) { toast('Sélectionnez un service', 'warning'); return; }
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Activation…';
        try {
            await api(`${APP_URL}/api/billing-subscriptions.php`, {
                method: 'POST',
                body: { service_id: sel.value, client_id: CURRENT_CLIENT_ID }
            });
            const svc = CLIENT_LICENSES.find(s => String(s.id) === String(sel.value));
            if (svc) CLIENT_SUBS.push({...svc, service_id: svc.id});
            toast('Service activé', 'success');
            form.remove();
            const list = document.getElementById(`lic-list-${assetId}`);
            if (list) renderClientServices(assetId, list);
        } catch(e) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Activer le service';
        }
    });

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-ghost btn-sm';
    cancelBtn.textContent = 'Annuler';
    cancelBtn.addEventListener('click', () => form.remove());

    btns.appendChild(confirmBtn); btns.appendChild(cancelBtn);
    form.appendChild(formTitle); form.appendChild(sel); form.appendChild(btns);
    wrap.appendChild(form);
    sel.focus();
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

// ── SÉLECTION MULTIPLE (Parc) ───────────────────────────────────────────────
const _blpSelectedLicIds = new Set();

function getCheckedIds() {
    return [...document.querySelectorAll('.row-chk:checked')].map(c => parseInt(c.dataset.id));
}
function onRowCheck() {
    const total   = document.querySelectorAll('.row-chk').length;
    const checked = document.querySelectorAll('.row-chk:checked').length;
    const all = document.getElementById('chk-all');
    if (all) { all.checked = checked === total && total > 0; all.indeterminate = checked > 0 && checked < total; }
    document.getElementById('bulk-bar').style.display = checked > 0 ? 'flex' : 'none';
    document.getElementById('bulk-count').textContent =
        checked + ' poste' + (checked > 1 ? 's' : '') + ' sélectionné' + (checked > 1 ? 's' : '');
}
function toggleAll(checked) {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = checked);
    onRowCheck();
}
function clearSelection() {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
    const all = document.getElementById('chk-all'); if (all) { all.checked = false; all.indeterminate = false; }
    document.getElementById('bulk-bar').style.display = 'none';
}

async function deleteSelected() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    if (!confirm(`Supprimer définitivement ${ids.length} poste${ids.length > 1 ? 's' : ''} ? Cette action est irréversible.`)) return;
    const bar = document.getElementById('bulk-bar');
    bar.innerHTML = '<span style="font-size:13px;color:var(--text-muted)">Suppression en cours…</span>';
    let done = 0, failed = 0;
    for (const id of ids) {
        try { await api(`${APP_URL}/api/assets.php`, { method: 'DELETE', body: { id } }); done++; }
        catch(e) { failed++; }
    }
    toast(failed === 0 ? `${done} poste${done > 1 ? 's' : ''} supprimé${done > 1 ? 's' : ''}` : `${done} supprimé(s), ${failed} échec(s)`, failed === 0 ? 'success' : 'warning');
    setTimeout(() => location.reload(), 700);
}

// ── ATTRIBUTION LICENCES MACHINE GROUPÉE ────────────────────────────────────
const _BLP_CAT = {office:'#2563eb',security:'#22d3a0',os:'#9d7bff',productivity:'#f5a623',development:'#38d9f5',design:'#fb923c',erp:'#ff4757',other:'#64748b'};
const _BLP_CAT_LABELS = {office:'Bureautique',security:'Sécurité',os:'Système',productivity:'Productivité',development:'Développement',design:'Design',erp:'ERP / Compta',other:'Autre'};

async function bulkAssignLicenses() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    _blpSelectedLicIds.clear();
    document.getElementById('blp-subtitle').textContent =
        ids.length + ' poste' + (ids.length > 1 ? 's' : '') + ' sélectionné' + (ids.length > 1 ? 's' : '');
    document.getElementById('blp-info').textContent = 'Les licences machine cochées seront attribuées à tous les postes qui ne les ont pas encore.';
    document.getElementById('blp-list').innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-muted);font-size:13px">Chargement…</div>';
    document.getElementById('blp-apply-btn').disabled = true;
    document.getElementById('bulk-lic-backdrop').style.display = '';
    document.getElementById('bulk-lic-panel').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    try {
        const r = await fetch(`${APP_URL}/api/licenses.php?target=asset`, {credentials: 'same-origin'});
        const d = await r.json();
        const lics = d.data || [];
        const counts = await Promise.all(lics.map(async l => {
            const ra = await fetch(`${APP_URL}/api/license-assignments.php?license_id=${l.id}`, {credentials:'same-origin'});
            const da = await ra.json();
            const existing = (da.data || []).filter(a => ids.includes(parseInt(a.asset_id))).length;
            return { ...l, already: existing };
        }));
        _blpRender(counts, ids);
    } catch(e) {
        document.getElementById('blp-list').innerHTML = '<div style="padding:20px;color:var(--danger);font-size:13px">Erreur de chargement</div>';
    }
}

function _blpRender(lics, ids) {
    const list = document.getElementById('blp-list');
    const total = ids.length;
    if (!lics.length) {
        list.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-muted);font-size:13px">Aucune licence machine configurée.<br><a href="' + APP_URL + '/pages/licenses.php" style="color:var(--accent)">Gérer les licences →</a></div>';
        return;
    }
    const cats = {};
    lics.forEach(l => { (cats[l.category] = cats[l.category] || []).push(l); });
    list.textContent = '';
    Object.entries(cats).forEach(([cat, items]) => {
        const hdr = document.createElement('div');
        hdr.style.cssText = 'padding:10px 20px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);border-top:1px solid var(--border)';
        hdr.textContent = _BLP_CAT_LABELS[cat] || cat;
        list.appendChild(hdr);
        items.forEach(l => {
            const int = n => parseInt(n) || 0;
            const avail    = Math.max(0, int(l.total_seats) - int(l.used_seats));
            const already  = l.already || 0;
            const toAssign = total - already;
            const canAdd   = avail >= toAssign;
            const allHave  = already === total;
            const row = document.createElement('label');
            row.style.cssText = 'display:flex;align-items:center;gap:12px;padding:11px 20px;cursor:pointer;border-bottom:1px solid var(--border)' + (allHave ? ';opacity:.55' : '');
            row.addEventListener('mouseenter', () => row.style.background = 'var(--bg-hover)');
            row.addEventListener('mouseleave', () => row.style.background = '');
            const cb = document.createElement('input');
            cb.type = 'checkbox'; cb.value = l.id; cb.checked = _blpSelectedLicIds.has(l.id);
            cb.disabled = allHave || (!canAdd && toAssign > 0);
            cb.style.accentColor = 'var(--accent)'; cb.style.cursor = cb.disabled ? 'not-allowed' : 'pointer';
            cb.addEventListener('change', () => {
                cb.checked ? _blpSelectedLicIds.add(l.id) : _blpSelectedLicIds.delete(l.id);
                document.getElementById('blp-apply-btn').disabled = _blpSelectedLicIds.size === 0;
            });
            const dot = document.createElement('div');
            dot.style.cssText = `width:9px;height:9px;border-radius:50%;background:${_BLP_CAT[cat]||'#64748b'};flex-shrink:0`;
            const info = document.createElement('div'); info.style.cssText = 'flex:1;min-width:0';
            const name = document.createElement('div'); name.style.cssText = 'font-size:13.5px;font-weight:600;color:var(--text-primary)';
            name.textContent = l.name + (l.vendor ? ' (' + l.vendor + ')' : '');
            const meta = document.createElement('div'); meta.style.cssText = 'font-size:12px;color:var(--text-muted);margin-top:2px';
            meta.textContent = (already > 0 ? already + '/' + total + ' déjà attribuée' + (already > 1 ? 's' : '') + ' · ' : '')
                + avail + ' siège' + (avail > 1 ? 's' : '') + ' disponible' + (avail > 1 ? 's' : '');
            info.appendChild(name); info.appendChild(meta);
            const badge = document.createElement('span');
            badge.style.cssText = 'font-size:11px;font-weight:700;padding:3px 8px;border-radius:20px;flex-shrink:0;white-space:nowrap';
            if (allHave) { badge.style.cssText += ';background:rgba(34,211,160,.12);color:var(--success)'; badge.textContent = 'Tous assignés'; }
            else if (!canAdd) { badge.style.cssText += ';background:rgba(255,71,87,.12);color:var(--danger)'; badge.textContent = 'Sièges insuffisants'; }
            else { badge.style.cssText += ';background:var(--bg-elevated);color:var(--text-muted)'; badge.textContent = toAssign + ' à attribuer'; }
            row.appendChild(cb); row.appendChild(dot); row.appendChild(info); row.appendChild(badge);
            list.appendChild(row);
        });
    });
    document.getElementById('blp-apply-btn').disabled = _blpSelectedLicIds.size === 0;
}

async function applyBulkLicenses() {
    const ids = getCheckedIds();
    const licIds = [..._blpSelectedLicIds];
    if (!ids.length || !licIds.length) return;
    const btn = document.getElementById('blp-apply-btn');
    btn.disabled = true; btn.textContent = 'Attribution en cours…';
    let done = 0, skipped = 0, failed = 0;
    for (const assetId of ids) {
        for (const licId of licIds) {
            try {
                await api(APP_URL + '/api/assign-licenses.php', { method: 'POST', body: { license_id: licId, target: 'asset', entity_id: assetId } });
                done++;
            } catch(e) {
                if (e.message && e.message.includes('Déjà')) skipped++; else failed++;
            }
        }
    }
    toast(done + ' attribution' + (done > 1 ? 's' : '') + ' effectuée' + (done > 1 ? 's' : '')
        + (skipped > 0 ? ', ' + skipped + ' déjà attribuée(s)' : '')
        + (failed > 0 ? ', ' + failed + ' échec(s)' : ''), failed > 0 ? 'warning' : 'success');
    closeBulkLicPanel();
    setTimeout(() => location.reload(), 700);
}

function closeBulkLicPanel() {
    document.getElementById('bulk-lic-backdrop').style.display = 'none';
    document.getElementById('bulk-lic-panel').style.display = 'none';
    document.body.style.overflow = '';
}

// ── ENDPOINT MODAL ──────────────────────────────────────
async function openEndpoint() {
    document.getElementById('ep-body').textContent = 'Chargement...';
    Modal.open('modal-endpoint');

    try {
        const res = await api(APP_URL + '/api/deploy-key.php', {
            method: 'POST',
            body: { client_id: CURRENT_CLIENT_ID }
        });
        renderEndpoint(res.data.plain_key);
    } catch(e) {
        document.getElementById('ep-body').textContent = 'Erreur — verifiez les cles API dans Parametres.';
    }
}

function buildDeployCmd(key, cron, os) {
    const cr = cron ? '&cron=1' : '';
    if (os === 'win') {
        const url = SERVER_URL + '/deploy.php?key=' + encodeURIComponent(key) + '&os=win';
        return 'powershell -ExecutionPolicy Bypass -Command "& {[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-Expression (New-Object Net.WebClient).DownloadString(\'' + url + '\')}"';
    }
    const url = SERVER_URL + '/deploy.php?key=' + encodeURIComponent(key) + cr;
    if (os === 'mac') return "sudo bash -c \"$(curl -fsSL '" + url + "')\"";
    return 'curl -fsSL "' + url + '" | bash';
}

function renderEndpoint(key) {
    const body = document.getElementById('ep-body');
    body.textContent = '';

    // Cron option
    const cronWrap = document.createElement('label');
    cronWrap.style.cssText = 'display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;margin-bottom:16px';
    const cronCb = document.createElement('input');
    cronCb.type = 'checkbox'; cronCb.id = 'ep-cron'; cronCb.checked = true;
    cronCb.style.accentColor = 'var(--accent)';
    const cronTxt = document.createElement('div');
    const ct = document.createElement('div');
    ct.style.cssText = 'font-weight:600;font-size:13.5px';
    ct.textContent = 'Installer le cron automatiquement';
    const cs = document.createElement('div');
    cs.style.cssText = 'font-size:12px;color:var(--text-secondary)';
    cs.textContent = 'Lance l agent toutes les heures';
    cronTxt.appendChild(ct); cronTxt.appendChild(cs);
    cronWrap.appendChild(cronCb); cronWrap.appendChild(cronTxt);
    cronCb.addEventListener('change', () => renderEndpoint(key));
    body.appendChild(cronWrap);

    // Tabs
    const tabBar = document.createElement('div');
    tabBar.className = 'tabs';
    tabBar.style.marginBottom = '16px';

    [['ep-tl','Linux','#icon-linux'],['ep-tm','macOS','#icon-apple'],['ep-tw','Windows','#icon-windows']].forEach((t, i) => {
        const btn = document.createElement('button');
        btn.className = 'tab' + (i===0?' active':'');
        btn.dataset.tab = t[0];
        const svg = document.createElementNS('http://www.w3.org/2000/svg','svg');
        svg.setAttribute('width','14');svg.setAttribute('height','14');svg.style.marginRight='5px';
        const use = document.createElementNS('http://www.w3.org/2000/svg','use');
        use.setAttributeNS('http://www.w3.org/1999/xlink','href',t[2]);
        svg.appendChild(use); btn.appendChild(svg);
        btn.appendChild(document.createTextNode(t[1]));
        btn.addEventListener('click', () => {
            tabBar.querySelectorAll('.tab').forEach(b=>b.classList.remove('active'));
            btn.classList.add('active');
            body.querySelectorAll('.ep-panel').forEach(p=>{p.style.display=p.id===t[0]?'block':'none';});
        });
        tabBar.appendChild(btn);
    });
    body.appendChild(tabBar);

    const cron = document.getElementById('ep-cron').checked;

    [['ep-tl','Linux'],['ep-tm','macOS'],['ep-tw','Windows']].forEach((t, i) => {
        const panel = document.createElement('div');
        panel.id = t[0]; panel.className = 'ep-panel';
        panel.style.display = i===0?'block':'none';

        const desc = document.createElement('p');
        desc.style.cssText = 'font-size:13px;color:var(--text-secondary);margin-bottom:10px';
        desc.textContent = 'Executer sur le poste cible (en root/sudo) :';

        const os_map = {'ep-tl':'linux','ep-tm':'mac','ep-tw':'win'};
        const cmd = buildDeployCmd(key, cron, os_map[t[0]] || 'linux');
        const block = document.createElement('div');
        block.className = 'ep-cmd';
        block.textContent = cmd;
        block.addEventListener('click', () => {
            navigator.clipboard.writeText(cmd).then(() => toast('Commande copiee !', 'success'));
        });

        const hint = document.createElement('div');
        hint.style.cssText = 'margin-top:10px;font-size:12px;color:var(--text-muted)';
        hint.textContent = 'Detection OS automatique, agent installe et poste enregistre en une seule commande.';

        panel.appendChild(desc); panel.appendChild(block); panel.appendChild(hint);
        body.appendChild(panel);
    });
}

// Copy on click for ep-cmd (backup)
document.addEventListener('click', e => {
    const cmd = e.target.closest('.ep-cmd');
    if (!cmd) return;
    navigator.clipboard.writeText(cmd.textContent.trim()).then(() => toast('Commande copiee !', 'success'));
});

function filterByBillable() {
    const val = document.getElementById('billable-filter')?.value;
    document.querySelectorAll('#main-table tbody tr[data-id]').forEach(row => {
        if (val === 'all' || val === '') {
            if (val === '') {
                // default: show all but maybe highlight hors-fact
                row.style.display = '';
            } else {
                row.style.display = '';
            }
        } else {
            const a = getAsset(row.dataset.id);
            const billable = a ? (parseInt(a.billable ?? 1)) : 1;
            row.style.display = (String(billable) === val) ? '' : 'none';
        }
    });
}

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
