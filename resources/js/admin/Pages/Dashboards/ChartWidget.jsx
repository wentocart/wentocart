import { useEffect, useMemo, useRef, useState } from 'react';
import * as echarts from 'echarts/core';
import {
    BarChart,
    FunnelChart,
    GaugeChart,
    HeatmapChart,
    LineChart,
    PieChart,
    RadarChart,
    ScatterChart,
    TreemapChart,
} from 'echarts/charts';
import {
    GridComponent,
    LegendComponent,
    PolarComponent,
    TooltipComponent,
    VisualMapComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';
import { money, ui, cx, t } from '../../ui';

echarts.use([
    BarChart,
    LineChart,
    PieChart,
    RadarChart,
    FunnelChart,
    ScatterChart,
    GaugeChart,
    HeatmapChart,
    TreemapChart,
    GridComponent,
    TooltipComponent,
    LegendComponent,
    VisualMapComponent,
    PolarComponent,
    CanvasRenderer,
]);

const PALETTES = {
    violet: ['#7C3AED', '#8B5CF6', '#A78BFA', '#C4B5FD', '#6D28D9', '#4C1D95'],
    sky: ['#0284C7', '#0EA5E9', '#38BDF8', '#7DD3FC', '#0369A1', '#0C4A6E'],
    emerald: ['#059669', '#10B981', '#34D399', '#6EE7B7', '#047857', '#064E3B'],
    amber: ['#D97706', '#F59E0B', '#FBBF24', '#FDE68A', '#B45309', '#78350F'],
    rose: ['#E11D48', '#F43F5E', '#FB7185', '#FECDD3', '#BE123C', '#881337'],
    indigo: ['#4F46E5', '#6366F1', '#818CF8', '#C7D2FE', '#3730A3', '#312E81'],
};

const tooltip = {
    trigger: 'axis',
    backgroundColor: 'rgba(15, 23, 42, 0.92)',
    borderWidth: 0,
    textStyle: { color: '#F8FAFC', fontSize: 12 },
    padding: [10, 12],
    extraCssText: 'border-radius:12px;box-shadow:0 18px 40px rgba(15,23,42,0.25);',
};

function mixHex(hex, towards, amount) {
    const parse = (value) => {
        const n = value.replace('#', '');
        return [parseInt(n.slice(0, 2), 16), parseInt(n.slice(2, 4), 16), parseInt(n.slice(4, 6), 16)];
    };
    const a = parse(hex);
    const b = parse(towards);
    const channel = (i) => Math.max(0, Math.min(255, Math.round(a[i] + (b[i] - a[i]) * amount)));
    return `#${[channel(0), channel(1), channel(2)].map((n) => n.toString(16).padStart(2, '0')).join('')}`;
}

function shadesFrom(hex) {
    const base = hex.startsWith('#') ? hex : `#${hex}`;
    return [
        base,
        mixHex(base, '#FFFFFF', 0.18),
        mixHex(base, '#FFFFFF', 0.36),
        mixHex(base, '#FFFFFF', 0.55),
        mixHex(base, '#000000', 0.18),
        mixHex(base, '#000000', 0.4),
    ];
}

function colors(palette) {
    if (typeof palette === 'string' && /^#?[0-9A-Fa-f]{6}$/.test(palette)) {
        return shadesFrom(palette.startsWith('#') ? palette : `#${palette}`);
    }

    return PALETTES[palette] || PALETTES.violet;
}

function gradient(color) {
    return new echarts.graphic.LinearGradient(0, 0, 0, 1, [
        { offset: 0, color: color + 'CC' },
        { offset: 1, color: color + '10' },
    ]);
}

function axis() {
    return {
        axisLine: { show: false },
        axisTick: { show: false },
        splitLine: { lineStyle: { color: '#F3F4F6' } },
        axisLabel: { color: '#737373', fontSize: 11 },
    };
}

function buildOption(type, data, palette, compact = false) {
    const paletteColors = colors(palette);
    const main = paletteColors[0];
    const labels = data.labels || [];
    const series = data.series || [];
    const slices = data.slices || [];
    const axisStyle = {
        ...axis(),
        axisLabel: {
            ...axis().axisLabel,
            fontSize: compact ? 11 : 11,
            hideOverlap: true,
        },
    };

    if (type === 'gauge') {
        const value = Number(data.value ?? slices[0]?.value ?? 0);
        const target = Number(data.target ?? 100) || 100;
        const percent = Math.max(0, Math.min(100, (value / target) * 100));
        return {
            series: [{
                type: 'gauge',
                startAngle: 210,
                endAngle: -30,
                min: 0,
                max: 100,
                center: ['50%', compact ? '55%' : '50%'],
                radius: compact ? '90%' : '100%',
                pointer: { show: false },
                progress: { show: true, width: compact ? 10 : 16, roundCap: true, itemStyle: { color: main } },
                axisLine: { lineStyle: { width: compact ? 10 : 16, color: [[1, '#F3F4F6']] } },
                axisTick: { show: false },
                splitLine: { show: false },
                axisLabel: { show: false },
                title: { show: !compact, offsetCenter: [0, '58%'], fontSize: 12, color: '#737373' },
                detail: {
                    valueAnimation: true,
                    fontSize: compact ? 16 : 28,
                    fontWeight: 600,
                    offsetCenter: [0, compact ? '0%' : '10%'],
                    formatter: '{value}%',
                    color: '#171717',
                },
                data: [{ value: Math.round(percent), name: data.name || t('admin.dashboards.progress', 'Progress') }],
            }],
        };
    }

    if (type === 'radar') {
        return {
            color: paletteColors,
            radar: {
                indicator: data.indicators || [],
                radius: compact ? '58%' : '75%',
                axisName: { show: !compact, color: '#737373', fontSize: 11 },
                splitArea: { areaStyle: { color: ['#F8FAFC', '#FFFFFF'] } },
            },
            series: [{
                type: 'radar',
                data: [{ value: data.values || [], name: 'Mix', areaStyle: { color: main + '33' }, lineStyle: { color: main, width: 2 }, itemStyle: { color: main } }],
            }],
        };
    }

    if (type === 'heatmap') {
        const max = Math.max(1, ...(data.cells || []).map((cell) => cell[2] || 0));
        return {
            tooltip: { ...tooltip, trigger: 'item', formatter: (p) => `${data.y?.[p.value[1]]} ${p.value[0]}:00 — ${p.value[2]}` },
            grid: compact
                ? { left: 28, right: 6, top: 6, bottom: 14, containLabel: false }
                : { left: 48, right: 16, top: 16, bottom: 28 },
            xAxis: {
                type: 'category',
                data: (data.x || []).map(String),
                ...axisStyle,
                axisLabel: { ...axisStyle.axisLabel, show: !compact, interval: compact ? 'auto' : 0 },
                splitArea: { show: true },
            },
            yAxis: {
                type: 'category',
                data: data.y || [],
                ...axisStyle,
                axisLabel: { ...axisStyle.axisLabel, show: !compact },
                splitArea: { show: true },
            },
            visualMap: { min: 0, max, show: false, inRange: { color: ['#F5F3FF', main] } },
            series: [{ type: 'heatmap', data: data.cells || [], itemStyle: { borderRadius: 3 } }],
        };
    }

    if (type === 'scatter') {
        return {
            color: [main],
            tooltip: { ...tooltip, trigger: 'item', formatter: (p) => `${data.x_label}: ${p.value[0]}<br/>${data.y_label}: ${p.value[1]}` },
            grid: compact
                ? { left: 28, right: 8, top: 8, bottom: 16, containLabel: true }
                : { left: 48, right: 16, top: 16, bottom: 36 },
            xAxis: { name: compact ? undefined : data.x_label, nameLocation: 'middle', nameGap: 24, ...axisStyle },
            yAxis: { name: compact ? undefined : data.y_label, ...axisStyle },
            series: [{ type: 'scatter', symbolSize: compact ? 8 : 12, data: data.points || [], itemStyle: { color: main, opacity: 0.8 } }],
        };
    }

    if (type === 'treemap') {
        return {
            color: paletteColors,
            tooltip: { ...tooltip, trigger: 'item' },
            series: [{
                type: 'treemap',
                roam: false,
                nodeClick: false,
                breadcrumb: { show: false },
                itemStyle: { borderColor: '#fff', borderWidth: compact ? 2 : 3, gapWidth: compact ? 2 : 3 },
                label: { show: !compact, color: '#fff', fontWeight: 600 },
                data: slices.map((slice, index) => ({ name: slice.name, value: slice.value, itemStyle: { color: paletteColors[index % paletteColors.length] } })),
            }],
        };
    }

    if (['pie', 'doughnut', 'funnel', 'polar'].includes(type)) {
        if (type === 'funnel') {
            return {
                color: paletteColors,
                tooltip: { ...tooltip, trigger: 'item' },
                series: [{
                    type: 'funnel',
                    left: '8%',
                    width: '84%',
                    minSize: '20%',
                    gap: compact ? 3 : 6,
                    label: { show: !compact, color: '#171717', fontWeight: 600, fontSize: compact ? 10 : 12 },
                    itemStyle: { borderRadius: 8 },
                    data: slices,
                }],
            };
        }
        if (type === 'polar') {
            return {
                color: paletteColors,
                polar: { radius: compact ? ['12%', '68%'] : ['18%', '72%'] },
                angleAxis: {
                    type: 'category',
                    data: slices.map((s) => s.name),
                    startAngle: 75,
                    axisLabel: { show: !compact, fontSize: 10, color: '#737373' },
                },
                radiusAxis: {},
                tooltip: { ...tooltip, trigger: 'item' },
                series: [{ type: 'bar', coordinateSystem: 'polar', data: slices.map((s) => s.value), itemStyle: { color: main, borderRadius: 6 } }],
            };
        }
        return {
            color: paletteColors,
            tooltip: { ...tooltip, trigger: 'item' },
            legend: compact
                ? { show: false }
                : { bottom: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#737373', fontSize: 11 } },
            series: [{
                type: 'pie',
                center: ['50%', '50%'],
                radius: compact
                    ? (type === 'doughnut' ? ['46%', '70%'] : ['0%', '70%'])
                    : (type === 'doughnut' ? ['52%', '74%'] : ['36%', '72%']),
                padAngle: 3,
                itemStyle: { borderRadius: compact ? 6 : 8, borderColor: '#fff', borderWidth: 2 },
                label: { show: false },
                data: slices,
            }],
        };
    }

    const isHorizontal = type === 'hbar';
    const stacked = type === 'stacked_bar';
    const categoryData = isHorizontal ? (slices.length ? slices.map((s) => s.name) : labels) : labels;
    const mapped = series.length
        ? series
        : [{ name: 'Value', data: slices.map((s) => s.value) }];

    return {
        color: paletteColors,
        tooltip,
        legend: (!compact && mapped.length > 1)
            ? { top: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { color: '#737373', fontSize: 11 } }
            : undefined,
        grid: compact
            ? {
                left: isHorizontal ? 8 : 8,
                right: 6,
                top: 8,
                bottom: 8,
                containLabel: true,
            }
            : { left: isHorizontal ? 96 : 44, right: 12, top: mapped.length > 1 ? 36 : 16, bottom: 28, containLabel: !isHorizontal },
        xAxis: isHorizontal
            ? { type: 'value', ...axisStyle, axisLabel: { ...axisStyle.axisLabel, show: !compact } }
            : {
                type: 'category',
                data: categoryData,
                boundaryGap: type !== 'line' && type !== 'area',
                ...axisStyle,
                axisLabel: {
                    ...axisStyle.axisLabel,
                    interval: compact ? 'auto' : 0,
                    rotate: compact && categoryData.length > 6 ? 30 : 0,
                },
            },
        yAxis: isHorizontal
            ? {
                type: 'category',
                data: categoryData,
                inverse: true,
                ...axisStyle,
                axisLabel: { ...axisStyle.axisLabel, width: compact ? 48 : undefined, overflow: 'truncate' },
                splitLine: { show: false },
            }
            : { type: 'value', ...axisStyle },
        series: mapped.map((row, index) => {
            const color = paletteColors[index % paletteColors.length];
            const chartType = type === 'area' || type === 'line' ? 'line' : 'bar';
            return {
                name: row.name,
                type: chartType,
                stack: stacked ? 'total' : undefined,
                smooth: true,
                symbol: 'circle',
                symbolSize: compact ? 10 : 8,
                showSymbol: labels.length < 16,
                barWidth: compact ? (isHorizontal ? 10 : 10) : (isHorizontal ? 14 : 18),
                barGap: '30%',
                itemStyle: { color, borderRadius: type === 'line' || type === 'area' ? 0 : (compact ? 2 : 8) },
                lineStyle: { width: compact ? 3 : 3, color },
                areaStyle: (type === 'area' || (compact && type === 'line')) ? { color: gradient(color) } : undefined,
                data: isHorizontal && slices.length ? slices.map((s) => s.value) : row.data,
            };
        }),
    };
}

function useTypeScale() {
    const [scale, setScale] = useState(1);

    useEffect(() => {
        const read = () => {
            const root = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
            setScale(Math.round((root / 16) * 1000) / 1000);
        };
        read();
        window.addEventListener('resize', read);
        return () => window.removeEventListener('resize', read);
    }, []);

    return scale;
}

function scaleChartFonts(value, scale) {
    if (scale === 1 || value == null) {
        return value;
    }
    if (Array.isArray(value)) {
        return value.map((item) => scaleChartFonts(item, scale));
    }
    if (typeof value !== 'object' || value.constructor !== Object) {
        return value;
    }
    const next = {};
    Object.entries(value).forEach(([key, child]) => {
        next[key] = key === 'fontSize' && typeof child === 'number'
            ? Math.round(child * scale)
            : scaleChartFonts(child, scale);
    });
    return next;
}

function ChartCanvas({ type, data, palette, tall, compact = false, fill = false }) {
    const ref = useRef(null);
    const chartRef = useRef(null);
    const typeScale = useTypeScale();
    const option = useMemo(
        () => scaleChartFonts(buildOption(type, data, palette, compact), typeScale),
        [type, data, palette, compact, typeScale],
    );

    useEffect(() => {
        if (!ref.current) {
            return undefined;
        }
        const chart = echarts.init(ref.current, null, { renderer: 'canvas' });
        chartRef.current = chart;
        const observer = new ResizeObserver(() => chart.resize());
        observer.observe(ref.current);
        return () => {
            observer.disconnect();
            chart.dispose();
            chartRef.current = null;
        };
    }, []);

    useEffect(() => {
        chartRef.current?.setOption(option, true);
        chartRef.current?.resize();
    }, [option]);

    return <div ref={ref} className={tall && !fill && !compact ? ui.chartBodyLg : (compact ? ui.dashBoardChart : (fill ? ui.chartBodyFill : ui.chartBody))} />;
}

export default function WidgetCard({ widget, currency = 'USD', compact = false, bare = false, fill = false, leading = null, dragHead = false }) {
    const span = {
        3: ui.chartSpan3,
        4: ui.chartSpan4,
        5: ui.chartSpan5,
        6: ui.chartSpan6,
        7: ui.chartSpan7,
        8: ui.chartSpan8,
        9: ui.chartSpan9,
        10: ui.chartSpan10,
        12: ui.chartSpan12,
    }[widget.col_span] || ui.chartSpan6;
    const data = widget.data || {};
    const empty = data.empty || data.kind === 'empty' || data.kind === 'error';
    const isKpi = widget.chart_type === 'kpi';
    const delta = data.delta;
    const tall = !compact && !fill && ['heatmap', 'treemap', 'radar'].includes(widget.chart_type);

    return (
        <section className={cx(
            bare ? 'min-w-0' : (compact ? 'rounded-lg border border-neutral-200 bg-white p-2 min-w-0 shadow-sm' : ui.chartCard),
            fill && 'flex h-full min-h-0 flex-col',
            !bare && !fill && span,
        )}>
            {bare ? null : (
                <div className={compact ? 'mb-1' : cx(ui.chartHead, fill && 'items-center mb-3', dragHead && 'js-dash-head cursor-grab active:cursor-grabbing select-none')}>
                    {leading}
                    <div className="min-w-0 flex-1">
                        <h2 className={compact ? 'truncate text-[11px] font-semibold text-neutral-900' : ui.chartTitle}>{widget.title}</h2>
                        {!compact ? (
                            <p className={ui.chartMeta}>
                                {t(widget.metric_label_key || `admin.dashboards.metric_${widget.metric}`, widget.metric_label || widget.metric)}
                                {' · '}
                                {widget.range_label_key
                                    ? t(widget.range_label_key, widget.range_label)
                                    : t('admin.dashboards.range_last_n_days', 'Last :n days').replace(':n', String(widget.range_days || ''))}
                            </p>
                        ) : null}
                    </div>
                </div>
            )}
            {isKpi ? (
                <div className={compact ? 'pt-0.5' : cx(ui.chartKpi, fill && 'flex min-h-0 flex-1 flex-col justify-center')}>
                    <div className={compact ? 'text-base font-semibold tracking-tight text-neutral-900 tabular-nums' : ui.chartKpiValue}>
                        {data.money ? money(data.value, currency) : Number(data.value ?? 0).toLocaleString()}
                    </div>
                    {delta != null ? (
                        <div className={cx(delta > 0 ? ui.chartKpiDeltaUp : delta < 0 ? ui.chartKpiDeltaDown : ui.chartKpiDeltaFlat, compact && 'mt-0.5 text-[11px]')}>
                            {delta > 0 ? '↑' : delta < 0 ? '↓' : '→'} {Math.abs(delta)}%{compact ? '' : ` ${t('admin.dashboards.vs_previous_period', 'vs previous period')}`}
                        </div>
                    ) : (
                        !compact ? (
                            <div className={ui.chartKpiDeltaFlat}>{t('admin.dashboards.current_catalog_snapshot', 'Current catalog snapshot')}</div>
                        ) : null
                    )}
                </div>
            ) : empty ? (
                <div className={cx(compact ? cx(ui.dashBoardChart, 'grid place-items-center text-sm text-neutral-500') : (fill ? ui.chartEmptyFill : ui.chartEmpty))}>
                    {data.message || 'No data in this range yet. The chart will fill as orders arrive.'}
                </div>
            ) : (
                <ChartCanvas
                    type={widget.chart_type}
                    data={{ ...data, target: widget.target, name: widget.title }}
                    palette={widget.palette}
                    tall={tall}
                    compact={compact}
                    fill={fill}
                />
            )}
        </section>
    );
}
