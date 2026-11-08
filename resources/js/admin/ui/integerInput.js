/**
 * Normalize integer field values for controlled inputs.
 *
 * React can leave leading zeros visible in type="number" fields when the DOM
 * value is "050" and the React value is number 50 (Number("050") === 50), so
 * reconciliation skips updating the DOM. Canonical string values avoid that.
 */

/** @param {unknown} raw */
export function normalizeIntegerInput(raw) {
    if (raw === '' || raw == null) {
        return '';
    }

    const cleaned = String(raw).trim();
    if (cleaned === '') {
        return '';
    }

    // Allow a lone minus while typing negative values (not used by Product Settings).
    if (cleaned === '-') {
        return '';
    }

    if (!/^-?\d+$/.test(cleaned)) {
        return '';
    }

    const n = Number.parseInt(cleaned, 10);
    if (Number.isNaN(n)) {
        return '';
    }

    return n;
}

/** @param {unknown} value */
export function integerInputValue(value) {
    if (value === '' || value == null || value === false) {
        return '';
    }

    if (typeof value === 'number' && Number.isNaN(value)) {
        return '';
    }

    const n = typeof value === 'number' ? value : Number.parseInt(String(value), 10);
    if (Number.isNaN(n)) {
        return '';
    }

    return String(n);
}
