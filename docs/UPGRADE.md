# Upgrades

Upgrade a live WentoCart shop from one release to another (for example **1.0.0 → 1.0.1**) without losing catalog, orders, or customer data.

Fresh installs still load `database/schema/mysql-schema.sql`. Live shops apply **additive** patches from `database/upgrades/` only. The full dump is never re-imported on a live database.

A one-click Autoupgrade admin extension is planned for a later release. Backup, upgrade, restore, and recovery already ship in core.

## Paths

| Path | Use when |
| --- | --- |
| `php artisan wento:upgrade` | Normal SSH or agency path (recommended) |
| `php artisan wento:upgrade-restore` | Restore a local backup from the CLI |
| `/wento-recovery.php` | Admin or storefront is broken after a failed upgrade |

## Recommended flow (CLI)

```bash
php artisan wento:upgrade --dry-run
php artisan wento:upgrade --backup --force
php artisan wento:upgrade-restore {backup-id} --force
```

With `--backup`, archives are written under `storage/app/upgrades/backups/{id}/` (`database`, `core.zip`, `recovery-credentials.txt`, `manifest.json`).

Signed-in admin API routes under `/admin/platform-upgrade/*` can also create backups, apply upgrades, and restore.

## Emergency recovery

If admin pages fail after an upgrade:

1. Open `https://your-shop.example/wento-recovery.php`
2. Enter the **recovery token** from `recovery-credentials.txt`
3. Select the backup and restore

This script does **not** load the full Laravel admin. It only needs PHP, the database credentials in `.env`, and files under `storage/app/upgrades/backups/`.

## What survives

Kept across upgrades: `.env`, `storage/` (uploads), installed extensions and their settings, child themes, and `config/admin-path.php`.

Customize through **extensions** and **child themes**, not by editing core `app/` files.

## Extension upgrades

- **Install Extension** (zip upload): a new module is installed and left for you to **enable**. The same or an older version shows “already installed”. A newer version **stages** the zip; click **Upgrade** on the row to apply (settings are kept).
- **Reset** uninstalls (clears settings), then reinstalls from the staged zip if present, otherwise from on-disk files, and enables again.

## Developer notes

- Bump `config('wento.version')` for each release.
- Add a class under `database/upgrades/` (for example `V1_0_1_AddFoo.php`) that implements `UpgradePatch`.
- Refresh schema dumps for **new** installs with `php package/dump-install-schema.php`.
