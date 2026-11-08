import { Link, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import Icon from './Icon';
import { ui } from './classes';
import { t } from './i18n';

function storeGroups(stores) {
    const map = new Map();
    (stores || []).forEach((row) => {
        const key = String(row.group_id || 'none');
        if (!map.has(key)) {
            map.set(key, {
                id: row.group_id,
                name: row.group || t('admin.shell.store_group_fallback', 'Default'),
                shops: [],
            });
        }
        map.get(key).shops.push(row);
    });

    return [...map.values()];
}

function allowSwitch() {
    if (typeof window !== 'undefined' && window.__wentoAdminDirty) {
        return window.confirm(t(
            'admin.shell.switch_context_confirm',
            'Switch context? Unsaved changes on this page will be discarded.',
        ));
    }

    return true;
}

export function shopContextTitle(shop) {
    const kind = shop?.context || (shop?.view_all ? 'all' : 'shop');
    if (kind === 'all') {
        return t('admin.shell.all_stores', 'All stores');
    }
    if (kind === 'group') {
        const name = String(shop?.context_label || '').replace(/^Group\s+/i, '').trim();

        return `${t('admin.shell.store_group', 'Group')} ${name}`.trim();
    }

    return shop?.context_label || shop?.name || t('admin.shell.shop', 'Shop');
}

export default function ShopTree({ shop, bar = false }) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const rootRef = useRef(null);
    const searchRef = useRef(null);
    const kind = shop?.context || (shop?.view_all ? 'all' : 'shop');
    const label = shopContextTitle(shop);

    const groups = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return storeGroups(shop?.stores).map((group) => ({
            ...group,
            shops: group.shops.filter((row) => {
                if (!row.url) {
                    return false;
                }
                if (!needle) {
                    return true;
                }

                return String(row.name || '').toLowerCase().includes(needle);
            }),
        })).filter((group) => (needle ? group.shops.length > 0 : true));
    }, [shop?.stores, query]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const close = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
                setOpen(false);
            }
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', onKey);
        searchRef.current?.focus();

        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const post = (payload) => {
        if (!allowSwitch()) {
            return;
        }
        setOpen(false);
        setQuery('');
        // A post keeps the current page's form state. Configure screens would
        // keep showing the previous shop until a full reload.
        router.post('/admin/shop-context', payload, { preserveScroll: true, preserveState: false });
    };

    return (
        <div className={ui.shopTree} ref={rootRef}>
            <button
                type="button"
                className={bar ? ui.shopTreeBtnBar : ui.shopTreeBtn}
                aria-expanded={open}
                aria-haspopup="listbox"
                aria-label={t('admin.shell.shop', 'Shop')}
                onClick={() => setOpen((value) => !value)}
            >
                <span className="truncate">{label}</span>
                <Icon name="chevron-down" className="shrink-0 opacity-70" />
            </button>
            {open ? (
                <div className={bar ? ui.shopTreePanelBar : ui.shopTreePanel} role="listbox">
                    <div className={ui.shopTreeSearch}>
                        <Icon name="search" />
                        <input
                            ref={searchRef}
                            className={ui.shopTreeSearchInput}
                            value={query}
                            placeholder={t('admin.shell.search_store', 'Search store name')}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </div>
                    <div className={ui.shopTreeList}>
                        {query.trim() === '' ? (
                            <button type="button" className={ui.shopTreeAll} onClick={() => post({ context: 'all' })}>
                                <span className={ui.shopTreeMark}>
                                    {kind === 'all' ? <Icon name="check" /> : null}
                                </span>
                                {t('admin.shell.all_stores', 'All stores')}
                            </button>
                        ) : null}
                        {groups.map((group) => {
                            const groupSelected = kind === 'group' && String(shop?.context_group_id) === String(group.id);
                            const shops = query.trim() === ''
                                ? (shop?.stores || []).filter((row) => String(row.group_id) === String(group.id))
                                : group.shops;

                            return (
                                <div key={group.id || group.name}>
                                    <div className={ui.shopTreeGroup}>
                                        <span className={ui.shopTreeMark}>
                                            {groupSelected ? <Icon name="check" /> : null}
                                        </span>
                                        <button
                                            type="button"
                                            className={ui.shopTreeGroupBtn}
                                            onClick={() => post({ context: 'group', group_id: group.id })}
                                        >
                                            <span className="truncate">{t('admin.shell.store_group', 'Group')} {group.name}</span>
                                        </button>
                                    </div>
                                    {shops.map((row) => {
                                        const selected = kind === 'shop' && String(row.id) === String(shop?.id);
                                        if (!row.url) {
                                            return (
                                                <div key={row.id} className={ui.shopTreeShop}>
                                                    <span className={ui.shopTreeMark} />
                                                    <span className="min-w-0 flex-1 truncate text-sm text-neutral-400">{row.name}</span>
                                                    <Link href={`/admin/shops/${row.id}/edit`} className={ui.shopTreeConfigure}>
                                                        {t('admin.shell.configure_url', 'Configure URL')}
                                                    </Link>
                                                </div>
                                            );
                                        }

                                        return (
                                            <div key={row.id} className={ui.shopTreeShop}>
                                                <span className={ui.shopTreeMark}>
                                                    {selected ? <Icon name="check" /> : null}
                                                </span>
                                                <button type="button" className={ui.shopTreeShopBtn} onClick={() => post({ context: 'shop', store_id: row.id })}>
                                                    <span className="truncate">{row.name}</span>
                                                </button>
                                                <a
                                                    href={row.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className={ui.shopTreeView}
                                                >
                                                    {t('admin.shell.view_my_store', 'View my store')}
                                                </a>
                                            </div>
                                        );
                                    })}
                                </div>
                            );
                        })}
                        {groups.length === 0 ? (
                            <div className={ui.shopTreeEmpty}>{t('admin.shell.no_stores', 'No stores found')}</div>
                        ) : null}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
