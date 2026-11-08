import { inRange, nodeMatches } from './categoryTree.js';

export function attributeValueMatches(row, filters) {
    if (!inRange(row.id, filters.id_min, filters.id_max)) {
        return false;
    }
    if (!inRange(row.position ?? 0, filters.position_min, filters.position_max)) {
        return false;
    }
    if (filters.status === 'active' && !row.is_enabled) {
        return false;
    }
    if (filters.status === 'inactive' && row.is_enabled) {
        return false;
    }
    const valueQ = String(filters.value || '').trim().toLowerCase();
    if (valueQ && !String(row.value || '').toLowerCase().includes(valueQ)) {
        return false;
    }
    const attributeQ = String(filters.attribute || '').trim().toLowerCase();
    if (attributeQ && !String(row.attribute_name || '').toLowerCase().includes(attributeQ)) {
        return false;
    }
    return true;
}

export function filterRows(rows, filters) {
    return (rows || []).filter((row) => attributeValueMatches(row, filters));
}

export function sortRows(rows, sort, dir) {
    const factor = dir === 'desc' ? -1 : 1;
    const compare = (a, b) => {
        let av;
        let bv;
        switch (sort) {
            case 'id':
                av = Number(a.id) || 0;
                bv = Number(b.id) || 0;
                break;
            case 'value':
                av = String(a.value || '').toLowerCase();
                bv = String(b.value || '').toLowerCase();
                break;
            case 'attribute':
                av = String(a.attribute_name || '').toLowerCase();
                bv = String(b.attribute_name || '').toLowerCase();
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

    return [...(rows || [])].sort(compare);
}
