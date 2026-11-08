import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import {
    Badge,
    Button,
    Field,
    FormErrors,
    Icon,
    Input,
    RowActions,
    Select,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Textarea,
    Toggle,
    ui,
    cx,
    t,
} from '../../ui';

const CLOSE_MS = 480;

function OptionRow({ icon, tone, label, hint, value, onChange, disabled }) {
    return (
        <div className={cx(ui.optionCard, disabled && 'opacity-70')}>
            <span className={cx(ui.optionCardIcon, tone)}>
                <Icon name={icon} />
            </span>
            <div className="min-w-0 flex-1">
                <div className={ui.label}>{label}</div>
                {hint ? <p className="mt-0.5 text-xs text-neutral-500">{hint}</p> : null}
            </div>
            <Toggle value={value} onChange={onChange} disabled={disabled} ariaLabel={label} />
        </div>
    );
}

function SharingBadges({ row }) {
    const chips = [
        row.share_customers ? { key: 'customers', tone: 'primary', label: t('admin.shops.share_badge_customers', 'Customer') } : null,
        row.share_orders ? { key: 'orders', tone: 'info', label: t('admin.shops.share_badge_orders', 'Orders') } : null,
        row.share_stock ? { key: 'stock', tone: 'warning', label: t('admin.shops.share_badge_stocks', 'Stocks') } : null,
    ].filter(Boolean);

    if (chips.length === 0) {
        return <span className={ui.cellSub}>—</span>;
    }

    return (
        <div className="flex flex-wrap gap-1">
            {chips.map((chip) => (
                <Badge key={chip.key} tone={chip.tone} dot={false}>
                    {chip.label}
                </Badge>
            ))}
        </div>
    );
}

function GroupModal({ mounted, open, group, onClose }) {
    const [entered, setEntered] = useState(false);
    const form = useForm({
        name: '',
        description: '',
        share_customers: false,
        share_orders: false,
        share_stock: false,
        is_enabled: true,
    });

    useEffect(() => {
        if (!mounted) {
            setEntered(false);
            return undefined;
        }
        if (open) {
            form.setData({
                name: group?.name || '',
                description: group?.description || '',
                share_customers: group?.share_customers ?? false,
                share_orders: group?.share_orders ?? false,
                share_stock: group?.share_stock ?? false,
                is_enabled: group?.is_enabled ?? true,
            });
            form.clearErrors();
            const enter = window.requestAnimationFrame(() => setEntered(true));
            const onKey = (e) => {
                if (e.key === 'Escape') {
                    onClose();
                }
            };
            window.addEventListener('keydown', onKey);
            return () => {
                window.cancelAnimationFrame(enter);
                window.removeEventListener('keydown', onKey);
            };
        }
        setEntered(false);
        return undefined;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mounted, open, group?.id]);

    if (!mounted) {
        return null;
    }

    const lockCustomers = !!group?.lock_customers;
    const lockOrders = !!group?.lock_orders;
    const lockStock = !!group?.lock_stock;
    const ordersHeld = lockOrders && !!form.data.share_orders;

    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        if (group) {
            form.put(`/admin/shop-groups/${group.id}`, options);
        } else {
            form.post('/admin/shop-groups', options);
        }
    };

    return createPortal(
        <div
            className={cx(ui.modalBackdrop, ui.modalBackdropAnim, 'z-[80]', entered ? 'opacity-100' : 'opacity-0')}
            role="presentation"
            onClick={onClose}
        >
            <div
                className={cx(
                    ui.modal,
                    ui.modalPanelAnim,
                    '!max-w-3xl',
                    entered ? 'translate-y-0 scale-100 opacity-100' : 'translate-y-4 scale-[0.97] opacity-0',
                )}
                role="dialog"
                aria-modal="true"
                aria-labelledby="shop-group-modal-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={cx(ui.modalHead, 'border-b-0')}>
                    <div className="flex min-w-0 items-start gap-3">
                        <span className={ui.panelIntroIcon}>
                            <Icon name="users" />
                        </span>
                        <div className="min-w-0">
                            <h2 id="shop-group-modal-title" className={ui.modalTitle}>
                                {group
                                    ? t('admin.shops.edit_group', 'Edit shop group')
                                    : t('admin.shops.add_group', 'Add group')}
                            </h2>
                            <p className={ui.modalSub}>
                                {t('admin.shops.group_modal_hint', 'Clothes, hardware, and phones each get a group. Sharing switches are optional.')}
                            </p>
                        </div>
                    </div>
                    <div className="relative flex shrink-0 items-start gap-2">
                        <div className="relative hidden h-14 w-20 sm:block" aria-hidden>
                            <span className="absolute right-3 top-1 h-11 w-11 rounded-full bg-violet-100" />
                            <span className="absolute right-0 top-3 grid h-10 w-10 place-items-center rounded-full bg-violet-50 text-violet-400">
                                <Icon name="users" />
                            </span>
                            <span className="absolute -right-1 top-0 grid h-6 w-6 place-items-center rounded-full bg-violet-600 text-white">
                                <Icon name="plus" className="h-3 w-3" />
                            </span>
                        </div>
                        <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={onClose} />
                    </div>
                </div>
                <form className="flex min-h-0 w-full flex-auto flex-col overflow-hidden" onSubmit={submit}>
                    <div className={ui.modalBody}>
                        <FormErrors errors={form.errors} />
                        <Field label={t('admin.shops.group_name', 'Group name')} required error={form.errors.name}>
                            <div className={ui.inputIconWrap}>
                                <span className={ui.inputIconLead}>
                                    <Icon name="price-tag" />
                                </span>
                                <Input
                                    wide
                                    required
                                    autoFocus
                                    className={ui.inputHasIcon}
                                    invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.shops.group_name_ph', 'Enter group name (e.g. Hardware)')}
                                />
                            </div>
                        </Field>
                        <Field
                            label={t('admin.shops.group_description', 'Description')}
                            hint={t('admin.shops.group_description_hint', 'Optional. For your team only — not shown on the store.')}
                            error={form.errors.description}
                        >
                            <Textarea
                                rows={3}
                                maxLength={500}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder={t('admin.shops.group_description_ph', 'Why this group exists, what it sells…')}
                            />
                        </Field>
                        <p className={cx(ui.label, 'pt-1')}>{t('admin.shops.group_options', 'Group options')}</p>
                        <div className="grid gap-2">
                            <OptionRow
                                icon="users"
                                tone="bg-violet-100 text-violet-600"
                                label={t('admin.shops.same_customers', 'Share customers')}
                                hint={
                                    lockCustomers
                                        ? t('admin.shops.lock_customers', 'A customer already belongs to a shop in this group, so this switch can no longer be changed.')
                                        : t('admin.shops.same_customers_hint', 'Shops in this group share customer accounts. Someone who registers in one shop can sign in at the others. You can turn this on or off until a customer registers. After that, the switch stays as it is.')
                                }
                                value={!!form.data.share_customers}
                                disabled={lockCustomers || ordersHeld}
                                onChange={(value) => form.setData({
                                    ...form.data,
                                    share_customers: value,
                                    share_orders: value ? form.data.share_orders : false,
                                })}
                            />
                            <OptionRow
                                icon="package"
                                tone="bg-amber-100 text-amber-700"
                                label={t('admin.shops.same_stock', 'Share available quantities')}
                                hint={
                                    lockStock
                                        ? t('admin.shops.lock_stock', 'A product in this group already has a quantity, so this switch can no longer be changed.')
                                        : t('admin.shops.same_stock_hint', 'Shops in this group share one available quantity for each product. You can turn this on or off until a product here has a quantity. Turning it on does not reset quantities to zero.')
                                }
                                value={!!form.data.share_stock}
                                disabled={lockStock || ordersHeld}
                                onChange={(value) => form.setData({
                                    ...form.data,
                                    share_stock: value,
                                    share_orders: value ? form.data.share_orders : false,
                                })}
                            />
                            <OptionRow
                                icon="cart"
                                tone="bg-sky-100 text-sky-600"
                                label={t('admin.shops.see_orders', 'Share orders')}
                                hint={
                                    lockOrders
                                        ? t('admin.shops.lock_orders', 'This group already has an order, so this switch can no longer be changed.')
                                        : t('admin.shops.see_orders_hint', 'A customer’s cart is shared by every shop in this group, so a purchase started in one shop can be finished in another. You can turn this on only when customers and quantities are already shared, and you can turn it off until this group takes an order.')
                                }
                                value={!!form.data.share_orders}
                                disabled={lockOrders || !form.data.share_customers || !form.data.share_stock}
                                onChange={(value) => form.setData('share_orders', value)}
                            />
                            <OptionRow
                                icon="check-circle"
                                tone="bg-emerald-100 text-emerald-700"
                                label={t('admin.shops.group_status', 'Status')}
                                hint={t('admin.shops.group_active_hint', 'Turn this group on or off. A group that is off cannot be chosen for a shop.')}
                                value={!!form.data.is_enabled}
                                onChange={(value) => form.setData('is_enabled', value)}
                            />
                        </div>
                    </div>
                    <div className={ui.modalFoot}>
                        <Button type="button" variant="secondary" onClick={onClose}>
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                        <Button type="submit" icon="check" busy={form.processing}>
                            {group ? t('admin.common.save', 'Save') : t('admin.shops.add_group', 'Add group')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>,
        document.body,
    );
}

function DeleteGroupModal({ mounted, open, group, groups, onClose }) {
    const [entered, setEntered] = useState(false);
    const targets = (groups || []).filter((row) => row.id !== group?.id);
    const form = useForm({ move_to_group_id: '' });

    useEffect(() => {
        if (!mounted) {
            setEntered(false);
            return undefined;
        }
        if (open) {
            form.setData({ move_to_group_id: targets[0] ? String(targets[0].id) : '' });
            form.clearErrors();
            const enter = window.requestAnimationFrame(() => setEntered(true));
            const onKey = (e) => {
                if (e.key === 'Escape') {
                    onClose();
                }
            };
            window.addEventListener('keydown', onKey);
            return () => {
                window.cancelAnimationFrame(enter);
                window.removeEventListener('keydown', onKey);
            };
        }
        setEntered(false);
        return undefined;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mounted, open, group?.id]);

    if (!mounted || !group) {
        return null;
    }

    const submit = (e) => {
        e.preventDefault();
        if (!form.data.move_to_group_id) {
            return;
        }
        form.delete(`/admin/shop-groups/${group.id}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return createPortal(
        <div
            className={cx(ui.modalBackdrop, ui.modalBackdropAnim, 'z-[80]', entered ? 'opacity-100' : 'opacity-0')}
            role="presentation"
            onClick={onClose}
        >
            <div
                className={cx(
                    ui.modal,
                    ui.modalPanelAnim,
                    '!max-w-3xl',
                    entered ? 'translate-y-0 scale-100 opacity-100' : 'translate-y-4 scale-[0.97] opacity-0',
                )}
                role="dialog"
                aria-modal="true"
                aria-labelledby="shop-group-delete-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={cx(ui.modalHead, 'border-b-0')}>
                    <div className="flex min-w-0 items-start gap-3">
                        <span className={ui.panelIntroIcon}>
                            <Icon name="trash" />
                        </span>
                        <div className="min-w-0">
                            <h2 id="shop-group-delete-title" className={ui.modalTitle}>
                                {t('admin.shops.delete_group_title', 'Delete this group?')}
                            </h2>
                            <p className={ui.modalSub}>
                                {t(
                                    'admin.shops.delete_group_shops',
                                    'This group has :count shop(s). Move them to another group. Deleting shops is a separate action on the shops list.',
                                ).replace(':count', String(group.shops))}
                            </p>
                        </div>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={onClose} />
                </div>
                <form className="flex min-h-0 w-full flex-auto flex-col overflow-hidden" onSubmit={submit}>
                    <div className={ui.modalBody}>
                        <FormErrors errors={form.errors} />
                        {targets.length === 0 ? (
                            <p className={ui.cellSub}>
                                {t(
                                    'admin.shops.delete_group_need_target',
                                    'Create another group first, or delete these shops from the shops list. Then this group can be removed.',
                                )}
                            </p>
                        ) : (
                            <Field label={t('admin.shops.move_shops_to', 'Move shops to')} required error={form.errors.move_to_group_id || form.errors.group}>
                                <Select
                                    wide
                                    required
                                    value={form.data.move_to_group_id}
                                    onChange={(e) => form.setData('move_to_group_id', e.target.value)}
                                >
                                    {targets.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        )}
                    </div>
                    <div className={ui.modalFoot}>
                        <Button type="button" variant="secondary" onClick={onClose}>
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                        <Button type="submit" icon="trash" busy={form.processing} disabled={targets.length === 0}>
                            {t('admin.shops.move_and_delete', 'Move shops and delete group')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>,
        document.body,
    );
}

export default function GroupsPanel({ groups = [] }) {
    const [modal, setModal] = useState({ open: false, closing: false, group: null });
    useEffect(() => {
        const id = new URLSearchParams(window.location.search).get('group');
        if (!id) {
            return;
        }
        const found = groups.find((row) => String(row.id) === String(id));
        if (found) {
            setModal({ open: true, closing: false, group: found });
        }
    }, [groups]);
    const [deleting, setDeleting] = useState({ open: false, closing: false, group: null });

    const requestClose = (setter) => {
        setter((current) => {
            if (current.closing || !current.open) {
                return current;
            }
            window.setTimeout(() => {
                setter({ open: false, closing: false, group: null });
            }, CLOSE_MS);
            return { ...current, closing: true, open: false };
        });
    };

    const showModal = modal.open || modal.closing;
    const showDelete = deleting.open || deleting.closing;

    return (
        <section className={ui.listPanel}>
            <div className={ui.panelIntro}>
                <div className={ui.panelIntroMain}>
                    <span className={ui.panelIntroIcon}>
                        <Icon name="folder" />
                    </span>
                    <div>
                        <h2 className={ui.panelIntroTitle}>{t('admin.shops.shop_groups', 'Shop groups')}</h2>
                        <p className={ui.panelIntroHint}>
                            {t('admin.shops.groups_hint', 'Organize shops the way page categories organize CMS pages.')}
                        </p>
                    </div>
                </div>
                <Button type="button" icon="plus" onClick={() => setModal({ open: true, closing: false, group: null })}>
                    {t('admin.shops.add_group', 'Add group')}
                </Button>
            </div>

            <div className={ui.tableWrap}>
                <Table>
                    <THead>
                        <tr>
                            <Th>{t('admin.shops.id_shop', 'id_shop')}</Th>
                            <Th>{t('admin.shops.name', 'Name')}</Th>
                            <Th>{t('admin.shops.description', 'Description')}</Th>
                            <Th>{t('admin.shops.sharing', 'Sharing')}</Th>
                            <Th>{t('admin.shops.status', 'Status')}</Th>
                            <Th actions>{t('admin.common.action', 'Action')}</Th>
                        </tr>
                    </THead>
                    <tbody>
                        {groups.length === 0 ? (
                            <tr>
                                <Td colSpan={6}>
                                    <p className={ui.empty}>{t('admin.shops.no_groups', 'No shop groups yet.')}</p>
                                </Td>
                            </tr>
                        ) : groups.map((row) => (
                            <Tr key={row.id}>
                                <Td>
                                    <span className="tabular-nums text-neutral-500">{row.id}</span>
                                </Td>
                                <Td>
                                    <span className={ui.cellStrong}>{row.name}</span>
                                </Td>
                                <Td>
                                    <span className={ui.cellSub}>{row.description || '—'}</span>
                                </Td>
                                <Td>
                                    <SharingBadges row={row} />
                                </Td>
                                <Td>
                                    <Badge tone={row.is_enabled ? 'success' : 'neutral'} dot>
                                        {row.is_enabled ? t('admin.common.active', 'Active') : t('admin.common.inactive', 'Inactive')}
                                    </Badge>
                                </Td>
                                <Td actions>
                                    <RowActions
                                        canEdit
                                        canDelete={row.can_delete && row.shops === 0}
                                        onEdit={() => setModal({ open: true, closing: false, group: row })}
                                        confirmMessage={t('admin.shops.delete_group_empty', 'This group has no shops. Delete it?')}
                                        onDelete={() => router.delete(`/admin/shop-groups/${row.id}`, { preserveScroll: true })}
                                        items={
                                            row.can_delete && row.shops > 0
                                                ? [{
                                                    key: 'delete',
                                                    icon: 'trash',
                                                    label: t('admin.common.delete', 'Delete'),
                                                    onClick: () => setDeleting({ open: true, closing: false, group: row }),
                                                }]
                                                : []
                                        }
                                    />
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </div>

            <GroupModal mounted={showModal} open={modal.open && !modal.closing} group={modal.group} onClose={() => requestClose(setModal)} />
            <DeleteGroupModal
                mounted={showDelete}
                open={deleting.open && !deleting.closing}
                group={deleting.group}
                groups={groups}
                onClose={() => requestClose(setDeleting)}
            />
        </section>
    );
}
