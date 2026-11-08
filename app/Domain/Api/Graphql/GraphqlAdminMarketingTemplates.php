<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Settings\Models\EmailTemplate;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;

/**
 */
final class GraphqlAdminMarketingTemplates
{
    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (
            ! $admin instanceof Admin
            || (! $admin->hasPermission('marketing.newsletter') && ! $admin->hasPermission('marketing.discounts'))
        ) {
            throw new UserError('Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function paginate(array $filters): array
    {
        self::assertManage();
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? $filters['limit'] ?? 10)));
        $q = EmailTemplate::query();

        $name = trim((string) ($filters['name'] ?? ''));
        if ($name !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($name, $op) {
                $inner->where('name', $op, '%'.$name.'%')
                    ->orWhere('code', $op, '%'.$name.'%')
                    ->orWhere('subject', $op, '%'.$name.'%');
            });
        }
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if (in_array($status, ['active', 'inactive', 'draft'], true)) {
            $q->where('is_enabled', $status === 'active');
        }

        $sort = strtolower(trim((string) ($filters['sort'] ?? 'id')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'desc'))) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sort, ['id', 'name', 'created_at', 'subject'], true)) {
            $sort = 'id';
        }
        $q->orderBy($sort, $order);

        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (EmailTemplate $row) => self::map($row))->values()->all(),
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
        self::assertManage();
        $row = EmailTemplate::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function create(array $input): array
    {
        self::assertManage();
        $name = trim((string) ($input['name'] ?? ''));
        $content = (string) ($input['content'] ?? $input['body'] ?? '');
        $status = strtolower(trim((string) ($input['status'] ?? 'active')));
        if ($name === '') {
            throw new UserError('name is required.');
        }
        if (! array_key_exists('content', $input) && ! array_key_exists('body', $input)) {
            throw new UserError('content is required.');
        }
        if ($status === '' || ! in_array($status, ['active', 'inactive', 'draft'], true)) {
            throw new UserError('status must be active, inactive, or draft.');
        }
        $subject = trim((string) ($input['subject'] ?? $name));
        if ($subject === '') {
            $subject = $name;
        }
        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($name);
        }
        $code = Str::limit($code !== '' ? $code : 'template-'.uniqid(), 80, '');
        if (EmailTemplate::query()->where('code', $code)->exists()) {
            $code = $code.'-'.substr(uniqid(), -4);
        }
        $row = EmailTemplate::query()->create([
            'code' => $code,
            'name' => $name,
            'subject' => $subject,
            'body' => $content,
            'is_enabled' => $status === 'active',
            'is_custom' => true,
        ]);

        return self::map($row);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(int $id, array $input): array
    {
        self::assertManage();
        $row = EmailTemplate::query()->find($id);
        if (! $row) {
            throw new UserError('Email template not found.');
        }
        if (array_key_exists('name', $input) && $input['name'] !== null) {
            $name = trim((string) $input['name']);
            if ($name === '') {
                throw new UserError('name cannot be empty.');
            }
            $row->name = $name;
        }
        if (array_key_exists('subject', $input) && $input['subject'] !== null) {
            $subject = trim((string) $input['subject']);
            if ($subject === '') {
                throw new UserError('subject cannot be empty.');
            }
            $row->subject = $subject;
        }
        if (array_key_exists('content', $input) || array_key_exists('body', $input)) {
            $row->body = (string) ($input['content'] ?? $input['body'] ?? $row->body);
        }
        if (array_key_exists('status', $input) && $input['status'] !== null) {
            $status = strtolower(trim((string) $input['status']));
            if (! in_array($status, ['active', 'inactive', 'draft'], true)) {
                throw new UserError('status must be active, inactive, or draft.');
            }
            $row->is_enabled = $status === 'active';
        } elseif (array_key_exists('enabled', $input) && $input['enabled'] !== null) {
            $row->is_enabled = (bool) $input['enabled'];
        }
        $row->save();

        return self::map($row->fresh() ?? $row);
    }

    /**
     * @return array<string, mixed>
     */
    public static function delete(int $id): array
    {
        self::assertManage();
        $row = EmailTemplate::query()->find($id);
        if (! $row) {
            throw new UserError('Email template not found.');
        }
        if (! $row->is_custom && $row->event_code) {
            throw new UserError('System email templates cannot be deleted.');
        }
        $snapshot = self::map($row);
        $row->delete();

        return $snapshot;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one template id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = EmailTemplate::query()->find($id);
            if (! $row || (! $row->is_custom && $row->event_code)) {
                $skipped[] = $id;
                continue;
            }
            $row->delete();
            $deleted[] = $id;
        }

        return [
            'ok' => true,
            'deleted' => $deleted,
            'skipped' => $skipped,
            'updated_count' => count($deleted),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(EmailTemplate $row): array
    {
        $enabled = (bool) $row->is_enabled;

        return [
            'id' => (int) $row->id,

            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'subject' => (string) $row->subject,
            'content' => (string) $row->body,
            'body' => (string) $row->body,
            'status' => $enabled ? 'active' : 'inactive',
            'is_enabled' => $enabled,
            'created_at' => optional($row->created_at)?->toIso8601String(),
        ];
    }
}
