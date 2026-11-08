<?php

namespace App\Domain\Subscription\Support;

use App\Domain\Security\CsvSafe;
use App\Domain\Subscription\Models\SubscriptionPlan;

/**
 * Single source of truth for subscription plan CSV import, export, and sample download.
 */
final class SubscriptionPlanCsvSchema
{
    public const DEFAULT_DELIMITER = ';';

    public const DEFAULT_MULTI_SEPARATOR = ',';

    /** @return list<string> */
    public static function columns(): array
    {
        return [
            'id',
            'name',
            'is_enabled',
            'position',
            'trial_enabled',
            'trial_duration',
            'trial_cycle',
            'trial_frequency',
            'duration',
            'cycle',
            'frequency',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function samplePlans(): array
    {
        return [
            [
                'name' => 'Monthly',
                'is_enabled' => '1',
                'position' => '1',
                'trial_enabled' => '0',
                'trial_duration' => '0',
                'trial_cycle' => '1',
                'trial_frequency' => 'month',
                'duration' => '0',
                'cycle' => '1',
                'frequency' => 'month',
            ],
            [
                'name' => 'Weekly',
                'is_enabled' => '1',
                'position' => '2',
                'trial_enabled' => '1',
                'trial_duration' => '1',
                'trial_cycle' => '1',
                'trial_frequency' => 'week',
                'duration' => '0',
                'cycle' => '1',
                'frequency' => 'week',
            ],
            [
                'name' => 'Quarterly',
                'is_enabled' => '1',
                'position' => '3',
                'trial_enabled' => '1',
                'trial_duration' => '14',
                'trial_cycle' => '1',
                'trial_frequency' => 'day',
                'duration' => '4',
                'cycle' => '3',
                'frequency' => 'month',
            ],
            [
                'name' => 'Annual',
                'is_enabled' => '1',
                'position' => '4',
                'trial_enabled' => '0',
                'trial_duration' => '0',
                'trial_cycle' => '1',
                'trial_frequency' => 'year',
                'duration' => '1',
                'cycle' => '1',
                'frequency' => 'year',
            ],
            [
                'name' => 'Legacy Daily',
                'is_enabled' => '0',
                'position' => '10',
                'trial_enabled' => '0',
                'trial_duration' => '0',
                'trial_cycle' => '1',
                'trial_frequency' => 'day',
                'duration' => '0',
                'cycle' => '1',
                'frequency' => 'day',
            ],
        ];
    }

    public static function sampleCsv(
        string $delimiter = self::DEFAULT_DELIMITER,
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
    ): string {
        $out = fopen('php://temp', 'r+');
        CsvSafe::put($out, self::columns(), $delimiter);
        foreach (self::samplePlans() as $row) {
            CsvSafe::put($out, self::rowToCsv($row, $multiSeparator), $delimiter);
        }
        rewind($out);

        return stream_get_contents($out) ?: '';
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    public static function rowToCsv(array $row, string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR): array
    {
        $cells = [];
        foreach (self::columns() as $key) {
            $value = $row[$key] ?? '';
            if (is_array($value)) {
                $value = implode($multiSeparator, array_map(static fn ($part) => (string) $part, $value));
            }
            $cells[] = (string) $value;
        }

        return $cells;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $frequencies
     * @return array<string, mixed>
     */
    public static function mapRow(
        array $row,
        string $locale = 'en',
        string $multiSeparator = self::DEFAULT_MULTI_SEPARATOR,
        array $frequencies = ['hour', 'day', 'week', 'month', 'year'],
    ): array {
        unset($multiSeparator);
        $get = static fn (string $key): ?string => array_key_exists($key, $row)
            ? trim((string) $row[$key])
            : null;

        $frequency = strtolower((string) ($get('frequency') ?? 'month'));
        if (! in_array($frequency, $frequencies, true)) {
            $frequency = 'month';
        }

        $trialFrequency = strtolower((string) ($get('trial_frequency') ?? $frequency));
        if (! in_array($trialFrequency, $frequencies, true)) {
            $trialFrequency = $frequency;
        }

        $trialEnabled = self::boolOr($get('trial_enabled'), false);

        return [
            'name' => (string) ($get('name') ?? ''),
            'is_enabled' => self::boolOr($get('is_enabled'), true),
            'position' => self::filled($get('position')) ? (int) $get('position') : null,
            'trial_enabled' => $trialEnabled,
            'trial_duration' => $trialEnabled ? self::intOr($get('trial_duration'), 1) : 0,
            'trial_cycle' => $trialEnabled ? max(1, self::intOr($get('trial_cycle'), 1)) : 1,
            'trial_frequency' => $trialFrequency,
            'duration' => max(0, self::intOr($get('duration'), 0)),
            'cycle' => max(1, self::intOr($get('cycle'), 1)),
            'frequency' => $frequency,
            'language_code' => $locale,
        ];
    }

    /** @return list<string|int|null> */
    public static function exportValues(SubscriptionPlan $plan, string $locale = 'en'): array
    {
        $t = $plan->translation($locale);
        $map = [
            'id' => $plan->id,
            'name' => $t?->name,
            'is_enabled' => self::boolToCsv($plan->is_enabled),
            'position' => $plan->position,
            'trial_enabled' => self::boolToCsv($plan->trial_enabled),
            'trial_duration' => $plan->trial_duration,
            'trial_cycle' => $plan->trial_cycle,
            'trial_frequency' => $plan->trial_frequency,
            'duration' => $plan->duration,
            'cycle' => $plan->cycle,
            'frequency' => $plan->frequency,
        ];

        return array_map(fn (string $key) => $map[$key] ?? '', self::columns());
    }

    protected static function filled(?string $value): bool
    {
        return $value !== null && $value !== '';
    }

    protected static function intOr(?string $value, int $default): int
    {
        return self::filled($value) ? (int) $value : $default;
    }

    protected static function boolOr(?string $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'yes', 'y', 'on', 'active', 'enabled' => true,
            '0', 'false', 'no', 'n', 'off', 'inactive', 'disabled' => false,
            default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default,
        };
    }

    protected static function boolToCsv(mixed $value): string
    {
        return $value ? '1' : '0';
    }
}
