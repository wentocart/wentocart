import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../../api.js';
import { t } from '../../i18n.js';
import { EmptyState, PageSkeleton } from '../../components/Feedback.jsx';
import { FormNotice } from '../../components/Form.jsx';

export default function SellerShipments() {
    const [rows, setRows] = useState(null);
    const [error, setError] = useState('');

    useEffect(() => {
        api.get('/account/seller/shipments')
            .then((r) => setRows(r.data.shipments?.data || []))
            .catch(() => {
                setRows([]);
                setError(t('storefront.seller.load_failed', 'Could not load shipments.'));
            });
    }, []);

    if (!rows) {
        return <PageSkeleton />;
    }

    return (
        <div>
            <h2 className="mv-page-title">{t('storefront.seller.shipments', 'Shipments')}</h2>
            <p className="mv-page-help">{t('storefront.seller.shipments_help', 'Fulfillment for your seller orders. Open an order to create or advance a shipment.')}</p>
            <FormNotice tone="error">{error}</FormNotice>
            {rows.length === 0 ? (
                <div className="mv-body">
                    <EmptyState
                        title={t('storefront.seller.no_shipments', 'No shipments yet.')}
                        action={<Link to="/account/seller/orders" className="shop-btn shop-btn-primary">{t('storefront.seller.orders', 'Seller Orders')}</Link>}
                    />
                </div>
            ) : (
                <ul className="mv-list">
                    {rows.map((row) => (
                        <li key={row.id} className="shop-card mv-row p-4 text-sm">
                            <div>
                                <p className="font-semibold capitalize">{row.status?.replaceAll('_', ' ')}</p>
                                <p className="mv-muted">
                                    {row.seller_order_number || `#${row.seller_order_id}`}
                                    {row.carrier ? ` · ${row.carrier}` : ''}
                                    {row.tracking_number ? ` · ${row.tracking_number}` : ''}
                                    {` · ${row.item_count || 0} ${t('storefront.account.items', 'items')}`}
                                </p>
                            </div>
                            {row.seller_order_id ? (
                                <Link to={`/account/seller/orders/${row.seller_order_id}`} className="mv-link">
                                    {t('storefront.seller.open_order', 'Open order')}
                                </Link>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
