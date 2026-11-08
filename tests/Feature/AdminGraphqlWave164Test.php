<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminGraphqlWave164Test extends TestCase
{
    public function test_wento_graphql_agent_skill_is_present(): void
    {
        $path = base_path('agent-skills/wento-graphql/SKILL.md');
        $this->assertFileExists($path);
        $body = (string) file_get_contents($path);
        $this->assertStringContainsString('name: wento-graphql', $body);
        $this->assertStringContainsString('/api/v1/llms.txt', $body);
        $this->assertStringContainsString('source_of_truth:', $body);
        $this->assertStringContainsString('package: single-skill', $body);
        $this->assertStringContainsString('Use this skill when', $body);
        $this->assertStringContainsString('createIntegrationToken', $body);
        $this->assertStringContainsString('permissionMode', $body);
        $this->assertStringContainsString('X-STOREFRONT-KEY', $body);
        $this->assertStringContainsString('## Endpoints', $body);
        $this->assertStringContainsString('## Security guards', $body);
    }
}
