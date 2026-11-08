<?php

namespace App\Domain\Seo;

use App\Domain\Ops\AbilityRegistry;
use App\Domain\Ops\Models\Operation;
use App\Domain\Ops\Models\OperationItem;
use App\Domain\Ops\Operator;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SeoFixJournal
{
    public const ABILITY = 'seo.health.fix';

    /** @var array<string, string> */
    public const LABELS = [
        'all' => 'Fix all automatic issues',
        'enable_sitemap' => 'Turn on XML sitemap',
        'write_robots_sitemap' => 'Add sitemap to robots.txt',
        'set_default_description' => 'Set default meta description',
        'enable_webp' => 'Turn on WebP output',
        'set_og_image' => 'Set Open Graph fallback image',
        'fill_product_titles' => 'Fill product meta titles',
        'fill_product_descriptions' => 'Fill product meta descriptions',
        'fill_product_keywords' => 'Fill product meta keywords',
        'fill_product_focus' => 'Fill product focus keywords',
        'trim_product_titles' => 'Trim long meta titles',
        'unique_product_titles' => 'Make duplicate titles unique',
        'expand_short_titles' => 'Expand short meta titles',
        'place_focus_in_title' => 'Put focus keyword in the title',
        'fill_product_canonicals' => 'Write product canonical URLs',
        'fill_thin_content' => 'Fill thin product descriptions',
        'assign_product_covers' => 'Assign product cover images',
        'optimize_images' => 'Generate sized/WebP product images',
        'fill_cms_meta' => 'Fill CMS page meta',
        'fill_category_meta' => 'Fill category meta',
        'noindex_disabled_products' => 'Noindex disabled products',
        'fill_image_alts' => 'Fill image alt text',
    ];

    /** @var list<array<string, mixed>> */
    protected array $changes = [];

    protected int $depth = 0;

    protected string $currentFix = '';

    public function start(string $fixId): void
    {
        if ($this->depth === 0) {
            $this->changes = [];
        }
        $this->depth++;
        $this->currentFix = $fixId;
    }

    public function isRecording(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function record(string $table, int $id, string $label, array $before, array $after, string $entityType): void
    {
        if ($before === $after) {
            return;
        }
        $this->changes[] = [
            'entity_type' => $entityType,
            'entity_id' => $id,
            'action' => 'update',
            'label' => Str::limit($label !== '' ? $label : ($table.' #'.$id), 255, ''),
            'before' => [
                'table' => $table,
                'id' => $id,
                'fields' => $before,
                'fix' => $this->currentFix,
            ],
            'after' => [
                'table' => $table,
                'id' => $id,
                'fields' => $after,
                'fix' => $this->currentFix,
            ],
            'reversible' => true,
        ];
    }

    public function finish(string $summary): ?Operation
    {
        $this->depth = max(0, $this->depth - 1);
        if ($this->depth > 0) {
            return null;
        }

        $changes = $this->changes;
        $this->changes = [];
        $this->currentFix = '';
        if ($changes === []) {
            return null;
        }

        $admin = Auth::guard('admin')->user();

        return DB::transaction(function () use ($changes, $summary, $admin) {
            $operation = Operation::query()->create([
                'uuid' => (string) Str::uuid(),
                'store_id' => app(StoreContext::class)->id() ?: null,
                'ability' => self::ABILITY,
                'source' => 'admin',
                'actor_type' => $admin instanceof Admin ? 'admin' : 'system',
                'actor_id' => $admin instanceof Admin ? $admin->id : null,
                'status' => 'completed',
                'dry_run' => false,
                'reversible' => true,
                'summary' => Str::limit($summary, 255, ''),
                'input' => ['source' => 'store-health'],
                'result' => ['count' => count($changes)],
            ]);
            foreach ($changes as $row) {
                $operation->items()->create([
                    'entity_type' => $row['entity_type'],
                    'entity_id' => $row['entity_id'],
                    'action' => $row['action'],
                    'label' => $row['label'],
                    'before_state' => $row['before'],
                    'after_state' => $row['after'],
                    'reversible' => true,
                ]);
            }

            return $operation->load('items');
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 12): array
    {
        return Operation::query()
            ->where('ability', self::ABILITY)
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Operation $op) => $this->present($op))
            ->values()
            ->all();
    }

    public function paginate(int $perPage = 20)
    {
        return Operation::query()
            ->where('ability', self::ABILITY)
            ->withCount([
                'items',
                'items as open_count' => fn ($q) => $q->where('reversible', true)->where('rolled_back', false),
            ])
            ->latest('id')
            ->paginate($perPage)
            ->through(fn (Operation $op) => [
                'id' => $op->id,
                'summary' => (string) $op->summary,
                'status' => (string) $op->status,
                'can_rollback' => $op->canRollback(),
                'items_count' => (int) $op->items_count,
                'open_count' => (int) $op->open_count,
                'created_at' => optional($op->created_at)?->toDateTimeString(),
            ]);
    }

    public function requireRun(int $id): Operation
    {
        $operation = Operation::query()
            ->where('ability', self::ABILITY)
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->find($id);
        if (! $operation) {
            throw new RuntimeException('That fix run was not found.');
        }

        return $operation;
    }

    public function forget(Operation $operation): void
    {
        if ($operation->ability !== self::ABILITY) {
            throw new RuntimeException('That is not a store-health run.');
        }
        DB::transaction(function () use ($operation) {
            $operation->items()->delete();
            $operation->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(Operation $op, int $itemLimit = 200): array
    {
        $jobs = [];
        foreach ($op->items as $item) {
            $fix = (string) (($item->before_state['fix'] ?? $item->after_state['fix'] ?? '') ?: 'update');
            $jobs[$fix] ??= [
                'id' => $fix,
                'label' => self::LABELS[$fix] ?? $fix,
                'count' => 0,
                'rolled_back' => 0,
            ];
            $jobs[$fix]['count']++;
            if ($item->rolled_back) {
                $jobs[$fix]['rolled_back']++;
            }
        }

        return [
            'id' => $op->id,
            'summary' => (string) $op->summary,
            'status' => (string) $op->status,
            'can_rollback' => $op->canRollback(),
            'items_count' => $op->items->count(),
            'open_count' => $op->items->where('rolled_back', false)->where('reversible', true)->count(),
            'created_at' => optional($op->created_at)?->toDateTimeString(),
            'jobs' => array_values($jobs),
            'items' => $op->items->take($itemLimit)->map(fn (OperationItem $item) => [
                'id' => $item->id,
                'entity_type' => $item->entity_type,
                'entity_id' => $item->entity_id,
                'label' => $item->label,
                'fix' => (string) (($item->before_state['fix'] ?? '') ?: 'update'),
                'fix_label' => self::LABELS[(string) (($item->before_state['fix'] ?? '') ?: '')] ?? ((string) (($item->before_state['fix'] ?? '') ?: 'update')),
                'before' => $item->before_state['fields'] ?? [],
                'after' => $item->after_state['fields'] ?? [],
                'rolled_back' => (bool) $item->rolled_back,
                'can_rollback' => $item->reversible && ! $item->rolled_back && $op->rolled_back_at === null && $op->status === 'completed',
            ])->values()->all(),
            'items_truncated' => $op->items->count() > $itemLimit,
        ];
    }

    public function rollback(?int $operationId, ?int $itemId = null, ?string $fix = null, bool $last = false): Operation
    {
        if ($last) {
            $operation = Operation::query()
                ->where('ability', self::ABILITY)
                ->where('status', 'completed')
                ->whereNull('rolled_back_at')
                ->latest('id')
                ->first();
            if (! $operation) {
                throw new RuntimeException('There is no store-health fix to roll back.');
            }

            return app(Operator::class)->rollback($operation);
        }

        $operation = Operation::query()->where('ability', self::ABILITY)->find($operationId);
        if (! $operation) {
            throw new RuntimeException('That fix run was not found.');
        }

        if ($itemId) {
            $item = $operation->items()->where('id', $itemId)->first();
            if (! $item) {
                throw new RuntimeException('That change was not found.');
            }
            $this->rollbackItems($operation, collect([$item]));

            return $operation->fresh('items');
        }

        if ($fix) {
            $items = $operation->items->filter(function (OperationItem $item) use ($fix) {
                return (($item->before_state['fix'] ?? '') === $fix) && ! $item->rolled_back;
            });
            $this->rollbackItems($operation, $items);

            return $operation->fresh('items');
        }

        return app(Operator::class)->rollback($operation);
    }

    /**
     * @param  Collection<int, OperationItem>  $items
     */
    protected function rollbackItems(Operation $operation, $items): void
    {
        if (! $operation->canRollback() && $operation->status !== 'completed') {
            throw new RuntimeException('This fix run cannot be rolled back.');
        }
        $ability = app(AbilityRegistry::class)->get(self::ABILITY);
        $did = 0;
        foreach ($items as $item) {
            if (! $item->reversible || $item->rolled_back) {
                continue;
            }
            $ability->rollback([
                'entity_type' => $item->entity_type,
                'entity_id' => $item->entity_id,
                'action' => $item->action,
                'before' => $item->before_state ?? [],
                'after' => $item->after_state ?? [],
            ]);
            $item->update(['rolled_back' => true]);
            $did++;
        }
        if ($did === 0) {
            throw new RuntimeException('Nothing left to roll back in that selection.');
        }
        $open = $operation->items()->where('reversible', true)->where('rolled_back', false)->count();
        if ($open === 0) {
            $operation->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
            ]);
        }
        ShopAudit::flush();
    }
}
