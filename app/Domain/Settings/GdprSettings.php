<?php

namespace App\Domain\Settings;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Localization\Models\Language;

final class GdprSettings
{
    public const POSITIONS = ['bottom_left', 'bottom_right', 'top_left', 'top_right', 'center'];

    /** Wento legacy `completed` kept; uses pending/processing/declined/approved/revoked. */
    public const STATUSES = ['pending', 'processing', 'declined', 'approved', 'revoked', 'completed'];

    public const TYPES = ['delete'];

    /** Shop REST GraphQL also documents update requests. */
    public const GRAPHQL_TYPES = ['delete', 'update'];

    /** Statuses that mean the request was already finalized (process is refused). */
    public const TERMINAL_STATUSES = ['approved', 'completed', 'revoked', 'declined'];

    /** @var list<string> */
    public const COPY_KEYS = [
        'agreement_title',
        'cookie_title',
        'strictly_necessary',
        'basic',
        'experience',
        'measurements',
        'targeting',
    ];

    public static function enabled(): bool
    {
        return ShopSetting::bool('gdpr.enabled', false);
    }

    public static function agreementEnabled(): bool
    {
        return self::enabled() && ShopSetting::bool('gdpr.agreement_enabled', false);
    }

    public static function agreementLabel(): string
    {
        return self::localized('agreement_title');
    }

    public static function cookieNoticeEnabled(): bool
    {
        return self::enabled() && ShopSetting::bool('gdpr.cookie_notice', false);
    }

    public static function cookiePosition(): string
    {
        $value = (string) ShopSetting::get('gdpr.cookie_position', 'bottom_left');

        return in_array($value, self::POSITIONS, true) ? $value : 'bottom_left';
    }

    public static function cookieTitle(): string
    {
        return self::localized('cookie_title');
    }

    public static function agreementPageId(): ?int
    {
        return self::pageIdFor('gdpr.agreement_pages', 'gdpr.agreement_page_id', Language::defaultCode());
    }

    public static function cookiePageId(): ?int
    {
        return self::pageIdFor('gdpr.cookie_pages', 'gdpr.cookie_page_id', Language::defaultCode());
    }

    /**
     * @return array<string, int|null>
     */
    public static function agreementPages(): array
    {
        return self::pageMap('gdpr.agreement_pages', 'gdpr.agreement_page_id');
    }

    /**
     * @return array<string, int|null>
     */
    public static function cookiePages(): array
    {
        return self::pageMap('gdpr.cookie_pages', 'gdpr.cookie_page_id');
    }

    /**
     * @return array<string, string>
     */
    public static function cookieCategories(): array
    {
        return [
            'strictly_necessary' => self::localized('strictly_necessary'),
            'basic' => self::localized('basic'),
            'experience' => self::localized('experience'),
            'measurements' => self::localized('measurements'),
            'targeting' => self::localized('targeting'),
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function adminCopy(): array
    {
        $saved = self::savedCopy();
        $default = Language::defaultCode();
        $codes = Language::query()->enabled()->orderBy('position')->pluck('code')->all();
        if ($codes === []) {
            $codes = [$default];
        }
        $out = [];
        foreach ($codes as $code) {
            $row = is_array($saved[$code] ?? null) ? $saved[$code] : [];
            $out[$code] = [];
            foreach (self::COPY_KEYS as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value === '' && $code === $default) {
                    $value = self::legacy($key);
                }
                $out[$code][$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'enabled' => self::enabled(),
            'agreement_enabled' => ShopSetting::bool('gdpr.agreement_enabled', false),
            'agreement_page_id' => self::agreementPageId(),
            'agreement_pages' => self::agreementPages(),
            'cookie_notice' => ShopSetting::bool('gdpr.cookie_notice', false),
            'cookie_position' => self::cookiePosition(),
            'cookie_page_id' => self::cookiePageId(),
            'cookie_pages' => self::cookiePages(),
            'copy' => self::adminCopy(),
            'cms_pages' => self::cmsPageChoices(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return [
            'enabled' => self::enabled(),
            'agreement_enabled' => self::agreementEnabled(),
            'agreement_title' => self::agreementLabel(),
            'agreement_page' => self::pageLink(self::pageIdFor('gdpr.agreement_pages', 'gdpr.agreement_page_id')),
            'cookie_notice' => self::cookieNoticeEnabled(),
            'cookie_position' => self::cookiePosition(),
            'cookie_title' => self::cookieTitle(),
            'cookie_page' => self::pageLink(self::pageIdFor('gdpr.cookie_pages', 'gdpr.cookie_page_id')),
            'categories' => self::cookieCategories(),
        ];
    }

    /**
     * @param  array<string, mixed>  $copy
     * @return array<string, array<string, string>>
     */
    public static function normalizeCopy(array $copy): array
    {
        $allowed = array_flip(Language::query()->enabled()->pluck('code')->all());
        $out = [];
        foreach ($copy as $code => $row) {
            $code = strtolower(trim((string) $code));
            if ($code === '' || ! is_array($row)) {
                continue;
            }
            if ($allowed !== [] && ! isset($allowed[$code])) {
                continue;
            }
            $out[$code] = [];
            foreach (self::COPY_KEYS as $key) {
                $out[$code][$key] = mb_substr(trim((string) ($row[$key] ?? '')), 0, $key === 'agreement_title' || $key === 'cookie_title' ? 255 : 500);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $pages
     * @return array<string, int>
     */
    public static function normalizePageMap(array $pages): array
    {
        $allowed = array_flip(Language::query()->enabled()->pluck('code')->all());
        $out = [];
        foreach ($pages as $code => $id) {
            $code = strtolower(trim((string) $code));
            if ($code === '' || ($allowed !== [] && ! isset($allowed[$code]))) {
                continue;
            }
            $id = (int) $id;
            if ($id > 0) {
                $out[$code] = $id;
            }
        }

        return $out;
    }

    /**
     * @return array<string, int|null>
     */
    private static function pageMap(string $jsonKey, string $legacyKey): array
    {
        $saved = ShopSetting::json($jsonKey, []);
        $saved = is_array($saved) ? $saved : [];
        $codes = Language::query()->enabled()->orderBy('position')->pluck('code')->all();
        if ($codes === []) {
            $codes = [Language::defaultCode()];
        }
        $legacy = (int) ShopSetting::get($legacyKey, 0);
        $out = [];
        foreach ($codes as $code) {
            $id = (int) ($saved[$code] ?? 0);
            if ($id <= 0 && $code === Language::defaultCode()) {
                $id = $legacy;
            }
            $out[$code] = $id > 0 ? $id : null;
        }

        return $out;
    }

    private static function pageIdFor(string $jsonKey, string $legacyKey, ?string $locale = null): ?int
    {
        $map = self::pageMap($jsonKey, $legacyKey);
        $locale ??= Language::currentCode();
        $id = (int) ($map[$locale] ?? 0);
        if ($id > 0) {
            return $id;
        }
        $fallback = (int) ($map[Language::defaultCode()] ?? 0);

        return $fallback > 0 ? $fallback : null;
    }

    /**
     * @return list<array{id: int, title: string, slug: string}>
     */
    public static function cmsPageChoices(): array
    {
        return CmsPage::query()
            ->with('translations')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(function (CmsPage $page) {
                $translation = $page->translation();
                $titles = [];
                foreach ($page->translations as $row) {
                    $titles[(string) $row->language_code] = (string) $row->title;
                }

                return [
                    'id' => (int) $page->id,
                    'title' => $translation?->title ?: ('Page #'.$page->id),
                    'slug' => (string) ($translation?->slug ?: ''),
                    'titles' => $titles,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function savedCopy(): array
    {
        $saved = ShopSetting::json('gdpr.copy', []);

        return is_array($saved) ? $saved : [];
    }

    private static function localized(string $key): string
    {
        $saved = self::savedCopy();
        $locale = Language::currentCode();
        $value = trim((string) ($saved[$locale][$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
        $fallback = trim((string) ($saved[Language::defaultCode()][$key] ?? ''));
        if ($fallback !== '') {
            return $fallback;
        }

        return self::legacy($key);
    }

    private static function legacy(string $key): string
    {
        $stored = match ($key) {
            'agreement_title' => trim((string) ShopSetting::get('gdpr.agreement_label', '')),
            'cookie_title' => trim((string) ShopSetting::get('gdpr.cookie_title', '')),
            'strictly_necessary' => trim((string) ShopSetting::get('gdpr.cookie_strictly_necessary', '')),
            'basic' => trim((string) ShopSetting::get('gdpr.cookie_basic', '')),
            'experience' => trim((string) ShopSetting::get('gdpr.cookie_experience', '')),
            'measurements' => trim((string) ShopSetting::get('gdpr.cookie_measurements', '')),
            'targeting' => trim((string) ShopSetting::get('gdpr.cookie_targeting', '')),
            default => '',
        };
        if ($stored !== '') {
            return $stored;
        }

        return self::defaults()[$key] ?? '';
    }

    /**
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        return [
            'agreement_title' => 'I agree with the terms and conditions',
            'cookie_title' => 'Cookie Block',
            'strictly_necessary' => 'Cookies the shop needs for the cart, sign-in, and checkout.',
            'basic' => 'Cookies that remember basic choices such as language.',
            'experience' => 'Cookies that personalize what you see in the shop.',
            'measurements' => 'Cookies that measure traffic and how the shop is used.',
            'targeting' => 'Cookies used for personalized marketing and ads.',
        ];
    }

    /**
     * @return array{title: string, slug: string}
     */
    private static function pageLink(?int $id): array
    {
        if (! $id) {
            return ['title' => '', 'slug' => ''];
        }
        $page = CmsPage::query()->with('translations')->find($id);
        $translation = $page?->translation();

        return [
            'title' => trim((string) ($translation?->title ?? '')),
            'slug' => trim((string) ($translation?->slug ?? '')),
        ];
    }
}
