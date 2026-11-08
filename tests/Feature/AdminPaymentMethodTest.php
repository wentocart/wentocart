<?php

namespace Tests\Feature;

use App\Domain\Payment\Models\PaymentMethod;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_filter_sort_and_export_payment_methods(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $methodA = PaymentMethod::query()->create([
            'code' => 'bank_wire',
            'name' => 'Bank Wire',
            'driver' => 'offline',
            'description' => 'Pay by bank transfer',
            'is_enabled' => true,
            'position' => 1,
        ]);

        $methodB = PaymentMethod::query()->create([
            'code' => 'paypal_express',
            'name' => 'PayPal Express',
            'driver' => 'paypal',
            'description' => 'Pay with your PayPal account',
            'is_enabled' => true,
            'position' => 2,
        ]);

        // List payment methods
        $this->get('/admin/payments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payments/Index', false)
                ->has('methods.data')
            );

        // Filter by driver
        $this->get('/admin/payments?driver=paypal')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payments/Index', false)
                ->where('filters.driver', 'paypal')
                ->where('methods.data.0.driver', 'paypal')
            );

        // Sort by driver asc
        $this->get('/admin/payments?sort=driver&dir=asc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payments/Index', false)
                ->where('filters.sort', 'driver')
                ->where('filters.dir', 'asc')
            );

        // Sort by description desc
        $this->get('/admin/payments?sort=description&dir=desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payments/Index', false)
                ->where('filters.sort', 'description')
                ->where('filters.dir', 'desc')
            );

        // Export with ids
        $response = $this->get('/admin/payments/export?ids='.$methodA->id);
        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('Bank Wire', $content);
        $this->assertStringNotContainsString('PayPal Express', $content);
    }
}
