<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Localization\Models\Language;
use App\Domain\Security\SafeRedirect;
use App\Domain\Subscription\Models\SubscriptionPlan;
use App\Domain\Subscription\Models\SubscriptionPlanTranslation;
use App\Domain\Subscription\Services\SubscriptionService;
use App\Domain\Subscription\Support\SubscriptionPlanCsvSchema;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionPlanController extends Controller
{
    public function __construct(
        protected SubscriptionService $subscriptions,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizePlan('view');

        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $frequency = trim($request->string('frequency')->toString());
        $trial = trim($request->string('trial')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $cycleMin = $request->input('cycle_min');
        $cycleMax = $request->input('cycle_max');
        $sort = $request->string('sort')->toString() ?: 'id';
        $dir = $request->string('dir')->toString() === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['id', 'name', 'is_enabled', 'cycle', 'frequency'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'id';
        }
        $frequencies = $this->subscriptions->frequencies();

        $base = SubscriptionPlan::query();
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $plans = SubscriptionPlan::query()
            ->with('translations')
            ->withCount('productAssignments')
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', function ($query) use ($q) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            })
            ->when($name !== '', function ($query) use ($name) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$name}%"));
            })
            ->when($frequency !== '' && in_array($frequency, $frequencies, true), fn ($query) => $query->where('frequency', $frequency))
            ->when($trial === 'yes', fn ($query) => $query->where('trial_enabled', true))
            ->when($trial === 'no', fn ($query) => $query->where('trial_enabled', false))
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('subscription_plans.id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('subscription_plans.id', '<=', (int) $idMax))
            ->when($cycleMin !== null && $cycleMin !== '', fn ($query) => $query->where('subscription_plans.cycle', '>=', (int) $cycleMin))
            ->when($cycleMax !== null && $cycleMax !== '', fn ($query) => $query->where('subscription_plans.cycle', '<=', (int) $cycleMax))
            ->when($sort === 'name', function ($query) use ($dir) {
                $query->orderBy(
                    SubscriptionPlanTranslation::query()
                        ->select('name')
                        ->whereColumn('subscription_plan_id', 'subscription_plans.id')
                        ->limit(1),
                    $dir
                )->orderBy('id');
            }, function ($query) use ($sort, $dir) {
                if ($sort === 'id') {
                    $query->orderBy('subscription_plans.id', $dir);
                } else {
                    $query->orderBy($sort, $dir)->orderBy('id');
                }
            })
            ->adminPaginate()
            ->through(function (SubscriptionPlan $plan) {
                $row = $this->subscriptions->presentPlan($plan);
                $row['actions'] = [
                    'edit' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.subscriptions.update'),
                    'delete' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.subscriptions.delete'),
                    'duplicate' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.subscriptions.create'),
                ];

                return $row;
            });

        return Inertia::render('Catalog/Subscriptions/Index', [
            'plans' => $plans,
            'counts' => $counts,
            'frequencyOptions' => $this->frequencyOptions(),
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'frequency' => $frequency,
                'trial' => $trial,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'cycle_min' => $cycleMin !== null && $cycleMin !== '' ? (string) $cycleMin : '',
                'cycle_max' => $cycleMax !== null && $cycleMax !== '' ? (string) $cycleMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
            'can' => [
                'create' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.subscriptions.create'),
                'update' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.subscriptions.update'),
                'delete' => $this->admin()?->hasPermission('catalog.manage') || $this->admin()?->hasPermission('catalog.subscriptions.delete'),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePlan('create');

        return Inertia::render('Catalog/Subscriptions/Form', [
            'frequencies' => $this->frequencyOptions(),
            'return_to' => $this->safeReturnTo($request->query('return')),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePlan('create');
        $this->subscriptions->savePlan($this->validated($request));

        return redirect()->route('admin.subscription-plans.index')->with('success', 'Subscription plan created.');
    }

    public function edit(SubscriptionPlan $subscriptionPlan): Response
    {
        $this->authorizePlan('update');
        $subscriptionPlan->load(['translations', 'productAssignments']);

        return Inertia::render('Catalog/Subscriptions/Form', [
            'plan' => $this->subscriptions->presentPlan($subscriptionPlan),
            'frequencies' => $this->frequencyOptions(),
        ]);
    }

    public function update(Request $request, SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $this->authorizePlan('update');
        $this->subscriptions->savePlan($this->validated($request), $subscriptionPlan);

        return redirect()->route('admin.subscription-plans.index')->with('success', 'Subscription plan updated.');
    }

    public function destroy(SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $this->authorizePlan('delete');
        try {
            $this->subscriptions->deletePlan($subscriptionPlan);
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('admin.subscription-plans.index')->with('success', 'Subscription plan deleted.');
    }

    public function duplicate(SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $this->authorizePlan('create');
        $copy = $this->subscriptions->duplicate($subscriptionPlan);

        return redirect()->route('admin.subscription-plans.index')->with('success', 'Subscription plan duplicated.');
    }

    public function toggle(SubscriptionPlan $subscriptionPlan): RedirectResponse
    {
        $this->authorizePlan('update');
        $subscriptionPlan->update(['is_enabled' => ! $subscriptionPlan->is_enabled]);

        return back()->with('success', $subscriptionPlan->is_enabled ? 'Subscription plan enabled.' : 'Subscription plan disabled.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $this->authorizePlan('delete');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $blocked = 0;
        $deleted = 0;
        foreach (SubscriptionPlan::query()->whereIn('id', $data['ids'])->get() as $plan) {
            try {
                $this->subscriptions->deletePlan($plan);
                $deleted++;
            } catch (ValidationException) {
                $blocked++;
            }
        }

        if ($deleted && $blocked) {
            return back()->with('warning', $deleted.' plan(s) deleted. '.$blocked.' could not be deleted because they are assigned to products.');
        }
        if ($blocked) {
            return back()->with('error', 'None of the selected plans could be deleted. Remove them from products first.');
        }

        return back()->with('success', 'Selected subscription plans deleted.');
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $this->authorizePlan('create');
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ]);

        $copied = 0;
        foreach (SubscriptionPlan::query()->whereIn('id', $data['ids'])->get() as $plan) {
            $this->subscriptions->duplicate($plan);
            $copied++;
        }

        return back()->with('success', $copied === 1 ? 'Subscription plan duplicated.' : $copied.' subscription plans duplicated.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $this->authorizePlan('update');
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => $this->toBool($request->input('enabled')),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = SubscriptionPlan::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No subscription plans were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Subscription plan enabled.' : $updated.' subscription plans enabled.')
            : ($updated === 1 ? 'Subscription plan disabled.' : $updated.' subscription plans disabled.'));
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizePlan('view');
        $delimiter = (string) $request->input('delimiter', SubscriptionPlanCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', SubscriptionPlanCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->subscriptions->exportCsv(Language::defaultCode(), $request, $delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'subscription-plans.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $this->authorizePlan('create');
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:8192'],
            'language' => ['nullable', 'string', 'max:16'],
            'delimiter' => ['nullable', 'string', 'max:8'],
            'multivalue_separator' => ['nullable', 'string', 'max:8'],
        ]);

        $count = $this->subscriptions->importCsv(
            $request->file('file')->getContent(),
            $data['language'] ?? Language::defaultCode(),
            $data['delimiter'] ?? SubscriptionPlanCsvSchema::DEFAULT_DELIMITER,
            $data['multivalue_separator'] ?? SubscriptionPlanCsvSchema::DEFAULT_MULTI_SEPARATOR,
        );

        return back()->with('success', "Imported {$count} subscription plans.");
    }

    public function importSample(Request $request): StreamedResponse
    {
        $this->authorizePlan('view');
        $delimiter = (string) $request->input('delimiter', SubscriptionPlanCsvSchema::DEFAULT_DELIMITER);
        $multi = (string) $request->input('multivalue_separator', SubscriptionPlanCsvSchema::DEFAULT_MULTI_SEPARATOR);
        $csv = $this->subscriptions->sampleCsv($delimiter, $multi);

        return response()->streamDownload(fn () => print ($csv), 'subscription-plans-sample.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    protected function validated(Request $request): array
    {
        $frequencies = $this->subscriptions->frequencies();
        $maxInt = SubscriptionPlan::MAX_UNSIGNED_INT;
        $data = $request->validate([
            'name' => ['required_without:translations', 'nullable', 'string', 'max:255'],
            'translations' => ['nullable', 'array'],
            'translations.*.language_code' => ['required_with:translations', 'string', Rule::exists('languages', 'code')->where('is_enabled', true)],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'is_enabled' => ['nullable', 'boolean'],
            'trial_enabled' => ['nullable', 'boolean'],
            'trial_duration' => ['nullable', 'numeric', 'decimal:0', 'min:0', 'max:'.$maxInt],
            'trial_cycle' => ['nullable', 'numeric', 'decimal:0', 'min:0', 'max:'.$maxInt],
            'trial_frequency' => ['nullable', Rule::in($frequencies)],
            'duration' => ['required', 'numeric', 'decimal:0', 'min:0', 'max:'.$maxInt],
            'cycle' => ['required', 'numeric', 'decimal:0', 'min:1', 'max:'.$maxInt],
            'frequency' => ['required', Rule::in($frequencies)],
        ], [
            'trial_duration.max' => 'Trial duration cannot exceed '.number_format($maxInt).'.',
            'trial_cycle.max' => 'Trial cycle cannot exceed '.number_format($maxInt).'.',
            'duration.max' => 'Duration cannot exceed '.number_format($maxInt).' billing cycles.',
            'cycle.max' => 'Billing cycle cannot exceed '.number_format($maxInt).'.',
            'trial_duration.decimal' => 'Trial duration must be a whole number.',
            'trial_cycle.decimal' => 'Trial cycle must be a whole number.',
            'duration.decimal' => 'Duration must be a whole number.',
            'cycle.decimal' => 'Billing cycle must be a whole number.',
        ]);

        $trialOn = $request->boolean('trial_enabled');
        if ($trialOn) {
            if ((int) ($data['trial_duration'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'trial_duration' => 'Trial duration must be at least 1 when trial is enabled.',
                ]);
            }
            if ((int) ($data['trial_cycle'] ?? 0) < 1) {
                throw ValidationException::withMessages([
                    'trial_cycle' => 'Trial cycle must be at least 1 when trial is enabled.',
                ]);
            }
        }

        $data['is_enabled'] = $request->boolean('is_enabled');
        $data['trial_enabled'] = $trialOn;
        $data['translations'] = $this->normalizeTranslations($data);
        $default = Language::defaultCode();
        $data['name'] = $data['translations'][$default]['name'] ?? '';
        $data['language_code'] = $default;

        return $data;
    }

    /** @return array<string, array<string, mixed>> */
    protected function normalizeTranslations(array $data): array
    {
        $default = Language::defaultCode();
        $rows = [];

        foreach ((array) ($data['translations'] ?? []) as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $language = (string) ($row['language_code'] ?? $key);
            if ($language === '') {
                continue;
            }
            $rows[$language] = [
                'language_code' => $language,
                'name' => trim((string) ($row['name'] ?? '')),
            ];
        }

        if ($rows === []) {
            $rows[$default] = [
                'language_code' => $default,
                'name' => trim((string) ($data['name'] ?? '')),
            ];
        }

        if (($rows[$default]['name'] ?? '') === '') {
            throw ValidationException::withMessages([
                'name' => 'Plan name is required.',
                "translations.$default.name" => 'Plan name is required for the default language.',
            ]);
        }

        return $rows;
    }

    protected function frequencyOptions(): array
    {
        $labels = [
            'hour' => 'Hour',
            'day' => 'Day',
            'week' => 'Week',
            'month' => 'Month',
            'year' => 'Year',
        ];

        return collect($this->subscriptions->frequencies())
            ->map(fn (string $value) => [
                'value' => $value,
                'label' => $labels[$value] ?? ucfirst($value),
            ])
            ->values()
            ->all();
    }

    /**
     * Only allow in-admin relative returns (e.g. product edit Subscription tab).
     */
    protected function safeReturnTo(mixed $value): ?string
    {
        $url = SafeRedirect::sanitize(is_string($value) ? $value : null);
        if ($url === null || ! str_starts_with($url, '/admin/')) {
            return null;
        }

        return $url;
    }

    protected function authorizePlan(string $ability): void
    {
        $admin = $this->admin();
        $map = [
            'view' => 'catalog.subscriptions.view',
            'create' => 'catalog.subscriptions.create',
            'update' => 'catalog.subscriptions.update',
            'delete' => 'catalog.subscriptions.delete',
        ];
        $slug = $map[$ability] ?? 'catalog.subscriptions.view';
        if ($admin && ($admin->hasPermission('catalog.manage') || $admin->hasPermission($slug))) {
            return;
        }

        abort(403, 'You do not have permission to perform this action.');
    }

    protected function admin(): ?Admin
    {
        return request()->user('admin');
    }

    protected function toBool(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true' || $value === 'on') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false' || $value === 'off') {
            return false;
        }

        return $value;
    }
}
