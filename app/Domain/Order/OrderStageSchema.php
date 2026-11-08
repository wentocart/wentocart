<?php

namespace App\Domain\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh installs get order_stage_id from the schema dump. Existing shops
 * receive the column the first time order stages are used.
 */
final class OrderStageSchema
{
    public static function ensure(): void
    {
        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'order_stage_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('order_stage_id')->nullable()->after('status');
                $table->index('order_stage_id');
            });
        }

        if (Schema::hasTable('order_status_histories') && ! Schema::hasColumn('order_status_histories', 'order_stage_id')) {
            Schema::table('order_status_histories', function (Blueprint $table) {
                $table->unsignedBigInteger('order_stage_id')->nullable()->after('status');
                $table->index('order_stage_id');
            });
        }

        self::backfill();
    }

    public static function backfill(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'order_stage_id') || ! Schema::hasTable('order_stages')) {
            return;
        }

        $map = DB::table('order_stages')->pluck('id', 'code');
        if ($map->isEmpty()) {
            return;
        }

        foreach ($map as $code => $id) {
            DB::table('orders')
                ->where('status', $code)
                ->whereNull('order_stage_id')
                ->update(['order_stage_id' => (int) $id]);
        }

        if (Schema::hasTable('order_status_histories') && Schema::hasColumn('order_status_histories', 'order_stage_id')) {
            foreach ($map as $code => $id) {
                DB::table('order_status_histories')
                    ->where('status', $code)
                    ->whereNull('order_stage_id')
                    ->update(['order_stage_id' => (int) $id]);
            }
        }
    }
}
