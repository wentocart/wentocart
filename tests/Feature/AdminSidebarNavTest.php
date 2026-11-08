<?php

namespace Tests\Feature;

use Tests\TestCase;

class AdminSidebarNavTest extends TestCase
{
    public function test_admin_layout_supports_collapsible_icon_sidebar(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/admin/Pages/Layout.jsx'));
        $sidebar = (string) file_get_contents(base_path('resources/js/admin/Pages/AdminSidebar.jsx'));
        $classes = (string) file_get_contents(base_path('resources/js/admin/ui/classes.js'));
        $menu = (string) file_get_contents(base_path('resources/js/admin/ui/adminMenu.js'));
        $adminCss = (string) file_get_contents(base_path('resources/css/admin.css'));

        $this->assertStringContainsString("import AdminSidebar from './AdminSidebar'", $layout);
        $this->assertStringContainsString("wento-admin-sidebar-collapsed", $layout);
        $this->assertStringNotContainsString('data-sidebar-toggle="topbar"', $layout);
        $this->assertStringContainsString("data-admin-layout={collapsed ? 'sidebar-collapsed' : 'sidebar-expanded'}", $layout);
        $this->assertStringNotContainsString('data-admin-sidebar-animating', $layout);
        $this->assertStringNotContainsString('iconMode', $layout);
        $this->assertStringNotContainsString('SIDEBAR_ANIM_MS', $layout);
        $this->assertStringNotContainsString('setIconMode', $layout);
        $this->assertStringNotContainsString('widthCollapsed', $layout);
        $this->assertStringContainsString("--admin-sidebar-width", $layout);
        $this->assertStringContainsString('buildAdminMenu', $layout);
        $this->assertStringContainsString('visibleMenu={visibleMenu}', $layout);
        $this->assertStringContainsString('collapsed={collapsed}', $layout);
        $this->assertStringContainsString('onToggleCollapse={toggleCollapsed}', $layout);

        $this->assertStringContainsString('data-admin-sidebar={collapsed ? \'collapsed\' : \'expanded\'}', $sidebar);
        $this->assertStringContainsString('data-sidebar-toggle="brand"', $sidebar);
        $this->assertStringNotContainsString('data-sidebar-toggle="edge"', $sidebar);
        $this->assertStringNotContainsString('widthCollapsed', $sidebar);
        $this->assertStringNotContainsString('railCollapsed', $sidebar);
        $this->assertStringContainsString('ui.brandCollapsed', $sidebar);
        $this->assertStringContainsString('brandLinkCollapsed', $sidebar);
        $this->assertStringContainsString('catalogImageUrl', $sidebar);
        $this->assertStringContainsString('sidebarLogoPath', $sidebar);
        $this->assertStringContainsString("from '../ui/adminSidebarLogo'", $sidebar);
        $this->assertStringContainsString('collapsed })', $sidebar);
        $this->assertStringContainsString('shop?.logo_url', $sidebar);
        $this->assertStringContainsString('ui.brandLogo', $sidebar);
        $this->assertStringContainsString('ui.brandLogoCollapsed', $sidebar);
        $this->assertStringContainsString('brandLogo:', $classes);
        $this->assertStringContainsString('brandLogoCollapsed:', $classes);
        $this->assertStringContainsString("brandLogoCollapsed: 'h-8 w-auto max-w-[40px]", $classes);
        $this->assertStringContainsString('CollapsedGroup', $sidebar);
        $this->assertStringContainsString('ui.navFlyout', $sidebar);
        $this->assertStringContainsString('ui.navIconBtn', $sidebar);
        $this->assertStringContainsString('ui.navIconBtnActive', $sidebar);
        $this->assertStringContainsString('ui.navIconActiveBar', $sidebar);
        $this->assertStringContainsString('placement="right"', $sidebar);
        $this->assertStringContainsString('aria-expanded={expanded}', $sidebar);
        $this->assertStringContainsString('aria-current={active ? \'page\' : undefined}', $sidebar);
        $this->assertStringContainsString('itemIsActive', $sidebar);
        $this->assertStringContainsString('setOpen', $sidebar);
        $this->assertStringContainsString('toggleExclusiveSection', $sidebar);
        $this->assertStringContainsString('sectionIsExpanded', $sidebar);
        $this->assertStringContainsString('toggleExclusiveSection(cur, key, expanded)', $sidebar);
        $this->assertStringContainsString("return { [key]: true }", $sidebar);
        $this->assertStringContainsString("return { [key]: false }", $sidebar);
        $this->assertStringContainsString('data-nav-accordion={expanded ? \'open\' : \'closed\'}', $sidebar);
        $this->assertStringContainsString('ui.navAccordionOpen', $sidebar);
        $this->assertStringContainsString('ui.navAccordion', $sidebar);
        $this->assertStringContainsString('ui.navAccordionClip', $sidebar);
        $this->assertStringContainsString('tabIndex={expanded ? 0 : -1}', $sidebar);
        $this->assertStringContainsString('findActiveSection', $sidebar);
        $this->assertStringContainsString('openStateForActiveSection', $sidebar);
        $this->assertStringContainsString('parentRowIsHighlighted', $sidebar);
        $this->assertStringContainsString('highlighted ? ui.navParentOpen : ui.navParent', $sidebar);
        $this->assertStringNotContainsString('childActive || expanded ? ui.navParentOpen', $sidebar);
        $this->assertStringNotContainsString('childActive || flyoutOpen ? ui.navIconBtnActive', $sidebar);
        $this->assertStringNotContainsString('{ ...cur, [key]: !expanded }', $sidebar);

        $this->assertStringContainsString("grid-cols-[var(--admin-sidebar-width,248px)_minmax(0,1fr)]", $classes);
        $this->assertStringContainsString('transition-[grid-template-columns] duration-300', $classes);
        $this->assertStringContainsString('motion-reduce:transition-none', $classes);
        $this->assertStringContainsString('sidebarCollapsed:', $classes);
        $this->assertStringContainsString('sidebarToggle:', $classes);
        $this->assertDoesNotMatchRegularExpression(
            "/sidebarToggle:\\s*'[^']*translate-x-1\\/2/",
            $classes,
        );
        $this->assertDoesNotMatchRegularExpression(
            "/sidebarToggle:\\s*'[^']*absolute/",
            $classes,
        );
        $this->assertStringContainsString('brandLinkCollapsed:', $classes);
        $this->assertStringContainsString('navCollapsed:', $classes);
        $this->assertStringContainsString('navScroll:', $classes);
        $this->assertStringContainsString('group/sidebar', $classes);
        $this->assertStringContainsString("sidebar: 'group/sidebar", $classes);
        $this->assertStringContainsString('pl-4 pt-4 pb-4 pr-0', $classes);
        $this->assertStringContainsString("nav: 'flex flex-col gap-1 pr-[2px]", $classes);
        $this->assertStringContainsString("navCollapsed: 'flex flex-col items-center gap-1 pr-[2px]", $classes);
        $this->assertStringContainsString('[scrollbar-gutter:stable]', $classes);
        $this->assertStringContainsString("'[scrollbar-width:thin] [scrollbar-color:transparent_transparent]'", $classes);
        $this->assertStringContainsString('[&::-webkit-scrollbar]:w-1.5', $classes);
        $this->assertStringContainsString('[&::-webkit-scrollbar-thumb]:bg-transparent', $classes);
        $this->assertStringContainsString('group-hover/sidebar:[&::-webkit-scrollbar-thumb]:bg-neutral-300/80', $classes);
        $this->assertStringContainsString('group-focus-within/sidebar:[&::-webkit-scrollbar-thumb]:bg-neutral-300/80', $classes);
        $this->assertStringNotContainsString("group-hover/sidebar:[&::-webkit-scrollbar]:w-1.5", $classes);
        $this->assertStringNotContainsString("group-hover/sidebar:[scrollbar-width:thin]", $classes);
        $this->assertStringContainsString('[&::-webkit-scrollbar-button]:hidden', $classes);
        $this->assertStringContainsString('[&::-webkit-scrollbar-button]:h-0', $classes);
        $this->assertStringContainsString('overscroll-y-contain', $classes);
        $this->assertStringContainsString('ui.navScroll', $sidebar);
        $this->assertStringContainsString('data-admin-sidebar-nav', $sidebar);
        $this->assertStringContainsString("logout: 'mt-4 mr-4", $classes);
        $this->assertStringContainsString('navIconBtn:', $classes);
        $this->assertStringContainsString('navIconBtnActive:', $classes);
        $this->assertStringContainsString('navFlyout:', $classes);
        $this->assertStringContainsString('group/flyout', $classes);
        $this->assertStringContainsString("'[&::-webkit-scrollbar]:w-1'", $classes);
        $this->assertStringContainsString('hover:[&::-webkit-scrollbar-thumb]:bg-neutral-300/80', $classes);
        $this->assertStringContainsString('focus-within:[&::-webkit-scrollbar-thumb]:bg-neutral-300/80', $classes);
        $this->assertStringContainsString('data-admin-sidebar-flyout', $sidebar);
        $this->assertStringContainsString('Collapsed-rail flyout only', $classes);
        $this->assertStringContainsString('navFlyoutLink:', $classes);
        $this->assertStringContainsString('navFlyoutLinkActive:', $classes);
        $this->assertStringContainsString('logoutCollapsed:', $classes);
        $this->assertStringContainsString('navAccordion:', $classes);
        $this->assertStringContainsString('navAccordionOpen:', $classes);
        $this->assertStringContainsString('navAccordionClip:', $classes);
        $this->assertStringContainsString('grid-rows-[0fr]', $classes);
        $this->assertStringContainsString('grid-rows-[1fr]', $classes);
        $this->assertStringContainsString('motion-reduce:transition-none', $classes);
        $this->assertStringContainsString('transition-[grid-template-rows] duration-300', $classes);

        $this->assertStringContainsString('--admin-sidebar-duration: 300ms', $adminCss);
        $this->assertStringContainsString('--admin-sidebar-width-collapsed: 64px', $adminCss);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $adminCss);

        $this->assertStringContainsString("export function buildAdminMenu", $menu);
        $this->assertStringContainsString("id: 'catalog'", $menu);
        $this->assertStringContainsString("id: 'orders'", $menu);
        $this->assertStringContainsString("id: 'dashboard'", $menu);
    }

    public function test_tooltip_supports_right_placement_for_sidebar_labels(): void
    {
        $tooltip = (string) file_get_contents(base_path('resources/js/admin/ui/Tooltip.jsx'));

        $this->assertStringContainsString("placement === 'right'", $tooltip);
        $this->assertStringContainsString('rect.right + gap', $tooltip);
    }

    public function test_layout_persists_exclusive_accordion_open_state(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/admin/Pages/Layout.jsx'));

        $this->assertStringContainsString('openKeys.length <= 1', $layout);
        $this->assertStringContainsString('{ [openKeys[0]]: true }', $layout);
        $this->assertStringContainsString('wento-admin-nav-open', $layout);
    }

    public function test_accordion_collapse_does_not_auto_open_active_parent(): void
    {
        $sidebar = (string) file_get_contents(base_path('resources/js/admin/Pages/AdminSidebar.jsx'));

        // Manual collapse must suppress active-route fallback (was reopening Catalog).
        $this->assertStringContainsString('entries.some(([, value]) => value === false)', $sidebar);
        $this->assertStringContainsString('return false;', $sidebar);
        $this->assertStringContainsString('do not auto-reopen the active page', $sidebar);
        $this->assertStringNotContainsString(
            'Object.prototype.hasOwnProperty.call(open || {}, key) && open[key] === false',
            $sidebar,
        );

        // Navigation still forces the active parent open; idle/unset still follows active.
        $this->assertStringContainsString('openStateForActiveSection(cur, key, { pathChanged })', $sidebar);
        $this->assertStringContainsString('return Boolean(isActiveParent);', $sidebar);
        $this->assertStringContainsString('toggleExclusiveSection(cur, key, expanded)', $sidebar);
    }

    public function test_sidebar_active_helpers_cover_main_menu_and_design(): void
    {
        $helper = base_path('resources/js/admin/ui/adminSidebarActive.js');
        $runner = base_path('tests/Unit/adminSidebarActive.test.mjs');
        $this->assertFileExists($helper);
        $this->assertFileExists($runner);

        $command = 'node '.escapeshellarg($runner).' 2>&1';
        exec($command, $output, $code);
        $text = implode("\n", $output);

        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('ALL PASS', $text);
        $this->assertStringContainsString('Main menu configure → active section mainmenu', $text);
        $this->assertStringContainsString('Theme module header → active section design', $text);
        $this->assertStringContainsString('Refresh on Design with stale Main menu open → open design only', $text);
        $this->assertStringContainsString('Expanded-only parent is not highlighted', $text);
    }

    public function test_sidebar_toggle_uses_single_collapsed_state_without_lagged_chrome(): void
    {
        $layout = (string) file_get_contents(base_path('resources/js/admin/Pages/Layout.jsx'));
        $sidebar = (string) file_get_contents(base_path('resources/js/admin/Pages/AdminSidebar.jsx'));

        // Dual collapsed/iconMode + timer remounted chrome mid-width-transition (jerky rail).
        $this->assertStringNotContainsString('const [iconMode, setIconMode]', $layout);
        $this->assertStringNotContainsString('window.setTimeout', $layout);
        $this->assertStringNotContainsString('sidebarAnimRef', $layout);
        $this->assertStringContainsString('collapsed={collapsed}', $layout);
        $this->assertStringContainsString("style={{ '--admin-sidebar-width': collapsed ? '64px' : '248px' }}", $layout);

        $this->assertStringNotContainsString('widthCollapsed', $sidebar);
        $this->assertMatchesRegularExpression(
            '/sidebarLogoPath\(\{\s*logoUrl:\s*shop\?\.logo_url,\s*collapsed\s*\}\)/',
            $sidebar,
        );
    }

    public function test_sidebar_logo_switches_between_full_and_compact_assets(): void
    {
        $helper = base_path('resources/js/admin/ui/adminSidebarLogo.js');
        $runner = base_path('tests/Unit/adminSidebarLogo.test.mjs');
        $this->assertFileExists($helper);
        $this->assertFileExists($runner);

        $command = 'node '.escapeshellarg($runner).' 2>&1';
        exec($command, $output, $code);
        $text = implode("\n", $output);

        $this->assertSame(0, $code, $text);
        $this->assertStringContainsString('ALL PASS', $text);
        $this->assertStringContainsString('collapsed → compact logo', $text);
        $this->assertStringContainsString('expanded with shop logo → shop logo', $text);

        $this->assertFileExists(base_path('themes/classic/assets/merchant/ui/logo.png'));
        $this->assertFileExists(base_path('themes/classic/assets/merchant/ui/logo_short.png'));
        $this->assertSame(
            '/themes/classic/assets/merchant/ui/logo_short.png',
            \App\Domain\Theme\ThemeSettingsCatalog::DEFAULT_LOGO_SHORT_URL,
        );
    }
}
