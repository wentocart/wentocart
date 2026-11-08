<?php

namespace Tests\Feature;

use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave175Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marketing_subscribers_rest_rest_compat(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $a = NewsletterSubscriber::query()->create([
            'email' => 'w175-a-'.strtolower(substr(uniqid(), -6)).'@example.test',
            'is_subscribed' => true,
        ]);
        $b = NewsletterSubscriber::query()->create([
            'email' => 'w175-b-'.strtolower(substr(uniqid(), -6)).'@example.test',
            'is_subscribed' => false,
        ]);

        $list = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/subscribers?per_page=10');
        $list->assertOk();
        $this->assertNotEmpty($list->json('data'));
        $this->assertArrayHasKey('meta', $list->json());
        $this->assertArrayHasKey('currentPage', $list->json('meta'));

        $show = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/marketing/subscribers/'.$a->id);
        $show->assertOk();
        $this->assertSame($a->email, (string) $show->json('email'));
        $this->assertTrue((bool) $show->json('is_subscribed'));

        $toggle = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/subscribers/'.$a->id, [
            'is_subscribed' => false,
        ]);
        $toggle->assertOk();
        $this->assertFalse((bool) $toggle->json('is_subscribed'));
        $this->assertFalse((bool) NewsletterSubscriber::query()->whereKey($a->id)->value('is_subscribed'));

        $missing = $this->actingAs($admin, 'admin')->putJson('/api/v1/console/marketing/subscribers/'.$a->id, []);
        $missing->assertStatus(422);

        $del = $this->actingAs($admin, 'admin')->deleteJson('/api/v1/console/marketing/subscribers/'.$b->id);
        $del->assertOk();
        $this->assertFalse(NewsletterSubscriber::query()->whereKey($b->id)->exists());

        $c = NewsletterSubscriber::query()->create([
            'email' => 'w175-c-'.strtolower(substr(uniqid(), -6)).'@example.test',
            'is_subscribed' => true,
        ]);
        $mass = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/subscribers/bulk-delete', [
            'ids' => [$c->id, 999999],
        ]);
        $mass->assertOk();
        $this->assertContains($c->id, $mass->json('deleted'));

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => '{ adminMarketingSubscribers(limit: 5) { id email is_subscribed } }',
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
    }
}
