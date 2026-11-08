# Extensions

Merchant label: **Extensions** (Admin → Design → Extensions). In code the domain is still Plugin / Apps. Tables stay `plugins`, `plugin_settings`, and `plugin_store`.

**Apps** is the same runtime: same zip, scanner, and kernel.

This page is the short developer contract. Full handbook (structure, coding standard, extension points, checklist): [extensions/README.md](extensions/README.md).

Payment and shipping provider packages use reserved certified capabilities. See [Reserved certified capabilities](#reserved-certified-capabilities-extension-points-only).

## Install path

**Admin → Extensions → Upload ZIP → Validate → Install (disabled) → Enable → Configure**

A Safe extension integrates only through official APIs. Do not edit WentoCart core files to make an extension work. Extension zips must never overwrite core.

## Zip layout

Required tree (install rejects anything else): see [extensions/STRUCTURE.md](extensions/STRUCTURE.md).

```text
my-extension.zip
├── app.json              # required
├── README.md             # required
├── preview.svg           # recommended
├── src/
│   ├── Plugin.php        # required — Modules\{Vendor}\{Name}\Plugin
│   ├── Http/             # optional — *Controller.php
│   └── Support/          # optional
└── assets/               # optional — only on this extension’s hosted pages
```

`app.json` may sit at the zip root or inside a single wrapper folder. Prefer root.

## Generate and pack

```bash
php artisan wento:make-app acme/hello-banner --title="Hello banner"
# → extensions/acme/hello-banner/

php artisan wento:app-pack acme/hello-banner
# → plugin-packs/acme-hello-banner.zip
```

Upload from **Extensions**. Enable is a second step. Work through [extensions/CHECKLIST.md](extensions/CHECKLIST.md) before upload.

## app.json

### Required

| Field | Notes |
| --- | --- |
| `vendor`, `name` | Lowercase slugs → id `vendor/name` |
| `display_name` | Merchant title |
| `version` | Semver string |
| `author` | Credit line |
| `description` | Short summary |
| `type` | `safe` or `certified` |
| `capabilities` | From the published catalog |

### Conditionally required

| Field | When |
| --- | --- |
| `hooks` | When using `hook.listen` |

### Optional (framework)

| Field | Notes |
| --- | --- |
| `manifest_version` | Default `1` |
| `category` | `payment`, `shipping`, `tax`, `analytics`, `marketing`, `auth`, `storage`, `admin`, `business`, `other` (UI only) |
| `min_app_version` / `max_app_version` | Platform compatibility (`config('wento.version')`) |
| `compatible_with` | Alias for max when max unset |
| `requires` | Map of `vendor/name` → constraint (`>=1.2.0`, `^1.0`, `~1.2`, `*`, exact) |
| `conflicts` | List of extension ids |
| `setup_steps` | Strings shown on Configure |
| `preview` | Relative image path |

Entry class is always `Modules\{Vendor}\{Name}\Plugin` extending `BasePlugin`.

## Lifecycle

Discover on disk → Install (DB, usually disabled) → Enable → Configure → Run → Disable → Uninstall (keep files) → Delete (remove `extensions/{vendor}/{name}`)

Zip path: Upload → Validate (structure + security) → extract into `extensions/` → same lifecycle.

- Enable re-checks platform + dependencies (required deps must be enabled).
- Disable / uninstall blocked while another **enabled** extension requires this id.
- Update refuses identity mismatch.

## Dependencies

Simple resolver (not Composer). Cycles are detected and blocked. Invalid deps block install / enable / update.

## Security

- Extract confined to temp (`storage/app/plugin-uploads/`) then `extensions/{vendor}/{name}` only
- Path traversal rejected; vendor/name must match `^[a-z][a-z-]{1,32}$`
- PHP only under `src/`; Safe scanner blocks eval/shell/Illuminate/DB/superglobals/…
- Certified unsigned zips rejected unless signed / payment-shipping community allowlist / `wento.apps.allow_certified_unsigned`
- Zips never write into core application paths or `public/`

## Official integration surfaces

Menus, hosted pages, list columns, view/storefront slots, published hooks/actions, settings, ModuleQuery reads, and **catalog.write** product APIs — see [extensions/SECURE-AND-OPEN.md](extensions/SECURE-AND-OPEN.md) and [extensions/EXTENSION-POINTS.md](extensions/EXTENSION-POINTS.md).

## Reserved certified capabilities (extension points only)

These capabilities exist in the catalog for a **later** certified lane. Do not implement Stripe, PayPal, or shipping-provider logic in core in this phase.

| Capability | Future meaning |
| --- | --- |
| `payment.driver` | Register a generic payment driver behind core’s payment contract |
| `shipping.driver` | Register a generic shipping/carrier driver |
| `http.outbound` | Allowlisted outbound HTTP |
| `schema.tables` | Prefixed extension-owned tables |
| `orders.read` | Controlled order reads |

Building a real payment extension is a **separate project** after this framework is stable.

## Related

- Handbook: [extensions/README.md](extensions/README.md)
- Bundled module: `extensions/weblytic/mainmenu`
- Other modules: install from the WentoCart marketplace
- Test fixtures: `tests/Fixtures/extensions/wento/sample-kit`, `sample-notice`
