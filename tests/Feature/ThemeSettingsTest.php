<?php

namespace Tests\Feature;

use App\Domain\Store\Models\Store;
use App\Domain\Theme\ThemeResolver;
use App\Domain\Theme\ThemeSettingsCatalog;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemeSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_and_reset_theme_settings(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->get('/admin/themes/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Design/ThemeSettings', false)
                ->has('settings.colors.accent')
                ->has('presets')
            );

        $this->put('/admin/themes/settings', [
            'preset' => 'purple',
            'announcement' => 'Purple week',
            'announcement_url' => '/catalog',
            'logo_text' => 'Nova Shop',
            'radius' => '14px',
            'font' => 'DM Sans',
            'home' => [
                'sections' => [
                    'hero' => true,
                    'trust' => false,
                    'categories' => true,
                    'promos' => true,
                    'new_arrivals' => true,
                    'featured' => true,
                    'bestsellers' => true,
                    'on_sale' => false,
                    'deal' => false,
                    'brands' => true,
                    'testimonials' => true,
                    'newsletter' => true,
                    'recent' => true,
                ],
                'hero' => [
                    'eyebrow' => 'SALE',
                    'title' => 'Hello world',
                    'body' => 'Body copy',
                    'cta_label' => 'Shop',
                    'cta_url' => '/catalog',
                    'cta2_label' => 'More',
                    'cta2_url' => '/search?q=new',
                    'image_url' => '',
                ],
            ],
        ])->assertRedirect();

        $store = Store::query()->where('is_default', true)->first();
        $this->assertSame('purple', $store->theme_settings['preset']);
        $this->assertSame('#6D3AED', $store->theme_settings['colors']['accent']);
        $this->assertSame(['en' => 'Purple week'], $store->theme_settings['announcement']);
        $this->assertFalse($store->theme_settings['home']['sections']['trust']);

        $settings = app(ThemeResolver::class)->settings(null, $store);
        $this->assertSame('#6D3AED', $settings['colors']['accent']);
        $this->assertSame('Hello world', $settings['home']['hero']['title']);

        $this->get('/api/v1/store')->assertOk()
            ->assertJsonPath('shop.theme.settings.preset', 'purple')
            ->assertJsonPath('shop.theme.settings.colors.accent', '#6D3AED')
            ->assertJsonPath('shop.announcement.en', 'Purple week')
            ->assertJsonPath('shop.theme.settings.top_bar.announcement.en', 'Purple week');

        $this->get('/')->assertOk()->assertSee('#6D3AED', false);

        $this->post('/admin/themes/settings/reset')->assertRedirect();
        $store->refresh();
        $this->assertNull($store->theme_settings);
    }

    public function test_admin_can_save_default_categories_density_limit(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $home = ThemeSettingsCatalog::defaults()['home'];
        $this->assertSame(20, $home['density']['categories_limit']);

        $this->put('/admin/themes/settings', [
            'preset' => 'azure',
            'home' => $home,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $store = Store::query()->where('is_default', true)->first();
        $this->assertSame(20, $store->theme_settings['home']['density']['categories_limit']);
    }

    public function test_top_bar_settings_are_exposed_on_the_shop_api(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/themes/settings', [
            'preset' => 'azure',
            'top_bar' => [
                'enabled' => true,
                'background' => '#111827',
                'text_color' => '#fefce8',
                'show_currency' => false,
                'show_language' => true,
                'announcement' => 'Summer clearance',
                'announcement_url' => '/sale',
                'links' => [
                    ['label' => 'Help desk', 'url' => '/help', 'open_in_new_tab' => false],
                ],
                'contact' => [
                    'phone' => '555-0100',
                    'label' => 'Call us',
                    'url' => '/contact',
                ],
            ],
        ])->assertRedirect();

        $this->get('/api/v1/store')->assertOk()
            ->assertJsonPath('shop.theme.settings.top_bar.enabled', true)
            ->assertJsonPath('shop.theme.settings.top_bar.background', '#111827')
            ->assertJsonPath('shop.theme.settings.top_bar.text_color', '#FEFCE8')
            ->assertJsonPath('shop.theme.settings.top_bar.show_currency', false)
            ->assertJsonPath('shop.theme.settings.top_bar.announcement.en', 'Summer clearance')
            ->assertJsonPath('shop.theme.settings.top_bar.links.0.label.en', 'Help desk')
            ->assertJsonPath('shop.theme.settings.top_bar.contact.phone', '555-0100');
    }

    public function test_custom_preset_keeps_edited_colors(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/themes/settings', [
            'preset' => 'custom',
            'colors' => [
                'accent' => '#0A7A4B',
                'accent_soft' => '#E6F5EE',
                'header' => '#084D32',
                'sale' => '#E11D48',
                'ink' => '#111111',
                'muted' => '#6B7280',
                'surface' => '#F7F8FA',
                'card' => '#FFFFFF',
            ],
            'home' => ThemeSettingsCatalog::defaults()['home'],
        ])->assertRedirect();

        $store = Store::query()->where('is_default', true)->first();
        $this->assertSame('custom', $store->theme_settings['preset']);
        $this->assertSame('#0A7A4B', $store->theme_settings['colors']['accent']);
        $this->assertSame('#084D32', $store->theme_settings['colors']['header']);
    }

    public function test_shop_api_exposes_home_settings_by_default(): void
    {
        $this->seed();

        $this->get('/api/v1/store')->assertOk()
            ->assertJsonPath('shop.theme.settings.preset', 'azure')
            ->assertJsonPath('shop.theme.settings.colors.accent', '#4545E6')
            ->assertJsonPath('shop.theme.settings.top_bar.background', '#3361cf')
            ->assertJsonStructure([
                'shop' => [
                    'theme' => [
                        'settings' => [
                            'home' => [
                                'sections',
                                'density',
                                'hero',
                                'trust',
                                'promos',
                                'testimonials',
                                'newsletter',
                                'order',
                            ],
                        ],
                    ],
                ],
            ]);
    }

    public function test_dark_preset_keeps_readable_text(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/themes/settings', [
            'preset' => 'dark',
            'home' => ThemeSettingsCatalog::defaults()['home'],
        ])->assertRedirect();

        $this->get('/api/v1/store')->assertOk()
            ->assertJsonPath('shop.theme.settings.preset', 'dark')
            ->assertJsonPath('shop.theme.settings.colors.surface', '#07080D')
            ->assertJsonPath('shop.theme.settings.colors.ink', '#F4F4F5');

        $this->get('/')->assertOk()
            ->assertSee('data-shop-appearance="dark"', false)
            ->assertSee('#F4F4F5', false);
    }

    public function test_unreadable_custom_dark_surface_is_auto_corrected(): void
    {
        $colors = ThemeSettingsCatalog::ensureReadableContrast([
            'accent' => '#111111',
            'accent_soft' => '#222222',
            'ink' => '#000000',
            'muted' => '#111111',
            'surface' => '#050505',
            'card' => '#0A0A0A',
            'header' => '#000000',
            'sale' => '#FF0000',
        ]);

        $this->assertSame('#F4F4F5', $colors['ink']);
        $this->assertSame('dark', ThemeSettingsCatalog::appearanceFor($colors));
    }

    public function test_homepage_module_order_and_configure_are_saved(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->get('/admin/themes/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Design/ThemeSettings', false)
                ->has('modules')
            );

        $order = ['newsletter', 'hero', 'trust', 'promos', 'featured', 'banner', 'new_arrivals', 'categories', 'promos_mid', 'category_shelves', 'testimonials', 'bestsellers', 'promos_bottom', 'brands', 'content'];

        $this->put('/admin/themes/settings', [
            'preset' => 'azure',
            'home' => [
                'order' => $order,
                'sections' => ['hero' => false, 'newsletter' => true],
            ],
        ])->assertRedirect();

        $store = Store::query()->where('is_default', true)->first();
        $this->assertSame('newsletter', $store->theme_settings['home']['order'][0]);
        $this->assertFalse($store->theme_settings['home']['sections']['hero']);
        $this->assertSame(['en' => 'Join Our Newsletter'], $store->theme_settings['home']['newsletter']['title']);
        $this->get('/api/v1/store')->assertOk()
            ->assertJsonPath('shop.theme.settings.home.order.0', 'newsletter')
            ->assertJsonPath('shop.theme.settings.home.sections.hero', false);

        $this->get('/admin/themes/modules/newsletter')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Design/ClassicModule', false)->where('code', 'newsletter'));

        $this->put('/admin/themes/modules/newsletter', [
            'home' => [
                'newsletter' => [
                    'title' => 'Weekly deals',
                    'body' => 'One email a week.',
                ],
            ],
        ])->assertRedirect();

        $store->refresh();
        $this->assertSame(['en' => 'Weekly deals'], $store->theme_settings['home']['newsletter']['title']);
        $this->assertSame('newsletter', $store->theme_settings['home']['order'][0]);

        $this->put('/admin/themes/modules/footer', [
            'home' => [
                'footer' => [
                    'copyright' => 'Shop rights reserved.',
                    'columns' => [
                        [
                            'title' => 'Help',
                            'type' => 'links',
                            'links' => [['label' => 'FAQ', 'url' => '/help']],
                        ],
                    ],
                ],
            ],
        ])->assertRedirect();

        $store->refresh();
        $this->assertSame(['en' => 'Shop rights reserved.'], $store->theme_settings['home']['footer']['copyright']);
        $this->assertSame(['en' => 'FAQ'], $store->theme_settings['home']['footer']['columns'][0]['links'][0]['label']);
    }

    public function test_catalog_home_honors_featured_limit(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/themes/modules/featured', [
            'home' => [
                'featured' => [
                    'title' => 'Picks',
                    'limit' => 4,
                    'category_id' => null,
                ],
            ],
        ])->assertRedirect();

        $home = $this->get('/api/v1/catalog/home')->assertOk()->json();
        $this->assertLessThanOrEqual(4, count($home['featured']));
    }

    public function test_admin_can_upload_theme_logo(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $file = \Illuminate\Http\UploadedFile::fake()->image('shop-logo.png', 240, 80);

        $response = $this->postJson('/admin/themes/settings/logo', [
            'image' => $file,
        ]);

        $response->assertOk()->assertJsonStructure(['url']);
        $url = (string) $response->json('url');
        $this->assertStringStartsWith('/storage/theme-media/', $url);
        $relative = ltrim(substr($url, strlen('/storage/')), '/');
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('public')->exists($relative), 'Missing stored logo at '.$relative);
    }

    public function test_theme_settings_back_to_themes_lives_in_page_header(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Design/ThemeSettings.jsx'));

        $this->assertMatchesRegularExpression(
            "/<PageHeader[\s\S]{0,600}href=\"\/admin\/themes\"[\s\S]{0,200}icon=\"arrow-left\"[\s\S]{0,200}back', 'Back to Themes'/",
            $source,
            'Back to Themes must appear in Theme settings PageHeader actions and link to /admin/themes.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/Save theme settings'[\s\S]{0,500}back', 'Back to Themes'/",
            $source,
            'Back to Themes must not remain in the bottom action row next to Save.'
        );
        $this->assertSame(
            1,
            substr_count($source, "back', 'Back to Themes'"),
            'Theme settings must not render duplicate Back to Themes buttons.'
        );
    }

    public function test_theme_settings_save_actions_use_sticky_bottom_bar(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Design/ThemeSettings.jsx'));
        $classes = (string) file_get_contents(base_path('resources/js/admin/ui/classes.js'));

        $this->assertStringContainsString(
            'formStickyBar:',
            $classes,
            'Shared formStickyBar styles must exist for sticky bottom action bars.'
        );
        $this->assertMatchesRegularExpression(
            "/formStickyBar:\s*'[^']*bottom-\[-1rem\][^']*lg:bottom-\[-1\.25rem\]/",
            $classes,
            'formStickyBar must offset Layout main p-4/lg:p-5 so the stuck bar is flush to the scrollport bottom.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/formStickyBar:\s*'[^']*\bbottom-0\b/",
            $classes,
            'formStickyBar must not use bottom-0 (that leaves main padding as a gap under the bar).'
        );
        $this->assertMatchesRegularExpression(
            "/ui\.formStickyBar[\s\S]{0,800}save', 'Save theme settings'[\s\S]{0,800}reset', 'Reset to defaults'/",
            $source,
            'Save and Reset must live in a single sticky bottom action bar.'
        );
        $this->assertMatchesRegularExpression(
            "/formStickyBar:\s*'[^']*\bpy-2\b/",
            $classes,
            'formStickyBar vertical padding must stay compact (py-2) without changing button size.'
        );
        $this->assertStringContainsString(
            'pb-20',
            $source,
            'Theme settings content must keep bottom padding so the last section can scroll above the sticky bar.'
        );
        $this->assertSame(
            1,
            substr_count($source, "save', 'Save theme settings'"),
            'Theme settings must not duplicate the Save theme settings button.'
        );
        $this->assertSame(
            1,
            substr_count($source, "reset', 'Reset to defaults'"),
            'Theme settings must not duplicate the Reset to defaults button.'
        );
        $this->assertStringContainsString(
            "router.post('/admin/themes/settings/reset')",
            $source,
            'Reset must keep posting to the existing reset route.'
        );
        $this->assertStringContainsString(
            'window.confirm',
            $source,
            'Reset must keep its confirmation step.'
        );
    }

    public function test_theme_settings_default_logo_url_is_classic_install_asset(): void
    {
        $defaults = ThemeSettingsCatalog::defaults();
        $this->assertSame(
            ThemeSettingsCatalog::DEFAULT_LOGO_URL,
            $defaults['logo_url'],
            'Catalog defaults must point at the Classic merchant install logo.'
        );
        $this->assertSame('/themes/classic/assets/merchant/ui/logo.png', ThemeSettingsCatalog::DEFAULT_LOGO_URL);
        $this->assertFileExists(base_path('themes/classic/assets/merchant/ui/logo.png'));
        $this->assertFileExists(public_path('themes/classic/assets/merchant/ui/logo.png'));

        $normalized = ThemeSettingsCatalog::normalize([
            'preset' => 'azure',
            'logo_url' => '',
            'logo_text' => '',
        ]);
        $this->assertSame(
            ThemeSettingsCatalog::DEFAULT_LOGO_URL,
            $normalized['logo_url'],
            'Clearing logo_url must restore the install default instead of mapping to /catalog.'
        );
        $this->assertNotSame('/catalog', $normalized['logo_url']);

        $fromLegacy = ThemeSettingsCatalog::normalize([
            'preset' => 'azure',
            'logo_url' => ThemeSettingsCatalog::LEGACY_DEFAULT_LOGO_URL,
        ]);
        $this->assertSame(
            ThemeSettingsCatalog::DEFAULT_LOGO_URL,
            $fromLegacy['logo_url'],
            'Legacy SVG install path must upgrade to the bundled PNG default.'
        );

        $custom = '/storage/theme-media/1/logo/custom-shop.png';
        $preserved = ThemeSettingsCatalog::normalize([
            'preset' => 'azure',
            'logo_url' => $custom,
        ]);
        $this->assertSame($custom, $preserved['logo_url'], 'Custom uploaded logos must be preserved.');
    }

    public function test_theme_settings_logo_field_previews_resolved_install_logo(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Design/ThemeSettings.jsx'));

        $this->assertStringContainsString(
            "DEFAULT_THEME_LOGO_URL = '/themes/classic/assets/merchant/ui/logo.png'",
            $source,
            'Admin Logo field must know the Classic install logo path.'
        );
        $this->assertStringContainsString('catalogImageUrl(storedUrl)', $source, 'Logo preview must resolve shop-base paths via catalogImageUrl/adminUrl.');
        $this->assertStringContainsString("adminUrl('/admin/themes/settings/logo')", $source);
        $this->assertMatchesRegularExpression(
            "/type=\"file\"[\s\S]*?max-h-20 max-w-full object-contain[\s\S]*?icon=\"trash\"[\s\S]*?logo_upload_hint/",
            $source,
            'Logo section order must be upload → preview with trash icon → hint.'
        );
        $this->assertStringContainsString('icon="trash"', $source, 'Logo remove control must use the trash icon.');
        $this->assertStringContainsString('disabled={!canRemoveLogo}', $source, 'Trash stays visible; disabled only for the install default logo.');
        $this->assertStringContainsString("remove_logo', 'Remove logo'", $source);
        $this->assertDoesNotMatchRegularExpression(
            "/function LogoUploadField[\s\S]{0,3500}admin\.common\.remove', 'Remove'/",
            $source,
            'Logo remove control must not show the text Remove label.'
        );
        $this->assertStringContainsString('onChange(DEFAULT_THEME_LOGO_URL)', $source, 'Trash must restore the install default logo URL.');
    }

    public function test_theme_settings_inertia_exposes_logo_url_for_preview(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->get('/admin/themes/settings')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Design/ThemeSettings', false)
                ->where('settings.logo_url', ThemeSettingsCatalog::DEFAULT_LOGO_URL)
            );
    }

    public function test_admin_shell_shares_theme_logo_url_for_sidebar_brand(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shop.logo_url', \App\Support\WebBase::url(ltrim(ThemeSettingsCatalog::DEFAULT_LOGO_URL, '/')))
            );
    }

    public function test_saving_empty_logo_url_restores_default_without_deleting_asset_file(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/themes/settings', [
            'preset' => 'azure',
            'logo_text' => '',
            'logo_url' => '',
            'home' => ThemeSettingsCatalog::defaults()['home'],
        ])->assertRedirect();

        $store = Store::query()->where('is_default', true)->first();
        $this->assertSame(ThemeSettingsCatalog::DEFAULT_LOGO_URL, $store->theme_settings['logo_url'] ?? null);
        $this->assertFileExists(public_path('themes/classic/assets/merchant/ui/logo.png'));

        $resolved = app(ThemeResolver::class)->settings(null, $store);
        $this->assertSame(ThemeSettingsCatalog::DEFAULT_LOGO_URL, $resolved['logo_url']);
    }

    public function test_theme_resolver_maps_legacy_default_logo_without_overwriting_custom(): void
    {
        $this->seed();
        $store = Store::query()->where('is_default', true)->firstOrFail();

        $store->update([
            'theme_settings' => ThemeSettingsCatalog::normalize([
                'preset' => 'azure',
                'logo_url' => ThemeSettingsCatalog::LEGACY_DEFAULT_LOGO_URL,
            ]),
        ]);
        $resolvedLegacy = app(ThemeResolver::class)->settings(null, $store->fresh());
        $this->assertSame(ThemeSettingsCatalog::DEFAULT_LOGO_URL, $resolvedLegacy['logo_url']);

        $custom = '/storage/theme-media/'.$store->id.'/logo/kept-custom.png';
        $store->update([
            'theme_settings' => ThemeSettingsCatalog::normalize([
                'preset' => 'azure',
                'logo_url' => $custom,
            ]),
        ]);
        $resolvedCustom = app(ThemeResolver::class)->settings(null, $store->fresh());
        $this->assertSame($custom, $resolvedCustom['logo_url']);
    }

    public function test_search_placeholder_is_multilingual_in_admin_and_normalizes_locale_map(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Design/ThemeSettings.jsx'));
        $this->assertStringContainsString('LocalizedField', $source);
        $this->assertStringContainsString('asLocalizedMap', $source);
        $this->assertStringContainsString('search_placeholder', $source);

        $header = (string) file_get_contents(base_path('storefront/src/components/ClassicHeader.jsx'));
        $this->assertStringContainsString('pickLocalizedText', $header);
        $this->assertStringContainsString('headerCfg.search_placeholder', $header);

        $fromString = ThemeSettingsCatalog::normalize([
            'preset' => 'azure',
            'home' => [
                'header' => [
                    'search_placeholder' => 'Find anything',
                ],
            ],
        ]);
        $this->assertIsArray($fromString['home']['header']['search_placeholder']);
        $this->assertContains('Find anything', $fromString['home']['header']['search_placeholder']);

        $fromMap = ThemeSettingsCatalog::normalize([
            'preset' => 'azure',
            'home' => [
                'header' => [
                    'search_placeholder' => [
                        'en' => 'Search for products',
                        'fr' => 'Rechercher des produits',
                    ],
                ],
            ],
        ]);
        $this->assertSame('Search for products', $fromMap['home']['header']['search_placeholder']['en'] ?? null);
        $this->assertSame('Rechercher des produits', $fromMap['home']['header']['search_placeholder']['fr'] ?? null);
    }

    public function test_trust_title_is_multilingual_and_the_row_uses_a_trash_icon(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Design/ClassicModule.jsx'));
        $trustAt = strpos($source, 'function TrustRow');
        $promoAt = strpos($source, 'function PromoRow');
        $this->assertNotFalse($trustAt);
        $this->assertNotFalse($promoAt);
        $trust = substr($source, $trustAt, $promoAt - $trustAt);
        $this->assertSame(2, substr_count($trust, '<LocalizedField'));
        $this->assertStringContainsString('icon="trash"', $trust);
        $this->assertStringContainsString('sm:grid-cols-[10.5rem_minmax(0,1fr)_minmax(0,1.15fr)_auto]', $trust);
        $this->assertStringNotContainsString('admin.common.remove', $trust);

        $home = (string) file_get_contents(base_path('storefront/src/pages/Home.jsx'));
        $this->assertStringContainsString('pickLocalizedText(item.title, locale', $home);
        $this->assertStringContainsString('pickLocalizedText(item.text, locale', $home);

        $fromString = ThemeSettingsCatalog::normalize([
            'preset' => 'azure',
            'home' => [
                'trust' => [
                    ['icon' => 'truck', 'title' => 'Free Fast Shipping', 'text' => 'Free shipping for orders over'],
                ],
            ],
        ]);
        $this->assertIsArray($fromString['home']['trust'][0]['title']);
        $this->assertIsArray($fromString['home']['trust'][0]['text']);
        $this->assertContains('Free Fast Shipping', $fromString['home']['trust'][0]['title']);
        $this->assertContains('Free shipping for orders over', $fromString['home']['trust'][0]['text']);

        $fromMap = ThemeSettingsCatalog::normalize([
            'preset' => 'azure',
            'home' => [
                'trust' => [
                    [
                        'icon' => 'support',
                        'title' => ['en' => 'Our Happy Clients', 'fr' => 'Nos clients heureux'],
                        'text' => ['en' => 'Online service center worldwide', 'fr' => 'Service en ligne'],
                    ],
                ],
            ],
        ]);
        $this->assertSame('Our Happy Clients', $fromMap['home']['trust'][0]['title']['en'] ?? null);
        $this->assertSame('Nos clients heureux', $fromMap['home']['trust'][0]['title']['fr'] ?? null);
        $this->assertSame('Online service center worldwide', $fromMap['home']['trust'][0]['text']['en'] ?? null);
        $this->assertSame('Service en ligne', $fromMap['home']['trust'][0]['text']['fr'] ?? null);
        $this->assertSame('support', $fromMap['home']['trust'][0]['icon']);
    }

    public function test_admin_can_save_multilingual_trust_titles(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $this->put('/admin/themes/modules/trust', [
            'home' => [
                'trust' => [
                    [
                        'icon' => 'truck',
                        'title' => ['en' => 'Free Fast Shipping', 'fr' => 'Livraison rapide'],
                        'text' => ['en' => 'Free shipping for orders over', 'fr' => 'Livraison offerte'],
                    ],
                    [
                        'icon' => 'shield',
                        'title' => 'Secure Payments',
                        'text' => 'Pay with multiple credit cards',
                    ],
                ],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $store = Store::query()->where('is_default', true)->firstOrFail();
        $saved = $store->theme_settings['home']['trust'] ?? [];
        $this->assertSame('Livraison rapide', $saved[0]['title']['fr'] ?? null);
        $this->assertSame('Free Fast Shipping', $saved[0]['title']['en'] ?? null);
        $this->assertSame('Free shipping for orders over', $saved[0]['text']['en'] ?? null);
        $this->assertSame('Livraison offerte', $saved[0]['text']['fr'] ?? null);
        $this->assertIsArray($saved[1]['title'] ?? null);
        $this->assertIsArray($saved[1]['text'] ?? null);
        $this->assertContains('Secure Payments', $saved[1]['title']);
        $this->assertContains('Pay with multiple credit cards', $saved[1]['text']);
        $this->assertSame('shield', $saved[1]['icon'] ?? null);
    }

    public function test_admin_can_save_multilingual_search_placeholder(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $home = ThemeSettingsCatalog::defaults()['home'];
        $home['header']['search_placeholder'] = [
            'en' => 'Search English',
            'fr' => 'Chercher Francais',
        ];

        $this->put('/admin/themes/settings', [
            'preset' => 'azure',
            'home' => $home,
        ])->assertRedirect();

        $store = Store::query()->where('is_default', true)->firstOrFail();
        $saved = $store->theme_settings['home']['header']['search_placeholder'] ?? null;
        $this->assertIsArray($saved);
        $this->assertSame('Search English', $saved['en'] ?? null);
        $this->assertSame('Chercher Francais', $saved['fr'] ?? null);
    }

    public function test_favicon_setting_sits_above_the_top_bar_and_is_saved(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/admin/Pages/Design/ThemeSettings.jsx'));
        $faviconAt = strpos($source, 'id="favicon"');
        $presetAt = strpos($source, 'id="presets"');
        $topBarAt = strpos($source, 'id="top_bar"');
        $this->assertNotFalse($faviconAt);
        $this->assertNotFalse($presetAt);
        $this->assertNotFalse($topBarAt);
        $this->assertLessThan($presetAt, $faviconAt, 'Favicon section must be the first settings block.');
        $this->assertLessThan($topBarAt, $faviconAt, 'Favicon section must render above the top bar section.');
        $this->assertSame(
            '/themes/classic/assets/img/favicon.png',
            \App\Domain\Theme\ThemeSettingsCatalog::resolvedFaviconUrl('')
        );
        $this->assertFileExists(base_path('themes/classic/assets/img/favicon.png'));
        $this->assertStringContainsString("adminUrl('/admin/themes/settings/favicon')", $source);

        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $url = '/storage/theme-media/1/favicon/mark.png';
        $this->put('/admin/themes/settings', [
            'preset' => 'azure',
            'favicon_url' => $url,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get('/api/v1/shop')->assertOk()
            ->assertJsonPath('shop.theme.settings.favicon_url', $url);

        $cleared = ThemeSettingsCatalog::normalize([
            'preset' => 'azure',
            'favicon_url' => '',
        ]);
        $this->assertSame(ThemeSettingsCatalog::DEFAULT_FAVICON_URL, $cleared['favicon_url']);
    }

    public function test_admin_can_upload_theme_favicon(): void
    {
        $this->seed();
        $admin = Admin::query()->first();
        $this->actingAs($admin, 'admin');

        $file = \Illuminate\Http\UploadedFile::fake()->image('favicon.png', 32, 32);

        $response = $this->postJson('/admin/themes/settings/favicon', [
            'image' => $file,
        ]);

        $response->assertOk()->assertJsonStructure(['url']);
        $url = (string) $response->json('url');
        $this->assertStringStartsWith('/storage/theme-media/', $url);
        $this->assertStringContainsString('/favicon/', $url);
        $relative = ltrim(substr($url, strlen('/storage/')), '/');
        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('public')->exists($relative));
    }

    public function test_normalize_keeps_layout_3_banner_presets_when_overlay_sets_layout_3_with_layout_1_columns(): void
    {
        $defaults = ThemeSettingsCatalog::defaults();
        $this->assertSame(1, $defaults['home']['hero']['layout']);

        $overlay = [
            'home' => [
                'hero' => [
                    'layout' => 3,
                    'slides' => $defaults['home']['hero']['slides'],
                    'sides' => $defaults['home']['hero']['sides'],
                ],
            ],
        ];

        $hero = ThemeSettingsCatalog::normalize(ThemeSettingsCatalog::merge($defaults, $overlay))['home']['hero'];
        $this->assertSame(3, $hero['layout']);
        $this->assertCount(3, $hero['columns']);
        $this->assertCount(3, $hero['layout_columns'][3]);

        foreach ($hero['layout_columns'][3] as $column) {
            $this->assertCount(3, $column['slides']);
            foreach ($column['slides'] as $slide) {
                $this->assertStringContainsString('/layout-3/', (string) ($slide['image_url'] ?? ''));
                $this->assertStringNotContainsString('placeholder.png', (string) ($slide['image_url'] ?? ''));
            }
        }

        $this->assertStringContainsString('a-1.jpg', (string) $hero['columns'][0]['slides'][0]['image_url']);
        $this->assertStringContainsString('b-1.jpg', (string) $hero['columns'][1]['slides'][0]['image_url']);
        $this->assertStringContainsString('c-1.jpg', (string) $hero['columns'][2]['slides'][0]['image_url']);
    }

    public function test_sanitize_hero_for_display_repairs_legacy_layout_3_side_placeholders(): void
    {
        $defaults = ThemeSettingsCatalog::defaultHeroLayoutColumns();
        $broken = [
            'layout' => 3,
            'layout_columns' => [
                1 => $defaults[1],
                2 => $defaults[2],
                3 => [
                    ['width_hint' => 'main', 'slides' => $defaults[1][0]['slides']],
                    ['width_hint' => 'side', 'slides' => [['title' => 'Missing', 'image_url' => '/themes/classic/assets/merchant/home/hero/placeholder.png']]],
                    ['width_hint' => 'side', 'slides' => [['title' => 'Missing', 'image_url' => '/themes/classic/assets/merchant/home/hero/placeholder.png']]],
                ],
            ],
            'columns' => [
                ['width_hint' => 'main', 'slides' => $defaults[1][0]['slides']],
                ['width_hint' => 'side', 'slides' => [['title' => 'Missing', 'image_url' => '/themes/classic/assets/merchant/home/hero/placeholder.png']]],
                ['width_hint' => 'side', 'slides' => [['title' => 'Missing', 'image_url' => '/themes/classic/assets/merchant/home/hero/placeholder.png']]],
            ],
            'slides' => $defaults[1][0]['slides'],
            'sides' => [],
        ];

        $hero = ThemeSettingsCatalog::sanitizeHeroForDisplay($broken);
        $this->assertCount(3, $hero['columns']);
        foreach ($hero['columns'] as $column) {
            $this->assertCount(3, $column['slides']);
            $this->assertStringContainsString('/layout-3/', (string) ($column['slides'][0]['image_url'] ?? ''));
        }
    }
}
