import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';

function csrfHeaders() {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-CSRF-TOKEN': token } : {}),
    };
}

async function loadInbox(unreadOnly) {
    const params = new URLSearchParams();
    if (unreadOnly) {
        params.set('unread', '1');
    }
    const res = await fetch(`/admin/notifications?${params}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!res.ok) {
        return null;
    }
    return res.json();
}

function age(iso) {
    const then = new Date(iso).getTime();
    if (Number.isNaN(then)) {
        return '';
    }
    const minutes = Math.max(0, Math.round((Date.now() - then) / 60000));
    if (minutes < 1) {
        return t('admin.notify.just_now', 'Just now');
    }
    if (minutes < 60) {
        return t('admin.notify.minutes', '{n}m ago').replace('{n}', String(minutes));
    }
    const hours = Math.round(minutes / 60);
    if (hours < 24) {
        return t('admin.notify.hours', '{n}h ago').replace('{n}', String(hours));
    }
    const days = Math.round(hours / 24);
    return t('admin.notify.days', '{n}d ago').replace('{n}', String(days));
}

export default function NotificationBell() {
    const shared = Number(usePage().props.notifications?.unread || 0);
    const [open, setOpen] = useState(false);
    const [unread, setUnread] = useState(shared);
    const [filter, setFilter] = useState('all');
    const [items, setItems] = useState([]);
    const [loading, setLoading] = useState(false);
    const [pulse, setPulse] = useState(false);
    const wrapRef = useRef(null);
    const unreadRef = useRef(shared);
    const openRef = useRef(open);

    useEffect(() => {
        setUnread(shared);
        unreadRef.current = shared;
    }, [shared]);

    useEffect(() => {
        openRef.current = open;
    }, [open]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onDoc = (event) => {
            if (wrapRef.current && !wrapRef.current.contains(event.target)) {
                setOpen(false);
            }
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('mousedown', onDoc);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDoc);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        let cancelled = false;
        setLoading(true);
        loadInbox(filter === 'unread').then((data) => {
            if (cancelled) {
                return;
            }
            if (data) {
                setItems(Array.isArray(data.items) ? data.items : []);
                setUnread(Number(data.unread || 0));
            }
            setLoading(false);
        });
        return () => {
            cancelled = true;
        };
    }, [open, filter]);

    useEffect(() => {
        if (typeof window.EventSource === 'undefined') {
            const id = window.setInterval(() => {
                if (document.visibilityState !== 'visible') {
                    return;
                }
                const params = new URLSearchParams({ summary: '1' });
                fetch(`/admin/notifications?${params}`, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                }).then(async (res) => {
                    if (!res.ok) {
                        return;
                    }
                    const data = await res.json();
                    setUnread(Number(data.unread || 0));
                }).catch(() => {});
            }, 45000);
            return () => window.clearInterval(id);
        }

        let source;
        try {
            source = new EventSource('/admin/notifications/stream?seconds=55', { withCredentials: true });
        } catch {
            return undefined;
        }
        const onInbox = (event) => {
            try {
                const data = JSON.parse(event.data || '{}');
                if (data.unread != null) {
                    const next = Number(data.unread || 0);
                    if (!openRef.current && next > unreadRef.current) {
                        setPulse(true);
                        window.setTimeout(() => setPulse(false), 1200);
                    }
                    unreadRef.current = next;
                    setUnread(next);
                }
            } catch {
                // ignore malformed frames
            }
        };
        source.addEventListener('inbox', onInbox);
        source.onerror = () => {
            // Browser will retry EventSource; keep last unread.
        };
        return () => {
            source.removeEventListener('inbox', onInbox);
            source.close();
        };
    }, []);

    const markOne = async (item) => {
        const res = await fetch(`/admin/notifications/${item.id}/read`, {
            method: 'POST',
            headers: csrfHeaders(),
            credentials: 'same-origin',
            body: '{}',
        });
        if (res.ok) {
            const data = await res.json();
            setUnread(Number(data.unread || 0));
            setItems((rows) => rows.map((row) => (row.id === item.id ? { ...row, read: true } : row)));
        }
        if (item.href) {
            setOpen(false);
            router.visit(item.href);
        }
    };

    const markAll = async () => {
        const res = await fetch('/admin/notifications/read-all', {
            method: 'POST',
            headers: csrfHeaders(),
            credentials: 'same-origin',
            body: '{}',
        });
        if (!res.ok) {
            return;
        }
        setUnread(0);
        setItems((rows) => (filter === 'unread' ? [] : rows.map((row) => ({ ...row, read: true }))));
    };

    const countLabel = unread > 99 ? '99+' : String(unread);

    return (
        <div className={ui.notifyWrap} ref={wrapRef}>
            <button
                type="button"
                className={cx(ui.menuBtn, 'relative', pulse && 'animate-pulse')}
                aria-label={t('admin.notify.bell', 'Notifications')}
                aria-expanded={open}
                aria-haspopup="dialog"
                onClick={() => setOpen((value) => !value)}
            >
                <Icon name="bell" />
                {unread > 0 ? (
                    <span className={cx(ui.notifyBadge, pulse && 'scale-110')}>{countLabel}</span>
                ) : null}
            </button>
            {open ? (
                <div className={ui.notifyPanel} role="dialog" aria-label={t('admin.notify.bell', 'Notifications')}>
                    <div className={ui.notifyHead}>
                        <div className={ui.notifyTitle}>{t('admin.notify.bell', 'Notifications')}</div>
                        {unread > 0 ? (
                            <button type="button" className={ui.notifyMark} onClick={markAll}>
                                {t('admin.notify.mark_all', 'Mark all read')}
                            </button>
                        ) : null}
                    </div>
                    <div className={ui.notifyTabs} role="tablist">
                        <button
                            type="button"
                            className={filter === 'all' ? ui.notifyTabOn : ui.notifyTab}
                            onClick={() => setFilter('all')}
                        >
                            {t('admin.notify.all', 'All')}
                        </button>
                        <button
                            type="button"
                            className={filter === 'unread' ? ui.notifyTabOn : ui.notifyTab}
                            onClick={() => setFilter('unread')}
                        >
                            {t('admin.notify.unread', 'Unread')}
                        </button>
                    </div>
                    <div className={ui.notifyList}>
                        {loading && items.length === 0 ? (
                            <div className={ui.notifyEmpty}>{t('admin.notify.loading', 'Loading…')}</div>
                        ) : null}
                        {!loading && items.length === 0 ? (
                            <div className={ui.notifyEmpty}>
                                {t('admin.notify.empty', 'No notifications yet. New orders, customers, and stock alerts show up here.')}
                            </div>
                        ) : null}
                        {items.filter((item) => filter !== 'unread' || !item.read).map((item) => (
                            <button
                                key={item.id}
                                type="button"
                                className={cx(
                                    ui.notifyItem,
                                    item.read
                                        ? ui.notifyItemRead
                                        : (item.tone === 'success' ? ui.notifyItemSuccess : ui.notifyItemInfo),
                                )}
                                onClick={() => markOne(item)}
                            >
                                <span className="min-w-0 flex-1">
                                    <span className={ui.notifyItemTitle}>{item.title}</span>
                                    {item.body ? <span className={cx(ui.notifyItemBody, 'block')}>{item.body}</span> : null}
                                    <span className={cx(ui.notifyItemTime, 'block')}>{age(item.created_at)}</span>
                                </span>
                            </button>
                        ))}
                    </div>
                </div>
            ) : null}
        </div>
    );
}
