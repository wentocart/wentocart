<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Customer\Models\Customer;
use App\Domain\Localization\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlCmsAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_cms_pages_and_cms_page_by_slug(): void
    {
        $this->seed();
        $locale = Language::defaultCode();
        $page = CmsPage::query()->create([
            'is_enabled' => true,
            'is_visible' => true,
            'status' => CmsPage::STATUS_PUBLISHED,
            'template' => 'default',
        ]);
        $page->translations()->create([
            'language_code' => $locale,
            'title' => 'About Wento',
            'slug' => 'about-wento-gql',
            'short_description' => 'Who we are',
            'body' => '<p>Hello from CMS GraphQL</p>',
            'meta_title' => 'About',
            'meta_description' => 'About page',
        ]);

        $list = $this->postJson('/api/v1/graphql', [
            'query' => '{ cmsPages(limit: 10) { id title slug } }',
        ]);
        $list->assertOk();
        $this->assertArrayNotHasKey('errors', $list->json() ?? []);
        $slugs = collect($list->json('data.cmsPages') ?? [])->pluck('slug')->all();
        $this->assertContains('about-wento-gql', $slugs);

        $one = $this->postJson('/api/v1/graphql', [
            'query' => 'query { cmsPage(slug: "about-wento-gql") { id title slug body } }',
        ]);
        $one->assertOk();
        $this->assertArrayNotHasKey('errors', $one->json() ?? []);
        $this->assertSame('About Wento', $one->json('data.cmsPage.title'));
        $this->assertStringContainsString('Hello from CMS', (string) $one->json('data.cmsPage.body'));
    }

    public function test_save_update_and_delete_customer_address(): void
    {
        $this->seed();
        $customer = Customer::factory()->create();

        $create = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                saveCustomerAddress(
                    firstName: "Ada"
                    lastName: "Lovelace"
                    line1: "1 Engine Way"
                    city: "London"
                    postalCode: "SW1A"
                    countryCode: "GB"
                    isDefaultShipping: true
                    isDefaultBilling: true
                    label: "Home"
                ) { id label city country_code is_default_shipping }
            }',
        ]);
        $create->assertOk();
        $this->assertArrayNotHasKey('errors', $create->json() ?? []);
        $id = (string) $create->json('data.saveCustomerAddress.id');
        $this->assertNotEmpty($id);
        $this->assertSame('London', $create->json('data.saveCustomerAddress.city'));

        $update = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) {
                saveCustomerAddress(
                    id: $id
                    firstName: "Ada"
                    lastName: "Lovelace"
                    line1: "2 Engine Way"
                    city: "Cambridge"
                    countryCode: "GB"
                    label: "Lab"
                ) { id city label }
            }',
            'variables' => ['id' => $id],
        ]);
        $update->assertOk();
        $this->assertArrayNotHasKey('errors', $update->json() ?? []);
        $this->assertSame('Cambridge', $update->json('data.saveCustomerAddress.city'));
        $this->assertSame('Lab', $update->json('data.saveCustomerAddress.label'));

        $profile = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation { updateCustomer(firstName: "Augusta", lastName: "King", phone: "+441234") { name phone } }',
        ]);
        $profile->assertOk();
        $this->assertArrayNotHasKey('errors', $profile->json() ?? []);
        $this->assertStringContainsString('Augusta', (string) $profile->json('data.updateCustomer.name'));
        $this->assertSame('+441234', $profile->json('data.updateCustomer.phone'));

        $delete = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation ($id: ID!) { deleteCustomerAddress(id: $id) { ok } }',
            'variables' => ['id' => $id],
        ]);
        $delete->assertOk();
        $this->assertArrayNotHasKey('errors', $delete->json() ?? []);
        $this->assertTrue((bool) $delete->json('data.deleteCustomerAddress.ok'));
        $this->assertSame(0, $customer->fresh()->addresses()->count());
    }

    public function test_change_customer_password(): void
    {
        $this->seed();
        $customer = Customer::factory()->create(['password' => 'OldPass99']);

        $bad = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                changeCustomerPassword(currentPassword: "WrongPass1", password: "NewPass99", passwordConfirmation: "NewPass99") { ok }
            }',
        ]);
        $bad->assertOk();
        $this->assertNotEmpty($bad->json('errors'));

        $ok = $this->actingAs($customer, 'customer')->postJson('/api/v1/graphql', [
            'query' => 'mutation {
                changeCustomerPassword(currentPassword: "OldPass99", password: "NewPass99", passwordConfirmation: "NewPass99") { ok message }
            }',
        ]);
        $ok->assertOk();
        $this->assertArrayNotHasKey('errors', $ok->json() ?? []);
        $this->assertTrue((bool) $ok->json('data.changeCustomerPassword.ok'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('NewPass99', $customer->fresh()->password));
    }
}
