<?php

namespace App\Domain\Ops;

use App\Domain\Ops\Models\Automation;
use App\Domain\Ops\Models\AutomationRun;
use App\Domain\Ops\Models\CommerceEvent;
use App\Domain\Ops\Models\Operation;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AutomationEngine
{
    protected static bool $running = false;

    public function handle(CommerceEvent $event): void
    {
        if (self::$running) {
            return;
        }
        if (\App\Domain\Settings\ShopSetting::bool('ops.automations_halted', false)) {
            return;
        }
        if ($event->operation_id) {
            $source = Operation::query()->whereKey($event->operation_id)->value('source');
            if ($source === 'automation') {
                return;
            }
        }

        $rules = Automation::query()->where('is_enabled', true)->where('event', $event->name)->get();
        if ($rules->isEmpty()) {
            return;
        }

        self::$running = true;
        try {
            foreach ($rules as $rule) {
                $this->run($rule, $event);
            }
        } finally {
            self::$running = false;
        }
    }

    protected function run(Automation $rule, CommerceEvent $event): void
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        if ($rule->condition_path) {
            $actual = data_get($payload, $rule->condition_path);
            if ((string) $actual !== (string) $rule->condition_equals) {
                AutomationRun::query()->create([
                    'automation_id' => $rule->id,
                    'status' => 'skipped',
                    'message' => 'Condition did not match.',
                    'payload' => $payload,
                    'created_at' => now(),
                ]);

                return;
            }
        }

        try {
            $operation = app(Operator::class)->execute($rule->ability, is_array($rule->action_input) ? $rule->action_input : [], [
                'source' => 'automation',
                'reason' => 'Automation: '.$rule->name,
            ]);
            AutomationRun::query()->create([
                'automation_id' => $rule->id,
                'status' => 'completed',
                'operation_id' => $operation->id,
                'message' => $operation->summary,
                'payload' => $payload,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('automation.failed', ['id' => $rule->id, 'error' => $e->getMessage()]);
            AutomationRun::query()->create([
                'automation_id' => $rule->id,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'payload' => $payload,
                'created_at' => now(),
            ]);
        }
    }
}
