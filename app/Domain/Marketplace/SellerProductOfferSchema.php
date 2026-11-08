<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerProductOfferSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('seller_products')) {
            return;
        }

        if (! Schema::hasColumn('seller_products', 'condition')) {
            Schema::table('seller_products', function (Blueprint $table) {
                $table->string('condition', 16)->default('new');
            });
        }
        if (! Schema::hasColumn('seller_products', 'is_catalog_offer')) {
            Schema::table('seller_products', function (Blueprint $table) {
                $table->boolean('is_catalog_offer')->default(false);
            });
        }
        if (! Schema::hasColumn('seller_products', 'is_draft')) {
            Schema::table('seller_products', function (Blueprint $table) {
                $table->boolean('is_draft')->default(false);
            });
        }
    }
}
