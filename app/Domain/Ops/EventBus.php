<?php

namespace App\Domain\Ops;

use App\Domain\Ops\Models\CommerceEvent;
use App\Domain\Store\StoreContext;

final class EventBus
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function emit(string $name, array $payload = [], ?int $operationId = null): CommerceEvent
    {
        $event = CommerceEvent::query()->create([
            'store_id' => app(StoreContext::class)->id() ?: null,
            'name' => $name,
            'operation_id' => $operationId,
            'payload' => $payload,
            'created_at' => now(),
        ]);

        wento_hook('ops.event', [
            'name' => $name,
            'payload' => $payload,
            'operation_id' => $operationId,
        ]);

        try {
            app(AutomationEngine::class)->handle($event);
        } catch (\Throwable) {
            // Automations must not break the write path.
        }
        try {
            app(WebhookDispatcher::class)->handle($event);
        } catch (\Throwable) {
            // Outbound webhooks are best-effort.
        }

        return $event;
    }
}
