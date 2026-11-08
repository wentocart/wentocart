export const CATEGORY_ORDER = [
    'payment',
    'shipping',
    'tax',
    'marketing',
    'analytics',
    'auth',
    'storage',
    'admin',
    'business',
    'other',
    'uncategorized',
];

export const CATEGORY_LABELS = {
    payment: 'Payment',
    shipping: 'Shipping & logistics',
    tax: 'Tax',
    marketing: 'Promotions & marketing',
    analytics: 'Analytics',
    auth: 'Authentication',
    storage: 'Storage',
    admin: 'Administration',
    business: 'Business',
    other: 'Other',
    uncategorized: 'Other extensions',
};

export function categoryKey(row) {
    const key = (row?.category || '').trim().toLowerCase();

    return key || 'uncategorized';
}

export function categoryLabel(key) {
    return CATEGORY_LABELS[key] || CATEGORY_LABELS.uncategorized;
}

export function normalizeInstalledApp(app) {
    return {
        ...app,
        installed: true,
        key: app.id,
        installable: false,
    };
}

export function normalizeCatalogItem(item) {
    return {
        ...item,
        installed: false,
        key: item.id,
        enabled: false,
        href: null,
        can_delete: item.can_delete !== false,
        can_uninstall: false,
        health_status: null,
        dependencies: { errors: [] },
        compatibility: { ok: true },
    };
}

/** @param {Array} apps @param {Array} catalog */
export function buildModuleRows(apps = [], catalog = []) {
    const installedIds = new Set((apps || []).map((app) => app.id));

    return [
        ...(apps || []).map(normalizeInstalledApp),
        ...(catalog || []).filter((item) => !installedIds.has(item.id)).map(normalizeCatalogItem),
    ];
}

export function hasModuleAlert(row) {
    if (!row?.installed) {
        return !!row?.blocked && !row?.installable;
    }

    if (row.compatibility?.ok === false) {
        return true;
    }
    if ((row.dependencies?.errors || []).length > 0) {
        return true;
    }
    if (row.health_status === 'error') {
        return true;
    }
    if (row.last_error) {
        return true;
    }

    return false;
}

export function alertMessage(row) {
    if (!row?.installed) {
        return row?.blocked || null;
    }
    if (row.last_error) {
        return row.last_error;
    }
    if ((row.dependencies?.errors || [])[0]) {
        return row.dependencies.errors[0];
    }
    if (row.compatibility?.ok === false) {
        return row.compatibility.message;
    }
    if (row.health_status === 'error') {
        return 'Extension is unhealthy.';
    }

    return null;
}

export function countAlerts(rows) {
    return rows.filter(hasModuleAlert).length;
}

export function countUpdates(rows) {
    return rows.filter((row) => row.installed && row.can_upgrade).length;
}

export function matchesSearch(row, query) {
    const q = (query || '').trim().toLowerCase();
    if (!q) {
        return true;
    }

    const haystack = [
        row.display_name,
        row.id,
        row.vendor,
        row.name,
        row.author,
        row.description,
        row.category,
        row.type,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return haystack.includes(q);
}

export function matchesStatus(row, status) {
    switch (status) {
    case 'installed':
        return !!row.installed;
    case 'not_installed':
        return !row.installed;
    case 'enabled':
        return row.installed && row.enabled;
    case 'disabled':
        return row.installed && !row.enabled;
    case 'alert':
        return hasModuleAlert(row);
    default:
        return true;
    }
}

export function filterModules(rows, { tab = 'modules', q = '', category = 'all', status = 'all' }) {
    let filtered = rows.filter((row) => matchesSearch(row, q));

    if (category !== 'all') {
        filtered = filtered.filter((row) => categoryKey(row) === category);
    }

    if (status !== 'all') {
        filtered = filtered.filter((row) => matchesStatus(row, status));
    }

    if (tab === 'alerts') {
        filtered = filtered.filter(hasModuleAlert);
    } else if (tab === 'updates') {
        filtered = filtered.filter((row) => row.installed && row.can_upgrade);
    }

    return filtered;
}

export function groupByCategory(rows) {
    const groups = new Map();

    rows.forEach((row) => {
        const key = categoryKey(row);
        if (!groups.has(key)) {
            groups.set(key, []);
        }
        groups.get(key).push(row);
    });

    return CATEGORY_ORDER
        .filter((key) => groups.has(key))
        .map((key) => ({
            key,
            label: categoryLabel(key),
            rows: groups.get(key).sort((a, b) => String(a.display_name).localeCompare(String(b.display_name))),
        }));
}

export function categoryOptions(rows) {
    const keys = new Set(rows.map(categoryKey));

    return CATEGORY_ORDER.filter((key) => keys.has(key)).map((key) => ({
        value: key,
        label: categoryLabel(key),
    }));
}

export function iconForCategory(category) {
    switch (category) {
    case 'payment':
        return 'orders';
    case 'shipping':
        return 'truck';
    case 'marketing':
        return 'tag';
    case 'analytics':
        return 'bar-chart';
    case 'auth':
        return 'lock';
    case 'storage':
        return 'folder';
    case 'admin':
        return 'settings';
    case 'business':
        return 'bag';
    default:
        return 'package';
    }
}

const CATEGORY_TONES = {
    payment: {
        icon: 'bg-sky-100 text-sky-600',
        tile: 'border-sky-200 bg-sky-50 text-sky-600',
        bar: 'border-neutral-200/90 bg-white hover:border-sky-200 hover:bg-sky-50/50',
        barOpen: 'border-sky-200 bg-sky-50/80 hover:bg-sky-50',
        count: 'bg-sky-100 text-sky-700',
        chevron: 'text-sky-500',
    },
    shipping: {
        icon: 'bg-amber-100 text-amber-600',
        tile: 'border-amber-200 bg-amber-50 text-amber-600',
        bar: 'border-neutral-200/90 bg-white hover:border-amber-200 hover:bg-amber-50/50',
        barOpen: 'border-amber-200 bg-amber-50/80 hover:bg-amber-50',
        count: 'bg-amber-100 text-amber-700',
        chevron: 'text-amber-500',
    },
    tax: {
        icon: 'bg-emerald-100 text-emerald-600',
        tile: 'border-emerald-200 bg-emerald-50 text-emerald-600',
        bar: 'border-neutral-200/90 bg-white hover:border-emerald-200 hover:bg-emerald-50/50',
        barOpen: 'border-emerald-200 bg-emerald-50/80 hover:bg-emerald-50',
        count: 'bg-emerald-100 text-emerald-700',
        chevron: 'text-emerald-500',
    },
    marketing: {
        icon: 'bg-rose-100 text-rose-600',
        tile: 'border-rose-200 bg-rose-50 text-rose-600',
        bar: 'border-neutral-200/90 bg-white hover:border-rose-200 hover:bg-rose-50/50',
        barOpen: 'border-rose-200 bg-rose-50/80 hover:bg-rose-50',
        count: 'bg-rose-100 text-rose-700',
        chevron: 'text-rose-500',
    },
    analytics: {
        icon: 'bg-indigo-100 text-indigo-600',
        tile: 'border-indigo-200 bg-indigo-50 text-indigo-600',
        bar: 'border-neutral-200/90 bg-white hover:border-indigo-200 hover:bg-indigo-50/50',
        barOpen: 'border-indigo-200 bg-indigo-50/80 hover:bg-indigo-50',
        count: 'bg-indigo-100 text-indigo-700',
        chevron: 'text-indigo-500',
    },
    auth: {
        icon: 'bg-cyan-100 text-cyan-700',
        tile: 'border-cyan-200 bg-cyan-50 text-cyan-700',
        bar: 'border-neutral-200/90 bg-white hover:border-cyan-200 hover:bg-cyan-50/50',
        barOpen: 'border-cyan-200 bg-cyan-50/80 hover:bg-cyan-50',
        count: 'bg-cyan-100 text-cyan-800',
        chevron: 'text-cyan-600',
    },
    storage: {
        icon: 'bg-orange-100 text-orange-600',
        tile: 'border-orange-200 bg-orange-50 text-orange-600',
        bar: 'border-neutral-200/90 bg-white hover:border-orange-200 hover:bg-orange-50/50',
        barOpen: 'border-orange-200 bg-orange-50/80 hover:bg-orange-50',
        count: 'bg-orange-100 text-orange-700',
        chevron: 'text-orange-500',
    },
    admin: {
        icon: 'bg-violet-100 text-violet-600',
        tile: 'border-violet-200 bg-violet-50 text-violet-600',
        bar: 'border-neutral-200/90 bg-white hover:border-violet-200 hover:bg-violet-50/50',
        barOpen: 'border-violet-200 bg-violet-50/80 hover:bg-violet-50',
        count: 'bg-violet-100 text-violet-700',
        chevron: 'text-violet-500',
    },
    business: {
        icon: 'bg-teal-100 text-teal-700',
        tile: 'border-teal-200 bg-teal-50 text-teal-700',
        bar: 'border-neutral-200/90 bg-white hover:border-teal-200 hover:bg-teal-50/50',
        barOpen: 'border-teal-200 bg-teal-50/80 hover:bg-teal-50',
        count: 'bg-teal-100 text-teal-800',
        chevron: 'text-teal-600',
    },
    other: {
        icon: 'bg-fuchsia-100 text-fuchsia-600',
        tile: 'border-fuchsia-200 bg-fuchsia-50 text-fuchsia-600',
        bar: 'border-neutral-200/90 bg-white hover:border-fuchsia-200 hover:bg-fuchsia-50/50',
        barOpen: 'border-fuchsia-200 bg-fuchsia-50/80 hover:bg-fuchsia-50',
        count: 'bg-fuchsia-100 text-fuchsia-700',
        chevron: 'text-fuchsia-500',
    },
};

export function categoryTone(category) {
    return CATEGORY_TONES[category] || CATEGORY_TONES.other;
}
