import { Link, usePage } from '@inertiajs/react';
import { ui, cx, t } from '../../ui';

const LINKS = [
    { href: '/admin/health', label: 'Overview', key: 'admin.health.nav_overview' },
    { href: '/admin/health/issues', label: 'Issues', key: 'admin.health.nav_issues' },
    { href: '/admin/health/seo', label: 'SEO', key: 'admin.health.nav_seo' },
    { href: '/admin/health/content', label: 'Content', key: 'admin.health.nav_content' },
    { href: '/admin/health/images', label: 'Images', key: 'admin.health.nav_images' },
    { href: '/admin/health/performance', label: 'Performance', key: 'admin.health.nav_performance' },
    { href: '/admin/health/technical', label: 'Technical', key: 'admin.health.nav_technical' },
    { href: '/admin/health/cleanup', label: 'Cleanup', key: 'admin.health.nav_cleanup' },
    { href: '/admin/health/fix-center', label: 'Fix Center', key: 'admin.health.nav_fix' },
    { href: '/admin/health/ai', label: 'AI', key: 'admin.health.nav_ai' },
    { href: '/admin/health/inspector', label: 'Page Inspector', key: 'admin.health.nav_inspector' },
    { href: '/admin/health/history', label: 'History', key: 'admin.health.nav_history' },
    { href: '/admin/health/settings', label: 'Settings', key: 'admin.health.nav_settings' },
];

export function healthSeverityTone(severity) {
    if (severity === 'critical') return 'danger';
    if (severity === 'warning') return 'warning';
    if (severity === 'opportunity') return 'info';
    if (severity === 'passed') return 'success';
    return 'neutral';
}

export function healthScoreDisk(score) {
    if (score == null) return ui.scoreDiskOk;
    if (score >= 90) return ui.scoreDiskGood;
    if (score >= 70) return ui.scoreDiskOk;
    return ui.scoreDiskBad;
}

export default function HealthShell({ children }) {
    const url = usePage().url || '';
    const path = url.split('?')[0];

    return (
        <div>
            <nav className="mb-5 flex flex-wrap gap-1 border-b border-neutral-200 pb-3">
                {LINKS.map((link) => {
                    const active = link.href === '/admin/health'
                        ? path === '/admin/health'
                        : path === link.href || path.startsWith(`${link.href}/`);

                    return (
                        <Link
                            key={link.href}
                            href={link.href}
                            className={cx(
                                'rounded-lg px-3 py-1.5 text-sm cursor-pointer',
                                active ? 'bg-violet-100 font-semibold text-violet-800' : 'text-neutral-600 hover:bg-neutral-100',
                            )}
                        >
                            {t(link.key, link.label)}
                        </Link>
                    );
                })}
            </nav>
            {children}
        </div>
    );
}
