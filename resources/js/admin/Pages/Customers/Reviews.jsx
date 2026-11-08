import { router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import Layout from '../Layout';
import {
    Badge,
    BulkActions,
    Button,
    ColumnFilter,
    ColumnRangeFilter,
    EmptyState,
    Field,
    FilterReset,
    Flash,
    Icon,
    ListFooter,
    ListPanel,
    ListSettings,
    PageHeader,
    RowActions,
    SelectionCheckbox,
    SortHeader,
    StatusTabs,
    Table,
    THead,
    Th,
    Tr,
    Td,
    Textarea,
    Input,
    Thumb,
    cx,
    ui,
    t,
    useListSelection,
} from '../../ui';
import './review-lightbox.css';

function tabs() {
    return [
        { key: 'all', label: t('admin.reviews.all', 'All') },
        { key: 'pending', label: t('admin.reviews.pending', 'Pending') },
        { key: 'approved', label: t('admin.reviews.approved', 'Approved') },
        { key: 'rejected', label: t('admin.reviews.rejected', 'Rejected') },
    ];
}

const tone = { pending: 'warning', approved: 'success', rejected: 'danger' };

function statusLabel(status) {
    if (status === 'approved') {
        return t('admin.reviews.approved', 'Approved');
    }
    if (status === 'rejected') {
        return t('admin.reviews.rejected', 'Rejected');
    }
    return t('admin.reviews.pending', 'Pending');
}

function moderate(id, payload, options = {}) {
    const hasFiles = Boolean(
        (payload.photos && payload.photos.length)
        || payload.video
        || payload.photo
    );
    router.post(`/admin/reviews/${id}`, payload, {
        preserveScroll: true,
        forceFormData: hasFiles || options.forceFormData,
        ...options,
    });
}

function ReviewImageLightbox({ urls, index, onClose, onChange }) {
    const images = (Array.isArray(urls) ? urls : []).filter(Boolean);
    const active = index == null ? null : images[index];

    useEffect(() => {
        if (active == null) {
            return undefined;
        }
        const onKey = (e) => {
            if (e.key === 'Escape') {
                onClose?.();
            } else if (e.key === 'ArrowRight' && images.length > 1) {
                onChange?.(((index ?? 0) + 1) % images.length);
            } else if (e.key === 'ArrowLeft' && images.length > 1) {
                onChange?.(((index ?? 0) - 1 + images.length) % images.length);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [active, images.length, index, onChange, onClose]);

    if (active == null || typeof document === 'undefined') {
        return null;
    }

    return createPortal(
        <div
            className="admin-review-lightbox"
            role="dialog"
            aria-modal="true"
            aria-label={t('admin.reviews.image_preview', 'Review image')}
        >
            <button
                type="button"
                className="admin-review-lightbox__backdrop"
                aria-label={t('admin.common.close', 'Close')}
                onClick={onClose}
            />
            <div className="admin-review-lightbox__panel">
                <button
                    type="button"
                    className="admin-review-lightbox__close"
                    onClick={onClose}
                    aria-label={t('admin.common.close', 'Close')}
                >
                    ×
                </button>
                {images.length > 1 ? (
                    <button
                        type="button"
                        className="admin-review-lightbox__nav is-prev"
                        onClick={() => onChange?.(((index ?? 0) - 1 + images.length) % images.length)}
                        aria-label={t('admin.common.previous', 'Previous')}
                    >
                        ‹
                    </button>
                ) : null}
                <div className="admin-review-lightbox__frame">
                    <img src={active} alt="" className="admin-review-lightbox__image" />
                </div>
                {images.length > 1 ? (
                    <button
                        type="button"
                        className="admin-review-lightbox__nav is-next"
                        onClick={() => onChange?.(((index ?? 0) + 1) % images.length)}
                        aria-label={t('admin.common.next', 'Next')}
                    >
                        ›
                    </button>
                ) : null}
            </div>
        </div>,
        document.body,
    );
}

function ReviewAdminVideo({ src }) {
    const videoRef = useRef(null);
    const [playing, setPlaying] = useState(false);

    const togglePlay = async () => {
        const el = videoRef.current;
        if (!el) return;
        try {
            if (el.paused) {
                await el.play();
                setPlaying(true);
            } else {
                el.pause();
                setPlaying(false);
            }
        } catch {
            setPlaying(false);
        }
    };

    const toggleFullscreen = async () => {
        const el = videoRef.current;
        if (!el) return;
        try {
            if (document.fullscreenElement === el) {
                await document.exitFullscreen?.();
            } else if (el.requestFullscreen) {
                await el.requestFullscreen();
            } else if (el.webkitEnterFullscreen) {
                el.webkitEnterFullscreen();
            }
        } catch {
            // Ignore fullscreen denial.
        }
    };

    return (
        <div className="relative max-w-md overflow-hidden rounded-md bg-neutral-900">
            <video
                ref={videoRef}
                src={src}
                playsInline
                preload="metadata"
                controls={false}
                controlsList="nodownload noplaybackrate noremoteplayback"
                disablePictureInPicture
                className="block max-h-48 w-full bg-neutral-900 object-contain"
                onPlay={() => setPlaying(true)}
                onPause={() => setPlaying(false)}
                onEnded={() => setPlaying(false)}
                onClick={togglePlay}
            />
            <div className="absolute inset-x-0 bottom-0 flex items-center gap-2 bg-gradient-to-t from-black/70 to-transparent p-2">
                <button
                    type="button"
                    className="inline-flex h-8 min-w-8 items-center justify-center rounded-md bg-white/95 px-2 text-sm font-semibold text-neutral-900 cursor-pointer"
                    onClick={togglePlay}
                    aria-label={playing ? t('admin.common.pause', 'Pause') : t('admin.common.play', 'Play')}
                >
                    {playing ? (
                        <span aria-hidden="true">❚❚</span>
                    ) : (
                        <Icon name="play" className="h-4 w-4" />
                    )}
                </button>
                <button
                    type="button"
                    className="inline-flex h-8 min-w-8 items-center justify-center rounded-md bg-white/95 px-2 text-sm font-semibold text-neutral-900 cursor-pointer"
                    onClick={toggleFullscreen}
                    aria-label={t('admin.common.fullscreen', 'Fullscreen')}
                >
                    <span aria-hidden="true">⛶</span>
                </button>
            </div>
        </div>
    );
}

function ReplyForm({ panel, query }) {
    const review = panel?.review || {};
    const status = review.status || (review.is_approved ? 'approved' : 'pending');
    const form = useForm({
        admin_reply: review.admin_reply || '',
        rejected_reason: review.rejected_reason || '',
        rating: review.rating || 5,
        title: review.title || '',
        body: review.body || '',
    });
    const [existingImages, setExistingImages] = useState(() => (
        Array.isArray(review.image_urls) ? review.image_urls : (review.photo_url ? [review.photo_url] : [])
    ));
    const [removeImages, setRemoveImages] = useState([]);
    const [newPhotos, setNewPhotos] = useState([]);
    const [existingVideo, setExistingVideo] = useState(review.video_url || null);
    const [removeVideo, setRemoveVideo] = useState(false);
    const [newVideo, setNewVideo] = useState(null);
    const [lightboxIndex, setLightboxIndex] = useState(null);
    const [newPhotoUrls, setNewPhotoUrls] = useState([]);

    useEffect(() => {
        form.setData({
            admin_reply: review.admin_reply || '',
            rejected_reason: review.rejected_reason || '',
            rating: review.rating || 5,
            title: review.title || '',
            body: review.body || '',
        });
        form.clearErrors();
        setExistingImages(Array.isArray(review.image_urls) ? review.image_urls : (review.photo_url ? [review.photo_url] : []));
        setRemoveImages([]);
        setNewPhotos([]);
        setExistingVideo(review.video_url || null);
        setRemoveVideo(false);
        setNewVideo(null);
        setLightboxIndex(null);
        document.getElementById('list-inner-form')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, [review.id]);

    useEffect(() => {
        const urls = newPhotos.map((file) => URL.createObjectURL(file));
        setNewPhotoUrls(urls);
        return () => {
            urls.forEach((url) => URL.revokeObjectURL(url));
        };
    }, [newPhotos]);

    const videoPreviewUrl = useMemo(() => {
        if (newVideo) {
            return URL.createObjectURL(newVideo);
        }
        if (existingVideo && !removeVideo) {
            return existingVideo;
        }
        return null;
    }, [newVideo, existingVideo, removeVideo]);

    useEffect(() => () => {
        if (newVideo && videoPreviewUrl?.startsWith('blob:')) {
            URL.revokeObjectURL(videoPreviewUrl);
        }
    }, [newVideo, videoPreviewUrl]);

    const closeForm = () => {
        router.get('/admin/reviews', query, { preserveScroll: true, replace: true });
    };

    const afterAction = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeForm(),
    };

    const visibleImages = existingImages.filter((url) => !removeImages.includes(url));
    const lightboxUrls = [...visibleImages, ...newPhotoUrls];
    const remainingSlots = Math.max(0, 5 - visibleImages.length - newPhotos.length);

    const saveContent = () => {
        moderate(review.id, {
            action: 'update',
            rating: form.data.rating,
            title: form.data.title,
            body: form.data.body,
            remove_images: removeImages,
            remove_video: removeVideo ? 1 : 0,
            photos: newPhotos,
            video: newVideo || undefined,
        }, { ...afterAction, forceFormData: true });
    };

    return (
        <div id="list-inner-form" className="p-4 sm:p-6">
            <div className={ui.sectionHead}>
                <div>
                    <div className={cx(ui.row, 'flex-wrap gap-2 mb-1')}>
                        <span className={ui.metaK}>#{review.id}</span>
                        <Badge tone={tone[status] || 'neutral'}>{statusLabel(status)}</Badge>
                        {review.is_verified_purchase ? (
                            <Badge tone="success">{t('admin.reviews.verified', 'Verified purchase')}</Badge>
                        ) : null}
                        <span className={ui.metaK}>{review.rating}/5</span>
                    </div>
                    <h2 className={ui.sectionTitle}>
                        {t('admin.reviews.reply_to_review', 'Reply to review')}
                    </h2>
                </div>
            </div>

            <div className={cx(ui.fieldGrid, 'mb-4')}>
                <Field label={t('admin.reviews.customer', 'Customer')}>
                    <Input
                        wide
                        disabled
                        value={review.customer?.name
                            ? `${review.customer.name}${review.customer.email ? ` (${review.customer.email})` : ''}`
                            : (review.customer?.email || '—')}
                    />
                </Field>
                <Field label={t('admin.reviews.product', 'Product')}>
                    <Input wide disabled value={review.product?.name || '—'} />
                </Field>
                <Field label={t('admin.reviews.rating', 'Rating')}>
                    <Input
                        wide
                        type="number"
                        min="1"
                        max="5"
                        value={form.data.rating}
                        onChange={(e) => form.setData('rating', Number(e.target.value))}
                    />
                </Field>
                <Field label={t('admin.reviews.title', 'Title')}>
                    <Input
                        wide
                        value={form.data.title}
                        onChange={(e) => form.setData('title', e.target.value)}
                    />
                </Field>
                <Field label={t('admin.reviews.body', 'Review text')} className={ui.fieldSpan2}>
                    <Textarea
                        rows={4}
                        value={form.data.body}
                        onChange={(e) => form.setData('body', e.target.value)}
                    />
                </Field>
                <Field label={t('admin.reviews.images', 'Images')} className={ui.fieldSpan2}>
                    <div className="flex flex-wrap gap-2 mb-2">
                        {visibleImages.map((url, index) => (
                            <div key={url} className="relative">
                                <button
                                    type="button"
                                    className="block cursor-pointer rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-200"
                                    onClick={() => setLightboxIndex(index)}
                                    aria-label={t('admin.reviews.view_image', 'View image')}
                                >
                                    <Thumb src={url} className="!w-16 !h-16 object-cover rounded-md pointer-events-none" />
                                </button>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="!absolute !top-1 !right-1 !px-1 !py-0 !min-h-0 !text-xs"
                                    onClick={() => setRemoveImages((list) => [...list, url])}
                                >
                                    ×
                                </Button>
                            </div>
                        ))}
                        {newPhotos.map((file, index) => (
                            <div key={`new-${file.name}-${index}`} className="relative">
                                <button
                                    type="button"
                                    className="block cursor-pointer rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-violet-200"
                                    onClick={() => setLightboxIndex(visibleImages.length + index)}
                                    aria-label={t('admin.reviews.view_image', 'View image')}
                                >
                                    <Thumb src={newPhotoUrls[index]} className="!w-16 !h-16 object-cover rounded-md pointer-events-none" />
                                </button>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="!absolute !top-1 !right-1 !px-1 !py-0 !min-h-0 !text-xs"
                                    onClick={() => setNewPhotos((list) => list.filter((_, i) => i !== index))}
                                >
                                    ×
                                </Button>
                            </div>
                        ))}
                    </div>
                    {remainingSlots > 0 ? (
                        <label className="inline-flex flex-col gap-1">
                            <span className="text-sm text-neutral-600">{t('admin.reviews.add_images', 'Add images')}</span>
                            <Input
                                type="file"
                                accept="image/jpeg,image/png,image/webp,image/gif"
                                multiple
                                onChange={(e) => {
                                    const files = Array.from(e.target.files || []);
                                    e.target.value = '';
                                    if (!files.length) return;
                                    setNewPhotos((current) => [...current, ...files.slice(0, remainingSlots)]);
                                }}
                            />
                        </label>
                    ) : (
                        <p className="text-xs text-neutral-500">{t('admin.reviews.max_images', 'Maximum of 5 images.')}</p>
                    )}
                </Field>
                <Field label={t('admin.reviews.video', 'Video')} className={ui.fieldSpan2}>
                    {videoPreviewUrl ? (
                        <div className="mb-2 space-y-2">
                            <ReviewAdminVideo src={videoPreviewUrl} />
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => {
                                    if (newVideo) setNewVideo(null);
                                    else setRemoveVideo(true);
                                }}
                            >
                                {t('admin.reviews.remove_video', 'Remove video')}
                            </Button>
                        </div>
                    ) : null}
                    {!newVideo && (removeVideo || !existingVideo) ? (
                        <label className="inline-flex flex-col gap-1">
                            <span className="text-sm text-neutral-600">{t('admin.reviews.add_video', 'Add video')}</span>
                            <Input
                                type="file"
                                accept="video/mp4,video/webm"
                                onChange={(e) => {
                                    setNewVideo(e.target.files?.[0] || null);
                                    setRemoveVideo(false);
                                    e.target.value = '';
                                }}
                            />
                        </label>
                    ) : null}
                </Field>
            </div>

            <form
                className={ui.fieldGrid}
                onSubmit={(event) => {
                    event.preventDefault();
                    moderate(review.id, { action: 'reply', admin_reply: form.data.admin_reply }, afterAction);
                }}
            >
                {status !== 'approved' ? (
                    <Field label={t('admin.reviews.reject_reason', 'Reject reason (optional)')}>
                        <Input
                            wide
                            value={form.data.rejected_reason}
                            onChange={(e) => form.setData('rejected_reason', e.target.value)}
                            placeholder={t('admin.reviews.reject_reason', 'Reject reason (optional)')}
                        />
                    </Field>
                ) : null}
                <Field
                    label={t('admin.reviews.shop_reply', 'Shop reply')}
                    className={status === 'approved' ? ui.fieldSpan2 : undefined}
                    required
                >
                    <Textarea
                        rows={4}
                        value={form.data.admin_reply}
                        onChange={(e) => form.setData('admin_reply', e.target.value)}
                        placeholder={t('admin.reviews.reply_placeholder', 'Public reply from the shop')}
                    />
                </Field>
                <div className={cx(ui.toolbar, ui.fieldSpan2)}>
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={saveContent}
                    >
                        {t('admin.reviews.save_content', 'Save content')}
                    </Button>
                    {status !== 'approved' ? (
                        <Button
                            type="button"
                            icon="check"
                            onClick={() => moderate(review.id, { action: 'approve' }, afterAction)}
                        >
                            {t('admin.reviews.approve', 'Approve')}
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => moderate(review.id, { action: 'pending' }, afterAction)}
                        >
                            {t('admin.reviews.unapprove', 'Unapprove')}
                        </Button>
                    )}
                    {status !== 'rejected' ? (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => moderate(review.id, {
                                action: 'reject',
                                rejected_reason: form.data.rejected_reason,
                            }, afterAction)}
                        >
                            {t('admin.reviews.reject', 'Reject')}
                        </Button>
                    ) : null}
                    <Button type="submit" variant="secondary" icon="check">
                        {t('admin.reviews.save_reply', 'Save reply')}
                    </Button>
                </div>
            </form>

            <ReviewImageLightbox
                urls={lightboxUrls}
                index={lightboxIndex}
                onClose={() => setLightboxIndex(null)}
                onChange={setLightboxIndex}
            />
        </div>
    );
}

export default function Reviews({ reviews, filters = {}, panel = null, merchant_replies = true }) {
    const { flash } = usePage().props;
    const rows = reviews.data || [];
    const query = useMemo(() => ({
        product: filters.product || '',
        customer: filters.customer || '',
        rating: filters.rating || '',
        review: filters.review || '',
        status: filters.status || 'all',
        id_min: filters.id_min || '',
        id_max: filters.id_max || '',
        sort: filters.sort || 'id',
        dir: filters.dir || 'desc',
    }), [filters]);
    const formOpen = panel?.mode === 'reply';
    const [bulkBusy, setBulkBusy] = useState(false);
    const selection = useListSelection(rows);

    const panelParams = useMemo(() => {
        if (panel?.mode === 'reply' && panel.review?.id) {
            return { reply: panel.review.id };
        }
        return {};
    }, [panel]);

    const apply = useCallback((next) => {
        const payload = { ...query, ...panelParams, ...next };
        Object.keys(payload).forEach((key) => {
            if (!payload[key] || payload[key] === 'all') {
                delete payload[key];
            }
        });
        router.get('/admin/reviews', payload, { preserveState: true, preserveScroll: true, replace: true });
    }, [query, panelParams]);

    const applyColumn = useCallback((nameOrPatch, value) => {
        if (typeof nameOrPatch === 'object' && nameOrPatch !== null) {
            apply(nameOrPatch);
            return;
        }
        apply({ [nameOrPatch]: value });
    }, [apply]);

    const sortBy = useCallback((column) => {
        const dir = query.sort === column && query.dir === 'asc' ? 'desc' : 'asc';
        apply({ sort: column, dir });
    }, [apply, query.dir, query.sort]);

    const openReply = (row) => {
        router.get('/admin/reviews', { ...query, reply: row.id }, { preserveScroll: true });
    };

    const runBulk = (action) => {
        const ids = selection.selectedIds;
        if (!ids.length || bulkBusy) {
            return;
        }
        setBulkBusy(true);
        router.post('/admin/reviews/bulk', { action, ids }, {
            preserveScroll: true,
            onSuccess: () => selection.clear(),
            onFinish: () => setBulkBusy(false),
        });
    };

    return (
        <Layout>
            <PageHeader
                title={t('admin.reviews.reviews', 'Reviews')}
                total={reviews.total}
                crumbs={[
                    { href: '/admin', label: t('admin.nav.dashboard', 'Dashboard') },
                    { href: '/admin/customers', label: t('admin.nav.customers', 'Customers') },
                    { label: t('admin.reviews.reviews', 'Reviews') },
                ]}
                actions={(
                    <>
                        {formOpen ? (
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => router.get('/admin/reviews', query, { preserveScroll: true, replace: true })}
                            >
                                {t('admin.common.back_to_list', 'Back to list')}
                            </Button>
                        ) : null}
                        <ListSettings
                            listUrl="/admin/reviews"
                            importUrl="/admin/reviews/import"
                            importSampleUrl="/admin/reviews/import/sample"
                            exportUrl="/admin/reviews/export"
                            query={query}
                        />
                    </>
                )}
            />
            <Flash flash={flash} className="mb-4" />
            <ListPanel
                table={!formOpen}
                tabs={formOpen ? null : (
                    <StatusTabs
                        tabs={tabs()}
                        value={query.status}
                        onChange={(status) => apply({ status })}
                    />
                )}
                bulk={formOpen ? null : (
                    <BulkActions
                        count={selection.selectedCount}
                        busy={bulkBusy}
                        items={[
                            {
                                key: 'approve',
                                label: t('admin.reviews.approve', 'Approve'),
                                icon: 'check',
                                onClick: () => runBulk('approve'),
                            },
                            {
                                key: 'unapprove',
                                label: t('admin.reviews.unapprove', 'Unapprove'),
                                icon: 'arrow-left',
                                onClick: () => runBulk('unapprove'),
                            },
                            {
                                key: 'reject',
                                label: t('admin.reviews.reject', 'Reject'),
                                icon: 'ban',
                                onClick: () => runBulk('reject'),
                            },
                            {
                                key: 'delete',
                                label: t('admin.products.delete_selection', 'Delete selection'),
                                icon: 'trash',
                                tone: 'danger',
                                confirm: t('admin.reviews.delete_selected', 'Delete selected reviews?'),
                                onClick: () => runBulk('delete'),
                            },
                        ]}
                    />
                )}
                footer={formOpen ? null : (
                    <ListFooter
                        pagination={reviews}
                        query={query}
                    />
                )}
            >
                {formOpen ? (
                    <ReplyForm panel={panel} query={query} />
                ) : (
                    <Table>
                        <THead>
                            <tr>
                                <Th check>
                                    <SelectionCheckbox
                                        checked={selection.allSelected}
                                        indeterminate={selection.someSelected}
                                        onChange={selection.toggleAll}
                                        ariaLabel={t('admin.common.select_all', 'Select all on this page')}
                                    />
                                </Th>
                                <SortHeader col="id" column="id" label={t('admin.common.id', 'ID')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <SortHeader column="product" label={t('admin.reviews.product', 'Product')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <SortHeader column="customer" label={t('admin.reviews.customer', 'Customer')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <SortHeader column="rating" label={t('admin.reviews.rating', 'Rating')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <SortHeader column="review" label={t('admin.reviews.review', 'Review')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <SortHeader col="status" column="status" label={t('admin.reviews.status', 'Status')} sort={query.sort} dir={query.dir} onSort={sortBy} />
                                <Th actions>{t('admin.common.action', 'Actions')}</Th>
                            </tr>
                            <tr>
                                <Th check className={ui.thFilter} />
                                <Th className={ui.thFilterId}>
                                    <ColumnRangeFilter
                                        minName="id_min"
                                        maxName="id_max"
                                        minValue={query.id_min}
                                        maxValue={query.id_max}
                                        onApply={applyColumn}
                                    />
                                </Th>
                                <Th className={ui.thFilter}>
                                    <ColumnFilter name="product" value={filters.product || ''} placeholder={t('admin.reviews.product', 'Product')} onApply={applyColumn} />
                                </Th>
                                <Th className={ui.thFilter}>
                                    <ColumnFilter name="customer" value={filters.customer || ''} placeholder={t('admin.reviews.customer', 'Customer')} onApply={applyColumn} />
                                </Th>
                                <Th className={ui.thFilter}>
                                    <ColumnFilter
                                        type="select"
                                        name="rating"
                                        value={filters.rating || ''}
                                        placeholder={t('admin.reviews.rating', 'Rating')}
                                        options={[5, 4, 3, 2, 1].map((value) => ({ value: String(value), label: `${value}/5` }))}
                                        onApply={applyColumn}
                                    />
                                </Th>
                                <Th className={ui.thFilter}>
                                    <ColumnFilter name="review" value={filters.review || ''} placeholder={t('admin.reviews.review', 'Review')} onApply={applyColumn} />
                                </Th>
                                <Th className={ui.thFilterStatus}>
                                    <ColumnFilter
                                        type="select"
                                        name="status"
                                        value={filters.status === 'all' ? '' : (filters.status || '')}
                                        placeholder={t('admin.reviews.status', 'Status')}
                                        options={[
                                            { value: 'pending', label: t('admin.reviews.pending', 'Pending') },
                                            { value: 'approved', label: t('admin.reviews.approved', 'Approved') },
                                            { value: 'rejected', label: t('admin.reviews.rejected', 'Rejected') },
                                        ]}
                                        onApply={(name, value) => apply({ [name]: value || 'all' })}
                                        compact
                                    />
                                </Th>
                                <Th actions className={ui.thFilter}>
                                    <FilterReset query={query} href="/admin/reviews" />
                                </Th>
                            </tr>
                        </THead>
                        <tbody>
                            {rows.length === 0 ? (
                                <EmptyState colSpan={8}>{t('admin.reviews.no_reviews_yet', 'No reviews yet.')}</EmptyState>
                            ) : rows.map((r) => {
                                const status = r.status || (r.is_approved ? 'approved' : 'pending');
                                return (
                                    <Tr key={r.id} selected={selection.isSelected(r.id)}>
                                        <Td check>
                                            <SelectionCheckbox
                                                checked={selection.isSelected(r.id)}
                                                onChange={() => selection.toggle(r.id)}
                                                ariaLabel={`${t('admin.common.select', 'Select')} review ${r.id}`}
                                            />
                                        </Td>
                                        <Td col="id">{r.id}</Td>
                                        <Td>
                                            {r.product?.id ? (
                                                <Button variant="link" href={`/admin/products/${r.product.id}/edit`}>{r.product.name || '—'}</Button>
                                            ) : (
                                                <div className="font-medium">{r.product?.name || '—'}</div>
                                            )}
                                            {r.is_verified_purchase ? <Badge tone="success">{t('admin.reviews.verified', 'Verified')}</Badge> : null}
                                        </Td>
                                        <Td>
                                            {r.customer?.id ? (
                                                <Button variant="link" href={`/admin/customers/${r.customer.id}`}>{r.customer.name || '—'}</Button>
                                            ) : (
                                                <div>{r.customer?.name || '—'}</div>
                                            )}
                                            <div className="text-xs text-neutral-500">{r.customer?.email || ''}</div>
                                        </Td>
                                        <Td>{r.rating}/5</Td>
                                        <Td>
                                            <div className="font-medium">{r.title || '—'}</div>
                                            <p className="mt-1 max-w-md truncate text-sm text-neutral-600">{r.body || '—'}</p>
                                            {(Array.isArray(r.image_urls) && r.image_urls.length) || r.photo_url || r.video_url ? (
                                                <div className="mt-2 flex flex-wrap gap-1.5">
                                                    {(Array.isArray(r.image_urls) && r.image_urls.length
                                                        ? r.image_urls
                                                        : (r.photo_url ? [r.photo_url] : [])
                                                    ).slice(0, 4).map((url) => (
                                                        <Thumb key={url} src={url} className="!w-10 !h-10 object-cover rounded" />
                                                    ))}
                                                    {r.video_url ? (
                                                        <span className="inline-flex items-center rounded bg-neutral-100 px-2 text-xs text-neutral-600">
                                                            {t('admin.reviews.video', 'Video')}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            ) : null}
                                            {r.admin_reply ? (
                                                <p className="mt-1 max-w-md truncate text-xs text-violet-700">
                                                    {t('admin.reviews.shop_reply', 'Shop reply')}: {r.admin_reply}
                                                </p>
                                            ) : null}
                                        </Td>
                                        <Td>
                                            <Badge tone={tone[status] || 'neutral'}>{statusLabel(status)}</Badge>
                                        </Td>
                                        <Td actions>
                                            <RowActions
                                                canEdit={false}
                                                confirmMessage={t('admin.reviews.delete_confirm', 'Delete this review?')}
                                                onDelete={() => moderate(r.id, { action: 'delete' })}
                                                items={[
                                                    ...(merchant_replies ? [{
                                                        key: 'reply',
                                                        label: t('admin.reviews.reply', 'Reply'),
                                                        icon: 'pencil',
                                                        onClick: () => openReply(r),
                                                    }] : []),
                                                    status !== 'approved'
                                                        ? {
                                                            key: 'approve',
                                                            label: t('admin.reviews.approve', 'Approve'),
                                                            icon: 'check',
                                                            onClick: () => moderate(r.id, { action: 'approve' }),
                                                        }
                                                        : {
                                                            key: 'unapprove',
                                                            label: t('admin.reviews.unapprove', 'Unapprove'),
                                                            icon: 'arrow-left',
                                                            onClick: () => moderate(r.id, { action: 'pending' }),
                                                        },
                                                    status !== 'rejected'
                                                        ? {
                                                            key: 'reject',
                                                            label: t('admin.reviews.reject', 'Reject'),
                                                            icon: 'ban',
                                                            onClick: () => moderate(r.id, { action: 'reject' }),
                                                        }
                                                        : null,
                                                ]}
                                            />
                                        </Td>
                                    </Tr>
                                );
                            })}
                        </tbody>
                    </Table>
                )}
            </ListPanel>
        </Layout>
    );
}
