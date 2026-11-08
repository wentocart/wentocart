<?php

namespace App\Domain\Marketplace;

use App\Domain\Settings\Models\Setting;

/**
 */
final class MarketplaceFulfilmentCarriers
{
    public const KEY = 'marketplace.fulfilment_carriers';

    /**
     * @return array{self_ship_title: string, carriers: list<array{code: string, title: string, is_enabled: bool, config: array<string, mixed>}>}
     */
    public static function all(): array
    {
        $raw = Setting::getValue(self::KEY, '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($decoded) ? $decoded : [];

        return [
            'self_ship_title' => (string) ($data['self_ship_title'] ?? ''),
            'carriers' => array_values(is_array($data['carriers'] ?? null) ? $data['carriers'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{self_ship_title: string, carriers: list<array<string, mixed>>}
     */
    public static function save(array $data): array
    {
        $current = self::all();
        if (array_key_exists('self_ship_title', $data) || array_key_exists('selfShipTitle', $data)) {
            $current['self_ship_title'] = (string) ($data['self_ship_title'] ?? $data['selfShipTitle'] ?? '');
        }
        if (array_key_exists('carriers', $data) && is_array($data['carriers'])) {
            $carriers = [];
            foreach ($data['carriers'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $code = trim((string) ($row['code'] ?? ''));
                if ($code === '' || $code === 'self_ship') {
                    continue;
                }
                $carriers[] = [
                    'code' => $code,
                    'title' => (string) ($row['title'] ?? $code),
                    'is_enabled' => (bool) ($row['is_enabled'] ?? $row['isEnabled'] ?? true),
                    'config' => is_array($row['config'] ?? null) ? $row['config'] : [],
                ];
            }
            $current['carriers'] = $carriers;
        }
        Setting::setValue(self::KEY, json_encode($current, JSON_UNESCAPED_UNICODE));

        return $current;
    }

    /**
     * Carriers sellers may choose when scheduling pickup (Self Ship always first).
     *
     * @return list<array{code: string, title: string, type: string}>
     */
    public static function availableForSellers(): array
    {
        $all = self::all();
        $title = trim($all['self_ship_title']) !== ''
            ? $all['self_ship_title']
            : 'Manual (Own Arrangement)';
        $rows = [[
            'code' => 'self_ship',
            'title' => $title,
            'type' => 'manual',
        ]];
        foreach ($all['carriers'] as $c) {
            if (! ($c['is_enabled'] ?? false)) {
                continue;
            }
            $rows[] = [
                'code' => (string) $c['code'],
                'title' => (string) ($c['title'] ?? $c['code']),
                'type' => 'integration',
            ];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public static function allowedCodes(): array
    {
        return array_values(array_map(
            fn (array $row) => (string) $row['code'],
            self::availableForSellers(),
        ));
    }

    public static function isAllowedCode(?string $code): bool
    {
        $code = trim((string) $code);
        if ($code === '') {
            return true;
        }

        return in_array($code, self::allowedCodes(), true);
    }
}
