import { router } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../../Layout';
import {
    Badge,
    Button,
    Field,
    Input,
    PageHeader,
    Select,
    formatMoney,
    ui,
    t,
} from '../../../ui';

const STATUS_TONES = {
    active: 'success',
    used: 'neutral',
    disabled: 'warning',
    cancelled: 'danger',
    expired: 'danger',
};

const TYPE_LABELS = {
    credit: 'Issued',
    redemption: 'Redeemed',
    adjustment: 'Adjustment',
    cancel: 'Cancelled',
};

function Card({ title, hint, children }) {
    return (
        <section className={ui.sectionCard}>
            {title ? (
                <div className={ui.sectionHead}>
                    <div>
                        <h2 className={ui.sectionTitle}>{title}</h2>
                        {hint ? <p className={ui.muted}>{hint}</p> : null}
                    </div>
                </div>
            ) : null}
            {children}
        </section>
    );
}

function DetailRow({ label, children }) {
    return (
        <div className="grid grid-cols-1 gap-1 py-2 border-b border-neutral-100 last:border-b-0 sm:grid-cols-[200px_1fr]">
            <span className={ui.label}>{label}</span>
            <span className="text-sm text-neutral-800">{children}</span>
        </div>
    );
}

export default function Show({ card }) {
    const currency = card.currency_code || 'USD';
    const [statusOpen, setStatusOpen] = useState(false);
    const [status, setStatus] = useState(card.status);
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const submitStatus = () => {
        setBusy(true);
        router.post(`/admin/gift-cards/${card.id}/status`, { status, note }, {
            preserveScroll: true,
            onFinish: () => { setBusy(false); setStatusOpen(false); setNote(''); },
        });
    };

    return (
        <Layout>
            <PageHeader
                title={card.code}
                total={null}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/gift-cards', label: t('admin.gift_cards.gift_cards', 'Gift cards') },
                    { label: card.code },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button variant="secondary" href="/admin/gift-cards">
                            {t('admin.common.back', 'Back')}
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={() => router.post(`/admin/gift-cards/${card.id}/resend`, {}, { preserveScroll: true })}
                        >
                            {t('admin.gift_cards.resend_email', 'Resend email')}
                        </Button>
                        <Button onClick={() => { setStatus(card.status); setStatusOpen(true); }}>
                            {t('admin.gift_cards.change_status', 'Change status')}
                        </Button>
                    </div>
                )}
            />

            <div className="space-y-4">
                <Card title={t('admin.gift_cards.details', 'Details')}>
                    <DetailRow label={t('admin.gift_cards.status', 'Status')}>
                        <Badge tone={card.is_expired && card.status !== 'expired' ? 'danger' : (STATUS_TONES[card.status] || 'neutral')}>
                            {card.is_expired && card.status !== 'expired' ? 'Expired' : card.status}
                        </Badge>
                    </DetailRow>
                    <DetailRow label={t('admin.gift_cards.initial_balance', 'Initial balance')}>{formatMoney(card.initial_balance, currency)}</DetailRow>
                    <DetailRow label={t('admin.gift_cards.current_balance', 'Current balance')}>
                        <span className={ui.cellStrong}>{formatMoney(card.current_balance, currency)}</span>
                    </DetailRow>
                    <DetailRow label={t('admin.gift_cards.used', 'Used')}>{formatMoney(card.used_amount, currency)}</DetailRow>
                    <DetailRow label={t('admin.gift_cards.expires', 'Expires')}>{card.expires_at || t('admin.gift_cards.never', 'Never')}</DetailRow>
                    <DetailRow label={t('admin.gift_cards.issued', 'Issued')}>{card.issued_at || '—'}</DetailRow>
                    <DetailRow label={t('admin.gift_cards.product', 'Product')}>{card.product?.name || '—'}</DetailRow>
                </Card>

                <Card title={t('admin.gift_cards.ownership', 'Ownership & delivery')}>
                    <DetailRow label={t('admin.gift_cards.customer', 'Customer')}>
                        {card.customer ? (
                            <a href={`/admin/customers/${card.customer.id}/edit`} className="text-primary-600">
                                {card.customer.name} · {card.customer.email}
                            </a>
                        ) : '—'}
                    </DetailRow>
                    <DetailRow label={t('admin.gift_cards.recipient_name', 'Recipient name')}>{card.recipient_name || '—'}</DetailRow>
                    <DetailRow label={t('admin.gift_cards.recipient_email', 'Recipient email')}>{card.recipient_email || '—'}</DetailRow>
                    <DetailRow label={t('admin.gift_cards.sender_name', 'Sender name')}>{card.sender_name || '—'}</DetailRow>
                    <DetailRow label={t('admin.gift_cards.gift_message', 'Gift message')}>{card.gift_message || '—'}</DetailRow>
                    <DetailRow label={t('admin.gift_cards.order', 'Order')}>
                        {card.order ? (
                            <a href={`/admin/orders/${card.order.id}`} className="text-primary-600">{card.order.number}</a>
                        ) : '—'}
                    </DetailRow>
                </Card>

                <Card title={t('admin.gift_cards.ledger', 'Transaction ledger')}>
                    <div className={ui.tableWrap}>
                        <table className={ui.table}>
                            <thead>
                                <tr>
                                    <th>{t('admin.common.date', 'Date')}</th>
                                    <th>{t('admin.gift_cards.type', 'Type')}</th>
                                    <th>{t('admin.gift_cards.amount', 'Amount')}</th>
                                    <th>{t('admin.gift_cards.balance_before', 'Before')}</th>
                                    <th>{t('admin.gift_cards.balance_after', 'After')}</th>
                                    <th>{t('admin.gift_cards.reference', 'Reference')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(!card.transactions || card.transactions.length === 0) ? (
                                    <tr>
                                        <td colSpan="6" className={ui.muted}>{t('admin.gift_cards.no_transactions', 'No transactions yet.')}</td>
                                    </tr>
                                ) : card.transactions.map((row) => (
                                    <tr key={row.id}>
                                        <td><span className="text-sm text-neutral-700">{row.created_at}</span></td>
                                        <td>{TYPE_LABELS[row.type] || row.type}</td>
                                        <td>{formatMoney(row.amount, currency)}</td>
                                        <td>{formatMoney(row.balance_before, currency)}</td>
                                        <td>{formatMoney(row.balance_after, currency)}</td>
                                        <td><span className="text-sm text-neutral-600">{row.order || '—'}</span></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>
            </div>

            {statusOpen ? (
                <div className={ui.modalBackdrop} onClick={() => setStatusOpen(false)}>
                    <div
                        className={`${ui.modal} max-w-md`}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="gift-card-status-title"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <div className={ui.modalHead}>
                            <h2 id="gift-card-status-title" className={ui.modalTitle}>
                                {t('admin.gift_cards.change_status', 'Change status')}
                            </h2>
                        </div>
                        <div className={ui.modalBody}>
                            <Field label={t('admin.gift_cards.status', 'Status')}>
                                <Select wide value={status} onChange={(e) => setStatus(e.target.value)}>
                                    <option value="active">Active</option>
                                    <option value="disabled">Disabled</option>
                                    <option value="cancelled">Cancelled</option>
                                </Select>
                            </Field>
                            <Field label={t('admin.gift_cards.note', 'Note')} hint={t('admin.gift_cards.note_hint', 'Recorded on the ledger for audit.')}>
                                <Input wide value={note} onChange={(e) => setNote(e.target.value)} />
                            </Field>
                        </div>
                        <div className={ui.modalFoot}>
                            <Button variant="secondary" onClick={() => setStatusOpen(false)}>{t('admin.common.cancel', 'Cancel')}</Button>
                            <Button onClick={submitStatus} disabled={busy}>{t('admin.common.save', 'Save')}</Button>
                        </div>
                    </div>
                </div>
            ) : null}
        </Layout>
    );
}
