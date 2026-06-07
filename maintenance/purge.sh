#!/bin/bash
# Rétention automatique InventorFlow
# - monitoring_snapshots : 90 jours
# - security_events      : 180 jours
CFG="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/config/db.php"
DB_USER=$(php -r "require '$CFG'; echo DB_USER;" 2>/dev/null)
DB_PASS=$(php -r "require '$CFG'; echo DB_PASS;" 2>/dev/null)
DB_NAME=$(php -r "require '$CFG'; echo DB_NAME;" 2>/dev/null)
MYSQL="mysql -u $DB_USER -p$DB_PASS $DB_NAME"

DEL_MON=$($MYSQL -sN -e "DELETE FROM monitoring_snapshots WHERE collected_at < DATE_SUB(NOW(), INTERVAL 90 DAY); SELECT ROW_COUNT();")
DEL_SEC=$($MYSQL -sN -e "DELETE FROM security_events WHERE detected_at < DATE_SUB(NOW(), INTERVAL 180 DAY); SELECT ROW_COUNT();")

echo "[$(date '+%Y-%m-%d %H:%M')] Purge OK — monitoring: ${DEL_MON} lignes, security: ${DEL_SEC} lignes"
