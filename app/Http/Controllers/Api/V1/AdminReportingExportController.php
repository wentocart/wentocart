<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\AdminReportingStats;
use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Http\Controllers\Controller;
use GraphQL\Error\UserError;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 */
class AdminReportingExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse|Response
    {
        GraphqlAdminAuth::bind($request);
        if (! Auth::guard('admin')->user()) {
            return response('Unauthenticated.', 401);
        }

        $entity = strtolower(trim((string) $request->query('entity', $request->query('group', 'sales'))));
        $type = trim((string) $request->query('type', ''));
        $args = [
            'type' => $type !== '' ? $type : null,
            'start' => $request->query('start'),
            'end' => $request->query('end'),
            'channel' => $request->query('storeId'),
        ];
        $args = array_filter($args, fn ($v) => $v !== null && $v !== '');

        try {
            $payload = match ($entity) {
                'customers' => AdminReportingStats::viewCustomers($args + ['type' => $args['type'] ?? 'total-customers']),
                'products' => AdminReportingStats::viewProducts($args + ['type' => $args['type'] ?? 'total-sold-quantities']),
                default => AdminReportingStats::viewSales($args + ['type' => $args['type'] ?? 'total-sales']),
            };
        } catch (UserError $e) {
            return response($e->getMessage(), 400);
        }

        $decoded = json_decode((string) ($payload['statistics'] ?? ''), true) ?: [];
        $columns = is_array($decoded['columns'] ?? null) ? $decoded['columns'] : [];
        $records = is_array($decoded['records'] ?? null) ? $decoded['records'] : [];
        if ($columns === [] && array_is_list($decoded)) {
            $records = $decoded;
            $keys = array_keys($records[0] ?? ['value' => null]);
            $columns = array_map(fn ($k) => ['key' => $k, 'label' => (string) $k], $keys);
        }

        $filename = sprintf(
            'reporting-%s-%s-%s.csv',
            $entity,
            preg_replace('/[^a-z0-9\-]+/i', '-', (string) ($payload['type'] ?? $type ?: 'export')) ?: 'export',
            now()->format('Ymd-His')
        );

        return response()->streamDownload(function () use ($columns, $records) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            $headers = array_map(fn ($c) => (string) ($c['label'] ?? $c['key'] ?? ''), $columns);
            if ($headers === []) {
                $headers = ['value'];
            }
            fputcsv($out, $headers);
            foreach ($records as $row) {
                if (! is_array($row)) {
                    fputcsv($out, [(string) $row]);
                    continue;
                }
                $line = [];
                foreach ($columns as $col) {
                    $key = (string) ($col['key'] ?? '');
                    $val = $row[$key] ?? '';
                    if (is_array($val) || is_object($val)) {
                        $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                    }
                    $line[] = $val;
                }
                if ($line === []) {
                    $line = [json_encode($row, JSON_UNESCAPED_UNICODE)];
                }
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
