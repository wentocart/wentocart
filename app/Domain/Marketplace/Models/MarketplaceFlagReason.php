<?php

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'label', 'code', 'applies_to', 'is_enabled', 'position',
])]
class MarketplaceFlagReason extends Model
{
    public const APPLIES_SELLER = 'seller';

    public const APPLIES_PRODUCT = 'product';

    public const APPLIES_BOTH = 'both';

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }
}
