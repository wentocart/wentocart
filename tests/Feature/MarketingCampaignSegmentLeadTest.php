<?php

namespace Tests\Feature;

use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Marketing\Models\NewsletterSubscriber;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MarketingCampaignSegmentLeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_preview_and_send_respect_customer_group_segment(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $retailer = CustomerGroup::query()->where('code', 'retailer')->first()
            ?? CustomerGroup::query()->create([
                'name' => 'Retailer',
                'code' => 'retailer',
                'is_enabled' => true,
            ]);
        $wholesale = CustomerGroup::query()->where('code', 'wholesale')->first()
            ?? CustomerGroup::query()->create([
                'name' => 'Wholesale',
                'code' => 'wholesale',
                'is_enabled' => true,
            ]);

        Customer::createTrusted([
            'name' => 'Retail Sam',
            'email' => 'retail-seg@example.test',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
            'customer_group' => $retailer->code,
            'newsletter' => true,
        ]);
        Customer::createTrusted([
            'name' => 'Wholesale Pat',
            'email' => 'wholesale-seg@example.test',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
            'customer_group' => $wholesale->code,
            'newsletter' => true,
        ]);

        NewsletterSubscriber::query()->create(['email' => 'retail-seg@example.test', 'is_subscribed' => true]);
        NewsletterSubscriber::query()->create(['email' => 'wholesale-seg@example.test', 'is_subscribed' => true]);
        NewsletterSubscriber::query()->create(['email' => 'guest-seg@example.test', 'is_subscribed' => true]);

        $create = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/marketing/campaigns', [
            'name' => 'Retail only blast',
            'content' => '<p>Hello retailers</p>',
            'status' => 'draft',
            'customer_group' => $retailer->code,
        ]);
        $create->assertCreated();
        $id = (int) $create->json('id');
        $this->assertSame($retailer->code, (string) $create->json('customerGroup'));

        $preview = $this->actingAs($admin, 'admin')
            ->getJson('/api/v1/console/marketing/campaigns/'.$id.'/preview-audience');
        $preview->assertOk();
        $this->assertSame(1, (int) $preview->json('recipientCount'));
        $this->assertSame($retailer->code, (string) $preview->json('customerGroup'));

        $send = $this->actingAs($admin, 'admin')
            ->postJson('/api/v1/console/marketing/campaigns/'.$id.'/send');
        $send->assertOk();
        $this->assertSame(1, (int) $send->json('sent_count'));
        $this->assertSame($retailer->code, (string) $send->json('customerGroup'));
    }
}
