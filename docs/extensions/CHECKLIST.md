# Extension pre-upload checklist

Use this before `wento:app-pack` and before uploading to a shop.

## Structure

- [ ] Tree matches [STRUCTURE.md](STRUCTURE.md) (`app.json`, `README.md`, `src/Plugin.php`)
- [ ] No files outside allowed top-level names
- [ ] Namespace is `Modules\{VendorStudly}\{NameStudly}`
- [ ] Controllers are `src/Http/*Controller.php` only
- [ ] `README.md` explains what the extension does and how to set it up

## Capabilities

- [ ] Capabilities match code (`catalog.write` if you call `$this->catalog()`, …)
- [ ] Own-data cleanup uses `actions()->after(...)` where needed
- [ ] No Laravel routes, Inertia, or core patches
- [ ] Settings keys are `snake_case`
- [ ] Page ids are `kebab-case`
- [ ] Read [SECURE-AND-OPEN.md](SECURE-AND-OPEN.md)
- [ ] Admin lists use `kpis` (four cards) + `list`; storefront CTAs follow [UI.md](UI.md)

## Pack and install

```bash
php artisan wento:app-pack vendor/name
```

- [ ] Zip installs without StructureValidator / SecurityScanner errors
- [ ] Enable succeeds; health is OK
- [ ] Manual smoke: admin pages + storefront path you care about

## Reference

Bundled reference: `extensions/weblytic/mainmenu`. Other modules come from the marketplace.
Handbook index: [README.md](README.md)
