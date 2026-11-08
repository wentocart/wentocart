import { discountPercent, formatMoney, productPrice } from '../lib/format.js';
import { compareAtEnabled, guestPricePrompt, pricesHidden, taxDisplay } from '../lib/commerce.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';

export default function Price({ product, size = 'md' }) {
    const store = useStore();
    if (product?.show_price === false || pricesHidden(store.shop, store.customer)) {
        if (guestPricePrompt(store.shop, store.customer)) {
            return <p className="text-sm text-[var(--shop-muted)]">{t('storefront.price.sign_in', 'Sign in to see the price')}</p>;
        }
        return null;
    }
    const off = discountPercent(product);
    const current = productPrice(product);
    const was = compareAtEnabled(store.shop)
        ? (product?.compare_price_formatted || (product?.compare_price ? formatMoney(product.compare_price) : null))
        : null;
    const currentClass = size === 'xl' ? 'text-3xl font-bold' : size === 'lg' ? 'text-xl font-bold' : 'text-base font-bold';
    const display = taxDisplay(store.shop);
    const excl = product?.price_excl_formatted || (product?.price_excl != null ? formatMoney(product.price_excl) : null);
    const incl = product?.price_incl_formatted || (product?.price_incl != null ? formatMoney(product.price_incl) : null);
    const taxHint = display === 'without_tax'
        ? t('storefront.price.excl_tax', 'excl. tax')
        : display === 'with_tax'
            ? t('storefront.price.incl_tax', 'incl. tax')
            : null;

    return (
        <div className="flex flex-wrap items-end gap-2">
            <span className={`${currentClass} text-[var(--shop-accent)]`}>{current}</span>
            {was && off ? <span className="text-sm text-[var(--shop-muted)] line-through">{was}</span> : null}
            {off ? <span className="text-sm font-semibold text-[var(--shop-sale,#ff4d4d)]">{off}% OFF</span> : null}
            {display === 'both' && excl && incl && excl !== incl ? (
                <span className="text-xs text-[var(--shop-muted)]">
                    {excl} {t('storefront.price.excl_tax', 'excl. tax')}
                    {' · '}
                    {incl} {t('storefront.price.incl_tax', 'incl. tax')}
                </span>
            ) : null}
            {display !== 'both' && current && taxHint ? <span className="text-xs text-[var(--shop-muted)]">{taxHint}</span> : null}
        </div>
    );
}
