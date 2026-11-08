# Themes

**Classic** is the only bundled storefront. Merchants can upload extra theme zips from **Design → Themes** (child overlays or full `spa-dist` replacements). Each shop stores the active theme on `stores.theme`.

`default` on `stores.theme` means Classic.

## Child vs full zip

| Zip | `theme.json` | Result |
| --- | --- | --- |
| Extend Classic | `"parent": "classic"` | Classic React templates stay. Child `settings` and `assets/overrides.css` restyle the shop. |
| Full override | no `parent`, `"engine": "spa-dist"` | Built `dist/` (or `assets.js`) replaces Classic completely. |

## theme.json

Child overlay example:

```json
{
  "name": "dusk",
  "display_name": "Dusk",
  "version": "1.0.0",
  "author": "Studio",
  "parent": "classic",
  "engine": "react-vite",
  "description": "Dark accent on Classic.",
  "settings": {
    "announcement": "Members week",
    "colors": { "accent": "#0f172a", "accent_soft": "#e2e8f0" }
  },
  "assets": { "css": ["assets/overrides.css"] }
}
```

Full override example:

```json
{
  "name": "aurora",
  "display_name": "Aurora",
  "version": "1.0.0",
  "engine": "spa-dist",
  "description": "Custom built storefront.",
  "assets": { "css": ["assets/app.css"], "js": ["assets/app.js"] }
}
```

The name must be a lowercase slug. Do not upload a zip named `classic`.

Allowed files: HTML, CSS, JS, fonts, images, JSON, and SVG. No PHP.

## Theme settings (admin)

**Design → Theme settings** stores a per-shop overlay on `stores.theme_settings`. It merges over Classic (or child) `theme.json` defaults.

Merchants can:

- Pick a color preset (Forest, Purple, Marketplace) or custom colors
- Edit announcement, logo text, font, and radius
- Toggle homepage sections (hero, trust, categories, promos, products, brands, testimonials, newsletter)
- Edit hero copy, promo banners, the trust row, testimonials, and newsletter text

Reset clears the shop overlay and falls back to the active theme’s `theme.json` plus built-in Classic defaults.

Classic covers home, category (filters in the URL), product, search, cart, checkout, order success, account, orders, wishlist, login, register, compare, and CMS. Product pages include moderated reviews and Q&A. The header currency switcher follows **Localization → Currencies**.

Category query example: `/headphones?brand=sony&price=10000-25000&rating=4`

## Git vs what the browser serves

| Tracked in git (edit these) | Gitignored runtime copy |
| --- | --- |
| `themes/classic/**` | `public/themes/**` (Laravel serves `/themes/classic/...` from here) |
| `storefront/src/**`, `resources/js/**`, `resources/css/**` | `public/build/**` (Vite hashed assets) |

`public/themes` is ignored on purpose (install copies, merchant zip size). If you change images or CSS only under `public/themes`, **git will not ship them**. Put the files in `themes/classic`, commit, and push. On the other machine:

```bash
git pull
php artisan wento:theme-publish
npm run build
```

Skip `npm run build` if you already run `npm run dev`.

`php artisan wento:doctor` and a normal artisan or HTTP boot also incremental-publish Classic, so a pull of new theme files shows up without a full reinstall.
