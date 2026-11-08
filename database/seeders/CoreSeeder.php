<?php

namespace Database\Seeders;

use App\Domain\Auth\Models\Role;
use App\Domain\Auth\StaffAcl;
use App\Domain\Catalog\Models\Warehouse;
use App\Domain\Cms\CoreCmsStubs;
use App\Domain\Cms\Models\CmsCategory;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Customer\SystemCustomerGroups;
use App\Domain\Dashboard\DefaultDashboard;
use App\Domain\Installer\Models\ShopUrl;
use App\Domain\Localization\CoreCountries;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\ShopLocaleDefaults;
use App\Domain\Order\Models\ReturnReason;
use App\Domain\Order\OrderStageCatalog;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Settings\DocumentTemplateCatalog;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\Models\Setting;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Domain\Store\StoreBootstrap;
use App\Domain\Tax\Models\TaxClass;
use App\Models\Admin;
use App\Support\HexColor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CoreSeeder extends Seeder
{
    public function run(): void
    {
        $progress = config('wento.install_progress');
        $installing = is_callable($progress) || (bool) config('wento.installing');
        $step = static function (int $done, int $total, string $message) use ($progress): void {
            if (is_callable($progress)) {
                \App\Domain\Installer\InstallProgress::tick(
                    $progress,
                    'configuration',
                    $done,
                    $total,
                    'Languages, currencies, regions & permissions'
                );
                \App\Domain\Installer\InstallProgress::say($progress, '  '.$message);
            }
        };

        $total = 8;
        if (is_callable($progress)) {
            \App\Domain\Installer\InstallProgress::note($progress, 'Configuring your store…');
        }
        $step(1, $total, 'Setting up languages and currencies…');
        ShopLocaleDefaults::seed();
        Currency::query()->updateOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'is_enabled' => true, 'exchange_rate' => 1, 'position' => 1]);
        Currency::query()->updateOrCreate(['code' => 'INR'], ['name' => 'Indian Rupee', 'symbol' => '₹', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 83, 'position' => 3]);
        Currency::query()->updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 0.92, 'position' => 2]);
        Currency::query()->updateOrCreate(['code' => 'GBP'], ['name' => 'Pound Sterling', 'symbol' => '£', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 0.79, 'position' => 4]);
        Currency::query()->updateOrCreate(['code' => 'AED'], ['name' => 'UAE Dirham', 'symbol' => 'د.إ', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 3.67, 'position' => 5]);
        Currency::query()->updateOrCreate(['code' => 'AUD'], ['name' => 'Australian Dollar', 'symbol' => 'A$', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 1.52, 'position' => 6]);
        Currency::query()->updateOrCreate(['code' => 'CAD'], ['name' => 'Canadian Dollar', 'symbol' => 'C$', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 1.36, 'position' => 7]);
        Currency::query()->updateOrCreate(['code' => 'SGD'], ['name' => 'Singapore Dollar', 'symbol' => 'S$', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 1.35, 'position' => 8]);
        Currency::query()->updateOrCreate(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 155, 'decimal_places' => 0, 'position' => 9]);
        Currency::query()->updateOrCreate(['code' => 'NZD'], ['name' => 'New Zealand Dollar', 'symbol' => 'NZ$', 'is_enabled' => true, 'is_default' => false, 'exchange_rate' => 1.64, 'position' => 10]);

        $step(2, $total, 'Loading countries and regions…');
        CoreCountries::seed();

        TaxClass::query()->firstOrCreate(['name' => 'Standard']);

        $step(3, $total, 'Setting up staff permissions…');
        StaffAcl::ensure();

        $step(4, $total, 'Setting up order statuses…');
        OrderStageCatalog::seed();

        $step(5, $total, 'Preparing your store profile…');
        StoreBootstrap::ensure();

        foreach ([
            ['name' => 'General', 'slug' => 'general', 'description' => 'General store pages', 'is_enabled' => true, 'position' => 1],
            ['name' => 'Legal', 'slug' => 'legal', 'description' => 'Policies and legal pages', 'is_enabled' => true, 'position' => 2],
            ['name' => 'Info', 'slug' => 'info', 'description' => 'About, contact, FAQ', 'is_enabled' => true, 'position' => 3],
        ] as $row) {
            CmsCategory::query()->firstOrCreate(['slug' => $row['slug']], $row);
        }

        $warehouse = Warehouse::query()->firstOrNew(['code' => 'default']);
        $warehouse->forceFill([
            'name' => 'Default Warehouse',
            'code' => 'default',
            'is_default' => true,
            'is_enabled' => true,
        ])->save();

        foreach ([
            ['code' => 'wrong_item', 'name' => 'Wrong item received', 'position' => 1],
            ['code' => 'damaged', 'name' => 'Arrived damaged', 'position' => 2],
            ['code' => 'size', 'name' => 'Size / fit issue', 'position' => 3],
            ['code' => 'changed_mind', 'name' => 'Changed my mind', 'position' => 4],
        ] as $row) {
            ReturnReason::query()->firstOrCreate(['code' => $row['code']], $row + ['is_enabled' => true]);
        }

        if (! $installing) {
            $this->seedFallbackAdmin();
        }

        $step(6, $total, 'Installing the admin dashboard…');
        app(DefaultDashboard::class)->install();

        $step(7, $total, 'Setting up payments and shipping…');
        CustomerGroup::query()->updateOrCreate(
            ['code' => 'retailer'],
            ['name' => 'Retailer', 'color' => HexColor::TONES['primary'], 'discount_percent' => 0, 'tax_exempt' => false, 'description' => 'Standard retail customers', 'is_enabled' => true, 'position' => 1]
        );
        CustomerGroup::query()->updateOrCreate(
            ['code' => 'wholesale'],
            ['name' => 'Wholesale', 'color' => HexColor::TONES['info'], 'discount_percent' => 0, 'tax_exempt' => false, 'description' => 'Wholesale buyers', 'is_enabled' => true, 'position' => 2]
        );
        CustomerGroup::query()->updateOrCreate(
            ['code' => 'vip'],
            ['name' => 'VIP', 'color' => HexColor::TONES['warning'], 'discount_percent' => 0, 'tax_exempt' => false, 'description' => 'VIP customers', 'is_enabled' => true, 'position' => 3]
        );
        SystemCustomerGroups::ensure();

        PaymentMethod::query()->updateOrCreate(
            ['code' => 'bank_transfer'],
            [
                'name' => 'Bank transfer',
                'description' => 'Pay by bank transfer using your order number as the reference.',
                'driver' => 'offline',
                'is_enabled' => false,
                'position' => 1,
                'config' => ['instructions' => ''],
                'rules' => PaymentMethod::emptyRules(),
            ]
        );
        PaymentMethod::query()->updateOrCreate(
            ['code' => 'cod'],
            [
                'name' => 'Cash on delivery',
                'description' => 'Pay the courier when the parcel arrives.',
                'driver' => 'offline',
                'is_enabled' => false,
                'position' => 2,
                'config' => ['instructions' => 'Pay the courier when the parcel arrives.'],
                'rules' => PaymentMethod::emptyRules(),
            ]
        );

        ShippingMethod::query()->updateOrCreate(
            ['code' => 'flat'],
            [
                'name' => 'Flat rate',
                'description' => 'Standard delivery',
                'type' => 'flat',
                'driver' => 'offline',
                'price' => 0,
                'is_enabled' => false,
                'position' => 1,
                'conditions' => ShippingMethod::emptyConditions(),
                'rate_config' => ['tiers' => []],
            ]
        );
        ShippingMethod::query()->updateOrCreate(
            ['code' => 'free'],
            [
                'name' => 'Free shipping',
                'description' => 'Free on qualifying orders',
                'type' => 'free',
                'driver' => 'offline',
                'price' => 0,
                'is_enabled' => false,
                'position' => 2,
                'conditions' => ShippingMethod::emptyConditions(),
                'rate_config' => ['tiers' => []],
            ]
        );
        ShippingMethod::query()->updateOrCreate(
            ['code' => 'local_pickup'],
            [
                'name' => 'Local pickup',
                'description' => 'Collect your order from the store.',
                'type' => 'local_pickup',
                'driver' => 'offline',
                'price' => 0,
                'is_enabled' => false,
                'position' => 3,
                'conditions' => ShippingMethod::emptyConditions(),
                'rate_config' => ['tiers' => []],
            ]
        );

        CoreCmsStubs::seed();

        $menu = Menu::query()->updateOrCreate(['code' => 'main'], ['name' => 'Main menu']);
        if (! MenuItem::query()->where('menu_id', $menu->id)->where('label', 'Home')->exists()) {
            MenuItem::query()->create(['menu_id' => $menu->id, 'label' => 'Home', 'url' => '/', 'position' => 1]);
        }

        DocumentTemplateCatalog::seed();

        $step(8, $total, 'Saving final store settings…');
        Setting::setValue('order_sequence', 10000);
        Setting::setValue('shop_name', 'WentoCart');
        Setting::setValue('shop_email', 'admin@wentocart.test');
        Setting::setValue('shop_address', '');
        Setting::setValue('shop_url', config('app.url'));
        Setting::setValue('multishop_enabled', '0');
        Setting::setValue('multivendor_enabled', '0');
        if (! Setting::getValue('install_id')) {
            Setting::setValue('install_id', (string) Str::uuid());
        }
        ShopUrl::query()->updateOrCreate(
            ['is_primary' => true],
            ShopUrl::attributesFromUrl((string) config('app.url'))
        );

        $defaultStore = StoreBootstrap::ensure();
        $defaultStore->fill([
            'name' => 'WentoCart',
            'email' => 'admin@wentocart.test',
        ])->save();
        StoreBootstrap::associateExisting($defaultStore);
        MailEvents::ensure($defaultStore->id);

        File::ensureDirectoryExists(storage_path('app'));
        File::put(config('wento.lock_path'), now()->toIso8601String());
    }

    protected function seedFallbackAdmin(): void
    {
        $super = Role::query()->where('slug', 'super_admin')->firstOrFail();
        $fallbackEmails = [
            'Demo@demo.com',
            'demo@demo.com',
            'admin@wentocart.test',
        ];
        $admin = Admin::query()->whereIn('email', $fallbackEmails)->first();
        if ($admin || ! Admin::query()->where('is_enabled', true)->exists()) {
            $admin ??= new Admin;
            $admin->fill([
                'email' => 'admin@wentocart.test',
                'name' => $admin->name ?: 'Super Admin',
                'password' => app()->environment('production')
                    ? Hash::make(Str::random(48))
                    : 'password',
                'is_enabled' => true,
            ]);
            $admin->save();
            $admin->roles()->sync([$super->id]);
        }
    }
}
