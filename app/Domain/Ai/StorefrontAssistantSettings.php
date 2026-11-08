<?php

namespace App\Domain\Ai;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Settings\ShopSetting;

/**
 * Storefront shopping assistant (separate from admin Ask AI).
 */
final class StorefrontAssistantSettings
{
    /**
     * @return array<string, mixed>
     */
    public static function forAdmin(): array
    {
        return [
            'enabled' => self::storedEnabled(),
            'timeout' => self::timeout(),
            'fallback_message' => self::fallbackMessage(),
            'icon_type' => self::iconType(),
            'icon_text' => self::iconText(),
            'terms_cms_page_id' => self::termsCmsPageId(),
            'off_topic_message' => self::offTopicMessage(),
        ];
    }

    /**
     * Public boot payload for the storefront widget.
     *
     * @return array<string, mixed>
     */
    public static function forStorefront(): array
    {
        if (! self::enabled() || AiSettings::activeConnection() === null) {
            return ['enabled' => false];
        }

        $terms = self::termsPageMeta();

        return [
            'enabled' => true,
            'icon_type' => self::iconType(),
            'icon_text' => self::iconText(),
            'fallback_message' => self::fallbackMessage(),
            'terms_title' => $terms['title'] ?? 'Terms and conditions',
            'terms_cms_page_id' => $terms['id'] ?? null,
        ];
    }

    public static function storedEnabled(): bool
    {
        return ShopSetting::bool('ai.storefront_enabled', false);
    }

    public static function enabled(): bool
    {
        return AiSettings::enabled() && self::storedEnabled();
    }

    public static function timeout(): int
    {
        return max(10, min(120, (int) ShopSetting::get('ai.storefront_timeout', 45)));
    }

    public static function fallbackMessage(): string
    {
        $msg = trim((string) ShopSetting::get(
            'ai.storefront_fallback',
            'Sorry, I could not find that in our shop. Please try asking about products, orders, shipping, or payment.'
        ));

        return $msg !== ''
            ? $msg
            : 'Sorry, I could not find that in our shop. Please try asking about products, orders, shipping, or payment.';
    }

    public static function offTopicMessage(): string
    {
        $msg = trim((string) ShopSetting::get(
            'ai.storefront_off_topic',
            'I can only help with this shop — products, cart, orders, shipping, and payment. Please ask something store-related.'
        ));

        return $msg !== ''
            ? $msg
            : 'I can only help with this shop — products, cart, orders, shipping, and payment. Please ask something store-related.';
    }

    public static function iconType(): string
    {
        $type = strtolower(trim((string) ShopSetting::get('ai.storefront_icon_type', 'text')));

        return in_array($type, ['text', 'icon'], true) ? $type : 'text';
    }

    public static function iconText(): string
    {
        $text = trim((string) ShopSetting::get('ai.storefront_icon_text', 'Ask AI'));

        return $text !== '' ? $text : 'Ask AI';
    }

    public static function termsCmsPageId(): ?int
    {
        $id = (int) ShopSetting::get('ai.storefront_terms_cms_page_id', 0);
        if ($id > 0) {
            return $id;
        }

        return self::defaultTermsPageId();
    }

    public static function defaultTermsPageId(): ?int
    {
        $page = CmsPage::query()
            ->public()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'terms'))
            ->first();

        if ($page) {
            return (int) $page->id;
        }

        $privacy = CmsPage::query()
            ->public()
            ->whereHas('translations', fn ($q) => $q->where('slug', 'privacy'))
            ->first();

        return $privacy ? (int) $privacy->id : null;
    }

    /**
     * @return array{id: ?int, title: ?string, slug: ?string, body: ?string}
     */
    public static function termsPageContent(): array
    {
        $id = self::termsCmsPageId();
        if (! $id) {
            return [
                'id' => null,
                'title' => 'Terms and conditions',
                'slug' => null,
                'body' => '<p>Please contact the shop for terms and conditions.</p>',
            ];
        }

        $page = CmsPage::query()->with('translations')->find($id);
        $tr = $page?->translation();

        return [
            'id' => $page?->id,
            'title' => $tr?->title ?: 'Terms and conditions',
            'slug' => $tr?->slug,
            'body' => \App\Domain\Security\HtmlSanitizer::clean($tr?->body ?: '<p>Please contact the shop for terms and conditions.</p>'),
        ];
    }

    /**
     * @return array{id: ?int, title: ?string}
     */
    public static function termsPageMeta(): array
    {
        $full = self::termsPageContent();

        return [
            'id' => $full['id'],
            'title' => $full['title'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): void
    {
        if (array_key_exists('enabled', $data)) {
            ShopSetting::put('ai.storefront_enabled', (bool) $data['enabled']);
        }
        if (array_key_exists('timeout', $data)) {
            ShopSetting::put('ai.storefront_timeout', max(10, min(120, (int) $data['timeout'])));
        }
        if (array_key_exists('fallback_message', $data)) {
            ShopSetting::put('ai.storefront_fallback', mb_substr(trim((string) $data['fallback_message']), 0, 500));
        }
        if (array_key_exists('off_topic_message', $data)) {
            ShopSetting::put('ai.storefront_off_topic', mb_substr(trim((string) $data['off_topic_message']), 0, 500));
        }
        if (array_key_exists('icon_type', $data)) {
            $type = strtolower(trim((string) $data['icon_type']));
            ShopSetting::put('ai.storefront_icon_type', in_array($type, ['text', 'icon'], true) ? $type : 'text');
        }
        if (array_key_exists('icon_text', $data)) {
            ShopSetting::put('ai.storefront_icon_text', mb_substr(trim((string) $data['icon_text']), 0, 40));
        }
            if (array_key_exists('terms_cms_page_id', $data)) {
            $id = (int) ($data['terms_cms_page_id'] ?? 0);
            ShopSetting::put('ai.storefront_terms_cms_page_id', $id > 0 ? $id : 0);
        }
    }
}
