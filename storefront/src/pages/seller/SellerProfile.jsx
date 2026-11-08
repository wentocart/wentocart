import { useEffect } from 'react';
import { useLocation, useOutletContext } from 'react-router-dom';
import { t } from '../../i18n.js';
import SellerProfileForm from './SellerProfileForm.jsx';
import SellerShopForm from './SellerShopForm.jsx';

export default function SellerProfile() {
    const { seller, setSeller } = useOutletContext();
    const { hash } = useLocation();

    useEffect(() => {
        if (!hash) {
            return undefined;
        }
        const target = document.querySelector(hash);
        target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        return undefined;
    }, [hash]);

    return (
        <div>
            <h2 className="mv-page-title">{t('storefront.seller.profile', 'Profile')}</h2>
            <p className="mv-page-help">
                {t('storefront.seller.profile_page_help', 'Manage your seller identity and storefront settings. Each section saves independently.')}
            </p>

            <div className="mv-profile-forms">
                <section id="seller-profile-form" className="mv-profile-form-block">
                    <h3 className="mv-profile-form-title">{t('storefront.seller.seller_profile', 'Seller profile')}</h3>
                    <p className="mv-profile-form-help">{t('storefront.seller.seller_profile_help', 'Identity, contact, and tax details.')}</p>
                    <SellerProfileForm seller={seller} setSeller={setSeller} />
                </section>

                <section id="seller-shop-form" className="mv-profile-form-block">
                    <h3 className="mv-profile-form-title">{t('storefront.seller.shop', 'Seller shop')}</h3>
                    <p className="mv-profile-form-help">{t('storefront.seller.seller_shop_help', 'Shop name, address, policies, media, and visibility.')}</p>
                    <SellerShopForm seller={seller} setSeller={setSeller} />
                </section>
            </div>
        </div>
    );
}
