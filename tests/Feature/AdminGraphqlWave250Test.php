<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\CmsPage;
use App\Domain\Localization\Models\Language;
use App\Domain\Settings\ShopSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGraphqlWave250Test extends TestCase
{
    use RefreshDatabase;

    public function test_shop_cms_pages_rest_and_cart_tokens_alias(): void
    {
        $this->seed();
        ShopSetting::put('api.require_storefront_key', false);
        $locale = Language::defaultCode();
        $suffix = strtolower(substr(uniqid(), -6));
        $slug = 'wave250-about-'.$suffix;

        $page = CmsPage::query()->create([
            'is_enabled' => true,
            'is_visible' => true,
            'status' => CmsPage::STATUS_PUBLISHED,
            'template' => 'default',
        ]);
        $page->translations()->create([
            'language_code' => $locale,
            'title' => 'Wave250 About '.$suffix,
            'slug' => $slug,
            'short_description' => 'About short',
            'body' => '<p>Wave250 CMS body</p>',
            'meta_title' => 'Meta '.$suffix,
            'meta_description' => 'Desc '.$suffix,
            'meta_keywords' => 'kw',
        ]);

        $draft = CmsPage::query()->create([
            'is_enabled' => true,
            'is_visible' => false,
            'status' => CmsPage::STATUS_DRAFT,
            'template' => 'default',
        ]);
        $draft->translations()->create([
            'language_code' => $locale,
            'title' => 'Hidden',
            'slug' => 'wave250-hidden-'.$suffix,
            'body' => '<p>nope</p>',
        ]);

        $list = $this->getJson('/api/v1/store/cms-pages');
        $list->assertOk();
        $this->assertIsArray($list->json());
        $slugs = collect($list->json())->pluck('slug')->all();
        $this->assertContains($slug, $slugs);
        $this->assertNotContains('wave250-hidden-'.$suffix, $slugs);
        $this->assertNotEmpty($list->headers->get('X-Total-Count'));

        $aliasList = $this->getJson('/api/v1/store/pages');
        $aliasList->assertOk();
        $this->assertContains($slug, collect($aliasList->json())->pluck('slug')->all());

        $byId = $this->getJson('/api/v1/store/pages/'.$page->id);
        $byId->assertOk();
        $this->assertSame('/api/v1/store/pages/'.$page->id, $byId->json('id'));
        $this->assertSame($slug, $byId->json('translation.slug'));
        $this->assertStringContainsString('Wave250 CMS body', (string) $byId->json('htmlContent'));

        $bySlug = $this->getJson('/api/v1/store/cms-pages/'.$slug);
        $bySlug->assertOk();
        $this->assertSame((int) $page->id, (int) $bySlug->json('id'));

        $missing = $this->getJson('/api/v1/store/pages/does-not-exist-'.$suffix);
        $missing->assertNotFound();

        $cart = $this->postJson('/api/v1/store/cart-tokens', []);
        $cart->assertCreated();
        $this->assertNotEmpty($cart->json('cartToken') ?? $cart->json('id'));
    }
}
