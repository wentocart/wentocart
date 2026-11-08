import { adminUrl } from '../webPath';

export const DEFAULT_CATALOG_IMAGES = {
    sm: '/images/default/p-sm-default.png',
    md: '/images/default/p-md-default.png',
    lg: '/images/default/p-lg-default.png',
};

export function catalogImageUrl(path, size = 'md') {
    if (path) {
        const raw = String(path);
        if (raw.startsWith('blob:')) {
            return raw;
        }
        if (/^https?:\/\//i.test(raw) || raw.startsWith('/')) {
            return adminUrl(raw);
        }

        return adminUrl(`/storage/${raw}`);
    }

    return adminUrl(DEFAULT_CATALOG_IMAGES[size] || DEFAULT_CATALOG_IMAGES.md);
}

/** Same file through Laravel when the public /storage link is missing. */
export function catalogMediaFallback(src) {
    const value = String(src || '');
    if (!value.includes('/storage/')) {
        return '';
    }

    return value.replace('/storage/', '/media/');
}

export function catalogThumbError(event, size = 'sm') {
    const img = event.currentTarget;
    const media = catalogMediaFallback(img.src);
    if (media && !img.dataset.triedMedia) {
        img.dataset.triedMedia = '1';
        img.src = media;
        return;
    }
    img.onerror = null;
    img.src = catalogImageUrl(null, size);
}
