import { useEffect, useMemo, useState } from 'react';
import SimplePdp from './SimplePdp.jsx';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';
import { pricesHidden } from '../lib/commerce.js';
import { useStore } from '../state/StoreState.jsx';

function unitLabel(unit) {
    const map = {
        hour: t('storefront.pdp.rental_unit_hour', 'hour(s)'),
        day: t('storefront.pdp.rental_unit_day', 'day(s)'),
        week: t('storefront.pdp.rental_unit_week', 'week(s)'),
        month: t('storefront.pdp.rental_unit_month', 'month(s)'),
    };
    return map[unit] || unit;
}

function pricingLabel(model) {
    const map = {
        per_hour: t('storefront.pdp.rental_price_per_hour', 'Per hour'),
        per_day: t('storefront.pdp.rental_price_per_day', 'Per day'),
        per_week: t('storefront.pdp.rental_price_per_week', 'Per week'),
        per_month: t('storefront.pdp.rental_price_per_month', 'Per month'),
    };
    return map[model] || model;
}

function addDays(iso, days) {
    const d = new Date(`${iso}T12:00:00`);
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
}

function daysBetween(start, end) {
    const a = new Date(`${start}T12:00:00`);
    const b = new Date(`${end}T12:00:00`);
    return Math.max(1, Math.round((b - a) / 86400000) + 1);
}

export default function RentalPdp(props) {
    const store = useStore();
    const product = props.product || {};
    const hidePrice = pricesHidden(store.shop, store.customer) || product?.show_price === false;
    const rental = product.rental || {};
    const unit = rental.rental_unit || 'day';
    const min = Math.max(1, Number(rental.min_duration) || 1);
    const max = Math.max(min, Number(rental.max_duration) || 30);
    const defaultDuration = Math.min(max, Math.max(min, Number(rental.default_duration) || min));
    const [start, setStart] = useState('');
    const [duration, setDuration] = useState(defaultDuration);
    const [end, setEnd] = useState('');

    useEffect(() => {
        setDuration(defaultDuration);
    }, [product.id, defaultDuration]);

    useEffect(() => {
        if (!start) {
            setEnd('');
            return;
        }
        // Day-based inclusive end date from duration.
        const span = unit === 'day' ? duration : duration;
        setEnd(addDays(start, Math.max(0, span - 1)));
    }, [start, duration, unit]);

    const preview = useMemo(() => {
        const tiers = rental.pricing_tiers || [];
        const tier = tiers.find((row) => duration >= Number(row.min_duration) && duration <= Number(row.max_duration));
        let unitPrice = Number(product.price) || 0;
        if (tier) {
            unitPrice = Number(tier.price) || 0;
        } else if (rental.preview?.unit_price != null && duration === Number(rental.preview.duration)) {
            unitPrice = Number(rental.preview.unit_price);
        } else {
            const model = rental.pricing_model || 'per_day';
            const base = Number(product.price) || 0;
            // Approximate FO preview; server recalculates authoritatively.
            const mult = model === 'per_week'
                ? (unit === 'week' ? duration : duration / 7)
                : model === 'per_month'
                    ? (unit === 'month' ? duration : duration / 30)
                    : model === 'per_hour'
                        ? (unit === 'hour' ? duration : duration * 24)
                        : duration;
            unitPrice = Math.round(base * Math.max(0.0001, mult) * 100) / 100;
        }
        let deposit = 0;
        if (rental.security_deposit_type === 'fixed') {
            deposit = Number(rental.security_deposit_value) || 0;
        } else if (rental.security_deposit_type === 'percent') {
            deposit = Math.round(unitPrice * ((Number(rental.security_deposit_value) || 0) / 100) * 100) / 100;
        }
        const cleaning = Number(rental.cleaning_fee) || 0;
        return { unitPrice, deposit, cleaning, due: Math.round((unitPrice + deposit + cleaning) * 100) / 100 };
    }, [product.price, rental, duration]);

    const rentalBooking = {
        start,
        end,
        duration,
        setStart,
        setDuration,
        setEnd,
        min,
        max,
        unit,
        preview,
    };

    return (
        <>
            <div className="shop-container py-2 space-y-3">
                <p className="text-sm text-neutral-600">
                    {t('storefront.pdp.rental_note', 'This is a rental. Choose your dates below. Shipping may apply for delivery and return.')}
                </p>
                <div className="shop-rental-facts rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700 space-y-2">
                    <p>
                        <strong>{t('storefront.pdp.rental_unit', 'Rental unit')}: </strong>
                        {unitLabel(unit)}
                    </p>
                    <p>
                        <strong>{t('storefront.pdp.rental_duration_range', 'Allowed duration')}: </strong>
                        {min}–{max} {unitLabel(unit)}
                    </p>
                    {rental.pricing_model ? (
                        <p>
                            <strong>{t('storefront.pdp.rental_pricing', 'Pricing')}: </strong>
                            {pricingLabel(rental.pricing_model)}
                        </p>
                    ) : null}
                    {(rental.pricing_tiers || []).length > 0 ? (
                        <div>
                            <strong>{t('storefront.pdp.rental_tiers', 'Duration tiers')}: </strong>
                            <ul className="list-disc ml-5 mt-1">
                                {rental.pricing_tiers.map((row) => (
                                    <li key={`${row.min_duration}-${row.max_duration}-${row.price}`}>
                                        {row.min_duration}–{row.max_duration} {unitLabel(row.unit || unit)}{hidePrice ? '' : `: ${formatMoney(row.price)}`}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    ) : null}
                    {!hidePrice && rental.security_deposit_type && rental.security_deposit_type !== 'none' ? (
                        <p>
                            <strong>{t('storefront.pdp.rental_deposit', 'Security deposit')}: </strong>
                            {rental.security_deposit_type === 'percent'
                                ? `${rental.security_deposit_value}%`
                                : formatMoney(rental.security_deposit_value)}
                        </p>
                    ) : null}
                    {!hidePrice && Number(rental.cleaning_fee) > 0 ? (
                        <p>
                            <strong>{t('storefront.pdp.rental_cleaning', 'Cleaning fee')}: </strong>
                            {formatMoney(rental.cleaning_fee)}
                        </p>
                    ) : null}
                    {rental.late_fee_type && rental.late_fee_type !== 'none' ? (
                        <p>
                            <strong>{t('storefront.pdp.rental_late_fee', 'Late fee')}: </strong>
                            {rental.late_fee_type} {!hidePrice && rental.late_fee_value != null ? `· ${rental.late_fee_value}` : ''}
                        </p>
                    ) : null}
                    {rental.fulfillment_method ? (
                        <p>
                            <strong>{t('storefront.pdp.rental_fulfillment', 'Fulfillment')}: </strong>
                            {String(rental.fulfillment_method).replace(/_/g, ' ')}
                        </p>
                    ) : null}
                    {rental.return_method ? (
                        <p>
                            <strong>{t('storefront.pdp.rental_return', 'Return')}: </strong>
                            {String(rental.return_method).replace(/_/g, ' ')}
                            {rental.return_location ? ` · ${rental.return_location}` : ''}
                        </p>
                    ) : null}
                    {rental.inventory_summary ? (
                        <p>
                            <strong>{t('storefront.pdp.rental_availability', 'Availability')}: </strong>
                            {rental.inventory_summary.available_units} {t('storefront.pdp.rental_units_available', 'unit(s) available')}
                        </p>
                    ) : null}
                    {!hidePrice && start ? (
                        <p className="font-medium text-neutral-900">
                            {t('storefront.pdp.rental_estimated', 'Estimated for your dates')}: {formatMoney(preview.unitPrice)}
                            {preview.deposit > 0 ? ` + ${t('storefront.pdp.rental_deposit_short', 'deposit')} ${formatMoney(preview.deposit)}` : ''}
                            {preview.cleaning > 0 ? ` + ${t('storefront.pdp.rental_cleaning_short', 'cleaning')} ${formatMoney(preview.cleaning)}` : ''}
                        </p>
                    ) : null}
                </div>
            </div>
            <SimplePdp {...props} rentalBooking={rentalBooking} />
        </>
    );
}

export { daysBetween, addDays };
