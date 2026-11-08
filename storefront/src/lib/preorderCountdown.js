function pad(value) {
    return String(value).padStart(2, '0');
}

export function preorderShopNowMs(serverNow, fetchedAtMs = Date.now()) {
    const serverMs = Date.parse(serverNow || '');
    if (!Number.isFinite(serverMs)) {
        return Date.now();
    }

    return Date.now() + (serverMs - fetchedAtMs);
}

export function preorderRemainingMs(countdownAt, shopNowMs) {
    const target = Date.parse(countdownAt || '');
    if (!Number.isFinite(target)) {
        return 0;
    }

    return Math.max(0, target - shopNowMs);
}

export function preorderRemainingParts(ms) {
    const total = Math.max(0, Math.floor(ms / 1000));

    return {
        days: Math.floor(total / 86400),
        hours: Math.floor((total % 86400) / 3600),
        minutes: Math.floor((total % 3600) / 60),
        seconds: total % 60,
    };
}

export function formatPreorderRemaining(ms) {
    const { days, hours, minutes, seconds } = preorderRemainingParts(ms);
    const clock = `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;

    return days > 0 ? `${days}d ${clock}` : clock;
}

export function shouldShowPreorderCountdown(preorder, placement) {
    if (!preorder?.enabled || !preorder?.active || !preorder.countdown_at) {
        return false;
    }
    if (placement === 'pdp') {
        return !!preorder.show_countdown_pdp;
    }
    if (placement === 'listing') {
        return !!preorder.show_countdown_listing;
    }

    return false;
}

export function showPreorderImageLabel(product) {
    return Boolean(product?.preorder?.enabled && product?.preorder?.active && product?.preorder?.show_image_label);
}
