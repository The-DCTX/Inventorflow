<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();
if (!is_superadmin()) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();

$users_stmt = $pdo->query('SELECT u.*, c.name as client_name FROM users u LEFT JOIN clients c ON u.client_id = c.id ORDER BY u.role, u.username');
$users = $users_stmt->fetchAll();

$clients = get_clients();
$me = current_user();

render_head('Comptes utilisateurs');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('users-admin'); ?>
<div class="main-wrapper">
<?php render_topbar('Comptes utilisateurs', count($users) . ' compte' . (count($users) > 1 ? 's' : '')); ?>
<main class="main-content">

<div class="toolbar">
    <div style="margin-left:auto">
        <button class="btn btn-primary" onclick="openModal()">
            <svg><use href="#icon-plus"/></svg> Nouveau compte
        </button>
    </div>
</div>

<div class="table-wrapper">
<table id="main-table">
    <thead>
        <tr>
            <th>Utilisateur</th>
            <th>Rôle</th>
            <th>Client</th>
            <th>Email</th>
            <th>Dernière connexion</th>
            <th>Statut</th>
            <th>Auth</th>
            <th>TOTP</th>
            <th style="cursor:default">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
        $role_labels = ['superadmin' => 'Super Admin', 'admin' => 'Admin', 'viewer' => 'Lecteur'];
        $role_colors = ['superadmin' => 'background:rgba(157,123,255,.15);color:#9d7bff', 'admin' => 'background:var(--accent-dim);color:var(--accent)', 'viewer' => 'background:var(--bg-elevated);color:var(--text-muted)'];
        $is_me = (int)$u['id'] === (int)$me['id'];
    ?>
    <tr data-id="<?= $u['id'] ?>">
        <td>
            <div style="display:flex;align-items:center;gap:10px">
                <div style="width:34px;height:34px;border-radius:50%;background:var(--accent-dim);border:1px solid rgba(79,126,248,.3);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0">
                    <?= strtoupper(substr($u['username'], 0, 2)) ?>
                </div>
                <div>
                    <div style="font-weight:600"><?= h($u['username']) ?><?= $is_me ? ' <span style="font-size:10px;color:var(--text-muted)">(vous)</span>' : '' ?></div>
                    <?php if ($u['full_name']): ?>
                    <div style="font-size:12px;color:var(--text-muted)"><?= h($u['full_name']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </td>
        <td><span style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:20px;<?= $role_colors[$u['role']] ?? '' ?>"><?= $role_labels[$u['role']] ?? $u['role'] ?></span></td>
        <td style="font-size:13px"><?= $u['client_name'] ? h($u['client_name']) : '<span class="text-muted">Tous les clients</span>' ?></td>
        <td style="font-size:13px"><?= $u['email'] ? h($u['email']) : '<span class="text-muted">—</span>' ?></td>
        <td style="font-size:12.5px;color:var(--text-muted)"><?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Jamais' ?></td>
        <td>
            <?php if ($u['active']): ?>
            <span class="badge badge-active">Actif</span>
            <?php else: ?>
            <span class="badge badge-retired">Inactif</span>
            <?php endif; ?>
        </td>
        <td>
            <?php $src = $u['auth_source'] ?? 'local'; ?>
            <span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;<?= $src==='ldap'?'background:rgba(56,217,245,.12);color:#38d9f5':'background:var(--bg-elevated);color:var(--text-muted)' ?>">
                <?= strtoupper($src) ?>
            </span>
        </td>
        <td>
            <?php if (!empty($u['totp_enabled'])): ?>
            <span class="badge badge-active">Actif</span>
            <?php else: ?>
            <span class="badge badge-retired">Inactif</span>
            <?php endif; ?>
        </td>
        <td>
            <div class="td-actions">
                <button class="btn btn-ghost btn-icon" onclick="openModal(<?= $u['id'] ?>)" title="Modifier"><svg><use href="#icon-edit"/></svg></button>
                <?php if (!empty($u['totp_enabled'])): ?>
                <button class="btn btn-ghost btn-icon" onclick="resetTotp(<?= $u['id'] ?>, '<?= h(addslashes($u['username'])) ?>')" title="Réinitialiser TOTP" style="color:var(--warning)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0115-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 01-15 6.7L3 16"/></svg>
                </button>
                <?php endif; ?>
                <?php if (!$is_me): ?>
                <button class="btn btn-ghost btn-icon" onclick="deleteUser(<?= $u['id'] ?>, '<?= h(addslashes($u['username'])) ?>')" title="Supprimer"><svg style="color:var(--danger)"><use href="#icon-trash"/></svg></button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Modal création / édition -->
<div class="modal-backdrop" id="modal-user" style="display:none">
<div class="modal" style="max-width:480px;width:100%">
    <div class="modal-header">
        <h2 class="modal-title" id="modal-title">Nouveau compte</h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-user')"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div class="modal-body">
        <form id="form-user" autocomplete="off">
        <input type="hidden" id="user-id">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Nom d'utilisateur <span class="required">*</span></label>
                <input type="text" id="user-username" class="form-control" autocomplete="off" required>
            </div>
            <div class="form-group">
                <label class="form-label">Nom complet</label>
                <input type="text" id="user-fullname" class="form-control">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Email</label>
            <input type="email" id="user-email" class="form-control">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Rôle <span class="required">*</span></label>
                <select id="user-role" class="form-control" onchange="onRoleChange()">
                    <option value="admin">Admin</option>
                    <option value="viewer">Lecteur</option>
                    <option value="superadmin">Super Admin</option>
                </select>
                <div class="form-hint" id="role-hint">Accès complet à un client</div>
            </div>
            <div class="form-group" id="client-group">
                <label class="form-label">Client associé</label>
                <select id="user-client" class="form-control">
                    <option value="">— Sélectionner —</option>
                    <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-group">
            <label class="form-label" id="pwd-label">Mot de passe <span class="required">*</span></label>
            <input type="password" id="user-password" class="form-control" autocomplete="new-password" minlength="8" placeholder="8 caractères minimum">
            <div class="form-hint" id="pwd-hint">Laisser vide pour ne pas modifier</div>
        </div>
        <div class="form-group" id="active-group" style="display:none">
            <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                <input type="checkbox" id="user-active" style="accent-color:var(--accent);width:16px;height:16px"> 
                <span>Compte actif</span>
            </label>
        </div>
        <div class="form-group">
            <label class="form-label">Source d'authentification</label>
            <select id="user-auth-source" class="form-control">
                <option value="local">Local (mot de passe InventorFlow)</option>
                <option value="ldap">LDAP / Active Directory</option>
            </select>
            <div class="form-hint">LDAP : le mot de passe est vérifié par l'annuaire</div>
        </div>
        </form>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="Modal.close('modal-user')">Annuler</button>
        <button class="btn btn-primary" onclick="saveUser()" id="btn-save">
            <svg><use href="#icon-check"/></svg> Enregistrer
        </button>
    </div>
</div>
</div>

<?php
$users_json = json_encode(array_map(fn($u) => array_map(fn($v) => $v === null ? '' : $v, $u), $users), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
?>
<script>
const APP_URL = '<?= APP_URL ?>';
const USERS   = <?= $users_json ?>;
const ME_ID   = <?= (int)$me['id'] ?>;

const ROLE_HINTS = {
    superadmin: 'Accès total à tous les clients — réservé aux administrateurs',
    admin:      'Accès complet à un client spécifique',
    viewer:     'Lecture seule sur un client spécifique',
};

function onRoleChange() {
    const role = document.getElementById('user-role').value;
    document.getElementById('role-hint').textContent = ROLE_HINTS[role] || '';
    document.getElementById('client-group').style.display = role === 'superadmin' ? 'none' : '';
}

function openModal(id) {
    document.getElementById('form-user').reset();
    document.getElementById('user-id').value = '';
    document.getElementById('active-group').style.display = 'none';
    document.getElementById('pwd-hint').style.display = 'none';
    document.getElementById('pwd-label').innerHTML = 'Mot de passe <span class="required">*</span>';
    document.getElementById('user-password').required = true;
    document.getElementById('modal-title').textContent = 'Nouveau compte';
    document.getElementById('user-role').value = 'admin';
    onRoleChange();

    if (id) {
        const u = USERS.find(x => x.id == id);
        if (!u) return;
        document.getElementById('modal-title').textContent = 'Modifier : ' + u.username;
        document.getElementById('user-id').value      = u.id;
        document.getElementById('user-username').value = u.username;
        document.getElementById('user-fullname').value = u.full_name;
        document.getElementById('user-email').value    = u.email;
        document.getElementById('user-role').value     = u.role;
        document.getElementById('user-client').value      = u.client_id;
        document.getElementById('user-auth-source').value = u.auth_source || 'local';
        document.getElementById('user-active').checked = u.active == 1;
        document.getElementById('active-group').style.display = u.id != ME_ID ? '' : 'none';
        document.getElementById('pwd-hint').style.display = '';
        document.getElementById('pwd-label').innerHTML = 'Nouveau mot de passe';
        document.getElementById('user-password').required = false;
        document.getElementById('user-password').value = '';
        onRoleChange();
    }

    Modal.open('modal-user');
}

async function saveUser() {
    const id       = document.getElementById('user-id').value;
    const username = document.getElementById('user-username').value.trim();
    const password = document.getElementById('user-password').value;
    const role     = document.getElementById('user-role').value;
    const clientId = document.getElementById('user-client').value;

    if (!username) { toast('Nom d\'utilisateur requis', 'error'); return; }
    if (!id && !password) { toast('Mot de passe requis', 'error'); return; }
    if (password && password.length < 8) { toast('Mot de passe trop court (8 caractères min)', 'error'); return; }

    const body = {
        username,
        full_name: document.getElementById('user-fullname').value.trim(),
        email:     document.getElementById('user-email').value.trim(),
        role,
        client_id:   role !== 'superadmin' ? clientId : '',
        auth_source: document.getElementById('user-auth-source').value,
        active:    document.getElementById('user-active').checked ? 1 : 0,
    };
    if (password) body.password = password;
    if (id) body.id = id;

    const btn = document.getElementById('btn-save');
    btn.disabled = true;
    try {
        await api(`${APP_URL}/api/users-admin.php`, { method: id ? 'PUT' : 'POST', body });
        toast(id ? 'Compte mis à jour' : 'Compte créé', 'success');
        Modal.close('modal-user');
        setTimeout(() => location.reload(), 700);
    } catch(e) {
        btn.disabled = false;
    }
}

async function resetTotp(id, username) {
    if (!confirm(`Réinitialiser le TOTP de "${username}" ? L'utilisateur devra le reconfigurer.`)) return;
    try {
        await api(`${APP_URL}/api/users-admin.php`, { method: 'PUT', body: { id, totp_reset: true } });
        toast('TOTP réinitialisé', 'success');
        setTimeout(() => location.reload(), 600);
    } catch(e) {}
}

async function deleteUser(id, username) {
    if (!confirm(`Supprimer le compte "${username}" ? Cette action est irréversible.`)) return;
    try {
        await api(`${APP_URL}/api/users-admin.php`, { method: 'DELETE', body: { id } });
        toast('Compte supprimé', 'success');
        setTimeout(() => location.reload(), 600);
    } catch(e) {}
}
</script>
<?php render_footer(); ?>
