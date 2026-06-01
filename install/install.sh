#!/bin/bash
set -e
export DEBIAN_FRONTEND=noninteractive

# Config
INSTALL_DIR="/var/www/inventor-flow"
APP_PORT="8080"
APP_URL="http://localhost:8080"
DB_NAME="inventorflow"
DB_USER="inventorflow"
DB_PASS="InventorFlow2024!"
ADMIN_PASS="AdminTest2024!"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log()  { echo "[$(date '+%H:%M:%S')] $*"; }
ok()   { echo "[OK]   $*"; }
warn() { echo "[WARN] $*"; }
fail() { echo "[ERR]  $*" >&2; exit 1; }

# ── Aide (-h / --help) — affichée sans privilèges root ────────────────────────
show_help() {
cat <<HELP
InventorFlow — Installateur automatique
=======================================

USAGE
  sudo bash install.sh [OPTION]

OPTIONS
  -h, --help    Affiche cette aide et quitte.
  -y, --yes     Mode non-interactif : saute l'avertissement et la confirmation
                (à réserver aux serveurs dédiés ou aux tests automatisés).

CE QUE FAIT LE SCRIPT
  Installe une stack complète puis déploie l'application :
    - Apache2 + PHP-FPM (proxy_fcgi)
    - MariaDB (base + utilisateur dédiés)
    - dépôt des fichiers, vhost et permissions
    - import du schéma (install.sql) + compte admin (mot de passe en bcrypt)
  Compatible Debian 11/12/13+ et Ubuntu 22.04/24.04+. À lancer en root.

CONFIGURATION (à éditer en haut de ce script AVANT lancement)
  APP_PORT      Port HTTP de l'application              (actuel : ${APP_PORT})
  INSTALL_DIR   Répertoire d'installation               (actuel : ${INSTALL_DIR})
  APP_URL       URL publique de l'application            (actuel : ${APP_URL})
  DB_NAME       Nom de la base de données               (actuel : ${DB_NAME})
  DB_USER       Utilisateur MySQL de l'application       (actuel : ${DB_USER})
  DB_PASS       Mot de passe de cet utilisateur          (actuel : ${DB_PASS})
  ADMIN_PASS    Mot de passe du compte admin de l'app    (actuel : ${ADMIN_PASS})

EXEMPLES
  sudo bash install.sh        Installation interactive (avec avertissement)
  sudo bash install.sh -y     Installation directe, sans confirmation
  bash install.sh --help      Affiche cette aide

AVERTISSEMENT
  L'installation modifie le système (paquets, services, configurations).
  Privilégiez un serveur dédié ou une VM propre, sans base ni site déjà en
  place. Sauvegardez vos données avant. Fourni sans garantie (AGPL-3.0).
HELP
}

case "${1:-}" in
  -h|--help) show_help; exit 0 ;;
esac

[ "$(id -u)" -ne 0 ] && fail "Ce script doit être lancé en root : sudo bash $0"

# ── Avertissement & consentement éclairé ──────────────────────────────────────
if [ "${1:-}" != "-y" ] && [ "${1:-}" != "--yes" ]; then
  cat <<'BANNER'

  ====================================================================
   /!\  AVERTISSEMENT — A LIRE AVANT DE CONTINUER  /!\
  ====================================================================
   Cet installeur s'execute en ROOT et modifie le systeme :
   il installe et configure Apache, PHP-FPM et MariaDB/MySQL.

   >>> INSTALLEZ DE PREFERENCE SUR UN SERVEUR DEDIE OU UNE VM PROPRE <<<
       sans base de donnees ni site web deja en place.

   Sur un serveur deja utilise : risque de conflits et, si vous
   confirmez l'ecrasement, PERTE des bases de donnees existantes.

   >>> SAUVEGARDEZ VOS DONNEES AVANT. <<<
   Logiciel fourni "tel quel", SANS AUCUNE GARANTIE (licence AGPL-3.0).
   Vous l'utilisez a vos propres risques.
  ====================================================================

BANNER
  printf "  Tapez OUI pour confirmer l'installation sur un serveur adapte : "
  read -r _CONSENT
  [ "$_CONSENT" = "OUI" ] || fail "Installation annulee (confirmation non recue). Astuce tests : 'bash install.sh -y'."
fi

log "=== INVENTORFLOW FRESH INSTALL ==="
log "OS: $(. /etc/os-release && echo $PRETTY_NAME)"

# ── Libérer les verrous dpkg ──────────────────────────────────────────────────
rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/cache/apt/archives/lock

# ── Gestion MariaDB existant ──────────────────────────────────────────────────
DB_EXISTS=0
if dpkg -l 2>/dev/null | awk '{print $2}' | grep -qE 'mariadb|mysql'; then
    DB_EXISTS=1
fi

ERASE_DB=0
if [ "$DB_EXISTS" -eq 1 ]; then
    echo
    echo -e "  ${YELLOW}⚠  MariaDB déjà présent sur ce système.${RESET}"
    echo -e "  ${BOLD}Que faire des bases de données existantes ?${RESET}"
    echo
    echo -e "  ${BOLD}1)${RESET} Tout écraser — installation propre (toutes les BDD seront supprimées)"
    echo -e "  ${BOLD}2)${RESET} Conserver les données existantes"
    echo -e "     ${YELLOW}⚠  Attention : peut provoquer des conflits de packages lors de l'installation${RESET}"
    echo
    read -rp "  Choix [1/2] : " DB_CHOICE
    case "${DB_CHOICE}" in
        1)
            warn "Toutes les bases de données existantes vont être DÉFINITIVEMENT SUPPRIMÉES."
            read -rp "  Pour confirmer, tapez en toutes lettres SUPPRIMER : " CONFIRM_ERASE
            if [ "$CONFIRM_ERASE" = "SUPPRIMER" ]; then
                ERASE_DB=1
            else
                ERASE_DB=0
                warn "Confirmation incorrecte — conservation des données."
            fi
            ;;
        *)
            ERASE_DB=0
            warn "Conservation des données — des erreurs dpkg peuvent survenir."
            ;;
    esac
fi

if [ "$ERASE_DB" -eq 1 ]; then
    # Sauvegarde de sécurité AVANT toute destruction (best-effort)
    if command -v mysqldump >/dev/null 2>&1 && systemctl is-active --quiet mariadb 2>/dev/null; then
        BACKUP_FILE="/var/backups/inventorflow-predrop-$(date +%Y%m%d-%H%M%S).sql.gz"
        mkdir -p /var/backups
        log "--- Sauvegarde de sécurité avant purge..."
        if mysqldump --all-databases 2>/dev/null | gzip > "$BACKUP_FILE" 2>/dev/null; then
            ok "Sauvegarde créée : $BACKUP_FILE"
        else
            warn "Sauvegarde impossible (base inaccessible)"
        fi
    else
        warn "mysqldump indisponible — aucune sauvegarde préalable possible"
    fi
    log "--- Purge complète MariaDB/MySQL..."
    ALL_MARIADB=$(dpkg -l 2>/dev/null | awk '/mariadb|mysql/{print $2}' || true)
    [ -n "$ALL_MARIADB" ] && dpkg --purge --force-all $ALL_MARIADB 2>/dev/null || true
    rm -rf /etc/mysql /var/lib/mysql
fi

# 1. Dépendances
log "--- Mise à jour apt..."
apt-get update -qq

# Libérer le port 80 si un service l'occupe
if ss -tlnp 2>/dev/null | grep -q ':80 '; then
    log "--- Port 80 occupé — arrêt du service..."
    fuser -k 80/tcp 2>/dev/null || true
    sleep 1
    ok "Port 80 libéré"
fi

# ── Installation intelligente : vérifie package ET config/binaire ────────────
need_install() {
    local pkg="$1" check="${2:-}"
    # Package absent de dpkg
    if ! dpkg -l "$pkg" 2>/dev/null | grep -q '^ii'; then return 0; fi
    # Package installé mais config/binaire supprimé → réinstaller
    if [ -n "$check" ] && [ ! -e "$check" ]; then return 0; fi
    return 1
}

PKGS_TO_INSTALL=""
need_install apache2       /etc/apache2/apache2.conf  && PKGS_TO_INSTALL="$PKGS_TO_INSTALL apache2"
need_install mariadb-server /etc/mysql/mariadb.cnf    && PKGS_TO_INSTALL="$PKGS_TO_INSTALL mariadb-server"
need_install php           /usr/bin/php               && PKGS_TO_INSTALL="$PKGS_TO_INSTALL php"
for pkg in php-mysql php-mbstring php-xml php-curl php-zip php-gd php-fpm curl unzip; do
    need_install "$pkg" && PKGS_TO_INSTALL="$PKGS_TO_INSTALL $pkg"
done

if [ -n "$PKGS_TO_INSTALL" ]; then
    log "--- Installation :${PKGS_TO_INSTALL}"
    apt-get install -y -q \
        -o Dpkg::Options::="--force-confnew" \
        -o Dpkg::Options::="--force-confdef" \
        -o Dpkg::Options::="--force-confmiss" \
        $PKGS_TO_INSTALL
else
    ok "Toutes les dépendances déjà installées"
fi

# ── Configuration Apache : PHP via PHP-FPM (robuste, pas de mod_php) ─────────
PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null)
[ -z "$PHP_VER" ] && fail "PHP introuvable après installation"

# Modules requis : dir (DirectoryIndex), rewrite (.htaccess), proxy_fcgi+setenvif (FPM)
a2dissite 000-default >/dev/null 2>&1 || true
a2enmod dir rewrite proxy_fcgi setenvif >/dev/null 2>&1 || true

# Handler .php -> PHP-FPM. Le paquet php-fpm fournit normalement la conf ;
# sinon on la crée nous-mêmes (cas d'une install dégradée).
FPM_SOCK="/run/php/php${PHP_VER}-fpm.sock"
if [ -f "/etc/apache2/conf-available/php${PHP_VER}-fpm.conf" ]; then
    a2enconf "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
else
    cat > /etc/apache2/conf-available/inventorflow-php-fpm.conf << FPMCONF
<FilesMatch "\.ph(ar|p|tml)$">
    SetHandler "proxy:unix:${FPM_SOCK}|fcgi://localhost"
</FilesMatch>
FPMCONF
    a2enconf inventorflow-php-fpm >/dev/null 2>&1 || true
fi
systemctl enable --now "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
ok "Module PHP-FPM ${PHP_VER} activé (proxy_fcgi)"

# ── PDO core : garantir son chargement AVANT pdo_mysql (priorité 10) ─────────
# Évite « undefined symbol: pdo_parse_params » si pdo.ini absent (install dégradée).
PHP_MODS_DIR="/etc/php/${PHP_VER}/mods-available"
if [ -d "$PHP_MODS_DIR" ] && [ ! -f "$PHP_MODS_DIR/pdo.ini" ]; then
    printf '; priority=10\nextension=pdo.so\n' > "$PHP_MODS_DIR/pdo.ini"
    phpenmod pdo >/dev/null 2>&1 || true
    systemctl reload "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
    ok "PDO core activé (pdo.ini priorité 10)"
fi

ok "Dépendances OK — PHP : $(php -r 'echo PHP_VERSION;' 2>/dev/null)"

# 2. MariaDB
if ! systemctl is-active mariadb >/dev/null 2>&1; then
    log "--- Démarrage MariaDB..."
    systemctl start mariadb
fi
systemctl enable mariadb >/dev/null 2>&1

log "--- Création base de données..."
mysql --connect-timeout=10 << SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "Base '${DB_NAME}' créée"

# 3. Import SQL
log "--- Import install.sql..."
[[ ! -f "${SCRIPT_DIR}/install.sql" ]] && fail "install.sql introuvable"
mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${SCRIPT_DIR}/install.sql"

# Reset mot de passe admin en bcrypt
# Générer le hash bcrypt — filtrer uniquement la ligne hash (évite les warnings PHP sur stdout)
ADMIN_HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_DEFAULT);" 2>/dev/null | grep -oP '^\$2[ayb]\$\d+\$\S+' | head -1)
if [ -z "$ADMIN_HASH" ]; then
    warn "Impossible de générer le hash PHP — le mot de passe admin restera 'InventorFlow2024!' (changez-le après connexion)"
    ADMIN_HASH=""
fi
if [ -n "$ADMIN_HASH" ]; then
    mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
        -e "UPDATE users SET \`password\`='${ADMIN_HASH}' WHERE username='admin';"
fi
mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
    -e "UPDATE app_settings SET setting_value='${APP_URL}' WHERE setting_key='app_url';"
ok "SQL importé et configuré"

# 4. Déploiement fichiers (depuis le dossier source app/)
log "--- Déploiement de l'application..."
APP_SRC="${SCRIPT_DIR}/../app"
[[ ! -d "$APP_SRC" ]] && fail "Dossier source introuvable : $APP_SRC"
[[ -d "${INSTALL_DIR}" ]] && mv "${INSTALL_DIR}" "${INSTALL_DIR}.bak.$(date +%s)"
mkdir -p "${INSTALL_DIR}"
cp -a "$APP_SRC/." "${INSTALL_DIR}/"

# Config DB
cat > "${INSTALL_DIR}/config/db.php" << PHP
<?php
define('DB_HOST',    'localhost');
define('DB_NAME',    '${DB_NAME}');
define('DB_USER',    '${DB_USER}');
define('DB_PASS',    '${DB_PASS}');
define('DB_CHARSET', 'utf8mb4');
function db(): PDO {
    static \$pdo = null;
    if (\$pdo === null) {
        \$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset='.DB_CHARSET, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
        ]);
    }
    return \$pdo;
}
PHP

# Config App
cat > "${INSTALL_DIR}/config/app.php" << PHP
<?php
define('APP_VERSION',  '1.0.0');
define('APP_URL',      '');
define('SESSION_NAME', 'inventorflow_session');
session_name(SESSION_NAME);
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (!isset(\$_SESSION['_app_settings'])) {
    try {
        \$rows = db()->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll();
        \$_SESSION['_app_settings'] = array_column(\$rows, 'setting_value', 'setting_key');
    } catch (Throwable \$e) { \$_SESSION['_app_settings'] = []; }
}
function app_setting(string \$key, string \$default = ''): string {
    return \$_SESSION['_app_settings'][\$key] ?? \$default;
}
define('APP_NAME',       app_setting('app_name', 'InventorFlow'));
define('APP_THEME',      app_setting('theme', 'dark'));
define('APP_SERVER_URL', app_setting('app_url', '${APP_URL}'));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
PHP

# Supprime l'installeur web (creds root en dur, redondant avec ce script)
rm -f "${INSTALL_DIR}/install.php"

chown -R www-data:www-data "${INSTALL_DIR}"
find "${INSTALL_DIR}" -type f -exec chmod 644 {} \;
find "${INSTALL_DIR}" -type d -exec chmod 755 {} \;
ok "Fichiers déployés dans ${INSTALL_DIR}"

# 5. VirtualHost Apache
log "--- Configuration Apache vhost..."
cat > "/etc/apache2/sites-available/inventorflow.conf" << VHOST
Listen ${APP_PORT}
<VirtualHost *:${APP_PORT}>
    ServerName inventorflow
    DocumentRoot ${INSTALL_DIR}
    <Directory ${INSTALL_DIR}>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    DirectoryIndex index.php
    ErrorLog  /var/log/apache2/inventorflow-error.log
    CustomLog /var/log/apache2/inventorflow-access.log combined
</VirtualHost>
VHOST

a2ensite inventorflow.conf >/dev/null 2>&1
systemctl restart apache2
ok "Apache configuré sur le port ${APP_PORT}"

# 6. Vérifications
log "--- Vérifications finales..."
PHP_VER=$(php -r 'echo PHP_VERSION;')
ok "PHP : ${PHP_VER}"

USERS=$(mysql -u "${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" -sN -e "SELECT COUNT(*) FROM users;" 2>/dev/null)
ok "MariaDB : ${USERS} user(s) en base"

sleep 1
HTTP=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:${APP_PORT}/" 2>/dev/null || echo "---")
if [[ "$HTTP" =~ ^(200|302)$ ]]; then
    ok "HTTP ${HTTP} — Application accessible"
else
    echo "[WARN] HTTP ${HTTP} — vérifier Apache"
fi

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║   Installation terminée avec succès !    ║"
echo "╠══════════════════════════════════════════╣"
echo "║  URL    : ${APP_URL}"
echo "║  Login  : admin"
echo "║  Pass   : ${ADMIN_PASS}"
echo "╚══════════════════════════════════════════╝"
