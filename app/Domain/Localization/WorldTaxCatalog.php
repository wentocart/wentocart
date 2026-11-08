<?php

namespace App\Domain\Localization;

/**
 * Starter consumption-tax rows for localization packs.
 *
 * Full JSON packs (IN / US / CA / …) win when they define tax/taxes.
 * Everyone else uses the standard national VAT/GST rate below (plus a 0% class
 * where shops commonly need GST-free / zero-rated products).
 *
 * These are national standard rates for e-commerce starters — not city add-ons,
 * not compound CGST/SGST lines, and not a live tax API. Merchants can edit after install.
 */
final class WorldTaxCatalog
{
    /**
     * @return list<array{class: string, name: string, rate: float|int, priority?: int, region?: string}>
     */
    public static function taxesFor(string $countryCode): array
    {
        $code = strtoupper($countryCode);

        return match ($code) {
            'AU' => [
                ['class' => 'GST', 'name' => 'GST 10%', 'rate' => 10, 'priority' => 100],
                ['class' => 'GST-free', 'name' => 'GST-free 0%', 'rate' => 0, 'priority' => 100],
            ],
            'AE' => [
                ['class' => 'VAT', 'name' => 'VAT 5%', 'rate' => 5, 'priority' => 100],
                ['class' => 'Zero-rated', 'name' => 'Zero-rated 0%', 'rate' => 0, 'priority' => 100],
            ],
            'NZ' => [
                ['class' => 'GST', 'name' => 'GST 15%', 'rate' => 15, 'priority' => 100],
                ['class' => 'GST-free', 'name' => 'GST-free 0%', 'rate' => 0, 'priority' => 100],
            ],
            'SG' => [
                ['class' => 'GST', 'name' => 'GST 9%', 'rate' => 9, 'priority' => 100],
                ['class' => 'GST-free', 'name' => 'GST-free 0%', 'rate' => 0, 'priority' => 100],
            ],
            'GB', 'UK' => [
                ['class' => 'VAT', 'name' => 'VAT 20%', 'rate' => 20, 'priority' => 100],
                ['class' => 'VAT reduced', 'name' => 'VAT reduced 5%', 'rate' => 5, 'priority' => 100],
                ['class' => 'Zero-rated', 'name' => 'Zero-rated 0%', 'rate' => 0, 'priority' => 100],
            ],
            default => self::fromStandardRate($code),
        };
    }

    public static function hasTax(string $countryCode): bool
    {
        return self::taxesFor($countryCode) !== [];
    }

    /**
     * @return list<array{class: string, name: string, rate: float|int, priority: int}>
     */
    protected static function fromStandardRate(string $code): array
    {
        $meta = self::standardRates()[$code] ?? null;
        if ($meta === null || $meta === [] || ! isset($meta['label'], $meta['rate'])) {
            return [];
        }

        $label = $meta['label'];
        $rate = $meta['rate'];
        $rows = [
            ['class' => $label, 'name' => $label.' '.$rate.'%', 'rate' => $rate, 'priority' => 100],
        ];

        if (! empty($meta['zero'])) {
            $zeroClass = ($label === 'GST') ? 'GST-free' : 'Zero-rated';
            $rows[] = [
                'class' => $zeroClass,
                'name' => $zeroClass.' 0%',
                'rate' => 0,
                'priority' => 100,
            ];
        }

        if (isset($meta['reduced']) && is_numeric($meta['reduced'])) {
            $rows[] = [
                'class' => $label.' reduced',
                'name' => $label.' reduced '.$meta['reduced'].'%',
                'rate' => $meta['reduced'],
                'priority' => 100,
            ];
        }

        return $rows;
    }

    /**
     * National standard VAT/GST rates (ISO 3166-1 alpha-2).
     * Source basis: common published standard rates for shop checkout starters.
     *
     * @return array<string, array{label: string, rate: float|int, zero?: bool, reduced?: float|int}>
     */
    protected static function standardRates(): array
    {
        return [
            // Europe / EU-style VAT
            'AT' => ['label' => 'VAT', 'rate' => 20, 'zero' => true, 'reduced' => 10],
            'BE' => ['label' => 'VAT', 'rate' => 21, 'zero' => true, 'reduced' => 6],
            'BG' => ['label' => 'VAT', 'rate' => 20, 'zero' => true, 'reduced' => 9],
            'HR' => ['label' => 'VAT', 'rate' => 25, 'zero' => true, 'reduced' => 5],
            'CY' => ['label' => 'VAT', 'rate' => 19, 'zero' => true, 'reduced' => 5],
            'CZ' => ['label' => 'VAT', 'rate' => 21, 'zero' => true, 'reduced' => 12],
            'DK' => ['label' => 'VAT', 'rate' => 25, 'zero' => true],
            'EE' => ['label' => 'VAT', 'rate' => 22, 'zero' => true, 'reduced' => 9],
            'FI' => ['label' => 'VAT', 'rate' => 25.5, 'zero' => true, 'reduced' => 10],
            'FR' => ['label' => 'VAT', 'rate' => 20, 'zero' => true, 'reduced' => 5.5],
            'DE' => ['label' => 'VAT', 'rate' => 19, 'zero' => true, 'reduced' => 7],
            'GR' => ['label' => 'VAT', 'rate' => 24, 'zero' => true, 'reduced' => 13],
            'HU' => ['label' => 'VAT', 'rate' => 27, 'zero' => true, 'reduced' => 5],
            'IE' => ['label' => 'VAT', 'rate' => 23, 'zero' => true, 'reduced' => 13.5],
            'IT' => ['label' => 'VAT', 'rate' => 22, 'zero' => true, 'reduced' => 10],
            'LV' => ['label' => 'VAT', 'rate' => 21, 'zero' => true, 'reduced' => 12],
            'LT' => ['label' => 'VAT', 'rate' => 21, 'zero' => true, 'reduced' => 9],
            'LU' => ['label' => 'VAT', 'rate' => 17, 'zero' => true, 'reduced' => 8],
            'MT' => ['label' => 'VAT', 'rate' => 18, 'zero' => true, 'reduced' => 5],
            'NL' => ['label' => 'VAT', 'rate' => 21, 'zero' => true, 'reduced' => 9],
            'PL' => ['label' => 'VAT', 'rate' => 23, 'zero' => true, 'reduced' => 8],
            'PT' => ['label' => 'VAT', 'rate' => 23, 'zero' => true, 'reduced' => 6],
            'RO' => ['label' => 'VAT', 'rate' => 19, 'zero' => true, 'reduced' => 9],
            'SK' => ['label' => 'VAT', 'rate' => 23, 'zero' => true, 'reduced' => 19],
            'SI' => ['label' => 'VAT', 'rate' => 22, 'zero' => true, 'reduced' => 9.5],
            'ES' => ['label' => 'VAT', 'rate' => 21, 'zero' => true, 'reduced' => 10],
            'SE' => ['label' => 'VAT', 'rate' => 25, 'zero' => true, 'reduced' => 12],
            'IS' => ['label' => 'VAT', 'rate' => 24, 'zero' => true, 'reduced' => 11],
            'NO' => ['label' => 'VAT', 'rate' => 25, 'zero' => true, 'reduced' => 15],
            'CH' => ['label' => 'VAT', 'rate' => 8.1, 'zero' => true, 'reduced' => 2.6],
            'LI' => ['label' => 'VAT', 'rate' => 8.1, 'zero' => true],
            'AL' => ['label' => 'VAT', 'rate' => 20, 'zero' => true],
            'BA' => ['label' => 'VAT', 'rate' => 17, 'zero' => true],
            'MK' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'RS' => ['label' => 'VAT', 'rate' => 20, 'zero' => true, 'reduced' => 10],
            'ME' => ['label' => 'VAT', 'rate' => 21, 'zero' => true],
            'MD' => ['label' => 'VAT', 'rate' => 20, 'zero' => true],
            'UA' => ['label' => 'VAT', 'rate' => 20, 'zero' => true, 'reduced' => 7],
            'TR' => ['label' => 'VAT', 'rate' => 20, 'zero' => true, 'reduced' => 10],
            'RU' => ['label' => 'VAT', 'rate' => 20, 'zero' => true, 'reduced' => 10],
            'BY' => ['label' => 'VAT', 'rate' => 20, 'zero' => true],
            'GE' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'AM' => ['label' => 'VAT', 'rate' => 20, 'zero' => true],
            'AZ' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'KZ' => ['label' => 'VAT', 'rate' => 12, 'zero' => true],
            'UZ' => ['label' => 'VAT', 'rate' => 12, 'zero' => true],

            // Americas (national; US/CA use JSON packs)
            'MX' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'BR' => ['label' => 'VAT', 'rate' => 17, 'zero' => true],
            'AR' => ['label' => 'VAT', 'rate' => 21, 'zero' => true, 'reduced' => 10.5],
            'CL' => ['label' => 'VAT', 'rate' => 19, 'zero' => true],
            'CO' => ['label' => 'VAT', 'rate' => 19, 'zero' => true],
            'PE' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'UY' => ['label' => 'VAT', 'rate' => 22, 'zero' => true, 'reduced' => 10],
            'PY' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'BO' => ['label' => 'VAT', 'rate' => 13, 'zero' => true],
            'EC' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'CR' => ['label' => 'VAT', 'rate' => 13, 'zero' => true],
            'PA' => ['label' => 'VAT', 'rate' => 7, 'zero' => true],
            'DO' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'GT' => ['label' => 'VAT', 'rate' => 12, 'zero' => true],
            'HN' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'NI' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'SV' => ['label' => 'VAT', 'rate' => 13, 'zero' => true],
            'JM' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'TT' => ['label' => 'VAT', 'rate' => 12.5, 'zero' => true],

            // Asia / Pacific
            'JP' => ['label' => 'VAT', 'rate' => 10, 'zero' => true, 'reduced' => 8],
            'KR' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'CN' => ['label' => 'VAT', 'rate' => 13, 'zero' => true, 'reduced' => 9],
            'TW' => ['label' => 'VAT', 'rate' => 5, 'zero' => true],
            'TH' => ['label' => 'VAT', 'rate' => 7, 'zero' => true],
            'MY' => ['label' => 'SST', 'rate' => 8, 'zero' => true],
            'ID' => ['label' => 'VAT', 'rate' => 11, 'zero' => true],
            'PH' => ['label' => 'VAT', 'rate' => 12, 'zero' => true],
            'VN' => ['label' => 'VAT', 'rate' => 10, 'zero' => true, 'reduced' => 5],
            'KH' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'LA' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'MM' => ['label' => 'VAT', 'rate' => 5, 'zero' => true],
            'BD' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'PK' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'LK' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'NP' => ['label' => 'VAT', 'rate' => 13, 'zero' => true],
            'IL' => ['label' => 'VAT', 'rate' => 17, 'zero' => true],
            'SA' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'BH' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'OM' => ['label' => 'VAT', 'rate' => 5, 'zero' => true],
            'QA' => ['label' => 'VAT', 'rate' => 5, 'zero' => true],
            'JO' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'EG' => ['label' => 'VAT', 'rate' => 14, 'zero' => true],
            'MA' => ['label' => 'VAT', 'rate' => 20, 'zero' => true, 'reduced' => 10],
            'TN' => ['label' => 'VAT', 'rate' => 19, 'zero' => true],
            'DZ' => ['label' => 'VAT', 'rate' => 19, 'zero' => true],
            'ZA' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'NG' => ['label' => 'VAT', 'rate' => 7.5, 'zero' => true],
            'KE' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'GH' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'TZ' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'UG' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'RW' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'ET' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'MU' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'FJ' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'PG' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],

            // Extra markets (national starters)
            'AD' => ['label' => 'VAT', 'rate' => 4.5, 'zero' => true],
            'AO' => ['label' => 'VAT', 'rate' => 14, 'zero' => true],
            'BS' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'BB' => ['label' => 'VAT', 'rate' => 17.5, 'zero' => true],
            'BZ' => ['label' => 'VAT', 'rate' => 12.5, 'zero' => true],
            'BJ' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'BW' => ['label' => 'VAT', 'rate' => 14, 'zero' => true],
            'BF' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'BI' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'CM' => ['label' => 'VAT', 'rate' => 19.25, 'zero' => true],
            'CV' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'CF' => ['label' => 'VAT', 'rate' => 19, 'zero' => true],
            'TD' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'KM' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'CG' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'CD' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'CI' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'DJ' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'DM' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'TL' => ['label' => 'VAT', 'rate' => 2.5, 'zero' => true],
            'GQ' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'SZ' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'GA' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'GM' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'GN' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'GW' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'GY' => ['label' => 'VAT', 'rate' => 14, 'zero' => true],
            'HT' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'IR' => ['label' => 'VAT', 'rate' => 9, 'zero' => true],
            'KG' => ['label' => 'VAT', 'rate' => 12, 'zero' => true],
            'LB' => ['label' => 'VAT', 'rate' => 11, 'zero' => true],
            'LS' => ['label' => 'VAT', 'rate' => 12, 'zero' => true],
            'LR' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'MG' => ['label' => 'VAT', 'rate' => 20, 'zero' => true],
            'MW' => ['label' => 'VAT', 'rate' => 16.5, 'zero' => true],
            'MV' => ['label' => 'GST', 'rate' => 8, 'zero' => true],
            'ML' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'MR' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'MN' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'MZ' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'NA' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'NE' => ['label' => 'VAT', 'rate' => 19, 'zero' => true],
            'PS' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'SN' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'SC' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'SL' => ['label' => 'GST', 'rate' => 15, 'zero' => true],
            'SO' => ['label' => 'VAT', 'rate' => 5, 'zero' => true],
            'SS' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'SD' => ['label' => 'VAT', 'rate' => 17, 'zero' => true],
            'SR' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'TJ' => ['label' => 'VAT', 'rate' => 14, 'zero' => true],
            'TG' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'TM' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'VE' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'YE' => ['label' => 'VAT', 'rate' => 5, 'zero' => true],
            'ZM' => ['label' => 'VAT', 'rate' => 16, 'zero' => true],
            'ZW' => ['label' => 'VAT', 'rate' => 15, 'zero' => true],
            'AF' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'CU' => ['label' => 'VAT', 'rate' => 10, 'zero' => true],
            'XK' => ['label' => 'VAT', 'rate' => 18, 'zero' => true],
            'MC' => ['label' => 'VAT', 'rate' => 20, 'zero' => true],
            'SM' => ['label' => 'VAT', 'rate' => 17, 'zero' => true],
            'FO' => ['label' => 'VAT', 'rate' => 25, 'zero' => true],
        ];
    }
}
