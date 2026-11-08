<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

class MainMenuExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $base = dirname(__DIR__, 2).'/extensions/weblytic/mainmenu/src/Support';
        require_once $base.'/MenuResolver.php';
        require_once $base.'/MenuConfig.php';
        require_once $base.'/LinkStore.php';
    }

    public function test_resolver_keeps_home_slash_and_orders_category_children_by_position(): void
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

    public function test_would_create_cycle_detects_a_b_loop(): void
    {
        $config = (new ReflectionClass(\Modules\Weblytic\Mainmenu\Support\MenuConfig::class))->newInstanceWithoutConstructor();
        $byId = [
            'LNK-a' => ['id' => 'LNK-a', 'parent_id' => 'LNK-b'],
            'LNK-b' => ['id' => 'LNK-b', 'parent_id' => 'LNK-a'],
        ];
        $this->assertTrue($config->wouldCreateCycle('LNK-a', 'LNK-b', $byId));
        $this->assertFalse($config->wouldCreateCycle('LNK-a', 'LNK-c', [
            'LNK-a' => ['id' => 'LNK-a', 'parent_id' => ''],
            'LNK-c' => ['id' => 'LNK-c', 'parent_id' => ''],
        ]));
    }

    public function test_link_store_allows_hash_fragments(): void
    {
        $store = (new ReflectionClass(\Modules\Weblytic\Mainmenu\Support\LinkStore::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass(\Modules\Weblytic\Mainmenu\Support\LinkStore::class))->getMethod('safeUrl');
        $method->setAccessible(true);
        $this->assertSame('/ok?q=1#frag', $method->invoke($store, '/ok?q=1#frag'));
        $this->assertSame('', $method->invoke($store, 'javascript:alert(1)'));
        $this->assertSame('https://example.com/x', $method->invoke($store, 'https://example.com/x'));
    }
}
