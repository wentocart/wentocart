import { Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import Button from './Button';
import { ui, cx } from './classes';
import { resolveAdminBreadcrumbs } from './adminMenu';
import { t } from './i18n';

function parentAndChild(crumbs) {
    if (!crumbs?.length) {
        return [];
    }

    const dashboard = t('admin.nav.dashboard', 'Dashboard');
    let trail = crumbs[0]?.label === dashboard && crumbs.length > 1 ? crumbs.slice(1) : crumbs;

    if (trail.length <= 2) {
        return trail;
    }

    return [trail[0], { ...trail[trail.length - 1], href: undefined }];
}

function subtitleText(value) {
    return typeof value === 'string' && value.trim() ? value : null;
}

function Crumbs({ crumbs, className }) {
    if (!crumbs?.length) {
        return null;
    }

    return (
        <nav className={className} aria-label={t('admin.common.breadcrumb', 'Breadcrumb')}>
            {crumbs.map((crumb, index) => {
                const isLast = index === crumbs.length - 1;
                return (
                    <span key={`${crumb.label}-${index}`}>
                        {index > 0 ? ' > ' : null}
                        {crumb.href && !isLast ? (
                            <Link href={crumb.href} className={ui.pageCrumbLink}>
                                {crumb.label}
                            </Link>
                        ) : (
                            <span className="text-neutral-500">{crumb.label}</span>
                        )}
                    </span>
                );
            })}
        </nav>
    );
}

export default function PageHeader({
    title,
    total,
    crumbs,
    actions,
    description,
    className,
    crumbsFirst = false,
    meta,
}) {
    const page = usePage();
    const { shop, appNav, moduleNav } = page.props;
    const currentUrl = page.url;
    const count = total == null || total === '' ? null : Number(total);
    const titleNode = (
        <h1 className={crumbsFirst ? ui.formEditTitle : ui.pageTitle}>
            {title}
            {Number.isFinite(count) ? (
                <span className={ui.pageTitleCount}>{` (${count.toLocaleString()})`}</span>
            ) : null}
        </h1>
    );

    const lead = subtitleText(meta) || subtitleText(description);
    const inlineMeta = subtitleText(meta) ? null : meta;
    const trail = useMemo(() => {
        const auto = resolveAdminBreadcrumbs(currentUrl, {
            multishop: Boolean(shop?.multishop),
            multivendor: Boolean(shop?.multivendor),
            appNav: appNav || [],
            moduleNav: moduleNav || [],
        });

        if (auto.length) {
            return auto;
        }

        return parentAndChild(crumbs);
    }, [currentUrl, shop?.multishop, shop?.multivendor, appNav, moduleNav, crumbs]);

    const subtitle = lead ? <p className={ui.pageSubtitle}>{lead}</p> : null;
    const isRecordForm = /(?:Form|Create|Edit)$/.test(page.component || '');
    const listHref = (items) => [...(items || [])].reverse().find((crumb) => {
        const href = crumb?.href;
        if (!href) {
            return false;
        }
        const path = href.split('?')[0].replace(/\/$/, '');

        return path !== '/admin' && path !== '/admin/dashboards';
    })?.href || null;
    const backHref = isRecordForm
        ? (listHref((crumbs || []).slice(0, -1)) || listHref(trail.slice(0, -1)))
        : null;
    const backButton = backHref ? (
        <Button variant="secondary" icon="arrow-left" href={backHref}>
            {t('admin.common.back_to_list', 'Back to list')}
        </Button>
    ) : null;
    const actionBar = (backButton || actions) ? (
        <div className={cx(ui.toolbar, 'shrink-0')}>
            {backButton}
            {actions}
        </div>
    ) : null;

    if (crumbsFirst) {
        return (
            <div className={cx(ui.formEditHead, className)}>
                <Crumbs crumbs={trail} className={ui.formEditCrumbs} />
                <div className={ui.formEditTitleRow}>
                    <div className="min-w-0 flex-1">
                        <div className={ui.formEditTitleBlock}>
                            {titleNode}
                            {inlineMeta ? <div className={ui.formEditMeta}>{inlineMeta}</div> : null}
                        </div>
                        {subtitle}
                    </div>
                    {actionBar}
                </div>
                {subtitleText(meta) && subtitleText(description) && description !== meta ? (
                    <p className={ui.pageSubtitle}>{description}</p>
                ) : null}
            </div>
        );
    }

    return (
        <div className={cx(ui.pageTitleRow, className)}>
            <div className="min-w-0 max-w-3xl">
                <Crumbs crumbs={trail} className={ui.pageCrumbs} />
                {titleNode}
                {subtitle}
            </div>
            {actionBar}
        </div>
    );
}
