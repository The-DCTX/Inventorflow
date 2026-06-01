<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();
if (!is_superadmin()) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();
$stmt = $pdo->query('SELECT c.*,
    (SELECT COUNT(*) FROM assets a WHERE a.client_id = c.id) as asset_count,
    (SELECT COUNT(*) FROM employees e WHERE e.client_id = c.id AND e.active = 1) as emp_count,
    (SELECT COUNT(*) FROM api_keys k WHERE k.client_id = c.id AND k.active = 1) as key_count
FROM clients c ORDER BY c.name');
$clients = $stmt->fetchAll();

render_head('Clients');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('clients'); ?>
<div class="main-wrapper">
<?php render_topbar('Clients', count($clients) . ' clients'); ?>
<main class="main-content">

<div class="toolbar">
    <div class="search-box">
        <svg><use href="#icon-search"/></svg>
        <input type="text" id="search-input" class="search-input" placeholder="Rechercher un client…">
    </div>
    <div style="margin-left:auto">
        <button class="btn btn-primary" onclick="Modal.open('modal-client')">
            <svg><use href="#icon-plus"/></svg> Nouveau client
        </button>
    </div>
</div>

<!-- CLIENT CARDS -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px" id="clients-grid">
<?php foreach ($clients as $c): ?>
<div class="card client-card" data-name="<?= h(strtolower($c['name'])) ?>">
    <div class="card-body" style="padding:20px">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px">
            <div>
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px">
                    <span style="font-size:17px;font-weight:800;color:var(--text-primary)"><?= h($c['name']) ?></span>
                    <span class="badge <?= $c['active'] ? 'badge-active' : 'badge-retired' ?>"><?= $c['active'] ? 'Actif' : 'Inactif' ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:6px">
                    <span class="badge badge-stock" style="font-family:monospace"><?= h($c['code']) ?></span>
                    <?php if ($c['contact_name']): ?>
                    <span style="font-size:12.5px;color:var(--text-secondary)"><?= h($c['contact_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div style="display:flex;gap:6px">
                <button class="btn btn-ghost btn-icon" onclick="editClient(<?= $c['id'] ?>)" title="Modifier">
                    <svg><use href="#icon-edit"/></svg>
                </button>
            </div>
        </div>

        <!-- Stats row -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:16px">
            <div style="text-align:center;padding:10px;background:var(--bg-elevated);border-radius:var(--radius-sm)">
                <div style="font-size:20px;font-weight:800;color:var(--text-primary)"><?= $c['asset_count'] ?></div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Postes</div>
            </div>
            <div style="text-align:center;padding:10px;background:var(--bg-elevated);border-radius:var(--radius-sm)">
                <div style="font-size:20px;font-weight:800;color:var(--text-primary)"><?= $c['emp_count'] ?></div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Utilisateurs</div>
            </div>
            <div style="text-align:center;padding:10px;background:<?= $c['key_count'] > 0 ? 'var(--success-dim)' : 'var(--bg-elevated)' ?>;border-radius:var(--radius-sm)">
                <div style="font-size:20px;font-weight:800;color:<?= $c['key_count'] > 0 ? 'var(--success)' : 'var(--text-muted)' ?>"><?= $c['key_count'] ?></div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">Clés API</div>
            </div>
        </div>

        <!-- Actions -->
        <div style="display:flex;gap:8px">
            <button class="btn btn-primary" style="flex:1;justify-content:center"
                onclick="openDeploy(<?= $c['id'] ?>, '<?= h(addslashes($c['name'])) ?>', '<?= h($c['code']) ?>')">
                <svg><use href="#icon-layers"/></svg>
                Déployer l'agent
            </button>
            <a href="<?= APP_URL ?>/?switch=<?= $c['id'] ?>" class="btn btn-secondary" title="Accéder au tableau de bord">
                <svg><use href="#icon-chevron-right"/></svg>
            </a>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($clients)): ?>
<div class="card" style="grid-column:1/-1">
    <div class="empty-state">
        <svg><use href="#icon-briefcase"/></svg>
        <h3>Aucun client</h3>
        <button class="btn btn-primary" onclick="Modal.open('modal-client')"><svg><use href="#icon-plus"/></svg> Ajouter</button>
    </div>
</div>
<?php endif; ?>
</div>

</main>
</div>

<!-- MODAL: ADD / EDIT CLIENT -->
<div class="modal-backdrop" id="modal-client" style="display:none">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="modal-client-title">Nouveau client</h2>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body">
            <form id="form-client" autocomplete="off">
                <input type="hidden" name="id" id="client-id">
                <div class="form-row">
                    <div class="form-group">
                        <label>Nom <span class="required">*</span></label>
                        <input type="text" name="name" id="client-name" class="form-control" required placeholder="Acme Corp">
                    </div>
                    <div class="form-group">
                        <label>Code <span class="required">*</span></label>
                        <input type="text" name="code" id="client-code" class="form-control" required placeholder="ACM" maxlength="20"
                            style="text-transform:uppercase;font-family:monospace">
                        <div class="form-hint">Utilisé dans la génération des noms de postes</div>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Nom du contact</label>
                        <input type="text" name="contact_name" id="client-contact-name" class="form-control" placeholder="Jean Dupont">
                    </div>
                    <div class="form-group">
                        <label>Email contact</label>
                        <input type="email" name="contact_email" id="client-contact-email" class="form-control" placeholder="it@acme.com">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Téléphone</label>
                        <input type="tel" name="phone" id="client-phone" class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Statut</label>
                        <select name="active" id="client-active" class="form-control">
                            <option value="1">Actif</option>
                            <option value="0">Inactif</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Adresse</label>
                    <textarea name="address" id="client-address" class="form-control" rows="2"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" data-modal-close>Annuler</button>
            <button class="btn btn-primary" onclick="saveClient()"><svg><use href="#icon-check"/></svg> Enregistrer</button>
        </div>
    </div>
</div>

<!-- MODAL: DEPLOY -->
<div class="modal-backdrop" id="modal-deploy" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="deploy-title">Déployer l'agent</h2>
                <div id="deploy-subtitle" style="font-size:12.5px;color:var(--text-secondary);margin-top:3px"></div>
            </div>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body" id="deploy-body">
            <div style="text-align:center;padding:40px 0">
                <svg class="icon-lg" style="color:var(--text-muted);animation:spin 1s linear infinite">
                    <use href="#icon-refresh"/>
                </svg>
                <div style="margin-top:12px;color:var(--text-secondary)">Préparation du déploiement…</div>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }

.deploy-tab { display:flex;gap:2px;margin-bottom:16px;border-bottom:1px solid var(--border);padding-bottom:0; }
.deploy-tab-btn {
    display:flex;align-items:center;gap:7px;
    padding:9px 16px;font-size:13.5px;font-weight:600;
    color:var(--text-secondary);cursor:pointer;border:none;
    background:none;font-family:inherit;
    border-bottom:2px solid transparent;
    transition:all var(--transition);margin-bottom:-1px;
}
.deploy-tab-btn:hover { color:var(--text-primary); }
.deploy-tab-btn.active { color:var(--accent);border-bottom-color:var(--accent); }

.deploy-cmd {
    background:var(--bg-base);
    border:1px solid var(--border);
    border-radius:var(--radius-sm);
    padding:14px 16px;
    font-family:'SF Mono','Consolas','Fira Code',monospace;
    font-size:13px;
    color:var(--success);
    word-break:break-all;
    cursor:pointer;
    transition:border-color var(--transition),box-shadow var(--transition);
    position:relative;
    line-height:1.7;
}
.deploy-cmd:hover {
    border-color:var(--accent);
    box-shadow:0 0 0 3px var(--accent-dim);
}
.deploy-cmd:hover::after {
    content:'Cliquer pour copier';
    position:absolute;top:8px;right:10px;
    font-size:11px;color:var(--accent);font-weight:600;
}
.deploy-cmd .cmd-comment { color:var(--text-muted); }
.deploy-option {
    display:flex;align-items:center;gap:10px;
    padding:10px 14px;
    border:1px solid var(--border);
    border-radius:var(--radius-sm);
    margin-bottom:8px;
    cursor:pointer;
    transition:border-color var(--transition),background var(--transition);
}
.deploy-option:hover { border-color:var(--accent);background:var(--accent-dim); }
.deploy-option input[type=checkbox] { accent-color:var(--accent);width:16px;height:16px; }
</style>

<?php
$clients_json = json_encode(array_map(function($c) {
    return array_map(fn($v) => $v === null ? '' : $v, $c);
}, $clients), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<script>
const CLIENTS = <?= $clients_json ?>;
const APP_URL = '<?= APP_URL ?>';
const SERVER_URL = '<?= h(APP_SERVER_URL) ?>';

// ── SEARCH ────────────────────────────────────────────────
document.getElementById('search-input')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.client-card').forEach(card => {
        card.closest('[class]').style.display =
            !q || card.dataset.name.includes(q) ? '' : 'none';
    });
});

// ── DEPLOY MODAL ──────────────────────────────────────────
let currentDeployKey = null;
let currentClientId  = null;

async function openDeploy(clientId, clientName, clientCode) {
    currentClientId = clientId;
    document.getElementById('deploy-title').textContent = 'Déployer — ' + clientName;
    document.getElementById('deploy-subtitle').textContent = 'Code client : ' + clientCode;
    document.getElementById('deploy-body').textContent = 'Chargement…';
    Modal.open('modal-deploy');

    try {
        const res = await api(`${APP_URL}/api/deploy-key.php`, { method: 'POST', body: { client_id: clientId } });
        currentDeployKey = res.data.plain_key;
        renderDeployModal(res.data);
    } catch(e) {
        document.getElementById('deploy-body').textContent = 'Erreur lors du chargement.';
    }
}

function renderDeployModal(data) {
    const body = document.getElementById('deploy-body');
    body.textContent = '';
    const key = data.plain_key;
    currentDeployKey = key;

    // Options
    const optWrap = document.createElement('div');
    optWrap.style.marginBottom = '20px';

    const deptGroup = document.createElement('div');
    deptGroup.className = 'form-group';
    deptGroup.style.marginBottom = '14px';
    const deptLabel = document.createElement('label');
    deptLabel.textContent = 'Département (optionnel)';
    const deptInput = document.createElement('input');
    deptInput.type = 'text';
    deptInput.id = 'deploy-dept';
    deptInput.className = 'form-control';
    deptInput.placeholder = 'IT, RH, DIR…';
    deptInput.style.textTransform = 'uppercase';
    deptInput.setAttribute('maxlength', '20');
    deptInput.addEventListener('input', () => refreshCommands(key));
    deptGroup.appendChild(deptLabel);
    deptGroup.appendChild(deptInput);

    const cronOpt = document.createElement('label');
    cronOpt.className = 'deploy-option';
    const cronCheck = document.createElement('input');
    cronCheck.type = 'checkbox';
    cronCheck.id = 'deploy-cron';
    cronCheck.checked = true;
    cronCheck.addEventListener('change', () => refreshCommands(key));
    const cronText = document.createElement('div');
    const cronTitle = document.createElement('div');
    cronTitle.style.fontWeight = '600';
    cronTitle.style.fontSize = '13.5px';
    cronTitle.textContent = 'Installer le cron automatiquement';
    const cronSub = document.createElement('div');
    cronSub.style.cssText = 'font-size:12px;color:var(--text-secondary)';
    cronSub.textContent = 'Lance l\'agent toutes les heures (cron Linux / LaunchDaemon macOS)';
    cronText.appendChild(cronTitle);
    cronText.appendChild(cronSub);
    cronOpt.appendChild(cronCheck);
    cronOpt.appendChild(cronText);

    optWrap.appendChild(deptGroup);
    optWrap.appendChild(cronOpt);
    body.appendChild(optWrap);

    // Tabs
    const tabBar = document.createElement('div');
    tabBar.className = 'deploy-tab';

    const tabs = [
        { id: 'dtab-linux', label: 'Linux',  icon: '#icon-linux' },
        { id: 'dtab-mac',   label: 'macOS',  icon: '#icon-apple' },
        { id: 'dtab-win',   label: 'Windows', icon: '#icon-windows' },
    ];
    tabs.forEach((t, i) => {
        const btn = document.createElement('button');
        btn.className = 'deploy-tab-btn' + (i === 0 ? ' active' : '');
        btn.dataset.tab = t.id;
        btn.innerHTML = '';
        const svgEl = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svgEl.setAttribute('width', '14'); svgEl.setAttribute('height', '14');
        const useEl = document.createElementNS('http://www.w3.org/2000/svg', 'use');
        useEl.setAttributeNS('http://www.w3.org/1999/xlink', 'href', t.icon);
        svgEl.appendChild(useEl);
        btn.appendChild(svgEl);
        btn.appendChild(document.createTextNode(' ' + t.label));
        btn.addEventListener('click', () => {
            tabBar.querySelectorAll('.deploy-tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            body.querySelectorAll('.dtab-content').forEach(el => {
                el.style.display = el.id === t.id ? 'block' : 'none';
            });
        });
        tabBar.appendChild(btn);
    });
    body.appendChild(tabBar);

    // Command panels
    tabs.forEach((t, i) => {
        const panel = document.createElement('div');
        panel.id = t.id;
        panel.className = 'dtab-content';
        panel.style.display = i === 0 ? 'block' : 'none';
        body.appendChild(panel);
    });

    // Copy on click
    body.addEventListener('click', e => {
        const cmd = e.target.closest('.deploy-cmd');
        if (!cmd) return;
        navigator.clipboard.writeText(cmd.dataset.cmd || cmd.textContent.trim())
            .then(() => toast('Commande copiée !', 'success'));
    });

    refreshCommands(key);
}

function buildCmd(os, key, dept, cron) {
    const d = dept ? `&dept=${encodeURIComponent(dept.toUpperCase())}` : '';
    const cr = cron ? '&cron=1' : '';
    if (os === 'win') {
        const url = `${SERVER_URL}/deploy.php?key=${encodeURIComponent(key)}${d}&os=win`;
        return `powershell -ExecutionPolicy Bypass -Command "& {[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-Expression (New-Object Net.WebClient).DownloadString('${url}')}"`;
    }
    const url = `${SERVER_URL}/deploy.php?key=${encodeURIComponent(key)}${d}${cr}`;
    if (os === 'mac') return `curl -fsSL '${url}' > /tmp/if_install.sh && sudo bash /tmp/if_install.sh`;
    return `curl -fsSL "${url}" | bash`;
}

function refreshCommands(key) {
    const dept = document.getElementById('deploy-dept')?.value.trim() || '';
    const cron = document.getElementById('deploy-cron')?.checked ?? true;

    const panels = { 'dtab-linux': 'Linux', 'dtab-mac': 'macOS', 'dtab-win': 'Windows' };
    Object.keys(panels).forEach(tabId => {
        const panel = document.getElementById(tabId);
        if (!panel) return;
        panel.textContent = '';

        const desc = document.createElement('p');
        desc.style.cssText = 'font-size:13px;color:var(--text-secondary);margin-bottom:12px';
        desc.textContent = 'Copiez et exécutez cette commande sur le poste cible (en root/sudo) :';
        panel.appendChild(desc);

        const os = tabId === 'dtab-mac' ? 'mac' : tabId === 'dtab-win' ? 'win' : 'linux';
        const cmd = buildCmd(os, key, dept, cron);
        const block = document.createElement('div');
        block.className = 'deploy-cmd';
        block.dataset.cmd = cmd;
        block.textContent = cmd;
        panel.appendChild(block);

        const hint = document.createElement('div');
        hint.style.cssText = 'margin-top:10px;font-size:12px;color:var(--text-muted);display:flex;align-items:center;gap:6px';
        hint.textContent = '⚡ Une seule commande — télécharge, configure, installe le cron et enregistre le poste.';
        panel.appendChild(hint);
    });
}

// ── CLIENT CRUD ───────────────────────────────────────────
function editClient(id) {
    const c = CLIENTS.find(x => x.id == id);
    if (!c) return;
    document.getElementById('modal-client-title').textContent = 'Modifier : ' + c.name;
    const fields = {
        'client-id': 'id', 'client-name': 'name', 'client-code': 'code',
        'client-contact-name': 'contact_name', 'client-contact-email': 'contact_email',
        'client-phone': 'phone', 'client-address': 'address', 'client-active': 'active',
    };
    Object.entries(fields).forEach(([elId, key]) => {
        const el = document.getElementById(elId);
        if (el) el.value = c[key] ?? '';
    });
    Modal.open('modal-client');
}

async function saveClient() {
    const form = document.getElementById('form-client');
    const data = Object.fromEntries(new FormData(form));
    data.code = data.code.toUpperCase();
    try {
        await api(`${APP_URL}/api/clients.php`, { method: data.id ? 'PUT' : 'POST', body: data });
        toast(data.id ? 'Client mis à jour' : 'Client créé', 'success');
        Modal.close('modal-client');
        setTimeout(() => location.reload(), 700);
    } catch(e) {}
}
</script>
<?php render_footer(); ?>
