# Extension structure (required)

Install rejects any Safe zip that does not match this tree. See `App\Domain\Plugin\StructureValidator`.

## Zip layout

```text
{vendor}-{name}.zip          # pack name is usually {name}.zip
├── app.json                 # required
├── README.md                # required (what / setup / capabilities)
├── preview.svg              # recommended (or path listed in app.json preview)
├── src/
│   ├── Plugin.php           # required — only boot entry
│   ├── Http/                # optional — *Controller.php only (one level)
│   └── Support/             # optional — pure helpers, no Laravel
└── assets/                  # optional — only with admin.assets or storefront.pages
    ├── admin.css
    └── admin.js
```

`app.json` may sit at the zip root **or** inside a single wrapper folder. Prefer root.

### Allowed top-level only

- Files: `app.json`, `README.md`, and the preview image (`preview.svg` / `.png` / `.jpg` / `.jpeg` / `.webp`)
- Directories: `src/`, `assets/`

Anything else (tests/, node_modules/, React/, random PHP at root) → **reject**.

### Under `src/`

| Path | Rule |
| --- | --- |
| `Plugin.php` | Required. Class `Modules\{VendorStudly}\{NameStudly}\Plugin` |
| `Http/{Name}Controller.php` | Controllers only; suffix `Controller`; one directory level |
| `Support/*.php` | Optional helpers; no Laravel / Illuminate / DB |

No other folders under `src/`. No PHP outside `src/`.

## Naming

| Thing | Rule | Example |
| --- | --- | --- |
| `vendor` / `name` | `^[a-z][a-z-]{1,32}$` | `demo` / `product-enquiry` |
| Extension id | `{vendor}/{name}` | `demo/product-enquiry` |
| Namespace | `Modules\{VendorStudly}\{NameStudly}` | `Modules\Demo\ProductEnquiry` |
| Entry class | `…\Plugin` extends `BasePlugin` | `Modules\Demo\ProductEnquiry\Plugin` |
| Controllers | `Modules\…\Http\{Page}Controller` | `…\Http\CreateRuleController` |
| Page / menu ids | `kebab-case` | `create-rule` |
| Settings keys | `snake_case` | `button_label` |

Studly = split on `-` / `_`, capitalize words, join (`product-enquiry` → `ProductEnquiry`).

## Forbidden

- Laravel routes, middleware, Inertia React, Blade for core screens
- Class interceptors / arbitrary hooks
- Patching core files
- PHP outside `src/`
- JS outside `assets/` (Safe)
- Calling `Illuminate\`, `DB::`, `$_GET`, `eval`, filesystem, curl (Safe scanner)
