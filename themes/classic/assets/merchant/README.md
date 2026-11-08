# Merchant media (only photos shipped with the product)

All storefront product, banner, and promo photographs live under this folder.

They are the merchant’s own assets for demo installs. Do **not** add third-party sample catalogs here.
These first-party assets may be used with WentoCart under the repository's
root `LICENSE`; no third-party stock-photo license or network download is required.

| Path | Role |
|------|------|
| `products/` | 33 product covers |
| `categories/` | Transparent product cutouts for featured category tiles |
| `brands/` | 24 demo brand logos (`{slug}.svg`) — well-known marks for dummy data only; trademarks belong to their owners |
| `banners/` | Home promo tiles + HD category heroes/covers |
| `home/` | Hero slides + side cards (copied from banners 1–3, 18–19) |
| `promo/` | Extra promo stills |
| `ui/` | First-party SVG chrome (logo, payment strip, reassurance icons) |

Rebuild catalog + theme from this pack:

```bash
php artisan wento:merchant-store --force
```

Category images are assigned to the category image, thumbnail, banner, and cover
fields during demo installation. Featured categories use transparent cutouts so
the theme controls the tile background without a baked-in grey rectangle. This
keeps category carousels and category pages fully populated in a fresh offline
install.
