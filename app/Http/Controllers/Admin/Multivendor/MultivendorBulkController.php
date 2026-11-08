<?php

namespace App\Http\Controllers\Admin\Multivendor;

use App\Domain\Marketplace\Models\MarketplaceCommissionRule;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerBrand;
use App\Domain\Marketplace\Models\SellerCategory;
use App\Domain\Marketplace\Models\SellerCommission;
use App\Domain\Marketplace\Models\SellerDiscount;
use App\Domain\Marketplace\Models\SellerFeature;
use App\Domain\Marketplace\Models\SellerPaymentMethod;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerReview;
use App\Domain\Marketplace\Models\SellerShippingMethod;
use App\Domain\Marketplace\Models\SellerSupplier;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Marketplace\Services\SellerCatalogService;
use App\Domain\Marketplace\Services\SellerReviewService;
use App\Domain\Marketplace\Services\SellerService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MultivendorBulkController extends Controller
{
    public function __construct(
        protected SellerService $sellers,
        protected SellerCatalogService $catalog,
        protected SellerReviewService $reviews,
    ) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'screen' => ['required', 'string', 'max:40'],
            'action' => ['required', 'in:delete,enable,disable,approve,reject'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($data['action'] === 'reject' && trim((string) ($data['note'] ?? '')) === ''
            && in_array($data['screen'], ['products', 'requests', 'sellers', 'profiles'], true)) {
            return back()->withErrors(['note' => 'A rejection reason is required.']);
        }

        $count = match ($data['action']) {
            'delete' => $this->bulkDelete($data['screen'], $data['ids']),
            'enable' => $this->bulkStatus($data['screen'], $data['ids'], true),
            'disable' => $this->bulkStatus($data['screen'], $data['ids'], false),
            'approve' => $this->bulkModerate($data['screen'], $data['ids'], 'approve', $data['note'] ?? null, $request),
            'reject' => $this->bulkModerate($data['screen'], $data['ids'], 'reject', $data['note'] ?? null, $request),
        };

        if ($count === 0) {
            return back()->with('error', 'No records were updated.');
        }

        return back()->with('success', 'Bulk action applied to '.$count.' record(s).');
    }

    /** @param list<int> $ids */
    protected function bulkDelete(string $screen, array $ids): int
    {
        $count = 0;

        foreach ($ids as $id) {
            $deleted = match ($screen) {
                'products' => $this->deleteProduct($id),
                'categories' => $this->deleteTaxonomy('categories', $id),
                'features' => $this->deleteTaxonomy('features', $id),
                'brands' => $this->deleteTaxonomy('brands', $id),
                'suppliers' => $this->deleteTaxonomy('suppliers', $id),
                'discounts' => $this->deleteModel(SellerDiscount::class, $id, function ($row) {
                    $row->loadMissing('seller');
                    if ($row->seller) {
                        $this->sellers->deleteDiscount($row->seller, $row);
                    } else {
                        $row->delete();
                    }
                }),
                'shipping' => $this->deleteModel(SellerShippingMethod::class, $id),
                'payments' => $this->deleteModel(SellerPaymentMethod::class, $id),
                'commission-rules' => $this->deleteModel(MarketplaceCommissionRule::class, $id),
                'commissions' => $this->deleteModel(SellerCommission::class, $id),
                'reviews' => $this->deleteModel(SellerReview::class, $id),
                default => false,
            };

            if ($deleted) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<int> $ids */
    protected function bulkStatus(string $screen, array $ids, bool $enabled): int
    {
        $model = match ($screen) {
            'products' => SellerProduct::class,
            'categories' => SellerCategory::class,
            'features' => SellerFeature::class,
            'brands' => SellerBrand::class,
            'suppliers' => SellerSupplier::class,
            'discounts' => SellerDiscount::class,
            'shipping' => SellerShippingMethod::class,
            'payments' => SellerPaymentMethod::class,
            'commissions' => SellerCommission::class,
            'commission-rules' => MarketplaceCommissionRule::class,
            default => null,
        };

        if ($screen === 'products') {
            $count = 0;
            foreach (SellerProduct::query()->whereIn('id', $ids)->get() as $row) {
                $this->sellers->setListingEnabled($row, $enabled);
                $count++;
            }

            return $count;
        }

        if ($screen === 'shops') {
            $count = 0;
            foreach (Seller::query()->whereIn('id', $ids)->get() as $seller) {
                if ($seller->status === Seller::STATUS_BLOCKED) {
                    continue;
                }
                $this->sellers->setShopVisible($seller, $enabled);
                $count++;
            }

            return $count;
        }

        if ($screen === 'discounts') {
            $rows = SellerDiscount::query()->whereIn('id', $ids)->get();
            foreach ($rows as $row) {
                $row->update(['is_enabled' => $enabled]);
                if ($row->discount_rule_id) {
                    DiscountRule::query()->whereKey($row->discount_rule_id)->update(['is_enabled' => $enabled]);
                }
            }

            return $rows->count();
        }

        if ($model === null) {
            return 0;
        }

        return $model::query()->whereIn('id', $ids)->update(['is_enabled' => $enabled]);
    }

    /** @param list<int> $ids */
    protected function bulkModerate(string $screen, array $ids, string $action, ?string $note, Request $request): int
    {
        $adminId = $request->user('admin')?->id;
        $count = 0;

        foreach ($ids as $id) {
            if ($screen === 'products') {
                $row = SellerProduct::query()->find($id);
                if (! $row || $row->approval_status !== SellerProduct::STATUS_PENDING) {
                    continue;
                }
                $this->sellers->reviewProduct($row, $action, $note, $adminId);
                $count++;

                continue;
            }

            if ($screen === 'reviews') {
                $row = SellerReview::query()->find($id);
                if (! $row || $row->status !== 'pending') {
                    continue;
                }
                $this->reviews->moderate($row, $action, $adminId, $note);
                $count++;

                continue;
            }

            if (in_array($screen, ['requests', 'sellers', 'profiles'], true)) {
                $row = Seller::query()->find($id);
                if (! $row) {
                    continue;
                }
                if ($action === 'approve') {
                    if (! in_array($row->status, [Seller::STATUS_PENDING, Seller::STATUS_BLOCKED], true)) {
                        continue;
                    }
                } elseif ($row->status !== Seller::STATUS_PENDING) {
                    continue;
                }
                $this->sellers->review($row, $action, $note, $adminId);
                $count++;
            }
        }

        return $count;
    }

    protected function deleteProduct(int $id): bool
    {
        $row = SellerProduct::query()->with(['seller', 'product'])->find($id);
        if (! $row) {
            return false;
        }

        $seller = $row->seller;
        $core = $row->product;
        if ($seller && $core) {
            $this->sellers->deleteProduct($seller, $core);
        } else {
            $row->delete();
        }

        return true;
    }

    protected function deleteTaxonomy(string $kind, int $id): bool
    {
        $row = match ($kind) {
            'categories' => SellerCategory::query()->with('seller')->find($id),
            'features' => SellerFeature::query()->with('seller')->find($id),
            'brands' => SellerBrand::query()->with('seller')->find($id),
            default => SellerSupplier::query()->with('seller')->find($id),
        };

        if (! $row || ! $row->seller) {
            return false;
        }

        match ($kind) {
            'categories' => $this->catalog->deleteCategory($row->seller, $row),
            'features' => $this->catalog->deleteFeature($row->seller, $row),
            'brands' => $this->catalog->deleteBrand($row->seller, $row),
            default => $this->catalog->deleteSupplier($row->seller, $row),
        };

        return true;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  callable(Model): void|null  $callback
     */
    protected function deleteModel(string $modelClass, int $id, ?callable $callback = null): bool
    {
        $row = $modelClass::query()->find($id);
        if (! $row) {
            return false;
        }

        if ($callback) {
            $callback($row);
        } else {
            $row->delete();
        }

        return true;
    }
}
