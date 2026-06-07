#!/bin/bash
# À exécuter avec : sudo bash /var/www/inventor-flow/maintenance/tune_mariadb.sh
# Buffer pool : 4 Go (25% des 16 Go RAM disponibles)

cat > /etc/mysql/mariadb.conf.d/99-inventorflow.cnf << 'CNF'
[mysqld]
# InventorFlow — tuning MariaDB 11.8
# Appliqué le $(date +%Y-%m-%d) — serveur 16 Go RAM

# InnoDB buffer pool : 25% RAM (recommandé : 50-75% sur serveur dédié)
innodb_buffer_pool_size     = 4G
innodb_buffer_pool_instances = 4

# Logs InnoDB — 256 Mo pour absorber les pics d'écriture
innodb_log_file_size         = 256M
innodb_log_buffer_size       = 64M

# Event scheduler pour les purges automatiques (alternative au cron si préféré)
event_scheduler              = ON

# Taille du cache de tables ouvertes
table_open_cache             = 512
table_definition_cache       = 512

# Thread cache pour les connexions répétées (PHP-FPM / Apache)
thread_cache_size            = 16
CNF

echo "Config écrite dans /etc/mysql/mariadb.conf.d/99-inventorflow.cnf"
echo "Redémarrage de MariaDB..."
systemctl restart mariadb
echo "Vérification buffer pool :"
CFG="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/config/db.php"
DB_USER=$(php -r "require '$CFG'; echo DB_USER;" 2>/dev/null)
DB_PASS=$(php -r "require '$CFG'; echo DB_PASS;" 2>/dev/null)
DB_NAME=$(php -r "require '$CFG'; echo DB_NAME;" 2>/dev/null)
mysql -u "$DB_USER" -p"$DB_PASS" -e "SELECT @@innodb_buffer_pool_size/1024/1024/1024 as buffer_pool_gb, @@event_scheduler;" "$DB_NAME"
