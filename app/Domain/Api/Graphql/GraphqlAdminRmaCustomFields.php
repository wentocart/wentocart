<?php

namespace App\Domain\Api\Graphql;

use App\Domain\Database\DbGrammar;
use App\Domain\Order\Models\ReturnCustomField;
use App\Models\Admin;
use GraphQL\Error\UserError;
use Illuminate\Support\Str;

/**
 */
final class GraphqlAdminRmaCustomFields
{
    /** @var list<string> */
    private const TYPES = ['text', 'textarea', 'select', 'multiselect', 'checkbox', 'radio'];

    public static function assertManage(): Admin
    {
        $admin = auth('admin')->user();
        if (! $admin instanceof Admin || ! $admin->hasPermission('orders.manage')) {
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
        $q = ReturnCustomField::query();
        $term = trim((string) ($filters['q'] ?? $filters['label'] ?? ''));
        if ($term !== '') {
            $op = DbGrammar::likeOperator();
            $q->where(function ($inner) use ($term, $op) {
                $inner->where('code', $op, '%'.$term.'%')
                    ->orWhere('label', $op, '%'.$term.'%');
            });
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== null && $filters['status'] !== '') {
            $q->where('is_enabled', ((int) $filters['status']) === 1);
        }
        $sort = strtolower(trim((string) ($filters['sort'] ?? 'position')));
        $order = strtolower(trim((string) ($filters['order'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
        $column = in_array($sort, ['id', 'code', 'label', 'position', 'created_at'], true) ? $sort : 'position';
        $q->orderBy($column, $order)->orderBy('id');
        $paginator = $q->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginator->items())->map(fn (ReturnCustomField $row) => self::map($row))->values()->all(),
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
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    public static function list(array $args): array
    {
        $page = self::paginate(array_merge($args, [
            'page' => 1,
            'per_page' => max(1, min(200, (int) ($args['limit'] ?? 50))),
        ]));

        return $page['data'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(int $id): ?array
    {
        self::assertManage();
        $row = ReturnCustomField::query()->find($id);

        return $row ? self::map($row) : null;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function create(array $args): array
    {
        self::assertManage();
        $label = trim((string) ($args['label'] ?? ''));
        if ($label === '') {
            throw new UserError('label is required.');
        }
        $type = strtolower(trim((string) ($args['type'] ?? 'text')));
        if (! in_array($type, self::TYPES, true)) {
            throw new UserError('type must be one of: '.implode(', ', self::TYPES).'.');
        }
        $code = trim((string) ($args['code'] ?? ''));
        if ($code === '') {
            $code = Str::slug($label, '_');
        }
        $code = Str::limit($code !== '' ? $code : 'field_'.uniqid(), 80, '');
        if (ReturnCustomField::query()->where('code', $code)->exists()) {
            $code = $code.'_'.substr(uniqid(), -4);
        }
        $options = self::parseOptions($args);
        $isRequired = false;
        if (array_key_exists('isRequired', $args) && $args['isRequired'] !== null) {
            $isRequired = ((int) $args['isRequired']) === 1;
        } elseif (array_key_exists('is_required', $args) && $args['is_required'] !== null) {
            $isRequired = (bool) $args['is_required'] || ((int) $args['is_required']) === 1;
        }
        $row = ReturnCustomField::query()->create([
            'code' => $code,
            'label' => $label,
            'type' => $type,
            'is_required' => $isRequired,
            'position' => (int) ($args['position'] ?? ((int) (ReturnCustomField::query()->max('position') ?? 0) + 1)),
            'input_validation' => isset($args['inputValidation'])
                ? trim((string) $args['inputValidation'])
                : (isset($args['input_validation']) ? trim((string) $args['input_validation']) : null),
            'is_enabled' => array_key_exists('status', $args) && $args['status'] !== null && $args['status'] !== ''
                ? ((int) $args['status']) === 1
                : true,
            'options' => $options,
        ]);

        return self::map($row);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function update(int $id, array $args): array
    {
        self::assertManage();
        $row = ReturnCustomField::query()->find($id);
        if (! $row) {
            throw new UserError('Return custom field not found.');
        }
        if (array_key_exists('label', $args) && $args['label'] !== null) {
            $label = trim((string) $args['label']);
            if ($label === '') {
                throw new UserError('label cannot be empty.');
            }
            $row->label = $label;
        }
        if (array_key_exists('type', $args) && $args['type'] !== null) {
            $type = strtolower(trim((string) $args['type']));
            if (! in_array($type, self::TYPES, true)) {
                throw new UserError('type must be one of: '.implode(', ', self::TYPES).'.');
            }
            $row->type = $type;
        }
        if (array_key_exists('isRequired', $args) && $args['isRequired'] !== null) {
            $row->is_required = ((int) $args['isRequired']) === 1;
        } elseif (array_key_exists('is_required', $args) && $args['is_required'] !== null) {
            $row->is_required = (bool) $args['is_required'] || ((int) $args['is_required']) === 1;
        }
        if (array_key_exists('position', $args) && $args['position'] !== null) {
            $row->position = (int) $args['position'];
        }
        if (array_key_exists('inputValidation', $args)) {
            $row->input_validation = $args['inputValidation'] !== null ? trim((string) $args['inputValidation']) : null;
        } elseif (array_key_exists('input_validation', $args)) {
            $row->input_validation = $args['input_validation'] !== null ? trim((string) $args['input_validation']) : null;
        }
        if (array_key_exists('status', $args) && $args['status'] !== null && $args['status'] !== '') {
            $row->is_enabled = ((int) $args['status']) === 1;
        }
        if (array_key_exists('optionsJson', $args) || array_key_exists('options', $args)) {
            $row->options = self::parseOptions($args);
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
        $row = ReturnCustomField::query()->find($id);
        if (! $row) {
            throw new UserError('Return custom field not found.');
        }
        $mapped = self::map($row, 'Return custom field deleted.');
        $row->delete();

        return $mapped;
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, deleted: list<int>, skipped: list<int>, updated_count: int, message: string}
     */
    public static function bulkDelete(array $ids): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one custom field id.');
        }
        $deleted = [];
        $skipped = [];
        foreach ($ids as $id) {
            $row = ReturnCustomField::query()->find($id);
            if (! $row) {
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
            'message' => count($deleted).' return custom field(s) deleted.',
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return array{ok: bool, updated_count: int}
     */
    public static function bulkUpdateStatus(array $ids, int $status): array
    {
        self::assertManage();
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($ids === []) {
            throw new UserError('Provide at least one custom field id.');
        }
        $updated = ReturnCustomField::query()->whereIn('id', $ids)->update(['is_enabled' => $status === 1]);

        return ['ok' => true, 'updated_count' => (int) $updated];
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(ReturnCustomField $row, ?string $message = null): array
    {
        $options = [];
        foreach (is_array($row->options) ? $row->options : [] as $i => $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $options[] = [
                'id' => (string) ($opt['id'] ?? ($i + 1)),
                'name' => (string) ($opt['name'] ?? $opt['label'] ?? ''),
                'value' => (string) ($opt['value'] ?? $opt['name'] ?? ''),
            ];
        }

        return [
            'id' => (int) $row->id,

            'code' => (string) $row->code,
            'label' => (string) $row->label,
            'type' => (string) $row->type,
            'isRequired' => $row->is_required ? 1 : 0,
            'is_required' => (bool) $row->is_required,
            'position' => (int) ($row->position ?? 0),
            'inputValidation' => $row->input_validation,
            'input_validation' => $row->input_validation,
            'status' => $row->is_enabled ? 1 : 0,
            'is_enabled' => (bool) $row->is_enabled,
            'options' => $options,
            'message' => $message,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    protected static function parseOptions(array $args): array
    {
        if (! empty($args['optionsJson'])) {
            $decoded = json_decode((string) $args['optionsJson'], true);
            if (! is_array($decoded)) {
                throw new UserError('optionsJson must be a JSON array.');
            }

            return $decoded;
        }
        if (isset($args['options']) && is_array($args['options'])) {
            return $args['options'];
        }

        return [];
    }
}
