import assert from 'node:assert/strict';
import {
    DEFAULT_SHOP_LOGO,
    DEFAULT_SHOP_LOGO_SHORT,
    LEGACY_SHOP_LOGO,
    sidebarLogoPath,
} from '../../resources/js/admin/ui/adminSidebarLogo.js';

function check(label, actual, expected) {
    assert.equal(actual, expected, label);
    console.log(`ok — ${label}`);
}

check('expanded with no logo → full default', sidebarLogoPath({ collapsed: false }), DEFAULT_SHOP_LOGO);
check(
    'expanded with shop logo → shop logo',
    sidebarLogoPath({ logoUrl: '/storage/custom-logo.png', collapsed: false }),
    '/storage/custom-logo.png',
);
check(
    'expanded with empty string → full default',
    sidebarLogoPath({ logoUrl: '   ', collapsed: false }),
    DEFAULT_SHOP_LOGO,
);
check('collapsed → compact logo', sidebarLogoPath({ collapsed: true }), DEFAULT_SHOP_LOGO_SHORT);
check(
    'collapsed with custom logo still uses compact mark',
    sidebarLogoPath({ logoUrl: '/storage/custom-logo.png', collapsed: true }),
    DEFAULT_SHOP_LOGO_SHORT,
);
check(
    'collapsed with full default still uses compact mark',
    sidebarLogoPath({ logoUrl: DEFAULT_SHOP_LOGO, collapsed: true }),
    DEFAULT_SHOP_LOGO_SHORT,
);
check(
    'collapsed with legacy default still uses compact mark',
    sidebarLogoPath({ logoUrl: LEGACY_SHOP_LOGO, collapsed: true }),
    DEFAULT_SHOP_LOGO_SHORT,
);
check('paths are project-relative theme assets', DEFAULT_SHOP_LOGO.startsWith('/themes/classic/'), true);
check('short path is project-relative theme asset', DEFAULT_SHOP_LOGO_SHORT.endsWith('/logo_short.png'), true);
check('short path is not the full logo', DEFAULT_SHOP_LOGO_SHORT !== DEFAULT_SHOP_LOGO, true);

console.log('ALL PASS');
