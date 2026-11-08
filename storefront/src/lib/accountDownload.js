/**
 * Download a paid order file and return server entitlement headers.
 * Count changes only after a successful (2xx) file response.
 */

const HEADER_REMAINING = 'x-wento-download-remaining';
const HEADER_USES = 'x-wento-download-uses';
const HEADER_MAX_USES = 'x-wento-download-max-uses';
const HEADER_AVAILABLE = 'x-wento-download-available';

function headerValue(headers, name) {
    if (!headers) {
        return null;
    }
    if (typeof headers.get === 'function') {
        return headers.get(name) ?? headers.get(name.toLowerCase());
    }
    return headers[name] ?? headers[name.toLowerCase()] ?? null;
}

export function entitlementFromDownloadHeaders(headers) {
    const remainingRaw = headerValue(headers, HEADER_REMAINING);
    const usesRaw = headerValue(headers, HEADER_USES);
    const maxUsesRaw = headerValue(headers, HEADER_MAX_USES);
    const availableRaw = headerValue(headers, HEADER_AVAILABLE);

    if (remainingRaw == null || usesRaw == null || maxUsesRaw == null || availableRaw == null) {
        return null;
    }

    const remaining = Number(remainingRaw);
    const uses = Number(usesRaw);
    const max_uses = Number(maxUsesRaw);
    if (![remaining, uses, max_uses].every((n) => Number.isFinite(n))) {
        return null;
    }

    return {
        remaining,
        uses,
        max_uses,
        available: String(availableRaw) === '1' || availableRaw === 1 || availableRaw === true,
    };
}

function filenameFromDisposition(disposition, fallback) {
    if (!disposition) {
        return fallback;
    }
    const utf = /filename\*=UTF-8''([^;]+)/i.exec(disposition);
    if (utf?.[1]) {
        try {
            return decodeURIComponent(utf[1].trim().replace(/^"|"$/g, ''));
        } catch {
            // fall through
        }
    }
    const plain = /filename="?([^"]+)"?/i.exec(disposition);
    if (plain?.[1]) {
        return plain[1].trim();
    }
    return fallback;
}

function saveBlob(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename || 'download';
    link.rel = 'noopener';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}

/**
 * @param {import('axios').AxiosInstance} api
 * @param {{ token: string, filename?: string, original_filename?: string }} row
 * @returns {Promise<{ remaining: number, uses: number, max_uses: number, available: boolean }|null>}
 */
export async function downloadAccountFile(api, row) {
    const token = String(row?.token || '').trim();
    if (!token) {
        throw new Error('Missing download token');
    }

    const res = await api.get(`/account/downloads/${encodeURIComponent(token)}`, {
        responseType: 'blob',
        // Avoid treating error JSON as a successful file blob.
        validateStatus: (status) => status >= 200 && status < 300,
    });

    const fallbackName = String(row.original_filename || row.filename || 'download').trim() || 'download';
    const filename = filenameFromDisposition(res.headers?.['content-disposition'], fallbackName);
    saveBlob(res.data, filename);

    return entitlementFromDownloadHeaders(res.headers);
}

export function applyDownloadEntitlement(row, entitlement) {
    if (!row || !entitlement) {
        return row;
    }

    return {
        ...row,
        remaining: entitlement.remaining,
        uses: entitlement.uses,
        max_uses: entitlement.max_uses,
        available: Boolean(entitlement.available),
    };
}
