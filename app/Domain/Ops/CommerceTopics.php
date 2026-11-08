<?php

namespace App\Domain\Ops;

use App\Domain\Ops\Models\Automation;
use App\Domain\Ops\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class CommerceTopics
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function notify(string $name, array $payload = [], ?int $operationId = null): void
    {
        $run = function () use ($name, $payload, $operationId) {
            if (! self::hasListeners($name)) {
                return;
            }
            app(EventBus::class)->emit($name, $payload, $operationId);
        };

        if (DB::transactionLevel() > 0 && ! app()->runningUnitTests()) {
            DB::afterCommit($run);

            return;
        }
        $run();
    }

    public static function hasListeners(string $name): bool
    {
        $map = self::listenerMap();
        if (isset($map['*']) || isset($map[$name])) {
            return true;
        }
        foreach (array_keys($map) as $pattern) {
            $pattern = (string) $pattern;
            if (str_ends_with($pattern, '*') && str_starts_with($name, rtrim($pattern, '*'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    public static function listenerMap(): array
    {
        try {
            return Cache::remember('ops.listener_map', 15, function () {
                $map = [];
                foreach (Automation::query()->where('is_enabled', true)->pluck('event') as $event) {
                    $map[(string) $event] = true;
                }
                foreach (WebhookEndpoint::query()->where('is_enabled', true)->whereNull('disabled_at')->get() as $endpoint) {
                    foreach ($endpoint->subscribedEvents() as $event) {
                        $map[$event] = true;
                    }
                }

                return $map;
            });
        } catch (\Throwable) {
            return [];
        }
    }

    public static function flushListeners(): void
    {
        Cache::forget('ops.listener_map');
    }
}
