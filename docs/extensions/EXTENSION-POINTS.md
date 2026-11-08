# Published extension points

Extensions may only use ids from these catalogs. Unknown ids are refused.

Source of truth in code:

- `App\Domain\Plugin\ExtensionCatalog`
- `App\Domain\Plugin\HookCatalog`
- `App\Domain\Plugin\ActionCatalog`
- `App\Domain\Plugin\Capabilities`

## Capabilities (declare in `app.json`)

### Safe

| Capability | Meaning |
| --- | --- |
| `admin.page` | Core Host settings form under Design → Extensions |
| `admin.menu` | Left-menu group for this extension |
| `admin.pages` | Hosted admin pages (`/admin/modules/...`) |
| `admin.assets` | CSS/JS from `assets/` on this extension’s admin pages |
| `settings` | Store key/value settings |
| `storefront.slot` | Blocks in storefront view slots |
| `view.slot` | Blocks on published admin view pages |
| `storefront.pages` | Storefront pages under `/m/{vendor}/{name}/{page}` |
| `account.menu` | Customer account menu items |
| `list.extend` | Columns on published admin lists |
| `hook.listen` | Published hooks / before-after actions |
| `catalog.read` | ModuleQuery + `catalog()->search()` (product typeahead) |
| `catalog.write` | Create / update / delete simple products via core ProductService |
| `customers.read` | ModuleQuery + `customers()->search()` / `find()` (customer typeahead) |
| `customers.write` | Groups, enable, profile, upsertAddress via core CustomerService |
| `mail.send` | Send email via shop mailer + enabled EmailTemplate |
| `checkout.adjust` | Validated cart fees and due-today amounts |
| `checkout.payment.filter` | Hide payment methods at checkout |
| `checkout.shipping.filter` | Hide shipping methods at checkout |
| `orders.write` | Record payments, fulfillment, and assign orders to staff |
| `documents.pdf` | Generate PDF (optional email attachment via shop mailer) |
| `auth.login.resolve` | Resolve username / alternate login to customer email |
| `auth.register` | Pending approval / post-register hooks |
| `carts.read` | Read abandoned carts (customer email for reminders; never session ids) |
| `carts.write` | Store small allowlisted meta on a cart |
| `schedule.tick` | Timed callbacks when `wento:extensions-tick` runs (cron) |
| `wishlist.read` | Read customer wishlist product ids |
| `wishlist.write` | Add / remove wishlist products |
| `orders.write` | Payments, fulfillment, staff assign, journey status, payment snapshots |

### Certified (later — unsigned zips refused)

`payment.driver`, `shipping.driver`, `http.outbound`, `schema.tables`, `orders.read`

## Storefront / admin view slots (`ExtensionCatalog::views`)

Register with `$this->slots()->on('{id}', function (array $ctx) { … })`.

| Slot id | Where it renders (Classic) | Typical payload |
| --- | --- | --- |
| `storefront.header.top` | Header top | context varies |
| `storefront.header.notice` | Header notice strip | often via hook |
| `storefront.home.body` | Home body | — |
| `storefront.footer` | Footer | — |
| `storefront.product.actions` | Product buy box (next to Add to cart / Wishlist) | `id`, `name`, `category_ids` |
| `storefront.product.below_trust` | Product page, **under** Secure Payments | `id`, `name`, `category_ids` |
| `storefront.product.buybox` | Hide price, quantity, or Add to cart. Return flags, not blocks: `['hide_price' => true, 'hide_qty' => true, 'hide_cart' => true]` | `id`, `name`, `category_ids` |
| `storefront.product.after` | Product page, **after** related / also-like | `id`, `name`, `category_ids` |
| `storefront.product.tabs` | Product tabs area | `id`, `name`, `category_ids` |
| `storefront.category.after` | Category page after listing | category context |
| `storefront.account.menu` | Account menu | Optional `icon`: kit id, emoji, Font Awesome class, image URL, or inline SVG. |
| `storefront.register.fields` | Registration form extra fields (`type: field`) | — |
| `storefront.cart.sidebar` | Cart summary sidebar | totals context |
| `storefront.cart.line` | Under each cart line, and under that line in checkout summary | `id`, `product_id`, `qty`, `name` |
| `storefront.order.detail` | Customer order page (table, pay link, donation) | `id`, `number` |
| `storefront.checkout.payment` | Checkout under payment methods | — |
| `storefront.checkout.shipping` | Checkout under shipping methods (pickup picker) | — |
| `storefront.checkout.delivery` | Checkout delivery-date / rate UI | — |
| `storefront.checkout.sidebar` | Checkout order summary sidebar | — |
| `admin.product.edit` | Admin product edit | product context |
| `admin.order.show.after_payment` | Admin order detail, **after Payment** | `id`, `number` |
| `admin.order.actions` | Admin order header, **before More Actions** (button or link blocks) | `id`, `number` |
| `admin.order.show` | Same region (legacy id; prefer `after_payment`) | `id`, `number` |
| `admin.customer.show` | Admin customer view | customer context |

**Placement rule:** choose a published slot id. Buy-box buttons use `storefront.product.actions`. Broader PDP content uses `storefront.product.after` / `.tabs`. Home, category, header, and footer each have their own ids above. New areas require a core catalog entry + theme render point.

Return a list of document blocks (e.g. `type: button` with `modal`), or `[]` to show nothing.

### Order detail (Store Credit and similar)

Register `admin.order.show.after_payment` with `view.slot`. Core sanitizes blocks. Do not return HTML.

```php
$this->slots()->on('admin.order.show.after_payment', function (array $ctx) {
    return [
        [
            'type' => 'card',
            'title' => 'Store Credit',
            'text' => 'Used on this order: 12.00',
        ],
        [
            'type' => 'table',
            'columns' => [
                ['key' => 'date', 'label' => 'Date'],
                ['key' => 'note', 'label' => 'Note'],
                ['key' => 'amount', 'label' => 'Amount'],
            ],
            'rows' => [
                ['date' => '1 Sep 2026', 'note' => 'Refund voucher', 'amount' => '+60.00'],
            ],
        ],
    ];
});
```

## Admin lists (`list.extend`)

Published list ids: `catalog.products`, `orders.orders`, `customers.customers`.  
Joins and selects are allowlisted in `ExtensionCatalog::lists()`.

## Hooks (`hook.listen` + `hooks` in `app.json`)

| Hook | Notes |
| --- | --- |
| `storefront.header.notice` | Mutate header notice payload |
| `storefront.product.tabs` | Product tabs hook surface |
| `catalog.product.saved` | After product save (informational) |
| `checkout.cart.updated` | Cart changed |
| `checkout.order.placed` | Order placed |
| `payment.drivers` | Reserved certified lane |
| `shipping.carriers` | Reserved certified lane |
| `admin.dashboard.cards` | Dashboard cards |
| `view.{slot}` | Mirror of each view slot id |
| `action.{name}.before` / `.after` | See actions below |

## Actions (`ActionCatalog`)

Listen with `$this->actions()->before('catalog.product.save', …)` (requires matching `hooks` entry). Safe apps **cannot abort** core writes.

| Action name |
| --- |
| `catalog.product.save` |
| `catalog.product.delete` |
| `customer.save` |
| `customer.delete` |
| `order.create` |
| `order.status` |
| `checkout.place` |

## Page documents

Hosted pages and slot blocks use a small allowlisted vocabulary (`text`, `button`, `form`, `table`, `list`, …). Core sanitizes hrefs and module submit paths (`/api/v1/modules/{vendor}/{name}/{page}`).

Hosted **admin indexes** use document key `list` (not a slot `table`). Core renders Catalog checkboxes + Bulk actions and POSTs `{ list_action, ids }` to `store()`. See [CODING-STANDARD.md](CODING-STANDARD.md#hosted-admin-lists). Do not import admin React from a zip.
