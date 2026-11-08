<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\CheckoutGroup;
use App\Domain\Settings\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CheckoutReferenceService
{
    public const PREFIX = 'CHK-';

    public const DEFAULT_START = 10000;

    public function next(): string
    {
        return DB::transaction(function () {
            $floor = self::DEFAULT_START - 1;
            $setting = Setting::query()->where('key', 'checkout_sequence')->lockForUpdate()->first();
            $stored = (int) ($setting?->value ?? $floor);
            $maxExisting = $this->maxExistingSequence();
            $seq = max($stored, $maxExisting, $floor) + 1;

            Setting::query()->updateOrCreate(
                ['key' => 'checkout_sequence'],
                ['value' => (string) $seq]
            );
            Cache::forget('wento.settings');

            return self::PREFIX.$seq;
        });
    }

    public function maxExistingSequence(): int
    {
        $prefix = self::PREFIX;
        $prefixLen = strlen($prefix);

        return (int) (CheckoutGroup::query()
            ->where('reference', 'like', $prefix.'%')
            ->pluck('reference')
            ->map(fn (string $number) => (int) substr($number, $prefixLen))
            ->max() ?? 0);
    }
}
