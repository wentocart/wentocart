<?php

namespace App\Domain\Ai\Contracts;

interface AiProvider
{
    public function name(): string;

    public function complete(string $prompt, array $options = []): string;
}
