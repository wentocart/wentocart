<?php

namespace App\Domain\Order\Models;

use App\Domain\Customer\Models\Customer;
use App\Domain\Order\EuWithdrawalSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EuWithdrawalRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_COMPLETED,
    ];

    protected $fillable = [
        'customer_id',
        'order_id',
        'reference',
        'status',
        'reason',
        'admin_note',
        'requested_at',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function () {
            EuWithdrawalSchema::ensure();
        });
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        EuWithdrawalSchema::ensure();

        return parent::resolveRouteBinding($value, $field);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
