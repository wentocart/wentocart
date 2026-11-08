<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerDiscountJoinSchema
{
    public static function ensure(): void
    {
        if (Schema::hasTable('discount_rules')
            && ! Schema::hasColumn('discount_rules', 'allow_sellers_to_join')) {
            Schema::table('discount_rules', function (Blueprint $table) {
                $table->boolean('allow_sellers_to_join')->default(true);
            });
        }

        if (! Schema::hasTable('marketplace_discount_joins')) {
            Schema::create('marketplace_discount_joins', function (Blueprint $table) {
                $table->id();
                $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
                $table->foreignId('discount_rule_id')->constrained('discount_rules')->cascadeOnDelete();
                $table->string('status', 32)->default('pending'); // pending|approved|disapproved
                $table->timestamps();
                $table->unique(['seller_id', 'discount_rule_id']);
                $table->index(['status', 'discount_rule_id']);
            });
        }
    }
}
