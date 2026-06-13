#!/bin/bash
# restore.sh — Restauration d'une sauvegarde InventorFlow (base et/ou fichiers).
# Une sauvegarde de sécurité est TOUJOURS effectuée avant restauration.
# Usage : sudo bash restore.sh [--db FICHIER.sql.gz] [--files FICHIER.tar.gz] [-y] [--dry-run]
set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="${APP_DIR}/backups"

DB_FILE=""; FILES_FILE=""; ASSUME_YES=false; DRY_RUN=false
while [[ $# -gt 0 ]]; do
  case "$1" in
    --db)       DB_FILE="${2:-}"; shift 2;;
    --files)    FILES_FILE="${2:-}"; shift 2;;
    -y|--yes)   ASSUME_YES=true; shift;;
    --dry-run)  DRY_RUN=true; shift;;
    *) echo "Option inconnue : $1" >&2; exit 2;;
  esac
done

log(){ echo "[restore] $*"; }
err(){ echo "[restore] ERREUR : $*" >&2; exit 1; }

[[ -z "$DB_FILE" && -z "$FILES_FILE" ]] && err "Rien à restaurer (--db et/ou --files requis)."

# N'accepter qu'un nom de fichier dans backups/ (anti-traversée de chemin)
[[ -n "$DB_FILE" ]]    && DB_FILE="${BACKUP_DIR}/$(basename "$DB_FILE")"
[[ -n "$FILES_FILE" ]] && FILES_FILE="${BACKUP_DIR}/$(basename "$FILES_FILE")"
[[ -n "$DB_FILE" && ! -f "$DB_FILE" ]]       && err "Fichier base introuvable : $DB_FILE"
[[ -n "$FILES_FILE" && ! -f "$FILES_FILE" ]] && err "Archive fichiers introuvable : $FILES_FILE"

CFG="${APP_DIR}/config/db.php"
DB_HOST=$(php -r "require '$CFG'; echo DB_HOST;" 2>/dev/null)
DB_NAME=$(php -r "require '$CFG'; echo DB_NAME;" 2>/dev/null)
DB_USER=$(php -r "require '$CFG'; echo DB_USER;" 2>/dev/null)
DB_PASS=$(php -r "require '$CFG'; echo DB_PASS;" 2>/dev/null)

log "Base     : ${DB_FILE:-(aucune)}"
log "Fichiers : ${FILES_FILE:-(aucun)}"

if [[ "$DRY_RUN" == true ]]; then
  [[ -n "$DB_FILE" ]]    && { gzip -t "$DB_FILE" 2>/dev/null && log "[dry-run] dump base valide" || err "dump base corrompu"; }
  [[ -n "$FILES_FILE" ]] && { tar tzf "$FILES_FILE" >/dev/null 2>&1 && log "[dry-run] archive fichiers valide" || err "archive fichiers corrompue"; }
  log "[dry-run] Une sauvegarde de sécurité serait faite, puis restauration. Rien appliqué."
  exit 0
fi

if [[ "$ASSUME_YES" != true ]]; then
  read -rp "Confirmer la restauration ? Elle écrase les données actuelles (oui/non) " a
  [[ "$a" == "oui" ]] || err "Annulé."
fi

# 1) Sauvegarde de sécurité AVANT toute restauration (non négociable).
#    Horodatée (presafe-) pour ne JAMAIS écraser un backup existant — en
#    particulier celui qu'on restaure (backup.sh nomme par jour, collision sinon).
TS="$(date +%Y-%m-%d_%H%M%S)"
log "Sauvegarde de sécurité avant restauration (presafe-${TS})…"
mysqldump --no-tablespaces --single-transaction -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null \
  | gzip > "${BACKUP_DIR}/presafe-${TS}.sql.gz" \
  && log "Base sauvegardée (presafe-${TS}.sql.gz)." \
  || log "AVERTISSEMENT : sauvegarde base de sécurité échouée (poursuite)."
tar czf "${BACKUP_DIR}/presafe-${TS}.tar.gz" \
  --exclude="${APP_DIR}/backups" --exclude='*.bak' --exclude='*.bak_*' --exclude='MEMORY.md' \
  "$APP_DIR" 2>/dev/null \
  && log "Fichiers sauvegardés (presafe-${TS}.tar.gz)." \
  || log "AVERTISSEMENT : sauvegarde fichiers de sécurité échouée (poursuite)."

# 2) Restauration de la base
if [[ -n "$DB_FILE" ]]; then
  log "Restauration de la base…"
  gunzip -c "$DB_FILE" | mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    && log "Base restaurée." || err "Échec restauration base."
fi

# 3) Restauration des fichiers — l'archive contient des chemins absolus
#    (var/www/inventor-flow/...). On extrait depuis / et on préserve config/db.php
#    pour ne pas casser la connexion à la base.
if [[ -n "$FILES_FILE" ]]; then
  log "Restauration des fichiers (config/db.php préservé)…"
  REL="${APP_DIR#/}"
  # Anti-écrasement root : refuser chemins absolus, traversée '..' ou toute
  # entrée hors de l'arborescence applicative ${REL}/ (les backups légitimes
  # générés par backup.sh y sont tous). Substitution (pas de grep -q) pour
  # éviter le piège SIGPIPE/pipefail.
  BAD_ABS=$(tar tzf "$FILES_FILE" 2>/dev/null | grep -E '^/|(^|/)\.\.(/|$)' | head -1)
  [[ -n "$BAD_ABS" ]] && err "Archive refusée (chemin absolu ou '..') : $BAD_ABS"
  BAD_OUT=$(tar tzf "$FILES_FILE" 2>/dev/null | grep -vE "^${REL}/" | head -1)
  [[ -n "$BAD_OUT" ]] && err "Archive refusée (hors de ${REL}/) : $BAD_OUT"
  tar xzf "$FILES_FILE" -C / --exclude="${REL}/config/db.php" \
    && log "Fichiers restaurés." || err "Échec restauration fichiers."
fi

log "Restauration terminée."
