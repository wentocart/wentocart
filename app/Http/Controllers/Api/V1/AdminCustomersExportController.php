<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Domain\Customer\Models\Customer;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminCustomersExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            return response('Unauthenticated.', 401);
        }
        if (! $admin->hasPermission('customers.manage')) {
            return response('Forbidden.', 403);
        }

        $q = Customer::query()->orderByDesc('id');

        $email = trim((string) $request->query('email', ''));
        if ($email !== '') {
            $q->where('email', 'like', '%'.$email.'%');
        }
        $name = trim((string) $request->query('name', $request->query('customer', '')));
        if ($name !== '') {
            $q->where(function ($inner) use ($name) {
                $inner->where('name', 'like', '%'.$name.'%')
                    ->orWhere('first_name', 'like', '%'.$name.'%')
                    ->orWhere('last_name', 'like', '%'.$name.'%');
            });
        }
        if ($request->query->has('is_enabled') || $request->query->has('status')) {
            $raw = $request->query('is_enabled', $request->query('status'));
            if ($raw !== null && $raw !== '') {
                $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($enabled === null) {
                    $enabled = in_array(strtolower((string) $raw), ['1', 'enabled', 'active', 'true'], true);
                }
                $q->where('is_enabled', (bool) $enabled);
            }
        }

        $rows = $q->limit(10000)->get();
        $filename = 'customers-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'id', 'email', 'name', 'first_name', 'last_name', 'phone',
                'is_enabled', 'newsletter', 'created_at',
            ]);
            foreach ($rows as $c) {
                fputcsv($out, [
                    $c->id,
                    (string) $c->email,
                    (string) ($c->name ?? ''),
                    (string) ($c->first_name ?? ''),
                    (string) ($c->last_name ?? ''),
                    (string) ($c->phone ?? ''),
                    $c->is_enabled ? '1' : '0',
                    $c->newsletter ? '1' : '0',
                    optional($c->created_at)?->toIso8601String(),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
