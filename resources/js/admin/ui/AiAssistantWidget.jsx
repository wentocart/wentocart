import { useEffect, useMemo, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import * as echarts from 'echarts/core';
import { BarChart, LineChart, PieChart } from 'echarts/charts';
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import Icon from './Icon';
import Button from './Button';
import { ui, cx } from './classes';
import { t } from './i18n';
import { adminPathname } from '../webPath';

echarts.use([BarChart, LineChart, PieChart, GridComponent, TooltipComponent, LegendComponent, CanvasRenderer]);

function tabFromPath(pathname) {
    if (pathname.startsWith('/admin/orders') || pathname.startsWith('/admin/invoices') || pathname.startsWith('/admin/credit-slips') || pathname.startsWith('/admin/delivery-slips') || pathname.startsWith('/admin/order-stages')) return 'orders';
    if (pathname.startsWith('/admin/customers') || pathname.startsWith('/admin/addresses') || pathname.startsWith('/admin/customer-groups') || pathname.startsWith('/admin/reviews') || pathname.startsWith('/admin/questions') || pathname.startsWith('/admin/quotes') || pathname.startsWith('/admin/companies')) return 'customers';
    if (pathname.startsWith('/admin/products') || pathname.startsWith('/admin/categories') || pathname.startsWith('/admin/brands') || pathname.startsWith('/admin/suppliers') || pathname.startsWith('/admin/attributes') || pathname.startsWith('/admin/features') || pathname.startsWith('/admin/stocks') || pathname.startsWith('/admin/inventory')) return 'products';
    if (pathname === '/admin' || pathname.startsWith('/admin/dashboards')) return 'dashboard';
    return 'other';
}

async function api(url, { method = 'GET', body } = {}) {
    const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    const res = await fetch(url, {
        method,
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : {}),
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(json.message || t('admin.ai.assistant_failed', 'Assistant request failed.'));
    }
    return json;
}

function ChartBlock({ chart }) {
    const ref = useRef(null);
    useEffect(() => {
        if (!ref.current || !chart) return undefined;
        const instance = echarts.init(ref.current);
        const type = chart.type === 'doughnut' ? 'pie' : chart.type;
        instance.setOption({
            tooltip: { trigger: type === 'pie' ? 'item' : 'axis' },
            legend: { bottom: 0 },
            series: type === 'pie' || chart.type === 'doughnut'
                ? [{
                    type: 'pie',
                    radius: chart.type === 'doughnut' ? ['40%', '65%'] : '65%',
                    data: (chart.labels || []).map((label, i) => ({
                        name: label,
                        value: chart.values?.[i] ?? 0,
                    })),
                }]
                : [{
                    type: type === 'line' ? 'line' : 'bar',
                    name: chart.label || 'Value',
                    data: chart.values || [],
                    smooth: type === 'line',
                }],
            ...(type === 'pie' || chart.type === 'doughnut' ? {} : {
                xAxis: { type: 'category', data: chart.labels || [] },
                yAxis: { type: 'value' },
                grid: { left: 40, right: 12, top: 24, bottom: 48 },
            }),
        });
        const onResize = () => instance.resize();
        window.addEventListener('resize', onResize);
        return () => {
            window.removeEventListener('resize', onResize);
            instance.dispose();
        };
    }, [chart]);

    return <div ref={ref} className={ui.aiChatChart} />;
}

function MessageBody({ msg, onConfirm, onCancel, confirming }) {
    const downloadCsv = async () => {
        if (!msg.table?.length || !msg.columns?.length) return;
        try {
            const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
            const res = await fetch('/admin/ai/assistant/export-csv', {
                method: 'POST',
                headers: {
                    Accept: 'text/csv',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : {}),
                },
                credentials: 'same-origin',
                body: JSON.stringify({ columns: msg.columns, rows: msg.table }),
            });
            if (!res.ok) throw new Error('CSV export failed');
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'ai-assistant-export.csv';
            a.click();
            URL.revokeObjectURL(url);
        } catch (e) {
            window.alert(e.message);
        }
    };

    return (
        <div>
            {msg.text ? <p className="whitespace-pre-wrap">{msg.text}</p> : null}
            {msg.link ? (
                <p className="mt-2">
                    <Link href={msg.link} className="text-sky-700 underline">{t('admin.ai.open_record', 'Open record')}</Link>
                </p>
            ) : null}
            {msg.kind === 'chart' && msg.chart ? <ChartBlock chart={msg.chart} /> : null}
            {(msg.kind === 'table' || msg.kind === 'chart') && msg.table?.length ? (
                <>
                    <div className={ui.aiChatTableWrap}>
                        <table className="min-w-full text-left text-xs">
                            <thead className="bg-neutral-100">
                                <tr>
                                    {(msg.columns || Object.keys(msg.table[0] || {})).map((col) => (
                                        <th key={col} className="whitespace-nowrap px-2 py-1 font-medium">{col}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {msg.table.map((row, idx) => (
                                    <tr key={idx} className="border-t border-neutral-100">
                                        {(msg.columns || Object.keys(row)).map((col) => (
                                            <td key={col} className="whitespace-nowrap px-2 py-1">{String(row[col] ?? '')}</td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <button type="button" className="mt-2 text-xs font-medium text-sky-700 underline" onClick={downloadCsv}>
                        {t('admin.ai.export_csv', 'Export CSV')}
                    </button>
                </>
            ) : null}
            {msg.kind === 'confirm_mutation' && msg.plan ? (
                <div className="mt-3 flex gap-2">
                    <Button type="button" busy={confirming} onClick={() => onConfirm?.(msg.plan)}>
                        {t('admin.ai.confirm', 'Confirm')}
                    </Button>
                    <Button type="button" variant="secondary" disabled={confirming} onClick={onCancel}>
                        {t('admin.common.cancel', 'Cancel')}
                    </Button>
                </div>
            ) : null}
        </div>
    );
}

const helloMessage = () => ({
    role: 'ai',
    kind: 'text',
    text: t('admin.ai.assistant_hello', 'Hello! How can I assist you today?'),
});

/**
 * Floating admin AI assistant with titled multi-chat sessions.
 */
export default function AiAssistantWidget() {
    const { ai, url } = usePage().props;
    const cfg = ai?.assistant || {};
    const isConfigured = cfg.configured !== false && ai?.configured !== false;
    const pathname = adminPathname(typeof window !== 'undefined' ? window.location.pathname : (url || ''));
    const [open, setOpen] = useState(false);
    const [wide, setWide] = useState(false);
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [question, setQuestion] = useState('');
    const [session, setSession] = useState({});
    const [messages, setMessages] = useState([]);
    const [chats, setChats] = useState([]);
    const [chatId, setChatId] = useState(null);
    const [chatTitle, setChatTitle] = useState('');
    const [showList, setShowList] = useState(false);
    const [naming, setNaming] = useState(false);
    const [titleDraft, setTitleDraft] = useState('');
    const [bootError, setBootError] = useState('');
    const bodyRef = useRef(null);

    const allowed = useMemo(() => {
        if (!cfg.assistant_enabled) return false;
        const tabs = cfg.accessible_tabs || ['all'];
        if (tabs.includes('all')) return true;
        return tabs.includes(tabFromPath(pathname));
    }, [cfg, pathname]);

    const loadChats = async () => {
        const json = await api('/admin/ai/assistant/chats');
        setChats(json.chats || []);
        return json.chats || [];
    };

    const openChat = async (id) => {
        const json = await api(`/admin/ai/assistant/chats/${id}`);
        const chat = json.chat;
        setChatId(chat.id);
        setChatTitle(chat.title);
        setSession(chat.context || {});
        setMessages(chat.messages?.length ? chat.messages : [helloMessage()]);
        setNaming(false);
        setShowList(false);
        setTitleDraft('');
    };

    const startNewChatFlow = () => {
        setNaming(true);
        setShowList(false);
        setTitleDraft('');
        setChatId(null);
        setChatTitle('');
        setSession({});
        setMessages([]);
    };

    const createChat = async () => {
        const title = titleDraft.trim();
        if (!title || busy) return;
        setBusy(true);
        setBootError('');
        try {
            const json = await api('/admin/ai/assistant/chats', { method: 'POST', body: { title } });
            const chat = json.chat;
            setChatId(chat.id);
            setChatTitle(chat.title);
            setSession(chat.context || {});
            setMessages(chat.messages?.length ? chat.messages : [helloMessage()]);
            setNaming(false);
            setTitleDraft('');
            await loadChats();
        } catch (e) {
            setBootError(e.message);
        } finally {
            setBusy(false);
        }
    };

    useEffect(() => {
        if (!open || !allowed) return undefined;
        let cancelled = false;
        (async () => {
            try {
                const list = await loadChats();
                if (cancelled) return;
                if (!chatId) {
                    if (list.length) {
                        await openChat(list[0].id);
                    } else {
                        setNaming(true);
                    }
                }
            } catch (e) {
                if (!cancelled) setBootError(e.message);
            }
        })();
        return () => { cancelled = true; };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, allowed]);

    useEffect(() => {
        if (bodyRef.current) {
            bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
        }
    }, [messages, open, busy, naming]);

    if (!allowed) {
        return null;
    }

    const historyPayload = () => messages
        .filter((m) => m.role === 'user' || (m.role === 'ai' && m.text))
        .slice(-8)
        .map((m) => ({
            role: m.role === 'ai' ? 'assistant' : 'user',
            content: m.text || '',
        }));

    const send = async () => {
        const q = question.trim();
        if (!q || busy || !chatId) return;
        setQuestion('');
        setMessages((prev) => [...prev, { role: 'user', kind: 'text', text: q }]);
        if (!isConfigured) {
            setMessages((prev) => [...prev, {
                role: 'ai',
                kind: 'text',
                text: t('admin.ai.assistant_unconfigured_hint', 'AI features are not configured or enabled yet. Configure an active provider connection in AI Settings.'),
                link: '/admin/ai',
            }]);
            return;
        }
        setBusy(true);
        try {
            const result = await api('/admin/ai/assistant/ask', {
                method: 'POST',
                body: {
                    question: q,
                    chat_id: chatId,
                    context: session,
                    history: historyPayload(),
                },
            });
            if (result.session) setSession(result.session);
            if (result.messages) {
                setMessages(result.messages);
            } else {
                setMessages((prev) => [...prev, {
                    role: 'ai',
                    kind: result.kind || 'text',
                    text: result.text || '',
                    chart: result.chart,
                    table: result.table || result.rows,
                    columns: result.columns,
                    plan: result.plan,
                    link: result.link,
                }]);
            }
            await loadChats();
        } catch (e) {
            setMessages((prev) => [...prev, {
                role: 'ai',
                kind: 'text',
                text: e.message || cfg.fallback_message || t('admin.ai.assistant_failed', 'Assistant request failed.'),
            }]);
        } finally {
            setBusy(false);
        }
    };

    const confirmMutation = async (plan) => {
        setConfirming(true);
        try {
            const result = await api('/admin/ai/assistant/mutate', {
                method: 'POST',
                body: { ...plan, chat_id: chatId },
            });
            if (result.messages) {
                setMessages(result.messages);
            } else {
                setMessages((prev) => [...prev, {
                    role: 'ai',
                    kind: 'text',
                    text: result.text || t('admin.ai.done', 'Done.'),
                    link: result.link,
                }]);
            }
        } catch (e) {
            setMessages((prev) => [...prev, { role: 'ai', kind: 'text', text: e.message }]);
        } finally {
            setConfirming(false);
        }
    };

    const deleteChat = async (id, e) => {
        e?.stopPropagation?.();
        if (!window.confirm(t('admin.ai.delete_chat', 'Delete this chat?'))) return;
        await api(`/admin/ai/assistant/chats/${id}`, { method: 'DELETE' });
        const list = await loadChats();
        if (chatId === id) {
            if (list[0]) await openChat(list[0].id);
            else startNewChatFlow();
        }
    };

    const iconLabel = cfg.icon_type === 'icon'
        ? <Icon name="sparkles" />
        : (cfg.icon_text || t('admin.ai.ask_ai', 'Ask AI'));

    return (
        <div className={ui.aiChatRoot}>
            {open ? (
                <div className={wide ? ui.aiChatPanelWide : ui.aiChatPanel} role="dialog" aria-label={t('admin.ai.assistant', 'AI Assistant')}>
                    <div className={ui.aiChatHeader}>
                        <div className="flex min-w-0 items-center gap-2 font-medium">
                            <Icon name="sparkles" />
                            <span className="truncate">{t('admin.ai.assistant_title', 'Shop AI')}</span>
                        </div>
                        <div className="flex shrink-0 items-center gap-1">
                            <button
                                type="button"
                                className="cursor-pointer rounded p-1 hover:bg-white/10"
                                title={t('admin.ai.new_chat', 'New chat')}
                                onClick={startNewChatFlow}
                            >
                                <Icon name="plus" />
                            </button>
                            <button
                                type="button"
                                className="cursor-pointer rounded p-1 hover:bg-white/10"
                                title={wide ? t('admin.ai.shrink', 'Shrink') : t('admin.ai.expand', 'Expand')}
                                onClick={() => setWide((v) => !v)}
                            >
                                <Icon name="eye" />
                            </button>
                            <button
                                type="button"
                                className="cursor-pointer rounded p-1 hover:bg-white/10"
                                aria-label={t('admin.common.close', 'Close')}
                                onClick={() => { setOpen(false); setShowList(false); }}
                            >
                                <Icon name="x" />
                            </button>
                        </div>
                    </div>

                    {!isConfigured ? (
                        <div className="flex items-center justify-between border-b border-amber-200 bg-amber-50 px-3 py-1.5 text-xs text-amber-900">
                            <span>{t('admin.ai.assistant_not_configured', 'AI provider is not yet configured.')}</span>
                            <Link href="/admin/ai" className="ml-2 font-semibold text-amber-800 underline shrink-0">
                                {t('admin.ai.configure_now', 'Configure')}
                            </Link>
                        </div>
                    ) : null}

                    {!naming && chatId ? (
                        <div className={cx(ui.aiChatTitleBar, 'relative')}>
                            <button
                                type="button"
                                className={cx(ui.aiChatTitle, 'cursor-pointer text-left')}
                                onClick={() => setShowList((v) => !v)}
                                title={t('admin.ai.switch_chat', 'Switch chat')}
                            >
                                {chatTitle || t('admin.ai.untitled_chat', 'Untitled chat')}
                            </button>
                            <Button type="button" variant="secondary" onClick={startNewChatFlow}>
                                {t('admin.ai.new_chat', 'New chat')}
                            </Button>
                            {showList ? (
                                <div className={ui.aiChatList}>
                                    {chats.length === 0 ? (
                                        <p className="px-3 py-2 text-sm text-neutral-500">{t('admin.ai.no_chats', 'No saved chats yet.')}</p>
                                    ) : (
                                        chats.map((row) => (
                                            <div
                                                key={row.id}
                                                className={cx(ui.aiChatListItem, row.id === chatId && ui.aiChatListItemActive)}
                                                onClick={() => openChat(row.id)}
                                                onKeyDown={(e) => { if (e.key === 'Enter') openChat(row.id); }}
                                                role="button"
                                                tabIndex={0}
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <div className="truncate font-medium">{row.title}</div>
                                                    <div className="text-xs text-neutral-500">{row.updated_at}</div>
                                                </div>
                                                <button
                                                    type="button"
                                                    className="cursor-pointer text-neutral-400 hover:text-red-600"
                                                    title={t('admin.common.delete', 'Delete')}
                                                    onClick={(e) => deleteChat(row.id, e)}
                                                >
                                                    <Icon name="trash" />
                                                </button>
                                            </div>
                                        ))
                                    )}
                                </div>
                            ) : null}
                        </div>
                    ) : null}

                    {naming ? (
                        <div className={ui.aiChatSetup}>
                            <h3 className="text-sm font-semibold text-neutral-900">
                                {t('admin.ai.name_this_chat', 'Name this chat')}
                            </h3>
                            <p className="text-sm text-neutral-600">
                                {t('admin.ai.name_this_chat_hint', 'Give it a short title so you can switch back later with full follow-up context.')}
                            </p>
                            <input
                                className={ui.aiChatInput}
                                value={titleDraft}
                                autoFocus
                                placeholder={t('admin.ai.chat_title_placeholder', 'e.g. August sales checks')}
                                onChange={(e) => setTitleDraft(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        createChat();
                                    }
                                }}
                            />
                            {bootError ? <p className="text-sm text-red-600">{bootError}</p> : null}
                            <div className="flex gap-2">
                                <Button type="button" busy={busy} disabled={!titleDraft.trim()} onClick={createChat}>
                                    {t('admin.ai.start_chat', 'Start chat')}
                                </Button>
                                {chats.length ? (
                                    <Button type="button" variant="secondary" onClick={() => openChat(chats[0].id)}>
                                        {t('admin.ai.back_to_chats', 'Back')}
                                    </Button>
                                ) : null}
                            </div>
                        </div>
                    ) : (
                        <>
                            <div ref={bodyRef} className={ui.aiChatBody}>
                                {messages.map((msg, i) => (
                                    <div key={i} className={msg.role === 'user' ? ui.aiChatBubbleUser : ui.aiChatBubbleAi}>
                                        <MessageBody
                                            msg={msg}
                                            confirming={confirming}
                                            onConfirm={confirmMutation}
                                            onCancel={() => setMessages((prev) => [...prev, {
                                                role: 'ai',
                                                kind: 'text',
                                                text: t('admin.ai.mutation_cancelled', 'Cancelled.'),
                                            }])}
                                        />
                                    </div>
                                ))}
                                {busy ? (
                                    <div className={ui.aiChatBubbleAi}>
                                        <span className="inline-flex items-center gap-2">
                                            <Icon name="spinner" className="animate-spin" />
                                            {t('admin.ai.working', 'Working…')}
                                        </span>
                                    </div>
                                ) : null}
                            </div>
                            <div className={ui.aiChatInputRow}>
                                <textarea
                                    className={ui.aiChatInput}
                                    rows={1}
                                    value={question}
                                    disabled={!chatId}
                                    placeholder={t('admin.ai.ask_something', 'Ask something…')}
                                    onChange={(e) => setQuestion(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !e.shiftKey) {
                                            e.preventDefault();
                                            send();
                                        }
                                    }}
                                />
                                <Button type="button" icon="check" busy={busy} disabled={!question.trim() || !chatId} onClick={send}>
                                    {t('admin.ai.send', 'Send')}
                                </Button>
                            </div>
                        </>
                    )}
                </div>
            ) : null}
            <button type="button" className={ui.aiChatFab} onClick={() => setOpen((v) => !v)}>
                {iconLabel}
            </button>
        </div>
    );
}
