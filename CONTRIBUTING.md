# Contribuer à InventorFlow

Merci de votre intérêt ! Ce document explique comment contribuer efficacement.

## Démarrer un environnement de développement

**Avec Docker (recommandé) :**
```bash
cp .env.example .env
docker compose up -d --build      # build local depuis vos modifications
```
➜ http://localhost:8080 — `admin` / valeur de `ADMIN_PASS`.

**En bare-metal** (Debian/Ubuntu) : voir [`install/install.sh`](install/install.sh) et le [README](README.md).

## Pile technique

PHP 8.4 (PDO, **sans framework**) · MariaDB/MySQL · Apache/nginx + PHP-FPM · HTML/CSS (dark mode) + **Vanilla JS**. Aucune étape de build front : le code est servi tel quel.

Voir **[ARCHITECTURE.md](ARCHITECTURE.md)** pour la vue d'ensemble et **[README — Structure du projet](README.md#-structure-du-projet)** pour l'arborescence.

## Conventions de code

- **PHP** : `declare`/typage quand c'est utile, requêtes **toujours préparées** (PDO), jamais de concaténation SQL avec des entrées utilisateur.
- **Sortie HTML** : échapper systématiquement avec `h()` (htmlspecialchars).
- **API** : endpoints dans `api/`, réponses via `json_success()` / `json_error()`, vérification `require_auth()` (+ `is_superadmin()` si besoin) en tête.
- **JS** : helpers existants `api()`, `toast()`, `Modal` — pas de dépendance lourde. Ne **jamais** appeler `api()`/`toast()` au niveau racine d'un script de page (chargés via `app.js` en fin de page) : différer dans `DOMContentLoaded`.
- **Multi-client** : filtrer les données par `client_id` sur toutes les vues non-superadmin.
- **Secrets / données** : aucune donnée réelle, IP interne ou identifiant ne doit figurer dans le dépôt. `config/db.php`, `config/recovery.php`, `backups/` sont ignorés.

## Base de données & migrations

Toute évolution de schéma passe par une migration **additive et idempotente** :

```
migrations/NNN_description.sql      # NNN = numéro croissant
```
- Uniquement `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, `INSERT IGNORE`… **Jamais** de `DROP`/`DELETE` de données utilisateur.
- Appliquées par `maintenance/migrate.php` (suivi dans la table `schema_version`).

## Workflow de contribution

1. Forkez le dépôt et créez une branche : `git checkout -b feat/ma-fonctionnalite`.
2. Développez ; testez en local (Docker) avant de pousser.
3. Vérifiez : pas de secret commité, migrations idempotentes, sortie échappée.
4. Ouvrez une **Pull Request** vers `main` avec une description claire (le « pourquoi » autant que le « quoi »).

## Sécurité

Merci de **ne pas** ouvrir d'issue publique pour une faille de sécurité — contactez le mainteneur en privé.

## Licence

En contribuant, vous acceptez que votre code soit distribué sous licence **GNU AGPL-3.0** (voir [LICENSE](LICENSE)).
