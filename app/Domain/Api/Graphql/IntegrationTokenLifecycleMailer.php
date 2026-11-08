<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Settings\ShopMailer;
use App\Models\Admin;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Generate & regenerate include a signed, 7-day, login-free revoke confirmation link.
 * Plaintext secrets are never emailed.
 */
final class IntegrationTokenLifecycleMailer
{
    public static function generated(Admin $admin, PersonalAccessToken $token): void
    {
        self::send($admin, $token, 'generated', true);
    }

    public static function regenerated(Admin $admin, PersonalAccessToken $token): void
    {
        self::send($admin, $token, 'regenerated', true);
    }

    public static function revoked(Admin $admin, string $tokenName, string $tokenId): void
    {
        $to = trim((string) $admin->email);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $subject = 'Integration token revoked: '.$tokenName;
        $html = self::htmlShell(
            $subject,
            '<p>Your Integration API token <strong>'.e($tokenName).'</strong> (id '.e($tokenId).') was revoked.</p>'
            .'<p>It can no longer authenticate Admin API requests.</p>'
        );
        app(ShopMailer::class)->sendHtml($to, $subject, $html, 'integration_token_revoked');
    }

    protected static function send(Admin $admin, PersonalAccessToken $token, string $action, bool $withRevokeLink): void
    {
        $to = trim((string) $admin->email);
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $name = (string) $token->name;
        $id = (string) $token->id;
        $subject = 'Integration token '.$action.': '.$name;
        $body = '<p>Your Integration API token <strong>'.e($name).'</strong> (id '.e($id).') was '
            .e($action).'.</p>'
            .'<p>The plaintext secret is shown only once in the admin UI / API response and is <strong>not</strong> included in this email.</p>';
        if ($withRevokeLink) {
            $confirmUrl = URL::temporarySignedRoute(
                'integrations.token.revoke.confirm',
                now()->addDays(7),
                ['token' => $token->id]
            );
            $body .= '<p>If you did not expect this, <a href="'.e($confirmUrl).'">confirm revoke of this token</a> '
                .'(opens a confirmation page; scanners cannot revoke by prefetch).</p>';
        }
        $html = self::htmlShell($subject, $body);
        app(ShopMailer::class)->sendHtml($to, $subject, $html, 'integration_token_'.$action);
    }

    protected static function htmlShell(string $title, string $body): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.e($title).'</title></head>'
            .'<body style="font-family:system-ui,sans-serif;line-height:1.5;color:#111">'
            .'<h1 style="font-size:18px">'.e($title).'</h1>'
            .$body
            .'<p style="color:#666;font-size:12px">Wento Admin API · Integration lifecycle</p>'
            .'</body></html>';
    }
}
