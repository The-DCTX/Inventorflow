#!/usr/bin/env bash
# InventorFlow — Script de backup
# Les credentials sont lus depuis config/db.php (pas de valeurs hardcodées)
set -euo pipefail
# 0027 : backups lisibles par www-data uniquement (dir 750, fichiers 640).
# Évite qu'un utilisateur local lise les dumps DB ou dépose une archive piégée.
umask 0027

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="${APP_DIR}/backups"
DATE=$(date +%Y-%m-%d)
START=$(date +%s)
KEEP_DAYS=180
STATUS="success"
MESSAGE=""

# Lire les credentials via PHP (fiable, supporte les caractères spéciaux)
_db_conf() { php -r "require '${APP_DIR}/config/db.php'; echo $1;"; }
DB_HOST=$(_db_conf DB_HOST)
DB_NAME=$(_db_conf DB_NAME)
DB_USER=$(_db_conf DB_USER)
DB_PASS=$(_db_conf DB_PASS)

mkdir -p "$BACKUP_DIR"
chmod 750 "$BACKUP_DIR" 2>/dev/null || true   # auto-corrige un éventuel 777 hérité
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$BACKUP_DIR/backup.log"; }

# ── Backup fichiers ──────────────────────────────────────────
FILES_PATH="$BACKUP_DIR/files-$DATE.tar.gz"
if tar czf "$FILES_PATH" \
    --exclude='*.bak' --exclude='*.bak_*' --exclude='MEMORY.md' \
    --exclude="${APP_DIR}/backups" \
    "$APP_DIR" 2>/dev/null; then
    FILES_SIZE=$(du -sh "$FILES_PATH" 2>/dev/null | cut -f1)
    log "Fichiers OK : $FILES_PATH ($FILES_SIZE)"
else
    STATUS="error"; MESSAGE="$MESSAGE Échec backup fichiers."
    log "ERREUR backup fichiers"
fi

# ── Backup base de données ───────────────────────────────────
DB_PATH="$BACKUP_DIR/db-$DATE.sql.gz"
if mysqldump --no-tablespaces --single-transaction \
    -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null \
    | gzip > "$DB_PATH"; then
    DB_SIZE=$(du -sh "$DB_PATH" 2>/dev/null | cut -f1)
    log "DB OK : $DB_PATH ($DB_SIZE)"
else
    STATUS="error"; MESSAGE="$MESSAGE Échec backup base de données."
    log "ERREUR backup DB"
fi

# ── Vérification intégrité ───────────────────────────────────
[[ -f "$FILES_PATH" ]] && ! tar tzf "$FILES_PATH" >/dev/null 2>&1 && \
    STATUS="warning" && MESSAGE="$MESSAGE Archive fichiers corrompue."
[[ -f "$DB_PATH" ]] && ! gzip -t "$DB_PATH" 2>/dev/null && \
    STATUS="warning" && MESSAGE="$MESSAGE Archive DB corrompue."

# ── Rotation ─────────────────────────────────────────────────
find "$BACKUP_DIR" -name '*.tar.gz' -mtime +"$KEEP_DAYS" -delete 2>/dev/null || true
find "$BACKUP_DIR" -name '*.sql.gz' -mtime +"$KEEP_DAYS" -delete 2>/dev/null || true

DURATION=$(( $(date +%s) - START ))
MSG_CLEAN="${MESSAGE:-Backup terminé sans erreur}"

mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" 2>/dev/null << SQL
INSERT INTO backup_logs (status, files_size, db_size, duration_sec, message, files_path, db_path)
VALUES ('$STATUS', '$FILES_SIZE', '$DB_SIZE', $DURATION, '$MSG_CLEAN', '$FILES_PATH', '$DB_PATH');
SQL

log "Backup $STATUS en ${DURATION}s (fichiers: ${FILES_SIZE:-?}, db: ${DB_SIZE:-?})"

if [[ "$STATUS" != "success" ]]; then
    php "${APP_DIR}/api/backup-notify.php" "$STATUS" "$MSG_CLEAN" "$FILES_SIZE" "$DB_SIZE" 2>/dev/null || true
fi
