import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Button from './Button';
import Icon from './Icon';
import Alert from './Alert';
import { Field, Input, Select } from './Field';
import { ui, cx } from './classes';
import { t } from './i18n';
import { adminUrl } from '../webPath';

export function cleanListQuery(payload = {}) {
    const next = { ...payload };
    Object.keys(next).forEach((key) => {
        if (next[key] === '' || next[key] == null || next[key] === 'all') {
            delete next[key];
        }
    });
    return next;
}

export function buildListQueryUrl(baseUrl, params = {}) {
    const qs = new URLSearchParams();
    Object.entries(cleanListQuery(params)).forEach(([key, value]) => {
        qs.set(key, String(value));
    });
    const query = qs.toString();
    return query ? `${baseUrl}?${query}` : baseUrl;
}

function ModalShell({ title, subtitle, children, footer, onClose }) {
    useEffect(() => {
        const onKey = (e) => {
            if (e.key === 'Escape') {
                onClose?.();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    return (
        <div className={ui.modalBackdrop} role="presentation" onClick={onClose}>
            <div
                className={`${ui.modal} max-w-xl`}
                role="dialog"
                aria-modal="true"
                aria-label={title}
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 className={ui.modalTitle}>{title}</h2>
                        {subtitle ? <p className={ui.modalSub}>{subtitle}</p> : null}
                    </div>
                    <button type="button" className={ui.menuBtn} onClick={onClose} aria-label={t('admin.common.close', 'Close')}>
                        <Icon name="x" />
                    </button>
                </div>
                <div className={ui.modalBody}>{children}</div>
                {footer ? <div className={ui.modalFoot}>{footer}</div> : null}
            </div>
        </div>
    );
}

export function ImportDataModal({
    open,
    onClose,
    importUrl,
    sampleUrl,
    accept = '.csv,text/csv',
    languages = [],
    defaultLanguage = '',
    defaultDelimiter = ';',
    defaultMultiSeparator = ',',
    showCsvSettings = false,
}) {
    const inputRef = useRef(null);
    const [file, setFile] = useState(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [dragOver, setDragOver] = useState(false);
    const [language, setLanguage] = useState(defaultLanguage);
    const [delimiter, setDelimiter] = useState(defaultDelimiter);
    const [multiSeparator, setMultiSeparator] = useState(defaultMultiSeparator);

    useEffect(() => {
        if (!open) {
            setFile(null);
            setError('');
            setBusy(false);
            setDragOver(false);
            if (inputRef.current) {
                inputRef.current.value = '';
            }
            return;
        }
        setLanguage(defaultLanguage || languages.find((row) => row.is_default)?.code || languages[0]?.code || 'en');
        setDelimiter(defaultDelimiter || ';');
        setMultiSeparator(defaultMultiSeparator || ',');
    }, [open, defaultLanguage, defaultDelimiter, defaultMultiSeparator, languages]);

    if (!open) {
        return null;
    }

    const pickFile = (next) => {
        if (!next) {
            setFile(null);
            return;
        }
        const name = String(next.name || '').toLowerCase();
        if (!name.endsWith('.csv') && next.type !== 'text/csv' && next.type !== 'application/vnd.ms-excel') {
            setError(t('admin.list.import_csv_only', 'Please choose a CSV file.'));
            setFile(null);
            return;
        }
        setError('');
        setFile(next);
    };

    const csvSettingsPayload = showCsvSettings
        ? {
            language,
            delimiter,
            multivalue_separator: multiSeparator,
        }
        : {};

    const sampleHref = sampleUrl
        ? buildListQueryUrl(sampleUrl, showCsvSettings ? {
            delimiter,
            multivalue_separator: multiSeparator,
            language,
        } : {})
        : null;

    const languageOptions = (languages && languages.length)
        ? languages
        : [{ code: language || 'en', name: language || t('admin.list.language_english', 'English'), is_default: true }];

    const submit = () => {
        if (!importUrl) {
            return;
        }
        if (!file) {
            setError(t('admin.list.import_file_required', 'Please select a CSV file to import.'));
            return;
        }
        if (showCsvSettings && !language) {
            setError(t('admin.list.import_language_required', 'Please select the language of the file.'));
            return;
        }
        if (showCsvSettings && !delimiter) {
            setError(t('admin.list.import_delimiter_required', 'Please set the field separator.'));
            return;
        }
        if (showCsvSettings && !multiSeparator) {
            setError(t('admin.list.import_multi_separator_required', 'Please set the multiple value separator.'));
            return;
        }
        setBusy(true);
        router.post(importUrl, { file, ...csvSettingsPayload }, {
            forceFormData: true,
            onFinish: () => setBusy(false),
            onSuccess: () => onClose?.(),
            onError: (errors) => {
                const pick = (value) => {
                    if (Array.isArray(value)) {
                        return value.filter(Boolean).join('\n');
                    }
                    return value || '';
                };
                setError(
                    pick(errors?.file)
                    || pick(errors?.language)
                    || pick(errors?.delimiter)
                    || pick(errors?.multivalue_separator)
                    || t('admin.list.import_failed', 'Import failed. Check the file and try again.'),
                );
            },
        });
    };

    return (
        <ModalShell
            title={t('admin.list.import_data', 'Import Data')}
            subtitle={t('admin.list.import_subtitle', 'Upload a CSV file to import records into this list.')}
            onClose={onClose}
            footer={(
                <div className={ui.importModalFoot}>
                    {sampleHref ? (
                        <a href={sampleHref} download className={ui.importSampleLink}>
                            <Icon name="download" className={ui.icon} />
                            {t('admin.list.download_sample_csv', 'Download sample CSV')}
                        </a>
                    ) : null}
                    <div className={ui.importModalFootAction}>
                        <Button type="button" icon="upload" busy={busy} onClick={submit}>
                            {t('admin.common.import', 'Import')}
                        </Button>
                    </div>
                </div>
            )}
        >
            {error ? (
                <Alert type="error" className="mb-4 whitespace-pre-line">
                    {error}
                </Alert>
            ) : null}
            <input
                ref={inputRef}
                type="file"
                accept={accept}
                className={ui.srOnly}
                onChange={(e) => pickFile(e.target.files?.[0] || null)}
            />
            <button
                type="button"
                className={cx(ui.importDrop, dragOver && ui.importDropActive)}
                onClick={() => inputRef.current?.click()}
                onDragEnter={(e) => { e.preventDefault(); setDragOver(true); }}
                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                onDragLeave={() => setDragOver(false)}
                onDrop={(e) => {
                    e.preventDefault();
                    setDragOver(false);
                    pickFile(e.dataTransfer.files?.[0] || null);
                }}
            >
                <Icon name="upload" className="mx-auto h-6 w-6 text-neutral-400" />
                <div className="text-sm font-medium text-neutral-800">
                    {file
                        ? file.name
                        : t('admin.list.choose_csv', 'Choose a CSV file or drag it here')}
                </div>
                <div className="text-xs text-neutral-500">
                    {t('admin.list.csv_hint', 'Accepted format: .csv')}
                </div>
            </button>

            {showCsvSettings ? (
                <div className={cx(ui.fieldGrid, 'mt-4')}>
                    <Field
                        label={(
                            <>
                                {t('admin.list.language_of_file', 'Language of the file')}
                                {' '}
                                <span className={ui.req}>*</span>
                            </>
                        )}
                        hint={t('admin.list.language_of_file_hint', 'The locale must be installed')}
                    >
                        <Select wide value={language} onChange={(e) => setLanguage(e.target.value)} required>
                            {languageOptions.map((lang) => (
                                <option key={lang.code} value={lang.code}>
                                    {lang.name || lang.code}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field
                        label={(
                            <>
                                {t('admin.list.field_separator', 'Field separator')}
                                {' '}
                                <span className={ui.req}>*</span>
                            </>
                        )}
                        hint={t('admin.list.field_separator_hint', 'e.g. sku;name;price')}
                    >
                        <Select wide value={delimiter} onChange={(e) => setDelimiter(e.target.value)} required>
                            <option value=";">;</option>
                            <option value=",">,</option>
                            <option value="|">|</option>
                            <option value={'\t'}>{t('admin.list.delimiter_tab', 'Tab')}</option>
                        </Select>
                    </Field>
                    <Field
                        className="sm:col-span-2"
                        label={(
                            <>
                                {t('admin.list.multiple_value_separator', 'Multiple value separator')}
                                {' '}
                                <span className={ui.req}>*</span>
                            </>
                        )}
                        hint={t(
                            'admin.list.multiple_value_separator_hint',
                            'e.g. Blouse; red.jpg, blue.jpg, green.jpg; 129.90',
                        )}
                    >
                        <Select wide value={multiSeparator} onChange={(e) => setMultiSeparator(e.target.value)} required>
                            <option value=",">,</option>
                            <option value=";">;</option>
                            <option value="|">|</option>
                        </Select>
                    </Field>
                </div>
            ) : null}
        </ModalShell>
    );
}

export function ExportDataModal({
    open,
    onClose,
    exportUrl,
    query = {},
    fields = [],
}) {
    const [draft, setDraft] = useState(query);

    useEffect(() => {
        if (open) {
            setDraft({ ...query });
        }
    }, [open, query]);

    if (!open) {
        return null;
    }

    const setField = (name, value) => {
        setDraft((prev) => ({ ...prev, [name]: value }));
    };

    const submit = () => {
        if (!exportUrl) {
            return;
        }
        window.location.href = adminUrl(buildListQueryUrl(exportUrl, draft));
        onClose?.();
    };

    return (
        <ModalShell
            title={t('admin.list.export_data', 'Export Data')}
            subtitle={t('admin.list.export_subtitle', 'Optionally refine filters, then export matching records as CSV.')}
            onClose={onClose}
            footer={(
                <>
                    <Button type="button" variant="secondary" onClick={onClose}>{t('admin.common.cancel', 'Cancel')}</Button>
                    <Button type="button" icon="download" onClick={submit}>{t('admin.common.export', 'Export')}</Button>
                </>
            )}
        >
            {fields.length === 0 ? (
                <p className={ui.muted}>{t('admin.list.export_all_hint', 'No extra filters configured. Export will use the current list filters.')}</p>
            ) : (
                <div className="grid gap-3 sm:grid-cols-2">
                    {fields.map((field) => {
                        if (field.type === 'select') {
                            return (
                                <Field key={field.name} label={field.label}>
                                    <Select
                                        wide
                                        value={draft[field.name] ?? 'all'}
                                        onChange={(e) => setField(field.name, e.target.value)}
                                    >
                                        <option value="all">{field.allLabel || t('admin.common.all', 'All')}</option>
                                        {(field.options || []).map((opt) => (
                                            <option key={String(opt.value)} value={opt.value}>{opt.label}</option>
                                        ))}
                                    </Select>
                                </Field>
                            );
                        }

                        return (
                            <Field key={field.name} label={field.label}>
                                <Input
                                    wide
                                    type={field.type === 'number' ? 'number' : field.type === 'date' ? 'date' : 'text'}
                                    value={draft[field.name] ?? ''}
                                    placeholder={field.placeholder || ''}
                                    onChange={(e) => setField(field.name, e.target.value)}
                                />
                            </Field>
                        );
                    })}
                </div>
            )}
        </ModalShell>
    );
}

/**
 * Shared list header settings (gear icon) + Import / Export modals.
 * Pass importUrl / importSampleUrl / exportUrl only when the resource supports those endpoints.
 * importSampleUrl should return a CSV matching the importer columns (e.g. products-sample.csv).
 */
export default function ListSettings({
    listUrl,
    importUrl,
    importSampleUrl,
    exportUrl,
    query = {},
    exportFields = [],
    importLanguages = [],
    importDefaultLanguage = '',
    showImportCsvSettings = false,
    className,
}) {
    const rootRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [exportOpen, setExportOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onDoc = (e) => {
            if (!rootRef.current?.contains(e.target)) {
                setOpen(false);
            }
        };
        const onKey = (e) => {
            if (e.key === 'Escape') {
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

    const refresh = () => {
        if (!listUrl) {
            router.reload({ preserveScroll: true });
            return;
        }
        router.get(listUrl, cleanListQuery(query), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const items = [
        importUrl ? {
            key: 'import',
            icon: 'upload',
            label: t('admin.common.import', 'Import'),
            onClick: () => setImportOpen(true),
        } : null,
        exportUrl ? {
            key: 'export',
            icon: 'download',
            label: t('admin.common.export', 'Export'),
            onClick: () => setExportOpen(true),
        } : null,
        {
            key: 'refresh',
            icon: 'refresh',
            label: t('admin.list.refresh_list', 'Refresh list'),
            onClick: refresh,
        },
    ].filter(Boolean);

    return (
        <>
            <div ref={rootRef} className={cx(ui.listSettings, className)}>
                <button
                    type="button"
                    className={ui.listSettingsBtn}
                    aria-haspopup="menu"
                    aria-expanded={open}
                    aria-label={t('admin.list.settings', 'List settings')}
                    onClick={() => setOpen((v) => !v)}
                >
                    <Icon name="settings" className="h-5 w-5" />
                </button>
                {open ? (
                    <div className={ui.listSettingsMenu} role="menu">
                        {items.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="menuitem"
                                className={ui.listSettingsItem}
                                onClick={() => {
                                    setOpen(false);
                                    item.onClick?.();
                                }}
                            >
                                <Icon name={item.icon} />
                                {item.label}
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>

            <ImportDataModal
                open={importOpen}
                onClose={() => setImportOpen(false)}
                importUrl={importUrl}
                sampleUrl={importSampleUrl}
                languages={importLanguages}
                defaultLanguage={importDefaultLanguage}
                showCsvSettings={showImportCsvSettings}
            />
            <ExportDataModal
                open={exportOpen}
                onClose={() => setExportOpen(false)}
                exportUrl={exportUrl}
                query={query}
                fields={exportFields}
            />
        </>
    );
}
