<?php

namespace Tests\Feature;

use App\Domain\Auth\Models\AdminImpersonation;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\CustomerNoteSchema;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerNote;
use App\Domain\Customer\Models\Review;
use App\Domain\Customer\Services\CustomerDeskService;
use App\Domain\Order\Models\Order;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerDeskTest extends TestCase
{
    use RefreshDatabase;

    public function test_desk_timeline_merges_notes_orders_reviews_and_impersonations(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();
        $suffix = strtolower(substr(uniqid(), -6));
        $customer = Customer::query()->create([
            'name' => 'Desk Customer',
            'email' => 'desk-'.$suffix.'@example.test',
            'password' => Hash::make('Password1!'),
            'is_enabled' => true,
        ]);

        CustomerNoteSchema::ensure();
        CustomerNote::query()->create([
            'customer_id' => $customer->id,
            'admin_id' => $admin->id,
            'note' => 'Called about delayed shipment',
            'customer_notified' => false,
        ]);

        $addr = ['country_code' => 'US', 'line1' => '1 Desk St', 'city' => 'Austin', 'postal_code' => '78701'];
        Order::createTrusted([
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'number' => 'DESK-'.strtoupper($suffix),
            'status' => 'processing',
            'payment_status' => 'pending',
            'currency_code' => 'USD',
            'subtotal' => 42.5,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'fee_total' => 0,
            'grand_total' => 42.5,
            'refunded_total' => 0,
            'amount_paid' => 0,
            'amount_due' => 42.5,
            'billing_address' => $addr,
            'shipping_address' => $addr,
        ]);

        $product = Product::query()->where('is_enabled', true)->first();
        if ($product && Schema::hasTable('reviews')) {
            Review::query()->create([
                'customer_id' => $customer->id,
                'product_id' => $product->id,
                'rating' => 5,
                'title' => 'Great',
                'body' => 'Loved it',
                'is_approved' => true,
                'status' => Review::STATUS_APPROVED,
            ]);
        }

        AdminImpersonation::query()->create([
            'admin_id' => $admin->id,
            'customer_id' => $customer->id,
            'started_at' => now(),
            'ip' => '127.0.0.1',
        ]);

        $desk = app(CustomerDeskService::class)->timeline($customer, 40);
        $types = collect($desk['events'])->pluck('type')->unique()->values()->all();

        $this->assertSame((int) $customer->id, (int) $desk['customer_id']);
        $this->assertGreaterThanOrEqual(1, (int) $desk['summary']['notes']);
        $this->assertGreaterThanOrEqual(1, (int) $desk['summary']['orders']);
        $this->assertGreaterThanOrEqual(1, (int) $desk['summary']['impersonations']);
        $this->assertContains('note', $types);
        $this->assertContains('order', $types);
        $this->assertContains('impersonation', $types);
    }

    public function test_rest_and_graphql_desk_activity_and_admin_ui(): void
    {
        $this->seed();
        $admin = Admin::query()->firstOrFail();

        $email = 'desk-api-'.strtolower(substr(uniqid(), -6)).'@example.test';
        $customerId = (string) $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'mutation ($e: String!) {
                createCustomer(email: $e, firstName: "Desk", lastName: "Api", password: "Password1!") { id }
            }',
            'variables' => ['e' => $email],
        ])->json('data.createCustomer.id');
        $this->assertNotSame('', $customerId);

        $this->actingAs($admin, 'admin')->postJson('/api/v1/console/customers/'.$customerId.'/notes', [
            'note' => 'Desk REST note',
            'customer_notified' => false,
        ])->assertCreated();

        $rest = $this->actingAs($admin, 'admin')->getJson('/api/v1/console/customers/'.$customerId.'/activity');
        $rest->assertOk();
        $this->assertSame((int) $customerId, (int) $rest->json('customer_id'));
        $this->assertGreaterThanOrEqual(1, (int) $rest->json('summary.notes'));
        $this->assertNotEmpty($rest->json('events'));
        $this->assertTrue(
            collect($rest->json('events'))->contains(fn ($e) => ($e['type'] ?? '') === 'note'),
        );

        $gql = $this->actingAs($admin, 'admin')->postJson('/api/v1/console/graphql', [
            'query' => 'query ($cid: ID!) {
                adminCustomerDesk(customerId: $cid, limit: 20) {
                    customerId
                    summary { notes orders reviews gdpr impersonations }
                    events { id type title body href metaJson }
                }
            }',
            'variables' => ['cid' => $customerId],
        ]);
        $gql->assertOk();
        $this->assertArrayNotHasKey('errors', $gql->json() ?? [], json_encode($gql->json()));
        $this->assertSame($customerId, (string) $gql->json('data.adminCustomerDesk.customerId'));
        $this->assertGreaterThanOrEqual(1, (int) $gql->json('data.adminCustomerDesk.summary.notes'));
        $this->assertNotEmpty($gql->json('data.adminCustomerDesk.events'));

        $show = $this->actingAs($admin, 'admin')->get('/admin/customers/'.$customerId);
        $show->assertOk();
        $show->assertInertia(fn ($page) => $page
            ->component('Customers/Show')
            ->has('desk.events')
            ->where('desk.customer_id', (int) $customerId)
            ->where('desk.summary.notes', fn ($n) => (int) $n >= 1));

        $this->actingAs($admin, 'admin')->post('/admin/customers/'.$customerId.'/desk-notes', [
            'note' => 'Inertia desk follow-up',
            'customer_notified' => false,
        ])->assertRedirect();

        $this->assertDatabaseHas('customer_notes', [
            'customer_id' => (int) $customerId,
            'note' => 'Inertia desk follow-up',
        ]);
    }
}
