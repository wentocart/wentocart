<?php

namespace Tests\Feature;

use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_subscribe_and_admin_can_list(): void
    {
        $this->seed();

        $this->postJson('/api/v1/newsletter', ['email' => 'shopper@example.test'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'shopper@example.test',
        ]);

        $this->postJson('/api/v1/newsletter', ['email' => 'shopper@example.test'])
            ->assertStatus(400)
            ->assertJsonPath('success', false);

        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->get('/admin/newsletter')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/Newsletter/Index', false)
                ->where('count', 1)
            );

        $id = NewsletterSubscriber::query()->where('email', 'shopper@example.test')->value('id');
        $this->delete('/admin/newsletter/'.$id)->assertRedirect();
        $this->assertDatabaseMissing('newsletter_subscribers', [
            'email' => 'shopper@example.test',
        ]);
    }

    public function test_admin_newsletter_supports_column_filters_sorting_and_export(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $first = NewsletterSubscriber::query()->create(['email' => 'alpha@test.com']);
        $second = NewsletterSubscriber::query()->create(['email' => 'beta@test.com']);

        // Filter by email
        $this->get('/admin/newsletter?email=alpha')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/Newsletter/Index', false)
                ->where('filters.email', 'alpha')
                ->where('subscribers.data.0.email', 'alpha@test.com')
            );

        // Sort by email asc
        $this->get('/admin/newsletter?sort=email&dir=asc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/Newsletter/Index', false)
                ->where('filters.sort', 'email')
                ->where('filters.dir', 'asc')
                ->where('subscribers.data.0.email', 'alpha@test.com')
            );

        // Export with ids
        $response = $this->get('/admin/newsletter/export?ids='.$first->id);
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('alpha@test.com', $content);
        $this->assertStringNotContainsString('beta@test.com', $content);
    }
}
