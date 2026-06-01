#!/bin/bash
# Rétention automatique InventorFlow
# - monitoring_snapshots : 90 jours
# - security_events      : 180 jours
MYSQL="mysql -u inventorflow -pinventorflow2024! inventorflow"

DEL_MON=$($MYSQL -sN -e "DELETE FROM monitoring_snapshots WHERE collected_at < DATE_SUB(NOW(), INTERVAL 90 DAY); SELECT ROW_COUNT();")
DEL_SEC=$($MYSQL -sN -e "DELETE FROM security_events WHERE detected_at < DATE_SUB(NOW(), INTERVAL 180 DAY); SELECT ROW_COUNT();")

echo "[$(date '+%Y-%m-%d %H:%M')] Purge OK — monitoring: ${DEL_MON} lignes, security: ${DEL_SEC} lignes"
