<?php

namespace App\Domain\Marketplace\Services;

use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Feature;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Supplier;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Catalog\Support\ProductCsvSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Language;
use App\Domain\Marketplace\MarketplaceSettings;
use App\Domain\Marketplace\Models\Seller;
use App\Domain\Marketplace\Models\SellerBrand;
use App\Domain\Marketplace\Models\SellerCategory;
use App\Domain\Marketplace\Models\SellerCommission;
use App\Domain\Marketplace\Models\SellerDiscount;
use App\Domain\Marketplace\Models\SellerEarning;
use App\Domain\Marketplace\Models\SellerFeature;
use App\Domain\Marketplace\Models\SellerOrder;
use App\Domain\Marketplace\Models\SellerOrderItem;
use App\Domain\Marketplace\Models\SellerPaymentMethod;
use App\Domain\Marketplace\Models\SellerProduct;
use App\Domain\Marketplace\Models\SellerRevenue;
use App\Domain\Marketplace\Models\SellerShippingMethod;
use App\Domain\Marketplace\Models\SellerSupplier;
use App\Domain\Marketplace\Models\SellerTranslation;
use App\Domain\Security\HtmlSanitizer;
use App\Domain\Security\RasterImageUpload;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Promo\Models\DiscountRule;
use App\Domain\Marketplace\Events\MarketplaceEvent;
use App\Domain\Marketplace\Services\MarketplaceLifecycleBroker;
use App\Domain\Shipping\Models\ShippingMethod;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerService
{
    public const DEFAULT_COMMISSION = 10.0;

    public function __construct(
        protected ProductService $products,
        protected SellerProductCatalogService $catalog,
    ) {}

    public function apply(Customer $customer, array $data, bool $reapply = false): Seller
    {
        return DB::transaction(function () use ($customer, $data, $reapply) {
            $existing = Seller::query()
                ->where('customer_id', $customer->id)
                ->lockForUpdate()
                ->first();

            if ($existing?->isSuspended()) {
                abort(403, 'This seller account is suspended.');
            }
            if ($existing && $existing->isPending()) {
                throw ValidationException::withMessages([
                    'shop_slug' => 'A seller request is already waiting for review.',
                ]);
            }
            if ($existing && $existing->isActive()) {
                throw ValidationException::withMessages([
                    'shop_slug' => 'This account is already a seller.',
                ]);
            }
            if ($existing && ! $reapply && ! $existing->canReapply()) {
                throw ValidationException::withMessages([
                    'shop_slug' => 'A seller request already exists for this account.',
                ]);
            }
            if ($reapply && $existing && ! $existing->canReapply()) {
                throw ValidationException::withMessages([
                    'shop_slug' => 'Only a rejected seller application can be submitted again.',
                ]);
            }

            // Never trust a client-supplied seller identity.
            unset($data['seller_id'], $data['customer_id'], $data['status']);

            $payload = $this->profilePayload($data);
            $payload['customer_id'] = $customer->id;
            $payload['status'] = Seller::STATUS_PENDING;
            $payload['review_note'] = null;
            $payload['reviewed_by'] = null;
            $payload['reviewed_at'] = null;
            $payload['blocked_at'] = null;
            $payload['commission_rate'] = $existing?->commission_rate ?? MarketplaceSettings::defaultCommission();

            if ($existing) {
                $existing->fill($payload)->save();
                $fresh = $existing->fresh();
                $this->syncShopTranslations($fresh, $data);
                if (! MarketplaceSettings::approvalRequired()) {
                    $fresh = $this->review($fresh, 'approve', 'Auto-approved (approval not required).', null, null);
                } else {
                    app(MarketplaceLifecycleBroker::class)->dispatch(MarketplaceEvent::sellerApplicationSubmitted($fresh));
                }

                return $fresh->fresh(['translations']);
            }

            $payload['commission_rate'] = MarketplaceSettings::defaultCommission();

            $created = Seller::query()->create($payload);
            $this->syncShopTranslations($created, $data);
            $fresh = $created->fresh();
            if (! MarketplaceSettings::approvalRequired()) {
                $fresh = $this->review($fresh, 'approve', 'Auto-approved (approval not required).', null, null);
            } else {
                app(MarketplaceLifecycleBroker::class)->dispatch(MarketplaceEvent::sellerApplicationSubmitted($fresh));
            }

            return $fresh->fresh(['translations']);
        });
    }

    /**
     * Admin lifecycle transitions.
     *
     * Supported actions (existing API values kept for compatibility):
     * - approve     : PENDING → APPROVED (ACTIVE), or BLOCKED → APPROVED (reactivate)
     * - reject      : PENDING → REJECTED (reason required)
     * - block|suspend : APPROVED → BLOCKED (SUSPENDED; reason required)
     * - reactivate  : alias for approve from BLOCKED
     */
    public function review(Seller $seller, string $action, ?string $note = null, ?int $adminId = null, ?float $commissionRate = null): Seller
    {
        $action = match ($action) {
            'suspend' => 'block',
            'reactivate' => 'approve',
            default => $action,
        };

        if (! in_array($action, ['approve', 'reject', 'block'], true)) {
            abort(422, 'Unknown seller review action.');
        }

        $result = DB::transaction(function () use ($seller, $action, $note, $adminId, $commissionRate) {
            /** @var Seller $seller */
            $seller = Seller::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
            $from = $seller->status;
            $note = $note !== null ? trim($note) : null;
            if ($note === '') {
                $note = null;
            }

            if ($action === 'approve') {
                if ($seller->isActive()) {
                    return ['seller' => $seller, 'event' => null]; // idempotent
                }
                if (! in_array($from, [Seller::STATUS_PENDING, Seller::STATUS_BLOCKED], true)) {
                    abort(422, 'Seller can only be approved from pending or suspended status.');
                }
                $wasSuspended = $from === Seller::STATUS_BLOCKED;
                $seller->status = Seller::STATUS_APPROVED;
                $seller->blocked_at = null;
                if ($commissionRate !== null) {
                    $seller->commission_rate = $commissionRate;
                }
                $this->ensureDefaultCommission($seller);
                $seller->review_note = $note;
                $seller->reviewed_by = $adminId;
                $seller->reviewed_at = now();
                $seller->save();
                $this->syncShopAvailability($seller->fresh());
                $fresh = $seller->fresh();

                return [
                    'seller' => $fresh,
                    'event' => $wasSuspended ? null : MarketplaceEvent::sellerApproved($fresh, $adminId, $from),
                ];
            }

            if ($action === 'reject') {
                if ($seller->isRejected()) {
                    return ['seller' => $seller, 'event' => null]; // idempotent
                }
                if ($from !== Seller::STATUS_PENDING) {
                    abort(422, 'Only a pending seller application can be rejected.');
                }
                if ($note === null) {
                    throw ValidationException::withMessages([
                        'note' => 'A rejection reason is required.',
                    ]);
                }
                $seller->status = Seller::STATUS_REJECTED;
                $seller->review_note = $note;
                $seller->reviewed_by = $adminId;
                $seller->reviewed_at = now();
                $seller->save();
                $this->syncShopAvailability($seller->fresh());

                return [
                    'seller' => $seller->fresh(),
                    'event' => MarketplaceEvent::sellerRejected($seller->fresh(), $adminId, $note),
                ];
            }

            // block / suspend
            if ($seller->isSuspended()) {
                return ['seller' => $seller, 'event' => null]; // idempotent
            }
            if ($from !== Seller::STATUS_APPROVED) {
                abort(422, 'Only an active seller can be suspended.');
            }
            if ($note === null) {
                throw ValidationException::withMessages([
                    'note' => 'A suspension reason is required.',
                ]);
            }
            $seller->status = Seller::STATUS_BLOCKED;
            $seller->blocked_at = now();
            $seller->review_note = $note;
            $seller->reviewed_by = $adminId;
            $seller->reviewed_at = now();
            $seller->save();
            $this->disableSellerCatalog($seller);
            $this->syncShopAvailability($seller->fresh());

            return [
                'seller' => $seller->fresh(),
                'event' => MarketplaceEvent::sellerSuspended($seller->fresh(), $adminId, $note),
            ];
        });

        if ($result['event'] ?? null) {
            app(MarketplaceLifecycleBroker::class)->dispatch($result['event']);
        }

        return $result['seller'];
    }

    public function updateProfile(Seller $seller, array $data): Seller
    {
        $seller->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'profile_name' => $data['profile_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'contact_name' => $data['contact_name'],
            'contact_phone' => $data['contact_phone'],
            'contact_email' => $data['contact_email'] ?? $data['email'],
            'tax_id' => $data['tax_id'] ?? null,
        ])->save();

        return $seller->fresh();
    }

    public function updateShop(Seller $seller, array $data, array $files = []): Seller
    {
        // Never trust client identity fields.
        unset($data['seller_id'], $data['customer_id'], $data['status'], $data['commission_rate']);

        $payload = [
            'shop_name' => $data['shop_name'],
            'shop_slug' => Str::slug((string) $data['shop_slug']),
            'address_line1' => $data['address_line1'],
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'],
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'],
            'country_code' => strtoupper((string) $data['country_code']),
            'description' => $data['description'] ?? null,
        ];
        foreach ([
            'facebook_url', 'twitter_url', 'instagram_url', 'youtube_url', 'vacation_message',
            'store_policy', 'shipping_policy', 'return_policy',
        ] as $key) {
            if (array_key_exists($key, $data)) {
                $payload[$key] = $data[$key] !== '' && $data[$key] !== null ? $data[$key] : null;
            }
        }
        if (array_key_exists('vacation_enabled', $data)) {
            $payload['vacation_enabled'] = (bool) $data['vacation_enabled'];
        }
        if (array_key_exists('is_active', $data) && $seller->status !== Seller::STATUS_BLOCKED) {
            $payload['is_active'] = (bool) $data['is_active'];
        }
        if (array_key_exists('minimum_order_amount', $data)) {
            \App\Domain\Marketplace\SellerMinimumOrderSchema::ensure();
            $raw = $data['minimum_order_amount'];
            $payload['minimum_order_amount'] = ($raw === null || $raw === '')
                ? null
                : max(0, round((float) $raw, 2));
        }

        $seller->fill($payload)->save();
        $this->syncShopTranslations($seller->fresh(), $data);
        $this->syncSellerMedia($seller->fresh(), $data, $files);
        $this->syncShopAvailability($seller->fresh());

        return $seller->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, \Illuminate\Http\UploadedFile|null>  $files
     */
    protected function syncSellerMedia(Seller $seller, array $data, array $files): void
    {
        $changed = false;

        if (! empty($data['remove_logo'])) {
            $this->deletePublicFile($seller->logo_path);
            $seller->logo_path = null;
            $changed = true;
        } elseif (! empty($files['logo'])) {
            $this->deletePublicFile($seller->logo_path);
            $seller->logo_path = $this->storeSellerImage($seller, $files['logo'], 'logo');
            $changed = true;
        }

        if (! empty($data['remove_banner'])) {
            $this->deletePublicFile($seller->banner_path);
            $seller->banner_path = null;
            $changed = true;
        } elseif (! empty($files['banner'])) {
            $this->deletePublicFile($seller->banner_path);
            $seller->banner_path = $this->storeSellerImage($seller, $files['banner'], 'banner');
            $changed = true;
        }

        if ($changed) {
            $seller->save();
        }
    }

    protected function storeSellerImage(Seller $seller, UploadedFile $file, string $kind): string
    {
        $path = RasterImageUpload::storePublic(
            $file,
            'marketplace/sellers/'.$seller->id.'/'.$kind,
            (int) config('wento.upload.max_image_kb', 5120)
        );
        $this->publishPublicDiskPath($path);

        return $path;
    }

    protected function deletePublicFile(?string $path): void
    {
        if (! $path) {
            return;
        }

        \App\Domain\Settings\ObjectStorageSettings::disk()->delete($path);
        $this->deletePublishedPublicDiskPath($path);
    }

    protected function publishPublicDiskPath(string $path): void
    {
        if (\App\Domain\Settings\ObjectStorageSettings::diskName() !== 'public') {
            return;
        }
        if ($this->publicStorageIsLinked()) {
            return;
        }

        $relative = ltrim($path, '/');
        $source = storage_path('app/public/'.$relative);
        if (! is_file($source)) {
            return;
        }

        $target = public_path('storage/'.$relative);
        $directory = dirname($target);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return;
        }

        copy($source, $target);
    }

    protected function deletePublishedPublicDiskPath(string $path): void
    {
        if ($this->publicStorageIsLinked()) {
            return;
        }

        $target = public_path('storage/'.ltrim($path, '/'));
        if (is_file($target)) {
            unlink($target);
        }
    }

    protected function publicStorageIsLinked(): bool
    {
        return is_link(public_path('storage'));
    }

    public function publicMediaUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return asset('storage/'.ltrim($path, '/'));
    }

    public function setCommission(Seller $seller, float $rate, ?string $notes = null, ?string $name = null, ?SellerCommission $row = null, ?int $adminId = null, ?bool $isDefault = null): Seller
    {
        $beforeRate = (float) $seller->commission_rate;
        $rate = max(0, min(100, $rate));
        $seller->update(['commission_rate' => $rate]);
        $makeDefault = $isDefault ?? true;
        $others = $seller->commissions()->when($row, fn ($query) => $query->whereKeyNot($row->id));
        if ($makeDefault || ! $others->where('is_default', true)->exists()) {
            $makeDefault = true;
            $seller->commissions()->when($row, fn ($query) => $query->whereKeyNot($row->id))->update(['is_default' => false]);
        }

        if ($row && (int) $row->seller_id === (int) $seller->id) {
            $row->fill([
                'name' => ($name !== null && $name !== '') ? $name : ($row->name ?: 'Store commission'),
                'type' => SellerCommission::TYPE_PERCENT,
                'rate' => $rate,
                'notes' => $notes,
                'is_default' => $makeDefault,
                'is_enabled' => true,
            ])->save();
        } else {
            SellerCommission::query()->create([
                'seller_id' => $seller->id,
                'name' => ($name !== null && $name !== '') ? $name : 'Store commission',
                'type' => SellerCommission::TYPE_PERCENT,
                'rate' => $rate,
                'is_default' => $makeDefault,
                'is_enabled' => true,
                'notes' => $notes,
            ]);
        }

        $fresh = $seller->fresh();
        if (round($beforeRate, 2) !== round($rate, 2)) {
            app(MarketplaceLifecycleBroker::class)->dispatch(
                MarketplaceEvent::sellerCommissionChanged($fresh, $adminId, $beforeRate, $rate, $notes),
            );
        }

        return $fresh;
    }

    /**
     * Dual-write seller listing into core Product + SellerProduct copy.
     *
     * The catalog payload (all product types + fields) is shaped by
     * SellerProductCatalogService so the seller portal and admin stay in parity,
     * while ownership and moderation stay here.
     *
     * Moderation (when auto-approve is off):
     * - create → pending
     * - edit approved / rejected / pending → pending (safest re-review)
     * - rejected edit is a resubmission and clears the prior rejection note
     *
     * @param  array<string, mixed>  $data  validated payload (legacy simple or full catalog shape)
     */
    public function saveProduct(Seller $seller, array $data, ?Product $product = null): Product
    {
        $forceApproved = (bool) ($data['force_approved'] ?? false);
        // Ownership and identity are never taken from the payload.
        unset($data['seller_id'], $data['product_id'], $data['approval_status'], $data['force_approved']);

        if ($product && (int) $product->seller_id !== (int) $seller->id) {
            abort(403, 'This product does not belong to this seller.');
        }
        if (! $product) {
            $type = isset($data['type']) ? (string) $data['type'] : null;
            app(SellerSubscriptionGate::class)->assertCanCreateProduct($seller, $type);
        }

        $locale = Language::defaultCode();

        // Admin create/update may force approve; sellers follow auto-approve / pending rules.
        $approval = SellerProduct::STATUS_PENDING;
        if ($forceApproved || MarketplaceSettings::autoApproveProducts()) {
            $approval = SellerProduct::STATUS_APPROVED;
        }

        $enabled = (bool) ($data['is_enabled'] ?? true);
        if ($approval !== SellerProduct::STATUS_APPROVED) {
            $enabled = false;
        }

        $payload = $this->catalog->buildSavePayload($seller, $data, $product, $approval, $enabled);
        $sku = (string) $payload['product']['sku'];

        $skuTaken = Product::query()
            ->where('sku', $sku)
            ->when($product, fn ($query) => $query->whereKeyNot($product->id))
            ->exists();
        if ($skuTaken) {
            throw ValidationException::withMessages([
                'sku' => 'This SKU is already in use.',
            ]);
        }

        return DB::transaction(function () use ($seller, $payload, $product, $locale, $approval) {
            $saved = $this->products->save($payload, $product);

            $translation = $saved->translation($locale);
            $copyPayload = [
                'sku' => $saved->sku,
                'name' => $translation?->name ?? ($payload['translation']['name'] ?? ''),
                'slug' => $translation?->slug,
                'description' => $translation?->description,
                'short_description' => $translation?->short_description,
                'price' => $saved->price,
                'compare_price' => $saved->compare_price,
                'stock_qty' => $saved->stock_qty,
                'category_id' => $saved->default_category_id,
                'is_enabled' => $saved->is_enabled,
                'approval_status' => $approval,
            ];
            if ($approval === SellerProduct::STATUS_PENDING) {
                // Resubmission / re-review: clear prior rejection metadata.
                $copyPayload['approval_note'] = null;
                $copyPayload['reviewed_by'] = null;
                $copyPayload['reviewed_at'] = null;
            }

            SellerProduct::query()->updateOrCreate(
                ['seller_id' => $seller->id, 'product_id' => $saved->id],
                $copyPayload
            );

            // Dual-write invariant: catalog Product.seller_id is authoritative and must match the listing row.
            if ((int) $saved->seller_id !== (int) $seller->id) {
                throw ValidationException::withMessages([
                    'seller_id' => 'Product ownership could not be assigned to this seller.',
                ]);
            }
            $listing = SellerProduct::query()
                ->where('seller_id', $seller->id)
                ->where('product_id', $saved->id)
                ->first();
            if (! $listing || (int) $listing->seller_id !== (int) $saved->seller_id) {
                throw ValidationException::withMessages([
                    'seller_id' => 'Seller listing ownership drifted from the catalog product.',
                ]);
            }

            $this->syncShopAvailability($seller->fresh());

            $saved = $saved->fresh(['translations', 'categories']);
            if ($approval === SellerProduct::STATUS_PENDING) {
                $listing = SellerProduct::query()
                    ->where('seller_id', $seller->id)
                    ->where('product_id', $saved->id)
                    ->first();
                if ($listing) {
                    app(MarketplaceLifecycleBroker::class)->dispatch(
                        MarketplaceEvent::sellerProductSubmitted($listing, $seller->fresh()),
                    );
                }
            }

            return $saved;
        });
    }

    /**
     * Import seller products from the shared Product CSV schema.
     * Creates or updates only products owned by this seller (never another seller's SKU).
     */
    public function importProductsCsv(
        Seller $seller,
        string $contents,
        string $locale = 'en',
        string $delimiter = ProductCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = ProductCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): int {
        $delimiter = $delimiter === "\\t" || $delimiter === "\t" ? "\t" : $delimiter;
        $parsedFile = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders(
            $parsedFile['header'],
            ProductCsvSchema::columns(),
            'Product',
            ProductCsvSchema::requiredColumns(),
        );
        CatalogCsvImport::assertHasDataRows($parsedFile['rows'], 'Product');

        $errors = [];
        $parsed = [];
        foreach ($parsedFile['rows'] as $index => $row) {
            $rowNumber = CatalogCsvImport::rowNumber($index);
            $sku = trim((string) ($row['sku'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($sku === '') {
                $errors[] = "Row {$rowNumber}: Product SKU is required.";
                continue;
            }
            if ($name === '') {
                $errors[] = "Row {$rowNumber}: Product name is required.";
                continue;
            }

            $skuUpper = Str::upper($sku);
            $foreign = Product::query()
                ->where('seller_id', '!=', $seller->id)
                ->where('sku', $skuUpper)
                ->exists();
            if ($foreign) {
                $errors[] = "Row {$rowNumber}: SKU \"{$sku}\" belongs to another seller.";
                continue;
            }

            $parsed[] = [
                'row' => $row,
                'mapped' => ProductCsvSchema::mapRow($row, $locale, $multiSeparator),
            ];
        }

        CatalogCsvImport::throwRowErrors($errors);
        if ($parsed === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. No valid Product rows to import.',
            ]);
        }

        return DB::transaction(function () use ($seller, $parsed) {
            $count = 0;
            foreach ($parsed as $item) {
                $mapped = $item['mapped'];
                $productPayload = $mapped['product'] ?? [];
                $translation = $mapped['translation'] ?? [];
                $rawSku = (string) ($productPayload['sku'] ?? '');

                $data = array_merge($productPayload, [
                    'name' => $translation['name'] ?? '',
                    'slug' => $translation['slug'] ?? null,
                    'short_description' => $translation['short_description'] ?? null,
                    'description' => $translation['description'] ?? null,
                    'meta_title' => $translation['meta_title'] ?? null,
                    'meta_description' => $translation['meta_description'] ?? null,
                    'meta_keywords' => $translation['meta_keywords'] ?? null,
                    'category_ids' => $mapped['category_ids'] ?? [],
                    'feature_value_ids' => $mapped['feature_value_ids'] ?? [],
                    'available_all_carriers' => (bool) ($mapped['available_all_carriers'] ?? true),
                    'shipping_method_ids' => $mapped['shipping_method_ids'] ?? [],
                    'related_ids' => $mapped['relations']['related'] ?? [],
                    'upsell_ids' => $mapped['relations']['upsell'] ?? [],
                    'crosssell_ids' => $mapped['relations']['crosssell'] ?? [],
                ]);

                foreach (SellerProductCatalogService::FORBIDDEN_KEYS as $key) {
                    unset($data[$key]);
                }

                $skuUpper = Str::upper($rawSku);
                $prefixed = Str::upper($seller->shop_slug.'-'.$rawSku);
                $existing = Product::query()
                    ->where('seller_id', $seller->id)
                    ->where(function ($query) use ($skuUpper, $prefixed) {
                        $query->where('sku', $skuUpper)->orWhere('sku', $prefixed);
                    })
                    ->first();

                $this->saveProduct($seller, $data, $existing);
                $count++;
            }

            return $count;
        });
    }

    public function reviewProduct(SellerProduct $row, string $action, ?string $note = null, ?int $adminId = null): SellerProduct
    {
        $status = match ($action) {
            'approve' => SellerProduct::STATUS_APPROVED,
            'reject' => SellerProduct::STATUS_REJECTED,
            default => abort(422, 'Unknown product review action.'),
        };

        if ($status === SellerProduct::STATUS_REJECTED && ($note === null || trim($note) === '')) {
            throw ValidationException::withMessages([
                'note' => 'A rejection reason is required.',
            ]);
        }

        return DB::transaction(function () use ($row, $status, $note, $adminId) {
            $locked = SellerProduct::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->approval_status !== SellerProduct::STATUS_PENDING) {
                abort(422, 'Only pending seller products can be reviewed.');
            }
            if ($status === SellerProduct::STATUS_APPROVED) {
                $seller = Seller::query()->find($locked->seller_id);
                if ($seller) {
                    $plan = app(SellerSubscriptionGate::class)->activePlan($seller);
                    $limit = $plan?->product_limit;
                    if ($limit !== null) {
                        $approved = SellerProduct::query()
                            ->where('seller_id', $seller->id)
                            ->where('approval_status', SellerProduct::STATUS_APPROVED)
                            ->count();
                        if ($approved >= (int) $limit) {
                            throw ValidationException::withMessages([
                                'product_limit' => 'You have reached max limit of '.(int) $limit.' products.',
                            ]);
                        }
                    }
                }
            }

            $locked->update([
                'approval_status' => $status,
                'approval_note' => $status === SellerProduct::STATUS_REJECTED ? trim((string) $note) : null,
                'is_enabled' => $status === SellerProduct::STATUS_APPROVED,
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
            ]);
            $locked->loadMissing('seller');
            $this->syncShopAvailability($locked->seller);
            $fresh = $locked->fresh(['seller', 'product', 'reviewedBy']);

            app(MarketplaceLifecycleBroker::class)->dispatch(
                $status === SellerProduct::STATUS_APPROVED
                    ? MarketplaceEvent::sellerProductApproved($fresh, $adminId)
                    : MarketplaceEvent::sellerProductRejected($fresh, $adminId, $note),
            );

            return $fresh;
        });
    }

    /**
     * Delete when safe; otherwise archive (disable) to preserve order history.
     *
     * @return array{action: string, product_id: int}
     */
    public function deleteProduct(Seller $seller, Product $product): array
    {
        if ((int) $product->seller_id !== (int) $seller->id) {
            abort(403, 'This product does not belong to this seller.');
        }

        return DB::transaction(function () use ($seller, $product) {
            $hasHistory = OrderItem::query()->where('product_id', $product->id)->exists()
                || SellerOrderItem::query()->where('product_id', $product->id)->exists();

            if ($hasHistory) {
                SellerProduct::query()
                    ->where('seller_id', $seller->id)
                    ->where('product_id', $product->id)
                    ->update(['is_enabled' => false]);
                $product->update([
                    'is_enabled' => false,
                    'available_for_order' => false,
                ]);

                return ['action' => 'archived', 'product_id' => $product->id];
            }

            SellerProduct::query()->where('seller_id', $seller->id)->where('product_id', $product->id)->delete();
            $this->products->delete($product);

            return ['action' => 'deleted', 'product_id' => $product->id];
        });
    }

    /** @param  array<int>  $productIds */
    public function bulkSetEnabled(Seller $seller, array $productIds, bool $enabled): int
    {
        $owned = Product::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $productIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($owned === []) {
            return 0;
        }

        if ($enabled) {
            $approvedIds = SellerProduct::query()
                ->where('seller_id', $seller->id)
                ->whereIn('product_id', $owned)
                ->where('approval_status', SellerProduct::STATUS_APPROVED)
                ->pluck('product_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($approvedIds === []) {
                return 0;
            }
            Product::query()->whereIn('id', $approvedIds)->update(['is_enabled' => true]);
            SellerProduct::query()
                ->where('seller_id', $seller->id)
                ->whereIn('product_id', $approvedIds)
                ->update(['is_enabled' => true]);

            return count($approvedIds);
        }

        Product::query()->whereIn('id', $owned)->update(['is_enabled' => false]);
        SellerProduct::query()
            ->where('seller_id', $seller->id)
            ->whereIn('product_id', $owned)
            ->update(['is_enabled' => false]);

        return count($owned);
    }

    /**
     * @param  array<int>  $productIds
     * @return list<array{action: string, product_id: int}>
     */
    public function bulkDelete(Seller $seller, array $productIds): array
    {
        $results = [];
        $products = Product::query()
            ->where('seller_id', $seller->id)
            ->whereIn('id', $productIds)
            ->get();

        foreach ($products as $product) {
            $results[] = $this->deleteProduct($seller, $product);
        }

        return $results;
    }

    /**
     * Split a parent Order into SellerOrders / SellerOrderItems.
     * Ownership comes from purchased product.seller_id — never from client input.
     * Platform lines (null seller_id) intentionally produce no SellerOrder.
     *
     * @param  array<int, float>  $discountByOrderItemId  order_item_id => discount share
     */
    public function captureOrder(Order $order, array $discountByOrderItemId = []): void
    {
        $order->loadMissing(['items.product', 'shippingMethod']);
        $groups = [];
        foreach ($order->items as $item) {
            $sellerId = (int) ($item->product?->seller_id ?? 0);
            if ($sellerId < 1) {
                continue;
            }
            $groups[$sellerId][] = $item;
        }

        if ($groups === []) {
            return;
        }

        $shippingAllocation = app(SellerShippingAllocationService::class)->allocate($order);

        $broker = app(MarketplaceLifecycleBroker::class);
        foreach ($groups as $sellerId => $items) {
            $seller = Seller::query()->find($sellerId);
            if (! $seller) {
                throw new \RuntimeException(
                    'Seller '.$sellerId.' is missing while capturing order '.$order->number.'.'
                );
            }

            $alreadyExisted = SellerOrder::query()
                ->where('seller_id', $seller->id)
                ->where('order_id', $order->id)
                ->exists();

            if (! $alreadyExisted) {
                app(SellerSubscriptionGate::class)->assertCanAcceptOrder($seller);
            }

            $sellerShipping = (float) ($shippingAllocation['sellers'][(int) $sellerId] ?? 0);
            $sellerOrder = $this->writeSellerOrder(
                $seller,
                $order,
                $items,
                $discountByOrderItemId,
                $sellerShipping,
                $shippingAllocation['shipping_method_id'] ?? null,
            );
            if (! $alreadyExisted) {
                $broker->dispatch(MarketplaceEvent::sellerOrderCreated($seller, $order, $sellerOrder));
            }
        }
    }

    /**
     * When core payment becomes paid, update seller-order payment state and apply release rules.
     */
    public function markOrderPaid(Order $order): void
    {
        SellerOrder::query()
            ->where('order_id', $order->id)
            ->update(['payment_status' => 'paid']);

        app(SellerEarningAvailabilityService::class)->handleOrderPaid($order);
    }

    /**
     * When core order is refunded, hold earnings and post compensating ledger debit.
     * Already-paid (withdrawn) rows stay paid for audit — admin must claw back manually.
     */
    public function markOrderRefunded(Order $order): void
    {
        SellerOrder::query()
            ->where('order_id', $order->id)
            ->update([
                'payment_status' => 'refunded',
                'status' => 'refunded',
            ]);

        SellerEarning::query()
            ->where('order_id', $order->id)
            ->whereIn('status', [SellerEarning::STATUS_PENDING, SellerEarning::STATUS_AVAILABLE])
            ->update(['status' => SellerEarning::STATUS_REFUNDED]);

        $ledger = app(SellerLedgerService::class);
        $inventory = app(SellerInventoryService::class);
        SellerOrder::query()
            ->where('order_id', $order->id)
            ->get()
            ->each(function (SellerOrder $sellerOrder) use ($ledger, $inventory) {
                $ledger->debitRefundAdjustment($sellerOrder);
                $inventory->restoreSellerOrderStock($sellerOrder, \App\Domain\Catalog\Models\InventoryMovement::ACTION_RETURN);
            });
    }

    /**
     * @param  iterable<int, mixed>  $items
     * @param  array<int, float>  $discountByOrderItemId
     */
    protected function writeSellerOrder(
        Seller $seller,
        Order $order,
        iterable $items,
        array $discountByOrderItemId = [],
        float $shippingTotal = 0.0,
        ?int $shippingMethodId = null,
    ): SellerOrder {
        $items = collect($items);
        $commission = app(CommissionService::class);

        $existing = SellerOrder::query()
            ->where('seller_id', $seller->id)
            ->where('order_id', $order->id)
            ->first();

        // Snapshot financials at sale time — never re-read live rules later.
        if ($existing && SellerEarning::query()->where('seller_order_id', $existing->id)->exists()) {
            $existing->update([
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'customer_email' => $order->email,
            ]);

            return $existing->fresh(['items', 'earnings']);
        }

        $subtotal = round((float) $items->sum(fn ($item) => (float) $item->total), 2);
        $tax = round((float) $items->sum(fn ($item) => (float) $item->tax_amount), 2);
        $discount = round((float) $items->sum(
            fn ($item) => (float) ($discountByOrderItemId[(int) $item->id] ?? 0)
        ), 2);
        // Phase 14 — parent shipping allocated onto this seller slice (commission still excludes shipping).
        $shipping = round(max(0, $shippingTotal), 2);
        $methodId = $shippingMethodId ?? ($order->shipping_method_id ? (int) $order->shipping_method_id : null);

        // Commissionable gross = item totals − allocated discount. Tax/shipping excluded.
        $gross = round(max(0, $subtotal - $discount), 2);
        $grand = round(max(0, $subtotal - $discount + $tax + $shipping), 2);

        $lineInputs = [];
        foreach ($items as $item) {
            $lineDiscount = (float) ($discountByOrderItemId[(int) $item->id] ?? 0);
            $lineGross = round(max(0, (float) $item->total - $lineDiscount), 2);
            $lineInputs[] = [
                'order_item_id' => (int) $item->id,
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'gross' => $lineGross,
                'product' => $item->product,
            ];
        }

        if ($existing
            && $existing->commission_rate !== null
            && ($existing->commission_type === null || $existing->commission_type !== 'mixed')
            && SellerEarning::query()->where('seller_order_id', $existing->id)->doesntExist()) {
            // Rare: seller order row without earnings — keep snapshotted rule.
            $snapType = ($existing->commission_type ?: CommissionService::TYPE_PERCENT) === CommissionService::TYPE_FIXED
                ? CommissionService::TYPE_FIXED
                : CommissionService::TYPE_PERCENT;
            $snapValue = (float) ($existing->commission_value ?? $existing->commission_rate);
            $calc = $commission->calculate($gross, [
                'type' => $snapType,
                'value' => $snapValue,
            ]);
            $priced = [
                'lines' => $commission->allocateLines(
                    $lineInputs,
                    (float) ($snapType === CommissionService::TYPE_PERCENT ? $snapValue : $existing->commission_rate),
                    $calc['commission_amount'],
                    $calc['seller_net']
                ),
                'commission_total' => $calc['commission_amount'],
                'earning_total' => $calc['seller_net'],
                'commission_rate' => (float) $existing->commission_rate,
                'commission_type' => $snapType,
                'commission_value' => $snapValue,
                'commission_source' => (string) ($existing->commission_source ?: CommissionService::SOURCE_SELLER),
                'commission_rule_id' => $existing->commission_rule_id ? (int) $existing->commission_rule_id : null,
            ];
            foreach ($priced['lines'] as &$row) {
                $row['commission_type'] = $priced['commission_type'];
                $row['commission_value'] = $priced['commission_value'];
                $row['commission_source'] = $priced['commission_source'];
                $row['commission_rule_id'] = $priced['commission_rule_id'];
            }
            unset($row);
        } else {
            $priced = $commission->calculateLines($seller, $lineInputs);
        }

        $commissionTotal = $priced['commission_total'];
        $earning = $priced['earning_total'];

        $sellerOrder = SellerOrder::query()->firstOrNew([
            'seller_id' => $seller->id,
            'order_id' => $order->id,
        ]);
        $sellerOrder->forceFill([
            'number' => $order->number,
            'customer_email' => $order->email,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'currency_code' => $order->currency_code,
            'subtotal' => $subtotal,
            'tax_total' => $tax,
            'shipping_total' => $shipping,
            'shipping_method_id' => $methodId,
            'discount_total' => $discount,
            'commission_rate' => $priced['commission_rate'],
            'commission_source' => $priced['commission_source'],
            'commission_type' => $priced['commission_type'],
            'commission_value' => $priced['commission_value'],
            'commission_rule_id' => $priced['commission_rule_id'],
            'commission_total' => $commissionTotal,
            'earning_total' => $earning,
            'grand_total' => $grand,
            'billing_address' => $order->billing_address,
            'shipping_address' => $order->shipping_address,
        ])->save();

        if ($sellerOrder->items()->exists()) {
            $sellerOrder->items()->delete();
        }

        foreach ($items as $item) {
            $sellerOrder->items()->create([
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku,
                'name' => $item->name,
                'qty' => $item->qty,
                'unit_price' => $item->unit_price,
                'tax_amount' => $item->tax_amount,
                'total' => $item->total,
            ]);
        }

        $hadEarnings = SellerEarning::query()->where('seller_order_id', $sellerOrder->id)->exists();
        if (! $hadEarnings) {
            foreach ($priced['lines'] as $row) {
                SellerEarning::query()->create([
                    'seller_id' => $seller->id,
                    'seller_order_id' => $sellerOrder->id,
                    'order_id' => $order->id,
                    'product_id' => $row['product_id'],
                    'order_item_id' => $row['order_item_id'],
                    'gross_amount' => $row['gross'],
                    'commission_amount' => $row['commission_amount'],
                    'net_amount' => $row['net_amount'],
                    'commission_rate' => $row['commission_rate'],
                    'commission_type' => $row['commission_type'] ?? $priced['commission_type'],
                    'commission_value' => $row['commission_value'] ?? $priced['commission_value'],
                    'commission_source' => $row['commission_source'] ?? $priced['commission_source'],
                    'commission_rule_id' => $row['commission_rule_id'] ?? $priced['commission_rule_id'],
                    'status' => SellerEarning::STATUS_PENDING,
                ]);
            }

            $this->bumpRevenue($seller, $gross, $commissionTotal, $earning, (int) $items->sum('qty'));
        }

        if ($order->payment_status === 'paid') {
            app(SellerEarningAvailabilityService::class)->handleOrderPaid($order);
        }

        return $sellerOrder->fresh(['items', 'earnings']);
    }

    public function updateSellerOrderStatus(Seller $seller, SellerOrder $sellerOrder, string $status): SellerOrder
    {
        if ((int) $sellerOrder->seller_id !== (int) $seller->id) {
            abort(403, 'This order does not belong to this seller.');
        }

        $allowed = ['pending', 'processing', 'shipped', 'completed', 'cancelled'];
        if (! in_array($status, $allowed, true)) {
            abort(422, 'Unknown order status.');
        }

        if ($sellerOrder->status === 'cancelled' && $status !== 'cancelled') {
            abort(422, 'A cancelled seller order cannot be reopened from here.');
        }

        // Shipped/completed must go through the shipment workflow so tracking stays consistent.
        if (in_array($status, ['shipped', 'completed'], true)) {
            abort(422, 'Use the shipment actions to mark this order shipped or delivered.');
        }

        if ($status === 'cancelled') {
            return $this->cancelSellerOrder($seller, $sellerOrder);
        }

        $sellerOrder->updateTrusted(['status' => $status]);

        return $sellerOrder->fresh(['items', 'shipments.items']);
    }

    /**
     * Cancel a seller slice with auditable financial reversal + stock restore.
     * Idempotent. Does not mutate other sellers' finances.
     */
    public function cancelSellerOrder(Seller $seller, SellerOrder $sellerOrder): SellerOrder
    {
        if ((int) $sellerOrder->seller_id !== (int) $seller->id) {
            abort(403, 'This order does not belong to this seller.');
        }

        return DB::transaction(function () use ($sellerOrder) {
            $locked = SellerOrder::query()->whereKey($sellerOrder->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === 'cancelled') {
                return $locked->fresh(['items', 'shipments.items', 'earnings']);
            }

            if (in_array($locked->status, ['shipped', 'completed', 'refunded'], true)) {
                abort(422, 'This seller order cannot be cancelled in its current state.');
            }

            // Hold pending/available earnings; already-paid (withdrawn) rows stay paid for audit.
            SellerEarning::query()
                ->where('seller_order_id', $locked->id)
                ->whereIn('status', [SellerEarning::STATUS_PENDING, SellerEarning::STATUS_AVAILABLE])
                ->update(['status' => SellerEarning::STATUS_REFUNDED]);

            // Ledger debit only when a credit was recognized (paid). Unpaid → no reversal entry.
            app(SellerLedgerService::class)->debitCancelAdjustment($locked);

            $this->restockSellerOrderItems($locked);

            $locked->update(['status' => 'cancelled']);

            $core = $locked->order;
            if ($core && in_array($core->payment_status, ['paid', 'partially_refunded'], true)) {
                $refundAmount = round((float) $locked->grand_total, 2);
                if ($refundAmount > 0) {
                    app(\App\Domain\Order\Services\PaymentRefundService::class)->refundAmount(
                        $core,
                        $refundAmount,
                        'Seller order '.$locked->number.' cancelled',
                    );
                }
            }
            if ($core) {
                $core->loadMissing('sellerOrders');
                $activeOthers = $core->sellerOrders
                    ->where('id', '!=', $locked->id)
                    ->whereNotIn('status', ['cancelled', 'refunded']);
                if ($activeOthers->isEmpty()) {
                    $core->update(['status' => 'cancelled']);
                }
            }

            return $locked->fresh(['items', 'shipments.items', 'earnings']);
        });
    }

    protected function restockSellerOrderItems(SellerOrder $sellerOrder): void
    {
        $inventory = app(\App\Domain\Catalog\Services\InventoryService::class);
        $sellerOrder->loadMissing('items');
        $reference = 'cancel:seller_order:'.$sellerOrder->id;

        // Idempotent restock: skip if this cancel reference was already recorded.
        $already = \App\Domain\Catalog\Models\InventoryMovement::query()
            ->where('reference', $reference)
            ->exists();
        if ($already) {
            return;
        }

        foreach ($sellerOrder->items as $item) {
            if (! $item->product_id) {
                continue;
            }
            $product = Product::query()->find($item->product_id);
            if (! $product) {
                continue;
            }
            $inventory->restock($product, (int) $item->qty, \App\Domain\Catalog\Models\InventoryMovement::ACTION_RESTOCK, $reference);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveShipping(Seller $seller, array $data, ?SellerShippingMethod $row = null): SellerShippingMethod
    {
        return DB::transaction(function () use ($seller, $data, $row) {
            $name = trim((string) $data['name']);
            $code = $row?->code ?: $this->uniqueShippingCode($seller, $name);

            $payload = [
                'name' => $name,
                'description' => $data['description'] ?? null,
                'type' => $data['type'] ?? 'flat',
                'price' => $data['price'] ?? 0,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            ];

            if ($row) {
                $row->fill($payload)->save();
            } else {
                $row = $seller->shippingMethods()->create($payload + ['code' => $code]);
            }

            $core = $row->shipping_method_id
                ? ShippingMethod::query()->find($row->shipping_method_id)
                : null;
            $corePayload = [
                'code' => $row->code,
                'name' => $row->name,
                'description' => $row->description,
                'type' => $row->type,
                'driver' => 'offline',
                'price' => $row->price,
                'is_enabled' => $row->is_enabled,
                'position' => 50,
                'seller_id' => $seller->id,
                'conditions' => ShippingMethod::emptyConditions(),
                'rate_config' => ['tiers' => []],
            ];
            if ($core) {
                $core->update($corePayload);
            } else {
                $core = ShippingMethod::query()->create($corePayload);
                $row->update(['shipping_method_id' => $core->id]);
            }

            return $row->fresh();
        });
    }

    public function deleteShipping(Seller $seller, SellerShippingMethod $row): void
    {
        $this->assertOwned($seller, $row->seller_id);
        if ($row->shipping_method_id) {
            ShippingMethod::query()->whereKey($row->shipping_method_id)->where('seller_id', $seller->id)->delete();
        }
        $row->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function savePayment(Seller $seller, array $data, ?SellerPaymentMethod $row = null): SellerPaymentMethod
    {
        return DB::transaction(function () use ($seller, $data, $row) {
            $payload = [
                'type' => $data['type'] ?? 'bank',
                'name' => $data['name'],
                'account_name' => $data['account_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'bank_name' => $data['bank_name'] ?? null,
                'routing_number' => $data['routing_number'] ?? null,
                'paypal_email' => $data['paypal_email'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_default' => (bool) ($data['is_default'] ?? false),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            ];

            if ($payload['is_default']) {
                $seller->paymentMethods()->update(['is_default' => false]);
            }

            if ($row) {
                $this->assertOwned($seller, $row->seller_id);
                $row->fill($payload)->save();
            } else {
                $row = $seller->paymentMethods()->create($payload);
            }

            $config = [
                'payout' => true,
                'type' => $row->type,
                'account_name' => $row->account_name,
                'account_number' => $row->account_number,
                'bank_name' => $row->bank_name,
                'routing_number' => $row->routing_number,
                'paypal_email' => $row->paypal_email,
            ];
            $corePayload = [
                'code' => $row->payment_method_id
                    ? (PaymentMethod::query()->find($row->payment_method_id)?->code ?: $this->uniquePaymentCode($seller, $row))
                    : $this->uniquePaymentCode($seller, $row),
                'name' => $row->name,
                'description' => 'Seller payout method',
                'driver' => 'offline',
                'is_enabled' => false,
                'position' => 90,
                'seller_id' => $seller->id,
                'config' => $config,
                'rules' => PaymentMethod::emptyRules(),
            ];
            $core = $row->payment_method_id ? PaymentMethod::query()->find($row->payment_method_id) : null;
            if ($core) {
                unset($corePayload['code']);
                $core->update($corePayload);
            } else {
                $core = PaymentMethod::query()->create($corePayload);
                $row->update(['payment_method_id' => $core->id]);
            }

            $seller->forceFill([
                'payout_bank_account' => $row->type === 'bank' ? $row->account_number : $seller->payout_bank_account,
                'payout_bank_ifsc' => $row->type === 'bank' ? $row->routing_number : $seller->payout_bank_ifsc,
                'payout_paypal_email' => $row->type === 'paypal' ? $row->paypal_email : $seller->payout_paypal_email,
                'payout_upi_id' => $row->type === 'upi' ? ($row->account_number ?: $row->paypal_email) : $seller->payout_upi_id,
            ])->save();

            return $row->fresh();
        });
    }

    public function deletePayment(Seller $seller, SellerPaymentMethod $row): void
    {
        $this->assertOwned($seller, $row->seller_id);
        if ($row->payment_method_id) {
            PaymentMethod::query()->whereKey($row->payment_method_id)->where('seller_id', $seller->id)->delete();
        }
        $row->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDiscount(Seller $seller, array $data, ?SellerDiscount $row = null): SellerDiscount
    {
        return DB::transaction(function () use ($seller, $data, $row) {
            $payload = [
                'name' => $data['name'],
                'code' => isset($data['code']) && $data['code'] !== '' ? strtoupper((string) $data['code']) : null,
                'apply_as' => $data['apply_as'] ?? 'catalog',
                'action' => $data['action'] ?? 'percent',
                'value' => $data['value'] ?? 0,
                'min_cart' => ($data['min_cart'] ?? '') === '' ? null : ($data['min_cart'] ?? null),
                'max_uses' => ($data['max_uses'] ?? '') === '' ? null : ($data['max_uses'] ?? null),
                'starts_at' => $data['starts_at'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
            ];

            if ($row) {
                $this->assertOwned($seller, $row->seller_id);
                $row->fill($payload)->save();
            } else {
                $row = $seller->discounts()->create($payload);
            }

            $rulePayload = [
                'name' => $row->name,
                'apply_as' => $row->apply_as,
                'code' => $row->code,
                'action' => $row->action,
                'value' => $row->value,
                'priority' => 80,
                'stop_further' => false,
                'min_cart' => $row->min_cart,
                'max_uses' => $row->max_uses,
                'starts_at' => $row->starts_at,
                'expires_at' => $row->expires_at,
                'is_enabled' => $row->is_enabled,
                'seller_id' => $seller->id,
            ];
            $rule = $row->discount_rule_id ? DiscountRule::query()->find($row->discount_rule_id) : null;
            if ($rule) {
                $rule->fill($rulePayload)->save();
            } else {
                $rule = DiscountRule::query()->create($rulePayload + ['used_count' => 0]);
                $row->update(['discount_rule_id' => $rule->id]);
            }

            $rule->conditions()->delete();
            $rule->conditions()->create([
                'type' => 'seller',
                'value_ids' => [(string) $seller->id],
            ]);

            return $row->fresh();
        });
    }

    public function deleteDiscount(Seller $seller, SellerDiscount $row): void
    {
        $this->assertOwned($seller, $row->seller_id);
        if ($row->discount_rule_id) {
            DiscountRule::query()->whereKey($row->discount_rule_id)->where('seller_id', $seller->id)->delete();
        }
        $row->delete();
    }

    public function present(Seller $seller): array
    {
        $seller->loadMissing('translations');
        $locale = Language::currentCode();
        $localized = $seller->translation($locale);
        $defaultLocale = Language::defaultCode();
        $shopName = $locale === $defaultLocale
            ? ($seller->shop_name ?: ($localized?->shop_name ?: ''))
            : ($localized?->shop_name ?: $seller->shop_name);

        return [
            'id' => $seller->id,
            'status' => $seller->status,
            'is_approved' => $seller->isApproved(),
            'is_suspended' => $seller->isSuspended(),
            'can_access_portal' => $seller->canAccessPortal(),
            'can_reapply' => $seller->canReapply(),
            'first_name' => $seller->first_name,
            'last_name' => $seller->last_name,
            'shop_name' => $shopName,
            'shop_slug' => $seller->shop_slug,
            'profile_name' => $seller->profile_name,
            'email' => $seller->email,
            'phone' => $seller->phone,
            'contact_name' => $seller->contact_name,
            'contact_phone' => $seller->contact_phone,
            'contact_email' => $seller->contact_email,
            'address_line1' => $seller->address_line1,
            'address_line2' => $seller->address_line2,
            'city' => $seller->city,
            'state' => $seller->state,
            'postal_code' => $seller->postal_code,
            'country_code' => $seller->country_code,
            'description' => HtmlSanitizer::clean($localized?->description ?? $seller->description),
            'tax_id' => $seller->tax_id,
            'logo_url' => $this->publicMediaUrl($seller->logo_path),
            'banner_url' => $this->publicMediaUrl($seller->banner_path),
            'facebook_url' => $seller->facebook_url,
            'twitter_url' => $seller->twitter_url,
            'instagram_url' => $seller->instagram_url,
            'youtube_url' => $seller->youtube_url,
            'store_policy' => HtmlSanitizer::clean($localized?->store_policy ?? $seller->store_policy),
            'shipping_policy' => HtmlSanitizer::clean($localized?->shipping_policy ?? $seller->shipping_policy),
            'return_policy' => HtmlSanitizer::clean($localized?->return_policy ?? $seller->return_policy),
            'is_active' => (bool) ($seller->is_active ?? true),
            'vacation_enabled' => (bool) $seller->vacation_enabled,
            'vacation_message' => HtmlSanitizer::clean($localized?->vacation_message ?? $seller->vacation_message),
            'commission_rate' => (float) $seller->commission_rate,
            'review_note' => $seller->review_note,
            'reviewed_at' => $seller->reviewed_at?->toIso8601String(),
            'created_at' => $seller->created_at?->toIso8601String(),
            'translations' => $seller->translations
                ->map(fn (SellerTranslation $row) => [
                    'language_code' => $row->language_code,
                    'shop_name' => $row->shop_name,
                    'description' => HtmlSanitizer::clean($row->description),
                    'store_policy' => HtmlSanitizer::clean($row->store_policy),
                    'shipping_policy' => HtmlSanitizer::clean($row->shipping_policy),
                    'return_policy' => HtmlSanitizer::clean($row->return_policy),
                    'vacation_message' => HtmlSanitizer::clean($row->vacation_message),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Persist localized shop_name / description (catalog-style translation rows)
     * and keep sellers.shop_name / description as the default-locale denormalized copy.
     *
     * @param  array<string, mixed>  $data
     */
    protected function syncShopTranslations(Seller $seller, array $data): void
    {
        $default = Language::defaultCode();
        $existing = $seller->translations()->get()->keyBy('language_code');
        $rows = [];
        $translatable = ['description', 'store_policy', 'shipping_policy', 'return_policy', 'vacation_message'];

        foreach (($data['translations'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $code = strtolower(trim((string) ($row['language_code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $current = $existing->get($code);
            $name = trim((string) ($row['shop_name'] ?? $current?->shop_name ?? $seller->shop_name ?? ''));
            if ($name === '') {
                continue;
            }
            $payload = ['shop_name' => $name];
            foreach ($translatable as $field) {
                if (array_key_exists($field, $row)) {
                    $value = $row[$field];
                    $payload[$field] = $value !== '' && $value !== null ? HtmlSanitizer::clean((string) $value) : null;
                } elseif ($current) {
                    $payload[$field] = $current->{$field};
                } else {
                    $payload[$field] = null;
                }
            }
            $rows[$code] = $payload;
        }

        if ($rows === [] && $existing->isNotEmpty()) {
            $current = $existing->get($default) ?? $existing->first();
            if ($current) {
                $payload = ['shop_name' => (string) ($data['shop_name'] ?? $seller->shop_name)];
                foreach ($translatable as $field) {
                    if (array_key_exists($field, $data)) {
                        $value = $data[$field];
                        $payload[$field] = $value !== '' && $value !== null ? HtmlSanitizer::clean((string) $value) : null;
                    }
                }
                $current->update($payload);
            }

            return;
        }

        if ($rows === []) {
            $rows[$default] = [
                'shop_name' => (string) $seller->shop_name,
                'description' => $seller->description,
                'store_policy' => $seller->store_policy,
                'shipping_policy' => $seller->shipping_policy,
                'return_policy' => $seller->return_policy,
                'vacation_message' => $seller->vacation_message,
            ];
        } elseif (! isset($rows[$default]) && filled($data['shop_name'] ?? null)) {
            $rows[$default] = [
                'shop_name' => (string) $data['shop_name'],
                'description' => isset($data['description']) ? HtmlSanitizer::clean((string) $data['description']) : null,
                'store_policy' => isset($data['store_policy']) ? HtmlSanitizer::clean((string) $data['store_policy']) : null,
                'shipping_policy' => isset($data['shipping_policy']) ? HtmlSanitizer::clean((string) $data['shipping_policy']) : null,
                'return_policy' => isset($data['return_policy']) ? HtmlSanitizer::clean((string) $data['return_policy']) : null,
                'vacation_message' => isset($data['vacation_message']) ? HtmlSanitizer::clean((string) $data['vacation_message']) : null,
            ];
        }

        foreach ($rows as $code => $payload) {
            SellerTranslation::query()->updateOrCreate(
                ['seller_id' => $seller->id, 'language_code' => $code],
                $payload
            );
        }

        $fallback = $rows[$default] ?? reset($rows);
        if (is_array($fallback)) {
            $seller->forceFill([
                'shop_name' => $fallback['shop_name'],
                'description' => $fallback['description'] ?? null,
                'store_policy' => $fallback['store_policy'] ?? null,
                'shipping_policy' => $fallback['shipping_policy'] ?? null,
                'return_policy' => $fallback['return_policy'] ?? null,
                'vacation_message' => $fallback['vacation_message'] ?? null,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function profilePayload(array $data): array
    {
        return [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'shop_name' => $data['shop_name'],
            'shop_slug' => Str::slug((string) $data['shop_slug']),
            'profile_name' => $data['profile_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'contact_name' => $data['contact_name'],
            'contact_phone' => $data['contact_phone'],
            'contact_email' => $data['contact_email'] ?? $data['email'],
            'address_line1' => $data['address_line1'],
            'address_line2' => $data['address_line2'] ?? null,
            'city' => $data['city'],
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'],
            'country_code' => strtoupper((string) $data['country_code']),
            'description' => $data['description'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
        ];
    }

    protected function ensureDefaultCommission(Seller $seller): void
    {
        if ($seller->commissions()->exists()) {
            return;
        }
        SellerCommission::query()->create([
            'seller_id' => $seller->id,
            'name' => 'Default',
            'rate' => $seller->commission_rate ?: self::DEFAULT_COMMISSION,
            'is_default' => true,
            'is_enabled' => true,
            'notes' => 'Assigned when the seller request was accepted.',
        ]);
    }

    protected function disableSellerCatalog(Seller $seller): void
    {
        Product::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        SellerProduct::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        ShippingMethod::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        SellerShippingMethod::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        DiscountRule::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        SellerDiscount::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        SellerCategory::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        SellerFeature::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        SellerBrand::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        SellerSupplier::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        Category::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        Feature::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        Brand::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        Supplier::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
    }

    protected function bumpRevenue(Seller $seller, float $gross, float $commission, float $net, int $items): void
    {
        $day = now()->toDateString();
        $row = SellerRevenue::query()->firstOrCreate(
            [
                'seller_id' => $seller->id,
                'period_date' => $day,
                'period_type' => 'day',
            ],
            [
                'order_count' => 0,
                'item_count' => 0,
                'gross_total' => 0,
                'commission_total' => 0,
                'net_total' => 0,
            ]
        );
        $row->update([
            'order_count' => $row->order_count + 1,
            'item_count' => $row->item_count + $items,
            'gross_total' => round((float) $row->gross_total + $gross, 2),
            'commission_total' => round((float) $row->commission_total + $commission, 2),
            'net_total' => round((float) $row->net_total + $net, 2),
        ]);
    }

    public function setShopVisible(Seller $seller, bool $visible): void
    {
        if ($seller->status === Seller::STATUS_BLOCKED) {
            return;
        }

        $seller->update(['is_active' => $visible]);
        $this->syncShopAvailability($seller->fresh());
    }

    public function setListingEnabled(SellerProduct $row, bool $enabled): void
    {
        $row->loadMissing('seller');
        $row->update(['is_enabled' => $enabled]);
        if (! $row->product_id) {
            return;
        }

        $seller = $row->seller;
        $listed = $enabled
            && $row->approval_status === SellerProduct::STATUS_APPROVED
            && $seller
            && $seller->isPublic();

        Product::query()->whereKey($row->product_id)->update([
            'is_enabled' => $listed,
            'available_for_order' => $listed && ! ($seller?->vacation_enabled ?? false),
        ]);
    }

    protected function syncShopAvailability(?Seller $seller): void
    {
        if (! $seller) {
            return;
        }

        $shopOpen = $seller->isPublic();
        $copies = SellerProduct::query()->where('seller_id', $seller->id)->get();
        foreach ($copies as $copy) {
            if (! $copy->product_id) {
                continue;
            }
            $listed = $shopOpen && $copy->approval_status === SellerProduct::STATUS_APPROVED && $copy->is_enabled;
            Product::query()->whereKey($copy->product_id)->update([
                'is_enabled' => $listed,
                'available_for_order' => $listed && ! $seller->vacation_enabled,
            ]);
        }
    }

    protected function uniqueShippingCode(Seller $seller, string $name): string
    {
        $base = 'seller-'.$seller->id.'-'.Str::slug($name);
        $code = $base !== 'seller-'.$seller->id.'-' ? $base : 'seller-'.$seller->id.'-ship';
        $i = 1;
        while (ShippingMethod::query()->where('code', $code)->exists()) {
            $code = $base.'-'.$i;
            $i++;
        }

        return $code;
    }

    protected function uniquePaymentCode(Seller $seller, SellerPaymentMethod $row): string
    {
        return 'seller-'.$seller->id.'-payout-'.$row->id;
    }

    protected function assertOwned(Seller $seller, int $sellerId): void
    {
        if ((int) $sellerId !== (int) $seller->id) {
            abort(403, 'This record does not belong to this seller.');
        }
    }
}
