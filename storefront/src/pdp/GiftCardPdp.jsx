import { useState } from 'react';
import api, { csrf } from '../api.js';
import { formatMoney, mediaUrlOrDefault } from '../lib/format.js';
import { t } from '../i18n.js';
import { useStore } from '../state/StoreState.jsx';
import { pricesHidden, completeCartAdd } from '../lib/commerce.js';
import { Icon } from '../components/Icons.jsx';

export default function GiftCardPdp({ product }) {
    const store = useStore();
    const hidePrice = pricesHidden(store.shop, store.customer) || product?.show_price === false;
    const config = product.gift_card || {};
    const amounts = (product.gift_card_amounts || []).filter((row) => row.is_enabled !== false);
    const [amountId, setAmountId] = useState(amounts[0]?.id || '');
    const [custom, setCustom] = useState(config.default_amount || '');
    const [recipientName, setRecipientName] = useState('');
    const [recipientEmail, setRecipientEmail] = useState('');
    const [senderName, setSenderName] = useState('');
    const [message, setMessage] = useState('');
    const [busy, setBusy] = useState(false);
    const [msg, setMsg] = useState('');

    const selected = amounts.find((row) => String(row.id) === String(amountId));
    const selling = config.amount_type === 'fixed'
        ? (selected?.selling_price ?? selected?.value ?? product.price)
        : (custom || config.default_amount || product.price);
    const coverSrc = mediaUrlOrDefault(product.cover_url || product.cover, 'lg');

    const add = async () => {
        setBusy(true);
        setMsg('');
        try {
            await csrf();
            const payload = {
                product_id: product.id,
                qty: 1,
                gift_card: {
                    fixed_amount_id: config.amount_type === 'fixed' ? amountId : undefined,
                    amount: config.amount_type === 'custom' ? custom : undefined,
                    recipient_name: recipientName,
                    recipient_email: recipientEmail,
                    sender_name: senderName,
                    gift_message: message,
                },
            };
            const { data: saved } = await api.post('/cart', payload);
            store.applyCart?.(saved);
            const { notices, blocked } = completeCartAdd({
                ping: store.ping,
                openCart: store.openCart,
                data: saved,
            });
            setMsg(blocked && notices.length ? notices.join(' ') : '');
        } catch (err) {
            const bag = err.response?.data?.errors || {};
            const first = Object.values(bag)[0];
            setMsg((Array.isArray(first) ? first[0] : first) || err.response?.data?.message || t('storefront.pdp.add_failed', 'Could not add to cart'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <div className="shop-pdp2">
            <div className="shop-pdp2-gallery">
                {product.cover_url || product.cover ? (
                    <img src={coverSrc} alt={product.name || ''} />
                ) : null}
            </div>
            <div className="shop-pdp2-buy">
                <p className="text-sm text-neutral-500">{t('storefront.pdp.gift_card', 'Gift card')}</p>
                <h1>{product.name}</h1>
                {hidePrice ? null : <p className="shop-pdp2-price">{formatMoney(selling)}</p>}
                {config.amount_type === 'fixed' ? (
                    <div className="flex flex-wrap gap-2 my-3">
                        {amounts.map((row) => (
                            <button
                                key={row.id}
                                type="button"
                                className={`shop-btn ${String(row.id) === String(amountId) ? 'shop-btn-primary' : ''}`}
                                onClick={() => setAmountId(row.id)}
                            >
                                {hidePrice ? null : formatMoney(row.value)}
                            </button>
                        ))}
                    </div>
                ) : (
                    <label className="block my-3">
                        <span className="text-sm">{t('storefront.pdp.gift_amount', 'Amount')}</span>
                        <input className="shop-input" type="number" min={config.min_amount || 1} max={config.max_amount || undefined} step={config.amount_step || 1} value={custom} onChange={(e) => setCustom(e.target.value)} />
                    </label>
                )}
                {config.recipient_email_enabled ? (
                    <label className="block my-2">
                        <span className="text-sm">{t('storefront.pdp.recipient_email', 'Recipient email')}</span>
                        <input className="shop-input" type="email" value={recipientEmail} onChange={(e) => setRecipientEmail(e.target.value)} required={!!config.recipient_email_required} />
                    </label>
                ) : null}
                {config.recipient_name_enabled ? (
                    <label className="block my-2">
                        <span className="text-sm">{t('storefront.pdp.recipient_name', 'Recipient name')}</span>
                        <input className="shop-input" value={recipientName} onChange={(e) => setRecipientName(e.target.value)} />
                    </label>
                ) : null}
                {config.sender_name_enabled ? (
                    <label className="block my-2">
                        <span className="text-sm">{t('storefront.pdp.sender_name', 'Your name')}</span>
                        <input className="shop-input" value={senderName} onChange={(e) => setSenderName(e.target.value)} />
                    </label>
                ) : null}
                {config.gift_message_enabled ? (
                    <label className="block my-2">
                        <span className="text-sm">{t('storefront.pdp.gift_message', 'Message')}</span>
                        <textarea className="shop-input" rows="3" value={message} onChange={(e) => setMessage(e.target.value)} />
                    </label>
                ) : null}
                {msg ? <p className="text-sm my-2">{msg}</p> : null}
                <button type="button" className="shop-btn shop-btn-primary" disabled={busy || !product.available_for_order} onClick={add}>
                    <Icon name="bag" className="h-4 w-4" />
                    {t('storefront.pdp.add', 'Add to cart')}
                </button>
                <p className="text-sm text-neutral-500 mt-3">{t('storefront.pdp.gift_digital', 'Delivered by email. No shipping.')}</p>
            </div>
        </div>
    );
}
