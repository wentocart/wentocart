<?php

namespace Tests\Feature;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminGraphqlWave112Test extends TestCase
{
    use RefreshDatabase;

    public function test_product_approved_reviews_categories_and_change_password(): void
    {
        $this->seed();

        $product = Product::query()->where('is_enabled', true)->first();
        $this->assertNotNull($product);

        $category = Category::query()->where('is_enabled', true)->first();
        if ($category) {
            $product->categories()->syncWithoutDetaching([$category->id]);
        }

        $customer = Customer::factory()->create([
            'password' => 'Password123',
        ]);

        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'rating' => 5,
            'title' => 'Wave112 approved',
            'body' => 'Visible on PDP',
            'is_approved' => true,
            'status' => Review::STATUS_APPROVED,
        ]);
        Review::query()->create([
            'product_id' => $product->id,
            'customer_id' => Customer::factory()->create()->id,
            'rating' => 2,
            'title' => 'Wave112 pending',
            'body' => 'Hidden on approvedReviews',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
        ]);

        $productQ = $this->postJson('/api/v1/graphql', [
            'query' => 'query ($id: ID!) {
                product(id: $id) {
                    id
                    approvedReviews(first: 10) {
                        totalCount
                        edges { node { title status } }
                    }
                    reviews(first: 20) {
                        totalCount
                        edges { node { title status } }
                    }
                    categories(first: 10) {
                        totalCount
                        edges { node { id name translation { name } } }
                    }
                }
            }',
            'variables' => ['id' => (string) $product->id],
        ]);
        $productQ->assertOk();
        $this->assertArrayNotHasKey('errors', $productQ->json() ?? [], json_encode($productQ->json()));

        $approvedTitles = collect($productQ->json('data.product.approvedReviews.edges') ?? [])
            ->pluck('node.title')->all();
        $this->assertContains('Wave112 approved', $approvedTitles);
        $this->assertNotContains('Wave112 pending', $approvedTitles);

        $allTitles = collect($productQ->json('data.product.reviews.edges') ?? [])
            ->pluck('node.title')->all();
        $this->assertContains('Wave112 approved', $allTitles);
        $this->assertContains('Wave112 pending', $allTitles);

        if ($category) {
            $this->assertGreaterThanOrEqual(1, (int) $productQ->json('data.product.categories.totalCount'));
        }

        $pw = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($input: ChangeCustomerPasswordInput!) {
                changeCustomerPassword(input: $input) {
                    ok success status message
                }
            }',
            'variables' => [
                'input' => [
                    'oldPassword' => 'Password123',
                    'newPassword' => 'NewPassword456',
                    'passwordConfirmation' => 'NewPassword456',
                ],
            ],
        ]);
        $pw->assertOk();
        $this->assertArrayNotHasKey('errors', $pw->json() ?? [], json_encode($pw->json()));
        $this->assertTrue((bool) $pw->json('data.changeCustomerPassword.status'));
        $this->assertTrue((bool) $pw->json('data.changeCustomerPassword.ok'));
        $customer->refresh();
        $this->assertTrue(Hash::check('NewPassword456', (string) $customer->password));
    }
}
