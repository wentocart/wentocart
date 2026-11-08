import './kpi-cards.css';
import { t } from './i18n';
import { moduleLabel } from './moduleLabel';

const TONES = ['sky', 'emerald', 'violet', 'amber'];

function TrendBadge({ trend, tone }) {
    const n = Number(trend || 0);
    const flat = Math.abs(n) < 0.05;
    const up = n > 0;
    const dir = flat ? 'flat' : up ? 'up' : 'down';
    const arrow = flat ? '—' : up ? '↑' : '↓';

    return (
        <div className={`kpi-badge kpi-badge--${tone} kpi-badge--${dir}`}>
            <div className="kpi-delta">
                <span aria-hidden="true">{arrow}</span>
                {' '}
                {Math.abs(n).toFixed(1)}%
            </div>
            <div className="kpi-vs">{t('admin.common.vs_last_30_days', 'vs last 30 days')}</div>
        </div>
    );
}

/**
 * Shared Back Office list KPI cards (exactly 4 recommended).
 * items: [{ key, label, value, trend? }]
 */
export default function KpiCards({ items = [], loading = false }) {
    if (!items.length) {
        return null;
    }

    return (
        <div className="kpi-grid">
            {items.slice(0, 4).map((item, index) => {
                const tone = item.tone || TONES[index] || 'sky';
                return (
                    <div key={item.key || item.label} className={`kpi-card kpi-card--${tone}`}>
                        <div className="kpi-label">{moduleLabel(item, item.label)}</div>
                        <div className="kpi-row">
                            <div className="kpi-trend-wrap">
                                {loading ? (
                                    <div className="kpi-badge kpi-badge--flat">
                                        <div className="kpi-delta">&nbsp;</div>
                                        <div className="kpi-vs">&nbsp;</div>
                                    </div>
                                ) : (
                                    <TrendBadge trend={item.trend ?? 0} tone={tone} />
                                )}
                            </div>
                            <div className="kpi-divider" aria-hidden="true" />
                            <div className="kpi-value">{item.value}</div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

/**
 * Build 4 KPI items from typical list counts: total / active / inactive / active rate.
 */
export function statusCountKpis(counts = {}, labels = {}) {
    const all = Number(counts.all ?? counts.total ?? 0);
    const active = Number(counts.active ?? 0);
    const inactive = Number(counts.inactive ?? counts.disabled ?? Math.max(0, all - active));
    const rate = all > 0 ? Math.round((active / all) * 1000) / 10 : 0;

    return [
        {
            key: 'total',
            label: labels.total || t('admin.common.total', 'Total'),
            value: all.toLocaleString(),
            trend: Number(labels.totalTrend ?? counts.total_trend ?? 0),
        },
        {
            key: 'active',
            label: labels.active || t('admin.common.active', 'Active'),
            value: active.toLocaleString(),
            trend: Number(labels.activeTrend ?? counts.active_trend ?? 0),
        },
        {
            key: 'inactive',
            label: labels.inactive || t('admin.common.inactive', 'Inactive'),
            value: inactive.toLocaleString(),
            trend: Number(labels.inactiveTrend ?? counts.inactive_trend ?? 0),
        },
        {
            key: 'rate',
            label: labels.rate || t('admin.common.active_rate', 'Active rate'),
            value: `${rate}%`,
            trend: Number(labels.rateTrend ?? counts.rate_trend ?? 0),
        },
    ];
}
