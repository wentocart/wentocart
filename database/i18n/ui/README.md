# Bundled UI chrome locale packs

Complete installer translations for admin + storefront labels (`t()` keys).

| File | Language |
|------|----------|
| `fr.json` | French |
| `es.json` | Spanish |
| `it.json` | Italian |
| `ar.json` | Arabic |
| `hi.json` | Hindi |

Each file is `{ "admin.orders.order_status": "…", … }` covering every registered UI key.

## Seed on install

`ShopLocaleDefaults::seedTranslations()` (via `CoreSeeder` / fresh install) loads these packs into `ui_string_translations`.

Manual re-seed:

```bash
php artisan wento:i18n-seed-packs --force-sync
```

## Rebuild after new `t()` keys

```bash
php artisan wento:i18n-sync --force
# refresh en-source.json from desired strings, then:
python3 database/i18n/ui/build_locale_packs.py
php artisan wento:i18n-seed-packs
```

Merchant edits in **International → Translations** are preserved (non-empty, non-English values are not overwritten).
