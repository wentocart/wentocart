import { useEffect, useRef, useState } from 'react';
import api, { csrf } from '../api.js';
import { useStore } from '../state/StoreState.jsx';
import { t } from '../i18n.js';

const VISITOR_KEY = 'wento_ai_visitor';

function visitorToken() {
    try {
        let tok = localStorage.getItem(VISITOR_KEY);
        if (!tok || !/^[a-zA-Z0-9_-]{16,64}$/.test(tok)) {
            tok = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 12)}`;
            localStorage.setItem(VISITOR_KEY, tok);
        }
        return tok;
    } catch {
        return `${Date.now().toString(36)}guest`;
    }
}

function saveVisitor(token) {
    if (token && /^[a-zA-Z0-9_-]{16,64}$/.test(token)) {
        try {
            localStorage.setItem(VISITOR_KEY, token);
        } catch {
            /* ignore */
        }
    }
}

function MessageBody({ msg }) {
    return (
        <div>
            {msg.text ? <p className="whitespace-pre-wrap">{msg.text}</p> : null}
            {msg.kind === 'table' && msg.table?.length ? (
                <div className="shop-ai-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                {(msg.columns || Object.keys(msg.table[0] || {})).map((col) => (
                                    <th key={col}>{col}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {msg.table.map((row, idx) => (
                                <tr key={idx}>
                                    {(msg.columns || Object.keys(row)).map((col) => (
                                        <td key={col}>{String(row[col] ?? '')}</td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : null}
        </div>
    );
}

/**
 * Floating storefront Shop AI — gate form + multi-chat + terms modal.
 */
export default function ShopAiWidget() {
    const { shop, customer } = useStore();
    const cfg = shop?.ai || {};
    const [open, setOpen] = useState(false);
    const [wide, setWide] = useState(false);
    const [busy, setBusy] = useState(false);
    const [question, setQuestion] = useState('');
    const [messages, setMessages] = useState([]);
    const [chats, setChats] = useState([]);
    const [chatId, setChatId] = useState(null);
    const [chatTitle, setChatTitle] = useState('');
    const [showList, setShowList] = useState(true);
    const [gate, setGate] = useState(true);
    const [title, setTitle] = useState('');
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [message, setMessage] = useState('');
    const [accepted, setAccepted] = useState(false);
    const [termsOpen, setTermsOpen] = useState(false);
    const [terms, setTerms] = useState(null);
    const [error, setError] = useState('');
    const bodyRef = useRef(null);
    const token = visitorToken();

    useEffect(() => {
        if (!cfg.enabled) return;
        // Prefill from logged-in customer when available.
        if (customer) {
            const n = customer.name || [customer.first_name, customer.last_name].filter(Boolean).join(' ');
            setName(n || '');
            setEmail(customer.email || '');
        }
    }, [customer, cfg.enabled]);

    useEffect(() => {
        if (!open || !cfg.enabled) return;
        loadChats();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, cfg.enabled]);

    useEffect(() => {
        if (bodyRef.current) {
            bodyRef.current.scrollTop = bodyRef.current.scrollHeight;
        }
    }, [messages, gate]);

    if (!cfg.enabled) {
        return null;
    }

    const loadChats = async () => {
        try {
            const { data } = await api.get('/ai/assistant/chats', {
                headers: { 'X-Ai-Visitor': token },
                params: { visitor_token: token },
            });
            if (data.visitor_token) saveVisitor(data.visitor_token);
            const rows = data.chats || [];
            setChats(rows);
            // Show history when chats exist so the customer can pick one.
            if (rows.length > 0 && gate) {
                setShowList(true);
            }
        } catch {
            /* ignore */
        }
    };

    const openChat = async (id) => {
        setBusy(true);
        setError('');
        try {
            const { data } = await api.get(`/ai/assistant/chats/${id}`, {
                headers: { 'X-Ai-Visitor': token },
                params: { visitor_token: token },
            });
            const chat = data.chat;
            setChatId(chat.id);
            setChatTitle(chat.title);
            setTitle(chat.title || '');
            setMessages(chat.messages || []);
            setName(chat.name || name);
            setEmail(chat.email || email);
            setGate(false);
            setShowList(false);
            if (data.visitor_token) saveVisitor(data.visitor_token);
        } catch (e) {
            setError(e.response?.data?.message || e.message || 'Could not open chat.');
        } finally {
            setBusy(false);
        }
    };

    const startNew = () => {
        setChatId(null);
        setChatTitle('');
        setTitle('');
        setMessages([]);
        setMessage('');
        setAccepted(false);
        setGate(true);
        setShowList(true);
        setError('');
        if (customer) {
            const n = customer.name || [customer.first_name, customer.last_name].filter(Boolean).join(' ');
            setName(n || '');
            setEmail(customer.email || '');
        }
    };

    const openTerms = async (e) => {
        e?.preventDefault?.();
        setTermsOpen(true);
        if (terms) return;
        try {
            const { data } = await api.get('/ai/assistant/terms');
            setTerms(data.terms || null);
        } catch {
            setTerms({ title: cfg.terms_title || 'Terms', body: '<p>Terms unavailable.</p>' });
        }
    };

    const submitGate = async (e) => {
        e.preventDefault();
        if (!accepted) {
            setError(t('storefront.ai.accept_terms_required', 'Please accept the terms and conditions.'));
            return;
        }
        setBusy(true);
        setError('');
        try {
            await csrf();
            const { data } = await api.post('/ai/assistant/chats', {
                title: title.trim(),
                name,
                email,
                message,
                accepted_terms: true,
                visitor_token: token,
            }, { headers: { 'X-Ai-Visitor': token } });
            if (data.visitor_token) saveVisitor(data.visitor_token);
            const chat = data.chat;
            setChatId(chat.id);
            setChatTitle(chat.title);
            setTitle(chat.title || '');
            setMessages(chat.messages || []);
            setGate(false);
            setShowList(false);
            await loadChats();
        } catch (err) {
            setError(err.response?.data?.message || err.message || t('storefront.ai.failed', 'Could not start chat.'));
        } finally {
            setBusy(false);
        }
    };

    const send = async () => {
        const q = question.trim();
        if (!q || !chatId || busy) return;
        setQuestion('');
        setMessages((prev) => [...prev, { role: 'user', kind: 'text', text: q }]);
        setBusy(true);
        try {
            await csrf();
            const { data } = await api.post('/ai/assistant/ask', {
                chat_id: chatId,
                question: q,
                visitor_token: token,
            }, { headers: { 'X-Ai-Visitor': token } });
            if (data.chat?.messages) {
                setMessages(data.chat.messages);
            } else if (data.result) {
                setMessages((prev) => [...prev, data.result]);
            }
            await loadChats();
        } catch (err) {
            setMessages((prev) => [...prev, {
                role: 'ai',
                kind: 'text',
                text: err.response?.data?.message || cfg.fallback_message || t('storefront.ai.failed', 'Could not get an answer.'),
            }]);
        } finally {
            setBusy(false);
        }
    };

    const fabLabel = cfg.icon_type === 'icon'
        ? '✦'
        : (cfg.icon_text || t('storefront.ai.ask_ai', 'Ask AI'));

    return (
        <div className="shop-ai-root">
            {open ? (
                <div className={wide ? 'shop-ai-panel shop-ai-panel-wide' : 'shop-ai-panel'} role="dialog" aria-label={t('storefront.ai.title', 'Shop AI')}>
                    <div className="shop-ai-header">
                        <div className="shop-ai-header-left">
                            <button
                                type="button"
                                className="shop-ai-icon-btn"
                                onClick={() => setShowList((v) => !v)}
                                title={t('storefront.ai.chats', 'Chat history')}
                                aria-label={t('storefront.ai.chats', 'Chat history')}
                            >
                                ☰
                            </button>
                            <span className="shop-ai-header-title">{chatTitle || t('storefront.ai.title', 'Shop AI')}</span>
                        </div>
                        <div className="shop-ai-header-actions">
                            <button type="button" className="shop-ai-icon-btn" onClick={startNew} title={t('storefront.ai.new_chat', 'New chat')}>＋</button>
                            <button type="button" className="shop-ai-icon-btn" onClick={() => setWide((v) => !v)} title={t('storefront.ai.expand', 'Expand')}>{wide ? '⤡' : '⤢'}</button>
                            <button type="button" className="shop-ai-icon-btn" onClick={() => setOpen(false)} title={t('storefront.ai.close', 'Close')}>✕</button>
                        </div>
                    </div>

                    {showList ? (
                        <div className="shop-ai-list">
                            <div className="shop-ai-list-head">
                                <span>{t('storefront.ai.your_chats', 'Your chats')}</span>
                                <button type="button" className="shop-ai-link" onClick={startNew}>
                                    {t('storefront.ai.new_chat', 'New chat')}
                                </button>
                            </div>
                            {(chats || []).length === 0 ? (
                                <p className="shop-ai-muted shop-ai-list-empty">{t('storefront.ai.no_chats', 'No saved chats yet. Start one below.')}</p>
                            ) : (
                                chats.map((row) => (
                                    <button
                                        key={row.id}
                                        type="button"
                                        className={row.id === chatId ? 'shop-ai-list-item shop-ai-list-item-active' : 'shop-ai-list-item'}
                                        onClick={() => openChat(row.id)}
                                        disabled={busy}
                                    >
                                        <div className="shop-ai-list-title">{row.title}</div>
                                        <div className="shop-ai-muted">{row.updated_at}</div>
                                    </button>
                                ))
                            )}
                        </div>
                    ) : null}

                    {gate ? (
                        <form className="shop-ai-gate" onSubmit={submitGate}>
                            <p className="shop-ai-gate-intro">
                                {chats.length > 0
                                    ? t('storefront.ai.gate_intro_or_pick', 'Pick a chat above, or start a new one with a title and your first question.')
                                    : t('storefront.ai.gate_intro', 'Add a title, tell us how to reach you, accept the terms, and ask your first question.')}
                            </p>
                            {error ? <p className="shop-ai-error">{error}</p> : null}
                            <label>
                                <span>{t('storefront.ai.chat_title', 'Title')}</span>
                                <input
                                    required
                                    maxLength={160}
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    placeholder={t('storefront.ai.chat_title_ph', 'e.g. Shirt questions')}
                                />
                            </label>
                            <label>
                                <span>{t('storefront.ai.name', 'Name')}</span>
                                <input required value={name} onChange={(e) => setName(e.target.value)} />
                            </label>
                            <label>
                                <span>{t('storefront.ai.email', 'Email')}</span>
                                <input required type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
                            </label>
                            <label>
                                <span>{t('storefront.ai.message', 'Message')}</span>
                                <textarea required rows={3} value={message} onChange={(e) => setMessage(e.target.value)} placeholder={t('storefront.ai.message_ph', 'Ask about products, shipping, orders…')} />
                            </label>
                            <label className="shop-ai-check">
                                <input type="checkbox" checked={accepted} onChange={(e) => setAccepted(e.target.checked)} />
                                <span>
                                    {t('storefront.ai.accept_prefix', 'I accept the')}
                                    {' '}
                                    <button type="button" className="shop-ai-link" onClick={openTerms}>
                                        {cfg.terms_title || t('storefront.ai.terms', 'terms and conditions')}
                                    </button>
                                </span>
                            </label>
                            <button type="submit" className="shop-ai-send" disabled={busy}>
                                {busy ? t('storefront.ai.starting', 'Starting…') : t('storefront.ai.start_chat', 'Start chat')}
                            </button>
                        </form>
                    ) : (
                        <>
                            <div className="shop-ai-body" ref={bodyRef}>
                                {messages.map((msg, idx) => (
                                    <div key={idx} className={msg.role === 'user' ? 'shop-ai-bubble-user' : 'shop-ai-bubble-ai'}>
                                        <MessageBody msg={msg} />
                                    </div>
                                ))}
                                {busy ? <div className="shop-ai-bubble-ai shop-ai-muted">{t('storefront.ai.thinking', 'Thinking…')}</div> : null}
                            </div>
                            <div className="shop-ai-input-row">
                                <textarea
                                    className="shop-ai-input"
                                    rows={1}
                                    value={question}
                                    placeholder={t('storefront.ai.ask_something', 'Ask something…')}
                                    onChange={(e) => setQuestion(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !e.shiftKey) {
                                            e.preventDefault();
                                            send();
                                        }
                                    }}
                                />
                                <button type="button" className="shop-ai-send" disabled={busy || !question.trim()} onClick={send}>
                                    {t('storefront.ai.send', 'Send')}
                                </button>
                            </div>
                        </>
                    )}
                </div>
            ) : null}

            <button type="button" className="shop-ai-fab" onClick={() => setOpen((v) => !v)}>
                {fabLabel}
            </button>

            {termsOpen ? (
                <div className="shop-ai-modal-backdrop" role="presentation" onClick={() => setTermsOpen(false)}>
                    <div className="shop-ai-modal" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
                        <div className="shop-ai-modal-head">
                            <h2>{terms?.title || cfg.terms_title || t('storefront.ai.terms', 'Terms and conditions')}</h2>
                            <button type="button" className="shop-ai-icon-btn dark" onClick={() => setTermsOpen(false)}>✕</button>
                        </div>
                        <div className="shop-ai-modal-body" dangerouslySetInnerHTML={{ __html: terms?.body || '<p>Loading…</p>' }} />
                    </div>
                </div>
            ) : null}
        </div>
    );
}
