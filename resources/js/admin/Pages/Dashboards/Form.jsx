import { router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import Layout from '../Layout';
import {
    Button,
    Checkbox,
    ChoiceCards,
    ColorField,
    Field,
    FormErrors,
    Icon,
    Input,
    PageHeader,
    SearchSelect,
    Select,
    Textarea,
    Toggle,
    ui,
    cx,
    t,
} from '../../ui';

const PRESET_HEX = {
    violet: '#7C3AED',
    sky: '#0284C7',
    emerald: '#059669',
    amber: '#D97706',
    rose: '#E11D48',
    indigo: '#4F46E5',
};

const emptyWidget = (charts = [], metrics = []) => {
    const chart = charts[0]?.value || 'area';
    const metric = (metrics.find((row) => (row.charts || []).includes(chart)) || metrics[0])?.value || 'revenue_by_day';
    return {
        title: 'New widget',
        chart_type: chart,
        metric,
        range_days: 30,
        col_span: 6,
        row_span: 2,
        grid_x: null,
        grid_y: null,
        palette: '#7C3AED',
        manual_labels: '',
        manual_values: '',
        target: 100,
    };
};

function toHex(palette) {
    if (typeof palette === 'string' && /^#?[0-9A-Fa-f]{6}$/.test(palette)) {
        return palette.startsWith('#') ? palette.toUpperCase() : `#${palette.toUpperCase()}`;
    }

    return PRESET_HEX[palette] || '#7C3AED';
}

function RangeField({ widget, ranges, rangeMin = 1, rangeMax = 3650, onChange }) {
    const days = Number(widget.range_days) || 30;
    const presetKeys = Object.keys(ranges || {}).map(Number);
    const [custom, setCustom] = useState(() => !presetKeys.includes(days));

    return (
        <div className="grid gap-2">
            <Select
                wide
                value={custom ? 'custom' : String(days)}
                onChange={(e) => {
                    if (e.target.value === 'custom') {
                        setCustom(true);
                        onChange({ range_days: days });
                        return;
                    }
                    setCustom(false);
                    onChange({ range_days: Number(e.target.value) });
                }}
            >
                {Object.entries(ranges || {}).map(([value, row]) => {
                    const label = typeof row === 'string' ? row : (row?.label || String(value));
                    const key = typeof row === 'object' && row?.label_key ? row.label_key : `admin.dashboards.range_${value}`;
                    return (
                        <option key={value} value={value}>{t(key, label)}</option>
                    );
                })}
                <option value="custom">{t('admin.dashboards.custom_days', 'Custom number of days')}</option>
            </Select>
            {custom ? (
                <Input
                    wide
                    type="number"
                    min={rangeMin}
                    max={rangeMax}
                    autoFocus
                    value={days}
                    onChange={(e) => onChange({ range_days: clampDays(e.target.value, rangeMin, rangeMax) })}
                    placeholder={t('admin.dashboards.enter_number_of_days', 'Enter number of days')}
                />
            ) : null}
        </div>
    );
}

const WIDTH_BOXES = [
    { cols: 2, key: 'admin.dashboards.width_narrow', label: 'Narrow', bars: 2 },
    { cols: 3, key: 'admin.dashboards.width_quarter', label: 'Quarter', bars: 3 },
    { cols: 4, key: 'admin.dashboards.width_third', label: 'Third', bars: 4 },
    { cols: 6, key: 'admin.dashboards.width_half', label: 'Half', bars: 6 },
    { cols: 8, key: 'admin.dashboards.width_wide', label: 'Wide', bars: 8 },
    { cols: 12, key: 'admin.dashboards.width_full', label: 'Full', bars: 12 },
];

function WidthBoxes({ value, onChange }) {
    const current = Number(value) || 6;

    return (
        <div className={ui.chartWidthPick}>
            {WIDTH_BOXES.map((opt) => {
                const active = current === opt.cols;
                return (
                    <button
                        key={opt.cols}
                        type="button"
                        className={active ? ui.chartWidthCardOn : ui.chartWidthCard}
                        onClick={() => onChange(opt.cols)}
                    >
                        <div className={ui.chartWidthBars} aria-hidden>
                            {Array.from({ length: 12 }, (_, i) => (
                                <span key={i} className={i < opt.bars ? ui.chartWidthBarOn : ui.chartWidthBarOff} />
                            ))}
                        </div>
                        <div className="text-[11px] font-semibold text-neutral-800">{t(opt.key, opt.label)}</div>
                    </button>
                );
            })}
        </div>
    );
}

function clampDays(value, min, max) {
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return min;
    }

    return Math.min(max, Math.max(min, Math.round(n)));
}

function WidgetColorField({ widget, palettes = [], onChange }) {
    const hex = toHex(widget.palette);
    const swatches = Array.isArray(palettes) && palettes[0]?.hex
        ? palettes
        : Object.entries(PRESET_HEX).map(([name, value]) => ({ name, hex: value }));

    return (
        <ColorField
            value={hex}
            onChange={(next) => onChange({ palette: next })}
            swatches={swatches}
            ariaLabel={t('admin.dashboards.pick_color', 'Pick color')}
        />
    );
}

export default function Form({
    dashboard,
    charts = [],
    metrics = [],
    ranges = {},
    range_min = 1,
    range_max = 3650,
    palettes = [],
    visibility = [],
    profiles = [],
    selected = {},
}) {
    const form = useForm({
        name: dashboard?.name || '',
        description: dashboard?.description || '',
        visibility: dashboard?.visibility || 'assigned',
        is_default: dashboard?.is_default ?? false,
        is_enabled: dashboard?.is_enabled ?? true,
        admin_ids: dashboard?.admin_ids || [],
        profile_ids: dashboard?.profile_ids || [],
        widgets: dashboard?.widgets?.length ? dashboard.widgets : [
            { title: 'Revenue', chart_type: 'kpi', metric: 'revenue', range_days: 30, col_span: 3, row_span: 1, grid_x: null, grid_y: null, palette: '#7C3AED', manual_labels: '', manual_values: '', target: 100 },
            { title: 'Revenue trend', chart_type: 'area', metric: 'revenue_by_day', range_days: 30, col_span: 8, row_span: 2, grid_x: null, grid_y: null, palette: '#7C3AED', manual_labels: '', manual_values: '', target: 100 },
        ],
    });

    const metricsFor = (chart) => metrics.filter((row) => (row.charts || []).includes(chart));
    const assignedOnly = form.data.visibility === 'assigned';

    const toggleProfile = (profileId) => {
        const id = Number(profileId);
        const current = form.data.profile_ids.map(Number);
        form.setData(
            'profile_ids',
            current.includes(id) ? current.filter((row) => row !== id) : [...current, id],
        );
    };

    const setWidget = (index, patch) => {
        form.setData('widgets', form.data.widgets.map((row, i) => {
            if (i !== index) {
                return row;
            }
            const next = { ...row, ...patch };
            if (patch.col_span && next.grid_x != null) {
                next.grid_x = Math.max(0, Math.min(next.grid_x, 12 - Number(patch.col_span)));
            }
            if (patch.chart_type) {
                const allowed = metricsFor(patch.chart_type);
                if (!allowed.some((row) => row.value === next.metric)) {
                    next.metric = allowed[0]?.value || next.metric;
                }
            }
            return next;
        }));
    };

    const moveWidget = (index, dir) => {
        const next = [...form.data.widgets];
        const swap = index + dir;
        if (swap < 0 || swap >= next.length) {
            return;
        }
        [next[index], next[swap]] = [next[swap], next[index]];
        form.setData('widgets', next);
    };

    const dragFrom = useRef(null);
    const dropWidget = (index) => {
        const from = dragFrom.current;
        dragFrom.current = null;
        if (from == null || from === index) {
            return;
        }
        const next = [...form.data.widgets];
        const [moved] = next.splice(from, 1);
        next.splice(index, 0, moved);
        form.setData('widgets', next);
    };

    const submit = (e) => {
        e.preventDefault();
        if (dashboard) {
            form.put(`/admin/dashboards/${dashboard.id}`);
        } else {
            form.post('/admin/dashboards');
        }
    };

    return (
        <Layout>
            <PageHeader
                title={dashboard ? t('admin.dashboards.edit_dashboard', 'Edit dashboard') : t('admin.dashboards.create_dashboard', 'Create dashboard')}
                crumbs={[
                    { href: '/admin/dashboards', label: t('admin.dashboards.dashboards', 'Dashboards') },
                    { label: dashboard ? t('admin.common.edit', 'Edit') : t('admin.common.create', 'Create') },
                ]}
                actions={
                    dashboard ? (
                        <Button variant="secondary" href={`/admin/dashboards/${dashboard.id}`}>
                            {t('admin.dashboards.open_board', 'Open board')}
                        </Button>
                    ) : null
                }
                className="mb-6"
            />

            <form onSubmit={submit} className={ui.formLayoutWide}>
                <div className={ui.formMain}>
                    <FormErrors errors={form.errors} />

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.dashboards.board', 'Board')}</h2>
                        </div>
                        <div className="grid gap-3">
                            <Field label={t('admin.dashboards.name', 'Name')} error={form.errors.name}>
                                <Input
                                    wide
                                    invalid={!!form.errors.name}
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('admin.dashboards.e_g_weekend_sales_room', 'e.g. Weekend sales room')}
                                />
                            </Field>
                            <Field label={t('admin.dashboards.description', 'Description')} error={form.errors.description}>
                                <Textarea
                                    rows={2}
                                    value={form.data.description}
                                    onChange={(e) => form.setData('description', e.target.value)}
                                    placeholder={t('admin.dashboards.what_this_board_is_for', 'What this board is for')}
                                />
                            </Field>
                        </div>
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.dashboards.who_can_open_it', 'Who can open it')}</h2>
                        </div>
                        <ChoiceCards name="visibility" options={visibility} value={form.data.visibility} onChange={(value) => form.setData('visibility', value)} />

                        {assignedOnly ? (
                            <div className={ui.blockGap}>
                                <Field
                                    label={t('admin.dashboards.employee_profiles', 'Employee profiles')}
                                    error={form.errors.profile_ids}
                                    hint={t(
                                        'admin.dashboards.employees_with_these_profiles_can_open_this',
                                        'Employees using these profiles can open this dashboard. Super admins always see every board.',
                                    )}
                                >
                                    {profiles.length ? (
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                            {profiles.map((profile) => {
                                                const checked = form.data.profile_ids.map(Number).includes(Number(profile.id));
                                                return (
                                                    <div key={profile.id} className={checked ? ui.choiceCardActive : ui.choiceCard}>
                                                        <Checkbox
                                                            label={profile.name}
                                                            checked={checked}
                                                            onChange={() => toggleProfile(profile.id)}
                                                        />
                                                        {profile.description ? (
                                                            <p className={`${ui.choiceHint} mt-1 pl-6`}>{profile.description}</p>
                                                        ) : null}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    ) : (
                                        <p className={`${ui.muted} mt-2`}>
                                            {t('admin.dashboards.no_employee_profiles_yet', 'No employee profiles yet. Create one under Staff → Profiles.')}
                                        </p>
                                    )}
                                </Field>

                                <SearchSelect
                                    label={t('admin.dashboards.specific_employees', 'Specific employees (optional)')}
                                    searchUrl="/admin/dashboards/search/admins"
                                    value={form.data.admin_ids}
                                    selectedOptions={selected.admins || []}
                                    onChange={(admin_ids) => form.setData('admin_ids', admin_ids)}
                                    placeholder={t('admin.dashboards.search_staff_by_name_or_email', 'Search staff by name or email…')}
                                    hint={t(
                                        'admin.dashboards.add_individual_employees_beyond_their_profil',
                                        'Optional: grant access to individual employees even if their profile is not selected.',
                                    )}
                                />
                            </div>
                        ) : null}
                    </section>

                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.dashboards.widgets', 'Widgets')}</h2>
                            <Button type="button" variant="secondary" icon="plus" onClick={() => form.setData('widgets', [...form.data.widgets, emptyWidget(charts, metrics)])}>
                                {t('admin.dashboards.add_widget', 'Add widget')}
                            </Button>
                        </div>
                        <p className={`${ui.metaK} mb-4`}>
                            {t('admin.dashboards.each_widget_picks_a_chart_design_and_a_live_stor', 'Each widget picks a chart design and a live store metric. Drag the grip to reorder. On the open board you can also resize blocks.')}
                        </p>
                        <div className={ui.stack}>
                            {form.data.widgets.map((widget, index) => {
                                const allowedMetrics = metricsFor(widget.chart_type);
                                return (
                                    <div
                                        key={`${widget.chart_type}-${index}`}
                                        className={ui.widgetEditor}
                                        onDragOver={(event) => event.preventDefault()}
                                        onDrop={() => dropWidget(index)}
                                    >
                                        <div className="mb-1 flex items-center justify-between gap-2">
                                            <button
                                                type="button"
                                                className={ui.chartGrip}
                                                draggable
                                                title={t('admin.dashboards.move_widget', 'Move widget')}
                                                onDragStart={() => { dragFrom.current = index; }}
                                            >
                                                <Icon name="grip" />
                                            </button>
                                            <span className={ui.muted}>{t('admin.dashboards.widget_n', 'Widget')} {index + 1}</span>
                                        </div>
                                        <div className="grid gap-3">
                                            <Field label={t('admin.dashboards.title', 'Title')} error={form.errors[`widgets.${index}.title`]}>
                                                <Input
                                                    wide
                                                    value={widget.title}
                                                    onChange={(e) => setWidget(index, { title: e.target.value })}
                                                />
                                            </Field>
                                            <Field
                                                label={t('admin.dashboards.width', 'Width')}
                                                hint={t('admin.dashboards.width_boxes_hint', 'Pick how much of the row this block should fill.')}
                                            >
                                                <WidthBoxes value={widget.col_span} onChange={(cols) => setWidget(index, { col_span: cols })} />
                                            </Field>
                                            <Field label={t('admin.dashboards.height', 'Height')}>
                                                <Select wide value={widget.row_span || (widget.chart_type === 'kpi' ? 1 : 2)} onChange={(e) => setWidget(index, { row_span: Number(e.target.value) })}>
                                                    <option value={1}>{t('admin.dashboards.height_short', 'Short')}</option>
                                                    <option value={2}>{t('admin.dashboards.height_medium', 'Medium')}</option>
                                                    <option value={3}>{t('admin.dashboards.height_tall', 'Tall')}</option>
                                                    <option value={4}>{t('admin.dashboards.height_extra_tall', 'Extra tall')}</option>
                                                    <option value={6}>{t('admin.dashboards.height_huge', 'Huge')}</option>
                                                    <option value={8}>{t('admin.dashboards.height_full', 'Very tall')}</option>
                                                </Select>
                                            </Field>
                                        </div>
                                        <Field label={t('admin.dashboards.chart_design', 'Chart design')}>
                                            <div className={ui.chartTypeGrid}>
                                                {charts.map((opt) => {
                                                    const active = widget.chart_type === opt.value;
                                                    return (
                                                        <button
                                                            key={opt.value}
                                                            type="button"
                                                            className={active ? ui.choiceCardActive : ui.choiceCard}
                                                            onClick={() => setWidget(index, { chart_type: opt.value })}
                                                        >
                                                            <div className={ui.choiceTitle}>{t(opt.label_key || `admin.dashboards.chart_${opt.value}`, opt.label)}</div>
                                                            <div className={ui.choiceHint}>{t(opt.hint_key || `admin.dashboards.chart_${opt.value}_hint`, opt.hint)}</div>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        </Field>
                                        <div className={ui.fieldGrid}>
                                            <Field label={t('admin.dashboards.data', 'Data')}>
                                                <Select wide value={widget.metric} onChange={(e) => setWidget(index, { metric: e.target.value })}>
                                                    {allowedMetrics.map((opt) => (
                                                        <option key={opt.value} value={opt.value}>
                                                            {t(opt.label_key || `admin.dashboards.metric_${opt.value}`, opt.label)}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </Field>
                                            <Field label={t('admin.dashboards.range', 'Range')}>
                                                <RangeField
                                                    widget={widget}
                                                    ranges={ranges}
                                                    rangeMin={range_min}
                                                    rangeMax={range_max}
                                                    onChange={(patch) => setWidget(index, patch)}
                                                />
                                            </Field>
                                            <Field
                                                label={t('admin.dashboards.color', 'Color')}
                                                hint={t('admin.dashboards.hex_or_swatch', 'Paste a hex code or pick a swatch.')}
                                            >
                                                <WidgetColorField widget={widget} palettes={palettes} onChange={(patch) => setWidget(index, patch)} />
                                            </Field>
                                        </div>
                                        {widget.metric === 'manual' ? (
                                            <div className={ui.fieldGrid}>
                                                <Field label={t('admin.dashboards.labels_comma_separated', 'Labels (comma separated)')}>
                                                    <Input wide value={widget.manual_labels} onChange={(e) => setWidget(index, { manual_labels: e.target.value })} placeholder={t('admin.dashboards.mon_tue_wed', 'Mon, Tue, Wed')} />
                                                </Field>
                                                <Field label={t('admin.dashboards.values_comma_separated', 'Values (comma separated)')}>
                                                    <Input wide value={widget.manual_values} onChange={(e) => setWidget(index, { manual_values: e.target.value })} placeholder="12, 19, 8" />
                                                </Field>
                                            </div>
                                        ) : null}
                                        {widget.chart_type === 'gauge' ? (
                                            <Field label={t('admin.dashboards.target', 'Target')}>
                                                <Input wide type="number" min="0" value={widget.target} onChange={(e) => setWidget(index, { target: e.target.value })} />
                                            </Field>
                                        ) : null}
                                        <div className={ui.toolbar}>
                                            <Button type="button" variant="secondary" onClick={() => moveWidget(index, -1)}>{t('admin.dashboards.up', 'Up')}</Button>
                                            <Button type="button" variant="secondary" onClick={() => moveWidget(index, 1)}>{t('admin.dashboards.down', 'Down')}</Button>
                                            <Button
                                                type="button"
                                                variant="danger"
                                                onClick={() => form.setData('widgets', form.data.widgets.filter((_, i) => i !== index))}
                                            >
                                                {t('admin.common.remove', 'Remove')}
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                </div>

                <div className={ui.formSide}>
                    <section className={ui.sectionCard}>
                        <div className={ui.sectionHead}>
                            <h2 className={ui.sectionTitle}>{t('admin.dashboards.status', 'Status')}</h2>
                        </div>
                        <Toggle label={t('admin.common.active', 'Active')} value={!!form.data.is_enabled} onChange={(value) => form.setData('is_enabled', value)} />
                        <div className={ui.sectionRule}>
                            <Toggle label={t('admin.dashboards.default_dashboard', 'Default dashboard')} value={!!form.data.is_default} onChange={(value) => form.setData('is_default', value)} />
                            <p className={`${ui.metaK} mt-2`}>{t('admin.dashboards.the_default_dashboard_opens_for_people_who_can_access_it', 'The default dashboard opens for people who can access it.')}</p>
                        </div>
                    </section>
                    <section className={ui.sectionCard}>
                        <div className={ui.actionList}>
                            <Button icon="check" busy={form.processing} type="submit">
                                {dashboard ? t('admin.dashboards.save_dashboard', 'Save dashboard') : t('admin.dashboards.create_dashboard', 'Create dashboard')}
                            </Button>
                            <Button type="button" variant="secondary" href="/admin/dashboards">
                                {t('admin.common.cancel', 'Cancel')}
                            </Button>
                        </div>
                    </section>
                </div>
            </form>
        </Layout>
    );
}
