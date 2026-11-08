<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminUsersExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->isSuperAdmin() && ! $admin->hasPermission('settings.manage')) {
            return response('Forbidden.', 403);
        }

        $rows = Admin::query()->with('roles:id,name')->orderByDesc('id')->limit(5000)->get();
        $filename = 'users-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'name', 'email', 'is_enabled', 'role', 'created_at']);
            foreach ($rows as $user) {
                fputcsv($out, [
                    $user->id,
                    (string) $user->name,
                    (string) $user->email,
                    $user->is_enabled ? '1' : '0',
                    (string) ($user->roles->first()?->name ?? ''),
                    optional($user->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
