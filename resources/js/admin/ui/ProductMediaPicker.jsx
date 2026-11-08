import { useEffect, useRef, useState } from 'react';
import Button from './Button';
import Icon from './Icon';
import { Field } from './Field';
import { Thumb } from './Image';
import { ui, cx } from './classes';
import { t } from './i18n';

/**
 * Reusable product-media thumbnail picker (single select).
 * Expects images that already belong to a product: { id, url, path?, alt? }.
 */
export function ProductMediaPicker({
    open,
    onClose,
    images = [],
    selectedId = null,
    onSelect,
    onUpload,
    uploading = false,
    title = t('admin.media.choose_image', 'Choose image'),
    emptyMessage = t('admin.media.no_product_images', 'No product images available.'),
}) {
    const fileRef = useRef(null);
    const [pendingId, setPendingId] = useState(selectedId);

    useEffect(() => {
        if (open) {
            setPendingId(selectedId);
        }
    }, [open, selectedId]);

    if (!open) {
        return null;
    }

    const canUpload = typeof onUpload === 'function';

    const confirm = () => {
        const image = images.find((row) => Number(row.id) === Number(pendingId)) || null;
        onSelect?.(image);
        onClose?.();
    };

    const handleUpload = (file) => {
        onUpload(file, {
            onUploaded: (image) => {
                if (!image?.id) {
                    return;
                }
                setPendingId(image.id);
                onSelect?.(image);
                onClose?.();
            },
        });
    };

    return (
        <div className={ui.modalBackdropNested} role="presentation" onClick={onClose}>
            <div
                className={`${ui.modal} max-w-3xl`}
                role="dialog"
                aria-modal="true"
                aria-labelledby="product-media-picker-title"
                onClick={(e) => e.stopPropagation()}
            >
                <div className={ui.modalHead}>
                    <div>
                        <h2 id="product-media-picker-title" className={ui.modalTitle}>{title}</h2>
                        <p className={ui.modalSub}>
                            {t('admin.media.pick_one_product_image', 'Select one image from this product’s media.')}
                        </p>
                    </div>
                    <Button type="button" variant="ghost" icon="x" aria-label={t('admin.common.close', 'Close')} onClick={onClose} />
                </div>
                <div className={ui.modalBody}>
                    {images.length === 0 ? (
                        <div className={ui.mediaPickerEmpty}>
                            <p className={ui.muted}>{emptyMessage}</p>
                            {canUpload ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    icon="upload"
                                    busy={uploading}
                                    onClick={() => fileRef.current?.click()}
                                >
                                    {t('admin.media.upload_image', 'Upload Image')}
                                </Button>
                            ) : null}
                        </div>
                    ) : (
                        <div className={ui.mediaPickerGrid}>
                            {images.map((image) => {
                                const selected = Number(pendingId) === Number(image.id);
                                return (
                                    <button
                                        key={image.id}
                                        type="button"
                                        className={cx(ui.mediaPickerTile, selected && ui.mediaPickerTileSelected)}
                                        aria-pressed={selected}
                                        aria-label={image.alt || t('admin.media.select_image', 'Select image')}
                                        onClick={() => setPendingId(image.id)}
                                        onDoubleClick={() => {
                                            onSelect?.(image);
                                            onClose?.();
                                        }}
                                    >
                                        <Thumb
                                            src={image.url || image.path}
                                            alt={image.alt || ''}
                                            size="md"
                                            className="h-full w-full object-cover"
                                        />
                                        {selected ? (
                                            <span className={ui.mediaPickerCheck} aria-hidden="true">
                                                <Icon name="check" className="h-3.5 w-3.5" />
                                            </span>
                                        ) : null}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    {canUpload && images.length > 0 ? (
                        <div className={ui.mediaPickerActions}>
                            <Button
                                type="button"
                                variant="secondary"
                                icon="upload"
                                busy={uploading}
                                onClick={() => fileRef.current?.click()}
                            >
                                {t('admin.media.upload_image', 'Upload Image')}
                            </Button>
                        </div>
                    ) : null}
                    {canUpload ? (
                        <input
                            ref={fileRef}
                            type="file"
                            accept="image/*"
                            className="hidden"
                            onChange={(e) => {
                                const file = e.target.files?.[0];
                                e.target.value = '';
                                if (file) {
                                    handleUpload(file);
                                }
                            }}
                        />
                    ) : null}
                </div>
                <div className={ui.modalFoot}>
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {t('admin.common.cancel', 'Cancel')}
                    </Button>
                    <Button type="button" disabled={!pendingId || !images.some((row) => Number(row.id) === Number(pendingId))} onClick={confirm}>
                        {t('admin.common.use_selected', 'Use selected')}
                    </Button>
                </div>
            </div>
        </div>
    );
}

/**
 * Field wrapper: preview + Choose / Change / Remove, backed by ProductMediaPicker.
 */
export function ProductMediaField({
    label = t('admin.media.combination_image', 'Combination image'),
    hint,
    images = [],
    valueId = null,
    valueUrl = null,
    onChange,
    onUpload,
    uploading = false,
    error,
}) {
    const [open, setOpen] = useState(false);
    const selected = images.find((row) => Number(row.id) === Number(valueId)) || null;
    const previewUrl = selected?.url || valueUrl || null;
    const hasSelection = !!(selected || (valueId && previewUrl));

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-3">
                {hasSelection ? (
                    <>
                        <div>
                            <div className={ui.metaK}>{t('admin.media.selected_image', 'Selected Image')}</div>
                            <Thumb src={previewUrl} className={ui.mediaPickerPreview} />
                        </div>
                        <div className={ui.mediaPickerActions}>
                            <Button type="button" variant="secondary" onClick={() => setOpen(true)}>
                                {t('admin.media.change_image', 'Change Image')}
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => onChange?.(null)}
                            >
                                {t('admin.media.remove', 'Remove')}
                            </Button>
                        </div>
                    </>
                ) : (
                    <div className={ui.mediaPickerActions}>
                        <Button type="button" variant="secondary" onClick={() => setOpen(true)}>
                            {t('admin.media.choose_image', 'Choose Image')}
                        </Button>
                    </div>
                )}
            </div>
            <ProductMediaPicker
                open={open}
                onClose={() => setOpen(false)}
                images={images.filter((row) => row.id && row.url)}
                selectedId={selected?.id || valueId}
                onSelect={(image) => onChange?.(image)}
                onUpload={onUpload}
                uploading={uploading}
            />
        </Field>
    );
}
