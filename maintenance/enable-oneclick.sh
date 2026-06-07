#!/usr/bin/env bash
# ============================================================
#  InventorFlow — Active les « mises à jour en un clic »
#
#  Pose UNIQUEMENT la règle sudo verrouillée qui autorise le serveur web
#  (www-data) à lancer maintenance/update.sh en root. Ne touche NI à la base,
#  NI aux fichiers de l'application, NI à la configuration. Idempotent.
#
#  Usage :  sudo bash maintenance/enable-oneclick.sh
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
UPDATE_SH="${SCRIPT_DIR}/update.sh"
SUDOERS_FILE="/etc/sudoers.d/inventorflow-update"
WEB_USER="${1:-www-data}"   # surchargez si votre serveur web tourne sous un autre user

GREEN='\033[0;32m'; RED='\033[0;31m'; CYAN='\033[0;36m'; NC='\033[0m'
info() { echo -e "${CYAN}[oneclick]${NC} $*"; }
ok()   { echo -e "${GREEN}[oneclick] ✓${NC} $*"; }
err()  { echo -e "${RED}[oneclick] ✗${NC} $*" >&2; exit 1; }

[[ $EUID -ne 0 ]] && err "À lancer en root (sudo)."
[[ -f "${APP_DIR}/config/db.php" ]] || err "'${APP_DIR}' ne ressemble pas à une installation InventorFlow (config/db.php absent)."
[[ -f "$UPDATE_SH" ]] || err "update.sh introuvable (${UPDATE_SH}). Mettez d'abord à jour vers une version qui le fournit."
id "$WEB_USER" >/dev/null 2>&1 || err "Utilisateur web '${WEB_USER}' inconnu. Relancez avec : sudo bash $0 <user_web>"

# update.sh doit être root-owned et NON modifiable par le web (sinon le NOPASSWD
# deviendrait une escalade de privilèges).
chown root:root "$UPDATE_SH"
chmod 755 "$UPDATE_SH"
RESTORE_SH="${SCRIPT_DIR}/restore.sh"
[[ -f "$RESTORE_SH" ]] && { chown root:root "$RESTORE_SH"; chmod 755 "$RESTORE_SH"; }

if [[ -f "$RESTORE_SH" ]]; then
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${UPDATE_SH}, ${RESTORE_SH}" > "$SUDOERS_FILE"
else
    echo "${WEB_USER} ALL=(root) NOPASSWD: ${UPDATE_SH}" > "$SUDOERS_FILE"
fi
chmod 440 "$SUDOERS_FILE"

if visudo -cf "$SUDOERS_FILE" >/dev/null 2>&1; then
    ok "Mises à jour en un clic activées pour '${WEB_USER}'."
    info "Testez via Administration → Mises à jour, ou : sudo -u ${WEB_USER} sudo -n ${UPDATE_SH} --help"
else
    rm -f "$SUDOERS_FILE"
    err "Règle sudo refusée par visudo — rien n'a été installé."
fi
