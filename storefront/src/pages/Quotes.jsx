import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { PageSkeleton } from '../components/Feedback.jsx';
import { t } from '../i18n.js';
import { formatMoney } from '../lib/format.js';

export default function Quotes() {
    const [rows, setRows] = useState(null);

    useEffect(() => {
        api.get('/account/quotes').then((r) => setRows(r.data.quotes || [])).catch(() => setRows([]));
    }, []);

    if (rows === null) {
        return <PageSkeleton />;
    }

    return (
        <div className="shop-vouchers">
            <header className="shop-vouchers-head">
                <h1>{t('storefront.account.quotes', 'Quotes')}</h1>
                <p>{t('storefront.account.quotes_body', 'Requests you sent from checkout.')}</p>
            </header>
            {rows.length === 0 ? (
                <p>{t('storefront.account.no_quotes', 'No quotes yet.')}</p>
            ) : (
                <ul className="space-y-3">
                    {rows.map((row) => (
                        <li key={row.id} className="rounded-lg border border-neutral-200 p-4">
                            <div className="font-medium">{row.number}</div>
                            <div className="text-sm text-neutral-600">
                                {row.status} · {formatMoney(row.total)}
                            </div>
                            {row.status === 'accepted' ? (
                                <button
                                    type="button"
                                    className="shop-btn shop-btn-primary mt-2"
                                    onClick={async () => {
                                        await csrf();
                                        await api.post(`/account/quotes/${row.id}/checkout`);
                                        window.location.href = '/checkout';
                                    }}
                                >
                                    {t('storefront.account.quote_checkout', 'Checkout at quoted prices')}
                                </button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
