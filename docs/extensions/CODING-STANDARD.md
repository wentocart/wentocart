# Extension coding standard

PHP in an extension talks **only** through `BasePlugin` helpers. Core owns rendering, routing, and security.

## Entry: `src/Plugin.php`

```php
namespace Modules\Acme\HelloBanner;

use App\Domain\Plugin\Support\BasePlugin;

class Plugin extends BasePlugin
{
    public function boot(): void
    {
        // Register menus, pages, slots, hooks here.
    }
}
```

### Allowed helpers (via `$this`)

| Helper | Capability needed | Use |
| --- | --- | --- |
| `settings()` | `settings` | Get/set this extension’s settings |
| `menu()->group(...)` | `admin.menu` | Left sidebar group |
| `pages()->admin(...)` | `admin.pages` | Hosted admin page controllers |
| `pages()->front(...)` | `storefront.pages` | Hosted storefront `/m/...` + module API submit |
| `slots()->on(...)` | `storefront.slot` or `view.slot` | Published slot blocks |
| `lists()->extend(...)` | `list.extend` | Published admin list columns |
| `hooks()->listen(...)` | `hook.listen` | Published hooks listed in `app.json` |
| `actions()->before/after(...)` | `hook.listen` | Published actions |
| `query()` | `catalog.read` / `customers.read` / `carts.read` | Allowlisted reads |
| `catalog()` | `catalog.write` | Simple products (incl. `sale_price` / `compare_price`) |
| `customers()` | `customers.write` | Groups, setEnabled, setProfile |
| `mail()` | `mail.send` | Send via shop mailer + enabled templates |
| `checkout()` | `checkout.adjust` / `checkout.payment.filter` / `checkout.shipping.filter` | Fees, due-today, credit, hide methods |
| `orders()` | `orders.write` | Payments, fulfillment, assignAdmin, setStatus, snapshots |
| `carts()` | `carts.read` / `carts.write` | Abandoned carts; cart meta KV |
| `wishlist()` | `wishlist.read` / `wishlist.write` | Wishlist product ids |
| `schedule()` | `schedule.tick` | Timed work on `wento:extensions-tick` |
| `pdf()` | `documents.pdf` | Render PDF HTML; optional email attachment |
| `auth()` | `auth.login.resolve` / `auth.register` | Username login; pending signup; onRegistered |
| `adminSchema()` / `saveSettings()` | `admin.page` | Core Host form under Design → Extensions |

Declare every capability you use in `app.json`. Undeclared use throws at boot → Unhealthy.

## Admin navigation (pick one pattern)

| Pattern | Capabilities | Sidebar |
| --- | --- | --- |
| **Business module** (recommended for multi-page apps) | `admin.menu` + `admin.pages` + `settings` | Your own left group |
| **Simple settings** | `admin.page` + `settings` | Link under **Design → Extensions** only |
| Both | All of the above | **Duplicate** entries — avoid unless intentional |

Reference: Product Enquiry uses the business-module pattern (no `admin.page`).

## Controllers

- Live under `src/Http/`
- Extend `ModuleController`
- Return **page documents** (arrays of blocks) — never Inertia React
- Core dispatches them; you never register routes

```php
namespace Modules\Demo\ProductEnquiry\Http;

use App\Domain\Plugin\Support\ModuleController;
use App\Domain\Plugin\Support\ModuleRequest;

class ConfigurationController extends ModuleController
{
    public function index(ModuleRequest $request): array
    {
        return $this->page([
            'title' => 'Configuration',
            'blocks' => [/* … */],
        ]);
    }

    public function store(ModuleRequest $request): array
    {
        $values = $request->all()['values'] ?? $request->all();
        if (! is_array($values)) {
            $values = [];
        }
        if (trim((string) ($values['office_name'] ?? '')) === '') {
            return $this->invalid($this->index($request), [
                'office_name' => 'Office name is required.',
            ], $values); // or pass $request; placement 'top'|'bottom'
        }
        $this->settings()->fill($values);

        return $this->index($request);
    }
}
```

Failed saves must call `invalid()`, never throw Inertia validation and never wipe the form. Core shows a red banner (`errors`) plus per-field red text (`field_errors`) and overlays submitted `values` on the document so typed input stays. Do not stuff validation into `hint`.

## Hosted admin lists

Do **not** import admin React (`Table`, `BulkActions`, Inertia pages) from a zip. Return a `list` document. Core draws the Catalog table: checkboxes, Bulk actions, row Edit.

POST body to the same page `store()`:

```json
{ "list_action": "delete", "ids": ["1", "4", "9"] }
```

Catalog actions: `enable`, `disable`, `duplicate`, `delete`. Extra actions need a `key` + `label`.

### KPIs on hosted lists

Core Catalog lists show **four** pastel KPI cards. Hosted pages get the same cards when you return `kpis` (max 4). Cookbook: [UI.md](UI.md). Live preview: `/admin/ui-kit` section 10.

```php
'kpis' => [
    ['label' => 'Total', 'value' => '128', 'trend' => 4.2],
    ['label' => 'Open', 'value' => '12', 'trend' => -1.0],
    ['label' => 'Closed', 'value' => '116', 'trend' => 2.5],
    ['label' => 'Reply rate', 'value' => '91%', 'trend' => 0.4],
],
```

Do not invent a second KPI grid in HTML/CSS.

### Example A — own records (recommended)

Store rows in `$this->settings()` (or later certified tables). List + edit on one controller:

```php
public function index(ModuleRequest $request): array
{
    $id = trim($request->string('id'));
    if ($id !== '') {
        return $this->page([
            'title' => 'Edit contact',
            'back' => [
                'href' => '/admin/modules/acme/crm/contacts',
                'label' => 'Back to list',
                'icon' => 'arrow-left',
            ],
            'fields' => [/* name, email, … */],
            'values' => $this->findRow($id),
        ]);
    }

    return $this->page([
        'title' => 'Contacts',
        'list' => [
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'rows' => array_map(fn ($row) => $row + [
                'href' => '/admin/modules/acme/crm/contacts?id='.$row['id'],
                'view_href' => '/admin/modules/acme/crm/contacts?id='.$row['id'],
                'delete' => true,
                'duplicate' => true,
            ], $this->rows()),
            'bulk' => 'catalog', // Active / Deactivate / Duplicate / Delete
            'empty' => 'No contacts yet.',
            'delete_confirm' => 'Delete the selected contacts?',
            'create' => [
                'href' => '/admin/modules/acme/crm/contacts?new=1',
                'label' => 'Add contact',
            ],
        ],
    ]);
}

public function store(ModuleRequest $request): array
{
    $bulk = $this->selectedList($request);
    if ($bulk['action'] !== '') {
        if ($bulk['ids'] === []) {
            return $this->invalid($this->index($request), ['Select at least one row.']);
        }
        // enable | disable | duplicate | delete using $bulk['ids']
        return $this->index(ModuleRequest::capture('GET', []));
    }
    // save the Edit / Add form ($request->all()['values'])
    return $this->index(ModuleRequest::capture('GET', []));
}
```

Live sample: Account Manager → **Customer Information** (`wento/sample-kit`).

## Hosted storefront lists (Classic)

Same `list` key, **different renderer**. Classic does not show Catalog checkboxes or Bulk actions. It shows Show entries, Search, sort, badges, and row buttons.

Do **not** import admin React or ship DataTables/jQuery. Demo: Sample kit → **Store credit list**.

Query the zip reads: `q`, `sort`, `dir`, `page`, `per_page` (10 / 25 / 50 / 100).

### Example B — read-only table (no bulk)

Until you need selection, keep the old `blocks` table + links. No checkboxes.

```php
return $this->page([
    'title' => 'Recent enquiries',
    'blocks' => [[
        'type' => 'table',
        'columns' => [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'email', 'label' => 'Email'],
        ],
        'rows' => $rows, // add a link block per row if they should open another hosted page
    ]],
]);
```

### Example C — extra bulk action

```php
'bulk' => [
    'delete',
    ['key' => 'export', 'label' => 'Export selection', 'icon' => 'download'],
],
```

`store()` receives `list_action=export` and `ids`. Do the work in PHP; do not ship a CSV UI in the zip.

### Do not

- Import `resources/js/admin/ui` from an extension
- Point `href` at `/admin/products` or other core screens (not on the allowlist)
- Use `list.extend` for this — that only adds a column on Products / Orders / Customers
- Put 10,000 rows in one document (cap is 200; paginate yourself later via query string)

## Storefront UI

Cookbook (Add to cart look, variants, KPIs): [UI.md](UI.md).

- Prefer **slots** + document blocks (`text`, `button` + `href` or `modal`, `link`, `card`)
- Core React (`ModuleBlocks`) renders blocks inside **published** theme slots
- You do **not** inject JS into the product buy box
- **Add to cart look:** slot `storefront.product.actions`, `type: button` (or `link`) with `href` or `modal`, omit `variant` (primary). `variant: secondary` sits with Compare / Wishlist.
- A button with no `href` and no `modal` is a dead control — core still draws it, but it does nothing
- Placement = choose a slot id from [EXTENSION-POINTS.md](EXTENSION-POINTS.md)
- Own pages under `/m/{vendor}/{name}/{page}` need `storefront.pages`

## Upgrades (zip / marketplace)

- Core upgrades keep the same `plugins` row id and never delete `plugin_settings` for that vendor/name.
- Merchants upload a newer zip (or use marketplace one-click when connected); settings and enabled state survive.
- Optional later: ship `upgrade.php` in the extension root for data migrations after files are replaced. Not required when you only use `plugin_settings`.
- Do not change `vendor` / `name` between versions — that creates a different extension.

## Settings

- Keys: `snake_case`
- Persist only via `$this->settings()`
- Own tables are optional. List suffixes in `app.json` as `"tables": ["gifts"]` and create `x_{vendor}_{name}_gifts` (the shop prefix is added for you). Uninstall drops only those names.
- A save response may set `'reload' => true` to reload the admin page, or `'redirect' => '/admin/modules/...'` to open another hosted page. Do not set `reload` on the normal index response.
- Field types: `text`, `textarea`, `toggle`, `number`, `password`, `select`, `search`, `radio`, `checkboxes`, `readonly`, **`image`**, **`file`**, **`color`**, **`date`**
- `color` opens the shared color picker. `date` opens a date picker. Both store a string.
- `image` / `file`: core shows a drop zone (same look as list Import). Core stores the file on the public disk under `storage/app/public/extensions/{vendor}/{name}/`. PHP in the zip must not upload files itself. Read with `$this->settings()->fileUrl('logo')` or `$this->settings()->file('logo')` (`path`, `url`, `name`). `image` = JPG/PNG/WEBP/GIF. `file` = PDF, Office, CSV, TXT, ZIP, or those images. No PHP, HTML, JS, or SVG.
- `checkboxes` is a multi-select list. Declare `options` like `select` / `radio`. Core stores the checked values as a JSON array of strings. Unknown ids are dropped.
- **Do not** declare `title_en`, `title_hi`, `title_fr`. Mark **one** field `localized => true`. Core shows the same language switcher as Products (one input visible; other languages stay on the field). Stored value is `{ "en": "…", "hi": "…" }`. Read the active locale with `$this->settings()->localized('headline')`.

```php
[
    'name' => 'headline',
    'type' => 'text', // or textarea
    'localized' => true,
    'label' => 'Headline',
],
// equivalent: 'type' => 'localized', 'input' => 'textarea'
```

```php
[
    'name' => 'logo',
    'type' => 'image',
    'label' => 'Logo',
],
[
    'name' => 'price_list',
    'type' => 'file',
    'label' => 'Price list PDF',
],
```

```php
[
    'name' => 'checked_order_statuses',
    'type' => 'checkboxes',
    'label' => 'Order statuses',
    'hint' => 'Select every status this app should watch.',
    'default' => ['processing'],
    'options' => [
        ['value' => 'processing', 'label' => 'Processing'],
        ['value' => 'shipped', 'label' => 'Shipped'],
        ['value' => 'cancelled', 'label' => 'Cancelled'],
    ],
]

$statuses = $this->settings()->get('checked_order_statuses', []);
```

## Translations (`i18n/ui.json`)

Ship English UI chrome for the hosted admin UI in `i18n/ui.json` next to `app.json`.

```json
{
  "module.acme.hello.configure.title": "Configuration",
  "module.acme.hello.configure.field.office": "Office name"
}
```

Rules:

- Keys **must** start with `module.{vendor}.{name}.` (themes: `theme.{code}.`).
- Keep the English string in the page document **and** add `title_key` / `hint_key` / `label_key` / `text_key` / `empty_key` / `delete_confirm_key` so merchants can override per language.
- After install/update, core syncs packs into International → **Translations → Modules** (or **Themes**). Admins translate each package individually.
- Bundled reference: `extensions/weblytic/mainmenu`. Other modules are installed from the marketplace.
- Themes may ship the same file under the theme root; storefront customer-facing copy still uses storefront translation surfaces / localized content fields.
- **Email templates** and **document designs** (invoices, credit slips, delivery slips): use the language switcher on each template editor (subject + body/design per language). That is content localization, not `ui.json`.
- Package `ui.json` is for hosted-admin chrome labels only (tabs, fields, list columns, buttons).

```php
return $this->page([
    'title' => 'Configuration',
    'title_key' => 'module.acme.hello.configure.title',
    'fields' => [[
        'name' => 'office_name',
        'type' => 'text',
        'label' => 'Office name',
        'label_key' => 'module.acme.hello.configure.field.office',
    ]],
]);
```

## Style

- PSR-12-ish: one class per file, typed where practical
- No `eval`, includes of other PHP, or dynamic class loading
- English UI strings in page documents **plus** `*_key` + `i18n/ui.json` (see Translations above)
- **Admin** tabs, menus, list actions, and `back` / `create` buttons: kit ids only — the same stroke set as Products (`resources/js/admin/ui/Icon.jsx`: `settings`, `pencil`/`edit`, `download`, `plus`, …). Do not ship Font Awesome or Material icons on hosted admin screens; unknown names used to fall back to a plus sign.
- Storefront / account **icons**: optional `icon` on `link` and `button` blocks. Kit id, emoji, Font Awesome class (`fas fa-star`), HTTPS/`/plugins/…` image URL, or sanitized inline SVG.

## Security reminder

Safe apps are scanned on install. Passing the scanner is required but not sufficient — stay on published APIs so merchants can trust the capability list.
