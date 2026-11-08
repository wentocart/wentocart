import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import Layout from '../Layout';
import {
    Button,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    Textarea,
    Toggle,
    t,
    ui,
} from '../../ui';

const SECTIONS = [
    { key: 'seller', title: 'Seller', names: ['seller_id'] },
    { key: 'identity', title: 'Identity', names: ['name', 'shop_name', 'shop_slug', 'slug', 'sku', 'code', 'profile_name', 'first_name', 'last_name', 'approval_status'] },
    { key: 'contact', title: 'Contact', names: ['email', 'phone', 'contact_name', 'contact_phone', 'contact_email', 'paypal_email'] },
    { key: 'address', title: 'Address', names: ['address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country_code'] },
    { key: 'pricing', title: 'Pricing', names: ['price', 'compare_price', 'stock_qty', 'rate', 'commission_rate', 'amount', 'value', 'min_cart', 'max_uses', 'default_commission', 'return_window_days', 'position'] },
    { key: 'catalog', title: 'Catalog', names: ['category_id', 'parent_id', 'type', 'scope', 'apply_as', 'action', 'product_id'] },
    { key: 'banking', title: 'Payout details', names: ['account_name', 'account_number', 'bank_name', 'routing_number'] },
    { key: 'social', title: 'Social links', names: ['facebook_url', 'twitter_url', 'instagram_url', 'youtube_url'] },
    { key: 'schedule', title: 'Schedule', names: ['starts_at', 'expires_at'] },
    { key: 'copy', title: 'Copy', names: ['short_description', 'description', 'notes', 'note', 'vacation_message', 'store_policy', 'shipping_policy', 'return_policy', 'approval_note'] },
    { key: 'options', title: 'Options', names: [] },
    { key: 'details', title: 'Details', names: [] },
];

const SECTION_TITLES = {
    seller: ['admin.form.section_seller', 'Seller'],
    identity: ['admin.form.section_identity', 'Identity'],
    contact: ['admin.form.section_contact', 'Contact'],
    address: ['admin.form.section_address', 'Address'],
    pricing: ['admin.form.section_pricing', 'Pricing'],
    catalog: ['admin.form.section_catalog', 'Catalog'],
    banking: ['admin.form.section_banking', 'Payout details'],
    social: ['admin.form.section_social', 'Social links'],
    schedule: ['admin.form.section_schedule', 'Schedule'],
    copy: ['admin.form.section_copy', 'Copy'],
    options: ['admin.form.section_options', 'Options'],
    details: ['admin.form.section_details', 'Details'],
};

const FULL_WIDTH = new Set([
    'textarea', 'seller', 'checkbox',
    'description', 'short_description', 'notes', 'note', 'vacation_message',
    'store_policy', 'shipping_policy', 'return_policy', 'approval_note',
    'address_line1', 'address_line2', 'seller_id',
]);

export default function Form({ title, path, action, method = 'post', values = {}, fields = [], sellers = [], reviewHref = null }) {
    const { errors } = usePage().props;
    const form = useForm(values);
    const groups = groupFields(fields);
    const [reviewNote, setReviewNote] = useState('');

    const submit = (event) => {
        event.preventDefault();
        form.submit(method, action);
    };

    const review = (reviewAction) => {
        if (!reviewHref) {
            return;
        }
        router.post(reviewHref, { action: reviewAction, note: reviewNote }, { preserveScroll: true });
    };

    return (
        <Layout>
            <PageHeader
                title={title}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/multivendor/dashboard', label: t('admin.nav.multivendor', 'Multivendor Management') },
                    { href: path, label: t('admin.common.list', 'List') },
                    { label: title },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button type="button" variant="secondary" href={path}>{t('admin.common.cancel', 'Cancel')}</Button>
                        <Button type="submit" form="multivendor-form" icon="check" busy={form.processing}>{t('admin.common.save', 'Save')}</Button>
                    </div>
                )}
            />
            <form id="multivendor-form" className={ui.formPage} onSubmit={submit}>
                <FormErrors errors={errors} />
                {groups.map((group) => (
                    <section key={group.key} className={ui.sectionCard}>
                        <header className="mb-5">
                            <p className={ui.sectionKicker}>{t(...SECTION_TITLES[group.key])}</p>
                        </header>
                        <div className={ui.fieldGrid}>
                            {group.fields.map((field) => (
                                <FieldBlock key={field.name} field={field} form={form} sellers={sellers} />
                            ))}
                        </div>
                    </section>
                ))}
                {reviewHref ? (
                    <section className={ui.sectionCard}>
                        <header className="mb-5">
                            <p className={ui.sectionKicker}>{t('admin.multivendor.moderation', 'Moderation')}</p>
                        </header>
                        <Field label={t('admin.multivendor.rejection_reason', 'Rejection reason')}>
                            <Textarea rows={3} value={reviewNote} onChange={(e) => setReviewNote(e.target.value)} />
                        </Field>
                        <div className="mt-4 flex flex-wrap gap-3">
                            <Button type="button" onClick={() => review('approve')}>{t('admin.sellers.accept', 'Accept')}</Button>
                            <Button type="button" variant="danger" onClick={() => review('reject')}>{t('admin.sellers.reject', 'Reject')}</Button>
                        </div>
                    </section>
                ) : null}
            </form>
        </Layout>
    );
}

function FieldBlock({ field, form, sellers }) {
    const full = FULL_WIDTH.has(field.type) || FULL_WIDTH.has(field.name);
    const className = full ? ui.fieldSpan2 : undefined;

    if (field.type === 'checkbox') {
        return (
            <Toggle
                className={className}
                value={Boolean(form.data[field.name])}
                onChange={(next) => form.setData(field.name, next)}
                label={field.label}
                hint={field.hint}
                error={form.errors[field.name]}
                disabled={field.disabled}
            />
        );
    }

    return (
        <Field className={className} label={field.label} hint={field.hint} error={form.errors[field.name]} required={!!field.required}>
            <Control field={field} form={form} sellers={sellers} />
        </Field>
    );
}

function groupFields(fields) {
    const named = new Map(SECTIONS.filter((section) => section.names.length).map((section) => [section.key, section.names]));
    const assigned = new Map();

    fields.forEach((field) => {
        if (field.type === 'checkbox') {
            assigned.set(field.name, 'options');
            return;
        }
        for (const [key, names] of named) {
            if (names.includes(field.name)) {
                assigned.set(field.name, key);
                return;
            }
        }
        assigned.set(field.name, field.type === 'textarea' ? 'copy' : 'details');
    });

    return SECTIONS
        .map((section) => ({
            key: section.key,
            fields: fields.filter((field) => assigned.get(field.name) === section.key),
        }))
        .filter((group) => group.fields.length > 0);
}

function Control({ field, form, sellers }) {
    const type = field.type || 'text';
    const value = form.data[field.name] ?? '';
    const set = (next) => form.setData(field.name, next);

    if (type === 'textarea') {
        return <Textarea rows={4} value={value || ''} onChange={(e) => set(e.target.value)} disabled={field.disabled} />;
    }
    if (type === 'seller') {
        return (
            <Select value={String(value || '')} onChange={(e) => set(e.target.value)} disabled={field.disabled} wide>
                <option value="">{t('admin.multivendor.choose_seller', 'Choose a seller')}</option>
                {sellers.map((seller) => (
                    <option key={seller.id} value={seller.id}>
                        {seller.available_label ? `${seller.label} (${seller.available_label} available)` : seller.label}
                    </option>
                ))}
            </Select>
        );
    }
    if (type === 'select') {
        return (
            <Select value={String(value || '')} onChange={(e) => set(e.target.value)} disabled={field.disabled} wide>
                <option value="">{t('admin.common.select', 'Select')}</option>
                {(field.options || []).map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                ))}
            </Select>
        );
    }
    if (type === 'number') {
        return <Input wide type="number" step="0.01" value={value} onChange={(e) => set(e.target.value)} disabled={field.disabled} />;
    }
    if (type === 'datetime') {
        return <Input wide type="datetime-local" value={value || ''} onChange={(e) => set(e.target.value)} disabled={field.disabled} />;
    }

    return <Input wide type={type} value={value || ''} onChange={(e) => set(e.target.value)} disabled={field.disabled} required={field.required} />;
}
