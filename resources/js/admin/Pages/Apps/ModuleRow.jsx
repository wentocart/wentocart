import { useEffect, useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Badge,
    Button,
    FormErrors,
    Icon,
    Loader,
    ui,
    t,
    cx,
} from '../../ui';
import { adminUrl } from '../../webPath';
import { DEFAULT_CATALOG_IMAGES } from '../../lib/catalogMedia';
import { alertMessage, categoryTone, iconForCategory } from './extensionManager';
import { extensionStatusBadges, useExtensionRowActions } from './extensionActions';
import ExtensionSplitButton from './ExtensionSplitButton';
import ExtensionCheckbox from './ExtensionCheckbox';
import { ExtensionCategoryToggle, useCategorySlide } from './ExtensionCard';

function ModuleBadges({ row }) {
    const badges = extensionStatusBadges(row);

    if (!badges.length) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {badges.map((badge) => (
                <Badge key={badge.key} tone={badge.tone} dot={badge.dot !== false}>
                    {badge.label}
                </Badge>
            ))}
        </div>
    );
}

export default function ModuleRow({ row, selected, onToggle, selectable, zebra }) {
    const [logoMode, setLogoMode] = useState(row.preview ? 'preview' : 'default');
    const issue = alertMessage(row);
    const { busy, primary, menuItems } = useExtensionRowActions(row);
    const logoSrc = logoMode === 'preview' && row.preview
        ? adminUrl(row.preview)
        : logoMode === 'default'
            ? adminUrl(DEFAULT_CATALOG_IMAGES.md)
            : null;

    return (
        <div className={cx(
            zebra ? 'border-b border-neutral-100 bg-neutral-50/60' : 'border-b border-neutral-100 bg-white',
            'relative',
            busy && 'pointer-events-none',
        )}
        >
            {busy ? (
                <div className="absolute inset-0 z-20 flex items-center justify-center bg-white/70" role="status" aria-live="polite">
                    <Loader inline />
                    <span className="ml-2 text-sm text-neutral-600">{t('admin.common.loading', 'Loading…')}</span>
                </div>
            ) : null}
            <div className="flex flex-col gap-3 px-3 py-3 lg:flex-row lg:items-start lg:gap-4">
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    {selectable ? (
                        <div className="pt-0.5">
                            <ExtensionCheckbox
                                checked={selected}
                                onChange={onToggle}
                                ariaLabel={`Select ${row.display_name}`}
                                variant="list"
                            />
                        </div>
                    ) : (
                        <span className="hidden w-5 shrink-0 lg:block" aria-hidden="true" />
                    )}

                    <div className={cx(
                        'flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border',
                        logoSrc ? 'border-neutral-200 bg-neutral-50' : categoryTone(row.category).tile,
                    )}>
                        {logoSrc ? (
                            <img
                                src={logoSrc}
                                alt=""
                                className="h-full w-full object-cover"
                                loading="lazy"
                                decoding="async"
                                onError={() => setLogoMode((mode) => (mode === 'preview' ? 'default' : 'icon'))}
                            />
                        ) : (
                            <Icon name={iconForCategory(row.category)} className="h-5 w-5" />
                        )}
                    </div>

                    <div className="min-w-0 flex-1 space-y-1">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <h3 className="text-sm font-semibold text-neutral-900">{row.display_name}</h3>
                            <span className="text-xs text-neutral-500">
                                v{row.version} · {t('admin.apps.by', 'by')} {row.author || row.vendor}
                            </span>
                        </div>
                        <p className="text-xs text-neutral-500">{row.id}</p>
                        <p className="line-clamp-2 text-sm text-neutral-600">{row.description}</p>
                        <ModuleBadges row={row} />
                        {issue ? (
                            <p className="text-xs text-amber-700">{issue}</p>
                        ) : null}
                    </div>
                </div>

                <div className="shrink-0 self-stretch lg:self-start pl-7 lg:pl-0">
                    <ExtensionSplitButton
                        row={row}
                        primary={primary}
                        menuItems={menuItems}
                        className={ui.splitBtnWrapList}
                        busy={busy}
                    />
                </div>
            </div>
        </div>
    );
}

export function ModuleGroup({ groupKey, label, rows, count, selection }) {
    const [open, setOpen] = useState(true);
    const panelRef = useCategorySlide(open);

    if (!rows.length) {
        return null;
    }

    const total = count ?? rows.length;
    const panelId = `extension-category-list-${groupKey || 'group'}`;

    return (
        <section>
            <h2>
                <ExtensionCategoryToggle
                    label={label}
                    count={total}
                    category={groupKey}
                    open={open}
                    panelId={panelId}
                    onToggle={() => setOpen((current) => !current)}
                />
            </h2>
            <div
                ref={panelRef}
                id={panelId}
                inert={open ? undefined : true}
                className="min-h-0"
            >
                <div className="mt-3 overflow-hidden rounded-2xl border border-neutral-200/80 bg-white shadow-[0_12px_40px_-28px_rgba(15,23,42,0.35)]">
                    {rows.map((row, index) => (
                        <ModuleRow
                            key={row.key}
                            row={row}
                            zebra={index % 2 === 1}
                            selectable={row.installed}
                            selected={selection?.isSelected(row.key)}
                            onToggle={() => selection?.toggle(row.key)}
                        />
                    ))}
                </div>
            </div>
        </section>
    );
}

export function UploadModuleModal({ open, onClose }) {
    const form = useForm({ zip: null });
    const fileRef = useRef(null);

    const clearInstallState = () => {
        form.reset();
        form.clearErrors();
        if (fileRef.current) {
            fileRef.current.value = '';
        }
    };

    useEffect(() => {
        if (open) {
            clearInstallState();
        }
    }, [open]);

    const close = () => {
        clearInstallState();
        onClose();
    };

    if (!open) {
        return null;
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-neutral-900/40 p-4">
            <div className="w-full max-w-lg rounded-2xl border border-neutral-200 bg-white p-5 shadow-xl">
                <div className="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-semibold text-neutral-900">{t('admin.apps.install_extension', 'Install Extension')}</h2>
                        <p className={ui.muted}>{t('admin.apps.zip_hint_install', 'Upload a zip with app.json at the root. New extensions install and enable. A newer zip for an installed extension is staged — use Upgrade on the row to apply (settings kept).')}</p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={close} />
                </div>
                <form
                    className="space-y-4"
                    encType="multipart/form-data"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post('/admin/extensions', {
                            forceFormData: true,
                            onSuccess: () => {
                                clearInstallState();
                                onClose();
                            },
                        });
                    }}
                >
                    <FormErrors errors={form.errors} />
                    <label className="block text-sm font-medium text-neutral-800">
                        {t('admin.apps.zip_file', 'Extension zip')}
                        <input
                            ref={fileRef}
                            type="file"
                            accept=".zip,application/zip"
                            className={`${ui.inputFull} mt-2`}
                            onChange={(e) => {
                                form.clearErrors();
                                form.setData('zip', e.target.files?.[0] || null);
                            }}
                        />
                    </label>
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={close}>
                            {t('admin.common.cancel', 'Cancel')}
                        </Button>
                        <Button type="submit" icon="upload" busy={form.processing} disabled={!form.data.zip}>
                            {t('admin.apps.install_extension', 'Install Extension')}
                        </Button>
                    </div>
                </form>
            </div>
        </div>
    );
}
