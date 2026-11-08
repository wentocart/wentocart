<?php

namespace App\Domain\Ops;

use App\Domain\Ops\Models\Operation;
use App\Domain\Ops\Models\OperationItem;
use App\Domain\Store\StoreContext;
use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Operator
{
    public function __construct(
        protected AbilityRegistry $abilities,
        protected EventBus $events,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array{source?: string, actor_type?: string, actor_id?: int|null, reason?: string, uuid?: string}  $context
     */
    public function preview(string $ability, array $input, array $context = []): Operation
    {
        return $this->run($ability, $input, $context, true);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{source?: string, actor_type?: string, actor_id?: int|null, reason?: string, uuid?: string}  $context
     */
    public function execute(string $ability, array $input, array $context = []): Operation
    {
        return $this->run($ability, $input, $context, false);
    }

    public function rollback(Operation $operation): Operation
    {
        if (! $operation->canRollback()) {
            throw new RuntimeException('This operation cannot be rolled back.');
        }

        $journal = app(StaffChangeJournal::class);
        $journal->silence();
        try {
            return $this->rollbackSilenced($operation);
        } finally {
            $journal->unsilence();
        }
    }

    protected function rollbackSilenced(Operation $operation): Operation
    {
        $ability = $this->abilities->get($operation->ability);
        $items = $operation->items()->orderByDesc('id')->get();

        $undo = DB::transaction(function () use ($operation, $ability, $items) {
            $undo = Operation::query()->create([
                'uuid' => (string) Str::uuid(),
                'store_id' => $operation->store_id,
                'ability' => $operation->ability,
                'source' => $operation->source,
                'actor_type' => $this->actorType(),
                'actor_id' => $this->actorId(),
                'status' => 'running',
                'dry_run' => false,
                'reversible' => false,
                'summary' => 'Rollback of #'.$operation->id.': '.$operation->summary,
                'input' => ['rollback_of' => $operation->id],
                'parent_id' => $operation->id,
            ]);

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
                $undo->items()->create([
                    'entity_type' => $item->entity_type,
                    'entity_id' => $item->entity_id,
                    'action' => 'rollback',
                    'label' => $item->label,
                    'before_state' => $item->after_state,
                    'after_state' => $item->before_state,
                    'reversible' => false,
                ]);
            }

            $operation->update([
                'status' => 'rolled_back',
                'rolled_back_at' => now(),
            ]);
            $undo->update([
                'status' => 'completed',
                'result' => ['rolled_back' => $operation->id],
            ]);

            return $undo;
        });

        $this->events->emit('ops.rolled_back', [
            'operation_id' => $operation->id,
            'undo_id' => $undo->id,
        ], $undo->id);

        return $undo->load('items');
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    protected function run(string $code, array $input, array $context, bool $dryRun): Operation
    {
        $journal = app(StaffChangeJournal::class);
        $journal->silence();
        try {
            return $this->runSilenced($code, $input, $context, $dryRun);
        } finally {
            $journal->unsilence();
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    protected function runSilenced(string $code, array $input, array $context, bool $dryRun): Operation
    {
        $ability = $this->abilities->get($code);
        $this->assertPermission($ability, $context);

        $planned = $ability->plan($input);
        $reversible = $planned !== [] && collect($planned)->every(fn (array $row) => $row['reversible'] ?? true);
        $summary = $ability->label().' · '.count($planned).' record'.(count($planned) === 1 ? '' : 's');

        $operation = Operation::query()->create([
            'uuid' => (string) ($context['uuid'] ?? Str::uuid()),
            'store_id' => app(StoreContext::class)->id() ?: null,
            'ability' => $code,
            'source' => (string) ($context['source'] ?? 'admin'),
            'actor_type' => $context['actor_type'] ?? $this->actorType(),
            'actor_id' => $context['actor_id'] ?? $this->actorId(),
            'status' => $dryRun ? 'previewed' : 'running',
            'dry_run' => $dryRun,
            'reversible' => $reversible,
            'summary' => $summary,
            'input' => $input,
            'reason' => $context['reason'] ?? null,
        ]);

        foreach ($planned as $row) {
            $operation->items()->create([
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'action' => $row['action'],
                'label' => $row['label'],
                'before_state' => $row['before'],
                'after_state' => $row['after'],
                'reversible' => (bool) ($row['reversible'] ?? true),
            ]);
        }

        if ($dryRun) {
            $operation->update(['result' => ['count' => count($planned)]]);

            return $operation->load('items');
        }

        try {
            DB::transaction(function () use ($ability, $input, $operation) {
                foreach ($operation->items as $item) {
                    $after = $ability->execute([
                        'entity_type' => $item->entity_type,
                        'entity_id' => $item->entity_id,
                        'action' => $item->action,
                        'before' => $item->before_state ?? [],
                        'after' => $item->after_state ?? [],
                        'label' => $item->label,
                    ], $input);
                    $item->update([
                        'after_state' => $after ?: $item->after_state,
                        'entity_id' => $item->entity_id ?: (int) ($after['id'] ?? 0) ?: $item->entity_id,
                    ]);
                }
                $operation->update([
                    'status' => 'completed',
                    'result' => ['count' => $operation->items->count()],
                ]);
            });
        } catch (Throwable $e) {
            $operation->update([
                'status' => 'failed',
                'result' => ['error' => $e->getMessage()],
            ]);
            throw $e;
        }

        $event = $ability->eventName();
        if ($event) {
            $this->events->emit($event, [
                'ability' => $code,
                'count' => $operation->items()->count(),
                'source' => $operation->source,
            ], $operation->id);
        }

        return $operation->fresh('items');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function assertPermission(Ability $ability, array $context): void
    {
        $source = (string) ($context['source'] ?? 'admin');
        if ($source !== 'admin' && $source !== 'ai') {
            return;
        }
        $admin = Auth::guard('admin')->user();
        if (! $admin instanceof Admin) {
            if ($source === 'ai') {
                return;
            }
            throw new InvalidArgumentException('An admin session is required for this ability.');
        }
        if (! $admin->hasPermission($ability->permission()) && ! $admin->hasPermission('*')) {
            throw new InvalidArgumentException('You do not have permission for '.$ability->code().'.');
        }
    }

    protected function actorType(): ?string
    {
        if (Auth::guard('admin')->check()) {
            return 'admin';
        }
        if (Auth::guard('customer')->check()) {
            return 'customer';
        }

        return 'system';
    }

    protected function actorId(): ?int
    {
        $admin = Auth::guard('admin')->id();
        if ($admin) {
            return (int) $admin;
        }
        $customer = Auth::guard('customer')->id();

        return $customer ? (int) $customer : null;
    }
}
