<?php

namespace App\Domain\Store;

use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Catalog\Models\UrlRewrite;
use App\Domain\Catalog\Services\StorefrontCatalogCache;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Customer\Models\Customer;
use App\Domain\Installer\InstallerService;
use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Ops\Models\WebhookEndpoint;
use App\Domain\Ops\Models\WebserviceKey;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\Models\EmailEventSetting;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\Models\Setting;
use App\Domain\Store\Models\ShopLookup;
use App\Domain\Store\Models\Store;
use App\Domain\Store\Models\StoreGroup;
use App\Domain\Store\Models\StoreSharingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreService
{
    public function enable(): Store
    {
        $store = StoreBootstrap::ensure();
        StoreBootstrap::associateExisting($store);
        Setting::setValue('multishop_enabled', '1');

        return $store->fresh(['policies', 'urls']) ?? $store;
    }

    public function disable(): void
    {
        if (Store::query()->count() > 1) {
            abort(422, 'Turn off extra shops before disabling multishop.');
        }

        Setting::setValue('multishop_enabled', '0');
    }

    /**
     * @param  array{
     *     name: string,
     *     email?: ?string,
     *     address?: ?string,
     *     shop_url: string,
     *     theme?: string,
     *     preset?: string,
     *     import_data?: bool,
     *     import?: list<string>,
     *     copy_from_id?: ?int,
     *     group_id?: ?int
     * }  $data
     */
    public function create(array $data): Store
    {
        $default = Store::query()->where('is_default', true)->firstOrFail();
        $imports = array_values(array_intersect(
            (array) ($data['import'] ?? []),
            Sharing::importKeys(),
        ));
        $importData = (bool) ($data['import_data'] ?? false);
        $preset = (string) ($data['preset'] ?? Sharing::PRESET_EMPTY);
        if ($preset === Sharing::PRESET_SAME) {
            $importData = true;
            $imports = array_values(array_unique(array_merge($imports, ['products', 'categories'])));
        }
        if (in_array('catalog', $imports, true)) {
            $imports = array_values(array_unique(array_merge($imports, ['products', 'categories'])));
        }
        if (in_array('methods', $imports, true)) {
            $imports = array_values(array_unique(array_merge($imports, ['payment', 'shipping'])));
        }
        if (! $importData) {
            $imports = [];
            $preset = Sharing::PRESET_EMPTY;
        }
        // A ticked import only links those rows. It must not switch customers
        // or orders to Share from. Preset "same" is the explicit share path.
        $source = $importData
            ? (Store::query()->find($data['copy_from_id'] ?? null) ?: $default)
            : $default;
        $group = $this->resolveGroup($data, $source, $preset);

        return DB::transaction(function () use ($data, $source, $group, $preset, $imports) {
            $store = Store::query()->create([
                'store_group_id' => $group->id,
                'name' => $data['name'],
                'code' => StoreBootstrap::uniqueCode($data['name']),
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'theme' => $data['theme'] ?? 'default',
                'color' => $this->color($data['color'] ?? null),
                'is_default' => false,
                'is_enabled' => true,
            ]);

            $url = ! empty($data['domain'])
                ? ShopUrl::attributesFromParts($data)
                : ShopUrl::attributesFromUrl($data['shop_url']);
            $url['store_id'] = $store->id;
            $url['is_primary'] = true;
            ShopUrl::query()->create($url);

            $this->writePolicies($store, $preset, $source);
            $this->applyGroupCustomerOrderShares($store->fresh('group'), $source);
            if (in_array('email', $imports, true)) {
                $this->copyEmail($source, $store);
            }
            if (in_array('cms', $imports, true)) {
                $this->copyCms($source, $store);
            }
            if (in_array('payment', $imports, true)) {
                $this->sharePivot('payment_method_store', 'payment_method_id', $source, $store, 'is_enabled');
            }
            if (in_array('shipping', $imports, true)) {
                $this->sharePivot('shipping_method_store', 'shipping_method_id', $source, $store, 'is_enabled');
            }
            if (in_array('products', $imports, true)) {
                $this->sharePivot('product_store', 'product_id', $source, $store);
            }
            if (in_array('categories', $imports, true)) {
                $this->sharePivot('category_store', 'category_id', $source, $store);
            }
            foreach (ShopLookup::families() as $family) {
                if (in_array($family, $imports, true)) {
                    $this->shareLookups($family, $source, $store);
                }
            }
            if (in_array('webhooks', $imports, true)) {
                $this->copyWebhooks($source, $store);
            }
            if (in_array('webservices', $imports, true)) {
                $this->copyWebservices($source, $store);
            }

            MailEvents::ensure($store->id);
            \App\Domain\Customer\SystemCustomerGroups::ensure();

            return $store->fresh(['urls', 'policies']);
        });
    }

    public function update(Store $store, array $data): Store
    {
        $store->fill([
            'name' => $data['name'] ?? $store->name,
            'email' => $data['email'] ?? $store->email,
            'address' => $data['address'] ?? $store->address,
            'theme' => $data['theme'] ?? $store->theme,
            'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : $store->is_enabled,
        ]);
        if (array_key_exists('color', $data)) {
            $store->color = $this->color($data['color']);
        }
        if (! empty($data['group_id']) && (int) $data['group_id'] !== (int) $store->store_group_id) {
            $this->assertCanLeaveGroup($store);
            $store->store_group_id = (int) $data['group_id'];
        }
        if (! empty($data['code']) && ! $store->is_default) {
            $store->code = StoreBootstrap::uniqueCode($data['code'], $store->id);
        }
        $store->save();

        if (! empty($data['domain']) || ! empty($data['shop_url'])) {
            $attrs = ! empty($data['domain'])
                ? ShopUrl::attributesFromParts($data)
                : ShopUrl::attributesFromUrl($data['shop_url']);
            ShopUrl::query()->updateOrCreate(
                ['store_id' => $store->id, 'is_primary' => true],
                $attrs + ['store_id' => $store->id, 'is_primary' => true],
            );
        }

        if (isset($data['policies']) && is_array($data['policies'])) {
            $this->savePolicies($store->fresh(), $data['policies']);
        }
        $this->applyGroupCustomerOrderShares(
            $store->fresh('group'),
            $store->group?->stores()->where('id', '!=', $store->id)->orderByDesc('is_default')->first() ?: $store
        );

        if ($store->is_default) {
            Setting::setValue('shop_name', $store->name);
            Setting::setValue('shop_email', $store->email ?? '');
            Setting::setValue('shop_address', $store->address ?? '');
            $url = $store->fresh('urls')?->primaryUrl()?->installRoot();
            if ($url) {
                Setting::setValue('shop_url', $url);
            }
        }

        return $store->fresh(['urls', 'policies']);
    }

    public function toggle(Store $store): Store
    {
        if ($store->is_default && $store->is_enabled) {
            abort(422, 'The default shop stays enabled.');
        }
        $store->is_enabled = ! $store->is_enabled;
        $store->save();

        return $store;
    }

    public function makeDefault(Store $store): Store
    {
        if (! $store->is_enabled) {
            abort(422, 'Enable the shop before making it default.');
        }
        Store::query()->where('is_default', true)->where('id', '!=', $store->id)->update(['is_default' => false]);
        $store->is_default = true;
        $store->is_enabled = true;
        $store->save();
        Setting::setValue('shop_name', $store->name);
        Setting::setValue('shop_email', $store->email ?? '');
        Setting::setValue('shop_address', $store->address ?? '');
        $url = $store->fresh('urls')?->primaryUrl()?->installRoot();
        if ($url) {
            Setting::setValue('shop_url', $url);
        }

        return $store->fresh(['urls', 'group']);
    }

    /**
     * @param  array{name: string, description?: ?string, share_customers?: bool, share_orders?: bool, share_stock?: bool, is_enabled?: bool}  $data
     */
    public function createGroup(array $data): StoreGroup
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            abort(422, 'Enter a group name.');
        }
        $shareCustomers = (bool) ($data['share_customers'] ?? false);
        $shareOrders = (bool) ($data['share_orders'] ?? false);
        $shareStock = (bool) ($data['share_stock'] ?? false);
        if ($shareOrders && (! $shareCustomers || ! $shareStock)) {
            abort(422, 'Share orders can be turned on only when customers and available quantities are already shared.');
        }

        return StoreGroup::query()->create([
            'name' => $name,
            'code' => $this->uniqueGroupCode($name),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'share_customers' => $shareCustomers,
            'share_orders' => $shareOrders,
            'share_stock' => $shareStock,
            'color' => $this->color($data['color'] ?? null),
            'is_enabled' => array_key_exists('is_enabled', $data) ? (bool) $data['is_enabled'] : true,
        ]);
    }

    /**
     * @param  array{name?: string, share_customers?: bool, share_orders?: bool, share_stock?: bool, is_enabled?: bool}  $data
     */
    public function updateGroup(StoreGroup $group, array $data): StoreGroup
    {
        $shareCustomers = array_key_exists('share_customers', $data) ? (bool) $data['share_customers'] : $group->share_customers;
        $shareOrders = array_key_exists('share_orders', $data) ? (bool) $data['share_orders'] : $group->share_orders;
        $shareStock = array_key_exists('share_stock', $data) ? (bool) $data['share_stock'] : (bool) $group->share_stock;
        $locks = $this->groupShareLocks($group);
        if ($locks['lock_customers'] && $shareCustomers !== (bool) $group->share_customers) {
            abort(422, 'Share customers can no longer be changed. A customer already belongs to a shop in this group.');
        }
        if ($locks['lock_stock'] && $shareStock !== (bool) $group->share_stock) {
            abort(422, 'Share available quantities can no longer be changed. A product in this group already has a quantity.');
        }
        if ($locks['lock_orders'] && $shareOrders !== (bool) $group->share_orders) {
            abort(422, 'Share orders can no longer be changed. This group already has an order.');
        }
        if ($shareOrders && (! $shareCustomers || ! $shareStock)) {
            abort(422, 'Share orders can be turned on only when customers and available quantities are already shared.');
        }
        if (! empty($data['name'])) {
            $group->name = trim((string) $data['name']);
        }
        if (array_key_exists('description', $data)) {
            $group->description = trim((string) $data['description']) ?: null;
        }
        $group->share_customers = $shareCustomers;
        $group->share_orders = $shareOrders;
        $group->share_stock = $shareStock;
        if (array_key_exists('color', $data)) {
            $group->color = $this->color($data['color']);
        }
        if (array_key_exists('is_enabled', $data)) {
            $group->is_enabled = (bool) $data['is_enabled'];
        }
        $group->save();

        return $group;
    }

    public function deleteGroup(StoreGroup $group, ?int $moveToGroupId = null): void
    {
        if ($group->code === 'default') {
            abort(422, 'The default group stays.');
        }
        $shopCount = $group->stores()->count();
        if ($shopCount > 0) {
            if (! $moveToGroupId) {
                abort(422, 'Move these shops to another group first, then delete this group.');
            }
            $target = StoreGroup::query()->where('id', $moveToGroupId)->where('id', '!=', $group->id)->first();
            if (! $target) {
                abort(422, 'Pick another group to move the shops into.');
            }
            $group->stores()->update(['store_group_id' => $target->id]);
        }
        $group->delete();
    }

    public function delete(Store $store): void
    {
        if (! $store->canBeDeleted()) {
            abort(422, 'Disable the shop instead. The default shop and shops with orders cannot be deleted.');
        }
        $store->urls()->delete();
        $store->delete();
    }

    /**
     * @param  array<int, array{family: string, mode: string, source_store_id?: ?int}>  $rows
     */
    public function savePolicies(Store $store, array $rows): void
    {
        foreach ($rows as $row) {
            $family = $row['family'] ?? '';
            if (! in_array($family, Sharing::families(), true)) {
                continue;
            }
            $mode = in_array($row['mode'] ?? '', Sharing::modes(), true) ? $row['mode'] : Sharing::MODE_OWN;
            $source = $mode === Sharing::MODE_OWN ? null : (int) ($row['source_store_id'] ?? 0);
            if ($source === $store->id) {
                $source = null;
                $mode = Sharing::MODE_OWN;
            }
            if ($mode !== Sharing::MODE_OWN && $source < 1) {
                $mode = Sharing::MODE_OWN;
                $source = null;
            }
            if ($source) {
                $peer = Store::query()->find($source);
                if (! $peer || (int) $peer->store_group_id !== (int) $store->store_group_id) {
                    $mode = Sharing::MODE_OWN;
                    $source = null;
                }
            }
            StoreSharingPolicy::query()->updateOrCreate(
                ['store_id' => $store->id, 'family' => $family],
                ['mode' => $mode, 'source_store_id' => $source ?: null],
            );
        }
    }

    protected function writePolicies(Store $store, string $preset, Store $source): void
    {
        foreach (Sharing::presetPolicies($preset) as $family => $rule) {
            StoreSharingPolicy::query()->updateOrCreate(
                ['store_id' => $store->id, 'family' => $family],
                [
                    'mode' => $rule['mode'],
                    'source_store_id' => $rule['source'] ? $source->id : null,
                ],
            );
        }
    }

    protected function copyEmail(Store $source, Store $store): void
    {
        foreach (EmailTemplate::withoutGlobalScope('shop')->where('store_id', $source->id)->get() as $template) {
            $copy = $template->replicate();
            $copy->store_id = $store->id;
            $copy->code = $template->is_custom ? $template->code.'-'.$store->code : $template->code;
            $copy->save();
        }
        foreach (EmailEventSetting::withoutGlobalScope('shop')->where('store_id', $source->id)->get() as $row) {
            EmailEventSetting::withoutGlobalScope('shop')->firstOrCreate(
                [
                    'store_id' => $store->id,
                    'event_code' => $row->event_code,
                    'recipient' => $row->recipient,
                ],
                ['is_enabled' => $row->is_enabled],
            );
        }
    }

    protected function copyCms(Store $source, Store $store): void
    {
        $pageMap = [];
        foreach (CmsPage::withoutGlobalScope('shop')->where('store_id', $source->id)->with('translations')->get() as $page) {
            $copy = $page->replicate();
            $copy->store_id = $store->id;
            $copy->save();
            foreach ($page->translations as $translation) {
                $copy->translations()->create($translation->only([
                    'language_code', 'title', 'slug', 'short_description', 'body',
                    'meta_title', 'meta_description', 'meta_keywords',
                ]));
            }
            $pageMap[$page->id] = $copy->id;
            foreach (UrlRewrite::query()->where('entity_type', 'cms')->where('entity_id', $page->id)->get() as $rewrite) {
                UrlRewrite::query()->firstOrCreate(
                    [
                        'store_id' => $store->id,
                        'language_code' => $rewrite->language_code,
                        'request_path' => $rewrite->request_path,
                    ],
                    [
                        'entity_type' => 'cms',
                        'entity_id' => $copy->id,
                    ],
                );
            }
        }
        foreach (CmsBlock::withoutGlobalScope('shop')->where('store_id', $source->id)->get() as $block) {
            $copy = $block->replicate();
            $copy->store_id = $store->id;
            $copy->code = $block->code;
            $copy->save();
        }
        foreach (Menu::withoutGlobalScope('shop')->where('store_id', $source->id)->with('allItems')->get() as $menu) {
            $copy = $menu->replicate();
            $copy->store_id = $store->id;
            $copy->save();
            $itemMap = [];
            foreach ($menu->allItems->sortBy('id') as $item) {
                $new = MenuItem::query()->create([
                    'menu_id' => $copy->id,
                    'parent_id' => $item->parent_id ? ($itemMap[$item->parent_id] ?? null) : null,
                    'label' => $item->label,
                    'url' => $item->url,
                    'position' => $item->position,
                ]);
                $itemMap[$item->id] = $new->id;
            }
        }
        unset($pageMap);
    }

    protected function sharePivot(string $table, string $fk, Store $source, Store $store, string $flag = 'is_visible'): void
    {
        foreach (DB::table($table)->where('store_id', $source->id)->get() as $row) {
            $payload = [
                $fk => $row->{$fk},
                'store_id' => $store->id,
                $flag => $row->{$flag} ?? true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (isset($row->price)) {
                $payload['price'] = $row->price;
            }
            if (isset($row->url_key)) {
                $payload['url_key'] = $row->url_key;
            }
            DB::table($table)->insertOrIgnore($payload);
        }
    }

    protected function shareLookups(string $family, Store $source, Store $store): void
    {
        foreach (ShopLookup::query()->where('family', $family)->where('store_id', $source->id)->get() as $row) {
            ShopLookup::query()->firstOrCreate(
                [
                    'family' => $family,
                    'lookup_id' => $row->lookup_id,
                    'store_id' => $store->id,
                ],
                ['is_visible' => (bool) $row->is_visible],
            );
        }
    }

    protected function copyWebhooks(Store $source, Store $store): void
    {
        foreach (WebhookEndpoint::withoutGlobalScope('shop')->where('store_id', $source->id)->get() as $row) {
            $copy = $row->replicate(['last_status', 'last_error', 'consecutive_failures', 'disabled_at', 'last_sent_at']);
            $copy->store_id = $store->id;
            $copy->consecutive_failures = 0;
            $copy->last_status = null;
            $copy->last_error = null;
            $copy->last_sent_at = null;
            $copy->disabled_at = null;
            $secret = $row->plainSecret() ?: WebhookEndpoint::generateSecret();
            $copy->setPlainSecret($secret);
            $copy->save();
        }
    }

    protected function copyWebservices(Store $source, Store $store): void
    {
        foreach (WebserviceKey::withoutGlobalScope('shop')->where('store_id', $source->id)->get() as $row) {
            WebserviceKey::issue([
                'store_id' => $store->id,
                'name' => $row->name,
                'scopes' => $row->scopes,
                'allowed_ips' => $row->allowed_ips,
                'is_enabled' => false,
                'expires_at' => $row->expires_at,
                'created_by' => $row->created_by,
            ], WebserviceKey::generatePlain());
        }
    }

    protected function shareCatalog(Store $source, Store $store): void
    {
        $this->sharePivot('product_store', 'product_id', $source, $store);
        $this->sharePivot('category_store', 'category_id', $source, $store);
    }

    public function hideProductOnStore(int $productId, int $storeId): void
    {
        DB::table('product_store')->updateOrInsert(
            ['product_id' => $productId, 'store_id' => $storeId],
            ['is_visible' => false, 'updated_at' => now(), 'created_at' => now()],
        );
        StorefrontCatalogCache::bump();
    }

    public function writeDefaultEnv(Store $store): void
    {
        if (app()->runningUnitTests() || ! $store->is_default) {
            return;
        }
        $primary = $store->primaryUrl();
        $url = $primary?->installRoot() ?: (string) config('app.url');
        app(InstallerService::class)->writeEnvPairs([
            'APP_NAME' => $store->name,
            'APP_URL' => $url,
            'MAIL_FROM_ADDRESS' => $store->email ?: config('mail.from.address'),
            'MAIL_FROM_NAME' => $store->name,
            'SESSION_PATH' => ShopUrl::sessionPathFromUrl($url),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveGroup(array $data, Store $source, string $preset): StoreGroup
    {
        if ($preset === Sharing::PRESET_SAME) {
            return $source->group ?: StoreGroup::query()->findOrFail($source->store_group_id);
        }
        if (! empty($data['group_id'])) {
            return StoreGroup::query()->findOrFail((int) $data['group_id']);
        }

        return $source->group ?: StoreGroup::query()->findOrFail($source->store_group_id);
    }

    /**
     * @return array{lock_customers: bool, lock_orders: bool, lock_stock: bool}
     */
    public function groupShareLocks(StoreGroup $group): array
    {
        $shopIds = array_map('intval', $group->stores()->pluck('id')->all());

        return [
            'lock_customers' => $this->groupHasRows(Customer::class, $shopIds),
            'lock_orders' => $this->groupHasRows(Order::class, $shopIds),
            'lock_stock' => $this->groupHasQuantities($shopIds),
        ];
    }

    /**
     * @param  class-string<Customer|Order>  $model
     * @param  list<int>  $shopIds
     */
    protected function groupHasRows(string $model, array $shopIds): bool
    {
        if ($shopIds === []) {
            return false;
        }
        $defaultId = (int) Store::query()->where('is_default', true)->value('id');

        return $model::withoutGlobalScope('shop')->where(function ($query) use ($shopIds, $defaultId) {
            $query->whereIn('store_id', $shopIds);
            if ($defaultId > 0 && in_array($defaultId, $shopIds, true)) {
                $query->orWhereNull('store_id');
            }
        })->exists();
    }

    /**
     * @param  list<int>  $shopIds
     */
    protected function groupHasQuantities(array $shopIds): bool
    {
        if ($shopIds === [] || ! DB::getSchemaBuilder()->hasTable('product_store')) {
            return false;
        }

        return DB::table('products')
            ->where('stock_qty', '>', 0)
            ->whereExists(function ($query) use ($shopIds) {
                $query->selectRaw('1')
                    ->from('product_store')
                    ->whereColumn('product_store.product_id', 'products.id')
                    ->whereIn('product_store.store_id', $shopIds);
            })
            ->exists();
    }

    protected function color(mixed $value): ?string
    {
        $raw = strtoupper(trim((string) $value));
        if ($raw === '') {
            return null;
        }
        if ($raw[0] !== '#') {
            $raw = '#'.$raw;
        }

        return preg_match('/^#[0-9A-F]{6}$/', $raw) ? $raw : null;
    }

    protected function assertCanLeaveGroup(Store $store): void
    {
        $store->loadMissing('group');
        $group = $store->group;
        if (! $group || ! ($group->share_customers || $group->share_orders || $group->share_stock)) {
            return;
        }
        $ids = $group->stores()->pluck('id')->all();
        if ($ids === []) {
            return;
        }
        $hasData = Customer::withoutGlobalScope('shop')->whereIn('store_id', $ids)->exists()
            || Order::withoutGlobalScope('shop')->whereIn('store_id', $ids)->exists();
        if ($hasData) {
            abort(422, 'This shop stays in '.$group->name.' because that group shares customers, orders, or stock and already has that data.');
        }
    }

    protected function uniqueGroupCode(string $name): string
    {
        $base = Str::slug($name) ?: 'group';
        $code = $base;
        $i = 2;
        while (StoreGroup::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }

    protected function applyGroupCustomerOrderShares(Store $store, Store $source): void
    {
        $store->loadMissing('group');
        $group = $store->group;
        if (! $group?->share_customers) {
            return;
        }
        $peer = ((int) $source->store_group_id === (int) $store->store_group_id && $source->id !== $store->id)
            ? $source
            : $group->stores()->where('id', '!=', $store->id)->orderByDesc('is_default')->orderBy('id')->first();
        if (! $peer) {
            return;
        }
        StoreSharingPolicy::query()->updateOrCreate(
            ['store_id' => $store->id, 'family' => Sharing::FAMILY_CUSTOMERS],
            ['mode' => Sharing::MODE_SHARE, 'source_store_id' => $peer->id],
        );
        if ($group->share_orders) {
            StoreSharingPolicy::query()->updateOrCreate(
                ['store_id' => $store->id, 'family' => Sharing::FAMILY_ORDERS],
                ['mode' => Sharing::MODE_SHARE, 'source_store_id' => $peer->id],
            );
        }
    }
}
