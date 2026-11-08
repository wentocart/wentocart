# Extensions

Installable apps live on disk as:

```text
extensions/{vendor}/{name}/
```

Merchants manage them in **Design → Extensions**.

## Shipped in this repository

| ID | Author | Fresh install |
| --- | --- | --- |
| `weblytic/mainmenu` | Weblytic Labs | Enabled (storefront mega menu) |

All other extensions are installed from the WentoCart marketplace. They are not included in this source tree.

## For extension authors

| Path | Role |
| --- | --- |
| `tests/Fixtures/extensions/wento/sample-kit` | Reference Safe app for tests |
| `php artisan wento:make-app acme/hello-banner` | Scaffold a new app |
| `php artisan wento:app-pack vendor/name` | Build an installable zip |

Handbook: [docs/extensions/README.md](../docs/extensions/README.md)
