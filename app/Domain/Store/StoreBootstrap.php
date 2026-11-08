<?php

namespace App\Domain\Store;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Models\CmsPage;
use App\Domain\Cms\Models\Menu;
use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\Models\EmailEventSetting;
use App\Domain\Settings\Models\EmailTemplate;
use App\Domain\Settings\Models\Setting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\Models\ShopLookup;
use App\Domain\Store\Models\Store;
use App\Domain\Store\Models\StoreGroup;
use App\Domain\Store\Models\StoreSharingPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class StoreBootstrap
{
    /** One process serves many requests; the default shop does not need rebuilding on each. */
    private static ?Store $ready = null;

    public static function reset(): void
    {
        self::$ready = null;
    }

    public static function ensure(): Store
    {
        if (self::$ready instanceof Store) {
            return self::$ready;
        }

        $store = Store::query()->where('is_default', true)->first();
        if ($store && self::isReady($store)) {
            self::repairLegacyStoreIds($store);

            return self::$ready = $store;
        }

        return self::$ready = self::provision();
    }

    /**
     * The default shop, its sharing rows, and a URL already exist.
     * Skip the install-time schema scan and lookup backfill on storefront requests.
     */
    private static function isReady(Store $store): bool
    {
        $families = Sharing::families();
        $policies = StoreSharingPolicy::query()
            ->where('store_id', $store->id)
            ->whereIn('family', $families)
            ->count();
        if ($policies < count($families)) {
            return false;
        }

        return ShopUrl::query()->where('store_id', $store->id)->exists();
    }

    private static function provision(): Store
    {
        $group = StoreGroup::query()->firstOrCreate(
            ['code' => 'default'],
            ['name' => 'Default'],
        );

        $name = (string) Setting::getValue('shop_name', config('app.name') ?: 'Store');
        $email = (string) Setting::getValue('shop_email', '');
        $address = (string) Setting::getValue('shop_address', '');

        $store = Store::query()->where('is_default', true)->first();
        if (! $store) {
            $store = Store::query()->create([
                'store_group_id' => $group->id,
                'name' => $name !== '' ? $name : 'Default shop',
                'code' => 'default',
                'email' => $email,
                'address' => $address,
                'theme' => 'default',
                'is_default' => true,
                'is_enabled' => true,
            ]);
        }

        foreach (Sharing::families() as $family) {
            StoreSharingPolicy::query()->firstOrCreate(
                ['store_id' => $store->id, 'family' => $family],
                ['mode' => Sharing::MODE_OWN, 'source_store_id' => null],
            );
        }

        self::linkInstalledRecords($store);

        if (Schema::hasColumn('shop_urls', 'store_id')) {
            ShopUrl::query()
                ->whereNull('store_id')
                ->whereIn('virtual_uri', ['', '/'])
                ->update(['store_id' => $store->id]);
            ShopUrl::query()->whereNull('store_id')->delete();
            if (! ShopUrl::query()->where('store_id', $store->id)->exists()) {
                ShopUrl::query()->create(array_merge(
                    ShopUrl::attributesFromUrl((string) Setting::getValue('shop_url', config('app.url'))),
                    ['store_id' => $store->id, 'is_primary' => true],
                ));
            }
        }

        self::repairLegacyStoreIds($store);

        return $store->fresh(['policies', 'urls']) ?? $store;
    }

    /**
     * Demo / early customers were often saved before a shop context existed.
     * Attach those rows to the default shop so email sign-in can find them.
     */
    private static function repairLegacyStoreIds(Store $store): void
    {
        if (! $store->is_default || ! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'store_id')) {
            return;
        }

        try {
            DB::table('customers')->whereNull('store_id')->update(['store_id' => $store->id]);
        } catch (Throwable) {
            // Installer / mid-migrate may not have the column yet.
        }
    }

    public static function associateExisting(Store $store): void
    {
        try {
            if (Schema::hasTable('product_store')) {
                foreach (Product::query()->pluck('id') as $id) {
                    DB::table('product_store')->insertOrIgnore([
                        'product_id' => $id,
                        'store_id' => $store->id,
                        'is_visible' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            if (Schema::hasTable('category_store')) {
                foreach (Category::query()->pluck('id') as $id) {
                    DB::table('category_store')->insertOrIgnore([
                        'category_id' => $id,
                        'store_id' => $store->id,
                        'is_visible' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            foreach (['cms_pages', 'cms_blocks', 'menus', 'email_templates', 'email_event_settings', 'carts', 'orders', 'customers', 'discount_rules'] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'store_id')) {
                    DB::table($table)->whereNull('store_id')->update(['store_id' => $store->id]);
                }
            }
            if (Schema::hasTable('payment_method_store')) {
                foreach (PaymentMethod::query()->pluck('id') as $id) {
                    DB::table('payment_method_store')->insertOrIgnore([
                        'payment_method_id' => $id,
                        'store_id' => $store->id,
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            if (Schema::hasTable('shipping_method_store')) {
                foreach (ShippingMethod::query()->pluck('id') as $id) {
                    DB::table('shipping_method_store')->insertOrIgnore([
                        'shipping_method_id' => $id,
                        'store_id' => $store->id,
                        'is_enabled' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            self::linkInstalledRecords($store);
            if (Schema::hasTable('url_rewrites') && Schema::hasColumn('url_rewrites', 'store_id')) {
                DB::table('url_rewrites')->whereNull('store_id')->update(['store_id' => $store->id]);
            }
        } catch (Throwable) {
            // Tables may not exist yet during early migrate.
        }
    }

    /**
     * The original shop keeps every record that already exists.
     * A shop created later stays empty until import links the rows it asked for.
     */
    public static function linkInstalledRecords(Store $store): void
    {
        if (! $store->is_default || ! Schema::hasTable('shop_lookups')) {
            return;
        }

        $now = now();
        foreach (ShopLookup::familyTables() as $family => $table) {
            if (! Schema::hasTable($table) || ! DB::table($table)->exists()) {
                continue;
            }
            $already = DB::table('shop_lookups')
                ->where('store_id', $store->id)
                ->where('family', $family)
                ->exists();
            if ($already) {
                continue;
            }
            foreach (DB::table($table)->pluck('id') as $id) {
                DB::table('shop_lookups')->insertOrIgnore([
                    'family' => $family,
                    'lookup_id' => $id,
                    'store_id' => $store->id,
                    'is_visible' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public static function uniqueCode(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'shop';
        $code = $base;
        $i = 2;
        while (Store::query()->where('code', $code)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }
}
