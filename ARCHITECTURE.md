# Architecture d'InventorFlow

Vue d'ensemble technique pour les contributeurs. Pour l'arborescence des fichiers, voir [README — Structure du projet](README.md#-structure-du-projet).

## Principe général

Application web **PHP sans framework** + **MariaDB**, servie par Apache/nginx (PHP-FPM) ou l'image Docker (PHP-Apache). Le front est en **HTML/CSS + Vanilla JS**, sans étape de build. Un **agent** léger (bash/PowerShell) déployé sur les postes remonte les métriques.

```
Postes (agent bash/PS) ──HTTPS──┐
                                 ▼
   Navigateur ──HTTP──►  Apache/PHP-FPM  ──►  MariaDB
                                 │
                                 └─ deploy.php (génère l'agent par clé client)
```

## Flux d'une requête

1. Chaque page/endpoint commence par `require config/app.php`, qui : démarre la session, charge `config/db.php` (connexion PDO via `db()`), `includes/auth.php`, `includes/functions.php`, et met en cache les `app_settings`.
2. `require_auth()` vérifie la session (redirige vers `login.php` ou renvoie 401 en JSON pour `/api/`).
3. Les **pages** (`pages/`) rendent le HTML via les helpers de `includes/layout.php` (`render_head`, `render_sidebar`, `render_topbar`, `render_footer`).
4. Le JS appelle les **endpoints** (`api/`) qui répondent en JSON (`json_success` / `json_error`).

## Couches

| Couche | Rôle |
|--------|------|
| `config/` | Connexion DB (`db.php`, généré à l'install) + bootstrap applicatif (`app.php`) |
| `includes/` | `auth.php` (sessions), `totp.php` (2FA RFC 6238, PHP pur), `ldap.php` (annuaire), `layout.php` (rendu), `functions.php` (helpers, `h()`, `json_*`) |
| `api/` | Endpoints REST/JSON par domaine (assets, licences, monitoring, billing, restore, totp…) |
| `pages/` | Vues (parc, supervision, licences, facturation, rapports, paramètres…) |
| `assets/` | CSS (thèmes dark/acid/aurora) + JS (`api()`, `toast()`, `Modal`) |
| `agent/` | Agent de supervision universel (bash macOS/Linux + PowerShell Windows) |

## Authentification

- **Locale** : mot de passe **bcrypt** vérifié en base, session PHP.
- **TOTP (2FA)** : `login()` peut renvoyer `'totp_required'` → 2ᵉ étape (`login-totp.php`) qui valide un code à 6 chiffres ou un code de secours. Politique globale : désactivé / optionnel / obligatoire.
- **LDAP/LDAPS** : un compte marqué `auth_source = ldap` délègue la vérification du mot de passe à l'annuaire ; le compte local conserve rôle et `client_id`.
- **Rôles** : `superadmin` (tous clients) / `admin` / `viewer`.

## Multi-client

Toutes les entités portent un `client_id`. Un super-administrateur bascule de client (sélecteur) ; les comptes `admin`/`viewer` sont cloisonnés à leur client. Chaque client dispose d'une **clé de déploiement** pour enrôler ses agents.

## Supervision

`deploy.php?key=<clé client>` génère un agent auto-installable. L'agent remonte périodiquement CPU/RAM/disque/température/uptime à `api/monitoring.php`, et détecte les tentatives de brute-force SSH. L'agent s'auto-met à jour en comparant sa version à `api/agent-version.php`.

## Déploiement & mises à jour

- **Bare-metal** : `install/install.sh` (idempotent) pour une **installation neuve** ; `maintenance/update.sh` (ou le bouton « Administration → Mises à jour ») pour les **mises à jour non destructives** (sauvegarde avant migration, `config/` et `backups/` préservés).
- **Docker** : `docker compose up` (image officielle GHCR ou build local). L'`entrypoint` génère la config, importe le schéma au 1ᵉʳ démarrage et applique les migrations.
- **Migrations** : `migrations/NNN_*.sql` additives, appliquées par `maintenance/migrate.php`, suivi dans `schema_version`.

## Sécurité

- Mots de passe **bcrypt**, requêtes **préparées** (PDO), sortie **échappée** (`h()`).
- En-têtes `X-Content-Type-Options`, `X-Frame-Options` ; `.htaccess` bloque l'accès direct aux `.sql`/`.log`/`.md`.
- Restauration de secours (`recovery.php`) protégée par un mot de passe **dédié, hors base**.
- Les opérations root (update/restore) passent par une **règle sudoers verrouillée** : le serveur web ne peut lancer que `update.sh` et `restore.sh`.
