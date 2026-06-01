<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();
$stmt = $pdo->prepare('SELECT id, name, active, last_used, used_count, created_at FROM api_keys WHERE client_id = ? ORDER BY created_at DESC');
$stmt->execute([$client_id]);
$keys = $stmt->fetchAll();

// Get client code for agent config preview
$c = $pdo->prepare('SELECT code FROM clients WHERE id = ?');
$c->execute([$client_id]);
$client = $c->fetch();

render_head('Clés API');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('settings'); ?>
<div class="main-wrapper">
<?php render_topbar('Clés API & Agent', 'Enregistrement automatique des postes'); ?>
<main class="main-content" style="max-width:900px">

<!-- HOW IT WORKS -->
<div class="card mb-24" style="border-color:rgba(79,126,248,0.25);background:linear-gradient(135deg,rgba(79,126,248,0.06),rgba(124,58,237,0.06))">
    <div class="card-body" style="display:flex;gap:20px;align-items:flex-start">
        <div style="width:40px;height:40px;background:var(--accent-dim);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <svg class="icon-md" style="color:var(--accent)"><use href="#icon-layers"/></svg>
        </div>
        <div>
            <div style="font-weight:700;font-size:14px;margin-bottom:6px">Comment ça marche ?</div>
            <div style="font-size:13.5px;color:var(--text-secondary);line-height:1.7">
                Générez une clé API, déployez le script <code style="color:var(--accent);background:var(--accent-dim);padding:1px 6px;border-radius:4px">inventorflow-agent.sh</code> sur vos postes Linux/macOS.
                Le script collecte automatiquement les infos matérielles et les envoie toutes les heures.
                Les postes inconnus sont créés, les existants sont mis à jour.
            </div>
        </div>
    </div>
</div>

<!-- API KEYS -->
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Clés API</div>
            <div class="card-subtitle"><?= count($keys) ?> clé<?= count($keys) > 1 ? 's' : '' ?> configurée<?= count($keys) > 1 ? 's' : '' ?></div>
        </div>
        <button class="btn btn-primary" onclick="Modal.open('modal-newkey')">
            <svg><use href="#icon-plus"/></svg> Nouvelle clé
        </button>
    </div>
    <div class="table-wrapper" style="border:none;border-radius:0">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Statut</th>
                    <th>Dernière utilisation</th>
                    <th>Appels</th>
                    <th>Créée le</th>
                    <th style="cursor:default">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($keys)): ?>
                <tr><td colspan="6">
                    <div class="empty-state" style="padding:30px 0">
                        <svg><use href="#icon-key"/></svg>
                        <h3>Aucune clé API</h3>
                        <p>Créez une clé pour commencer à enregistrer des postes automatiquement.</p>
                        <button class="btn btn-primary" onclick="Modal.open('modal-newkey')"><svg><use href="#icon-plus"/></svg> Créer une clé</button>
                    </div>
                </td></tr>
            <?php else: ?>
            <?php foreach ($keys as $k): ?>
                <tr>
                    <td style="font-weight:600"><?= h($k['name']) ?></td>
                    <td><span class="badge <?= $k['active'] ? 'badge-active' : 'badge-retired' ?>"><?= $k['active'] ? 'Active' : 'Révoquée' ?></span></td>
                    <td><?= $k['last_used'] ? time_ago($k['last_used']) : '<span class="text-muted">Jamais</span>' ?></td>
                    <td><span class="fw-600"><?= number_format($k['used_count']) ?></span></td>
                    <td><?= date('d/m/Y', strtotime($k['created_at'])) ?></td>
                    <td>
                        <div class="td-actions">
                            <button class="btn btn-ghost btn-icon" onclick="showAgentConfig(<?= $k['id'] ?>, '<?= h(addslashes($k['name'])) ?>')" title="Voir config agent">
                                <svg><use href="#icon-download"/></svg>
                            </button>
                            <button class="btn btn-ghost btn-icon" onclick="revokeKey(<?= $k['id'] ?>, '<?= h(addslashes($k['name'])) ?>')" title="Révoquer">
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
</div>

<!-- AGENT INSTALL -->
<div class="card">
    <div class="card-header">
        <div class="card-title">Installation de l'agent</div>
    </div>
    <div class="card-body">
        <div class="tabs">
            <button class="tab active" onclick="switchTab(this,'tab-linux')">Linux</button>
            <button class="tab" onclick="switchTab(this,'tab-mac')">macOS</button>
            <button class="tab" onclick="switchTab(this,'tab-cron')">Cron / Automatisation</button>
        </div>

        <div id="tab-linux">
            <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:12px">Déploiement rapide sur un poste Linux (requiert curl) :</p>
            <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;font-family:monospace;font-size:13px;color:var(--text-primary);overflow-x:auto">
                <div style="color:var(--text-muted);margin-bottom:4px"># Télécharger et installer l'agent</div>
                <div>sudo wget -O /usr/local/bin/inventorflow-agent.sh \</div>
                <div style="padding-left:4ch"><?= h(($_SERVER['HTTP_HOST'] ?? 'localhost:8083') . APP_URL) ?>/agent/inventorflow-agent.sh</div>
                <div>sudo chmod +x /usr/local/bin/inventorflow-agent.sh</div>
                <br>
                <div style="color:var(--text-muted);margin-bottom:4px"># Éditer la config (remplacer REPLACE_WITH_YOUR_API_KEY)</div>
                <div>sudo nano /usr/local/bin/inventorflow-agent.sh</div>
                <br>
                <div style="color:var(--text-muted);margin-bottom:4px"># Premier lancement</div>
                <div>sudo /usr/local/bin/inventorflow-agent.sh</div>
            </div>
        </div>

        <div id="tab-mac" style="display:none">
            <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:12px">Déploiement sur macOS (curl est pré-installé) :</p>
            <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;font-family:monospace;font-size:13px;color:var(--text-primary);overflow-x:auto">
                <div style="color:var(--text-muted);margin-bottom:4px"># Télécharger l'agent</div>
                <div>sudo curl -o /usr/local/bin/inventorflow-agent.sh \</div>
                <div style="padding-left:4ch"><?= h(($_SERVER['HTTP_HOST'] ?? 'localhost:8083') . APP_URL) ?>/agent/inventorflow-agent.sh</div>
                <div>sudo chmod +x /usr/local/bin/inventorflow-agent.sh</div>
                <br>
                <div style="color:var(--text-muted);margin-bottom:4px"># Éditer la config</div>
                <div>sudo nano /usr/local/bin/inventorflow-agent.sh</div>
                <br>
                <div style="color:var(--text-muted);margin-bottom:4px"># Premier lancement</div>
                <div>sudo /usr/local/bin/inventorflow-agent.sh</div>
            </div>
        </div>

        <div id="tab-cron" style="display:none">
            <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:12px">Automatiser avec cron (toutes les heures) :</p>
            <div style="background:var(--bg-base);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;font-family:monospace;font-size:13px;color:var(--text-primary);overflow-x:auto">
                <div style="color:var(--text-muted);margin-bottom:4px"># Éditer crontab root</div>
                <div>sudo crontab -e</div>
                <br>
                <div style="color:var(--text-muted);margin-bottom:4px"># Ajouter ces lignes :</div>
                <div>@reboot /usr/local/bin/inventorflow-agent.sh</div>
                <div>0 * * * * /usr/local/bin/inventorflow-agent.sh >> /var/log/inventorflow-agent.log 2>&1</div>
            </div>
            <div style="margin-top:12px;padding:12px;background:var(--success-dim);border:1px solid rgba(34,211,160,0.2);border-radius:var(--radius-sm);font-size:13px;color:var(--success)">
                Le log de l'agent est disponible dans <code>/var/log/inventorflow-agent.log</code>
            </div>
        </div>
    </div>
</div>

</main>
</div>

<!-- MODAL: NEW KEY -->
<div class="modal-backdrop" id="modal-newkey" style="display:none">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <h2 class="modal-title">Nouvelle clé API</h2>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body">
            <div id="newkey-form">
                <div class="form-group">
                    <label>Nom de la clé <span class="required">*</span></label>
                    <input type="text" id="key-name" class="form-control" placeholder="Ex: Agent salle serveurs, Laptops RH…">
                    <div class="form-hint">Identifie l'usage ou le groupe de postes ciblé</div>
                </div>
            </div>
            <div id="newkey-result" style="display:none">
                <div style="padding:14px;background:var(--success-dim);border:1px solid rgba(34,211,160,0.25);border-radius:var(--radius-sm);margin-bottom:16px">
                    <div style="font-size:12px;font-weight:700;color:var(--success);margin-bottom:8px">⚠ Copiez cette clé maintenant — elle ne sera plus affichée</div>
                    <div id="key-display" style="font-family:monospace;font-size:13px;background:var(--bg-base);padding:10px;border-radius:4px;word-break:break-all;cursor:pointer" onclick="copyKey()" title="Cliquer pour copier"></div>
                </div>
                <div style="font-size:13px;color:var(--text-secondary)">
                    Ajoutez cette clé dans la variable <code style="color:var(--accent)">IF_API_KEY</code> du script agent.
                </div>
            </div>
        </div>
        <div class="modal-footer" id="newkey-footer">
            <button class="btn btn-secondary" data-modal-close>Annuler</button>
            <button class="btn btn-primary" onclick="createKey()"><svg><use href="#icon-key"/></svg> Générer la clé</button>
        </div>
    </div>
</div>

<!-- MODAL: AGENT CONFIG -->
<div class="modal-backdrop" id="modal-agent-config" style="display:none">
    <div class="modal modal-lg">
        <div class="modal-header">
            <h2 class="modal-title">Configuration agent</h2>
            <button class="modal-close" data-modal-close><svg><use href="#icon-x"/></svg></button>
        </div>
        <div class="modal-body">
            <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:12px">
                Bloc de configuration à remplacer dans le script agent :
            </p>
            <div id="agent-config-block" style="background:var(--bg-base);border:1px solid var(--border);border-radius:var(--radius-sm);padding:16px;font-family:monospace;font-size:13px;color:var(--text-primary);white-space:pre;overflow-x:auto"></div>
            <button class="btn btn-secondary" style="margin-top:12px" onclick="copyConfig()">
                <svg><use href="#icon-download"/></svg> Copier
            </button>
        </div>
    </div>
</div>

<?php
$keys_json = json_encode(array_map(fn($k) => array_map(fn($v) => $v === null ? '' : $v, $k), $keys),
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<script>
const APP_URL = '<?= APP_URL ?>';
const SERVER_URL = '<?= h('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8083')) ?>';
const API_KEYS = <?= $keys_json ?>;

async function createKey() {
    const name = document.getElementById('key-name').value.trim();
    if (!name) { toast('Donnez un nom à la clé', 'warning'); return; }
    try {
        const res = await api(`${APP_URL}/api/apikeys.php`, { method: 'POST', body: { name } });
        const keyDisplay = document.getElementById('key-display');
        keyDisplay.textContent = res.data.plain_key;
        document.getElementById('newkey-form').style.display = 'none';
        document.getElementById('newkey-result').style.display = 'block';
        const footer = document.getElementById('newkey-footer');
        footer.textContent = '';
        const closeBtn = document.createElement('button');
        closeBtn.className = 'btn btn-primary';
        closeBtn.setAttribute('data-modal-close', '');
        closeBtn.textContent = 'Fermer';
        footer.appendChild(closeBtn);
    } catch(e) {}
}

function copyKey() {
    const key = document.getElementById('key-display').textContent;
    navigator.clipboard.writeText(key).then(() => toast('Clé copiée', 'success'));
}

function showAgentConfig(id, name) {
    const config = `# ── CONFIGURATION InventorFlow Agent ──\nIF_SERVER="${SERVER_URL}"\nIF_API_KEY="[VOTRE_CLÉ_API]"\nIF_DEPT_CODE=""`;
    const block = document.getElementById('agent-config-block');
    block.textContent = config;
    Modal.open('modal-agent-config');
}

function copyConfig() {
    const text = document.getElementById('agent-config-block').textContent;
    navigator.clipboard.writeText(text).then(() => toast('Config copiée', 'success'));
}

async function revokeKey(id, name) {
    if (!confirm(`Révoquer la clé "${name}" ? Les agents utilisant cette clé ne pourront plus s'enregistrer.`)) return;
    try {
        await api(`${APP_URL}/api/apikeys.php`, { method: 'DELETE', body: { id } });
        toast('Clé révoquée', 'success');
        setTimeout(() => location.reload(), 600);
    } catch(e) {}
}

function switchTab(btn, tabId) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    ['tab-linux','tab-mac','tab-cron'].forEach(id => {
        document.getElementById(id).style.display = id === tabId ? 'block' : 'none';
    });
}
</script>
<?php render_footer(); ?>
