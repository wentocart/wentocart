/** Matches ThemeSettingsCatalog::DEFAULT_LOGO_URL — Classic install logo. */
export const DEFAULT_SHOP_LOGO = '/themes/classic/assets/merchant/ui/logo.png';

/** Compact mark for the collapsed admin sidebar rail. */
export const DEFAULT_SHOP_LOGO_SHORT = '/themes/classic/assets/merchant/ui/logo_short.png';

/** Previous install default (still treated as bundled). */
export const LEGACY_SHOP_LOGO = '/themes/classic/assets/merchant/ui/logo.svg';

/**
 * Pick the sidebar brand image for the current rail width.
 * Expanded → shop / full default logo. Collapsed → bundled compact mark.
 *
 * @param {{ logoUrl?: string|null, collapsed?: boolean }} opts
 * @returns {string}
 */
export function sidebarLogoPath({ logoUrl = '', collapsed = false } = {}) {
    if (collapsed) {
        return DEFAULT_SHOP_LOGO_SHORT;
    }
    const raw = String(logoUrl || '').trim();

    return raw || DEFAULT_SHOP_LOGO;
}
