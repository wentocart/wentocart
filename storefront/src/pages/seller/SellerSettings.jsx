import { Link, useOutletContext } from 'react-router-dom';
import { t } from '../../i18n.js';

const links = [
    { to: '/account/seller/profile', title: 'Seller profile & shop', body: 'Identity, contact, shop details, policies, and media.' },
    { to: '/account/seller/shipping', title: 'Shipping methods', body: 'Rates customers can choose for your items.' },
    { to: '/account/seller/payments', title: 'Payout methods', body: 'Bank or PayPal details for withdrawals.' },
    { to: '/account/seller/discounts', title: 'Discounts', body: 'Seller coupons and catalog promotions.' },
    { to: '/account/seller/products', title: 'Catalog', body: 'Products, categories, brands, suppliers, features, and attributes.' },
];

export default function SellerSettings() {
    const { seller } = useOutletContext() || {};

    return (
        <div>
            <h2 className="mv-page-title">{t('storefront.seller.settings', 'Settings')}</h2>
            <p className="mv-page-help">
                {t('storefront.seller.settings_help', 'Configure your shop. These pages stay in your customer account — sellers never use the admin panel.')}
            </p>
            {seller?.vacation_enabled ? (
                <p className="shop-card mt-4 p-4 text-sm text-amber-800">
                    {t('storefront.seller.vacation_on', 'Vacation mode is on.')}
                    {seller.vacation_message ? ` ${seller.vacation_message}` : ''}
                </p>
            ) : null}
            <div className="mt-6 grid gap-3 sm:grid-cols-2">
                {links.map((item) => (
                    <Link key={item.to} to={item.to} className="shop-card block p-5 hover:bg-neutral-50">
                        <p className="font-semibold">{t(`storefront.seller.settings.${item.to.split('/').pop()}`, item.title)}</p>
                        <p className="mt-1 mv-muted-sm">{t(`storefront.seller.settings_body.${item.to.split('/').pop()}`, item.body)}</p>
                    </Link>
                ))}
            </div>
        </div>
    );
}
