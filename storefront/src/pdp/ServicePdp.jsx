import SimplePdp from './SimplePdp.jsx';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';
import { pricesHidden } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';

function formatDuration(service) {
    if (!service?.duration) {
        return null;
    }
    const unit = service.duration_unit || 'hours';
    const value = service.duration;
    const labels = {
        minutes: t('storefront.pdp.service_minutes', 'minutes'),
        hours: t('storefront.pdp.service_hours', 'hours'),
        days: t('storefront.pdp.service_days', 'days'),
    };
    return `${value} ${labels[unit] || unit}`;
}

function deliveryLabel(method) {
    const map = {
        online: t('storefront.pdp.service_online', 'Online'),
        onsite: t('storefront.pdp.service_onsite', 'On-site'),
        both: t('storefront.pdp.service_both', 'Online or on-site'),
    };
    return map[method] || method;
}

function pricingLabel(model) {
    const map = {
        fixed: t('storefront.pdp.service_price_fixed', 'Fixed price'),
        per_hour: t('storefront.pdp.service_price_per_hour', 'Per hour'),
        per_day: t('storefront.pdp.service_price_per_day', 'Per day'),
        starting_from: t('storefront.pdp.service_price_from', 'Starting from'),
    };
    return map[model] || model;
}

function ServiceFacts({ product }) {
    const store = useStore();
    const hidePrice = pricesHidden(store.shop, store.customer) || product?.show_price === false;
    const service = product?.service || {};
    const duration = formatDuration(service);
    const travel = Number(service.travel_fee || 0);

    return (
        <div className="shop-pdp2-service-info">
            <p className="shop-pdp2-service-note">
                {t('storefront.pdp.service_note', 'This is a service. No physical shipping is charged for this item.')}
            </p>
            <div className="shop-service-facts rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700 space-y-2">
                {service.service_type ? (
                    <p>
                        <strong>{t('storefront.pdp.service_type', 'Service type')}: </strong>
                        {String(service.service_type).replace(/_/g, ' ')}
                    </p>
                ) : null}
                {duration ? (
                    <p>
                        <strong>{t('storefront.pdp.service_duration', 'Duration')}: </strong>
                        {duration}
                    </p>
                ) : null}
                {service.pricing_model ? (
                    <p>
                        <strong>{t('storefront.pdp.service_pricing', 'Pricing')}: </strong>
                        {pricingLabel(service.pricing_model)}
                        {!hidePrice && service.pricing_model === 'starting_from' && product.price != null
                            ? ` · ${t('storefront.pdp.from_price', 'From')} ${formatMoney(product.price)}`
                            : null}
                    </p>
                ) : null}
                {service.delivery_method ? (
                    <p>
                        <strong>{t('storefront.pdp.service_delivery', 'Delivery')}: </strong>
                        {deliveryLabel(service.delivery_method)}
                    </p>
                ) : null}
                {!hidePrice && travel > 0 ? (
                    <p>
                        <strong>{t('storefront.pdp.travel_fee', 'Travel fee')}: </strong>
                        {formatMoney(travel)}
                    </p>
                ) : null}
                {service.service_area ? (
                    <p>
                        <strong>{t('storefront.pdp.service_area', 'Service area')}: </strong>
                        {service.service_area}
                    </p>
                ) : null}
                {service.location_info ? (
                    <p>
                        <strong>{t('storefront.pdp.location_info', 'Location')}: </strong>
                        {service.location_info}
                    </p>
                ) : null}
                {service.meeting_instructions ? (
                    <p>
                        <strong>{t('storefront.pdp.meeting_instructions', 'Meeting')}: </strong>
                        {service.meeting_instructions}
                    </p>
                ) : null}
                {service.online_service_url ? (
                    <p>
                        <strong>{t('storefront.pdp.online_url', 'Online link')}: </strong>
                        <a href={service.online_service_url} target="_blank" rel="noreferrer" className="underline">
                            {service.online_service_url}
                        </a>
                    </p>
                ) : null}
                {service.delivery_instructions ? (
                    <p>
                        <strong>{t('storefront.pdp.delivery_instructions', 'Instructions')}: </strong>
                        {service.delivery_instructions}
                    </p>
                ) : null}
                {service.requires_booking ? (
                    <p className="text-neutral-600">
                        {t('storefront.pdp.service_booking_hint', 'Choose an available booking date before adding to cart.')}
                    </p>
                ) : null}
            </div>
        </div>
    );
}

export default function ServicePdp(props) {
    const product = props.product || {};

    return (
        <SimplePdp
            {...props}
            belowPrice={<ServiceFacts product={product} />}
        />
    );
}
