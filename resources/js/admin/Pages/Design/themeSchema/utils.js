export function getByPath(obj, path) {
    if (!path) return undefined;
    return path.split('.').reduce((cur, key) => (cur && typeof cur === 'object' ? cur[key] : undefined), obj);
}

export function setByPath(obj, path, value) {
    const parts = path.split('.');
    const next = { ...obj };
    let cur = next;
    parts.forEach((part, index) => {
        if (index === parts.length - 1) {
            cur[part] = value;
            return;
        }
        cur[part] = { ...(cur[part] || {}) };
        cur = cur[part];
    });
    return next;
}

/** @type {{ key: string, label: string, description: string }[]} */
export const THEME_SCHEMA_TABS = [
    {
        key: 'layout',
        label: 'Layout',
        description: 'Choose which homepage blocks are visible on the storefront.',
    },
    {
        key: 'brand',
        label: 'Header & brand',
        description: 'Logo, contact details, announcement bar, and brand colors.',
    },
    {
        key: 'homepage',
        label: 'Homepage',
        description: 'Hero, ticker, services, categories, and promo banners.',
    },
    {
        key: 'modules',
        label: 'Modules',
        description: 'Product collections, carousels, trending, steps, and testimonials.',
    },
    {
        key: 'content',
        label: 'Blog & newsletter',
        description: 'Editorial blog block and newsletter signup banner.',
    },
    {
        key: 'footer',
        label: 'Footer',
        description: 'Footer about text, menus, recent posts, and payment image.',
    },
];

const GROUP_TAB_MAP = {
    sections: 'layout',
    header: 'brand',
    colors: 'brand',
    ticker: 'homepage',
    hero: 'homepage',
    services: 'homepage',
    categories: 'homepage',
    promos: 'homepage',
    feature_collection: 'modules',
    feature_carousel: 'modules',
    process: 'modules',
    trending: 'modules',
    testimonials: 'modules',
    blog: 'content',
    newsletter: 'content',
    footer: 'footer',
};

/**
 * @param {Array<{ id?: string, label?: string, fields?: unknown[] }>} groups
 */
export function buildTabPlan(groups) {
    const byTab = Object.fromEntries(THEME_SCHEMA_TABS.map((tab) => [tab.key, { ...tab, groups: [] }]));
    const other = { key: 'other', label: 'Other', description: 'Additional theme options.', groups: [] };

    groups.forEach((group) => {
        const tabKey = GROUP_TAB_MAP[group.id] || 'other';
        const bucket = tabKey === 'other' ? other : byTab[tabKey];
        if (bucket) {
            bucket.groups.push(group);
        }
    });

    const tabs = THEME_SCHEMA_TABS
        .map((tab) => byTab[tab.key])
        .filter((tab) => tab.groups.length > 0);

    if (other.groups.length > 0) {
        tabs.push(other);
    }

    return tabs;
}

export function isFullWidthField(field) {
    const type = field?.type || 'text';
    return type === 'repeater' || type === 'strings' || type === 'textarea';
}

export function isToggleOnlyGroup(group) {
    const fields = group?.fields || [];
    return fields.length > 0 && fields.every((field) => field.type === 'toggle');
}
