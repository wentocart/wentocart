<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave258Test extends TestCase
{
    use RefreshDatabase;

    public function test_docker_stack_docs_and_compose_present(): void
    {
        $compose = base_path('docker-compose.yml');
        if (! is_file($compose)) {
            $this->markTestSkipped('Docker files are not part of this source tree.');
        }

        $this->seed();

        $dockerfile = base_path('docker/Dockerfile');
        $entrypoint = base_path('docker/entrypoint.sh');
        $dockerReadme = base_path('docker/README.md');

        $this->assertFileExists($compose);
        $this->assertFileExists($dockerfile);
        $this->assertFileExists($entrypoint);
        $this->assertFileExists($dockerReadme);

        $readme = (string) file_get_contents($dockerReadme);
        $this->assertStringContainsString('docker compose up --build', $readme);
        $this->assertStringContainsString('Meilisearch', $readme);
        $this->assertStringContainsString('/api/v1/store/docs', $readme);

        $composeBody = (string) file_get_contents($compose);
        $this->assertStringContainsString('mysql:', $composeBody);
        $this->assertStringContainsString('redis:', $composeBody);
        $this->assertStringContainsString('meilisearch:', $composeBody);
        $this->assertStringContainsString('shop:', $composeBody);

        $rootReadme = (string) file_get_contents(base_path('README.md'));
        $this->assertStringContainsString('docker/README.md', $rootReadme);
    }
}
