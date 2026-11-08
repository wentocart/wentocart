<?php

namespace App\Domain\Order\Models;

use App\Domain\Auth\Models\Profile;
use App\Domain\Store\Concerns\ShopLookupAssigned;
use App\Domain\Store\Models\ShopLookup;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

#[Fillable([
    'code', 'name', 'buyer_label', 'buyer_note', 'tone', 'lane', 'tracker_step', 'sla_hours',
    'settle_payment', 'unlock_invoice', 'unlock_delivery_slip', 'unlock_credit_slip',
    'stock_move', 'notify_buyer', 'notify_staff', 'mail_template',
    'buyer_may_pay', 'buyer_may_cancel', 'next_stage_ids',
    'is_system', 'is_enabled', 'position',
])]
class OrderStage extends Model
{
    use ShopLookupAssigned;

    public static function shopLookupFamily(): string
    {
        return ShopLookup::FAMILY_ORDER_STAGE;
    }

    public const LANES = [
        'inbox' => 'Inbox — waiting on the shop or customer',
        'pack' => 'Pack — picking and packing',
        'transit' => 'Transit — with the carrier',
        'done' => 'Done — fulfilled',
        'hold' => 'Hold — paused or problem',
        'void' => 'Void — cancelled',
        'reverse' => 'Reverse — refunded',
    ];

    public const STOCK_MOVES = [
        'none' => 'Leave stock as-is',
        'return' => 'Return stock to warehouse',
    ];

    protected function casts(): array
    {
        return [
            'tracker_step' => 'integer',
            'sla_hours' => 'integer',
            'settle_payment' => 'boolean',
            'unlock_invoice' => 'boolean',
            'unlock_delivery_slip' => 'boolean',
            'unlock_credit_slip' => 'boolean',
            'notify_buyer' => 'boolean',
            'notify_staff' => 'boolean',
            'buyer_may_pay' => 'boolean',
            'buyer_may_cancel' => 'boolean',
            'next_stage_ids' => 'array',
            'is_system' => 'boolean',
            'is_enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    public function profiles(): BelongsToMany
    {
        return $this->belongsToMany(Profile::class, 'order_stage_profile')->withTimestamps();
    }

    /**
     * Empty profile list means every employee who can manage orders may use this status.
     * Super admins always have access.
     */
    public function isAccessibleTo(?Admin $admin): bool
    {
        if (! $admin) {
            return false;
        }
        if ($admin->isSuperAdmin()) {
            return true;
        }
        $this->loadMissing('profiles:id');
        if ($this->profiles->isEmpty()) {
            return true;
        }

        return $this->profiles->contains('id', (int) $admin->profile_id);
    }

    public static function queryAccessibleTo(?Admin $admin): Builder
    {
        $query = static::query()->enabled()->ordered();
        if (! $admin || $admin->isSuperAdmin()) {
            return $query;
        }

        $profileId = (int) $admin->profile_id;

        return $query->where(function (Builder $inner) use ($profileId) {
            $inner->whereDoesntHave('profiles')
                ->orWhereHas('profiles', fn (Builder $profiles) => $profiles->where('profiles.id', $profileId));
        });
    }

    /**
     * Statuses an admin may switch an order to, plus the order’s current status so the select stays valid.
     *
     * @return Collection<int, self>
     */
    public static function optionsForAdmin(?Admin $admin, ?Order $order = null): Collection
    {
        $stages = static::queryAccessibleTo($admin)->get();
        if (! $order) {
            return $stages;
        }

        $current = null;
        if ($order->order_stage_id) {
            $current = static::query()->whereKey((int) $order->order_stage_id)->first();
        }
        if (! $current) {
            $current = static::query()->where('code', $order->status)->first();
        }
        if ($current && ! $stages->contains('id', $current->id)) {
            return $stages->prepend($current);
        }

        return $stages;
    }

    public function laneLabel(): string
    {
        return self::LANES[$this->lane] ?? $this->lane;
    }

    /** Paid / cancelled / refunded / none — for admin form. */
    public function moneyMeaning(): string
    {
        if ($this->lane === 'reverse' || $this->unlock_credit_slip) {
            return 'refunded';
        }
        if ($this->lane === 'void') {
            return 'cancelled';
        }
        if ($this->settle_payment) {
            return 'paid';
        }

        return 'none';
    }

    /** Processing / shipped / delivered / stopped / none — for admin form. */
    public function fulfillmentMeaning(): string
    {
        return match ($this->lane) {
            'pack' => 'processing',
            'transit' => 'shipped',
            'done' => 'delivered',
            'void', 'reverse' => 'stopped',
            default => 'none',
        };
    }

    /**
     * Map form meanings into lane / payment / stock flags.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function attributesFromMeanings(array $data): array
    {
        $money = $data['money_meaning'] ?? 'none';
        $fulfillment = $data['fulfillment_meaning'] ?? 'none';

        $lane = match ($money) {
            'cancelled' => 'void',
            'refunded' => 'reverse',
            default => match ($fulfillment) {
                'processing' => 'pack',
                'shipped' => 'transit',
                'delivered' => 'done',
                'stopped' => 'hold',
                default => 'inbox',
            },
        };

        return [
            'lane' => $lane,
            'settle_payment' => $money === 'paid',
            'stock_move' => in_array($money, ['cancelled', 'refunded'], true) ? 'return' : 'none',
            'unlock_invoice' => (bool) ($data['unlock_invoice'] ?? ($money === 'paid')),
            'unlock_credit_slip' => (bool) ($data['unlock_credit_slip'] ?? ($money === 'refunded')),
            'unlock_delivery_slip' => (bool) ($data['unlock_delivery_slip'] ?? in_array($fulfillment, ['shipped', 'delivered'], true)),
            'tracker_step' => match ($fulfillment) {
                'processing' => 2,
                'shipped' => 3,
                'delivered' => 4,
                default => $money === 'none' ? 1 : null,
            },
        ];
    }

    public function allowsTransitionTo(self $next): bool
    {
        $ids = $this->next_stage_ids ?? [];
        if ($ids === [] || $ids === null) {
            return true;
        }

        return in_array((int) $next->id, array_map('intval', $ids), true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'buyer_label' => $this->buyer_label,
            'buyer_note' => $this->buyer_note,
            'tone' => $this->tone,
            'lane' => $this->lane,
            'lane_label' => $this->laneLabel(),
            'money_meaning' => $this->moneyMeaning(),
            'fulfillment_meaning' => $this->fulfillmentMeaning(),
            'tracker_step' => $this->tracker_step,
            'sla_hours' => $this->sla_hours,
            'settle_payment' => $this->settle_payment,
            'unlock_invoice' => $this->unlock_invoice,
            'unlock_delivery_slip' => $this->unlock_delivery_slip,
            'unlock_credit_slip' => $this->unlock_credit_slip,
            'stock_move' => $this->stock_move,
            'notify_buyer' => $this->notify_buyer,
            'notify_staff' => $this->notify_staff,
            'mail_template' => $this->mail_template,
            'buyer_may_pay' => $this->buyer_may_pay,
            'buyer_may_cancel' => $this->buyer_may_cancel,
            'next_stage_ids' => $this->next_stage_ids ?? [],
            'is_system' => $this->is_system,
            'is_enabled' => $this->is_enabled,
            'position' => $this->position,
            'profile_ids' => $this->relationLoaded('profiles')
                ? $this->profiles->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : [],
            'profiles' => $this->relationLoaded('profiles')
                ? $this->profiles->map(fn (Profile $profile) => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                ])->values()->all()
                : [],
            'actions' => [
                'edit' => true,
                'delete' => ! $this->is_system,
            ],
        ];
    }
}
