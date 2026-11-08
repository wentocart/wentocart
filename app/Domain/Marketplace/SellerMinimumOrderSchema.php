<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerMinimumOrderSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('sellers')) {
            return;
        }
        if (! Schema::hasColumn('sellers', 'minimum_order_amount')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->decimal('minimum_order_amount', 12, 2)->nullable();
            });
        }
    }
}
