<?php

namespace App\Domain\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stable payment-status rows so admin selects can use IDs while business
 * logic keeps comparing the unique `code` string.
 */
final class PaymentStatusCatalog
{
    /** @var list<array{code: string, name: string, position: int}> */
    public const DEFAULTS = [
        ['code' => 'pending', 'name' => 'Pending', 'position' => 10],
        ['code' => 'partial', 'name' => 'Partial', 'position' => 20],
        ['code' => 'paid', 'name' => 'Paid', 'position' => 30],
        ['code' => 'failed', 'name' => 'Failed', 'position' => 40],
        ['code' => 'refunded', 'name' => 'Refunded', 'position' => 50],
        ['code' => 'partially_refunded', 'name' => 'Partially refunded', 'position' => 60],
    ];

    public static function ensure(): void
    {
        if (! Schema::hasTable('payment_statuses')) {
            Schema::create('payment_statuses', function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->unique();
                $table->string('name', 120);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
            });
        }

        foreach (self::DEFAULTS as $row) {
            $existing = DB::table('payment_statuses')->where('code', $row['code'])->first();
            if ($existing) {
                continue;
            }
            DB::table('payment_statuses')->insert([
                'code' => $row['code'],
                'name' => $row['name'],
                'position' => $row['position'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'payment_status_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('payment_status_id')->nullable()->after('payment_status');
                $table->index('payment_status_id');
            });
        }

        self::backfillOrders();
    }

    public static function backfillOrders(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'payment_status_id')) {
            return;
        }

        $map = DB::table('payment_statuses')->pluck('id', 'code');
        foreach ($map as $code => $id) {
            DB::table('orders')
                ->where('payment_status', $code)
                ->whereNull('payment_status_id')
                ->update(['payment_status_id' => (int) $id]);
        }
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public static function options(): array
    {
        self::ensure();

        return DB::table('payment_statuses')
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id', 'code', 'name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])
            ->values()
            ->all();
    }

    public static function idForCode(?string $code): ?int
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        self::ensure();
        $id = DB::table('payment_statuses')->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }

    public static function codeForId(int|string|null $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        self::ensure();
        $code = DB::table('payment_statuses')->where('id', (int) $id)->value('code');

        return $code !== null ? (string) $code : null;
    }

    public static function resolveCode(int|string|null $idOrCode): ?string
    {
        if ($idOrCode === null || $idOrCode === '') {
            return null;
        }
        if (is_numeric($idOrCode) && ! preg_match('/[a-z_]/i', (string) $idOrCode)) {
            return self::codeForId((int) $idOrCode);
        }

        $code = trim((string) $idOrCode);

        return self::idForCode($code) ? $code : null;
    }
}
