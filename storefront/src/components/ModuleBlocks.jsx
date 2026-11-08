import { useState } from 'react';
import { Link } from 'react-router-dom';
import api, { csrf } from '../api.js';
import { FormErrorBanner, FormField } from './Form.jsx';
import { parseApiErrors } from '../lib/formErrors.js';
import ExtensionIcon from './ExtensionIcon.jsx';

function shopButtonClass(variant = 'primary', look = 'default') {
    if (look === 'cart') {
        return 'shop-pdp2-cart';
    }
    if (look === 'quiet') {
        return 'shop-pdp2-quiet';
    }
    if (variant === 'secondary' || variant === 'outline') {
        return 'shop-btn shop-btn-secondary';
    }
    if (variant === 'ghost') {
        return 'shop-btn shop-btn-ghost';
    }

    return 'shop-btn shop-btn-primary';
}

function SlotIcon({ icon }) {
    return icon ? <ExtensionIcon icon={icon} className="h-4 w-4" /> : null;
}

function SlotLink({ slot, className, children, inline }) {
    const internal = typeof slot.href === 'string' && slot.href.startsWith('/');
    const body = children;
    if (internal) {
        return inline
            ? <Link className={className} to={slot.href}>{body}</Link>
            : (
                <p>
                    <Link className={className} to={slot.href}>{body}</Link>
                </p>
            );
    }

    return inline
        ? <a className={className} href={slot.href}>{body}</a>
        : (
            <p>
                <a className={className} href={slot.href}>{body}</a>
            </p>
        );
}

function CardBody({ slot }) {
    return (
        <>
            {slot.image ? (
                <img src={slot.image} alt={slot.title || ''} className="h-40 w-full object-cover" />
            ) : (
                <div className="flex h-28 items-center justify-center bg-[var(--shop-accent-soft)] text-sm text-[var(--shop-accent)]">
                    {slot.title || 'Post'}
                </div>
            )}
            <div className="space-y-2 p-4">
                {slot.title ? <h3 className="text-lg font-semibold">{slot.title}</h3> : null}
                {slot.text ? <p className="line-clamp-3 text-sm text-neutral-600 whitespace-pre-wrap">{slot.text}</p> : null}
                {slot.href ? <span className="inline-block text-sm font-semibold text-[var(--shop-accent)]">Read more →</span> : null}
            </div>
        </>
    );
}

function ModalField({ field, value, onChange, error }) {
    if (field.type === 'textarea') {
        return (
            <FormField label={field.label} error={error}>
                <textarea
                    className="shop-input"
                    rows={4}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    required
                />
            </FormField>
        );
    }

    return (
        <FormField label={field.label} error={error}>
            <input
                className="shop-input"
                type={field.type === 'number' ? 'number' : field.type === 'password' ? 'password' : 'text'}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                required
            />
        </FormField>
    );
}

function ModuleModalButton({ slot, inline = false }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState({});
    const [success, setSuccess] = useState('');
    const fields = slot.modal?.fields || [];
    const hidden = slot.modal?.hidden || {};
    const [values, setValues] = useState(() => Object.fromEntries(fields.map((field) => [field.name, ''])));

    const submit = async (event) => {
        event.preventDefault();
        setBusy(true);
        setError('');
        setFieldErrors({});
        setSuccess('');
        try {
            await csrf();
            const path = slot.modal.submit.replace(/^\/api\/v1/, '');
            const response = await api.post(path, { ...hidden, ...values });
            const doc = response.data?.document;
            if (doc?.errors?.length || (doc?.field_errors && Object.keys(doc.field_errors).length)) {
                setFieldErrors(doc.field_errors || {});
                setError((doc.errors && doc.errors[0]) || 'Please fix the highlighted fields.');
                return;
            }
            const message = doc?.blocks?.find((block) => block.type === 'text')?.text
                || 'Thank you. Your enquiry was sent.';
            setSuccess(message);
            setValues(Object.fromEntries(fields.map((field) => [field.name, ''])));
        } catch (err) {
            const parsed = parseApiErrors(err, 'Could not send your enquiry. Please try again.');
            setFieldErrors(parsed.fields);
            setError(parsed.message);
        } finally {
            setBusy(false);
        }
    };

    const look = inline
        ? ((slot.variant === 'secondary' || slot.variant === 'ghost' || slot.variant === 'outline') ? 'quiet' : 'cart')
        : 'default';
    const btnClass = `${shopButtonClass(slot.variant, look)} inline-flex items-center gap-2`;
    const icon = slot.icon ? <ExtensionIcon icon={slot.icon} className="h-4 w-4" /> : null;
    const trigger = (
        <button type="button" className={`${btnClass} inline-flex items-center gap-2`} onClick={() => { setOpen(true); setSuccess(''); setError(''); setFieldErrors({}); }}>
            {icon}
            {slot.label}
        </button>
    );

    return (
        <>
            {inline ? trigger : <p>{trigger}</p>}
            {open ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                        <div className="flex items-start justify-between gap-4">
                            <h2 className="text-xl font-bold">{slot.modal?.title || slot.label}</h2>
                            <button type="button" className="text-neutral-500" onClick={() => setOpen(false)} aria-label="Close">×</button>
                        </div>
                        {success ? (
                            <p className="mt-4 text-sm text-green-700">{success}</p>
                        ) : (
                            <form className="mt-4 space-y-4" onSubmit={submit}>
                                <FormErrorBanner messages={error} />
                                {fields.map((field) => (
                                    <ModalField
                                        key={field.name}
                                        field={field}
                                        value={values[field.name] || ''}
                                        error={fieldErrors[field.name]}
                                        onChange={(value) => setValues((current) => ({ ...current, [field.name]: value }))}
                                    />
                                ))}
                                <div className="flex justify-end gap-3">
                                    <button type="button" className="shop-btn shop-btn-secondary" onClick={() => setOpen(false)}>Cancel</button>
                                    <button type="submit" className="shop-btn shop-btn-primary" disabled={busy}>{busy ? 'Sending…' : 'Submit'}</button>
                                </div>
                            </form>
                        )}
                    </div>
                </div>
            ) : null}
        </>
    );
}

export default function ModuleBlocks({ slots = [], inline = false }) {
    if (!slots?.length) {
        return null;
    }

    const cards = [];
    const rest = [];
    slots.forEach((slot, i) => {
        if (slot.type === 'card') {
            cards.push({ slot, i });
        } else {
            rest.push({ slot, i });
        }
    });

    const restClass = inline ? 'flex flex-wrap items-center gap-2' : 'space-y-3';

    return (
        <div className={inline ? 'contents' : 'space-y-6'}>
            {cards.length > 0 ? (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {cards.map(({ slot, i }) => {
                        const className = 'shop-card block overflow-hidden transition hover:shadow-md';
                        if (slot.href && typeof slot.href === 'string' && slot.href.startsWith('/')) {
                            return (
                                <Link key={i} to={slot.href} className={className}>
                                    <CardBody slot={slot} />
                                </Link>
                            );
                        }
                        if (slot.href) {
                            return (
                                <a key={i} href={slot.href} className={className}>
                                    <CardBody slot={slot} />
                                </a>
                            );
                        }
                        return (
                            <article key={i} className={className}>
                                <CardBody slot={slot} />
                            </article>
                        );
                    })}
                </div>
            ) : null}
            <div className={restClass}>
                {rest.map(({ slot, i }) => {
                    if (slot.type === 'table') {
                        const columns = slot.columns || [];
                        const rows = slot.rows || [];
                        return (
                            <div key={i} className="shop-module-table-wrap">
                                <table className="shop-module-table">
                                    <thead>
                                        <tr>
                                            {columns.map((col) => <th key={col.key}>{col.label}</th>)}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((row, ri) => (
                                            <tr key={ri}>
                                                {columns.map((col) => <td key={col.key}>{row[col.key] || ''}</td>)}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        );
                    }
                    if (slot.type === 'button' && slot.modal) {
                        return <ModuleModalButton key={i} slot={slot} inline={inline} />;
                    }
                    if (slot.type === 'button' || slot.type === 'link') {
                        const className = `${shopButtonClass(slot.variant, inline ? (slot.variant === 'secondary' || slot.variant === 'ghost' || slot.variant === 'outline' ? 'quiet' : 'cart') : 'default')} inline-flex items-center gap-2`;
                        const body = (
                            <>
                                <SlotIcon icon={slot.icon} />
                                {slot.label}
                            </>
                        );
                        if (slot.href) {
                            return <SlotLink key={i} slot={slot} className={className} inline={inline}>{body}</SlotLink>;
                        }
                        if (slot.type === 'link') {
                            return null;
                        }
                        return (
                            <button key={i} type="button" className={className}>
                                {body}
                            </button>
                        );
                    }
                    return slot.text ? <p key={i} className="text-sm text-neutral-600 whitespace-pre-wrap">{slot.text}</p> : null;
                })}
            </div>
        </div>
    );
}
