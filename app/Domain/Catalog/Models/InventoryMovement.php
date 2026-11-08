<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Marketplace\Models\Seller;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'seller_id', 'warehouse_id', 'action', 'qty', 'reference'])]
class InventoryMovement extends Model
{
    public const ACTION_SALE = 'sale';

    public const ACTION_RESERVATION = 'reservation';

    public const ACTION_RELEASE = 'release';

    public const ACTION_RESTOCK = 'restock';

    public const ACTION_RETURN = 'return';

    public const ACTION_ADJUSTMENT = 'adjustment';

    public const ACTION_PURCHASE = 'purchase';

    public const ACTION_TRANSFER_OUT = 'transfer_out';

    public const ACTION_TRANSFER_IN = 'transfer_in';

    /** @deprecated Prefer ACTION_SALE */
    public const ACTION_ORDER = 'order';

    public const ACTIONS = [
        self::ACTION_SALE,
        self::ACTION_RESERVATION,
        self::ACTION_RELEASE,
        self::ACTION_RESTOCK,
        self::ACTION_RETURN,
        self::ACTION_ADJUSTMENT,
        self::ACTION_PURCHASE,
        self::ACTION_TRANSFER_OUT,
        self::ACTION_TRANSFER_IN,
        self::ACTION_ORDER,
        'cancel',
        'order_cancelled',
        'order_edit',
        'added',
        'removed',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:3',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public static function normalizeAction(string $action): string
    {
        return match ($action) {
            'order', 'order_edit' => self::ACTION_SALE,
            'cancel', 'order_cancelled', 'added' => self::ACTION_RESTOCK,
            'removed' => self::ACTION_ADJUSTMENT,
            default => $action,
        };
    }
}
