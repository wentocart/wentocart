import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Layout from '../Layout';
import { useSaveOnChange } from './useSaveOnChange';
import EmailLogs from './EmailLogs';
import {
    Badge,
    Button,
    BulkActions,
    catalogBulkActionItems,
    Card,
    EmptyState,
    FeatureTile,
    Field,
    FormErrors,
    Icon,
    Input,
    ListPanel,
    PageHeader,
    Pagination,
    RowActions,
    Select,
    SelectionCheckbox,
    SettingsSectionHead,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Toggle,
    useListSelection,
    ui,
    t,
    cx,
} from '../../ui';

const PAGE_SIZE = 8;

function eventCopy(event) {
    const names = {
        'customer.created': {
            name: t('admin.email.new_customer_created', 'New customer created'),
            hint: t('admin.email.new_customer_created_hint', 'Account opened from the storefront or created by staff.'),
        },
        'customer.forgot_password': {
            name: t('admin.email.forgot_password', 'Forgot password'),
            hint: t('admin.email.forgot_password_hint', 'Customer asked to recover their storefront login.'),
        },
        'order.created': {
            name: t('admin.email.new_order_created', 'New order created'),
            hint: t('admin.email.new_order_created_hint', 'Fired when a customer places an order from the storefront.'),
        },
        'order.paid': {
            name: t('admin.email.payment_received', 'Payment received'),
            hint: t('admin.email.payment_received_hint', 'Fired when an order payment status becomes paid.'),
        },
        'stock.low': {
            name: t('admin.email.low_stock', 'Low stock'),
            hint: t('admin.email.low_stock_hint', 'Fired hourly when a product marked Notify when low stock drops to its alert level.'),
        },
    };

    return names[event.code] || { name: event.name, hint: event.hint };
}

function recipientCopy(eventCode, recipient) {
    const key = `${eventCode}.${recipient.key}`;
    const copy = {
        'customer.created.customer': {
            label: t('admin.email.welcome_email_to_customer', 'Welcome email to customer'),
            hint: t('admin.email.welcome_email_to_customer_hint', 'Sent to the new account email.'),
        },
        'customer.created.admin': {
            label: t('admin.email.notification_to_admin', 'Notification to admin'),
            hint: t('admin.email.notification_to_admin_hint', 'Sent to the store email in Settings.'),
        },
        'customer.forgot_password.customer': {
            label: t('admin.email.recovery_email_to_customer', 'Recovery email to customer'),
            hint: t('admin.email.recovery_email_to_customer_hint', 'Required. The customer cannot reset a password without this email.'),
        },
        'order.created.customer': {
            label: t('admin.email.order_confirmation_to_customer', 'Order confirmation to customer'),
            hint: t('admin.email.order_confirmation_to_customer_hint', 'Storefront checkout only.'),
        },
        'order.created.admin': {
            label: t('admin.email.order_notification_to_admin', 'Order notification to admin'),
            hint: t('admin.email.order_notification_to_admin_hint', 'Storefront checkout only.'),
        },
        'order.paid.customer': {
            label: t('admin.email.payment_confirmation_to_customer', 'Payment confirmation to customer'),
            hint: t('admin.email.payment_confirmation_to_customer_hint', 'Sent to the order email.'),
        },
        'order.paid.admin': {
            label: t('admin.email.payment_notification_to_admin', 'Payment notification to admin'),
            hint: t('admin.email.payment_notification_to_admin_hint', 'Sent to the store email in Settings.'),
        },
        'stock.low.admin': {
            label: t('admin.email.low_stock_to_admin', 'Low-stock notice to admin'),
            hint: t('admin.email.low_stock_to_admin_hint', 'Sent to the store email in Settings. Turned off per product with Notify when low stock.'),
        },
    };

    return copy[key] || { label: recipient.label, hint: recipient.hint };
}

function groupLabel(group) {
    if (group === 'customers') {
        return t('admin.email.customers', 'Customers');
    }
    if (group === 'orders') {
        return t('admin.email.orders', 'Orders');
    }
    if (group === 'marketplace') {
        return t('admin.email.marketplace', 'Marketplace');
    }
    if (group === 'catalog') {
        return t('admin.email.catalog', 'Catalog');
    }

    return group;
}

function groupHint(group) {
    if (group === 'customers') {
        return t('admin.email.customers_hint', 'Account, welcome, and password recovery emails.');
    }
    if (group === 'orders') {
        return t('admin.email.orders_hint', 'Order placement and payment emails.');
    }
    if (group === 'marketplace') {
        return t('admin.email.marketplace_hint', 'Seller applications, listings, orders, and payouts.');
    }
    if (group === 'catalog') {
        return t('admin.email.catalog_hint', 'Inventory alerts for products you chose to watch.');
    }

    return '';
}

function recipientBadge(recipient) {
    if (recipient === 'admin') {
        return t('admin.email.admin', 'Admin');
    }
    if (recipient === 'seller') {
        return t('admin.email.seller', 'Seller');
    }
    if (recipient === 'custom') {
        return t('admin.email.custom', 'Custom');
    }

    return t('admin.email.customer', 'Customer');
}

function recipientTone(recipient) {
    if (recipient === 'admin') {
        return 'warning';
    }
    if (recipient === 'seller' || recipient === 'custom') {
        return 'info';
    }

    return 'primary';
}

function audienceTone(recipient) {
    if (recipient === 'admin') {
        return 'warning';
    }
    if (recipient === 'seller' || recipient === 'custom') {
        return 'info';
    }

    return 'success';
}

function smtpFields(smtp) {
    return {
        mailer: smtp?.mailer || 'log',
        host: smtp?.host || '',
        port: smtp?.port || 587,
        encryption: smtp?.encryption || 'tls',
        username: smtp?.username || '',
        password: '',
        from_address: smtp?.from_address || '',
        from_name: smtp?.from_name || '',
    };
}

function previewHref(templates) {
    const welcome = (templates || []).find((row) => row.code === 'welcome');
    if (welcome?.id) {
        return `/admin/email/templates/${welcome.id}/edit`;
    }

    return '/admin/email?tab=templates';
}

function flattenRows(events) {
    const rows = [];
    (events || []).forEach((event) => {
        const copy = eventCopy(event);
        (event.recipients || []).forEach((row) => {
            const rec = recipientCopy(event.code, row);
            rows.push({
                id: `${event.code}.${row.key}`,
                group: event.group,
                eventCode: event.code,
                eventName: copy.name,
                recipient: row.key,
                required: !!row.required,
                templateId: row.template_id || null,
                enabled: row.required ? true : !!row.enabled,
                title: rec.label,
                hint: rec.hint,
            });
        });
    });

    return rows;
}

function PaperPlaneGraphic() {
    return (
        <div className="relative mx-auto w-full max-w-[9rem] shrink-0" aria-hidden="true">
            <div className="grid h-28 place-items-center rounded-2xl bg-gradient-to-br from-violet-100 via-white to-sky-50">
                <span className="grid h-16 w-16 place-items-center rounded-2xl bg-white text-violet-600 shadow-sm ring-1 ring-violet-100">
                    <Icon name="send" className="h-8 w-8" />
                </span>
            </div>
        </div>
    );
}

function EmailIntro() {
    return (
        <div className={cx(ui.helpCard, 'mb-6')}>
            <div className="grid items-center gap-4 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,16rem)]">
                <div className="flex min-w-0 items-start gap-3">
                    <span className={ui.sectionHeadIcon}>
                        <Icon name="mail" className="h-5 w-5" />
                    </span>
                    <div className="min-w-0">
                        <h2 className={cx(ui.sectionTitle, 'mb-0')}>
                            {t('admin.email.keep_informed', 'Keep your customers informed')}
                        </h2>
                        <p className={cx(ui.muted, 'mt-1')}>
                            {t('admin.email.keep_informed_hint', 'Customize each email so customers always get the right message.')}
                        </p>
                    </div>
                </div>
                <PaperPlaneGraphic />
                <div className="rounded-2xl border border-violet-100 bg-white/80 p-4 shadow-sm">
                    <div className="flex items-start gap-3">
                        <span className={cx(ui.statIcon, 'h-9 w-9 rounded-xl', ui.statIconWarning)}>
                            <Icon name="lightbulb" className="h-4 w-4" />
                        </span>
                        <div className="min-w-0">
                            <p className="text-sm text-neutral-700">
                                {t('admin.email.templates_tip', 'Tip: Customize each email using templates.')}
                            </p>
                            <Button
                                variant="link"
                                className="mt-1 px-0"
                                href="/admin/email?tab=templates"
                            >
                                {t('admin.email.open_templates', 'Open templates')}
                                <Icon name="chevron-right" />
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

function TemplatesIntro() {
    const benefits = [
        t('admin.email.trust', 'Build customer trust'),
        t('admin.email.engagement', 'Increase engagement'),
        t('admin.email.customizable', 'Fully customizable templates'),
    ];

    return (
        <div className={cx(ui.helpCard, 'mb-6')}>
            <div className="grid items-center gap-4 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,16rem)]">
                <div className="flex min-w-0 items-start gap-3">
                    <span className={ui.sectionHeadIcon}>
                        <Icon name="mail" className="h-5 w-5" />
                    </span>
                    <div className="min-w-0">
                        <h2 className={cx(ui.sectionTitle, 'mb-0')}>
                            {t('admin.email.keep_informed', 'Keep your customers informed')}
                        </h2>
                        <p className={cx(ui.muted, 'mt-1')}>
                            {t('admin.email.templates_banner_hint', 'Send the right message at the right time. Customize email templates for a better shopping experience.')}
                        </p>
                    </div>
                </div>
                <PaperPlaneGraphic />
                <ul className="space-y-2">
                    {benefits.map((item) => (
                        <li key={item} className="flex items-start gap-2 text-sm text-neutral-700">
                            <span className={ui.tipCheck}>
                                <Icon name="check" className="h-3 w-3" />
                            </span>
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

function EventsTab({ events }) {
    const rows = useMemo(() => flattenRows(events), [events]);
    const groups = useMemo(() => {
        const seen = [];
        const map = {};
        rows.forEach((row) => {
            if (!map[row.group]) {
                map[row.group] = { key: row.group, count: 0 };
                seen.push(row.group);
            }
            map[row.group].count += 1;
        });

        return seen.map((key) => map[key]);
    }, [rows]);

    const initial = useMemo(() => {
        const map = {};
        rows.forEach((row) => {
            map[row.id] = row.required ? true : !!row.enabled;
        });

        return map;
    }, [rows]);

    const [enabled, setEnabled] = useState(initial);
    const [busyId, setBusyId] = useState(null);
    const [group, setGroup] = useState(groups[0]?.key || 'customers');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(PAGE_SIZE);
    const errors = usePage().props.errors || {};

    useEffect(() => {
        setEnabled(initial);
    }, [initial]);

    useEffect(() => {
        if (groups.length && !groups.some((item) => item.key === group)) {
            setGroup(groups[0].key);
        }
    }, [groups, group]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rows.filter((row) => {
            if (row.group !== group) {
                return false;
            }
            if (!q) {
                return true;
            }

            return [row.title, row.hint, row.eventName, recipientBadge(row.recipient)]
                .join(' ')
                .toLowerCase()
                .includes(q);
        });
    }, [rows, group, search]);

    const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
    const safePage = Math.min(page, pages);
    const start = (safePage - 1) * pageSize;
    const visible = filtered.slice(start, start + pageSize);
    const from = filtered.length ? start + 1 : 0;
    const to = start + visible.length;

    const saveRow = (row, value) => {
        if (row.required) {
            return;
        }

        const next = { ...enabled, [row.id]: value };
        setEnabled(next);
        setBusyId(row.id);

        const settings = rows.map((item) => ({
            event_code: item.eventCode,
            recipient: item.recipient,
            is_enabled: item.required ? true : !!next[item.id],
        }));

        router.put('/admin/email/events', { settings }, {
            preserveScroll: true,
            onFinish: () => setBusyId(null),
        });
    };

    return (
        <div className="space-y-4">
            <FormErrors errors={errors} />
            <div className={ui.queryLayout}>
                <Card padded={false} className="space-y-1 p-3">
                    {groups.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            className={item.key === group ? ui.sideNavItemActive : ui.sideNavItem}
                            onClick={() => {
                                setGroup(item.key);
                                setSearch('');
                                setPage(1);
                            }}
                        >
                            <span>{groupLabel(item.key)}</span>
                            <span className={item.key === group ? 'text-violet-600' : 'text-neutral-400'}>
                                ({item.count})
                            </span>
                        </button>
                    ))}
                </Card>

                <Card padded={false}>
                    <div className="flex flex-col gap-3 border-b border-neutral-100 p-5 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <h2 className={cx(ui.sectionTitle, 'mb-0')}>{groupLabel(group)}</h2>
                            <p className={cx(ui.muted, 'mt-1')}>{groupHint(group)}</p>
                        </div>
                        <div className="relative w-full shrink-0 sm:max-w-xs">
                            <Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <Input
                                className="w-full pl-9"
                                value={search}
                                placeholder={t('admin.email.search_events', 'Search events...')}
                                onChange={(e) => {
                                    setSearch(e.target.value);
                                    setPage(1);
                                }}
                            />
                        </div>
                    </div>

                    <div className="divide-y divide-neutral-100">
                        {visible.length === 0 ? (
                            <p className={cx(ui.muted, 'px-5 py-10 text-center')}>
                                {t('admin.email.no_events_match', 'No events match this search.')}
                            </p>
                        ) : (
                            visible.map((row) => (
                                <div key={row.id} className="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <p className="text-xs text-neutral-500">{row.eventName}</p>
                                        <div className="mt-0.5 flex flex-wrap items-center gap-2">
                                            <span className={ui.choiceTitle}>{row.title}</span>
                                            <Badge tone={recipientTone(row.recipient)} dot={false}>
                                                {recipientBadge(row.recipient)}
                                            </Badge>
                                            {row.required ? (
                                                <Badge tone="warning" dot={false}>
                                                    {t('admin.email.required', 'Required')}
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <p className={ui.choiceHint}>{row.hint}</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2 shrink-0">
                                        <Toggle
                                            value={row.required ? true : !!enabled[row.id]}
                                            disabled={row.required || busyId === row.id}
                                            onChange={(value) => saveRow(row, value)}
                                        />
                                        {row.templateId ? (
                                            <Button variant="secondary" icon="pencil" href={`/admin/email/templates/${row.templateId}/edit`}>
                                                {t('admin.email.edit_template', 'Edit template')}
                                            </Button>
                                        ) : null}
                                        {row.templateId ? (
                                            <RowActions
                                                editHref={`/admin/email/templates/${row.templateId}/edit`}
                                                canDelete={false}
                                                items={[
                                                    {
                                                        key: 'duplicate',
                                                        icon: 'copy',
                                                        label: t('admin.email.duplicate', 'Duplicate'),
                                                        onClick: () => router.post(`/admin/email/templates/${row.templateId}/duplicate`),
                                                    },
                                                ]}
                                            />
                                        ) : null}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>

                    <div className={ui.listFooter}>
                        <span>
                            {t('admin.common.showing', 'Showing')} {from} {t('admin.common.to', 'to')} {to} {t('admin.common.of', 'of')}{' '}
                            {filtered.length} {t('admin.email.events_count', 'events')}
                        </span>
                        <Pagination
                            inline
                            paginator={{
                                current_page: safePage,
                                last_page: pages,
                                from,
                                to,
                                total: filtered.length,
                                per_page: pageSize,
                            }}
                            perPageOptions={[8, 16, 24, 48]}
                            onPageChange={setPage}
                            onPerPageChange={(n) => {
                                setPageSize(n);
                                setPage(1);
                            }}
                        />
                    </div>
                </Card>
            </div>
        </div>
    );
}

function TemplatesTab({ templates }) {
    const rows = templates || [];
    const [audience, setAudience] = useState('all');
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState('all');
    const [showFilter, setShowFilter] = useState(false);
    const [page, setPage] = useState(1);
    const [pageSize, setPageSize] = useState(PAGE_SIZE);
    const [busyId, setBusyId] = useState(null);
    const [bulkBusy, setBulkBusy] = useState(false);
    const togglingIds = useRef(new Set());

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();

        return rows.filter((row) => {
            if (audience === 'system' && row.is_custom) {
                return false;
            }
            if (audience === 'customer' || audience === 'admin' || audience === 'seller') {
                if (row.recipient !== audience) {
                    return false;
                }
            }
            if (status === 'active' && !row.is_enabled) {
                return false;
            }
            if (status === 'inactive' && row.is_enabled) {
                return false;
            }
            if (!q) {
                return true;
            }

            return [row.name, row.code, row.subject, recipientBadge(row.recipient)]
                .join(' ')
                .toLowerCase()
                .includes(q);
        });
    }, [rows, audience, search, status]);

    const pages = Math.max(1, Math.ceil(filtered.length / pageSize));
    const safePage = Math.min(page, pages);
    const start = (safePage - 1) * pageSize;
    const visible = filtered.slice(start, start + pageSize);
    const from = filtered.length ? start + 1 : 0;
    const to = start + visible.length;

    const selection = useListSelection(filtered);

    const chips = [
        { key: 'all', label: t('admin.common.all', 'All') },
        { key: 'customer', label: t('admin.email.customer', 'Customer') },
        { key: 'admin', label: t('admin.email.admin', 'Admin') },
        { key: 'seller', label: t('admin.email.seller', 'Seller') },
        { key: 'system', label: t('admin.email.system', 'System') },
    ];

    const toggleRow = (row, value) => {
        if (!row.can_disable && !value) {
            return;
        }
        if (togglingIds.current.has(row.id)) {
            return;
        }
        togglingIds.current.add(row.id);
        setBusyId(row.id);
        router.post(`/admin/email/templates/${row.id}/toggle`, { is_enabled: value ? 1 : 0 }, {
            preserveScroll: true,
            onFinish: () => {
                togglingIds.current.delete(row.id);
                setBusyId(null);
            },
        });
    };

    const runBulk = (url, payload = {}) => {
        const ids = (selection.selectedIds || [])
            .map((id) => Number(id))
            .filter((id) => Number.isFinite(id) && id > 0);
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post(url, { ...payload, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    };

    return (
        <ListPanel
            filters={(
                <div className={cx(ui.filterBar, 'justify-between')}>
                    <div className="flex flex-wrap gap-2">
                        {chips.map((chip) => (
                            <button
                                key={chip.key}
                                type="button"
                                className={audience === chip.key ? ui.chipActive : ui.chip}
                                onClick={() => {
                                    setAudience(chip.key);
                                    setPage(1);
                                }}
                            >
                                {chip.label}
                            </button>
                        ))}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative w-full sm:w-56">
                            <Icon name="search" className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <Input
                                className="w-full pl-9"
                                value={search}
                                placeholder={t('admin.email.search_templates', 'Search templates...')}
                                onChange={(e) => {
                                    setSearch(e.target.value);
                                    setPage(1);
                                }}
                            />
                        </div>
                        <Button
                            type="button"
                            variant="secondary"
                            icon="filter"
                            aria-pressed={showFilter}
                            onClick={() => {
                                setShowFilter((open) => {
                                    if (open) {
                                        setStatus('all');
                                        setPage(1);
                                    }

                                    return !open;
                                });
                            }}
                        >
                            {t('admin.common.filter', 'Filter')}
                        </Button>
                        {showFilter ? (
                            <Select
                                value={status}
                                aria-label={t('admin.email.status', 'Status')}
                                onChange={(e) => {
                                    setStatus(e.target.value);
                                    setPage(1);
                                }}
                            >
                                <option value="all">{t('admin.email.status_all', 'All statuses')}</option>
                                <option value="active">{t('admin.common.active', 'Active')}</option>
                                <option value="inactive">{t('admin.common.inactive', 'Inactive')}</option>
                            </Select>
                        ) : null}
                    </div>
                </div>
            )}
            bulk={(
                <BulkActions
                    count={selection.selectedCount}
                    busy={bulkBusy}
                    items={catalogBulkActionItems({
                        onActive: () => runBulk('/admin/email/templates/bulk-status', { enabled: 1 }),
                        onDeactivate: () => runBulk('/admin/email/templates/bulk-status', { enabled: 0 }),
                        onDuplicate: () => runBulk('/admin/email/templates/bulk-duplicate'),
                        onDelete: () => runBulk('/admin/email/templates/bulk-delete'),
                        deleteConfirm: t('admin.email.delete_selected_templates', 'Delete selected custom templates? System templates will be skipped.'),
                    })}
                />
            )}
            footer={(
                <div className={ui.listFooter}>
                    <span>
                        {t('admin.common.showing', 'Showing')} {from} {t('admin.common.to', 'to')} {to} {t('admin.common.of', 'of')}{' '}
                        {filtered.length} {t('admin.email.templates_count', 'templates')}
                    </span>
                    <Pagination
                        inline
                        paginator={{
                            current_page: safePage,
                            last_page: pages,
                            from,
                            to,
                            total: filtered.length,
                            per_page: pageSize,
                        }}
                        perPageOptions={[8, 16, 24, 48]}
                        onPageChange={setPage}
                        onPerPageChange={(n) => {
                            setPageSize(n);
                            setPage(1);
                        }}
                    />
                </div>
            )}
        >
            <Table>
                <THead>
                    <Tr>
                        <Th check>
                            <SelectionCheckbox
                                checked={selection.allSelected}
                                indeterminate={selection.someSelected}
                                onChange={selection.toggleAll}
                            />
                        </Th>
                        <Th>{t('admin.email.template', 'Template')}</Th>
                        <Th>{t('admin.email.subject', 'Subject')}</Th>
                        <Th>{t('admin.email.audience', 'Audience')}</Th>
                        <Th>{t('admin.email.type', 'Type')}</Th>
                        <Th col="status">{t('admin.email.status', 'Status')}</Th>
                        <Th actions>{t('admin.common.actions', 'Actions')}</Th>
                    </Tr>
                </THead>
                <tbody>
                    {visible.length === 0 ? (
                        <EmptyState colSpan={7}>
                            {t('admin.email.no_templates_yet', 'No templates yet.')}
                        </EmptyState>
                    ) : (
                        visible.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                    />
                                </Td>
                                <Td>
                                    <div className="font-medium text-neutral-900">{row.name}</div>
                                    <div className={ui.muted}>{row.code}</div>
                                </Td>
                                <Td>{row.subject}</Td>
                                <Td>
                                    <Badge tone={audienceTone(row.recipient)} dot={false}>
                                        {recipientBadge(row.recipient)}
                                    </Badge>
                                </Td>
                                <Td>
                                    <Badge tone={row.is_custom ? 'info' : 'neutral'} dot={false}>
                                        {row.is_custom
                                            ? t('admin.email.custom', 'Custom')
                                            : t('admin.email.system', 'System')}
                                    </Badge>
                                </Td>
                                <Td col="status">
                                    <Toggle
                                        value={!!row.is_enabled}
                                        disabled={!row.can_disable || busyId === row.id}
                                        ariaLabel={row.name}
                                        onChange={(value) => toggleRow(row, value)}
                                    />
                                </Td>
                                <Td actions>
                                    <div className={ui.row}>
                                        <Button
                                            variant="ghost"
                                            icon="pencil"
                                            href={`/admin/email/templates/${row.id}/edit`}
                                            aria-label={t('admin.common.edit', 'Edit')}
                                        />
                                        <RowActions
                                            editHref={`/admin/email/templates/${row.id}/edit`}
                                            canDelete={!!row.is_custom}
                                            confirmMessage={t('admin.email.delete_this_template', 'Delete this template?')}
                                            onDelete={() => router.delete(`/admin/email/templates/${row.id}`)}
                                            items={[
                                                {
                                                    key: 'duplicate',
                                                    icon: 'copy',
                                                    label: t('admin.email.duplicate', 'Duplicate'),
                                                    onClick: () => router.post(`/admin/email/templates/${row.id}/duplicate`),
                                                },
                                            ]}
                                        />
                                    </div>
                                </Td>
                            </Tr>
                        ))
                    )}
                </tbody>
            </Table>
        </ListPanel>
    );
}

function smtpConfigured(smtp, mailer, host) {
    const mode = mailer || smtp?.mailer || 'log';
    if (mode === 'smtp') {
        return Boolean(String(host || smtp?.host || '').trim());
    }
    if (mode === 'sendmail') {
        return Boolean(smtp?.sendmail_ready);
    }

    return false;
}

function FieldWithIcon({ icon, children }) {
    return (
        <div className="relative">
            <Icon name={icon} className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
            {children}
        </div>
    );
}

function MailGuideModal({ onClose }) {
    useEffect(() => {
        const onKey = (event) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const sections = [
        {
            title: t('admin.email.guide_log_title', 'Log (local)'),
            body: [
                t('admin.email.guide_log_what', 'The shop writes the full message into the server log (storage/logs/laravel.log) and stops. The address in Send to does not receive anything.'),
                t('admin.email.guide_log_when', 'Use this on a computer where you only want to check a template before a mailbox is ready.'),
                t('admin.email.guide_log_fill', 'Set Mailer to Log (local). From name and From email are saved for later. Host, port, username, and password are ignored.'),
                t('admin.email.guide_log_check', 'Send a test. The page can say it was sent, and Email logs shows Sent, because the log file was written. The inbox stays empty. Open the log file to read the message.'),
            ],
        },
        {
            title: t('admin.email.guide_smtp_title', 'SMTP'),
            body: [
                t('admin.email.guide_smtp_what', 'The shop signs in to a mail host and delivers the message. This is the option that works on this computer and on a server when you have a mailbox.'),
                t('admin.email.guide_smtp_fill', 'Set Mailer to SMTP. From email should be a mailbox that host lets you send from, and it usually must match the username. From name is what the customer sees.'),
                t('admin.email.guide_smtp_server', 'Host is the provider’s SMTP server, for example smtp.hostinger.com. Use port 465 with SSL, or port 587 with TLS. Username is usually the full email address. Password is that mailbox password. Leave the password blank to keep the one already saved.'),
                t('admin.email.guide_smtp_check', 'Send a test to an inbox you can open. Mailer, host, and from-address save as you edit them. The password saves when you leave that field. If the test does not arrive, open Email logs on this page. The host’s error is stored there (wrong password, blocked port, or a From address the host refused).'),
            ],
        },
        {
            title: t('admin.email.guide_sendmail_title', 'Sendmail'),
            body: [
                t('admin.email.guide_sendmail_what', 'The shop hands the message to the mail program already installed on the server. PHP’s sendmail_path is used, so you do not enter a host, port, or password.'),
                t('admin.email.guide_sendmail_server', 'It works on a Linux server where sendmail or Postfix is installed, or where the host has set sendmail_path in php.ini. After you move the shop there, choose Sendmail and send a test.'),
                t('admin.email.guide_sendmail_local', 'This Windows computer has no sendmail program. A test here will fail and tell you to use SMTP. That is expected. SMTP is the option for this machine.'),
                t('admin.email.guide_sendmail_fill', 'Set Mailer to Sendmail, then fill in From email and From name. Host and password stay unused. If the live server refuses the message, Email logs shows the error. Ask the host to enable local mail, or switch the shop back to SMTP.'),
            ],
        },
    ];

    return createPortal(
        <div className={cx(ui.modalBackdrop, 'z-[80]')} role="presentation" onClick={onClose}>
            <div
                className={cx(ui.modal, 'max-w-3xl')}
                role="dialog"
                aria-modal="true"
                aria-labelledby="email-guide-title"
                onClick={(event) => event.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div className="min-w-0">
                        <h2 id="email-guide-title" className={ui.modalTitle}>
                            {t('admin.email.guide_title', 'How the shop sends email')}
                        </h2>
                        <p className={ui.modalSub}>
                            {t('admin.email.guide_subtitle', 'Three mailers. Pick one, save, then send a test. Only one mailer is active at a time.')}
                        </p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={onClose} />
                </div>
                <div className={ui.modalBody}>
                    {sections.map((section) => (
                        <section key={section.title} className="space-y-2">
                            <h3 className={ui.sectionTitle}>{section.title}</h3>
                            {section.body.map((paragraph) => (
                                <p key={paragraph} className="text-sm text-neutral-700">{paragraph}</p>
                            ))}
                        </section>
                    ))}
                </div>
                <div className={ui.modalFoot}>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('admin.common.close', 'Close')}
                    </Button>
                </div>
            </div>
        </div>,
        document.body,
    );
}

function SmtpPromoCard() {
    const [guideOpen, setGuideOpen] = useState(false);
    const benefits = [
        t('admin.email.smtp_benefit_reliable', 'Reliable email delivery'),
        t('admin.email.smtp_benefit_informed', 'Keep your customers informed'),
        t('admin.email.smtp_benefit_test', 'Test your configuration easily'),
    ];

    return (
        <div className={cx(ui.helpCard, 'p-0')}>
            <div className="grid gap-4 p-5 sm:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className={cx(ui.sectionTitle, 'mb-1 text-violet-800')}>
                            {t('admin.email.smtp_promo_title', 'Send better emails for a better experience')}
                        </h2>
                        <p className={cx(ui.muted, 'mt-1')}>
                            {t('admin.email.smtp_promo_hint', 'Configure your SMTP settings to ensure reliable email delivery for order updates, customer notifications, and more.')}
                        </p>
                    </div>
                    <div className="relative hidden shrink-0 sm:block" aria-hidden="true">
                        <span className="grid h-16 w-16 place-items-center rounded-2xl bg-white text-violet-600 shadow-sm ring-1 ring-violet-100">
                            <Icon name="mail" className="h-8 w-8" />
                        </span>
                        <span className="absolute -right-2 -top-2 grid h-8 w-8 place-items-center rounded-full bg-violet-600 text-white shadow">
                            <Icon name="send" className="h-4 w-4" />
                        </span>
                    </div>
                </div>
                <ul className="space-y-2">
                    {benefits.map((item) => (
                        <li key={item} className="flex items-start gap-2 text-sm text-neutral-700">
                            <span className={ui.tipCheck}>
                                <Icon name="check" className="h-3 w-3" />
                            </span>
                            <span>{item}</span>
                        </li>
                    ))}
                </ul>
            </div>
            <div className="grid gap-3 border-t border-violet-100 bg-violet-50/80 px-5 py-4 sm:px-6">
                <div className="flex min-w-0 items-start gap-2">
                    <span className={cx(ui.statIcon, 'h-9 w-9 rounded-xl', ui.statIconWarning)}>
                        <Icon name="lightbulb" className="h-4 w-4" />
                    </span>
                    <p className="min-w-0 text-sm text-neutral-700">
                        {t('admin.email.smtp_guide_hint', 'Need help? Check out our email configuration guide.')}
                    </p>
                </div>
                <Button type="button" variant="secondary" icon="lightbulb" className="bg-white" onClick={() => setGuideOpen(true)}>
                    {t('admin.email.view_guide', 'View guide')}
                </Button>
            </div>
            {guideOpen ? <MailGuideModal onClose={() => setGuideOpen(false)} /> : null}
        </div>
    );
}

function SmtpTab({ smtp }) {
    const form = useForm(smtpFields(smtp));
    const test = useForm({ to: smtp?.from_address || '' });
    const errors = usePage().props.errors || {};
    const configured = smtpConfigured(smtp, form.data.mailer, form.data.host);
    const { saveDeferred } = useSaveOnChange(form, '/admin/email/smtp', {
        textKeys: ['host', 'port', 'username', 'from_address', 'from_name'],
        deferKeys: ['password'],
    });

    return (
        <div className={cx(ui.formLayout, 'mb-2')}>
            <div className={ui.formMain}>
                <FormErrors errors={errors} />
                <Card
                    as="form"
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                    }}
                >
                    <SettingsSectionHead
                        icon="mail"
                        title={t('admin.email.smtp_settings', 'SMTP settings')}
                        hint={t('admin.email.smtp_hint', 'Choose how the shop sends emails. Password is write-only and is never shown again.')}
                        extra={(
                            <Badge tone={configured ? 'success' : 'neutral'} dot={false}>
                                {configured
                                    ? t('admin.email.smtp_configured', 'Configured')
                                    : t('admin.email.smtp_not_configured', 'Not configured')}
                            </Badge>
                        )}
                    />

                    <FormErrors errors={form.errors} />

                    <div className="grid gap-3 md:grid-cols-2">
                        <FeatureTile
                            icon="package"
                            tone="bg-violet-100 text-violet-600"
                            title={t('admin.email.mailer', 'Mailer')}
                            hint={form.errors.mailer || t('admin.email.mailer_hint', 'Use local log for testing or configure an external SMTP server.')}
                            control={(
                                <Select
                                    className="w-40 shrink-0"
                                    value={form.data.mailer}
                                    onChange={(e) => form.setData('mailer', e.target.value)}
                                >
                                    <option value="log">{t('admin.email.mailer_log', 'Log (local)')}</option>
                                    <option value="smtp">SMTP</option>
                                    <option value="sendmail">Sendmail</option>
                                </Select>
                            )}
                        />
                        <FeatureTile
                            icon="mail"
                            tone="bg-sky-100 text-sky-600"
                            title={t('admin.email.from_email_address', 'From email address')}
                            hint={form.errors.from_address || t('admin.email.from_address_hint', 'This email will be used as the sender address.')}
                            control={(
                                <Input className="w-52 shrink-0" type="email" value={form.data.from_address} onChange={(e) => form.setData('from_address', e.target.value)} />
                            )}
                        />
                        <FeatureTile
                            icon="user"
                            tone="bg-emerald-100 text-emerald-600"
                            title={t('admin.email.from_name', 'From name')}
                            hint={form.errors.from_name || t('admin.email.from_name_hint', 'This name will appear as the sender name in emails.')}
                            control={(
                                <Input className="w-48 shrink-0" value={form.data.from_name} onChange={(e) => form.setData('from_name', e.target.value)} />
                            )}
                        />
                    </div>

                    {form.data.mailer === 'smtp' ? (
                        <div className="space-y-3 rounded-xl border border-neutral-200 bg-neutral-50/60 p-4">
                            <div>
                                <h3 className="text-sm font-semibold text-neutral-900">{t('admin.email.smtp_server', 'SMTP server')}</h3>
                                <p className={cx(ui.muted, 'mt-0.5')}>
                                    {t('admin.email.smtp_server_hint', 'Host, port, encryption, and the mailbox login. These are required when Mailer is SMTP.')}
                                </p>
                            </div>
                            <div className="grid gap-3 md:grid-cols-2">
                                        <FeatureTile icon="link" tone="bg-sky-100 text-sky-600" title={t('admin.email.host', 'Host')} hint={form.errors.host} control={<Input className="w-48 shrink-0" value={form.data.host} onChange={(e) => form.setData('host', e.target.value)} />} />
                                        <FeatureTile icon="pin" tone="bg-amber-100 text-amber-600" title={t('admin.email.port', 'Port')} hint={form.errors.port} control={<Input className="w-24 shrink-0" type="number" value={form.data.port} onChange={(e) => form.setData('port', e.target.value)} />} />
                                        <FeatureTile
                                            icon="lock"
                                            tone="bg-violet-100 text-violet-600"
                                            title={t('admin.email.encryption', 'Encryption')}
                                            hint={form.errors.encryption}
                                            control={(
                                                <Select className="w-32 shrink-0" value={form.data.encryption} onChange={(e) => form.setData('encryption', e.target.value)}>
                                                    <option value="">{t('admin.email.encryption_none', 'None')}</option>
                                                    <option value="tls">TLS</option>
                                                    <option value="ssl">SSL</option>
                                                </Select>
                                            )}
                                        />
                                        <FeatureTile icon="user" tone="bg-emerald-100 text-emerald-600" title={t('admin.email.username', 'Username')} hint={form.errors.username} control={<Input className="w-44 shrink-0" value={form.data.username} onChange={(e) => form.setData('username', e.target.value)} />} />
                                        <FeatureTile
                                            icon="lock"
                                            tone="bg-rose-100 text-rose-600"
                                            title={t('admin.email.password', 'Password')}
                                            hint={form.errors.password || (smtp?.has_password ? t('admin.email.password_set', 'A password is already stored. Leave blank to keep it.') : null)}
                                            control={(
                                                <Input className="w-44 shrink-0" type="password" autoComplete="new-password" value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} onBlur={saveDeferred} />
                                            )}
                                        />
                            </div>
                        </div>
                    ) : form.data.mailer === 'sendmail' ? (
                        <p className={ui.muted}>
                            {smtp?.sendmail_ready
                                ? t('admin.email.sendmail_ready', 'Sendmail hands each message to the mail program on this server. Host, port, and password are not used. Send a test when From email is filled in.')
                                : t('admin.email.sendmail_missing', 'Sendmail hands each message to the mail program on the server. This computer does not have that program, so use SMTP here. On the live Linux server, choose Sendmail after sendmail or Postfix is installed.')}
                        </p>
                    ) : (
                        <p className={ui.muted}>
                            {t('admin.email.log_only', 'Log (local) writes the message to the shop log. It does not deliver email. Use SMTP or Sendmail when a customer should receive the message.')}
                        </p>
                    )}

                </Card>

                <Card
                    as="form"
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        test.post('/admin/email/smtp/test');
                    }}
                >
                    <SettingsSectionHead
                        icon="send"
                        title={t('admin.email.send_test', 'Send a test email')}
                        hint={t('admin.email.send_test_hint', 'Send a test email to verify your SMTP configuration. Mail settings save as you edit them.')}
                    />
                    <FormErrors errors={test.errors} />
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <Field
                            className="min-w-0 flex-1"
                            label={t('admin.email.test_to', 'Send to')}
                            required
                            error={test.errors.to}
                            hint={t('admin.email.test_to_hint', 'Address that receives the test message.')}
                        >
                            <Input wide type="email" value={test.data.to} onChange={(e) => test.setData('to', e.target.value)} />
                        </Field>
                        <Button variant="secondary" icon="send" busy={test.processing} className="shrink-0">
                            {t('admin.email.send_test', 'Send a test email')}
                        </Button>
                    </div>
                </Card>
            </div>

            <aside className={ui.formSide}>
                <SmtpPromoCard />
            </aside>
        </div>
    );
}

function emailLead(tab) {
    if (tab === 'templates') {
        return t('admin.email.templates_lead', 'Manage the email notifications that will be sent to customers or to the store email. Customize the content using templates.');
    }
    if (tab === 'smtp') {
        return t('admin.email.smtp_lead', 'Manage how your store sends emails. Configure SMTP settings and send a test email to ensure everything works correctly.');
    }
    if (tab === 'logs') {
        return t('admin.advanced.email_logs_lead', 'View the history of all emails sent from your store.');
    }

    return t('admin.email.lead', 'Configure the email notifications that will be sent to customers or to the store email. Forgot-password recovery cannot be turned off.');
}

export default function Email({
    tab = 'events',
    events = [],
    templates = [],
    smtp = {},
    email_logs: emailLogs = null,
    email_log_filters: emailLogFilters = {},
    email_log_templates: emailLogTemplates = [],
    email_log_stats: emailLogStats = {},
}) {
    const requested = ['templates', 'smtp', 'settings', 'logs'].includes(tab) ? tab : 'events';
    const current = requested === 'settings' ? 'smtp' : requested;
    const isTemplates = current === 'templates';
    const isSmtp = current === 'smtp';
    const isLogs = current === 'logs';

    return (
        <Layout>
            <PageHeader
                title={isTemplates ? t('admin.email.email_templates', 'Email Templates') : t('admin.email.email', 'Email')}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { label: t('admin.nav.configure', 'Configure') },
                    { label: t('admin.email.email', 'Email') },
                ]}
                description={emailLead(current)}
                actions={isSmtp || isLogs ? null : (
                    <>
                        {isTemplates ? (
                            <Button variant="secondary" icon="eye" href={previewHref(templates)}>
                                {t('admin.email.preview_email', 'Preview email')}
                            </Button>
                        ) : (
                            <Button icon="eye" href={previewHref(templates)}>
                                {t('admin.email.preview_email', 'Preview email')}
                            </Button>
                        )}
                        {isTemplates ? (
                            <Button icon="plus" href="/admin/email/templates/create">
                                {t('admin.email.add_custom_template', 'Add custom template')}
                            </Button>
                        ) : null}
                    </>
                )}
            />
            <StatusTabs
                className="mb-4"
                tabs={[
                    { key: 'events', label: t('admin.email.events', 'Events') },
                    { key: 'templates', label: t('admin.email.templates', 'Templates'), count: (templates || []).length },
                    { key: 'smtp', label: t('admin.email.smtp', 'SMTP') },
                    { key: 'logs', label: t('admin.advanced.email_logs', 'Email logs') },
                ]}
                value={current}
                onChange={(next) => router.get('/admin/email', { tab: next }, { preserveState: true, replace: true })}
            />
            {current === 'events' ? <EmailIntro /> : null}
            {current === 'templates' ? <TemplatesIntro /> : null}
            {current === 'templates' ? <TemplatesTab templates={templates} /> : null}
            {current === 'smtp' ? <SmtpTab smtp={smtp} /> : null}
            {current === 'logs' ? (
                <EmailLogs
                    rows={emailLogs}
                    filters={emailLogFilters}
                    templates={emailLogTemplates}
                    stats={emailLogStats}
                />
            ) : null}
            {current === 'events' ? <EventsTab events={events} /> : null}
        </Layout>
    );
}
