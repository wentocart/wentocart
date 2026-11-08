<?php

namespace App\Domain\Store\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family', 'lookup_id', 'store_id', 'is_visible'])]
class ShopLookup extends Model
{
    public const FAMILY_BRAND = 'brands';

    public const FAMILY_SUPPLIER = 'suppliers';

    public const FAMILY_FEATURE = 'features';

    public const FAMILY_ATTRIBUTE = 'attributes';

    public const FAMILY_FILE = 'files';

    public const FAMILY_PLAN = 'plans';

    public const FAMILY_WAREHOUSE = 'warehouses';

    public const FAMILY_ORDER_STAGE = 'order_stages';

    public const FAMILY_CUSTOMER_GROUP = 'customer_groups';

    public const FAMILY_ADDRESS = 'addresses';

    public const FAMILY_REVIEW = 'reviews';

    public const FAMILY_QUESTION = 'questions';

    /** @return list<string> */
    public static function families(): array
    {
        return [
            self::FAMILY_BRAND,
            self::FAMILY_SUPPLIER,
            self::FAMILY_FEATURE,
            self::FAMILY_ATTRIBUTE,
            self::FAMILY_FILE,
            self::FAMILY_PLAN,
            self::FAMILY_WAREHOUSE,
            self::FAMILY_ORDER_STAGE,
            self::FAMILY_CUSTOMER_GROUP,
            self::FAMILY_ADDRESS,
            self::FAMILY_REVIEW,
            self::FAMILY_QUESTION,
        ];
    }

    /**
     * Lookup family => table of record ids. Used to link the original shop once.
     *
     * @return array<string, string>
     */
    public static function familyTables(): array
    {
        return [
            self::FAMILY_BRAND => 'brands',
            self::FAMILY_SUPPLIER => 'suppliers',
            self::FAMILY_FEATURE => 'features',
            self::FAMILY_ATTRIBUTE => 'attributes',
            self::FAMILY_FILE => 'attachments',
            self::FAMILY_PLAN => 'subscription_plans',
            self::FAMILY_WAREHOUSE => 'warehouses',
            self::FAMILY_ORDER_STAGE => 'order_stages',
            self::FAMILY_CUSTOMER_GROUP => 'customer_groups',
            self::FAMILY_ADDRESS => 'customer_addresses',
            self::FAMILY_REVIEW => 'reviews',
            self::FAMILY_QUESTION => 'product_questions',
        ];
    }

    protected function casts(): array
    {
        return ['is_visible' => 'boolean'];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
