<?php

namespace Tests\Feature;

use App\Domain\Ai\AiProviderFactory;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\Contracts\AiProvider;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiContentAssistTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        AiProviderFactory::fake(null);
        parent::tearDown();
    }

    public function test_ai_settings_page_loads_for_super_admin(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->get('/admin/ai')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('ai')->has('catalog'));
    }

    public function test_content_endpoint_requires_configuration(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->postJson('/admin/ai/content', [
            'action' => 'generate',
            'field' => 'product.name',
            'text' => '',
        ])->assertStatus(422);
    }

    public function test_only_one_connection_is_active(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        AiSettings::saveFlags(['enabled' => true, 'content_enabled' => true]);
        $a = AiSettings::upsertConnection(null, [
            'driver' => 'openai',
            'label' => 'OpenAI A',
            'api_key' => 'sk-aaa',
            'model' => 'gpt-4o-mini',
        ]);
        $b = AiSettings::upsertConnection(null, [
            'driver' => 'gemini',
            'label' => 'Gemini B',
            'api_key' => 'gem-bbb',
            'model' => 'gemini-2.0-flash',
        ]);

        AiSettings::activate($b['id']);
        $this->assertSame($b['id'], AiSettings::activeId());
        $this->assertSame('gemini', AiSettings::activeConnection()['driver']);

        AiSettings::activate($a['id']);
        $this->assertSame($a['id'], AiSettings::activeId());
        $this->assertSame('openai', AiSettings::activeConnection()['driver']);
    }

    public function test_content_endpoint_returns_provider_text_when_configured(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        AiSettings::saveFlags(['enabled' => true, 'content_enabled' => true]);
        AiSettings::upsertConnection(null, [
            'driver' => 'openai',
            'label' => 'Test OpenAI',
            'api_key' => 'sk-test-key',
            'model' => 'gpt-4o-mini',
        ]);

        AiProviderFactory::fake(new class implements AiProvider
        {
            public function name(): string
            {
                return 'openai';
            }

            public function complete(string $prompt, array $options = []): string
            {
                return 'AI Product Title';
            }
        });

        $this->postJson('/admin/ai/content', [
            'action' => 'generate',
            'field' => 'product.name',
            'locale' => 'en',
            'context' => ['sku' => 'SKU-1'],
        ])->assertOk()->assertJsonPath('text', 'AI Product Title');
    }

    public function test_structure_action_formats_cms_body(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        AiSettings::saveFlags(['enabled' => true, 'content_enabled' => true]);
        AiSettings::upsertConnection(null, [
            'driver' => 'openai',
            'label' => 'Test OpenAI',
            'api_key' => 'sk-test-key',
            'model' => 'gpt-4o-mini',
        ]);

        AiProviderFactory::fake(new class implements AiProvider
        {
            public function name(): string
            {
                return 'openai';
            }

            public function complete(string $prompt, array $options = []): string
            {
                return '<h2>Returns</h2><p>Organized copy.</p><ul><li>Step one</li></ul>';
            }
        });

        $this->postJson('/admin/ai/content', [
            'action' => 'structure',
            'field' => 'cms.body',
            'locale' => 'en',
            'text' => '<p>We accept returns within 30 days. Contact support.</p>',
            'context' => ['title' => 'Returns'],
        ])->assertOk()->assertJsonPath('text', '<h2>Returns</h2><p>Organized copy.</p><ul><li>Step one</li></ul>');
    }

    public function test_add_connection_shows_provider_specific_requirements(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $this->post('/admin/ai/connections', [
            'driver' => 'openai_compatible',
            'label' => 'Groq',
            'api_key' => 'gsk-test',
            'model' => 'llama-3.3-70b-versatile',
            // missing base_url
        ])->assertSessionHasErrors('base_url');

        $this->post('/admin/ai/connections', [
            'driver' => 'openai_compatible',
            'label' => 'Groq',
            'api_key' => 'gsk-test',
            'model' => 'llama-3.3-70b-versatile',
            'base_url' => 'https://api.groq.com/openai/v1',
        ])->assertRedirect();

        $this->assertNotNull(AiSettings::activeConnection());
        $this->assertSame('openai_compatible', AiSettings::activeConnection()['driver']);
    }
}
