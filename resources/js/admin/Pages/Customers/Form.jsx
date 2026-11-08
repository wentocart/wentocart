import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Layout from '../Layout';
import {
    Avatar,
    Badge,
    Button,
    FeatureTile,
    Field,
    FormErrors,
    Input,
    PageHeader,
    Select,
    RowActions,
    StatusTabs,
    Textarea,
    cx,
    money,
    ui,
    t,
    ModuleSlots,
} from '../../ui';

function formatDate(iso) {
    if (!iso) {
        return '—';
    }
    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
}

function splitName(name = '') {
    const parts = String(name).trim().split(/\s+/);
    return { first: parts[0] || '', last: parts.slice(1).join(' ') };
}

function groupCode(group) {
    return String(group?.code || group || '').trim();
}

function pickGroupCode(groups, ...preferred) {
    const codes = (groups || []).map(groupCode).filter(Boolean);
    const match = preferred.map((value) => String(value || '').trim()).find((code) => code && codes.includes(code));

    return match || codes[0] || '';
}

function looksLikeEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function collectCustomerFormErrors({ editing, data }) {
    const errors = {};
    const firstName = String(data.first_name || '').trim();
    const lastName = String(data.last_name || '').trim();
    const email = String(data.email || '').trim();
    const password = String(data.password || '');
    const confirm = String(data.password_confirmation || '');

    if (!firstName) {
        errors.first_name = t('admin.customers.first_name_required', 'First name is required.');
    }
    if (!lastName) {
        errors.last_name = t('admin.customers.last_name_required', 'Last name is required.');
    }
    if (!String(data.customer_group || '').trim()) {
        errors.customer_group = t('admin.customers.group_required', 'Customer group is required.');
    }
    if (!email) {
        errors.email = t('admin.customers.email_required', 'Email address is required.');
    } else if (!looksLikeEmail(email)) {
        errors.email = t('admin.customers.email_invalid', 'Enter a valid email address.');
    }

    if (!editing) {
        if (password === '') {
            errors.password = t('admin.customers.password_required', 'Password is required.');
        } else if (password.length < 8) {
            errors.password = t('admin.customers.password_min_8', 'Password must be at least 8 characters.');
        }
        if (confirm === '') {
            errors.password_confirmation = t('admin.customers.confirm_password_required', 'Confirm password is required.');
        } else if (password !== '' && password !== confirm) {
            errors.password_confirmation = t('admin.customers.password_mismatch', 'Password confirmation does not match.');
        }
    } else if (password !== '' || confirm !== '') {
        if (password === '') {
            errors.password = t('admin.customers.password_required', 'Password is required.');
        } else if (password.length < 8) {
            errors.password = t('admin.customers.password_min_8', 'Password must be at least 8 characters.');
        }
        if (confirm === '') {
            errors.password_confirmation = t('admin.customers.confirm_password_required', 'Confirm password is required.');
        } else if (password !== '' && password !== confirm) {
            errors.password_confirmation = t('admin.customers.password_mismatch', 'Password confirmation does not match.');
        }
    }

    return errors;
}

function deskTypeTone(type) {
    switch (String(type || '')) {
        case 'order':
            return 'success';
        case 'note':
            return 'primary';
        case 'review':
            return 'info';
        case 'gdpr':
            return 'warning';
        case 'impersonation':
            return 'neutral';
        default:
            return 'neutral';
    }
}

function formatDeskWhen(iso) {
    if (!iso) {
        return '—';
    }
    try {
        return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    } catch {
        return String(iso);
    }
}

export default function CustomerForm({ customer, groups, default_group_code = 'retailer', moduleSlots = [], desk = null }) {
    const editing = Boolean(customer?.id);
    const { shop, errors: pageErrors } = usePage().props;
    const currency = shop?.currency || 'USD';
    const split = splitName(customer?.name);
    const initialGroup = pickGroupCode(groups, customer?.customer_group, default_group_code);
    const [tab, setTab] = useState('general');
    const [showPassword, setShowPassword] = useState(false);
    const [showConfirm, setShowConfirm] = useState(false);
    const [statusBusy, setStatusBusy] = useState(false);
    const [deskNote, setDeskNote] = useState('');
    const [deskNotify, setDeskNotify] = useState(false);
    const [deskBusy, setDeskBusy] = useState(false);
    const formRef = useRef(null);
    const photoRef = useRef(null);
    const avatarFile = useRef(null);
    const [preview, setPreview] = useState(customer?.avatar_url || null);
    const deskSummary = desk?.summary || {};
    const deskEvents = Array.isArray(desk?.events) ? desk.events : [];

    const form = useForm({
        first_name: customer?.first_name || split.first,
        last_name: customer?.last_name || split.last,
        email: customer?.email || '',
        phone: customer?.phone || '',
        password: '',
        password_confirmation: '',
        customer_group: initialGroup,
        date_of_birth: customer?.date_of_birth || '',
        tax_vat: customer?.tax_vat || '',
        gender: customer?.gender || '',
        company_name: customer?.company_name || '',
        business_type: customer?.business_type || '',
        company_website: customer?.company_website || '',
        gst_tax_id: customer?.gst_tax_id || '',
        is_enabled: customer?.is_enabled ?? true,
        newsletter: customer?.newsletter ?? true,
        notes: customer?.notes || '',
        avatar: null,
    });

    useEffect(() => {
        if (! avatarFile.current) {
            setPreview(customer?.avatar_url || null);
        }
    }, [customer?.avatar_url]);

    const assignedGroup = useMemo(
        () => (groups || []).find((g) => (g.code || g) === form.data.customer_group) || null,
        [groups, form.data.customer_group],
    );

    const submit = (createAnother = false) => {
        const errors = collectCustomerFormErrors({ editing, data: form.data });

        form.clearErrors();
        if (Object.keys(errors).length) {
            form.setError(errors);
            setTab('general');
            return;
        }

        form.transform((data) => {
            const payload = {
                ...data,
                phone: data.phone || '',
                customer_group: data.customer_group || initialGroup,
                create_another: createAnother,
                ...(editing ? { _method: 'put' } : {}),
            };
            if (avatarFile.current instanceof File) {
                payload.avatar = avatarFile.current;
            } else {
                delete payload.avatar;
            }
            return payload;
        });
        form.post(editing ? `/admin/customers/${customer.id}` : '/admin/customers', {
            forceFormData: true,
            preserveState: true,
            preserveScroll: true,
            onSuccess: () => {
                avatarFile.current = null;
                if (photoRef.current) {
                    photoRef.current.value = '';
                }
            },
        });
    };

    const selectTab = (next) => {
        setTab(next);
        const scroller = formRef.current?.closest('main');
        if (scroller) {
            scroller.scrollTop = 0;
        }
    };

    const toggleCustomerStatus = () => {
        if (statusBusy) {
            return;
        }
        if (!editing) {
            form.setData('is_enabled', !form.data.is_enabled);
            return;
        }
        setStatusBusy(true);
        router.post(`/admin/customers/${customer.id}/toggle`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                form.setData('is_enabled', !form.data.is_enabled);
            },
            onFinish: () => setStatusBusy(false),
        });
    };

    const submitDeskNote = () => {
        const note = String(deskNote || '').trim();
        if (!editing || !note || deskBusy) {
            return;
        }
        setDeskBusy(true);
        router.post(`/admin/customers/${customer.id}/desk-notes`, {
            note,
            customer_notified: deskNotify,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setDeskNote('');
                setDeskNotify(false);
            },
            onFinish: () => setDeskBusy(false),
        });
    };

    const onPhoto = (e) => {
        const file = e.target.files?.[0];
        if (!file) {
            return;
        }
        avatarFile.current = file;
        form.setData('avatar', file);
        setPreview(URL.createObjectURL(file));
    };

    const section = (title, children) => (
        <section className={ui.sectionCard}>
            <h2 className={ui.sectionTitle}>{title}</h2>
            {children}
        </section>
    );

    const title = editing
        ? (customer.name || t('admin.customers.edit_customer', 'Edit Customer'))
        : t('admin.customers.add_new_customer', 'Add New Customer');

    return (
        <Layout mainClassName={ui.mainFlushTop}>
            <form
                ref={formRef}
                className={ui.formPageShell}
                onSubmit={(e) => {
                    e.preventDefault();
                    submit(false);
                }}
            >
                <div className={ui.formPageStickyHead}>
                    <PageHeader
                        crumbsFirst
                        title={title}
                        meta={assignedGroup ? (
                            <Badge tone={assignedGroup.color || 'primary'}>{assignedGroup.name}</Badge>
                        ) : null}
                        crumbs={[
                            { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                            { href: '/admin/customers', label: t('admin.customers.customers', 'Customers') },
                            { label: editing ? t('admin.customers.edit_customer', 'Edit Customer') : t('admin.customers.add_new_customer', 'Add New Customer') },
                        ]}
                        actions={(
                            <>
                                <Button type="submit" icon="check" busy={form.processing}>
                                    {editing
                                        ? t('admin.customers.save_changes', 'Save Changes')
                                        : t('admin.customers.save_customer', 'Save Customer')}
                                </Button>
                                <RowActions
                                    canView={false}
                                    canEdit={false}
                                    canDelete={editing}
                                    confirmMessage={t('admin.customers.delete_this_customer', 'Delete this customer?')}
                                    onDelete={() => router.delete(`/admin/customers/${customer.id}`)}
                                    items={[
                                        {
                                            key: 'back',
                                            label: t('admin.customers.back_to_list', 'Back to list'),
                                            icon: 'arrow-left',
                                            href: '/admin/customers',
                                        },
                                        ...(editing ? [{
                                            key: 'impersonate',
                                            label: t('admin.customers.impersonate', 'Login as customer'),
                                            icon: 'users',
                                            onClick: () => {
                                                if (window.confirm(t('admin.customers.impersonate_confirm', 'Open the storefront as this customer?'))) {
                                                    router.post(`/admin/customers/${customer.id}/impersonate`);
                                                }
                                            },
                                        }] : [{
                                            key: 'another',
                                            label: t('admin.customers.save_create_another', 'Save & Create Another'),
                                            icon: 'plus',
                                            onClick: () => submit(true),
                                        }]),
                                    ]}
                                />
                            </>
                        )}
                    />
                    <ModuleSlots slots={moduleSlots} />
                    <FormErrors className="mb-2" errors={{ ...form.errors, ...pageErrors }} />
                    <StatusTabs
                        tabs={[
                            { key: 'general', label: t('admin.customers.general_information', 'General Information') },
                            ...(editing ? [{
                                key: 'desk',
                                label: t('admin.customers.desk', 'Desk'),
                            }] : []),
                            { key: 'notes', label: t('admin.customers.profile_notes', 'Profile notes') },
                        ]}
                        value={tab}
                        onChange={selectTab}
                        className={ui.formEditTabs}
                    />
                </div>

                <div className={ui.formPageBody}>
            <div className={cx(ui.formLayout, ui.blockGap)}>
                <div className={ui.formMain}>
                    {tab === 'general' && (
                        <>
                            {section(t('admin.customers.status', 'Status'), (
                                <div className="grid gap-3 md:grid-cols-2">
                                    <FeatureTile
                                        icon="user"
                                        tone="bg-violet-100 text-violet-600"
                                        title={t('admin.customers.active_inactive', 'Active or inactive')}
                                        hint={t('admin.customers.status_hint', 'An inactive customer cannot sign in.')}
                                        value={!!form.data.is_enabled}
                                        disabled={statusBusy}
                                        onChange={() => toggleCustomerStatus()}
                                    />
                                    <FeatureTile
                                        icon="mail"
                                        tone="bg-sky-100 text-sky-600"
                                        title={t('admin.customers.newsletter', 'Newsletter')}
                                        hint={t('admin.customers.subscribe_to_newsletter', 'Subscribe to newsletter')}
                                        value={!!form.data.newsletter}
                                        onChange={(on) => form.setData('newsletter', on)}
                                    />
                                </div>
                            ))}
                            {section('Personal Information', (
                                <div className={ui.fieldGrid}>
                                    <Field label={t('admin.customers.gender', 'Gender')} className={ui.fieldSpan2}>
                                        <div className="grid grid-cols-3 gap-3" role="radiogroup" aria-label={t('admin.customers.gender', 'Gender')}>
                                            {[
                                                ['male', t('admin.customers.male', 'Male')],
                                                ['female', t('admin.customers.female', 'Female')],
                                                ['other', t('admin.customers.other', 'Other')],
                                            ].map(([value, label]) => {
                                                const active = form.data.gender === value;

                                                return (
                                                    <button
                                                        key={value}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={active}
                                                        className={active ? ui.choiceCardActive : ui.choiceCard}
                                                        onClick={() => form.setData('gender', active ? '' : value)}
                                                    >
                                                        <div className={ui.choiceTitle}>{label}</div>
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </Field>
                                    <Field label={t('admin.customers.first_name', 'First Name')} error={form.errors.first_name} required>
                                        <Input wide invalid={!!form.errors.first_name} placeholder={t('admin.customers.enter_first_name', 'Enter first name')} value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} />
                                    </Field>
                                    <Field label={t('admin.customers.last_name', 'Last Name')} error={form.errors.last_name} required>
                                        <Input wide invalid={!!form.errors.last_name} placeholder={t('admin.customers.enter_last_name', 'Enter last name')} value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} />
                                    </Field>
                                    <Field label={t('admin.customers.email_address', 'Email Address')} error={form.errors.email} required>
                                        <Input wide type="email" invalid={!!form.errors.email} placeholder={t('admin.customers.enter_email_address', 'Enter email address')} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                                    </Field>
                                    <Field
                                        label={t('admin.customers.password', 'Password')}
                                        error={form.errors.password}
                                        required={!editing}
                                        hint={editing
                                            ? t('admin.customers.password_hint_edit', 'Leave blank to keep the current password. New passwords must be at least 8 characters.')
                                            : t('admin.customers.password_hint_create', 'Password must be at least 8 characters.')}
                                    >
                                        <div className={ui.row}>
                                            <Input
                                                wide
                                                type={showPassword ? 'text' : 'password'}
                                                invalid={!!form.errors.password}
                                                value={form.data.password}
                                                onChange={(e) => form.setData('password', e.target.value)}
                                                placeholder={editing
                                                    ? t('admin.customers.leave_blank_to_keep_current', 'Leave blank to keep current')
                                                    : t('admin.customers.set_password', 'Set password')}
                                                autoComplete={editing ? 'new-password' : 'new-password'}
                                            />
                                            <Button type="button" variant="ghost" onClick={() => setShowPassword((v) => !v)}>{showPassword ? t('admin.customers.hide', 'Hide') : t('admin.customers.show', 'Show')}</Button>
                                        </div>
                                    </Field>
                                     <Field label={t('admin.customers.phone_number', 'Phone Number')} error={form.errors.phone}>
                                         <Input
                                             wide
                                             invalid={!!form.errors.phone}
                                             placeholder="+1 555 123 4567"
                                             value={form.data.phone}
                                             onChange={(e) => form.setData('phone', e.target.value)}
                                         />
                                     </Field>
                                    <Field
                                        label={t('admin.customers.confirm_password', 'Confirm Password')}
                                        error={form.errors.password_confirmation}
                                        required={!editing}
                                    >
                                        <div className={ui.row}>
                                            <Input
                                                wide
                                                type={showConfirm ? 'text' : 'password'}
                                                invalid={!!form.errors.password_confirmation}
                                                value={form.data.password_confirmation}
                                                onChange={(e) => form.setData('password_confirmation', e.target.value)}
                                                placeholder={t('admin.customers.confirm_password_2', 'Confirm password')}
                                                autoComplete="new-password"
                                            />
                                            <Button type="button" variant="ghost" onClick={() => setShowConfirm((v) => !v)}>{showConfirm ? t('admin.customers.hide', 'Hide') : t('admin.customers.show', 'Show')}</Button>
                                        </div>
                                    </Field>
                                    <Field label={t('admin.customers.date_of_birth', 'Date of Birth')}>
                                        <Input wide type="date" value={form.data.date_of_birth || ''} onChange={(e) => form.setData('date_of_birth', e.target.value)} />
                                    </Field>
                                    <Field label={t('admin.customers.tax_vat_number', 'Tax / VAT Number')}>
                                        <Input wide placeholder={t('admin.customers.enter_tax_or_vat_number', 'Enter tax or VAT number')} value={form.data.tax_vat} onChange={(e) => form.setData('tax_vat', e.target.value)} />
                                    </Field>
                                </div>
                            ))}
                            {section('Company Information (Optional)', (
                                <div className={ui.fieldGrid}>
                                    <Field label={t('admin.customers.company_name', 'Company Name')}>
                                        <Input wide placeholder={t('admin.customers.enter_company_name', 'Enter company name')} value={form.data.company_name} onChange={(e) => form.setData('company_name', e.target.value)} />
                                    </Field>
                                    <Field label={t('admin.customers.business_type', 'Business Type')}>
                                        <Select wide value={form.data.business_type} onChange={(e) => form.setData('business_type', e.target.value)}>
                                            <option value="">{t('admin.customers.select_business_type', 'Select business type')}</option>
                                            <option value="retail">{t('admin.customers.retail', 'Retail')}</option>
                                            <option value="wholesale">{t('admin.customers.wholesale', 'Wholesale')}</option>
                                            <option value="other">{t('admin.customers.other', 'Other')}</option>
                                        </Select>
                                    </Field>
                                    <Field label={t('admin.customers.company_website', 'Company Website')}>
                                        <Input wide placeholder={t('admin.customers.enter_website_url', 'Enter website URL')} value={form.data.company_website} onChange={(e) => form.setData('company_website', e.target.value)} />
                                    </Field>
                                    <Field label={t('admin.customers.gst_tax_id', 'GST / Tax ID')}>
                                        <Input wide placeholder={t('admin.customers.enter_gst_or_tax_id', 'Enter GST or Tax ID')} value={form.data.gst_tax_id} onChange={(e) => form.setData('gst_tax_id', e.target.value)} />
                                    </Field>
                                </div>
                            ))}
                            {section('Default Settings', (
                                <div className={ui.fieldGrid}>
                                    <Field label={t('admin.customers.group', 'Group')} required error={form.errors.customer_group}>
                                        <Select wide value={form.data.customer_group} onChange={(e) => form.setData('customer_group', e.target.value)}>
                                            {(groups || []).length === 0 ? (
                                                <option value="">{t('admin.customers.no_groups', 'No customer groups yet')}</option>
                                            ) : null}
                                            {(groups || []).map((g) => (
                                                <option key={g.code || g} value={g.code || g}>{g.name || g}</option>
                                            ))}
                                        </Select>
                                    </Field>
                                </div>
                            ))}
                        </>
                    )}

                    {tab === 'desk' && editing && (
                        <>
                            {section(t('admin.customers.desk_activity', 'Customer Desk'), (
                                <>
                                    <p className={ui.metaK}>
                                        {t(
                                            'admin.customers.desk_hint',
                                            'One timeline for staff notes, orders, reviews, GDPR, and storefront impersonation — not just a memo field.',
                                        )}
                                    </p>
                                    <div className={cx(ui.row, 'flex-wrap gap-2 mt-3')}>
                                        {[
                                            ['orders', t('admin.customers.total_orders', 'Total Orders')],
                                            ['notes', t('admin.customers.desk_notes', 'Staff notes')],
                                            ['reviews', t('admin.customers.desk_reviews', 'Reviews')],
                                            ['gdpr', t('admin.customers.desk_gdpr', 'GDPR')],
                                            ['impersonations', t('admin.customers.desk_impersonations', 'Impersonations')],
                                        ].map(([key, label]) => (
                                            <Badge key={key} tone="neutral" dot={false}>
                                                {label}: {Number(deskSummary[key] || 0)}
                                            </Badge>
                                        ))}
                                    </div>
                                    <div className="mt-4 space-y-3">
                                        {deskEvents.length === 0 ? (
                                            <div className={ui.metaK}>
                                                {t('admin.customers.desk_empty', 'No desk activity yet. Add a staff note below.')}
                                            </div>
                                        ) : deskEvents.map((event) => (
                                            <div key={event.id} className="border-l-2 border-neutral-200 pl-3 py-1">
                                                <div className={cx(ui.row, 'flex-wrap items-center gap-2')}>
                                                    <Badge tone={deskTypeTone(event.type)} dot={false}>
                                                        {String(event.type || 'event')}
                                                    </Badge>
                                                    <span className={ui.metaK}>{formatDeskWhen(event.at)}</span>
                                                </div>
                                                <div className="mt-1 font-medium text-neutral-900">
                                                    {event.href ? (
                                                        <a href={event.href} className="hover:underline">
                                                            {event.title}
                                                        </a>
                                                    ) : (
                                                        event.title
                                                    )}
                                                </div>
                                                {event.body ? (
                                                    <div className="mt-0.5 text-sm text-neutral-600 whitespace-pre-wrap">
                                                        {event.body}
                                                    </div>
                                                ) : null}
                                            </div>
                                        ))}
                                    </div>
                                </>
                            ))}
                            {section(t('admin.customers.add_desk_note', 'Add staff note'), (
                                <>
                                    <Field label={t('admin.customers.desk_note', 'Note')}>
                                        <Textarea
                                            maxLength={5000}
                                            value={deskNote}
                                            onChange={(e) => setDeskNote(e.target.value)}
                                            placeholder={t(
                                                'admin.customers.desk_note_placeholder',
                                                'Log a support call, escalation, or follow-up…',
                                            )}
                                        />
                                        <div className={ui.metaK}>{(deskNote || '').length} / 5000</div>
                                    </Field>
                                    <label className={cx(ui.row, 'items-center gap-2 mt-3 text-sm text-neutral-700')}>
                                        <input
                                            type="checkbox"
                                            checked={deskNotify}
                                            onChange={(e) => setDeskNotify(e.target.checked)}
                                        />
                                        {t('admin.customers.notify_customer', 'Email this note to the customer')}
                                    </label>
                                    <div className="mt-3">
                                        <Button
                                            type="button"
                                            icon="check"
                                            busy={deskBusy}
                                            disabled={!String(deskNote || '').trim()}
                                            onClick={submitDeskNote}
                                        >
                                            {t('admin.customers.save_desk_note', 'Save desk note')}
                                        </Button>
                                    </div>
                                </>
                            ))}
                        </>
                    )}

                    {tab === 'notes' && section(t('admin.customers.profile_notes', 'Profile notes'), (
                        <Field
                            label={t('admin.customers.profile_notes', 'Profile notes')}
                            hint={t(
                                'admin.customers.profile_notes_hint',
                                'Internal memo saved on the customer record. Use Desk for dated staff notes and activity.',
                            )}
                        >
                            <Textarea maxLength={500} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder={t('admin.customers.add_notes_about_this_customer', 'Add notes about this customer...')} />
                            <div className={ui.metaK}>{(form.data.notes || '').length} / 500</div>
                        </Field>
                    ))}
                </div>

                <aside className={ui.formSideUnderStickyHead}>
                    {section('Customer Summary', (
                        <>
                            <div className={ui.stack}>
                                <Avatar
                                    name={`${form.data.first_name} ${form.data.last_name}`.trim() || customer?.email}
                                    src={preview}
                                    className={ui.avatarLg}
                                />
                                <Button type="button" variant="secondary" icon="upload" onClick={() => photoRef.current?.click()}>{t('admin.customers.add_photo', 'Add Photo')}</Button>
                                <input ref={photoRef} type="file" accept="image/*" className={ui.srOnly} onChange={onPhoto} />
                            </div>
                            <div className={ui.summaryRow}><span>{t('admin.customers.total_orders', 'Total Orders')}</span><strong>{customer?.orders_count ?? 0}</strong></div>
                            <div className={ui.summaryRow}><span>{t('admin.customers.total_spent', 'Total Spent')}</span><strong>{money(customer?.total_spent || 0, currency)}</strong></div>
                            <div className={ui.summaryRow}><span>{t('admin.customers.average_order_value', 'Average Order Value')}</span><strong>{money(customer?.aov || 0, currency)}</strong></div>
                            <div className={ui.summaryRow}><span>{t('admin.customers.customer_since', 'Customer Since')}</span><strong>{formatDate(customer?.created_at)}</strong></div>
                            <div className={ui.summaryRow}><span>{t('admin.customers.last_order', 'Last Order')}</span><strong>{formatDate(customer?.last_order_at)}</strong></div>
                            <div className={cx(ui.row, ui.blockGap, 'flex-wrap')}>
                                <Badge tone={form.data.is_enabled ? 'success' : 'danger'}>{form.data.is_enabled ? t('admin.common.active', 'Active') : t('admin.common.inactive', 'Inactive')}</Badge>
                                {assignedGroup ? (
                                    <Badge tone={assignedGroup.color || 'primary'}>{assignedGroup.name}</Badge>
                                ) : null}
                            </div>
                            <div className={ui.summaryActions}>
                                {editing ? (
                                    <Button
                                        variant="secondary"
                                        className="w-full justify-center"
                                        href={`/admin/addresses?customer_id=${customer.id}`}
                                    >
                                        {t('admin.customers.manage_addresses', 'Manage addresses')}
                                    </Button>
                                ) : null}
                                <Button
                                    variant="secondary"
                                    className="w-full justify-center"
                                    href={assignedGroup?.id
                                        ? `/admin/customer-groups?edit=${assignedGroup.id}`
                                        : '/admin/customer-groups'}
                                >
                                    {t('admin.customers.manage_groups', 'Manage groups')}
                                </Button>
                            </div>
                        </>
                    ))}
                </aside>
            </div>
                </div>
            </form>
        </Layout>
    );
}
