<?php

namespace App\Domain\Catalog;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh installs get this table from the schema dump. Existing shops
 * create it the first time price history is recorded.
 */
final class PriceHistorySchema
{
    public static function ensure(): void
    {
        if (Schema::hasTable('product_price_history')) {
            return;
        }

        Schema::create('product_price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->decimal('price', 12, 2);
            $table->date('recorded_on');
            $table->timestamp('recorded_at');
            $table->unique(['product_id', 'recorded_on', 'store_id'], 'price_history_product_day');
            $table->index(['product_id', 'recorded_at']);
        });
    }
}
