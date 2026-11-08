import { useEffect, useRef, useState } from 'react';
import api, { csrf } from '../api.js';
import { Icon } from './Icons.jsx';
import { SellerModalShell } from './SellerModal.jsx';
import { t } from '../i18n.js';

function ImportDataModal({ open, onClose, onImported, sampleUrl }) {
    const inputRef = useRef(null);
    const [file, setFile] = useState(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [delimiter, setDelimiter] = useState(';');
    const [multiSeparator, setMultiSeparator] = useState(',');

    useEffect(() => {
        if (!open) {
            setFile(null);
            setError('');
            setBusy(false);
            setDelimiter(';');
            setMultiSeparator(',');
            if (inputRef.current) {
                inputRef.current.value = '';
            }
        }
    }, [open]);

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
            setError(t('storefront.seller.import_csv_only', 'Please choose a CSV file.'));
            setFile(null);
            return;
        }
        setError('');
        setFile(next);
    };

    const submit = async () => {
        if (!file) {
            setError(t('storefront.seller.import_file_required', 'Please select a CSV file to import.'));
            return;
        }
        setBusy(true);
        setError('');
        try {
            await csrf();
            const body = new FormData();
            body.append('file', file);
            body.append('delimiter', delimiter);
            body.append('multivalue_separator', multiSeparator);
            const response = await api.post('/account/seller/products/import', body);
            onImported?.(response.data);
            onClose?.();
        } catch (err) {
            const data = err.response?.data;
            const message = data?.errors?.file?.[0]
                || data?.message
                || t('storefront.seller.import_failed', 'Import failed. Check the file and try again.');
            setError(message);
        } finally {
            setBusy(false);
        }
    };

    const sampleHref = sampleUrl
        ? `${sampleUrl}?delimiter=${encodeURIComponent(delimiter)}&multivalue_separator=${encodeURIComponent(multiSeparator)}`
        : null;

    return (
        <SellerModalShell
            title={t('storefront.seller.import_data', 'Import Data')}
            subtitle={t('storefront.seller.import_subtitle', 'Upload a CSV to create or update your products.')}
            onClose={onClose}
            footer={(
                <>
                    <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={onClose} disabled={busy}>
                        {t('storefront.seller.cancel', 'Cancel')}
                    </button>
                    <button type="button" className="shop-btn shop-btn-primary shop-btn--sm" onClick={submit} disabled={busy}>
                        <Icon name="upload" className="h-4 w-4 shrink-0" />
                        {busy ? t('storefront.seller.importing', 'Importing…') : t('storefront.seller.import', 'Import')}
                    </button>
                </>
            )}
        >
            {error ? <p className="mv-error-sm mb-3" role="alert">{error}</p> : null}
            <div className="mv-import-drop">
                <input
                    ref={inputRef}
                    type="file"
                    accept=".csv,text/csv"
                    className="sr-only"
                    onChange={(event) => pickFile(event.target.files?.[0] || null)}
                />
                <button type="button" className="shop-btn shop-btn-ghost shop-btn--sm" onClick={() => inputRef.current?.click()}>
                    {file ? file.name : t('storefront.seller.choose_csv', 'Choose CSV file')}
                </button>
                {sampleHref ? (
                    <a className="mv-import-sample" href={sampleHref}>
                        {t('storefront.seller.download_sample', 'Download sample CSV')}
                    </a>
                ) : null}
            </div>
            <div className="mv-import-settings">
                <label className="shop-field-label" htmlFor="seller-import-delimiter">
                    {t('storefront.seller.field_separator', 'Field separator')}
                </label>
                <select
                    id="seller-import-delimiter"
                    className="shop-input"
                    value={delimiter}
                    onChange={(event) => setDelimiter(event.target.value)}
                >
                    <option value=";">;</option>
                    <option value=",">,</option>
                    <option value="|">|</option>
                    <option value={'\t'}>Tab</option>
                </select>
                <label className="shop-field-label" htmlFor="seller-import-multi">
                    {t('storefront.seller.multi_separator', 'Multiple value separator')}
                </label>
                <select
                    id="seller-import-multi"
                    className="shop-input"
                    value={multiSeparator}
                    onChange={(event) => setMultiSeparator(event.target.value)}
                >
                    <option value=",">,</option>
                    <option value=";">;</option>
                    <option value="|">|</option>
                </select>
            </div>
        </SellerModalShell>
    );
}

export default function SellerListSettings({ onExport, onRefresh, onImported, sampleUrl = '/api/v1/account/seller/products/import/sample' }) {
    const rootRef = useRef(null);
    const [open, setOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onDoc = (event) => {
            if (rootRef.current && !rootRef.current.contains(event.target)) {
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

    const items = [
        onImported ? {
            key: 'import',
            icon: 'upload',
            label: t('storefront.seller.import', 'Import'),
            onClick: () => setImportOpen(true),
        } : null,
        onExport ? {
            key: 'export',
            icon: 'download',
            label: t('storefront.seller.export', 'Export'),
            onClick: onExport,
        } : null,
        onRefresh ? {
            key: 'refresh',
            icon: 'refresh',
            label: t('storefront.seller.refresh_list', 'Refresh list'),
            onClick: onRefresh,
        } : null,
    ].filter(Boolean);

    return (
        <>
            <div ref={rootRef} className="mv-list-settings">
                <button
                    type="button"
                    className="mv-list-settings-btn"
                    aria-haspopup="menu"
                    aria-expanded={open}
                    aria-label={t('storefront.seller.list_settings', 'List settings')}
                    onClick={() => setOpen((value) => !value)}
                >
                    <Icon name="settings" className="h-5 w-5" />
                </button>
                {open ? (
                    <div className="mv-list-settings-menu" role="menu">
                        {items.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                role="menuitem"
                                className="mv-list-settings-item"
                                onClick={() => {
                                    setOpen(false);
                                    item.onClick?.();
                                }}
                            >
                                <Icon name={item.icon} className="h-4 w-4 shrink-0" />
                                {item.label}
                            </button>
                        ))}
                    </div>
                ) : null}
            </div>
            {onImported ? (
                <ImportDataModal
                    open={importOpen}
                    onClose={() => setImportOpen(false)}
                    onImported={onImported}
                    sampleUrl={sampleUrl}
                />
            ) : null}
        </>
    );
}
