import { useEffect } from 'react';
import { assetUrl } from '../lib/paymentUiRegistry.js';

function loadStylesheet(href) {
    if (document.querySelector(`link[data-wento-payment-notice="${href}"]`)) {
        return;
    }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.wentoPaymentNotice = href;
    document.head.appendChild(link);
}

export default function PaymentNotice({ block }) {
    useEffect(() => {
        (block.assets?.css || []).forEach((file) => {
            loadStylesheet(assetUrl(block.vendor, block.name, file));
        });
    }, [block]);

    if (!block?.text) {
        return null;
    }

    const variant = block.variant === 'warning' ? 'warning' : 'info';

    return (
        <div
            className={`payment-notice payment-notice--${variant}`}
            role="note"
            data-extension={block.extension || ''}
        >
            <p>{block.text}</p>
        </div>
    );
}
