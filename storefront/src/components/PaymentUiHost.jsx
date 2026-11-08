import { useEffect, useRef } from 'react';
import {
    assetUrl,
    registerPaymentUi,
} from '../lib/paymentUiRegistry.js';

function loadStylesheet(href) {
    if (document.querySelector(`link[data-wento-payment-ui="${href}"]`)) {
        return;
    }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.wentoPaymentUi = href;
    document.head.appendChild(link);
}

function loadScript(src) {
    const existing = document.querySelector(`script[data-wento-payment-ui="${src}"]`);
    if (existing) {
        return existing.dataset.loaded === 'true'
            ? Promise.resolve()
            : new Promise((resolve, reject) => {
                existing.addEventListener('load', () => resolve(), { once: true });
                existing.addEventListener('error', () => reject(new Error('Could not load payment UI.')), { once: true });
            });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.defer = true;
        script.dataset.wentoPaymentUi = src;
        script.onload = () => {
            script.dataset.loaded = 'true';
            resolve();
        };
        script.onerror = () => reject(new Error('Could not load payment UI.'));
        document.body.appendChild(script);
    });
}

export default function PaymentUiHost({
    block,
    enabled,
    checkoutContext,
    paymentMethod,
    onReady,
    onError,
}) {
    const mountRef = useRef(null);
    const mountedRef = useRef(false);
    const blockRef = useRef(block);
    const paymentMethodRef = useRef(paymentMethod);

    blockRef.current = block;
    paymentMethodRef.current = paymentMethod;

    useEffect(() => {
        if (!enabled || !block || block.type !== 'payment_ui') {
            return undefined;
        }

        let cancelled = false;

        async function boot() {
            try {
                (block.assets?.css || []).forEach((file) => {
                    loadStylesheet(assetUrl(block.vendor, block.name, file));
                });
                for (const file of (block.assets?.js || [])) {
                    await loadScript(assetUrl(block.vendor, block.name, file));
                }
                if (cancelled || !mountRef.current) {
                    return;
                }
                document.dispatchEvent(new CustomEvent('wento:payment-ui:mount', {
                    detail: {
                        extension: block.extension,
                        mount: block.mount,
                        mountElement: mountRef.current,
                        config: block.config || {},
                        checkoutContext,
                        paymentMethod: {
                            id: paymentMethod?.id,
                            code: paymentMethod?.code,
                            driver: paymentMethod?.driver,
                            name: paymentMethod?.name,
                        },
                        register: registerPaymentUi,
                    },
                }));
                mountedRef.current = true;
                document.dispatchEvent(new CustomEvent('wento:payment-ui:context', {
                    detail: {
                        extension: block.extension,
                        mount: block.mount,
                        checkoutContext,
                        paymentMethod: {
                            id: paymentMethod?.id,
                            code: paymentMethod?.code,
                            driver: paymentMethod?.driver,
                            name: paymentMethod?.name,
                        },
                    },
                }));
                onReady?.(block.extension);
            } catch (err) {
                if (!cancelled) {
                    onError?.(err?.message || 'Payment UI could not be loaded.');
                }
            }
        }

        boot();

        return () => {
            cancelled = true;
            mountedRef.current = false;
            document.dispatchEvent(new CustomEvent('wento:payment-ui:unmount', {
                detail: { extension: block.extension, mount: block.mount },
            }));
        };
    }, [enabled, block?.extension, block?.mount, block?.vendor, block?.name]);

    useEffect(() => {
        if (!enabled || !block || block.type !== 'payment_ui' || !mountedRef.current) {
            return;
        }

        document.dispatchEvent(new CustomEvent('wento:payment-ui:context', {
            detail: {
                extension: block.extension,
                mount: block.mount,
                checkoutContext,
                paymentMethod: {
                    id: paymentMethodRef.current?.id,
                    code: paymentMethodRef.current?.code,
                    driver: paymentMethodRef.current?.driver,
                    name: paymentMethodRef.current?.name,
                },
            },
        }));
    }, [enabled, block?.extension, block?.mount, checkoutContext]);

    if (!enabled || !block || block.type !== 'payment_ui') {
        return null;
    }

    return (
        <div
            id={block.mount}
            ref={mountRef}
            className="payment-ui-host"
            data-extension={block.extension}
        />
    );
}
