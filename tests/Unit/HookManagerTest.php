<?php

namespace Tests\Unit;

use App\Support\Hooks\HookManager;
use PHPUnit\Framework\TestCase;

class HookManagerTest extends TestCase
{
    public function test_hooks_can_mutate_payload(): void
    {
        HookManager::flush();
        HookManager::listen('demo', fn (array $p) => ['n' => $p['n'] + 1]);
        $this->assertSame(2, HookManager::fire('demo', ['n' => 1])['n']);
    }
}
