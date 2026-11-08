<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductRentalAsset;
use App\Domain\Catalog\Models\ProductRentalPricingTier;
use App\Domain\Catalog\Models\ProductRentalSetting;
use App\Domain\Order\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalProductService
{
    public function syncProduct(Product $product, array $config, array $tiers = [], array $assets = []): ProductRentalSetting
    {
        if ($product->type !== 'rental') {
            abort(422, 'Rental configuration applies only to rental products.');
        }

        $this->validateConfig($config, $tiers, $assets);

        return DB::transaction(function () use ($product, $config, $tiers, $assets) {
            $row = ProductRentalSetting::query()->updateOrCreate(
                ['product_id' => $product->id],
                $this->configAttributes($config),
            );

            $this->syncTiers($product, $tiers);
            $this->syncAssets($product, $assets, $config['inventory_mode'] ?? 'quantity');

            return $row->fresh(['pricingTiers', 'assets']);
        });
    }

    public function presentConfig(Product $product): ?array
    {
        $config = $product->relationLoaded('rentalSetting')
            ? $product->rentalSetting
            : ProductRentalSetting::query()->where('product_id', $product->id)->first();
        if (! $config) {
            return null;
        }

        $tiers = ($product->relationLoaded('rentalPricingTiers')
            ? $product->rentalPricingTiers
            : ProductRentalPricingTier::query()->where('product_id', $product->id)->orderBy('position')->get()
        )->map(fn (ProductRentalPricingTier $row) => [
            'id' => $row->id,
            'min_duration' => (int) $row->min_duration,
            'max_duration' => (int) $row->max_duration,
            'unit' => $row->unit,
            'price' => (float) $row->price,
            'position' => (int) $row->position,
        ])->values()->all();

        $assets = ($product->relationLoaded('rentalAssets')
            ? $product->rentalAssets
            : ProductRentalAsset::query()->where('product_id', $product->id)->orderBy('position')->get()
        )->map(fn (ProductRentalAsset $row) => [
            'id' => $row->id,
            'asset_id' => $row->asset_id,
            'serial_number' => $row->serial_number,
            'barcode' => $row->barcode,
            'status' => $row->status,
            'location' => $row->location,
            'condition' => $row->condition,
            'position' => (int) $row->position,
        ])->values()->all();

        $base = (float) $product->sellingPrice();
        $defaultDuration = max(1, (int) $config->default_duration);
        $previewUnit = $this->unitPriceForDuration($product, $defaultDuration, $base);
        $previewDeposit = $this->depositAmount($product, $previewUnit);
        $previewCleaning = $this->cleaningFeeAmount($product);

        return array_merge($this->presentRow($config), [
            'pricing_tiers' => $tiers,
            'assets' => $assets,
            'preview' => [
                'duration' => $defaultDuration,
                'unit_price' => $previewUnit,
                'deposit' => $previewDeposit,
                'cleaning_fee' => $previewCleaning,
                'total_due' => round($previewUnit + $previewDeposit + $previewCleaning, 2),
            ],
            'inventory_summary' => $this->inventorySummary($product),
        ]);
    }

    public function presentRow(ProductRentalSetting $config): array
    {
        return [
            'rental_unit' => $config->rental_unit,
            'min_duration' => (int) $config->min_duration,
            'max_duration' => (int) $config->max_duration,
            'default_duration' => (int) $config->default_duration,
            'pricing_model' => $config->pricing_model,
            'availability_config' => $config->availability_config ?? [],
            'security_deposit_type' => $config->security_deposit_type,
            'security_deposit_value' => $config->security_deposit_value !== null ? (float) $config->security_deposit_value : null,
            'inventory_mode' => $config->inventory_mode,
            'maintenance_units' => (int) $config->maintenance_units,
            'out_of_service_units' => (int) $config->out_of_service_units,
            'fulfillment_method' => $config->fulfillment_method,
            'return_method' => $config->return_method,
            'return_location' => $config->return_location,
            'grace_period' => $config->grace_period,
            'grace_period_unit' => $config->grace_period_unit,
            'late_fee_type' => $config->late_fee_type,
            'late_fee_value' => $config->late_fee_value !== null ? (float) $config->late_fee_value : null,
            'damage_fee_type' => $config->damage_fee_type,
            'damage_fee_value' => $config->damage_fee_value !== null ? (float) $config->damage_fee_value : null,
            'lost_item_fee_type' => $config->lost_item_fee_type,
            'lost_item_fee_value' => $config->lost_item_fee_value !== null ? (float) $config->lost_item_fee_value : null,
            'cleaning_fee' => $config->cleaning_fee !== null ? (float) $config->cleaning_fee : null,
            'policies' => $config->policies,
            'requires_shipping' => true,
        ];
    }

    /**
     * Resolve start/end/duration from FO inputs and validate against product rules + availability.
     *
     * @return array{start: string, end: string, duration: int}
     */
    public function resolvePeriod(
        Product $product,
        ?string $startDate,
        ?string $endDate = null,
        ?int $duration = null,
        float $qty = 1,
        ?int $excludeOrderId = null,
    ): array {
        if (! $product->isRental()) {
            throw ValidationException::withMessages(['booking_date' => 'Rental dates apply only to rental products.']);
        }

        $product->loadMissing(['rentalSetting', 'rentalPricingTiers', 'rentalAssets']);
        $config = $product->rentalSetting;
        if (! $config) {
            throw ValidationException::withMessages(['booking_date' => 'This rental product is not configured.']);
        }

        $startRaw = trim((string) $startDate);
        if ($startRaw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $startRaw)) {
            throw ValidationException::withMessages(['booking_date' => 'Choose a start date for this rental.']);
        }

        try {
            $start = Carbon::createFromFormat('Y-m-d', $startRaw)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['booking_date' => 'Use a valid start date (YYYY-MM-DD).']);
        }

        $min = max(1, (int) $config->min_duration);
        $max = max($min, (int) $config->max_duration);
        $unit = (string) ($config->rental_unit ?: 'day');

        $endRaw = trim((string) $endDate);
        $durationVal = $duration !== null ? (int) $duration : 0;

        if ($endRaw !== '') {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $endRaw)) {
                throw ValidationException::withMessages(['booking_end_date' => 'Use a valid end date (YYYY-MM-DD).']);
            }
            try {
                $end = Carbon::createFromFormat('Y-m-d', $endRaw)->startOfDay();
            } catch (\Throwable) {
                throw ValidationException::withMessages(['booking_end_date' => 'Use a valid end date (YYYY-MM-DD).']);
            }
            if ($end->lt($start)) {
                throw ValidationException::withMessages(['booking_end_date' => 'End date must be on or after the start date.']);
            }
            $durationVal = $this->durationBetween($start, $end, $unit);
        } elseif ($durationVal > 0) {
            $end = $this->endFromDuration($start, $durationVal, $unit);
        } else {
            $durationVal = max(1, (int) $config->default_duration);
            $end = $this->endFromDuration($start, $durationVal, $unit);
        }

        if ($durationVal < $min) {
            throw ValidationException::withMessages([
                'rental_duration' => "Minimum rental duration is {$min} {$unit}(s).",
            ]);
        }
        if ($durationVal > $max) {
            throw ValidationException::withMessages([
                'rental_duration' => "Maximum rental duration is {$max} {$unit}(s).",
            ]);
        }

        $this->assertAvailabilityWindow($config, $start, $end);
        $this->assertCapacity($product, $start->toDateString(), $end->toDateString(), $qty, $excludeOrderId);

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'duration' => $durationVal,
        ];
    }

    public function unitPriceForDuration(Product $product, int $duration, ?float $catalogUnit = null): float
    {
        $product->loadMissing(['rentalSetting', 'rentalPricingTiers']);
        $base = $catalogUnit !== null ? max(0, $catalogUnit) : (float) $product->sellingPrice();
        $config = $product->rentalSetting;
        if (! $config) {
            return round($base, 2);
        }

        $duration = max(1, $duration);
        $tiers = $product->relationLoaded('rentalPricingTiers')
            ? $product->rentalPricingTiers
            : ProductRentalPricingTier::query()->where('product_id', $product->id)->orderBy('position')->get();

        foreach ($tiers as $tier) {
            if ($duration >= (int) $tier->min_duration && $duration <= (int) $tier->max_duration) {
                // Tier price is the period price for that duration band (not per-unit × duration).
                return round(max(0, (float) $tier->price), 2);
            }
        }

        $model = (string) ($config->pricing_model ?: 'per_day');
        $multiplier = match ($model) {
            'per_hour' => $this->durationAsHours($duration, (string) $config->rental_unit),
            'per_week' => $this->durationAsWeeks($duration, (string) $config->rental_unit),
            'per_month' => $this->durationAsMonths($duration, (string) $config->rental_unit),
            default => $this->durationAsDays($duration, (string) $config->rental_unit),
        };

        return round(max(0, $base * max(0.0001, $multiplier)), 2);
    }

    public function depositAmount(Product $product, float $rentalUnitPrice): float
    {
        $product->loadMissing('rentalSetting');
        $config = $product->rentalSetting;
        if (! $config) {
            return 0.0;
        }
        $type = (string) ($config->security_deposit_type ?: 'none');
        if ($type === 'none') {
            return 0.0;
        }
        $value = $config->security_deposit_value !== null ? (float) $config->security_deposit_value : 0.0;
        if ($type === 'percent') {
            return round(max(0, $rentalUnitPrice * ($value / 100)), 2);
        }

        return round(max(0, $value), 2);
    }

    public function cleaningFeeAmount(Product $product): float
    {
        $product->loadMissing('rentalSetting');
        $fee = $product->rentalSetting?->cleaning_fee;

        return $fee !== null ? round(max(0, (float) $fee), 2) : 0.0;
    }

    /**
     * Fee rates shown / applied on return (late/damage/lost) — not charged at checkout.
     *
     * @return array{late: array{type: string, value: float|null}, damage: array{type: string, value: float|null}, lost: array{type: string, value: float|null}}
     */
    public function contingentFees(Product $product): array
    {
        $product->loadMissing('rentalSetting');
        $c = $product->rentalSetting;

        return [
            'late' => ['type' => (string) ($c?->late_fee_type ?: 'none'), 'value' => $c?->late_fee_value !== null ? (float) $c->late_fee_value : null],
            'damage' => ['type' => (string) ($c?->damage_fee_type ?: 'none'), 'value' => $c?->damage_fee_value !== null ? (float) $c->damage_fee_value : null],
            'lost' => ['type' => (string) ($c?->lost_item_fee_type ?: 'none'), 'value' => $c?->lost_item_fee_value !== null ? (float) $c->lost_item_fee_value : null],
        ];
    }

    /**
     * @return array{deposit: float, cleaning_fee: float, deposit_status: string, contingent_fees: array}
     */
    public function buildRentalData(Product $product, float $unitPrice, ?array $assetIds = null): array
    {
        return [
            'deposit' => $this->depositAmount($product, $unitPrice),
            'cleaning_fee' => $this->cleaningFeeAmount($product),
            'deposit_status' => 'held',
            'contingent_fees' => $this->contingentFees($product),
            'assigned_asset_ids' => $assetIds ?? [],
        ];
    }

    public function inventorySummary(Product $product, ?string $from = null, ?string $until = null): array
    {
        $config = $product->relationLoaded('rentalSetting')
            ? $product->rentalSetting
            : ProductRentalSetting::query()->where('product_id', $product->id)->first();

        if (! $config || $config->inventory_mode === 'asset') {
            $assets = ProductRentalAsset::query()->where('product_id', $product->id)->get();
            $byStatus = $assets->groupBy('status')->map->count();
            $rented = (int) ($byStatus['rented'] ?? 0);
            $reserved = (int) ($byStatus['reserved'] ?? 0);

            return [
                'mode' => 'asset',
                'total_units' => $assets->count(),
                'available_units' => (int) ($byStatus['available'] ?? 0),
                'reserved_units' => $reserved,
                'rented_units' => $rented,
                'maintenance_units' => (int) ($byStatus['maintenance'] ?? 0),
                'out_of_service_units' => (int) (($byStatus['out_of_service'] ?? 0) + ($byStatus['lost'] ?? 0) + ($byStatus['damaged'] ?? 0)),
            ];
        }

        $total = max(0, (int) $product->stock_qty);
        $maintenance = (int) $config->maintenance_units;
        $outOfService = (int) $config->out_of_service_units;
        $capacity = max(0, $total - $maintenance - $outOfService);
        $from = $from ?: now()->toDateString();
        $until = $until ?: $from;
        $rented = $this->bookedQty($product, $from, $until);
        $available = max(0, $capacity - $rented);

        return [
            'mode' => 'quantity',
            'total_units' => $total,
            'available_units' => $available,
            'reserved_units' => 0,
            'rented_units' => $rented,
            'maintenance_units' => $maintenance,
            'out_of_service_units' => $outOfService,
            'capacity' => $capacity,
        ];
    }

    /**
     * @return list<int> ProductRentalAsset ids
     */
    public function reserveAssets(Product $product, float $qty, string $start, string $end): array
    {
        $product->loadMissing('rentalSetting');
        if (($product->rentalSetting?->inventory_mode ?? 'quantity') !== 'asset') {
            return [];
        }

        $need = max(1, (int) ceil($qty));
        $candidates = ProductRentalAsset::query()
            ->where('product_id', $product->id)
            ->where('status', 'available')
            ->orderBy('position')
            ->lockForUpdate()
            ->get();

        $picked = [];
        foreach ($candidates as $asset) {
            if ($this->assetOverlaps($asset->id, $start, $end)) {
                continue;
            }
            $picked[] = (int) $asset->id;
            if (count($picked) >= $need) {
                break;
            }
        }

        if (count($picked) < $need) {
            throw ValidationException::withMessages([
                'booking_date' => 'Not enough rental assets are available for those dates.',
            ]);
        }

        ProductRentalAsset::query()->whereIn('id', $picked)->update(['status' => 'rented']);

        return $picked;
    }

    public function releaseAssets(array $assetIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $assetIds)));
        if ($ids === []) {
            return;
        }
        ProductRentalAsset::query()
            ->whereIn('id', $ids)
            ->whereIn('status', ['rented', 'reserved'])
            ->update(['status' => 'available']);
    }

    public function releaseOrderItem(OrderItem $item): void
    {
        $data = is_array($item->rental_data) ? $item->rental_data : [];
        $assetIds = is_array($data['assigned_asset_ids'] ?? null) ? $data['assigned_asset_ids'] : [];
        $this->releaseAssets($assetIds);
        $data['deposit_status'] = 'released';
        $data['assigned_asset_ids'] = [];
        $item->rental_data = $data;
        $item->save();
    }

    /**
     * Compute late fee amount for an overdue return (days late after end + grace).
     */
    public function lateFeeForReturn(Product $product, OrderItem $item, ?Carbon $returnedAt = null): float
    {
        $product->loadMissing('rentalSetting');
        $config = $product->rentalSetting;
        if (! $config || ($config->late_fee_type ?: 'none') === 'none') {
            return 0.0;
        }
        $end = $item->booking_end_date ? Carbon::parse($item->booking_end_date)->startOfDay() : null;
        if (! $end) {
            return 0.0;
        }
        $grace = (int) ($config->grace_period ?? 0);
        $graceUnit = (string) ($config->grace_period_unit ?: 'days');
        $due = $end->copy();
        if ($grace > 0) {
            $due = match ($graceUnit) {
                'hours' => $due->addHours($grace),
                'weeks' => $due->addWeeks($grace),
                default => $due->addDays($grace),
            };
        }
        $returnedAt = $returnedAt ?: now();
        if ($returnedAt->lte($due)) {
            return 0.0;
        }
        $value = (float) ($config->late_fee_value ?? 0);
        $type = (string) $config->late_fee_type;
        if ($type === 'fixed') {
            return round(max(0, $value), 2);
        }
        if ($type === 'percent') {
            return round(max(0, (float) $item->unit_price * ($value / 100)), 2);
        }
        // per_hour / per_day
        $lateUnits = $type === 'per_hour'
            ? max(1, (int) ceil($due->diffInMinutes($returnedAt) / 60))
            : max(1, (int) $due->diffInDays($returnedAt));

        return round(max(0, $value * $lateUnits), 2);
    }

    public function bookedQty(Product $product, string $start, string $end, ?int $excludeOrderId = null): int
    {
        $query = OrderItem::query()
            ->where('product_id', $product->id)
            ->whereNotNull('booking_date')
            ->whereHas('order', function ($q) {
                $q->whereNotIn('status', ['cancelled', 'refunded', 'void']);
            })
            ->whereDate('booking_date', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereDate('booking_end_date', '>=', $start)
                    ->orWhere(function ($inner) use ($start) {
                        $inner->whereNull('booking_end_date')->whereDate('booking_date', '>=', $start);
                    });
            });

        if ($excludeOrderId) {
            $query->where('order_id', '!=', $excludeOrderId);
        }

        return (int) ceil((float) $query->sum('qty'));
    }

    protected function assetOverlaps(int $assetId, string $start, string $end, ?int $excludeOrderId = null): bool
    {
        $query = OrderItem::query()
            ->whereNotNull('rental_data')
            ->whereHas('order', function ($q) {
                $q->whereNotIn('status', ['cancelled', 'refunded', 'void']);
            })
            ->whereDate('booking_date', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereDate('booking_end_date', '>=', $start)
                    ->orWhere(function ($inner) use ($start) {
                        $inner->whereNull('booking_end_date')->whereDate('booking_date', '>=', $start);
                    });
            });

        if ($excludeOrderId) {
            $query->where('order_id', '!=', $excludeOrderId);
        }

        foreach ($query->get(['id', 'rental_data']) as $item) {
            $data = is_array($item->rental_data) ? $item->rental_data : [];
            $ids = array_map('intval', is_array($data['assigned_asset_ids'] ?? null) ? $data['assigned_asset_ids'] : []);
            if (in_array($assetId, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    protected function assertAvailabilityWindow(ProductRentalSetting $config, Carbon $start, Carbon $end): void
    {
        $now = Carbon::now()->startOfDay();
        if ($start->lt($now)) {
            throw ValidationException::withMessages([
                'booking_date' => 'Rental start date cannot be in the past.',
            ]);
        }

        $availability = is_array($config->availability_config) ? $config->availability_config : [];

        $availableFrom = trim((string) ($availability['available_from'] ?? ''));
        if ($availableFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $availableFrom)) {
            $from = Carbon::createFromFormat('Y-m-d', $availableFrom)->startOfDay();
            if ($start->lt($from)) {
                throw ValidationException::withMessages([
                    'booking_date' => 'This rental is not available before '.$availableFrom.'.',
                ]);
            }
        }

        $availableUntil = trim((string) ($availability['available_until'] ?? ''));
        if ($availableUntil !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $availableUntil)) {
            $until = Carbon::createFromFormat('Y-m-d', $availableUntil)->startOfDay();
            if ($end->gt($until)) {
                throw ValidationException::withMessages([
                    'booking_end_date' => 'This rental is not available after '.$availableUntil.'.',
                ]);
            }
        }

        $minAdvance = isset($availability['min_advance_booking']) && $availability['min_advance_booking'] !== ''
            ? (int) $availability['min_advance_booking']
            : 0;
        $minUnit = (string) ($availability['min_advance_unit'] ?? 'days');
        if ($minAdvance > 0) {
            $earliest = match ($minUnit) {
                'hours' => Carbon::now()->addHours($minAdvance)->startOfDay(),
                'weeks' => Carbon::now()->addWeeks($minAdvance)->startOfDay(),
                default => Carbon::now()->addDays($minAdvance)->startOfDay(),
            };
            if ($start->lt($earliest)) {
                throw ValidationException::withMessages([
                    'booking_date' => 'This rental needs more advance notice.',
                ]);
            }
        }

        $maxAdvance = isset($availability['max_advance_booking']) && $availability['max_advance_booking'] !== ''
            ? (int) $availability['max_advance_booking']
            : 0;
        $maxUnit = (string) ($availability['max_advance_unit'] ?? 'days');
        if ($maxAdvance > 0) {
            $latest = match ($maxUnit) {
                'hours' => Carbon::now()->addHours($maxAdvance)->startOfDay(),
                'weeks' => Carbon::now()->addWeeks($maxAdvance)->startOfDay(),
                default => Carbon::now()->addDays($maxAdvance)->startOfDay(),
            };
            if ($start->gt($latest)) {
                throw ValidationException::withMessages([
                    'booking_date' => 'That start date is too far in advance.',
                ]);
            }
        }
    }

    protected function assertCapacity(Product $product, string $start, string $end, float $qty, ?int $excludeOrderId = null): void
    {
        $product->loadMissing('rentalSetting');
        $config = $product->rentalSetting;
        $need = max(1, (int) ceil($qty));

        if (($config?->inventory_mode ?? 'quantity') === 'asset') {
            $available = ProductRentalAsset::query()
                ->where('product_id', $product->id)
                ->where('status', 'available')
                ->orderBy('position')
                ->get()
                ->filter(fn (ProductRentalAsset $a) => ! $this->assetOverlaps($a->id, $start, $end, $excludeOrderId))
                ->count();
            if ($available < $need) {
                throw ValidationException::withMessages([
                    'booking_date' => 'Not enough rental assets are available for those dates.',
                ]);
            }

            return;
        }

        $summary = $this->inventorySummary($product, $start, $end);
        // Recompute without counting would-be order: booked already in summary for range.
        $capacity = (int) ($summary['capacity'] ?? $summary['available_units'] + $summary['rented_units']);
        $booked = $this->bookedQty($product, $start, $end, $excludeOrderId);
        $free = max(0, $capacity - $booked);
        if ($free < $need) {
            throw ValidationException::withMessages([
                'booking_date' => 'This rental is not available for the selected dates.',
            ]);
        }
    }

    protected function durationBetween(Carbon $start, Carbon $end, string $unit): int
    {
        return match ($unit) {
            'hour' => max(1, (int) ceil($start->diffInMinutes($end->copy()->endOfDay()) / 60)),
            'week' => max(1, (int) ceil(max(1, $start->diffInDays($end) + 1) / 7)),
            'month' => max(1, (int) ceil(max(1, $start->diffInDays($end) + 1) / 30)),
            default => max(1, $start->diffInDays($end) + 1),
        };
    }

    protected function endFromDuration(Carbon $start, int $duration, string $unit): Carbon
    {
        $duration = max(1, $duration);

        return match ($unit) {
            'hour' => $start->copy()->addHours($duration)->subDay()->startOfDay(),
            'week' => $start->copy()->addWeeks($duration)->subDay()->startOfDay(),
            'month' => $start->copy()->addMonths($duration)->subDay()->startOfDay(),
            default => $start->copy()->addDays($duration - 1)->startOfDay(),
        };
    }

    protected function durationAsHours(int $duration, string $unit): float
    {
        return match ($unit) {
            'day' => $duration * 24,
            'week' => $duration * 24 * 7,
            'month' => $duration * 24 * 30,
            default => (float) $duration,
        };
    }

    protected function durationAsDays(int $duration, string $unit): float
    {
        return match ($unit) {
            'hour' => max(1, $duration / 24),
            'week' => $duration * 7,
            'month' => $duration * 30,
            default => (float) $duration,
        };
    }

    protected function durationAsWeeks(int $duration, string $unit): float
    {
        return match ($unit) {
            'hour' => max(1 / 168, $duration / 168),
            'day' => max(1 / 7, $duration / 7),
            'month' => $duration * 4,
            default => (float) $duration,
        };
    }

    protected function durationAsMonths(int $duration, string $unit): float
    {
        return match ($unit) {
            'hour' => max(1 / 720, $duration / 720),
            'day' => max(1 / 30, $duration / 30),
            'week' => max(1 / 4, $duration / 4),
            default => (float) $duration,
        };
    }

    protected function validateConfig(array $config, array $tiers, array $assets): void
    {
        $min = (int) ($config['min_duration'] ?? 0);
        $max = (int) ($config['max_duration'] ?? 0);
        $default = (int) ($config['default_duration'] ?? 0);

        if ($min <= 0) {
            throw ValidationException::withMessages(['rental.min_duration' => 'Minimum rental duration must be greater than zero.']);
        }
        if ($max < $min) {
            throw ValidationException::withMessages(['rental.max_duration' => 'Maximum duration must be greater than or equal to minimum.']);
        }
        if ($default < $min || $default > $max) {
            throw ValidationException::withMessages(['rental.default_duration' => 'Default duration must be within the allowed range.']);
        }

        $this->validateFee('security_deposit', $config['security_deposit_type'] ?? 'none', $config['security_deposit_value'] ?? null);
        $this->validateFee('late_fee', $config['late_fee_type'] ?? 'none', $config['late_fee_value'] ?? null);
        $this->validateFee('damage_fee', $config['damage_fee_type'] ?? 'none', $config['damage_fee_value'] ?? null);
        $this->validateFee('lost_item_fee', $config['lost_item_fee_type'] ?? 'none', $config['lost_item_fee_value'] ?? null);

        if (($config['cleaning_fee'] ?? '') !== '' && (float) $config['cleaning_fee'] < 0) {
            throw ValidationException::withMessages(['rental.cleaning_fee' => 'Cleaning fee cannot be negative.']);
        }

        if ((int) ($config['maintenance_units'] ?? 0) < 0 || (int) ($config['out_of_service_units'] ?? 0) < 0) {
            throw ValidationException::withMessages(['rental.maintenance_units' => 'Inventory unit counts cannot be negative.']);
        }

        $this->validateTierRanges($tiers);

        if (($config['inventory_mode'] ?? 'quantity') === 'asset') {
            $ids = [];
            foreach ($assets as $index => $row) {
                $assetId = trim((string) ($row['asset_id'] ?? ''));
                if ($assetId === '') {
                    throw ValidationException::withMessages(["rental_assets.$index.asset_id" => 'Asset ID is required.']);
                }
                if (isset($ids[$assetId])) {
                    throw ValidationException::withMessages(["rental_assets.$index.asset_id" => 'Duplicate asset IDs are not allowed.']);
                }
                $ids[$assetId] = true;
            }
        }
    }

    protected function validateFee(string $prefix, string $type, mixed $value): void
    {
        if ($type === 'none') {
            return;
        }
        if ($value === null || $value === '') {
            throw ValidationException::withMessages(["rental.{$prefix}_value" => 'Fee value is required.']);
        }
        $amount = (float) $value;
        if ($amount < 0) {
            throw ValidationException::withMessages(["rental.{$prefix}_value" => 'Fee value cannot be negative.']);
        }
        if ($type === 'percent' && $amount > 100) {
            throw ValidationException::withMessages(["rental.{$prefix}_value" => 'Percentage cannot exceed 100%.']);
        }
    }

    protected function validateTierRanges(array $tiers): void
    {
        $normalized = [];
        foreach ($tiers as $index => $row) {
            $min = (int) ($row['min_duration'] ?? 0);
            $max = (int) ($row['max_duration'] ?? 0);
            if ($min <= 0 || $max < $min) {
                throw ValidationException::withMessages(["rental_pricing_tiers.$index.min_duration" => 'Invalid tier duration range.']);
            }
            if ((float) ($row['price'] ?? 0) < 0) {
                throw ValidationException::withMessages(["rental_pricing_tiers.$index.price" => 'Tier price cannot be negative.']);
            }
            $normalized[] = ['min' => $min, 'max' => $max, 'index' => $index];
        }

        usort($normalized, fn ($a, $b) => $a['min'] <=> $b['min']);
        for ($i = 1; $i < count($normalized); $i++) {
            if ($normalized[$i]['min'] <= $normalized[$i - 1]['max']) {
                throw ValidationException::withMessages([
                    "rental_pricing_tiers.{$normalized[$i]['index']}.min_duration" => 'Pricing tier ranges cannot overlap.',
                ]);
            }
        }
    }

    protected function configAttributes(array $config): array
    {
        return [
            'rental_unit' => $config['rental_unit'] ?? 'day',
            'min_duration' => (int) ($config['min_duration'] ?? 1),
            'max_duration' => (int) ($config['max_duration'] ?? 30),
            'default_duration' => (int) ($config['default_duration'] ?? 1),
            'pricing_model' => $config['pricing_model'] ?? 'per_day',
            'availability_config' => $config['availability_config'] ?? null,
            'security_deposit_type' => $config['security_deposit_type'] ?? 'none',
            'security_deposit_value' => ($config['security_deposit_value'] ?? '') === '' ? null : $config['security_deposit_value'],
            'inventory_mode' => $config['inventory_mode'] ?? 'quantity',
            'maintenance_units' => (int) ($config['maintenance_units'] ?? 0),
            'out_of_service_units' => (int) ($config['out_of_service_units'] ?? 0),
            'fulfillment_method' => $config['fulfillment_method'] ?? 'both',
            'return_method' => $config['return_method'] ?? 'both',
            'return_location' => ($config['return_location'] ?? '') !== '' ? $config['return_location'] : null,
            'grace_period' => ($config['grace_period'] ?? '') === '' ? null : (int) $config['grace_period'],
            'grace_period_unit' => ($config['grace_period_unit'] ?? '') === '' ? null : $config['grace_period_unit'],
            'late_fee_type' => $config['late_fee_type'] ?? 'none',
            'late_fee_value' => ($config['late_fee_value'] ?? '') === '' ? null : $config['late_fee_value'],
            'damage_fee_type' => $config['damage_fee_type'] ?? 'none',
            'damage_fee_value' => ($config['damage_fee_value'] ?? '') === '' ? null : $config['damage_fee_value'],
            'lost_item_fee_type' => $config['lost_item_fee_type'] ?? 'none',
            'lost_item_fee_value' => ($config['lost_item_fee_value'] ?? '') === '' ? null : $config['lost_item_fee_value'],
            'cleaning_fee' => ($config['cleaning_fee'] ?? '') === '' ? null : $config['cleaning_fee'],
            'policies' => $config['policies'] ?? null,
        ];
    }

    protected function syncTiers(Product $product, array $tiers): void
    {
        $keep = [];
        foreach ($tiers as $index => $row) {
            $payload = [
                'min_duration' => (int) $row['min_duration'],
                'max_duration' => (int) $row['max_duration'],
                'unit' => $row['unit'] ?? 'day',
                'price' => $row['price'],
                'position' => (int) ($row['position'] ?? $index),
            ];
            if (! empty($row['id'])) {
                ProductRentalPricingTier::query()
                    ->where('product_id', $product->id)
                    ->where('id', $row['id'])
                    ->update($payload);
                $keep[] = (int) $row['id'];
            } else {
                $created = ProductRentalPricingTier::query()->create([
                    'product_id' => $product->id,
                    ...$payload,
                ]);
                $keep[] = $created->id;
            }
        }

        ProductRentalPricingTier::query()
            ->where('product_id', $product->id)
            ->when($keep, fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }

    protected function syncAssets(Product $product, array $assets, string $mode): void
    {
        if ($mode !== 'asset') {
            ProductRentalAsset::query()->where('product_id', $product->id)->delete();

            return;
        }

        $keep = [];
        foreach ($assets as $index => $row) {
            $payload = [
                'asset_id' => trim((string) $row['asset_id']),
                'serial_number' => ($row['serial_number'] ?? '') !== '' ? $row['serial_number'] : null,
                'barcode' => ($row['barcode'] ?? '') !== '' ? $row['barcode'] : null,
                'status' => $row['status'] ?? 'available',
                'location' => ($row['location'] ?? '') !== '' ? $row['location'] : null,
                'condition' => ($row['condition'] ?? '') !== '' ? $row['condition'] : null,
                'position' => (int) ($row['position'] ?? $index),
            ];
            if (! empty($row['id'])) {
                ProductRentalAsset::query()
                    ->where('product_id', $product->id)
                    ->where('id', $row['id'])
                    ->update($payload);
                $keep[] = (int) $row['id'];
            } else {
                $created = ProductRentalAsset::query()->create([
                    'product_id' => $product->id,
                    ...$payload,
                ]);
                $keep[] = $created->id;
            }
        }

        ProductRentalAsset::query()
            ->where('product_id', $product->id)
            ->when($keep, fn ($q) => $q->whereNotIn('id', $keep))
            ->delete();
    }
}
