<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Settings\ShopMailer;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * excess products are switched off and need admin approval again.
 * Paid→paid upgrades wait until the current plan ends.
 */
class SellerPlanActivationService
{
    public function applyProductCap(Seller $seller, MarketplaceSubscriptionPlan $plan): int
    {
        $limit = $plan->product_limit;
        if ($limit === null) {
            return 0;
        }

        return (int) DB::transaction(function () use ($seller, $limit) {
            $keepIds = Product::query()
                ->where('seller_id', $seller->id)
                ->orderBy('id')
                ->limit((int) $limit)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $excess = Product::query()
                ->where('seller_id', $seller->id)
                ->when($keepIds !== [], fn ($q) => $q->whereNotIn('id', $keepIds))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($excess === []) {
                return 0;
            }

            Product::query()->whereIn('id', $excess)->update([
                'is_enabled' => false,
                'available_for_order' => false,
            ]);

            SellerProduct::query()
                ->where('seller_id', $seller->id)
                ->whereIn('product_id', $excess)
                ->update([
                    'is_enabled' => false,
                    'approval_status' => SellerProduct::STATUS_PENDING,
                    'approval_note' => 'Disabled: exceeds plan product limit.',
                ]);

            return count($excess);
        });
    }

    /**
     * Activate or schedule a paid subscription after payment / admin approval.
     *
     * @return array{status: string, productsDisabled: int, scheduled: bool}
     */
    public function activateOrSchedule(MarketplaceSellerSubscription $sub): array
    {
        $sub->loadMissing(['plan', 'seller']);
        $plan = $sub->plan;
        $seller = $sub->seller;
        if (! $plan || ! $seller) {
            $sub->update([
                'status' => MarketplaceSellerSubscription::STATUS_ACTIVE,
                'starts_at' => now(),
                'ends_at' => null,
                'paypal_token' => null,
                'payment_token' => null,
            ]);

            return ['status' => MarketplaceSellerSubscription::STATUS_ACTIVE, 'productsDisabled' => 0, 'scheduled' => false];
        }

        $current = MarketplaceSellerSubscription::query()
            ->with('plan')
            ->where('seller_id', $seller->id)
            ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
            ->where('id', '!=', $sub->id)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();

        $days = $plan->resolvedDurationDays();
        $currentIsFree = $current && (
            (float) ($current->price ?? $current->plan?->price ?? 0) <= 0
            || (bool) ($current->plan?->is_default ?? false)
            || (string) ($current->payment_method ?? '') === 'free'
        );
        if ($current && ! $currentIsFree && $current->ends_at && $current->ends_at->isFuture()) {
            $starts = $current->ends_at->copy();
            $sub->update([
                'status' => MarketplaceSellerSubscription::STATUS_SCHEDULED,
                'starts_at' => $starts,
                'ends_at' => $days === null ? null : $starts->copy()->addDays($days),
                'paypal_token' => null,
                'payment_token' => null,
            ]);
            $this->notify($seller, 'marketplace.subscription.created', $sub->fresh(['plan']));

            return [
                'status' => MarketplaceSellerSubscription::STATUS_SCHEDULED,
                'productsDisabled' => 0,
                'scheduled' => true,
            ];
        }

        if ($current) {
            $current->update([
                'status' => MarketplaceSellerSubscription::STATUS_CANCELLED,
                'ends_at' => now(),
            ]);
        }

        $sub->update([
            'status' => MarketplaceSellerSubscription::STATUS_ACTIVE,
            'starts_at' => now(),
            'ends_at' => $days === null ? null : now()->addDays($days),
            'paypal_token' => null,
            'payment_token' => null,
            'reminder_days_sent' => [],
        ]);
        $disabled = $this->applyProductCap($seller, $plan);
        $fresh = $sub->fresh(['plan']);
        $this->notify($seller, 'marketplace.subscription.created', $fresh);
        $this->notify($seller, 'marketplace.subscription.activated', $fresh);

        return [
            'status' => MarketplaceSellerSubscription::STATUS_ACTIVE,
            'productsDisabled' => $disabled,
            'scheduled' => false,
        ];
    }

    /** Promote scheduled subscriptions whose start date has arrived. */
    public function promoteDueScheduled(): int
    {
        $due = MarketplaceSellerSubscription::query()
            ->with(['plan', 'seller'])
            ->where('status', MarketplaceSellerSubscription::STATUS_SCHEDULED)
            ->whereNotNull('starts_at')
            ->where('starts_at', '<=', now())
            ->orderBy('id')
            ->get();

        $n = 0;
        foreach ($due as $sub) {
            MarketplaceSellerSubscription::query()
                ->where('seller_id', $sub->seller_id)
                ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
                ->where('id', '!=', $sub->id)
                ->update(['status' => MarketplaceSellerSubscription::STATUS_CANCELLED, 'ends_at' => now()]);

            $sub->update(['status' => MarketplaceSellerSubscription::STATUS_ACTIVE, 'reminder_days_sent' => []]);
            if ($sub->seller && $sub->plan) {
                $this->applyProductCap($sub->seller, $sub->plan);
                $this->notify($sub->seller, 'marketplace.subscription.activated', $sub->fresh(['plan']));
            }
            $n++;
        }

        return $n;
    }

    private function notify(Seller $seller, string $event, MarketplaceSellerSubscription $sub): void
    {
        $email = (string) ($seller->email ?? $seller->contact_email ?? '');
        if ($email === '') {
            return;
        }
        try {
            app(ShopMailer::class)->sendEvent($event, [
                'plan' => (string) ($sub->plan?->name ?? ''),
                'shop' => (string) ($seller->shop_name ?? ''),
                'status' => (string) $sub->status,
                'starts_at' => optional($sub->starts_at)?->toDateString(),
                'ends_at' => optional($sub->ends_at)?->toDateString(),
                'price' => (float) ($sub->price ?? 0),
            ], $email);
        } catch (Throwable) {
            // Mail must never block activation.
        }
    }
}
