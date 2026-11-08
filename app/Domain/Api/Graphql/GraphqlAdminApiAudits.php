<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Api\Models\AdminApiAudit;
use App\Domain\Database\DbGrammar;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Carbon;

/**
 */
final class GraphqlAdminApiAudits
{
    public static function assertRead(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters = []): array
    {
        self::assertRead();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 20)));
        $q = AdminApiAudit::query()->orderByDesc('id');

        $adminId = (int) ($filters['admin_id'] ?? $filters['adminId'] ?? 0);
        if ($adminId > 0) {
            $q->where('admin_id', $adminId);
        }
        $tokenId = (int) ($filters['token_id'] ?? $filters['tokenId'] ?? 0);
        if ($tokenId > 0) {
            $q->where('token_id', $tokenId);
        }
        $path = trim((string) ($filters['path'] ?? $filters['q'] ?? ''));
        if ($path !== '') {
            $q->where('path', DbGrammar::likeOperator(), '%'.$path.'%');
        }
        $operation = trim((string) ($filters['operation'] ?? ''));
        if ($operation !== '') {
            $q->where('operation', DbGrammar::likeOperator(), '%'.$operation.'%');
        }
        $method = strtoupper(trim((string) ($filters['method'] ?? '')));
        if ($method !== '') {
            $q->where('method', $method);
        }

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())
                ->map(fn (AdminApiAudit $row) => self::map($row))
                ->values()
                ->all(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertRead();
        $row = AdminApiAudit::query()->find($id);

        return $row ? self::map($row, true) : null;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertRead();
        $clean = array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id) => $id > 0)));
        $deleted = $clean === [] ? 0 : AdminApiAudit::query()->whereIn('id', $clean)->delete();

        return ['ok' => true, 'deleted' => (int) $deleted];
    }

    /**
     * @return array{ok: bool, deleted: int, days: int}
     */
    public static function prune(?int $days = null, bool $asAdmin = true): array
    {
        if ($asAdmin) {
            self::assertRead();
        }
        $days = $days !== null && $days > 0 ? $days : (int) config('wento.admin_api_audit_retention_days', 90);
        $days = max(1, min(3650, $days));
        $cutoff = Carbon::now()->subDays($days);
        $deleted = AdminApiAudit::query()->where('created_at', '<', $cutoff)->delete();

        return ['ok' => true, 'deleted' => (int) $deleted, 'days' => $days];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(AdminApiAudit $row, bool $detail = false): array
    {
        $base = [
            'id' => (string) $row->id,
            'admin_id' => $row->admin_id,
            'adminId' => $row->admin_id,
            'token_id' => $row->token_id,
            'tokenId' => $row->token_id,
            'method' => $row->method,
            'path' => $row->path,
            'operation' => $row->operation,
            'status' => $row->status,
            'ip_address' => $row->ip_address,
            'ipAddress' => $row->ip_address,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'createdAt' => optional($row->created_at)?->toIso8601String(),
        ];
        if ($detail) {
            $base['user_agent'] = $row->user_agent;
            $base['request_payload'] = $row->request_payload ?? [];
            $base['response_summary'] = $row->response_summary ?? [];
            $base['requestPayload'] = $base['request_payload'];
            $base['responseSummary'] = $base['response_summary'];
        }

        return $base;
    }
}
