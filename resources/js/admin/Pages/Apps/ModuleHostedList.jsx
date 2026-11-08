import { router } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import {
    Badge,
    BulkActions,
    Button,
    EmptyState,
    ListFooter,
    ListPanel,
    RowActions,
    SelectionCheckbox,
    Table,
    THead,
    Th,
    Tr,
    Td,
    t,
    useListSelection,
} from '../../ui';
import { moduleLabel } from './moduleLabel';

function catalogLabels() {
    return {
        enable: {
            label: t('admin.products.active_selection', 'Active selection'),
            icon: 'check-circle',
        },
        disable: {
            label: t('admin.products.deactivate_selection', 'Deactivate selection'),
            icon: 'ban',
        },
        duplicate: {
            label: t('admin.products.duplicate_selection', 'Duplicate selection'),
            icon: 'copy',
        },
        delete: {
            label: t('admin.products.delete_selected', 'Delete selection'),
            icon: 'trash',
            tone: 'danger',
        },
    };
}

export default function ModuleHostedList({ app, page, list }) {
    const idKey = list.id_key || 'id';
    const rows = list.rows || [];
    const columns = list.columns || [];
    const selectable = Boolean(list.selectable);
    const selection = useListSelection(rows, { idKey });
    const [busy, setBusy] = useState(false);
    const postUrl = `/admin/modules/${app.vendor}/${app.name}/${page}`;
    const labels = catalogLabels();

    const runIds = useCallback((action, ids) => {
        if (!ids.length || busy) {
            return;
        }
        setBusy(true);
        router.post(postUrl, { list_action: action, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBusy(false),
        });
    }, [busy, postUrl, selection]);

    const run = useCallback((action) => {
        runIds(action, selection.selectedIds || []);
    }, [runIds, selection.selectedIds]);

    const bulkItems = (list.bulk || []).map((item) => {
        const preset = labels[item.key] || {};
        return {
            key: item.key,
            label: moduleLabel(item, item.label || preset.label || item.key),
            icon: item.icon || preset.icon,
            tone: item.key === 'delete' ? 'danger' : preset.tone,
            confirm: item.key === 'delete'
                ? (list.delete_confirm_key
                    ? moduleLabel({ label: list.delete_confirm, label_key: list.delete_confirm_key }, list.delete_confirm || t('admin.apps.delete_selected', 'Delete selected rows?'))
                    : (list.delete_confirm || t('admin.apps.delete_selected', 'Delete selected rows?')))
                : undefined,
            onClick: () => run(item.key),
        };
    });

    const colSpan = columns.length + (selectable ? 1 : 0) + 1;

    return (
        <ListPanel
            bulk={selectable && bulkItems.length > 0 ? (
                <BulkActions count={selection.selectedCount} busy={busy} items={bulkItems} />
            ) : null}
            footer={<ListFooter left={<span>{rows.length} {t('admin.apps.on_this_page', 'on this page')}</span>} />}
        >
            <Table>
                <THead>
                    <tr>
                        {selectable ? (
                            <Th check>
                                <SelectionCheckbox
                                    checked={selection.allSelected}
                                    indeterminate={selection.someSelected}
                                    onChange={selection.toggleAll}
                                    ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                />
                            </Th>
                        ) : null}
                        {columns.map((col) => (
                            <Th key={col.key} col={col.key === 'id' || col.key === 'order' ? 'id' : col.key === 'status' || col.key === 'visibility' ? 'status' : undefined}>
                                {moduleLabel(col, col.label)}
                            </Th>
                        ))}
                        <Th actions>{t('admin.common.action', 'Actions')}</Th>
                    </tr>
                </THead>
                <tbody>
                    {rows.length === 0 ? (
                        <EmptyState colSpan={colSpan}>
                            {list.empty_key
                                ? moduleLabel({ label: list.empty, label_key: list.empty_key }, list.empty || t('admin.common.no_records', 'No records yet.'))
                                : (list.empty || t('admin.common.no_records', 'No records yet.'))}
                        </EmptyState>
                    ) : rows.map((row) => {
                        const id = row[idKey];
                        const href = row.href;
                        return (
                            <Tr key={id}>
                                {selectable ? (
                                    <Td check>
                                        <SelectionCheckbox
                                            checked={selection.isSelected(id)}
                                            onChange={() => selection.toggle(id)}
                                            ariaLabel={t('admin.common.select_row', 'Select row')}
                                        />
                                    </Td>
                                ) : null}
                                {columns.map((col) => {
                                    const value = row[col.key];
                                    const tone = row[`${col.key}_tone`];
                                    return (
                                        <Td key={col.key} col={col.key === 'id' || col.key === 'order' ? 'id' : col.key === 'status' || col.key === 'visibility' ? 'status' : undefined}>
                                            {col.badge && value != null && value !== '' ? (
                                                <Badge tone={tone || 'neutral'} dot={false}>{value}</Badge>
                                            ) : value}
                                        </Td>
                                    );
                                })}
                                <Td actions>
                                    <RowActions
                                        viewHref={row.view_href || undefined}
                                        canView={Boolean(row.view_href)}
                                        editHref={row.edit !== false && href ? href : undefined}
                                        canEdit={row.edit !== false && Boolean(href)}
                                        canDelete={row.delete === true}
                                        onDelete={row.delete === true ? () => runIds('delete', [id]) : undefined}
                                        confirmMessage={list.delete_confirm || undefined}
                                        items={[
                                            ...(row.duplicate === true ? [{
                                                key: 'duplicate',
                                                label: t('admin.common.duplicate', 'Duplicate'),
                                                icon: 'copy',
                                                onClick: () => runIds('duplicate', [id]),
                                            }] : []),
                                            ...(row.actions || []).map((action) => ({
                                                key: action.href,
                                                label: moduleLabel(action, action.label),
                                                href: action.href,
                                                icon: action.icon?.value || action.icon || 'arrow-right',
                                            })),
                                        ]}
                                    />
                                </Td>
                            </Tr>
                        );
                    })}
                </tbody>
            </Table>
        </ListPanel>
    );
}

export function moduleListHeaderActions(list) {
    if (!list?.create?.href) {
        return null;
    }

    return (
        <Button icon={list.create.icon || 'plus'} href={list.create.href}>
            {moduleLabel(list.create, list.create.label)}
        </Button>
    );
}
