<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ProductFlagSchema
{
    public static function ensure(): void
    {
        if (Schema::hasTable('product_flags')) {
            return;
        }

        Schema::create('product_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('seller_id')->nullable()->constrained('sellers')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('reason', 120);
            $table->text('body')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('moderated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_note', 500)->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
            $table->index(['seller_id', 'status']);
        });
    }
}
