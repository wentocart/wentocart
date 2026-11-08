<?php

namespace Tests\Feature;

use App\Domain\Cms\Models\CmsBlock;
use App\Domain\Cms\Models\Menu;
use App\Domain\Cms\Models\MenuItem;
use App\Domain\Plugin\Models\InstalledPlugin;
use App\Domain\Plugin\PluginInstaller;
use App\Domain\Plugin\PluginKernel;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCmsDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_manage_blocks_and_read_cms_menu_api(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        $menu = Menu::query()->updateOrCreate(['code' => 'footer'], ['name' => 'Footer']);
        $privacy = MenuItem::query()->create([
            'menu_id' => $menu->id,
            'label' => 'Privacy',
            'url' => '/privacy',
            'position' => 1,
        ]);
        MenuItem::query()->create([
            'menu_id' => $menu->id,
            'parent_id' => $privacy->id,
            'label' => 'Terms',
            'url' => '/terms',
            'position' => 2,
        ]);

        $this->get('/api/v1/cms/menu/footer')->assertOk()
            ->assertJsonPath('menu.name', 'Footer')
            ->assertJsonPath('menu.items.0.label', 'Privacy')
            ->assertJsonPath('menu.items.0.children.0.label', 'Terms');

        $this->post('/admin/blocks', [
            'title' => 'Footer legal',
            'code' => 'footer_legal',
            'location' => 'footer',
            'content' => '© WentoCart',
            'is_enabled' => true,
            'position' => 1,
        ])->assertRedirect();

        $this->assertTrue(CmsBlock::query()->where('code', 'footer_legal')->exists());
        $this->get('/admin/blocks')->assertOk()->assertSee('Footer legal');
        $this->get('/api/v1/cms/blocks?location=footer')->assertOk()
            ->assertJsonPath('blocks.0.title', 'Footer legal');

        $this->get('/api/v1/catalog/home')->assertOk()
            ->assertJsonPath('menu', null);
    }

    public function test_main_menu_extension_payload_migrates_core_main_menu(): void
    {
        $this->seed();
        $this->actingAs(Admin::query()->first(), 'admin');

        PluginKernel::reset();
        if (! InstalledPlugin::query()->where('vendor', 'weblytic')->where('name', 'mainmenu')->exists()) {
            app(PluginInstaller::class)->installFromSource('weblytic', 'mainmenu');
        }
        InstalledPlugin::query()->where('vendor', 'weblytic')->where('name', 'mainmenu')->update(['is_enabled' => true]);
        PluginKernel::reset();
        app(PluginKernel::class)->boot();

        $response = $this->getJson('/api/v1/modules/weblytic/mainmenu/menu');
        $response->assertOk();
        $items = $response->json('items');
        $this->assertIsArray($items);
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('label', $items[0]);
        $this->assertArrayHasKey('url', $items[0]);
    }

    public function test_main_menu_resolver_preserves_root_and_sorts_children_by_position(): void
    {
        $resolver = new \Modules\Weblytic\Mainmenu\Support\MenuResolver();
        $tree = $resolver->resolve([[
            'id' => 'LNK-home',
            'type' => 'link',
            'ref' => 'home',
            'enabled' => true,
            'new_window' => false,
            'parent_id' => '',
            'position' => 0,
            'label_override' => '',
            'labels' => [],
        ]], [
            'home' => [
                'id' => 'home',
                'label' => 'Home',
                'url' => '/',
                'labels' => [],
                'new_window' => false,
                'enabled' => true,
            ],
        ], ['locale' => 'en']);
        $this->assertSame('/', $tree[0]['url']);

        $tree = $resolver->resolve([[
            'id' => 'CAT-1',
            'type' => 'category',
            'ref' => '1',
            'enabled' => true,
            'new_window' => false,
            'parent_id' => '',
            'position' => 0,
            'label_override' => '',
            'labels' => [],
        ]], [], [
            'locale' => 'en',
            'categories' => [
                ['id' => 1, 'parent_id' => 0, 'is_enabled' => 1, 'position' => 0],
                ['id' => 2, 'parent_id' => 1, 'is_enabled' => 1, 'position' => 20],
                ['id' => 3, 'parent_id' => 1, 'is_enabled' => 1, 'position' => 10],
            ],
            'category_translations' => [
                ['category_id' => 1, 'language_code' => 'en', 'name' => 'Root', 'slug' => 'root'],
                ['category_id' => 2, 'language_code' => 'en', 'name' => 'Zebra', 'slug' => 'zebra'],
                ['category_id' => 3, 'language_code' => 'en', 'name' => 'Alpha', 'slug' => 'alpha'],
            ],
            'url_rewrites' => [
                ['language_code' => 'en', 'request_path' => 'root', 'entity_type' => 'category', 'entity_id' => 1],
                ['language_code' => 'en', 'request_path' => 'zebra', 'entity_type' => 'category', 'entity_id' => 2],
                ['language_code' => 'en', 'request_path' => 'alpha', 'entity_type' => 'category', 'entity_id' => 3],
            ],
        ]);
        $this->assertSame(['CAT-3', 'CAT-2'], array_column($tree[0]['children'], 'id'));
    }
}
