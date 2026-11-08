<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Auth\Models\Role;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminRolesExportController extends Controller
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

        $rows = Role::query()->withCount('permissions')->orderByDesc('id')->limit(5000)->get();
        $filename = 'roles-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'name', 'slug', 'permissions_count', 'created_at']);
            foreach ($rows as $role) {
                fputcsv($out, [
                    $role->id,
                    (string) $role->name,
                    (string) ($role->slug ?? ''),
                    (int) $role->permissions_count,
                    optional($role->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
