#!/usr/bin/env bash
# ============================================================
#  InventorFlow — Mise à jour NON DESTRUCTIVE
#
#  - Ne touche QU'AU code de l'application.
#  - Ne lance JAMAIS install.sql, ne fait AUCUNE purge, aucun DROP.
#  - Préserve config/db.php, config/app.php et backups/.
#  - Sauvegarde la base (mysqldump via backup.sh) AVANT toute migration.
#  - Migrations de schéma additives uniquement (voir migrations/).
#
#  Usage :
#    sudo bash maintenance/update.sh              # vers la dernière release
#    sudo bash maintenance/update.sh --dry-run    # simulation (ne modifie rien)
#    sudo bash maintenance/update.sh --tag v1.0.6 # version précise
#    sudo bash maintenance/update.sh -y           # sans confirmation
# ============================================================
set -euo pipefail

REPO="The-DCTX/Inventorflow"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
DRY_RUN=false
ASSUME_YES=false
TARGET_TAG=""

RED='\033[0;31m'; GREEN='\033[0;32m'; CYAN='\033[0;36m'; YELLOW='\033[1;33m'; NC='\033[0m'
info()    { echo -e "${CYAN}[update]${NC} $*"; }
ok()      { echo -e "${GREEN}[update] ✓${NC} $*"; }
warn()    { echo -e "${YELLOW}[update] ⚠${NC} $*"; }
err()     { echo -e "${RED}[update] ✗${NC} $*" >&2; exit 1; }

usage() {
    cat <<EOF
Usage : sudo bash maintenance/update.sh [options]
  --dry-run        Montre ce qui serait fait, sans rien modifier
  --tag vX.Y.Z     Met à jour vers une version précise (défaut : dernière release)
  -y, --yes        Ne pas demander de confirmation
  -h, --help       Affiche cette aide
EOF
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dry-run)  DRY_RUN=true ;;
        --tag)      TARGET_TAG="${2:-}"; shift ;;
        -y|--yes)   ASSUME_YES=true ;;
        -h|--help)  usage; exit 0 ;;
        *)          err "Option inconnue : $1 (voir --help)" ;;
    esac
    shift
done

# Téléchargement portable (curl ou wget) : _fetch URL OUTFILE  (OUTFILE="-" = stdout)
_fetch() {
    local url="$1" out="$2" timeout="${3:-60}"
    if command -v curl >/dev/null 2>&1; then
        if [[ "$out" == "-" ]]; then curl -fsSL --max-time "$timeout" "$url"
        else curl -fsSL --max-time "$timeout" "$url" -o "$out"; fi
    elif command -v wget >/dev/null 2>&1; then
        if [[ "$out" == "-" ]]; then wget -qO- --timeout="$timeout" "$url"
        else wget -qO "$out" --timeout="$timeout" "$url"; fi
    else
        return 1
    fi
}

# ── Pré-requis ───────────────────────────────────────────────
# --dry-run ne modifie rien (lecture seule + temp) → root non requis.
[[ "$DRY_RUN" != true && $EUID -ne 0 ]] && err "À lancer en root (sudo)."
command -v curl >/dev/null 2>&1 || command -v wget >/dev/null 2>&1 || err "curl ou wget est requis."
command -v tar  >/dev/null || err "tar est requis."
[[ -f "$APP_DIR/config/db.php" ]] || err "config/db.php introuvable — '$APP_DIR' n'est pas une installation InventorFlow."

# ── Version installée ────────────────────────────────────────
CUR_VER="$(tr -cd '0-9.' < "$APP_DIR/VERSION" 2>/dev/null || true)"
if [[ -z "$CUR_VER" && -f "$APP_DIR/config/app.php" ]]; then
    CUR_VER="$(grep -oE "APP_VERSION', *'[0-9.]+'" "$APP_DIR/config/app.php" 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
fi
CUR_VER="${CUR_VER:-0.0.0}"

# ── Version cible ────────────────────────────────────────────
if [[ -z "$TARGET_TAG" ]]; then
    info "Recherche de la dernière version sur GitHub…"
    TARGET_TAG="$(_fetch "https://api.github.com/repos/${REPO}/releases/latest" - 15 2>/dev/null \
                  | grep -oE '"tag_name"[^,]*' | grep -oE 'v?[0-9]+\.[0-9]+\.[0-9]+' | head -1 || true)"
    [[ -z "$TARGET_TAG" ]] && err "Impossible de récupérer la dernière version (réseau / API GitHub ?)."
fi
[[ "$TARGET_TAG" == v* ]] || TARGET_TAG="v${TARGET_TAG}"
TARGET_VER="${TARGET_TAG#v}"

info "Version installée : $CUR_VER"
info "Version cible     : $TARGET_VER"

# vrai si $1 > $2 (semver simple)
_newer() {
    [[ "$1" == "$2" ]] && return 1
    [[ "$(printf '%s\n%s\n' "$1" "$2" | sort -t. -k1,1n -k2,2n -k3,3n | tail -1)" == "$1" ]]
}
if ! _newer "$TARGET_VER" "$CUR_VER"; then
    ok "Déjà à jour (cible ≤ installée). Rien à faire."
    exit 0
fi

# ── Confirmation ─────────────────────────────────────────────
if [[ "$ASSUME_YES" != true && "$DRY_RUN" != true ]]; then
    echo
    warn "Mise à jour $CUR_VER → $TARGET_VER dans : $APP_DIR"
    warn "config/ et backups/ sont préservés. Une sauvegarde de la base est faite avant migration."
    read -rp "Continuer ? (oui/non) " ans
    [[ "$ans" == "oui" ]] || err "Annulé."
fi

# ── Téléchargement de la release ─────────────────────────────
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
TARBALL_URL="https://github.com/${REPO}/archive/refs/tags/${TARGET_TAG}.tar.gz"
info "Téléchargement de ${TARGET_TAG}…"
_fetch "$TARBALL_URL" "$TMP/src.tar.gz" 60 || err "Téléchargement échoué : $TARBALL_URL"
tar xzf "$TMP/src.tar.gz" -C "$TMP" || err "Extraction échouée."
SRC="$(find "$TMP" -maxdepth 1 -type d -name 'Inventorflow-*' | head -1)"
[[ -d "$SRC" ]] || err "Dossier source extrait introuvable."

# Fichiers locaux / données préservés
EXCLUDES=(
    --exclude='config/db.php'
    --exclude='config/app.php'
    --exclude='backups/'
    --exclude='.git/'
    --exclude='*.log'
    --exclude='install.php'
)

# ── DRY-RUN : montre le diff, ne modifie rien ────────────────
if [[ "$DRY_RUN" == true ]]; then
    info "Simulation (--dry-run) — AUCUNE modification ne sera faite."
    if command -v rsync >/dev/null; then
        echo "── Fichiers qui seraient mis à jour ──"
        rsync -an --itemize-changes "${EXCLUDES[@]}" "$SRC"/ "$APP_DIR"/ | grep -vE '^\.d' || true
    else
        warn "rsync absent : impossible de lister le diff précis (cp -a serait utilisé)."
    fi
    if [[ -f "$SRC/maintenance/migrate.php" ]]; then
        echo "── État des migrations (cible) ──"
        php "$SRC/maintenance/migrate.php" --status 2>/dev/null || warn "migrate.php --status indisponible."
    fi
    echo "── Sauvegarde ──"
    info "[dry-run] backup.sh serait exécuté AVANT toute migration."
    ok "Simulation terminée. Relancez sans --dry-run pour appliquer."
    exit 0
fi

# ── Sauvegarde AVANT toute modification ──────────────────────
if [[ -f "$APP_DIR/maintenance/backup.sh" ]]; then
    info "Sauvegarde (fichiers + base) avant mise à jour…"
    bash "$APP_DIR/maintenance/backup.sh" || err "Sauvegarde échouée — mise à jour interrompue (rien n'a été modifié)."
    ok "Sauvegarde effectuée (dossier backups/)."
else
    warn "backup.sh introuvable — sauvegardez manuellement avant de continuer."
    [[ "$ASSUME_YES" == true ]] || { read -rp "Continuer sans sauvegarde ? (oui/non) " a; [[ "$a" == "oui" ]] || err "Annulé."; }
fi

# ── Mise à jour des fichiers ─────────────────────────────────
info "Mise à jour des fichiers (config/ et backups/ préservés)…"
if command -v rsync >/dev/null; then
    rsync -a "${EXCLUDES[@]}" "$SRC"/ "$APP_DIR"/ || err "Copie (rsync) échouée."
else
    # Fallback sans rsync : tar honore les exclusions (cp -a écraserait config/).
    ( cd "$SRC" && tar \
        --exclude='./config/db.php' --exclude='./config/app.php' \
        --exclude='./backups' --exclude='./.git' --exclude='./install.php' \
        -cf - . ) | tar -C "$APP_DIR" -xf - || err "Copie (tar) échouée."
fi
ok "Fichiers mis à jour."

# ── Migrations de base (additives uniquement) ────────────────
if [[ -f "$APP_DIR/maintenance/migrate.php" ]]; then
    info "Application des migrations de base (additives)…"
    php "$APP_DIR/maintenance/migrate.php" || err "Migration échouée — restaurez la dernière sauvegarde si nécessaire."
fi

# ── Bump de version + droits ─────────────────────────────────
printf '%s\n' "$TARGET_VER" > "$APP_DIR/VERSION"
if [[ -f "$APP_DIR/config/app.php" ]]; then
    sed -i.bak -E "s/define\('APP_VERSION', *'[^']*'\)/define('APP_VERSION', '${TARGET_VER}')/" "$APP_DIR/config/app.php" && rm -f "$APP_DIR/config/app.php.bak"
fi
[[ -f "$APP_DIR/install.php" ]] && rm -f "$APP_DIR/install.php"
chown -R www-data:www-data "$APP_DIR" 2>/dev/null || true

# ── (Re)pose de la règle sudo « un clic » (update.sh + restore.sh) ───────────
# Best-effort idempotent : les mises à jour obtiennent l'autorisation pour les
# nouveaux scripts (ex. restore.sh) sans intervention SSH manuelle. Placé APRÈS
# le chown -R pour que les scripts redeviennent root-owned (sécurité du NOPASSWD).
if [[ -f "$APP_DIR/maintenance/enable-oneclick.sh" ]]; then
    bash "$APP_DIR/maintenance/enable-oneclick.sh" >/dev/null 2>&1 \
        && ok "Règle « un clic » à jour (update.sh + restore.sh)." \
        || warn "Règle « un clic » non actualisée — lancez enable-oneclick.sh si besoin."
fi

ok "Mise à jour terminée : $CUR_VER → $TARGET_VER"
echo
info "Les agents déjà en v1.0.6+ se mettront à jour seuls. Sinon, re-déployez-les une fois depuis la fiche client."
