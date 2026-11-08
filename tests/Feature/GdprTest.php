<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\GdprRequest;
use App\Domain\Customer\Services\CustomerAnonymizer;
use App\Domain\Localization\Models\Language;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\GdprSettings;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GdprTest extends TestCase
{
    use RefreshDatabase;

    public function test_gdpr_update_requests_are_no_longer_accepted(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        $customer = Customer::factory()->create([
            'name' => 'Ada GDPR',
            'email' => 'ada.gdpr@example.com',
        ]);

        // Rectification lives in My Account now; the request flow is delete-only.
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr', [
                'type' => 'update',
                'message' => 'Please correct my phone number.',
                'fields' => ['first_name' => 'Ada'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->assertDatabaseCount('gdpr_requests', 0);
    }

    public function test_a_delete_request_requires_a_reason(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        $customer = Customer::factory()->create(['first_name' => 'Ada', 'last_name' => 'GDPR']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr', ['type' => 'delete'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');
    }

    public function test_delete_requests_get_a_unique_reference_shown_to_customer_and_admin(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'ref-me@example.com']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr', ['type' => 'delete', 'message' => 'Please erase my account'])
            ->assertCreated()
            ->assertJsonStructure(['reference']);

        $request = GdprRequest::query()->firstOrFail();
        $this->assertMatchesRegularExpression('/^GDPR-[A-Z0-9]{8}$/', (string) $request->reference);

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/gdpr')
            ->assertOk()
            ->assertJsonPath('requests.0.reference', $request->reference);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->get('/admin/gdpr-requests?reference='.$request->reference)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/GdprRequests')
                ->where('requests.data.0.reference', $request->reference));
    }

    public function test_a_second_delete_request_is_blocked_while_one_is_open(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'one-request@example.com']);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr', ['type' => 'delete', 'message' => 'Please erase my account'])
            ->assertCreated();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr', ['type' => 'delete', 'message' => 'Please erase my account again'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');

        $this->assertDatabaseCount('gdpr_requests', 1);
    }

    public function test_completing_a_delete_request_erases_the_account_and_keeps_the_request(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        Mail::fake();
        $customer = Customer::factory()->create(['email' => 'erase-me@example.com', 'name' => 'Erase Me']);
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr', ['type' => 'delete', 'message' => 'Please erase my account'])
            ->assertCreated();
        $request = GdprRequest::query()->firstOrFail();

        $this->actingAs(Admin::query()->first(), 'admin')
            ->put('/admin/gdpr-requests/'.$request->id, ['status' => 'completed'])
            ->assertRedirect('/admin/gdpr-requests');

        $fresh = $customer->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame('deleted-'.$customer->id.'@anonymized.invalid', $fresh->email);
        $this->assertFalse((bool) $fresh->is_enabled);
        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame('[removed after account deletion]', $request->fresh()->message);
        $this->assertNull($request->fresh()->requested_changes);
    }

    public function test_erase_wipes_chats_tickets_credentials_auth_material_and_cancels_subscriptions(): void
    {
        $this->seed();
        $customer = Customer::factory()->create([
            'email' => 'wipe-me@example.com',
            'totp_secret' => 'TOPSECRET',
            'totp_confirmed_at' => now(),
            'google_id' => 'google-123',
            'remember_token' => 'remember-me',
        ]);
        $id = (int) $customer->id;
        $product = \App\Domain\Catalog\Models\Product::query()->where('is_enabled', true)->firstOrFail();

        DB::table('ai_customer_chats')->insert([
            ['customer_id' => $id, 'visitor_token' => 'v1', 'name' => 'Wipe Me', 'email' => 'wipe-me@example.com', 'title' => 'Help', 'messages_json' => '[]'],
            ['customer_id' => null, 'visitor_token' => 'v2', 'name' => 'Wipe Me', 'email' => 'wipe-me@example.com', 'title' => 'Help', 'messages_json' => '[]'],
            ['customer_id' => null, 'visitor_token' => 'v3', 'name' => 'Someone Else', 'email' => 'other@example.com', 'title' => 'Hi', 'messages_json' => '[]'],
        ]);
        $ticketId = DB::table('support_tickets')->insertGetId([
            'customer_id' => $id, 'name' => 'Wipe Me', 'email' => 'wipe-me@example.com', 'body' => 'My order is late',
        ]);
        $guestTicketId = DB::table('support_tickets')->insertGetId([
            'customer_id' => null, 'name' => 'Wipe Me', 'email' => 'wipe-me@example.com', 'body' => 'Guest question',
        ]);
        DB::table('support_ticket_replies')->insert([
            ['support_ticket_id' => $ticketId, 'author' => 'customer', 'body' => 'Any update?'],
            ['support_ticket_id' => $ticketId, 'author' => 'staff', 'body' => 'On its way.'],
        ]);
        $customer->webauthnCredentials()->create([
            'credential_id' => 'cred-1', 'public_key' => 'pk', 'sign_count' => 0,
        ]);
        DB::table('reviews')->insert([
            'customer_id' => $id, 'product_id' => $product->id, 'rating' => 5, 'body' => 'Great!',
        ]);
        DB::table('price_alerts')->insert([
            'customer_id' => $id, 'product_id' => $product->id, 'last_price' => 9.99, 'email' => 'wipe-me@example.com',
        ]);
        DB::table('preorder_notify_subscriptions')->insert([
            'customer_id' => $id, 'product_id' => $product->id,
        ]);
        $cartId = DB::table('carts')->insertGetId(['customer_id' => $id]);
        DB::table('payment_checkouts')->insert([
            'token' => 'pay-1', 'cart_id' => $cartId, 'customer_id' => $id, 'email' => 'wipe-me@example.com',
            'payment_method_id' => 0, 'driver' => 'test', 'amount' => 10,
        ]);
        $order = Order::createTrusted([
            'number' => 'GDPR-WIPE', 'customer_id' => $id, 'email' => 'wipe-me@example.com',
            'status' => 'pending', 'currency_code' => 'USD', 'payment_status' => 'pending',
            'billing_address' => ['country_code' => 'US'],
            'shipping_address' => ['country_code' => 'US'],
        ]);
        $item = $order->items()->create([
            'product_id' => $product->id, 'sku' => 'SKU-1', 'name' => 'Demo', 'qty' => 1, 'unit_price' => 10, 'total' => 10,
        ]);
        DB::table('customer_subscriptions')->insert([
            'customer_id' => $id, 'order_id' => $order->id, 'order_item_id' => $item->id,
            'product_id' => $product->id, 'status' => 'active',
        ]);
        DB::table('gift_cards')->insert([
            'product_id' => $product->id, 'customer_id' => $id, 'code' => 'GIFT-1',
            'initial_balance' => 25, 'current_balance' => 25, 'selected_amount' => 25,
            'recipient_name' => 'Friend', 'recipient_email' => 'friend@example.com',
            'sender_name' => 'Wipe Me', 'gift_message' => 'Happy birthday!',
            'status' => 'active',
        ]);
        DB::table('b2b_quotes')->insert([
            'customer_id' => $id, 'number' => 'Q-WIPE', 'note' => 'Call me at 555-0100',
        ]);

        app(CustomerAnonymizer::class)->erase($customer);

        // Conversations and credentials are gone, including email-matched rows.
        $this->assertDatabaseMissing('ai_customer_chats', ['visitor_token' => 'v1']);
        $this->assertDatabaseMissing('ai_customer_chats', ['visitor_token' => 'v2']);
        $this->assertDatabaseHas('ai_customer_chats', ['visitor_token' => 'v3']);
        $this->assertDatabaseMissing('support_tickets', ['id' => $ticketId]);
        $this->assertDatabaseMissing('support_tickets', ['id' => $guestTicketId]);
        $this->assertDatabaseMissing('support_ticket_replies', ['support_ticket_id' => $ticketId]);
        $this->assertDatabaseMissing('webauthn_credentials', ['credential_id' => 'cred-1']);
        $this->assertDatabaseMissing('product_answers', ['customer_id' => $id]);
        $this->assertDatabaseMissing('reviews', ['customer_id' => $id]);
        $this->assertDatabaseMissing('price_alerts', ['customer_id' => $id]);
        $this->assertDatabaseMissing('preorder_notify_subscriptions', ['customer_id' => $id]);
        $this->assertDatabaseMissing('carts', ['customer_id' => $id]);
        $this->assertDatabaseMissing('payment_checkouts', ['customer_id' => $id]);

        // Auth material and social links are cleared on the kept row.
        $fresh = $customer->fresh();
        $this->assertNull($fresh->totp_secret);
        $this->assertNull($fresh->totp_confirmed_at);
        $this->assertNull($fresh->google_id);
        $this->assertNull($fresh->remember_token);
        $this->assertNull($fresh->email_verified_at);
        $this->assertFalse((bool) $fresh->is_enabled);

        // Business records survive with personal content removed.
        $this->assertSame('cancelled', DB::table('customer_subscriptions')->where('customer_id', $id)->value('status'));
        $card = DB::table('gift_cards')->where('customer_id', $id)->first();
        $this->assertSame('Deleted customer', $card->sender_name);
        $this->assertNull($card->gift_message);
        $this->assertSame('friend@example.com', $card->recipient_email);
        $this->assertNull(DB::table('b2b_quotes')->where('customer_id', $id)->value('note'));
        $this->assertSame('deleted-'.$id.'@anonymized.invalid', $order->fresh()->email);
    }

    public function test_customer_can_revoke_only_a_pending_request(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        Mail::fake();
        $customer = Customer::factory()->create();
        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr', ['type' => 'delete', 'message' => 'Delete me'])
            ->assertCreated();
        $request = GdprRequest::query()->firstOrFail();

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr/'.$request->id.'/revoke')
            ->assertOk()
            ->assertJsonPath('status', 'deleted');

        $this->assertDatabaseMissing('gdpr_requests', ['id' => $request->id]);

        $this->actingAs($customer, 'customer')
            ->postJson('/api/v1/account/gdpr/'.$request->id.'/revoke')
            ->assertNotFound();
    }

    public function test_deleting_a_customer_keeps_the_order_and_clears_personal_address_fields(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['email' => 'keep-order@example.com', 'name' => 'Keep Order']);
        $order = Order::createTrusted([
            'number' => 'GDPR-1',
            'customer_id' => $customer->id,
            'email' => 'keep-order@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'customer_note' => 'Leave at the blue door',
            'billing_address' => ['first_name' => 'Ada', 'line1' => '1 Secret St', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Ada', 'line1' => '1 Secret St', 'city' => 'Austin', 'country_code' => 'US'],
        ]);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->delete('/admin/customers/'.$customer->id)
            ->assertRedirect();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $fresh = $order->fresh();
        $this->assertNotNull($fresh);
        $this->assertNull($fresh->customer_id);
        $this->assertSame('deleted-'.$customer->id.'@anonymized.invalid', $fresh->email);
        $this->assertSame('US', $fresh->billing_address['country_code'] ?? null);
        $this->assertArrayNotHasKey('first_name', $fresh->billing_address);
        $this->assertArrayNotHasKey('line1', $fresh->shipping_address);
        $this->assertNull($fresh->customer_note);
    }

    public function test_signup_requires_the_gdpr_agreement_when_it_is_on(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        ShopSetting::put('gdpr.agreement_enabled', true);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'No Agree',
            'email' => 'no-agree@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertStatus(422)->assertJsonValidationErrors('gdpr_agreed');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Yes Agree',
            'email' => 'yes-agree@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
            'gdpr_agreed' => true,
        ])->assertOk();
    }

    public function test_customer_can_download_their_data_as_html_and_pdf(): void
    {
        $this->seed();
        ShopSetting::put('gdpr.enabled', true);
        $customer = Customer::factory()->create(['email' => 'export-me@example.com', 'name' => 'Export Me']);

        $this->actingAs($customer, 'customer')
            ->get('/api/v1/account/gdpr/export.html')
            ->assertOk()
            ->assertSee('export-me@example.com', false);

        $this->actingAs($customer, 'customer')
            ->get('/api/v1/account/gdpr/export.pdf')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_gdpr_requests_stay_hidden_until_gdpr_is_enabled(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'customer')
            ->getJson('/api/v1/account/gdpr')
            ->assertNotFound();
    }

    public function test_gdpr_titles_are_per_language_and_the_body_is_a_cms_page(): void
    {
        $this->seed();
        $default = Language::defaultCode();
        $terms = CmsPage::query()->create([
            'is_enabled' => true,
            'is_visible' => true,
            'status' => CmsPage::STATUS_PUBLISHED,
        ]);
        $terms->translations()->create([
            'language_code' => $default,
            'title' => 'Shop terms',
            'slug' => 'shop-terms',
            'body' => 'The agreement body.',
        ]);

        $copy = [];
        foreach (Language::enabledList() as $language) {
            $copy[$language->code] = [
                'agreement_title' => 'Agree '.$language->code,
                'cookie_title' => 'Cookies '.$language->code,
                'strictly_necessary' => 'Needed '.$language->code,
                'basic' => 'Basic '.$language->code,
                'experience' => 'Experience '.$language->code,
                'measurements' => 'Measure '.$language->code,
                'targeting' => 'Ads '.$language->code,
            ];
        }

        $this->actingAs(Admin::query()->first(), 'admin')
            ->put('/admin/gdpr', [
                'enabled' => true,
                'agreement_enabled' => true,
                'agreement_page_id' => $terms->id,
                'cookie_notice' => true,
                'cookie_position' => 'bottom_left',
                'cookie_page_id' => $terms->id,
                'copy' => $copy,
            ])
            ->assertRedirect();

        $this->actingAs(Admin::query()->first(), 'admin')
            ->get('/admin/gdpr')
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->component('Settings/Gdpr')
                ->where('agreement_page_id', $terms->id)
                ->where('cookie_page_id', $terms->id)
                ->where('copy.'.$default.'.agreement_title', 'Agree '.$default)
                ->where('cms_pages.0.slug', 'shop-terms'));

        $payload = GdprSettings::storefrontPayload();
        $this->assertSame('Agree '.$default, $payload['agreement_title']);
        $this->assertSame('shop-terms', $payload['agreement_page']['slug']);
        $this->assertSame('Shop terms', $payload['agreement_page']['title']);
        $this->assertSame('Cookies '.$default, $payload['cookie_title']);
        $this->assertSame('Needed '.$default, $payload['categories']['strictly_necessary']);
    }
}
