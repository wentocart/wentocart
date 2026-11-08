export function inRange(value, min, max) {
    const n = Number(value);
    if (Number.isNaN(n)) {
        return false;
    }
    if (min !== '' && min != null && n < Number(min)) {
        return false;
    }
    if (max !== '' && max != null && n > Number(max)) {
        return false;
    }
    return true;
}

export function nodeMatches(node, filters) {
    const nameQ = String(filters.name || '').trim().toLowerCase();
    const slugQ = String(filters.slug || '').trim().toLowerCase();
    if (nameQ && !String(node.name || '').toLowerCase().includes(nameQ)) {
        return false;
    }
    if (slugQ && !String(node.slug || node.seller_slug || '').toLowerCase().includes(slugQ)) {
        return false;
    }
    if (!inRange(node.id, filters.id_min, filters.id_max)) {
        return false;
    }
    if (!inRange(node.products_count ?? 0, filters.products_min, filters.products_max)) {
        return false;
    }
    if (!inRange(node.position ?? 0, filters.position_min, filters.position_max)) {
        return false;
    }
    if (filters.status === 'active' && !node.is_enabled) {
        return false;
    }
    if (filters.status === 'inactive' && node.is_enabled) {
        return false;
    }
    return true;
}

export function filterTree(nodes, filters) {
    return (nodes || []).reduce((acc, node) => {
        const children = filterTree(node.children || [], filters);
        const matches = nodeMatches(node, filters);
        if (matches || children.length > 0) {
            acc.push({ ...node, children });
        }
        return acc;
    }, []);
}

export function sortNodes(nodes, sort, dir) {
    const factor = dir === 'desc' ? -1 : 1;
    const compare = (a, b) => {
        let av;
        let bv;
        switch (sort) {
            case 'id':
                av = Number(a.id) || 0;
                bv = Number(b.id) || 0;
                break;
            case 'name':
                av = String(a.name || '').toLowerCase();
                bv = String(b.name || '').toLowerCase();
                break;
            case 'slug':
                av = String(a.slug || a.seller_slug || '').toLowerCase();
                bv = String(b.slug || b.seller_slug || '').toLowerCase();
                break;
            case 'products_count':
                av = Number(a.products_count) || 0;
                bv = Number(b.products_count) || 0;
                break;
            case 'is_enabled':
                av = a.is_enabled ? 1 : 0;
                bv = b.is_enabled ? 1 : 0;
                break;
            case 'position':
            default:
                av = Number(a.position) || 0;
                bv = Number(b.position) || 0;
                break;
        }
        if (av < bv) return -1 * factor;
        if (av > bv) return 1 * factor;
        return (Number(a.id) || 0) - (Number(b.id) || 0);
    };

    return [...(nodes || [])]
        .map((node) => ({
            ...node,
            children: sortNodes(node.children || [], sort, dir),
        }))
        .sort(compare);
}

export function collectVisibleNodes(nodes, openIds, out = []) {
    (nodes || []).forEach((node) => {
        out.push(node);
        if ((node.children || []).length > 0 && openIds.has(node.id)) {
            collectVisibleNodes(node.children, openIds, out);
        }
    });
    return out;
}

export function collectExpandableIds(nodes, acc = []) {
    (nodes || []).forEach((node) => {
        if ((node.children || []).length > 0) {
            acc.push(node.id);
            collectExpandableIds(node.children, acc);
        }
    });
    return acc;
}

export function collectOpenIdsForSearch(nodes, filters, ancestors = [], acc = new Set()) {
    const hasText = String(filters.name || '').trim() || String(filters.slug || '').trim();
    if (!hasText) {
        return acc;
    }
    (nodes || []).forEach((node) => {
        const childHits = new Set();
        collectOpenIdsForSearch(node.children || [], filters, [...ancestors, node.id], childHits);
        childHits.forEach((id) => acc.add(id));
        const selfMatch = nodeMatches(node, {
            ...filters,
            status: 'all',
            id_min: '',
            id_max: '',
            products_min: '',
            products_max: '',
            position_min: '',
            position_max: '',
        });
        if (selfMatch || childHits.size > 0) {
            ancestors.forEach((id) => acc.add(id));
            if ((node.children || []).length > 0) {
                acc.add(node.id);
            }
        }
    });
    return acc;
}
