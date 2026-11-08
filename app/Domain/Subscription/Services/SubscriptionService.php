<?php

namespace App\Domain\Subscription\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Support\CatalogCsvImport;
use App\Domain\Catalog\Support\PositionReorder;
use App\Domain\Catalog\Support\ProductModeConflict;
use App\Domain\Localization\Models\Language;
use App\Domain\Security\CsvSafe;
use App\Domain\Subscription\Models\ProductSubscriptionPlan;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Models\SubscriptionPlanTranslation;
use App\Domain\Subscription\Support\SubscriptionPlanCsvSchema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubscriptionService
{
    public function frequencies(): array
    {
        return config('wento.subscription.frequencies', ['hour', 'day', 'week', 'month', 'year']);
    }

    public function billingLabel(int $cycle, string $frequency): string
    {
        $cycle = max(1, $cycle);
        $unit = $this->unit($frequency, $cycle);

        return 'Every '.$cycle.' '.$unit;
    }

    public function trialSummary(SubscriptionPlan $plan): string
    {
        if (! $plan->trial_enabled) {
            return 'No';
        }

        $n = max(0, (int) $plan->trial_duration) * max(1, (int) $plan->trial_cycle);

        return $n.' '.$this->unit($plan->trial_frequency, $n);
    }

    public function durationSummary(SubscriptionPlan $plan): string
    {
        if ((int) $plan->duration === 0) {
            return 'Until cancelled';
        }

        return (int) $plan->duration.' billing cycle'.((int) $plan->duration === 1 ? '' : 's');
    }

    public function presentPlan(SubscriptionPlan $plan, ?string $locale = null): array
    {
        $name = $plan->translation($locale)?->name ?: 'Untitled plan';

        return [
            'id' => $plan->id,
            'name' => $name,
            'is_enabled' => (bool) $plan->is_enabled,
            'trial_enabled' => (bool) $plan->trial_enabled,
            'trial_duration' => (int) $plan->trial_duration,
            'trial_cycle' => (int) $plan->trial_cycle,
            'trial_frequency' => $plan->trial_frequency,
            'duration' => (int) $plan->duration,
            'cycle' => (int) $plan->cycle,
            'frequency' => $plan->frequency,
            'trial_summary' => $this->trialSummary($plan),
            'billing_summary' => $this->billingLabel((int) $plan->cycle, $plan->frequency),
            'duration_summary' => $this->durationSummary($plan),
            'products_count' => $plan->product_assignments_count ?? $plan->productAssignments()->count(),
            'translations' => $plan->relationLoaded('translations')
                ? $plan->translations->map(fn (SubscriptionPlanTranslation $row) => [
                    'language_code' => $row->language_code,
                    'name' => $row->name,
                ])->values()->all()
                : [],
        ];
    }

    public function savePlan(array $data, ?SubscriptionPlan $plan = null): SubscriptionPlan
    {
        $this->assertPlanIntegersInRange($data);

        return DB::transaction(function () use ($data, $plan) {
            $plan ??= new SubscriptionPlan;
            $default = Language::defaultCode();
            $payload = [
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'trial_enabled' => (bool) ($data['trial_enabled'] ?? false),
                'trial_duration' => (int) ($data['trial_duration'] ?? 0),
                'trial_cycle' => (int) ($data['trial_cycle'] ?? 1),
                'trial_frequency' => $data['trial_frequency'] ?? 'month',
                'duration' => (int) ($data['duration'] ?? 0),
                'cycle' => (int) ($data['cycle'] ?? 1),
                'frequency' => $data['frequency'] ?? 'month',
                'position' => PositionReorder::resolve(
                    $data['position'] ?? null,
                    $plan,
                ),
            ];
            $plan->fill($payload);
            if (! $plan->trial_enabled) {
                $plan->trial_duration = 0;
            }
            $plan->save();

            $this->syncTranslations($plan, $data, $default);

            return $plan->fresh('translations');
        });
    }

    public function csvColumns(): array
    {
        return SubscriptionPlanCsvSchema::columns();
    }

    public function sampleCsv(
        string $delimiter = SubscriptionPlanCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = SubscriptionPlanCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        return SubscriptionPlanCsvSchema::sampleCsv($delimiter, $multiSeparator);
    }

    public function importCsv(
        string $contents,
        string $locale = 'en',
        string $delimiter = SubscriptionPlanCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = SubscriptionPlanCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): int {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $this->normalizeCsvMultiSeparator($multiSeparator);

        $parsed = CatalogCsvImport::parse($contents, $delimiter);
        CatalogCsvImport::assertHeaders($parsed['header'], SubscriptionPlanCsvSchema::columns(), 'Subscription plan');
        CatalogCsvImport::assertHasDataRows($parsed['rows'], 'Subscription plan');

        $frequencies = $this->frequencies();
        $errors = [];
        $prepared = [];
        foreach ($parsed['rows'] as $index => $row) {
            $rowNumber = CatalogCsvImport::rowNumber($index);
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                $errors[] = "Row {$rowNumber}: Subscription plan name is required.";

                continue;
            }

            $frequency = strtolower(trim((string) ($row['frequency'] ?? '')));
            if ($frequency !== '' && ! in_array($frequency, $frequencies, true)) {
                $errors[] = "Row {$rowNumber}: Invalid billing frequency.";

                continue;
            }

            $prepared[] = SubscriptionPlanCsvSchema::mapRow($row, $locale, $multiSeparator, $frequencies);
        }

        CatalogCsvImport::throwRowErrors($errors);
        if ($prepared === []) {
            throw ValidationException::withMessages([
                'file' => 'Invalid CSV file. No valid Subscription plan rows to import.',
            ]);
        }

        return DB::transaction(function () use ($prepared, $locale) {
            $count = 0;
            foreach ($prepared as $mapped) {
                $existing = $this->findPlanByName($mapped['name'], $locale);
                $this->savePlan([
                    ...$mapped,
                    'translations' => [
                        $locale => [
                            'language_code' => $locale,
                            'name' => $mapped['name'],
                        ],
                    ],
                ], $existing);
                $count++;
            }

            return $count;
        });
    }

    public function exportCsv(
        string $locale = 'en',
        ?Request $request = null,
        string $delimiter = SubscriptionPlanCsvSchema::DEFAULT_DELIMITER,
        string $multiSeparator = SubscriptionPlanCsvSchema::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $delimiter = $this->normalizeCsvDelimiter($delimiter);
        $this->normalizeCsvMultiSeparator($multiSeparator);

        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, $this->csvColumns(), $delimiter);

        $status = $request?->string('status')->toString() ?: 'all';
        $name = trim((string) ($request?->string('name')->toString() ?: $request?->string('q')->toString() ?: ''));
        $frequency = trim((string) ($request?->string('frequency')->toString() ?: ''));
        $trial = trim((string) ($request?->string('trial')->toString() ?: ''));
        $idMin = $request?->input('id_min');
        $idMax = $request?->input('id_max');
        $cycleMin = $request?->input('cycle_min');
        $cycleMax = $request?->input('cycle_max');
        $frequencies = $this->frequencies();

        SubscriptionPlan::query()
            ->with('translations')
            ->when($status === 'active', fn ($q) => $q->where('is_enabled', true))
            ->when($status === 'inactive', fn ($q) => $q->where('is_enabled', false))
            ->when($name !== '', function ($query) use ($name) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
            })
            ->when($frequency !== '' && in_array($frequency, $frequencies, true), fn ($q) => $q->where('frequency', $frequency))
            ->when($trial === 'yes', fn ($q) => $q->where('trial_enabled', true))
            ->when($trial === 'no', fn ($q) => $q->where('trial_enabled', false))
            ->when($idMin !== null && $idMin !== '', fn ($q) => $q->where('subscription_plans.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($q) => $q->where('subscription_plans.id', '<=', (int) $idMax))
            ->when($cycleMin !== null && $cycleMin !== '', fn ($q) => $q->where('subscription_plans.cycle', '>=', (int) $cycleMin))
            ->when($cycleMax !== null && $cycleMax !== '', fn ($q) => $q->where('subscription_plans.cycle', '<=', (int) $cycleMax))
            ->orderBy('position')
            ->orderBy('id')
            ->chunk(100, function ($plans) use ($out, $delimiter, $locale) {
                foreach ($plans as $plan) {
                    CsvSafe::put($out, SubscriptionPlanCsvSchema::exportValues($plan, $locale), $delimiter);
                }
            });

        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    protected function findPlanByName(string $name, string $locale): ?SubscriptionPlan
    {
        $normalized = trim($name);
        if ($normalized === '') {
            return null;
        }

        return SubscriptionPlan::query()
            ->whereHas('translations', function ($query) use ($normalized, $locale) {
                $query->where('language_code', $locale)
                    ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($normalized)]);
            })
            ->first();
    }

    protected function normalizeCsvDelimiter(string $delimiter): string
    {
        $delimiter = $delimiter === '' ? SubscriptionPlanCsvSchema::DEFAULT_DELIMITER : $delimiter;

        return strlen($delimiter) === 1 ? $delimiter : SubscriptionPlanCsvSchema::DEFAULT_DELIMITER;
    }

    protected function normalizeCsvMultiSeparator(string $multiSeparator): string
    {
        return $multiSeparator === ''
            ? SubscriptionPlanCsvSchema::DEFAULT_MULTI_SEPARATOR
            : $multiSeparator;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function syncTranslations(SubscriptionPlan $plan, array $data, string $default): void
    {
        $rows = $data['translations'] ?? [];
        if ($rows === []) {
            $language = (string) ($data['language_code'] ?? $default);
            $rows = [$language => [
                'language_code' => $language,
                'name' => $data['name'] ?? '',
            ]];
        }

        foreach ($rows as $key => $row) {
            $row = (array) $row;
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            if ($language !== $default && $name === '') {
                SubscriptionPlanTranslation::query()
                    ->where('subscription_plan_id', $plan->id)
                    ->where('language_code', $language)
                    ->delete();

                continue;
            }

            $plan->translations()->updateOrCreate(
                ['language_code' => $language],
                ['name' => $name],
            );
        }
    }

    public function duplicate(SubscriptionPlan $plan): SubscriptionPlan
    {
        $plan->loadMissing('translations');
        $copy = $plan->replicate();
        $copy->is_enabled = false;
        $copy->position = (int) SubscriptionPlan::query()->max('position') + 1;
        $copy->save();

        foreach ($plan->translations as $translation) {
            $copy->translations()->create([
                'language_code' => $translation->language_code,
                'name' => $translation->name.' (copy)',
            ]);
        }

        return $copy->fresh('translations');
    }

    public function deletePlan(SubscriptionPlan $plan): void
    {
        $count = $plan->productAssignments()->count();
        if ($count > 0) {
            throw ValidationException::withMessages([
                'plan' => 'This subscription plan is currently assigned to '.$count.' product'.($count === 1 ? '' : 's').'. Please remove the plan from these products before deleting it.',
            ]);
        }

        $plan->delete();
    }

    public function subscriptionPrice(float $regular, ?string $discountType, mixed $discountValue, mixed $explicitPrice): float
    {
        $regular = max(0, round($regular, 2));
        if ($discountType === 'percent') {
            $pct = min(100, max(0, (float) $discountValue));

            return max(0, round($regular * (1 - ($pct / 100)), 2));
        }
        if ($discountType === 'fixed') {
            $amount = max(0, (float) $discountValue);

            return max(0, round($regular - min($amount, $regular), 2));
        }

        return max(0, round((float) $explicitPrice, 2));
    }

    public const PRODUCT_REQUIRES_ACTIVE_PLAN = 'Please add at least one active subscription plan before saving this product.';

    public function subscriptionPurchaseSelected(string $purchaseOption): bool
    {
        return in_array($purchaseOption, ['subscription', 'both'], true);
    }

    public function productRequiresActivePlan(bool $enabled, string $purchaseOption): bool
    {
        return $enabled && $this->subscriptionPurchaseSelected($purchaseOption);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    public function hasActiveAssignedPlan(array $rows): bool
    {
        $ids = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $planId = (int) ($row['subscription_plan_id'] ?? 0);
            if ($planId < 1 || ! $this->assignmentIsEnabled($row)) {
                continue;
            }
            $ids[] = $planId;
        }
        if ($ids === []) {
            return false;
        }

        return SubscriptionPlan::query()
            ->whereIn('id', array_values(array_unique($ids)))
            ->where('is_enabled', true)
            ->exists();
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    public function assertProductHasRequiredPlans(bool $enabled, string $purchaseOption, array $rows): void
    {
        if (! $this->productRequiresActivePlan($enabled, $purchaseOption)) {
            return;
        }
        if (! $this->hasActiveAssignedPlan($rows)) {
            throw ValidationException::withMessages([
                'subscription_plans' => self::PRODUCT_REQUIRES_ACTIVE_PLAN,
            ]);
        }
    }

    /** @param  array<string, mixed>  $row */
    protected function assignmentIsEnabled(array $row): bool
    {
        if (! array_key_exists('is_enabled', $row)) {
            return true;
        }
        $value = $row['is_enabled'];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
        }

        return (bool) $value;
    }

    public function syncProduct(Product $product, bool $enabled, string $purchaseOption, array $rows): void
    {
        $this->assertProductHasRequiredPlans($enabled, $purchaseOption, $rows);
        if ($enabled) {
            ProductModeConflict::assertExclusive(true, (bool) $product->preorder_enabled);
        }

        DB::transaction(function () use ($product, $enabled, $purchaseOption, $rows) {
            $product->update([
                'subscription_enabled' => $enabled,
                'purchase_option' => in_array($purchaseOption, ['one_time', 'subscription', 'both'], true)
                    ? $purchaseOption
                    : 'one_time',
            ]);

            $keep = [];
            $seenPlanIds = [];
            $existing = $product->productSubscriptionPlans()->get()->keyBy('subscription_plan_id');
            $defaultId = null;
            $position = 0;

            foreach (array_values($rows) as $row) {
                $planId = (int) ($row['subscription_plan_id'] ?? 0);
                if ($planId < 1 || isset($seenPlanIds[$planId])) {
                    continue;
                }
                $seenPlanIds[$planId] = true;
                $plan = SubscriptionPlan::query()->find($planId);
                if (! $plan) {
                    continue;
                }
                $current = $existing->get($planId);
                if (! $current && ! $plan->is_enabled) {
                    throw ValidationException::withMessages([
                        'subscription_plans' => 'Disabled subscription plans cannot be assigned to products.',
                    ]);
                }

                $regular = $product->sellingPrice();
                $discountType = in_array($row['discount_type'] ?? '', ['percent', 'fixed'], true)
                    ? $row['discount_type']
                    : null;
                $price = $this->subscriptionPrice(
                    $regular,
                    $discountType,
                    $row['discount_value'] ?? 0,
                    $row['price'] ?? $regular,
                );

                $payload = [
                    'price' => $price,
                    'discount_type' => $discountType,
                    'discount_value' => $discountType ? max(0, (float) ($row['discount_value'] ?? 0)) : null,
                    'is_enabled' => (bool) ($row['is_enabled'] ?? true),
                    'is_default' => (bool) ($row['is_default'] ?? false),
                    'position' => $position,
                ];
                $position++;

                if ($current) {
                    $current->update($payload);
                    $model = $current;
                } else {
                    $model = $product->productSubscriptionPlans()->create($payload + [
                        'subscription_plan_id' => $planId,
                    ]);
                }
                $keep[] = $model->id;
                if ($payload['is_default'] && $payload['is_enabled'] && $plan->is_enabled) {
                    $defaultId = $model->id;
                }
            }

            $query = $product->productSubscriptionPlans();
            if ($keep) {
                $query->whereNotIn('id', $keep);
            }
            $query->delete();

            $product->productSubscriptionPlans()->update(['is_default' => false]);
            if ($defaultId) {
                $product->productSubscriptionPlans()->whereKey($defaultId)->update(['is_default' => true]);
            } else {
                $fallback = $product->productSubscriptionPlans()
                    ->where('is_enabled', true)
                    ->orderBy('position')
                    ->first();
                if ($fallback) {
                    $plan = SubscriptionPlan::query()->find($fallback->subscription_plan_id);
                    if ($plan?->is_enabled) {
                        $fallback->update(['is_default' => true]);
                    }
                }
            }
        });
    }

    public function updateProductAssignment(Product $product, int $planId, array $row): ProductSubscriptionPlan
    {
        $assignment = $product->productSubscriptionPlans()->where('subscription_plan_id', $planId)->first();
        if (! $assignment) {
            throw ValidationException::withMessages([
                'subscription_plans' => 'This plan is not assigned to this product.',
            ]);
        }

        if (($row['discount_type'] ?? null) === 'percent' && (float) ($row['discount_value'] ?? 0) > 100) {
            throw ValidationException::withMessages([
                'discount_value' => 'Percentage discount cannot exceed 100%.',
            ]);
        }

        $regular = $product->sellingPrice();
        $discountType = in_array($row['discount_type'] ?? '', ['percent', 'fixed'], true)
            ? $row['discount_type']
            : null;
        $price = $this->subscriptionPrice(
            $regular,
            $discountType,
            $row['discount_value'] ?? 0,
            $row['price'] ?? $assignment->price ?? $regular,
        );
        $isEnabled = array_key_exists('is_enabled', $row)
            ? $this->assignmentIsEnabled($row)
            : (bool) $assignment->is_enabled;
        $isDefault = (bool) ($row['is_default'] ?? $assignment->is_default);

        $preview = $product->productSubscriptionPlans->map(function (ProductSubscriptionPlan $item) use ($assignment, $isEnabled) {
            return [
                'subscription_plan_id' => $item->subscription_plan_id,
                'is_enabled' => (int) $item->id === (int) $assignment->id ? $isEnabled : (bool) $item->is_enabled,
            ];
        })->all();
        $this->assertProductHasRequiredPlans(
            (bool) $product->subscription_enabled,
            (string) ($product->purchase_option ?: 'one_time'),
            $preview,
        );

        $assignment->update([
            'price' => $price,
            'discount_type' => $discountType,
            'discount_value' => $discountType ? max(0, (float) ($row['discount_value'] ?? 0)) : null,
            'is_enabled' => $isEnabled,
            'is_default' => false,
        ]);

        $product->productSubscriptionPlans()->update(['is_default' => false]);
        if ($isDefault && $isEnabled) {
            $assignment->update(['is_default' => true]);
        } else {
            $fallback = $product->productSubscriptionPlans()
                ->where('is_enabled', true)
                ->orderBy('position')
                ->first();
            if ($fallback) {
                $plan = SubscriptionPlan::query()->find($fallback->subscription_plan_id);
                if ($plan?->is_enabled) {
                    $fallback->update(['is_default' => true]);
                }
            }
        }

        return $assignment->fresh();
    }

    public function duplicateProduct(Product $from, Product $to): void
    {
        $to->update([
            'subscription_enabled' => (bool) $from->subscription_enabled,
            'purchase_option' => $from->purchase_option ?: 'one_time',
        ]);
        foreach ($from->productSubscriptionPlans as $row) {
            $to->productSubscriptionPlans()->create([
                'subscription_plan_id' => $row->subscription_plan_id,
                'price' => $row->price,
                'discount_type' => $row->discount_type,
                'discount_value' => $row->discount_value,
                'is_enabled' => $row->is_enabled,
                'is_default' => $row->is_default,
                'position' => $row->position,
            ]);
        }
    }

    public function storefrontPlans(Product $product, ?string $locale = null): array
    {
        if (! $product->subscription_enabled || ! in_array($product->purchase_option, ['subscription', 'both'], true)) {
            return [];
        }

        $product->loadMissing(['productSubscriptionPlans.plan.translations']);

        return $product->productSubscriptionPlans
            ->sortBy('position')
            ->values()
            ->filter(function (ProductSubscriptionPlan $row) {
                $plan = $row->plan;
                if (! $row->is_enabled || ! $plan || ! $plan->is_enabled) {
                    return false;
                }

                return true;
            })
            ->map(function (ProductSubscriptionPlan $row) use ($locale) {
                $plan = $row->plan;
                $presented = $this->presentPlan($plan, $locale);

                return [
                    'id' => $plan->id,
                    'assignment_id' => $row->id,
                    'name' => $presented['name'],
                    'billing_summary' => $presented['billing_summary'],
                    'trial_summary' => $presented['trial_summary'],
                    'duration_summary' => $presented['duration_summary'],
                    'trial_enabled' => (bool) $presented['trial_enabled'],
                    'cycle' => (int) $presented['cycle'],
                    'frequency' => $presented['frequency'],
                    'duration' => (int) $presented['duration'],
                    'price' => (float) $row->price,
                    'discount_type' => $row->discount_type,
                    'discount_value' => $row->discount_value !== null ? (float) $row->discount_value : null,
                    'is_default' => (bool) $row->is_default,
                ];
            })
            ->values()
            ->all();
    }

    public function storefrontMessage(Product $product, ?string $locale = null): ?string
    {
        if (! $product->subscription_enabled || ! in_array($product->purchase_option, ['subscription', 'both'], true)) {
            return null;
        }

        $locale ??= Language::currentCode();
        $rows = $product->relationLoaded('translations')
            ? $product->translations
            : $product->translations()->get();
        $current = trim((string) (collect($rows)->firstWhere('language_code', $locale)?->subscription_message ?? ''));
        if ($current !== '') {
            return $current;
        }
        $default = Language::defaultCode();
        if ($default !== $locale) {
            $fallback = trim((string) (collect($rows)->firstWhere('language_code', $default)?->subscription_message ?? ''));
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return null;
    }

    public function assignmentPrice(Product $product, SubscriptionPlan $plan): float
    {
        $row = $product->productSubscriptionPlans->firstWhere('subscription_plan_id', $plan->id)
            ?? $product->productSubscriptionPlans()->where('subscription_plan_id', $plan->id)->first();
        if (! $row || ! $row->is_enabled) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'This subscription plan is not available for this product.',
            ]);
        }
        if (! $plan->is_enabled) {
            throw ValidationException::withMessages([
                'subscription_plan_id' => 'This subscription plan is not available.',
            ]);
        }

        return (float) $row->price;
    }

    protected function unit(string $frequency, int $count): string
    {
        $map = [
            'hour' => ['Hour', 'Hours'],
            'day' => ['Day', 'Days'],
            'week' => ['Week', 'Weeks'],
            'month' => ['Month', 'Months'],
            'year' => ['Year', 'Years'],
        ];
        $pair = $map[$frequency] ?? $map['month'];

        return $count === 1 ? $pair[0] : $pair[1];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function assertPlanIntegersInRange(array $data): void
    {
        $max = (string) SubscriptionPlan::MAX_UNSIGNED_INT;
        $fields = [
            'trial_duration' => ['label' => 'Trial duration', 'min' => 0],
            'trial_cycle' => ['label' => 'Trial cycle', 'min' => 0],
            'duration' => ['label' => 'Duration', 'min' => 0],
            'cycle' => ['label' => 'Billing cycle', 'min' => 1],
        ];
        $errors = [];

        foreach ($fields as $field => $config) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $raw = $data[$field];
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = trim((string) $raw);
            if ($value === '' || ! preg_match('/^\d+$/', $value)) {
                $errors[$field] = $config['label'].' must be a whole number.';

                continue;
            }

            if ($this->unsignedIntStringLessThan($value, (string) $config['min']) || $this->unsignedIntStringGreaterThan($value, $max)) {
                $errors[$field] = $config['label'].' must be between '.$config['min'].' and '.number_format((int) $max).'.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function unsignedIntStringLessThan(string $left, string $right): bool
    {
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right);
        }

        return $left < $right;
    }

    protected function unsignedIntStringGreaterThan(string $left, string $right): bool
    {
        if (strlen($left) !== strlen($right)) {
            return strlen($left) > strlen($right);
        }

        return $left > $right;
    }
}
