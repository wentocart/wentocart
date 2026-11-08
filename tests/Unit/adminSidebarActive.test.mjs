/**
 * Node runner for adminSidebarActive helpers (invoked from PHPUnit).
 * Exit 0 + prints PASS lines; exit 1 on failure.
 */
import {
    findActiveSection,
    isActive,
    itemIsActive,
    openStateForActiveSection,
    parentRowIsHighlighted,
    sectionKey,
} from '../../resources/js/admin/ui/adminSidebarActive.js';

function assert(condition, message) {
    if (!condition) {
        console.error('FAIL:', message);
        process.exit(1);
    }
    console.log('PASS:', message);
}

const mainMenu = {
    id: 'mainmenu',
    label: 'Main menu',
    items: [
        { href: '/admin/modules/weblytic/mainmenu/configure', label: 'Configure' },
        { href: '/admin/modules/weblytic/mainmenu/links', label: 'Links' },
    ],
};

const design = {
    id: 'design',
    label: 'Design',
    items: [
        { href: '/admin/cms', label: 'Pages' },
        { href: '/admin/themes', label: 'Themes' },
        { href: '/admin/themes/settings', label: 'Theme settings' },
        { href: '/admin/extensions', label: 'Extensions' },
    ],
};

const menu = [mainMenu, design];
const allHrefs = menu.flatMap((node) => node.items.map((item) => item.href));

// 1) Main menu page highlights Main menu only
{
    const path = '/admin/modules/weblytic/mainmenu/configure';
    const active = findActiveSection(menu, path, allHrefs);
    assert(sectionKey(active) === 'mainmenu', 'Main menu configure → active section mainmenu');
    assert(itemIsActive(path, mainMenu.items[0], allHrefs), 'Main menu Configure child is active');
    assert(!itemIsActive(path, design.items[1], allHrefs), 'Design Themes is not active on mainmenu URL');
    assert(parentRowIsHighlighted(true) === true, 'parent highlighted when child active');
    assert(parentRowIsHighlighted(false) === false, 'parent not highlighted when only expanded');
    assert(
        parentRowIsHighlighted(true, 'mainmenu', 'mainmenu') === true,
        'winning section stays highlighted',
    );
    assert(
        parentRowIsHighlighted(true, 'design', 'mainmenu') === false,
        'non-winning section never highlighted even if childActive',
    );
}

// 2) Design page highlights Design only
{
    const path = '/admin/themes/modules/header';
    const active = findActiveSection(menu, path, allHrefs);
    assert(sectionKey(active) === 'design', 'Theme module header → active section design');
    assert(itemIsActive(path, design.items[1], allHrefs), 'Design Themes matches /admin/themes/modules/header');
    assert(
        !mainMenu.items.some((item) => itemIsActive(path, item, allHrefs)),
        'Main menu children are not active on Design URL',
    );
}

// 3) Moving between sections: longest match / exclusive active section
{
    const designPath = '/admin/themes/settings';
    const mainPath = '/admin/modules/weblytic/mainmenu/links';
    assert(sectionKey(findActiveSection(menu, designPath, allHrefs)) === 'design', 'Theme settings → design');
    assert(sectionKey(findActiveSection(menu, mainPath, allHrefs)) === 'mainmenu', 'Main menu links → mainmenu');
    // Nested themes/settings prefers settings over themes prefix
    assert(
        isActive(designPath, '/admin/themes/settings', allHrefs),
        'Exact /admin/themes/settings wins over shorter /admin/themes',
    );
    assert(
        !isActive(designPath, '/admin/themes', allHrefs),
        'Shorter /admin/themes is not active when longer match exists',
    );
}

// 4) Nested routes highlight parent section
{
    const path = '/admin/cms/pages/12/edit';
    assert(sectionKey(findActiveSection(menu, path, allHrefs)) === 'design', 'Nested CMS edit → design');
}

// 5) Direct URL / refresh: correct open state (stale Main menu not left open)
{
    const stale = { mainmenu: true };
    const corrected = openStateForActiveSection(stale, 'design', { pathChanged: false });
    assert(
        corrected.design === true && !corrected.mainmenu,
        'Refresh on Design with stale Main menu open → open design only',
    );

    const afterNav = openStateForActiveSection({ design: true }, 'mainmenu', { pathChanged: true });
    assert(
        afterNav.mainmenu === true && !afterNav.design,
        'Navigate Design → Main menu opens mainmenu only',
    );

    const keptCollapsed = openStateForActiveSection({ design: false }, 'design', { pathChanged: false });
    assert(
        keptCollapsed.design === false,
        'Explicit collapse of active parent is preserved on same path',
    );

    const idle = openStateForActiveSection({}, 'design', { pathChanged: false });
    assert(Object.keys(idle).length === 0, 'Idle {} stays {} (active fallback still works)');
}

// 6) Expand without route active must not highlight
assert(
    parentRowIsHighlighted(false) === false,
    'Expanded-only parent is not highlighted (accordion independent of active style)',
);

console.log('ALL PASS');
process.exit(0);
