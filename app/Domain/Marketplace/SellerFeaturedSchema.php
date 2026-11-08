<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerFeaturedSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('sellers')) {
            return;
        }
        if (! Schema::hasColumn('sellers', 'is_featured')) {
            Schema::table('sellers', function (Blueprint $table) {
                $table->boolean('is_featured')->default(false);
                $table->unsignedInteger('featured_position')->default(0);
            });
        }
    }
}
