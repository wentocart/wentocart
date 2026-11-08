import { useLayoutEffect, useRef, useState } from 'react';
import {
    Icon,
    Loader,
    ui,
    cx,
    t,
} from '../../ui';
import { adminUrl } from '../../webPath';
import { DEFAULT_CATALOG_IMAGES } from '../../lib/catalogMedia';
import { alertMessage, categoryTone, iconForCategory } from './extensionManager';
import { extensionStatusBadges, useExtensionRowActions } from './extensionActions';
import ExtensionSplitButton from './ExtensionSplitButton';
import ExtensionCheckbox from './ExtensionCheckbox';

const badgeToneClass = {
    success: ui.badgeSuccess,
    warning: ui.badgeWarning,
    danger: ui.badgeDanger,
    info: ui.badgeInfo,
};

const badgeDotClass = {
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-red-500',
    info: 'bg-sky-500',
};

function ExtensionCardCheckbox({ checked, onChange, ariaLabel }) {
    return (
        <ExtensionCheckbox
            checked={checked}
            onChange={onChange}
            ariaLabel={ariaLabel}
            variant="card"
        />
    );
}

export function ExtensionCardSkeleton() {
    return (
        <div className={cx(ui.card, 'flex h-full min-h-[17.5rem] animate-pulse flex-col p-4')}>
            <div className="flex items-start gap-3">
                <div className="h-14 w-14 shrink-0 rounded-xl bg-neutral-100" />
                <div className="min-w-0 flex-1 space-y-2">
                    <div className="h-4 w-3/4 rounded bg-neutral-100" />
                    <div className="h-3 w-1/2 rounded bg-neutral-100" />
                    <div className="h-3 w-2/3 rounded bg-neutral-100" />
                </div>
            </div>
            <div className="mt-4 space-y-2 flex-1">
                <div className="h-3 w-full rounded bg-neutral-100" />
                <div className="h-3 w-full rounded bg-neutral-100" />
                <div className="h-3 w-4/5 rounded bg-neutral-100" />
            </div>
            <div className="mt-4 h-6 w-24 rounded-full bg-neutral-100" />
            <div className="mt-4 h-9 w-full rounded-lg bg-neutral-100" />
        </div>
    );
}

export default function ExtensionCard({ row, selected, onToggle, selectable }) {
    // preview → default catalog image → category icon
    const [logoMode, setLogoMode] = useState(row.preview ? 'preview' : 'default');
    const badges = extensionStatusBadges(row);
    const issue = alertMessage(row);
    const { busy, primary, menuItems } = useExtensionRowActions(row);
    const logoSrc = logoMode === 'preview' && row.preview
        ? adminUrl(row.preview)
        : logoMode === 'default'
            ? adminUrl(DEFAULT_CATALOG_IMAGES.md)
            : null;

    return (
        <article className={cx(
            ui.card,
            'relative flex h-full min-h-[17.5rem] flex-col p-4 transition hover:shadow-[0_16px_48px_-28px_rgba(15,23,42,0.42)]',
            busy && 'pointer-events-none',
        )}
        >
            {busy ? (
                <div className="absolute inset-0 z-20 flex items-center justify-center rounded-[inherit] bg-white/75 backdrop-blur-[1px]" role="status" aria-live="polite">
                    <Loader inline />
                    <span className="ml-2 text-sm text-neutral-600">{t('admin.common.loading', 'Loading…')}</span>
                </div>
            ) : null}

            {selectable ? (
                <div className="absolute right-4 top-4 z-10">
                    <ExtensionCardCheckbox
                        checked={selected}
                        onChange={onToggle}
                        ariaLabel={t('admin.apps.select_extension', 'Select :name').replace(':name', row.display_name)}
                    />
                </div>
            ) : null}

            <div className={cx('flex items-start gap-3', selectable && 'pr-6')}>
                    <div className="h-14 w-14 shrink-0">
                        <div className={cx(
                            'flex h-full w-full items-center justify-center overflow-hidden rounded-xl border',
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
                                <Icon name={iconForCategory(row.category)} className="h-6 w-6" />
                            )}
                        </div>
                    </div>

                <div className="min-w-0 flex-1">
                    <h3 className="truncate text-sm font-semibold text-neutral-900">{row.display_name}</h3>
                    <p className="mt-0.5 text-xs text-neutral-500">
                        v{row.version} · {t('admin.apps.by', 'by')} {row.author || row.vendor}
                    </p>
                    <p className="mt-0.5 truncate text-xs text-neutral-400">{row.id}</p>
                </div>
            </div>

            <p className="mt-3 line-clamp-3 flex-1 text-sm leading-relaxed text-neutral-600">
                {row.description || t('admin.apps.no_description', 'No description provided.')}
            </p>

            {badges.length ? (
                <div className="mt-3 flex flex-wrap gap-1.5">
                    {badges.map((status) => (
                        <span key={status.key} className={cx(ui.badge, badgeToneClass[status.tone] || ui.badgeNeutral)}>
                            {status.dot !== false ? (
                                <span className={cx(ui.badgeDot, badgeDotClass[status.tone] || 'bg-neutral-400')} aria-hidden="true" />
                            ) : null}
                            {status.label}
                        </span>
                    ))}
                </div>
            ) : null}

            {issue ? (
                <p className="mt-2 line-clamp-2 text-xs text-amber-700">{issue}</p>
            ) : null}

            <div className="mt-4 w-full self-stretch">
                <ExtensionSplitButton
                    row={row}
                    primary={primary}
                    menuItems={menuItems}
                    busy={busy}
                />
            </div>
        </article>
    );
}

export function ExtensionCategoryToggle({ label, count, open, onToggle, panelId, category }) {
    const name = label || t('admin.apps.uncategorized', 'Other');
    const tone = categoryTone(category);
    const headerIcons = {
        payment: 'card',
        marketing: 'price-tag',
        tax: 'file',
        business: 'briefcase',
    };

    return (
        <button
            type="button"
            className={cx(ui.extensionCategoryBtn, open ? tone.barOpen : tone.bar)}
            aria-expanded={open}
            aria-controls={panelId}
            aria-label={open
                ? t('admin.apps.collapse_category', 'Collapse {name}', { name })
                : t('admin.apps.expand_category', 'Expand {name}', { name })}
            onClick={onToggle}
        >
            <span className={cx(ui.extensionCategoryIcon, tone.icon)}>
                <Icon name={headerIcons[category] || iconForCategory(category)} />
            </span>
            <span className={ui.extensionCategoryTitle}>{name}</span>
            <span className={cx(ui.extensionCategoryCount, tone.count)}>{count}</span>
            <Icon
                name="chevron-down"
                className={cx(ui.extensionCategoryChevron, tone.chevron, open ? 'rotate-0' : '-rotate-90')}
            />
        </button>
    );
}

const CATEGORY_SLIDE_MS = 300;
const CATEGORY_SLIDE_EASE = 'cubic-bezier(0.32, 0.72, 0, 1)';

export function useCategorySlide(open) {
    const ref = useRef(null);
    const previousOpen = useRef(open);

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) {
            return undefined;
        }

        const clearSlide = () => {
            el.style.height = '';
            el.style.overflow = '';
        };
        const show = () => {
            el.hidden = false;
            el.style.height = 'auto';
            el.style.overflow = '';
            el.getAnimations().forEach((animation) => animation.cancel());
            el.style.height = '';
        };
        const hide = () => {
            el.hidden = true;
            el.getAnimations().forEach((animation) => animation.cancel());
            clearSlide();
        };

        if (previousOpen.current === open) {
            if (!open) {
                hide();
            }
            return undefined;
        }

        previousOpen.current = open;
        el.getAnimations().forEach((animation) => animation.cancel());

        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduce) {
            if (open) show();
            else hide();
            return undefined;
        }

        let cancelled = false;
        const options = { duration: CATEGORY_SLIDE_MS, easing: CATEGORY_SLIDE_EASE, fill: 'forwards' };
        const stop = (animation) => {
            cancelled = true;
            const current = Math.round(el.getBoundingClientRect().height);
            animation.cancel();
            el.style.height = `${current}px`;
        };

        if (open) {
            const wasHidden = el.hidden;
            el.style.overflow = 'hidden';
            if (wasHidden) {
                el.style.height = '0px';
            }
            el.hidden = false;
            const current = Math.round(el.getBoundingClientRect().height);
            el.style.height = 'auto';
            const full = el.scrollHeight;
            el.style.height = `${current}px`;
            const animation = el.animate(
                [{ height: `${current}px` }, { height: `${full}px` }],
                options,
            );
            animation.onfinish = () => {
                if (!cancelled) show();
            };
            return () => stop(animation);
        }

        if (el.hidden) {
            return undefined;
        }

        el.style.overflow = 'hidden';
        const current = Math.round(el.getBoundingClientRect().height);
        el.style.height = `${current}px`;
        const animation = el.animate(
            [{ height: `${current}px` }, { height: '0px' }],
            options,
        );
        animation.onfinish = () => {
            if (!cancelled) hide();
        };
        return () => stop(animation);
    }, [open]);

    return ref;
}

export function ExtensionGridGroup({ groupKey, label, rows, count, selection }) {
    const [open, setOpen] = useState(true);
    const panelRef = useCategorySlide(open);

    if (!rows.length) {
        return null;
    }

    const total = count ?? rows.length;
    const panelId = `extension-category-${groupKey || 'group'}`;

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
                <div className="grid grid-cols-1 gap-4 pt-3 sm:grid-cols-2 lg:grid-cols-3">
                    {rows.map((row) => (
                        <ExtensionCard
                            key={row.key}
                            row={row}
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

export function ExtensionGridSkeleton() {
    return (
        <div className="grid grid-cols-1 gap-4 p-3 sm:grid-cols-2 lg:grid-cols-3">
            {Array.from({ length: 8 }).map((_, index) => (
                <ExtensionCardSkeleton key={index} />
            ))}
        </div>
    );
}
