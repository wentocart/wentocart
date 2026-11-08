<?php

namespace App\Domain\Plugin\Support;

use App\Domain\Plugin\PluginManifest;

class ModuleAuth
{
    public function __construct(protected PluginManifest $manifest) {}

    /**
     * Resolve username (or other identifier) to a customer email.
     * Callback: function (string $identifier): ?string
     */
    public function resolveLogin(callable $callback): void
    {
        ModuleBuses::resolveLogin($this->manifest, $callback);
    }

    /**
     * Configure registration defaults. Return ['is_enabled' => false, 'auto_login' => false]
     * for admin-approval flows.
     */
    public function configureRegister(callable $callback): void
    {
        ModuleBuses::configureRegister($this->manifest, $callback);
    }

    /**
     * After a customer is created. Callback: function (int $customerId, array $extra): void
     */
    public function onRegistered(callable $callback): void
    {
        ModuleBuses::onRegistered($this->manifest, $callback);
    }
}
