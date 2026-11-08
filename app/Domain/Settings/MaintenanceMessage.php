<?php

namespace App\Domain\Settings;

use App\Domain\Security\HtmlSanitizer;

final class MaintenanceMessage
{
    public const LEGACY_PLAIN = 'This shop is temporarily closed for maintenance.';

    public static function defaultHtml(): string
    {
        return <<<'HTML'
<h2>We will be back shortly</h2>
<p>Our shop is temporarily closed while we finish a few updates. Thank you for your patience — we are polishing things so your next visit is smoother.</p>
<ul>
<li>Orders already placed are still being handled</li>
<li>Your account, wishlist, and cart stay safe</li>
<li>We will reopen as soon as everything is ready</li>
</ul>
<p>Need help in the meantime? Reply to your latest order email and our team will follow up.</p>
HTML;
    }

    public static function defaultHeader(): string
    {
        return '<p><strong>Scheduled maintenance</strong> — the shop is offline for a short update.</p>';
    }

    public static function defaultFooter(): string
    {
        return '<p>Please check back soon. Staff can still reach the admin while this page is shown.</p>';
    }

    public static function defaultBanner(): string
    {
        return '<p><strong>Ground update:</strong> We expect to reopen shortly. For urgent order help, reply to your confirmation email.</p>';
    }

    public static function forEditor(?string $stored, ?string $fallback = null): string
    {
        $message = trim((string) $stored);
        if ($message === '' || $message === self::LEGACY_PLAIN) {
            return $fallback ?? self::defaultHtml();
        }

        if (! str_contains($message, '<')) {
            return '<p>'.e($message).'</p>';
        }

        return $message;
    }

    public static function forOptionalEditor(?string $stored, string $fallback): string
    {
        $message = trim((string) $stored);
        if ($message === '') {
            return $fallback;
        }

        if (! str_contains($message, '<')) {
            return '<p>'.e($message).'</p>';
        }

        return $message;
    }

    public static function sanitize(string $html): string
    {
        $clean = HtmlSanitizer::clean($html);

        return self::isEmptyHtml($clean) ? self::defaultHtml() : $clean;
    }

    public static function sanitizeOptional(string $html): string
    {
        $clean = HtmlSanitizer::clean($html);

        return self::isEmptyHtml($clean) ? '' : $clean;
    }

    public static function isEmptyHtml(string $html): bool
    {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '';
    }
}
