<?php

namespace App\Domain\Order\Services;

use App\Domain\Order\Models\Order;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\OrderSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OrderNumberService
{
    public const PREFIX = 'WENTO-';

    public function next(): string
    {
        return DB::transaction(function () {
            $seq = $this->peekSequence(true);

            Setting::query()->updateOrCreate(
                ['key' => OrderSettings::sequenceKey()],
                ['value' => (string) $seq]
            );
            Cache::forget('wento.settings');

            return OrderSettings::formatNumber($seq);
        });
    }

    /**
     * Next sequence for the current shop. A shop with no counter of its own
     * begins at its starting number. Numbers already used by any shop are skipped.
     */
    public function peekSequence(bool $lock = false): int
    {
        $start = max(1, OrderSettings::numberStart());
        $query = Setting::query()->where('key', OrderSettings::sequenceKey());
        if ($lock) {
            $query->lockForUpdate();
        }
        $raw = $query->value('value');
        if ($raw === null || $raw === '') {
            return $this->firstFree($start);
        }

        $floor = max(0, $start - 1);

        return $this->firstFree(max((int) $raw, $floor) + 1);
    }

    public function maxExistingSequence(?string $prefix = null): int
    {
        $prefix = $prefix ?? OrderSettings::numberPrefix();
        $prefixLen = strlen($prefix);
        if ($prefixLen < 1) {
            return 0;
        }

        return (int) (Order::query()
            ->withoutGlobalScope('shop')
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->map(fn (string $number) => (int) substr($number, $prefixLen))
            ->max() ?? 0);
    }

    /**
     * Smallest sequence at or above $seq whose formatted number is unused.
     * Order numbers are global, so this ignores the shop list scope.
     */
    protected function firstFree(int $seq): int
    {
        if (! $this->taken($seq)) {
            return $seq;
        }

        $prefix = OrderSettings::numberPrefix();
        $prefixLen = strlen($prefix);
        $taken = Order::query()
            ->withoutGlobalScope('shop')
            ->where('number', 'like', $prefix.'%')
            ->pluck('number')
            ->map(fn (string $number) => (int) substr($number, $prefixLen))
            ->flip();

        while ($taken->has($seq)) {
            $seq++;
        }

        return $seq;
    }

    protected function taken(int $seq): bool
    {
        return Order::query()
            ->withoutGlobalScope('shop')
            ->where('number', OrderSettings::formatNumber($seq))
            ->exists();
    }
}
