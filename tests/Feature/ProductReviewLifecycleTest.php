<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Review;
use App\Domain\Order\Models\Order;
use App\Domain\Order\Models\OrderItem;
use App\Domain\Settings\MailEvents;
use App\Domain\Settings\ReviewSettings;
use App\Domain\Settings\ShopSetting;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductReviewLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_submit_review(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Nope',
            'body' => 'Guests should be blocked.',
        ])->assertUnauthorized();
    }

    public function test_disabled_reviews_are_rejected(): void
    {
        $this->seed();
        ShopSetting::put('reviews.enabled', false);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Off Reviews',
            'email' => 'off.reviews@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 4,
            'body' => 'Should fail.',
        ])->assertStatus(422);

        ShopSetting::put('reviews.enabled', true);
    }

    public function test_verified_only_blocks_non_buyers_and_allows_buyers(): void
    {
        $this->seed();
        ShopSetting::put('reviews.who', 'verified');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Buyer Only',
            'email' => 'buyer.only@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Blocked',
            'body' => 'I never bought this.',
        ])->assertStatus(422);

        $customerId = auth('customer')->id();
        $order = Order::createTrusted([
            'customer_id' => $customerId,
            'number' => 'REV-'.uniqid(),
            'status' => 'completed',
            'currency_code' => 'USD',
            'email' => 'buyer.only@example.com',
            'subtotal' => 10,
            'discount_total' => 0,
            'shipping_total' => 0,
            'tax_total' => 0,
            'grand_total' => 10,
            'payment_status' => 'paid',
            'amount_paid' => 10,
            'amount_due' => 0,
            'billing_address' => ['first_name' => 'Buyer', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
            'shipping_address' => ['first_name' => 'Buyer', 'line1' => '1 Main', 'city' => 'Austin', 'country_code' => 'US'],
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'name' => 'Demo',
            'sku' => $product->sku,
            'qty' => 1,
            'unit_price' => 10,
            'total' => 10,
        ]);

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Verified ok',
            'body' => 'I bought this and love it.',
        ])->assertCreated()
            ->assertJsonPath('review.is_verified_purchase', true);

        ShopSetting::put('reviews.who', 'registered');
    }

    public function test_automatic_moderation_publishes_immediately(): void
    {
        $this->seed();
        ShopSetting::put('reviews.moderation', 'automatic');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Auto Mod',
            'email' => 'auto.mod@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 4,
            'title' => 'Auto live',
            'body' => 'Published without staff.',
        ])->assertCreated()
            ->assertJsonPath('review.status', Review::STATUS_APPROVED);

        $this->getJson('/api/v1/catalog/product/demo-cotton-tee')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Auto live']);

        ShopSetting::put('reviews.moderation', 'required');
    }

    public function test_update_preserves_photo_and_paginated_endpoint_works(): void
    {
        $this->seed();
        Storage::fake('public');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Photo Reviewer',
            'email' => 'photo.review@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $file = UploadedFile::fake()->image('look.jpg', 200, 200);
        $this->post('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'With photo',
            'body' => 'Photo attached.',
            'photo' => $file,
        ], ['Accept' => 'application/json'])->assertCreated();

        $review = Review::query()->where('title', 'With photo')->firstOrFail();
        $this->assertNotEmpty($review->photo_path);

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 4,
            'title' => 'With photo updated',
            'body' => 'Still keep media.',
        ])->assertCreated();

        $this->assertSame($review->photo_path, $review->fresh()->photo_path);

        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin')
            ->post('/admin/reviews/'.$review->id, ['action' => 'approve'])
            ->assertRedirect();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/catalog/product/demo-cotton-tee/reviews?sort=highest')
            ->assertOk()
            ->assertJsonPath('meta.sort', 'highest')
            ->assertJsonFragment(['title' => 'With photo updated']);
    }

    public function test_admin_can_edit_review_content(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Edit Target',
            'email' => 'edit.target@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        $customerId = (int) auth('customer')->id();

        $review = Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customerId,
            'rating' => 3,
            'title' => 'Old',
            'body' => 'Old body',
            'status' => Review::STATUS_PENDING,
            'is_approved' => false,
        ]);

        $this->actingAs(Admin::query()->first(), 'admin')
            ->post('/admin/reviews/'.$review->id, [
                'action' => 'update',
                'rating' => 5,
                'title' => 'Edited',
                'body' => 'Edited body',
            ])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(5, (int) $review->rating);
        $this->assertSame('Edited', $review->title);
        $this->assertSame('Edited body', $review->body);
    }

    public function test_eligibility_endpoint_and_mail_events_exist(): void
    {
        $this->seed();
        MailEvents::ensure();
        $this->assertContains(MailEvents::PRODUCT_REVIEW_SUBMITTED, MailEvents::codes());
        $this->assertContains(MailEvents::PRODUCT_REVIEW_REPLIED, MailEvents::codes());

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Elig',
            'email' => 'elig@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->getJson('/api/v1/account/reviews/eligibility?product_id='.$product->id)
            ->assertOk()
            ->assertJsonPath('eligibility.allowed', true);
    }

    public function test_multiple_images_video_and_admin_media_update(): void
    {
        $this->seed();
        Storage::fake('public');
        ShopSetting::put('reviews.media', 'video');
        ShopSetting::put('reviews.moderation', 'automatic');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Media Reviewer',
            'email' => 'media.review@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->post('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Gallery',
            'body' => 'Many images and a clip.',
            'photos' => [
                UploadedFile::fake()->image('a.jpg', 120, 120),
                UploadedFile::fake()->image('b.jpg', 120, 120),
            ],
            'video' => UploadedFile::fake()->create('clip.mp4', 400, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $review = Review::query()->where('title', 'Gallery')->firstOrFail();
        $this->assertCount(2, $review->imagePaths());
        $this->assertNotEmpty($review->videoPath());
        $this->assertNotEmpty($review->photo_path);

        $keepOne = $review->imagePaths()[0];
        $dropOne = $review->imagePaths()[1];
        $this->post('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 4,
            'title' => 'Gallery updated',
            'body' => 'Removed one image and video.',
            'remove_images' => [$dropOne],
            'remove_video' => '1',
            'photos' => [
                UploadedFile::fake()->image('c.jpg', 100, 100),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $review->refresh();
        $this->assertContains($keepOne, $review->imagePaths());
        $this->assertNotContains($dropOne, $review->imagePaths());
        $this->assertCount(2, $review->imagePaths());
        $this->assertNull($review->videoPath());

        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin')
            ->post('/admin/reviews/'.$review->id, [
                'action' => 'update',
                'rating' => 5,
                'title' => 'Admin media',
                'body' => 'Admin replaced video',
                'remove_images' => $review->imagePaths(),
                'video' => UploadedFile::fake()->create('admin.mp4', 300, 'video/mp4'),
            ])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame([], $review->imagePaths());
        $this->assertNotEmpty($review->videoPath());
        $this->assertNull($review->photo_path);

        ShopSetting::put('reviews.media', 'images');
        ShopSetting::put('reviews.moderation', 'required');
    }

    public function test_review_update_accepts_product_id_from_query_when_body_empty(): void
    {
        $this->seed();
        ShopSetting::put('reviews.moderation', 'automatic');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Query Id',
            'email' => 'query.id@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews?product_id='.$product->id.'&rating=4', [
            'title' => 'Via query',
            'body' => 'Body fields only; product_id came from query string.',
        ])->assertCreated()
            ->assertJsonPath('review.product_id', $product->id)
            ->assertJsonPath('review.rating', 4);

        ShopSetting::put('reviews.moderation', 'required');
    }

    public function test_partial_update_without_title_body_preserves_existing_text(): void
    {
        $this->seed();
        ShopSetting::put('reviews.moderation', 'automatic');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Keep Text',
            'email' => 'keep.text@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Keep me',
            'body' => 'Please do not wipe this body text.',
        ])->assertCreated();

        // Simulate a broken multipart body where only query product_id/rating arrive.
        $this->postJson('/api/v1/account/reviews?product_id='.$product->id.'&rating=4', [])
            ->assertCreated();

        $review = Review::query()
            ->where('product_id', $product->id)
            ->where('title', 'Keep me')
            ->firstOrFail();
        $this->assertSame('Keep me', $review->title);
        $this->assertSame('Please do not wipe this body text.', $review->body);
        $this->assertSame(4, (int) $review->rating);

        ShopSetting::put('reviews.moderation', 'required');
    }

    public function test_media_none_rejects_uploads(): void
    {
        $this->seed();
        Storage::fake('public');
        ShopSetting::put('reviews.media', 'none');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'No Media',
            'email' => 'no.media@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->post('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Blocked media',
            'body' => 'Should reject photo.',
            'photos' => [UploadedFile::fake()->image('x.jpg', 80, 80)],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        ShopSetting::put('reviews.media', 'images');
    }

    public function test_min_length_and_invalid_product_are_validated(): void
    {
        $this->seed();
        ShopSetting::put('reviews.min_length', 20);
        $this->assertSame(20, ReviewSettings::minLength());

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Shorty',
            'email' => 'shorty@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'body' => 'too short',
        ])->assertCreated();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'body' => str_repeat('x', 25),
        ])->assertStatus(422);

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => 999999,
            'rating' => 5,
            'body' => 'Fits the limit.',
        ])->assertStatus(422);

        ShopSetting::put('reviews.min_length', 0);
    }

    public function test_customers_can_vote_once_and_guests_cannot(): void
    {
        $this->seed();
        ShopSetting::put('reviews.moderation', 'automatic');
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Author',
            'email' => 'vote.author@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 5,
            'title' => 'Vote target',
            'body' => 'Please vote on this review.',
        ])->assertCreated();

        $review = Review::query()->where('title', 'Vote target')->firstOrFail();
        $this->postJson('/api/v1/account/reviews/'.$review->id.'/vote', ['helpful' => true])
            ->assertStatus(422);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->postJson('/api/v1/account/reviews/'.$review->id.'/vote', ['helpful' => true])
            ->assertUnauthorized();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Voter',
            'email' => 'vote.reader@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews/'.$review->id.'/vote', ['helpful' => true])
            ->assertOk()
            ->assertJsonPath('helpful_count', 1)
            ->assertJsonPath('not_helpful_count', 0)
            ->assertJsonPath('my_vote', true);

        $this->postJson('/api/v1/account/reviews/'.$review->id.'/vote', ['helpful' => true])
            ->assertOk()
            ->assertJsonPath('helpful_count', 1);

        $this->postJson('/api/v1/account/reviews/'.$review->id.'/vote', ['helpful' => false])
            ->assertOk()
            ->assertJsonPath('helpful_count', 0)
            ->assertJsonPath('not_helpful_count', 1)
            ->assertJsonPath('my_vote', false);

        $review->refresh();
        $this->assertSame(0, (int) $review->helpful_count);
        $this->assertSame(1, (int) $review->not_helpful_count);

        $this->getJson('/api/v1/catalog/product/demo-cotton-tee/reviews')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $review->id,
                'not_helpful_count' => 1,
                'helpful_count' => 0,
                'my_vote' => false,
            ]);

        ShopSetting::put('reviews.moderation', 'required');
    }

    public function test_zero_minimum_stores_a_rating_without_the_written_review(): void
    {
        $this->seed();
        ShopSetting::put('reviews.min_length', 0);
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Stars Only',
            'email' => 'stars.only@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 4,
            'title' => 'Hidden',
            'body' => 'Text should not save.',
        ])->assertStatus(422);

        $this->postJson('/api/v1/account/reviews', [
            'product_id' => $product->id,
            'rating' => 4,
        ])->assertCreated();

        $saved = Review::query()->where('product_id', $product->id)->first();
        $this->assertSame(4, (int) $saved->rating);
        $this->assertNull($saved->title);
        $this->assertNull($saved->body);
    }

    public function test_guests_review_only_when_everyone_can_review(): void
    {
        $this->seed();
        $product = Product::query()->where('sku', 'DEMO-001')->firstOrFail();
        ShopSetting::put('reviews.who', 'registered');

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/reviews', [
            'rating' => 5,
            'guest_name' => 'Pat Guest',
            'guest_email' => 'pat.guest@example.com',
            'body' => 'Guest note',
        ])->assertStatus(422);

        ShopSetting::put('reviews.who', 'everyone');
        $this->postJson('/api/v1/catalog/product/'.$product->id.'/reviews', [
            'rating' => 5,
            'guest_name' => 'Pat Guest',
            'guest_email' => 'pat.guest@example.com',
            'body' => 'Guest note',
        ])->assertCreated();

        $saved = Review::query()->where('guest_email', 'pat.guest@example.com')->first();
        $this->assertNotNull($saved);
        $this->assertNull($saved->customer_id);
        $this->assertSame('Pat Guest', $saved->authorName());

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Same Email',
            'email' => 'same.email@example.com',
            'password' => 'Password1a',
            'password_confirmation' => 'Password1a',
        ])->assertOk();
        auth('customer')->logout();

        $this->postJson('/api/v1/catalog/product/'.$product->id.'/reviews', [
            'rating' => 4,
            'guest_name' => 'Same Email',
            'guest_email' => 'same.email@example.com',
            'body' => 'Should sign in',
        ])->assertStatus(422);
    }
}
