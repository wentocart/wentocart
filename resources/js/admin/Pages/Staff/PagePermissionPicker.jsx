import { useMemo, useState } from 'react';
import { Badge, Checkbox, Input, ui, t } from '../../ui';

export default function PagePermissionPicker({
    groups = [],
    value = [],
    onChange,
    error,
    readOnly = false,
}) {
    const [filter, setFilter] = useState('');
    const [activeGroup, setActiveGroup] = useState(groups[0]?.heading || '');

    const selected = useMemo(() => new Set(value), [value]);

    const filteredGroups = useMemo(() => {
        const q = filter.trim().toLowerCase();
        if (!q) return groups;

        return groups
            .map((group) => ({
                ...group,
                pages: group.pages.filter((page) => {
                    const hay = `${page.label} ${page.slug} ${page.href || ''}`.toLowerCase();
                    return hay.includes(q);
                }),
            }))
            .filter((group) => group.pages.length > 0);
    }, [groups, filter]);

    const visibleGroup = filteredGroups.find((g) => g.heading === activeGroup)
        || filteredGroups[0]
        || null;

    const totalPages = groups.reduce((sum, g) => sum + g.pages.length, 0);
    const selectedCount = value.length;

    const setSelected = (next) => onChange?.(Array.from(next));

    const toggle = (slug, checked) => {
        const next = new Set(selected);
        if (checked) next.add(slug);
        else next.delete(slug);
        setSelected(next);
    };

    const toggleGroup = (group, checked) => {
        const next = new Set(selected);
        group.pages.forEach((page) => {
            if (checked) next.add(page.slug);
            else next.delete(page.slug);
        });
        setSelected(next);
    };

    const groupSelectedCount = (group) => group.pages.filter((p) => selected.has(p.slug)).length;

    return (
        <div className="space-y-4">
            {!readOnly ? (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge tone="primary">{selectedCount} / {totalPages} {t('admin.staff.picker.selected', 'selected')}</Badge>
                        <button
                            type="button"
                            className="text-xs font-medium text-violet-700 hover:underline cursor-pointer"
                            onClick={() => setSelected(new Set(groups.flatMap((g) => g.pages.map((p) => p.slug))))}
                        >
                            {t('admin.staff.picker.select_all', 'Select all')}
                        </button>
                        {selectedCount > 0 ? (
                            <button
                                type="button"
                                className="text-xs font-medium text-neutral-500 hover:underline cursor-pointer"
                                onClick={() => setSelected(new Set())}
                            >
                                {t('admin.staff.picker.clear_all', 'Clear all')}
                            </button>
                        ) : null}
                    </div>
                    <div className="w-full sm:w-64">
                        <Input
                            wide
                            value={filter}
                            onChange={(e) => setFilter(e.target.value)}
                            placeholder={t('admin.staff.picker.search_pages', 'Search pages…')}
                        />
                    </div>
                </div>
            ) : null}

            {error ? <p className={ui.fieldError}>{error}</p> : null}

            <div className={ui.staffLayout}>
                <aside className={ui.staffSide}>
                    <div className={ui.staffSideHead}>
                        <p className="text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            {t('admin.staff.picker.areas', 'Admin areas')}
                        </p>
                    </div>
                    <nav className={ui.staffSideNav}>
                        {filteredGroups.map((group) => {
                            const count = groupSelectedCount(group);
                            const allOn = !readOnly && count === group.pages.length && group.pages.length > 0;
                            return (
                                <button
                                    key={group.heading}
                                    type="button"
                                    className={visibleGroup?.heading === group.heading ? ui.staffNavBtnActive : ui.staffNavBtn}
                                    onClick={() => setActiveGroup(group.heading)}
                                >
                                    <span>{t(group.heading_key || `admin.pages.group.${String(group.heading || '').toLowerCase().replace(/[^a-z0-9]+/g, '_')}`, group.heading)}</span>
                                    <span className={`text-xs tabular-nums ${allOn ? 'text-emerald-600 font-medium' : 'text-neutral-400'}`}>
                                        {readOnly ? group.pages.length : `${count}/${group.pages.length}`}
                                    </span>
                                </button>
                            );
                        })}
                    </nav>
                </aside>

                <div className={ui.staffMain}>
                    {visibleGroup ? (
                        <>
                            <div className={ui.staffMainHead}>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 className="text-base font-semibold text-neutral-900">
                                            {t(visibleGroup.heading_key || `admin.pages.group.${String(visibleGroup.heading || '').toLowerCase().replace(/[^a-z0-9]+/g, '_')}`, visibleGroup.heading)}
                                        </h3>
                                        <p className="text-sm text-neutral-500 mt-0.5">
                                            {readOnly
                                                ? `${visibleGroup.pages.length} ${t('admin.staff.picker.pages_in_area', 'pages in this area')}`
                                                : `${groupSelectedCount(visibleGroup)} / ${visibleGroup.pages.length} ${t('admin.staff.picker.pages_in_area', 'pages in this area')}`}
                                        </p>
                                    </div>
                                    {!readOnly ? (
                                        <button
                                            type="button"
                                            className="text-sm font-medium text-violet-700 hover:underline cursor-pointer"
                                            onClick={() => {
                                                const allOn = groupSelectedCount(visibleGroup) === visibleGroup.pages.length;
                                                toggleGroup(visibleGroup, !allOn);
                                            }}
                                        >
                                            {groupSelectedCount(visibleGroup) === visibleGroup.pages.length
                                                ? t('admin.roles.clear', 'Clear')
                                                : t('admin.roles.all_pages', 'All pages')}
                                        </button>
                                    ) : null}
                                </div>
                            </div>
                            <div className={ui.staffMainBody}>
                                <div className={ui.staffPageGrid}>
                                    {visibleGroup.pages.map((page) => {
                                        const on = selected.has(page.slug);
                                        if (readOnly) {
                                            return (
                                                <div key={page.slug} className={ui.staffPageCardRead}>
                                                    <div className="font-medium text-neutral-900">{t(page.label_key || `admin.pages.${page.slug}`, page.label)}</div>
                                                    <div className="mt-1 text-xs text-neutral-500 truncate">{page.href}</div>
                                                    {page.roles?.length ? (
                                                        <div className="mt-3 flex flex-wrap gap-1">
                                                            {page.roles.map((name) => (
                                                                <Badge key={name} tone="neutral">{name}</Badge>
                                                            ))}
                                                        </div>
                                                    ) : (
                                                        <p className={`${ui.muted} mt-3 text-xs`}>
                                                            {t('admin.permissions.not_used_by_any_role_yet', 'Not used by any permission yet')}
                                                        </p>
                                                    )}
                                                </div>
                                            );
                                        }

                                        return (
                                            <label
                                                key={page.slug}
                                                className={on ? ui.staffPageCardOn : `${ui.staffPageCard} cursor-pointer`}
                                            >
                                                <Checkbox
                                                    label={t(page.label_key || `admin.pages.${page.slug}`, page.label)}
                                                    checked={on}
                                                    onChange={(e) => toggle(page.slug, e.target.checked)}
                                                />
                                                <div className="mt-2 pl-6 text-xs text-neutral-500 truncate">{page.href}</div>
                                            </label>
                                        );
                                    })}
                                </div>
                            </div>
                        </>
                    ) : (
                        <div className={`${ui.staffMainBody} ${ui.muted}`}>
                            {t('admin.staff.picker.no_pages_match', 'No pages match your search.')}
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
