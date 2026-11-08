<?php

namespace App\Domain\Localization;

/**
 * Country postal formats for checkout and saved addresses.
 * A country with no postal system stays optional. Every other country requires a code in that country's shape.
 */
final class PostalCode
{
    /**
     * @return array{required: bool, pattern: string, example: string}
     */
    public static function spec(?string $countryCode): array
    {
        $code = strtoupper(trim((string) $countryCode));
        if (isset(self::NONE[$code])) {
            return ['required' => false, 'pattern' => '', 'example' => ''];
        }
        if ($code === '') {
            return ['required' => true, 'pattern' => self::GENERIC, 'example' => ''];
        }
        if (isset(self::FORMATS[$code])) {
            return ['required' => true, ...self::FORMATS[$code]];
        }

        return ['required' => true, 'pattern' => self::GENERIC, 'example' => ''];
    }

    /**
     * @return array{required: bool, pattern: string, example: string}
     */
    public static function client(?string $countryCode): array
    {
        $spec = self::spec($countryCode);
        if ($spec['pattern'] === '' && ! $spec['required']) {
            return ['required' => false, 'pattern' => '', 'example' => ''];
        }

        return $spec;
    }

    /**
     * @return list<string|\Closure>
     */
    public static function rules(string $mode, ?string $countryCode): array
    {
        $spec = self::spec($countryCode);
        $required = match ($mode) {
            'optional' => false,
            'required' => true,
            default => $spec['required'],
        };

        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:32',
            function (string $attribute, mixed $value, \Closure $fail) use ($spec): void {
                $message = self::invalidMessage($spec, $value);
                if ($message !== null) {
                    $fail($message);
                }
            },
        ];
    }

    /**
     * @param  array{required: bool, pattern: string, example: string}  $spec
     */
    public static function invalidMessage(array $spec, mixed $value): ?string
    {
        $code = trim((string) $value);
        if ($code === '' || $spec['pattern'] === '') {
            return null;
        }
        $normalized = preg_replace('/\s+/', ' ', $code) ?? $code;
        if (preg_match('~'.$spec['pattern'].'~i', $normalized) === 1) {
            return null;
        }
        if ($spec['example'] !== '') {
            return 'Enter a valid postal code, for example '.$spec['example'].'.';
        }

        return 'Enter a valid postal code for this country.';
    }

    private const GENERIC = '^[A-Za-z0-9][A-Za-z0-9 \\-]{1,11}$';

    /** Countries that do not use postal codes. */
    private const NONE = [
        'AE' => true, 'AG' => true, 'AO' => true, 'AW' => true, 'BF' => true, 'BI' => true,
        'BJ' => true, 'BO' => true, 'BS' => true, 'BW' => true, 'BZ' => true, 'CD' => true,
        'CF' => true, 'CG' => true, 'CI' => true, 'CK' => true, 'CM' => true, 'DJ' => true,
        'DM' => true, 'ER' => true, 'FJ' => true, 'GD' => true, 'GH' => true, 'GM' => true,
        'GN' => true, 'GQ' => true, 'GY' => true, 'HK' => true, 'KI' => true, 'KM' => true,
        'KN' => true, 'KP' => true, 'LC' => true, 'ML' => true, 'MO' => true, 'MR' => true,
        'MW' => true, 'NR' => true, 'NU' => true, 'QA' => true, 'RW' => true, 'SB' => true,
        'SC' => true, 'SL' => true, 'SO' => true, 'SR' => true, 'ST' => true, 'SY' => true,
        'TF' => true, 'TK' => true, 'TL' => true, 'TO' => true, 'TV' => true, 'UG' => true,
        'VU' => true, 'YE' => true, 'ZW' => true,
    ];

    /** @var array<string, array{pattern: string, example: string}> */
    private const FORMATS = [
        'US' => ['pattern' => '^\\d{5}(-\\d{4})?$', 'example' => '12345'],
        'CA' => ['pattern' => '^[ABCEGHJ-NPRSTVXY]\\d[ABCEGHJ-NPRSTV-Z][ ]?\\d[ABCEGHJ-NPRSTV-Z]\\d$', 'example' => 'K1A 0B1'],
        'GB' => ['pattern' => '^(GIR 0AA|[A-Z]{1,2}\\d[A-Z\\d]? ?\\d[A-Z]{2})$', 'example' => 'SW1A 1AA'],
        'IN' => ['pattern' => '^\\d{6}$', 'example' => '110001'],
        'AU' => ['pattern' => '^\\d{4}$', 'example' => '2000'],
        'NZ' => ['pattern' => '^\\d{4}$', 'example' => '6011'],
        'DE' => ['pattern' => '^\\d{5}$', 'example' => '10115'],
        'FR' => ['pattern' => '^\\d{5}$', 'example' => '75001'],
        'IT' => ['pattern' => '^\\d{5}$', 'example' => '00118'],
        'ES' => ['pattern' => '^\\d{5}$', 'example' => '28001'],
        'NL' => ['pattern' => '^\\d{4} ?[A-Z]{2}$', 'example' => '1012 AB'],
        'BE' => ['pattern' => '^\\d{4}$', 'example' => '1000'],
        'AT' => ['pattern' => '^\\d{4}$', 'example' => '1010'],
        'CH' => ['pattern' => '^\\d{4}$', 'example' => '8001'],
        'SE' => ['pattern' => '^\\d{3} ?\\d{2}$', 'example' => '111 22'],
        'NO' => ['pattern' => '^\\d{4}$', 'example' => '0150'],
        'DK' => ['pattern' => '^\\d{4}$', 'example' => '1050'],
        'FI' => ['pattern' => '^\\d{5}$', 'example' => '00100'],
        'PL' => ['pattern' => '^\\d{2}-\\d{3}$', 'example' => '00-001'],
        'PT' => ['pattern' => '^\\d{4}-?\\d{3}$', 'example' => '1000-001'],
        'IE' => ['pattern' => '^[AC-FHKNPRTV-Y]\\d{2} ?[0-9AC-FHKNPRTV-Y]{4}$', 'example' => 'D02 AF30'],
        'JP' => ['pattern' => '^\\d{3}-?\\d{4}$', 'example' => '100-0001'],
        'SG' => ['pattern' => '^\\d{6}$', 'example' => '018956'],
        'BR' => ['pattern' => '^\\d{5}-?\\d{3}$', 'example' => '01310-100'],
        'MX' => ['pattern' => '^\\d{5}$', 'example' => '01000'],
        'CN' => ['pattern' => '^\\d{6}$', 'example' => '100000'],
        'ZA' => ['pattern' => '^\\d{4}$', 'example' => '8001'],
    ];
}
