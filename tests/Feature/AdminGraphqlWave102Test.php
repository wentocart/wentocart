<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Review;
use App\Domain\Order\Models\Order;
use App\Domain\Settings\ShopSetting;
use App\Domain\Shipping\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave102Test extends TestCase
{
    use RefreshDatabase;

    public function test_rest_reviews_cancel_invoices_shipments(): void
    {
        $this->seed();
        ShopSetting::put('customers.registration', true);
        ShopSetting::put('customers.account_activation', 'automatic');
        ShopSetting::put('customers.email_verification', 'disabled');
        ShopSetting::put('orders.allow_cancellation', true);
        ShopSetting::put('documents.invoices_enabled', true);

        $register = $this->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCustomer(
                    email: "wave102@example.com"
                    password: "Password123"
                    passwordConfirmation: "Password123"
                    firstName: "Wave"
                    lastName: "HundredTwo"
                ) { success accessToken customer { id } }
            }',
        ]);
        $register->assertOk();
        $this->assertArrayNotHasKey('errors', $register->json() ?? [], json_encode($register->json()));
        $token = (string) $register->json('data.createCustomer.accessToken');
        $this->assertNotSame('', $token);
        $customerId = (int) $register->json('data.createCustomer.customer.id');

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $review = Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customerId,
            'rating' => 5,
            'title' => 'Wave102 Review',
            'body' => 'Solid product.',
            'status' => Review::STATUS_APPROVED,
            'is_approved' => true,
        ]);

        $pending = Order::createTrusted([
            'number' => 'W102-PEND-1',
            'customer_id' => $customerId,
            'email' => 'wave102@example.com',
            'status' => 'pending',
            'currency_code' => 'USD',
            'payment_status' => 'pending',
            'grand_total' => 19,
            'billing_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
        ]);

        $paid = Order::createTrusted([
            'number' => 'W102-PAID-1',
            'customer_id' => $customerId,
            'email' => 'wave102@example.com',
            'status' => 'completed',
            'currency_code' => 'USD',
            'payment_status' => 'paid',
            'grand_total' => 44,
            'fulfillment_details' => ['invoice_number' => 'INV-W102-1'],
            'billing_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
            'shipping_address' => ['country_code' => 'US', 'first_name' => 'W', 'line1' => '1 Main'],
        ]);

        $shipment = Shipment::query()->create([
            'order_id' => $paid->id,
            'status' => Shipment::STATUS_SHIPPED,
            'carrier' => 'Wave102 Carrier',
            'tracking_number' => 'W102TRACK',
            'tracking_url' => 'https://track.example/W102TRACK',
        ]);

        $reviews = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{ customerReviews { id title rating status } }',
        ]);
        $reviews->assertOk();
        $this->assertArrayNotHasKey('errors', $reviews->json() ?? [], json_encode($reviews->json()));
        $titles = collect($reviews->json('data.customerReviews') ?? [])->pluck('title')->all();
        $this->assertContains('Wave102 Review', $titles);

        $oneReview = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) { customerReview(id: $id) { id title } }',
            'variables' => ['id' => (string) $review->id],
        ]);
        $oneReview->assertOk();
        $this->assertSame('Wave102 Review', (string) $oneReview->json('data.customerReview.title'));

        $cancel = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                createCancelOrder(number: "W102-PEND-1", comment: "Wave102 cancel") {
                    success message order { incrementId status }
                }
            }',
        ]);
        $this->assertSame(200, $cancel->status(), json_encode($cancel->json()));
        $this->assertArrayNotHasKey('errors', $cancel->json() ?? [], json_encode($cancel->json()));
        $this->assertTrue((bool) $cancel->json('data.createCancelOrder.success'));
        $this->assertSame('cancelled', (string) $cancel->json('data.createCancelOrder.order.status'));
        $this->assertSame('cancelled', (string) $pending->fresh()->status);

        $invoices = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{
                customerInvoices {
                    id invoiceNumber downloadUrl grandTotal status
                }
            }',
        ]);
        $invoices->assertOk();
        $this->assertArrayNotHasKey('errors', $invoices->json() ?? [], json_encode($invoices->json()));
        $invoiceNumbers = collect($invoices->json('data.customerInvoices') ?? [])->pluck('invoiceNumber')->all();
        $this->assertNotEmpty($invoiceNumbers);
        $this->assertTrue(
            collect($invoices->json('data.customerInvoices') ?? [])
                ->contains(fn ($row) => str_contains((string) ($row['downloadUrl'] ?? ''), 'W102-PAID-1'))
        );

        $oneInvoice = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                customerInvoice(id: $id) { id incrementId downloadUrl }
            }',
            'variables' => ['id' => (string) $paid->id],
        ]);
        $oneInvoice->assertOk();
        $this->assertArrayNotHasKey('errors', $oneInvoice->json() ?? [], json_encode($oneInvoice->json()));
        $this->assertSame('W102-PAID-1', (string) $oneInvoice->json('data.customerInvoice.incrementId'));

        $ships = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => '{
                customerOrderShipments {
                    id trackingNumber carrier status incrementId
                }
            }',
        ]);
        $ships->assertOk();
        $this->assertArrayNotHasKey('errors', $ships->json() ?? [], json_encode($ships->json()));
        $tracks = collect($ships->json('data.customerOrderShipments') ?? [])->pluck('trackingNumber')->all();
        $this->assertContains('W102TRACK', $tracks);

        $oneShip = $this->withToken($token)->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                customerOrderShipment(id: $id) { id trackingNumber carrier }
            }',
            'variables' => ['id' => (string) $shipment->id],
        ]);
        $oneShip->assertOk();
        $this->assertSame('W102TRACK', (string) $oneShip->json('data.customerOrderShipment.trackingNumber'));
    }
}
