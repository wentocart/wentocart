<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Category;
use App\Domain\Customer\Models\Customer;
use App\Domain\Customer\Models\CustomerGroup;
use App\Domain\Localization\Models\Country;
use App\Domain\Localization\Models\Currency;
use App\Domain\Localization\Models\Language;
use App\Domain\Payment\Models\PaymentMethod;
use App\Domain\Payment\PaymentGatewayRegistry;
use App\Domain\Payment\Support\PaymentMethodCsvSchema;
use App\Domain\Payment\Support\PaymentMethodExtension;
use App\Domain\Plugin\PluginKernel;
use App\Domain\Shipping\Models\ShippingMethod;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PaymentMethodController extends Controller
{
    public function __construct(
        protected PaymentMethodExtension $extensions,
        protected PluginKernel $plugins,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'all';
        $q = trim($request->string('q')->toString());
        $name = trim($request->string('name')->toString());
        $driver = trim($request->string('driver')->toString());
        $description = trim($request->string('description')->toString());
        $rules = trim($request->string('rules')->toString());
        $idMin = $request->input('id_min');
        $idMax = $request->input('id_max');
        $sort = $request->string('sort')->toString() ?: 'name';
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $allowedSort = ['id', 'name', 'driver', 'description', 'is_enabled', 'position'];
        if (! in_array($sort, $allowedSort, true)) {
            $sort = 'name';
        }

        $base = PaymentMethod::query();

        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('is_enabled', true)->count(),
            'inactive' => (clone $base)->where('is_enabled', false)->count(),
        ];

        $methods = PaymentMethod::query()
            ->when($status === 'active', fn ($query) => $query->where('is_enabled', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_enabled', false))
            ->when($q !== '', fn ($query) => $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('driver', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            }))
            ->when($name !== '', fn ($query) => $query->where('name', 'like', "%{$name}%"))
            ->when($driver !== '', fn ($query) => $query->where('driver', 'like', "%{$driver}%"))
            ->when($description !== '', fn ($query) => $query->where('description', 'like', "%{$description}%"))
            ->when($rules !== '', function ($query) use ($rules) {
                $needle = strtolower($rules);
                $query->where(function ($inner) use ($needle) {
                    if (str_contains('no restrictions', $needle) || str_contains('none', $needle)) {
                        $inner->orWhereNull('rules')
                            ->orWhere('rules', '[]')
                            ->orWhere('rules', '{}');
                    }
                    $inner->orWhere('rules', 'like', '%'.$needle.'%');
                });
            })
            ->when($idMin !== null && $idMin !== '', fn ($query) => $query->where('id', '>=', (int) $idMin))
            ->when($idMax !== null && $idMax !== '', fn ($query) => $query->where('id', '<=', (int) $idMax))
            ->orderBy($sort, $dir)
            ->when($sort !== 'id', fn ($query) => $query->orderBy('id'))
            ->adminPaginate()
            ->through(fn (PaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'description' => $method->description,
                'driver' => $method->driver,
                'is_enabled' => $method->is_enabled,
                'rule_summary' => $method->ruleSummary(),
                'extension' => $this->extensions->summary($method),
                'actions' => [
                    'edit' => true,
                    'delete' => true,
                ],
            ]);

        return Inertia::render('Payments/Index', [
            'methods' => $methods,
            'counts' => $counts,
            'filters' => [
                'status' => $status,
                'q' => $q,
                'name' => $name,
                'driver' => $driver,
                'description' => $description,
                'rules' => $rules,
                'id_min' => $idMin !== null && $idMin !== '' ? (string) $idMin : '',
                'id_max' => $idMax !== null && $idMax !== '' ? (string) $idMax : '',
                'sort' => $sort,
                'dir' => $dir,
            ],
        ]);
    }

    public function export(Request $request)
    {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->input('ids')))));

        return $this->csvDownload('payment-methods.csv', PaymentMethodCsvSchema::exportCsv($ids));
    }

    public function importSample()
    {
        return $this->csvDownload('payment-methods-sample.csv', PaymentMethodCsvSchema::sampleCsv());
    }

    public function import(Request $request): RedirectResponse
    {
        return $this->csvImport($request, PaymentMethodCsvSchema::import(...), 'payment methods');
    }

    public function create(): Response
    {
        return Inertia::render('Payments/Form', $this->formProps());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        PaymentMethod::query()->create([
            'code' => $this->uniqueCodeFromName($data['name']),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'driver' => $data['driver'],
            'is_enabled' => $data['is_enabled'],
            'position' => 0,
            'config' => $this->configPayload($data),
            'rules' => PaymentMethod::normalizeRules($data['rules'] ?? []),
        ]);

        return redirect()->route('admin.payments.index')->with('success', 'Payment method created.');
    }

    public function edit(PaymentMethod $paymentMethod): Response
    {
        $rules = PaymentMethod::normalizeRules($paymentMethod->rules);

        return Inertia::render('Payments/Form', [
            ...$this->formProps(),
            'method' => [
                'id' => $paymentMethod->id,
                'name' => $paymentMethod->name,
                'description' => $paymentMethod->description ?? '',
                'driver' => $paymentMethod->driver ?: 'offline',
                'is_enabled' => $paymentMethod->is_enabled,
                'rules' => $rules,
                'config' => $this->presentConfig($paymentMethod),
            ],
            'extension' => $this->extensions->panel($paymentMethod),
            'selected' => $this->selectedLabels($rules),
        ]);
    }

    public function update(Request $request, PaymentMethod $paymentMethod): RedirectResponse
    {
        $data = $this->validated($request, $paymentMethod);

        $paymentMethod->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'driver' => $data['driver'],
            'is_enabled' => $data['is_enabled'],
            'config' => array_merge($paymentMethod->config ?? [], $this->configPayload($data, $paymentMethod)),
            'rules' => PaymentMethod::normalizeRules($data['rules'] ?? []),
        ]);

        if ($request->has('extension_values') && is_array($request->input('extension_values'))) {
            $this->extensions->save($paymentMethod, $request->input('extension_values'));
            PluginKernel::reset();
            $this->plugins->boot();
        }

        return redirect()->route('admin.payments.index')->with('success', 'Payment method updated.');
    }

    public function destroy(PaymentMethod $paymentMethod): RedirectResponse
    {
        $paymentMethod->delete();

        return redirect()->route('admin.payments.index')->with('success', 'Payment method deleted.');
    }

    public function toggle(PaymentMethod $paymentMethod): RedirectResponse
    {
        $paymentMethod->update(['is_enabled' => ! $paymentMethod->is_enabled]);

        return back()->with('success', $paymentMethod->is_enabled ? 'Payment method activated.' : 'Payment method deactivated.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:payment_methods,id'],
        ]);

        $deleted = PaymentMethod::query()->whereIn('id', $data['ids'])->delete();

        return back()->with('success', $deleted === 1 ? 'Payment method deleted.' : $deleted.' payment methods deleted.');
    }

    public function bulkStatus(Request $request): RedirectResponse
    {
        $request->merge([
            'ids' => array_values(array_filter(array_map('intval', (array) $request->input('ids', [])))),
            'enabled' => filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        ]);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:payment_methods,id'],
            'enabled' => ['required', 'boolean'],
        ]);

        $updated = PaymentMethod::query()
            ->whereIn('id', $data['ids'])
            ->update(['is_enabled' => (bool) $data['enabled']]);

        if ($updated === 0) {
            return back()->with('error', 'No payment methods were updated.');
        }

        return back()->with('success', $data['enabled']
            ? ($updated === 1 ? 'Payment method enabled.' : $updated.' payment methods enabled.')
            : ($updated === 1 ? 'Payment method disabled.' : $updated.' payment methods disabled.'));
    }

    public function bulkDuplicate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:payment_methods,id'],
        ]);

        $copied = 0;
        foreach (PaymentMethod::query()->whereIn('id', $data['ids'])->get() as $method) {
            $this->duplicateMethod($method);
            $copied++;
        }

        return back()->with('success', $copied === 1 ? 'Payment method duplicated.' : $copied.' payment methods duplicated.');
    }

    protected function duplicateMethod(PaymentMethod $method): PaymentMethod
    {
        $copy = $method->replicate();
        $copy->name = $method->name.' (copy)';
        $copy->code = $this->uniqueCodeFromName($copy->name);
        $copy->is_enabled = false;
        $copy->save();

        return $copy;
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $results = Customer::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($inner) use ($q) {
                    $inner->where('name', 'like', "%{$q}%")
                        ->orWhere('email', 'like', "%{$q}%");
                });
            })
            ->orderBy('name')
            ->limit(25)
            ->get(['id', 'name', 'email'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => "{$c->name} ({$c->email})",
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    public function searchCategories(Request $request): JsonResponse
    {
        $q = trim($request->string('q')->toString());

        $results = Category::query()
            ->with('translations')
            ->when($q !== '', function ($query) use ($q) {
                $query->whereHas('translations', fn ($t) => $t->where('name', 'like', "%{$q}%"));
            })
            ->orderBy('position')
            ->limit(25)
            ->get()
            ->map(fn (Category $c) => [
                'id' => $c->id,
                'name' => $c->translations->first()?->name ?? "Category #{$c->id}",
            ])
            ->values();

        return response()->json(['results' => $results]);
    }

    /** @return array<string, mixed> */
    protected function formProps(): array
    {
        $this->plugins->boot();
        $registry = app(PaymentGatewayRegistry::class);
        $labels = [
            'offline' => 'Offline / bank transfer',
            'paypal' => 'PayPal',
            'razorpay' => 'Razorpay',
            'upi' => 'UPI',
            'stripe' => 'Stripe',
        ];

        return [
            'drivers' => collect($registry->ids())
                ->map(fn (string $id) => [
                    'value' => $id,
                    'label' => $labels[$id] ?? ucfirst($id),
                ])
                ->values()
                ->all(),
            'options' => [
                'customer_groups' => CustomerGroup::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'shipping_methods' => ShippingMethod::query()
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'countries' => Country::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'currencies' => Currency::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']),
                'languages' => Language::query()
                    ->where('is_enabled', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'code']),
            ],
            'selected' => [
                'categories' => [],
                'customers' => [],
                'customer_groups' => [],
                'shipping_methods' => [],
                'countries' => [],
                'currencies' => [],
                'languages' => [],
            ],
        ];
    }

    /** @param  array<string, mixed>  $rules */
    protected function selectedLabels(array $rules): array
    {
        return [
            'categories' => Category::query()
                ->with('translations')
                ->whereIn('id', $rules['category_ids'] ?? [])
                ->get()
                ->map(fn (Category $c) => [
                    'id' => $c->id,
                    'name' => $c->translations->first()?->name ?? "Category #{$c->id}",
                ])
                ->values()
                ->all(),
            'customers' => Customer::query()
                ->whereIn('id', $rules['customer_ids'] ?? [])
                ->get(['id', 'name', 'email'])
                ->map(fn (Customer $c) => [
                    'id' => $c->id,
                    'name' => "{$c->name} ({$c->email})",
                ])
                ->values()
                ->all(),
            'customer_groups' => CustomerGroup::query()
                ->whereIn('id', $rules['customer_group_ids'] ?? [])
                ->get(['id', 'name'])
                ->map(fn (CustomerGroup $g) => ['id' => $g->id, 'name' => $g->name])
                ->values()
                ->all(),
            'shipping_methods' => ShippingMethod::query()
                ->whereIn('id', $rules['shipping_method_ids'] ?? [])
                ->get(['id', 'name'])
                ->map(fn (ShippingMethod $m) => ['id' => $m->id, 'name' => $m->name])
                ->values()
                ->all(),
            'countries' => Country::query()
                ->whereIn('id', $rules['country_ids'] ?? [])
                ->get(['id', 'name'])
                ->map(fn (Country $c) => ['id' => $c->id, 'name' => $c->name])
                ->values()
                ->all(),
            'currencies' => Currency::query()
                ->whereIn('id', $rules['currency_ids'] ?? [])
                ->get(['id', 'name', 'code'])
                ->map(fn (Currency $c) => [
                    'id' => $c->id,
                    'name' => $c->code ? "{$c->name} ({$c->code})" : $c->name,
                ])
                ->values()
                ->all(),
            'languages' => Language::query()
                ->whereIn('id', $rules['language_ids'] ?? [])
                ->get(['id', 'name', 'code'])
                ->map(fn (Language $l) => [
                    'id' => $l->id,
                    'name' => $l->code ? "{$l->name} ({$l->code})" : $l->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?PaymentMethod $existing = null): array
    {
        $this->plugins->boot();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'driver' => ['required', Rule::in(app(PaymentGatewayRegistry::class)->ids())],
            'is_enabled' => ['required', 'boolean'],
            'extension_values' => ['nullable', 'array'],
            'config' => ['nullable', 'array'],
            'config.client_id' => ['nullable', 'string', 'max:200'],
            'config.secret' => ['nullable', 'string', 'max:200'],
            'config.sandbox' => ['nullable', 'boolean'],
            'config.key_id' => ['nullable', 'string', 'max:200'],
            'config.key_secret' => ['nullable', 'string', 'max:200'],
            'config.vpa' => ['nullable', 'string', 'max:120'],
            'rules' => ['nullable', 'array'],
            'rules.category_ids' => ['nullable', 'array'],
            'rules.category_ids.*' => ['integer'],
            'rules.customer_ids' => ['nullable', 'array'],
            'rules.customer_ids.*' => ['integer'],
            'rules.customer_group_ids' => ['nullable', 'array'],
            'rules.customer_group_ids.*' => ['integer'],
            'rules.shipping_method_ids' => ['nullable', 'array'],
            'rules.shipping_method_ids.*' => ['integer'],
            'rules.country_ids' => ['nullable', 'array'],
            'rules.country_ids.*' => ['integer'],
            'rules.currency_ids' => ['nullable', 'array'],
            'rules.currency_ids.*' => ['integer'],
            'rules.language_ids' => ['nullable', 'array'],
            'rules.language_ids.*' => ['integer'],
        ]);

        $data['is_enabled'] = (bool) $data['is_enabled'];

        return $data;
    }

    /** @return array<string, mixed> */
    protected function presentConfig(PaymentMethod $method): array
    {
        $config = is_array($method->config) ? $method->config : [];

        return [
            'client_id' => (string) ($config['client_id'] ?? ''),
            'has_secret' => trim((string) ($config['secret'] ?? '')) !== '',
            'sandbox' => array_key_exists('sandbox', $config) ? (bool) $config['sandbox'] : true,
            'key_id' => (string) ($config['key_id'] ?? ''),
            'has_key_secret' => trim((string) ($config['key_secret'] ?? '')) !== '',
            'vpa' => (string) ($config['vpa'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function configPayload(array $data, ?PaymentMethod $existing = null): array
    {
        $incoming = is_array($data['config'] ?? null) ? $data['config'] : [];
        $current = is_array($existing?->config) ? $existing->config : [];
        $secret = trim((string) ($incoming['secret'] ?? ''));
        $keySecret = trim((string) ($incoming['key_secret'] ?? ''));

        return [
            'instructions' => $data['description'] ?? ($current['instructions'] ?? ''),
            'client_id' => trim((string) ($incoming['client_id'] ?? $current['client_id'] ?? '')),
            'secret' => $secret !== '' ? $secret : (string) ($current['secret'] ?? ''),
            'sandbox' => array_key_exists('sandbox', $incoming) ? (bool) $incoming['sandbox'] : (bool) ($current['sandbox'] ?? true),
            'key_id' => trim((string) ($incoming['key_id'] ?? $current['key_id'] ?? '')),
            'key_secret' => $keySecret !== '' ? $keySecret : (string) ($current['key_secret'] ?? ''),
            'vpa' => trim((string) ($incoming['vpa'] ?? $current['vpa'] ?? '')),
        ];
    }

    protected function uniqueCodeFromName(string $name): string
    {
        $base = Str::slug($name) ?: 'payment';
        $code = $base;
        $i = 2;
        while (PaymentMethod::query()->where('code', $code)->exists()) {
            $code = "{$base}-{$i}";
            $i++;
        }

        return $code;
    }
}
