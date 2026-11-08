<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\ProductQuestion;
use App\Domain\Customer\Models\Review;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Money;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewQaCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviews_stay_hidden_until_admin_approves(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Riley Reviewer',
            'email' => 'riley@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Keep this one',
            'body' => 'Comfortable and exactly as listed.',
        ])->assertCreated();

        $this->assertDatabaseHas('reviews', [
            'title' => 'Keep this one',
            'status' => Review::STATUS_PENDING,
            'is_approved' => 0,
        ]);

        $this->getJson('/api/v1/catalog/product/demo-cotton-tee')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Keep this one']);

        $review = Review::query()->where('title', 'Keep this one')->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/reviews/'.$review->id, ['action' => 'approve'])
            ->assertRedirect();

        $this->assertSame(Review::STATUS_APPROVED, $review->fresh()->status);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/reviews/'.$review->id, ['action' => 'reply', 'admin_reply' => 'Glad it worked for you.'])
            ->assertRedirect();

        $this->assertSame('Glad it worked for you.', $review->fresh()->admin_reply);

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/catalog/product/demo-cotton-tee')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Keep this one', 'admin_reply' => 'Glad it worked for you.']);
    }

    public function test_product_questions_need_moderation_before_they_appear(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Quinn Question',
            'email' => 'quinn@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/questions', [
            'product_id' => $product->id,
            'body' => 'Does this include a spare charging cable?',
        ])->assertCreated();

        $this->getJson('/api/v1/catalog/product/demo-cotton-tee')
            ->assertOk()
            ->assertJsonMissing(['body' => 'Does this include a spare charging cable?']);

        $question = ProductQuestion::query()->where('body', 'Does this include a spare charging cable?')->firstOrFail();
        $this->actingAs(Admin::query()->first(), 'admin')
            ->from('/admin/questions')
            ->post('/admin/questions/'.$question->id, [
                'action' => 'answer',
                'body' => 'Yes, a spare cable is in the box.',
            ])
            ->assertRedirect();

        $this->getJson('/api/v1/catalog/product/demo-cotton-tee')
            ->assertOk()
            ->assertJsonFragment(['body' => 'Does this include a spare charging cable?'])
            ->assertJsonFragment(['body' => 'Yes, a spare cable is in the box.']);
    }

    public function test_currency_switcher_converts_catalog_prices(): void
    {
        $this->seed();
        Currency::query()->where('code', 'INR')->update(['exchange_rate' => 83, 'is_enabled' => true]);
        Money::forgetCache();

        $this->assertEquals(2489.17, Money::convert(29.99, 'USD', 'INR'));

        $this->postJson('/api/v1/i18n/currency', ['currency' => 'INR'])
            ->assertOk()
            ->assertJsonPath('currency', 'INR');

        $this->getJson('/api/v1/store?currency=INR')
            ->assertOk()
            ->assertJsonPath('currency', 'INR');

        $this->getJson('/api/v1/catalog/product/demo-cotton-tee?currency=INR')
            ->assertOk()
            ->assertJsonPath('product.price', 2489.17)
            ->assertJsonPath('product.price_formatted', '₹2,489.17');
    }
}
