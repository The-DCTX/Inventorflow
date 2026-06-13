#!/bin/bash
# Préparation au démarrage du conteneur app : génère la config depuis les
# variables d'environnement, initialise la base au 1er lancement, applique les
# migrations, puis lance la commande (Apache).
set -e

APP=/var/www/html
DB_HOST="${DB_HOST:-db}"
DB_NAME="${DB_NAME:-inventorflow}"
DB_USER="${DB_USER:-inventorflow}"
DB_PASS="${DB_PASS:-inventorflow}"
ADMIN_PASS="${ADMIN_PASS:-AdminTest2024!}"
RECOVERY_PASS="${RECOVERY_PASS:-}"

mysql_app() { mysql -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" "$@"; }

# 1) config/db.php — identique à install.sh, valeurs injectées par l'environnement.
cat > "$APP/config/db.php" <<PHP
<?php
define('DB_HOST',    '${DB_HOST}');
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

# 1b) config/app.php — bootstrap applicatif (identique à install.sh), avec cookie
#     de session durci (HttpOnly + SameSite=Lax ; Secure auto derrière un proxy HTTPS).
APP_VER="$(tr -cd '0-9.' < "$APP/VERSION" 2>/dev/null || true)"; APP_VER="${APP_VER:-1.0.0}"
cat > "$APP/config/app.php" <<PHP
<?php
define('APP_VERSION',  '${APP_VER}');
define('APP_URL',      '');
define('SESSION_NAME', 'inventorflow_session');
session_name(SESSION_NAME);
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => (!empty(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] !== 'off')
                  || ((\$_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    'path'     => '/',
]);
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
define('APP_SERVER_URL', app_setting('app_url', ''));
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
PHP

# 2) Mot de passe de restauration de secours (optionnel, via l'environnement).
if [ -n "$RECOVERY_PASS" ]; then
    php -r '$h=password_hash(getenv("RECOVERY_PASS"),PASSWORD_DEFAULT);
            file_put_contents("/var/www/html/config/recovery.php",
            "<?php\ndefine(\"RECOVERY_HASH\", ".var_export($h,true).");\n");'
fi

# 3) Attendre que MariaDB réponde.
echo "[entrypoint] Attente de MariaDB ($DB_HOST)…"
for _ in $(seq 1 60); do
    mysql_app -e "SELECT 1" >/dev/null 2>&1 && break
    sleep 2
done

# 4) Initialiser le schéma si la base est vide (table users absente).
if ! mysql_app -e "SELECT 1 FROM users LIMIT 1" >/dev/null 2>&1; then
    echo "[entrypoint] Base vide → import du schéma + compte admin…"
    mysql_app < "$APP/install/install.sql"
    HASH=$(php -r "echo password_hash('${ADMIN_PASS}', PASSWORD_DEFAULT);")
    mysql_app -e "UPDATE users SET \`password\`='${HASH}' WHERE username='admin';"
fi

# 5) Migrations additives (idempotentes).
[ -f "$APP/maintenance/migrate.php" ] && php "$APP/maintenance/migrate.php" || true

# 6) Permissions sur les dossiers inscriptibles.
chown -R www-data:www-data "$APP/config" "$APP/backups" 2>/dev/null || true

echo "[entrypoint] Prêt."
exec "$@"
