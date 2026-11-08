<?php

namespace App\Domain\Ai;

use App\Domain\Ai\Contracts\AiProvider;
use RuntimeException;

/**
 * Provider-agnostic gateway for content, admin chat, and storefront chat lanes.
 */
class AiGateway
{
    /** @var array<string, AiProvider> */
    protected array $providers = [];

    public function register(AiProvider $provider): void
    {
        $this->providers[$provider->name()] = $provider;
    }

    public function driver(string $name): AiProvider
    {
        if (! isset($this->providers[$name])) {
            throw new RuntimeException("AI provider [{$name}] is not registered.");
        }

        return $this->providers[$name];
    }

    /** @return list<string> */
    public function available(): array
    {
        return array_keys($this->providers);
    }
}
