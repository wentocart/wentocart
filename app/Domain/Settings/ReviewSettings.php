<?php

namespace App\Domain\Settings;

final class ReviewSettings
{
    /** @var list<string> */
    public const WHO_OPTIONS = ['everyone', 'registered', 'verified'];

    /** @var list<string> */
    public const MODERATION_OPTIONS = ['required', 'automatic'];

    /** @var list<string> */
    public const MEDIA_OPTIONS = ['none', 'images', 'video'];

    public static function enabled(): bool
    {
        return ShopSetting::bool('reviews.enabled', true);
    }

    public static function whoCanReview(): string
    {
        $raw = trim((string) ShopSetting::get('reviews.who', 'registered'));

        return in_array($raw, self::WHO_OPTIONS, true) ? $raw : 'registered';
    }

    public static function moderation(): string
    {
        $raw = trim((string) ShopSetting::get('reviews.moderation', 'required'));

        return in_array($raw, self::MODERATION_OPTIONS, true) ? $raw : 'required';
    }

    public static function merchantReplies(): bool
    {
        return ShopSetting::bool('reviews.merchant_replies', true);
    }

    public static function media(): string
    {
        $raw = trim((string) ShopSetting::get('reviews.media', 'images'));

        return in_array($raw, self::MEDIA_OPTIONS, true) ? $raw : 'images';
    }

    public static function minLength(): int
    {
        $value = (int) ShopSetting::get('reviews.min_length', 500);

        return max(0, min(2000, $value));
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return [
            'enabled' => self::enabled(),
            'who' => self::whoCanReview(),
            'moderation' => self::moderation(),
            'merchant_replies' => self::merchantReplies(),
            'media' => self::media(),
            'min_length' => self::minLength(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function storefrontPayload(): array
    {
        return self::payload();
    }
}
