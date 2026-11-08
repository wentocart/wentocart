<?php

namespace App\Domain\Marketplace;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class SellerPaymentRequestSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('seller_orders')) {
            return;
        }
        if (! Schema::hasColumn('seller_orders', 'seller_payout_status')) {
            Schema::table('seller_orders', function (Blueprint $table) {
                $table->string('seller_payout_status', 32)->nullable()->index();
                $table->timestamp('seller_payout_requested_at')->nullable();
                $table->timestamp('seller_payout_paid_at')->nullable();
                $table->string('seller_payout_comment', 500)->nullable();
            });
        }
    }
}
