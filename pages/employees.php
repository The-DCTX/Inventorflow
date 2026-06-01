<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();
$departments = get_departments($client_id);

$stmt = $pdo->prepare('SELECT e.*,
    d.name as dept_name,
    COUNT(a.id) as asset_count
FROM employees e
LEFT JOIN departments d ON e.department_id = d.id
LEFT JOIN assets a ON a.assigned_to = e.id AND a.status = "active"
WHERE e.client_id = ?
GROUP BY e.id
ORDER BY e.last_name, e.first_name');
$stmt->execute([$client_id]);
$employees = $stmt->fetchAll();

// Licences par employé (une seule requête pour tous)
$lic_map = [];
$lmap_stmt = $pdo->prepare('SELECT la.employee_id, l.id, l.name, l.vendor, l.category
    FROM license_assignments la
    JOIN licenses l ON la.license_id = l.id
    JOIN employees e ON la.employee_id = e.id
    WHERE e.client_id = ? AND la.active = 1 AND la.employee_id IS NOT NULL
    ORDER BY l.category, l.name');
$lmap_stmt->execute([$client_id]);
foreach ($lmap_stmt->fetchAll() as $lr) {
    $lic_map[$lr['employee_id']][] = ['id' => $lr['id'], 'name' => $lr['name'], 'vendor' => $lr['vendor'], 'category' => $lr['category']];
}

// Assets par employé pour le tooltip de survol
$assets_map = [];
$amap_stmt = $pdo->prepare('SELECT assigned_to, id, hostname, os_type, model FROM assets WHERE client_id=? AND assigned_to IS NOT NULL AND status="active" ORDER BY hostname');
$amap_stmt->execute([$client_id]);
foreach ($amap_stmt->fetchAll() as $arow) {
    $assets_map[$arow['assigned_to']][] = ['id' => $arow['id'], 'hostname' => $arow['hostname'], 'os' => $arow['os_type'], 'model' => $arow['model']];
}

// Wizard data
$pdo = db();

// Available/stock assets for assignment
$avail_stmt = $pdo->prepare('SELECT id, hostname, os_type, model, brand, status, department_id,
    assigned_to, billable FROM assets WHERE client_id=? AND status IN ("active","stock") ORDER BY hostname');
$avail_stmt->execute([$client_id]);
$avail_assets = $avail_stmt->fetchAll();
$avail_assets_json = json_encode(array_map(fn($a)=>array_map(fn($v)=>$v===null?'':$v,$a),$avail_assets),
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// Client billing subscriptions (active services)
$subs_stmt = $pdo->prepare('SELECT bs.id as sub_id, bs.service_id, s.name, s.category, s.color,
    s.price, s.unit, s.billing_period FROM billing_subscriptions bs
    JOIN billing_services s ON bs.service_id=s.id WHERE bs.client_id=? AND bs.active=1 ORDER BY s.category');
$subs_stmt->execute([$client_id]);
$client_subs_wiz = $subs_stmt->fetchAll();
$client_subs_wiz_json = json_encode(array_map(fn($s)=>array_map(fn($v)=>$v===null?'':$v,$s),$client_subs_wiz),
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// All available software licenses for this client
$lics_stmt = $pdo->prepare('SELECT id, name, vendor, category, total_seats,
    (SELECT COUNT(*) FROM license_assignments la WHERE la.license_id=l.id) as used_seats
    FROM licenses l WHERE l.client_id=? AND l.active=1 ORDER BY category, name');
$lics_stmt->execute([$client_id]);
$client_lics_wiz = $lics_stmt->fetchAll();
$client_lics_wiz_json = json_encode(array_map(fn($l)=>array_map(fn($v)=>$v===null?'':$v,$l),$client_lics_wiz),
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

render_head('Utilisateurs');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('employees'); ?>
<div class="main-wrapper">
<?php render_topbar('Utilisateurs', count($employees) . ' utilisateurs enregistrés'); ?>
<main class="main-content">

<div class="toolbar">
    <div class="search-box">
        <svg><use href="#icon-search"/></svg>
        <input type="text" id="search-input" class="search-input" placeholder="Rechercher un utilisateur…">
    </div>
    <?php $filter_dept = strtolower(trim($_GET['dept'] ?? '')); ?>
    <select id="dept-filter" class="filter-select">
        <option value="">Tous les depts</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?= h(strtolower($d['name'])) ?>" <?= $filter_dept === strtolower($d['name']) ? 'selected' : '' ?>><?= h($d['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <div style="margin-left:auto;display:flex;gap:8px">
        <button class="btn btn-secondary" onclick="openImportModal()">
            <svg><use href="#icon-upload"/></svg> Importer CSV
        </button>
        <button class="btn btn-primary" onclick="openWizard()">
            <svg><use href="#icon-plus"/></svg> Nouvel utilisateur
        </button>
    </div>
</div>

<!-- Barre d'actions groupées (visible dès qu'une sélection existe) -->
<div id="bulk-bar" style="display:none;align-items:center;gap:12px;padding:10px 16px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:12px">
    <span id="bulk-count" style="font-size:13px;font-weight:600;color:var(--text-primary)"></span>
    <div style="margin-left:auto;display:flex;gap:8px">
        <button class="btn btn-secondary btn-sm" onclick="clearSelection()">Désélectionner tout</button>
        <button class="btn btn-secondary btn-sm" onclick="bulkAssignLicenses()" style="border-color:rgba(79,126,248,0.4);color:var(--accent)">
            <svg><use href="#icon-key"/></svg> Attribuer licences
        </button>
        <button class="btn btn-danger btn-sm" onclick="deleteSelected()">
            <svg><use href="#icon-trash"/></svg> Supprimer la sélection
        </button>
        <button class="btn btn-danger btn-sm" onclick="deleteAll()" style="background:var(--danger);color:#fff;border-color:var(--danger)">
            <svg><use href="#icon-trash"/></svg> Tout supprimer
        </button>
    </div>
</div>

<!-- Panel attribution licences groupée -->
<div id="bulk-lic-backdrop" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100" onclick="closeBulkLicPanel()"></div>
<div id="bulk-lic-panel" style="display:none;position:fixed;top:0;right:0;height:100vh;width:460px;max-width:100vw;background:var(--bg-card);border-left:1px solid var(--border);z-index:1101;flex-direction:column;overflow:hidden">
    <div style="display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--border);flex-shrink:0">
        <div style="flex:1;min-width:0">
            <div style="font-size:15px;font-weight:700;color:var(--text-primary)">Attribuer des licences</div>
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

<div class="table-wrapper">
    <table id="main-table">
        <thead>
            <tr>
                <th style="width:36px;cursor:default"><input type="checkbox" id="chk-all" title="Tout sélectionner" style="accent-color:var(--accent);cursor:pointer" onchange="toggleAll(this.checked)"></th>
                <th data-sort="firstname">Prénom</th>
                <th data-sort="lastname">Nom</th>
                <th data-sort="dept">Département</th>
                <th data-sort="position">Poste</th>
                <th data-sort="email">Email</th>
                <th data-sort="assets">Postes assignés</th>
                <th style="cursor:default">Licences</th>
                <th style="cursor:default">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($employees)): ?>
            <tr class="empty-row"><td colspan="8">
                <div class="empty-state">
                    <svg><use href="#icon-users"/></svg>
                    <h3>Aucun utilisateur</h3>
                    <p>Ajoutez les membres de l'organisation.</p>
                    <button class="btn btn-primary" onclick="openWizard()"><svg><use href="#icon-plus"/></svg> Ajouter</button>
                </div>
            </td></tr>
        <?php else: ?>
        <?php foreach ($employees as $emp): ?>
            <tr data-id="<?= $emp['id'] ?>">
                <td onclick="event.stopPropagation()" style="width:36px;padding:0 8px">
                    <input type="checkbox" class="row-chk" data-id="<?= $emp['id'] ?>" style="accent-color:var(--accent);cursor:pointer" onchange="onRowCheck()">
                </td>
                <td data-col="firstname">
                    <div style="display:flex;align-items:center;gap:10px">
                        <div style="width:32px;height:32px;border-radius:50%;background:var(--accent-dim);border:1px solid rgba(79,126,248,0.3);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;flex-shrink:0">
                            <?= strtoupper(substr($emp['first_name'], 0, 1) . substr($emp['last_name'], 0, 1)) ?>
                        </div>
                        <div>
                            <a href="<?= APP_URL ?>/pages/employee-profile.php?id=<?= $emp['id'] ?>" style="font-weight:600;color:var(--text-primary);text-decoration:none;transition:color var(--transition)" onmouseover="this.style.color='var(--accent)'" onmouseout="this.style.color='var(--text-primary)'"><?= $emp['first_name'] ? h($emp['first_name']) : '<span class="text-muted">—</span>' ?></a>
                            <?php if (!$emp['active']): ?><div class="td-meta" style="color:var(--danger)">Inactif</div><?php endif; ?>
                        </div>
                    </div>
                </td>
                <td data-col="lastname" style="font-weight:600"><?= h($emp['last_name']) ?></td>
                <td data-col="dept" class="col-dept"><?= $emp['dept_name'] ? h($emp['dept_name']) : '<span class="text-muted">—</span>' ?></td>
                <td data-col="position"><?= $emp['position'] ? h($emp['position']) : '<span class="text-muted">—</span>' ?></td>
                <td data-col="email"><?= $emp['email'] ? '<a href="mailto:'.h($emp['email']).'" style="color:var(--accent)">'.h($emp['email']).'</a>' : '<span class="text-muted">—</span>' ?></td>
                <td data-col="assets">
                    <?php if ($emp['asset_count'] > 0):
                        $tip_assets = $assets_map[$emp['id']] ?? [];
                        $tip_json = htmlspecialchars(json_encode($tip_assets), ENT_QUOTES, 'UTF-8');
                    ?>
                    <span class="badge badge-active emp-assets-badge" data-assets="<?= $tip_json ?>" style="cursor:default"><?= $emp['asset_count'] ?> poste<?= $emp['asset_count'] > 1 ? 's' : '' ?></span>
                    <?php else: ?>
                    <span class="text-muted">Aucun</span>
                    <?php endif; ?>
                </td>
                <td data-col="licenses">
                    <?php
                    $emp_lics  = $lic_map[$emp['id']] ?? [];
                    $cat_colors = ['office'=>'#2563eb','security'=>'#22d3a0','os'=>'#9d7bff','productivity'=>'#f5a623','development'=>'#38d9f5','design'=>'#fb923c','erp'=>'#ff4757','other'=>'#64748b'];
                    if ($emp_lics):
                        $lics_json = htmlspecialchars(json_encode($emp_lics), ENT_QUOTES, 'UTF-8');
                        $total     = count($emp_lics);
                        $shown     = array_slice($emp_lics, 0, 5);
                        $extra     = $total - count($shown);
                    ?>
                    <div class="emp-lic-bubbles" data-lics="<?= $lics_json ?>"
                         style="display:flex;align-items:center;gap:2px;cursor:default">
                        <!-- Cercles empilés -->
                        <div style="display:flex;align-items:center">
                        <?php foreach (array_reverse($shown) as $i => $el):
                            $color = $cat_colors[$el['category']] ?? '#64748b';
                        ?>
                        <span style="width:22px;height:22px;border-radius:50%;background:<?= h($color) ?>;border:2px solid var(--bg-card);display:flex;align-items:center;justify-content:center;margin-left:<?= $i > 0 ? '-7px' : '0' ?>;position:relative;z-index:<?= count($shown)-$i ?>;flex-shrink:0"
                              title="<?= h($el['name']) ?>">
                            <span style="width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.35)"></span>
                        </span>
                        <?php endforeach; ?>
                        </div>
                        <!-- Compteur -->
                        <span style="margin-left:6px;font-size:12px;font-weight:600;color:var(--text-primary)"><?= $total ?></span>
                        <?php if ($extra > 0): ?>
                        <span style="font-size:11px;color:var(--text-muted);margin-left:2px">+<?= $extra ?></span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span style="color:var(--border);font-size:13px">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="td-actions">
                        <a href="<?= APP_URL ?>/pages/employee-profile.php?id=<?= $emp['id'] ?>" class="btn btn-ghost btn-icon" title="Voir le profil"><svg><use href="#icon-user"/></svg></a>
                        <button class="btn btn-ghost btn-icon" onclick="editEmp(<?= $emp['id'] ?>)" title="Modifier"><svg><use href="#icon-edit"/></svg></button>
                        <?php if ($emp['active']): ?>
                        <button class="btn btn-ghost btn-icon" onclick="empOpenLicAssign(<?= $emp['id'] ?>, '<?= h(addslashes(trim($emp['first_name'].' '.$emp['last_name']))) ?>')" title="Attribuer des licences" style="color:var(--accent)"><svg><use href="#icon-key"/></svg></button>
                        <button class="btn btn-ghost btn-icon" onclick="quickOffboard(<?= $emp['id'] ?>, '<?= h(addslashes($emp['first_name'] . ' ' . $emp['last_name'])) ?>', <?= (int)$emp['asset_count'] ?>)" title="Offboarding" style="color:var(--warning)"><svg><use href="#icon-log-out"/></svg></button>
                        <?php endif; ?>
                        <button class="btn btn-ghost btn-icon" onclick="deleteEmp(<?= $emp['id'] ?>, '<?= h(addslashes($emp['first_name'] . ' ' . $emp['last_name'])) ?>')" title="Supprimer"><svg style="color:var(--danger)"><use href="#icon-trash"/></svg></button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>


<style>
/* ── Tooltip postes assignés ── */
.emp-assets-tip{position:fixed;z-index:9999;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:0 8px 24px rgba(0,0,0,.45);padding:8px 0;min-width:200px;max-width:280px;pointer-events:none;opacity:0;transition:opacity .12s ease}
.emp-assets-tip.visible{opacity:1}
.emp-tip-row{display:flex;align-items:center;gap:8px;padding:6px 12px;font-size:12.5px}
.emp-tip-row:not(:last-child){border-bottom:1px solid var(--border)}
.emp-tip-os{font-size:10px;font-weight:700;padding:2px 5px;border-radius:3px;flex-shrink:0}
.emp-tip-os.MAC{background:rgba(157,123,255,.15);color:#9d7bff}
.emp-tip-os.WIN{background:rgba(56,217,245,.15);color:#38d9f5}
.emp-tip-os.LIN{background:rgba(255,167,51,.15);color:#ffa733}
.emp-tip-hostname{font-family:monospace;font-weight:600;color:var(--accent);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1}
.emp-tip-model{font-size:11px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
/* ── /Tooltip ── */
.imp-source-card{padding:16px;background:var(--bg-elevated);border:2px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;text-align:center;transition:all var(--transition)}
.imp-source-card:hover{border-color:var(--accent)}
.imp-source-card.active{border-color:var(--accent);background:var(--accent-dim)}
.imp-step-label{font-size:12px;font-weight:600;color:var(--text-muted);white-space:nowrap}
.imp-step.active .wiz-step-circle{background:var(--accent)!important;color:#fff!important;box-shadow:0 0 0 3px var(--accent-glow)}
.imp-step.done .wiz-step-circle{background:var(--success)!important;color:#fff!important}
.imp-step.active .imp-step-label,.imp-step.done .imp-step-label{color:var(--text-primary)}
</style>
<!-- MODAL: IMPORT CSV -->
<div class="modal-backdrop" id="modal-import" style="display:none">
<div class="modal" style="max-width:700px;width:100%">
  <div class="modal-header">
    <h2 class="modal-title">Importer des utilisateurs</h2>
    <button class="btn btn-ghost btn-icon" onclick="closeImportModal()"><svg><use href="#icon-x"/></svg></button>
  </div>
  <div class="modal-body">
    <div id="imp-steps" style="display:flex;align-items:center;margin-bottom:24px">
      <div class="imp-step active" data-step="1"><span class="wiz-step-circle">1</span><span class="imp-step-label">Source</span></div>
      <div class="wiz-connector"></div>
      <div class="imp-step" data-step="2"><span class="wiz-step-circle">2</span><span class="imp-step-label">Fichier</span></div>
      <div class="wiz-connector"></div>
      <div class="imp-step" data-step="3"><span class="wiz-step-circle">3</span><span class="imp-step-label">Apercu</span></div>
      <div class="wiz-connector"></div>
      <div class="imp-step" data-step="4"><span class="wiz-step-circle">4</span><span class="imp-step-label">Resultat</span></div>
    </div>

    <!-- STEP 1 : Source -->
    <div id="imp-step-1" class="imp-step-body">
      <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:16px">Choisissez la source de vos donnees utilisateurs :</p>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px">
        <div class="imp-source-card active" onclick="selectSource('ad')" id="src-ad">
          <div style="font-size:24px;margin-bottom:6px">&#127970;</div>
          <div style="font-weight:700;font-size:14px">Active Directory</div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Export PowerShell, CSV LDAP</div>
        </div>
        <div class="imp-source-card" onclick="selectSource('generic')" id="src-generic">
          <div style="font-size:24px;margin-bottom:6px">&#128196;</div>
          <div style="font-weight:700;font-size:14px">CSV Manuel</div>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:4px">Excel, logiciel RH, liste perso</div>
        </div>
      </div>

      <div id="imp-ad-tuto" style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;margin-bottom:16px">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px">Comment exporter depuis Active Directory</div>
        <div style="font-size:13px;color:var(--text-secondary);line-height:1.9">
          <b>1.</b> Ouvrez PowerShell en tant qu'Administrateur sur le controleur de domaine<br>
          <b>2.</b> Executez la commande :
        </div>
        <div style="position:relative;margin:10px 0">
          <div id="imp-ps-cmd" style="background:var(--bg-base);border:1px solid var(--border);border-radius:6px;padding:10px 80px 10px 14px;font-family:monospace;font-size:11.5px;color:var(--accent);word-break:break-all;line-height:1.6">Get-ADUser -Filter * -Properties GivenName,Surname,EmailAddress,TelephoneNumber,Department,Title,Enabled | Select-Object GivenName,Surname,EmailAddress,TelephoneNumber,Department,Title,Enabled | Export-Csv -Path "C:\users_export.csv" -NoTypeInformation -Encoding UTF8</div>
          <button onclick="copyPsCmd()" style="position:absolute;top:8px;right:8px;background:var(--accent-dim);border:none;border-radius:4px;padding:3px 10px;font-size:11px;color:var(--accent);cursor:pointer;font-weight:600">Copier</button>
        </div>
        <div style="font-size:13px;color:var(--text-secondary);line-height:1.9">
          <b>3.</b> Le fichier <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">C:\users_export.csv</code> est pret a importer<br>
          <b>Astuce :</b> remplacez <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">-Filter *</code> par <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">-Filter {Enabled -eq $true}</code> pour n'exporter que les comptes actifs
        </div>
      </div>

      <div id="imp-generic-tuto" style="display:none;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;margin-bottom:16px">
        <div style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:10px">Format CSV attendu</div>
        <div style="font-size:13px;color:var(--text-secondary);line-height:1.9;margin-bottom:8px">
          Colonnes supportees (ordre libre, noms en francais ou anglais) :<br>
          <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">Prenom</code>&nbsp;
          <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">Nom</code>&nbsp;
          <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">Email</code>&nbsp;
          <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">Telephone</code>&nbsp;
          <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">Departement</code>&nbsp;
          <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">Poste</code>&nbsp;
          <code style="background:var(--bg-base);padding:1px 5px;border-radius:3px">Actif</code>
        </div>
        <div style="font-size:12px;color:var(--text-muted)">Les departements inexistants seront crees automatiquement.</div>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
        <button class="btn btn-ghost btn-sm" onclick="downloadTemplate()">
          <svg><use href="#icon-download"/></svg> Modele CSV
        </button>
        <button class="btn btn-primary" onclick="impGoStep(2)">Suivant <svg><use href="#icon-chevron-right"/></svg></button>
      </div>
    </div>

    <!-- STEP 2 : Upload -->
    <div id="imp-step-2" class="imp-step-body" style="display:none">
      <div id="imp-dropzone" onclick="document.getElementById('imp-file-input').click()" ondragover="impDragOver(event)" ondragleave="impDragLeave(event)" ondrop="impDrop(event)"
           style="border:2px dashed var(--border);border-radius:var(--radius-sm);padding:40px;text-align:center;cursor:pointer;transition:all var(--transition)">
        <svg width="36" height="36" style="color:var(--text-muted);margin:0 auto 10px;display:block"><use href="#icon-upload"/></svg>
        <div id="imp-drop-label" style="font-size:14px;font-weight:600;margin-bottom:4px">Glissez votre CSV ici</div>
        <div style="font-size:12.5px;color:var(--text-muted)">ou cliquez pour parcourir &middot; .csv &middot; UTF-8 ou Windows-1252</div>
      </div>
      <input type="file" id="imp-file-input" accept=".csv,.txt" style="display:none" onchange="impFileSelected(event)">
      <div id="imp-file-info" style="display:none;margin-top:12px;padding:10px 14px;background:var(--bg-elevated);border-radius:var(--radius-sm);align-items:center;gap:10px">
        <svg width="16" height="16" style="color:var(--success);flex-shrink:0"><use href="#icon-check"/></svg>
        <span id="imp-file-name" style="font-size:13.5px;font-weight:600;flex:1"></span>
        <span id="imp-file-size" style="font-size:12px;color:var(--text-muted)"></span>
        <button onclick="clearImpFile()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:16px">&times;</button>
      </div>
      <div style="display:flex;justify-content:space-between;margin-top:20px">
        <button class="btn btn-ghost btn-sm" onclick="impGoStep(1)">&#8592; Retour</button>
        <button id="imp-preview-btn" class="btn btn-primary" onclick="impPreview()" disabled>
          Previsualiser <svg><use href="#icon-chevron-right"/></svg>
        </button>
      </div>
    </div>

    <!-- STEP 3 : Preview -->
    <div id="imp-step-3" class="imp-step-body" style="display:none">
      <div style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius-sm)">
        <table style="width:100%;font-size:12px;border-collapse:collapse">
          <thead style="position:sticky;top:0;background:var(--bg-elevated)">
            <tr>
              <th style="padding:8px 10px;text-align:left;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border)">#</th>
              <th style="padding:8px 10px;text-align:left;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border)">Nom</th>
              <th style="padding:8px 10px;text-align:left;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border)">Email</th>
              <th style="padding:8px 10px;text-align:left;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border)">Dept</th>
              <th style="padding:8px 10px;text-align:left;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border)">Poste</th>
              <th style="padding:8px 10px;text-align:left;color:var(--text-muted);font-weight:600;border-bottom:1px solid var(--border)">Statut</th>
            </tr>
          </thead>
          <tbody id="imp-preview-body"></tbody>
        </table>
      </div>
      <div id="imp-preview-summary" style="margin-top:10px;padding:10px 14px;background:var(--bg-elevated);border-radius:var(--radius-sm);font-size:13px;display:flex;gap:20px;flex-wrap:wrap"></div>
      <div style="display:flex;justify-content:space-between;margin-top:16px">
        <button class="btn btn-ghost btn-sm" onclick="impGoStep(2)">&#8592; Retour</button>
        <button id="imp-confirm-btn" class="btn btn-primary" onclick="impConfirm()">
          <svg><use href="#icon-check"/></svg> Importer <span id="imp-count-badge"></span>
        </button>
      </div>
    </div>

    <!-- STEP 4 : Result -->
    <div id="imp-step-4" class="imp-step-body" style="display:none;text-align:center;padding:20px 0">
      <div id="imp-result-icon" style="font-size:48px;margin-bottom:12px"></div>
      <div id="imp-result-title" style="font-size:18px;font-weight:800;margin-bottom:6px"></div>
      <div id="imp-result-sub" style="font-size:13.5px;color:var(--text-secondary);margin-bottom:20px"></div>
      <div id="imp-result-errors" style="display:none;text-align:left;background:var(--danger-dim);border:1px solid rgba(255,71,87,.2);border-radius:var(--radius-sm);padding:12px;margin-bottom:16px;font-size:12px;color:var(--danger)"></div>
      <button class="btn btn-primary" onclick="Modal.close('modal-import');location.reload()">Fermer et actualiser</button>
    </div>

  </div>
</div>
</div>

</main>
</div>


<!-- ════════════════════════════════════════════════════════
     WIZARD: NOUVEL UTILISATEUR — 4 étapes
     ════════════════════════════════════════════════════════ -->
<div class="modal-backdrop" id="modal-wizard" style="display:none">
<div class="modal" style="max-width:620px">

<!-- Header avec stepper -->
<div class="modal-header" style="flex-direction:column;align-items:stretch;gap:16px;padding-bottom:0">
    <div style="display:flex;align-items:center;justify-content:space-between">
        <h2 class="modal-title" id="wiz-title">Nouvel utilisateur</h2>
        <button class="modal-close" onclick="closeWizard()"><svg><use href="#icon-x"/></svg></button>
    </div>
    <!-- Stepper -->
    <div id="wiz-stepper" style="display:flex;align-items:center;gap:0;padding-bottom:16px">
        <?php
        $steps = [
            ['num'=>1,'label'=>'Identité','icon'=>'users'],
            ['num'=>2,'label'=>'Poste','icon'=>'monitor'],
            ['num'=>3,'label'=>'Services','icon'=>'layers'],
            ['num'=>4,'label'=>'Résumé','icon'=>'check'],
        ];
        foreach ($steps as $i => $step):
        ?>
        <div class="wiz-step" data-step="<?= $step['num'] ?>" id="wiz-step-<?= $step['num'] ?>" style="display:flex;align-items:center;flex:<?= $i < count($steps)-1 ? '1' : '0' ?>">
            <div style="display:flex;flex-direction:column;align-items:center;gap:4px">
                <div class="wiz-step-circle" style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;transition:all 200ms ease">
                    <?= $step['num'] ?>
                </div>
                <span class="wiz-step-label" style="font-size:10.5px;font-weight:600;white-space:nowrap;transition:color 200ms ease"><?= h($step['label']) ?></span>
            </div>
            <?php if ($i < count($steps)-1): ?>
            <div class="wiz-connector" id="wiz-conn-<?= $step['num'] ?>" style="flex:1;height:2px;margin:0 6px;margin-bottom:18px;background:var(--border);transition:background 300ms ease"></div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Body steps -->
<div class="modal-body" id="wiz-body" style="min-height:360px">

    <!-- STEP 1: Identité ──────────────────────── -->
    <div id="wiz-s1" class="wiz-step-content">
        <div style="margin-bottom:20px">
            <div style="font-size:15px;font-weight:700;margin-bottom:4px">Informations de l'utilisateur</div>
            <div style="font-size:13px;color:var(--text-secondary)">Les informations de base du nouvel employé.</div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Prénom <span class="required">*</span></label>
                <input type="text" id="w-first" class="form-control" autocomplete="given-name" placeholder="Jean">
            </div>
            <div class="form-group">
                <label>Nom <span class="required">*</span></label>
                <input type="text" id="w-last" class="form-control" autocomplete="family-name" placeholder="Dupont">
            </div>
        </div>
        <div class="form-group">
            <label>Email professionnel</label>
            <input type="email" id="w-email" class="form-control" autocomplete="email" placeholder="jean.dupont@entreprise.com">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Poste / Fonction</label>
                <input type="text" id="w-position" class="form-control" placeholder="Développeur, Comptable…">
            </div>
            <div class="form-group">
                <label>Département</label>
                <select id="w-dept" class="form-control">
                    <option value="">Sélectionner</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label>Téléphone</label>
            <input type="tel" id="w-phone" class="form-control" autocomplete="tel" placeholder="+33 6 00 00 00 00">
        </div>
    </div>

    <!-- STEP 2: Attribution poste ────────────────── -->
    <div id="wiz-s2" class="wiz-step-content" style="display:none">
        <div style="margin-bottom:16px">
            <div style="font-size:15px;font-weight:700;margin-bottom:4px">Attribution d'un poste</div>
            <div style="font-size:13px;color:var(--text-secondary)">Sélectionnez le poste à attribuer — ou ignorez cette étape si l'utilisateur n'a pas encore de machine.</div>
        </div>
        <div id="wiz-assets-list" style="display:flex;flex-direction:column;gap:8px;max-height:320px;overflow-y:auto"></div>
        <!-- Options sans machine existante -->
        <div style="margin-top:12px;display:flex;flex-direction:column;gap:8px">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px;background:var(--bg-elevated);border-radius:var(--radius-sm);border:1px solid var(--border)" id="wiz-no-asset-label">
                <input type="radio" name="wiz_asset" value="" id="wiz-no-asset" style="accent-color:var(--accent)" onchange="document.getElementById('wiz-new-asset-form').style.display='none'">
                <div>
                    <div style="font-size:13px;font-weight:600">Aucun poste pour l'instant</div>
                    <div style="font-size:12px;color:var(--text-muted)">L'utilisateur est en télétravail ou n'a pas encore de machine.</div>
                </div>
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px;background:var(--bg-elevated);border-radius:var(--radius-sm);border:1px solid var(--border)">
                <input type="radio" name="wiz_asset" value="new" id="wiz-new-asset" style="accent-color:var(--purple)" onchange="document.getElementById('wiz-new-asset-form').style.display='block'">
                <div>
                    <div style="font-size:13px;font-weight:600;color:var(--purple)">+ Créer un poste manuellement</div>
                    <div style="font-size:12px;color:var(--text-muted)">Poste non encore connecté à l'agent — ajout manuel.</div>
                </div>
            </label>
        </div>
        <!-- Formulaire poste non connecté -->
        <div id="wiz-new-asset-form" style="display:none;margin-top:12px;padding:14px;background:var(--bg-elevated);border:1px solid var(--border-active);border-radius:var(--radius-sm)">
            <div style="font-size:12px;font-weight:600;color:var(--text-secondary);margin-bottom:10px">Informations du poste</div>
            <div class="form-row">
                <div class="form-group">
                    <label>Type OS <span class="required">*</span></label>
                    <select id="wna-os" class="form-control">
                        <option value="WIN">Windows</option>
                        <option value="MAC">macOS</option>
                        <option value="LIN">Linux</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Marque / Modèle</label>
                    <input type="text" id="wna-model" class="form-control" placeholder="Dell Latitude, MacBook Pro…">
                </div>
            </div>
            <div class="form-group">
                <label>Hostname (facultatif)</label>
                <input type="text" id="wna-hostname" class="form-control" placeholder="Laissez vide pour génération automatique" style="font-family:monospace">
            </div>
            <div class="form-group" style="margin-bottom:0">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:400;text-transform:none;letter-spacing:0">
                    <input type="checkbox" id="wna-billable" checked style="accent-color:var(--accent);width:16px;height:16px">
                    <div>
                        <div style="font-weight:600">Inclure dans la facturation</div>
                        <div style="font-size:11.5px;color:var(--text-muted)">Décocher = poste En stock, Hors facturation (stock de remplacement, machine test…)</div>
                    </div>
                </label>
            </div>
        </div>
    </div>

    <!-- STEP 3: Services & Licences ──────────────── -->
    <div id="wiz-s3" class="wiz-step-content" style="display:none">
        <div style="margin-bottom:16px">
            <div style="font-size:15px;font-weight:700;margin-bottom:4px">Services & Licences</div>
            <div style="font-size:13px;color:var(--text-secondary)">Pré-rempli selon les abonnements de l'entreprise.</div>
        </div>

        <!-- Services actifs du client (pré-cochés) -->
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px">Services actifs de l'entreprise</div>
        <div id="wiz-services-list" style="display:flex;flex-direction:column;gap:5px;margin-bottom:16px"></div>

        <!-- Licences logicielles -->
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px;margin-top:16px">Licences logicielles disponibles</div>
        <div id="wiz-lics-list" style="display:flex;flex-direction:column;gap:5px"></div>
    </div>

    <!-- STEP 4: Récapitulatif ────────────────────── -->
    <div id="wiz-s4" class="wiz-step-content" style="display:none">
        <div style="margin-bottom:16px">
            <div style="font-size:15px;font-weight:700;margin-bottom:4px">Récapitulatif</div>
            <div style="font-size:13px;color:var(--text-secondary)">Vérifiez les informations avant de confirmer la création.</div>
        </div>
        <div id="wiz-summary"></div>
    </div>

</div><!-- /modal-body -->

<!-- Footer navigation -->
<div class="modal-footer" style="justify-content:space-between">
    <button class="btn btn-ghost" id="wiz-btn-back" onclick="wizBack()" style="display:none">
        <svg><use href="#icon-arrow-down"/></svg> Précédent
    </button>
    <button class="btn btn-secondary" onclick="closeWizard()">Annuler</button>
    <button class="btn btn-primary" id="wiz-btn-next" onclick="wizNext()">
        Suivant <svg><use href="#icon-chevron-right"/></svg>
    </button>
</div>

</div><!-- /modal -->
</div><!-- /backdrop -->

<style>
.wiz-step-content { animation: fadeSlideIn 200ms ease; }
.wiz-step-circle.done  { background:var(--success)!important; color:#fff!important; }
.wiz-step-circle.active { background:var(--accent)!important; color:#fff!important; box-shadow:0 0 0 3px var(--accent-glow); }
.wiz-step-circle.idle  { background:var(--bg-elevated); color:var(--text-muted); border:1px solid var(--border); }
.wiz-step-label.active { color:var(--accent); }
.wiz-step-label.done   { color:var(--success); }
.wiz-step-label.idle   { color:var(--text-muted); }
.wiz-connector.done    { background:var(--success)!important; }
.wiz-asset-card { padding:10px 12px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition); }
.wiz-asset-card:hover { border-color:var(--accent);background:var(--accent-dim); }
.wiz-asset-card.selected { border-color:var(--accent);background:var(--accent-dim); }
.wiz-summary-section { padding:12px 16px;background:var(--bg-elevated);border-radius:var(--radius-sm);margin-bottom:10px;border:1px solid var(--border); }
.wiz-summary-row { display:flex;justify-content:space-between;align-items:center;padding:4px 0; }
.wiz-summary-label { font-size:12.5px;color:var(--text-secondary); }
.wiz-summary-value { font-size:13px;font-weight:600; }
</style>

<!-- MODAL -->
<div class="modal-backdrop" id="modal-emp" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="modal-emp-title">Nouvel utilisateur</h2>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body">
            <form id="form-emp" autocomplete="off">
                <input type="hidden" name="id" id="emp-id">
                <input type="hidden" name="client_id" value="<?= $client_id ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Prénom <span class="required">*</span></label>
                        <input type="text" name="first_name" id="emp-first" class="form-control" required placeholder="Jean">
                    </div>
                    <div class="form-group">
                        <label>Nom <span class="required">*</span></label>
                        <input type="text" name="last_name" id="emp-last" class="form-control" required placeholder="Dupont">
                    </div>
                </div>
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" id="emp-email" class="form-control" placeholder="jean.dupont@entreprise.com">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Téléphone</label>
                        <input type="tel" name="phone" id="emp-phone" class="form-control" placeholder="+33 6 00 00 00 00">
                    </div>
                    <div class="form-group">
                        <label>Poste</label>
                        <input type="text" name="position" id="emp-position" class="form-control" placeholder="Développeur, Comptable…">
                    </div>
                </div>
                <div class="form-group">
                    <label>Département</label>
                    <select name="department_id" id="emp-dept" class="form-control">
                        <option value="">Sélectionner</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Statut</label>
                    <select name="active" id="emp-active" class="form-control">
                        <option value="1">Actif</option>
                        <option value="0">Inactif</option>
                    </select>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-modal-close>Annuler</button>
            <button class="btn btn-primary" onclick="saveEmp()"><svg><use href="#icon-check"/></svg> Enregistrer</button>
        </div>
    </div>
</div>

<?php
$emps_json = json_encode(array_map(function($e) {
    return array_map(fn($v) => $v === null ? '' : $v, $e);
}, $employees), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<script>
const EMPLOYEES = <?= $emps_json ?>;
const APP_URL = '<?= APP_URL ?>';

function resetEmpForm() {
    document.getElementById('form-emp').reset();
    document.getElementById('emp-id').value = '';
    document.getElementById('modal-emp-title').textContent = 'Nouvel utilisateur';
}

function editEmp(id) {
    const e = EMPLOYEES.find(x => x.id == id);
    if (!e) return;
    resetEmpForm();
    document.getElementById('modal-emp-title').textContent = 'Modifier : ' + e.first_name + ' ' + e.last_name;
    const fields = {
        'emp-id': 'id', 'emp-first': 'first_name', 'emp-last': 'last_name',
        'emp-email': 'email', 'emp-phone': 'phone', 'emp-position': 'position',
        'emp-dept': 'department_id', 'emp-active': 'active',
    };
    Object.entries(fields).forEach(([elId, key]) => {
        const el = document.getElementById(elId);
        if (el) el.value = e[key] ?? '';
    });
    Modal.open('modal-emp');
}

// ── WIZARD: NOUVEL UTILISATEUR ─────────────────────────────
const WIZ_ASSETS     = <?= $avail_assets_json ?>;
const WIZ_CLIENT_SUBS = <?= $client_subs_wiz_json ?>;
const WIZ_LICS       = <?= $client_lics_wiz_json ?>;
const CAT_COLORS_WIZ = {monitoring:'#38d9f5',security:'#22d3a0',backup:'#f5a623',support:'#9d7bff',infrastructure:'#4f7ef8',other:'#64748b'};
const CAT_LABELS_WIZ = {monitoring:'Supervision',security:'Sécurité',backup:'Sauvegarde',support:'Support',infrastructure:'Infrastructure',other:'Autre'};

let wizStep = 1;
let wizData = {first:'',last:'',email:'',position:'',dept_id:'',phone:'',asset_id:null,services:[],licenses:[]};

function openWizard() {
    wizStep = 1; wizData = {first:'',last:'',email:'',position:'',dept_id:'',phone:'',asset_id:null,services:[],licenses:[]};
    document.getElementById('w-first').value='';document.getElementById('w-last').value='';
    document.getElementById('w-email').value='';document.getElementById('w-position').value='';
    document.getElementById('w-dept').value='';document.getElementById('w-phone').value='';
    wizRenderStep();
    Modal.open('modal-wizard');
}
function closeWizard() { Modal.close('modal-wizard'); }

function wizRenderStep() {
    // Show/hide step content
    for (let i=1;i<=4;i++) {
        const el = document.getElementById('wiz-s'+i);
        if (el) el.style.display = i===wizStep ? 'block' : 'none';
    }
    // Update stepper
    for (let i=1;i<=4;i++) {
        const circle = document.querySelector('#wiz-step-'+i+' .wiz-step-circle');
        const label  = document.querySelector('#wiz-step-'+i+' .wiz-step-label');
        if (!circle) continue;
        circle.className = 'wiz-step-circle ' + (i<wizStep?'done':i===wizStep?'active':'idle');
        if (label) label.className = 'wiz-step-label ' + (i<wizStep?'done':i===wizStep?'active':'idle');
        if (i<4) {
            const conn = document.getElementById('wiz-conn-'+i);
            if (conn) conn.classList.toggle('done', i<wizStep);
        }
    }
    // Back button
    document.getElementById('wiz-btn-back').style.display = wizStep>1?'':'none';
    // Next button label
    const nextBtn = document.getElementById('wiz-btn-next');
    nextBtn.innerHTML = wizStep===4
        ? `<svg><use href="#icon-check"/></svg> Créer l'utilisateur`
        : 'Suivant <svg><use href="#icon-chevron-right"/></svg>';

    if (wizStep===2) wizRenderAssets();
    if (wizStep===3) wizRenderServices();
    if (wizStep===4) wizRenderSummary();
}

function wizNext() {
    if (wizStep===1) {
        if (!document.getElementById('w-first').value.trim() || !document.getElementById('w-last').value.trim()) {
            toast('Prénom et nom obligatoires','warning'); return;
        }
        wizData.first    = document.getElementById('w-first').value.trim();
        wizData.last     = document.getElementById('w-last').value.trim();
        wizData.email    = document.getElementById('w-email').value.trim();
        wizData.position = document.getElementById('w-position').value.trim();
        wizData.dept_id  = document.getElementById('w-dept').value;
        wizData.phone    = document.getElementById('w-phone').value.trim();
        wizStep=2; wizRenderStep(); return;
    }
    if (wizStep===2) {
        const checked = document.querySelector('input[name="wiz_asset"]:checked');
        if (checked && checked.value === 'new') {
            // Validate new asset OS
            if (!document.getElementById('wna-os').value) {
                toast('Sélectionnez un type OS','warning'); return;
            }
            wizData.asset_id = 'new';
            wizData.newAsset = {
                os: document.getElementById('wna-os').value,
                model: document.getElementById('wna-model').value.trim(),
                hostname: document.getElementById('wna-hostname').value.trim(),
                billable: document.getElementById('wna-billable').checked ? 1 : 0,
            };
        } else {
            wizData.asset_id = checked ? (checked.value||null) : null;
            wizData.newAsset = null;
        }
        wizData.services = WIZ_CLIENT_SUBS.map(s=>parseInt(s.sub_id));
        wizData.licenses = [];
        wizStep=3; wizRenderStep(); return;
    }
    if (wizStep===3) {
        // Read checked services
        wizData.services = [...document.querySelectorAll('#wiz-services-list input:checked')].map(cb=>parseInt(cb.value));
        wizData.licenses = [...document.querySelectorAll('#wiz-lics-list input:checked')].map(cb=>parseInt(cb.value));
        wizStep=4; wizRenderStep(); return;
    }
    if (wizStep===4) {
        wizSave();
    }
}

function wizBack() {
    if (wizStep>1) { wizStep--; wizRenderStep(); }
}

function wizRenderAssets() {
    const list = document.getElementById('wiz-assets-list');
    list.textContent = '';

    // Séparer les postes : disponibles vs non-assignables
    const available = WIZ_ASSETS.filter(a => !a.assigned_to && parseInt(a.billable ?? 1) === 1);
    const taken     = WIZ_ASSETS.filter(a =>  a.assigned_to && a.assigned_to !== '');
    const hf        = WIZ_ASSETS.filter(a => !a.assigned_to && parseInt(a.billable ?? 1) === 0);

    function makeCard(a, disabled, reason) {
        const isSelected = !disabled && wizData.asset_id === String(a.id);
        const wrap = document.createElement('div');
        wrap.style.cssText = `
            display:flex;align-items:center;gap:10px;
            padding:10px 12px;border-radius:var(--radius-sm);
            border:1px solid ${disabled ? 'var(--border)' : isSelected ? 'var(--accent)' : 'var(--border)'};
            background:${disabled ? 'var(--bg-base)' : isSelected ? 'var(--accent-dim)' : 'var(--bg-elevated)'};
            opacity:${disabled ? '0.45' : '1'};
            cursor:${disabled ? 'not-allowed' : 'pointer'};
            pointer-events:${disabled ? 'none' : 'auto'};
            transition:all var(--transition);
        `;
        if (!disabled) {
            wrap.addEventListener('mouseenter', () => { if (!isSelected) { wrap.style.borderColor='var(--accent)'; wrap.style.background='var(--accent-dim)'; } });
            wrap.addEventListener('mouseleave', () => { if (wizData.asset_id !== String(a.id)) { wrap.style.borderColor='var(--border)'; wrap.style.background='var(--bg-elevated)'; } });
            wrap.addEventListener('click', () => {
                wizData.asset_id = String(a.id);
                document.querySelector('#wiz-no-asset')?.checked && (document.querySelector('#wiz-no-asset').checked = false);
                document.querySelector('#wiz-new-asset')?.checked && (document.querySelector('#wiz-new-asset').checked = false);
                document.getElementById('wiz-new-asset-form').style.display = 'none';
                list.querySelectorAll('.wiz-a-card').forEach(el => {
                    el.style.borderColor = 'var(--border)'; el.style.background = 'var(--bg-elevated)';
                });
                wrap.style.borderColor = 'var(--accent)'; wrap.style.background = 'var(--accent-dim)';
            });
            wrap.classList.add('wiz-a-card');
        }

        // Radio (hidden visually, for accessibility)
        const rb = document.createElement('input');
        rb.type='radio'; rb.name='wiz_asset'; rb.value=disabled?'':a.id;
        rb.checked = !disabled && isSelected;
        rb.disabled = disabled;
        rb.style.accentColor = 'var(--accent)';
        rb.style.flexShrink = '0';

        const info = document.createElement('div');
        info.style.cssText = 'flex:1;min-width:0';
        const hn = document.createElement('div');
        hn.style.cssText = `font-size:13px;font-weight:700;font-family:monospace;color:${disabled?'var(--text-muted)':'var(--accent)'}`;
        hn.textContent = a.hostname || '(sans hostname)';
        const sub = document.createElement('div');
        sub.style.cssText = 'font-size:12px;color:var(--text-muted)';
        sub.textContent = ([a.brand,a.model].filter(Boolean).join(' ')||'—') + (reason?' · '+reason:'');
        info.appendChild(hn); info.appendChild(sub);

        const badge = document.createElement('span');
        if (disabled && reason === 'Déjà assigné') {
            badge.className = 'badge badge-repair';
            badge.textContent = 'Indisponible';
        } else if (disabled && reason === 'Hors facturation') {
            badge.className = 'badge';
            badge.style.cssText = 'background:rgba(100,116,139,.15);color:#64748b;border:1px solid rgba(100,116,139,.3)';
            badge.textContent = 'Hors fact.';
        } else {
            badge.className = 'badge ' + (a.status==='active'?'badge-active':'badge-stock');
            badge.textContent = a.status==='active'?'Actif':'En stock';
        }
        badge.style.flexShrink = '0';

        wrap.appendChild(rb); wrap.appendChild(info); wrap.appendChild(badge);
        return wrap;
    }

    // Section: postes disponibles
    if (available.length) {
        const lbl = document.createElement('div');
        lbl.style.cssText = 'font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);padding:4px 0 6px';
        lbl.textContent = 'Disponibles (' + available.length + ')';
        list.appendChild(lbl);
        available.forEach(a => list.appendChild(makeCard(a, false, null)));
    }

    // Section: postes hors facturation (non assignables via ce flux)
    if (hf.length) {
        const lbl2 = document.createElement('div');
        lbl2.style.cssText = 'font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);padding:12px 0 6px';
        lbl2.textContent = 'En stock — Hors facturation (' + hf.length + ')';
        const hint = document.createElement('div');
        hint.style.cssText = 'font-size:11.5px;color:var(--text-muted);margin-bottom:6px;font-weight:400;text-transform:none;letter-spacing:0';
        hint.textContent = 'Ces postes sont exclus de la facturation. Pour les assigner, repassez-les en facturation normale dans le Parc.';
        list.appendChild(lbl2); list.appendChild(hint);
        hf.forEach(a => list.appendChild(makeCard(a, true, 'Hors facturation')));
    }

    // Section: postes déjà assignés — cachés par défaut, toggle pour afficher
    if (taken.length) {
        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.style.cssText = 'display:flex;align-items:center;gap:6px;background:none;border:none;font-size:12px;color:var(--text-muted);cursor:pointer;padding:10px 0 4px;font-family:inherit;transition:color var(--transition)';
        toggleBtn.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg> Afficher les postes déjà assignés (' + taken.length + ')';
        toggleBtn.addEventListener('mouseenter', () => toggleBtn.style.color='var(--text-primary)');
        toggleBtn.addEventListener('mouseleave', () => toggleBtn.style.color='var(--text-muted)');

        const takenSection = document.createElement('div');
        takenSection.style.display = 'none';
        takenSection.style.marginTop = '4px';

        let visible = false;
        toggleBtn.addEventListener('click', () => {
            visible = !visible;
            takenSection.style.display = visible ? 'block' : 'none';
            toggleBtn.innerHTML = (visible
                ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg> Masquer les postes déjà assignés'
                : '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg> Afficher les postes déjà assignés (' + taken.length + ')'
            );
        });

        taken.forEach(a => takenSection.appendChild(makeCard(a, true, 'Déjà assigné')));
        list.appendChild(toggleBtn);
        list.appendChild(takenSection);
    }

    if (!available.length && !hf.length && !taken.length) {
        const msg = document.createElement('div');
        msg.style.cssText = 'font-size:13px;color:var(--text-muted);padding:12px 0;text-align:center';
        msg.textContent = 'Aucun poste enregistré — utilisez "+ Créer un poste manuellement" ci-dessous.';
        list.appendChild(msg);
    }
}

function wizRenderServices() {
    const sList = document.getElementById('wiz-services-list');
    const lList = document.getElementById('wiz-lics-list');
    sList.textContent=''; lList.textContent='';

    if (!WIZ_CLIENT_SUBS.length) {
        const msg=document.createElement('div'); msg.style.cssText='font-size:13px;color:var(--text-muted);padding:8px 0'; msg.textContent='Aucun service actif — aucun abonnement configuré pour ce client.'; sList.appendChild(msg);
    } else {
        WIZ_CLIENT_SUBS.forEach(s => {
            const lbl=document.createElement('label');
            lbl.style.cssText='display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--radius-sm);cursor:pointer;transition:background var(--transition)';
            lbl.addEventListener('mouseenter',()=>lbl.style.background='var(--bg-hover)');
            lbl.addEventListener('mouseleave',()=>lbl.style.background='');
            const cb=document.createElement('input'); cb.type='checkbox'; cb.value=s.sub_id;
            cb.checked=wizData.services.includes(parseInt(s.sub_id)); cb.style.accentColor='var(--accent)';
            const dot=document.createElement('div');
            dot.style.cssText=`width:8px;height:8px;border-radius:50%;background:${s.color||CAT_COLORS_WIZ[s.category]||'var(--accent)'};flex-shrink:0`;
            const tx=document.createElement('div'); tx.style.flex='1';
            const n=document.createElement('div'); n.style.cssText='font-size:13px;font-weight:500'; n.textContent=s.name;
            const p=document.createElement('div'); p.style.cssText='font-size:11.5px;color:var(--text-muted)';
            p.textContent=(CAT_LABELS_WIZ[s.category]||s.category)+' · '+parseFloat(s.price||0).toFixed(2).replace('.',',')+'€';
            const chip=document.createElement('span'); chip.style.cssText='font-size:10px;font-weight:600;padding:2px 7px;border-radius:20px;background:var(--success-dim);color:var(--success);flex-shrink:0'; chip.textContent='Inclus';
            tx.appendChild(n); tx.appendChild(p);
            lbl.appendChild(cb); lbl.appendChild(dot); lbl.appendChild(tx); lbl.appendChild(chip);
            sList.appendChild(lbl);
        });
    }

    if (!WIZ_LICS.length) {
        const msg=document.createElement('div'); msg.style.cssText='font-size:13px;color:var(--text-muted);padding:8px 0'; msg.textContent='Aucune licence logicielle configurée.'; lList.appendChild(msg);
    } else {
        WIZ_LICS.forEach(l => {
            const avail=parseInt(l.total_seats||0)-parseInt(l.used_seats||0);
            const lbl=document.createElement('label');
            lbl.style.cssText='display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:var(--radius-sm);cursor:pointer;transition:background var(--transition)';
            if (avail<=0) { lbl.style.opacity='0.5'; lbl.style.cursor='not-allowed'; }
            lbl.addEventListener('mouseenter',()=>{ if(avail>0) lbl.style.background='var(--bg-hover)';});
            lbl.addEventListener('mouseleave',()=>lbl.style.background='');
            const cb=document.createElement('input'); cb.type='checkbox'; cb.value=l.id; cb.disabled=avail<=0;
            cb.checked=wizData.licenses.includes(parseInt(l.id)); cb.style.accentColor='var(--accent)';
            const tx=document.createElement('div'); tx.style.flex='1';
            const n=document.createElement('div'); n.style.cssText='font-size:13px;font-weight:500'; n.textContent=l.name+(l.vendor?' ('+l.vendor+')':'');
            const p=document.createElement('div'); p.style.cssText='font-size:11.5px;color:var(--text-muted)';
            p.textContent=avail>0?avail+' siège'+(avail>1?'s':'')+' disponible'+(avail>1?'s':''):'Aucun siège disponible';
            tx.appendChild(n); tx.appendChild(p);
            lbl.appendChild(cb); lbl.appendChild(tx);
            lList.appendChild(lbl);
        });
    }
}

function wizRenderSummary() {
    const el = document.getElementById('wiz-summary');
    el.textContent='';

    // Utilisateur
    const s1=document.createElement('div'); s1.className='wiz-summary-section';
    const s1t=document.createElement('div'); s1t.style.cssText='font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px'; s1t.textContent='Utilisateur';
    [['Nom',wizData.first+' '+wizData.last],['Email',wizData.email||'—'],['Poste',wizData.position||'—']].forEach(([k,v])=>{
        const r=document.createElement('div'); r.className='wiz-summary-row';
        const l=document.createElement('span'); l.className='wiz-summary-label'; l.textContent=k;
        const vv=document.createElement('span'); vv.className='wiz-summary-value'; vv.textContent=v;
        r.appendChild(l); r.appendChild(vv); s1.appendChild(r);
    });
    s1.insertBefore(s1t,s1.firstChild); el.appendChild(s1);

    // Poste
    const s2=document.createElement('div'); s2.className='wiz-summary-section';
    const s2t=document.createElement('div'); s2t.style.cssText='font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px'; s2t.textContent='Poste attribué';
    const asset=WIZ_ASSETS.find(a=>String(a.id)===String(wizData.asset_id));
    const ar=document.createElement('div'); ar.className='wiz-summary-row';
    const al=document.createElement('span'); al.className='wiz-summary-label'; al.textContent='Machine';
    const av=document.createElement('span'); av.className='wiz-summary-value'; av.style.cssText='font-family:monospace;color:var(--accent)';
    if (wizData.asset_id === 'new' && wizData.newAsset) {
        av.textContent = (wizData.newAsset.hostname || 'Auto-généré') + ' (' + wizData.newAsset.os + ')';
        if (!wizData.newAsset.billable) {
            const hf = document.createElement('span'); hf.style.cssText='font-size:11px;color:#64748b;margin-left:8px'; hf.textContent='Hors facturation';
            av.appendChild(hf);
        }
    } else { av.textContent=asset?asset.hostname:'Aucun poste'; }
    ar.appendChild(al); ar.appendChild(av); s2.appendChild(ar);
    s2.insertBefore(s2t,s2.firstChild); el.appendChild(s2);

    // Services & Licences
    const svcs=WIZ_CLIENT_SUBS.filter(s=>wizData.services.includes(parseInt(s.sub_id)));
    const lics=WIZ_LICS.filter(l=>wizData.licenses.includes(parseInt(l.id)));
    if (svcs.length||lics.length) {
        const s3=document.createElement('div'); s3.className='wiz-summary-section';
        const s3t=document.createElement('div'); s3t.style.cssText='font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-muted);margin-bottom:8px'; s3t.textContent='Services & Licences';
        [...svcs.map(s=>['Service',s.name,'var(--success)']),
         ...lics.map(l=>['Licence',l.name+(l.vendor?' ('+l.vendor+')':''),'var(--accent)'])].forEach(([type,name,col])=>{
            const r=document.createElement('div'); r.className='wiz-summary-row';
            const lt=document.createElement('span'); lt.className='wiz-summary-label'; lt.textContent=type;
            const vt=document.createElement('span'); vt.className='wiz-summary-value'; vt.style.color=col; vt.textContent=name;
            r.appendChild(lt); r.appendChild(vt); s3.appendChild(r);
        });
        s3.insertBefore(s3t,s3.firstChild); el.appendChild(s3);
    }
}

async function wizSave() {
    const btn=document.getElementById('wiz-btn-next');
    btn.disabled=true; btn.textContent='Création…';
    try {
        // 1. Create employee
        const empRes = await api(APP_URL+'/api/employees.php',{method:'POST',body:{
            client_id:'<?= $client_id ?>',
            first_name:wizData.first, last_name:wizData.last, email:wizData.email,
            phone:wizData.phone, position:wizData.position,
            department_id:wizData.dept_id||null, active:1
        }});
        const empId = empRes.data.id;

        // 2. Assign or create asset
        let finalAssetId = wizData.asset_id;
        if (wizData.asset_id === 'new' && wizData.newAsset) {
            // Create new asset manually
            const na = wizData.newAsset;
            const newAssetRes = await api(APP_URL+'/api/assets.php',{method:'POST',body:{
                client_id:'<?= $client_id ?>',
                hostname: na.hostname || '',
                os_type: na.os,
                model: na.model,
                status: na.billable ? 'active' : 'stock',
                billable: na.billable,
                assigned_to: empId,
                dept_code: wizData.dept_id || '',
            }}).catch(()=>null);
            if (newAssetRes) finalAssetId = newAssetRes.data.id;
        } else if (finalAssetId) {
            await api(APP_URL+'/api/assets.php',{method:'PUT',body:{
                id:finalAssetId, assigned_to:empId,
                status:'active', billable:1,
                client_id:'<?= $client_id ?>',
                hostname:(WIZ_ASSETS.find(a=>String(a.id)===String(finalAssetId))||{}).hostname||'',
                os_type:(WIZ_ASSETS.find(a=>String(a.id)===String(finalAssetId))||{}).os_type||'WIN'
            }}).catch(()=>{});
        }

        // 3. Assign software licenses to asset
        if (finalAssetId && finalAssetId !== 'new' && wizData.licenses.length) {
            for (const licId of wizData.licenses) {
                await api(APP_URL+'/api/license-assignments.php',{method:'POST',body:{
                    license_id:licId, asset_id:finalAssetId
                }}).catch(()=>{});
            }
        }

        toast('Utilisateur '+wizData.first+' '+wizData.last+' créé avec succès', 'success');
        closeWizard();
        setTimeout(()=>location.reload(), 800);
    } catch(e) {
        btn.disabled=false;
        btn.innerHTML="<svg><use href='#icon-check'/></svg> Créer l'utilisateur";
    }
}

async function saveEmp() {
    const form = document.getElementById('form-emp');
    const data = Object.fromEntries(new FormData(form));
    try {
        await api(`${APP_URL}/api/employees.php`, { method: data.id ? 'PUT' : 'POST', body: data });
        toast(data.id ? 'Utilisateur mis à jour' : 'Utilisateur ajouté', 'success');
        Modal.close('modal-emp');
        setTimeout(() => location.reload(), 700);
    } catch(e) {}
}

function quickOffboard(id, name, assetCount) {
    const msg = assetCount > 0
        ? `Offboarding de "${name}"

Cet employé a ${assetCount} machine(s) assignée(s).
Redir vers son profil pour le wizard complet.`
        : `Offboarding de ${name}

Aucune machine assignée. Désactiver directement ?`;
    if (assetCount > 0) {
        window.location.href = APP_URL + '/pages/employee-profile.php?id=' + id + '#offboarding';
        return;
    }
    if (!confirm(msg)) return;
    api(APP_URL + '/api/employees.php', { method: 'PUT', body: {
        id,
        first_name: document.querySelector(`tr[data-id=${id}] [data-col=name]`)?.querySelector('a')?.textContent?.split(' ')[0] || '',
        last_name: document.querySelector(`tr[data-id=${id}] [data-col=name]`)?.querySelector('a')?.textContent?.split(' ').slice(1).join(' ') || '',
        active: 0
    }}).then(() => {
        toast(name + ' désactivé(e)', 'success');
        setTimeout(() => location.reload(), 700);
    }).catch(() => {});
}

async function deleteEmp(id, name) {
    if (!confirm('Supprimer "' + name + '" ? Cette action est irréversible.')) return;
    try {
        await api(`${APP_URL}/api/employees.php`, { method: 'DELETE', body: { id } });
        toast('Utilisateur supprimé', 'success');
        setTimeout(() => location.reload(), 600);
    } catch(e) {}
}

// ── Sélection groupée ──
function getCheckedIds() {
    return [...document.querySelectorAll('.row-chk:checked')].map(c => parseInt(c.dataset.id));
}

function onRowCheck() {
    const total   = document.querySelectorAll('.row-chk').length;
    const checked = document.querySelectorAll('.row-chk:checked').length;
    document.getElementById('chk-all').checked       = checked === total && total > 0;
    document.getElementById('chk-all').indeterminate = checked > 0 && checked < total;
    const bar = document.getElementById('bulk-bar');
    bar.style.display = checked > 0 ? 'flex' : 'none';
    document.getElementById('bulk-count').textContent =
        checked + ' utilisateur' + (checked > 1 ? 's' : '') + ' sélectionné' + (checked > 1 ? 's' : '');
}

function toggleAll(checked) {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = checked);
    onRowCheck();
}

function clearSelection() {
    document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
    document.getElementById('chk-all').checked = false;
    document.getElementById('chk-all').indeterminate = false;
    document.getElementById('bulk-bar').style.display = 'none';
}

async function deleteSelected() {
    const ids = getCheckedIds();
    if (!ids.length) return;
    if (!confirm(`Supprimer ${ids.length} utilisateur${ids.length > 1 ? 's' : ''} ? Cette action est irréversible.`)) return;
    await _bulkDelete(ids);
}

async function deleteAll() {
    const total = document.querySelectorAll('.row-chk').length;
    if (!confirm(`Supprimer la totalité des ${total} utilisateurs de ce client ? Cette action est irréversible.`)) return;
    const ids = [...document.querySelectorAll('.row-chk')].map(c => parseInt(c.dataset.id));
    await _bulkDelete(ids);
}

async function _bulkDelete(ids) {
    const bar = document.getElementById('bulk-bar');
    bar.innerHTML = '<span style="font-size:13px;color:var(--text-muted)">Suppression en cours…</span>';

    let done = 0, failed = 0;
    for (const id of ids) {
        try {
            await api(`${APP_URL}/api/employees.php`, { method: 'DELETE', body: { id } });
            done++;
        } catch(e) { failed++; }
    }

    if (failed === 0) {
        toast(`${done} utilisateur${done > 1 ? 's' : ''} supprimé${done > 1 ? 's' : ''}`, 'success');
    } else {
        toast(`${done} supprimé(s), ${failed} échec(s)`, 'warning');
    }
    setTimeout(() => location.reload(), 700);
}
// ── /Sélection groupée ──

// ── IMPORT CSV ───────────────────────────────────────────────
let impSource='ad', impFile=null;

function openImportModal(){
    impSource='ad'; impFile=null;
    selectSource('ad');
    impGoStep(1);
    Modal.open('modal-import');
}
function closeImportModal(){ Modal.close('modal-import'); }

function selectSource(s){
    impSource=s;
    document.getElementById('src-ad').classList.toggle('active',s==='ad');
    document.getElementById('src-generic').classList.toggle('active',s==='generic');
    document.getElementById('imp-ad-tuto').style.display=s==='ad'?'':'none';
    document.getElementById('imp-generic-tuto').style.display=s==='generic'?'':'none';
}

function downloadTemplate(){
    const rows=impSource==='ad'
        ?[['GivenName','Surname','EmailAddress','TelephoneNumber','Department','Title','Enabled'],
          ['Jean','Dupont','jean.dupont@domaine.fr','0601020304','Informatique','Admin systeme','True'],
          ['Marie','Martin','marie.martin@domaine.fr','0601020305','RH','Responsable RH','True']]
        :[['Prenom','Nom','Email','Telephone','Departement','Poste','Actif'],
          ['Jean','Dupont','jean.dupont@entreprise.fr','0601020304','Informatique','Admin systeme','1'],
          ['Marie','Martin','marie.martin@entreprise.fr','0601020305','RH','Responsable RH','1']];
    const csv=rows.map(r=>r.map(v=>'"'+v+'"').join(',')).join('\n');
    const a=document.createElement('a');
    a.href='data:text/csv;charset=utf-8,﻿'+encodeURIComponent(csv);
    a.download='modele_import_'+impSource+'.csv';
    a.click();
}

function copyPsCmd(){
    const t=document.getElementById('imp-ps-cmd').textContent;
    if(navigator.clipboard) navigator.clipboard.writeText(t).then(()=>toast('Commande copiee','success'));
}

function impGoStep(n){
    for(let i=1;i<=4;i++){
        const b=document.getElementById('imp-step-'+i);
        if(b) b.style.display=(i===n)?'':'none';
        const d=document.querySelector('.imp-step[data-step="'+i+'"]');
        if(d){d.classList.toggle('active',i===n);d.classList.toggle('done',i<n);}
        const cc=document.querySelectorAll('#imp-steps .wiz-connector')[i-1];
        if(cc) cc.classList.toggle('done',i<n);
    }
}

function impDragOver(e){
    e.preventDefault();
    const dz=document.getElementById('imp-dropzone');
    dz.style.borderColor='var(--accent)'; dz.style.background='var(--accent-dim)';
}
function impDragLeave(){
    const dz=document.getElementById('imp-dropzone');
    dz.style.borderColor='var(--border)'; dz.style.background='';
}
function impDrop(e){e.preventDefault();impDragLeave();const f=e.dataTransfer.files[0];if(f)setImpFile(f);}
function impFileSelected(e){if(e.target.files[0])setImpFile(e.target.files[0]);}

function setImpFile(f){
    impFile=f;
    document.getElementById('imp-drop-label').textContent=f.name;
    const fi=document.getElementById('imp-file-info');
    fi.style.display='flex';
    document.getElementById('imp-file-name').textContent=f.name;
    document.getElementById('imp-file-size').textContent=(f.size/1024).toFixed(1)+' Ko';
    document.getElementById('imp-preview-btn').disabled=false;
}
function clearImpFile(){
    impFile=null;
    document.getElementById('imp-file-input').value='';
    document.getElementById('imp-drop-label').textContent='Glissez votre CSV ici';
    document.getElementById('imp-file-info').style.display='none';
    document.getElementById('imp-preview-btn').disabled=true;
}

async function impPreview(){
    if(!impFile) return;
    const btn=document.getElementById('imp-preview-btn');
    btn.disabled=true; btn.textContent='Analyse...';
    try{
        const fd=new FormData();
        fd.append('file',impFile); fd.append('source',impSource); fd.append('preview','1');
        const r=await fetch(APP_URL+'/api/import-employees.php',{method:'POST',body:fd,credentials:'same-origin'});
        const d=await r.json();
        if(!d.success) throw new Error(d.error||'Erreur analyse');
        renderImportPreview(d.data);
        impGoStep(3);
    }catch(e){ toast(e.message,'error'); }
    finally{ btn.disabled=false; btn.innerHTML='Previsualiser <svg width="14" height="14"><use href="#icon-chevron-right"/></svg>'; }
}

function _td(text, css){
    const td=document.createElement('td');
    td.style.cssText='padding:7px 10px;'+css;
    td.textContent=text||'';
    return td;
}

function renderImportPreview(data){
    const body=document.getElementById('imp-preview-body');
    body.textContent='';
    const rows=data.rows||[];
    const SC={ok:'var(--success)',skip:'var(--text-muted)',duplicate:'var(--warning)'};
    const SL={ok:'A importer',skip:'Ignorer',duplicate:'Doublon'};

    rows.forEach((r,i)=>{
        const tr=document.createElement('tr');
        tr.style.borderBottom='1px solid var(--border-subtle)';
        const faded=r.status!=='ok'?'opacity:.55':'';

        tr.appendChild(_td(String(i+1), 'color:var(--text-muted);'+faded));
        tr.appendChild(_td((r.first_name||'')+' '+(r.last_name||''), 'font-weight:600;'+faded));
        tr.appendChild(_td(r.email||'—', 'color:var(--text-secondary);'+faded));

        // Department cell — may show "new" badge
        const tdDept=document.createElement('td');
        tdDept.style.cssText='padding:7px 10px;'+faded;
        if(r.department && r.dept_new){
            const badge=document.createElement('span');
            badge.style.color='var(--accent)';
            badge.textContent='+ '+r.department;
            tdDept.appendChild(badge);
        } else {
            tdDept.textContent=r.department||'—';
        }
        tr.appendChild(tdDept);

        tr.appendChild(_td(r.position||'—', 'color:var(--text-secondary);'+faded));

        const tdStatus=document.createElement('td');
        tdStatus.style.cssText='padding:7px 10px';
        const badge=document.createElement('span');
        badge.style.cssText='font-size:11.5px;font-weight:700;color:'+(SC[r.status]||'var(--text-muted)');
        badge.textContent=(SL[r.status]||r.status)+(r.reason?' ('+r.reason+')':'');
        tdStatus.appendChild(badge);
        tr.appendChild(tdStatus);

        body.appendChild(tr);
    });

    const ok=rows.filter(r=>r.status==='ok').length;
    const sk=rows.filter(r=>r.status!=='ok').length;
    const hasNew=rows.some(r=>r.dept_new);

    const summary=document.getElementById('imp-preview-summary');
    summary.textContent='';
    const mkSpan=(txt,color)=>{const s=document.createElement('span');s.style.cssText='font-weight:700;color:'+color;s.textContent=txt;return s;};
    summary.appendChild(mkSpan(ok+' a importer','var(--success)'));
    if(sk){ const s=mkSpan(sk+' ignore'+(sk>1?'s':''),'var(--text-muted)'); s.style.fontWeight='400'; summary.appendChild(s); }
    if(hasNew) summary.appendChild(mkSpan('+ nouveaux departements','var(--accent)'));

    document.getElementById('imp-count-badge').textContent=ok>0?'('+ok+')':'';
    document.getElementById('imp-confirm-btn').disabled=ok===0;
}

async function impConfirm(){
    if(!impFile) return;
    const btn=document.getElementById('imp-confirm-btn');
    btn.disabled=true; btn.textContent='Import en cours...';
    try{
        const fd=new FormData();
        fd.append('file',impFile); fd.append('source',impSource); fd.append('preview','0');
        const r=await fetch(APP_URL+'/api/import-employees.php',{method:'POST',body:fd,credentials:'same-origin'});
        const d=await r.json();
        if(!d.success) throw new Error(d.error||'Erreur serveur');
        const {imported,skipped,errors}=d.data;
        document.getElementById('imp-result-icon').textContent=imported>0?'✅':'⚠️';
        document.getElementById('imp-result-title').textContent=imported>0?
            imported+' utilisateur'+(imported>1?'s':'')+' importe'+(imported>1?'s':''):
            'Aucun utilisateur importe';
        document.getElementById('imp-result-sub').textContent=skipped>0?
            skipped+' ligne'+(skipped>1?'s':'')+' ignoree'+(skipped>1?'s':'')+' (doublons / donnees manquantes)':
            'Import termine sans erreur';
        if(errors&&errors.length){
            const ed=document.getElementById('imp-result-errors');
            ed.style.display='';
            ed.textContent=errors.slice(0,5).join('\n');
        }
        impGoStep(4);
    }catch(e){
        toast(e.message,'error');
        btn.disabled=false;
        btn.innerHTML='<svg width="14" height="14"><use href="#icon-check"/></svg> Importer';
    }
}

// ── Tooltip postes assignés ──
(function () {
    const tip = document.createElement('div');
    tip.className = 'emp-assets-tip';
    document.body.appendChild(tip);

    let hideTimer = null;

    function showTip(badge, assets) {
        clearTimeout(hideTimer);

        tip.innerHTML = assets.map(a => `
            <div class="emp-tip-row">
                <span class="emp-tip-os ${a.os}">${a.os}</span>
                <div style="flex:1;min-width:0">
                    <div class="emp-tip-hostname">${a.hostname}</div>
                    ${a.model ? `<div class="emp-tip-model">${a.model}</div>` : ''}
                </div>
            </div>`).join('');

        // Positionner hors écran pour mesurer sans inline-opacity qui écraserait .visible
        tip.style.left = '-9999px';
        tip.style.top  = '-9999px';
        tip.classList.add('visible');

        requestAnimationFrame(() => {
            const rect   = badge.getBoundingClientRect();
            const tipW   = tip.offsetWidth;
            const tipH   = tip.offsetHeight;
            let left = rect.left;
            let top  = rect.bottom + 6;

            if (left + tipW > window.innerWidth - 12) left = window.innerWidth - tipW - 12;
            if (top  + tipH > window.innerHeight - 8)  top  = rect.top - tipH - 6;

            tip.style.left = left + 'px';
            tip.style.top  = top  + 'px';
        });
    }

    function hideTip() {
        hideTimer = setTimeout(() => tip.classList.remove('visible'), 80);
    }

    document.querySelectorAll('.emp-assets-badge').forEach(badge => {
        let assets = [];
        try { assets = JSON.parse(badge.dataset.assets || '[]'); } catch(e) {}
        if (!assets.length) return;

        badge.addEventListener('mouseenter', () => showTip(badge, assets));
        badge.addEventListener('mouseleave', hideTip);
    });
})();
// ── /Tooltip ──

// ── Tooltip bulles licences ──
(function () {
    const tip = document.createElement('div');
    tip.className = 'emp-assets-tip'; // réutilise le même style
    tip.style.minWidth = '180px';
    document.body.appendChild(tip);
    let hideTimer = null;

    const CAT_COLORS = {office:'#2563eb',security:'#22d3a0',os:'#9d7bff',productivity:'#f5a623',development:'#38d9f5',design:'#fb923c',erp:'#ff4757',other:'#64748b'};
    const CAT_LABELS = {office:'Bureautique',security:'Sécurité',os:'Système',productivity:'Productivité',development:'Développement',design:'Design',erp:'ERP',other:'Autre'};

    function showLicTip(el, lics) {
        clearTimeout(hideTimer);
        tip.innerHTML = lics.map(l => `
            <div class="emp-tip-row">
                <span style="width:8px;height:8px;border-radius:50%;background:${CAT_COLORS[l.category]||'#64748b'};flex-shrink:0;display:inline-block"></span>
                <div style="flex:1;min-width:0">
                    <div class="emp-tip-hostname" style="font-family:inherit;font-size:12.5px">${l.name}${l.vendor?' <span style="opacity:.6">('+l.vendor+')</span>':''}</div>
                    <div class="emp-tip-model">${CAT_LABELS[l.category]||l.category}</div>
                </div>
            </div>`).join('');

        tip.style.left = '-9999px';
        tip.style.top  = '-9999px';
        tip.classList.add('visible');

        requestAnimationFrame(() => {
            const rect = el.getBoundingClientRect();
            const tipW = tip.offsetWidth;
            const tipH = tip.offsetHeight;
            let left = rect.left;
            let top  = rect.bottom + 6;
            if (left + tipW > window.innerWidth - 12) left = window.innerWidth - tipW - 12;
            if (top  + tipH > window.innerHeight - 8)  top  = rect.top - tipH - 6;
            tip.style.left = left + 'px';
            tip.style.top  = top  + 'px';
        });
    }

    function hideTip() { hideTimer = setTimeout(() => tip.classList.remove('visible'), 80); }

    document.querySelectorAll('.emp-lic-bubbles').forEach(wrap => {
        let lics = [];
        try { lics = JSON.parse(wrap.dataset.lics || '[]'); } catch(e) {}
        if (!lics.length) return;
        wrap.addEventListener('mouseenter', () => showLicTip(wrap, lics));
        wrap.addEventListener('mouseleave', hideTip);
    });
})();
// ── /Tooltip bulles licences ──

// ── Attribution licences utilisateur ──
function empOpenLicAssign(empId, empName) {
    openLicAssignPanel('employee', empId, empName);
}
// ── /Attribution licences utilisateur ──

// ── Attribution licences groupée ────────────────────────────────────────────
let _blpSelectedLicIds = new Set();

async function bulkAssignLicenses() {
    const ids = getCheckedIds();
    if (!ids.length) return;

    _blpSelectedLicIds.clear();
    document.getElementById('blp-subtitle').textContent =
        ids.length + ' utilisateur' + (ids.length > 1 ? 's' : '') + ' sélectionné' + (ids.length > 1 ? 's' : '');
    document.getElementById('blp-info').textContent = 'Les licences cochées seront attribuées à tous les utilisateurs qui ne les ont pas encore.';
    document.getElementById('blp-list').innerHTML =
        '<div style="padding:40px;text-align:center;color:var(--text-muted);font-size:13px">Chargement…</div>';
    document.getElementById('blp-apply-btn').disabled = true;

    document.getElementById('bulk-lic-backdrop').style.display = '';
    document.getElementById('bulk-lic-panel').style.display = 'flex';
    document.body.style.overflow = 'hidden';

    try {
        // Charger toutes les licences employee
        const r = await fetch(`${APP_URL}/api/licenses.php?target=employee`, {credentials: 'same-origin'});
        const d = await r.json();
        const lics = d.data || [];

        // Pour chaque licence, compter combien des sélectionnés l'ont déjà
        const counts = await Promise.all(lics.map(async l => {
            const ra = await fetch(`${APP_URL}/api/license-assignments.php?license_id=${l.id}`, {credentials:'same-origin'});
            const da = await ra.json();
            const existing = (da.data || []).filter(a => ids.includes(parseInt(a.employee_id))).length;
            return { ...l, already: existing };
        }));

        _blpRender(counts, ids);
    } catch(e) {
        document.getElementById('blp-list').innerHTML =
            '<div style="padding:20px;color:var(--danger);font-size:13px">Erreur de chargement</div>';
    }
}

const _BLP_CAT = {office:'#2563eb',security:'#22d3a0',os:'#9d7bff',productivity:'#f5a623',development:'#38d9f5',design:'#fb923c',erp:'#ff4757',other:'#64748b'};
const _BLP_CAT_LABELS = {office:'Bureautique',security:'Sécurité',os:'Système',productivity:'Productivité',development:'Développement',design:'Design',erp:'ERP / Compta',other:'Autre'};

function _blpRender(lics, ids) {
    const list = document.getElementById('blp-list');
    const total = ids.length;

    if (!lics.length) {
        list.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text-muted);font-size:13px">Aucune licence utilisateur configurée.<br><a href="' + APP_URL + '/pages/licenses.php" style="color:var(--accent)">Gérer les licences →</a></div>';
        return;
    }

    // Grouper par catégorie
    const cats = {};
    lics.forEach(l => { (cats[l.category] = cats[l.category] || []).push(l); });

    list.textContent = '';
    Object.entries(cats).forEach(([cat, items]) => {
        const hdr = document.createElement('div');
        hdr.style.cssText = 'padding:10px 20px 4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);border-top:1px solid var(--border)';
        hdr.textContent = _BLP_CAT_LABELS[cat] || cat;
        list.appendChild(hdr);

        items.forEach(l => {
            const avail    = Math.max(0, (int = n => parseInt(n) || 0, int(l.total_seats) - int(l.used_seats)));
            const already  = l.already || 0;
            const toAssign = total - already;
            const canAdd   = avail >= toAssign;
            const allHave  = already === total;

            const row = document.createElement('label');
            row.style.cssText = 'display:flex;align-items:center;gap:12px;padding:11px 20px;cursor:pointer;border-bottom:1px solid var(--border);transition:background var(--transition)' + (allHave ? ';opacity:.55' : '');
            row.addEventListener('mouseenter', () => row.style.background = 'var(--bg-hover)');
            row.addEventListener('mouseleave', () => row.style.background = '');

            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.value = l.id;
            cb.checked = _blpSelectedLicIds.has(l.id);
            cb.disabled = allHave || (!canAdd && toAssign > 0);
            cb.style.accentColor = 'var(--accent)';
            cb.style.cursor = cb.disabled ? 'not-allowed' : 'pointer';
            cb.addEventListener('change', () => {
                cb.checked ? _blpSelectedLicIds.add(l.id) : _blpSelectedLicIds.delete(l.id);
                document.getElementById('blp-apply-btn').disabled = _blpSelectedLicIds.size === 0;
            });

            const dot = document.createElement('div');
            dot.style.cssText = `width:9px;height:9px;border-radius:50%;background:${_BLP_CAT[cat]||'#64748b'};flex-shrink:0`;

            const info = document.createElement('div');
            info.style.cssText = 'flex:1;min-width:0';
            const name = document.createElement('div');
            name.style.cssText = 'font-size:13.5px;font-weight:600;color:var(--text-primary)';
            name.textContent = l.name + (l.vendor ? ' (' + l.vendor + ')' : '');
            const meta = document.createElement('div');
            meta.style.cssText = 'font-size:12px;color:var(--text-muted);margin-top:2px';
            meta.textContent = (already > 0 ? already + '/' + total + ' déjà attribuée' + (already > 1 ? 's' : '') + ' · ' : '')
                + avail + ' siège' + (avail > 1 ? 's' : '') + ' disponible' + (avail > 1 ? 's' : '');
            info.appendChild(name); info.appendChild(meta);

            // Badge état
            const badge = document.createElement('span');
            badge.style.cssText = 'font-size:11px;font-weight:700;padding:3px 8px;border-radius:20px;flex-shrink:0;white-space:nowrap';
            if (allHave) {
                badge.style.cssText += ';background:rgba(34,211,160,.12);color:var(--success)';
                badge.textContent = 'Tous assignés';
            } else if (!canAdd) {
                badge.style.cssText += ';background:rgba(255,71,87,.12);color:var(--danger)';
                badge.textContent = 'Sièges insuffisants';
            } else {
                badge.style.cssText += ';background:var(--bg-elevated);color:var(--text-muted)';
                badge.textContent = toAssign + ' à attribuer';
            }

            row.appendChild(cb); row.appendChild(dot); row.appendChild(info); row.appendChild(badge);
            list.appendChild(row);
        });
    });

    document.getElementById('blp-apply-btn').disabled = _blpSelectedLicIds.size === 0;
}

async function applyBulkLicenses() {
    const ids     = getCheckedIds();
    const licIds  = [..._blpSelectedLicIds];
    if (!ids.length || !licIds.length) return;

    const btn = document.getElementById('blp-apply-btn');
    btn.disabled = true;
    btn.textContent = 'Attribution en cours…';

    let done = 0, skipped = 0, failed = 0;

    for (const empId of ids) {
        for (const licId of licIds) {
            try {
                await api(APP_URL + '/api/assign-licenses.php', {
                    method: 'POST',
                    body: { license_id: licId, target: 'employee', entity_id: empId }
                });
                done++;
            } catch(e) {
                // Déjà attribuée ou plus de sièges → skip silencieux
                if (e.message && e.message.includes('Déjà')) skipped++;
                else failed++;
            }
        }
    }

    const msg = done + ' attribution' + (done > 1 ? 's' : '') + ' effectuée' + (done > 1 ? 's' : '')
        + (skipped > 0 ? ', ' + skipped + ' déjà attribuée' + (skipped > 1 ? 's' : '') : '')
        + (failed > 0 ? ', ' + failed + ' échec' + (failed > 1 ? 's' : '') : '');

    toast(msg, failed > 0 ? 'warning' : 'success');
    closeBulkLicPanel();
}

function closeBulkLicPanel() {
    document.getElementById('bulk-lic-backdrop').style.display = 'none';
    document.getElementById('bulk-lic-panel').style.display = 'none';
    document.body.style.overflow = '';
}
// ── /Attribution licences groupée ───────────────────────────────────────────

// Pré-filtrage depuis ?dept= (lien depuis la page Départements)
<?php if ($filter_dept): ?>
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('dept-filter');
    if (sel && sel.value) sel.dispatchEvent(new Event('change'));
});
<?php endif; ?>

</script>
<?php render_footer(); ?>
