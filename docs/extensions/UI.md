# Extension UI cookbook

Zip authors **do not** import admin React (`KpiCards`, `Button`, Tailwind). Core draws every hosted screen from a **page document**. Copy the arrays below.

**See it live:** Admin sidebar → **UI kit** (`/admin/ui-kit`) → sections **10. List KPIs** and **11. Extensions**. Core contributors copy the JSX; zip authors copy the PHP in those snippets.

Related: [CODING-STANDARD.md](CODING-STANDARD.md) (fields, lists, icons, uploads) · [STOREFRONT.md](STOREFRONT.md) (slots) · [EXTENSION-POINTS.md](EXTENSION-POINTS.md) (slot ids)

---

## Where to look

| You want | Admin (hosted page) | Storefront |
| --- | --- | --- |
| Four pastel KPI cards (Products list look) | `'kpis' => [ … max 4 … ]` on `$this->page()` | not used |
| Catalog table + bulk | `'list' => [ … ]` | Classic shopper table: same `'list'` key (search / sort / pager / row buttons). No bulk. |
| Solid CTA | `type: button` + **`href` or `modal`** | same, in a published slot |
| Next to **Add to cart** | — | slot `storefront.product.actions`, omit `variant` (primary) |
| Next to Compare / Wishlist | — | same slot, `'variant' => 'secondary'` |
| Settings fields | `'fields'` / `'tabs'` | — |
| Language switcher on one field | `'localized' => true` | — |
| Image / PDF | `'type' => 'image'` / `'file'` | — |

---

## KPIs (admin lists)

Core lists always show **exactly four** pastel cards (`KpiCards`). Hosted pages use the same component when you return `kpis`. Extra rows are dropped. Missing `trend` is `0`.

```php
return $this->page([
    'title' => 'Enquiries',
    'kpis' => [
        ['label' => 'Total', 'value' => (string) $total, 'trend' => 2.1],
        ['label' => 'Open', 'value' => (string) $open, 'trend' => -0.4],
        ['label' => 'Closed', 'value' => (string) $closed, 'trend' => 1.0],
        ['label' => 'Reply rate', 'value' => $rate.'%', 'trend' => 0],
    ],
    'list' => [
        'columns' => [/* … */],
        'rows' => [/* … */],
        'bulk' => 'catalog',
    ],
]);
```

Optional keys: `key` (stable id), `tone` (`sky` / `emerald` / `violet` / `amber`). You may put `kpis` on the `list` array instead; core still lifts them to the page.

Overview (no table): return `kpis` plus `blocks`, or a block `['type' => 'kpis', 'items' => [ … ]]`.

Do **not** draw your own four boxes in HTML/CSS.

---

## Buttons and links

### Admin

Core renders `button` and `link` with the shared `<Button>` (same as Products). Variants: `primary`, `secondary`, `outline`, `ghost`.

A **button without `href` or `modal` does nothing**. Always set one:

```php
['type' => 'button', 'label' => 'View list', 'href' => '/admin/modules/acme/help/list', 'icon' => 'arrow-right'],
['type' => 'link', 'label' => 'Docs', 'href' => '/admin/modules/acme/help/help', 'variant' => 'secondary'],
```

`href` must pass `PageDocument` sanitizer (shop paths such as `/admin/modules/{vendor}/{name}/…` or `/m/…`).

### Storefront (Classic)

| `variant` | Look | Use |
| --- | --- | --- |
| omit / `primary` in `storefront.product.actions` | Same height, fill, and accent as **Add to cart** (`shop-pdp2-cart`) | Buy-box CTA |
| `secondary` / `outline` / `ghost` in that slot | Same text row as **Compare** / **Wishlist** | Quiet action |
| `primary` in other slots | `shop-btn shop-btn-primary` | Home, account, footer |
| `secondary` / `outline` | `shop-btn shop-btn-secondary` | Secondary CTA |
| `ghost` | `shop-btn shop-btn-ghost` | Tertiary |

```php
$this->slots()->on('storefront.product.actions', function () {
    return [
        [
            'type' => 'button',
            'label' => 'Ask a question',
            'href' => '/m/acme/help/ask',
            'icon' => 'help',
        ],
        [
            'type' => 'button',
            'label' => 'Size guide',
            'href' => '/m/acme/help/size',
            'variant' => 'secondary',
        ],
    ];
});
```

Need a form overlay? Keep `modal` (submit URL + fields). Primary + modal in the buy box also uses the Add to cart look.

You cannot restyle Classic with zip CSS on the buy box. Matching Add to cart means **primary + this slot**, not a custom class.

### Storefront hosted lists

Classic paints `document.list` on `/m/{vendor}/{name}/{page}` (`ModuleList`). Keys: `search`, `per_page_options`, `page`, `last_page`, `total`, `sort`, `dir`, column `sort` / `badge`, row `actions` / `{key}_tone`. Do not import admin `Table`.

---

## Admin blocks cheat sheet

| `type` | Keys |
| --- | --- |
| `kpis` | `items` (same shape as page `kpis`) |
| `button` | `label`, `href` and/or `modal`, `variant`, `icon` |
| `link` | `label`, `href`, `variant`, `icon` |
| `text` | `text` |
| `section` | `title`, `text` |
| `card` | `title`, `text`, `image`, `href` |
| `table` | `columns`, `rows` (read-only; prefer `list` for Catalog chrome) |

---

## Core vs zip

| Who | How |
| --- | --- |
| Core admin pages | `import { Button, KpiCards } from '../../ui'` — copy `/admin/ui-kit` |
| Extension PHP | Page documents only. Never import `resources/js/admin/ui` |

If a hosted screen does not look like Products, the document is missing a key (`kpis`, `list`, `button`+`href`) — do not add a CSS file in the zip.
