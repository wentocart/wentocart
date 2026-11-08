<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\MarketplaceSubscriptionPlanSchema;
use App\Domain\Marketplace\Models\MarketplaceSellerSubscription;
use App\Domain\Marketplace\Models\MarketplaceSubscriptionPlan;
use App\Domain\Marketplace\Models\Seller;
use Illuminate\Validation\ValidationException;

/**
 * When subscription plans are enabled, public shop visibility requires an active plan.
 * Plan caps (max products / product types) apply to seller catalog writes.
 */
class SellerSubscriptionGate
{
    public function plansRequired(): bool
    {
        return MarketplaceSettings::subscriptionPlansEnabled();
    }

    public function hasActivePlan(Seller $seller): bool
    {
        if (! $this->plansRequired()) {
            return true;
        }

        return $this->activeSubscription($seller) !== null;
    }

    public function activeSubscription(Seller $seller): ?MarketplaceSellerSubscription
    {
        if (! $this->plansRequired()) {
            return null;
        }
        MarketplaceSubscriptionPlanSchema::ensure();

        return MarketplaceSellerSubscription::query()
            ->with('plan')
            ->where('seller_id', $seller->id)
            ->where('status', MarketplaceSellerSubscription::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();
    }

    public function activePlan(Seller $seller): ?MarketplaceSubscriptionPlan
    {
        return $this->activeSubscription($seller)?->plan;
    }

    /**
     * Empty product_limit = unlimited. Only enforced when plans are enabled
     * and the seller has an active plan with a numeric cap.
     */
    public function assertCanCreateProduct(Seller $seller, ?string $productType = null): void
    {
        if (! $this->plansRequired()) {
            return;
        }

        $sub = $this->activeSubscription($seller);
        if (! $sub) {
            throw ValidationException::withMessages([
                'plan' => 'An active subscription plan is required to create products.',
            ]);
        }

        $plan = $sub->plan;
        if ($plan && $productType !== null && $productType !== '') {
            $allowed = $plan->allowed_product_types;
            if (is_array($allowed) && $allowed !== [] && ! in_array($productType, $allowed, true)) {
                throw ValidationException::withMessages([
                    'type' => 'Your plan does not allow product type: '.$productType.'.',
                ]);
            }
        }

        $limit = $plan?->product_limit;
        if ($limit === null) {
            return;
        }
        $count = Product::query()->where('seller_id', $seller->id)->count();
        if ($count >= (int) $limit) {
            throw ValidationException::withMessages([
                'product_limit' => 'You have reached max limit of '.(int) $limit.' products.',
            ]);
        }
    }

    public function assertPubliclyVisible(Seller $seller): void
    {
        if (! $this->hasActivePlan($seller)) {
            abort(404);
        }
    }

    /**
     */
    public function planAllows(Seller $seller, string $permission): bool
    {
        if ($permission === '' || ! $this->plansRequired()) {
            return true;
        }
        $plan = $this->activePlan($seller);
        if (! $plan) {
            // Without an active plan, only profile/plans are usable (enforced at route layer).
            return in_array($permission, ['profile', 'plans'], true);
        }
        $perms = $plan->permissions;
        if (! is_array($perms) || $perms === [] || in_array('*', $perms, true) || in_array('all', $perms, true)) {
            return true;
        }

        return in_array($permission, $perms, true);
    }

    public function assertPlanAllows(Seller $seller, string $permission): void
    {
        if (! $this->planAllows($seller, $permission)) {
            abort(403, 'Your subscription plan does not include this permission.');
        }
    }

    /**
     */
    public function assertCanAcceptOrder(Seller $seller): void
    {
        if (! $this->plansRequired()) {
            return;
        }
        $sub = $this->activeSubscription($seller);
        if (! $sub) {
            throw ValidationException::withMessages([
                'plan' => 'An active subscription plan is required to accept orders.',
            ]);
        }
        $limit = $sub->plan?->order_limit;
        if ($limit === null) {
            return;
        }
        $query = \App\Domain\Marketplace\Models\SellerOrder::query()
            ->where('seller_id', $seller->id);
        if ($sub->starts_at) {
            $query->where('created_at', '>=', $sub->starts_at);
        }
        if ($query->count() >= (int) $limit) {
            throw ValidationException::withMessages([
                'order_limit' => 'You have reached max limit of '.(int) $limit.' orders for this plan.',
            ]);
        }
    }
}
