import { useEffect, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { GridStack } from 'gridstack';
import 'gridstack/dist/gridstack.min.css';
import { Button, Icon, ui, t } from '../../ui';
import WidgetCard from './ChartWidget';
import './board.css';

const COLS = 12;
const MAX_COL = 12;
const MAX_ROW = 8;
const MAX_Y = 80;

function clamp(n, min, max) {
    return Math.min(max, Math.max(min, Math.round(Number(n) || 0)));
}

function hasGrid(widget) {
    return widget.grid_x != null && widget.grid_x !== '' && widget.grid_y != null && widget.grid_y !== '';
}

function packMissing(widgets) {
    const placed = [];
    const missing = [];
    widgets.forEach((widget) => {
        if (hasGrid(widget)) {
            placed.push({
                ...widget,
                grid_x: clamp(widget.grid_x, 0, COLS - 1),
                grid_y: clamp(widget.grid_y, 0, MAX_Y),
                col_span: clamp(widget.col_span || 4, 2, MAX_COL),
                row_span: clamp(widget.row_span || 3, 2, MAX_ROW),
            });
        } else {
            missing.push(widget);
        }
    });

    let maxY = 0;
    placed.forEach((widget) => {
        maxY = Math.max(maxY, widget.grid_y + widget.row_span);
    });

    let cursorX = 0;
    let cursorY = placed.length ? maxY : 0;
    let rowH = 0;
    const packed = missing.map((widget) => {
        const w = clamp(widget.col_span || 4, 2, MAX_COL);
        const h = clamp(widget.row_span || 3, 2, MAX_ROW);
        if (cursorX + w > COLS) {
            cursorX = 0;
            cursorY += rowH || h;
            rowH = 0;
        }
        const next = { ...widget, col_span: w, row_span: h, grid_x: cursorX, grid_y: cursorY };
        cursorX += w;
        rowH = Math.max(rowH, h);
        return next;
    });

    const byId = new Map([...placed, ...packed].map((widget) => [widget.id, widget]));
    return widgets.map((widget) => byId.get(widget.id) || widget);
}

function gsAttrs(widget) {
    return {
        'gs-id': String(widget.id),
        'gs-x': String(Number(widget.grid_x ?? 0)),
        'gs-y': String(Number(widget.grid_y ?? 0)),
        'gs-w': String(Number(widget.col_span || 4)),
        'gs-h': String(Number(widget.row_span || 3)),
        'gs-min-w': '2',
        'gs-min-h': '2',
    };
}

async function hostReady(el, maxMs = 4000) {
    const started = performance.now();
    while (performance.now() - started < maxMs) {
        if (el.isConnected && el.clientWidth > 0) {
            return true;
        }
        await new Promise((resolve) => requestAnimationFrame(() => resolve()));
    }
    return el.isConnected && el.clientWidth > 0;
}

function readLayout(grid) {
    return (grid.save(false) || []).map((node, position) => ({
        id: Number(node.id),
        position,
        col_span: clamp(node.w, 2, MAX_COL),
        row_span: clamp(node.h, 2, MAX_ROW),
        grid_x: clamp(node.x, 0, COLS - 1),
        grid_y: clamp(node.y, 0, MAX_Y),
    }));
}

export default function WidgetBoard({ dashboardId, widgets = [], currency, editable = false }) {
    const packed = useMemo(() => packMissing(widgets), [widgets]);
    const hostRef = useRef(null);
    const gridRef = useRef(null);
    const [dirty, setDirty] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const host = hostRef.current;
        let cancelled = false;
        let grid;

        (async () => {
            if (!host || !packed.length || !(await hostReady(host)) || cancelled) {
                return;
            }
            delete host.gridstack;
            grid = GridStack.init({
                column: 12,
                cellHeight: 84,
                margin: 10,
                float: false,
                animate: false,
                auto: false,
                draggable: {
                    handle: '.sd-widget-head',
                    cancel: 'button, input, select, a, canvas',
                },
                resizable: {
                    handles: 'se',
                    autoHide: true,
                },
                minRow: 1,
            }, host);
            if (cancelled) {
                grid.destroy(false);
                return;
            }
            grid.enableMove(editable);
            grid.enableResize(editable);
            packed.forEach((widget) => {
                const el = host.querySelector(`.grid-stack-item[gs-id="${widget.id}"]`);
                if (!el) {
                    return;
                }
                Object.entries(gsAttrs(widget)).forEach(([key, value]) => el.setAttribute(key, value));
                if (!el.gridstackNode) {
                    grid.makeWidget(el);
                }
                const node = el.gridstackNode;
                const next = {
                    x: Number(widget.grid_x ?? 0),
                    y: Number(widget.grid_y ?? 0),
                    w: Number(widget.col_span || 4),
                    h: Number(widget.row_span || 3),
                };
                if (!node || node.x !== next.x || node.y !== next.y || node.w !== next.w || node.h !== next.h) {
                    grid.update(el, next);
                }
            });
            grid.setAnimation(true);
            if (editable) {
                grid.on('dragstop', () => {
                    grid.onResize();
                    setDirty(true);
                });
                grid.on('resizestop', () => {
                    grid.onResize();
                    setDirty(true);
                });
            }
            requestAnimationFrame(() => grid.onResize());
            window.setTimeout(() => grid.onResize(), 150);
            window.setTimeout(() => grid.onResize(), 400);
            gridRef.current = grid;
        })();

        return () => {
            cancelled = true;
            if (grid) {
                grid.off('dragstop');
                grid.off('resizestop');
                grid.destroy(false);
            }
            gridRef.current = null;
        };
    }, [editable, packed]);

    const saveLayout = () => {
        const grid = gridRef.current;
        if (!editable || !dashboardId || !dirty || !grid) {
            return;
        }
        setSaving(true);
        router.patch(`/admin/dashboards/${dashboardId}/layout`, {
            widgets: readLayout(grid),
        }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSaving(false),
            onSuccess: () => setDirty(false),
        });
    };

    return (
        <div>
            {editable ? (
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <p className={ui.muted}>
                        {t('admin.dashboards.layout_hint_gridstack', 'Drag a block by its header. Hover the bottom-right corner to resize. Neighbours swap or shift to make room. Click Save layout when you are done.')}
                    </p>
                    <Button icon="check" disabled={!dirty} busy={saving} onClick={saveLayout}>
                        {t('admin.dashboards.save_layout', 'Save layout')}
                    </Button>
                </div>
            ) : null}

            <div ref={hostRef} className="grid-stack sd-board-grid">
                {packed.map((widget) => (
                    <div key={widget.id} className="grid-stack-item" {...gsAttrs(widget)}>
                        <div className="grid-stack-item-content">
                            <div className="sd-widget-card">
                                <div className="sd-widget-card-body">
                                    <div className="sd-widget-head" style={editable ? undefined : { cursor: 'default' }}>
                                        {editable ? (
                                            <span className="sd-widget-drag-handle">
                                                <Icon name="grip" />
                                            </span>
                                        ) : null}
                                        <div className="sd-widget-head-main min-w-0">
                                            <h2 className="sd-widget-title">{widget.title}</h2>
                                            <div className="sd-widget-head-meta">
                                                {t(widget.metric_label_key || `admin.dashboards.metric_${widget.metric}`, widget.metric_label || widget.metric)}
                                                {' · '}
                                                {widget.range_label_key
                                                    ? t(widget.range_label_key, widget.range_label)
                                                    : t('admin.dashboards.range_last_n_days', 'Last :n days').replace(':n', String(widget.range_days || ''))}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="sd-widget-chart-area">
                                        <WidgetCard widget={widget} currency={currency} bare fill />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}
