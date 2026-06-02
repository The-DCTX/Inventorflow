# Migrations de base de données

Migrations **additives et idempotentes** appliquées par `maintenance/migrate.php`
(lui-même appelé par `maintenance/update.sh`).

## Règles

- **Jamais de destruction.** Uniquement `CREATE TABLE IF NOT EXISTS`,
  `ALTER TABLE ... ADD COLUMN ...`, ajout d'index, insertion de données de
  référence avec `INSERT IGNORE` / `ON DUPLICATE KEY UPDATE`.
  Pas de `DROP`, pas de `TRUNCATE`, pas de `DELETE` de données utilisateur.
- **Nommage** : `NNN_description.sql` où `NNN` est un entier croissant
  (`001_…`, `002_…`). L'ordre d'application suit ce numéro.
- **Idempotent** : une migration doit pouvoir être relancée sans casse
  (utilisez `IF NOT EXISTS`). Chaque numéro n'est appliqué qu'une fois
  (suivi dans la table `schema_version`).

## Exemple

```sql
-- 001_example_add_column.sql
ALTER TABLE assets ADD COLUMN IF NOT EXISTS notes TEXT NULL;
```

## Commandes

```bash
php maintenance/migrate.php --status   # état (appliquées / en attente)
php maintenance/migrate.php            # applique les migrations en attente
```
