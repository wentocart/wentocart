import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';
import { chordLabel } from './JumpShortcutCapture';

const RECENTS_KEY = 'wento.admin.jump.recents';

async function searchPalette(q) {
    const params = new URLSearchParams();
    if (q) {
        params.set('q', q);
    }
    const res = await fetch(`/admin/command-palette?${params}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!res.ok) {
        return { pages: [], records: [], actions: [] };
    }
    return res.json();
}

function readRecents() {
    try {
        const raw = window.localStorage.getItem(RECENTS_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed.slice(0, 8) : [];
    } catch {
        return [];
    }
}

export function recordJumpRecent(url, label) {
    if (!url || !String(url).startsWith('/admin')) {
        return;
    }
    const path = String(url).split('?')[0];
    if (path === '/admin/command-palette') {
        return;
    }
    const row = {
        href: path,
        label: label || path.replace(/^\/admin\/?/, '') || 'Dashboard',
        label_key: label ? undefined : 'admin.nav.dashboard',
        group: 'Recent',
        group_key: 'admin.jump.recents',
    };
    const next = [row, ...readRecents().filter((r) => r.href !== path)].slice(0, 8);
    try {
        window.localStorage.setItem(RECENTS_KEY, JSON.stringify(next));
    } catch {
        // ignore quota
    }
}

function eventKey(e) {
    const code = String(e.code || '');
    if (/^Key[A-Z]$/.test(code)) {
        return code.slice(3).toLowerCase();
    }
    if (/^Digit[1-9]$/.test(code)) {
        return code.slice(5);
    }
    if (/^Numpad[1-9]$/.test(code)) {
        return code.slice(6);
    }

    return '';
}

function isTypingTarget(e) {
    const el = e.target;
    if (!el || !el.tagName) {
        return false;
    }
    const tag = String(el.tagName).toLowerCase();
    if (tag === 'input' || tag === 'textarea' || tag === 'select') {
        return true;
    }

    return !!el.isContentEditable;
}

function matchesChord(e, chord) {
    const parts = String(chord || 'ctrl+f').toLowerCase().split('+').filter(Boolean);
    if (parts.length < 2) {
        return false;
    }
    const key = parts[parts.length - 1];
    if (eventKey(e) !== key) {
        return false;
    }
    const needCtrl = parts.includes('ctrl');
    const needMeta = parts.includes('meta');
    const needAlt = parts.includes('alt');
    const needShift = parts.includes('shift');
    const onlyShift = needShift && !needCtrl && !needMeta && !needAlt;
    if (onlyShift && isTypingTarget(e)) {
        return false;
    }
    const ctrlOk = needCtrl ? e.ctrlKey : !e.ctrlKey;
    const metaOk = needMeta ? e.metaKey : !e.metaKey;
    const altOk = needAlt ? e.altKey : !e.altKey;
    const shiftOk = needShift ? e.shiftKey : !e.shiftKey;

    return ctrlOk && metaOk && altOk && shiftOk;
}

export default function CommandPalette({ open, onClose }) {
    const inputRef = useRef(null);
    const [q, setQ] = useState('');
    const [results, setResults] = useState({ pages: [], records: [], actions: [] });
    const [recents, setRecents] = useState([]);
    const [active, setActive] = useState(0);
    const jump = usePage().props.jump || {};

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        setQ('');
        setActive(0);
        setRecents(readRecents());
        searchPalette('').then(setResults);
        const id = window.setTimeout(() => inputRef.current?.focus(), 20);
        return () => window.clearTimeout(id);
    }, [open]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const handle = window.setTimeout(() => {
            searchPalette(q).then((data) => {
                setResults(data);
                setActive(0);
            });
        }, 180);
        return () => window.clearTimeout(handle);
    }, [q, open]);

    const groups = useMemo(() => {
        const list = [];
        if (!q && recents.length) {
            list.push({ label: t('admin.jump.recents', 'Recent'), rows: recents });
        }
        if ((results.actions || []).length) {
            list.push({ label: t('admin.jump.actions', 'Actions'), rows: results.actions });
        }
        list.push({ label: t('admin.jump.pages', 'Pages'), rows: results.pages || [] });
        if ((results.records || []).length || q) {
            list.push({ label: t('admin.jump.records', 'Records'), rows: results.records || [] });
        }
        return list;
    }, [q, recents, results]);

    const flat = useMemo(
        () => groups.flatMap((g) => g.rows.map((row) => ({ ...row, _group: g.label }))),
        [groups],
    );

    const go = useCallback((href, label) => {
        if (!href) {
            return;
        }
        recordJumpRecent(href, label);
        onClose?.();
        router.get(href);
    }, [onClose]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                onClose?.();
                return;
            }
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive((i) => Math.min(i + 1, Math.max(flat.length - 1, 0)));
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive((i) => Math.max(i - 1, 0));
                return;
            }
            if (e.key === 'Enter') {
                const row = flat[active];
                if (row) {
                    e.preventDefault();
                    go(row.href, row.label);
                }
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose, flat, active, go]);

    if (!open) {
        return null;
    }

    let flatIndex = -1;

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={`${ui.modal} max-w-lg`}
                role="dialog"
                aria-modal="true"
                aria-label={t('admin.search_shortcut.open', 'Search')}
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div className="flex w-full items-center gap-2">
                        <Icon name="search" />
                        <input
                            ref={inputRef}
                            className="w-full bg-transparent text-sm outline-none"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={t('admin.search_shortcut.placeholder', 'Search pages, products, orders, or customers')}
                        />
                        <kbd className="shrink-0 rounded-md border border-neutral-200 bg-neutral-50 px-1.5 py-0.5 text-[11px] font-semibold text-neutral-600">
                            {jump.label || chordLabel(jump.shortcut) || t('admin.search_shortcut.default_label', 'Ctrl+F')}
                        </kbd>
                    </div>
                    <button type="button" className={ui.menuBtn} onClick={onClose} aria-label={t('admin.common.close', 'Close')}>
                        <Icon name="x" />
                    </button>
                </div>
                <div className={ui.modalBody}>
                    {groups.map((group) => (
                        <div key={group.label} className="mb-3 last:mb-0">
                            <p className={ui.muted}>{group.label}</p>
                            {group.rows.length === 0 ? (
                                <p className="text-sm text-neutral-400">{t('admin.jump.empty', 'No matches')}</p>
                            ) : (
                                <ul className="mt-1 space-y-0.5">
                                    {group.rows.map((row) => {
                                        flatIndex += 1;
                                        const index = flatIndex;
                                        const isActive = index === active;
                                        return (
                                            <li key={`${row.href}-${row.label}-${index}`}>
                                                <button
                                                    type="button"
                                                    className={cx(ui.navChildLink, isActive && 'bg-violet-50 text-violet-900')}
                                                    onMouseEnter={() => setActive(index)}
                                                    onClick={() => go(row.href, row.label_key ? t(row.label_key, row.label) : row.label)}
                                                >
                                                    <span>{row.label_key ? t(row.label_key, row.label) : row.label}</span>
                                                    <span className="ml-auto text-xs text-neutral-400">
                                                        {row.group_key ? t(row.group_key, row.group) : row.group}
                                                    </span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
}

export function useCommandPalette() {
    const [open, setOpen] = useState(false);
    const jump = usePage().props.jump || {};
    const chord = jump.shortcut || 'ctrl+f';

    useEffect(() => {
        const onKey = (e) => {
            if (!matchesChord(e, chord) || e.repeat) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            setOpen(true);
        };
        window.addEventListener('keydown', onKey, true);
        return () => window.removeEventListener('keydown', onKey, true);
    }, [chord]);

    return { open, setOpen };
}
