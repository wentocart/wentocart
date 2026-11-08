<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Api\Graphql\AdminReportingStats;
use App\Domain\Api\Graphql\GraphqlAdminAuth;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin REST Reporting REST:
 * GET /api/v1/console/reporting/stats|sales|customers|products[+ /view|/export]
 */
class AdminReportingController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        return $this->stats(fn (array $args) => AdminReportingStats::overview($args), $request);
    }

    public function sales(Request $request): JsonResponse
    {
        return $this->stats(fn (array $args) => AdminReportingStats::sales($args), $request, 'total-sales');
    }

    public function customers(Request $request): JsonResponse
    {
        return $this->stats(fn (array $args) => AdminReportingStats::customers($args), $request, 'total-customers');
    }

    public function products(Request $request): JsonResponse
    {
        return $this->stats(fn (array $args) => AdminReportingStats::products($args), $request, 'total-sold-quantities');
    }

    public function viewSales(Request $request): JsonResponse
    {
        return $this->view(fn (array $args) => AdminReportingStats::viewSales($args), $request, 'total-sales');
    }

    public function viewCustomers(Request $request): JsonResponse
    {
        return $this->view(fn (array $args) => AdminReportingStats::viewCustomers($args), $request, 'total-customers');
    }

    public function viewProducts(Request $request): JsonResponse
    {
        return $this->view(fn (array $args) => AdminReportingStats::viewProducts($args), $request, 'total-sold-quantities');
    }

    public function exportSales(Request $request): StreamedResponse|Response
    {
        return $this->export($request, 'sales', 'total-sales');
    }

    public function exportCustomers(Request $request): StreamedResponse|Response
    {
        return $this->export($request, 'customers', 'total-customers');
    }

    public function exportProducts(Request $request): StreamedResponse|Response
    {
        return $this->export($request, 'products', 'total-sold-quantities');
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $resolver
     */
    protected function stats(callable $resolver, Request $request, ?string $defaultType = null): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        try {
            return response()->json($this->decodeStatistics($resolver($this->args($request, $defaultType))));
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $resolver
     */
    protected function view(callable $resolver, Request $request, string $defaultType): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        try {
            $payload = $this->decodeStatistics($resolver($this->args($request, $defaultType)));
            $table = is_array($payload['statistics'] ?? null) ? $payload['statistics'] : [];

            return response()->json([
                'entity' => $payload['entity'] ?? null,
                'type' => $payload['type'] ?? $defaultType,
                'dateRange' => $payload['dateRange'] ?? null,
                'columns' => $table['columns'] ?? [],
                'records' => $table['records'] ?? (array_is_list($table) ? $table : []),
                'statistics' => $table,
            ]);
        } catch (UserError $e) {
            return $this->userError($e);
        }
    }

    protected function export(Request $request, string $entity, string $defaultType): StreamedResponse|Response
    {
        $request->query->set('entity', $entity);
        if (! $request->query->has('type') || trim((string) $request->query('type')) === '') {
            $request->query->set('type', $defaultType);
        }

        return app(AdminReportingExportController::class)($request);
    }

    /**
     * @return array<string, mixed>
     */
    protected function args(Request $request, ?string $defaultType = null): array
    {
        $type = trim((string) $request->query('type', ''));
        $args = [
            'type' => $type !== '' ? $type : $defaultType,
            'start' => $request->query('start'),
            'end' => $request->query('end'),
            'channel' => $request->query('storeId'),
        ];

        return array_filter($args, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function decodeStatistics(array $payload): array
    {
        if (is_string($payload['statistics'] ?? null)) {
            $decoded = json_decode((string) $payload['statistics'], true);
            $payload['statistics'] = is_array($decoded) ? $decoded : [];
        }

        return $payload;
    }

    protected function guard(Request $request): ?JsonResponse
    {
        GraphqlAdminAuth::bind($request);
        if (! Auth::guard('admin')->user() instanceof Admin) {
            return response()->json(['message' => 'Unauthenticated.', 'error' => 'unauthenticated'], 401);
        }

        return null;
    }

    protected function userError(UserError $e, int $default = 422): JsonResponse
    {
        $msg = $e->getMessage();
        $status = match (true) {
            str_contains(strtolower($msg), 'unauthenticated') => 401,
            str_contains(strtolower($msg), 'forbidden') => 403,
            default => $default,
        };

        return response()->json([
            'message' => $msg,
            'error' => $status === 401 ? 'unauthenticated' : 'validation',
        ], $status);
    }
}
