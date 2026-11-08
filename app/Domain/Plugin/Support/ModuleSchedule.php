<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\PluginManifest;

/**
 * Register work for the shop schedule tick (abandoned cart mail, digests, …).
 * Core runs `php artisan wento:extensions-tick` on a cron; extensions never own cron.
 */
class ModuleSchedule
{
    public function __construct(protected PluginManifest $manifest) {}

    public function onTick(callable $callback): void
    {
        ModuleBuses::scheduleTick($this->manifest, $callback);
    }
}
