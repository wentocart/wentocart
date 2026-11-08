<?php

namespace App\Domain\Promo\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DiscountRedemption extends Model
{
    protected $fillable = [
        'discount_rule_id', 'customer_id', 'email', 'order_id', 'store_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_rule_id' => 'integer',
            'customer_id' => 'integer',
            'order_id' => 'integer',
            'store_id' => 'integer',
        ];
    }

    /**
     * Existing shops predate this table: create it (and the per-customer
     * limit column) on first use instead of requiring a migration run.
     */
    public static function ensureStorage(): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }
        $ensured = true;

        try {
            if (! Schema::hasTable('discount_redemptions')) {
                Schema::create('discount_redemptions', function ($table) {
                    $table->id();
                    $table->unsignedBigInteger('discount_rule_id');
                    $table->unsignedBigInteger('customer_id')->nullable();
                    $table->string('email', 190)->nullable();
                    $table->unsignedBigInteger('order_id')->nullable();
                    $table->unsignedBigInteger('store_id')->nullable();
                    $table->timestamps();
                    $table->unique(['discount_rule_id', 'order_id']);
                    $table->index(['discount_rule_id', 'customer_id']);
                    $table->index(['email']);
                });
            }
            if (! Schema::hasColumn('discount_rules', 'max_uses_per_customer')) {
                Schema::table('discount_rules', function ($table) {
                    $table->unsignedInteger('max_uses_per_customer')->nullable();
                });
            }
        } catch (Throwable) {
            // Storage without DDL rights keeps working until the DBA adds
            // the column/table; enforcement then activates automatically.
            $ensured = false;
        }
    }

    public static function usedByRule(int $ruleId, ?int $customerId, ?string $email): int
    {
        self::ensureStorage();

        return static::query()
            ->where('discount_rule_id', $ruleId)
            ->where(function ($query) use ($customerId, $email) {
                if ($customerId) {
                    $query->where('customer_id', $customerId);
                }
                if ($email !== null && $email !== '') {
                    $query->orWhere('email', strtolower(trim($email)));
                }
                if (! $customerId && ($email === null || $email === '')) {
                    $query->whereRaw('1 = 0');
                }
            })
            ->count();
    }
}
