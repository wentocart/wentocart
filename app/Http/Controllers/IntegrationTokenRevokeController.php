<?php

namespace App\Http\Controllers;

use App\Domain\Api\Graphql\IntegrationTokenLifecycleMailer;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 */
class IntegrationTokenRevokeController extends Controller
{
    public function confirm(Request $request, int $token): Response
    {
        if (! $request->hasValidSignature()) {
            return response(self::page('Invalid or expired link', '<p>This revoke link is invalid or has expired.</p>'), 403);
        }

        $row = PersonalAccessToken::query()->whereKey($token)->first();
        if (! $row || $row->tokenable_type !== Admin::class) {
            return response(self::page('Token already gone', '<p>This Integration token is no longer active.</p>'), 404);
        }

        $action = route('integrations.token.revoke.submit', ['token' => $token]);
        $query = $request->getQueryString();
        if ($query) {
            $action .= (str_contains($action, '?') ? '&' : '?').$query;
        }

        $body = '<p>Revoke Integration token <strong>'.e((string) $row->name).'</strong> (id '.e((string) $row->id).')?</p>'
            .'<p>This cannot be undone. A new token must be issued afterward.</p>'
            .'<form method="post" action="'.e($action).'">'
            .'<input type="hidden" name="_token" value="'.e(csrf_token()).'">'
            .'<button type="submit" style="background:#b91c1c;color:#fff;border:0;padding:10px 16px;border-radius:6px;cursor:pointer">'
            .'Yes, revoke token</button>'
            .'</form>';

        return response(self::page('Confirm token revoke', $body));
    }

    public function submit(Request $request, int $token): Response
    {
        if (! $request->hasValidSignature()) {
            return response(self::page('Invalid or expired link', '<p>This revoke link is invalid or has expired.</p>'), 403);
        }

        $row = PersonalAccessToken::query()->whereKey($token)->first();
        if (! $row || $row->tokenable_type !== Admin::class) {
            return response(self::page('Token already gone', '<p>This Integration token is no longer active.</p>'), 404);
        }

        $admin = Admin::query()->find($row->tokenable_id);
        $name = (string) $row->name;
        $id = (string) $row->id;
        $row->delete();
        if ($admin instanceof Admin) {
            IntegrationTokenLifecycleMailer::revoked($admin, $name, $id);
        }

        return response(self::page(
            'Token revoked',
            '<p>Integration token <strong>'.e($name).'</strong> has been revoked.</p>'
        ));
    }

    protected static function page(string $title, string $body): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<title>'.e($title).'</title></head>'
            .'<body style="font-family:system-ui,sans-serif;max-width:520px;margin:40px auto;padding:0 16px;line-height:1.5">'
            .'<h1 style="font-size:20px">'.e($title).'</h1>'
            .$body
            .'</body></html>';
    }
}
