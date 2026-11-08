<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\HookCatalog;
use App\Domain\Plugin\PluginManifest;
use App\Support\Hooks\HookManager;
use InvalidArgumentException;

class PluginHooks
{
    public function __construct(protected PluginManifest $manifest) {}

    public function listen(string $hook, callable $callback): void
    {
        if (! $this->manifest->can('hook.listen')) {
            throw new InvalidArgumentException('This app did not declare hook.listen.');
        }
        if (! HookCatalog::allows($hook) || ! in_array($hook, $this->manifest->hooks, true)) {
            throw new InvalidArgumentException('This app cannot listen to '.$hook.'.');
        }
        HookManager::listen($hook, $callback);
    }
}
