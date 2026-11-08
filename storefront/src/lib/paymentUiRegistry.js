import { webPath } from '../webPath';

const handlers = new Map();

export function registerPaymentUi(extensionId, handler) {
    if (!extensionId || typeof handler !== 'object' || handler === null) {
        return;
    }
    handlers.set(extensionId, handler);
}

export function getPaymentUi(extensionId) {
    return handlers.get(extensionId) || null;
}

export function collectPaymentContext(extensionId) {
    const handler = getPaymentUi(extensionId);
    if (!handler?.collectContext) {
        return {};
    }
    const context = handler.collectContext();
    return context && typeof context === 'object' ? context : {};
}

export async function confirmClientAction(extensionId, order, paymentMeta) {
    const handler = getPaymentUi(extensionId);
    if (!handler?.confirmAfterOrder) {
        throw new Error('Payment UI is not ready yet.');
    }
    return handler.confirmAfterOrder(order, paymentMeta);
}

export async function confirmBeforeCheckout(extensionId, checkoutContext) {
    const handler = getPaymentUi(extensionId);
    if (!handler?.confirmBeforeCheckout) {
        return null;
    }
    const proof = await handler.confirmBeforeCheckout(checkoutContext);
    return proof && typeof proof === 'object' ? proof : null;
}

export function supportsPaymentFirst(extensionId) {
    const handler = getPaymentUi(extensionId);
    return typeof handler?.confirmBeforeCheckout === 'function';
}

export function matchesPaymentUiBlock(block, paymentMethod) {
    return matchesPaymentSlotBlock(block, paymentMethod, 'payment_ui');
}

export function matchesPaymentNoticeBlock(block, paymentMethod) {
    return matchesPaymentSlotBlock(block, paymentMethod, 'payment_notice');
}

export function matchesPaymentSlotBlock(block, paymentMethod, type) {
    if (!block || block.type !== type || !paymentMethod) {
        return false;
    }
    const when = block.when || {};
    if (when.payment_method_id && String(when.payment_method_id) !== String(paymentMethod.id)) {
        return false;
    }
    if (when.payment_method_code) {
        const code = String(paymentMethod.code || paymentMethod.driver || '').toLowerCase();
        if (code !== String(when.payment_method_code).toLowerCase()) {
            return false;
        }
    }

    return true;
}

export function assetUrl(vendor, name, file) {
    return webPath(`/plugins/${vendor}/${name}/${file}`);
}

if (typeof window !== 'undefined') {
    window.__WENTO_PAYMENT_UI__ = {
        register: registerPaymentUi,
        get: getPaymentUi,
    };
}
