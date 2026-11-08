<?php

namespace App\Domain\Marketplace;

use App\Domain\Settings\Models\Setting;

/**
 */
final class MarketplaceLandingSettings
{
    public const KEY = 'marketplace.landing_page';

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $raw = Setting::getValue(self::KEY, '');
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $data = is_array($decoded) ? $decoded : [];

        return array_merge(self::defaults(), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): array
    {
        $merged = array_merge(self::all(), $data);
        // Keep only known keys.
        $clean = [];
        foreach (array_keys(self::defaults()) as $key) {
            if (array_key_exists($key, $merged)) {
                $clean[$key] = $merged[$key];
            }
        }
        Setting::setValue(self::KEY, json_encode($clean, JSON_UNESCAPED_UNICODE));

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'banner_title' => 'Sell on our marketplace',
            'banner_description' => 'Open your shop and reach customers worldwide.',
            'banner_button_title' => 'Become a Seller',
            'banner_image' => '',
            'community_count' => '',
            'business_hour' => '24x7',
            'payment_duration' => '7',
            'serviceable_pincode' => '1000',
            'features_heading' => 'Why sell with us',
            'features_description' => 'Tools that help sellers grow.',
            'features_image' => '',
            'feature_boxes' => [
                ['icon' => '', 'title' => 'Easy onboarding', 'description' => 'Start selling in minutes.'],
                ['icon' => '', 'title' => 'Secure payouts', 'description' => 'Get paid on schedule.'],
                ['icon' => '', 'title' => 'Powerful catalog', 'description' => 'Manage products at scale.'],
                ['icon' => '', 'title' => 'Growth tools', 'description' => 'Discounts, AI and more.'],
            ],
            'journey_heading' => 'Seller journey',
            'journey_description' => 'Five steps to your first sale.',
            'journey_steps' => [
                ['icon' => '', 'title' => 'Register', 'description' => 'Create your seller account.'],
                ['icon' => '', 'title' => 'Get approved', 'description' => 'We review your shop.'],
                ['icon' => '', 'title' => 'Add products', 'description' => 'List what you sell.'],
                ['icon' => '', 'title' => 'Receive orders', 'description' => 'Fulfil with your carriers.'],
                ['icon' => '', 'title' => 'Get paid', 'description' => 'Request payouts anytime.'],
            ],
        ];
    }
}
