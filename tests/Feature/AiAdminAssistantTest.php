<?php

namespace Tests\Feature;

use App\Domain\Ai\AiProviderFactory;
use App\Domain\Ai\AiSettings;
use App\Domain\Ai\AssistantSettings;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Ai\SqlGuard;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAdminAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        AiProviderFactory::fake(null);
        parent::tearDown();
    }

    public function test_sql_guard_rejects_writes_and_unknown_tables(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SqlGuard::sanitizeSelects('DELETE FROM products', 10);
    }

    public function test_sql_guard_expands_select_star_to_lean_columns(): void
    {
        $sql = SqlGuard::sanitizeSelects('SELECT * FROM products WHERE stock_qty < 100', 10);
        $this->assertCount(1, $sql);
        $this->assertStringNotContainsString('SELECT *', $sql[0]);
        $this->assertStringContainsString('stock_qty', $sql[0]);
        $this->assertStringContainsString('sku', $sql[0]);
    }

    public function test_sql_guard_allows_count_star(): void
    {
        $sql = SqlGuard::sanitizeSelects('SELECT COUNT(*) AS product_count FROM products', 10);
        $this->assertCount(1, $sql);
        $this->assertStringContainsString('COUNT(*)', $sql[0]);
    }

    public function test_sql_guard_allows_select_on_products(): void
    {
        $sql = SqlGuard::sanitizeSelects('SELECT id, sku FROM products ORDER BY id DESC', 5);
        $this->assertCount(1, $sql);
        $this->assertStringContainsString('LIMIT 5', $sql[0]);
        $prefix = \Illuminate\Support\Facades\DB::connection()->getTablePrefix();
        if ($prefix !== '') {
            $this->assertStringContainsString($prefix.'products', $sql[0]);
        } else {
            $this->assertStringContainsString('FROM products', $sql[0]);
        }
    }

    public function test_sql_guard_prefixes_orders_table(): void
    {
        $sql = SqlGuard::sanitizeSelects(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS order_month, COUNT(*) AS order_count FROM orders GROUP BY order_month ORDER BY order_month",
            10
        );
        $this->assertCount(1, $sql);
        $prefix = \Illuminate\Support\Facades\DB::connection()->getTablePrefix();
        if ($prefix !== '') {
            $this->assertMatchesRegularExpression('/FROM\s+'.preg_quote($prefix, '/').'orders\b/i', $sql[0]);
            $this->assertDoesNotMatchRegularExpression('/FROM\s+orders\b/i', $sql[0]);
        }
    }

    public function test_assistant_ask_requires_configuration(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $chat = \App\Domain\Ai\Models\AiAssistantChat::query()->create([
            'admin_id' => $admin->id,
            'title' => 'Test',
            'context_json' => [],
            'messages_json' => [],
        ]);

        $this->postJson('/admin/ai/assistant/ask', [
            'question' => 'How many orders?',
            'chat_id' => $chat->id,
        ])->assertStatus(422);
    }

    public function test_assistant_general_lane_returns_text(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        AiSettings::saveFlags(['enabled' => true, 'content_enabled' => true]);
        AssistantSettings::save(['assistant_enabled' => true]);
        AiSettings::upsertConnection(null, [
            'driver' => 'openai',
            'label' => 'Test',
            'api_key' => 'sk-test',
            'model' => 'gpt-4o-mini',
        ]);

        $chat = \App\Domain\Ai\Models\AiAssistantChat::query()->create([
            'admin_id' => $admin->id,
            'title' => 'General chat',
            'context_json' => [],
            'messages_json' => [],
        ]);

        $box = (object) ['n' => 0];
        AiProviderFactory::fake(new class($box) implements AiProvider
        {
            public function __construct(private object $box) {}

            public function name(): string
            {
                return 'openai';
            }

            public function complete(string $prompt, array $options = []): string
            {
                $this->box->n++;
                if ($this->box->n === 1) {
                    return 'general';
                }
                if ($this->box->n === 2) {
                    return 'general';
                }

                return 'Hello from the assistant.';
            }
        });

        $this->postJson('/admin/ai/assistant/ask', [
            'question' => 'What is AI?',
            'chat_id' => $chat->id,
        ])->assertOk()
            ->assertJsonPath('kind', 'text')
            ->assertJsonPath('text', 'Hello from the assistant.');

        $chat->refresh();
        $this->assertNotEmpty($chat->messages_json);
    }

    public function test_can_create_and_list_chats(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->postJson('/admin/ai/assistant/chats', [
            'title' => 'August sales',
        ])->assertOk()->assertJsonPath('chat.title', 'August sales');

        $this->getJson('/admin/ai/assistant/chats')
            ->assertOk()
            ->assertJsonCount(1, 'chats');
    }

    public function test_assistant_settings_for_inertia_returns_enabled_when_unconfigured(): void
    {
        AiSettings::saveFlags(['enabled' => true]);
        \App\Domain\Settings\ShopSetting::put('ai.assistant_enabled', true);
        $inertia = AssistantSettings::forInertia();

        $this->assertTrue($inertia['assistant_enabled']);
        $this->assertFalse($inertia['configured']);
        $this->assertEquals(['all'], $inertia['accessible_tabs']);

        AiSettings::saveFlags(['enabled' => false]);
        $this->assertFalse(AssistantSettings::enabled());
        $this->assertFalse(AssistantSettings::forInertia()['assistant_enabled']);
    }

    public function test_admin_chats_logs_filtering_bulk_delete_and_export(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $chat1 = \App\Domain\Ai\Models\AiAssistantChat::query()->create([
            'admin_id' => $admin->id,
            'title' => 'Product questions',
            'context_json' => [],
            'messages_json' => [['role' => 'user', 'text' => 'Hello']],
        ]);

        $chat2 = \App\Domain\Ai\Models\AiAssistantChat::query()->create([
            'admin_id' => $admin->id,
            'title' => 'Order questions',
            'context_json' => [],
            'messages_json' => [['role' => 'user', 'text' => 'Where is my order?']],
        ]);

        // Filter by title
        $response = $this->get('/admin/ai/logs?title=Product');
        $response->assertOk();

        // Export
        $export = $this->get('/admin/ai/logs/export?ids='.$chat1->id);
        $export->assertOk();

        // Bulk delete
        $deleteResponse = $this->post('/admin/ai/logs/bulk-delete', [
            'ids' => [$chat1->id],
        ]);
        $deleteResponse->assertRedirect();
        $this->assertDatabaseMissing('ai_assistant_chats', ['id' => $chat1->id]);
        $this->assertDatabaseHas('ai_assistant_chats', ['id' => $chat2->id]);
    }

    public function test_customer_chats_filtering_bulk_delete_and_export(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $chat1 = \App\Domain\Ai\Models\AiCustomerChat::query()->create([
            'visitor_token' => 'test-visitor-1',
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
            'title' => 'Help with checkout',
            'messages_json' => [['role' => 'user', 'text' => 'Discount not working']],
        ]);

        $chat2 = \App\Domain\Ai\Models\AiCustomerChat::query()->create([
            'visitor_token' => 'test-visitor-2',
            'name' => 'Bob Jones',
            'email' => 'bob@example.com',
            'title' => 'Shipping rates',
            'messages_json' => [['role' => 'user', 'text' => 'When will it arrive?']],
        ]);

        // Filter by customer
        $response = $this->get('/admin/ai/customer-chats?customer=Alice');
        $response->assertOk();

        // Export
        $export = $this->get('/admin/ai/customer-chats/export?ids='.$chat1->id);
        $export->assertOk();

        // Bulk delete
        $deleteResponse = $this->post('/admin/ai/customer-chats/bulk-delete', [
            'ids' => [$chat1->id],
        ]);
        $deleteResponse->assertRedirect();
        $this->assertDatabaseMissing('ai_customer_chats', ['id' => $chat1->id]);
        $this->assertDatabaseHas('ai_customer_chats', ['id' => $chat2->id]);
    }
}
