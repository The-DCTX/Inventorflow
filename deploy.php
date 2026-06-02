<?php
/**
 * InventorFlow — Dynamic agent installer
 * Embeds the agent script directly — no secondary download needed.
 *
 * Usage:
 *   sudo bash -c "$(curl -fsSL 'http://server/deploy.php?key=if_xxx')"          # macOS
 *   sudo bash <(curl -fsSL "http://server/deploy.php?key=if_xxx&dept=IT&cron=1") # Linux
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$api_key = trim($_GET['key'] ?? '');
$dept    = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['dept'] ?? '')));
$cron    = ($_GET['cron'] ?? '0') === '1';

if (!$api_key) {
    http_response_code(400);
    echo "#!/usr/bin/env bash\necho 'ERROR: Missing API key'\nexit 1\n";
    exit;
}

$key_hash = hash('sha256', $api_key);
$stmt = db()->prepare('SELECT k.*, c.name as client_name FROM api_keys k JOIN clients c ON k.client_id = c.id WHERE k.key_hash = ? AND k.active = 1 LIMIT 1');
$stmt->execute([$key_hash]);
$key_row = $stmt->fetch();

if (!$key_row) {
    http_response_code(401);
    echo "#!/usr/bin/env bash\necho 'ERROR: Invalid or inactive API key'\nexit 1\n";
    exit;
}

$settings = db()->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
$settings_map = array_column($settings, 'setting_value', 'setting_key');
$app_name    = $settings_map['app_name'] ?? 'InventorFlow';

// Use the actual request host so the agent phones home to the same server it was deployed from.
// This works correctly whether accessed via IP, hostname or domain.
// Honour the reverse-proxy scheme (X-Forwarded-Proto) so an agent deployed via HTTPS
// phones home in HTTPS — otherwise curl -L follows the 301 http->https and drops the POST body.
$proto      = (
       (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on')
    || (($_SERVER['SERVER_PORT'] ?? '') == 443)
) ? 'https' : 'http';
$server_url = rtrim($proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8083'), '/');
$client_name = $key_row['client_name'];

db()->prepare('UPDATE api_keys SET last_used = NOW(), used_count = used_count + 1 WHERE id = ?')
    ->execute([$key_row['id']]);

// ── WINDOWS : retourne un installeur PowerShell ───────────────
$os = strtolower(trim($_GET['os'] ?? 'linux'));
if ($os === 'win') {
    $ps_agent_path = __DIR__ . '/agent/agent.ps1';
    if (!file_exists($ps_agent_path)) {
        http_response_code(500);
        echo "Write-Error 'ERROR: Windows agent not found on server'"; exit;
    }
    $ps_agent = file_get_contents($ps_agent_path);
    $ps_agent = str_replace('PLACEHOLDER_SERVER', addslashes($server_url), $ps_agent);
    $ps_agent = str_replace('PLACEHOLDER_KEY',    addslashes($api_key),    $ps_agent);
    if ($dept) $ps_agent = str_replace('$IF_DEPT = ""', '$IF_DEPT = "' . addslashes($dept) . '"', $ps_agent);

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="inventorflow-install.ps1"');
    // Générer l'installeur PowerShell avec l'agent embarqué
    $ps_agent_escaped = str_replace("'", "''", $ps_agent); // escape single quotes for PS here-string
    echo <<<PSEOF
# ================================================================
#  {$app_name} — Installeur Windows
#  Client    : {$client_name}
#  Généré le : {$_SERVER['REQUEST_TIME_FLOAT']}
#  Exécuter en tant qu'Administrateur
# ================================================================
#Requires -RunAsAdministrator

\$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

\$IF_DIR  = 'C:\\ProgramData\\InventorFlow'
\$IF_SVC  = 'InventorFlow-Agent'
\$IF_LOG  = "\$IF_DIR\\agent.log"
\$APP_NAME = '{$app_name}'

function Write-Step { param(\$msg) Write-Host "[\$APP_NAME] \$msg" -ForegroundColor Cyan }
function Write-OK   { param(\$msg) Write-Host "[\$APP_NAME] OK \$msg" -ForegroundColor Green }
function Write-Fail { param(\$msg) Write-Host "[\$APP_NAME] ERREUR \$msg" -ForegroundColor Red }

# Vérifier droits admin
\$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not \$isAdmin) { Write-Fail 'Ce script doit être exécuté en tant qu Administrateur'; exit 1 }

# Créer le dossier
Write-Step 'Création de \$IF_DIR...'
New-Item -ItemType Directory -Force -Path \$IF_DIR | Out-Null
Write-OK 'Dossier créé'

# Écrire l agent (embarqué — pas de second téléchargement)
Write-Step 'Installation de l agent...'
\$agentContent = @'
{$ps_agent_escaped}
'@
[IO.File]::WriteAllText("\$IF_DIR\\agent.ps1", \$agentContent, [Text.Encoding]::UTF8)
Write-OK "Agent écrit dans \$IF_DIR\\agent.ps1"

# Télécharger NSSM (Windows Service wrapper)
Write-Step 'Téléchargement de NSSM...'
\$arch = if ([Environment]::Is64BitOperatingSystem) { 'win64' } else { 'win32' }
\$nssmZip = "\$env:TEMP\\nssm.zip"
\$nssmExe = "\$IF_DIR\\nssm.exe"
try {
    (New-Object Net.WebClient).DownloadFile('https://nssm.cc/release/nssm-2.24.zip', \$nssmZip)
    Expand-Archive -Path \$nssmZip -DestinationPath "\$env:TEMP\\nssm_tmp" -Force
    Copy-Item "\$env:TEMP\\nssm_tmp\\nssm-2.24\\\$arch\\nssm.exe" -Destination \$nssmExe -Force
    Remove-Item \$nssmZip -Force -ErrorAction SilentlyContinue
    Remove-Item "\$env:TEMP\\nssm_tmp" -Recurse -Force -ErrorAction SilentlyContinue
    Write-OK 'NSSM installé'
} catch {
    Write-Fail "Téléchargement NSSM échoué : \$_"
    exit 1
}

# Supprimer l ancien service si présent
\$existingSvc = Get-Service -Name \$IF_SVC -ErrorAction SilentlyContinue
if (\$existingSvc) {
    Write-Step 'Suppression de l ancien service...'
    Stop-Service -Name \$IF_SVC -Force -ErrorAction SilentlyContinue
    & \$nssmExe remove \$IF_SVC confirm | Out-Null
    Start-Sleep -Seconds 2
}

# Installer le service via NSSM
Write-Step 'Installation du service Windows...'
\$psExe = (Get-Command powershell.exe).Source
& \$nssmExe install \$IF_SVC \$psExe "-ExecutionPolicy Bypass -NonInteractive -WindowStyle Hidden -File `"\$IF_DIR\\agent.ps1`"" | Out-Null
& \$nssmExe set \$IF_SVC AppDirectory     \$IF_DIR        | Out-Null
& \$nssmExe set \$IF_SVC AppRestartDelay  60000          | Out-Null
& \$nssmExe set \$IF_SVC AppStdout        \$IF_LOG        | Out-Null
& \$nssmExe set \$IF_SVC AppStderr        \$IF_LOG        | Out-Null
& \$nssmExe set \$IF_SVC AppRotateFiles   1              | Out-Null
& \$nssmExe set \$IF_SVC AppRotateBytes   1048576        | Out-Null
& \$nssmExe set \$IF_SVC Start            SERVICE_AUTO_START | Out-Null
& \$nssmExe set \$IF_SVC Description      "{$app_name} — Agent de supervision IT" | Out-Null
Write-OK "Service '\$IF_SVC' installé (démarrage automatique)"

# Démarrer le service
Write-Step 'Démarrage du service...'
Start-Service -Name \$IF_SVC
Start-Sleep -Seconds 3
\$svc = Get-Service -Name \$IF_SVC
if (\$svc.Status -eq 'Running') {
    Write-OK "Service démarré — premier enregistrement en cours..."
    Write-Host ""
    Write-Host "  Tableau de bord : {$server_url}/" -ForegroundColor Green
    Write-Host "  Logs agent      : \$IF_LOG"        -ForegroundColor Green
    Write-Host "  Arrêter service : Stop-Service \$IF_SVC"
    Write-Host ""
} else {
    Write-Fail "Le service n a pas démarré (status: \$(\$svc.Status))"
    Write-Host "Logs : Get-Content \$IF_LOG"
    exit 1
}
PSEOF;
    exit;
}

// ── LINUX/MACOS (comportement existant) ──────────────────────
// Load agent script and pre-configure it
$agent_path = __DIR__ . '/agent/inventorflow-agent.sh';
if (!file_exists($agent_path)) {
    http_response_code(500);
    echo "#!/usr/bin/env bash\necho 'ERROR: Agent script not found on server'\nexit 1\n";
    exit;
}

$agent = file_get_contents($agent_path);
$agent = preg_replace('/IF_SERVER="[^"]*"/',    "IF_SERVER=\"{$server_url}\"",  $agent);
$agent = preg_replace('/IF_API_KEY="[^"]*"/',   "IF_API_KEY=\"{$api_key}\"",    $agent);
$agent = preg_replace('/IF_DEPT_CODE="[^"]*"/', "IF_DEPT_CODE=\"{$dept}\"",     $agent);

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: inline; filename="inventorflow-install.sh"');
?>
#!/usr/bin/env bash
# =============================================================
#  <?= $app_name ?> — Installateur automatique
#  Client    : <?= htmlspecialchars($client_name, ENT_QUOTES) ?>

#  Généré le : <?= date('Y-m-d H:i:s') ?>

#  NE PAS PARTAGER — contient votre clé API
# =============================================================
set -euo pipefail

AGENT_PATH="/usr/local/bin/inventorflow-agent.sh"
LOG_FILE="/var/log/inventorflow-agent.log"
APP_NAME="<?= $app_name ?>"

RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${CYAN}[${APP_NAME}]${NC} $*"; }
success() { echo -e "${GREEN}[${APP_NAME}] ✓${NC} $*"; }
warn()    { echo -e "${YELLOW}[${APP_NAME}] ⚠${NC} $*"; }
error()   { echo -e "${RED}[${APP_NAME}] ✗${NC} $*"; exit 1; }

[[ $EUID -ne 0 ]] && error "Ce script doit être exécuté en root (sudo)"

if [[ "$(uname)" == "Darwin" ]]; then
    OS_FAMILY="mac"
    info "Système détecté : macOS $(sw_vers -productVersion)"
elif [[ "$(uname)" == "Linux" ]]; then
    OS_FAMILY="linux"
    info "Système détecté : Linux ($(uname -r))"
else
    error "Système non supporté : $(uname)"
fi

# Écriture de l'agent (déjà configuré — pas de téléchargement)
info "Installation de l'agent..."
cat > "$AGENT_PATH" << 'AGENT_EOF'
<?= $agent ?>
AGENT_EOF
chmod +x "$AGENT_PATH"
success "Agent installé dans $AGENT_PATH"

<?php if ($cron): ?>
# Installation du cron
install_cron() {
    info "Installation du cron (toutes les heures)..."
    if [[ "$OS_FAMILY" == "mac" ]]; then
        PLIST="/Library/LaunchDaemons/com.inventorflow.agent.plist"
        cat > "$PLIST" << PLIST_EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
    <key>Label</key><string>com.inventorflow.agent</string>
    <key>ProgramArguments</key>
    <array><string>${AGENT_PATH}</string></array>
    <key>StartInterval</key><integer>300</integer>
    <key>RunAtLoad</key><true/>
    <key>StandardOutPath</key><string>${LOG_FILE}</string>
    <key>StandardErrorPath</key><string>${LOG_FILE}</string>
</dict></plist>
PLIST_EOF
        launchctl unload "$PLIST" 2>/dev/null || true
        launchctl load "$PLIST"
        success "LaunchDaemon installé (démarrage auto + toutes les heures)"
    else
        echo "*/5 * * * * root ${AGENT_PATH} >> ${LOG_FILE} 2>&1" > /etc/cron.d/inventorflow-agent
        chmod 644 /etc/cron.d/inventorflow-agent
        success "Cron installé dans /etc/cron.d/inventorflow-agent"
    fi
}
install_cron
<?php endif; ?>

# Premier enregistrement
info "Enregistrement du poste..."
if "$AGENT_PATH"; then
    success "Poste enregistré avec succès dans ${APP_NAME} !"
    echo ""
    echo -e "  ${GREEN}Tableau de bord :${NC} <?= $server_url ?>/"
    echo -e "  ${GREEN}Logs agent :${NC}      ${LOG_FILE}"
else
    warn "L'enregistrement a échoué — vérifiez la connectivité vers <?= $server_url ?>"
    warn "Relancez manuellement : sudo ${AGENT_PATH}"
fi
