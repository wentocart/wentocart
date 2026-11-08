<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GraphqlCountriesLocalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_exposes_countries_locales_currencies_channels_and_payment_methods(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{
                countriesList { code name has_regions regions { code name } }
                localesList { id code name is_default }
                currenciesList { id code symbol is_default }
                storesList { id code name is_default is_enabled }
                paymentMethodsList { id code title driver }
            }',
        ]);

        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? []);

        $countries = $response->json('data.countriesList');
        $this->assertIsArray($countries);
        $this->assertNotEmpty($countries);
        $this->assertArrayHasKey('code', $countries[0]);
        $this->assertArrayHasKey('regions', $countries[0]);

        $locales = $response->json('data.localesList');
        $this->assertIsArray($locales);
        $this->assertNotEmpty($locales);
        $this->assertNotEmpty($locales[0]['code'] ?? null);

        $currencies = $response->json('data.currenciesList');
        $this->assertIsArray($currencies);
        $this->assertNotEmpty($currencies);

        $channels = $response->json('data.storesList');
        $this->assertIsArray($channels);
        $this->assertNotEmpty($channels);

        $payments = $response->json('data.paymentMethodsList');
        $this->assertIsArray($payments);
        $this->assertNotEmpty($payments);
        $this->assertNotEmpty($payments[0]['code'] ?? null);
    }

    public function test_rest_countries_locales_payment_methods_connections(): void
    {
        $this->seed();

        $response = $this->postJson('/api/v1/graphql', [
            'query' => '{
                countries(first: 5) {
                    totalCount
                    edges {
                        node {
                            id code name
                            states { totalCount edges { node { code defaultName } } }
                        }
                    }
                }
                locales(first: 5) {
                    totalCount
                    edges { node { id code name } }
                }
                paymentMethods(first: 10) {
                    totalCount
                    edges { node { id code title sortOrder } }
                }
            }',
        ]);
        $response->assertOk();
        $this->assertArrayNotHasKey('errors', $response->json() ?? [], json_encode($response->json()));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.countries.totalCount'));
        $this->assertNotEmpty($response->json('data.countries.edges.0.node.code'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.locales.totalCount'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('data.paymentMethods.totalCount'));
    }
}
