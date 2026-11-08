<?php

namespace App\Support\Hooks;

/**
 * Lightweight observer/hook bus for core + future zip plugins.
 *
 * Usage: HookManager::listen('catalog.product.saved', $callback);
 *        HookManager::fire('catalog.product.saved', ['product' => $product]);
 */
class HookManager
{
    /** @var array<string, list<callable>> */
    protected static array $listeners = [];

    public static function listen(string $hook, callable $callback): void
    {
        static::$listeners[$hook][] = $callback;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function fire(string $hook, array $payload = []): array
    {
        foreach (static::$listeners[$hook] ?? [] as $callback) {
            try {
                $result = $callback($payload);
                if (is_array($result)) {
                    $payload = $result;
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning("Extension listener for hook '{$hook}' threw an exception: {$e->getMessage()}");
            }
        }

        return $payload;
    }

    public static function flush(): void
    {
        static::$listeners = [];
    }
}
