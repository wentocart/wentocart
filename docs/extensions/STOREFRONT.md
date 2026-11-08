# Storefront (frontend) extensions

Same rule as admin: **Secure + Open**.  
Developers add real shopper features through **published** menus, pages, slots, and events — never by editing Classic React or injecting arbitrary JS into core pages.

Also read: [SECURE-AND-OPEN.md](SECURE-AND-OPEN.md) · [EXTENSION-POINTS.md](EXTENSION-POINTS.md)

---

## What you can build (developer map)

| You want… | How (secure) | Capability |
| --- | --- | --- |
| Extra **My Account** menu (My reviews, membership, …) | `$this->slots()->on('storefront.account.menu', …)` return `type: link` | `account.menu` |
| Own account / storefront **page** (`/m/vendor/name/page`) | `$this->pages()->front('page', Controller::class)` | `storefront.pages` |
| Shopper **DataTable** (search, sort, pager, row buttons) | Front page + `'list' => [ … ]` — Classic paints it | `storefront.pages` |
| Button / label / modal on **product buy box** | `$this->slots()->on('storefront.product.actions', …)` | `storefront.slot` |
| Content **under** product / category | `storefront.product.after`, `storefront.category.after` | `storefront.slot` |
| Product **tabs** area | `storefront.product.tabs` | `storefront.slot` |
| Header / home body | `storefront.header.top`, `storefront.home.body` | `storefront.slot` |
| **Cart** sidebar | `storefront.cart.sidebar` | `storefront.slot` |
| **Checkout** under payment / sidebar | `storefront.checkout.payment`, `storefront.checkout.sidebar` | `storefront.slot` |
| Fee or deposit on totals | `$this->checkout()->adjust(...)` | `checkout.adjust` |
| Partial payment / schedule on order | `$this->orders()->recordPayment` / `setPaymentDetails` | `orders.write` |
| React when **order is placed** | `$this->actions()->after('checkout.place', …)` | `hook.listen` |
| Activate membership / sync from order lines | Same after action — payload includes `customer_id`, `email`, `items[]` (`product_id`, `sku`, `qty`) | `hook.listen` |
| Assign shopper to a customer group | `$this->customers()->assignGroup($id, 'vip')` | `customers.write` |
| Read order rows for follow-up (beyond place payload) | `$this->query()->rows('orders', …)` | `orders.read` (**certified**) |
| Send email on order / custom action | `$this->mail()->sendTemplate('code', $to, $vars)` | `mail.send` |

Core renders document blocks (`text`, `button`+`href` or `modal`, `link`, `card`, …). Your zip does **not** ship PDP/account React into Classic.

**Buy box:** `storefront.product.actions`. Primary (`href` or `modal`) uses the same control as **Add to cart**. `variant: secondary` uses the Compare / Wishlist row. A button with neither `href` nor `modal` does nothing. Full table: [UI.md](UI.md).

---

## 1) My Account menu

```php
$this->slots()->on('storefront.account.menu', function () {
    return [[
        'type' => 'link',
        'href' => '/m/acme/membership/home',
        'label' => 'My membership',
        'icon' => 'star',
    ]];
});
```

`app.json`: `"capabilities": ["account.menu", "storefront.pages", …]`

Pair with a front page controller for the destination. Classic account sidebar already merges `shop.modules.account_menu`.

`icon` is optional. Core accepts:

- Classic kit id: `'star'`, `'user'`, `'heart'`, `'bag'`, `'gift'`, `'help'`, `'grid'`
- Emoji: `'👑'`
- Font Awesome classes: `'fas fa-crown'` (FA 6 solid / regular / brands ship with the storefront)
- HTTPS or `/plugins/{vendor}/{name}/…` image URL (png, jpg, webp, gif, svg)
- Inline SVG: `'<svg viewBox="0 0 24 24">…</svg>'` (scripts and event handlers are stripped)

You can also pass an array: `['type' => 'fa', 'class' => 'fas fa-star']`, `['type' => 'image', 'src' => 'https://…']`, `['type' => 'svg', 'markup' => '<svg…>']`. Unknown or unsafe values are dropped; the account sidebar falls back to `grid`.

Examples: My reviews (core already has reviews), wishlist, subscription, prime — **extension adds the link + hosted page**; it does not fork Account.jsx.

---

## 1b) Hosted storefront table (Classic DataTable)

Admin and Classic are **two kits**. Do **not** import admin React, jQuery, or DataTables.

Return a `list` document from a **front** controller. Classic (`ModuleList`) draws Show entries, Search, sortable headers, badges, row buttons, and Previous/Next. Query string keys: `q`, `sort`, `dir`, `page`, `per_page`.

Hosted storefront lists use the same `document.list` shape as admin lists. Classic renders search, sort, badges, and row buttons — do not import admin React.

Working demo (enable **Sample kit**): signed-in shopper → **Store credit list** → `/m/wento/sample-kit/credits`.

```php
$this->pages()->front('credits', StorefrontCreditsController::class, true);

return $this->page([
    'title' => 'Store credit list',
    'crumbs' => [
        ['name' => 'Home', 'href' => '/'],
        ['name' => 'Your account', 'href' => '/account'],
        ['name' => 'Store credit list'],
    ],
    'list' => [
        'search' => true,
        'per_page_options' => [10, 25, 50],
        'per_page' => $perPage,
        'page' => $page,
        'last_page' => $lastPage,
        'total' => $total,
        'sort' => $sort,
        'dir' => $dir,
        'empty' => 'No store credits yet.',
        'columns' => [
            ['key' => 'id', 'label' => 'ID', 'sort' => true],
            ['key' => 'status', 'label' => 'Order status', 'badge' => true],
        ],
        'rows' => [[
            'id' => '1',
            'status' => 'Store Credit',
            'status_tone' => 'danger',
            'actions' => [
                ['label' => 'View', 'href' => '/m/acme/store-credit/credits?id=1', 'icon' => 'eye', 'variant' => 'ghost'],
                ['label' => 'Complete payment', 'href' => '/m/acme/store-credit/credits?id=1&pay=1', 'variant' => 'secondary'],
            ],
        ]],
    ],
]);
```

Badge tones: `success`, `warning`, `danger`, `info`, `primary`, `neutral`. Row `actions` need `label` + `href` (max 4). Do not ship a second table CSS file in the zip.

A slot `type: table` is a **static** grid (no search/pager). Prefer page-level `list` for account ledgers.

---

## 2) Buttons, labels, titles, modals on shop pages

Pick a **published slot** (placement = catalog id, not CSS):

| Slot | Typical UI |
| --- | --- |
| `storefront.product.actions` | Button next to Add to cart (enquiry, notify me) |
| `storefront.product.after` | Longer content below related products |
| `storefront.product.tabs` | Tab-style blocks |
| `storefront.category.after` | Banner / CTA under category listing |
| `storefront.home.body` | Home cards / promos |
| `storefront.header.top` | Thin top bar content |
| `storefront.footer` | Footer note / links |

Example (button + modal) — same pattern as Product Enquiry:

```php
return [[
    'type' => 'button',
    'label' => 'Ask a question',
    'variant' => 'secondary',
    'modal' => [
        'title' => 'Ask us',
        'submit' => '/api/v1/modules/acme/help/submit',
        'fields' => [
            ['name' => 'message', 'type' => 'textarea', 'label' => 'Message'],
        ],
    ],
]];
```

**Missing spots today** (we add them the same way when needed): home hero, category toolbar, header bottom, cart line items. Ask for the slot → we publish id + theme mount + docs.

---

## 3) Order placed → extension reacts (e.g. stock manager)

```php
// app.json hooks must include:
// "action.checkout.place.after"

$this->actions()->after('checkout.place', function (array $payload) {
    $orderId = (int) ($payload['id'] ?? 0);
    $customerId = (int) ($payload['customer_id'] ?? 0);
    $skus = collect($payload['items'] ?? [])->pluck('sku');
    // Adjust your extension mapping — never raw UPDATE on core tables
    // unless through a published write API (customers.write, catalog.write, …).
    return $payload;
});
```

Also available: hook `checkout.order.placed` (payload includes order model for core listeners; Safe apps use published actions + allowlisted reads).

**Secure:** you cannot abort checkout from Safe before-actions. You **can** update **your** settings/tables and call published write APIs.

---

## 4) Emails on order / actions

**Today:** use the published mail API + merchant templates.

| API | Capability | Meaning |
| --- | --- | --- |
| `$this->mail()->sendTemplate('membership_activated', $to, $vars)` | `mail.send` | Shop mailer + enabled template only |
| `$this->mail()->listTemplates()` | `mail.send` | Codes the merchant enabled |

Merchant creates/edits templates under **Configure → Email** (subject/body with `{{placeholders}}`). Extension supplies scalar vars only (HTML stripped). Core still sends built-in shop events (order confirmation, etc.) on its own.

Until a template exists, `sendTemplate` fails with a clear validation error — document required codes in your README.

---

## Block types you may return in slots

| `type` | Use |
| --- | --- |
| `text` | Label / paragraph |
| `button` | CTA; **require** `href` and/or `modal`. Primary on `storefront.product.actions` matches Add to cart. `variant`: `primary`, `secondary`, `outline`, `ghost` |
| `link` | Navigation; same variants as button |
| `kpis` | Four pastel admin cards (`items` like page-level `kpis`) |
| `card` | Title + text + optional image/href |
| `table` | Static columns/rows (no pager). Prefer page `list` for shopper DataTables |

Href/submit paths are sanitized by core (`PageDocument`). Prefer `/m/{vendor}/{name}/{page}` or `/api/v1/modules/{vendor}/{name}/{page}`.

---

## Mental checklist (frontend)

```text
Account menu item          → account.menu + slot storefront.account.menu
Account / public page      → storefront.pages + pages()->front
Shopper DataTable          → page document `list` (not admin React / DataTables)
PDP / home / header UI     → storefront.slot + published slot id
Order placed reaction      → hook.listen + action.checkout.place.after
Assign group / send mail   → customers.write / mail.send
Read orders (beyond place) → orders.read (certified) + query()
Send email                 → mail.send (shop templates only)
Inject JS into Classic PDP → NEVER
```

---

## Related demos

- Product Enquiry — PDP button + modal (`storefront.product.actions`)
- Sample kit fixture — account menu, `/m/wento/sample-kit/board`, **Store credit list** table, header/footer/home/product/category slots  

Share your target **module list** and we map each feature to an existing surface or add the next published slot/API.
