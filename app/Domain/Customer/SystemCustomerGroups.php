<?php

namespace App\Domain\Customer;

use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Store\Models\ShopLookup;
use App\Domain\Store\Models\Store;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Visitor, Guest, and Customer exist in every shop and cannot be removed.
 */
class SystemCustomerGroups
{
    public const VISITOR = 'visitor';

    public const GUEST = 'guest';

    public const CUSTOMER = 'customer';

    /**
     * @return list<array{code: string, name: string, description: string, position: int}>
     */
    public static function definitions(): array
    {
        return [
            [
                'code' => self::VISITOR,
                'name' => 'Visitor',
                'description' => 'People without an account, or customers who are not logged in.',
                'position' => 1,
            ],
            [
                'code' => self::GUEST,
                'name' => 'Guest',
                'description' => 'People who placed an order through guest checkout.',
                'position' => 2,
            ],
            [
                'code' => self::CUSTOMER,
                'name' => 'Customer',
                'description' => 'People who created an account.',
                'position' => 3,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_column(self::definitions(), 'code');
    }

    public static function ensure(): void
    {
        if (! Schema::hasTable('customer_groups') || ! Schema::hasTable('stores')) {
            return;
        }

        self::ensureColumn();

        $storeIds = Store::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $token = implode(',', $storeIds);
        if (Cache::get('wento.system_customer_groups') === $token) {
            return;
        }

        foreach (self::definitions() as $definition) {
            $group = CustomerGroup::withoutGlobalScope('shop')->firstOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'position' => $definition['position'],
                    'discount_percent' => 0,
                    'tax_exempt' => false,
                    'hide_prices' => false,
                    'is_enabled' => true,
                    'is_system' => true,
                    'color' => 'primary',
                ],
            );

            if (! $group->is_system || ! $group->is_enabled) {
                $group->forceFill([
                    'is_system' => true,
                    'is_enabled' => true,
                ])->save();
            }

            foreach ($storeIds as $storeId) {
                if ($storeId < 1) {
                    continue;
                }
                ShopLookup::query()->firstOrCreate(
                    [
                        'family' => ShopLookup::FAMILY_CUSTOMER_GROUP,
                        'lookup_id' => $group->id,
                        'store_id' => $storeId,
                    ],
                    ['is_visible' => true],
                );
            }
        }

        Cache::forever('wento.system_customer_groups', $token);
    }

    public static function ensureColumn(): void
    {
        if (Schema::hasColumn('customer_groups', 'is_system')) {
            return;
        }

        Schema::table('customer_groups', function (Blueprint $table) {
            $table->boolean('is_system')->default(false);
        });
    }
}
