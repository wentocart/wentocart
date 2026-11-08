import { useMemo, useRef, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import Icon from './Icon';
import { ui, cx } from './classes';
import { t } from './i18n';

async function postAiContent(payload) {
    const xsrf = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    const res = await fetch('/admin/ai/content', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf[1]) } : {}),
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(json.message || t('admin.ai.request_failed', 'AI request failed.'));
    }
    return String(json.text || '');
}

const ACTIONS = [
    { id: 'generate', labelKey: 'admin.ai.action_generate', label: 'Generate' },
    { id: 'structure', labelKey: 'admin.ai.action_structure', label: 'Organize & format' },
    { id: 'improve', labelKey: 'admin.ai.action_improve', label: 'Improve' },
    { id: 'shorten', labelKey: 'admin.ai.action_shorten', label: 'Shorten' },
    { id: 'expand', labelKey: 'admin.ai.action_expand', label: 'Expand' },
    { id: 'seo', labelKey: 'admin.ai.action_seo', label: 'Make SEO-friendly' },
];

function isRichBodyField(field) {
    const f = String(field || '');
    if (f.includes('short_description') || f.includes('meta_')) {
        return false;
    }
    return f === 'cms.body' || f === 'product.description' || f === 'category.description'
        || f === 'body' || f === 'description' || /\.body$|\.description$/.test(f);
}

/**
 * Empuls-style field assist: pill over the field, menu of rewrite actions.
 */
export default function AiAssist({
    value = '',
    onApply,
    field = 'text',
    locale,
    context = {},
    className,
    mode = 'overlay', // overlay | inline
}) {
    const { ai, i18n } = usePage().props;
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [dismissed, setDismissed] = useState(false);
    const lastRef = useRef(null);
    const enabled = !!ai?.content_enabled;
    const activeLocale = locale || i18n?.current || i18n?.locale || 'en';
    const hasText = String(value || '').replace(/<[^>]+>/g, '').trim() !== '';
    const richBody = isRichBodyField(field);

    const actions = useMemo(() => {
        const base = richBody ? ACTIONS : ACTIONS.filter((row) => row.id !== 'structure');
        if (!hasText) {
            return base.filter((row) => row.id === 'generate' || row.id === 'structure');
        }
        return base;
    }, [hasText, richBody]);

    if (dismissed || !enabled) {
        return null;
    }

    const run = async (action) => {
        setError('');
        setOpen(false);
        if (!enabled) {
            setError(t('admin.ai.configure_first', 'Enable AI and add an API key under Configure → AI.'));
            return;
        }
        setBusy(true);
        try {
            lastRef.current = value;
            const text = await postAiContent({
                action,
                field,
                text: value || '',
                locale: activeLocale,
                context,
            });
            onApply?.(text, { action, previous: lastRef.current });
        } catch (e) {
            setError(e.message || t('admin.ai.request_failed', 'AI request failed.'));
        } finally {
            setBusy(false);
        }
    };

    const undo = () => {
        if (lastRef.current !== null) {
            onApply?.(lastRef.current, { action: 'undo' });
            lastRef.current = null;
        }
    };

    const pill = (
        <div className={cx(mode === 'overlay' ? ui.aiAssistBar : 'relative inline-flex', className)}>
            <div className={ui.aiAssistCluster}>
                {open ? (
                    <div className={ui.aiAssistMenu} role="menu">
                        {actions.map((row) => (
                            <button
                                key={row.id}
                                type="button"
                                role="menuitem"
                                className={ui.aiAssistMenuItem}
                                disabled={busy}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => run(row.id)}
                            >
                                {t(row.labelKey, row.label)}
                            </button>
                        ))}
                        {lastRef.current !== null ? (
                            <button
                                type="button"
                                role="menuitem"
                                className={ui.aiAssistMenuItem}
                                disabled={busy}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={undo}
                            >
                                {t('admin.ai.undo', 'Undo last AI change')}
                            </button>
                        ) : null}
                        {!enabled ? (
                            <Link
                                href="/admin/ai"
                                className={cx(ui.aiAssistMenuItem, 'text-sky-700')}
                                onMouseDown={(e) => e.preventDefault()}
                            >
                                {t('admin.ai.open_settings', 'Open AI settings')}
                            </Link>
                        ) : null}
                    </div>
                ) : null}
                <button
                    type="button"
                    className={ui.aiAssistPill}
                    disabled={busy}
                    aria-haspopup="menu"
                    aria-expanded={open}
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={() => setOpen((v) => !v)}
                >
                    <Icon name={busy ? 'spinner' : 'sparkles'} className={busy ? 'animate-spin' : undefined} />
                    {busy
                        ? t('admin.ai.working', 'Working…')
                        : hasText
                            ? t('admin.ai.improve_with_ai', 'Improve with AI')
                            : t('admin.ai.generate_with_ai', 'Generate with AI')}
                </button>
            </div>
            <button
                type="button"
                className={ui.aiAssistDismiss}
                title={t('admin.ai.dismiss', 'Hide')}
                aria-label={t('admin.ai.dismiss', 'Hide')}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => setDismissed(true)}
            >
                <Icon name="x" />
            </button>
        </div>
    );

    return (
        <div className={mode === 'overlay' ? ui.aiAssistWrap : undefined}>
            {pill}
            {error ? (
                <p className={ui.aiAssistHint}>
                    {error}{' '}
                    {!ai?.configured ? (
                        <Link href="/admin/ai" className="underline">
                            {t('admin.ai.settings', 'AI settings')}
                        </Link>
                    ) : null}
                </p>
            ) : null}
        </div>
    );
}
