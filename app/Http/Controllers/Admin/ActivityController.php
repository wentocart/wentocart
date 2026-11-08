<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ops\Models\Operation;
use App\Domain\Ops\Operator;
use App\Domain\Seo\SeoFixJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class ActivityController extends Controller
{
    public function index(Request $request): Response
    {
        $source = trim((string) $request->query('source', ''));
        $status = trim((string) $request->query('status', ''));
        if (! in_array($source, ['admin', 'ai'], true)) {
            $source = '';
        }

        $operations = Operation::query()
            ->withCount('items')
            ->where('ability', '!=', SeoFixJournal::ABILITY)
            ->whereNotIn('source', ['import', 'automation'])
            ->when($source !== '', fn ($q) => $q->where('source', $source))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->adminPaginate()
            ->through(fn (Operation $op) => $this->row($op));

        return Inertia::render('Ops/Activity/Index', [
            'operations' => $operations,
            'filters' => ['source' => $source, 'status' => $status],
        ]);
    }

    public function show(Operation $operation): Response
    {
        $operation->load('items');

        return Inertia::render('Ops/Activity/Show', [
            'operation' => $this->detail($operation),
        ]);
    }

    public function rollback(Operation $operation, Operator $operator): RedirectResponse
    {
        try {
            $undo = $operator->rollback($operation);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.activity.show', $undo)->with('success', 'Change rolled back.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Operation $op): array
    {
        return [
            'id' => $op->id,
            'ability' => $op->ability,
            'source' => $op->source,
            'status' => $op->status,
            'dry_run' => $op->dry_run,
            'reversible' => $op->reversible,
            'can_rollback' => $op->canRollback(),
            'summary' => $op->summary,
            'reason' => $op->reason,
            'items_count' => (int) ($op->items_count ?? $op->items()->count()),
            'created_at' => optional($op->created_at)?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function detail(Operation $op): array
    {
        return [
            ...$this->row($op),
            'uuid' => $op->uuid,
            'input' => $op->input,
            'result' => $op->result,
            'parent_id' => $op->parent_id,
            'items' => $op->items->map(fn ($item) => [
                'id' => $item->id,
                'entity_type' => $item->entity_type,
                'entity_id' => $item->entity_id,
                'action' => $item->action,
                'label' => $item->label,
                'before' => $item->before_state,
                'after' => $item->after_state,
                'reversible' => $item->reversible,
                'rolled_back' => $item->rolled_back,
            ])->values()->all(),
        ];
    }
}
