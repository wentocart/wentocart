import { useForm } from '@inertiajs/react';
import Layout from '../Layout';
import {
    Alert,
    Button,
    Card,
    Checkbox,
    Field,
    FormErrors,
    Icon,
    Input,
    PageHeader,
    Textarea,
    cx,
    t,
    ui,
} from '../../ui';

function scopeGroup(id) {
    if (id.startsWith('catalog.')) {
        return t('admin.ops_tools.group_products', 'Products');
    }
    if (id.startsWith('orders.')) {
        return t('admin.ops_tools.group_orders', 'Orders');
    }
    if (id.startsWith('customers.')) {
        return t('admin.ops_tools.group_customers', 'Customers');
    }
    if (id === 'webhooks.manage') {
        return t('admin.ops_tools.ws_group_webhooks', 'Webhooks');
    }

    return t('admin.ops_tools.ws_group_shop', 'Shop');
}

function groupedScopes(scopes) {
    const groups = [];
    scopes.forEach((row) => {
        const name = scopeGroup(row.id);
        const last = groups[groups.length - 1];
        if (!last || last.name !== name) {
            groups.push({ name, rows: [row] });
            return;
        }
        last.rows.push(row);
    });
    return groups;
}

function KeyHelp() {
    const steps = [
        {
            icon: 'file',
            title: t('admin.ops_tools.key_help_name', 'Give it a name'),
            hint: t('admin.ops_tools.key_help_name_hint', 'Use the partner or app name so you can find this key later.'),
        },
        {
            icon: 'lock',
            title: t('admin.ops_tools.key_help_access', 'Tick only what they need'),
            hint: t('admin.ops_tools.key_help_access_hint', 'Read is enough for most apps. Tick write only if they must change products, orders, or customers.'),
        },
        {
            icon: 'globe',
            title: t('admin.ops_tools.key_help_optional', 'Lock it down (optional)'),
            hint: t('admin.ops_tools.key_help_optional_hint', 'Paste their server IPs and a date if this access should stop automatically.'),
        },
        {
            icon: 'check',
            title: t('admin.ops_tools.key_help_copy', 'Copy the key once'),
            hint: t('admin.ops_tools.key_help_copy_hint', 'After you save, the key appears once on the list. We cannot show it again.'),
        },
    ];

    return (
        <aside className="space-y-4">
            <div className={ui.helpCard}>
                <div className="relative z-10 space-y-4">
                    <div className="flex items-start gap-3">
                        <span className={ui.sectionHeadIcon}>
                            <Icon name="lock" className="h-5 w-5" />
                        </span>
                        <div className="min-w-0">
                            <p className={ui.sectionKicker}>{t('admin.ops_tools.key_help_kicker', 'How this works')}</p>
                            <h2 className={cx(ui.sectionTitle, 'mb-0 mt-1')}>
                                {t('admin.ops_tools.key_help_title', 'One key for one partner')}
                            </h2>
                            <p className={cx(ui.muted, 'mt-1')}>
                                {t('admin.ops_tools.key_help_body', 'Their developer keeps this key on their server and uses it to read or update your shop. Do not put it in a public app that customers download.')}
                            </p>
                        </div>
                    </div>
                    <div className="mx-auto grid h-28 w-full max-w-[11rem] place-items-center rounded-2xl bg-gradient-to-br from-violet-100 via-white to-sky-50" aria-hidden="true">
                        <span className="grid h-16 w-16 place-items-center rounded-2xl bg-white text-violet-600 shadow-sm ring-1 ring-violet-100">
                            <Icon name="lock" className="h-8 w-8" />
                        </span>
                    </div>
                    <ol className="space-y-3">
                        {steps.map((step, index) => (
                            <li key={step.title} className="flex items-start gap-3">
                                <span className={ui.tipCheck}>{index + 1}</span>
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-neutral-900">{step.title}</p>
                                    <p className={cx(ui.muted, 'mt-0.5')}>{step.hint}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                </div>
            </div>
            <Alert type="warning">
                {t('admin.ops_tools.key_safety', 'Do not put this key inside a public app that customers download. Their developer should keep it on their own server.')}
            </Alert>
        </aside>
    );
}

export default function KeyCreate({ scopes = [] }) {
    const form = useForm({
        name: '',
        scopes: ['shop.read', 'catalog.read', 'orders.read'],
        allowed_ips: '',
        expires_at: '',
    });
    const groups = groupedScopes(scopes);

    const toggleScope = (id) => {
        const current = form.data.scopes || [];
        form.setData('scopes', current.includes(id) ? current.filter((row) => row !== id) : [...current, id]);
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.ops_tools.create_ws_key', 'Create Webservice Key')}
                description={t('admin.ops_tools.create_ws_key_hint', 'Name the partner, tick the access they need, then save. Copy the key as soon as it appears.')}
                crumbs={[
                    { href: '/admin/ops-tools?tab=keys', label: t('admin.ops_tools.title', 'Webhooks') },
                    { href: '/admin/ops-tools?tab=keys', label: t('admin.ops_tools.keys', 'Web Services') },
                    { label: t('admin.ops_tools.create_ws_key', 'Create Webservice Key') },
                ]}
            />
            <div className={ui.formLayoutSplit}>
                <Card className="min-w-0 space-y-4">
                    <FormErrors errors={form.errors} />
                    <Field
                        label={t('admin.common.name', 'Name')}
                        hint={t('admin.ops_tools.key_name_hint', 'Example: Accounting app, warehouse, or mobile team.')}
                    >
                        <Input
                            wide
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder={t('admin.ops_tools.key_name_placeholder', 'Accounting app')}
                        />
                    </Field>
                    <Field
                        label={t('admin.ops_tools.scopes', 'What they can access')}
                        hint={t('admin.ops_tools.scopes_hint', 'Leave write boxes off unless they must change records in this shop.')}
                    >
                        <div className="space-y-4">
                            {groups.map((group) => (
                                <div key={group.name} className="space-y-2">
                                    <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500">{group.name}</p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {group.rows.map((row) => (
                                            <Checkbox
                                                key={row.id}
                                                size="lg"
                                                className="rounded-xl border border-neutral-200 bg-neutral-50/80 px-3 py-2.5 items-start"
                                                label={(
                                                    <span className="min-w-0">
                                                        <span className="block font-medium text-neutral-900">{row.label}</span>
                                                        <span className={ui.muted}>{row.hint}</span>
                                                    </span>
                                                )}
                                                checked={(form.data.scopes || []).includes(row.id)}
                                                onChange={() => toggleScope(row.id)}
                                            />
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Field>
                    <Field label={t('admin.ops_tools.allowed_ips', 'Only these computers (optional)')} hint={t('admin.ops_tools.ip_hint', 'Leave blank to allow any computer. Their developer can paste IP addresses here.')}>
                        <Textarea rows={3} value={form.data.allowed_ips} onChange={(e) => form.setData('allowed_ips', e.target.value)} />
                    </Field>
                    <Field label={t('admin.ops_tools.expires', 'Expires (optional)')} hint={t('admin.ops_tools.expires_hint', 'Leave blank if this key should stay valid until you revoke it.')}>
                        <Input wide type="date" value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} />
                    </Field>
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" busy={form.processing} onClick={() => form.post('/admin/ops-tools/keys')}>
                            {t('admin.ops_tools.create_ws_key', 'Create Webservice Key')}
                        </Button>
                        <Button type="button" variant="secondary" href="/admin/ops-tools?tab=keys">
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                    </div>
                </Card>
                <KeyHelp />
            </div>
        </Layout>
    );
}
