import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import Layout from '../Layout';
import {
    Alert,
    Badge,
    Button,
    Card,
    Checkbox,
    EmptyState,
    Field,
    FormErrors,
    Icon,
    Input,
    PageHeader,
    Select,
    StatusTabs,
    StatusToggle,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    t,
} from '../../ui';
import ConnectGuide from './ConnectGuide';
import WebhookLogs from './WebhookLogs';

function copyText(value) {
    const text = value == null ? '' : String(value);
    if (!text || !navigator?.clipboard?.writeText) {
        return;
    }
    navigator.clipboard.writeText(text).catch(() => {});
}

const GROUP_ORDER = ['Orders', 'Catalog', 'Customers', 'Content', 'Marketing', 'Checkout', 'System'];

function groupMeta(group) {
    const rows = {
        Orders: {
            label: t('admin.ops_tools.group_orders', 'Orders'),
            hint: t('admin.ops_tools.group_orders_hint', 'Placed, paid, refunded, cancelled, and returns.'),
            icon: 'bag',
            tone: ui.statIconPrimary,
            badge: 'primary',
        },
        Catalog: {
            label: t('admin.ops_tools.group_products', 'Products'),
            hint: t('admin.ops_tools.group_products_hint', 'Products, stock, categories, brands, tags, and suppliers.'),
            icon: 'package',
            tone: ui.statIconSuccess,
            badge: 'success',
        },
        Customers: {
            label: t('admin.ops_tools.group_customers', 'Customers'),
            hint: t('admin.ops_tools.group_customers_hint', 'Accounts, addresses, and reviews.'),
            icon: 'users',
            tone: ui.statIconPrimary,
            badge: 'primary',
        },
        Content: {
            label: t('admin.ops_tools.group_content', 'Content'),
            hint: t('admin.ops_tools.group_content_hint', 'Store pages.'),
            icon: 'file',
            tone: ui.statIconInfo,
            badge: 'info',
        },
        Marketing: {
            label: t('admin.ops_tools.group_marketing', 'Marketing'),
            hint: t('admin.ops_tools.group_marketing_hint', 'Discounts and coupons.'),
            icon: 'tag',
            tone: ui.statIconDanger,
            badge: 'danger',
        },
        Checkout: {
            label: t('admin.ops_tools.group_checkout', 'Checkout'),
            hint: t('admin.ops_tools.group_checkout_hint', 'Carts. Off until you enable them — they can be frequent.'),
            icon: 'bag',
            tone: ui.statIconWarning,
            badge: 'warning',
        },
        System: {
            label: t('admin.ops_tools.group_system', 'System'),
            hint: t('admin.ops_tools.group_system_hint', 'Test ping from this page.'),
            icon: 'settings',
            tone: ui.statIconWarning,
            badge: 'warning',
        },
    };

    return rows[group] || { label: group, hint: '', icon: 'layers', tone: ui.statIconInfo, badge: 'info' };
}

function orderedGroupNames(groups) {
    const names = Object.keys(groups);
    return [...GROUP_ORDER.filter((name) => names.includes(name)), ...names.filter((name) => !GROUP_ORDER.includes(name))];
}

function toggleOpenGroup(setOpen, group) {
    setOpen((cur) => {
        const next = new Set(cur);
        if (next.has(group)) {
            next.delete(group);
        } else {
            next.add(group);
        }
        return next;
    });
}

function scrollToId(id) {
    window.setTimeout(() => {
        const el = document.getElementById(id);
        if (!el) {
            return;
        }
        const main = el.closest('main') || document.querySelector('main');
        if (main) {
            const top = el.getBoundingClientRect().top - main.getBoundingClientRect().top + main.scrollTop - 12;
            main.scrollTo({ top: Math.max(0, top), behavior: 'smooth' });
            return;
        }
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 50);
}

export default function Tools({
    tab = 'webhooks',
    catalog = {},
    webhooks = [],
    deliveries = [],
    events = [],
    keys = [],
    revealed = {},
    shopUrl = '',
}) {
    void shopUrl;
    const eventList = catalog.events || [];
    const scopeList = catalog.scopes || [];
    const hook = useForm({
        name: '',
        url: '',
        events: ['order.placed', 'order.updated'],
        is_enabled: true,
    });
    const testPing = useForm({ webhook_id: String(webhooks[0]?.id || ''), event: 'order.placed' });
    const [copied, setCopied] = useState('');
    const [eventQ, setEventQ] = useState('');
    const [openGroups, setOpenGroups] = useState(() => new Set());
    const [openNotify, setOpenNotify] = useState(() => new Set());

    useEffect(() => {
        if (!testPing.data.webhook_id && webhooks[0]?.id) {
            testPing.setData('webhook_id', String(webhooks[0].id));
        }
    }, [webhooks]);

    const groupedEvents = useMemo(() => {
        const groups = {};
        eventList.forEach((row) => {
            groups[row.group] = groups[row.group] || [];
            groups[row.group].push(row);
        });
        return groups;
    }, [eventList]);

    const visibleEvents = useMemo(() => {
        const q = eventQ.trim().toLowerCase();
        return eventList.filter((row) => {
            if (!q) {
                return true;
            }
            const hay = `${row.label} ${row.hint || ''} ${row.group} ${row.id}`.toLowerCase();
            return hay.includes(q);
        });
    }, [eventList, eventQ]);

    const visibleGrouped = useMemo(() => {
        const groups = {};
        visibleEvents.forEach((row) => {
            groups[row.group] = groups[row.group] || [];
            groups[row.group].push(row);
        });
        return groups;
    }, [visibleEvents]);

    useEffect(() => {
        const q = eventQ.trim().toLowerCase();
        if (!q) {
            return;
        }
        const names = new Set();
        eventList.forEach((row) => {
            const hay = `${row.label} ${row.hint || ''} ${row.group} ${row.id}`.toLowerCase();
            if (hay.includes(q)) {
                names.add(row.group);
            }
        });
        setOpenGroups(names);
    }, [eventQ, eventList]);

    const goTab = (next) => router.get('/admin/ops-tools', { tab: next }, { preserveState: true, preserveScroll: true, replace: true });

    const toggleEvent = (id) => {
        const current = hook.data.events || [];
        if (id === '*') {
            hook.setData('events', current.includes('*') ? [] : ['*']);
            return;
        }
        const next = current.filter((row) => row !== '*');
        hook.setData('events', next.includes(id) ? next.filter((row) => row !== id) : [...next, id]);
    };

    const toggleEventGroup = (ids) => {
        const current = hook.data.events || [];
        if (current.includes('*')) {
            return;
        }
        const allOn = ids.every((id) => current.includes(id));
        hook.setData(
            'events',
            allOn ? current.filter((id) => !ids.includes(id)) : [...new Set([...current, ...ids])],
        );
    };

    const copy = (value, id) => {
        copyText(value);
        setCopied(id);
    };

    const scopeLabel = (id) => (scopeList.find((row) => row.id === id)?.label || id);
    const eventLabel = (id) => (eventList.find((row) => row.id === id)?.label || (id === '*' ? t('admin.ops_tools.all_events', 'All events') : id));

    const openWebhookTry = (row) => {
        testPing.setData('event', row.id);
        const current = hook.data.events || [];
        if (!current.includes('*') && !current.includes(row.id) && row.id !== 'ops.ping') {
            hook.setData('events', [...current, row.id]);
        }
        scrollToId('ops-test-webhook');
    };

    const sendTestWebhook = () => {
        const id = testPing.data.webhook_id;
        if (!id) {
            return;
        }
        router.post(`/admin/ops-tools/webhooks/${id}/ping`, {}, { preserveScroll: true });
    };

    const header = {
        webhooks: {
            title: t('admin.ops_tools.webhooks', 'Webhook Events'),
            description: t('admin.ops_tools.hint', 'Send real-time store events to external apps and services. Choose the events you want to track, add a webhook destination, and test the connection before going live.'),
        },
        keys: {
            title: t('admin.ops_tools.keys', 'Web Services'),
            description: t('admin.ops_tools.keys_hint', 'Create an access key so a partner app can read or update this shop. Tick only what they need, copy the key once, then try a sample fetch.'),
        },
        deliveries: {
            title: t('admin.ops_tools.deliveries', 'Webhook Logs'),
            description: t('admin.ops_tools.logs_hint', 'Each row is one message we sent to a saved destination. Open a log to see the payload and their reply. Retry a failed send from here.'),
        },
        docs: {
            title: t('admin.ops_tools.docs', 'Helps'),
            description: t('admin.ops_tools.docs_hint', 'How to connect this shop to another app, what to fill in, and how to keep keys and webhook secrets safe.'),
        },
    }[tab] || {
        title: t('admin.ops_tools.webhooks', 'Webhook Events'),
        description: t('admin.ops_tools.hint', 'Send real-time store events to external apps and services. Choose the events you want to track, add a webhook destination, and test the connection before going live.'),
    };

    return (
        <Layout>
            <PageHeader
                title={header.title}
                description={header.description}
                actions={tab === 'keys' ? (
                    <div className={ui.toolbar}>
                        <Button href="/admin/ops-tools/webservices" variant="secondary">
                            {t('admin.ops_tools.view_webservices', 'View Webservices')}
                        </Button>
                        <Button href="/admin/ops-tools/keys/create">
                            {t('admin.ops_tools.create_service_key', 'Create Service Key')}
                        </Button>
                    </div>
                ) : null}
            />
            <StatusTabs
                className="mb-4"
                value={tab}
                onChange={goTab}
                tabs={[
                    { key: 'webhooks', label: t('admin.ops_tools.webhooks', 'Webhook Events') },
                    { key: 'keys', label: t('admin.ops_tools.keys', 'Web Services') },
                    { key: 'deliveries', label: t('admin.ops_tools.deliveries', 'Webhook Logs') },
                    { key: 'docs', label: t('admin.ops_tools.docs', 'Helps') },
                ]}
            />

            {revealed?.webhook_secret ? (
                <Alert type="warning" className="mb-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="font-medium">{t('admin.ops_tools.secret_once', 'Copy this signing secret now — it will not be shown again')}</p>
                            <code className="break-all text-sm">{revealed.webhook_secret}</code>
                        </div>
                        <Button type="button" variant="secondary" onClick={() => copy(revealed.webhook_secret, 'hook')}>
                            {copied === 'hook' ? t('admin.common.copied', 'Copied') : t('admin.common.copy', 'Copy')}
                        </Button>
                    </div>
                </Alert>
            ) : null}
            {revealed?.api_key ? (
                <Alert type="warning" className="mb-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <p className="font-medium">{t('admin.ops_tools.key_once', 'Copy this access key now — it will not be shown again')}</p>
                            <code className="break-all text-sm">{revealed.api_key}</code>
                        </div>
                        <Button type="button" variant="secondary" onClick={() => copy(revealed.api_key, 'key')}>
                            {copied === 'key' ? t('admin.common.copied', 'Copied') : t('admin.common.copy', 'Copy')}
                        </Button>
                    </div>
                </Alert>
            ) : null}

            {tab === 'webhooks' ? (
                <div className="space-y-4">
                    <Alert type="info">
                        {t('admin.ops_tools.webhook_notice', 'Choose which store events you want to send to your webhook destinations. Open a group to manage its events. New events stay disabled until you enable them.')}
                    </Alert>

                    <div className={ui.filterBar}>
                        <label className={ui.filterSearch}>
                            <span className={ui.filterSearchIcon}><Icon name="search" /></span>
                            <input
                                className={ui.filterSearchInput}
                                value={eventQ}
                                onChange={(e) => setEventQ(e.target.value)}
                                placeholder={t('admin.ops_tools.search_events', 'Search events')}
                            />
                        </label>
                        <Button type="button" variant="ghost" onClick={() => setOpenGroups(new Set(orderedGroupNames(visibleGrouped)))}>
                            {t('admin.ops_tools.expand_all', 'Expand all')}
                        </Button>
                        <Button type="button" variant="ghost" onClick={() => setOpenGroups(new Set())}>
                            {t('admin.ops_tools.collapse_all', 'Collapse all')}
                        </Button>
                    </div>

                    <Card padded={false}>
                        {orderedGroupNames(visibleGrouped).length ? orderedGroupNames(visibleGrouped).map((group) => {
                            const meta = groupMeta(group);
                            const rows = visibleGrouped[group] || [];
                            const open = openGroups.has(group);
                            const enabledCount = rows.filter((row) => row.enabled).length;

                            return (
                                <div key={group} className="border-b border-neutral-200 last:border-b-0">
                                    <button
                                        type="button"
                                        className={open ? ui.groupAccordionBtnOpen : ui.groupAccordionBtn}
                                        aria-expanded={open}
                                        onClick={() => toggleOpenGroup(setOpenGroups, group)}
                                    >
                                        <span className={ui.panelIntroMain}>
                                            <span className={`${ui.statIcon} ${meta.tone}`}>
                                                <Icon name={meta.icon} />
                                            </span>
                                            <span className={ui.cellStack}>
                                                <span className={ui.cellStrong}>{meta.label}</span>
                                                <span className={ui.cellSub}>{meta.hint}</span>
                                            </span>
                                        </span>
                                        <span className={ui.row}>
                                            <Badge tone={meta.badge} dot={false}>
                                                {enabledCount}
                                                {' '}
                                                {t('admin.ops_tools.enabled_count', 'Enabled')}
                                            </Badge>
                                            <Icon name={open ? 'chevron-down' : 'chevron-right'} />
                                        </span>
                                    </button>
                                    {open ? (
                                        <div className={ui.groupAccordionBody}>
                                            <Table>
                                                <THead>
                                                    <Th>{t('admin.ops_tools.event_name', 'Event')}</Th>
                                                    <Th>{t('admin.ops_tools.available_data', 'What we send')}</Th>
                                                    <Th col="status">{t('admin.common.status', 'Status')}</Th>
                                                    <Th></Th>
                                                </THead>
                                                <tbody>
                                                    {rows.map((row) => (
                                                        <Tr key={row.id}>
                                                            <Td>
                                                                <div className={ui.cellStrong}>{row.label}</div>
                                                                <div className={ui.cellSub}>{row.hint}</div>
                                                            </Td>
                                                            <Td>
                                                                <div className={ui.muted}>{(row.data || []).join(', ') || '—'}</div>
                                                            </Td>
                                                            <Td col="status">
                                                                <StatusToggle
                                                                    active={!!row.enabled}
                                                                    onToggle={() => router.post('/admin/ops-tools/events/toggle', { event: row.id }, { preserveScroll: true })}
                                                                />
                                                            </Td>
                                                            <Td>
                                                                <Button type="button" variant="secondary" icon="play" onClick={() => openWebhookTry(row)}>
                                                                    {t('admin.ops_tools.try', 'Try')}
                                                                </Button>
                                                            </Td>
                                                        </Tr>
                                                    ))}
                                                </tbody>
                                            </Table>
                                        </div>
                                    ) : null}
                                </div>
                            );
                        }) : (
                            <p className={`${ui.empty} p-5`}>{t('admin.ops_tools.no_events', 'No events match that search.')}</p>
                        )}
                    </Card>

                    <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <Card className="space-y-3">
                            <h2 className={ui.sectionTitle}>{t('admin.ops_tools.add_webhook', 'Add a destination')}</h2>
                            <p className={ui.sectionLead}>
                                {t(
                                    'admin.ops_tools.add_webhook_help',
                                    'Enter the HTTPS URL where you want to receive webhook notifications. When a selected event occurs, we\'ll securely send its data to this URL. Only events that are enabled and selected for this destination will be sent.',
                                )}
                            </p>
                            <FormErrors errors={hook.errors} />
                            <Field label={t('admin.common.name', 'Name')}>
                                <Input wide value={hook.data.name} onChange={(e) => hook.setData('name', e.target.value)} />
                            </Field>
                            <Field label={t('admin.ops_tools.https_url', 'Secure Web URL')} hint={t('admin.ops_tools.https_only', 'Must be a public https address. Local and private addresses are blocked for safety.')}>
                                <Input wide value={hook.data.url} onChange={(e) => hook.setData('url', e.target.value)} />
                            </Field>
                            <Field label={t('admin.ops_tools.events', 'Notify them when')} hint={t('admin.ops_tools.events_hint', 'Open a group and tick the events this URL should receive.')}>
                                <div className="space-y-2">
                                    <Checkbox
                                        label={t('admin.ops_tools.all_events', 'All events')}
                                        checked={(hook.data.events || []).includes('*')}
                                        onChange={() => toggleEvent('*')}
                                    />
                                    {orderedGroupNames(groupedEvents).map((group) => {
                                        const meta = groupMeta(group);
                                        const rows = groupedEvents[group] || [];
                                        const ids = rows.map((row) => row.id);
                                        const selected = hook.data.events || [];
                                        const allStar = selected.includes('*');
                                        const groupOn = allStar || ids.every((id) => selected.includes(id));
                                        const open = openNotify.has(group);

                                        return (
                                            <div key={group} className="rounded-xl border border-neutral-200">
                                                <div className={`${ui.row} justify-between gap-3 px-5 py-4`}>
                                                    <button
                                                        type="button"
                                                        className={`${ui.navParent} flex-1 rounded-lg`}
                                                        aria-expanded={open}
                                                        onClick={() => toggleOpenGroup(setOpenNotify, group)}
                                                    >
                                                        <Icon name={open ? 'chevron-down' : 'chevron-right'} />
                                                        <span className={ui.cellStack}>
                                                            <span className={ui.cellStrong}>{meta.label}</span>
                                                            <span className={ui.cellSub}>{meta.hint}</span>
                                                        </span>
                                                    </button>
                                                    <Checkbox
                                                        label={t('admin.ops_tools.whole_group', 'All Groups')}
                                                        checked={groupOn}
                                                        disabled={allStar}
                                                        onChange={() => toggleEventGroup(ids)}
                                                    />
                                                </div>
                                                {open ? (
                                                    <div className="space-y-1 border-t border-neutral-200 px-5 py-4">
                                                        {rows.map((row) => (
                                                            <Checkbox
                                                                key={row.id}
                                                                label={row.enabled ? row.label : `${row.label} (${t('admin.common.off', 'Off')})`}
                                                                checked={allStar || selected.includes(row.id)}
                                                                disabled={allStar}
                                                                onChange={() => toggleEvent(row.id)}
                                                            />
                                                        ))}
                                                    </div>
                                                ) : null}
                                            </div>
                                        );
                                    })}
                                </div>
                            </Field>
                            <Button type="button" busy={hook.processing} onClick={() => hook.post('/admin/ops-tools/webhooks', { onSuccess: () => hook.reset() })}>
                                {t('admin.common.save', 'Save')}
                            </Button>
                        </Card>
                        <Card>
                            <h2 className={ui.sectionTitle}>{t('admin.ops_tools.endpoints', 'Saved destinations')}</h2>
                            <p className={`${ui.sectionLead} mb-3`}>
                                {t('admin.ops_tools.saved_webhook_help', 'Each saved URL receives only the events you chose for it. Pause a destination to stop all posts to that address.')}
                            </p>
                            <Table>
                                <THead>
                                    <Th>{t('admin.common.name', 'Name')}</Th>
                                    <Th>{t('admin.common.status', 'Status')}</Th>
                                    <Th></Th>
                                </THead>
                                <tbody>
                                    {webhooks.length ? webhooks.map((row) => (
                                        <Tr key={row.id}>
                                            <Td>
                                                {row.name}
                                                <div className={ui.muted}>{row.url}</div>
                                                <div className={ui.muted}>{(row.events || []).map(eventLabel).join(', ')}</div>
                                            </Td>
                                            <Td>
                                                <Badge tone={row.last_status === 'failed' || !row.is_enabled ? 'danger' : 'neutral'}>
                                                    {row.is_enabled ? (row.last_status || '—') : t('admin.common.off', 'Off')}
                                                </Badge>
                                                {row.last_error ? <div className={ui.muted}>{row.last_error}</div> : null}
                                                {row.secret_prefix ? <div className={ui.muted}>{row.secret_prefix}…</div> : null}
                                            </Td>
                                            <Td>
                                                <div className="flex flex-wrap gap-2">
                                                    <Button variant="secondary" type="button" onClick={() => router.post(`/admin/ops-tools/webhooks/${row.id}/toggle`)}>
                                                        {row.is_enabled ? t('admin.common.disable', 'Disable') : t('admin.common.enable', 'Enable')}
                                                    </Button>
                                                    <Button variant="secondary" type="button" onClick={() => router.post(`/admin/ops-tools/webhooks/${row.id}/ping`)}>
                                                        {t('admin.ops_tools.ping', 'Send test')}
                                                    </Button>
                                                    <Button variant="secondary" type="button" onClick={() => router.post(`/admin/ops-tools/webhooks/${row.id}/rotate`)}>
                                                        {t('admin.ops_tools.rotate', 'New secret')}
                                                    </Button>
                                                    <Button variant="danger" type="button" onClick={() => router.delete(`/admin/ops-tools/webhooks/${row.id}`)}>
                                                        {t('admin.common.delete', 'Delete')}
                                                    </Button>
                                                </div>
                                            </Td>
                                        </Tr>
                                    )) : (
                                        <EmptyState colSpan={3}>{t('admin.ops_tools.no_destinations', 'No destinations yet. Add one on the left.')}</EmptyState>
                                    )}
                                </tbody>
                            </Table>
                        </Card>
                    </div>

                    <Card id="ops-test-webhook" className="space-y-3 scroll-mt-24">
                        <h2 className={ui.sectionTitle}>{t('admin.ops_tools.test_title', 'Test webhook')}</h2>
                        <Alert type="info">
                            {t('admin.ops_tools.test_hint', 'Send a test message to a saved destination so their developer can confirm it arrives. Save a destination first if the list is empty.')}
                        </Alert>
                        <Field label={t('admin.ops_tools.select_event', 'Event')}>
                            <Select wide value={testPing.data.event} onChange={(e) => testPing.setData('event', e.target.value)}>
                                {eventList.map((row) => (
                                    <option key={row.id} value={row.id}>{row.label}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('admin.ops_tools.test_destination', 'Destination')}>
                            <Select wide value={testPing.data.webhook_id} onChange={(e) => testPing.setData('webhook_id', e.target.value)}>
                                {webhooks.length ? webhooks.map((row) => (
                                    <option key={row.id} value={row.id}>{row.name}</option>
                                )) : (
                                    <option value="">{t('admin.ops_tools.no_destinations_short', 'Save a destination first')}</option>
                                )}
                            </Select>
                        </Field>
                        <Button type="button" icon="send" disabled={!testPing.data.webhook_id} onClick={sendTestWebhook}>
                            {t('admin.ops_tools.send_test', 'Send test webhook')}
                        </Button>
                    </Card>

                    {events.length ? (
                        <Card>
                            <h2 className={ui.sectionTitle}>{t('admin.ops_tools.recent_events', 'Recent events')}</h2>
                            <Table>
                                <THead>
                                    <Th>{t('admin.ops_tools.event', 'Event')}</Th>
                                    <Th>{t('admin.activity.when', 'When')}</Th>
                                </THead>
                                <tbody>
                                    {events.map((event) => (
                                        <Tr key={event.id}>
                                            <Td>
                                                {eventLabel(event.name) === event.name ? event.name : eventLabel(event.name)}
                                                {event.operation_id ? (
                                                    <div className={ui.muted}>
                                                        <Link href={`/admin/activity/${event.operation_id}`}>#{event.operation_id}</Link>
                                                    </div>
                                                ) : null}
                                            </Td>
                                            <Td>{event.created_at}</Td>
                                        </Tr>
                                    ))}
                                </tbody>
                            </Table>
                        </Card>
                    ) : null}
                </div>
            ) : null}

            {tab === 'deliveries' ? (
                <WebhookLogs deliveries={deliveries} eventLabel={eventLabel} />
            ) : null}

            {tab === 'keys' ? (
                <Card>
                    <h2 className={ui.sectionTitle}>{t('admin.ops_tools.keys', 'Access keys')}</h2>
                    <Table>
                        <THead>
                            <Th>{t('admin.common.name', 'Name')}</Th>
                            <Th>{t('admin.ops_tools.scopes', 'What they can access')}</Th>
                            <Th></Th>
                        </THead>
                        <tbody>
                            {keys.length ? keys.map((row) => (
                                <Tr key={row.id}>
                                    <Td>
                                        {row.name}
                                        <div className={ui.muted}>{row.key_prefix}…</div>
                                        {row.last_used_at ? <div className={ui.muted}>{t('admin.ops_tools.last_used', 'Last used')}: {row.last_used_at} {row.last_ip || ''}</div> : null}
                                        {row.expires_at ? <div className={ui.muted}>{t('admin.ops_tools.expires', 'Expires')}: {row.expires_at}</div> : null}
                                    </Td>
                                    <Td>
                                        <Badge tone={row.is_enabled ? 'success' : 'neutral'}>
                                            {row.is_enabled ? t('admin.common.on', 'On') : t('admin.common.off', 'Off')}
                                        </Badge>
                                        <div className={ui.muted}>{(row.scopes || []).map(scopeLabel).join(', ')}</div>
                                    </Td>
                                    <Td>
                                        <div className="flex flex-wrap gap-2">
                                            <Button variant="secondary" type="button" onClick={() => router.post(`/admin/ops-tools/keys/${row.id}/toggle`)}>
                                                {row.is_enabled ? t('admin.common.disable', 'Disable') : t('admin.common.enable', 'Enable')}
                                            </Button>
                                            <Button variant="danger" type="button" onClick={() => router.delete(`/admin/ops-tools/keys/${row.id}`)}>
                                                {t('admin.ops_tools.revoke', 'Revoke')}
                                            </Button>
                                        </div>
                                    </Td>
                                </Tr>
                            )) : (
                                <EmptyState colSpan={3}>{t('admin.ops_tools.no_keys', 'No keys yet. Create a Service Key to let another system read this shop.')}</EmptyState>
                            )}
                        </tbody>
                    </Table>
                </Card>
            ) : null}

            {tab === 'docs' ? (
                <ConnectGuide webhooks={webhooks} keys={keys} deliveries={deliveries} />
            ) : null}
        </Layout>
    );
}
