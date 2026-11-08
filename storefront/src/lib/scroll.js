/**
 * Instant viewport jump for SPA route changes.
 * html { scroll-behavior: smooth } must not animate these.
 */
export function jumpToTop() {
    const root = document.documentElement;
    const previous = root.style.scrollBehavior;
    root.style.scrollBehavior = 'auto';
    window.scrollTo(0, 0);
    root.scrollTop = 0;
    document.body.scrollTop = 0;
    root.style.scrollBehavior = previous;
}

/**
 * Height of the storefront chrome that stays over content while scrolling.
 * Prefers fixed/stuck header rows; falls back to the full #header box.
 */
export function stickyHeaderOffset(extra = 12) {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return extra;
    }

    const header = document.getElementById('header');
    if (!header) {
        return extra;
    }

    let height = 0;
    const stuckLogo = header.querySelector('.header-top.is-stuck');
    if (stuckLogo) {
        height += stuckLogo.getBoundingClientRect().height;
        const stuckNav = header.querySelector('.header-top.is-stuck + .header-top-main');
        if (stuckNav) {
            const style = window.getComputedStyle(stuckNav);
            if (style.display !== 'none' && style.visibility !== 'hidden') {
                height += stuckNav.getBoundingClientRect().height;
            }
        }
    } else {
        // Estimate sticky chrome before it engages: logo row + desktop nav (nav hides ≤1024px).
        const logo = header.querySelector('.header-top');
        const nav = header.querySelector('.header-top-main');
        if (logo) {
            height += logo.getBoundingClientRect().height;
        }
        if (nav) {
            const style = window.getComputedStyle(nav);
            if (style.display !== 'none' && style.visibility !== 'hidden') {
                height += nav.getBoundingClientRect().height;
            }
        }
        if (!height) {
            // Ignore top announcement bar that will scroll away; use current header bottom in view.
            const rect = header.getBoundingClientRect();
            height = Math.max(0, Math.min(rect.bottom, rect.height));
        }
    }

    return Math.max(0, Math.ceil(height)) + extra;
}

/**
 * Smoothly (or instantly) bring `el` just below the sticky header.
 */
export function scrollToUnderHeader(el, { behavior = 'smooth', extra = 12 } = {}) {
    if (!el || typeof window === 'undefined') {
        return;
    }
    const offset = stickyHeaderOffset(extra);
    const top = Math.max(0, window.scrollY + el.getBoundingClientRect().top - offset);
    window.scrollTo({ top, behavior });
}

export function jumpToHash(hash) {
    const id = decodeURIComponent(String(hash || '').replace(/^#/, ''));
    if (!id) {
        jumpToTop();
        return;
    }
    const target = document.getElementById(id);
    if (!target) {
        jumpToTop();
        return;
    }
    const root = document.documentElement;
    const previous = root.style.scrollBehavior;
    root.style.scrollBehavior = 'auto';
    scrollToUnderHeader(target, { behavior: 'auto' });
    root.style.scrollBehavior = previous;
}
