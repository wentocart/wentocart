/**
 * Storefront asset helpers — only ship merchant-owned media under
 * /themes/classic/assets/merchant. Do not remap to third-party demo packs.
 */
import { webPath } from './webPath.js';

const MERCHANT_ROOT = '/themes/classic/assets/merchant';

function merchantUrl(relative) {
    return webPath(`${MERCHANT_ROOT}/${String(relative || '').replace(/^\/+/, '')}`);
}

/**
 * Pass through merchant / storage URLs. Strip legacy demo / catalog / module paths
 * so we never serve third-party stock that was removed from the repo.
 */
export function classicAssetUrl(url) {
    const value = String(url || '').trim();
    if (!value) {
        return value;
    }
    if (
        value.includes('/themes/classic/assets/demo/')
        || value.includes('/themes/classic/assets/catalog/')
        || value.includes('/themes/classic/assets/modules/')
        || value.includes('/themes/classic/assets/img/')
        || value.includes('/storage/classic-banners/')
    ) {
        return '';
    }
    return webPath(value);
}

/** Keep product covers from storage / merchant — never invent Unsplash covers. */
export function classicDemoProduct(product) {
    if (!product) {
        return product;
    }
    const cover = classicAssetUrl(product?.cover_url || product?.cover || '');
    const hover = classicAssetUrl(product?.hover_url || '');
    return {
        ...product,
        cover_url: cover || product.cover_url || '',
        hover_url: hover || cover || product.hover_url || '',
    };
}

export function classicDemoCategoryImage(category) {
    const existing = classicAssetUrl(category?.image || category?.image_url || category?.thumb || '');
    return existing || '';
}

export function classicDemoShelfCategory(category) {
    return {
        ...category,
        image: classicDemoCategoryImage(category),
    };
}

export function classicDemoShelfProduct(product) {
    return classicDemoProduct(product);
}

export function classicDemoBrands(brands = []) {
    return (Array.isArray(brands) ? brands : []).map((brand) => ({
        ...brand,
        logo: classicAssetUrl(brand?.logo || brand?.logo_url || ''),
        banner: classicAssetUrl(brand?.banner || brand?.banner_url || ''),
        tagline: brand?.tagline || brand?.short_description || '',
    }));
}

export function classicDemoContent(html = '') {
    return String(html || '');
}

export function classicDemoTestimonialAvatar(url) {
    const existing = classicAssetUrl(url || '');
    return existing || merchantUrl('ui/avatar-default.svg');
}

export function classicDemoPaymentStrip() {
    return merchantUrl('ui/payment-strip.svg');
}

export function classicDemoLogo() {
    return merchantUrl('ui/logo.png');
}

export function classicDemoMenuPromo() {
    return merchantUrl('banners/15.png');
}

export const CLASSIC_PDP_ROOT = `${MERCHANT_ROOT}/products`;

export const CLASSIC_PDP_COLORS = [
    { name: 'Black', hex: '#434A54' },
    { name: 'Silver', hex: '#C0C4CC' },
    { name: 'Blue', hex: '#3B82F6' },
];

export const CLASSIC_PDP_DIMENSIONS = [];

export function classicPdpGallery() {
    return [];
}

export function classicPdpCmsBanner() {
    return '';
}

export function classicPdpUi(name) {
    const map = {
        payment: merchantUrl('ui/payment-strip.svg'),
        paymentFooter: merchantUrl('ui/payment-strip.svg'),
        security: merchantUrl('ui/reassurance-security.svg'),
        delivery: merchantUrl('ui/reassurance-delivery.svg'),
        returns: merchantUrl('ui/reassurance-return.svg'),
        brand: merchantUrl('ui/logo.png'),
        descriptionHtml: null,
    };
    return map[name] || null;
}

export function classicPdpRelatedProduct(product) {
    return classicDemoProduct(product);
}

export const CLASSIC_PDP_HERO_SKU = '';
export const CLASSIC_PDP_HERO_SKUS = [];

export function classicPdpEnhance(product) {
    return classicDemoProduct(product);
}
