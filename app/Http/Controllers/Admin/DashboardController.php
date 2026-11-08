<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Dashboard\ChartCatalog;
use App\Domain\Dashboard\DefaultDashboard;
use App\Domain\Dashboard\MetricService;
use App\Domain\Dashboard\Models\Dashboard;
use App\Domain\Auth\Models\Profile;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(protected MetricService $metrics) {}

    public function home(Request $request): RedirectResponse
    {
        return redirect()->route('admin.dashboards.index');
    }

    public function show(Request $request, Dashboard $dashboard): Response
    {
        $this->authorizeView($request, $dashboard);
        $admin = $request->user('admin');

        $canManage = $admin->isSuperAdmin() || $admin->hasPermission('dashboards.manage');

        return Inertia::render('Dashboards/Show', [
            'dashboard' => $this->metrics->present($dashboard->load(['widgets', 'assignees', 'assignedProfiles', 'creator'])),
            'available' => Dashboard::query()->accessibleTo($admin)->where('is_enabled', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => $canManage,
        ]);
    }

    public function index(Request $request): Response
    {
        $admin = $request->user('admin');
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());

        $base = Dashboard::query()->accessibleTo($admin);

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
            'assigned' => (clone $base)->where('visibility', 'assigned')->count(),
        ];

        $canManage = $admin->isSuperAdmin() || $admin->hasPermission('dashboards.manage');

        $dashboards = Dashboard::query()
            ->accessibleTo($admin)
            ->with(['assignees', 'widgets'])
            ->withCount('widgets')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($status === 'assigned', fn ($query) => $query->where('visibility', 'assigned'))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")->orWhere('description', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->adminPaginate()
            ->through(function (Dashboard $board) use ($canManage) {
                $card = $this->metrics->presentCard($board);
                $card['actions'] = [
                    'edit' => $canManage,
                    'delete' => $canManage && ! $board->is_default,
                ];

                return $card;
            });

        return Inertia::render('Dashboards/Index', [
            'dashboards' => $dashboards,
            'counts' => $counts,
            'canManage' => $canManage,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizeManage($request);

        return Inertia::render('Dashboards/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request);
        $board = $this->persist(new Dashboard, $data, $request->user('admin'));

        return redirect()->route('admin.dashboards.show', $board)->with('success', 'Dashboard created.');
    }

    public function edit(Request $request, Dashboard $dashboard): Response
    {
        $this->authorizeManage($request);
        $dashboard->load(['widgets', 'assignees', 'assignedProfiles']);

        return Inertia::render('Dashboards/Form', [
            ...$this->formProps(),
            'dashboard' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'description' => $dashboard->description ?? '',
                'visibility' => $dashboard->visibility,
                'is_default' => $dashboard->is_default,
                'is_enabled' => $dashboard->is_enabled,
                'admin_ids' => $dashboard->assignees->pluck('id')->all(),
                'profile_ids' => $dashboard->assignedProfiles->pluck('id')->all(),
                'widgets' => $dashboard->widgets->map(fn ($widget) => [
                    'title' => $widget->title,
                    'chart_type' => $widget->chart_type,
                    'metric' => $widget->metric,
                    'range_days' => $widget->range_days,
                    'col_span' => $widget->col_span,
                    'row_span' => max(1, min(ChartCatalog::ROW_MAX, (int) $widget->option('row_span', $widget->chart_type === 'kpi' ? 1 : 2))),
                    'grid_x' => $widget->option('grid_x') === null ? null : (int) $widget->option('grid_x'),
                    'grid_y' => $widget->option('grid_y') === null ? null : (int) $widget->option('grid_y'),
                    'palette' => ChartCatalog::normalizePalette($widget->option('palette', 'violet')),
                    'manual_labels' => $widget->option('manual_labels', ''),
                    'manual_values' => $widget->option('manual_values', ''),
                    'target' => $widget->option('target', 100),
                ])->values()->all(),
            ],
            'selected' => [
                'admins' => $dashboard->assignees->map(fn (Admin $admin) => [
                    'id' => $admin->id,
                    'name' => $admin->name.' ('.$admin->email.')',
                ]),
            ],
        ]);
    }

    public function update(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $this->validated($request, $dashboard);
        $this->persist($dashboard, $data, $request->user('admin'));

        return redirect()->route('admin.dashboards.show', $dashboard)->with('success', 'Dashboard updated.');
    }

    public function destroy(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeManage($request);
        if ($dashboard->is_default) {
            return back()->with('error', 'The default dashboard cannot be deleted. Make another board default first.');
        }
        $dashboard->delete();

        return redirect()->route('admin.dashboards.index')->with('success', 'Dashboard deleted.');
    }

    public function toggle(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeManage($request);
        if ($dashboard->is_default && $dashboard->is_enabled) {
            return back()->with('error', 'Turn on another default dashboard before disabling this one.');
        }
        $dashboard->update(['is_enabled' => ! $dashboard->is_enabled]);

        return back()->with('success', $dashboard->is_enabled ? 'Dashboard activated.' : 'Dashboard deactivated.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:dashboards,id'],
        ]);

        Dashboard::query()->whereIn('id', $data['ids'])->where('is_default', false)->delete();

        return back()->with('success', 'Selected dashboards deleted.');
    }

    public function searchAdmins(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());
        $results = Admin::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'email'])
            ->map(fn (Admin $admin) => [
                'id' => $admin->id,
                'name' => $admin->name.' ('.$admin->email.')',
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    /** @return array<string, mixed> */
    protected function formProps(): array
    {
        return [
            'charts' => ChartCatalog::chartOptions(),
            'metrics' => ChartCatalog::metricOptions(),
            'ranges' => ChartCatalog::rangeOptions(),
            'range_min' => ChartCatalog::RANGE_MIN,
            'range_max' => ChartCatalog::RANGE_MAX,
            'spans' => ChartCatalog::SPANS,
            'palettes' => ChartCatalog::paletteSwatches(),
            'profiles' => Profile::query()
                ->where('is_enabled', true)
                ->orderBy('name')
                ->get(['id', 'name', 'description'])
                ->map(fn (Profile $profile) => [
                    'id' => $profile->id,
                    'name' => $profile->name,
                    'description' => $profile->description,
                ])
                ->values()
                ->all(),
            'visibility' => collect(Dashboard::VISIBILITY)
                ->map(fn ($label, $value) => [
                    'value' => $value,
                    'label' => $label,
                    'label_key' => 'admin.dashboards.visibility_'.$value,
                    'hint' => $value === 'assigned'
                        ? 'Only super admins and the employee profiles (or people) you pick below'
                        : 'Every signed-in admin can open this board',
                    'hint_key' => 'admin.dashboards.visibility_'.$value.'_hint',
                ])
                ->values()
                ->all(),
            'selected' => ['admins' => []],
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Dashboard $existing = null): array
    {
        $request->merge([
            'description' => $request->input('description') === '' ? null : $request->input('description'),
        ]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'visibility' => ['required', Rule::in(array_keys(Dashboard::VISIBILITY))],
            'is_default' => ['nullable', 'boolean'],
            'is_enabled' => ['required', 'boolean'],
            'admin_ids' => ['nullable', 'array'],
            'admin_ids.*' => ['integer', 'exists:admins,id'],
            'profile_ids' => ['nullable', 'array'],
            'profile_ids.*' => ['integer', 'exists:profiles,id'],
            'widgets' => ['required', 'array', 'min:1'],
            'widgets.*.title' => ['required', 'string', 'max:120'],
            'widgets.*.chart_type' => ['required', Rule::in(array_keys(ChartCatalog::CHARTS))],
            'widgets.*.metric' => ['required', Rule::in(array_keys(ChartCatalog::METRICS))],
            'widgets.*.range_days' => ['required', 'integer', 'min:'.ChartCatalog::RANGE_MIN, 'max:'.ChartCatalog::RANGE_MAX],
            'widgets.*.col_span' => ['required', 'integer', 'min:'.ChartCatalog::SPAN_MIN, 'max:'.ChartCatalog::SPAN_MAX],
            'widgets.*.row_span' => ['nullable', 'integer', 'min:'.ChartCatalog::ROW_MIN, 'max:'.ChartCatalog::ROW_MAX],
            'widgets.*.grid_x' => ['nullable', 'integer', 'min:0', 'max:'.(ChartCatalog::SPAN_MAX - 1)],
            'widgets.*.grid_y' => ['nullable', 'integer', 'min:0', 'max:'.ChartCatalog::GRID_Y_MAX],
            'widgets.*.palette' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (! ChartCatalog::isValidPalette(is_string($value) ? $value : null)) {
                    $fail('Choose a named palette or a 6-digit hex color.');
                }
            }],
            'widgets.*.manual_labels' => ['nullable', 'string'],
            'widgets.*.manual_values' => ['nullable', 'string'],
            'widgets.*.target' => ['nullable', 'numeric', 'min:0'],
        ]);

        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_enabled'] = (bool) $data['is_enabled'];
        $data['admin_ids'] = array_values(array_unique(array_map('intval', $data['admin_ids'] ?? [])));
        $data['profile_ids'] = array_values(array_unique(array_map('intval', $data['profile_ids'] ?? [])));

        if ($data['visibility'] !== 'assigned') {
            $data['admin_ids'] = [];
            $data['profile_ids'] = [];
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function persist(Dashboard $dashboard, array $data, Admin $actor): Dashboard
    {
        if ($data['is_default']) {
            $ignore = $dashboard->exists ? $dashboard->id : 0;
            Dashboard::query()->when($ignore, fn ($query) => $query->where('id', '!=', $ignore))->update(['is_default' => false]);
        }

        $dashboard->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'visibility' => $data['visibility'],
            'is_default' => $data['is_default'],
            'is_enabled' => $data['is_enabled'],
        ]);
        if (! $dashboard->exists) {
            $dashboard->slug = DefaultDashboard::slugFrom($data['name']);
            $dashboard->created_by_admin_id = $actor->id;
        }
        $dashboard->save();
        $dashboard->assignees()->sync($data['admin_ids']);
        $dashboard->assignedProfiles()->sync($data['profile_ids']);

        $dashboard->widgets()->delete();
        foreach (array_values($this->withGrid($data['widgets'])) as $index => $widget) {
            $dashboard->widgets()->create([
                'title' => $widget['title'],
                'chart_type' => $widget['chart_type'],
                'metric' => $widget['metric'],
                'range_days' => $widget['range_days'],
                'col_span' => $widget['col_span'],
                'position' => $index,
                'options' => [
                    'palette' => ChartCatalog::normalizePalette($widget['palette'] ?? 'violet'),
                    'row_span' => max(ChartCatalog::ROW_MIN, min(ChartCatalog::ROW_MAX, (int) ($widget['row_span'] ?? ($widget['chart_type'] === 'kpi' ? 1 : 2)))),
                    'grid_x' => (int) $widget['grid_x'],
                    'grid_y' => (int) $widget['grid_y'],
                    'manual_labels' => $widget['manual_labels'] ?? '',
                    'manual_values' => $widget['manual_values'] ?? '',
                    'target' => $widget['target'] ?? 100,
                ],
            ]);
        }

        return $dashboard->refresh()->load('widgets');
    }

    /** @param  array<int, array<string, mixed>>  $widgets */
    protected function withGrid(array $widgets): array
    {
        $cursorX = 0;
        $cursorY = 0;
        $rowH = 0;
        $out = [];

        foreach ($widgets as $widget) {
            $width = max(ChartCatalog::SPAN_MIN, min(ChartCatalog::SPAN_MAX, (int) $widget['col_span']));
            $height = max(ChartCatalog::ROW_MIN, min(ChartCatalog::ROW_MAX, (int) ($widget['row_span'] ?? ($widget['chart_type'] === 'kpi' ? 1 : 2))));
            $hasX = array_key_exists('grid_x', $widget) && $widget['grid_x'] !== null && $widget['grid_x'] !== '';
            $hasY = array_key_exists('grid_y', $widget) && $widget['grid_y'] !== null && $widget['grid_y'] !== '';

            if ($hasX && $hasY) {
                $gridX = max(0, min(ChartCatalog::SPAN_MAX - $width, (int) $widget['grid_x']));
                $gridY = max(0, min(ChartCatalog::GRID_Y_MAX, (int) $widget['grid_y']));
            } else {
                if ($cursorX + $width > ChartCatalog::SPAN_MAX) {
                    $cursorX = 0;
                    $cursorY += max($rowH, 1);
                    $rowH = 0;
                }
                $gridX = $cursorX;
                $gridY = $cursorY;
                $cursorX += $width;
                $rowH = max($rowH, $height);
            }

            $widget['grid_x'] = $gridX;
            $widget['grid_y'] = $gridY;
            $widget['row_span'] = $height;
            $out[] = $widget;
        }

        return $out;
    }

    public function layout(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeManage($request);
        $this->authorizeView($request, $dashboard);

        $data = $request->validate([
            'widgets' => ['required', 'array', 'min:1'],
            'widgets.*.id' => ['required', 'integer'],
            'widgets.*.position' => ['required', 'integer', 'min:0'],
            'widgets.*.col_span' => ['required', 'integer', 'min:'.ChartCatalog::SPAN_MIN, 'max:'.ChartCatalog::SPAN_MAX],
            'widgets.*.row_span' => ['required', 'integer', 'min:'.ChartCatalog::ROW_MIN, 'max:'.ChartCatalog::ROW_MAX],
            'widgets.*.grid_x' => ['required', 'integer', 'min:0', 'max:'.(ChartCatalog::SPAN_MAX - 1)],
            'widgets.*.grid_y' => ['required', 'integer', 'min:0', 'max:'.ChartCatalog::GRID_Y_MAX],
        ]);

        $ids = $dashboard->widgets()->pluck('id')->all();
        foreach ($data['widgets'] as $row) {
            if (! in_array((int) $row['id'], $ids, true)) {
                continue;
            }
            $widget = $dashboard->widgets()->whereKey($row['id'])->first();
            if (! $widget) {
                continue;
            }
            $options = $widget->options ?? [];
            $options['row_span'] = (int) $row['row_span'];
            $options['grid_x'] = (int) $row['grid_x'];
            $options['grid_y'] = (int) $row['grid_y'];
            $widget->update([
                'position' => (int) $row['position'],
                'col_span' => (int) $row['col_span'],
                'options' => $options,
            ]);
        }

        return back();
    }

    protected function authorizeView(Request $request, Dashboard $dashboard): void
    {
        abort_unless($dashboard->isAccessibleTo($request->user('admin')), 403);
    }

    protected function authorizeManage(Request $request): void
    {
        $admin = $request->user('admin');
        abort_unless($admin && ($admin->isSuperAdmin() || $admin->hasPermission('dashboards.manage')), 403);
    }
}
