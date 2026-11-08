const SEGMENT = /^[\p{L}\p{N}]+(?:[-_][\p{L}\p{N}]+)*$/u;
const MAX = 80;

export function tidyVirtualUri(value) {
    const text = String(value ?? '').replace(/\\/g, '/').trim().replace(/^\/+|\/+$/g, '');
    try {
        return text.normalize('NFC');
    } catch {
        return text;
    }
}

/**
 * Mirrors ShopUrl::virtualUriMessage. Empty means the value is allowed.
 */
export function virtualUriMessage(value, reserved = []) {
    const segment = tidyVirtualUri(value);
    if (segment === '') {
        return '';
    }
    if ([...segment].length > MAX) {
        return `Use at most ${MAX} characters.`;
    }
    if (!SEGMENT.test(segment)) {
        return 'Use letters and numbers, with a hyphen or underscore between words. Capitals are fine. Example: Shop-2, tienda, or 商店.';
    }
    const key = segment.toLowerCase();
    if (reserved.some((name) => String(name).toLowerCase() === key)) {
        return 'That path is already used by the shop (admin, cart, api, …).';
    }

    return '';
}
