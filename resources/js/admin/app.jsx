import '@vitejs/plugin-react/preamble';
import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import '../../css/admin.css';
import { setI18nMessages } from './ui';
import { adminUrl } from './webPath';

/** Apply UI strings + document direction before page components call t(). */
function applyAdminI18n(page) {
    const i18n = page?.props?.i18n;
    setI18nMessages(i18n?.messages);
    if (typeof document === 'undefined') {
        return;
    }
    const code = String(i18n?.locale || 'en').toLowerCase();
    document.documentElement.lang = code;
    document.documentElement.dir = code.startsWith('ar') ? 'rtl' : 'ltr';
}

router.on('navigate', (event) => applyAdminI18n(event.detail.page));
router.on('success', (event) => applyAdminI18n(event.detail.page));

const visit = router.visit.bind(router);
router.visit = (url, options) => visit(adminUrl(url), options);

const nativeFetch = window.fetch.bind(window);
window.fetch = (input, init) => {
    if (typeof input === 'string') {
        return nativeFetch(adminUrl(input), init);
    }
    if (input instanceof Request) {
        const next = adminUrl(input.url);
        if (next && next !== input.url) {
            return nativeFetch(new Request(next, input), init);
        }
    }

    return nativeFetch(input, init);
};

function publishAdminLinks(root) {
    if (!(root instanceof Element)) {
        return;
    }
    const links = root.matches('a[href]') ? [root] : [];
    root.querySelectorAll('a[href]').forEach((link) => links.push(link));
    links.forEach((link) => {
        // Editable content (rich-text editors, visual builders) owns its DOM.
        // Rewriting hrefs inside it fights the editor: the editor reverts the
        // change, the watcher rewrites it again, and the tab hangs forever.
        if (link.closest('[contenteditable], .ProseMirror, .email-canvas, .page-canvas-frame, .gjs-canvas')) {
            return;
        }
        const href = link.getAttribute('href');
        const next = adminUrl(href);
        if (!next || next === href) {
            return;
        }
        link.setAttribute('href', next);
    });
}

function watchAdminLinks() {
    publishAdminLinks(document.documentElement);
    const observer = new MutationObserver((records) => {
        records.forEach((record) => {
            if (record.type === 'attributes' && record.target instanceof Element) {
                publishAdminLinks(record.target);
                return;
            }
            record.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    publishAdminLinks(node);
                }
            });
        });
    });
    observer.observe(document.documentElement, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['href'],
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watchAdminLinks);
} else {
    watchAdminLinks();
}

document.addEventListener('click', (event) => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
    }
    const target = event.target;
    const link = target instanceof Element ? target.closest('a[href]') : null;
    if (!link || link.target === '_blank' || link.hasAttribute('download')) {
        return;
    }
    const href = link.getAttribute('href');
    const next = adminUrl(href);
    if (!next || next === href) {
        return;
    }
    event.preventDefault();
    window.location.assign(next);
});

const pages = import.meta.glob('./Pages/**/*.jsx');

// A settings save is a PUT that redirects. When the follow-up response is a
// normal HTML document (no X-Inertia header), Inertia opens a modal and tries
// to render that document in a sandboxed frame. The frame stays white, so the
// admin looks blank until a manual refresh. Reload instead.
let recoveringHtmlResponse = false;
router.on('httpException', (event) => {
    const response = event.detail?.response;
    const headers = response?.headers || {};
    if (headers['x-inertia']) {
        return;
    }
    event.preventDefault();
    if (recoveringHtmlResponse) {
        return false;
    }
    const key = 'wento-html-reload';
    const tries = Number(sessionStorage.getItem(key) || '0');
    if (tries >= 1) {
        sessionStorage.removeItem(key);
        window.alert('This page could not be saved. Refresh and try the switch again.');
        return false;
    }
    recoveringHtmlResponse = true;
    sessionStorage.setItem(key, '1');
    window.location.reload();
    return false;
});

window.addEventListener('vite:preloadError', (event) => {
    const key = 'wento-asset-reload';
    const n = Number(sessionStorage.getItem(key) || '0');
    if (n < 1) {
        // Swallow only while we are about to reload. Always preventDefault()
        // turns a failed import into `undefined`, and Inertia then crashes on
        // `module.default`.
        event.preventDefault();
        sessionStorage.setItem(key, String(n + 1));
        window.location.reload();
    }
});

function showBootError(error) {
    const el = document.getElementById('app');
    const message = error?.message ? String(error.message) : 'Unknown error';
    if (el) {
        el.innerHTML = `<div class="admin-boot-error"><p>Admin failed to start.</p><p>${message.replace(/</g, '&lt;')}</p><p><a href="">Reload</a></p></div>`;
    }
    console.error(error);
}

function unwrapPage(mod, name) {
    if (typeof mod === 'function' || (mod && (mod.default || typeof mod.render === 'function'))) {
        return mod;
    }
    throw new Error(`Admin page failed to load: ${name}`);
}

function loadPage(name) {
    const loader = pages[`./Pages/${name}.jsx`];
    if (!loader) {
        return Promise.reject(new Error(`Admin page not found: ${name}`));
    }

    const load = () => loader().then((mod) => unwrapPage(mod, name));

    return load().catch(() => new Promise((resolve) => {
        window.setTimeout(resolve, 250);
    }).then(() => load()));
}

createInertiaApp({
    resolve: (name) => loadPage(name),
    setup({ el, App, props }) {
        if (!el) {
            throw new Error('Admin root element is missing');
        }
        sessionStorage.removeItem('wento-asset-reload');
        sessionStorage.removeItem('wento-html-reload');
        applyAdminI18n(props.initialPage);
        createRoot(el).render(<App {...props} />);
    },
}).catch(showBootError);
