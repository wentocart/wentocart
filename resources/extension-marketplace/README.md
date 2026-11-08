# WentoCart Extension Marketplace

This repository ships **Main Menu** (`weblytic/mainmenu`) only. It is enabled on a fresh install so the storefront has navigation.

Other extensions (payments, shipping, and the rest of the Weblytic catalog) are not included in source. Merchants install them from **Admin → Extensions** / the official marketplace. They may be free, but they are obtained from the marketplace — not copied from this repository.

## How it works

1. `catalog.json` lists the bundled shelf.
2. `GET /api/v1/extensions/marketplace` returns that list plus any packages present under `extensions/`.
3. Marketplace downloads land in `extensions/{vendor}/{name}/` after the merchant installs them.
