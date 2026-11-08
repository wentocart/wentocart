<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerWarehouseSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('seller_warehouses')) {
            Schema::create('seller_warehouses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('seller_id')->constrained('sellers')->cascadeOnDelete();
                $table->string('code', 64);
                $table->string('name', 120);
                $table->text('description')->nullable();
                $table->string('country_code', 2)->nullable();
                $table->string('state', 80)->nullable();
                $table->string('city', 80)->nullable();
                $table->string('street', 190)->nullable();
                $table->string('postcode', 32)->nullable();
                $table->string('contact_name', 120)->nullable();
                $table->string('contact_email', 190)->nullable();
                $table->string('contact_phone', 40)->nullable();
                $table->string('fax', 40)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->unsignedInteger('priority')->default(0);
                $table->boolean('is_enabled')->default(false);
                $table->timestamps();
                $table->unique(['seller_id', 'code']);
                $table->index(['seller_id', 'is_enabled']);
            });
        }

        if (Schema::hasTable('seller_products')
            && ! Schema::hasColumn('seller_products', 'inventory_by_warehouse')) {
            Schema::table('seller_products', function (Blueprint $table) {
                $table->json('inventory_by_warehouse')->nullable();
            });
        }
    }
}
