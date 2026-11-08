# Extension developer handbook

WentoCart extensions live under **`extensions/{vendor}/{name}/`**. Merchants manage them from **Design → Extensions**.

- Short contract: [EXTENSIONS.md](../EXTENSIONS.md)

## Build your first extension

```bash
php artisan wento:make-app acme/hello-banner --title="Hello banner"
# → extensions/acme/hello-banner/

php artisan wento:app-pack acme/hello-banner
# → plugin-packs/acme-hello-banner.zip
```

Then **Admin → Design → Extensions → Upload → Enable**.

## Handbook

| Doc | What it covers |
| --- | --- |
| [STRUCTURE.md](STRUCTURE.md) | Required folders, file names, and namespaces |
| [CODING-STANDARD.md](CODING-STANDARD.md) | PHP rules, `BasePlugin`, menus, settings, and lists |
| [CHECKLIST.md](CHECKLIST.md) | Pre-upload checklist |
| [EXTENSION-POINTS.md](EXTENSION-POINTS.md) | Published slots, lists, hooks, and actions |
| [STOREFRONT.md](STOREFRONT.md) | Account menu, shopper pages, and storefront slots |
| [UI.md](UI.md) | How hosted admin and storefront pages should look |
| [SECURE-AND-OPEN.md](SECURE-AND-OPEN.md) | What a Safe zip may and may not do |

## Rules

A zip may only use published menus, pages, slots, lists, hooks, and write APIs. Core validates the zip. Do not register Laravel routes, Inertia React pages, class interceptors, arbitrary hooks, or raw `DB::`.

Install rejects zips that break [STRUCTURE.md](STRUCTURE.md).

| Path | Role |
| --- | --- |
| `extensions/{vendor}/{name}/` | On-disk home for a module |
| `plugin-packs/{vendor}-{name}.zip` | Local pack output (gitignored) |
| `storage/app/plugin-uploads/` | Temporary unpack folder |

Vendor and name must match `^[a-z][a-z-]{1,32}$`.
