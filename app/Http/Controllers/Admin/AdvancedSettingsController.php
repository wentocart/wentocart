<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Installer\DemoDataService;
use App\Domain\Installer\MillionDataService;
use App\Domain\Settings\AdminSelectQuery;
use App\Domain\Settings\EmailLogIndex;
use App\Domain\Settings\MaintenanceMessage;
use App\Domain\Settings\Models\EmailLog;
use App\Domain\Settings\Models\FileChangeLog;
use App\Domain\Settings\Models\SavedQuery;
use App\Domain\Settings\RequestProfiler;
use App\Domain\Settings\ShopSetting;
use App\Domain\Security\CsvSafe;
use App\Domain\Store\StoreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AdvancedSettingsController extends Controller
{
    public function edit(Request $request): Response|RedirectResponse
    {
        $tab = $request->string('tab')->toString();
        if ($tab === 'jump') {
            return redirect()->route('admin.store.general');
        }
        if ($tab === 'email') {
            return redirect()->route('admin.email.index', array_filter([
                'tab' => 'logs',
                'q' => trim($request->string('q')->toString()) ?: null,
                'template' => trim($request->string('template')->toString()) ?: null,
                'status' => trim($request->string('status')->toString()) ?: null,
                'range' => $request->query('range'),
                'per_page' => $request->query('per_page'),
            ], fn ($value) => $value !== null && $value !== ''));
        }

        $allowed = ['profiler', 'queries', 'files', 'info', 'maintenance', 'demo'];
        if (! in_array($tab, $allowed, true)) {
            $tab = 'profiler';
        }

        $tables = AdminSelectQuery::tables();

        return Inertia::render('Settings/Advanced', [
            'tab' => $tab,
            'profiler' => ShopSetting::bool('advanced.profiler', false),
            'queries' => RequestProfiler::history(),
            'tables' => array_map(fn (string $name) => [
                'name' => $name,
                'sql' => AdminSelectQuery::sampleSelect($name),
            ], $tables),
            'saved_queries' => SavedQuery::query()->orderByDesc('id')->limit(100)->get()->map(fn (SavedQuery $row) => [
                'id' => $row->id,
                'name' => $row->name,
                'sql' => $row->sql,
                'last_run_at' => optional($row->last_run_at)?->toIso8601String(),
            ]),
            'query_result' => $request->session()->get('query_result'),
            'draft_sql' => $request->session()->get('query_sql', old('sql', '')),
            'file_logs' => $this->fileLogPaginator($request),
            'file_log_filters' => [
                'action' => trim($request->string('action')->toString()),
                'path' => trim($request->string('path')->toString()),
                'date' => $this->fileLogDate($request),
                'sort' => $this->fileLogSort($request),
                'dir' => $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc',
                'per_page' => app(EmailLogIndex::class)->perPage($request),
            ],
            'info' => $this->shopInfo(),
            'maintenance' => [
                'enabled' => ShopSetting::bool('advanced.maintenance', false),
                'message' => MaintenanceMessage::forEditor(
                    (string) ShopSetting::get('advanced.maintenance_message', '')
                ),
                'header' => MaintenanceMessage::forOptionalEditor(
                    (string) ShopSetting::get('advanced.maintenance_header', ''),
                    MaintenanceMessage::defaultHeader()
                ),
                'footer' => MaintenanceMessage::forOptionalEditor(
                    (string) ShopSetting::get('advanced.maintenance_footer', ''),
                    MaintenanceMessage::defaultFooter()
                ),
                'banner' => MaintenanceMessage::forOptionalEditor(
                    (string) ShopSetting::get('advanced.maintenance_banner', ''),
                    MaintenanceMessage::defaultBanner()
                ),
                'ips' => (string) ShopSetting::get('advanced.maintenance_ips', ''),
                'client_ip' => (string) $request->ip(),
            ],
            'demo' => app(DemoDataService::class)->snapshot(),
            'millions' => app(MillionDataService::class)->snapshot(),
        ]);
    }

    public function exportEmailLogs(Request $request): StreamedResponse
    {
        $filename = 'email-logs-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request) {
            $out = fopen('php://output', 'w');
            CsvSafe::put($out, ['to', 'subject', 'template', 'status', 'error', 'when']);

            app(EmailLogIndex::class)->query($request)
                ->orderByDesc('id')
                ->limit(5000)
                ->get()
                ->each(function (EmailLog $row) use ($out) {
                    CsvSafe::put($out, [
                        $row->to_email,
                        $row->subject,
                        $row->template_code,
                        $row->status,
                        $row->error,
                        optional($row->created_at)?->format('Y-m-d H:i:s'),
                    ]);
                });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function updateProfiler(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        ShopSetting::put('advanced.profiler', (bool) $data['enabled']);
        if (! $data['enabled']) {
            RequestProfiler::forget();
        }

        return back()->with('success', $data['enabled'] ? 'Profiler is on.' : 'Profiler is off.');
    }

    public function updateJump(Request $request): RedirectResponse
    {
        return app(StoreSettingsController::class)->updateSearchShortcut($request);
    }

    public function runQuery(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sql' => ['required', 'string', 'max:'.AdminSelectQuery::MAX_SQL],
            'saved_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = AdminSelectQuery::run($data['sql']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['sql' => $e->getMessage()]);
        }

        if (! empty($data['saved_id'])) {
            SavedQuery::query()->whereKey($data['saved_id'])->update(['last_run_at' => now()]);
        }

        return redirect()
            ->route('admin.advanced.edit', ['tab' => 'queries'])
            ->with('query_result', $result)
            ->with('query_sql', $data['sql']);
    }

    public function saveQuery(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'sql' => ['required', 'string', 'max:'.AdminSelectQuery::MAX_SQL],
        ]);

        try {
            AdminSelectQuery::prepare($data['sql']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['sql' => $e->getMessage()]);
        }

        $storeId = app(StoreContext::class)->id();
        SavedQuery::query()->create([
            'store_id' => $storeId > 0 ? $storeId : null,
            'admin_id' => $request->user('admin')?->id,
            'name' => $data['name'] ?: Str::limit(trim(preg_replace('/\s+/', ' ', $data['sql']) ?: $data['sql']), 80),
            'sql' => trim($data['sql']),
        ]);

        return redirect()
            ->route('admin.advanced.edit', ['tab' => 'queries'])
            ->with('success', 'Query saved.')
            ->with('query_sql', $data['sql']);
    }

    public function destroyQuery(SavedQuery $savedQuery): RedirectResponse
    {
        $savedQuery->delete();

        return redirect()
            ->route('admin.advanced.edit', ['tab' => 'queries'])
            ->with('success', 'Saved query removed.');
    }

    public function previewMaintenance(Request $request): \Illuminate\Http\Response
    {
        $data = $this->maintenanceInput($request);

        return response()->view('storefront.maintenance', [
            'message' => MaintenanceMessage::sanitize((string) ($data['message'] ?? '')),
            'header' => MaintenanceMessage::sanitizeOptional((string) ($data['header'] ?? '')),
            'footer' => MaintenanceMessage::sanitizeOptional((string) ($data['footer'] ?? '')),
            'banner' => MaintenanceMessage::sanitizeOptional((string) ($data['banner'] ?? '')),
            'shop' => config('app.name'),
        ]);
    }

    public function updateMaintenance(Request $request): RedirectResponse
    {
        $data = $this->maintenanceInput($request);
        ShopSetting::put('advanced.maintenance', (bool) $data['enabled']);
        ShopSetting::put(
            'advanced.maintenance_message',
            MaintenanceMessage::sanitize((string) ($data['message'] ?? ''))
        );
        ShopSetting::put(
            'advanced.maintenance_header',
            MaintenanceMessage::sanitizeOptional((string) ($data['header'] ?? ''))
        );
        ShopSetting::put(
            'advanced.maintenance_footer',
            MaintenanceMessage::sanitizeOptional((string) ($data['footer'] ?? ''))
        );
        ShopSetting::put(
            'advanced.maintenance_banner',
            MaintenanceMessage::sanitizeOptional((string) ($data['banner'] ?? ''))
        );
        ShopSetting::put('advanced.maintenance_ips', $data['ips'] ?? '');
        FileChangeLog::record($data['enabled'] ? 'enabled' : 'disabled', 'maintenance');

        return back()->with('success', $data['enabled'] ? 'Maintenance mode is on.' : 'Maintenance mode is off.');
    }

    /**
     * @return array{enabled: bool, message: ?string, header: ?string, footer: ?string, banner: ?string, ips: ?string}
     */
    protected function maintenanceInput(Request $request): array
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:50000'],
            'header' => ['nullable', 'string', 'max:20000'],
            'footer' => ['nullable', 'string', 'max:20000'],
            'banner' => ['nullable', 'string', 'max:20000'],
            'ips' => ['nullable', 'string', 'max:500'],
        ]);
        $data['enabled'] = (bool) $data['enabled'];

        return $data;
    }

    public function installDemo(DemoDataService $demo): RedirectResponse
    {
        try {
            if (! $demo->install()) {
                return redirect()
                    ->route('admin.advanced.edit', ['tab' => 'demo'])
                    ->with('info', 'Demo data has already been installed. It can be added only once, during installation or from this page.');
            }
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.advanced.edit', ['tab' => 'demo'])
                ->with('error', 'Could not install demo data. Check storage/logs/laravel.log.');
        }

        return redirect()
            ->route('admin.advanced.edit', ['tab' => 'demo'])
            ->with('success', 'Demo data installed. Browse the storefront and admin lists to explore the sample shop.');
    }

    public function removeDemo(DemoDataService $demo): RedirectResponse
    {
        try {
            if (! $demo->remove()) {
                return redirect()
                    ->route('admin.advanced.edit', ['tab' => 'demo'])
                    ->with('info', 'There is no demo data to remove.');
            }
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.advanced.edit', ['tab' => 'demo'])
                ->with('error', 'Could not remove demo data. Check storage/logs/laravel.log.');
        }

        return redirect()
            ->route('admin.advanced.edit', ['tab' => 'demo'])
            ->with('success', 'Demo data removed. Sample products, customers, and orders are gone. You can install demo data again if you need it.');
    }

    public function installMillions(MillionDataService $millions): RedirectResponse
    {
        if ($millions->isRunning()) {
            return redirect()
                ->route('admin.advanced.edit', ['tab' => 'demo'])
                ->with('success', 'Test millions data is already running. This page refreshes until it finishes.');
        }

        $started = $millions->startInBackground();

        return redirect()
            ->route('admin.advanced.edit', ['tab' => 'demo'])
            ->with(
                $started ? 'success' : 'error',
                $started
                    ? 'Started loading one million products, customers, orders, reviews, and wishlists. Keep this tab open — it can take a long time.'
                    : 'Could not start the millions data job. Use php artisan wento:million-store --force from the server.'
            );
    }

    /**
     * @return array<string, mixed>
     */
    protected function shopInfo(): array
    {
        $store = app(StoreContext::class)->store();
        $db = config('database.connections.'.config('database.default'));

        $diskFree = null;
        try {
            $bytes = @disk_free_space(base_path());
            $diskFree = is_numeric($bytes) ? round(((float) $bytes) / 1048576, 1).' MB' : null;
        } catch (Throwable) {
            $diskFree = null;
        }

        $mysql = null;
        try {
            $mysql = DB::selectOne('select version() as v')?->v;
        } catch (Throwable) {
            $mysql = null;
        }

        return [
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'app_name' => (string) config('app.name'),
            'app_url' => (string) config('app.url'),
            'shop_name' => $store?->name,
            'shop_url' => $store?->primaryUrl()?->root() ?: (string) config('app.url'),
            'multishop' => app(StoreContext::class)->enabled(),
            'database' => $db['database'] ?? null,
            'driver' => $db['driver'] ?? config('database.default'),
            'prefix' => $db['prefix'] ?? config('wento.table_prefix'),
            'cache' => (string) config('cache.default'),
            'mailer' => (string) config('mail.default'),
            'disk_free' => $diskFree,
            'mysql' => $mysql,
            'gd' => function_exists('imagecreatetruecolor'),
        ];
    }

    protected function fileLogDate(Request $request): string
    {
        $date = trim($request->string('date')->toString());

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : '';
    }

    protected function fileLogSort(Request $request): string
    {
        $sort = $request->string('sort')->toString();

        return in_array($sort, ['action', 'path', 'created_at'], true) ? $sort : 'created_at';
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<\App\Domain\Settings\Models\FileChangeLog>
     */
    protected function fileLogQuery(Request $request)
    {
        $action = trim($request->string('action')->toString());
        $path = trim($request->string('path')->toString());
        $date = $this->fileLogDate($request);

        return FileChangeLog::query()
            ->when($action !== '', fn ($query) => $query->where('action', 'like', '%'.$action.'%'))
            ->when($path !== '', fn ($query) => $query->where('path', 'like', '%'.$path.'%'))
            ->when($date !== '', fn ($query) => $query->whereDate('created_at', $date));
    }

    protected function fileLogPaginator(Request $request): LengthAwarePaginator
    {
        $sort = $this->fileLogSort($request);
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';

        return $this->fileLogQuery($request)
            ->orderBy($sort, $dir)
            ->orderBy('id', $dir)
            ->paginate(app(EmailLogIndex::class)->perPage($request))
            ->withQueryString()
            ->through(fn (FileChangeLog $row) => [
                'id' => $row->id,
                'action' => $row->action,
                'path' => $row->path,
                'admin_id' => $row->admin_id,
                'created_at' => optional($row->created_at)?->format('Y-m-d H:i:s P'),
            ]);
    }
}
