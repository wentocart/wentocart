<?php

namespace App\Domain\Store;

use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Settings\CommunityFeatures;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\PricingSettings;
use App\Domain\Settings\ProductSettings;
use App\Domain\Store\Models\Store;
use App\Domain\Store\Models\StoreGroup;
use App\Domain\Store\Models\StoreSharingPolicy;
use App\Domain\Theme\ThemeResolver;
use App\Domain\Theme\ThemeSettingsCatalog;
use App\Support\WebBase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Throwable;

class StoreContext
{
    protected ?Store $store = null;

    protected bool $viewAll = false;

    protected ?int $groupContextId = null;

    protected bool $resolved = false;

    public const ALL_STORES_COLOR = '#25B9D7';

    public const EMPTY_CONTEXT_COLOR = '#EBEBEB';

    public function enabled(): bool
    {
        if (! config('wento.installed')) {
            return false;
        }

        try {
            return self::flag(Setting::getValue('multishop_enabled', '0'));
        } catch (Throwable) {
            return false;
        }
    }

    public static function flag(mixed $raw): bool
    {
        return match (true) {
            is_bool($raw) => $raw,
            is_int($raw), is_float($raw) => (int) $raw === 1,
            default => in_array(strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes'], true),
        };
    }

    public function resolveFromRequest(Request $request): void
    {
        $this->resolved = true;
        $this->viewAll = false;
        $this->store = null;

        if ($request->is('admin') || $request->is('admin/*')) {
            $this->resolveAdmin($request);

            return;
        }

        $bound = $request->attributes->get('wento.shop_url');
        if ($bound instanceof ShopUrl && $bound->store_id) {
            $this->store = Store::query()->where('is_enabled', true)->find($bound->store_id);
            if ($this->enabled() && ! $this->store) {
                abort(404, 'Unknown shop URL.');
            }
            if ($this->store) {
                return;
            }
        }

        $matched = ShopUrl::matchFromRequest($request);
        if ($matched?->store_id) {
            $this->store = Store::query()->where('is_enabled', true)->find($matched->store_id);
        }
        if (! $this->store && $request->is('api/*')) {
            $storeKey = trim((string) ($request->header('X-Store') ?: $request->header('X-Wento-Store') ?: ''));
            if ($storeKey !== '') {
                $this->store = Store::query()
                    ->where('is_enabled', true)
                    ->where(function ($q) use ($storeKey) {
                        if (ctype_digit($storeKey)) {
                            $q->whereKey((int) $storeKey);
                        } else {
                            $q->where('code', $storeKey);
                        }
                    })
                    ->first();
            }
        }

        // Multishop on: never silently serve another shop for an unmatched host/path.
        if ($this->enabled()) {
            if (! $this->store) {
                abort(404, 'Unknown shop URL.');
            }

            return;
        }

        $this->store ??= $this->defaultStore();
    }

    public function resolveAdmin(Request $request): void
    {
        $default = $this->defaultStore();
        if (! $this->enabled()) {
            $this->store = $default;
            $this->viewAll = false;

            return;
        }

        $marker = (string) $request->session()->get('admin.context', '');
        $raw = $request->session()->get('admin.store_id', $default?->id);
        if ($marker === '' && ($raw === 'all' || $raw === 'view_all')) {
            $marker = 'all';
        } elseif ($marker === '' && is_numeric($raw)) {
            $marker = 'shop:'.(int) $raw;
        }

        if ($marker === 'all') {
            $concrete = $request->session()->get('admin.store_id_concrete', $default?->id);
            $this->store = Store::query()->find($concrete) ?: $default;
            $this->groupContextId = null;
            $this->viewAll = ! $this->mustUseConcreteShop($request);

            return;
        }

        if (str_starts_with($marker, 'group:')) {
            $groupId = (int) substr($marker, 6);
            $group = StoreGroup::query()->where('is_enabled', true)->find($groupId);
            $concrete = $request->session()->get('admin.store_id_concrete', $default?->id);
            $fallback = $group?->stores()->where('is_enabled', true)->orderByDesc('is_default')->orderBy('id')->first();
            $this->store = ($fallback instanceof Store ? $fallback : null) ?: (Store::query()->find($concrete) ?: $default);
            $this->groupContextId = $group ? $group->id : null;
            $this->viewAll = false;

            return;
        }

        $shopId = str_starts_with($marker, 'shop:') ? (int) substr($marker, 5) : (int) $raw;
        $this->store = Store::query()->find($shopId) ?: $default;
        $this->groupContextId = null;
        $this->viewAll = false;
        if ($this->store) {
            $request->session()->put('admin.store_id_concrete', $this->store->id);
            $request->session()->put('admin.context', 'shop:'.$this->store->id);
        }
    }

    public function bind(Store $store, bool $viewAll = false): void
    {
        $this->resolved = true;
        $this->store = $store;
        $this->viewAll = $viewAll;
    }

    public function viewAll(): bool
    {
        return $this->enabled() && $this->viewAll;
    }

    public function contextKind(): string
    {
        if (! $this->enabled() || $this->groupContextId === null && ! $this->viewAll()) {
            return 'shop';
        }
        if ($this->viewAll()) {
            return 'all';
        }

        return $this->groupContextId ? 'group' : 'shop';
    }

    public function contextGroupId(): int
    {
        return (int) ($this->groupContextId ?? 0);
    }

    public function sharesCart(): bool
    {
        $store = $this->store();
        $store?->loadMissing('group');
        $group = $store?->group;

        return (bool) ($group?->share_customers && $group?->share_orders && $group?->share_stock);
    }

    /**
     * Shops a saved catalog row should be linked to.
     * A group context links every shop in that group. One shop stays on that shop.
     *
     * @return list<int>
     */
    public function associationIds(): array
    {
        if ($this->contextKind() === 'group') {
            $ids = $this->contextGroupShopIds();

            return $ids !== [] ? $ids : array_values(array_filter([$this->id()]));
        }

        return array_values(array_unique(array_filter(array_map('intval', [$this->id(), $this->catalogSourceId()]))));
    }

    public function cartStoreId(): int
    {
        if ($this->sharesCart()) {
            $ids = $this->groupShopIds($this->store());

            return $ids[0] ?? $this->requireId();
        }

        return $this->requireId();
    }

    public function filters(): bool
    {
        return $this->enabled() && ! $this->viewAll();
    }

    public function id(): int
    {
        return (int) ($this->store()?->id ?: 0);
    }

    /**
     * Always return a concrete shop id (creates/ensures default store if needed).
     * Use on writes so carts, orders, and customers never get store_id = null.
     */
    public function requireId(): int
    {
        $id = $this->id();
        if ($id > 0) {
            return $id;
        }

        $store = StoreBootstrap::ensure();
        $this->bind($store, false);

        return (int) $store->id;
    }

    public function store(): ?Store
    {
        if ($this->resolved) {
            return $this->store;
        }

        // Session auth can load the customer before ResolveStore runs. The shop
        // URL is already on the request by then. Using the default shop here
        // hides a shared-group customer and the address save returns Unauthenticated.
        $bound = request()->attributes->get('wento.shop_url');
        if ($bound instanceof ShopUrl && $bound->store_id) {
            $this->resolved = true;
            $this->store = Store::query()->where('is_enabled', true)->find($bound->store_id);

            return $this->store;
        }

        $this->store = $this->defaultStore();
        $this->resolved = true;

        return $this->store;
    }

    public function defaultStore(): ?Store
    {
        try {
            return Store::query()->where('is_default', true)->first()
                ?: Store::query()->orderBy('id')->first();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<Store> */
    public function enabledStores(): array
    {
        try {
            return Store::query()->with(['group', 'urls'])->where('is_enabled', true)->get()
                ->sortBy(fn (Store $row) => [mb_strtolower((string) ($row->group?->name ?? '')), mb_strtolower($row->name)])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function policy(string $family): ?StoreSharingPolicy
    {
        $store = $this->store();
        if (! $store) {
            return null;
        }

        $store->loadMissing('policies');

        return $store->policy($family);
    }

    /** @return list<int> */
    public function idsFor(string $family): array
    {
        $id = $this->id();
        if ($id < 1) {
            return [];
        }

        if ($this->contextKind() === 'group') {
            $ids = $this->contextGroupShopIds();

            return $ids !== [] ? $ids : [$id];
        }
        if ($family === 'carts' && $this->sharesCart()) {
            $ids = $this->groupShopIds($this->store());

            return $ids !== [] ? $ids : [$id];
        }

        $policy = $this->policy($family);
        $store = $this->store();
        $store?->loadMissing('group');
        if ($family === Sharing::FAMILY_CUSTOMERS && $store?->group?->share_customers) {
            return $this->groupShopIds($store);
        }
        if ($family === Sharing::FAMILY_ORDERS && $store?->group?->share_orders) {
            return $this->groupShopIds($store);
        }
        if ($policy && $policy->shares()) {
            $ids = array_filter([$id, (int) $policy->source_store_id]);

            return array_values(array_unique($ids));
        }

        return [$id];
    }

    /** @return list<int> */
    public function contextGroupShopIds(): array
    {
        $groupId = $this->contextGroupId();
        if ($groupId < 1) {
            return [];
        }
        $ids = Store::query()->where('store_group_id', $groupId)->where('is_enabled', true)->orderByDesc('is_default')->orderBy('id')->pluck('id')->all();

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return list<int> */
    protected function groupShopIds(Store $store): array
    {
        $ids = $store->group?->stores()->where('is_enabled', true)->orderByDesc('is_default')->orderBy('id')->pluck('id')->all() ?? [];
        if ($ids === []) {
            return [$store->id];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return list<int> */
    public function catalogStoreIds(): array
    {
        if ($this->contextKind() === 'group') {
            $ids = $this->contextGroupShopIds();

            return $ids !== [] ? $ids : [];
        }
        $policy = $this->policy(Sharing::FAMILY_CATALOG);
        if ($policy && $policy->shares()) {
            return [(int) $policy->source_store_id];
        }

        $id = $this->id();

        return $id > 0 ? [$id] : [];
    }

    public function catalogSourceId(): int
    {
        $policy = $this->policy(Sharing::FAMILY_CATALOG);
        if ($policy && $policy->shares()) {
            return (int) $policy->source_store_id;
        }

        return $this->id();
    }

    public function customerHomeId(): int
    {
        $store = $this->store();
        $store?->loadMissing('group');
        if ($store?->group?->share_customers) {
            $ids = $this->groupShopIds($store);

            return $ids[0] ?? $this->id();
        }
        $policy = $this->policy(Sharing::FAMILY_CUSTOMERS);
        if ($policy && $policy->shares()) {
            return (int) $policy->source_store_id;
        }

        return $this->id();
    }

    public function constrain(Builder $query, string $family, string $column = 'store_id'): Builder
    {
        if (! $this->filters()) {
            return $query;
        }

        $ids = $this->idsFor($family);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $table = $query->getModel()->getTable();

        return $query->whereIn($table.'.'.$column, $ids);
    }

    public function constrainProducts(Builder $query): Builder
    {
        if (! $this->filters()) {
            return $query;
        }

        $ids = $this->catalogStoreIds();
        $current = $this->id();
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        $query->whereHas('storeAssignments', function (Builder $q) use ($ids) {
            $q->whereIn('store_id', $ids)->where('is_visible', true);
        });

        $policy = $this->policy(Sharing::FAMILY_CATALOG);
        if ($policy && $policy->mode === Sharing::MODE_SHARE_OVERRIDE && $current > 0) {
            $query->whereDoesntHave('storeAssignments', function (Builder $q) use ($current) {
                $q->where('store_id', $current)->where('is_visible', false);
            });
        }

        return $query;
    }

    public function constrainCategories(Builder $query): Builder
    {
        if (! $this->filters()) {
            return $query;
        }

        $ids = $this->catalogStoreIds();
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('storeAssignments', function (Builder $q) use ($ids) {
            $q->whereIn('store_id', $ids)->where('is_visible', true);
        });
    }

    public function constrainMethods(Builder $query): Builder
    {
        if (! $this->filters()) {
            return $query;
        }

        $ids = $this->idsFor(Sharing::FAMILY_MODULES);
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('shops', fn (Builder $q) => $q->whereIn('stores.id', $ids));
    }

    public function constrainLookups(Builder $query, string $family): Builder
    {
        if (! $this->filters()) {
            return $query;
        }

        $ids = $this->id() > 0 ? [$this->id()] : [];
        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('storeAssignments', function (Builder $q) use ($ids, $family) {
            $q->where('family', $family)->whereIn('store_id', $ids)->where('is_visible', true);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function inertiaShop(string $currency): array
    {
        $store = $this->store();
        $enabled = $this->enabled();
        $store?->loadMissing('urls');

        return [
            'id' => $store?->id,
            'name' => $store?->name ?: (string) Setting::getValue('shop_name', config('app.name')),
            'code' => $store?->code,
            'theme' => $store?->theme ?: 'default',
            'logo_url' => $this->shopLogoUrl($store),
            'currency' => $currency,
            'url' => $this->shopHomeUrl($store),
            'multishop' => $enabled,
            'multivendor' => MarketplaceSettings::enabled(),
            'community' => CommunityFeatures::payload(),
            'products' => [
                'out_of_stock_notification' => ProductSettings::outOfStockNotification(),
            ],
            'pricing' => [
                'decimal_precision' => PricingSettings::decimalPrecision(),
                'rounding' => PricingSettings::rounding(),
            ],
            'view_all' => $this->viewAll(),
            'context' => $this->contextKind(),
            'context_group_id' => $this->contextGroupId() ?: null,
            'context_label' => $this->contextLabel(),
            'context_color' => $this->contextColor(),
            'context_ink' => $this->ink($this->contextColor()),
            'context_has_color' => $this->hasContextColor(),
            'context_color_url' => $this->contextColorUrl(),
            'can_disable' => $enabled && Store::query()->count() <= 1,
            'stores' => $enabled ? collect($this->enabledStores())->map(fn (Store $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'code' => $row->code,
                'is_default' => $row->is_default,
                'color' => $row->color ?: '',
                'group_id' => $row->store_group_id,
                'group' => $row->group?->name ?: '',
                'group_color' => $row->group?->color ?: '',
                'url' => $this->shopHomeUrl($row),
            ])->values()->all() : [],
        ];
    }

    /** Theme settings logo for admin chrome (sidebar brand). */
    protected function shopLogoUrl(?Store $store): string
    {
        try {
            $settings = app(ThemeResolver::class)->settings(null, $store);
            $logo = is_string($settings['logo_url'] ?? null) ? trim($settings['logo_url']) : '';
            $path = $logo !== '' ? $logo : ThemeSettingsCatalog::DEFAULT_LOGO_URL;
        } catch (Throwable) {
            $path = ThemeSettingsCatalog::DEFAULT_LOGO_URL;
        }

        // Folder installs need the install-path prefix so <img src> resolves without JS helpers.
        return WebBase::url(ltrim($path, '/'));
    }

    public function contextLabel(): string
    {
        if ($this->contextKind() === 'all') {
            return 'All stores';
        }
        if ($this->contextKind() === 'group') {
            return (string) (StoreGroup::query()->find($this->contextGroupId())?->name ?: 'Group');
        }

        return (string) ($this->store()?->name ?: Setting::getValue('shop_name', config('app.name')));
    }

    public function contextColor(): string
    {
        if ($this->contextKind() === 'all') {
            return self::ALL_STORES_COLOR;
        }
        if ($this->contextKind() === 'group') {
            $color = (string) (StoreGroup::query()->find($this->contextGroupId())?->color ?: '');

            return $color !== '' ? $color : self::EMPTY_CONTEXT_COLOR;
        }
        $color = (string) ($this->store()?->color ?: '');

        return $color !== '' ? $color : self::EMPTY_CONTEXT_COLOR;
    }

    public function hasContextColor(): bool
    {
        if ($this->contextKind() === 'all') {
            return true;
        }
        if ($this->contextKind() === 'group') {
            return (string) (StoreGroup::query()->find($this->contextGroupId())?->color ?: '') !== '';
        }

        return (string) ($this->store()?->color ?: '') !== '';
    }

    public function contextColorUrl(): string
    {
        if ($this->contextKind() === 'group') {
            return '/admin/shops?group='.$this->contextGroupId();
        }
        $id = (int) ($this->store()?->id ?? 0);

        return $id > 0 ? '/admin/shops/'.$id.'/edit' : '/admin/shops';
    }

    public function ink(string $hex): string
    {
        $raw = ltrim($hex, '#');
        if (strlen($raw) !== 6) {
            return '#1f2937';
        }
        $luma = (0.299 * hexdec(substr($raw, 0, 2))) + (0.587 * hexdec(substr($raw, 2, 2))) + (0.114 * hexdec(substr($raw, 4, 2)));

        return $luma > 170 ? '#1f2937' : '#ffffff';
    }

    public function shopHomeUrl(?Store $store = null): string
    {
        $store ??= $this->store();
        try {
            $store?->loadMissing('urls');
            $root = $store?->primaryUrl()?->root();
            if (is_string($root) && $root !== '') {
                return rtrim($root, '/').'/';
            }
        } catch (Throwable) {
            // Shop URLs may be missing during install.
        }

        $fallback = (string) (Setting::getValue('shop_url', config('app.url')) ?: config('app.url'));

        return rtrim($fallback, '/').'/';
    }

    public function mustUseConcreteShop(Request $request): bool
    {
        if ($request->is('admin/shop-context')) {
            return false;
        }

        if (! $request->isMethod('GET')) {
            return true;
        }

        $path = $request->path();
        if (preg_match('#/(create|edit)$#', $path)) {
            return true;
        }

        if ($path === 'admin/shops') {
            return false;
        }

        foreach (['admin/store', 'admin/email', 'admin/shops', 'admin/urls', 'admin/seo', 'admin/images', 'admin/performance', 'admin/advanced', 'admin/themes'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}
