import { router, useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Button,
    Checkbox,
    ColorField,
    Field,
    FieldError,
    FormErrors,
    Input,
    PageHeader,
    Select,
    Textarea,
    ui,
    t,
} from '../../ui';

export default function Form({
    stage,
    mailTemplates = [],
    moneyMeanings = [],
    fulfillmentMeanings = [],
    profiles = [],
}) {
    const form = useForm({
        name: stage?.name || '',
        buyer_label: stage?.buyer_label || '',
        buyer_note: stage?.buyer_note || '',
        tone: stage?.tone || '#737373',
        money_meaning: stage?.money_meaning || 'none',
        fulfillment_meaning: stage?.fulfillment_meaning || 'none',
        notify_buyer: stage?.notify_buyer ?? true,
        mail_template: stage?.mail_template || 'order_status',
        unlock_invoice: stage?.unlock_invoice ?? false,
        unlock_credit_slip: stage?.unlock_credit_slip ?? false,
        unlock_delivery_slip: stage?.unlock_delivery_slip ?? false,
        is_enabled: stage?.is_enabled ?? true,
        profile_ids: (stage?.profile_ids || []).map(Number),
    });

    const toggleProfile = (id) => {
        const current = form.data.profile_ids.map(Number);
        const next = current.includes(Number(id))
            ? current.filter((value) => value !== Number(id))
            : [...current, Number(id)];
        form.setData('profile_ids', next);
    };

    const submit = (e) => {
        e.preventDefault();
        if (stage) {
            form.put(`/admin/order-stages/${stage.id}`);
        } else {
            form.post('/admin/order-stages');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={stage ? t('admin.order_stages.edit_status', 'Edit order status') : t('admin.order_stages.add_status', 'Add order status')}
                crumbs={[
                    { href: '/admin/orders', label: t('admin.orders.orders', 'Orders') },
                    { href: '/admin/order-stages', label: t('admin.nav.order_statuses', 'Order statuses') },
                    { label: stage ? t('admin.common.edit', 'Edit') : t('admin.common.add_new', 'Add new') },
                ]}
                actions={
                    stage ? (
                        <Button
                            type="button"
                            variant="danger"
                            onClick={() => {
                                if (stage.is_system) {
                                    window.alert(t('admin.order_stages.system_cannot_delete', 'System stages cannot be deleted. Disable them instead.'));
                                    return;
                                }
                                if (window.confirm(t('admin.order_stages.delete_this_status', 'Delete this order status?'))) {
                                    router.delete(`/admin/order-stages/${stage.id}`);
                                }
                            }}
                        >
                            {t('admin.common.delete', 'Delete')}
                        </Button>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayout}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.order_stages.basics', 'Basics')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field
                                label={t('admin.order_stages.staff_name', 'Status name (Internal / Staff)')}
                                hint={t('admin.order_stages.staff_name_hint', 'Used internally in admin backoffice.')}
                                error={form.errors.name}
                            >
                                <Input
                                    wide
                                    invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.order_stages.e_g_packed', 'e.g. Packed')}
                                />
                            </Field>
                            <Field
                                label={t('admin.order_stages.customer_name', 'Customer display label')}
                                hint={t('admin.order_stages.customer_name_hint', 'Shown to buyers on customer account, emails, and tracking.')}
                                error={form.errors.buyer_label}
                            >
                                <Input
                                    wide
                                    invalid={!!form.errors.buyer_label}
                                    value={form.data.buyer_label}
                                    onChange={(e) => form.setData('buyer_label', e.target.value)}
                                    placeholder={t('admin.order_stages.e_g_your_order_is_packed', 'e.g. Your order is packed')}
                                />
                            </Field>
                            <Field label={t('admin.order_stages.color', 'Color')} error={form.errors.tone}>
                                <ColorField value={form.data.tone} onChange={(tone) => form.setData('tone', tone)} fallback="#737373" />
                            </Field>
                            <div>
                                <label className={ui.label}>{t('admin.order_stages.when_to_use', 'When to use')}</label>
                                <Textarea
                                    className="mt-1 min-h-24 block w-full border border-neutral-200 rounded-lg px-3 py-2 text-sm bg-white resize-y"
                                    rows="3"
                                    maxLength={255}
                                    invalid={!!form.errors.buyer_note}
                                    value={form.data.buyer_note}
                                    onChange={(e) => form.setData('buyer_note', e.target.value)}
                                    placeholder={t('admin.order_stages.when_to_use_hint', 'Short note for your team')}
                                />
                                <FieldError>{form.errors.buyer_note}</FieldError>
                            </div>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.order_stages.this_step_means', 'This step means')}</h2>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label={t('admin.order_stages.money', 'Money')} error={form.errors.money_meaning}>
                                <Select
                                    wide
                                    value={form.data.money_meaning}
                                    onChange={(e) => form.setData('money_meaning', e.target.value)}
                                >
                                    {moneyMeanings.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </Select>
                            </Field>
                            <Field label={t('admin.order_stages.fulfillment', 'Fulfillment')} error={form.errors.fulfillment_meaning}>
                                <Select
                                    wide
                                    value={form.data.fulfillment_meaning}
                                    onChange={(e) => form.setData('fulfillment_meaning', e.target.value)}
                                >
                                    {fulfillmentMeanings.map((opt) => (
                                        <option key={opt.value} value={opt.value}>{opt.label}</option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.order_stages.email', 'Email')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Checkbox
                                label={t('admin.order_stages.send_email_to_customer', 'Send email to customer')}
                                checked={!!form.data.notify_buyer}
                                onChange={(e) => form.setData('notify_buyer', e.target.checked)}
                            />
                            {form.data.notify_buyer ? (
                                <Field label={t('admin.order_stages.mail_template', 'Mail template')} error={form.errors.mail_template}>
                                    <Select
                                        wide
                                        value={form.data.mail_template || 'order_status'}
                                        onChange={(e) => form.setData('mail_template', e.target.value)}
                                    >
                                        {mailTemplates.map((opt) => (
                                            <option key={opt.value} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </Select>
                                </Field>
                            ) : null}
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.order_stages.documents', 'Documents')}</h2>
                        </div>
                        <div className="grid gap-2">
                            <Checkbox
                                label={t('admin.order_stages.generate_pdf', 'Generate PDF (invoice)')}
                                checked={!!form.data.unlock_invoice}
                                onChange={(e) => form.setData('unlock_invoice', e.target.checked)}
                            />
                            <Checkbox
                                label={t('admin.order_stages.generate_delivery_slip', 'Generate delivery slip')}
                                checked={!!form.data.unlock_delivery_slip}
                                onChange={(e) => form.setData('unlock_delivery_slip', e.target.checked)}
                            />
                            <Checkbox
                                label={t('admin.order_stages.unlock_credit_slip', 'Unlock credit slip')}
                                checked={!!form.data.unlock_credit_slip}
                                onChange={(e) => form.setData('unlock_credit_slip', e.target.checked)}
                            />
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.order_stages.access_to', 'Access to')}</h2>
                        </div>
                        <p className={`${ui.muted} mb-3`}>
                            {t(
                                'admin.order_stages.access_hint',
                                'Leave all unchecked so every employee who can manage orders can use this status. Tick profiles to limit who can switch an order to it. Super admins always see every status.',
                            )}
                        </p>
                        <Field label={t('admin.order_stages.allowed_profiles', 'Allowed staff roles / Profiles')} error={form.errors.profile_ids}>
                            {profiles.length ? (
                                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                    {profiles.map((profile) => {
                                        const checked = form.data.profile_ids.map(Number).includes(Number(profile.id));
                                        return (
                                            <div key={profile.id} className={checked ? ui.choiceCardActive : ui.choiceCard}>
                                                <Checkbox
                                                    label={profile.name}
                                                    checked={checked}
                                                    onChange={() => toggleProfile(profile.id)}
                                                />
                                                {profile.description ? (
                                                    <p className={`${ui.muted} mt-1 pl-6`}>{profile.description}</p>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <p className={`${ui.muted} mt-2`}>
                                    {t('admin.order_stages.no_profiles', 'No employee profiles yet. Create one under Staff → Profiles.')}
                                </p>
                            )}
                        </Field>
                    </section>
                </div>

                <aside className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <Checkbox
                            label={t('admin.common.enabled', 'Enabled')}
                            checked={!!form.data.is_enabled}
                            onChange={(e) => form.setData('is_enabled', e.target.checked)}
                        />
                        <div className={`${ui.actionList} mt-4`}>
                            <Button type="submit" icon="check" busy={form.processing}>
                                {stage ? t('admin.common.save', 'Save') : t('admin.common.create', 'Create')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/order-stages">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </aside>
            </form>
        </Layout>
    );
}
