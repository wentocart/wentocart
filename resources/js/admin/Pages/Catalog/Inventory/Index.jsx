import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import Layout from '../../Layout';
import { catalogImageUrl } from '../../../lib/catalogMedia';
import {
    Badge,
    BulkActions,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    Field,
    FilterReset,
    Flash,
    Icon,
    Input,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    Select,
    SelectionCheckbox,
    SortHeader,
    Table,
    THead,
    Th,
    Tr,
    Td,
    ui,
    cx,
    t,
    useListSelection,
    KpiCards,
    statusCountKpis,
} from '../../../ui';

function cleanQuery(payload) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null) {
            delete next[key];
        }
    });
    return next;
}

function stockTone(qty) {
    return qty > 0 ? 'success' : 'danger';
}

function InventoryTransferDialog({
    open,
    row,
    warehouses,
    busy,
    onCancel,
    onSave,
}) {
    const defaults = useMemo(() => {
        const list = Array.isArray(warehouses) ? warehouses : [];
        const from = list.find((w) => w.is_default) || list[0] || null;
        const to = list.find((w) => from && w.id !== from.id) || null;
        return {
            from_warehouse_id: from ? String(from.id) : '',
            to_warehouse_id: to ? String(to.id) : '',
        };
    }, [warehouses]);
    const [fromId, setFromId] = useState('');
    const [toId, setToId] = useState('');
    const [qty, setQty] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        setFromId(defaults.from_warehouse_id);
        setToId(defaults.to_warehouse_id);
        setQty('');
        setError('');
        return undefined;
    }, [open, row, defaults.from_warehouse_id, defaults.to_warehouse_id]);

    if (!open || !row) {
        return null;
    }

    const title = t('admin.inventory.transfer_stock', 'Transfer stock');
    const submit = () => {
        const parsed = Number.parseFloat(qty);
        if (!Number.isFinite(parsed) || parsed <= 0) {
            setError(t('admin.inventory.transfer_qty_required', 'Enter a transfer quantity greater than zero.'));
            return;
        }
        if (!fromId || !toId || fromId === toId) {
            setError(t('admin.inventory.transfer_warehouses_required', 'Choose different source and destination warehouses.'));
            return;
        }
        setError('');
        onSave({
            from_warehouse_id: Number(fromId),
            to_warehouse_id: Number(toId),
            qty: parsed,
        });
    };

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={busy ? undefined : onCancel}>
            <div
                className={`${ui.modal} max-w-xl`}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 className={ui.modalTitle}>{title}</h2>
                        <p className={ui.modalSub}>{row.name}</p>
                    </div>
                    <button type="button" className={ui.menuBtn} onClick={onCancel} disabled={busy} aria-label={t('admin.common.close', 'Close')}>
                        <Icon name="x" />
                    </button>
                </div>
                <div className={ui.modalBody}>
                    <dl className="grid grid-cols-3 gap-3 mb-4 text-sm">
                        <div>
                            <dt className="text-neutral-500">{t('admin.inventory.stock', 'Stock')}</dt>
                            <dd className="font-semibold tabular-nums">{row.stock_qty}</dd>
                        </div>
                        <div>
                            <dt className="text-neutral-500">{t('admin.inventory.reserved', 'Reserved')}</dt>
                            <dd className="font-semibold tabular-nums">{row.reserved_qty}</dd>
                        </div>
                        <div>
                            <dt className="text-neutral-500">{t('admin.inventory.available', 'Available')}</dt>
                            <dd className="font-semibold tabular-nums">{row.available}</dd>
                        </div>
                    </dl>
                    <div className="grid gap-3 md:grid-cols-2">
                        <Field label={t('admin.inventory.from_warehouse', 'From warehouse')} error={error && !fromId ? error : ''}>
                            <Select wide value={fromId} onChange={(e) => { setFromId(e.target.value); setError(''); }}>
                                <option value="">{t('admin.inventory.choose_warehouse', 'Choose warehouse')}</option>
                                {(warehouses || []).map((w) => (
                                    <option key={w.id} value={String(w.id)}>{w.code} — {w.name}</option>
                                ))}
                            </Select>
                        </Field>
                        <Field label={t('admin.inventory.to_warehouse', 'To warehouse')}>
                            <Select wide value={toId} onChange={(e) => { setToId(e.target.value); setError(''); }}>
                                <option value="">{t('admin.inventory.choose_warehouse', 'Choose warehouse')}</option>
                                {(warehouses || []).filter((w) => String(w.id) !== String(fromId)).map((w) => (
                                    <option key={w.id} value={String(w.id)}>{w.code} — {w.name}</option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    <Field
                        className="mt-3"
                        label={t('admin.inventory.transfer_qty', 'Quantity')}
                        error={error}
                    >
                        <Input
                            id="admin-inventory-transfer-qty"
                            type="number"
                            min="0"
                            step="1"
                            placeholder={t('admin.inventory.transfer_qty_placeholder', 'e.g. 5')}
                            value={qty}
                            invalid={!!error}
                            onChange={(e) => {
                                setQty(e.target.value);
                                setError('');
                            }}
                        />
                    </Field>
                    <p className="text-sm text-neutral-500 mt-3">
                        {t('admin.inventory.transfer_help', 'Moves available units between warehouses. Product total stock stays the same.')}
                    </p>
                </div>
                <div className={ui.modalFoot}>
                    <Button variant="ghost" type="button" onClick={onCancel} disabled={busy}>
                        {t('admin.common.cancel', 'Cancel')}
                    </Button>
                    <Button type="button" busy={busy} onClick={submit}>
                        {t('admin.inventory.transfer', 'Transfer')}
                    </Button>
                </div>
            </div>
        </div>
    );
}

function InventoryAdjustDialog({
    open,
    rows,
    busy,
    onCancel,
    onSave,
}) {
    const [delta, setDelta] = useState('');
    const [error, setError] = useState('');

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        setDelta('');
        setError('');
        return undefined;
    }, [open, rows]);

    if (!open) {
        return null;
    }

    const count = rows.length;
    const single = count === 1 ? rows[0] : null;
    const title = single
        ? t('admin.inventory.edit_quantity', 'Edit quantity')
        : t('admin.inventory.adjust_stock', 'Adjust stock');
    const subtitle = single
        ? single.name
        : t('admin.inventory.apply_to_selected', 'Apply to :count selected').replace(':count', String(count));

    const submit = () => {
        const parsed = Number.parseInt(delta, 10);
        if (!Number.isFinite(parsed) || parsed === 0) {
            setError(t('admin.inventory.delta_required', 'Enter a non-zero stock change.'));
            return;
        }
        setError('');
        onSave(parsed);
    };

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={busy ? undefined : onCancel}>
            <div
                className={`${ui.modal} max-w-xl`}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 className={ui.modalTitle}>{title}</h2>
                        {subtitle ? <p className={ui.modalSub}>{subtitle}</p> : null}
                    </div>
                    <button type="button" className={ui.menuBtn} onClick={onCancel} disabled={busy} aria-label={t('admin.common.close', 'Close')}>
                        <Icon name="x" />
                    </button>
                </div>
                <div className={ui.modalBody}>
                    {single ? (
                        <dl className="grid grid-cols-3 gap-3 mb-4 text-sm">
                            <div>
                                <dt className="text-neutral-500">{t('admin.inventory.stock', 'Stock')}</dt>
                                <dd className="font-semibold tabular-nums">{single.stock_qty}</dd>
                            </div>
                            <div>
                                <dt className="text-neutral-500">{t('admin.inventory.reserved', 'Reserved')}</dt>
                                <dd className="font-semibold tabular-nums">{single.reserved_qty}</dd>
                            </div>
                            <div>
                                <dt className="text-neutral-500">{t('admin.inventory.available', 'Available')}</dt>
                                <dd className="font-semibold tabular-nums">{single.available}</dd>
                            </div>
                        </dl>
                    ) : null}
                    <Field
                        label={t('admin.inventory.delta', '± qty')}
                        error={error}
                    >
                        <Input
                            id="admin-inventory-delta"
                            type="number"
                            placeholder={t('admin.inventory.delta_placeholder', 'e.g. 5 or -2')}
                            value={delta}
                            invalid={!!error}
                            onChange={(e) => {
                                setDelta(e.target.value);
                                setError('');
                            }}
                        />
                    </Field>
                    <p className="text-sm text-neutral-500 mt-3">
                        {t('admin.inventory.adjust_help', 'Use a stock delta to restock or remove units without rewriting product fields.')}
                    </p>
                </div>
                <div className={ui.modalFoot}>
                    <Button variant="ghost" type="button" onClick={onCancel} disabled={busy}>
                        {t('admin.common.cancel', 'Cancel')}
                    </Button>
                    <Button type="button" busy={busy} onClick={submit}>
                        {t('admin.inventory.adjust', 'Adjust')}
                    </Button>
                </div>
            </div>
        </div>
    );
}

export default function Index({ inventory, filters = {}, warehouses = [] }) {
    const rows = inventory.data || [];
    const [bulkBusy, setBulkBusy] = useState(false);
    const [adjustRows, setAdjustRows] = useState(null);
    const [transferRow, setTransferRow] = useState(null);

    const query = useMemo(() => ({
        name: filters.name || '',
        sku: filters.sku || '',
        supplier: filters.supplier || '',
        id_min: filters.id_min ?? '',
        id_max: filters.id_max ?? '',
        stock_min: filters.stock_min ?? '',
        stock_max: filters.stock_max ?? '',
        reserved_min: filters.reserved_min ?? '',
        reserved_max: filters.reserved_max ?? '',
        available_min: filters.available_min ?? '',
        available_max: filters.available_max ?? '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
        page: filters.page || 1,
        per_page: filters.per_page || 10,
    }), [filters]);

    const apply = useCallback((next) => {
        router.get('/admin/inventory', cleanQuery({ ...query, ...next, page: next.page ?? 1 }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [query]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value, page: 1 });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir, page: 1 });
    }, [apply, query.dir, query.sort]);

    const selection = useListSelection(rows);
    const colSpan = 10;
    const canTransfer = (warehouses || []).length >= 2;

    const openBulkAdjust = useCallback(() => {
        if (!selection.selectedCount) {
            return;
        }
        const selectedRows = rows.filter((row) => selection.selectedIds.includes(row.id));
        setAdjustRows({ rows: selectedRows });
    }, [rows, selection.selectedCount, selection.selectedIds]);

    const openRowAdjust = useCallback((row) => {
        setAdjustRows({ rows: [row] });
    }, []);

    const closeAdjust = useCallback(() => {
        if (!bulkBusy) {
            setAdjustRows(null);
        }
    }, [bulkBusy]);

    const openRowTransfer = useCallback((row) => {
        setTransferRow(row);
    }, []);

    const closeTransfer = useCallback(() => {
        if (!bulkBusy) {
            setTransferRow(null);
        }
    }, [bulkBusy]);

    const saveTransfer = useCallback((payload) => {
        if (!transferRow?.id || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post(`/admin/inventory/${transferRow.id}/transfer`, payload, {
            preserveScroll: true,
            onFinish: () => {
                setBulkBusy(false);
                setTransferRow(null);
            },
        });
    }, [bulkBusy, transferRow]);

    const saveAdjust = useCallback((delta) => {
        const targetRows = adjustRows?.rows || [];
        const ids = targetRows.map((row) => row.id);
        if (!ids.length || bulkBusy) {
            return;
        }

        setBulkBusy(true);
        const finish = () => {
            setBulkBusy(false);
            setAdjustRows(null);
            selection.clear();
        };

        if (ids.length === 1) {
            router.post(`/admin/inventory/${ids[0]}/adjust`, { delta }, {
                preserveScroll: true,
                onFinish: finish,
            });
            return;
        }

        router.post('/admin/inventory/bulk-adjust', { ids, delta }, {
            preserveScroll: true,
            onFinish: finish,
        });
    }, [adjustRows?.rows, bulkBusy, selection]);

    const kpis = statusCountKpis({ all: inventory.total ?? 0 }, {
        total: t('admin.inventory.inventory', 'Inventory'),
        active: t('admin.common.active', 'Active'),
        inactive: t('admin.common.inactive', 'Inactive'),
        rate: t('admin.common.active_rate', 'Active rate'),
    });

    return (
        <Layout>
            <PageHeader
                title={t('admin.inventory.inventory', 'Inventory')}
                total={inventory.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/products', label: t('admin.nav.catalog', 'Catalog') },
                    { label: t('admin.inventory.inventory', 'Inventory') },
                ]}
                actions={(
                    <div className={ui.toolbar}>
                        <Button href="/admin/inventory/journal" variant="secondary">
                            {t('admin.inventory.journal', 'Stock journal')}
                        </Button>
                        <ListSettings
                            listUrl="/admin/inventory"
                            importUrl="/admin/inventory/import"
                            importSampleUrl="/admin/inventory/import/sample"
                            exportUrl="/admin/inventory/export"
                            query={query}
                        />
                    </div>
                )}
            />

            <KpiCards items={kpis} />

            <Flash />

            <ListPanel
                bulk={(
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[{
                            key: 'adjust',
                            label: t('admin.inventory.adjust_stock', 'Adjust stock'),
                            onClick: openBulkAdjust,
                        }]}
                    />
                )}
                footer={<ListFooter pagination={inventory} query={query} />}
            >
                <Table>
                    <THead>
                        <tr>
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                            <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="name" label={t('admin.products.name', 'Name')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="sku" label={t('admin.products.reference', 'Reference')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader column="supplier" label={t('admin.products.supplier', 'Supplier')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="stock_qty" label={t('admin.inventory.stock', 'Stock')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="reserved_qty" label={t('admin.inventory.reserved', 'Reserved')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <SortHeader col="center" column="available" label={t('admin.inventory.available', 'Available')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                            <Th col="center">{t('admin.inventory.edit_quantity', 'Edit quantity')}</Th>
                            <Th col="center">{t('admin.inventory.transfer', 'Transfer')}</Th>
                        </tr>
                        <tr>
                            <Th check className={ui.thFilter} />
                            <Th className={ui.thFilterId}>
                                <ColumnRangeFilter
                                    minName="id_min"
                                    maxName="id_max"
                                    minValue={query.id_min}
                                    maxValue={query.id_max}
                                    onApply={applyColumn}
                                    compact
                                />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="name" value={query.name} placeholder={t('admin.products.search_name', 'Search name')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="sku" value={query.sku} placeholder={t('admin.products.search_reference', 'Search reference')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilter}>
                                <ColumnFilter name="supplier" value={query.supplier} placeholder={t('admin.inventory.search_supplier', 'Search supplier')} onApply={applyColumn} />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="stock_min"
                                    maxName="stock_max"
                                    minValue={query.stock_min}
                                    maxValue={query.stock_max}
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="reserved_min"
                                    maxName="reserved_max"
                                    minValue={query.reserved_min}
                                    maxValue={query.reserved_max}
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <ColumnRangeFilter
                                    minName="available_min"
                                    maxName="available_max"
                                    minValue={query.available_min}
                                    maxValue={query.available_max}
                                    onApply={applyColumn}
                                    center
                                />
                            </Th>
                            <Th className={ui.thFilterCenter}>
                                <FilterReset query={query} href="/admin/inventory" />
                            </Th>
                            <Th className={ui.thFilterCenter} />
                        </tr>
                    </THead>
                    <tbody>
                        {rows.length === 0 ? (
                            <EmptyState colSpan={colSpan}>{t('admin.inventory.no_products', 'No products match these filters.')}</EmptyState>
                        ) : rows.map((row) => (
                            <Tr key={row.id} selected={selection.isSelected(row.id)}>
                                <Td check>
                                    <SelectionCheckbox
                                        checked={selection.isSelected(row.id)}
                                        onChange={() => selection.toggle(row.id)}
                                        ariaLabel={`${t('admin.common.select', 'Select')} ${row.name}`}
                                    />
                                </Td>
                                <Td col="id">{row.id}</Td>
                                <Td>
                                    <div className={ui.row}>
                                        <img src={catalogImageUrl(row.cover_path || row.cover_url, 'sm')} alt="" className={ui.thumbLg} />
                                        <div className={ui.cellStack}>
                                            <div className={ui.cellStrong}>{row.name}</div>
                                        </div>
                                    </div>
                                </Td>
                                <Td>{row.sku || '—'}</Td>
                                <Td>{row.supplier_name || '—'}</Td>
                                <Td col="center">
                                    <Badge tone={stockTone(Number(row.stock_qty))} dot={false} className="min-w-8 justify-center tabular-nums">
                                        {row.stock_qty}
                                    </Badge>
                                </Td>
                                <Td col="center">{row.reserved_qty}</Td>
                                <Td col="center">
                                    <Badge tone={stockTone(Number(row.available))} dot={false} className="min-w-8 justify-center tabular-nums">
                                        {row.available}
                                    </Badge>
                                </Td>
                                <Td col="center">
                                    <button
                                        type="button"
                                        className={cx(ui.btnBase, ui.btnGhost, 'px-2 py-2')}
                                        title={t('admin.inventory.edit_quantity', 'Edit quantity')}
                                        aria-label={`${t('admin.inventory.edit_quantity', 'Edit quantity')} — ${row.name}`}
                                        onClick={() => openRowAdjust(row)}
                                    >
                                        <Icon name="pencil" />
                                    </button>
                                </Td>
                                <Td col="center">
                                    <button
                                        type="button"
                                        className={cx(ui.btnBase, ui.btnGhost, 'px-2 py-2')}
                                        title={t('admin.inventory.transfer_stock', 'Transfer stock')}
                                        aria-label={`${t('admin.inventory.transfer_stock', 'Transfer stock')} — ${row.name}`}
                                        disabled={!canTransfer}
                                        onClick={() => openRowTransfer(row)}
                                    >
                                        <Icon name="truck" />
                                    </button>
                                </Td>
                            </Tr>
                        ))}
                    </tbody>
                </Table>
            </ListPanel>

            <InventoryAdjustDialog
                open={Boolean(adjustRows)}
                rows={adjustRows?.rows || []}
                busy={bulkBusy}
                onCancel={closeAdjust}
                onSave={saveAdjust}
            />
            <InventoryTransferDialog
                open={Boolean(transferRow)}
                row={transferRow}
                warehouses={warehouses}
                busy={bulkBusy}
                onCancel={closeTransfer}
                onSave={saveTransfer}
            />
        </Layout>
    );
}
