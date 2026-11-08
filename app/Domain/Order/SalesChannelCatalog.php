<?php

namespace App\Domain\Order;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales channels for order settings selects (ID in UI, code on orders.channel).
 */
final class SalesChannelCatalog
{
    /** @var list<array{code: string, name: string, position: int}> */
    public const DEFAULTS = [
        ['code' => 'web', 'name' => 'Online Store', 'position' => 10],
        ['code' => 'pos', 'name' => 'POS', 'position' => 20],
        ['code' => 'phone', 'name' => 'Phone', 'position' => 30],
    ];

    public static function ensure(): void
    {
        if (! Schema::hasTable('sales_channels')) {
            Schema::create('sales_channels', function (Blueprint $table) {
                $table->id();
                $table->string('code', 32)->unique();
                $table->string('name', 120);
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
            });
        }

        foreach (self::DEFAULTS as $row) {
            if (DB::table('sales_channels')->where('code', $row['code'])->exists()) {
                continue;
            }
            DB::table('sales_channels')->insert([
                'code' => $row['code'],
                'name' => $row['name'],
                'position' => $row['position'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'sales_channel_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('sales_channel_id')->nullable()->after('channel');
                $table->index('sales_channel_id');
            });
        }

        self::backfillOrders();
    }

    public static function backfillOrders(): void
    {
        if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'sales_channel_id')) {
            return;
        }

        $map = DB::table('sales_channels')->pluck('id', 'code');
        foreach ($map as $code => $id) {
            DB::table('orders')
                ->where('channel', $code)
                ->whereNull('sales_channel_id')
                ->update(['sales_channel_id' => (int) $id]);
        }
    }

    /**
     * @return list<array{id: int, code: string, name: string}>
     */
    public static function options(): array
    {
        self::ensure();

        return DB::table('sales_channels')
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
        $id = DB::table('sales_channels')->where('code', $code)->value('id');

        return $id !== null ? (int) $id : null;
    }

    public static function codeForId(int|string|null $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        self::ensure();
        $code = DB::table('sales_channels')->where('id', (int) $id)->value('code');

        return $code !== null ? (string) $code : null;
    }
}
