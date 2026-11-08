<?php

namespace App\Domain\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fresh installs get this table from the schema dump. Existing shops
 * create it the first time EU withdrawal is used.
 */
final class EuWithdrawalSchema
{
    public static function ensure(): void
    {
        if (! Schema::hasTable('eu_withdrawal_requests')) {
            Schema::create('eu_withdrawal_requests', function (Blueprint $table) {
                $table->id();
                // Nullable for guest EU withdrawals (order email proves ownership).
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('reference', 32)->nullable()->unique();
                $table->string('status', 16)->default('pending');
                $table->string('reason', 500)->nullable();
                $table->text('admin_note')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'customer_id']);
                $table->index(['order_id', 'status']);
            });
        } else {
            foreach ([
                'reference' => fn (Blueprint $table) => $table->string('reference', 32)->nullable()->unique()->after('order_id'),
                'admin_note' => fn (Blueprint $table) => $table->text('admin_note')->nullable()->after('reason'),
                'requested_at' => fn (Blueprint $table) => $table->timestamp('requested_at')->nullable()->after('admin_note'),
                'decided_at' => fn (Blueprint $table) => $table->timestamp('decided_at')->nullable()->after('requested_at'),
            ] as $column => $add) {
                if (! Schema::hasColumn('eu_withdrawal_requests', $column)) {
                    Schema::table('eu_withdrawal_requests', $add);
                }
            }
            self::ensureCustomerIdNullable();
        }

        if (Schema::hasColumn('eu_withdrawal_requests', 'reference')) {
            self::backfillReferences();
        }
    }

    public static function newReference(): string
    {
        do {
            $reference = 'EUWD-'.strtoupper(Str::random(8));
        } while (DB::table('eu_withdrawal_requests')->where('reference', $reference)->exists());

        return $reference;
    }

    private static function backfillReferences(): void
    {
        $ids = DB::table('eu_withdrawal_requests')->whereNull('reference')->pluck('id');
        foreach ($ids as $id) {
            DB::table('eu_withdrawal_requests')->where('id', $id)->update(['reference' => self::newReference()]);
        }
    }

    private static function ensureCustomerIdNullable(): void
    {
        if (! Schema::hasColumn('eu_withdrawal_requests', 'customer_id')) {
            return;
        }
        try {
            Schema::table('eu_withdrawal_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('customer_id')->nullable()->change();
            });
        } catch (\Throwable) {
            // doctrine/dbal may be unavailable; guest inserts will fail until migrated.
        }
    }
}
