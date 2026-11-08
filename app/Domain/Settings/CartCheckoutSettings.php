<?php

namespace App\Domain\Settings;

use App\Domain\Cms\Models\CmsPage;

final class CartCheckoutSettings
{
    public const DEFAULT_EXPIRY_HOURS = 168;

    public const DEFAULT_EXPIRY_MINUTES = 10080;

    public const DEFAULT_MAX_ITEMS = 50;

    /** @var list<string> */
    public const UNAVAILABLE_OPTIONS = ['keep', 'remove'];

    /** @var list<string> */
    public const LAYOUT_OPTIONS = ['one_page', 'stepped'];

    public static function cartExpiryMinutes(): int
    {
        $stored = ShopSetting::get('checkout.cart_expiry_minutes', null);
        if ($stored !== null && $stored !== '') {
            return self::clampInt($stored, 1, 525600, self::DEFAULT_EXPIRY_MINUTES);
        }

        $hours = self::clampInt(ShopSetting::get('checkout.cart_expiry_hours', self::DEFAULT_EXPIRY_HOURS), 1, 8760, self::DEFAULT_EXPIRY_HOURS);

        return $hours * 60;
    }

    public static function cartExpiryHours(): int
    {
        return max(1, (int) ceil(self::cartExpiryMinutes() / 60));
    }

    public static function maxCartItems(): int
    {
        return self::clampInt(ShopSetting::get('checkout.max_cart_items', self::DEFAULT_MAX_ITEMS), 1, 500, self::DEFAULT_MAX_ITEMS);
    }

    public static function couponsEnabled(): bool
    {
        return ShopSetting::bool('checkout.coupons_enabled', true);
    }

    public static function termsRequired(): bool
    {
        return ShopSetting::bool('checkout.terms_required', false);
    }

    public static function termsCmsPageId(): ?int
    {
        $id = (int) ShopSetting::get('checkout.terms_cms_page_id', 0);

        return $id > 0 ? $id : null;
    }

    /**
     * @return array{id: ?int, title: string, slug: string}
     */
    public static function termsPage(): array
    {
        $id = self::termsCmsPageId();
        if (! $id) {
            return ['id' => null, 'title' => '', 'slug' => ''];
        }
        $page = CmsPage::query()->find($id);
        $translation = $page?->translation();
        $slug = trim((string) ($translation?->slug ?? ''));

        return [
            'id' => $page ? (int) $page->id : null,
            'title' => trim((string) ($translation?->title ?? '')),
            'slug' => $slug,
        ];
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

                return [
                    'id' => (int) $page->id,
                    'title' => $translation?->title ?: ('Page #'.$page->id),
                    'slug' => (string) ($translation?->slug ?: ''),
                ];
            })
            ->values()
            ->all();
    }

    public static function stockValidation(): bool
    {
        return ShopSetting::bool('checkout.stock_validation', true);
    }

    public static function unavailableItems(): string
    {
        $raw = trim((string) ShopSetting::get('checkout.unavailable_items', 'keep'));

        return in_array($raw, self::UNAVAILABLE_OPTIONS, true) ? $raw : 'keep';
    }

    public static function layout(): string
    {
        $raw = trim((string) ShopSetting::get('checkout.layout', 'one_page'));

        return in_array($raw, self::LAYOUT_OPTIONS, true) ? $raw : 'one_page';
    }

    public static function giftWrapEnabled(): bool
    {
        return ShopSetting::bool('checkout.gift_wrap_enabled', false);
    }

    public static function giftWrapFee(): float
    {
        return max(0, round((float) ShopSetting::get('checkout.gift_wrap_fee', 0), 2));
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'cart_expiry_minutes' => self::cartExpiryMinutes(),
            'max_cart_items' => self::maxCartItems(),
            'coupons_enabled' => self::couponsEnabled(),
            'terms_required' => self::termsRequired(),
            'terms_cms_page_id' => self::termsCmsPageId(),
            'cms_pages' => self::cmsPageChoices(),
            'stock_validation' => self::stockValidation(),
            'unavailable_items' => self::unavailableItems(),
            'layout' => self::layout(),
            'gift_wrap_enabled' => self::giftWrapEnabled(),
            'gift_wrap_fee' => self::giftWrapFee(),
            'customer_notes_enabled' => OrderSettings::customerNotesEnabled(),
            'guest_checkout' => CustomerSettings::guestCheckout(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        $terms = self::termsPage();

        return [
            'cart_expiry_minutes' => self::cartExpiryMinutes(),
            'max_cart_items' => self::maxCartItems(),
            'coupons_enabled' => self::couponsEnabled(),
            'terms_required' => self::termsRequired(),
            'terms_page_slug' => $terms['slug'],
            'terms_page_title' => $terms['title'],
            'stock_validation' => self::stockValidation(),
            'unavailable_items' => self::unavailableItems(),
            'layout' => self::layout(),
            'gift_wrap_enabled' => self::giftWrapEnabled(),
            'gift_wrap_fee' => self::giftWrapFee(),
        ];
    }

    protected static function clampInt(mixed $raw, int $min, int $max, int $default): int
    {
        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }
}
