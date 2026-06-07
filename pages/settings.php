<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/layout.php';
require_auth();

$client_id = current_client_id();
if (!$client_id) { header('Location: ' . APP_URL . '/'); exit; }

$pdo = db();

// Load global app settings (superadmin only)
$app_settings = [];
if (is_superadmin()) {
    $rows = $pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
    $app_settings = array_column($rows, 'setting_value', 'setting_key');
}

$conventions = [];
foreach (['ALL', 'MAC', 'WIN', 'LIN'] as $os) {
    $stmt = $pdo->prepare('SELECT * FROM naming_conventions WHERE client_id = ? AND os_type = ?');
    $stmt->execute([$client_id, $os]);
    $row = $stmt->fetch();
    $conventions[$os] = $row ?: ['id' => null, 'template' => '{CLIENT}-{OS}-{SEQ:3}', 'os_type' => $os];
}

render_head('Paramètres');
render_icons();
?>
<div class="sidebar-overlay"></div>
<?php render_sidebar('settings'); ?>
<div class="main-wrapper">
<?php render_topbar('Paramètres', 'Configuration du client'); ?>
<main class="main-content" style="max-width:800px">

<!-- ─── TOTP / 2FA — Mon compte (tous les utilisateurs) ─────────────────── -->
<div class="card mb-24" id="card-totp-account">
    <div class="card-header">
        <div>
            <div class="card-title">Double authentification (2FA)</div>
            <div class="card-subtitle">Sécurisez votre compte avec une application TOTP (Authenticator, Aegis, etc.)</div>
        </div>
        <div id="totp-status-badge"></div>
    </div>
    <div class="card-body">
        <div id="totp-account-content">
            <p style="color:var(--text-muted);font-size:13.5px">Chargement…</p>
        </div>
    </div>
</div>

<!-- Modal: Setup TOTP -->
<div class="modal-backdrop" id="modal-totp-setup" style="display:none">
<div class="modal" style="max-width:440px;width:100%">
    <div class="modal-header">
        <h2 class="modal-title">Configurer le TOTP</h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-totp-setup')"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div class="modal-body">
        <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:16px">
            Scannez ce QR code avec votre application (Authenticator, Aegis, Bitwarden…) puis entrez le code généré pour confirmer.
        </p>
        <div style="text-align:center;margin:16px 0">
            <canvas id="totp-qr-canvas" style="border-radius:8px;border:1px solid var(--border)"></canvas>
        </div>
        <div style="background:var(--bg-elevated);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;font-family:monospace;font-size:13px;text-align:center;margin-bottom:16px;word-break:break-all" id="totp-secret-display"></div>
        <div class="form-group" style="margin-bottom:0">
            <label class="form-label">Code de vérification</label>
            <input type="text" id="totp-verify-input" class="form-control"
                inputmode="numeric" maxlength="6" placeholder="000000"
                style="font-family:monospace;font-size:18px;letter-spacing:.15em;text-align:center">
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="Modal.close('modal-totp-setup')">Annuler</button>
        <button class="btn btn-primary" onclick="totpActivate()">
            <svg><use href="#icon-check"/></svg> Activer
        </button>
    </div>
</div>
</div>

<!-- Modal: Backup codes -->
<div class="modal-backdrop" id="modal-totp-backup" style="display:none">
<div class="modal" style="max-width:420px;width:100%">
    <div class="modal-header">
        <h2 class="modal-title">Codes de secours</h2>
        <button class="btn btn-ghost btn-icon" onclick="Modal.close('modal-totp-backup')"><svg><use href="#icon-x"/></svg></button>
    </div>
    <div class="modal-body">
        <div style="background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.3);border-radius:var(--radius-sm);padding:12px 14px;font-size:13px;color:#f5a623;margin-bottom:16px">
            <b>⚠ Copiez ces codes maintenant.</b> Ils ne seront plus affichés. Chaque code ne peut être utilisé qu'une seule fois.
        </div>
        <div id="backup-codes-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px"></div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" onclick="copyBackupCodes()">Copier tout</button>
        <button class="btn btn-primary" onclick="Modal.close('modal-totp-backup')">Compris</button>
    </div>
</div>
</div>


<!-- SITE SETTINGS (superadmin only) -->
<?php if (is_superadmin()): ?>
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Configuration générale</div>
            <div class="card-subtitle">Nom du site et URL serveur utilisés dans l'interface et les scripts agents</div>
        </div>
    </div>
    <div class="card-body">
        <form id="form-site-settings" autocomplete="off">
            <!-- THÈME VISUEL -->
            <div class="form-group" style="margin-bottom:16px">
                <label>Thème visuel</label>
                <div style="display:flex;gap:10px;margin-top:2px">
                    <label id="theme-dark" onclick="selectTheme('dark')" style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:var(--radius-sm);cursor:pointer;border:2px solid <?= APP_THEME==='dark'?'var(--accent)':'var(--border)' ?>;flex:1;transition:border-color var(--transition)">
                        <input type="radio" name="theme" value="dark" <?= APP_THEME==='dark'?'checked':'' ?> style="accent-color:var(--accent);flex-shrink:0">
                        <div>
                            <div style="font-size:13px;font-weight:600">Dark OLED</div>
                            <div style="font-size:11.5px;color:var(--text-muted)">Bleu nuit professionnel</div>
                        </div>
                        <div style="width:22px;height:22px;border-radius:50%;background:#07090f;border:2px solid #4f7ef8;margin-left:auto;flex-shrink:0"></div>
                    </label>
                    <label id="theme-acid" onclick="selectTheme('acid')" style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:var(--radius-sm);cursor:pointer;border:2px solid <?= APP_THEME==='acid'?'#00ff41':'var(--border)' ?>;flex:1;transition:border-color var(--transition)">
                        <input type="radio" name="theme" value="acid" <?= APP_THEME==='acid'?'checked':'' ?> style="accent-color:#00ff41;flex-shrink:0">
                        <div>
                            <div style="font-size:13px;font-weight:600">Acid</div>
                            <div style="font-size:11.5px;color:var(--text-muted)">Noir + vert fluo néon</div>
                        </div>
                        <div style="width:22px;height:22px;border-radius:50%;background:#000;border:2px solid #00ff41;box-shadow:0 0 8px #00ff41;margin-left:auto;flex-shrink:0"></div>
                    </label>
                    <label id="theme-aurora" onclick="selectTheme('aurora')" style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:var(--radius-sm);cursor:pointer;border:2px solid <?= APP_THEME==='aurora'?'#8b5cf6':'var(--border)' ?>;flex:1;transition:border-color var(--transition)">
                        <input type="radio" name="theme" value="aurora" <?= APP_THEME==='aurora'?'checked':'' ?> style="accent-color:#8b5cf6;flex-shrink:0">
                        <div>
                            <div style="font-size:13px;font-weight:600">Aurora</div>
                            <div style="font-size:11.5px;color:var(--text-muted)">Espace × Aurores boréales</div>
                        </div>
                        <div style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#7c3aed,#06b6d4);flex-shrink:0;margin-left:auto"></div>
                    </label>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Nom de l'application <span class="required">*</span></label>
                    <input type="text" name="app_name" id="setting-app-name" class="form-control"
                        value="<?= h($app_settings['app_name'] ?? 'InventorFlow') ?>"
                        placeholder="InventorFlow">
                    <div class="form-hint">Affiché dans la sidebar, les onglets et les scripts</div>
                </div>
                <div class="form-group">
                    <label>URL du serveur <span class="required">*</span></label>
                    <input type="url" name="app_url" id="setting-app-url" class="form-control"
                        value="<?= h($app_settings['app_url'] ?? 'http://localhost:8083') ?>"
                        placeholder="http://localhost:8083"
                        oninput="updateUrlPreview()">
                    <div class="form-hint">Utilisé dans les endpoints agent — sans slash final</div>
                </div>
            </div>
            <div id="url-preview" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--accent-dim);border:1px solid rgba(79,126,248,0.2);border-radius:var(--radius-sm)">
                <svg class="icon-sm" style="color:var(--accent);flex-shrink:0"><use href="#icon-layers"/></svg>
                <div>
                    <div style="font-size:11.5px;color:var(--text-secondary);margin-bottom:2px">Endpoint agent :</div>
                    <code id="url-preview-value" style="font-size:13px;color:var(--accent)"><?= h(($app_settings['app_url'] ?? 'http://localhost:8083') . '/api/register.php') ?></code>
                </div>
            </div>
        </form>
    </div>
    <div class="card-footer">
        <button class="btn btn-secondary" onclick="document.getElementById('form-site-settings').reset()">Annuler</button>
        <button class="btn btn-primary" onclick="saveSiteSettings()">
            <svg><use href="#icon-check"/></svg> Sauvegarder
        </button>
    </div>
</div>
<?php endif; ?>

<!-- NAMING CONVENTIONS -->
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Modèles de nommage</div>
            <div class="card-subtitle">Définissez les modèles utilisés pour suggérer des noms de postes</div>
        </div>
    </div>
    <div class="card-body">

        <div class="card" style="background:var(--bg-elevated);margin-bottom:20px;padding:16px">
            <div style="font-size:13px;font-weight:600;color:var(--text-secondary);margin-bottom:10px">Variables disponibles</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                <?php foreach ([
                    ['{CLIENT}', 'Code client (ex: SAG)'],
                    ['{OS}', 'Type OS (MAC/WIN/LIN)'],
                    ['{DEPT}', 'Code département'],
                    ['{SEQ:3}', 'Séquence 3 chiffres (001, 002…)'],
                    ['{SEQ:4}', 'Séquence 4 chiffres'],
                    ['{YEAR}', 'Année (2024)'],
                    ['{MONTH}', 'Mois (01-12)'],
                ] as [$token, $desc]): ?>
                <div style="display:flex;gap:6px;align-items:center;padding:5px 10px;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm)">
                    <code style="color:var(--accent);font-size:12px"><?= h($token) ?></code>
                    <span style="color:var(--text-muted);font-size:11.5px"><?= h($desc) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <form id="form-naming">
        <?php foreach (['ALL' => 'Tous les OS (défaut)', 'MAC' => 'macOS', 'WIN' => 'Windows', 'LIN' => 'Linux'] as $os => $label):
            $conv = $conventions[$os];
            $color = match($os) { 'MAC' => 'var(--os-mac)', 'WIN' => 'var(--os-win)', 'LIN' => 'var(--os-lin)', default => 'var(--accent)' };
        ?>
        <div style="padding:16px;border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                <?php if ($os !== 'ALL'): ?>
                <span class="badge badge-<?= strtolower($os) ?>"><?= h($os) ?></span>
                <?php endif; ?>
                <span style="font-weight:600;font-size:13.5px;color:var(--text-primary)"><?= h($label) ?></span>
            </div>
            <input type="hidden" name="conventions[<?= $os ?>][id]" value="<?= h($conv['id'] ?? '') ?>">
            <input type="hidden" name="conventions[<?= $os ?>][os_type]" value="<?= h($os) ?>">
            <div style="display:flex;gap:8px;align-items:center">
                <input type="text" name="conventions[<?= $os ?>][template]"
                    class="form-control" style="font-family:monospace"
                    value="<?= h($conv['template']) ?>"
                    placeholder="{CLIENT}-{OS}-{SEQ:3}"
                    oninput="updatePreview(this, '<?= $os ?>')">
                <button type="button" class="btn btn-secondary" onclick="resetSeq('<?= $os ?>')" title="Remettre la séquence à zéro">
                    <svg><use href="#icon-refresh"/></svg>
                </button>
            </div>
            <div id="preview-<?= $os ?>" style="margin-top:8px"></div>
        </div>
        <?php endforeach; ?>

        <input type="hidden" name="client_id" value="<?= $client_id ?>">
        </form>
    </div>
    <div class="card-footer">
        <button class="btn btn-secondary" onclick="resetForms()">Annuler</button>
        <button class="btn btn-primary" onclick="saveNaming()"><svg><use href="#icon-check"/></svg> Sauvegarder</button>
    </div>
</div>

<!-- DANGER ZONE -->
<!-- CHANGE PASSWORD -->
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Changer le mot de passe</div>
            <div class="card-subtitle">Compte : <?= h(current_user()['username']) ?></div>
        </div>
    </div>
    <div class="card-body">
        <form id="form-pwd" autocomplete="off">
            <div class="form-group">
                <label>Mot de passe actuel <span class="required">*</span></label>
                <input type="password" id="pwd-current" class="form-control" required autocomplete="current-password">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Nouveau mot de passe <span class="required">*</span></label>
                    <input type="password" id="pwd-new" class="form-control" required minlength="8" autocomplete="new-password" oninput="checkPwdStrength()">
                    <div id="pwd-strength" style="margin-top:6px;height:4px;border-radius:4px;background:var(--bg-elevated);overflow:hidden">
                        <div id="pwd-strength-bar" style="height:100%;width:0%;transition:width 300ms,background 300ms;border-radius:4px"></div>
                    </div>
                    <div id="pwd-strength-label" class="form-hint"></div>
                </div>
                <div class="form-group">
                    <label>Confirmer <span class="required">*</span></label>
                    <input type="password" id="pwd-confirm" class="form-control" required autocomplete="new-password">
                </div>
            </div>
        </form>
    </div>
    <div class="card-footer">
        <button class="btn btn-primary" onclick="changePassword()">
            <svg><use href="#icon-key"/></svg> Changer le mot de passe
        </button>
    </div>
</div>

<?php if (is_superadmin()): ?>
<div class="card" style="border-color:rgba(255,71,87,0.25)">
    <div class="card-header">
        <div class="card-title" style="color:var(--danger)">Zone dangereuse</div>
    </div>
    <div class="card-body">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0">
            <div>
                <div style="font-weight:600;font-size:13.5px">Réinitialiser les séquences</div>
                <div class="form-hint">Remet tous les compteurs de noms à zéro. Les noms existants ne sont pas modifiés.</div>
            </div>
            <button class="btn btn-danger" onclick="resetAllSeq()">Réinitialiser</button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- EMAIL NOTIFICATIONS -->
<?php if (is_superadmin()): ?>
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Notifications email</div>
            <div class="card-subtitle">Alertes backup — Gmail, Outlook ou SMTP custom</div>
        </div>
    </div>
    <div class="card-body">

        <!-- Presets fournisseurs -->
        <div style="display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap">
            <button type="button" class="btn btn-ghost btn-sm" onclick="smtpPreset('gmail')">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M24 5.457v13.909c0 .904-.732 1.636-1.636 1.636h-3.819V11.73L12 16.64l-6.545-4.91v9.273H1.636A1.636 1.636 0 0 1 0 19.366V5.457c0-2.023 2.309-3.178 3.927-1.964L5.455 4.64 12 9.548l6.545-4.91 1.528-1.145C21.69 2.28 24 3.434 24 5.457z"/></svg>
                Gmail
            </button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="smtpPreset('outlook')">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7.88 12.04q0 .45-.11.87-.1.41-.33.74-.22.33-.58.52-.37.2-.87.2t-.85-.2q-.35-.21-.57-.55-.22-.33-.33-.75-.1-.42-.1-.86t.1-.87q.1-.43.34-.76.22-.34.59-.54.36-.2.87-.2t.86.2q.35.21.57.55.22.34.31.77.1.43.1.88zM24 12v9.38q0 .46-.33.8-.33.32-.8.32H7.13q-.46 0-.8-.33-.32-.33-.32-.8V18H1q-.41 0-.7-.3-.3-.29-.3-.7V7q0-.41.3-.7Q.58 6 1 6h6.1V2.55q0-.44.3-.75.3-.3.75-.3h12.9q.44 0 .75.3.3.3.3.75V10.85l1.24.72h.01q.1.07.18.18.07.12.07.25zm-6-8.25v3h3v-3zm0 4.5v3h3v-3zm0 4.5v1.83l3.05-1.83zm-5.25-9v3h3.75v-3zm0 4.5v3h3.75v-3zm0 4.5v2.03l2.41 1.47H12.75zm-3.75-9v3H12v-3zm0 4.5v3H12v-3zm0 4.5v3H12v-3z"/></svg>
                Outlook / Hotmail
            </button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="smtpPreset('brevo')" style="color:#0092ff">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M19.2 0H4.8A4.8 4.8 0 0 0 0 4.8v14.4A4.8 4.8 0 0 0 4.8 24h14.4A4.8 4.8 0 0 0 24 19.2V4.8A4.8 4.8 0 0 0 19.2 0zm-3.6 15.6c0 1.99-1.61 3.6-3.6 3.6H6.6V4.8h5.4c1.79 0 3.24 1.45 3.24 3.24 0 .87-.34 1.66-.9 2.25.82.62 1.36 1.59 1.36 2.71v2.6z"/></svg>
                Brevo (300/j gratuit)
            </button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="smtpPreset('custom')" style="color:var(--text-muted)">
                <svg><use href="#icon-settings"/></svg>
                SMTP custom
            </button>
        </div>

        <!-- Guide app password -->
        <div id="smtp-guide" style="display:none;margin-bottom:16px;padding:14px;background:var(--accent-dim);border:1px solid rgba(79,126,248,.2);border-radius:var(--radius-sm);font-size:13px;line-height:1.7;color:var(--text-secondary)"></div>

        <form id="form-email-settings">
        <div class="form-row">
            <div class="form-group">
                <label>Email destinataire (admin) <span class="required">*</span></label>
                <input type="email" name="admin_email" class="form-control"
                    value="<?= h($app_settings['admin_email'] ?? '') ?>"
                    placeholder="admin@domaine.fr">
                <div class="form-hint">Reçoit les alertes de backup</div>
            </div>
            <div class="form-group">
                <label>Nom expéditeur</label>
                <input type="text" name="smtp_from_name" class="form-control"
                    value="<?= h($app_settings['smtp_from_name'] ?? 'InventorFlow') ?>"
                    placeholder="InventorFlow">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group" style="flex:2">
                <label>Serveur SMTP <span class="required">*</span></label>
                <input type="text" name="smtp_host" id="smtp_host" class="form-control"
                    value="<?= h($app_settings['smtp_host'] ?? '') ?>"
                    placeholder="smtp.gmail.com">
            </div>
            <div class="form-group" style="flex:0 0 110px">
                <label>Port</label>
                <input type="number" name="smtp_port" id="smtp_port" class="form-control"
                    value="<?= h($app_settings['smtp_port'] ?? '587') ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Compte SMTP <span class="required">*</span></label>
                <input type="email" name="smtp_user" id="smtp_user" class="form-control"
                    value="<?= h($app_settings['smtp_user'] ?? '') ?>"
                    placeholder="votre@gmail.com">
            </div>
            <div class="form-group">
                <label>Mot de passe / App Password <span class="required">*</span></label>
                <input type="password" name="smtp_pass" class="form-control"
                    value="<?= h($app_settings['smtp_pass'] ?? '') ?>"
                    placeholder="xxxx xxxx xxxx xxxx"
                    autocomplete="new-password">
            </div>
        </div>
        <label style="display:flex;align-items:center;gap:10px;font-size:13.5px;cursor:pointer;padding:8px 0">
            <input type="checkbox" name="notif_backup_error" value="1" style="accent-color:var(--accent)"
                <?= !empty($app_settings['notif_backup_error']) ? 'checked' : '' ?>>
            Alerte email en cas d'erreur de backup
        </label>
        </form>
    </div>
    <div class="card-footer" style="display:flex;align-items:center;gap:10px">
        <button class="btn btn-secondary btn-sm" onclick="testEmail()" id="btn-test-email">
            <svg><use href="#icon-wifi"/></svg> Tester l'envoi
        </button>
        <div style="flex:1"></div>
        <button class="btn btn-secondary" onclick="document.getElementById('form-email-settings').reset()">Annuler</button>
        <button class="btn btn-primary" onclick="saveEmailSettings()">
            <svg><use href="#icon-check"/></svg> Sauvegarder
        </button>
    </div>
</div>
<?php endif; ?>


<!-- ─── TOTP POLICY (superadmin) ─────────────────────────────────────────── -->
<?php if (is_superadmin()): ?>
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Politique TOTP globale</div>
            <div class="card-subtitle">Définit si le TOTP est requis pour se connecter</div>
        </div>
    </div>
    <div class="card-body">
        <?php $totp_policy = app_setting('totp_policy','disabled'); ?>
        <div style="display:flex;flex-direction:column;gap:10px" id="totp-policy-group">
            <?php foreach ([
                ['disabled',     'Désactivé',            'Aucun utilisateur ne doit configurer le TOTP.'],
                ['optional',     'Optionnel',             'Chaque utilisateur peut activer le TOTP depuis ses paramètres. S\'il est activé, il sera requis à la connexion.'],
                ['required_all', 'Obligatoire pour tous', 'Tout utilisateur ayant configuré son TOTP devra le saisir. Les comptes sans TOTP peuvent se connecter mais un bandeau les incite à le configurer.'],
            ] as [$val, $label, $desc]): ?>
            <label style="display:flex;align-items:flex-start;gap:12px;padding:14px;border:2px solid <?= $totp_policy===$val?'var(--accent)':'var(--border)' ?>;border-radius:var(--radius-sm);cursor:pointer;transition:border-color .15s" id="totp-opt-<?= $val ?>">
                <input type="radio" name="totp_policy" value="<?= $val ?>" <?= $totp_policy===$val?'checked':'' ?> style="margin-top:3px;accent-color:var(--accent);flex-shrink:0" onchange="highlightTotpPolicy()">
                <div>
                    <div style="font-weight:600;font-size:14px"><?= $label ?></div>
                    <div style="font-size:13px;color:var(--text-secondary);margin-top:3px"><?= $desc ?></div>
                </div>
            </label>
            <?php endforeach; ?>
        </div>
        <div style="margin-top:16px">
            <button class="btn btn-primary" onclick="saveTotpPolicy()">
                <svg><use href="#icon-check"/></svg> Enregistrer la politique
            </button>
        </div>
    </div>
</div>

<!-- ─── LDAP / LDAPS ─────────────────────────────────────────────────────── -->
<div class="card mb-24">
    <div class="card-header">
        <div>
            <div class="card-title">Annuaire LDAP / LDAPS</div>
            <div class="card-subtitle">Authentifiez les utilisateurs via un Active Directory ou OpenLDAP</div>
        </div>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;font-weight:600">
            <input type="checkbox" id="ldap_enabled" value="1" <?= app_setting('ldap_enabled','0')==='1'?'checked':'' ?> style="accent-color:var(--accent);width:16px;height:16px">
            Activer LDAP
        </label>
    </div>
    <div class="card-body" id="ldap-config-body" style="<?= app_setting('ldap_enabled','0')!=='1'?'opacity:.45;pointer-events:none':'' ?>">
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">Hôte <span class="required">*</span></label>
                <input type="text" id="ldap_host" class="form-control" placeholder="ldap.exemple.com" value="<?= h(app_setting('ldap_host')) ?>">
            </div>
            <div class="form-group" style="max-width:120px">
                <label class="form-label">Port</label>
                <input type="number" id="ldap_port" class="form-control" value="<?= h(app_setting('ldap_port','389')) ?>" min="1" max="65535">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Base DN <span class="required">*</span></label>
            <input type="text" id="ldap_base_dn" class="form-control" placeholder="dc=exemple,dc=com" value="<?= h(app_setting('ldap_base_dn')) ?>">
        </div>
        <div class="form-row">
            <div class="form-group">
                <label class="form-label">DN service account</label>
                <input type="text" id="ldap_bind_dn" class="form-control" placeholder="cn=binduser,dc=exemple,dc=com" value="<?= h(app_setting('ldap_bind_dn')) ?>">
                <div class="form-hint">Optionnel — nécessaire pour chercher le DN utilisateur</div>
            </div>
            <div class="form-group">
                <label class="form-label">Mot de passe service account</label>
                <input type="password" id="ldap_bind_pass" class="form-control" placeholder="••••••••" value="<?= h(app_setting('ldap_bind_pass')) ?>">
            </div>
        </div>
        <div class="form-group">
            <label class="form-label">Filtre utilisateur</label>
            <input type="text" id="ldap_user_filter" class="form-control" value="<?= h(app_setting('ldap_user_filter','(sAMAccountName={username})')) ?>">
            <div class="form-hint"><code>{username}</code> est remplacé par l'identifiant saisi. Exemples : <code>(uid={username})</code> pour OpenLDAP, <code>(sAMAccountName={username})</code> pour AD</div>
        </div>

        <div style="border-top:1px solid var(--border-subtle);padding-top:18px;margin-top:6px">
            <div style="font-weight:600;font-size:14px;margin-bottom:12px">Sécurité TLS</div>
            <div style="display:flex;flex-direction:column;gap:10px">
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
                    <input type="checkbox" id="ldap_use_tls" value="1" <?= app_setting('ldap_use_tls','0')==='1'?'checked':'' ?> style="accent-color:var(--accent);width:15px;height:15px" onchange="onLdapTlsChange()">
                    <span><b>StartTLS</b> — chiffrer la connexion après négociation (port 389)</span>
                </label>
                <div class="form-hint" style="margin-top:-6px">Pour LDAPS natif, utilisez le port <b>636</b> — StartTLS sera ignoré.</div>
                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px">
                    <input type="checkbox" id="ldap_cert_verify" value="1" <?= app_setting('ldap_cert_verify','1')!=='0'?'checked':'' ?> style="accent-color:var(--accent);width:15px;height:15px" onchange="onLdapCertVerifyChange()">
                    <span>Vérifier le certificat TLS</span>
                </label>
            </div>

            <div id="ldap-cert-block" style="margin-top:14px;<?= (app_setting('ldap_cert_verify','1')==='0')?'display:none':'' ?>">
                <label class="form-label">Certificat CA (PEM) — facultatif</label>
                <textarea id="ldap_cert_content" class="form-control" rows="5"
                    style="font-family:monospace;font-size:12px;resize:vertical"
                    placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"><?= h(app_setting('ldap_cert_content')) ?></textarea>
                <div class="form-hint" style="margin-top:4px">
                    Collez le certificat PEM de votre CA, ou laissez vide si votre certificat est signé par une CA reconnue.
                    <label style="color:var(--accent);cursor:pointer;margin-left:8px" for="ldap-cert-file">Importer un fichier…</label>
                    <input type="file" id="ldap-cert-file" accept=".pem,.crt,.cer" style="display:none" onchange="importCertFile(this)">
                </div>
                <div id="ldap-auto-cert-btn" style="margin-top:8px;display:none">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="autoFetchCert()">
                        <svg width="14" height="14"><use href="#icon-wifi"/></svg>
                        Récupérer le certificat automatiquement
                    </button>
                </div>
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:20px;flex-wrap:wrap">
            <button class="btn btn-primary" onclick="saveLdapSettings()">
                <svg><use href="#icon-check"/></svg> Enregistrer
            </button>
            <button class="btn btn-ghost" onclick="testLdap()" id="btn-test-ldap">
                <svg><use href="#icon-wifi"/></svg> Tester la connexion
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

</main>

</div>

<script>
const APP_URL = '<?= APP_URL ?>';
const CLIENT_ID = <?= $client_id ?>;

// ── SITE SETTINGS ────────────────────────────────────────

// ── CHANGE PASSWORD ────────────────────────────────────────
function checkPwdStrength() {
    const pwd = document.getElementById('pwd-new').value;
    const bar   = document.getElementById('pwd-strength-bar');
    const label = document.getElementById('pwd-strength-label');
    let score = 0;
    if (pwd.length >= 8)  score++;
    if (pwd.length >= 12) score++;
    if (/[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^A-Za-z0-9]/.test(pwd)) score++;
    const levels = [
        {pct:0,   color:'var(--danger)',  text:''},
        {pct:20,  color:'var(--danger)',  text:'Très faible'},
        {pct:40,  color:'var(--warning)', text:'Faible'},
        {pct:60,  color:'var(--warning)', text:'Moyen'},
        {pct:80,  color:'var(--success)', text:'Fort'},
        {pct:100, color:'var(--success)', text:'Très fort'},
    ];
    const l = levels[score] || levels[0];
    bar.style.width   = l.pct + '%';
    bar.style.background = l.color;
    label.textContent = l.text;
    label.style.color = l.color;
}

async function changePassword() {
    const current = document.getElementById('pwd-current').value;
    const newPwd  = document.getElementById('pwd-new').value;
    const confirm = document.getElementById('pwd-confirm').value;
    if (!current || !newPwd) { toast('Remplissez tous les champs', 'warning'); return; }
    if (newPwd !== confirm)  { toast('Les mots de passe ne correspondent pas', 'error'); return; }
    if (newPwd.length < 8)  { toast('Minimum 8 caractères', 'warning'); return; }
    try {
        await api(APP_URL + '/api/change-password.php', {
            method: 'POST',
            body: { current_password: current, new_password: newPwd }
        });
        toast('Mot de passe changé avec succès', 'success');
        document.getElementById('form-pwd').reset();
        document.getElementById('pwd-strength-bar').style.width = '0%';
        document.getElementById('pwd-strength-label').textContent = '';
    } catch(e) {}
}

function updateUrlPreview() {
    const url = document.getElementById('setting-app-url')?.value.trim().replace(/\/$/, '') || '';
    const preview = document.getElementById('url-preview-value');
    if (preview) preview.textContent = url + '/api/register.php';
}

function selectTheme(t) {
    document.querySelectorAll('[name=theme]').forEach(r => r.checked = r.value===t);
    // Preview immediately — set on both html and force style recalc
    document.documentElement.setAttribute('data-theme', t);
    document.documentElement.dataset.theme = t;
    localStorage.setItem('if_theme', t); // persist for instant apply on next load
    document.querySelectorAll('label[onclick^=selectTheme]').forEach(lbl => {
        const isActive = lbl.getAttribute('onclick').includes("'"+t+"'");
        lbl.style.borderColor = isActive ? (t==='acid'?'#00e639':t==='aurora'?'#8b5cf6':'var(--accent)') : 'var(--border)';
    });
}

async function saveSiteSettings() {
    const form = document.getElementById('form-site-settings');
    if (!form) return;
    const data = Object.fromEntries(new FormData(form));
    data.app_url = data.app_url.trim().replace(/\/$/, '');

    try {
        await api(`${APP_URL}/api/settings.php`, { method: 'POST', body: data });
        const savedTheme = data.theme || 'dark';
        localStorage.setItem('if_theme', savedTheme);
        toast('Paramètres sauvegardés', 'success');
        setTimeout(() => { window.location.href = window.location.href.split('?')[0]+'?t='+Date.now(); }, 800);
    } catch(e) {}
}

// ── NAMING CONVENTIONS ────────────────────────────────────
function updatePreview(input, os) {
    const preview = document.getElementById('preview-' + os);
    const tpl = input.value.trim();
    if (!tpl) { preview.textContent = ''; return; }
    const sample = tpl
        .replace('{CLIENT}', 'CLI')
        .replace('{OS}', os === 'ALL' ? 'WIN' : os)
        .replace('{DEPT}', 'IT')
        .replace(/\{SEQ:(\d+)\}/g, (_, n) => '0'.repeat(n - 1) + '1')
        .replace('{SEQ}', '1')
        .replace('{YEAR}', new Date().getFullYear())
        .replace('{MONTH}', String(new Date().getMonth() + 1).padStart(2, '0'));

    const wrap = document.createElement('div');
    wrap.className = 'hostname-preview';
    const label = document.createElement('span');
    label.className = 'label';
    label.textContent = 'Aperçu :';
    const val = document.createElement('span');
    val.className = 'value';
    val.textContent = sample.toUpperCase();
    wrap.appendChild(label);
    wrap.appendChild(val);
    preview.textContent = '';
    preview.appendChild(wrap);
}

async function saveNaming() {
    const form = document.getElementById('form-naming');
    const data = Object.fromEntries(new FormData(form));
    try {
        await api(`${APP_URL}/api/naming.php`, { method: 'POST', body: data });
        toast('Modèles sauvegardés', 'success');
    } catch(e) {}
}

async function resetSeq(os) {
    if (!confirm('Remettre la séquence ' + os + ' à zéro ?')) return;
    try {
        await api(`${APP_URL}/api/naming.php`, { method: 'PUT', body: { action: 'reset_seq', os_type: os, client_id: CLIENT_ID } });
        toast('Séquence ' + os + ' réinitialisée', 'success');
    } catch(e) {}
}

async function resetAllSeq() {
    if (!confirm('Remettre TOUTES les séquences à zéro ?')) return;
    try {
        await api(`${APP_URL}/api/naming.php`, { method: 'PUT', body: { action: 'reset_all', client_id: CLIENT_ID } });
        toast('Toutes les séquences réinitialisées', 'success');
    } catch(e) {}
}

function resetForms() {
    location.reload();
}

// Init previews
document.querySelectorAll('[name^="conventions"][name$="[template]"]').forEach(input => {
    const os = input.name.match(/conventions\[(\w+)\]/)?.[1];
    if (os) updatePreview(input, os);
});

// ── EMAIL SETTINGS ───────────────────────────────────────
const SMTP_PRESETS = {
    gmail:   { host:'smtp.gmail.com',        port:587,
               guide:'<b>Gmail — App Password</b><br>1. Activez la <b>validation en 2 étapes</b> sur votre compte Google<br>2. Allez dans <b>Compte Google → Sécurité → Mots de passe des applications</b><br>3. Créez un mot de passe pour "Messagerie" → copiez les 16 caractères<br>4. Collez-le dans le champ Mot de passe ci-dessous' },
    outlook: { host:'smtp.office365.com',    port:587,
               guide:'<b>Outlook / Hotmail — App Password</b><br>1. Activez la <b>vérification en deux étapes</b> sur votre compte Microsoft<br>2. Allez dans <b>Sécurité → Options avancées → Créer un mot de passe d\'application</b><br>3. Copiez le mot de passe généré et collez-le dans le champ Mot de passe ci-dessous' },
    brevo:   { host:'smtp-relay.brevo.com',    port:587,
               guide:'<b>Brevo (ex-Sendinblue) — 300 emails/jour gratuits</b><br>1. Créez un compte gratuit sur <b>brevo.com</b><br>2. Allez dans <b>Mon compte → SMTP & API → Clés SMTP</b><br>3. Créez une clé SMTP → copiez la valeur<br>4. <b>Utilisateur SMTP</b> = votre email Brevo<br>5. <b>Mot de passe</b> = la clé SMTP générée (commence par xkeysib-...)<br><br>✅ Compatible MFA — la clé SMTP est indépendante du mot de passe du compte' },
    custom:  { host:'', port:587, guide:'' },
};

function smtpPreset(p) {
    const pr = SMTP_PRESETS[p];
    document.getElementById('smtp_host').value = pr.host;
    document.getElementById('smtp_port').value = pr.port;
    const guide = document.getElementById('smtp-guide');
    if (pr.guide) {
        guide.style.display = '';
        // Safe HTML for static trusted content
        guide.innerHTML = pr.guide;
    } else {
        guide.style.display = 'none';
    }
}

async function saveEmailSettings() {
    const fd = new FormData(document.getElementById('form-email-settings'));
    const data = {};
    ['admin_email','smtp_from_name','smtp_host','smtp_port','smtp_user','smtp_pass'].forEach(k => {
        data[k] = fd.get(k) || '';
    });
    data.notif_backup_error = fd.get('notif_backup_error') ? '1' : '0';
    try {
        await api(`${APP_URL}/api/settings.php`, {method:'POST', body:data});
        toast('Paramètres email sauvegardés', 'success');
    } catch(e) {}
}

async function testEmail() {
    const email = document.querySelector('[name="admin_email"]').value;
    if (!email) { toast('Renseignez l\'email destinataire', 'warning'); return; }
    const btn = document.getElementById('btn-test-email');
    btn.disabled = true; btn.textContent = 'Envoi…';
    try {
        await api(`${APP_URL}/api/backup.php`, {method:'POST', body:{action:'test_email', to: email}});
        toast('Email de test envoyé à ' + email, 'success');
    } catch(e) { /* error already toasted */ }
    finally { btn.disabled = false; btn.innerHTML = '<svg><use href="#icon-wifi"/></svg> Tester l\'envoi'; }
}


// ── TOTP ACCOUNT ─────────────────────────────────────────
let _totpSetupUri = '';

async function initTotpAccount() {
    try {
        const r = await api(`${APP_URL}/api/totp.php?action=status`, {method:'GET'});
        const d = r.data;
        const badge = document.getElementById('totp-status-badge');
        const body  = document.getElementById('totp-account-content');
        if (d.enabled) {
            badge.innerHTML = '<span class="badge badge-active">Activé</span>';
            body.innerHTML = `
            <p style="font-size:14px;color:var(--text-secondary);margin-bottom:16px">
                Le TOTP est actif sur votre compte. Vous serez invité à entrer votre code à chaque connexion.
            </p>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="btn btn-ghost" onclick="totpRegenBackup()">
                    <svg width="16" height="16"><use href="#icon-refresh"/></svg> Régénérer les codes de secours
                </button>
                <button class="btn btn-ghost" onclick="totpDisableSelf()" style="color:var(--danger);border-color:rgba(255,71,87,.3)">
                    Désactiver le TOTP
                </button>
            </div>`;
        } else {
            badge.innerHTML = '<span class="badge badge-retired">Désactivé</span>';
            const policyMsg = d.policy === 'required_all'
                ? '<div style="background:rgba(245,166,35,.1);border:1px solid rgba(245,166,35,.3);border-radius:var(--radius-sm);padding:10px 14px;font-size:13px;color:#f5a623;margin-bottom:14px">⚠ La politique globale exige que vous configuriez le TOTP.</div>'
                : '';
            body.innerHTML = `${policyMsg}
            <p style="font-size:14px;color:var(--text-secondary);margin-bottom:16px">
                Activez la double authentification pour sécuriser votre compte.
            </p>
            <button class="btn btn-primary" onclick="totpSetup()">
                <svg><use href="#icon-lock"/></svg> Configurer le TOTP
            </button>`;
        }
    } catch(e) {}
}

async function totpSetup() {
    try {
        const r = await api(`${APP_URL}/api/totp.php?action=setup`, {method:'GET'});
        const {secret, uri} = r.data;
        _totpSetupUri = uri;
        document.getElementById('totp-secret-display').textContent = secret;
        document.getElementById('totp-verify-input').value = '';
        Modal.open('modal-totp-setup');
        // QR code via qrious
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js';
        script.onload = () => {
            new QRious({
                element: document.getElementById('totp-qr-canvas'),
                value: uri, size: 200,
                background: '#0d1220', foreground: '#e8edf8', level: 'M'
            });
        };
        if (!window.QRious) document.head.appendChild(script);
        else script.onload();
    } catch(e) {}
}

async function totpActivate() {
    const code = document.getElementById('totp-verify-input').value.replace(/\D/g,'');
    if (code.length !== 6) { toast('Entrez un code à 6 chiffres', 'warning'); return; }
    try {
        const r = await api(`${APP_URL}/api/totp.php`, {method:'POST', body:{action:'activate', code}});
        Modal.close('modal-totp-setup');
        showBackupCodes(r.data.backup_codes);
        toast('TOTP activé avec succès', 'success');
        setTimeout(() => initTotpAccount(), 1200);
    } catch(e) {}
}

async function totpDisableSelf() {
    if (!confirm('Désactiver le TOTP ? Votre compte sera moins sécurisé.')) return;
    try {
        await api(`${APP_URL}/api/totp.php`, {method:'POST', body:{action:'disable'}});
        toast('TOTP désactivé', 'success');
        initTotpAccount();
    } catch(e) {}
}

async function totpRegenBackup() {
    if (!confirm('Régénérer les codes de secours ? Les anciens seront invalidés.')) return;
    try {
        const r = await api(`${APP_URL}/api/totp.php`, {method:'POST', body:{action:'regen-backup'}});
        showBackupCodes(r.data.backup_codes);
    } catch(e) {}
}

function showBackupCodes(codes) {
    const grid = document.getElementById('backup-codes-grid');
    grid.innerHTML = codes.map(c =>
        `<div style="font-family:monospace;font-size:13px;background:var(--bg-elevated);border:1px solid var(--border);border-radius:6px;padding:8px 10px;text-align:center">${c}</div>`
    ).join('');
    Modal.open('modal-totp-backup');
}

function copyBackupCodes() {
    const codes = [...document.querySelectorAll('#backup-codes-grid div')].map(d => d.textContent).join('\n');
    navigator.clipboard.writeText(codes).then(() => toast('Codes copiés', 'success'));
}

document.getElementById('totp-verify-input')?.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g,'').slice(0,6);
    if (this.value.length === 6) totpActivate();
});

// ── TOTP POLICY ─────────────────────────────────────────
function highlightTotpPolicy() {
    document.querySelectorAll('[id^="totp-opt-"]').forEach(el => {
        const r = el.querySelector('input[type=radio]');
        el.style.borderColor = r.checked ? 'var(--accent)' : 'var(--border)';
    });
}

async function saveTotpPolicy() {
    const val = document.querySelector('[name="totp_policy"]:checked')?.value;
    if (!val) return;
    try {
        await api(`${APP_URL}/api/settings.php`, {method:'POST', body:{totp_policy: val}});
        toast('Politique TOTP enregistrée', 'success');
    } catch(e) {}
}

// ── LDAP ─────────────────────────────────────────────────
document.getElementById('ldap_enabled')?.addEventListener('change', function() {
    const body = document.getElementById('ldap-config-body');
    body.style.opacity = this.checked ? '1' : '.45';
    body.style.pointerEvents = this.checked ? '' : 'none';
});

function onLdapTlsChange() {
    const tls  = document.getElementById('ldap_use_tls').checked;
    const port = parseInt(document.getElementById('ldap_port').value, 10);
    const autoCert = document.getElementById('ldap-auto-cert-btn');
    if (autoCert) autoCert.style.display = (tls || port === 636) ? '' : 'none';
}

function onLdapCertVerifyChange() {
    const verify = document.getElementById('ldap_cert_verify').checked;
    document.getElementById('ldap-cert-block').style.display = verify ? '' : 'none';
}

function importCertFile(input) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => { document.getElementById('ldap_cert_content').value = e.target.result.trim(); };
    reader.readAsText(file);
}

async function autoFetchCert() {
    const host = document.getElementById('ldap_host').value.trim();
    const port = document.getElementById('ldap_port').value.trim() || '636';
    if (!host) { toast('Renseignez l\'hôte LDAP d\'abord', 'warning'); return; }
    toast('Récupération du certificat via le serveur…', 'info');
    try {
        const r = await api(`${APP_URL}/api/ldap-test.php?fetch_cert=1&host=${encodeURIComponent(host)}&port=${encodeURIComponent(port)}`, {method:'GET'});
        if (r.data?.cert) {
            document.getElementById('ldap_cert_content').value = r.data.cert;
            toast('Certificat récupéré', 'success');
        }
    } catch(e) {}
}

async function saveLdapSettings() {
    const data = {
        ldap_enabled:     document.getElementById('ldap_enabled').checked ? '1' : '0',
        ldap_host:        document.getElementById('ldap_host').value.trim(),
        ldap_port:        document.getElementById('ldap_port').value.trim(),
        ldap_base_dn:     document.getElementById('ldap_base_dn').value.trim(),
        ldap_bind_dn:     document.getElementById('ldap_bind_dn').value.trim(),
        ldap_bind_pass:   document.getElementById('ldap_bind_pass').value,
        ldap_user_filter: document.getElementById('ldap_user_filter').value.trim(),
        ldap_use_tls:     document.getElementById('ldap_use_tls').checked ? '1' : '0',
        ldap_cert_verify: document.getElementById('ldap_cert_verify').checked ? '1' : '0',
        ldap_cert_content: document.getElementById('ldap_cert_content').value.trim(),
    };
    try {
        await api(`${APP_URL}/api/settings.php`, {method:'POST', body: data});
        toast('Configuration LDAP sauvegardée', 'success');
    } catch(e) {}
}

async function testLdap() {
    const btn = document.getElementById('btn-test-ldap');
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = '<svg><use href="#icon-wifi"/></svg> Test en cours…';
    // Save first so test uses current values
    await saveLdapSettings().catch(() => {});
    try {
        const r = await api(`${APP_URL}/api/ldap-test.php`, {method:'GET'});
        toast(r.message || 'Connexion réussie', 'success');
    } catch(e) {}
    finally { btn.disabled = false; btn.innerHTML = orig; }
}

// Init on load (after app.js defines window.api)
if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initTotpAccount);
} else {
    initTotpAccount();
}

</script>
<?php render_footer(); ?>
