<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductServiceSetting;
use App\Domain\Order\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceProductService
{
    public function syncProduct(Product $product, array $config): ProductServiceSetting
    {
        if ($product->type !== 'service') {
            abort(422, 'Service configuration applies only to service products.');
        }

        $this->validateConfig($config);

        return DB::transaction(function () use ($product, $config) {
            return ProductServiceSetting::query()->updateOrCreate(
                ['product_id' => $product->id],
                $this->configAttributes($config),
            );
        });
    }

    public function presentConfig(Product $product): ?array
    {
        $config = $product->relationLoaded('serviceSetting')
            ? $product->serviceSetting
            : ProductServiceSetting::query()->where('product_id', $product->id)->first();
        if (! $config) {
            return null;
        }

        return $this->presentRow($config);
    }

    public function presentRow(ProductServiceSetting $config): array
    {
        return [
            'service_type' => $config->service_type,
            'duration' => $config->duration !== null ? (float) $config->duration : null,
            'duration_unit' => $config->duration_unit,
            'pricing_model' => $config->pricing_model ?: 'fixed',
            'delivery_method' => $config->delivery_method ?: 'online',
            'meeting_instructions' => $config->meeting_instructions,
            'delivery_instructions' => $config->delivery_instructions,
            'online_service_url' => $config->online_service_url,
            'service_area' => $config->service_area,
            'location_info' => $config->location_info,
            'travel_fee' => $config->travel_fee !== null ? (float) $config->travel_fee : null,
            'availability_config' => $config->availability_config ?? [],
            'requires_booking' => $this->requiresBookingForType((string) $config->service_type),
            'requires_scheduling' => $config->requiresScheduling(),
        ];
    }

    public function requiresBookingDate(Product $product): bool
    {
        if ($product->type === 'rental') {
            return true;
        }
        if (! $product->isService()) {
            return false;
        }
        $type = (string) ($product->serviceSetting?->service_type
            ?? ProductServiceSetting::query()->where('product_id', $product->id)->value('service_type')
            ?? 'one_time');

        return $this->requiresBookingForType($type);
    }

    public function requiresBookingForType(string $serviceType): bool
    {
        return in_array($serviceType, ['hourly', 'appointment'], true);
    }

    /**
     * Apply service pricing_model on top of a catalog unit price.
     */
    public function applyPricingModel(Product $product, float $catalogUnit): float
    {
        if (! $product->isService()) {
            return round(max(0, $catalogUnit), 2);
        }

        $config = $product->relationLoaded('serviceSetting')
            ? $product->serviceSetting
            : ProductServiceSetting::query()->where('product_id', $product->id)->first();
        $model = (string) ($config?->pricing_model ?: 'fixed');
        $duration = $config?->duration !== null ? (float) $config->duration : null;
        $unit = $config?->duration_unit;

        $multiplier = match ($model) {
            'per_hour' => $this->durationAsHours($duration, $unit),
            'per_day' => $this->durationAsDays($duration, $unit),
            // starting_from and fixed charge the catalog base at checkout.
            default => 1.0,
        };

        return round(max(0, $catalogUnit * max(0.0001, $multiplier)), 2);
    }

    public function unitPrice(Product $product): float
    {
        return $this->applyPricingModel($product, (float) $product->sellingPrice());
    }

    public function travelFeePerUnit(Product $product): float
    {
        if (! $product->isService()) {
            return 0.0;
        }
        $config = $product->relationLoaded('serviceSetting')
            ? $product->serviceSetting
            : ProductServiceSetting::query()->where('product_id', $product->id)->first();
        if (! $config) {
            return 0.0;
        }
        $delivery = (string) ($config->delivery_method ?: 'online');
        if (! in_array($delivery, ['onsite', 'both'], true)) {
            return 0.0;
        }
        $fee = $config->travel_fee !== null ? (float) $config->travel_fee : 0.0;

        return round(max(0, $fee), 2);
    }

    /**
     * Validate booking_date against appointment availability rules.
     *
     * @throws ValidationException
     */
    public function assertBookingAllowed(Product $product, string $date): void
    {
        if (! $product->isService()) {
            return;
        }

        $config = $product->relationLoaded('serviceSetting')
            ? $product->serviceSetting
            : ProductServiceSetting::query()->where('product_id', $product->id)->first();

        if (! $config || $config->service_type !== 'appointment') {
            return;
        }

        try {
            $booking = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'booking_date' => 'Use a valid date (YYYY-MM-DD).',
            ]);
        }

        $availability = is_array($config->availability_config) ? $config->availability_config : [];
        $days = array_values(array_filter(array_map(
            fn ($d) => strtolower(substr((string) $d, 0, 3)),
            is_array($availability['available_days'] ?? null) ? $availability['available_days'] : [],
        )));
        $weekday = $this->weekdayKey($booking);
        if ($days !== [] && ! in_array($weekday, $days, true)) {
            throw ValidationException::withMessages([
                'booking_date' => 'That day is not available for this service.',
            ]);
        }

        $now = Carbon::now();
        $minAdvance = isset($availability['min_advance_hours']) && $availability['min_advance_hours'] !== ''
            ? (float) $availability['min_advance_hours']
            : 0.0;
        $startTime = trim((string) ($availability['start_time'] ?? '00:00'));
        if (! preg_match('/^\d{1,2}:\d{2}/', $startTime)) {
            $startTime = '00:00';
        }
        $bookingStart = $booking->copy()->setTimeFromTimeString($startTime);
        if ($minAdvance > 0 && $bookingStart->lt($now->copy()->addHours($minAdvance))) {
            throw ValidationException::withMessages([
                'booking_date' => 'This booking needs more advance notice.',
            ]);
        }

        $maxAdvance = isset($availability['max_advance_days']) && $availability['max_advance_days'] !== ''
            ? (int) $availability['max_advance_days']
            : 0;
        if ($maxAdvance > 0 && $booking->gt($now->copy()->startOfDay()->addDays($maxAdvance))) {
            throw ValidationException::withMessages([
                'booking_date' => 'That date is too far in advance.',
            ]);
        }

        $endTime = trim((string) ($availability['end_time'] ?? '23:59'));
        if (! preg_match('/^\d{1,2}:\d{2}/', $endTime)) {
            $endTime = '23:59';
        }
        $bookingEnd = $booking->copy()->setTimeFromTimeString($endTime);
        if ($booking->isSameDay($now) && $now->gte($bookingEnd)) {
            throw ValidationException::withMessages([
                'booking_date' => 'Booking hours for today have ended.',
            ]);
        }

        $maxBookings = isset($availability['max_bookings']) && $availability['max_bookings'] !== ''
            ? (int) $availability['max_bookings']
            : 0;
        if ($maxBookings > 0) {
            $taken = (float) OrderItem::query()
                ->where('product_id', $product->id)
                ->whereDate('booking_date', $date)
                ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'refunded']))
                ->sum('qty');
            if ($taken >= $maxBookings) {
                throw ValidationException::withMessages([
                    'booking_date' => 'No more bookings are available on that date.',
                ]);
            }
        }
    }

    /**
     *
     * @return list<array<string, mixed>>
     */
    public function slotsForDate(Product $product, string $date): array
    {
        try {
            $day = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        if ($product->isService()) {
            try {
                $this->assertBookingAllowed($product, $date);
            } catch (ValidationException) {
                return [];
            }
        } elseif (! $product->isRental() && $product->type !== 'booking') {
            // Non-bookable catalogue SKUs have no slots.
            return [];
        }

        $config = $product->relationLoaded('serviceSetting')
            ? $product->serviceSetting
            : ProductServiceSetting::query()->where('product_id', $product->id)->first();

        $availability = is_array($config?->availability_config) ? $config->availability_config : [];
        $startTime = trim((string) ($availability['start_time'] ?? '09:00'));
        $endTime = trim((string) ($availability['end_time'] ?? '17:00'));
        if (! preg_match('/^\d{1,2}:\d{2}/', $startTime)) {
            $startTime = '09:00';
        }
        if (! preg_match('/^\d{1,2}:\d{2}/', $endTime)) {
            $endTime = '17:00';
        }

        $durationMinutes = 60;
        if ($config && (float) ($config->duration ?? 0) > 0) {
            $durationMinutes = match ((string) ($config->duration_unit ?? 'minutes')) {
                'hours' => (int) round((float) $config->duration * 60),
                'days' => (int) round((float) $config->duration * 24 * 60),
                default => (int) round((float) $config->duration),
            };
        }
        $durationMinutes = max(15, min(24 * 60, $durationMinutes));

        $maxBookings = isset($availability['max_bookings']) && $availability['max_bookings'] !== ''
            ? (int) $availability['max_bookings']
            : 0;
        $taken = (float) OrderItem::query()
            ->where('product_id', $product->id)
            ->whereDate('booking_date', $date)
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['cancelled', 'refunded']))
            ->sum('qty');
        $remaining = $maxBookings > 0 ? max(0, $maxBookings - (int) $taken) : null;

        $serviceType = (string) ($config?->service_type ?? 'default');
        if ($serviceType !== 'appointment' && $serviceType !== 'hourly') {
            $from = $day->copy()->setTimeFromTimeString($startTime);
            $to = $day->copy()->setTimeFromTimeString($endTime);
            $fromTs = $from->timestamp;
            $toTs = $to->timestamp;

            return [[
                'slotId' => $fromTs.'-'.$toTs,
                'from' => $from->format('D, d M h:i A'),
                'to' => $to->format('D, d M h:i A'),
                'timestamp' => $fromTs.'-'.$toTs,
                'qty' => $remaining !== null ? (string) $remaining : null,
                'time' => null,
                'slots' => null,
            ]];
        }

        $cursor = $day->copy()->setTimeFromTimeString($startTime);
        $end = $day->copy()->setTimeFromTimeString($endTime);
        $slots = [];
        $guard = 0;
        while ($cursor->copy()->addMinutes($durationMinutes)->lte($end) && $guard < 96) {
            $slotEnd = $cursor->copy()->addMinutes($durationMinutes);
            $fromTs = $cursor->timestamp;
            $toTs = $slotEnd->timestamp;
            $slots[] = [
                'slotId' => $fromTs.'-'.$toTs,
                'from' => $cursor->format('h:i A'),
                'to' => $slotEnd->format('h:i A'),
                'timestamp' => $fromTs.'-'.$toTs,
                'qty' => $remaining !== null ? (string) $remaining : null,
                'time' => null,
                'slots' => null,
            ];
            $cursor->addMinutes($durationMinutes);
            $guard++;
        }

        return $slots;
    }

    protected function weekdayKey(Carbon $date): string
    {
        return match ((int) $date->dayOfWeek) {
            Carbon::SUNDAY => 'sun',
            Carbon::MONDAY => 'mon',
            Carbon::TUESDAY => 'tue',
            Carbon::WEDNESDAY => 'wed',
            Carbon::THURSDAY => 'thu',
            Carbon::FRIDAY => 'fri',
            default => 'sat',
        };
    }

    protected function durationAsHours(?float $duration, ?string $unit): float
    {
        $d = (float) ($duration ?? 0);
        if ($d <= 0) {
            return 1.0;
        }

        return match ($unit) {
            'minutes' => $d / 60,
            'days' => $d * 24,
            default => $d,
        };
    }

    protected function durationAsDays(?float $duration, ?string $unit): float
    {
        $d = (float) ($duration ?? 0);
        if ($d <= 0) {
            return 1.0;
        }

        return match ($unit) {
            'minutes' => $d / (60 * 24),
            'hours' => $d / 24,
            default => $d,
        };
    }

    protected function validateConfig(array $config): void
    {
        $type = $config['service_type'] ?? 'one_time';
        $allowedTypes = ['one_time', 'recurring', 'hourly', 'appointment'];
        if (! in_array($type, $allowedTypes, true)) {
            throw ValidationException::withMessages(['service.service_type' => 'Invalid service type.']);
        }

        $durationRequired = in_array($type, ['hourly', 'appointment'], true)
            || in_array($config['pricing_model'] ?? 'fixed', ['per_hour', 'per_day'], true);
        if ($durationRequired) {
            $duration = (float) ($config['duration'] ?? 0);
            if ($duration <= 0) {
                throw ValidationException::withMessages(['service.duration' => 'Duration must be greater than zero for this pricing or service type.']);
            }
            $unit = $config['duration_unit'] ?? null;
            if (! in_array($unit, ['minutes', 'hours', 'days'], true)) {
                throw ValidationException::withMessages(['service.duration_unit' => 'Select a valid duration unit.']);
            }
        } elseif (($config['duration'] ?? '') !== '' && (float) $config['duration'] <= 0) {
            throw ValidationException::withMessages(['service.duration' => 'Duration must be greater than zero.']);
        }

        $pricingModel = $config['pricing_model'] ?? 'fixed';
        if (! in_array($pricingModel, ['fixed', 'per_hour', 'per_day', 'starting_from'], true)) {
            throw ValidationException::withMessages(['service.pricing_model' => 'Invalid pricing model.']);
        }

        if (($config['travel_fee'] ?? '') !== '' && (float) $config['travel_fee'] < 0) {
            throw ValidationException::withMessages(['service.travel_fee' => 'Travel fee cannot be negative.']);
        }

        if ($type === 'appointment') {
            $availability = $config['availability_config'] ?? [];
            $days = $availability['available_days'] ?? [];
            if (! is_array($days) || count($days) === 0) {
                throw ValidationException::withMessages(['service.availability_config' => 'Select at least one available day for appointment services.']);
            }
        }
    }

    protected function configAttributes(array $config): array
    {
        return [
            'service_type' => $config['service_type'] ?? 'one_time',
            'duration' => ($config['duration'] ?? '') === '' ? null : $config['duration'],
            'duration_unit' => ($config['duration_unit'] ?? '') === '' ? null : $config['duration_unit'],
            'pricing_model' => $config['pricing_model'] ?? 'fixed',
            'delivery_method' => $config['delivery_method'] ?? 'online',
            'meeting_instructions' => ($config['meeting_instructions'] ?? '') !== '' ? $config['meeting_instructions'] : null,
            'delivery_instructions' => ($config['delivery_instructions'] ?? '') !== '' ? $config['delivery_instructions'] : null,
            'online_service_url' => ($config['online_service_url'] ?? '') !== '' ? $config['online_service_url'] : null,
            'service_area' => ($config['service_area'] ?? '') !== '' ? $config['service_area'] : null,
            'location_info' => ($config['location_info'] ?? '') !== '' ? $config['location_info'] : null,
            'travel_fee' => ($config['travel_fee'] ?? '') === '' ? null : $config['travel_fee'],
            'availability_config' => $config['availability_config'] ?? null,
        ];
    }
}
