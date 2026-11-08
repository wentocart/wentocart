import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Icon from '../../ui/Icon';
import { Button, ColorField, Field, Input, LocalizedField, Select, Textarea, Toggle, Thumb, ui, t, cx } from '../../ui';
import { resolveFieldCopy } from './moduleLabel';

function isUploadField(field) {
    return field?.type === 'image' || field?.type === 'file' || field?.type === 'upload';
}

function ExtensionUploadField({ field: rawField, value, onChange, error }) {
    const field = resolveFieldCopy(rawField);
    const inputRef = useRef(null);
    const [dragOver, setDragOver] = useState(false);
    const [previewUrl, setPreviewUrl] = useState('');
    const isImage = field.type === 'image';
    const picked = typeof File !== 'undefined' && value instanceof File;
    const stored = value && typeof value === 'object' && !picked && value.path ? value : null;
    const cleared = value && typeof value === 'object' && value.clear === true;
    const label = picked ? value.name : (stored?.name || '');
    const imageSrc = previewUrl || (!cleared && isImage ? (stored?.url || '') : '');

    useEffect(() => {
        if (!picked) {
            setPreviewUrl('');
            return undefined;
        }
        const url = URL.createObjectURL(value);
        setPreviewUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [picked, value]);

    const hint = field.hint || (isImage
        ? t('admin.apps.upload_image_hint', 'JPG, PNG, WEBP or GIF. Drag a file here or click to choose.')
        : t('admin.apps.upload_file_hint', 'PDF, Office, CSV, TXT, ZIP or images. Drag a file here or click to choose.'));

    return (
        <Field label={field.label} hint={hint} error={error} required={field.required}>
            <input
                ref={inputRef}
                type="file"
                accept={field.accept || (isImage ? 'image/jpeg,image/png,image/webp,image/gif' : undefined)}
                className={ui.srOnly}
                onChange={(event) => onChange(event.target.files?.[0] || value)}
            />
            <button
                type="button"
                className={cx(ui.importDrop, dragOver && ui.importDropActive)}
                onClick={() => inputRef.current?.click()}
                onDragEnter={(event) => { event.preventDefault(); setDragOver(true); }}
                onDragOver={(event) => { event.preventDefault(); setDragOver(true); }}
                onDragLeave={() => setDragOver(false)}
                onDrop={(event) => {
                    event.preventDefault();
                    setDragOver(false);
                    const next = event.dataTransfer.files?.[0];
                    if (next) {
                        onChange(next);
                    }
                }}
            >
                {isImage && imageSrc ? (
                    <Thumb src={imageSrc} className="mx-auto h-16 w-16" />
                ) : (
                    <Icon name={isImage ? 'photo' : 'upload'} className="mx-auto h-6 w-6 text-neutral-400" />
                )}
                <div className="text-sm font-medium text-neutral-800">
                    {label || t('admin.apps.choose_or_drop', 'Choose a file or drag it here')}
                </div>
            </button>
            {(picked || stored) && !cleared ? (
                <div className={`${ui.toolbar} mt-2`}>
                    <Button
                        type="button"
                        variant="secondary"
                        icon="x"
                        onClick={() => onChange(stored ? { ...stored, clear: true } : { clear: true })}
                    >
                        {t('admin.apps.remove_file', 'Remove file')}
                    </Button>
                    {stored?.url && !picked ? (
                        <Button type="button" variant="ghost" icon="download" href={stored.url} native>
                            {t('admin.apps.download_file', 'Download')}
                        </Button>
                    ) : null}
                </div>
            ) : null}
        </Field>
    );
}

function isLocalizedField(field) {
    return field?.localized === true && (field.type === 'text' || field.type === 'textarea' || field.type === 'localized');
}

function asLocalizedMap(value, defaultLanguage, fieldDefault) {
    if (value && typeof value === 'object' && !Array.isArray(value)) {
        return { ...value };
    }
    if (typeof value === 'string') {
        return { [defaultLanguage]: value };
    }
    if (fieldDefault && typeof fieldDefault === 'object' && !Array.isArray(fieldDefault)) {
        return { ...fieldDefault };
    }
    if (typeof fieldDefault === 'string') {
        return { [defaultLanguage]: fieldDefault };
    }

    return {};
}

function useAdminLanguages() {
    const { i18n } = usePage().props;
    const defaultLanguage = i18n?.default || 'en';
    const languages = (i18n?.languages?.length
        ? i18n.languages
        : [{ code: defaultLanguage, name: i18n?.default_name || defaultLanguage.toUpperCase(), is_default: true }]);

    return { languages, defaultLanguage };
}

function matchesWhen(when, values) {
    if (!when) {
        return true;
    }
    const current = values?.[when.field];
    if (when.equals !== undefined) {
        return current === when.equals;
    }
    if (Array.isArray(when.in)) {
        return when.in.includes(current);
    }

    return true;
}

function isFieldVisible(field, values) {
    if (Array.isArray(field.show_when_all) && field.show_when_all.length > 0) {
        return field.show_when_all.every((when) => matchesWhen(when, values));
    }

    return matchesWhen(field.show_when, values);
}

function resolveLinkHref(link, values) {
    if (!link) {
        return null;
    }

    if (typeof link.href === 'string') {
        return link.href;
    }

    const field = link.href_field;
    if (field && link.href && typeof link.href === 'object') {
        return link.href[values?.[field]] || Object.values(link.href)[0] || null;
    }

    return null;
}

function FieldLink({ link, values }) {
    const href = resolveLinkHref(link, values);
    if (!link?.text || !href) {
        return null;
    }

    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer"
            className="inline-flex items-center gap-1 text-xs italic text-neutral-600 hover:text-violet-700"
        >
            {link.text}
            <Icon name="link" className="h-3 w-3 shrink-0" />
        </a>
    );
}

function wrapControlWithLink(control, link) {
    if (!link) {
        return control;
    }

    return (
        <div className="w-full space-y-1.5">
            {control}
            <div>{link}</div>
        </div>
    );
}

/** Prevent browsers from autofilling admin login into API key fields. */
function secretFieldInputProps(field) {
    return {
        name: `ext-setting-${field.name}`,
        autoComplete: field.type === 'password' ? 'new-password' : 'off',
        'data-1p-ignore': true,
        'data-lpignore': 'true',
    };
}

function AutofillSafeInput({ field, readOnlyGuard = false, ...props }) {
    const [locked, setLocked] = useState(readOnlyGuard);

    return (
        <Input
            {...props}
            {...secretFieldInputProps(field)}
            readOnly={locked || props.readOnly}
            onFocus={(event) => {
                if (locked) {
                    setLocked(false);
                }
                props.onFocus?.(event);
            }}
        />
    );
}

function PasswordFieldControl({ field, value, onChange, placeholder, error, className }) {
    const [show, setShow] = useState(false);
    const [locked, setLocked] = useState(true);

    return (
        <div className="flex items-center gap-2 max-w-lg w-full">
            <div className="relative flex-1">
                <input
                    type={show ? 'text' : 'password'}
                    name={`ext-setting-${field.name}`}
                    autoComplete="new-password"
                    data-1p-ignore="true"
                    data-lpignore="true"
                    value={value ?? ''}
                    placeholder={placeholder || (field.required ? 'Enter key' : 'Optional')}
                    readOnly={locked}
                    onFocus={() => {
                        if (locked) setLocked(false);
                    }}
                    onChange={(e) => onChange(e.target.value)}
                    className={cx(
                        ui.input,
                        'w-full font-mono text-sm tracking-normal',
                        error && ui.inputInvalid,
                        className
                    )}
                />
            </div>
            <button
                type="button"
                onClick={() => {
                    setLocked(false);
                    setShow((prev) => !prev);
                }}
                className="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-neutral-700 bg-neutral-100 hover:bg-neutral-200 hover:text-neutral-900 border border-neutral-300 rounded-xl transition cursor-pointer select-none"
                title={show ? 'Hide key' : 'Show key'}
            >
                <Icon name={show ? 'eye-off' : 'eye'} className="w-3.5 h-3.5" />
                <span>{show ? 'Hide' : 'Show'}</span>
            </button>
        </div>
    );
}

function CopyReadonlyControl({ value }) {
    const text = String(value ?? '');
    const [copied, setCopied] = useState(false);
    const timerRef = useRef(null);

    useEffect(() => () => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
        }
    }, []);

    async function copyValue() {
        if (text === '') {
            return;
        }

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(text);
            } else {
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.left = '-9999px';
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                document.body.removeChild(area);
            }
            setCopied(true);
            if (timerRef.current) {
                clearTimeout(timerRef.current);
            }
            timerRef.current = setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    }

    return (
        <div className="relative w-full max-w-xl">
            <div className={ui.inputGroup}>
                <input
                    type="text"
                    readOnly
                    value={text}
                    className={ui.configCopyInput}
                    aria-label="Read-only value"
                />
                <button
                    type="button"
                    className={ui.configCopyBtn}
                    onClick={copyValue}
                    disabled={text === ''}
                >
                    Copy
                </button>
            </div>
            {copied ? (
                <span className={ui.configCopyTip} role="status" aria-live="polite">
                    Copied
                </span>
            ) : null}
        </div>
    );
}

export function ExtensionField({ field: rawField, value, masked, onChange, values = {}, error = '', languages = [], currentLanguage, onLanguageChange, defaultLanguage = 'en' }) {
    const field = resolveFieldCopy(rawField);
    const placeholder = field.placeholder || '';
    const resolvedValue = value ?? field.default ?? (field.type === 'checkboxes' ? [] : '');
    const link = <FieldLink link={field.link} values={values} />;

    if (isUploadField(field)) {
        return <ExtensionUploadField field={field} value={value} onChange={onChange} error={error} />;
    }

    if (isLocalizedField(field)) {
        const map = asLocalizedMap(value, defaultLanguage, field.default);
        const lang = currentLanguage || defaultLanguage;
        const currentValue = map[lang] ?? '';
        const useTextarea = field.type === 'textarea' || field.input === 'textarea';
        const control = useTextarea ? (
            <Textarea
                className={ui.configInputWide}
                rows="4"
                value={currentValue}
                onChange={(e) => onChange({ ...map, [lang]: e.target.value })}
            />
        ) : (
            <Input
                wide
                className={ui.configInputWide}
                type="text"
                value={currentValue}
                onChange={(e) => onChange({ ...map, [lang]: e.target.value })}
            />
        );

        if (field.inline) {
            return (
                <div className={ui.configRow}>
                    <div className={ui.configLabelCol}>
                        <div className={ui.configLabelAlign}>
                            <span className={ui.configLabel}>{field.label}</span>
                        </div>
                    </div>
                    <div className={ui.configFieldCol}>
                        <LocalizedField
                            beside
                            hint={field.hint}
                            error={error}
                            required={field.required}
                            languages={languages}
                            currentLanguage={lang}
                            onLanguageChange={onLanguageChange}
                        >
                            {control}
                        </LocalizedField>
                    </div>
                </div>
            );
        }

        return (
            <LocalizedField
                beside
                label={field.label}
                hint={field.hint}
                error={error}
                required={field.required}
                languages={languages}
                currentLanguage={lang}
                onLanguageChange={onLanguageChange}
            >
                {control}
            </LocalizedField>
        );
    }

    if (field.type === 'color') {
        return (
            <Field label={field.label} hint={field.hint} error={error} required={field.required}>
                <ColorField value={resolvedValue} onChange={onChange} />
                {link}
            </Field>
        );
    }

    if (field.type === 'date') {
        return (
            <Field label={field.label} hint={field.hint} error={error} required={field.required}>
                <Input
                    wide
                    type="date"
                    value={resolvedValue || ''}
                    onChange={(e) => onChange(e.target.value)}
                />
                {link}
            </Field>
        );
    }

    if (field.type === 'radio') {
        return (
            <Field label={field.label} hint={field.hint} error={error} required={field.required} inline={!!field.inline} alignTop>
                <div className="space-y-2">
                    {(field.options || []).map((option) => (
                        <label key={option.value} className={ui.checkboxRow}>
                            <input
                                type="radio"
                                name={field.name}
                                value={option.value}
                                checked={String(resolvedValue) === String(option.value)}
                                onChange={() => onChange(option.value)}
                                className={ui.checkboxInput}
                            />
                            <span>{option.label}</span>
                        </label>
                    ))}
                </div>
                {link}
            </Field>
        );
    }

    if (field.type === 'checkboxes') {
        const selected = Array.isArray(resolvedValue) ? resolvedValue.map(String) : [];

        return (
            <Field label={field.label} hint={field.hint} required={field.required} inline={!!field.inline} alignTop>
                <div className={ui.checkboxList} role="group" aria-label={field.label}>
                    {(field.options || []).map((option) => {
                        const optionValue = String(option.value);
                        const checked = selected.includes(optionValue);

                        return (
                            <label key={option.value} className={ui.checkboxRow}>
                                <input
                                    type="checkbox"
                                    name={`${field.name}[]`}
                                    value={optionValue}
                                    checked={checked}
                                    onChange={() => {
                                        if (checked) {
                                            onChange(selected.filter((item) => item !== optionValue));
                                        } else {
                                            onChange([...selected, optionValue]);
                                        }
                                    }}
                                    className={ui.checkboxInput}
                                />
                                <span>{option.label}</span>
                            </label>
                        );
                    })}
                </div>
                {link}
            </Field>
        );
    }

    if (field.type === 'readonly') {
        const display = field.value ?? resolvedValue ?? '';

        return (
            <Field label={field.label} hint={field.hint} error={error} inline={!!field.inline}>
                {field.copy ? (
                    <CopyReadonlyControl value={display} />
                ) : (
                    <Input
                        className={ui.configInputWide}
                        type="text"
                        readOnly
                        value={display}
                    />
                )}
                {link}
            </Field>
        );
    }

    if (field.type === 'toggle') {
        return (
            <Toggle
                inline={!!field.inline}
                value={!!resolvedValue}
                onChange={(on) => onChange(on)}
                label={field.label}
                hint={field.hint}
                error={error}
            />
        );
    }

    if (field.inline) {
        if (field.type === 'textarea') {
            return (
                <Field label={field.label} hint={field.hint} error={error} required={field.required} inline alignTop>
                    {wrapControlWithLink(
                        <Textarea
                            className={ui.configInputWide}
                            rows="4"
                            value={resolvedValue}
                            onChange={(e) => onChange(e.target.value)}
                        />,
                        link,
                    )}
                </Field>
            );
        }

        if (field.type === 'select') {
            return (
                <Field label={field.label} hint={field.hint} error={error} required={field.required} inline>
                    {wrapControlWithLink(
                        <Select
                            wide
                            className={ui.configInputWide}
                            value={resolvedValue}
                            onChange={(e) => onChange(e.target.value)}
                        >
                            {(field.options || []).map((option) => (
                                <option key={option.value} value={option.value}>{option.label}</option>
                            ))}
                        </Select>,
                        link,
                    )}
                </Field>
            );
        }

        if (field.type === 'password') {
            return (
                <Field label={field.label} hint={field.hint} error={error} required={field.required} inline>
                    {wrapControlWithLink(
                        <PasswordFieldControl
                            field={field}
                            value={resolvedValue}
                            placeholder={placeholder}
                            error={error}
                            onChange={(val) => onChange(val)}
                        />,
                        link,
                    )}
                </Field>
            );
        }

        if (field.type === 'number') {
            return (
                <Field label={field.label} hint={field.hint} error={error} required={field.required} inline>
                    {wrapControlWithLink(
                        <Input
                            className={ui.configInput}
                            type="number"
                            value={resolvedValue}
                            onChange={(e) => onChange(e.target.value)}
                        />,
                        link,
                    )}
                </Field>
            );
        }

        const isApiKeyField = /(?:^|_)(?:secret|publishable|webhook).*key$/i.test(field.name || '');

        return (
            <Field label={field.label} hint={field.hint} error={error} required={field.required} inline>
                {wrapControlWithLink(
                    isApiKeyField ? (
                        <AutofillSafeInput
                            field={field}
                            readOnlyGuard
                            className={ui.configInputWide}
                            type="text"
                            value={resolvedValue}
                            onChange={(e) => onChange(e.target.value)}
                        />
                    ) : (
                        <Input
                            className={ui.configInputWide}
                            type="text"
                            value={resolvedValue}
                            onChange={(e) => onChange(e.target.value)}
                        />
                    ),
                    link,
                )}
            </Field>
        );
    }

    if (field.type === 'textarea') {
        return (
            <Field label={field.label} hint={field.hint} error={error} required={field.required}>
                <Textarea rows="4" value={resolvedValue} onChange={(e) => onChange(e.target.value)} />
                {link}
            </Field>
        );
    }

    if (field.type === 'select') {
        return (
            <Field label={field.label} hint={field.hint} error={error} required={field.required}>
                <Select wide value={resolvedValue} onChange={(e) => onChange(e.target.value)}>
                    {(field.options || []).map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </Select>
                {link}
            </Field>
        );
    }

    if (field.type === 'password') {
        return (
            <Field label={field.label} hint={field.hint} error={error} required={field.required}>
                <PasswordFieldControl
                    field={field}
                    value={resolvedValue}
                    placeholder={placeholder}
                    error={error}
                    onChange={(val) => onChange(val)}
                />
                {link}
            </Field>
        );
    }

    if (field.type === 'number') {
        return (
            <Field label={field.label} hint={field.hint} error={error} required={field.required}>
                <Input wide type="number" value={resolvedValue} onChange={(e) => onChange(e.target.value)} />
                {link}
            </Field>
        );
    }

    const isApiKeyField = /(?:^|_)(?:secret|publishable|webhook).*key$/i.test(field.name || '');

    return (
        <Field label={field.label} hint={field.hint} error={error} required={field.required}>
            {isApiKeyField ? (
                <AutofillSafeInput
                    field={field}
                    readOnlyGuard
                    wide
                    type="text"
                    value={resolvedValue}
                    onChange={(e) => onChange(e.target.value)}
                />
            ) : (
                <Input wide value={resolvedValue} onChange={(e) => onChange(e.target.value)} />
            )}
            {link}
        </Field>
    );
}

export default function ExtensionSettingsFields({ fields = [], values = {}, masked = {}, errors = {}, onChange, layout = 'stack' }) {
    const { languages, defaultLanguage } = useAdminLanguages();
    const [language, setLanguage] = useState(defaultLanguage);
    const visibleFields = fields.filter((field) => isFieldVisible(field, values));
    const stackClass = layout === 'config' ? ui.configStack : 'space-y-4';

    return (
        <div className={stackClass}>
            {visibleFields.map((field) => (
                <ExtensionField
                    key={field.name}
                    field={field}
                    value={values?.[field.name]}
                    masked={!!masked?.[field.name]}
                    values={values}
                    languages={languages}
                    defaultLanguage={defaultLanguage}
                    currentLanguage={language}
                    onLanguageChange={setLanguage}
                    error={errors?.[field.name] || errors?.[`values.${field.name}`] || ''}
                    onChange={(value) => onChange(field.name, value)}
                />
            ))}
        </div>
    );
}
