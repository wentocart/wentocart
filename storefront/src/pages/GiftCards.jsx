import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api.js';
import { PageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';

export default function GiftCards() {
    const [rows, setRows] = useState(null);

    useEffect(() => {
        api.get('/account/gift-cards').then((r) => setRows(r.data.gift_cards || [])).catch(() => setRows([]));
    }, []);

    if (rows === null) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-vouchers">
            <header className="shop-vouchers-head">
                <div>
                    <h1>{t('storefront.account.gift_cards', 'Gift cards')}</h1>
                    <p>{t('storefront.account.gift_cards_body', 'Balances issued to your account or email.')}</p>
                </div>
                <Link to="/cart" className="shop-vouchers-browse">
                    {t('storefront.account.redeem_in_cart', 'Redeem in cart')}
                </Link>
            </header>
            {rows.length === 0 ? (
                <p>{t('storefront.account.no_gift_cards', 'No gift cards yet.')}</p>
            ) : (
                <ul className="space-y-3">
                    {rows.map((row) => (
                        <li key={row.code} className="rounded-lg border border-neutral-200 p-4">
                            <div className="font-medium">{row.code}</div>
                            <div className="text-sm text-neutral-600">
                                {formatMoney(row.balance)} · {row.status}
                                {row.expires_at ? ` · ${row.expires_at}` : ''}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
