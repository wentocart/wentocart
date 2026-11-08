# Upgrade

Move a live WentoCart shop from one release to another (for example **1.0.0 → 1.0.1**) without losing catalog, orders, or customers.

Fresh installs load `database/schema/mysql-schema.sql`. Live shops apply **additive** patches from `database/upgrades/` only. The full schema dump is never re-imported on a live database.

## Recommended path

```bash
php artisan wento:upgrade --dry-run
php artisan wento:upgrade --backup --force
```

| Command | Use when |
| --- | --- |
| `php artisan wento:upgrade` | Normal SSH or agency upgrade |
| `php artisan wento:upgrade-restore {id}` | Restore a local backup from the CLI |
| `/wento-recovery.php` | Admin or the storefront will not boot after a failed upgrade |

With `--backup`, archives are written under `storage/app/upgrades/backups/{id}/` (`database`, `core.zip`, `recovery-credentials.txt`, `manifest.json`).

## Emergency recovery

If admin pages fail after an upgrade:

1. Open `https://your-shop.example/wento-recovery.php`
2. Enter the recovery token from `recovery-credentials.txt`
3. Select the backup and restore

This script does not load the full Laravel admin. It needs PHP, the database credentials in `.env`, and files under `storage/app/upgrades/backups/`.

## What is kept

`.env`, `storage/` (uploads), installed extensions and their settings, child themes, and `config/admin-path.php`.

Customize through **extensions** and **child themes**, not by editing core `app/` files.

More detail: [docs/UPGRADE.md](docs/UPGRADE.md).
