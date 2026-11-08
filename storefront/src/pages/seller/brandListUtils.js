import { inRange, nodeMatches } from './categoryTree.js';

export function filterRows(rows, filters) {
    return (rows || []).filter((row) => nodeMatches(row, filters));
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
            case 'name':
                av = String(a.name || '').toLowerCase();
                bv = String(b.name || '').toLowerCase();
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

    return [...(rows || [])].sort(compare);
}
