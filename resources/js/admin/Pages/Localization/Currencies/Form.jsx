import { useForm } from '@inertiajs/react';
import Layout from '../../Layout';
import { Button, Checkbox, Field, FormErrors, Input, PageHeader, Select, ui, t } from '../../../ui';

export default function Form({ currency }) {
    const form = useForm({
        code: currency?.code || '',
        name: currency?.name || '',
        symbol: currency?.symbol || '',
        decimal_places: currency?.decimal_places ?? 2,
        symbol_position: currency?.symbol_position || 'before',
        exchange_rate: currency?.exchange_rate ?? 1,
        is_enabled: currency?.is_enabled ?? true,
        is_default: currency?.is_default ?? false,
    });

    const submit = (e) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            code: String(data.code || '').toUpperCase(),
            is_enabled: !!data.is_enabled,
            is_default: !!data.is_default,
        }));
        if (currency) {
            form.put(`/admin/localization/currencies/${currency.id}`);
        } else {
            form.post('/admin/localization/currencies');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={currency ? t('admin.currencies.edit_currency', 'Edit currency') : t('admin.currencies.add_currency', 'Add currency')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/localization', label: t('admin.currencies.localization', 'Localization') },
                    { href: '/admin/localization/currencies', label: t('admin.currencies.currencies', 'Currencies') },
                    { label: currency ? t('admin.common.edit', 'Edit') : t('admin.currencies.add', 'Add') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href="/admin/localization/currencies">{t('admin.common.cancel', 'Cancel')}</Button>
                        <Button type="submit" form="currency-form" icon="check" busy={form.processing}>{t('admin.currencies.save', 'Save')}</Button>
                    </div>
                )}
                className="mb-6"
            />
            <form id="currency-form" onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}><h2 className={ui.sectionTitle}>{t('admin.currencies.currency_details', 'Currency details')}</h2></div>
                        <div className={ui.fieldGrid}>
                            <Field label={t('admin.currencies.code', 'Code')} error={form.errors.code} hint={t('admin.currencies.iso_4217_e_g_inr', 'ISO 4217, e.g. INR')} required>
                                <Input wide disabled={!!currency} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} maxLength={3} placeholder={t('admin.currencies.inr', 'INR')} />
                            </Field>
                            <Field label={t('admin.currencies.name', 'Name')} error={form.errors.name} required>
                                <Input wide value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder={t('admin.currencies.indian_rupee', 'Indian Rupee')} />
                            </Field>
                            <Field label={t('admin.currencies.symbol', 'Symbol')} error={form.errors.symbol} required>
                                <Input wide value={form.data.symbol} onChange={(e) => form.setData('symbol', e.target.value)} placeholder="₹" />
                            </Field>
                            <Field label={t('admin.currencies.symbol_position', 'Symbol position')} error={form.errors.symbol_position} required>
                                <Select wide value={form.data.symbol_position} onChange={(e) => form.setData('symbol_position', e.target.value)}>
                                    <option value="before">{t('admin.currencies.before_amount_100', 'Before amount (₹100)')}</option>
                                    <option value="after">{t('admin.currencies.after_amount_100', 'After amount (100 ₹)')}</option>
                                </Select>
                            </Field>
                            <Field label={t('admin.currencies.decimal_places', 'Decimal places')} error={form.errors.decimal_places} required>
                                <Input wide type="number" min={0} max={4} value={form.data.decimal_places} onChange={(e) => form.setData('decimal_places', e.target.value)} />
                            </Field>
                            <Field label={t('admin.currencies.exchange_rate', 'Exchange rate')} error={form.errors.exchange_rate} hint={t('admin.currencies.relative_to_the_store_default_default_1', 'Relative to the store default (default = 1)')} required>
                                <Input wide type="number" step="0.000001" value={form.data.exchange_rate} onChange={(e) => form.setData('exchange_rate', e.target.value)} disabled={!!form.data.is_default} />
                            </Field>
                        </div>
                    </section>
                </div>
                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}><h2 className={ui.sectionTitle}>{t('admin.currencies.status', 'Status')}</h2></div>
                        <Checkbox label={t('admin.common.active', 'Active')} checked={!!form.data.is_enabled} onChange={(e) => form.setData('is_enabled', e.target.checked)} />
                        <div className="mt-3">
                            <Checkbox
                                label={t('admin.currencies.default_store_currency', 'Default store currency')}
                                checked={!!form.data.is_default}
                                onChange={(e) => form.setData({ ...form.data, is_default: e.target.checked, exchange_rate: e.target.checked ? 1 : form.data.exchange_rate, is_enabled: e.target.checked ? true : form.data.is_enabled })}
                            />
                        </div>
                        <p className={`${ui.muted} mt-3`}>{t('admin.currencies.currencies_are_deactivated_not_deleted_so_histor', 'Currencies are deactivated, not deleted, so historical orders stay valid.')}</p>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
